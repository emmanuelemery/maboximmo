<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$query = trim((string)($_GET['q'] ?? ''));
$country = trim((string)($_GET['country'] ?? 'fr'));

if ($query === '' || mb_strlen($query) < 3) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];

try {
    $pdo = db();
    $like = '%' . $query . '%';
    $stmt = $pdo->prepare("
        SELECT id, nom_immeuble, adresse_1, adresse_2, code_postal, ville, pays, latitude, longitude
        FROM immeubles
        WHERE adresse_1 LIKE :q
           OR ville LIKE :q
           OR code_postal LIKE :q
           OR nom_immeuble LIKE :q
        ORDER BY nom_immeuble ASC, adresse_1 ASC
        LIMIT 8
    ");
    $stmt->execute([':q' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $nom = trim((string)($row['nom_immeuble'] ?? ''));
        $adresseParts = [
            $row['adresse_1'] ?? '',
            $row['code_postal'] ?? '',
            $row['ville'] ?? '',
        ];
        $adresse = trim(implode(' ', array_filter($adresseParts)));
        $label = $nom !== '' ? $nom . ' — ' . $adresse : $adresse;
        $items[] = [
            'source' => 'local',
            'label' => $label,
            'place_id' => '',
            'payload' => [
                'immeuble_id'    => (int)($row['id'] ?? 0),
                'adresse_1'      => $row['adresse_1'] ?? '',
                'adresse_2'      => $row['adresse_2'] ?? '',
                'code_postal'    => $row['code_postal'] ?? '',
                'ville'          => $row['ville'] ?? '',
                'pays'           => $row['pays'] ?? 'France',
                'latitude'       => $row['latitude'] ?? '',
                'longitude'      => $row['longitude'] ?? '',
                'adresse_formatee' => $adresse,
                'google_place_id'  => '',
                'label'          => $label,
            ],
        ];
    }
} catch (Throwable $e) {
    // ignore DB errors for autocomplete
}

if ($GOOGLE_MAPS_API_KEY !== '') {
    $params = [
        'input' => $query,
        'key' => $GOOGLE_MAPS_API_KEY,
        'types' => 'address',
        'components' => 'country:' . ($country !== '' ? $country : 'fr'),
    ];

    $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response !== false) {
        $data = json_decode($response, true);
        if (is_array($data) && ($data['status'] ?? '') === 'OK') {
            foreach ($data['predictions'] as $prediction) {
                $items[] = [
                    'source' => 'google',
                    'label' => (string)($prediction['description'] ?? ''),
                    'place_id' => (string)($prediction['place_id'] ?? ''),
                    'payload' => [],
                ];
            }
        }
    }
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
