<?php
/**
 * inc/api_helpers.php — Réponses API standardisées (Phase 3)
 *
 * Uniformise le format JSON de toutes les APIs :
 *   { "success": true/false, "message": "...", "data": {...} }
 */
declare(strict_types=1);

function api_success(array $data = [], string $message = 'OK'): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $code = 400, array $data = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
