<?php
declare(strict_types=1);
set_time_limit(120);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Upload document immeuble avec extraction IA optionnelle
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Même logique qu'api/bien_intake_upload.php mais pour le contexte immeuble :
 *   - Stockage dans /uploads/immeubles_docs/
 *   - Insertion dans immeubles_documents
 *   - Extraction IA si type != 'autre' (dispatcher + OCR Vision si scan)
 *
 * POST params :
 *   - fichier (PDF, max 20 Mo, type MIME vérifié)
 *   - csrf_token
 *   - id_immeuble (int)
 *   - force_type (bail|mandat|titre|diag|fiche|divers|autre)
 *   - sous_type (optionnel, ex: 'ag', 'reglement_copro')
 *
 * Renvoie JSON : {ok, document_id, doc_type, force_type, fields, method, fichier, nom, used_ocr}
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_import_parser.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ia.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ocr.php';
require_once dirname(__DIR__) . '/inc/ged_document_links.php';  // GED CENTRALE UNIQUE (Sprint 7D)
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']     ?? 0);
$societeId = (int)($_SESSION['id_societe']  ?? 0);
$agenceId  = (int)($_SESSION['id_agence']   ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}
verify_csrf_any('ajouter_bien');

// ─── Validation paramètres ───
$idImmeuble = isset($_POST['id_immeuble']) && ctype_digit((string)$_POST['id_immeuble'])
    ? (int)$_POST['id_immeuble'] : 0;
if ($idImmeuble <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_immeuble manquant ou invalide']));
}

$validTypes = ['bail', 'mandat', 'titre', 'diag', 'fiche', 'divers', 'autre'];
$forceType = isset($_POST['force_type']) ? trim(strtolower((string)$_POST['force_type'])) : 'autre';
if (!in_array($forceType, $validTypes, true)) {
    $forceType = 'autre';
}
$sousType = isset($_POST['sous_type']) ? trim((string)$_POST['sous_type']) : null;
if ($sousType !== null && strlen($sousType) > 80) $sousType = substr($sousType, 0, 80);

// ─── Fichier ───
if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun fichier reçu']));
}
$file = $_FILES['fichier'];
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
    exit(json_encode(['ok' => false, 'error' => 'Format non supporté (PDF uniquement)']));
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if ($realMime !== 'application/pdf') {
    exit(json_encode(['ok' => false, 'error' => "Type MIME invalide ({$realMime}). PDF requis."]));
}
if ($file['size'] > 20 * 1024 * 1024) {
    exit(json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 20 Mo)']));
}

// ─── Stockage fichier ───
$uploadDir = dirname(__DIR__) . '/uploads/immeubles_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
$safeName  = 'imm_' . $idImmeuble . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$destPath  = $uploadDir . $safeName;
$publicUrl = '/uploads/immeubles_docs/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    exit(json_encode(['ok' => false, 'error' => 'Déplacement fichier impossible']));
}

