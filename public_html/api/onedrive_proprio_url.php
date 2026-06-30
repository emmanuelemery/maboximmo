<?php
/**
 * api/onedrive_proprio_url.php — Renvoie l'URL web OneDrive du dossier d'un
 * propriétaire (pour l'ouvrir et y récupérer un document, ex. DPE).
 *
 * GET ?proprio_id=INT  → { ok:bool, url:string, folder?:string, error?:string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/onedrive_classer.php'; // oc_proprio_folder_url()
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = $GLOBALS['pdo'];

$pid = (int)($_GET['proprio_id'] ?? 0);
if ($pid <= 0) exit(json_encode(['ok' => false, 'error' => 'proprio_id manquant']));
if (!function_exists('graph_is_configured') || !graph_is_configured()) {
    exit(json_encode(['ok' => false, 'error' => 'Microsoft Graph non configuré']));
}
if (!function_exists('oc_proprio_folder_url')) {
    exit(json_encode(['ok' => false, 'error' => 'Module OneDrive indisponible']));
}

try {
    $res = oc_proprio_folder_url($pdo, $pid);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
