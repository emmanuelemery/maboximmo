<?php
declare(strict_types=1);
/**
 * api/bail_ceremonie_lancer.php — LANCE la cérémonie de signature SANS passer par
 * le composeur de mail.
 *
 * ── Pourquoi ce point d'entrée existe (19-20/08/2026) ────────────────────────
 * Jusqu'ici la cérémonie ne s'ouvrait qu'en EFFET DE BORD d'un mail réussi :
 * dans `api/mail_compose_send.php`, le passage en statut `envoye`, les SMS de la
 * vague 1 et le gel des annexes vivent tous à l'intérieur d'un
 * `if ($signMode && $okCount > 0)`. Conséquence mesurée sur le bail #660 :
 * l'écran d'envoi refusé (mobile manquant) ⇒ aucun mail ⇒ **aucun SMS non plus**,
 * bail resté en `projet`, et une page qui affichait pourtant trois signataires
 * « en attente ». Personne n'a rien reçu et rien ne le disait.
 *
 * Ici la cérémonie s'ouvre pour elle-même. `bcer_ouvrir_vague()` prévient chaque
 * signataire **par mail ET par SMS** — les deux portent le MÊME jeton, donc le
 * premier des deux qui arrive ouvre la même cérémonie. Ce qui manque à quelqu'un
 * (pas d'email, ou pas de mobile) ne prive personne d'autre.
 *
 * ⚠️ RIEN NE BLOQUE, MAIS RIEN NE SE TAIT. Chaque signataire est rendu avec ce
 * qui lui est réellement parti. Un signataire sans email NI mobile est remonté
 * dans `bloques` : c'est le seul cas où quelqu'un n'a rien reçu, et l'écran doit
 * le crier plutôt que de le laisser découvrir à la relance.
 *
 * ⚠️ Le mail envoyé ici est le gabarit sobre de `bcer_mail_invitation()`. Pour y
 * joindre le PDF du projet, le RIB et les annexes en pièces séparées, c'est le
 * parcours « Envoyer pour signature » (mail_compose) qu'il faut prendre — la page
 * de signature, elle, porte déjà le RIB, les montants et les annexes ouvrables.
 *
 * POST JSON : { bail_id, role_tels?: {role_code: "06…"}, relancer?: bool }
 *          →  { ok, message, envois[], bloques[], vague2[], statut }
 * Auth : user connecté + scope société (bypass admin, exception bailleur 9/10).
 */
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

require_once dirname(__DIR__) . '/inc/bail_signature.php';
require_once dirname(__DIR__) . '/inc/bail_ceremonie.php';

$pdo     = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['user_id'] ?? 0);
$userSoc = (int)($_SESSION['id_societe'] ?? 0);
$role    = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($role === 1);

$body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$bailId = (int)($body['bail_id'] ?? 0);
if ($bailId <= 0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));

// ── Bail + périmètre ─────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT bb.id_societe, bb.statut, b.id_proprietaire
                       FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien
                      WHERE bb.id = ?");
$st->execute([$bailId]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($r['id_societe']) && (int)$r['id_societe'] !== $userSoc) {
    $ok = false;
    if (in_array($role, [9,10], true) && (int)($r['id_proprietaire'] ?? 0) > 0) {
        $c = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId, (int)$r['id_proprietaire']]); $ok = (bool)$c->fetchColumn();
    }
    if (!$ok) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}
if (in_array((string)$r['statut'], ['signe','actif','resilie'], true)) {
    exit(json_encode(['ok'=>false,
        'error'=>'Ce bail est ' . $r['statut'] . " : la signature est figée, il faut un avenant."], JSON_UNESCAPED_UNICODE));
}

// ── Les signataires (créés s'ils n'existent pas, rafraîchis sinon) ───────────
/* Les numéros saisis à l'écran l'emportent sur les fiches : c'est l'agent qui
   vient de les vérifier avec le client. Clés = role_code, comme bsig les attend. */
