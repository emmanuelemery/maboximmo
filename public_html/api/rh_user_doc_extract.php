<?php
/**
 * POST /api/rh_user_doc_extract.php
 *
 * Upload + analyse automatique d'un document utilisateur.
 *
 * Pipeline :
 *   1. Validation upload (extension, taille, MIME réel)
 *   2. Stockage physique dans uploads/user_docs/<user_id>/<hash>.<ext>
 *   3. Insertion ligne rh_user_documents avec analysis_status='analyzing'
 *   4. Appel rhExtractDocument() (router + détection + extracteur)
 *   5. UPDATE de la ligne avec le résultat (type, score, extracted_data…)
 *   6. Réponse JSON avec la structure complète pour le front
 *
 * Paramètres POST (multipart/form-data) :
 *   - fichier   : fichier uploadé (PDF, JPG, PNG, WEBP, HEIC)
 *   - hint_type : (optionnel) force un type (rib, cni, …) — sinon auto-détect
 *   - categorie : (optionnel) catégorie user_docs pour la liste ultérieure
 *
 * Réponse JSON :
 *   {
 *     "ok": true,
 *     "doc_id": 42,
 *     "doc_type": "rib",
 *     "confidence": 85,
 *     "engine": "hybrid",
 *     "score": 92,
 *     "fields": { iban: "...", bic: "...", ... },
 *     "validations": { iban: "ok", bic: "ok" },
 *     "raw_text_preview": "..." (tronqué 500 car)
 *   }
 *
 * Erreur :
 *   { "ok": false, "error": "Message d'erreur" }
 */
declare(strict_types=1);
set_time_limit(180);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/rh_document_extractor.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

/**
 * Shutdown handler : si une erreur fatale PHP survient (OutOfMemory,
 * parse error, etc.), on renvoie quand même un JSON d'erreur propre
 * plutôt qu'une page HTML qui ferait planter le front JSON parser.
 */
