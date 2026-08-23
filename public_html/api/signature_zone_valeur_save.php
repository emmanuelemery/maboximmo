<?php
/**
 * api/signature_zone_valeur_save.php — le signataire valide SES zones.
 *
 * POST JSON : { token, valeurs:{ id_zone: {texte?, image?} }, nom } → { ok, signe, tous_signes, … }
 *
 * ⚠️ PAGE PUBLIQUE : aucune session. L'autorisation, c'est le JETON — et lui seul décide
 * de quelles zones on parle. On ne fait donc JAMAIS confiance aux identifiants de zone
 * envoyés par le client : on repart des zones du document, on garde celles qui portent le
 * rôle du porteur du jeton, et on ignore le reste. Sans ce filtre, il suffirait de
 * renvoyer l'identifiant d'une autre zone pour signer à la place de quelqu'un d'autre.
 *
 * ⚠️ L'apposition sur le PDF n'a PAS lieu ici : elle attend que tout le monde ait signé.
 * Ce qu'on enregistre, c'est la valeur — et c'est elle que la page relit pour montrer
 * immédiatement sa signature au signataire.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/bail_signature.php';
require_once dirname(__DIR__) . '/inc/signature_etat.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo  = $GLOBALS['pdo'];
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$ko = static function (string $m, int $c = 400) { http_response_code($c); exit(json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE)); };

$token = (string)($body['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) $ko('Lien invalide.');
$sig = bsig_get_by_token($pdo, $token);
if (!$sig)                                   $ko('Ce lien n\'existe pas.', 404);
if (($sig['statut'] ?? '') === 'signe')      $ko('Vous avez déjà signé ce document.');
if (bsig_is_expired($sig))                   $ko('Ce lien a expiré. Votre agence peut le réactiver en un clic.');
if ((string)($sig['objet_type'] ?? '') !== 'ged') $ko('Ce lien ne porte pas sur un document.');

$docId  = (int)$sig['objet_id'];
$sigId  = (int)$sig['id'];
$role   = (string)($sig['role_code'] ?? '');
$nom    = trim((string)($body['nom'] ?? '')) ?: trim((string)($sig['nom_signataire'] ?? ''));
if ($nom === '') $ko('Merci d\'indiquer votre nom.');

/* Les zones QUI LUI REVIENNENT — déterminées côté serveur, jamais d'après le client. */
$st = $pdo->prepare("SELECT id, type, libelle FROM signature_zones
                      WHERE ged_document_id = ? AND role_code = ?");
$st->execute([$docId, $role]);
$miennes = [];
foreach ($st as $r) { $miennes[(int)$r['id']] = $r; }
if (!$miennes) $ko('Aucune zone ne vous est attribuée sur ce document.');

$recues = is_array($body['valeurs'] ?? null) ? $body['valeurs'] : [];
$ip = function_exists('client_ip') ? (string)client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');

$n = 0; $manquantes = [];
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO signature_zone_valeurs (id_zone, id_signature, valeur_texte, valeur_image, ip)
                          VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE valeur_texte=VALUES(valeur_texte),
                                                  valeur_image=VALUES(valeur_image), signed_at=NOW()");
    foreach ($miennes as $zid => $z) {
        $v   = $recues[$zid] ?? $recues[(string)$zid] ?? null;
        $txt = $v ? trim((string)($v['texte'] ?? '')) : '';
        $img = $v ? trim((string)($v['image'] ?? '')) : '';
        /* Une image doit être un PNG en data URL — ce contenu finira dans un acte
           opposable, on ne recopie pas une chaîne arbitraire. */
        if ($img !== '' && !preg_match('~^data:image/png;base64,[A-Za-z0-9+/=]+$~', $img)) $img = '';
        if ($txt === '' && $img === '') { $manquantes[] = (string)($z['libelle'] ?: $z['type']); continue; }
        $ins->execute([$zid, $sigId, $txt !== '' ? $txt : null, $img !== '' ? $img : null, $ip ?: null]);
        $n++;
    }
    if ($manquantes) { $pdo->rollBack(); $ko('Il reste à remplir : ' . implode(', ', array_slice($manquantes, 0, 4)) . '.'); }

    /* La signature est marquée SIGNÉE — même mécanique que le bail, donc le suivi en
       huit étapes et la clôture continuent de fonctionner sans rien de spécifique. */
    $pdo->prepare("UPDATE bail_signatures SET statut='signe', signed_at=NOW(), nom_signataire=?, ip=? WHERE id=?")
        ->execute([$nom, $ip ?: null, $sigId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[signature_zone_valeur_save] ' . $e->getMessage());
    $ko('Enregistrement impossible : ' . $e->getMessage(), 500);
}

/* Tous signés ? C'est ce qui déclenchera l'apposition — une seule fois, à la fin. */
$reste = 0;
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM bail_signatures
                         WHERE objet_type='ged' AND objet_id=? AND statut <> 'signe'");
    $q->execute([$docId]);
    $reste = (int)$q->fetchColumn();
} catch (Throwable $e) {}

$tous = ($reste === 0);
$acte = null;
if ($tous) {
    /* ── LE DERNIER SIGNATAIRE DÉCLENCHE L'ACTE ────────────────────────────────────
       C'est ici, et une seule fois : `signature_finaliser()` est idempotente, donc deux
       validations à la même seconde ne produiront pas deux actes portant deux empreintes
       différentes. Non bloquant pour le signataire : sa signature est déjà enregistrée,
       et lui refuser un merci parce que l'assemblage a échoué serait lui faire porter un
       incident qui n'est pas le sien — on journalise et l'agence reprend la main. */
    try {
        require_once dirname(__DIR__) . '/inc/signature_finaliser.php';
        $r = signature_finaliser($pdo, $docId, null);
        if (!empty($r['ok'])) $acte = (int)$r['doc_id'];
        else error_log('[signature_zone_valeur_save finalisation] ' . (string)($r['error'] ?? '?'));
    } catch (Throwable $e) { error_log('[signature_zone_valeur_save finalisation] ' . $e->getMessage()); }
}

echo json_encode([
    'ok'          => true,
    'signe'       => true,
    'zones'       => $n,
    'tous_signes' => $tous,
    'reste'       => $reste,
    'message'     => $tous
        ? 'Merci — toutes les parties ont signé. Le document définitif va être établi.'
        : 'Merci — votre signature est enregistrée. ' . $reste . ' partie(s) doivent encore signer.',
], JSON_UNESCAPED_UNICODE);
