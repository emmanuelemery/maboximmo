<?php
declare(strict_types=1);
/**
 * api/transaction_dossier_checklist_upload.php
 * Upload d'une pièce de la CHECKLIST « documents à récupérer » d'un dossier de vente.
 * Classe en GED avec le TYPE PRÉCIS (pv_assemblee, dpe, cni…) sur la BONNE entité
 * (IMMEUBLE / TIERS / BIEN, résolue depuis le dossier) + liens DOSSIER & BIEN.
 *
 * POST multipart : id_dossier, doc_type, entity_type, label?, file, csrf_token (form 'dossier_checklist')
 * Réponse : { ok, doc_id, error }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/document_requests.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('dossier_checklist');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$idDossier  = (int)($_POST['id_dossier'] ?? 0);
$docType    = trim((string)($_POST['doc_type'] ?? ''));
$entityType = strtoupper(trim((string)($_POST['entity_type'] ?? '')));
$label      = trim((string)($_POST['label'] ?? $docType));

$dossier = $idDossier > 0 ? dv_get($pdo, $idDossier) : null;
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }

$idSoc = (int)($_SESSION['id_societe'] ?? 0) ?: null;
$isSuperScope = in_array($roleId, [1,2,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isSuperScope && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit; }

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'fichier manquant']); exit; }
if ($docType === '' || $entityType === '') { echo json_encode(['ok'=>false,'error'=>'type/entité manquant']); exit; }

// Résolution + validation des entités du dossier (bien → immeuble + propriétaire tiers).
$stB = $pdo->prepare("SELECT b.id_immeuble, p.id_tiers
                      FROM biens b LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                      WHERE b.id = ? LIMIT 1");
$stB->execute([(int)$dossier['id_bien']]);
$row = $stB->fetch(PDO::FETCH_ASSOC) ?: [];
$bienId = (int)$dossier['id_bien'];
$entMap = ['BIEN' => $bienId];
if (!empty($row['id_immeuble'])) $entMap['IMMEUBLE'] = (int)$row['id_immeuble'];
if (!empty($row['id_tiers']))    $entMap['TIERS']    = (int)$row['id_tiers'];

$entityId = (int)($entMap[$entityType] ?? 0);
if ($entityId <= 0) { echo json_encode(['ok'=>false,'error'=>'entité non résolue pour ce dossier']); exit; }

// Liens supplémentaires : le dossier (visible « Documents du dossier ») + le bien.
$extra = [['entity_type'=>'DOSSIER','entity_id'=>$idDossier,'relation_type'=>'main']];
if ($bienId > 0 && $entityType !== 'BIEN') $extra[] = ['entity_type'=>'BIEN','entity_id'=>$bienId,'relation_type'=>'reference'];

$f = $_FILES['file'];
$res = dr_commit_file_to_entity($pdo, [
    'tmp_path'      => $f['tmp_name'],
    'name_original' => (string)$f['name'],
    'mime_type'     => (string)($f['type'] ?? ''),
    'size_bytes'    => (int)($f['size'] ?? 0),
], $docType, $entityType, $entityId, [
    'id_dossier'  => $idDossier,
    'societe_id'  => (int)$dossier['id_societe'],
    'agence_id'   => (int)($dossier['id_agence'] ?? 0),
    'created_by'  => function_exists('current_user_id') ? (int)current_user_id() : null,
    'label'       => $label,
    'extra_links' => $extra,
]);

echo json_encode($res, JSON_UNESCAPED_UNICODE);
