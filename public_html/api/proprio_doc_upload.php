<?php
/**
 * api/proprio_doc_upload.php — Dépôt d'un document (mandat de gestion…) sur un PROPRIÉTAIRE.
 * Classé en GED + lié au TIERS du propriétaire. POST : id_tiers, doc_type, CSRF, fichier (multipart).
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
if (function_exists('verify_csrf_any')) { verify_csrf_any('proprio_doc'); }

$tiersId = isset($_POST['id_tiers']) && ctype_digit((string)$_POST['id_tiers']) ? (int)$_POST['id_tiers'] : 0;
if ($tiersId <= 0) { exit(json_encode(['ok'=>false,'error'=>'tiers manquant'])); }

$docTypes = [
    'MANDAT_GESTION' => ['n3' => '01_mandat_signe', 'type' => 'mandat_gestion'],
];
$docType = strtoupper(trim((string)($_POST['doc_type'] ?? 'MANDAT_GESTION')));
if (!isset($docTypes[$docType])) $docType = 'MANDAT_GESTION';

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) { exit(json_encode(['ok'=>false,'error'=>'Aucun fichier reçu'])); }
$file = $_FILES['fichier'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf','doc','docx','jpg','jpeg','png'], true)) { exit(json_encode(['ok'=>false,'error'=>'Format non supporté'])); }
$finfo = finfo_open(FILEINFO_MIME_TYPE); $realMime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
if ($file['size'] > 20 * 1024 * 1024) { exit(json_encode(['ok'=>false,'error'=>'Fichier trop volumineux (max 20 Mo)'])); }

// Contexte société/agence depuis le tiers
$stT = $pdo->prepare("SELECT t.id_societe, t.id_agence FROM tiers t WHERE t.id = ? LIMIT 1");
$stT->execute([$tiersId]); $tier = $stT->fetch(PDO::FETCH_ASSOC);
if (!$tier) { exit(json_encode(['ok'=>false,'error'=>'tiers introuvable'])); }

$uploadDir = dirname(__DIR__) . '/uploads/biens_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
$safeName  = strtolower($docType) . '_t' . $tiersId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath  = $uploadDir . $safeName;
$publicUrl = '/uploads/biens_docs/' . $safeName;
if (!move_uploaded_file($file['tmp_name'], $destPath)) { exit(json_encode(['ok'=>false,'error'=>'Déplacement fichier impossible'])); }

$links = [['entity_type'=>'TIERS', 'entity_id'=>$tiersId, 'relation_type'=>'main']];

try {
    $res = gus_commit_document(
        $pdo,
        ['path_on_disk'=>$destPath, 'name_original'=>$file['name'], 'mime_type'=>$realMime, 'size_bytes'=>(int)$file['size'], 'public_url'=>$publicUrl],
        [
            'document_type'  => $docTypes[$docType]['type'],
            'source_module'  => '05_GESTION_LOCATIVE',
            'security_level' => 'interne',
            'societe_id'     => $tier['id_societe'] !== null ? (int)$tier['id_societe'] : null,
            'agence_id'      => $tier['id_agence']  !== null ? (int)$tier['id_agence']  : null,
            'tenant_id'      => $tier['id_societe'] !== null ? (int)$tier['id_societe'] : null,
            'created_by'     => (int)current_user_id() ?: null,
            'naming_ctx'     => [
                'n1_slug'=>'05_gestion_locative', 'n2_slug'=>'01_mandat_gestion', 'n3_slug'=>$docTypes[$docType]['n3'],
                'type_doc'=>$docTypes[$docType]['type'], 'entity_type'=>'TIERS', 'entity_id'=>$tiersId,
                'source_filename'=>$file['name'], 'ext'=>$ext,
            ],
        ],
        $links
    );
    if (empty($res['ok'])) { exit(json_encode(['ok'=>false,'error'=>'GED : ' . implode(' / ', $res['errors'] ?? ['échec commit'])])); }
    echo json_encode(['ok'=>true, 'doc'=>['id'=>(int)$res['doc_id'], 'name_display'=>$res['name_display'] ?? $file['name']]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[proprio_doc_upload] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
