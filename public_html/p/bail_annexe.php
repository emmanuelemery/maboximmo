<?php
declare(strict_types=1);
/**
 * p/bail_annexe.php — Servir une ANNEXE du bail au signataire, sans compte.
 *
 * ⚠️🔥 POURQUOI CETTE PAGE EXISTE.
 * La page de signature listait les annexes et faisait cocher « j'ai pris
 * connaissance des N pièces annexées » — sans qu'aucune ne soit ouvrable. On
 * demandait donc au signataire d'attester une lecture matériellement impossible.
 *
 * ── Deux modes ───────────────────────────────────────────────────────────────
 *   (défaut)  page d'affichage : barre de retour TOUJOURS visible + le document
 *   &raw=1    le fichier lui-même, servi par la cage d'accès
 *
 * ⚠️🔥 LA BARRE DE RETOUR N'EST PAS UN CONFORT. Constaté en recette le 18/08 :
 * l'annexe s'ouvrait dans un nouvel onglet, et sur mobile le signataire ne savait
 * plus revenir à la signature. Un PDF affiché plein écran par le visualiseur du
 * téléphone n'a ni barre d'adresse ni bouton retour évident : la cérémonie
 * s'arrêtait là. On enveloppe donc le document dans une page à nous, qui garde
 * une porte de sortie sous les yeux.
 *
 * ── Ce qui borne l'accès ─────────────────────────────────────────────────────
 * Le jeton identifie UNE ligne `bail_signatures`, donc UN bail. La liste des
 * pièces servables est calculée ICI depuis `annexes_signature_json` — l'instantané
 * figé à l'envoi — et passée à la cage GED comme périmètre de jeton. Le paramètre
 * `d` du client n'est jamais cru.
 *
 * URL : /p/bail_annexe.php?t=<token>&d=<id_ged>[&raw=1]
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bail_signature.php';
require_once __DIR__ . '/../inc/ged_access.php';

$pdo = $GLOBALS['pdo'];

/* ⚠️ Pas de type de retour `never` : il n'existe qu'à partir de PHP 8.1 et le
   poste local tourne en 8.0.30 — l'erreur ne se serait déclenchée qu'en
   production, sur un cas d'erreur, c'est-à-dire au pire moment. */
$refuser = static function (int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
};

$token = (string)($_GET['t'] ?? '');
$docId = (int)($_GET['d'] ?? 0);
$brut  = (string)($_GET['raw'] ?? '') === '1';
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) $refuser(400, 'Lien invalide.');
if ($docId <= 0)                                  $refuser(400, 'Pièce non précisée.');

$sig = bsig_get_by_token($pdo, $token);
if (!$sig) $refuser(404, "Ce lien n'existe pas.");

/* Un lien expiré n'ouvre plus rien — l'annexe est une pièce de l'acte. Un
   signataire qui a DÉJÀ signé garde l'accès : il vient relire ce qu'il a signé,
   et le lui refuser serait absurde. */
if (bsig_is_expired($sig)) $refuser(410, 'Ce lien de signature a expiré. Contactez votre agence.');
if (empty($sig['vague_ouverte_at']) && (int)($sig['vague'] ?? 1) >= 2) {
    $refuser(403, "Ce n'est pas encore votre tour de signer.");
}

// ── Le périmètre : les annexes FIGÉES de ce bail, et elles seules ────────────
$annexes = [];
try {
    $st = $pdo->prepare("SELECT annexes_signature_json FROM bien_baux WHERE id = ? LIMIT 1");
    $st->execute([(int)$sig['id_bail']]);
    $annexes = json_decode((string)($st->fetchColumn() ?: '[]'), true) ?: [];
} catch (Throwable $e) { error_log('[bail_annexe] ' . $e->getMessage()); }

$autorises = []; $nomPiece = 'Pièce annexée'; $uidPiece = '';
foreach ($annexes as $ax) {
    if (!preg_match('/^(ged|mbo):(\d+)$/', (string)($ax['uid'] ?? ''), $m)) continue;
    $autorises[] = (int)$m[2];
    if ((int)$m[2] === $docId) {
        $nomPiece = (string)($ax['nom'] ?? $nomPiece);
        $uidPiece = (string)$ax['uid'];
    }
}
if (!in_array($docId, $autorises, true)) {
    $refuser(403, "Cette pièce ne fait pas partie des annexes de votre acte.");
}

/** Identité de jeton : aucun user, aucune société, strictement ces pièces-là. */
$identite = [
    'user_id'         => 0,
    'societe'         => 0,
    'admin_sup'       => false,
    'ged_token_scope' => ['bail_annexes' => $autorises],
];

