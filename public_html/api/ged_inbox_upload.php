<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Endpoint d'UPLOAD de doc à l'inbox.
 * Fichier : public_html/api/ged_inbox_upload.php
 *
 * Pourquoi ce fichier dans /api/ et pas /modules/ged/ :
 *   Hostinger mod_security bloque par défaut les uploads multipart de PDF
 *   vers les paths "non-API" (ex /modules/, /pages/...). Le path /api/
 *   est whitelisté, c'est ce que fait déjà api/bien_import_upload.php
 *   et api/immeuble_doc_upload.php avec succès en prod.
 *
 * Ce endpoint :
 *   - Reçoit un POST multipart $_FILES['file']
 *   - Stocke en quarantaine local : /storage/ged/00_A_CLASSER_IA/_quarantine_YYYY-MM/uniq_name
 *   - Lance gedExtractDocument() (Claude Sonnet 4.6 + fallback GPT-4o)
 *   - INSERT dans ged_analyses (status=to_validate)
 *   - Retourne JSON {ok, analysis_id, engine, model, confidence}
 *
 * Pour la suite (validate, skip, reject, preview), utilise toujours
 * /modules/ged/ged_inbox_action.php — seul l'upload était bloqué par le WAF.
 */

set_time_limit(120); // OCR + IA peut prendre 30-60s

@ini_set('display_errors', '0');
error_reporting(0);
ob_start();

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
require_once dirname(__DIR__) . '/modules/ged/ged_functions.php';
require_once dirname(__DIR__) . '/modules/ged/ged_storage.php';
require_once dirname(__DIR__) . '/modules/ged/ged_storage_local.php';
require_once dirname(__DIR__) . '/modules/ged/ged_extraction.php';

// Filet : si fatal error, on renvoie quand même du JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) @ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'      => false,
            'message' => 'Erreur fatale serveur : ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ged_up_respond(bool $ok, string $msg = '', array $extra = []): void {
    while (ob_get_level()) @ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// Trace utile pour debug serveur
@error_log(sprintf(
    '[ged_inbox_upload] %s ct=%s files=%s ip=%s',
    $_SERVER['REQUEST_METHOD'] ?? '?',
    substr((string)($_SERVER['CONTENT_TYPE'] ?? ''), 0, 60),
    isset($_FILES['file']) ? 'file' : (isset($_FILES['fichier']) ? 'fichier' : '(none)'),
    $_SERVER['REMOTE_ADDR'] ?? '?'
));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ged_up_respond(false, 'POST requis');
}

$userId    = (int)current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);

// Accepte 'file' (nouveau) ou 'fichier' (compat éventuelle)
$fileKey = isset($_FILES['file']) ? 'file' : (isset($_FILES['fichier']) ? 'fichier' : null);
if ($fileKey === null) {
    ged_up_respond(false, 'Aucun fichier reçu (champ file ou fichier manquant)');
}
$F = $_FILES[$fileKey];

