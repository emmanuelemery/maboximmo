<?php
declare(strict_types=1);

/**
 * FluxBox — intégration Mindee OCR + reconnaissance de type document.
 *
 * Workflow :
 *  1. À l'upload, on appelle fluxbox_mindee_submit_doc($docId) qui :
 *     a. Vérifie si l'OCR du hash existe déjà dans fluxbox_cache_ocr → si oui, applique direct (PAS DE 2e APPEL MINDEE)
 *     b. Sinon : soumet le doc à Mindee → stocke job_id dans source_meta.mindee_job_id
 *  2. Un endpoint de polling (mindee_poll) ou un cron rafraîchit les jobs en attente
 *  3. Quand Mindee répond, on applique le résultat :
 *     - ocr_text → fluxbox_documents.ocr_text + fluxbox_cache_ocr (cache par hash)
 *     - Type doc détecté → mapping vers classement N1-N4 (auto-classement haute confiance)
 *     - Entités extraites → entity_instance, dates, etc.
 *
 * Configuration : clé MINDEE_API_KEY via GLOBALS, define ou env.
 * Sans clé → fonctions désactivées proprement (return null/false).
 *
 * Validé EMERY 2026-05-16.
 */

if (!function_exists('fluxbox_mindee_api_key')) {
    function fluxbox_mindee_api_key(): string
    {
        $key = $GLOBALS['MINDEE_API_KEY']
            ?? (defined('MINDEE_API_KEY') ? MINDEE_API_KEY : null)
            ?? getenv('MINDEE_API_KEY')
            ?: '';
        return (string)$key;
    }
}

if (!function_exists('fluxbox_mindee_enabled')) {
    function fluxbox_mindee_enabled(): bool
    {
        return fluxbox_mindee_api_key() !== '';
    }
}

if (!function_exists('fluxbox_mindee_endpoint')) {
    /**
     * Endpoint Mindee selon le model (OCR générique ou model spécifique).
     * Référence mémoire : model OCR `88dd1dbd-...` validé EMERY.
     */
    function fluxbox_mindee_endpoint(string $product = 'ocr'): string
    {
        return 'https://api.mindee.net/v2/products/' . $product . '/enqueue';
    }
}

