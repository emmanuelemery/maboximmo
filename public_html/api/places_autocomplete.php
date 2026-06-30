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
    // Recherche ACTIVE par MOTS : chaque mot saisi doit se retrouver (dans
    // n'importe quel ordre) dans l'adresse complète. Ainsi « 13 louis blan »
    // matche « 13 Rue Louis Blanc 69250 Neuville » (le LIKE plein n'y arrivait pas
    // à cause du mot « Rue » intercalé).
    $haystack = "CONCAT_WS(' ', adresse_1, adresse_2, code_postal, ville, nom_immeuble)";
    $tokens = array_values(array_filter(preg_split('/\s+/', $query) ?: [], fn($t) => $t !== ''));
    if (!$tokens) { $tokens = [$query]; }
    $where = [];
    $args  = [];
    foreach ($tokens as $tok) {
        $where[] = "$haystack LIKE ?";
        $args[]  = '%' . $tok . '%';
    }
    $stmt = $pdo->prepare("
        SELECT id, nom_immeuble, adresse_1, adresse_2, code_postal, ville, pays, latitude, longitude
        FROM immeubles
        WHERE " . implode(' AND ', $where) . "
        ORDER BY nom_immeuble ASC, adresse_1 ASC
        LIMIT 8
    ");
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dédoublonnage : si plusieurs lignes pointent la même adresse (doublons base),
    // on n'en affiche qu'UNE. On garde en priorité celle qui a des coordonnées GPS.
    $seen = [];
    $uniq = [];
    foreach ($rows as $row) {
        $key = mb_strtolower(trim(
            preg_replace('/\s+/', ' ',
                ($row['adresse_1'] ?? '') . '|' . ($row['code_postal'] ?? '') . '|' . ($row['ville'] ?? '')
            )
        ));
        $hasGps = ($row['latitude'] ?? '') !== '' && $row['latitude'] !== null
               && ($row['longitude'] ?? '') !== '' && $row['longitude'] !== null;
        if (!isset($seen[$key])) {
            $seen[$key] = count($uniq);
            $uniq[] = $row;
        } elseif ($hasGps) {
            // remplace la version sans GPS déjà retenue par celle qui a les coords
            $uniq[$seen[$key]] = $row;
        }
    }
    $rows = $uniq;

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
