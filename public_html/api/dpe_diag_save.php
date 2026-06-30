<?php
/**
 * api/dpe_diag_save.php — Enregistre les champs DPE saisis dans le modal de diagnostics
 * sur le BIEN choisi (lot), via le service central dpe_enregistrer().
 *
 * POST JSON : {
 *   id_bien:int,                 // lot choisi (obligatoire)
 *   ged_document_id?:int,        // doc DPE déjà en GED (pour traçabilité/aperçu)
 *   meta?:{url,nom_orig,method,score},
 *   fields:{ dpe_classe, ges_classe, dpe_valeur, ges_valeur,
 *            dpe_valeur_conso_primaire, dpe_valeur_conso_finale,
 *            dpe_version, dpe_vierge, dpe_date_realisation,
 *            dpe_reference_certificat, date_indice_prix_energies,
 *            surface_habitable, surface_carrez, surface_sejour,
 *            nb_pieces, chauffage_energie }
 * }
 * Réponse : { ok:bool, diag_id:int, error:?string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/dpe_service.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) exit(json_encode(['ok' => false, 'error' => 'JSON invalide']));

$bienId = (int)($body['id_bien'] ?? 0);
if ($bienId <= 0) exit(json_encode(['ok' => false, 'error' => 'Choisissez un lot (bien) avant d’enregistrer.']));

$pdo = $GLOBALS['pdo'];

// Le bien doit exister
$chk = $pdo->prepare("SELECT id FROM biens WHERE id = ?");
$chk->execute([$bienId]);
if (!$chk->fetchColumn()) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable.']));

// ── Champs DPE acceptés (whitelist = ce que dpe_enregistrer sait remonter) ──
$allowed = [
    'dpe_classe', 'ges_classe', 'dpe_valeur', 'ges_valeur',
    'dpe_valeur_conso_primaire', 'dpe_valeur_conso_finale',
    'dpe_version', 'dpe_vierge', 'dpe_date_realisation',
    'dpe_reference_certificat', 'date_indice_prix_energies',
    'surface_habitable', 'surface_carrez', 'surface_sejour',
    'nb_pieces', 'chauffage_energie',
];
$in = is_array($body['fields'] ?? null) ? $body['fields'] : [];
$fields = [];
foreach ($allowed as $k) {
    if (!array_key_exists($k, $in)) continue;
    $v = $in[$k];
    if ($v === '' || $v === null) continue;
    if ($k === 'dpe_vierge') { $fields[$k] = (int)(!empty($v)); continue; }
    $fields[$k] = $v;
}
if (!$fields) exit(json_encode(['ok' => false, 'error' => 'Aucun champ DPE renseigné.']));

$metaIn = is_array($body['meta'] ?? null) ? $body['meta'] : [];
$meta = [
    'url'      => (string)($metaIn['url'] ?? ''),
    'nom_orig' => (string)($metaIn['nom_orig'] ?? ''),
    'method'   => (string)($metaIn['method'] ?? 'saisie_modal'),
    'score'    => (int)($metaIn['score'] ?? 0),
    'ged_document_id' => (int)($body['ged_document_id'] ?? 0),
];

$res = dpe_enregistrer($pdo, $bienId, $fields, $meta);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
