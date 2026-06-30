<?php
declare(strict_types=1);
/**
 * api/immeuble_enrichir_save.php — Persiste l'enrichissement public sur l'immeuble.
 *
 * Écrit (Manifeste Loi 2) les données publiques retrouvées depuis l'adresse :
 *   - champs first-class : parcelle_reference, altitude, zone_plu,
 *     registre_copro_immatriculation/periode/maj, copro_nb_lots
 *   - snapshot complet par source dans enrichissement_public_json
 *   - une date de mise à jour par source (enrichi_cadastre/registre/risques_le)
 *
 * Sauvegarde PARTIELLE possible : seules les sources fournies sont mises à jour
 * (bouton 🔄 par bloc). Les autres champs/dates sont préservés.
 *
 * POST JSON : { immeuble_id, csrf, cadastre?, plu?, risques?, registre? }
 * Réponse : { ok, dates:{cadastre,registre,risques} }
 *
 * Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) exit(json_encode(['ok'=>false,'error'=>'JSON invalide']));

$_POST['csrf_token'] = (string)($body['csrf'] ?? ''); // verify_csrf lit $_POST['csrf_token'] (token en JSON)
verify_csrf('immeuble_enrichir');

$pdo   = $GLOBALS['pdo'];
$immId = (int)($body['immeuble_id'] ?? 0);
if ($immId <= 0) exit(json_encode(['ok'=>false,'error'=>'immeuble_id requis']));

$st = $pdo->prepare("SELECT id, enrichissement_public_json FROM immeubles WHERE id = ? LIMIT 1");
$st->execute([$immId]);
$imm = $st->fetch(PDO::FETCH_ASSOC);
if (!$imm) exit(json_encode(['ok'=>false,'error'=>'Immeuble introuvable']));

$snap = json_decode((string)($imm['enrichissement_public_json'] ?? ''), true);
if (!is_array($snap)) $snap = [];

$now = date('Y-m-d H:i:s');
$set = []; $par = [];
$dates = ['cadastre'=>null, 'registre'=>null, 'risques'=>null];

// ── CADASTRE + PLU + altitude ──
$cad = is_array($body['cadastre'] ?? null) ? $body['cadastre'] : null;
$plu = is_array($body['plu'] ?? null) ? $body['plu'] : null;
$alt = $body['altitude'] ?? null;
if ($cad !== null || $plu !== null || $alt !== null) {
    if ($cad !== null) {
        $snap['cadastre'] = $cad + ['saved_at'=>$now];
        if (!empty($cad['reference'])) { $set[]='parcelle_reference = ?'; $par[]=substr((string)$cad['reference'],0,20); }
    }
    if ($plu !== null) {
        $snap['plu'] = $plu + ['saved_at'=>$now];
        if (!empty($plu['type'])) { $set[]='zone_plu = ?'; $par[]=substr((string)$plu['type'],0,20); }
    }
    if ($alt !== null && $alt !== '') { $set[]='altitude = ?'; $par[]=(int)$alt; }
    $set[]='enrichi_cadastre_le = ?'; $par[]=$now; $dates['cadastre']=$now;
}

// ── REGISTRE COPRO (RNC) ──
$reg = is_array($body['registre'] ?? null) ? $body['registre'] : null;
if ($reg !== null) {
    $snap['registre'] = $reg + ['saved_at'=>$now];
    if (!empty($reg['immatriculation'])) { $set[]='registre_copro_immatriculation = ?'; $par[]=substr((string)$reg['immatriculation'],0,20); }
    if (!empty($reg['construction']))    { $set[]='registre_copro_periode = ?';        $par[]=substr((string)$reg['construction'],0,40); }
    if (!empty($reg['date_maj']))        { $set[]='registre_copro_maj = ?';            $par[]=substr((string)$reg['date_maj'],0,10); }
    if (!empty($reg['nb_lots']) && (int)$reg['nb_lots']>0) { $set[]='copro_nb_lots = ?'; $par[]=(int)$reg['nb_lots']; }
    $set[]='enrichi_registre_le = ?'; $par[]=$now; $dates['registre']=$now;
}

// ── RISQUES (ERP) ──
$ris = is_array($body['risques'] ?? null) ? $body['risques'] : null;
if ($ris !== null) {
    $snap['risques'] = $ris + ['saved_at'=>$now];
    $set[]='enrichi_risques_le = ?'; $par[]=$now; $dates['risques']=$now;
}

if (!$set) exit(json_encode(['ok'=>false,'error'=>'Aucune source fournie']));

// Détail complet par source (titres + items) → pour les modals de lecture en fiche 360
if (isset($body['details']) && is_array($body['details'])) { $snap['details'] = $body['details']; }

// snapshot JSON toujours réécrit
$set[]='enrichissement_public_json = ?'; $par[]=json_encode($snap, JSON_UNESCAPED_UNICODE);
$par[]=$immId;

$sql = "UPDATE immeubles SET " . implode(', ', $set) . " WHERE id = ?";
try {
    $pdo->prepare($sql)->execute($par);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
}

// ── Ingestion AUTO des documents publics dans la GED (ERP + photos) ──
// Déclenchée dès qu'on récupère les données publiques. Idempotent (dédup hash).
$docsAuto = ['erp'=>null,'photos'=>0];
try {
    $stGeo = $pdo->prepare("SELECT latitude, longitude, google_place_id FROM immeubles WHERE id=? LIMIT 1");
    $stGeo->execute([$immId]);
    $geo = $stGeo->fetch(PDO::FETCH_ASSOC) ?: [];
    $lat = (float)($geo['latitude'] ?? 0); $lng = (float)($geo['longitude'] ?? 0);
    $placeId = (string)($geo['google_place_id'] ?? '');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($ris !== null && $lat && $lng) {
        require_once __DIR__ . '/../inc/erp_ingest.php';
        $erp = erp_ingest_to_ged($pdo, $immId, $lat, $lng, ['user_id'=>$uid]);
        $docsAuto['erp'] = !empty($erp['ok']) ? ($erp['doc_id'] ?? true) : null;
    }
    if ($placeId !== '') {
        require_once __DIR__ . '/../inc/photos_ingest.php';
        $ph = photos_ingest_to_ged($pdo, $immId, $placeId, ['user_id'=>$uid]);
        $docsAuto['photos'] = (int)($ph['count'] ?? 0);
    }
} catch (Throwable) {}

// ── VALIDATION de l'immeuble (après chargement des données publiques) ──
// On valide une fois, si pas déjà fait. Best-effort (colonnes ajoutées par migration).
$valideLe = null;
try {
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $pdo->prepare("UPDATE immeubles SET valide_le = NOW(), valide_par = ? WHERE id = ? AND valide_le IS NULL")
        ->execute([$uid, $immId]);
    $valideLe = (string)$pdo->query("SELECT valide_le FROM immeubles WHERE id=".(int)$immId)->fetchColumn();
} catch (Throwable) {}

echo json_encode(['ok'=>true, 'immeuble_id'=>$immId, 'dates'=>$dates, 'docs_auto'=>$docsAuto, 'valide_le'=>$valideLe], JSON_UNESCAPED_UNICODE);