try {
    // ─── Extraction (si type != 'autre') ───
    $fields          = [];
    $docType         = $forceType;
    $docTitre        = null;
    $docDate         = null;
    $resume          = null;
    $method          = 'none';
    $usedOcr         = false;
    $extractionError = null;

    if ($forceType !== 'autre') {
        $texteSource = BienImportParser::extractText($destPath);
        $textLen     = mb_strlen(trim($texteSource));

        if ($textLen < 200) {
            // OCR Vision
            try {
                $tmpDir = dirname(__DIR__) . '/uploads/_ocr_tmp/imm_' . $idImmeuble . '_' . time();
                $images = BienIntakeOCR::pdfToImages($destPath, $tmpDir, 8);
                if (empty($images)) throw new RuntimeException('pdftoppm indisponible');
                $ocrResult = BienIntakeOCR::analyseImagesIA($images, $forceType);
                BienIntakeOCR::cleanupTmpDir($tmpDir);
                if ($ocrResult['ok']) {
                    $fields   = $ocrResult['fields'] ?? [];
                    $docType  = $ocrResult['doc_type'] ?? $forceType;
                    $docTitre = $ocrResult['doc_titre'] ?? null;
                    $docDate  = $ocrResult['doc_date'] ?? null;
                    $resume   = $ocrResult['resume'] ?? null;
                    $method   = 'ocr_vision';
                    $usedOcr  = true;
                } else {
                    $extractionError = $ocrResult['error'] ?? 'OCR échoué';
                }
            } catch (Throwable $e) {
                $extractionError = 'OCR : ' . $e->getMessage();
                error_log('[immeuble_doc_upload] OCR failed: ' . $e->getMessage());
            }
        } else {
            // Extraction texte + dispatcher par type forcé
            $iaResult = analyseBienIntakeIA($texteSource, $forceType);
            if ($iaResult['ok']) {
                $fields   = $iaResult['fields'] ?? [];
                $docType  = $iaResult['doc_type'] ?? $forceType;
                $docTitre = $iaResult['doc_titre'] ?? null;
                $docDate  = $iaResult['doc_date'] ?? null;
                $resume   = $iaResult['resume'] ?? null;
                $method   = 'text_ia';
            } else {
                $extractionError = $iaResult['error'] ?? 'Extraction échouée';
            }
        }
    }

    // ─── Enregistrement GED CENTRALE UNIQUE (Sprint 7D 2026-05-25) ───
    // Plus d'INSERT immeubles_documents. Source unique = ged_documents + ged_document_links.
    $pdo = db_reconnect_fresh();

    // Mapping type immeuble → document_type GED canonique
    $gedTypeMap = [
        'bail'      => 'BAIL',
        'mandat'    => 'MANDAT_GESTION',
        'titre'     => 'TITRE_PROPRIETE',
        'diag'      => 'DIAG_IMMEUBLE',
        'fiche'     => 'FICHE_IMMEUBLE',
        'reglement' => 'REGLEMENT_COPRO',
        'pv_ag'     => 'PV_ASSEMBLEE_GENERALE',
        'divers'    => 'AUTRE',
        'autre'     => 'AUTRE',
    ];
    $gedDocType = $gedTypeMap[$forceType] ?? 'AUTRE';
    if (!empty($sousType)) {
        // sous-type peut surcharger : ex 'ag' → PV_ASSEMBLEE_GENERALE
        $sousMap = ['ag' => 'PV_ASSEMBLEE_GENERALE', 'reglement_copro' => 'REGLEMENT_COPRO'];
        $gedDocType = $sousMap[$sousType] ?? $gedDocType;
    }

    // Récupère soc/age depuis l'immeuble pour cohérence cascade
    $stImmCtx = $pdo->prepare("SELECT i.id_societe, i.id_agence, i.nom_immeuble,
                                       s.raison_sociale AS soc_raison,
                                       a.code_agence, a.nom_agence
                                 FROM immeubles i
                                 LEFT JOIN societes s ON s.id = i.id_societe
                                 LEFT JOIN agences  a ON a.id = i.id_agence
                                 WHERE i.id = ? LIMIT 1");
    $stImmCtx->execute([$idImmeuble]);
    $immCtx = $stImmCtx->fetch(PDO::FETCH_ASSOC) ?: [];

    $socIdGed = (int)($immCtx['id_societe'] ?? 0) ?: ($societeId ?: 1);
    $ageIdGed = (int)($immCtx['id_agence']  ?? 0) ?: ($agenceId  ?: 3);

    $gedCommitResult = null;
    $gedCommitError  = null;
    try {
        $gedCommitResult = gus_commit_document(
            $pdo,
            [
                'path_on_disk'  => $destPath,
                'name_original' => $file['name'],
                'mime_type'     => 'application/pdf',
                'size_bytes'    => (int)$file['size'],
                'public_url'    => $publicUrl,
            ],
            [
                'document_type'  => $gedDocType,
                'source_module'  => '02_SYNDIC',
                'security_level' => 'interne',
                'societe_id'     => $socIdGed,
                'agence_id'      => $ageIdGed,
                'tenant_id'      => $socIdGed,
                'created_by'     => $userId ?: null,
                'storage_provider' => 'local',
                'metadata_extra' => [
                    'titre_ia'    => $docTitre,
                    'resume_ia'   => $resume,
                    'doc_date'    => $docDate,
                    'ia_fields'   => !empty($fields) ? $fields : null,
                    'method'      => $method,
                    'used_ocr'    => $usedOcr,
                    'sous_type'   => $sousType,
                    'force_type'  => $forceType,
                    'classement'  => [
                        'immeuble_id_bdd' => $idImmeuble,
                        'date_doc'        => $docDate,
                    ],
                    'legacy_source' => 'immeuble_doc_upload',
                ],
                'naming_ctx' => [
                    'societe_raison' => $immCtx['soc_raison'] ?? 'Régie EMERY',
                    'agence_code'    => $immCtx['code_agence'] ?? 'RE69-2',
                    'agence_nom'     => $immCtx['nom_agence']  ?? 'LYON',
                    'user_id'        => $userId,
                    'n1_slug'        => '02_syndic',
                    'n2_slug'        => 'immeubles',
                    'n3_slug'        => strtolower($gedDocType),
                    'type_doc'       => $gedDocType,
                    'entity_type'    => 'IMB',
                    'entity_id'      => $idImmeuble,
                    'date_doc'       => $docDate,
                    'source_filename'=> $file['name'],
                ],
            ],
            [
                ['entity_type' => 'IMB', 'entity_id' => $idImmeuble, 'relation_type' => 'main'],
            ]
        );
        if (empty($gedCommitResult['ok'])) {
            $gedCommitError = 'gus_commit_document errors: ' . json_encode($gedCommitResult['errors'] ?? ['unknown']);
            error_log('[immeuble_doc_upload] ' . $gedCommitError);
        }
    } catch (Throwable $exDoc) {
        $gedCommitError = 'EXCEPTION pipeline GED : ' . $exDoc->getMessage()
                        . ' @ ' . basename($exDoc->getFile()) . ':' . $exDoc->getLine();
        error_log('[immeuble_doc_upload] ' . $gedCommitError);
    }

    echo json_encode([
        'ok'          => true,
        'document_id' => $gedCommitResult['doc_id'] ?? null,  // ged_documents.id (plus immeubles_documents)
        'ged_doc_id'  => $gedCommitResult['doc_id'] ?? null,
        'ged_name_display' => $gedCommitResult['name_display'] ?? null,
        'ged_name_file'    => $gedCommitResult['name_file']    ?? null,
        'ged_deduplicated' => $gedCommitResult['deduplicated'] ?? null,
        'ged_error'   => $gedCommitError,
        'doc_type'    => $docType,
        'force_type'  => $forceType,
        'sous_type'   => $sousType,
        'doc_titre'   => $docTitre,
        'doc_date'    => $docDate,
        'resume'      => $resume,
        'fields'      => $fields,
        'method'      => $method,
        'used_ocr'    => $usedOcr,
        'fichier'     => function_exists('app_url') ? app_url($publicUrl) : $publicUrl,
        'fichier_relatif' => $publicUrl,
        'nom'         => $file['name'],
        'extraction_error' => $extractionError,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
