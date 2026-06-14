<?php
// api/transaction_dossier_new_bien.php — Étape 2 du parcours « Nouveau dossier ».
// Crée un bien minimal (en vente) rattaché au propriétaire, puis ouvre son dossier.
// POST : id_proprietaire, id_tiers, designation, id_type_bien,
//        adresse_1, code_postal, ville, latitude, longitude, google_place_id, adresse_formatee
//   →  { ok, id_bien, id_dossier, url }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/immeuble_link.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idProp     = (int)(post('id_proprietaire') ?? 0);
$idTiers    = (int)(post('id_tiers') ?? 0);
$idTypeBien = (int)(post('id_type_bien') ?? 0);
$designation= trim((string)(post('designation') ?? ''));
$adresse1   = trim((string)(post('adresse_1') ?? ''));
$cp         = trim((string)(post('code_postal') ?? ''));
$ville      = trim((string)(post('ville') ?? ''));
$lat        = post('latitude') !== '' && post('latitude') !== null ? (float)post('latitude') : null;
$lng        = post('longitude') !== '' && post('longitude') !== null ? (float)post('longitude') : null;
$placeId    = trim((string)(post('google_place_id') ?? '')) ?: null;
$adrFmt     = trim((string)(post('adresse_formatee') ?? '')) ?: null;

if ($idProp <= 0)     { echo json_encode(['ok'=>false,'error'=>'propriétaire manquant']); exit; }
if ($idTypeBien <= 0) { echo json_encode(['ok'=>false,'error'=>'type de bien requis']); exit; }
if ($adresse1 === '' && $ville === '') { echo json_encode(['ok'=>false,'error'=>'adresse requise']); exit; }

try {
    // Vérifie le type de bien.
    $stTy = $pdo->prepare("SELECT label FROM base_types_bien WHERE id = ? LIMIT 1");
    $stTy->execute([$idTypeBien]);
    $typeLabel = (string)($stTy->fetchColumn() ?: '');
    if ($typeLabel === '') { echo json_encode(['ok'=>false,'error'=>'type de bien invalide']); exit; }

    // Vérifie le propriétaire + récupère son agence.
    $stP = $pdo->prepare("SELECT id, id_agence FROM proprietaires WHERE id = ? LIMIT 1");
    $stP->execute([$idProp]);
    $prop = $stP->fetch(PDO::FETCH_ASSOC);
    if (!$prop) { echo json_encode(['ok'=>false,'error'=>'propriétaire introuvable']); exit; }

    $idSoc = function_exists('current_societe_id') ? current_societe_id() : null;
    $idAge = $prop['id_agence'] ?: (function_exists('current_agence_id') ? current_agence_id() : null);
    if ($designation === '') $designation = $typeLabel . ($ville !== '' ? ' ' . $ville : '');

    // Immeuble : réutilise l'existant (sélectionné dans le modal OU même adresse),
    // sinon en crée un seul — JAMAIS de doublon (logique commune à bien_detail).
    $idImmeubleSel = (int)(post('id_immeuble_selected') ?? 0);
    $idImmeuble = immeuble_resolve($pdo, [
        'id_immeuble_selected' => $idImmeubleSel,
        'adresse_1' => $adresse1, 'code_postal' => $cp, 'ville' => $ville,
        'latitude' => $lat, 'longitude' => $lng, 'google_place_id' => $placeId,
        'id_societe' => $idSoc, 'id_agence' => $idAge,
    ]);

    $ins = $pdo->prepare("INSERT INTO biens
        (id_proprietaire, id_tiers, id_agence, id_societe, id_type_bien, id_immeuble,
         designation, type_commercialisation,
         adresse_1, code_postal, ville, latitude, longitude, precision_geoloc,
         date_creation, date_modification)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'vente', ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $ins->execute([
        $idProp, $idTiers ?: null, $idAge, $idSoc, $idTypeBien, $idImmeuble ?: null,
        $designation,
        $adresse1 ?: null, $cp ?: null, $ville ?: null, $lat, $lng, $placeId ? 'google' : null,
    ]);
    $idBien = (int)$pdo->lastInsertId();

    // Ouvre le dossier (crée + seed vendeur depuis le propriétaire) à l'étape estimation.
    $idDossier = dv_ensure_for_bien($pdo, $idBien, ['source' => 'manual', 'id_user' => current_user_id()]);

    echo json_encode([
        'ok'         => true,
        'id_bien'    => $idBien,
        'id_dossier' => $idDossier,
        'id_immeuble'=> $idImmeuble ?: null,
        // On ouvre le détail du bien à compléter (proprio + immeuble déjà liés),
        // avec un retour direct vers le dossier.
        'url'         => app_url('/bien_detail.php?edit=' . $idBien . '&return_dossier=' . $idDossier),
        'url_dossier' => app_url('/transaction_dossier.php?id=' . $idDossier),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_new_bien] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