register_shutdown_function(static function () {
    $err = error_get_last();
    if ($err && in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Erreur fatale PHP : ' . ($err['message'] ?? 'unknown'),
            'file'  => basename($err['file'] ?? ''),
            'line'  => $err['line'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
    }
});

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }

    verify_csrf_any();

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        throw new RuntimeException('PDO non disponible');
    }

    $userId = SecurityGuard::userId();
    if ($userId <= 0) {
        throw new RuntimeException('Session invalide');
    }

    // ─── 1. Validation upload ──────────────────────────────────────
    if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fichier manquant ou erreur upload');
    }
    $file = $_FILES['fichier'];

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload invalide');
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Fichier trop volumineux (max 10 Mo)');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException("Extension non autorisée : {$ext}");
    }

    // MIME réel
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']) ?: 'application/octet-stream';
    finfo_close($finfo);

    $allowedMimes = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
    ];
    if (!in_array($realMime, $allowedMimes, true)) {
        throw new RuntimeException("Type MIME non autorisé : {$realMime}");
    }

    // ─── 2. Stockage physique ──────────────────────────────────────
    // Convention unifiée : TOUS les docs RH (manuels ou IA) dans /uploads/rh_docs/<user_id>/
    $uploadBaseDir = dirname(__DIR__) . '/uploads/rh_docs/' . $userId . '/';
    if (!is_dir($uploadBaseDir)) {
        @mkdir($uploadBaseDir, 0755, true);
    }

    $hashName = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = $uploadBaseDir . $hashName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Échec du stockage du fichier');
    }

    // ─── 3. Création ligne en base (analysis_status = 'analyzing') ─
    // Fusion rh_user_documents → rh_documents : on écrit directement dans la
    // table unifiée rh_documents avec les colonnes IA (analysis_*).
    $hintType  = (string)($_POST['hint_type'] ?? '');
    $categorie = (string)($_POST['categorie'] ?? 'autre');

    // Récupère id_societe / id_agence du user pour garder la cohérence
    $stmtUser = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ? LIMIT 1");
    $stmtUser->execute([$userId]);
    $userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: ['id_societe' => null, 'id_agence' => null];

    $relPath = '/uploads/rh_docs/' . $userId . '/' . $hashName;

    $stmt = $pdo->prepare("
        INSERT INTO rh_documents
            (id_user, id_societe, id_agence, categorie, type_document, label,
             filename, original_name, file_path, taille, mime_type,
             uploaded_by, upload_date, actif, version,
             analysis_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, 1, 'analyzing')
    ");
    $stmt->execute([
        $userId,
        $userMeta['id_societe'],
        $userMeta['id_agence'],
        $categorie,
        $hintType ?: null,
        $file['name'],          // label = nom original par défaut
        $hashName,
        $file['name'],
        $relPath,
        (int)$file['size'],
        $realMime,
        $userId,
    ]);
    $docId = (int)$pdo->lastInsertId();

    // ─── 4. Appel du router d'extraction ───────────────────────────
    $result = rhExtractDocument($destPath, $realMime, $hintType !== '' ? $hintType : null);

    // ─── 5. UPDATE avec le résultat + APPLICATION AU PROFIL ───────
    $applyResult = ['applied' => [], 'candidates' => [], 'skipped' => []];

    if ($result['success']) {
        // 5a. Application des champs extraits sur users (sans écrasement)
        try {
            $applyResult = rhApplyExtractedToProfile(
                $pdo,
                $userId,
                (string)$result['doc_type'],
                $result['fields'] ?? []
            );
        } catch (Throwable $applyEx) {
            error_log('[rh_user_doc_extract] apply: ' . $applyEx->getMessage());
        }

        // 5b. Score extracteur (plus pertinent que confidence de détection)
        $score = (int)($result['extractor_score'] ?? $result['confidence'] ?? 0);

        $payload = [
            'fields'      => $result['fields']      ?? [],
            'validations' => $result['validations'] ?? [],
            'confidence'  => $result['confidence']  ?? 0,
            'applied'     => $applyResult['applied']    ?? [],
            'candidates'  => $applyResult['candidates'] ?? [],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            UPDATE rh_documents
            SET type_document   = ?,
                analysis_status = 'analyzed',
                analysis_score  = ?,
                analysis_engine = ?,
                extracted_data  = ?,
                analyzed_at     = NOW()
            WHERE id = ? AND id_user = ?
        ");
        $stmt->execute([
            $result['doc_type'] ?? null,
            max(0, min(100, $score)),
            $result['engine']   ?? null,
            $json,
            $docId,
            $userId,
        ]);
    } else {
        // Pipeline a échoué — on garde le fichier mais on marque failed
        $stmt = $pdo->prepare("
            UPDATE rh_documents
            SET analysis_status = 'failed',
                analysis_error  = ?,
                analyzed_at     = NOW(),
                type_document   = ?
            WHERE id = ? AND id_user = ?
        ");
        $stmt->execute([
            mb_substr((string)($result['error'] ?? 'Erreur inconnue'), 0, 500),
            $result['doc_type'] ?? null,
            $docId,
            $userId,
        ]);
    }

    // ─── 6. Réponse JSON ───────────────────────────────────────────
    $rawPreview = mb_substr((string)($result['raw_text'] ?? ''), 0, 500, 'UTF-8');

    echo json_encode([
        'ok'              => (bool)($result['success'] ?? false),
        'doc_id'          => $docId,
        'doc_type'        => $result['doc_type']    ?? 'unknown',
        'confidence'      => (int)($result['confidence'] ?? 0),
        'engine'          => $result['engine']      ?? 'none',
        'fields'          => $result['fields']      ?? [],
        'validations'     => $result['validations'] ?? [],
        'applied'         => $applyResult['applied']    ?? [],
        'candidates'      => $applyResult['candidates'] ?? [],
        'raw_text_preview'=> $rawPreview,
        'error'           => $result['error']       ?? null,
        'file'            => [
            'name' => $file['name'],
            'size' => (int)$file['size'],
            'mime' => $realMime,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    error_log('[rh_user_doc_extract] ' . $e->getMessage());
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
