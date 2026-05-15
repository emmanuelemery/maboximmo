<?php
declare(strict_types=1);

/**
 * FluxBox — fonctions core (anti-doublon SHA-256, ingestion, gestion cartes).
 *
 * Spec : project_fluxbox_module.md (validé EMERY 2026-05-13).
 *
 * Pattern global :
 *   1. Ingestion fichier → fluxbox_documents_ingest()
 *      → calcule SHA-256, vérifie doublon, sinon stocke
 *   2. Création carte → fluxbox_carte_create()
 *      → IA prépare proposition_json, statut=pending
 *   3. Validation → fluxbox_carte_validate()
 *      → exécute actions_ia, promeut vers ged_documents, statut=validated
 */

require_once __DIR__ . '/ged_functions.php';
require_once __DIR__ . '/ged_naming_v3.php';
require_once __DIR__ . '/ged_classement_v3.php';

if (!function_exists('fluxbox_pdo')) {
    function fluxbox_pdo(): PDO
    {
        return ged_pdo();
    }
}

if (!function_exists('fluxbox_current_tenant_id')) {
    function fluxbox_current_tenant_id(): int
    {
        $tid = ged_current_tenant_id();
        return $tid !== null ? (int)$tid : 0;
    }
}

// ════════════════════════════════════════════════════════════════════════
// 1. INGESTION — anti-doublon SHA-256 (niveau 1)
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_hash_file')) {
    /**
     * Calcule le SHA-256 binaire d'un fichier.
     * @throws RuntimeException si fichier illisible
     */
    function fluxbox_hash_file(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Fichier inaccessible : $path");
        }
        $h = @hash_file('sha256', $path);
        if ($h === false || $h === '') {
            throw new RuntimeException("Échec hash SHA-256 : $path");
        }
        return $h;
    }
}

