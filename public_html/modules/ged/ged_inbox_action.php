<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Handler AJAX de l'inbox.
 * Fichier : modules/ged/ged_inbox_action.php
 *
 * Actions :
 *   upload    : POST file → quarantaine local → extract IA → INSERT ged_analyses (status=to_validate)
 *   validate  : POST analysis_id + champs édités → UPDATE BDD → rename + upload Drive (background)
 *   skip      : laisse status=to_validate, retourne next analysis_id
 *   reject    : status=rejected
 *   preview   : GET id → stream le fichier local en quarantaine (PDF/image inline)
 */

// ── Mode défensif JSON ULTRA STRICT ──────────────────────────────────────────
$__contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$__isJsonBody  = stripos($__contentType, 'application/json') !== false;

// Si payload JSON (upload base64), $_REQUEST['action'] sera vide.
// On lit le body pour pré-extraire l'action (avant bootstrap).
$__action = $_REQUEST['action'] ?? '';
if ($__action === '' && $__isJsonBody && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $__rawBody = file_get_contents('php://input');
    if (is_string($__rawBody) && $__rawBody !== '') {
        $__pre = json_decode($__rawBody, true);
        if (is_array($__pre)) {
            $__action = (string)($__pre['action'] ?? '');
            // Mémorise le body brut pour ne pas re-lire php://input dans la suite
            $GLOBALS['__GED_JSON_BODY'] = $__rawBody;
        }
    }
}

$__isJson = ($__action !== 'preview');

// Trace minimale pour debug serveur (n'expose rien de sensible)
@error_log(sprintf(
    '[ged_inbox_action] %s ct=%s action=%s ip=%s',
    $_SERVER['REQUEST_METHOD'] ?? '?',
    substr($__contentType, 0, 50),
    $__action ?: '(none)',
    $_SERVER['REMOTE_ADDR'] ?? '?'
));

if ($__isJson) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(0);
    if (function_exists('ob_get_level')) {
        while (ob_get_level()) @ob_end_clean();
    }
    ob_start();
}

require_once __DIR__ . '/../../inc/bootstrap.php';

// Bootstrap force display_errors=1 en mode dev → on ré-écrase pour les endpoints JSON
if ($__isJson) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(0);
}

// AJAX-aware auth check : si pas loggé, JSON 401 plutôt que redirect HTML
if ($__isJson && empty($_SESSION['user_id'])) {
    while (ob_get_level()) @ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Session expirée — reconnecte-toi.', 'auth' => false]);
    exit;
}

require_login(); // safety net (au cas où user_id existe mais session invalide)
require_once __DIR__ . '/ged_functions_legacy.php'; // archivé Phase 1.1 — fusion Phase 2/3
require_once __DIR__ . '/ged_storage.php';
require_once __DIR__ . '/ged_storage_local.php';
require_once __DIR__ . '/ged_classer.php';
require_once __DIR__ . '/ged_jobs.php';

$action = $_REQUEST['action'] ?? '';
$userId = current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$isAdmin   = in_array(current_role_id(), [1, 7, 8], true);

// ── Filet de sécurité : si fatal error, on renvoie quand même du JSON ────────
register_shutdown_function(function () use ($__action) {
    if ($__action === 'preview') return;
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

// ── Action `preview` (GET) ──────────────────────────────────────────────────
if ($action === 'preview') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); exit('id manquant'); }

    $pdo = $GLOBALS['pdo'];
    $where = "id = :id";
    $params = ['id' => $id];
    if (!$isAdmin && $societeId > 0) {
        $where .= " AND id_societe = :sid";
        $params['sid'] = $societeId;
    }
    $st = $pdo->prepare("SELECT storage_driver, storage_file_id, storage_mime, nom_original, extension FROM ged_analyses WHERE {$where}");
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || empty($r['storage_file_id'])) { http_response_code(404); exit('fichier introuvable'); }

    if ($r['storage_driver'] !== 'local') {
        http_response_code(404); exit('preview disponible uniquement en quarantaine locale');
    }

    $base = defined('GED_STORAGE_LOCAL_BASE') ? (string)GED_STORAGE_LOCAL_BASE
          : dirname(__DIR__, 3) . '/storage/ged';
    $abs = $base . '/' . ltrim((string)$r['storage_file_id'], '/');
    if (!is_file($abs)) { http_response_code(404); exit('fichier physique introuvable'); }

    $mime = $r['storage_mime'] ?: GedStorageDriver::detectMime($abs);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: inline; filename="' . basename($abs) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}

