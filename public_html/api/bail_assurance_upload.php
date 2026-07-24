<?php
/**
 * api/bail_assurance_upload.php — Dépôt de l'ATTESTATION D'ASSURANCE par le PRENEUR depuis la page
 * publique de signature. Sécurisé par le TOKEN de signature (aucune authentification).
 *   - token valide + NON expiré + rôle = preneur uniquement
 *   - fichier PDF ou image, taille ≤ 15 Mo, type MIME réel vérifié
 *   - stocké dans uploads/baux/ + classé en GED (type attestation_assurance, lié BAIL + BIEN)
 * POST (multipart) : t=<token>, attestation=<file>  →  { ok, doc_id?, error? }
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bail_signature.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$token = (string)($_POST['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) exit(json_encode(['ok'=>false,'error'=>'Lien invalide.']));
$sig = bsig_get_by_token($pdo, $token);
if (!$sig)                                    exit(json_encode(['ok'=>false,'error'=>'Lien invalide.']));
if (function_exists('bsig_is_expired') && bsig_is_expired($sig)) exit(json_encode(['ok'=>false,'error'=>'Lien expiré.']));
if (($sig['role_code'] ?? '') !== 'preneur')  exit(json_encode(['ok'=>false,'error'=>'Dépôt réservé au preneur.']));

$f = $_FILES['attestation'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) exit(json_encode(['ok'=>false,'error'=>'Aucun fichier reçu.']));
if ((int)($f['size'] ?? 0) > 15 * 1024 * 1024)                    exit(json_encode(['ok'=>false,'error'=>'Fichier trop volumineux (max 15 Mo).']));

$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (string)@finfo_file($finfo, (string)$f['tmp_name']) : (string)($f['type'] ?? '');
$allowed = ['application/pdf'=>'pdf', 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/heic'=>'heic'];
if (!isset($allowed[$mime])) exit(json_encode(['ok'=>false,'error'=>'Format non accepté (PDF ou image).']));
$ext = $allowed[$mime];

$bailId = (int)($sig['id_bail'] ?? 0);
$idBien = (int)($sig['id_bien'] ?? 0);
$dir    = __DIR__ . '/../uploads/baux/'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$name   = 'attestation_assurance_bail_' . $bailId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
$path   = $dir . $name;
if (!@move_uploaded_file((string)$f['tmp_name'], $path)) exit(json_encode(['ok'=>false,'error'=>'Échec de l\'enregistrement.']));

// Classement GED (best-effort) : type attestation_assurance, lié BAIL (main) + BIEN (reference).
$docId = 0;
try {
    require_once __DIR__ . '/../inc/ged_document_links.php';
    $q = $pdo->prepare("SELECT bb.numero_bail, b.id_societe AS bisoc, b.id_agence AS biage, bb.id_societe AS bsoc, bb.id_agence AS bage
                          FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=? LIMIT 1");
    $q->execute([$bailId]); $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $soc = (int)($r['bisoc'] ?? 0) ?: (int)($r['bsoc'] ?? 0) ?: null;
    $age = (int)($r['biage'] ?? 0) ?: (int)($r['bage'] ?? 0) ?: null;
    $res = gus_commit_document($pdo,
        ['path_on_disk'=>$path, 'name_original'=>'Attestation_assurance.' . $ext, 'mime_type'=>$mime,
         'size_bytes'=>filesize($path) ?: 0, 'public_url'=>'/uploads/baux/' . $name],
        [
            'document_type'=>'attestation_assurance', 'source_module'=>'03_GESTION_LOCATIVE', 'security_level'=>'interne',
            'societe_id'=>$soc, 'agence_id'=>$age, 'tenant_id'=>$soc, 'created_by'=>null, 'storage_provider'=>'local',
            'name_display'=>'Attestation d\'assurance — ' . ($r['numero_bail'] ?: ('bail ' . $bailId)),
            'metadata_extra'=>['id_bail'=>$bailId, 'source'=>'signature_preneur', 'doc_date'=>date('Y-m-d')],
            'naming_ctx'=>['n1_slug'=>'03_gestion_locative', 'type_doc'=>'attestation_assurance',
                'entity_type'=>'BAIL', 'entity_id'=>$bailId, 'date_doc'=>date('Y-m-d'), 'source_filename'=>'Attestation_assurance.' . $ext],
        ],
        [
            ['entity_type'=>'BAIL', 'entity_id'=>$bailId, 'relation_type'=>'main'],
            ['entity_type'=>'BIEN', 'entity_id'=>$idBien, 'relation_type'=>'reference'],
        ]
    );
    $docId = (int)($res['doc_id'] ?? 0);
} catch (Throwable $e) { error_log('[bail_assurance_upload ged] ' . $e->getMessage()); }

echo json_encode(['ok'=>true, 'doc_id'=>$docId, 'message'=>'Attestation d\'assurance reçue, merci.'], JSON_UNESCAPED_UNICODE);