if (!function_exists('fluxbox_mindee_submit_doc')) {
    /**
     * Soumet un fluxbox_document à Mindee — ou applique le cache si déjà OCRisé.
     *
     * @return array{
     *   ok:bool,
     *   cached:bool,       // true si déjà dans fluxbox_cache_ocr
     *   job_id:?string,    // ID du job Mindee si soumis
     *   reason:?string,    // raison si non soumis (Mindee désactivé, doc déjà OCRisé...)
     * }
     */
    function fluxbox_mindee_submit_doc(int $docId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_pdo();

        // Charge le doc
        $st = $pdo->prepare("SELECT id, hash_sha256, fichier_chemin, fichier_nom, mime_type, ocr_status, ocr_text, source_meta FROM fluxbox_documents WHERE id = ? LIMIT 1");
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return ['ok'=>false, 'cached'=>false, 'job_id'=>null, 'reason'=>'document introuvable'];

        // ─── PAS DE DOUBLE OCR : si déjà OCRisé ou en cache, on applique direct ───
        $hash = (string)$doc['hash_sha256'];
        if (!empty($doc['ocr_text']) && (string)$doc['ocr_status'] === 'done') {
            return ['ok'=>true, 'cached'=>true, 'job_id'=>null, 'reason'=>'doc déjà OCRisé'];
        }

        // Cache cross-document par hash binaire SHA-256
        try {
            $stC = $pdo->prepare("SELECT ocr_text FROM fluxbox_cache_ocr WHERE hash_sha256 = ? LIMIT 1");
            $stC->execute([$hash]);
            $cachedText = (string)($stC->fetchColumn() ?: '');
            if ($cachedText !== '') {
                // Applique le cache sans appeler Mindee
                $pdo->prepare("UPDATE fluxbox_documents SET ocr_text = ?, ocr_status = 'cached', updated_at = NOW() WHERE id = ?")
                    ->execute([$cachedText, $docId]);
                return ['ok'=>true, 'cached'=>true, 'job_id'=>null, 'reason'=>'OCR récupéré du cache (hash identique)'];
            }
        } catch (Throwable) {
            // Table cache absente — continue sans
        }

        // Si Mindee n'est pas configuré, on s'arrête là
        if (!fluxbox_mindee_enabled()) {
            return ['ok'=>false, 'cached'=>false, 'job_id'=>null, 'reason'=>'Mindee non configuré (MINDEE_API_KEY manquante)'];
        }

        // Résolution du chemin physique
        $path = (string)$doc['fichier_chemin'];
        if ($path !== '' && !preg_match('#^([A-Za-z]:|/)#', $path)) {
            $path = __DIR__ . '/../storage_fluxbox/' . $path;
        }
        if (!is_file($path)) {
            return ['ok'=>false, 'cached'=>false, 'job_id'=>null, 'reason'=>'fichier physique introuvable'];
        }

        // Filtre formats supportés (PDF, images uniquement — pas de .msg, .docx, .zip)
        $ext = strtolower(pathinfo((string)$doc['fichier_nom'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png','tiff','tif','webp','heic'], true)) {
            return ['ok'=>false, 'cached'=>false, 'job_id'=>null, 'reason'=>"format $ext non supporté par Mindee"];
        }

        // Soumission Mindee
        $jobId = fluxbox_mindee_http_submit($path, (string)($doc['mime_type'] ?? ''), (string)$doc['fichier_nom']);
        if ($jobId === null) {
            return ['ok'=>false, 'cached'=>false, 'job_id'=>null, 'reason'=>'soumission Mindee échouée'];
        }

        // Mémorise le job dans source_meta + statut OCR pending
        $meta = !empty($doc['source_meta']) ? (json_decode((string)$doc['source_meta'], true) ?: []) : [];
        $meta['mindee_job_id']      = $jobId;
        $meta['mindee_submitted_at'] = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE fluxbox_documents SET source_meta = ?, ocr_status = 'pending' WHERE id = ?")
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $docId]);

        return ['ok'=>true, 'cached'=>false, 'job_id'=>$jobId, 'reason'=>null];
    }
}

if (!function_exists('fluxbox_mindee_http_submit')) {
    /**
     * POST le fichier au endpoint Mindee enqueue. Retourne le job_id ou null.
     */
    function fluxbox_mindee_http_submit(string $filePath, string $mimeType, string $filename): ?string
    {
        $apiKey = fluxbox_mindee_api_key();
        if ($apiKey === '') return null;

        $endpoint = fluxbox_mindee_endpoint('ocr');
        $cfile = new CURLFile($filePath, $mimeType ?: 'application/octet-stream', $filename);

        $ch = curl_init($endpoint);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['document' => $cfile],
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $apiKey, // Mindee v2 : pas de préfixe "Token "
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("Mindee submit fail: HTTP=$httpCode err=$err body=" . substr((string)$resp, 0, 200));
            return null;
        }

        $json = json_decode((string)$resp, true);
        return (string)($json['job']['id'] ?? $json['id'] ?? '');
    }
}

if (!function_exists('fluxbox_mindee_http_poll')) {
    /**
     * GET le statut d'un job Mindee. Retourne :
     *   - ['status'=>'completed', 'result'=>{...}]
     *   - ['status'=>'pending']
     *   - ['status'=>'failed', 'error'=>...]
     */
    function fluxbox_mindee_http_poll(string $jobId): array
    {
        $apiKey = fluxbox_mindee_api_key();
        if ($apiKey === '') return ['status'=>'failed', 'error'=>'no_api_key'];

        $url = 'https://api.mindee.net/v2/jobs/' . urlencode($jobId);
        $ch = curl_init($url);
        if ($ch === false) return ['status'=>'failed', 'error'=>'curl_init'];
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) return ['status'=>'failed', 'error'=>'curl_exec'];

        $json = json_decode((string)$resp, true);
        if (!is_array($json)) return ['status'=>'failed', 'error'=>'invalid_json'];

        $status = strtolower((string)($json['job']['status'] ?? $json['status'] ?? 'pending'));
        if ($status === 'completed' || $status === 'success') {
            return ['status'=>'completed', 'result'=>$json];
        }
        if ($status === 'failed' || $httpCode >= 400) {
            return ['status'=>'failed', 'error'=>(string)($json['error']['message'] ?? 'unknown')];
        }
        return ['status'=>'pending'];
    }
}