// ── Toutes les autres actions retournent du JSON ────────────────────────────
// On vide tout buffer accumulé (warnings HTML éventuels) avant d'écrire le JSON.
while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, must-revalidate, private');

function inbox_respond(bool $ok, string $msg = '', array $extra = []): void {
    while (ob_get_level()) @ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function inbox_respond_and_continue(bool $ok, string $msg = '', array $extra = []): void
{
    // Réponse immédiate pour éviter les timeouts (OCR/IA peuvent durer)
    while (ob_get_level()) @ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);

    // Flush + libère le client; le script peut continuer (php-fpm)
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        @flush();
    }
    @ignore_user_abort(true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    inbox_respond(false, 'POST requis');
}

try {
    switch ($action) {

        // ── Upload + analyse IA ─────────────────────────────────────────────
        case 'upload': {
            // 2 modes : JSON base64 (prod, contourne WAF) ou multipart $_FILES (local/CLI fallback)
            $tmp  = null;
            $name = '';
            $cleanupTmp = false; // true si on a créé un tempnam à supprimer

            if ($__isJsonBody) {
                // Body JSON déjà lu en pré-bootstrap → réutilise
                $rawBody = $GLOBALS['__GED_JSON_BODY'] ?? null;
                if ($rawBody === null) {
                    $rawBody = (string)file_get_contents('php://input');
                }
                $payload = json_decode((string)$rawBody, true);
                if (!is_array($payload)) {
                    inbox_respond(false, 'JSON body invalide ou vide');
                }
                $name        = trim((string)($payload['filename']       ?? ''));
                $sizeClient  = (int)($payload['size']                    ?? 0);
                $b64         = (string)($payload['content_base64']      ?? '');

                if ($name === '')        inbox_respond(false, 'filename manquant');
                if ($b64  === '')        inbox_respond(false, 'content_base64 manquant');
                if ($sizeClient > 20 * 1024 * 1024) inbox_respond(false, 'Fichier trop volumineux (max 20 Mo).');

                // base64_decode strict (rejette tout caractère hors alphabet base64)
                $bin = base64_decode($b64, true);
                if ($bin === false || $bin === '') {
                    inbox_respond(false, 'Décodage base64 invalide');
                }
                $sizeReal = strlen($bin);
                if ($sizeReal > 20 * 1024 * 1024) {
                    inbox_respond(false, 'Contenu décodé > 20 Mo, refusé');
                }
                // Sanity : tolère 5% d'écart entre size annoncé et size réel (charset, BOM, etc.)
                if ($sizeClient > 0 && abs($sizeReal - $sizeClient) > max(1024, $sizeClient * 0.05)) {
                    inbox_respond(false, "Taille incohérente : annoncé {$sizeClient}, réel {$sizeReal}");
                }

                $tmp = tempnam(sys_get_temp_dir(), 'gedup_');
                if ($tmp === false || @file_put_contents($tmp, $bin) === false) {
                    if ($tmp !== false) @unlink($tmp);
                    inbox_respond(false, 'Impossible d\'écrire le fichier temporaire serveur');
                }
                $cleanupTmp = true;
                @error_log("[ged_inbox_action] upload JSON base64 ok : {$name} ({$sizeReal} octets) → {$tmp}");

            } else {
                // Mode multipart classique (local + curl + CLI tests)
                if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    inbox_respond(false, 'Fichier upload manquant ou erreur (multipart $_FILES vide)');
                }
                $tmp  = (string)$_FILES['file']['tmp_name'];
                $name = (string)$_FILES['file']['name'];
                @error_log("[ged_inbox_action] upload multipart ok : {$name}");
            }

            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
            $allowed = ['pdf','jpg','jpeg','png','webp','heic'];
            if (!in_array($ext, $allowed, true)) {
                if ($cleanupTmp && $tmp) @unlink($tmp);
                inbox_respond(false, "Extension non autorisée : {$ext}");
            }

            // 1. Stockage local en quarantaine
            $base = dirname(__DIR__, 3) . '/storage/ged';
            $local = new GedStorageLocal($base);
            $folder = $local->ensureFolder('00_A_CLASSER_IA');
            $folder = $local->ensureFolder('_quarantine_' . date('Y-m'), $folder);

            $uniq = bin2hex(random_bytes(4));
            $up = $local->upload($tmp, "{$uniq}_{$name}", $folder);

            // 2. Extraction IA
            require_once __DIR__ . '/ged_extraction.php';
            $absLocal = $base . '/' . $up['file_id'];
            $forcedService = isset($_POST['forced_service']) ? trim((string)$_POST['forced_service']) : null;
            if ($forcedService === '') $forcedService = null;
            $extr = gedExtractDocument($absLocal, $forcedService);

            $ai = $extr['data'] ?? [];

            // 3. INSERT ged_analyses (status=to_validate)
            // Après OCR/IA, la connexion MySQL a pu timeout (mutualisé → wait_timeout bas).
            $pdo = function_exists('db_keepalive') ? db_keepalive() : ged_get_pdo();
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
                    :module, :n2, :n3, :sugname,
                    :immeuble, :fournisseur, :locataire,
                    :proprio, :montant, :date_doc,
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
                'sugname'    => null,
                'immeuble'   => isset($ai['immeuble'])    ? mb_substr((string)$ai['immeuble'], 0, 255)    : null,
                'fournisseur'=> isset($ai['fournisseur']) ? mb_substr((string)$ai['fournisseur'], 0, 255) : null,
                'locataire'  => null,
                'proprio'    => null,
                'montant'    => isset($ai['montant_ttc']) ? (float)$ai['montant_ttc'] : (isset($ai['montant_ht']) ? (float)$ai['montant_ht'] : null),
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

            // Cleanup du tempnam JSON upload si on en a créé un
            if ($cleanupTmp && $tmp && is_file($tmp)) @unlink($tmp);

            inbox_respond(true, 'Document analysé et ajouté à la file', [
                'analysis_id' => $insertedId,
                'engine'      => $extr['path_engine'] ?? null,
                'model'       => $extr['model_used'] ?? null,
                'confidence'  => $ai['confiance_globale'] ?? null,
            ]);
        }

        // ── Classer (low-cost) : re-lance une analyse minimale sans IA premium ─
        case 'classer': {
            $analysisId = (int)($_POST['analysis_id'] ?? 0);
            if ($analysisId <= 0) inbox_respond(false, 'analysis_id manquant');

            $forcedService = isset($_POST['forced_service']) ? trim((string)$_POST['forced_service']) : '';
            if ($forcedService === '') $forcedService = null;

            $pdo = ged_get_pdo();
            $where = "id = :id";
            $params = ['id' => $analysisId];
            if (!$isAdmin && $societeId > 0) {
                $where .= " AND id_societe = :sid";
                $params['sid'] = $societeId;
            }
            $st = $pdo->prepare("
                SELECT id, storage_driver, storage_file_id, nom_original, extension, ai_raw_response
                FROM ged_analyses
                WHERE {$where}
            ");
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || empty($r['storage_file_id'])) inbox_respond(false, 'Analyse introuvable ou fichier manquant');
            if (!empty($r['storage_driver']) && $r['storage_driver'] !== 'local') {
                inbox_respond(false, 'Classer disponible uniquement si le fichier est en quarantaine locale');
            }

            $base = dirname(__DIR__, 3) . '/storage/ged';
            $abs  = $base . '/' . $r['storage_file_id'];
            if (!is_file($abs)) inbox_respond(false, 'Fichier local introuvable (quarantaine)');

            $orig = (string)($r['nom_original'] ?? '');
            if ($orig === '') {
                $orig = 'document.' . ((string)($r['extension'] ?? 'bin') ?: 'bin');
            }

            $class = gedClasserLowCost($abs, $orig);
            $conf  = isset($class['confidence']) ? (float)$class['confidence'] : 0.0;

            $module  = isset($class['module']) ? (string)$class['module'] : null;
            $niv2    = isset($class['niveau_2']) ? (string)$class['niveau_2'] : null;
            $niv3    = isset($class['niveau_3']) ? (string)$class['niveau_3'] : null;
            $typeDoc = isset($class['type_document']) ? (string)$class['type_document'] : null;
            $tiers   = isset($class['tiers_principal']) ? (string)$class['tiers_principal'] : null;

            $dateDoc = isset($class['date_document']) && is_string($class['date_document']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $class['date_document'])
                ? (string)$class['date_document'] : null;
            $montant = isset($class['montant_ttc']) && $class['montant_ttc'] !== null ? (float)$class['montant_ttc'] : null;

            if ($forcedService !== null) {
                $module = $forcedService;
            }

            $analysisLevel = (string)($class['analysis_level'] ?? 'classer_v1');
            $engine        = (string)($class['engine'] ?? 'filename_only');

            $rawPrev = json_decode((string)($r['ai_raw_response'] ?? ''), true);
            if (!is_array($rawPrev)) $rawPrev = [];

            $aiRaw = $rawPrev;
            $aiRaw['_status']         = 'ready';
            $aiRaw['_analysis_level'] = $analysisLevel;
            $aiRaw['_engine']         = $engine;
            $aiRaw['_cost']           = 'low';
            $aiRaw['type_document']   = $typeDoc ? strtoupper($typeDoc) : null;
            $aiRaw['module']          = $module ? strtoupper((string)$module) : null;
            $aiRaw['niveau_2']        = $niv2;
            $aiRaw['niveau_3']        = $niv3;
            $aiRaw['date_document']   = $dateDoc;
            $aiRaw['montant_ttc']     = $montant;
            $aiRaw['tiers_principal'] = $tiers;
            $aiRaw['banque_detectee'] = $class['banque_detectee'] ?? null;
            $aiRaw['description_courte'] = $class['title'] ?? null;
            $aiRaw['confiance_globale']  = $conf;
            $aiRaw['forced_service']  = $forcedService;

            $status = $conf >= 75.0 ? 'to_validate' : 'manual_review';

            $upd = $pdo->prepare("
                UPDATE ged_analyses SET
                    ocr_engine = :ocr_eng,
                    ocr_text   = :ocr_text,
                    ia_engine  = :ia_eng,
                    suggested_module  = :module,
                    suggested_level_2 = :n2,
                    suggested_level_3 = :n3,
                    suggested_filename = :sfn,
                    detected_montant  = :montant,
                    detected_date     = :date_doc,
                    date_document     = :date_doc2,
                    suggested_action  = :action,
                    confidence_score  = :conf,
                    status            = :status,
                    ai_raw_response   = :ai_raw,
                    updated_at        = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                'ocr_eng'   => mb_substr($engine, 0, 50),
                'ocr_text'  => isset($class['raw_text']) ? mb_substr((string)$class['raw_text'], 0, 65535) : null,
                'ia_eng'    => mb_substr($analysisLevel, 0, 50),
                'module'    => $module !== null ? mb_substr((string)$module, 0, 50) : null,
                'n2'        => $niv2 !== null ? mb_substr((string)$niv2, 0, 100) : null,
                'n3'        => $niv3 !== null ? mb_substr((string)$niv3, 0, 100) : null,
                'sfn'       => isset($class['suggested_filename']) ? mb_substr((string)$class['suggested_filename'], 0, 255) : null,
                'montant'   => $montant,
                'date_doc'  => $dateDoc,
                'date_doc2' => $dateDoc,
                'action'    => $conf >= 75.0 ? 'Classer (éco) — prêt à valider' : 'Classer (éco) — à vérifier',
                'conf'      => $conf,
                'status'    => $status,
                'ai_raw'    => json_encode($aiRaw, JSON_UNESCAPED_UNICODE),
                'id'        => $analysisId,
            ]);

            inbox_respond(true, 'Classé (éco)', [
                'next_id'       => $analysisId,
                'analysis_level'=> $analysisLevel,
                'engine'        => $engine,
                'confidence'    => $conf,
            ]);
        }

        // ── Analyse IA avancée (sur clic utilisateur uniquement) ────────────
        case 'analyze_advanced': {
            $analysisId = (int)($_POST['analysis_id'] ?? 0);
            if ($analysisId <= 0) inbox_respond(false, 'analysis_id manquant');

            $forcedService = isset($_POST['forced_service']) ? trim((string)$_POST['forced_service']) : '';
            if ($forcedService === '') $forcedService = null;

            $pdo = ged_get_pdo();
            $where = "id = :id";
            $params = ['id' => $analysisId];
            if (!$isAdmin && $societeId > 0) {
                $where .= " AND id_societe = :sid";
                $params['sid'] = $societeId;
            }
            $st = $pdo->prepare("
                SELECT id, storage_driver, storage_file_id, ai_raw_response
                FROM ged_analyses
                WHERE {$where}
            ");
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || empty($r['storage_file_id'])) inbox_respond(false, 'Analyse introuvable ou fichier manquant');
            if (!empty($r['storage_driver']) && $r['storage_driver'] !== 'local') {
                inbox_respond(false, 'Analyse IA avancée disponible uniquement si le fichier est en quarantaine locale');
            }

            $base = dirname(__DIR__, 3) . '/storage/ged';
            $abs  = $base . '/' . $r['storage_file_id'];
            if (!is_file($abs)) inbox_respond(false, 'Fichier local introuvable (quarantaine)');

            // Marque la ligne comme "queued" en conservant les champs déjà extraits
            $rawPrev = json_decode((string)($r['ai_raw_response'] ?? ''), true);
            if (!is_array($rawPrev)) $rawPrev = [];
            $rawPrev['_status'] = 'queued';
            $rawPrev['_analysis_level'] = 'ai_advanced_v1';
            $rawPrev['forced_service'] = $forcedService;

            $pdo->prepare("
                UPDATE ged_analyses
                SET ia_engine = 'queued',
                    suggested_action = 'Analyse IA avancée en cours…',
                    status = 'manual_review',
                    ai_raw_response = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([json_encode($rawPrev, JSON_UNESCAPED_UNICODE), $analysisId]);

            // Enqueue job BDD (exécution via cron worker). Fallback : si la migration n'est
            // pas encore appliquée, on garde le comportement best-effort inline.
            try {
                $jobId = ged_jobs_enqueue('ged_analyze_advanced', $analysisId, [
                    'forced_service' => $forcedService,
                    'requested_by'   => (int)$userId,
                    'requested_at'   => date('c'),
                ], 5, null, 3);

                inbox_respond(true, 'Analyse IA avancée mise en file (cron)', [
                    'job_id'  => $jobId,
                    'next_id' => $analysisId,
                ]);
            } catch (Throwable $queueErr) {
                @error_log('[ged_inbox_action analyze_advanced] enqueue failed → fallback inline: ' . $queueErr->getMessage());

                // Répond immédiatement (évite timeouts), puis continue l'analyse IA.
                inbox_respond_and_continue(true, 'Analyse IA avancée lancée (fallback)', ['next_id' => $analysisId]);

                try {
                    require_once __DIR__ . '/ged_extraction.php';
                    $extr = gedExtractDocument($abs, $forcedService);
                    $ai   = $extr['data'] ?? [];
                    if (!is_array($ai)) $ai = [];

                    $ai['_analysis_level'] = 'ai_advanced_v1';
                    $ai['forced_service']  = $forcedService;

                    $pdo = function_exists('db_keepalive') ? db_keepalive() : $pdo;
                    $dateDoc = !empty($ai['date_document']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ai['date_document'])
                        ? (string)$ai['date_document'] : null;

                    $upd = $pdo->prepare("
                        UPDATE ged_analyses SET
                            ocr_engine = :ocr_eng,
                            ocr_text   = :ocr_text,
                            ia_engine  = :ia_eng,
                            suggested_module  = :module,
                            suggested_level_2 = :n2,
                            suggested_level_3 = :n3,
                            detected_immeuble = :immeuble,
                            detected_fournisseur = :fournisseur,
                            detected_montant  = :montant,
                            detected_date     = :date_doc,
                            suggested_action  = :action,
                            confidence_score  = :conf,
                            ai_raw_response   = :ai_raw,
                            date_document     = :date_doc2,
                            status            = :status,
                            updated_at        = NOW()
                        WHERE id = :id
                    ");
                    $status = ($extr['ok'] ?? false) ? 'to_validate' : 'manual_review';
                    $upd->execute([
                        'ocr_eng'   => $extr['path_engine'] ?? 'unknown',
                        'ocr_text'  => isset($extr['raw_text']) ? mb_substr((string)$extr['raw_text'], 0, 65535) : null,
                        'ia_eng'    => $extr['model_used'] ?? 'unknown',
                        'module'    => isset($ai['module'])      ? mb_substr((string)$ai['module'], 0, 50)       : null,
                        'n2'        => isset($ai['niveau_2'])    ? mb_substr((string)$ai['niveau_2'], 0, 100)    : null,
                        'n3'        => isset($ai['niveau_3'])    ? mb_substr((string)$ai['niveau_3'], 0, 100)    : null,
                        'immeuble'  => isset($ai['immeuble'])    ? mb_substr((string)$ai['immeuble'], 0, 255)    : null,
                        'fournisseur'=> isset($ai['fournisseur']) ? mb_substr((string)$ai['fournisseur'], 0, 255) : null,
                        'montant'   => isset($ai['montant_ttc']) ? (float)$ai['montant_ttc']
                                    : (isset($ai['montant_ht']) ? (float)$ai['montant_ht'] : null),
                        'date_doc'  => $dateDoc,
                        'action'    => isset($ai['action_proposee']) ? (string)$ai['action_proposee'] : null,
                        'conf'      => isset($ai['confiance_globale']) ? (float)$ai['confiance_globale'] : null,
                        'ai_raw'    => json_encode($ai, JSON_UNESCAPED_UNICODE),
                        'date_doc2' => $dateDoc,
                        'status'    => $status,
                        'id'        => $analysisId,
                    ]);
                } catch (Throwable $e2) {
                    @error_log('[ged_inbox_action analyze_advanced fallback] ' . $e2->getMessage());
                    try {
                        $pdo = function_exists('db_keepalive') ? db_keepalive() : $pdo;
                        $pdo->prepare("
                            UPDATE ged_analyses
                            SET status='manual_review',
                                suggested_action = 'Analyse IA avancée en erreur — validation manuelle',
                                ia_engine = 'failed',
                                ai_raw_response = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([json_encode(['_error' => $e2->getMessage()], JSON_UNESCAPED_UNICODE), $analysisId]);
                    } catch (Throwable) { /* ignore */ }
                }

                exit;
            }
        }

        // ── Validation : update fields → rename + Drive upload (background) ─
        case 'validate': {
            $analysisId = (int)($_POST['analysis_id'] ?? 0);
            if ($analysisId <= 0) inbox_respond(false, 'analysis_id manquant');

            $pdo = ged_get_pdo();

            // 1. Update champs édités par user
            $fields = [
                'suggested_module'  => $_POST['suggested_module']  ?? null,
                'suggested_level_2' => $_POST['suggested_level_2'] ?? null,
                'suggested_level_3' => $_POST['suggested_level_3'] ?? null,
                'date_document'     => !empty($_POST['date_document']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['date_document']) ? $_POST['date_document'] : null,
                'detected_montant'  => isset($_POST['detected_montant']) && $_POST['detected_montant'] !== '' ? (float)$_POST['detected_montant'] : null,
                'tiers_nom'         => $_POST['tiers_nom']   ?? null,
                'objet_type'        => $_POST['objet_type']  ?? null,
                'objet_id'          => isset($_POST['objet_id']) ? (int)$_POST['objet_id'] : null,
                'ref_societe'       => $_POST['ref_societe'] ?? null,
                'ref_agence'        => $_POST['ref_agence']  ?? null,
            ];
            if (empty($fields['objet_id']) || (int)$fields['objet_id'] <= 0) {
                inbox_respond(false, 'ID objet invalide (doit être > 0)');
            }
            // Sync user-edited type_document into ai_raw_response.type_document
            $newType = isset($_POST['type_document']) ? trim((string)$_POST['type_document']) : '';

            $sets = []; $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "{$k} = :{$k}";
                $vals[$k] = $v;
            }
            $vals['id'] = $analysisId;
            $up = $pdo->prepare("UPDATE ged_analyses SET " . implode(',', $sets) . " WHERE id = :id");
            $up->execute($vals);

            // Patch ai_raw_response.type_document si modifié
            if ($newType !== '') {
                $st = $pdo->prepare("SELECT ai_raw_response FROM ged_analyses WHERE id = ?");
                $st->execute([$analysisId]);
                $raw = json_decode((string)$st->fetchColumn(), true);
                if (!is_array($raw)) $raw = [];
                $raw['type_document'] = strtoupper($newType);
                $pdo->prepare("UPDATE ged_analyses SET ai_raw_response = ? WHERE id = ?")
                    ->execute([json_encode($raw, JSON_UNESCAPED_UNICODE), $analysisId]);
            }

            // 2. Marque comme validée
            $ok = gedValidateAnalysis($analysisId, $userId);
            if (!$ok) inbox_respond(false, 'Analyse introuvable ou déjà validée');

            // 3. Rename + Drive upload (background-friendly : on répond avant que ce soit fini)
            // En PHP synchrone on doit faire avant la réponse ; pour vrai async il faudrait une queue.
            // Compromis MVP : on lance, on attend max 1s, puis on répond.
            $promoted = null; $promoteError = null;
            try {
                require_once __DIR__ . '/ged_rename_and_store.php';
                $stChk = $pdo->prepare("SELECT storage_file_id, storage_driver FROM ged_analyses WHERE id = ?");
                $stChk->execute([$analysisId]);
                $r = $stChk->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['storage_file_id']) && ($r['storage_driver'] === 'local' || $r['storage_driver'] === null)) {
                    // Marque une tentative de sync Drive (même si la config Drive n'est pas prête, on garde une trace)
                    $pdo->prepare("
                        UPDATE ged_analyses
                        SET drive_sync_status = 'pending',
                            drive_sync_error = NULL,
                            drive_sync_attempts = COALESCE(drive_sync_attempts, 0) + 1,
                            drive_sync_last_attempt_at = NOW()
                        WHERE id = ?
                    ")->execute([$analysisId]);

                    $promoted = gedRenameAndStoreFromAnalysis($analysisId);

                    // Si le driver actif n'est PAS Drive, on considère la sync Drive comme non effectuée.
                    if (!empty($promoted['storage_driver']) && $promoted['storage_driver'] !== 'google_drive') {
                        $pdo->prepare("
                            UPDATE ged_analyses
                            SET drive_sync_status = 'error',
                                drive_sync_error  = ?
                            WHERE id = ?
                        ")->execute([
                            "Driver actif = {$promoted['storage_driver']} (Drive non configuré ou désactivé).",
                            $analysisId,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                $promoteError = $e->getMessage();
                error_log('[ged_inbox validate] ' . $e->getMessage());
                try {
                    $pdo->prepare("
                        UPDATE ged_analyses
                        SET drive_sync_status = 'error',
                            drive_sync_error  = ?,
                            drive_sync_last_attempt_at = NOW()
                        WHERE id = ?
                    ")->execute([mb_substr($promoteError, 0, 2000), $analysisId]);
                } catch (Throwable) {
                    // ignore (best-effort)
                }
            }

            // 4. Récupère le prochain doc à valider (pour optimistic UI)
            $nextId = inbox_pick_next_analysis_id($pdo, $userId, $societeId, $isAdmin, $analysisId);

            inbox_respond(true, 'Validé' . ($promoted ? ' + classé sur Drive' : ''), [
                'promoted'      => $promoted !== null,
                'promote_error' => $promoteError,
                'nom_renomme'   => $promoted['nom_renomme'] ?? null,
                'next_id'       => $nextId,
            ]);
        }

        // ── Skip : pas de changement, juste next ────────────────────────────
        case 'skip': {
            $analysisId = (int)($_POST['analysis_id'] ?? 0);
            $pdo = ged_get_pdo();
            $nextId = inbox_pick_next_analysis_id($pdo, $userId, $societeId, $isAdmin, $analysisId);
            inbox_respond(true, 'Skipped', ['next_id' => $nextId]);
        }

        // ── Reject : status = rejected ──────────────────────────────────────
        case 'reject': {
            $analysisId = (int)($_POST['analysis_id'] ?? 0);
            if ($analysisId <= 0) inbox_respond(false, 'analysis_id manquant');
            $ok = gedRejectAnalysis($analysisId, $userId);
            $pdo = ged_get_pdo();
            $nextId = inbox_pick_next_analysis_id($pdo, $userId, $societeId, $isAdmin, $analysisId);
            inbox_respond($ok, $ok ? 'Rejeté' : 'Analyse introuvable', ['next_id' => $nextId]);
        }

        default:
            inbox_respond(false, 'Action inconnue : ' . htmlspecialchars((string)$action));
    }
} catch (Throwable $e) {
    error_log('[ged_inbox_action] ' . $e->getMessage());
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Erreur interne';
    inbox_respond(false, $msg);
}

/**
 * Sélectionne l'ID de la prochaine analyse à valider (file priorisée par confiance).
 */
function inbox_pick_next_analysis_id(PDO $pdo, int $userId, int $societeId, bool $isAdmin, int $excludeId): ?int
{
    $where = "status IN ('to_validate','manual_review') AND id != :excl";
    $params = ['excl' => $excludeId];
    if (!$isAdmin && $societeId > 0) {
        $where .= " AND id_societe = :sid";
        $params['sid'] = $societeId;
    }
    $sql = "SELECT id FROM ged_analyses WHERE {$where}
            ORDER BY (confidence_score IS NULL) DESC, confidence_score ASC, created_at ASC
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $id = (int)$st->fetchColumn();
    return $id > 0 ? $id : null;
}
