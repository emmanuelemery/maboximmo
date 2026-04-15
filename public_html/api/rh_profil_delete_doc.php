<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
// Rewired to use rh_documents as single source of truth
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$meId   = current_user_id();
$roleId = current_role_id();
$data   = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { $data = []; }
$docId  = (int)($data['doc_id'] ?? ($_POST['doc_id'] ?? 0));

if ($docId <= 0) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'doc_id manquant']); exit;
}

// Source unique : rh_documents — filtre tenant centralisé via SecurityGuard
$stmt = $pdo->prepare("SELECT * FROM rh_documents WHERE id = ?" . SecurityGuard::sqlAnd('id_user'));
$stmt->execute(array_merge([$docId], SecurityGuard::sqlParams('id_user')));
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Document introuvable']); exit; }

// Supprimer le fichier physique
$path = !empty($doc['file_path']) ? $doc['file_path']
      : (__DIR__ . '/../uploads/rh_docs/' . $doc['id_user'] . '/' . $doc['filename']);
if ($path && file_exists($path)) unlink($path);

// Soft delete — même filtre tenant centralisé
$pdo->prepare("UPDATE rh_documents SET actif = 0 WHERE id = ?" . SecurityGuard::sqlAnd('id_user'))
    ->execute(array_merge([$docId], SecurityGuard::sqlParams('id_user')));

// Audit trail RGPD
AuditLog::log($pdo, 'DELETE', 'rh_documents', $docId, $doc);

echo json_encode(['success' => true]);