// ══════════════════════════════════════════════════════════════════════════
//  MODE FICHIER — la cage sert le document
// ══════════════════════════════════════════════════════════════════════════
if ($brut) {
    /* ⚠️ L'HORODATAGE EST POSÉ ICI, PAS SUR UN CLIC.
       Un clic côté navigateur peut être simulé et surtout ne dit pas que la
       pièce est parvenue. Ce qu'on consigne, c'est que le serveur a RENDU ce
       fichier à ce signataire, à cette seconde. Ce n'est pas une preuve de
       lecture — rien ne peut l'être — mais c'est un fait vérifiable. */
    try {
        $q = $pdo->prepare("SELECT annexes_lues_json FROM bail_signatures WHERE id = ? LIMIT 1");
        $q->execute([(int)$sig['id']]);
        $journal = json_decode((string)($q->fetchColumn() ?: '[]'), true) ?: [];

        $ip = '';
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) { $ip = trim(explode(',', (string)$_SERVER[$k])[0]); break; }
        }

        $trouve = false;
        foreach ($journal as &$ligne) {
            if ((string)($ligne['uid'] ?? '') === $uidPiece) {
                /* On garde la PREMIÈRE ouverture et on compte les suivantes :
                   c'est la première qui date la prise de connaissance ; les
                   relectures ne doivent pas l'effacer. */
                $ligne['nb'] = (int)($ligne['nb'] ?? 1) + 1;
                $ligne['dernier_at'] = date('Y-m-d H:i:s');
                $trouve = true;
                break;
            }
        }
        unset($ligne);
        if (!$trouve) {
            $journal[] = ['uid' => $uidPiece, 'nom' => $nomPiece,
                          'ouvert_at' => date('Y-m-d H:i:s'), 'ip' => substr($ip, 0, 45), 'nb' => 1];
        }
        $pdo->prepare("UPDATE bail_signatures SET annexes_lues_json = ? WHERE id = ?")
            ->execute([json_encode($journal, JSON_UNESCAPED_UNICODE), (int)$sig['id']]);
    } catch (Throwable $e) { error_log('[bail_annexe journal] ' . $e->getMessage()); }

    ged_serve($docId, 'inline', $identite);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
//  MODE PAGE — la barre de retour, puis le document
// ══════════════════════════════════════════════════════════════════════════
$g = GedAccess::grant($docId, 'meta', $identite);
if ($g === null) $refuser(403, "Cette pièce n'est pas consultable.");

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$base    = function_exists('app_url') ? app_url('/p/bail_annexe.php') : '/p/bail_annexe.php';
$baseSig = function_exists('app_url') ? app_url('/p/bail_signature.php') : '/p/bail_signature.php';
$urlDoc  = $base . '?t=' . urlencode($token) . '&d=' . $docId . '&raw=1';
$urlSig  = $baseSig . '?t=' . urlencode($token);
?><!doctype html>
<html lang="fr"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h($nomPiece) ?> — pièce annexée</title>
<style>
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;height:100%;background:#eef2f5;
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;}
  body{display:flex;flex-direction:column;}
  /* La barre est COLLÉE EN HAUT et ne défile pas : c'est tout l'objet de cette
     page. `env(safe-area-inset-top)` la garde sous l'encoche des iPhone. */
  .bar{position:sticky;top:0;z-index:10;background:#243B5C;color:#fff;
       padding:calc(10px + env(safe-area-inset-top)) 12px 10px;
       display:flex;align-items:center;gap:10px;box-shadow:0 2px 10px rgba(0,0,0,.18);}
  .bar a.retour{flex:0 0 auto;background:#D4A047;color:#1a2333;text-decoration:none;
       font-weight:800;font-size:14px;padding:10px 14px;border-radius:9px;white-space:nowrap;}
  .bar .nom{flex:1;min-width:0;font-size:13px;font-weight:700;line-height:1.3;
       overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}
  .doc{flex:1;min-height:0;}
  .doc iframe{width:100%;height:100%;border:0;background:#fff;}
  /* Repli : beaucoup de navigateurs mobiles refusent d'afficher un PDF en
     iframe. On propose donc TOUJOURS le lien direct sous le cadre, plutôt que
     de laisser un rectangle blanc inexplicable. */
  .repli{padding:14px 16px 26px;text-align:center;background:#eef2f5;}
  .repli a{display:inline-block;background:#5f8f93;color:#fff;text-decoration:none;
       font-weight:800;font-size:14px;padding:12px 18px;border-radius:10px;}
  .repli p{font-size:12px;color:#64748b;margin:10px auto 0;max-width:420px;line-height:1.5;}
</style>
</head><body>
  <div class="bar">
    <a class="retour" href="<?= $h($urlSig) ?>">← Retour à la signature</a>
    <div class="nom">📄 <?= $h($nomPiece) ?></div>
  </div>
  <div class="doc"><iframe src="<?= $h($urlDoc) ?>" title="<?= $h($nomPiece) ?>"></iframe></div>
  <div class="repli">
    <a href="<?= $h($urlDoc) ?>" target="_blank" rel="noopener">⤢ Ouvrir la pièce en plein écran</a>
    <p>Si le document ne s'affiche pas ci-dessus, votre navigateur ne sait pas
    l'intégrer : ouvrez-le en plein écran, puis revenez avec le bouton doré.</p>
  </div>
</body></html>