if (($F['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'Fichier dépasse upload_max_filesize (php.ini)',
        UPLOAD_ERR_FORM_SIZE  => 'Fichier dépasse MAX_FILE_SIZE (formulaire)',
        UPLOAD_ERR_PARTIAL    => 'Upload partiel',
        UPLOAD_ERR_NO_FILE    => 'Aucun fichier',
        UPLOAD_ERR_NO_TMP_DIR => 'Dossier tmp manquant',
        UPLOAD_ERR_CANT_WRITE => 'Écriture disque impossible',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqué par une extension PHP',
    ];
    $code = (int)($F['error'] ?? 0);
    ged_up_respond(false, 'Erreur upload : ' . ($errMap[$code] ?? "code {$code}"));
}

$tmp     = (string)$F['tmp_name'];
$name    = (string)$F['name'];
$size    = (int)$F['size'];
$ext     = strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';

$allowed = ['pdf','jpg','jpeg','png','webp','heic'];
if (!in_array($ext, $allowed, true)) {
    ged_up_respond(false, "Extension non autorisée : {$ext}");
}
if ($size > 20 * 1024 * 1024) {
    ged_up_respond(false, 'Fichier trop volumineux (max 20 Mo)');
}

try {
    // 1. Stockage local en quarantaine
    $base   = dirname(__DIR__, 2) . '/storage/ged';
    $local  = new GedStorageLocal($base);
    $folder = $local->ensureFolder('00_A_CLASSER_IA');
    $folder = $local->ensureFolder('_quarantine_' . date('Y-m'), $folder);

    $uniq   = bin2hex(random_bytes(4));
    $up     = $local->upload($tmp, "{$uniq}_{$name}", $folder);
    $absLocal = $base . '/' . $up['file_id'];

    // 2. Extraction IA
    $extr = gedExtractDocument($absLocal);
    $ai   = $extr['data'] ?? [];

    // 3. INSERT ged_analyses
    $pdo = ged_get_pdo();
    $stmt = $pdo->prepare("
        INSERT INTO ged_analyses (
            document_id, document_table, source_type,
            ocr_engine, ocr_text, ia_engine,
            suggested_module, suggested_level_2, suggested_level_3, suggested_filename,
            detected_immeuble, detected_fournisseur, detected_locataire,
            detected_proprietaire, detected_montant, detected_date,
            suggested_action, confidence_score, status,
            id_societe, id_agence, ai_raw_response,
            sha256, storage_driver, storage_file_id, storage_folder_id,
            storage_size, storage_mime, tiers_nom, nom_original, nom_renomme,
            extension, version_doc, date_document
        ) VALUES (
            NULL, 'ged_inbox', 'upload',
            :ocr_eng, :ocr_text, :ia_eng,
            :module, :n2, :n3, NULL,
            :immeuble, :fournisseur, NULL,
            NULL, :montant, :date_doc,
            :action, :conf, 'to_validate',
            :id_soc, :id_ag, :ai_raw,
            :sha, 'local', :file_id, :folder_id,
            :size, :mime, :tiers, :nom_ori, NULL,
            :ext, 1, :date_doc2
        )
    ");
    $dateDoc = !empty($ai['date_document']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ai['date_document'])
        ? (string)$ai['date_document'] : null;
    $stmt->execute([
        'ocr_eng'    => $extr['path_engine'] ?? 'unknown',
        'ocr_text'   => isset($extr['raw_text']) ? mb_substr((string)$extr['raw_text'], 0, 65535) : null,
        'ia_eng'     => $extr['model_used'] ?? 'unknown',
        'module'     => isset($ai['module'])      ? mb_substr((string)$ai['module'], 0, 50)       : null,
        'n2'         => isset($ai['niveau_2'])    ? mb_substr((string)$ai['niveau_2'], 0, 100)    : null,
        'n3'         => isset($ai['niveau_3'])    ? mb_substr((string)$ai['niveau_3'], 0, 100)    : null,
        'immeuble'   => isset($ai['immeuble'])    ? mb_substr((string)$ai['immeuble'], 0, 255)    : null,
        'fournisseur'=> isset($ai['fournisseur']) ? mb_substr((string)$ai['fournisseur'], 0, 255) : null,
        'montant'    => isset($ai['montant_ttc']) ? (float)$ai['montant_ttc']
                       : (isset($ai['montant_ht']) ? (float)$ai['montant_ht'] : null),
        'date_doc'   => $dateDoc,
        'action'     => isset($ai['action_proposee']) ? (string)$ai['action_proposee'] : null,
        'conf'       => isset($ai['confiance_globale']) ? (float)$ai['confiance_globale'] : null,
        'id_soc'     => $societeId ?: null,
        'id_ag'      => $agenceId  ?: null,
        'ai_raw'     => json_encode($ai, JSON_UNESCAPED_UNICODE),
        'sha'        => $up['sha256'],
        'file_id'    => $up['file_id'],
        'folder_id'  => $up['folder_id'],
        'size'       => $up['size'],
        'mime'       => $up['mime'],
        'tiers'      => isset($ai['tiers_principal']) ? mb_substr((string)$ai['tiers_principal'], 0, 255) : null,
        'nom_ori'    => mb_substr($name, 0, 255),
        'ext'        => $ext,
        'date_doc2'  => $dateDoc,
    ]);
    $insertedId = (int)$pdo->lastInsertId();

    ged_up_respond(true, 'Document analysé et ajouté à la file', [
        'analysis_id' => $insertedId,
        'engine'      => $extr['path_engine'] ?? null,
        'model'       => $extr['model_used'] ?? null,
        'confidence'  => $ai['confiance_globale'] ?? null,
    ]);

} catch (Throwable $e) {
    @error_log('[ged_inbox_upload] EXCEPTION: ' . $e->getMessage());
    ged_up_respond(false, 'Erreur traitement : ' . $e->getMessage());
}
