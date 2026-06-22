<?php
/**
 * api/transaction_dossier_doc_upload.php — Upload DIRECT d'un ou plusieurs documents
 * sur un DOSSIER DE VENTE (même mécanisme fiable que api/bail_doc_upload.php).
 *
 * Le contexte est connu (dossier → bien → immeuble → société/agence) : le document est
 * committé immédiatement en GED (pas de pile FluxBox à valider) et :
 *   - lié au DOSSIER (entity_type=DOSSIER) → visible « Documents du dossier » ;
 *   - lié au BIEN (entity_type=BIEN)       → visible fiche bien / 360 ;
 *   - lié à l'IMMEUBLE (si présent).
 *
 * POST : id_dossier (requis), document[] (1..N), type_document, csrf_token (form 'transaction_doc_upload').
 * Sécurité : login + CSRF + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_once __DIR__ . '/../inc/bien_scope_resolver.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('transaction_doc_upload');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);

$idDossier = isset($_POST['id_dossier']) && ctype_digit((string)$_POST['id_dossier']) ? (int)$_POST['id_dossier'] : 0;
if ($idDossier <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier requis']); exit; }

// Type de document (canonique : alimente les onglets Actes/Mandat/Offre du cockpit).
$ALLOWED = ['MANDAT_VENTE','COMPROMIS','ACTE_AUTHENTIQUE','OFFRE_ACHAT','ESTIMATION','DIAG_DPE','AVANT_CONTRAT',
            'FINANCEMENT_DEMANDE','FINANCEMENT_ACCORD','TITRE_PROPRIETE','COPROPRIETE','IDENTITE','BAIL','AUTRE'];
$typeDoc = strtoupper(trim((string)($_POST['type_document'] ?? 'AUTRE')));
if (!in_array($typeDoc, $ALLOWED, true)) { $typeDoc = 'AUTRE'; }
$n3map = [
    'MANDAT_VENTE'        => '01_mandat',
    'COMPROMIS'           => '02_compromis',
    'AVANT_CONTRAT'       => '02_compromis',
    'OFFRE_ACHAT'         => '03_offre',
    'ACTE_AUTHENTIQUE'    => '04_acte',
    'ESTIMATION'          => '05_estimation',
    'DIAG_DPE'            => '06_diagnostics',
    'FINANCEMENT_DEMANDE' => '07_financement',
    'FINANCEMENT_ACCORD'  => '07_financement',
    'TITRE_PROPRIETE'     => '08_propriete',
    'COPROPRIETE'         => '09_copropriete',
    'IDENTITE'            => '10_identite',
    'BAIL'                => '11_location',
    'AUTRE'               => '99_autre',
];

// ── Résolution contexte : dossier_vente → bien → immeuble → propriétaire (tiers) ──
$st = $pdo->prepare("SELECT dv.id_bien, b.id_immeuble, b.id_proprietaire, p.id_tiers AS proprio_tiers_id
                     FROM dossier_vente dv
                     LEFT JOIN biens b          ON b.id = dv.id_bien
                     LEFT JOIN proprietaires p  ON p.id = b.id_proprietaire
                     WHERE dv.id = ? LIMIT 1");
$st->execute([$idDossier]);
$ctxRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctxRow) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Dossier introuvable']); exit; }

$bienId       = (int)($ctxRow['id_bien'] ?? 0);
$immId        = (int)($ctxRow['id_immeuble'] ?? 0);
$proprioTiers = (int)($ctxRow['proprio_tiers_id'] ?? 0);

// Bail actif/le plus récent du bien → rattachement GED (docs location, financement, etc.).
$bailId = 0;
if ($bienId > 0) {
    try {
        $stBail = $pdo->prepare("SELECT id FROM bien_baux WHERE id_bien = ?
                                 ORDER BY (statut='actif') DESC, (date_fin IS NULL) DESC, id DESC LIMIT 1");
        $stBail->execute([$bienId]);
        $bailId = (int)($stBail->fetchColumn() ?: 0);
    } catch (Throwable $e) { /* table absente : ignore */ }
}

$socId = null; $ageId = null;
if ($bienId > 0) {
    $rv = bien_resolve_soc_age($pdo, $bienId);
    $socId = $rv['societe_id']; $ageId = $rv['agence_id'];
}
$societeRaison = $socId ? (string)($pdo->query("SELECT raison_sociale FROM societes WHERE id = " . (int)$socId)->fetchColumn() ?: '') : '';
$ageRow = $ageId ? ($pdo->query("SELECT code_agence, nom_agence FROM agences WHERE id = " . (int)$ageId)->fetch(PDO::FETCH_ASSOC) ?: []) : [];
$immNom = $immId > 0 ? (string)($pdo->query("SELECT nom_immeuble FROM immeubles WHERE id = " . (int)$immId)->fetchColumn() ?: '') : '';
$uRow   = $userId > 0 ? ($pdo->query("SELECT matricule_paie, username FROM users WHERE id = " . (int)$userId)->fetch(PDO::FETCH_ASSOC) ?: []) : [];

