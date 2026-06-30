<?php
declare(strict_types=1);
/**
 * api/places_photos.php — Photos Google Places d'un lieu (si elles existent).
 *
 * Beaucoup d'adresses résidentielles n'ont aucune photo Places : dans ce cas on
 * renvoie une liste vide et la page n'affiche rien (pas de bloc vide). Quand le
 * lieu est connu (commerce, POI, résidence), Google fournit des photos.
 *
 * GET : place_id=STRING [&max=INT]
 * Réponse : { ok, photos:[url,...] }
 *
 * Lecture seule. Accès admin / super admin (page pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();

header('Content-Type: application/json; charset=utf-8');

$key = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
$placeId = trim((string)($_GET['place_id'] ?? ''));
$max = max(1, min(8, (int)($_GET['max'] ?? 6)));
if ($placeId === '' || $key === '') { exit(json_encode(['ok' => true, 'photos' => []])); }

$url = 'https://maps.googleapis.com/maps/api/place/details/json?'
     . http_build_query(['place_id' => $placeId, 'fields' => 'photo', 'key' => $key]);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
$r = curl_exec($ch);
curl_close($ch);

$d = is_string($r) ? json_decode($r, true) : null;
$photos = [];
if (is_array($d) && ($d['status'] ?? '') === 'OK') {
    foreach (($d['result']['photos'] ?? []) as $ph) {
        $ref = $ph['photo_reference'] ?? '';
        if ($ref === '') continue;
        $photos[] = 'https://maps.googleapis.com/maps/api/place/photo?'
                  . http_build_query(['maxwidth' => 640, 'photo_reference' => $ref, 'key' => $key]);
        if (count($photos) >= $max) break;
    }
}

echo json_encode(['ok' => true, 'photos' => $photos], JSON_UNESCAPED_UNICODE);
