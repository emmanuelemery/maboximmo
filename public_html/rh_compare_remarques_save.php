<?php
declare(strict_types=1);
/**
 * rh_compare_remarques_save.php — Enregistre les remarques de l'agent sur une
 * comparaison salaires (rh_salaires_comparaisons.remarques). Appelé en AJAX
 * depuis le modal de comparaison. Renvoie JSON.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }
verify_csrf();

$pdo = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$compareId = (int)($_POST['compare_id'] ?? 0);
$remarques = trim((string)($_POST['remarques'] ?? ''));
if ($compareId <= 0) { echo json_encode(['ok' => false, 'error' => 'compare_id manquant']); exit; }

$st = $pdo->prepare("SELECT id_agence FROM rh_salaires_comparaisons WHERE id = ? LIMIT 1");
$st->execute([$compareId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['ok' => false, 'error' => 'introuvable']); exit; }
if (!rh_wf_can_access($pdo, $userId, (int)($row['id_agence'] ?? 0))) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'accès refusé']); exit; }

try {
    $pdo->prepare("UPDATE rh_salaires_comparaisons SET remarques = ? WHERE id = ?")->execute([$remarques !== '' ? $remarques : null, $compareId]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
