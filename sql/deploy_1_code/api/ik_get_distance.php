<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/**
 * Calcule la distance Haversine entre deux points GPS (en km, arrondie à 0.1)
 */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    $c = 2 * asin(sqrt($a));
    return round($r * $c, 1);
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['error' => 'DB indisponible']); exit; }

$departType     = $_GET['depart_type'] ?? 'agence';
$departId       = (int)($_GET['depart_id'] ?? 0);
$departLat      = isset($_GET['depart_lat']) ? (float)$_GET['depart_lat'] : null;
$departLng      = isset($_GET['depart_lng']) ? (float)$_GET['depart_lng'] : null;
$immeubleId     = (int)($_GET['immeuble_id'] ?? 0);
$userId         = current_user_id();

// --- Résoudre les coordonnées du départ ---
if ($departType === 'agence' && $departId > 0) {
    $stmt = $pdo->prepare("SELECT latitude, longitude, nom_agence, adresse_1, ville FROM agences WHERE id = ?");
    $stmt->execute([$departId]);
    $ag = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ag && $ag['latitude']) {
        $departLat   = (float)$ag['latitude'];
        $departLng   = (float)$ag['longitude'];
        $departLabel = trim(($ag['nom_agence'] ?? '') . ' - ' . ($ag['adresse_1'] ?? '') . ' ' . ($ag['ville'] ?? ''));
    }
} elseif ($departType === 'domicile') {
    $stmt = $pdo->prepare("SELECT latitude, longitude, adresse, ville FROM rh_user_domicile WHERE id_user = ?");
    $stmt->execute([$userId]);
    $dom = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dom && $dom['latitude']) {
        $departLat   = (float)$dom['latitude'];
        $departLng   = (float)$dom['longitude'];
        $departLabel = 'Domicile - ' . ($dom['adresse'] ?? '') . ' ' . ($dom['ville'] ?? '');
    }
}

// --- Résoudre les coordonnées de la destination ---
$destLat = $destLng = null;
$destLabel = '';
$destVille = '';
if ($immeubleId > 0) {
    $stmt = $pdo->prepare("SELECT latitude, longitude, nom_immeuble, adresse_1, code_postal, ville FROM immeubles WHERE id = ?");
    $stmt->execute([$immeubleId]);
    $imm = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($imm && $imm['latitude']) {
        $destLat   = (float)$imm['latitude'];
        $destLng   = (float)$imm['longitude'];
        $destLabel = ($imm['nom_immeuble'] ?? '') . ' - ' . ($imm['adresse_1'] ?? '');
        $destVille = ($imm['ville'] ?? '');
    }
}

// --- Calculer ---
$distance = null;
if ($departLat !== null && $departLng !== null && $destLat !== null && $destLng !== null) {
    $distance = haversine($departLat, $departLng, $destLat, $destLng);
}

echo json_encode([
    'distance'      => $distance,
    'depart_lat'    => $departLat,
    'depart_lng'    => $departLng,
    'depart_label'  => $departLabel ?? '',
    'dest_lat'      => $destLat,
    'dest_lng'      => $destLng,
    'dest_label'    => $destLabel,
    'dest_ville'    => $destVille,
]);
