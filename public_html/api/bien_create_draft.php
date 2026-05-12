<?php
declare(strict_types=1);

// api/bien_create_draft.php — Crée un bien brouillon (AJAX) pour l’intake/analyse
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_form_loader.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);

try {
    if ($societeId <= 0 || $agenceId <= 0) {
        exit(json_encode(['ok' => false, 'error' => 'Session agence/société invalide']));
    }

    $bienId = bien_form_create_draft($pdo, $societeId ?: null, $agenceId ?: null, null, $userId ?: null);
    echo json_encode([
        'ok' => true,
        'bien_id' => $bienId,
        'url_edit' => app_url('/bien_detail.php?edit=' . $bienId . '&from=dpe_analyse'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

