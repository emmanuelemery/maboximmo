<?php
/**
 * api/mandat_doublon_flag.php — Marque / démarque une ligne de mandat comme doublon
 * (masquage réversible du registre — rien n'est supprimé, aucun coût IA).
 * POST : id, flag (1=doublon, 0=restaurer). Sécurité : admin + CSRF (form 'mandat_extraire').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/mandat_registre.php';
require_admin_or_super_admin();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('mandat_extraire');

/** @var PDO $pdo */
$pdo  = $GLOBALS['pdo'];
$id   = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
$flag = !empty($_POST['flag']) && $_POST['flag'] !== '0' ? 1 : 0;
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id requis']); exit; }
if (!mr_col_exists($pdo, 'is_doublon')) { echo json_encode(['ok'=>false,'error'=>'migration is_doublon non jouée']); exit; }

try {
    $pdo->prepare("UPDATE mandats_registre SET is_doublon=?, updated_at=NOW() WHERE id=?")->execute([$flag, $id]);
    echo json_encode(['ok'=>true, 'id'=>$id, 'is_doublon'=>$flag], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'SQL : '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
