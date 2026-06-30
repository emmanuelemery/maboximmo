<?php
/**
 * api/tiers_quick_update.php
 *
 * Update rapide des champs principaux d'un tiers (raison_sociale, nom, prenom,
 * email, telephone). Pour édition simple depuis tiers_360.php.
 *
 * POST JSON :
 *   {
 *     "id": 5,
 *     "raison_sociale": "SARL SABY",
 *     "nom": "SABY",
 *     "prenom": "Pierre",
 *     "email": "...",
 *     "telephone": "..."
 *   }
 *
 * Auth : user authentifié + scope check societe (sauf admin).
 * Sprint R-EDIT-TIERS 2026-05-25.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']    ?? 0);
$userSocId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = ((int)($_SESSION['id_role'] ?? 0) === 1);

$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id requis']));
}

// Lecture + scope check
$st = $pdo->prepare("SELECT id, id_societe, raison_sociale, nom, prenom, email, telephone, nom_affichage
                     FROM tiers WHERE id = ?");
$st->execute([$id]);
$tiers = $st->fetch(PDO::FETCH_ASSOC);
if (!$tiers) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Tiers introuvable']));
}
if (!$isAdmin && !empty($tiers['id_societe']) && (int)$tiers['id_societe'] !== $userSocId) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Tiers hors de votre société']));
}

// Champs autorisés à modifier (whitelist sécurité)
$allowed = ['raison_sociale', 'nom', 'prenom', 'email', 'telephone', 'nom_affichage'];
$updates = [];
$params  = [];
$changes = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $body)) {
        $newVal = trim((string)$body[$field]);
        $oldVal = (string)($tiers[$field] ?? '');
        if ($newVal !== $oldVal) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $newVal !== '' ? $newVal : null;
            $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
        }
    }
}

if (empty($updates)) {
    exit(json_encode(['ok' => true, 'message' => 'Aucun changement détecté', 'changes' => []]));
}

$params[':id'] = $id;
try {
    $sql = "UPDATE tiers SET " . implode(', ', $updates) . ", date_modification = NOW() WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'UPDATE échoué : ' . $e->getMessage()]));
}

echo json_encode([
    'ok'      => true,
    'id'      => $id,
    'changes' => $changes,
    'message' => 'Tiers mis à jour (' . count($changes) . ' champ(s) modifié(s)).',
], JSON_UNESCAPED_UNICODE);
