<?php
// api/bien_estimation_upload.php — Dépôt d'un avis de valeur / estimation (PDF ou Word)
// sur un BIEN. Classé en GED (document_type=ESTIMATION) + liens BIEN/TIERS proprio/IMB.
// POST : id_bien, CSRF, fichier (multipart)  →  { ok, doc }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis']));
}
if (function_exists('verify_csrf_any')) { verify_csrf_any('dossier_estimation'); }

$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
if ($bienId <= 0) { exit(json_encode(['ok'=>false,'error'=>'bien manquant'])); }

// Type de document déposé (whitelist) + dossier optionnel (lien GED DOSSIER).
$docTypes = [
    'ESTIMATION'       => ['n3' => 'estimation',  'label' => 'avis de valeur'],
    'OFFRE_ACHAT'      => ['n3' => 'offre',        'label' => "offre d'achat"],
    'MANDAT_VENTE'     => ['n3' => 'mandat',       'label' => 'mandat de vente'],
    'COMPROMIS'        => ['n3' => 'compromis',    'label' => 'compromis'],
    'ACTE_AUTHENTIQUE' => ['n3' => 'acte',         'label' => 'acte'],
];
$docType = strtoupper(trim((string)($_POST['doc_type'] ?? 'ESTIMATION')));
if (!isset($docTypes[$docType])) $docType = 'ESTIMATION';
$idDossier = isset($_POST['id_dossier']) && ctype_digit((string)$_POST['id_dossier']) ? (int)$_POST['id_dossier'] : 0;

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['ok'=>false,'error'=>'Aucun fichier reçu']));
}
$file = $_FILES['fichier'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExt = ['pdf','doc','docx'];
if (!in_array($ext, $allowedExt, true)) {
    exit(json_encode(['ok'=>false,'error'=>'Format non supporté (PDF ou Word)']));
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowedMime = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/zip', 'application/octet-stream', // docx parfois détecté ainsi
];
if (!in_array($realMime, $allowedMime, true)) {
    exit(json_encode(['ok'=>false,'error'=>"Type MIME invalide ({$realMime})"]));
}
if ($file['size'] > 20 * 1024 * 1024) {
    exit(json_encode(['ok'=>false,'error'=>'Fichier trop volumineux (max 20 Mo)']));
}

// ── Contexte bien + scope société (super admin / manager bypass) ──
$stB = $pdo->prepare("
    SELECT b.id_societe, b.id_agence, b.id_immeuble, b.reference_bien,
           p.id_tiers AS proprio_tiers_id
      FROM biens b
      LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
     WHERE b.id = ? LIMIT 1");
$stB->execute([$bienId]);
$bien = $stB->fetch(PDO::FETCH_ASSOC);
if (!$bien) { exit(json_encode(['ok'=>false,'error'=>'bien introuvable'])); }

$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$bien['id_societe'] !== $idSoc) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'hors scope']));
}

// ── Stockage fichier ──
$uploadDir = dirname(__DIR__) . '/uploads/biens_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
$safeName  = strtolower($docType) . '_' . $bienId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath  = $uploadDir . $safeName;
$publicUrl = '/uploads/biens_docs/' . $safeName;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    exit(json_encode(['ok'=>false,'error'=>'Déplacement fichier impossible']));
}

// ── Liens GED : BIEN (main) + proprio (TIERS) + immeuble (IMB) ──
$links = [['entity_type'=>'BIEN', 'entity_id'=>$bienId, 'relation_type'=>'main']];
if (!empty($bien['proprio_tiers_id'])) {
    $links[] = ['entity_type'=>'TIERS', 'entity_id'=>(int)$bien['proprio_tiers_id'], 'relation_type'=>'annexe'];
}
if (!empty($bien['id_immeuble'])) {
    $links[] = ['entity_type'=>'IMB', 'entity_id'=>(int)$bien['id_immeuble'], 'relation_type'=>'annexe'];
}
if ($idDossier > 0) {
    $links[] = ['entity_type'=>'DOSSIER', 'entity_id'=>$idDossier, 'relation_type'=>'annexe'];
}

try {
    $res = gus_commit_document(
        $pdo,
        [
            'path_on_disk'  => $destPath,
            'name_original' => $file['name'],
            'mime_type'     => $realMime,
            'size_bytes'    => (int)$file['size'],
            'public_url'    => $publicUrl,
        ],
        [
            'document_type'  => $docType,
            'source_module'  => '05_TRANSACTION',
            'security_level' => 'interne',
            'societe_id'     => $bien['id_societe'] !== null ? (int)$bien['id_societe'] : null,
            'agence_id'      => $bien['id_agence']  !== null ? (int)$bien['id_agence']  : null,
            'tenant_id'      => $bien['id_societe'] !== null ? (int)$bien['id_societe'] : null,
            'created_by'     => (int)current_user_id() ?: null,
            'naming_ctx'     => [
                'n1_slug'         => '06_transaction',
                'n2_slug'         => 'biens',
                'n3_slug'         => $docTypes[$docType]['n3'],
                'type_doc'        => $docType,
                'entity_type'     => 'BIEN',
                'entity_id'       => $bienId,
                'source_filename' => $file['name'],
                'ext'             => $ext,
            ],
        ],
        $links
    );
    if (empty($res['ok'])) {
        exit(json_encode(['ok'=>false,'error'=>'GED : ' . implode(' / ', $res['errors'] ?? ['échec commit'])]));
    }
    echo json_encode([
        'ok'  => true,
        'doc' => [
            'id'           => (int)$res['doc_id'],
            'name_display' => $res['name_display'] ?? $file['name'],
            'url'          => app_url('/api/ged_doc_serve.php?id=' . (int)$res['doc_id']),
            'deduplicated' => !empty($res['deduplicated']),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[bien_estimation_upload] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
