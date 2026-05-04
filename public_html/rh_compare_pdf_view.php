<?php
declare(strict_types=1);

/**
 * Endpoint de visualisation INLINE du PDF source d'une comparaison salaires
 * (rh_salaires_comparaisons.file_path).
 *
 * Sert le fichier avec Content-Disposition: inline pour qu'il s'affiche dans
 * un iframe (Hostinger force `attachment` sur les PDFs servis directement).
 *
 * URL : /rh_compare_pdf_view.php?id={id_comparaison}
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Paramètre id manquant.');
}

$st = $pdo->prepare("SELECT id_societe, id_agence, file_path, file_name FROM rh_salaires_comparaisons WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Comparaison introuvable.');
}
if (empty($row['file_path'])) {
    http_response_code(404);
    exit('Aucun PDF associé.');
}

// Auth : admin (id_agence dans access list) OU gestion_salaires sur l'agence
if (!rh_wf_can_access($pdo, $userId, (int)($row['id_agence'] ?? 0))) {
    http_response_code(403);
    exit('Accès refusé.');
}

$publicHtml = __DIR__;
$absPath    = $publicHtml . '/' . ltrim((string)$row['file_path'], '/');
$realPath   = realpath($absPath);
$expected   = realpath($publicHtml . '/uploads/salaires_comptable');

if ($realPath === false || $expected === false || strpos($realPath, $expected) !== 0) {
    http_response_code(403);
    exit('Path invalide.');
}
if (!is_file($realPath) || !is_readable($realPath)) {
    http_response_code(404);
    exit('Fichier introuvable sur le serveur.');
}

$filename = $row['file_name'] ?: basename($realPath);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, max-age=0, no-cache');
header('X-Frame-Options: SAMEORIGIN');

readfile($realPath);
exit;
