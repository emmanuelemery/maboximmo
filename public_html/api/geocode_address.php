<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$query = trim((string)($_GET['q'] ?? ''));

if ($query === '' || $GOOGLE_MAPS_API_KEY === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_query_or_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$params = [
    'address' => $query,
    'key' => $GOOGLE_MAPS_API_KEY,
];

$url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
    echo json_encode(['ok' => false, 'error' => $data['status'] ?? 'unknown'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $data['results'][0];
$components = $result['address_components'] ?? [];

$getComponent = static function (string $type) use ($components): string {
    foreach ($components as $c) {
        if (!empty($c['types']) && in_array($type, $c['types'], true)) {
            return (string)($c['long_name'] ?? '');
        }
    }
    return '';
};

$streetNumber = $getComponent('street_number');
$route = $getComponent('route');
$line1 = trim($streetNumber . ' ' . $route);
$postal = $getComponent('postal_code');
$city = $getComponent('locality');
if ($city === '') {
    $city = $getComponent('postal_town');
}
if ($city === '') {
    $city = $getComponent('administrative_area_level_2');
}
$country = $getComponent('country');

$lat = $result['geometry']['location']['lat'] ?? '';
$lng = $result['geometry']['location']['lng'] ?? '';

echo json_encode([
    'ok' => true,
    'adresse_1' => $line1,
    'adresse_2' => '',
    'code_postal' => $postal,
    'ville' => $city,
    'pays' => $country !== '' ? $country : 'France',
    'latitude' => $lat,
    'longitude' => $lng,
    'google_place_id' => '',
    'adresse_formatee' => $result['formatted_address'] ?? '',
], JSON_UNESCAPED_UNICODE);
