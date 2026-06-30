<?php
declare(strict_types=1);
/**
 * api/geo_altitude.php — Altitude (m) d'un point GPS via Google Elevation API.
 *
 * L'altitude influence la zone climatique du DPE (seuil > 800 m), d'où sa reprise
 * dès la saisie de l'adresse (Manifeste — Loi 4 : une action, plusieurs bénéfices).
 *
 * GET : lat=FLOAT&lng=FLOAT
 * Réponse : { ok, altitude:int|null, source:'google' }
 *
 * Aucune écriture. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$key = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);

if ($key === '' || $lat === 0.0 || $lng === 0.0) {
    exit(json_encode(['ok' => false, 'error' => 'lat/lng ou clé manquants']));
}

$url = 'https://maps.googleapis.com/maps/api/elevation/json?'
     . http_build_query(['locations' => $lat . ',' . $lng, 'key' => $key]);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) { exit(json_encode(['ok' => false, 'error' => $err])); }

$data = json_decode($resp, true);
if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'][0]['elevation'])) {
    exit(json_encode(['ok' => false, 'error' => $data['status'] ?? 'unknown']));
}

echo json_encode([
    'ok'       => true,
    'altitude' => (int) round((float)$data['results'][0]['elevation']),
    'source'   => 'google',
], JSON_UNESCAPED_UNICODE);
