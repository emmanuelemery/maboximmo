<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();

if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$data    = json_decode(file_get_contents('php://input'), true) ?: [];
$adresse = trim($data['adresse'] ?? '');
$cp      = trim($data['code_postal'] ?? '');
$ville   = trim($data['ville'] ?? '');
$lat     = isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null;
$lng     = isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null;

$stmt = $pdo->prepare("INSERT INTO rh_user_domicile (id_user, adresse, code_postal, ville, latitude, longitude)
    VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE adresse=VALUES(adresse), code_postal=VALUES(code_postal),
    ville=VALUES(ville), latitude=VALUES(latitude), longitude=VALUES(longitude)");
$stmt->execute([$userId, $adresse, $cp, $ville, $lat, $lng]);

echo json_encode(['ok' => true]);