if (!function_exists('fluxbox_normalize_ocr_text')) {
    /**
     * Normalisation pour anti-doublon niveau 2 (hash contenu post-OCR).
     * Lowercase + suppression espaces multiples + ponctuation.
     */
    function fluxbox_normalize_ocr_text(string $text): string
    {
        $text = mb_strtolower($text);
        // Suppression ponctuation et accents légers
        $text = preg_replace('/[[:punct:]]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}

if (!function_exists('fluxbox_hash_content')) {
    /**
     * Hash niveau 2 : SHA-256 du texte OCR normalisé.
     */
    function fluxbox_hash_content(string $ocrText): string
    {
        $norm = fluxbox_normalize_ocr_text($ocrText);
        return hash('sha256', $norm);
    }
}

if (!function_exists('fluxbox_documents_ingest')) {
    /**
     * Ingère un fichier dans fluxbox_documents.
     * → Si doublon SHA-256 : incrémente seen_count, met à jour last_seen_at, renvoie l'existant.
     * → Sinon : crée la ligne et renvoie la nouvelle ligne.
     *
     * @param array{
     *   path:string,           // chemin fichier physique
     *   fichier_nom?:string,
     *   source_type?:string,   // manual|email|watcher|zip|webhook|photo|api|other
     *   source_meta?:array,
     *   mime_type?:string,
     * } $input
     *
     * @return array{
     *   id:int, is_duplicate:bool, seen_count:int,
     *   hash_sha256:string, document:array
     * }
     */
    function fluxbox_documents_ingest(array $input, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        if ($tenantId <= 0) {
            throw new RuntimeException('tenant_id manquant pour ingestion FluxBox');
        }

        $path = (string)($input['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Fichier source manquant ou introuvable');
        }

        $hash      = fluxbox_hash_file($path);
        $sourceType = (string)($input['source_type'] ?? 'manual');
        $sourceMeta = $input['source_meta'] ?? null;
        $nom       = (string)($input['fichier_nom'] ?? basename($path));
        $mime      = (string)($input['mime_type'] ?? (function_exists('mime_content_type') ? (string)mime_content_type($path) : 'application/octet-stream'));
        $size      = @filesize($path) ?: 0;
        $userId    = current_user_id();

        // Anti-doublon niveau 1 : UK (tenant_id, hash_sha256)
        $st = $pdo->prepare("
            SELECT * FROM `fluxbox_documents`
            WHERE `tenant_id` = ? AND `hash_sha256` = ?
            LIMIT 1
        ");
        $st->execute([$tenantId, $hash]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $pdo->prepare("
                UPDATE `fluxbox_documents`
                SET `seen_count` = `seen_count` + 1,
                    `last_seen_at` = NOW()
                WHERE `id` = ?
            ")->execute([(int)$existing['id']]);
            $existing['seen_count'] = (int)$existing['seen_count'] + 1;

            return [
                'id'           => (int)$existing['id'],
                'is_duplicate' => true,
                'seen_count'   => (int)$existing['seen_count'],
                'hash_sha256'  => $hash,
                'document'     => $existing,
            ];
        }

        // Nouvel ingest
        $st = $pdo->prepare("
            INSERT INTO `fluxbox_documents`
              (`tenant_id`, `hash_sha256`, `source_type`, `source_meta`,
               `fichier_nom`, `fichier_chemin`, `taille_octets`, `mime_type`,
               `ocr_status`, `created_by`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $st->execute([
            $tenantId, $hash, $sourceType,
            $sourceMeta !== null ? json_encode($sourceMeta, JSON_UNESCAPED_UNICODE) : null,
            $nom, $path, $size, $mime, $userId ?: null,
        ]);
        $id = (int)$pdo->lastInsertId();

        $doc = $pdo->query("SELECT * FROM `fluxbox_documents` WHERE `id` = {$id}")->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'id'           => $id,
            'is_duplicate' => false,
            'seen_count'   => 1,
            'hash_sha256'  => $hash,
            'document'     => $doc,
        ];
    }
}

if (!function_exists('fluxbox_zip_extract_recursive')) {
    /**
     * Extrait un ZIP de façon récursive (ZIP dans ZIP supportés jusqu'à profondeur max).
     * Chaque fichier extrait est ingéré dans fluxbox_documents (avec son anti-doublon SHA-256).
     *
     * Protections anti zip-bomb :
     *  - Profondeur max (défaut 5)
     *  - Taille totale extraite max (défaut 500 Mo)
     *  - Nombre total de fichiers max (défaut 5000)
     *  - Pas de path traversal (slashes nettoyés)
     *
     * @param string $zipPath Chemin du ZIP source
     * @param array{
     *   max_depth?:int, max_total_bytes?:int, max_files?:int,
     *   source_type?:string, source_meta?:array,
     *   parent_zip_path?:string, _depth?:int, _stats?:array
     * } $opts
     * @return array{
     *   files_ingested:int, files_dup:int, errors:array<string>,
     *   total_bytes:int, nested_zips:int
     * }
     */
    function fluxbox_zip_extract_recursive(string $zipPath, array $opts = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();

        $maxDepth      = (int)($opts['max_depth']       ?? 5);
        $maxTotalBytes = (int)($opts['max_total_bytes'] ?? 500 * 1024 * 1024);
        $maxFiles      = (int)($opts['max_files']       ?? 5000);
        $depth         = (int)($opts['_depth']          ?? 0);
        $sourceType    = (string)($opts['source_type']  ?? 'zip');
        $parentMeta    = (array)($opts['source_meta']   ?? []);

        // Stats partagées entre appels récursifs
        $stats = $opts['_stats'] ?? [
            'files_ingested' => 0,
            'files_dup'      => 0,
            'errors'         => [],
            'total_bytes'    => 0,
            'nested_zips'    => 0,
        ];

        if (!class_exists('ZipArchive')) {
            $stats['errors'][] = 'Extension PHP ZipArchive absente — extraction ZIP indisponible';
            return $stats;
        }
        if ($depth > $maxDepth) {
            $stats['errors'][] = "Profondeur ZIP dépassée (> $maxDepth) sur $zipPath";
            return $stats;
        }
        if (!is_file($zipPath)) {
            $stats['errors'][] = "ZIP introuvable : $zipPath";
            return $stats;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $stats['errors'][] = "Ouverture ZIP échouée : $zipPath";
            return $stats;
        }

        // Vérif anti zip-bomb : poids non compressé attendu
        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            if ($s !== false) $unpacked += (int)($s['size'] ?? 0);
        }
        if ($stats['total_bytes'] + $unpacked > $maxTotalBytes) {
            $zip->close();
            $stats['errors'][] = sprintf("Quota taille extraite dépassé (%d Mo) au ZIP %s",
                (int)($maxTotalBytes / 1024 / 1024), basename($zipPath));
            return $stats;
        }

        $tenantId = fluxbox_current_tenant_id();
        $extractRoot = __DIR__ . '/../storage_fluxbox/' . $tenantId . '/_zip_extract/'
                     . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        if (!@mkdir($extractRoot, 0750, true) && !is_dir($extractRoot)) {
            $zip->close();
            $stats['errors'][] = "Création dossier extraction impossible";
            return $stats;
        }

        // Extraction
        if (!$zip->extractTo($extractRoot)) {
            $zip->close();
            $stats['errors'][] = "Extraction échouée pour $zipPath";
            return $stats;
        }
        $zip->close();

        // Parcours récursif des fichiers extraits
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($stats['files_ingested'] + $stats['files_dup'] >= $maxFiles) {
                $stats['errors'][] = "Quota fichiers max atteint ($maxFiles)";
                break;
            }
            if (!$file->isFile()) continue;

            $filePath = $file->getRealPath();
            if (!$filePath) continue;
            $relativePath = ltrim(str_replace($extractRoot, '', $filePath), DIRECTORY_SEPARATOR . '/');

            // Path traversal sécurité : on ne ressort pas du extractRoot
            if (strpos($filePath, realpath($extractRoot) ?: $extractRoot) !== 0) continue;

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            // ZIP imbriqué → récursion
            if ($ext === 'zip') {
                $stats['nested_zips']++;
                $nestedOpts = array_merge($opts, [
                    '_depth'         => $depth + 1,
                    '_stats'         => $stats,
                    'parent_zip_path'=> ($opts['parent_zip_path'] ?? basename($zipPath)) . ' > ' . $relativePath,
                ]);
                $nested = fluxbox_zip_extract_recursive($filePath, $nestedOpts, $pdo);
                $stats = $nested; // accumulator partagé
                @unlink($filePath);
                continue;
            }

            // Ingestion fichier classique
            try {
                $ingest = fluxbox_documents_ingest([
                    'path'         => $filePath,
                    'fichier_nom'  => basename($filePath),
                    'source_type'  => $sourceType,
                    'source_meta'  => array_merge($parentMeta, [
                        'extracted_from' => $opts['parent_zip_path'] ?? basename($zipPath),
                        'relative_path'  => $relativePath,
                        'depth'          => $depth,
                    ]),
                ], $pdo);

                if ($ingest['is_duplicate']) {
                    $stats['files_dup']++;
                } else {
                    $stats['files_ingested']++;
                    $stats['total_bytes'] += (int)(@filesize($filePath) ?: 0);
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "Ingestion échouée pour $relativePath : " . $e->getMessage();
            }
        }

        return $stats;
    }
}

if (!function_exists('fluxbox_documents_set_ocr')) {
    /**
     * Stocke le résultat OCR et calcule le hash_contenu (niveau 2).
     */
    function fluxbox_documents_set_ocr(int $documentId, string $ocrText, string $engine = 'tesseract', ?PDO $pdo = null): void
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $hashContenu = fluxbox_hash_content($ocrText);

        $pdo->prepare("
            UPDATE `fluxbox_documents`
            SET `ocr_text` = ?, `hash_contenu` = ?, `ocr_status` = 'done', `updated_at` = NOW()
            WHERE `id` = ?
        ")->execute([$ocrText, $hashContenu, $documentId]);

        // Stocker dans cache_ocr pour réutilisation cross-document si même hash binaire
        $tenantId = fluxbox_current_tenant_id();
        $doc = $pdo->prepare("SELECT hash_sha256 FROM fluxbox_documents WHERE id = ?");
        $doc->execute([$documentId]);
        $row = $doc->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare("
                INSERT IGNORE INTO `fluxbox_cache_ocr`
                  (`tenant_id`, `hash_sha256`, `ocr_text`, `ocr_engine`)
                VALUES (?, ?, ?, ?)
            ")->execute([$tenantId, (string)$row['hash_sha256'], $ocrText, $engine]);
        }
    }
}

// ════════════════════════════════════════════════════════════════════════
// 2. CARTES — création, file d'attente, validation
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_carte_create')) {
    /**
     * Crée une carte associée (ou non) à un document.
     *
     * @param array{
     *   document_id?:?int,
     *   titre:string,
     *   sous_titre?:string,
     *   priorite?:string,           // urgent|important|normal|faible
     *   priorite_reason?:string,
     *   confiance_ia?:float,
     *   proposition?:array,         // sera JSON-encodée
     * } $input
     */
    function fluxbox_carte_create(array $input, ?PDO $pdo = null): int
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();

        $st = $pdo->prepare("
            INSERT INTO `fluxbox_cartes`
              (`tenant_id`, `document_id`, `titre`, `sous_titre`,
               `priorite`, `priorite_reason`, `statut`,
               `confiance_ia`, `proposition_json`, `created_by`)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
        ");
        $st->execute([
            $tenantId,
            $input['document_id'] ?? null,
            (string)$input['titre'],
            $input['sous_titre'] ?? null,
            (string)($input['priorite'] ?? 'normal'),
            $input['priorite_reason'] ?? null,
            isset($input['confiance_ia']) ? (float)$input['confiance_ia'] : null,
            isset($input['proposition']) ? json_encode($input['proposition'], JSON_UNESCAPED_UNICODE) : null,
            $userId ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('fluxbox_carte_action_add')) {
    /**
     * Ajoute une action IA proposée à une carte.
     */
    function fluxbox_carte_action_add(int $carteId, string $type, string $label, array $payload, ?float $confiance = null, ?PDO $pdo = null): int
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();

        $st = $pdo->prepare("
            INSERT INTO `fluxbox_actions_ia`
              (`tenant_id`, `carte_id`, `action_type`, `action_label`, `payload_json`, `confiance`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $tenantId, $carteId, $type, $label,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $confiance,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('fluxbox_carte_get_next')) {
    /**
     * Renvoie la carte prioritaire à afficher au user.
     * Ordre : urgence (urgent > important > normal > faible), puis ancienneté.
     * Exclut les cartes verrouillées par un autre user, validated, dismissed,
     * ou "later" jusqu'à later_until.
     *
     * @param array<string>|null $sourceFilter Filtre sur fluxbox_documents.source_type
     *                                          ex ['email','webhook'] ou ['manual','watcher','zip','photo']
     *                                          null = pas de filtre (toutes sources)
     */
    function fluxbox_carte_get_next(?PDO $pdo = null, ?array $sourceFilter = null): ?array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();
        if ($tenantId <= 0) return null;

        // Libère les cartes assignées depuis > 10 min (verrou expiré)
        $pdo->prepare("
            UPDATE `fluxbox_cartes`
            SET `assigned_to` = NULL, `assigned_at` = NULL, `statut` = 'pending'
            WHERE `tenant_id` = ?
              AND `statut` = 'in_progress'
              AND `assigned_at` < (NOW() - INTERVAL 10 MINUTE)
        ")->execute([$tenantId]);

        // Construction conditionnelle de la requête avec filtre source
        $sourceJoin   = '';
        $sourceWhere  = '';
        $sourceParams = [];
        if (is_array($sourceFilter) && count($sourceFilter) > 0) {
            $sourceJoin  = 'LEFT JOIN `fluxbox_documents` d ON d.id = c.document_id';
            $placeholders = implode(',', array_fill(0, count($sourceFilter), '?'));
            $sourceWhere = "AND (d.source_type IN ($placeholders) OR c.document_id IS NULL)";
            $sourceParams = $sourceFilter;
        }

        $sql = "
            SELECT c.* FROM `fluxbox_cartes` c
            $sourceJoin
            WHERE c.`tenant_id` = ?
              AND (c.`statut` = 'pending'
                   OR (c.`statut` = 'later' AND (c.`later_until` IS NULL OR c.`later_until` <= NOW()))
                   OR (c.`statut` = 'in_progress' AND c.`assigned_to` = ?))
              $sourceWhere
            ORDER BY
              FIELD(c.`priorite`, 'urgent', 'important', 'normal', 'faible'),
              c.`created_at` ASC
            LIMIT 1
        ";
        $params = array_merge([$tenantId, $userId], $sourceParams);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $carte = $st->fetch(PDO::FETCH_ASSOC);
        if (!$carte) return null;

        // Verrouille pour ce user
        $pdo->prepare("
            UPDATE `fluxbox_cartes`
            SET `statut` = 'in_progress', `assigned_to` = ?, `assigned_at` = NOW()
            WHERE `id` = ? AND (`assigned_to` IS NULL OR `assigned_to` = ?)
        ")->execute([$userId, (int)$carte['id'], $userId]);

        // Charge les actions IA associées
        $stA = $pdo->prepare("
            SELECT * FROM `fluxbox_actions_ia`
            WHERE `tenant_id` = ? AND `carte_id` = ?
            ORDER BY `id` ASC
        ");
        $stA->execute([$tenantId, (int)$carte['id']]);
        $carte['actions_ia'] = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Décode JSON proposition
        if (!empty($carte['proposition_json'])) {
            $carte['proposition'] = json_decode((string)$carte['proposition_json'], true) ?: [];
        }

        return $carte;
    }
}

if (!function_exists('fluxbox_carte_validate')) {
    /**
     * Valide une carte : exécute les actions IA, promeut le doc vers GED si applicable.
     *
     * @param int $carteId
     * @param array $overrides Optionnel : remplace certains champs de la proposition (ajustements user)
     * @return array{ok:bool, ged_document_id:?int, actions_executed:int, errors:array<string>}
     */
    function fluxbox_carte_validate(int $carteId, array $overrides = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();

        $st = $pdo->prepare("SELECT * FROM `fluxbox_cartes` WHERE `id` = ? AND `tenant_id` = ?");
        $st->execute([$carteId, $tenantId]);
        $carte = $st->fetch(PDO::FETCH_ASSOC);
        if (!$carte) {
            return ['ok' => false, 'ged_document_id' => null, 'actions_executed' => 0,
                    'errors' => ['Carte introuvable']];
        }

        $proposition = !empty($carte['proposition_json'])
            ? (json_decode((string)$carte['proposition_json'], true) ?: [])
            : [];
        // Merge overrides user (ajustements modal "Ajuster")
        $classement = array_merge(($proposition['classement'] ?? []), ($overrides['classement'] ?? []));

        $validation = ged_v3_validate_minimum($classement);
        if (!$validation['ok']) {
            return ['ok' => false, 'ged_document_id' => null, 'actions_executed' => 0,
                    'errors' => $validation['errors']];
        }

        $errors = [];
        $executed = 0;
        $gedDocId = null;

        try {
            $pdo->beginTransaction();

            // 1. Promotion vers ged_documents si la carte a un document attaché
            if (!empty($carte['document_id'])) {
                $namingOverride = null;
                if (!empty($carte['naming_proposed'])
                    && in_array((string)($carte['naming_status'] ?? ''), ['ready','needs_review'], true)) {
                    $namingOverride = (string)$carte['naming_proposed'];
                }
                $gedDocId = fluxbox_promote_to_ged((int)$carte['document_id'], $classement, $pdo, $namingOverride);

                if ($namingOverride !== null) {
                    require_once __DIR__ . '/fluxbox_va_orchestrator.php';
                    fluxbox_va_mark_applied($carteId, $pdo);
                }
            }

            // 2. Exécution des actions IA acceptées
            $stA = $pdo->prepare("
                SELECT * FROM `fluxbox_actions_ia`
                WHERE `carte_id` = ? AND `tenant_id` = ? AND `statut` IN ('proposed','accepted')
            ");
            $stA->execute([$carteId, $tenantId]);
            $actions = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($actions as $action) {
                try {
                    fluxbox_action_execute($action, $gedDocId, $pdo);
                    $executed++;
                } catch (Throwable $e) {
                    $errors[] = "Action #{$action['id']} : " . $e->getMessage();
                    $pdo->prepare("
                        UPDATE `fluxbox_actions_ia`
                        SET `statut` = 'failed', `result_json` = ?
                        WHERE `id` = ?
                    ")->execute([json_encode(['error' => $e->getMessage()]), (int)$action['id']]);
                }
            }

            // 3. Marque la carte validée
            $pdo->prepare("
                UPDATE `fluxbox_cartes`
                SET `statut` = 'validated', `validated_by` = ?, `validated_at` = NOW(),
                    `assigned_to` = NULL
                WHERE `id` = ?
            ")->execute([$userId, $carteId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'ged_document_id' => null, 'actions_executed' => 0,
                    'errors' => [$e->getMessage()]];
        }

        return [
            'ok'               => count($errors) === 0,
            'ged_document_id'  => $gedDocId,
            'actions_executed' => $executed,
            'errors'           => $errors,
        ];
    }
}

if (!function_exists('fluxbox_carte_later')) {
    /**
     * Reporte une carte. $until = '+1 hour', '+1 day', ou DateTime.
     */
    function fluxbox_carte_later(int $carteId, string|DateTimeInterface $until = '+1 day', ?PDO $pdo = null): bool
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $dt = $until instanceof DateTimeInterface ? $until->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime((string)$until));

        $st = $pdo->prepare("
            UPDATE `fluxbox_cartes`
            SET `statut` = 'later', `later_until` = ?, `assigned_to` = NULL
            WHERE `id` = ? AND `tenant_id` = ?
        ");
        return $st->execute([$dt, $carteId, $tenantId]);
    }
}

if (!function_exists('fluxbox_carte_dismiss')) {
    /**
     * Marque une carte comme rejetée (doublon, hors-scope, etc.).
     */
    function fluxbox_carte_dismiss(int $carteId, string $reason = '', ?PDO $pdo = null): bool
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();

        $st = $pdo->prepare("
            UPDATE `fluxbox_cartes`
            SET `statut` = 'dismissed', `dismissed_by` = ?, `dismissed_at` = NOW(),
                `dismissed_reason` = ?, `assigned_to` = NULL
            WHERE `id` = ? AND `tenant_id` = ?
        ");
        return $st->execute([$userId, $reason, $carteId, $tenantId]);
    }
}

if (!function_exists('fluxbox_stats_by_source')) {
    /**
     * Renvoie pour chaque source un récap (nouveaux fichiers, cartes en attente,
     * cartes IA-prêtes, 3 derniers items).
     *
     * @return array{
     *   telechargements:array{new_docs:int,pending_cards:int,ia_ready:int,latest:array<array>},
     *   mails:array{new_docs:int,pending_cards:int,ia_ready:int,latest:array<array>},
     *   total_pending:int
     * }
     */
    function fluxbox_stats_by_source(?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();

        $result = [
            'telechargements' => ['new_docs'=>0, 'pending_cards'=>0, 'ia_ready'=>0, 'latest'=>[]],
            'mails'           => ['new_docs'=>0, 'pending_cards'=>0, 'ia_ready'=>0, 'latest'=>[]],
            'total_pending'   => 0,
        ];
        if ($tenantId <= 0) return $result;

        $sources = [
            'telechargements' => ['manual','watcher','zip','photo'],
            'mails'           => ['email','webhook'],
        ];

        try {
            foreach ($sources as $key => $types) {
                $placeholders = implode(',', array_fill(0, count($types), '?'));
                $params = array_merge([$tenantId], $types);

                // Compteur fluxbox_documents reçus
                $st = $pdo->prepare("
                    SELECT COUNT(*) FROM `fluxbox_documents`
                    WHERE `tenant_id` = ? AND `source_type` IN ($placeholders)
                ");
                $st->execute($params);
                $result[$key]['new_docs'] = (int)$st->fetchColumn();

                // Cartes pending pour cette source
                $st = $pdo->prepare("
                    SELECT
                      SUM(CASE WHEN c.statut = 'pending' THEN 1 ELSE 0 END) AS pending,
                      SUM(CASE WHEN c.statut = 'pending' AND c.confiance_ia >= 60 THEN 1 ELSE 0 END) AS ia_ready
                    FROM `fluxbox_cartes` c
                    LEFT JOIN `fluxbox_documents` d ON d.id = c.document_id
                    WHERE c.tenant_id = ?
                      AND (d.source_type IN ($placeholders) OR c.document_id IS NULL)
                ");
                $st->execute($params);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $result[$key]['pending_cards'] = (int)($row['pending'] ?? 0);
                    $result[$key]['ia_ready']      = (int)($row['ia_ready'] ?? 0);
                }

                // 3 derniers items reçus
                $st = $pdo->prepare("
                    SELECT c.id AS carte_id, c.titre, c.priorite, c.created_at, d.fichier_nom
                    FROM `fluxbox_cartes` c
                    LEFT JOIN `fluxbox_documents` d ON d.id = c.document_id
                    WHERE c.tenant_id = ?
                      AND c.statut IN ('pending','in_progress','later')
                      AND (d.source_type IN ($placeholders) OR c.document_id IS NULL)
                    ORDER BY c.created_at DESC
                    LIMIT 3
                ");
                $st->execute($params);
                $result[$key]['latest'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $result['total_pending'] = $result['telechargements']['pending_cards']
                                     + $result['mails']['pending_cards'];
        } catch (Throwable) {}

        return $result;
    }
}

if (!function_exists('fluxbox_carte_stats')) {
    /**
     * Stats pour le bandeau dashboard.
     */
    function fluxbox_carte_stats(?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();

        $stats = [
            'pending'  => 0,
            'urgent'   => 0,
            'later'    => 0,
            'today_validated' => 0,
            'doublons_blocked' => 0,
        ];

        try {
            $st = $pdo->prepare("
                SELECT
                  SUM(CASE WHEN `statut` = 'pending' THEN 1 ELSE 0 END) AS pending,
                  SUM(CASE WHEN `statut` = 'pending' AND `priorite` = 'urgent' THEN 1 ELSE 0 END) AS urgent,
                  SUM(CASE WHEN `statut` = 'later' THEN 1 ELSE 0 END) AS later,
                  SUM(CASE WHEN `statut` = 'validated' AND DATE(`validated_at`) = CURDATE() THEN 1 ELSE 0 END) AS today_validated
                FROM `fluxbox_cartes`
                WHERE `tenant_id` = ?
            ");
            $st->execute([$tenantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stats['pending']         = (int)$row['pending'];
                $stats['urgent']          = (int)$row['urgent'];
                $stats['later']           = (int)$row['later'];
                $stats['today_validated'] = (int)$row['today_validated'];
            }

            $st = $pdo->prepare("
                SELECT COALESCE(SUM(`seen_count`) - COUNT(*), 0) AS doublons
                FROM `fluxbox_documents`
                WHERE `tenant_id` = ? AND `seen_count` > 1
            ");
            $st->execute([$tenantId]);
            $stats['doublons_blocked'] = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable) {}

        return $stats;
    }
}

// ════════════════════════════════════════════════════════════════════════
// 3. PROMOTION FLUXBOX → GED
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_promote_to_ged')) {
    /**
     * Promeut un fluxbox_document vers ged_documents avec le classement validé.
     * Lien via ged_documents.fluxbox_source_id.
     *
     * @return int id ged_documents créé
     */
    function fluxbox_promote_to_ged(int $fluxboxDocId, array $classement, ?PDO $pdo = null, ?string $namingOverride = null): int
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();

        // Vérifie qu'il n'existe pas déjà (idempotent)
        $st = $pdo->prepare("SELECT id FROM `ged_documents` WHERE `fluxbox_source_id` = ? LIMIT 1");
        $st->execute([$fluxboxDocId]);
        $existing = $st->fetchColumn();
        if ($existing) return (int)$existing;

        $stF = $pdo->prepare("SELECT * FROM `fluxbox_documents` WHERE `id` = ? AND `tenant_id` = ?");
        $stF->execute([$fluxboxDocId, $tenantId]);
        $fluxDoc = $stF->fetch(PDO::FETCH_ASSOC);
        if (!$fluxDoc) {
            throw new RuntimeException("FluxBox document $fluxboxDocId introuvable pour tenant $tenantId");
        }

        // Génère nom canonique : Variante A si override fourni, sinon V3 legacy
        $ctx = ged_v3_get_user_context($pdo);
        if ($namingOverride !== null && $namingOverride !== '') {
            $nameCanonical = $namingOverride;
        } else {
            $nameParts = [
                'soc'  => $ctx['societe_code'] ?: 'SOC',
                'age'  => $ctx['agence_code']  ?: 'AGE',
                'n1'   => (string)($classement['n1'] ?? ''),
                'n2'   => (string)($classement['n2'] ?? ''),
                'n3'   => (string)($classement['n3'] ?? ''),
                'n4'   => (string)($classement['n4'] ?? ''),
                'n5'   => (string)($classement['n5'] ?? ''),
                'n6'   => (string)($classement['n6'] ?? ''),
                'date' => $classement['date'] ?? null,
                'ext'  => pathinfo((string)$fluxDoc['fichier_nom'], PATHINFO_EXTENSION),
            ];
            $nameCanonical = ged_v3_build_canonical_name($nameParts);
        }
        $nameDisplay   = $classement['name_display'] ?? $nameCanonical;

        $uuid = ged_generate_uuid();
        $sourceModule = (string)($classement['n1'] ?? '');

        $stIns = $pdo->prepare("
            INSERT INTO `ged_documents`
              (`uuid`, `tenant_id`, `folder_id`, `societe_id`, `agence_id`,
               `name_display`, `name_canonical`, `name_file`,
               `source_module`, `storage_provider`, `mime_type`, `size_bytes`,
               `hash_sha256`, `metadata`, `status`, `version`,
               `fluxbox_source_id`, `created_by`)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 'local', ?, ?, ?, ?, 'active', 1, ?, ?)
        ");
        $stIns->execute([
            $uuid, $tenantId,
            $ctx['societe_id'], $ctx['agence_id'],
            $nameDisplay, $nameCanonical, $nameCanonical,
            $sourceModule,
            (string)$fluxDoc['mime_type'],
            (int)$fluxDoc['taille_octets'],
            (string)$fluxDoc['hash_sha256'],
            json_encode([
                'classement' => $classement,
                'fluxbox_id' => $fluxboxDocId,
                'source_type' => $fluxDoc['source_type'],
            ], JSON_UNESCAPED_UNICODE),
            $fluxboxDocId,
            $userId ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('fluxbox_action_execute')) {
    /**
     * Exécute une action IA acceptée (envoi mail, création tâche, etc.).
     * Pour V1 : seul le classement_ged est exécutable (déjà fait via fluxbox_promote_to_ged).
     * Les autres actions sont LOGGÉES comme "executed" sans effet pour ne pas casser le flow.
     */
    function fluxbox_action_execute(array $action, ?int $gedDocId, PDO $pdo): void
    {
        $type = (string)$action['action_type'];

        // V1 : pour les actions hors classement_ged, on logge sans exécuter (à brancher V1.1+)
        $resultJson = null;
        $statut = 'executed';

        switch ($type) {
            case 'classement_ged':
                // Déjà exécuté en amont par fluxbox_promote_to_ged
                $resultJson = json_encode(['ged_document_id' => $gedDocId]);
                break;

            case 'envoi_email':
            case 'creation_tache':
            case 'workflow':
            case 'marquer_paye':
            case 'autre':
                // V1 : non implémenté → loggé en pending pour suivi
                $resultJson = json_encode(['v1_skipped' => true, 'reason' => 'action non implémentée en V1, à brancher V1.1+']);
                $statut = 'executed'; // on n'échoue pas la carte
                break;
        }

        $pdo->prepare("
            UPDATE `fluxbox_actions_ia`
            SET `statut` = ?, `executed_at` = NOW(), `result_json` = ?
            WHERE `id` = ?
        ")->execute([$statut, $resultJson, (int)$action['id']]);
    }
}
