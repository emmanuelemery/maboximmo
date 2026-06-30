<?php
declare(strict_types=1);
/**
 * api/immeuble_reconnaitre.php — Reconnaissance anti-doublon d'un immeuble + aperçu.
 *
 * CRITIQUE (anti-doublon) : `places_details.php` ne matche que par google_place_id
 * ou par un adresse_cle au format `|` (pipes), alors que les immeubles sont créés
 * avec un adresse_cle au format ESPACES (cf. api/bien_autosave.php). Les deux ne
 * matchent jamais → faux « nouveau » → DOUBLONS.
 *
 * Clé anti-doublon CANONIQUE = coordonnées GPS Google : tous les immeubles ont été
 * géocodés (admin/admin_immeubles_geocode_batch.php → latitude/longitude + gps_source).
 * Le google_place_id stocké vient de la Geocoding API ; la page envoie celui de la
 * Places API → souvent DIFFÉRENTS pour la même adresse. Donc le GPS prime sur le place_id.
 *
 * Ordre de reconnaissance :
 *   1) GPS de proximité (≈ même point Google → même immeuble)   ← canonique
 *   2) google_place_id (au cas où il coïncide)
 *   3) adresse_cle = lower(trim("a1 a2 cp ville"))   (format ESPACES, comme bien_autosave)
 *   4) adresse normalisée : adresse_1 + code_postal + ville (LOWER/TRIM)
 *
 * GET : adresse_1, code_postal, ville, [adresse_2], [place_id], [lat], [lng]
 * Réponse : { ok, connu:bool, immeuble_id, nom_immeuble, nb_biens, nb_infos, infos:[{label,value}] }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];

$a1  = trim((string)($_GET['adresse_1']  ?? ''));
$a2  = trim((string)($_GET['adresse_2']  ?? ''));
$cp  = trim((string)($_GET['code_postal']?? ''));
$vl  = trim((string)($_GET['ville']      ?? ''));
$pid = trim((string)($_GET['place_id']   ?? ''));
$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);

$immId = 0; $matchMode = '';

// 1) GPS de proximité (clé canonique : tous les immeubles sont géocodés Google).
//    Tolérance ≈ 0.00025° (~25 m) : les deux points venant de Google sont quasi
//    identiques pour la même adresse, mais on garde une marge toit/entrée.
if ($lat !== 0.0 && $lng !== 0.0) {
    try {
        $s = $pdo->prepare(
            "SELECT id FROM immeubles
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
               AND ABS(latitude - ?)  < 0.00025
               AND ABS(longitude - ?) < 0.00030
             ORDER BY (POW(latitude - ?, 2) + POW(longitude - ?, 2)) ASC
             LIMIT 1");
        $s->execute([$lat, $lng, $lat, $lng]); $immId = (int)$s->fetchColumn();
        if ($immId > 0) $matchMode = 'gps';
    } catch (Throwable) {}
}
// 2) google_place_id (au cas où il coïncide)
if ($immId <= 0 && $pid !== '') {
    try {
        $s = $pdo->prepare("SELECT id FROM immeubles WHERE google_place_id = ? LIMIT 1");
        $s->execute([$pid]); $immId = (int)$s->fetchColumn();
        if ($immId > 0) $matchMode = 'place_id';
    } catch (Throwable) {}
}
// 3) adresse_cle format ESPACES (canonique, comme bien_autosave)
if ($immId <= 0) {
    $cle = mb_strtolower(trim("$a1 $a2 $cp $vl"));
    if ($cle !== '') {
        try {
            $s = $pdo->prepare("SELECT id FROM immeubles WHERE adresse_cle = ? LIMIT 1");
            $s->execute([$cle]); $immId = (int)$s->fetchColumn();
            if ($immId > 0) $matchMode = 'adresse_cle';
        } catch (Throwable) {}
    }
}
// 4) adresse normalisée (fallback robuste)
if ($immId <= 0 && $a1 !== '') {
    try {
        $s = $pdo->prepare("SELECT id FROM immeubles
                            WHERE LOWER(TRIM(adresse_1)) = LOWER(TRIM(?))
                              AND COALESCE(TRIM(code_postal),'') = COALESCE(TRIM(?),'')
                              AND LOWER(TRIM(COALESCE(ville,''))) = LOWER(TRIM(?)) LIMIT 1");
        $s->execute([$a1, $cp, $vl]); $immId = (int)$s->fetchColumn();
        if ($immId > 0) $matchMode = 'adresse';
    } catch (Throwable) {}
}

if ($immId <= 0) {
    exit(json_encode(['ok' => true, 'connu' => false, 'immeuble_id' => 0, 'match' => '', 'nb_infos' => 0, 'infos' => []], JSON_UNESCAPED_UNICODE));
}

// ── Aperçu des infos reprenables (mêmes libellés que immeuble_apercu) ──
$st = $pdo->prepare("SELECT * FROM immeubles WHERE id = ? LIMIT 1");
$st->execute([$immId]);
$imm = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// Whitelist = infos MÉTIER de l'immeuble UNIQUEMENT. On exclut volontairement
// adresse/cp/ville/quartier/pays/lat/long : déjà affichés une fois côté page
// (adresse normalisée Google + GPS) → pas de redondance, compteur honnête.
$labels = [
    'nom_immeuble' => "Nom de l'immeuble", 'reference_immeuble' => 'Référence immeuble',
    'type_immeuble' => "Type d'immeuble",
    'annee_construction' => 'Année de construction', 'periode_construction' => 'Période de construction',
    'nb_lots' => 'Nombre de lots', 'copro_nb_lots' => 'Lots (copropriété)', 'nb_etages' => "Nombre d'étages",
    'nb_appartements' => "Nombre d'appartements", 'ascenseur' => 'Ascenseur', 'gardien' => 'Gardien',
    'chauffage_collectif' => 'Chauffage collectif', 'copro_budget_previsionnel_annuel' => 'Budget prévisionnel copro',
    'copro_tantiemes_total' => 'Tantièmes totaux', 'syndic_nom' => 'Syndic',
];
$infos = [];
foreach ($labels as $col => $label) {
    if (!array_key_exists($col, $imm)) continue;
    $v = $imm[$col];
    if ($v === null || $v === '' || $v === '0' || $v === 0) continue;
    // Anti-bruit : un type générique ("immeuble"/"autre"/"bâtiment") n'apporte rien → on l'omet.
    if ($col === 'type_immeuble' && in_array(mb_strtolower(trim((string)$v)), ['immeuble','autre','batiment','bâtiment',''], true)) continue;
    if (in_array($col, ['ascenseur','gardien','chauffage_collectif'], true)) $v = 'Oui';
    if (in_array($col, ['latitude','longitude'], true)) $v = number_format((float)$v, 5, '.', '');
    $infos[] = ['label' => $label, 'value' => (string)$v];
}

$nbBiens = 0;
try {
    $sb = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE id_immeuble = ?");
    $sb->execute([$immId]); $nbBiens = (int)$sb->fetchColumn();
} catch (Throwable) {}

echo json_encode([
    'ok' => true, 'connu' => true, 'immeuble_id' => $immId, 'match' => $matchMode,
    'nom_immeuble' => (string)($imm['nom_immeuble'] ?? ''),
    'nb_biens' => $nbBiens, 'nb_infos' => count($infos), 'infos' => $infos,
    // Fraîcheur de l'enrichissement public déjà persisté (pour les pastilles + boutons 🔄)
    'enrichi' => [
        'cadastre' => $imm['enrichi_cadastre_le'] ?? null,
        'registre' => $imm['enrichi_registre_le'] ?? null,
        'risques'  => $imm['enrichi_risques_le']  ?? null,
    ],
], JSON_UNESCAPED_UNICODE);