if (!function_exists('fluxbox_mindee_apply_result')) {
    /**
     * Applique un résultat Mindee à un fluxbox_document :
     *  - Sauve ocr_text (+ cache cross-doc par hash)
     *  - Si type doc détecté → mapping vers classement GED
     *  - Stocke le résultat complet dans source_meta.mindee_result
     */
    function fluxbox_mindee_apply_result(int $docId, array $result, ?PDO $pdo = null): bool
    {
        if ($pdo === null) $pdo = ged_pdo();

        $st = $pdo->prepare("SELECT id, hash_sha256, source_meta FROM fluxbox_documents WHERE id = ? LIMIT 1");
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return false;

        // Extraction du texte OCR depuis la structure Mindee
        $ocrText = fluxbox_mindee_extract_text($result);
        $docType = fluxbox_mindee_detect_type($result);
        $extracted = fluxbox_mindee_extract_fields($result);

        $hash = (string)$doc['hash_sha256'];
        $meta = !empty($doc['source_meta']) ? (json_decode((string)$doc['source_meta'], true) ?: []) : [];
        $meta['mindee_completed_at'] = date('Y-m-d H:i:s');
        $meta['mindee_doc_type']     = $docType;
        $meta['mindee_extracted']    = $extracted;
        unset($meta['mindee_job_id']); // job consumé

        // Sauve ocr_text et statut
        if (function_exists('fluxbox_documents_set_ocr') && $ocrText !== '') {
            try { fluxbox_documents_set_ocr($docId, $ocrText, 'mindee', $pdo); }
            catch (Throwable) {}
        } else {
            $pdo->prepare("UPDATE fluxbox_documents SET ocr_text = ?, ocr_status = 'done', source_meta = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$ocrText, json_encode($meta, JSON_UNESCAPED_UNICODE), $docId]);
        }

        // Met à jour source_meta même si set_ocr a déjà touché à la ligne (idempotent)
        $pdo->prepare("UPDATE fluxbox_documents SET source_meta = ? WHERE id = ?")
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $docId]);

        return true;
    }
}