$roleTels = [];
foreach ((array)($body['role_tels'] ?? []) as $k => $v) {
    $k = trim((string)$k); $v = trim((string)$v);
    if ($k !== '' && $v !== '') $roleTels[$k] = $v;
}
try {
    $signataires = bsig_create_for_signataires($pdo, $bailId, $userId, [], $roleTels);
} catch (Throwable $e) {
    error_log('[bail_ceremonie_lancer create] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>'Impossible de préparer les signataires : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}
if (!$signataires) {
    exit(json_encode(['ok'=>false,
        'error'=>"Aucun signataire n'a pu être déterminé pour ce bail. Vérifie le preneur et le bailleur dans « Modifier le projet »."],
        JSON_UNESCAPED_UNICODE));
}

/* ── LE PRÉ-VOL : on s'arrête ICI, avant toute ouverture de vague ────────────
   `verifier: true` répond ce qui va se passer et ce qui manque, puis SORT. Rien
   n'est envoyé, aucune vague n'est ouverte, aucun jeton n'est consommé et aucune
   date n'est posée — on peut le relancer autant de fois qu'on veut.

   ⚠️ Il ne passe SURTOUT PAS par `bcer_ouvrir_vague($dryRun = true)` : ce
   paramètre ne couvre que le SMS, la fonction poserait `vague_ouverte_at` et
   `sent_at` puis enverrait de vrais mails. Une « vérification » qui envoie n'est
   pas une vérification.

   Les lignes de signataires viennent d'être préparées ci-dessus — c'est le même
   geste que l'ouverture de l'atelier « Signataires », et c'est ce qui permet de
   vérifier les VRAIES coordonnées plutôt qu'une intention. */
if (!empty($body['verifier'])) {
    require_once dirname(__DIR__) . '/inc/bail_ceremonie.php';
    $pv = bcer_prevol($pdo, $bailId);
    exit(json_encode([
        'ok'        => true,
        'prevol'    => true,
        'pret'      => (bool)$pv['ok'],
        'bloquants' => (int)$pv['bloquants'],
        'alertes'   => (int)$pv['alertes'],
        'points'    => $pv['points'],
        'message'   => $pv['ok']
            ? ('✅ Tout est en place' . ($pv['alertes'] > 0 ? ' — ' . $pv['alertes'] . ' point(s) à connaître.' : '.'))
            : ('⛔ ' . $pv['bloquants'] . ' point(s) empêche(nt) la cérémonie de partir correctement.'),
    ], JSON_UNESCAPED_UNICODE));
}

/* « Relancer » rouvre une vague déjà ouverte. `bsig_vague_a_ouvrir()` est
   idempotente par construction (elle ignore ce qui porte `vague_ouverte_at`) —
   c'est voulu pour que deux signatures simultanées ne convoquent pas le
   mandataire deux fois. Pour un renvoi VOULU par l'agent, on desserre ce verrou
   explicitement, et pour les seuls liens encore en attente. */
if (!empty($body['relancer'])) {
    try {
        $pdo->prepare("UPDATE bail_signatures SET vague_ouverte_at = NULL
                        WHERE id_bail = ? AND vague = 1 AND statut = 'pending'")->execute([$bailId]);
    } catch (Throwable $e) { error_log('[bail_ceremonie_lancer relance] ' . $e->getMessage()); }
}

// ── Ouverture de la vague 1 : mail + SMS, même jeton ─────────────────────────
$envois = [];
try {
    /* Canal choisi par l'agent : 'tous' (mail + SMS), 'sms' seul, 'mail' seul.
       Une valeur inconnue retombe sur 'tous' — on n'invente pas un envoi restreint
       à partir d'un paramètre douteux. */
    $canaux = (string)($body['canaux'] ?? 'tous');
    if (!in_array($canaux, ['tous', 'sms', 'mail'], true)) $canaux = 'tous';
    $envois = bcer_ouvrir_vague($pdo, $bailId, 1, false, $canaux);
} catch (Throwable $e) {
    error_log('[bail_ceremonie_lancer vague] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>'Ouverture de la cérémonie impossible : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

$nomsParId = [];
foreach ($signataires as $s) $nomsParId[(int)($s['id'] ?? 0)] = trim((string)($s['nom_signataire'] ?? $s['nom'] ?? ''));

if (!$envois) {
    /* `deja_ouverte` est un DRAPEAU, pas un message à relire côté client : l'écran
       propose la relance sans avoir à reconnaître une phrase française — un texte
       qu'on reformule un jour casserait silencieusement le parcours. */
    exit(json_encode(['ok'=>false, 'deja_ouverte'=>true,
        'error'=>"La vague 1 est déjà ouverte : les liens ont déjà été émis."], JSON_UNESCAPED_UNICODE));
}

/* Ce qui est PARTI, et ce qui n'est parti à personne. Un signataire sans email ni
   mobile n'a rien reçu du tout : c'est le seul vrai blocage, il est nommé. */
$rapport = []; $bloques = []; $nbOk = 0;
foreach ($envois as $e) {
    $nom = $nomsParId[(int)$e['id']] ?? '';
    $ligne = [
        'role'  => (string)$e['role'],
        'nom'   => $nom,
        'email' => (string)($e['email'] ?? ''),
        'tel'   => (string)($e['tel'] ?? ''),
        'mail'  => (bool)$e['mail'],
        'sms'   => (bool)$e['sms'],
        // La RAISON de l'échec SMS, telle que sms_envoyer() la formule.
        'sms_error' => $e['sms_error'] ?? null,
    ];
    if ($e['mail'] || $e['sms']) { $nbOk++; }
    else {
        $ligne['raison'] = (trim((string)($e['email'] ?? '')) === '' && trim((string)($e['tel'] ?? '')) === '')
            ? 'ni email ni mobile renseignés'
            : "l'envoi a échoué (email et SMS refusés)";
        $bloques[] = $ligne;
    }
    $rapport[] = $ligne;
}

// ── Le bail passe « envoyé » dès qu'UN signataire a été touché ───────────────
$statut = (string)$r['statut'];
if ($nbOk > 0) {
    try {
        $pdo->prepare("UPDATE bien_baux SET statut='envoye', sent_at=NOW(), updated_at=NOW()
                        WHERE id=? AND statut='projet'")->execute([$bailId]);
        if ($statut === 'projet') $statut = 'envoye';
    } catch (Throwable $e) { error_log('[bail_ceremonie_lancer statut] ' . $e->getMessage()); }

    /* Gel des annexes : par ce chemin aucune pièce n'est cochée, donc la liste est
       VIDE — et c'est un cas normal (cf. api/mail_compose_send.php). On ne l'écrit
       que si rien n'a encore été figé : un envoi précédent, lui, avait peut-être
       joint des pièces, et son instantané ne doit pas être effacé. */
    try {
        $pdo->prepare("UPDATE bien_baux SET annexes_signature_json = '[]', annexes_envoye_at = NOW()
                        WHERE id = ? AND (annexes_signature_json IS NULL OR annexes_signature_json = '')")
            ->execute([$bailId]);
    } catch (Throwable $e) { error_log('[bail_ceremonie_lancer annexes] ' . $e->getMessage()); }
}

// La vague 2 (mandataire) n'est PAS convoquée ici : bcer_avancer() s'en charge à
// la dernière signature de la vague 1. On la NOMME pour que l'agent le sache.
$vague2 = [];
try {
    $st2 = $pdo->prepare("SELECT role_code, nom_signataire FROM bail_signatures
                           WHERE id_bail = ? AND vague = 2 AND statut = 'pending'");
    $st2->execute([$bailId]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v)
        $vague2[] = trim((string)($v['nom_signataire'] ?? '')) ?: (string)$v['role_code'];
} catch (Throwable $e) { error_log('[bail_ceremonie_lancer vague2] ' . $e->getMessage()); }

echo json_encode([
    'ok'      => $nbOk > 0,
    'statut'  => $statut,
    'envois'  => $rapport,
    'bloques' => $bloques,
    'vague2'  => $vague2,
    'message' => $nbOk > 0
        ? ($nbOk . ' signataire(s) prévenus.')
        : "Personne n'a pu être prévenu : aucun signataire n'a d'email ni de mobile exploitable.",
], JSON_UNESCAPED_UNICODE);
