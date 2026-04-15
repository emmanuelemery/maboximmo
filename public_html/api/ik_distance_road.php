<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$oLat = (float)($_GET['olat'] ?? 0);
$oLng = (float)($_GET['olng'] ?? 0);
$dLat = (float)($_GET['dlat'] ?? 0);
$dLng = (float)($_GET['dlng'] ?? 0);

if (!$oLat || !$oLng || !$dLat || !$dLng) {
    echo json_encode(['ok' => false, 'error' => 'Coordonnées manquantes']); exit;
}

// Charger la clé Google
$googleConfigPaths = [
    __DIR__ . '/../../u630423897/google_config.php',
    __DIR__ . '/../google_config.php',
    __DIR__ . '/../../google_config.php',
];
foreach ($googleConfigPaths as $p) {
    if (file_exists($p)) { require_once $p; break; }
}
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';

if (!$apiKey) {
    // Fallback Haversine si pas de clé
    $r    = 6371.0;
    $dLa  = deg2rad($dLat - $oLat);
    $dLo  = deg2rad($dLng - $oLng);
    $a    = sin($dLa/2)**2 + cos(deg2rad($oLat)) * cos(deg2rad($dLat)) * sin($dLo/2)**2;
    $km   = round($r * 2 * asin(sqrt($a)), 1);
    echo json_encode(['ok' => true, 'km' => $km, 'source' => 'haversine']); exit;
}

// Google Distance Matrix API (voiture)
$url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . http_build_query([
    'origins'      => "$oLat,$oLng",
    'destinations' => "$dLat,$dLng",
    'mode'         => 'driving',
    'units'        => 'metric',
    'key'          => $apiKey,
]);

$ctx = stream_context_create(['http' => ['timeout' => 8]]);
$raw = @file_get_contents($url, false, $ctx);

if (!$raw) {
    echo json_encode(['ok' => false, 'error' => 'API indisponible']); exit;
}

$data = json_decode($raw, true);
$element = $data['rows'][0]['elements'][0] ?? null;

if (!$element || ($element['status'] ?? '') !== 'OK') {
    echo json_encode(['ok' => false, 'error' => 'Trajet introuvable', 'raw_status' => $element['status'] ?? 'unknown']); exit;
}

$meters = (int)($element['distance']['value'] ?? 0);
$km     = round($meters / 1000, 1);
$duree  = $element['duration']['text'] ?? '';

echo json_encode(['ok' => true, 'km' => $km, 'duree' => $duree, 'source' => 'google']);