if (!function_exists('fluxbox_mindee_extract_text')) {
    function fluxbox_mindee_extract_text(array $result): string
    {
        // Structure Mindee v2 (selon docs) :
        //   $result['document']['inference']['pages'][i]['lines'][j]['text']
        // ou plain text dans $result['document']['inference']['raw_text']
        $doc = $result['document'] ?? $result['inference'] ?? $result;
        $rawText = $doc['inference']['raw_text']
                ?? $doc['raw_text']
                ?? $doc['ocr']['raw_text']
                ?? '';
        if ($rawText !== '') return (string)$rawText;

        // Fallback : concatène les lignes
        $pages = $doc['inference']['pages'] ?? $doc['pages'] ?? [];
        $lines = [];
        foreach ($pages as $p) {
            foreach (($p['lines'] ?? []) as $ln) {
                if (!empty($ln['text'])) $lines[] = (string)$ln['text'];
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('fluxbox_mindee_detect_type')) {
    /**
     * Identifie le type de document détecté par Mindee.
     * Retourne un type métier interne : 'invoice', 'payslip', 'bank_statement', 'id_card', etc.
     */
    function fluxbox_mindee_detect_type(array $result): string
    {
        $product = (string)($result['document']['inference']['product']['name'] ?? '');
        $map = [
            'invoice'        => 'invoice',
            'invoice_v4'     => 'invoice',
            'payslip'        => 'payslip',
            'payslip_v3'     => 'payslip',
            'bank_statement' => 'bank_statement',
            'idcard'         => 'id_card',
            'idcard_v2'      => 'id_card',
            'receipt'        => 'receipt',
            'expense_receipt'=> 'receipt',
            'carte_grise'    => 'vehicle_registration',
            'vehicle_registration' => 'vehicle_registration',
        ];
        return $map[strtolower($product)] ?? 'generic';
    }
}

if (!function_exists('fluxbox_mindee_extract_fields')) {
    /**
     * Extrait les champs structurés du résultat Mindee (montant, date, fournisseur, etc.).
     * Retourne un tableau associatif filtré.
     */
    function fluxbox_mindee_extract_fields(array $result): array
    {
        $pred = $result['document']['inference']['prediction']
             ?? $result['inference']['prediction']
             ?? [];
        $fields = [];
        // Mapping commun (Mindee retourne { value, confidence })
        $keys = ['date', 'invoice_number', 'total_amount', 'total_net', 'total_tax',
                 'supplier_name', 'supplier_address', 'customer_name',
                 'employee_name', 'employer_name', 'pay_period',
                 'iban', 'bic', 'account_holder', 'bank_name', 'balance',
                 'license_plate'];
        foreach ($keys as $k) {
            if (isset($pred[$k]['value'])) {
                $fields[$k] = [
                    'value'      => $pred[$k]['value'],
                    'confidence' => (float)($pred[$k]['confidence'] ?? 0),
                ];
            }
        }
        return $fields;
    }
}

if (!function_exists('fluxbox_mindee_classement_from_type')) {
    /**
     * Mapping type Mindee → classement GED (N1, N2, N3, N4).
     * Renvoie un classement partiel à merger avec celui de la cascade existante.
     */
    function fluxbox_mindee_classement_from_type(string $type): array
    {
        $map = [
            'invoice'         => ['n1'=>'06_COMPTABILITE',   'n2'=>'FOURNISSEURS',      'n3'=>'FACTURES_A_PAYER', 'n4'=>''],
            'payslip'         => ['n1'=>'02_RH',             'n2'=>'COLLABORATEURS',    'n3'=>'COLLABORATEUR',    'n4'=>'03_PAIE'],
            'bank_statement'  => ['n1'=>'06_COMPTABILITE',   'n2'=>'BANQUES',           'n3'=>'COMPTE_BANCAIRE',  'n4'=>'01_RELEVES'],
            'receipt'         => ['n1'=>'06_COMPTABILITE',   'n2'=>'FOURNISSEURS',      'n3'=>'FACTURES_A_PAYER', 'n4'=>''],
            'id_card'         => ['n1'=>'02_RH',             'n2'=>'COLLABORATEURS',    'n3'=>'COLLABORATEUR',    'n4'=>'01_IDENTITE'],
            'vehicle_registration' => ['n1'=>'01_DIRECTION', 'n2'=>'15_VEHICULES',      'n3'=>'VEHICULE',         'n4'=>''],
        ];
        return $map[$type] ?? [];
    }
}

if (!function_exists('fluxbox_mindee_poll_pending')) {
    /**
     * Rafraîchit les jobs Mindee en attente.
     * Appelé par un cron ou par un endpoint frontend qui rafraîchit la pile.
     */
    function fluxbox_mindee_poll_pending(int $limit = 20, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_pdo();
        $stats = ['polled'=>0, 'completed'=>0, 'still_pending'=>0, 'failed'=>0];

        $st = $pdo->prepare("
            SELECT id, source_meta
            FROM fluxbox_documents
            WHERE ocr_status = 'pending'
              AND JSON_EXTRACT(source_meta, '$.mindee_job_id') IS NOT NULL
            ORDER BY id DESC
            LIMIT ?
        ");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $r) {
            $meta = !empty($r['source_meta']) ? (json_decode((string)$r['source_meta'], true) ?: []) : [];
            $jobId = (string)($meta['mindee_job_id'] ?? '');
            if ($jobId === '') continue;
            $stats['polled']++;
            $poll = fluxbox_mindee_http_poll($jobId);
            if ($poll['status'] === 'completed') {
                fluxbox_mindee_apply_result((int)$r['id'], $poll['result'] ?? [], $pdo);
                $stats['completed']++;
            } elseif ($poll['status'] === 'failed') {
                // Marque échec pour ne pas re-polling à l'infini
                $meta['mindee_failed_at'] = date('Y-m-d H:i:s');
                $meta['mindee_error']     = $poll['error'] ?? 'unknown';
                unset($meta['mindee_job_id']);
                $pdo->prepare("UPDATE fluxbox_documents SET source_meta = ?, ocr_status = 'failed' WHERE id = ?")
                    ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
                $stats['failed']++;
            } else {
                $stats['still_pending']++;
            }
        }

        return $stats;
    }
}