// ── Collecte fichiers ──
$files = [];
if (!empty($_FILES['document'])) {
    $f = $_FILES['document'];
    if (is_array($f['name'])) {
        for ($i = 0; $i < count($f['name']); $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $files[] = ['name'=>(string)$f['name'][$i], 'tmp'=>(string)$f['tmp_name'][$i], 'size'=>(int)$f['size'][$i]];
            }
        }
    } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $files[] = ['name'=>(string)$f['name'], 'tmp'=>(string)$f['tmp_name'], 'size'=>(int)$f['size']];
    }
}
if (empty($files)) { echo json_encode(['ok'=>false,'error'=>'Aucun fichier reçu']); exit; }

// ── Stockage ──
$storageBase = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'storage_fluxbox';
if (!is_dir($storageBase)) @mkdir($storageBase, 0775, true);
$storageDir = $storageBase . DIRECTORY_SEPARATOR . ($socId ?: 0) . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);

$done = []; $errors = [];
foreach ($files as $file) {
    if ($file['size'] > 50 * 1024 * 1024) { $errors[] = $file['name'].' : > 50 Mo'; continue; }
    $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'pdf';
    if (!in_array($ext, ['pdf','jpg','jpeg','png','tiff','tif','docx','xlsx','doc','heic'], true)) {
        $errors[] = $file['name'].' : type non autorisé'; continue;
    }
    $hash = hash_file('sha256', $file['tmp']) ?: '';
    $mime = function_exists('mime_content_type') ? (mime_content_type($file['tmp']) ?: 'application/octet-stream') : 'application/octet-stream';
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $file['name']) ?: 'doc';
    $dest = $storageDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . substr($hash, 0, 8) . '_' . $safe;
    $moved = is_uploaded_file($file['tmp']) ? move_uploaded_file($file['tmp'], $dest) : copy($file['tmp'], $dest);
    if (!$moved) { $errors[] = $file['name'].' : échec écriture'; continue; }

    $namingCtx = [
        'upload_date'     => 'now',
        'n1_slug'         => '06_transaction',
        'n2_slug'         => '01_dossier_vente',
        'n3_slug'         => $n3map[$typeDoc] ?? '99_autre',
        'date_doc'        => date('Y-m-d'),
        'type_doc'        => $typeDoc,
        'source_filename' => $file['name'],
        'user_id'         => $userId,
        'entity_type'     => 'BIEN',
        'entity_id'       => $bienId,
        'societe_raison'  => $societeRaison ?: null,
        'agence_code'     => $ageRow['code_agence'] ?? null,
        'agence_nom'      => $ageRow['nom_agence']  ?? null,
        'user_matricule'  => $uRow['matricule_paie'] ?? null,
        'user_username'   => $uRow['username']       ?? null,
        'immeuble_nom'    => $immNom ?: null,
    ];
    $ctx = [
        'tenant_id'      => $socId ?: null,
        'societe_id'     => $socId ?: null,
        'agence_id'      => $ageId ?: null,
        'document_type'  => $typeDoc,
        'source_module'  => '06_TRANSACTION',
        'security_level' => 'interne',
        'created_by'     => $userId ?: null,
        'naming_ctx'     => $namingCtx,
    ];
    $links = [
        ['entity_type'=>'DOSSIER', 'entity_id'=>$idDossier, 'relation_type'=>'main',      'is_validated'=>true, 'validated_by'=>$userId],
    ];
    if ($bienId > 0) {
        $links[] = ['entity_type'=>'BIEN', 'entity_id'=>$bienId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId];
    }
    if ($immId > 0) {
        $links[] = ['entity_type'=>'IMB', 'entity_id'=>$immId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId];
    }
    // Propriétaire (vendeur) : rattachement TIERS → le doc remonte sur la fiche propriétaire.
    if ($proprioTiers > 0) {
        $links[] = ['entity_type'=>'TIERS', 'entity_id'=>$proprioTiers, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId];
    }
    // Bail du bien : rattachement BAIL → cohérence GED pour les biens loués (location, financement…).
    if ($bailId > 0) {
        $links[] = ['entity_type'=>'BAIL', 'entity_id'=>$bailId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId];
    }

    $res = gus_commit_document($pdo, [
        'path_on_disk' => $dest,
        'name_original'=> $file['name'],
        'hash_sha256'  => $hash,
        'mime_type'    => $mime,
        'size_bytes'   => $file['size'],
    ], $ctx, $links);

    if (!empty($res['ok'])) {
        $done[] = ['doc_id'=>$res['doc_id'], 'name'=>$res['name_display'] ?? $file['name'], 'deduplicated'=>!empty($res['deduplicated'])];
    } else {
        $errors[] = $file['name'].' : '.implode(' / ', $res['errors'] ?? ['échec commit']);
    }
}

echo json_encode([
    'ok'         => count($done) > 0,
    'n'          => count($done),
    'docs'       => $done,
    'errors'     => $errors,
    'id_dossier' => $idDossier,
    'id_bien'    => $bienId,
], JSON_UNESCAPED_UNICODE);
