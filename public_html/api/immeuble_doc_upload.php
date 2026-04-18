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

    // ─── Insertion en BDD ───
    $pdo = db_keepalive();

    $stmt = $pdo->prepare("
        INSERT INTO immeubles_documents (
            id_immeuble, id_societe, id_agence,
            type_document, sous_type,
            nom_fichier, url_fichier, taille_octets, mime_type,
            extraction_json, resume_ia, method_extraction,
            id_user_created
        ) VALUES (
            :id_imm, :id_soc, :id_age,
            :type, :sous,
            :nom, :url, :taille, :mime,
            :json, :resume, :method,
            :user
        )
    ");
    $stmt->execute([
        ':id_imm' => $idImmeuble,
        ':id_soc' => $societeId ?: null,
        ':id_age' => $agenceId ?: null,
        ':type'   => $forceType,
        ':sous'   => $sousType,
        ':nom'    => $file['name'],
        ':url'    => $publicUrl,
        ':taille' => (int)$file['size'],
        ':mime'   => 'application/pdf',
        ':json'   => !empty($fields) ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
        ':resume' => $resume,
        ':method' => $method,
        ':user'   => $userId ?: null,
    ]);
    $documentId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'          => true,
        'document_id' => $documentId,
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
