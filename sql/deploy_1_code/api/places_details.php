<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$placeId = trim((string)($_GET['place_id'] ?? ''));

if ($placeId === '' || $GOOGLE_MAPS_API_KEY === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_place_id_or_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$params = [
    'place_id' => $placeId,
    'key' => $GOOGLE_MAPS_API_KEY,
    'fields' => 'address_component,geometry,place_id,formatted_address',
];

$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query($params);

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
if (!is_array($data) || ($data['status'] ?? '') !== 'OK') {
    echo json_encode(['ok' => false, 'error' => $data['status'] ?? 'unknown'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $data['result'] ?? [];
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

// Quartier : neighborhood > sublocality_level_1 > sublocality > political (arrondissement)
$quartier = $getComponent('neighborhood');
if ($quartier === '') $quartier = $getComponent('sublocality_level_1');
if ($quartier === '') $quartier = $getComponent('sublocality');

$lat = $result['geometry']['location']['lat'] ?? '';
$lng = $result['geometry']['location']['lng'] ?? '';

// Vérifier si l'immeuble existe déjà en BDD (par google_place_id ou adresse_cle)
$immeubleId = 0;
try {
    $pdo2 = db();
    $pid  = $result['place_id'] ?? $placeId;
    if ($pid !== '') {
        $s = $pdo2->prepare("SELECT id FROM immeubles WHERE google_place_id = ? LIMIT 1");
        $s->execute([$pid]);
        $immeubleId = (int)($s->fetchColumn() ?: 0);
    }
    if ($immeubleId === 0 && $line1 !== '' && $postal !== '' && $city !== '') {
        $cle = implode('|', [
            mb_strtolower(trim($line1)),
            '',
            mb_strtolower(trim($postal)),
            mb_strtolower(trim($city)),
        ]);
        $s2 = $pdo2->prepare("SELECT id FROM immeubles WHERE adresse_cle = ? LIMIT 1");
        $s2->execute([$cle]);
        $immeubleId = (int)($s2->fetchColumn() ?: 0);
    }
} catch (Throwable) { /* ignoré */ }

echo json_encode([
    'ok'             => true,
    'adresse_1'      => $line1,
    'adresse_2'      => '',
    'code_postal'    => $postal,
    'ville'          => $city,
    'quartier'       => $quartier,
    'pays'           => $country !== '' ? $country : 'France',
    'latitude'       => $lat,
    'longitude'      => $lng,
    'google_place_id'=> $result['place_id'] ?? $placeId,
    'adresse_formatee' => $result['formatted_address'] ?? '',
    'immeuble_id'    => $immeubleId > 0 ? $immeubleId : '',
    'immeuble_connu' => $immeubleId > 0,
], JSON_UNESCAPED_UNICODE);
