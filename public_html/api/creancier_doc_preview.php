<?php
/**
 * api/creancier_doc_preview.php — Sert le PDF d'une analyse créancier (inline, viewer).
 *
 * Stream sécurisé du fichier stocké (creancier_doc_analyse.storage_path) pour
 * affichage dans l'iframe de la page review. Manager + login requis.
 *
 * GET : analyse_id (requis).
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); exit('Accès refusé'); }

$pdo = $GLOBALS['pdo'];
$id  = (int)($_GET['analyse_id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('analyse_id requis'); }

$st = $pdo->prepare("SELECT storage_path, file_mime, file_name FROM creancier_doc_analyse WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['storage_path']) || !is_file($row['storage_path'])) {
    http_response_code(404); exit('Fichier introuvable');
}

$mime = $row['file_mime'] ?: 'application/pdf';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($row['file_name'] ?: 'document.pdf') . '"');
header('Content-Length: ' . filesize($row['storage_path']));
header('X-Content-Type-Options: nosniff');
readfile($row['storage_path']);
