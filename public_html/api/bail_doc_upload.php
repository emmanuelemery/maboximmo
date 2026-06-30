<?php
/**
 * api/bail_doc_upload.php — Upload DIRECT d'un ou plusieurs documents sur un BAIL.
 *
 * Bail = table canonique `bien_baux` (id = bien_baux.id, cf. bail_360.php).
 * Tout le contexte étant connu (proprio → immeuble → bien → bail), le document est
 * committé immédiatement en GED (pas de pile FluxBox à valider) et :
 *   - lié au BIEN (entity_type=BIEN) via le pipeline central → visible dans le bien ;
 *   - lié à l'IMMEUBLE (si présent) ;
 *   - marqué id_bail = bien_baux.id sur ged_documents → visible sur la fiche bail 360.
 *   - + lien polymorphe BAIL (bien_baux.id) pour le modèle central.
 *
 * POST : id_bail (= bien_baux.id, requis), document[] (1..N), csrf_token (form 'bail_doc_upload').
 * Sécurité : login + CSRF + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('bail_doc_upload');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);

$bailId = isset($_POST['id_bail']) && ctype_digit((string)$_POST['id_bail']) ? (int)$_POST['id_bail'] : 0;
if ($bailId <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bail requis']); exit; }

// Type de pièce (valide la checklist « Pièces bail » sur bail_360).
$ALLOWED_TYPES = ['bail_signe','edl_entree','attestation_assurance','dpe','caution_garant','edl_sortie','autre'];
$typePiece = strtolower(trim((string)($_POST['type_piece'] ?? 'bail_signe')));
if (!in_array($typePiece, $ALLOWED_TYPES, true)) { $typePiece = 'bail_signe'; }
// Slug N3 GED indicatif selon la pièce.
$n3map = [
    'bail_signe'            => '01_bail_signe',
    'edl_entree'            => '02_edl_entree',
    'edl_sortie'            => '03_edl_sortie',
    'caution_garant'        => '04_caution',
    'attestation_assurance' => '05_assurance',
    'dpe'                   => '06_dpe',
    'autre'                 => '99_autre',
];

// ── Résolution contexte : bien_baux → bien → immeuble → société/agence ──
$st = $pdo->prepare("SELECT bb.id AS bail_id, bb.id_bien,
                            bi.id_immeuble, bi.id_societe, bi.id_agence
                     FROM bien_baux bb LEFT JOIN biens bi ON bi.id = bb.id_bien
                     WHERE bb.id = ? LIMIT 1");
$st->execute([$bailId]);
$ctxRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctxRow) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Bail introuvable']); exit; }

$bienId = (int)($ctxRow['id_bien'] ?? 0);
$immId  = (int)($ctxRow['id_immeuble'] ?? 0);
// Société/agence : résolues depuis le bien/propriétaire (jamais la session user).
require_once __DIR__ . '/../inc/bien_scope_resolver.php';
$rv = bien_resolve_soc_age($pdo, $bienId);
$socId = $rv['societe_id']; $ageId = $rv['agence_id'];

$societeRaison = (string)($pdo->query("SELECT raison_sociale FROM societes WHERE id = " . (int)$socId)->fetchColumn() ?: '');
$ageRow = $pdo->query("SELECT code_agence, nom_agence FROM agences WHERE id = " . (int)$ageId)->fetch(PDO::FETCH_ASSOC) ?: [];
$immNom = $immId > 0 ? (string)($pdo->query("SELECT nom_immeuble FROM immeubles WHERE id = " . (int)$immId)->fetchColumn() ?: '') : '';
$uRow = $userId > 0 ? ($pdo->query("SELECT matricule_paie, username FROM users WHERE id = " . (int)$userId)->fetch(PDO::FETCH_ASSOC) ?: []) : [];

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
$storageDir = $storageBase . DIRECTORY_SEPARATOR . $socId . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);

$done = []; $errors = [];
$analyse = null; $analyseDone = false;
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
        'n1_slug'         => '05_gestion_locative',
        'n2_slug'         => '02_bail',
        'n3_slug'         => $n3map[$typePiece] ?? '99_autre',
        'date_doc'        => date('Y-m-d'),
        'type_doc'        => $typePiece,
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
        'document_type'  => $typePiece,
        'source_module'  => '05_GESTION_LOCATIVE',
        'security_level' => 'interne',
        'created_by'     => $userId ?: null,
        'naming_ctx'     => $namingCtx,
    ];
    $links = [
        ['entity_type'=>'BIEN', 'entity_id'=>$bienId, 'relation_type'=>'main',      'is_validated'=>true, 'validated_by'=>$userId],
        ['entity_type'=>'BAIL', 'entity_id'=>$bailId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId],
    ];
    if ($immId > 0) {
        $links[] = ['entity_type'=>'IMB', 'entity_id'=>$immId, 'relation_type'=>'reference', 'is_validated'=>true, 'validated_by'=>$userId];
    }

    $res = gus_commit_document($pdo, [
        'path_on_disk' => $dest,
        'name_original'=> $file['name'],
        'hash_sha256'  => $hash,
        'mime_type'    => $mime,
        'size_bytes'   => $file['size'],
    ], $ctx, $links);

    if (!empty($res['ok'])) {
        // Marque id_bail (colonne lue par bail_360.php) — clé canonique bien_baux.id.
        try { $pdo->prepare("UPDATE ged_documents SET id_bail = ? WHERE id = ?")->execute([$bailId, (int)$res['doc_id']]); } catch (Throwable $e) {}

        // Analyse IA du bail (best-effort) → remplit bien_baux (synthèse financière + clauses).
        // Sur le 1er PDF uploadé seulement, pour ne pas multiplier les appels IA.
        if ($ext === 'pdf' && $typePiece === 'bail_signe' && empty($analyseDone)) {
            $analyseDone = true;
            try {
                require_once __DIR__ . '/../inc/bail_analyse_service.php';
                $an = bail_analyse_pdf($dest);
                if (!empty($an['ok']) && !empty($an['data'])) {
                    $filled = bail_analyse_apply_to_bien_baux($pdo, $bailId, $an['data']);
                    // Descriptif du bien : rempli UNIQUEMENT si vide (jamais d'écrasement).
                    $descr = trim((string)($an['data']['bien']['description'] ?? ''));
                    if ($descr !== '' && $bienId > 0) {
                        $cur = $pdo->prepare("SELECT description FROM biens WHERE id=?"); $cur->execute([$bienId]);
                        $existing = (string)($cur->fetchColumn() ?: '');
                        if (trim($existing) === '') {
                            $pdo->prepare("UPDATE biens SET description=?, date_modification=NOW() WHERE id=?")->execute([$descr, $bienId]);
                            $filled[] = 'bien.description';
                        }
                    }
                    $analyse = ['ok'=>true, 'champs_remplis'=>$filled];
                } else {
                    $analyse = ['ok'=>false, 'error'=>$an['error'] ?? 'analyse indisponible'];
                }
            } catch (Throwable $e) {
                $analyse = ['ok'=>false, 'error'=>$e->getMessage()];
            }
        }

        $done[] = ['doc_id'=>$res['doc_id'], 'name'=>$res['name_display'] ?? $file['name'], 'deduplicated'=>!empty($res['deduplicated'])];
    } else {
        $errors[] = $file['name'].' : '.implode(' / ', $res['errors'] ?? ['échec commit']);
    }
}

echo json_encode([
    'ok'      => count($done) > 0,
    'n'       => count($done),
    'docs'    => $done,
    'errors'  => $errors,
    'analyse' => $analyse,   // {ok, champs_remplis[]} ou {ok:false, error}
    'id_bail' => $bailId,
    'id_bien' => $bienId,
], JSON_UNESCAPED_UNICODE);
