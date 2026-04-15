<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo  = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$type = $_GET['type'] ?? '';  // 'immeuble', 'agence', 'domicile'
$id   = (int)($_GET['id']   ?? 0);

if (!$id || !in_array($type, ['immeuble', 'agence', 'domicile'])) {
    echo json_encode(['ok' => false, 'error' => 'Paramètres invalides']); exit;
}

// ── Récupérer l'adresse selon le type ────────────────────────────────────────
if ($type === 'immeuble') {
    $stmt = $pdo->prepare("SELECT adresse_1, code_postal, ville FROM immeubles WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $adresse = trim(($row['adresse_1'] ?? '') . ' ' . ($row['code_postal'] ?? '') . ' ' . ($row['ville'] ?? ''));
    $table   = 'immeubles';
} elseif ($type === 'agence') {
    $stmt = $pdo->prepare("SELECT adresse_1, code_postal, ville FROM agences WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $adresse = trim(($row['adresse_1'] ?? '') . ' ' . ($row['code_postal'] ?? '') . ' ' . ($row['ville'] ?? ''));
    $table   = 'agences';
} else {
    // Domicile : vérifier que l'utilisateur geocode son propre domicile
    $currentUserId = current_user_id();
    if ($id !== $currentUserId) { echo json_encode(['ok' => false, 'error' => 'Accès refusé']); exit; }
    $stmt = $pdo->prepare("SELECT adresse, code_postal, ville FROM rh_user_domicile WHERE id_user = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $adresse = trim(($row['adresse'] ?? '') . ' ' . ($row['code_postal'] ?? '') . ' ' . ($row['ville'] ?? ''));
    $table   = 'rh_user_domicile';
}

if (!$row || !$adresse) {
    echo json_encode(['ok' => false, 'error' => 'Adresse introuvable']); exit;
}

// ── Clé Google Maps ───────────────────────────────────────────────────────────
// Charger depuis le fichier de config réel (chemin absolu)
$googleConfigPaths = [
    __DIR__ . '/../../u630423897/google_config.php',
    __DIR__ . '/../google_config.php',
    __DIR__ . '/../../google_config.php',
];
foreach ($googleConfigPaths as $p) {
    if (file_exists($p)) { require_once $p; break; }
}
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : ($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');

// ── Géocodage : Google en priorité, Nominatim en fallback ────────────────────
$lat = null; $lng = null;

if ($apiKey !== '') {
    // Google Geocoding API
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address' => $adresse . ', France',
        'key'     => $apiKey,
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        if (($data['status'] ?? '') === 'OK' && !empty($data['results'][0]['geometry']['location'])) {
            $loc = $data['results'][0]['geometry']['location'];
            $lat = round((float)$loc['lat'], 7);
            $lng = round((float)$loc['lng'], 7);
        }
    }
}

if (!$lat) {
    // Fallback Nominatim (OpenStreetMap)
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $adresse . ', France',
        'format' => 'json',
        'limit'  => 1,
    ]);
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: MaBoxImmo/1.0\r\n",
        'timeout' => 8,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $results = json_decode($raw, true);
        if (!empty($results[0]['lat'])) {
            $lat = round((float)$results[0]['lat'], 7);
            $lng = round((float)$results[0]['lon'], 7);
        }
    }
}

if (!$lat) {
    echo json_encode(['ok' => false, 'error' => 'Adresse non trouvée : ' . $adresse]); exit;
}

// ── Sauvegarder en base ───────────────────────────────────────────────────────
$pkCol = ($type === 'domicile') ? 'id_user' : 'id';
$upd = $pdo->prepare("UPDATE `$table` SET latitude = ?, longitude = ? WHERE `$pkCol` = ?");
$upd->execute([$lat, $lng, $id]);

echo json_encode(['ok' => true, 'lat' => $lat, 'lng' => $lng]);
