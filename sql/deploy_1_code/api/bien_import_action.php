<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false]));
}

verify_csrf_any('ajouter_bien');

$data      = json_decode(file_get_contents('php://input'), true);
$action    = trim((string)($data['action']    ?? ''));
$fichierId = (int)($data['fichier_id'] ?? 0);

if (!$fichierId || !$action) {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres manquants']));
}

// Vérifie appartenance société
$check = $pdo->prepare("SELECT f.id FROM bien_import_fichiers f JOIN bien_imports i ON i.id = f.id_import WHERE f.id = ? AND i.id_societe = ?");
$check->execute([$fichierId, $societeId]);
if (!$check->fetchColumn()) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Accès refusé']));
}

match ($action) {
    'ignorer' => $pdo->prepare("UPDATE bien_import_fichiers SET statut = 'ignored', updated_at = NOW() WHERE id = ?")->execute([$fichierId]),
    'reinit'  => $pdo->prepare("UPDATE bien_import_fichiers SET statut = 'analysed', updated_at = NOW() WHERE id = ? AND statut = 'ignored'")->execute([$fichierId]),
    default   => null,
};

echo json_encode(['ok' => true]);
