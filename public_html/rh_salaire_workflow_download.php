<?php
declare(strict_types=1);

/**
 * Endpoint de téléchargement des fichiers du workflow comptable salaires.
 *
 * Sécurité :
 *   - Authentification obligatoire (require_login)
 *   - Role check : admin OU gestion_salaires=1 sur l'agence du fichier
 *   - Path traversal bloqué (le path est issu de la BDD, pas du POST/GET direct)
 *
 * URL : /rh_salaire_workflow_download.php?id={id_workflow_log}
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_login();

$pdo = $GLOBALS['pdo'];
$userId = (int)current_user_id();

$logId = (int)($_GET['id'] ?? 0);
if ($logId <= 0) {
    http_response_code(400);
    exit('Paramètre id manquant.');
}

$st = $pdo->prepare("
    SELECT id, id_societe, id_agence, mois_reference, type_action, iteration,
           fichier_path, fichier_nom_original
    FROM rh_salaire_workflow_log
    WHERE id = ? LIMIT 1
");
$st->execute([$logId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Log workflow introuvable.');
}

if (empty($row['fichier_path'])) {
    http_response_code(404);
    exit('Aucun fichier associé à cette action.');
}

// Vérifie le droit d'accès via le helper centralisé
if (!rh_wf_can_access($pdo, $userId, (int)$row['id_agence'])) {
    http_response_code(403);
    exit('Accès refusé.');
}

// Construire le path absolu et vérifier qu'il reste dans uploads/rh_salaires/
$publicHtml = __DIR__;
$absPath = $publicHtml . '/' . ltrim((string)$row['fichier_path'], '/');
$realPath = realpath($absPath);
$expectedPrefix = realpath($publicHtml . '/uploads/rh_salaires');

if ($realPath === false || $expectedPrefix === false || strpos($realPath, $expectedPrefix) !== 0) {
    http_response_code(403);
    exit('Path invalide.');
}

if (!is_file($realPath) || !is_readable($realPath)) {
    http_response_code(404);
    exit('Fichier introuvable sur le serveur.');
}

$filename = $row['fichier_nom_original'] ?: basename($realPath);
$mime = mime_content_type($realPath) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, max-age=0, no-cache');

readfile($realPath);
exit;
