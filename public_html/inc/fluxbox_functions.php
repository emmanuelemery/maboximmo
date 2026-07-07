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

if (!function_exists('fluxbox_resolve_target_code')) {
    /**
     * Résout un code court pour le nom de fichier depuis un id société/agence cible.
     *
     * Source de vérité : glossaire `ged_codes_glossaire` (catégories societe/agence/user/...).
     * Si l'entité n'a pas encore d'entrée glossaire, autoSeed génère et persiste un code
     * dérivé du nom (verrouillable ensuite via /admin/admin_ged_glossaire.php).
     *
     * Fallbacks défensifs (uniquement si glossaire indisponible) :
     *   2. colonne `code` directe sur la table
     *   3. slug court du `nom`
     *   4. $defaultCode
     */
    function fluxbox_resolve_target_code(PDO $pdo, string $table, int $id, string $defaultCode = ''): string
    {
        if ($id <= 0 || !in_array($table, ['societes', 'agences'], true)) return $defaultCode;

        // 1. SOURCE OFFICIELLE : ged_codes_glossaire (codes courts maîtres figés par l'admin)
        //    Lookup DIRECT par entity_id, sans filtre tenant_id : les sociétés et agences
        //    sont des entités globalement uniques (pas multi-tenant au niveau code court).
        try {
            $category = ($table === 'societes') ? 'societe' : 'agence';
            $st = $pdo->prepare("
                SELECT code FROM ged_codes_glossaire
                WHERE category = ? AND entity_id = ? AND is_active = 1
                ORDER BY is_locked DESC, id ASC
                LIMIT 1
            ");
            $st->execute([$category, $id]);
            $code = (string)$st->fetchColumn();
            if ($code !== '') return $code;
        } catch (Throwable) {
            // Table glossaire absente / migration pas jouée → fallback ci-dessous
        }

        // 2. Code direct selon la table (societes a `code`?, agences a `code_agence`)
        $codeColumns = ($table === 'agences') ? ['code_agence', 'code'] : ['code'];
        $nameColumns = ($table === 'agences') ? ['nom_agence', 'nom'] : ['nom'];
        foreach ($codeColumns as $col) {
            try {
                $st = $pdo->prepare("SELECT `$col` AS c FROM `$table` WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $code = (string)$st->fetchColumn();
                if ($code !== '') return $code;
            } catch (Throwable) {}
        }
        // 3. Slug du nom
        foreach ($nameColumns as $col) {
            try {
                $st = $pdo->prepare("SELECT `$col` AS n FROM `$table` WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $nom = (string)$st->fetchColumn();
                if ($nom !== '') return fluxbox_slug_short($nom);
            } catch (Throwable) {}
        }

        return $defaultCode;
    }
}

if (!function_exists('fluxbox_glossary_code')) {
    /**
     * Récupère le code court officiel du glossaire (ged_codes_glossaire) pour une entité.
     * Lookup direct par (category, entity_id) sans filtre tenant (entités globalement uniques).
     *
     * @return string Code court (ex "REGE", "38-1", "SYNDIC", "2024") ou '' si absent.
     */
    function fluxbox_glossary_code(PDO $pdo, string $category, int $entityId): string
    {
        if ($entityId <= 0 || $category === '') return '';
        static $cache = [];
        $key = $category . ':' . $entityId;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $st = $pdo->prepare("
                SELECT code FROM ged_codes_glossaire
                WHERE category = ? AND entity_id = ? AND is_active = 1
                ORDER BY is_locked DESC, id ASC
                LIMIT 1
            ");
            $st->execute([$category, $entityId]);
            $code = (string)$st->fetchColumn();
            return $cache[$key] = $code;
        } catch (Throwable) {
            return $cache[$key] = '';
        }
    }
}

if (!function_exists('fluxbox_glossary_code_by_level')) {
    /**
     * Pour un code N1-N5 (ex "04_SYNDIC"), retourne le code court du glossaire (ex "SYNDIC").
     * Fait le lookup en 2 temps : ged_level_codes (code → id) → ged_codes_glossaire (entity_id → short code).
     * Fallback : si pas de code court dans le glossaire, retourne le code passé.
     */
    function fluxbox_glossary_code_by_level(PDO $pdo, int $level, string $code): string
    {
        if ($code === '' || $level < 1 || $level > 5) return $code;
        static $cache = [];
        $key = "L$level:$code";
        if (isset($cache[$key])) return $cache[$key];
        try {
            // 1. Trouve l'id du ged_level_codes correspondant
            $st = $pdo->prepare("SELECT id FROM ged_level_codes WHERE level_number = ? AND code = ? LIMIT 1");
            $st->execute([$level, $code]);
            $lcId = (int)$st->fetchColumn();
            if ($lcId <= 0) return $cache[$key] = $code;
            // 2. Cherche dans le glossaire (category = metier_n<level>)
            $cat = 'metier_n' . $level;
            $short = fluxbox_glossary_code($pdo, $cat, $lcId);
            return $cache[$key] = ($short !== '' ? $short : $code);
        } catch (Throwable) {
            return $cache[$key] = $code;
        }
    }
}

if (!function_exists('fluxbox_resolve_canonical_parts')) {
    /**
     * Résout TOUS les segments d'un nom canonique via le glossaire officiel.
     * - soc/age : ged_codes_glossaire (category=societe|agence)
     * - n1/n2  : ged_codes_glossaire (category=metier_n1|metier_n2) via ged_level_codes.id
     * - n3     : si placeholder entité (IMMEUBLE, BANQUE, etc.) → ged_codes_glossaire (category=immeuble|banque)
     *            sinon code N3 direct (level_codes)
     * - n4/n5  : codes N4/N5 directs (déjà courts dans ged_level_codes)
     *
     * @param array $classement  proposition.classement (n1, n2, n3, n4, n5, entity_instance, target_*_id, immeuble_id_bdd)
     * @param array $proposition proposition_json complète (peut avoir target_societe_id à la racine)
     * @param array $carteCtx    contexte carte (created_by, created_at) pour résoudre user + upload_date
     * @return array Segments prêts à être passés à ged_v3_preview / ged_v3_build_canonical_name
     */
    function fluxbox_resolve_canonical_parts(PDO $pdo, array $classement, array $proposition = [], array $carteCtx = []): array
    {
        // IDs métier (root du proposition ou dans classement)
        $socId = (int)($proposition['target_societe_id'] ?? $classement['target_societe_id'] ?? 0);
        $ageId = (int)($proposition['target_agence_id']  ?? $classement['target_agence_id']  ?? 0);

        $soc = $socId > 0 ? fluxbox_glossary_code($pdo, 'societe', $socId) : '';
        $age = $ageId > 0 ? fluxbox_glossary_code($pdo, 'agence',  $ageId) : '';

        $n1Code = (string)($classement['n1'] ?? '');
        $n2Code = (string)($classement['n2'] ?? '');
        $n3Code = (string)($classement['n3'] ?? '');

        $n1 = $n1Code !== '' ? fluxbox_glossary_code_by_level($pdo, 1, $n1Code) : '';
        $n2 = $n2Code !== '' ? fluxbox_glossary_code_by_level($pdo, 2, $n2Code) : '';

        // N3 : si placeholder entité → résolution via ged_codes_glossaire catégorie spécifique
        $n3 = $n3Code;
        if ($n3Code !== '') {
            try {
                $st = $pdo->prepare("SELECT 1 FROM ged_level_codes WHERE code = ? AND COALESCE(is_entity_placeholder, 0) = 1 LIMIT 1");
                $st->execute([$n3Code]);
                if ($st->fetchColumn()) {
                    // CAS SPÉCIAL VÉHICULE : le code véhicule est dans classement.vehicule_code (matché Haiku)
                    if ($n3Code === 'VEHICULE') {
                        $vehCode = trim((string)($classement['vehicule_code'] ?? ''));
                        if ($vehCode === '') $vehCode = trim((string)($classement['entity_instance'] ?? ''));
                        if ($vehCode !== '') $n3 = $vehCode;
                    } else {
                        // Mapping placeholder → catégorie glossaire (immeuble, banque, etc.)
                        $catMap = [
                            'IMMEUBLE'      => 'immeuble',
                            'BANQUE'        => 'banque',
                            'COLLABORATEUR' => 'user',
                            'FOURNISSEUR'   => 'fournisseur',
                            'SOCIETE'       => 'societe',
                            'BAILLEUR'      => 'bailleur',
                            'LOCATAIRE'     => 'locataire',
                        ];
                        $cat = $catMap[$n3Code] ?? null;
                        $entityId = (int)($classement['immeuble_id_bdd']
                                      ?? $classement['banque_id_bdd']
                                      ?? $classement['fournisseur_id_bdd']
                                      ?? 0);
                        if ($cat !== null && $entityId > 0) {
                            $short = fluxbox_glossary_code($pdo, $cat, $entityId);
                            if ($short !== '') $n3 = $short;
                        }
                        // Fallback : ref BDD ou instance détectée par Haiku
                        if ($n3 === $n3Code) {
                            $refBdd = trim((string)($classement['immeuble_ref_bdd'] ?? ''));
                            $entInst = trim((string)($classement['entity_instance'] ?? ''));
                            if ($refBdd !== '') $n3 = $refBdd;
                            elseif ($entInst !== '') $n3 = $entInst;
                        }
                    }
                }
            } catch (Throwable) {}
        }

        // user qui charge : carteCtx.created_by → glossaire category=user
        $userCode = '';
        $uploaderId = (int)($carteCtx['created_by'] ?? 0);
        if ($uploaderId > 0) {
            $userCode = fluxbox_glossary_code($pdo, 'user', $uploaderId);
            if ($userCode === '') {
                // Fallback : initiales prénom + nom depuis la table users
                try {
                    $st = $pdo->prepare("SELECT prenom, nom FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$uploaderId]);
                    if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                        $userCode = mb_substr((string)$u['prenom'], 0, 1) . (string)$u['nom'];
                    }
                } catch (Throwable) {}
            }
        }

        return [
            'soc'         => $soc,
            'age'         => $age,
            'user'        => $userCode,
            'n1'          => $n1,
            'n2'          => $n2,
            'n3'          => $n3,
            'n4'          => (string)($classement['n4'] ?? ''),
            'n5'          => (string)($classement['n5'] ?? ''),
            // n6 = SIGNE/NON_SIGNE uniquement (statut signature détecté par Haiku ou saisi par user)
            'n6'          => in_array(strtoupper((string)($classement['n6'] ?? '')), ['SIGNE', 'NON_SIGNE'], true)
                              ? strtoupper((string)$classement['n6'])
                              : '',
            'date'        => $classement['date'] ?? $proposition['target_date'] ?? null,
            'upload_date' => $carteCtx['created_at'] ?? null,
        ];
    }
}

if (!function_exists('fluxbox_slug_short')) {
    /**
     * Slug court (8 car max) ASCII upper pour les codes générés depuis un nom.
     * Ex : "Régie Emery" → "REGIE_EM"
     */
    function fluxbox_slug_short(string $s, int $maxLen = 12): string
    {
        if ($s === '') return '';
        // Translit manuelle (iconv sur Windows produit "'E" pour "É" → casse)
        static $accentMap = [
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
            'à'=>'A','á'=>'A','â'=>'A','ã'=>'A','ä'=>'A','å'=>'A','æ'=>'AE',
            'Ç'=>'C','ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'è'=>'E','é'=>'E','ê'=>'E','ë'=>'E',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','ì'=>'I','í'=>'I','î'=>'I','ï'=>'I',
            'Ñ'=>'N','ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Œ'=>'OE',
            'ò'=>'O','ó'=>'O','ô'=>'O','õ'=>'O','ö'=>'O','ø'=>'O','œ'=>'oe',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','ù'=>'U','ú'=>'U','û'=>'U','ü'=>'U',
            'Ý'=>'Y','ÿ'=>'Y','Ÿ'=>'Y','ý'=>'Y','ß'=>'SS','€'=>'E',
        ];
        $s = strtr($s, $accentMap);
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9_]+/', '_', $s) ?? '';
        $s = preg_replace('/_+/', '_', $s) ?? '';
        $s = trim($s, '_');
        return substr($s, 0, $maxLen);
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
            // Détecte si le doc a été précédemment supprimé par l'utilisateur (flag dans source_meta).
            // Dans ce cas, on RÉACTIVE l'enregistrement au lieu de bloquer comme doublon —
            // l'utilisateur veut explicitement reprendre le process sur ce fichier.
            $existingMeta = !empty($existing['source_meta'])
                ? (json_decode((string)$existing['source_meta'], true) ?: [])
                : [];
            $wasDeleted = !empty($existingMeta['deleted_at']);

            if ($wasDeleted) {
                // Nettoie les flags de suppression et merge avec les nouvelles source_meta de cet upload
                unset($existingMeta['deleted_at'], $existingMeta['deleted_by_user']);
                if (is_array($sourceMeta)) {
                    $existingMeta = array_merge($existingMeta, $sourceMeta);
                }
                $existingMeta['reactivated_at'] = date('Y-m-d H:i:s');

                // Met à jour le doc avec le nouveau chemin physique (l'ancien fichier a été unlink à la suppression)
                $pdo->prepare("
                    UPDATE `fluxbox_documents`
                    SET `fichier_chemin` = ?, `fichier_nom` = ?, `source_meta` = ?,
                        `source_type` = ?, `mime_type` = ?, `taille_octets` = ?,
                        `ocr_status` = 'pending', `seen_count` = 1, `last_seen_at` = NOW()
                    WHERE `id` = ?
                ")->execute([
                    $path, $nom,
                    json_encode($existingMeta, JSON_UNESCAPED_UNICODE),
                    $sourceType, $mime, $size,
                    (int)$existing['id'],
                ]);

                // Rafraîchit le row pour le retour
                $stR = $pdo->prepare("SELECT * FROM `fluxbox_documents` WHERE `id` = ?");
                $stR->execute([(int)$existing['id']]);
                $existing = $stR->fetch(PDO::FETCH_ASSOC) ?: $existing;

                return [
                    'id'             => (int)$existing['id'],
                    'is_duplicate'   => false,
                    'is_reactivated' => true,
                    'seen_count'     => 1,
                    'hash_sha256'    => $hash,
                    'document'       => $existing,
                ];
            }

            // Doublon classique : incrémente le compteur
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

        // V2 : propage entity_instance, user_label, ET soc/agence cibles dans le classement
        // pour que fluxbox_promote_to_ged puisse les utiliser dans le nom canonique.
        // Priorité : override modal Ajuster > proposition upload > vide.
        $classement['entity_instance'] = (string)(
            $overrides['entity_instance']
            ?? $proposition['entity_instance']
            ?? ''
        );
        $classement['user_label'] = (string)(
            $overrides['user_label']
            ?? $proposition['user_label']
            ?? ''
        );
        // IDs société/agence cibles (depuis le modal upload). Pas d'override possible côté
        // formulaire Ajuster (champs disabled), donc on lit directement la proposition.
        if (isset($proposition['target_societe_id'])) {
            $classement['target_societe_id'] = (int)$proposition['target_societe_id'];
        }
        if (array_key_exists('target_agence_id', $proposition)) {
            $classement['target_agence_id'] = (int)$proposition['target_agence_id'];
        }

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
                // ── CONVERGENCE GED : base fonctionnelle = MaBoxOffice ───────────────
                // Entité connue (bail/bien/immeuble/tiers) → on NOMME via le moteur MaBoxOffice
                // (entité + type glossaire), qui devient la base unique du nom GED. Fallback
                // sur le naming historique (VA) uniquement si MBO ne peut rien produire.
                $mboEntType = ''; $mboEntId = 0;
                if (!empty($proposition['bail_id']))         { $mboEntType='BAIL';  $mboEntId=(int)$proposition['bail_id']; }
                elseif (!empty($proposition['bien_id']))     { $mboEntType='BIEN';  $mboEntId=(int)$proposition['bien_id']; }
                elseif (!empty($proposition['immeuble_id'])) { $mboEntType='IMB';   $mboEntId=(int)$proposition['immeuble_id']; }
                elseif (!empty($proposition['tiers_id']))    { $mboEntType='TIERS'; $mboEntId=(int)$proposition['tiers_id']; }
                $mboType = strtoupper((string)(
                    $overrides['forced_type_doc']
                    ?? $proposition['forced_type_doc']
                    ?? $classement['type_doc']
                    ?? ''
                ));
                if ($mboEntType !== '' && $mboEntId > 0 && function_exists('fluxbox_mbo_ged_name')) {
                    $mboName = fluxbox_mbo_ged_name($pdo, (int)$carte['document_id'], $mboEntType, $mboEntId, $mboType, true);
                    if ($mboName !== '') $namingOverride = $mboName;
                }
                // Fallback historique (Variante A) si pas de nom MaBoxOffice.
                if ($namingOverride === null) {
                    $hasEntityInstance = trim((string)$classement['entity_instance']) !== '';
                    if (!$hasEntityInstance
                        && !empty($carte['naming_proposed'])
                        && in_array((string)($carte['naming_status'] ?? ''), ['ready','needs_review'], true)) {
                        $namingOverride = (string)$carte['naming_proposed'];
                    }
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

            // Fix B3 (2026-05-26) : trace audit pour validation manuelle utilisateur
            try {
                $pdo->prepare("
                    INSERT INTO `fluxbox_actions_ia`
                        (tenant_id, carte_id, action_type, action_label, payload_json, confiance, statut, created_at)
                    VALUES (?, ?, 'user_validation', ?, ?, 1.0, 'executed', NOW())
                ")->execute([
                    $tenantId, $carteId,
                    'Validation manuelle utilisateur (carte → ged_documents)',
                    json_encode([
                        'user_id' => $userId,
                        'ged_doc_id' => $gedDocId,
                        'actions_executed' => $executed,
                        'classement' => $classement,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Throwable) {}

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
            // Fix P2 (2026-05-26) : "pending" inclut aussi 'in_progress' (carte en cours d'ajustement)
            // pour cohérence avec ce qui est AFFICHÉ dans la pile.
            $st = $pdo->prepare("
                SELECT
                  SUM(CASE WHEN `statut` IN ('pending','in_progress') THEN 1 ELSE 0 END) AS pending,
                  SUM(CASE WHEN `statut` IN ('pending','in_progress') AND `priorite` = 'urgent' THEN 1 ELSE 0 END) AS urgent,
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

        // Résolution code société/agence : priorité aux target_*_id du modal, fallback ctx user
        $targetSocId = (int)($classement['target_societe_id'] ?? 0);
        $targetAgeId = (int)($classement['target_agence_id']  ?? 0);
        $socCode = $targetSocId > 0
            ? fluxbox_resolve_target_code($pdo, 'societes', $targetSocId, $ctx['societe_code'] ?: 'SOC')
            : ($ctx['societe_code'] ?: 'SOC');
        $ageCode = $targetAgeId > 0
            ? fluxbox_resolve_target_code($pdo, 'agences', $targetAgeId, $ctx['agence_code'] ?: 'AGE')
            : ($targetAgeId === 0 && array_key_exists('target_agence_id', $classement)
                ? 'SOC' // explicite "société uniquement" = pas d'agence dans le nom
                : ($ctx['agence_code'] ?: 'AGE'));

        // Compactage : on supprime les "_" et "-" internes des codes soc/age pour qu'ils
        // ne créent pas de faux segments dans le nom canonique (ex "42-1" → "421" et non "42_1").
        // Le glossaire reste lisible humain, c'est juste le nom final qui est nettoyé.
        $socCode = preg_replace('/[_\-\s]+/', '', $socCode) ?? $socCode;
        $ageCode = preg_replace('/[_\-\s]+/', '', $ageCode) ?? $ageCode;

        if ($namingOverride !== null && $namingOverride !== '') {
            $nameCanonical = $namingOverride;
        } else {
            // V2 : si N3 est un placeholder entité (COLLABORATEUR, IMMEUBLE, BANQUE…) et
            // qu'on a une entity_instance (ex "Dupont-Pierre"), on remplace la valeur du segment N3
            // dans le nom canonique par l'instance. Le classement.n3 logique reste à COLLABORATEUR
            // pour que la cascade et les requêtes par type fonctionnent toujours.
            $n3Code         = (string)($classement['n3'] ?? '');
            $entityInstance = trim((string)($classement['entity_instance'] ?? ''));
            $n3ForName      = $n3Code;
            if ($n3Code !== '' && $entityInstance !== '') {
                $stPh = $pdo->prepare("SELECT 1 FROM ged_level_codes WHERE code = ? AND COALESCE(is_entity_placeholder, 0) = 1 LIMIT 1");
                $stPh->execute([$n3Code]);
                if ($stPh->fetchColumn()) {
                    $n3ForName = $entityInstance;
                }
            }
            $nameParts = [
                'soc'  => $socCode,
                'age'  => $ageCode,
                'n1'   => (string)($classement['n1'] ?? ''),
                'n2'   => (string)($classement['n2'] ?? ''),
                'n3'   => $n3ForName,
                'n4'   => (string)($classement['n4'] ?? ''),
                'n5'   => (string)($classement['n5'] ?? ''),
                'n6'   => (string)($classement['n6'] ?? ''),
                'date' => $classement['date'] ?? null,
                'ext'  => pathinfo((string)$fluxDoc['fichier_nom'], PATHINFO_EXTENSION),
            ];
            $nameCanonical = ged_v3_build_canonical_name($nameParts);
        }
        // V2 : name_display priorité user_label > classement.name_display > nameCanonical
        $userLabelFinal = trim((string)($classement['user_label'] ?? ''));
        $nameDisplay = $userLabelFinal !== ''
            ? $userLabelFinal
            : ($classement['name_display'] ?? $nameCanonical);

        $uuid = ged_generate_uuid();
        $sourceModule = (string)($classement['n1'] ?? '');

        // Type de document (glossaire) : sans lui, le doc tombe en « Documents divers » et ne
        // coche aucune pièce de base (ex. « Bail signé »). On le reprend de la carte.
        $docTypeCode = strtolower(trim((string)($classement['type_doc'] ?? $classement['document_type'] ?? '')));
        if ($docTypeCode === '') {
            try {
                $stT = $pdo->prepare("SELECT proposition_json FROM fluxbox_cartes WHERE document_id = ? ORDER BY id DESC LIMIT 1");
                $stT->execute([$fluxboxDocId]);
                $pT = json_decode((string)$stT->fetchColumn(), true) ?: [];
                $docTypeCode = strtolower(trim((string)($pT['forced_type_doc'] ?? ($pT['classement']['type_doc'] ?? ''))));
            } catch (Throwable) {}
        }

        // ── ANTI-DOUBLON TRANSVERSAL PAR HASH ──────────────────────────────────
        // Le MÊME fichier peut déjà être en GED via un AUTRE chemin (MaBoxOffice /
        // gus_commit_document) : la garde `fluxbox_source_id` plus haut ne le voit pas.
        // On ne recrée JAMAIS de ligne pour un fichier identique → on réutilise la
        // ligne existante et on lui attache simplement les liens d'entité ci-dessous.
        $hashDoc  = (string)($fluxDoc['hash_sha256'] ?? '');
        $gedDocId = 0;
        if ($hashDoc !== '') {
            $stH = $pdo->prepare("SELECT id FROM ged_documents
                                   WHERE hash_sha256 = ? AND tenant_id = ?
                                     AND status IN ('active','archived')
                                   ORDER BY id ASC LIMIT 1");
            $stH->execute([$hashDoc, $tenantId]);
            $gedDocId = (int)$stH->fetchColumn();
            if ($gedDocId > 0) {
                // Rattache la source fluxbox si absente (on garde le nom déjà en base).
                $pdo->prepare("UPDATE ged_documents SET fluxbox_source_id = COALESCE(fluxbox_source_id, ?) WHERE id = ?")
                    ->execute([$fluxboxDocId, $gedDocId]);
            }
        }
        if ($gedDocId === 0) {
            $stIns = $pdo->prepare("
                INSERT INTO `ged_documents`
                  (`uuid`, `tenant_id`, `folder_id`, `societe_id`, `agence_id`,
                   `name_display`, `name_canonical`, `name_file`,
                   `document_type`, `source_module`, `storage_provider`, `mime_type`, `size_bytes`,
                   `hash_sha256`, `metadata`, `status`, `version`,
                   `fluxbox_source_id`, `created_by`)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'local', ?, ?, ?, ?, 'active', 1, ?, ?)
            ");
            $stIns->execute([
                $uuid, $tenantId,
                $ctx['societe_id'], $ctx['agence_id'],
                $nameDisplay, $nameCanonical, $nameCanonical,
                ($docTypeCode !== '' ? $docTypeCode : null),
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
            $gedDocId = (int)$pdo->lastInsertId();
        }

        // ── LIENS D'ENTITÉ (CRITIQUE) ──────────────────────────────────────────
        // Sans ça, un doc validé manuellement est ORPHELIN : invisible dans les fiches
        // 360 (bien/bail/immeuble) qui lisent via ged_document_links. On aligne le
        // classement manuel sur l'auto-commit : entité principale + références (immeuble…).
        try {
            $stC = $pdo->prepare("SELECT proposition_json FROM fluxbox_cartes WHERE document_id = ? ORDER BY id DESC LIMIT 1");
            $stC->execute([$fluxboxDocId]);
            $prop = json_decode((string)$stC->fetchColumn(), true) ?: [];

            $bailId  = (int)($prop['bail_id']     ?? $classement['bail_id']     ?? 0);
            $bienId  = (int)($prop['bien_id']     ?? $classement['bien_id']     ?? 0);
            $immId   = (int)($prop['immeuble_id'] ?? $classement['immeuble_id'] ?? 0);
            $tiersId = (int)($prop['tiers_id']    ?? $classement['tiers_id']    ?? 0);
            $creaId  = (int)($prop['creancier_dossier_id'] ?? 0);

            $stLink = $pdo->prepare("
                INSERT INTO ged_document_links
                    (tenant_id, document_id, entity_type, entity_id, relation_type, is_validated, validated_at, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE is_validated = 1, validated_at = NOW()
            ");

            if ($creaId > 0) {
                $stLink->execute([$tenantId, $gedDocId, 'CREANCIER_DOSSIER', $creaId, 'main']);
            } elseif ($bailId > 0) {
                // Bail → principal ; bien + immeuble en référence (le doc remonte sur le bien).
                $stLink->execute([$tenantId, $gedDocId, 'BAIL', $bailId, 'main']);
                $rb = $pdo->prepare("SELECT bb.id_bien, b.id_immeuble FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien WHERE bb.id = ?");
                $rb->execute([$bailId]); $r = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($r['id_bien']))     $stLink->execute([$tenantId, $gedDocId, 'BIEN', (int)$r['id_bien'], 'reference']);
                if (!empty($r['id_immeuble'])) $stLink->execute([$tenantId, $gedDocId, 'IMB', (int)$r['id_immeuble'], 'reference']);
            } elseif ($bienId > 0) {
                $ri = $pdo->prepare("SELECT id_immeuble, id_proprietaire FROM biens WHERE id = ?");
                $ri->execute([$bienId]); $rbi = $ri->fetch(PDO::FETCH_ASSOC) ?: [];
                $immFromBien = (int)($rbi['id_immeuble'] ?? 0);
                // Règle TAXE FONCIÈRE : une TF couvre TOUS les lots de l'immeuble. Si le
                // propriétaire a >1 bien dans cet immeuble → principal = IMMEUBLE (bien en réf).
                $typeDoc = strtolower((string)($prop['forced_type_doc'] ?? $classement['type_doc'] ?? ''));
                $isTF = ($typeDoc === 'taxe_fonciere')
                     || stripos((string)$nameCanonical, 'taxe') !== false
                     || stripos((string)($classement['n3'] ?? ''), 'taxe_fonciere') !== false;
                $multiLots = false;
                if ($isTF && $immFromBien > 0 && !empty($rbi['id_proprietaire'])) {
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE id_immeuble = ? AND id_proprietaire = ?");
                    $cnt->execute([$immFromBien, (int)$rbi['id_proprietaire']]);
                    $multiLots = ((int)$cnt->fetchColumn()) > 1;
                }
                if ($multiLots) {
                    // TF multi-lots → immeuble principal, bien en référence.
                    $stLink->execute([$tenantId, $gedDocId, 'IMB', $immFromBien, 'main']);
                    $stLink->execute([$tenantId, $gedDocId, 'BIEN', $bienId, 'reference']);
                } else {
                    // Cas standard → bien principal, immeuble en référence.
                    $stLink->execute([$tenantId, $gedDocId, 'BIEN', $bienId, 'main']);
                    if ($immFromBien > 0) $stLink->execute([$tenantId, $gedDocId, 'IMB', $immFromBien, 'reference']);
                }
                // Doc de TYPE BAIL chargé depuis le bien (sans bail_id explicite) → on le rattache
                // AUSSI au bail actif du bien, sinon il n'apparaît jamais dans « Documents du bail »
                // ni dans « Pièces bail » sur bail_360.
                $n4 = strtoupper((string)($classement['n4'] ?? ''));
                $isBailDoc = ($n4 === 'BAIL')
                          || (bool)preg_match('/^(bail|edl|etat_des_lieux|avenant|caution|acte_de_caution)/', $typeDoc);
                if ($isBailDoc) {
                    $ab = $pdo->prepare("SELECT id FROM bien_baux WHERE id_bien = ? AND statut IN ('actif','signe') ORDER BY id DESC LIMIT 1");
                    $ab->execute([$bienId]);
                    $activeBail = (int)$ab->fetchColumn();
                    // relation MAIN : c'est LE document du bail (bail signé, EDL…) → apparaît dans
                    // « Documents du bail » et coche « Pièces bail », pas seulement « Mentionné dans ».
                    if ($activeBail > 0) $stLink->execute([$tenantId, $gedDocId, 'BAIL', $activeBail, 'main']);
                }
            } elseif ($immId > 0) {
                $stLink->execute([$tenantId, $gedDocId, 'IMB', $immId, 'main']);
            } elseif ($tiersId > 0) {
                $stLink->execute([$tenantId, $gedDocId, 'TIERS', $tiersId, 'reference']);
            }
        } catch (Throwable $e) {
            error_log('[fluxbox_promote_to_ged] liens entité échoués doc#' . $gedDocId . ' : ' . $e->getMessage());
        }

        return $gedDocId;
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

if (!function_exists('fluxbox_resolve_agence_of_entity')) {
    /**
     * Agence de rattachement d'une entité (bien/immeuble/bail/propriétaire/salarié),
     * en cascade : bien → immeuble/propriétaire → agence. Logique UNIQUE reprise de
     * MaBoxOffice (mbo_agence_of_entity) pour un contexte société/agence FIABLE dans
     * TOUS les points d'entrée FluxBox (modale « Charger des documents » des fiches 360).
     */
    function fluxbox_resolve_agence_of_entity(PDO $pdo, string $type, int $id): int
    {
        if ($id <= 0) return 0;
        $type = strtoupper($type); $idBien = $idImm = $idProp = $idAgence = 0;
        if ($type === 'EMP')   { $st=$pdo->prepare("SELECT id_agence FROM users WHERE id=?"); $st->execute([$id]); return (int)$st->fetchColumn(); }
        if ($type === 'BAIL')  { $st=$pdo->prepare("SELECT id_bien FROM bien_baux WHERE id=?"); $st->execute([$id]); $idBien=(int)$st->fetchColumn(); }
        elseif ($type === 'BIEN')  $idBien = $id;
        elseif ($type === 'IMB')   $idImm  = $id;
        elseif ($type === 'PROP')  $idProp = $id;   // PROP = proprietaires.id (clé directe)
        elseif ($type === 'TIERS') {
            // TIERS = tiers.id (≠ proprietaires.id). On retrouve le rôle propriétaire via id_tiers,
            // sinon on prend l'agence portée directement par le tiers.
            $st=$pdo->prepare("SELECT id, id_agence FROM proprietaires WHERE id_tiers=? LIMIT 1"); $st->execute([$id]);
            if ($r=$st->fetch(PDO::FETCH_ASSOC)) { $idProp=(int)$r['id']; if (!empty($r['id_agence'])) $idAgence=(int)$r['id_agence']; }
            if (!$idAgence) { $st2=$pdo->prepare("SELECT id_agence FROM tiers WHERE id=?"); $st2->execute([$id]); $idAgence=(int)$st2->fetchColumn(); }
        }
        if ($idBien>0){ $st=$pdo->prepare("SELECT id_immeuble,id_proprietaire,id_agence FROM biens WHERE id=?"); $st->execute([$idBien]); if($r=$st->fetch(PDO::FETCH_ASSOC)){ $idImm=$idImm?:(int)$r['id_immeuble']; $idProp=$idProp?:(int)$r['id_proprietaire']; $idAgence=(int)$r['id_agence']; } }
        if (!$idAgence && $idImm>0){ $st=$pdo->prepare("SELECT id_agence FROM immeubles WHERE id=?"); $st->execute([$idImm]); $idAgence=(int)$st->fetchColumn(); }
        if (!$idAgence && $idProp>0){ $st=$pdo->prepare("SELECT id_agence FROM proprietaires WHERE id=?"); $st->execute([$idProp]); $idAgence=(int)$st->fetchColumn(); }
        return $idAgence;
    }
    /** Société de rattachement d'une entité, via son agence (fallback tenant fourni). */
    function fluxbox_resolve_societe_of_entity(PDO $pdo, string $type, int $id, int $fallbackTenant = 0): int
    {
        $age = fluxbox_resolve_agence_of_entity($pdo, $type, $id);
        if ($age > 0) { $st=$pdo->prepare("SELECT id_societe FROM agences WHERE id=?"); $st->execute([$age]); $s=(int)$st->fetchColumn(); if ($s) return $s; }
        return $fallbackTenant;
    }
}

if (!function_exists('fluxbox_mbo_ged_name')) {
    /**
     * CONVERGENCE FluxBox → MaBoxOffice : construit le NOM GED via le moteur
     * MaBoxOffice (entité + type) pour un document fluxbox donné, en contexte
     * « entité connue » (fiches 360). Renvoie '' si indisponible (→ l'appelant
     * garde alors le nommage catégorie/N1-N5 historique : zéro régression).
     *
     * @param string $entityType  BIEN | BAIL | IMB | TIERS | EMP (types MaBoxOffice)
     * @param string $mboType     code type (glossaire ged_document_types), ex. ETAT_LIEUX
     */
    function fluxbox_mbo_ged_name(PDO $pdo, int $fluxDocId, string $entityType, int $entityId, string $mboType, bool $ensure = false): string
    {
        $match = __DIR__ . '/maboxoffice_match.php';
        if ($fluxDocId <= 0 || $entityId <= 0 || !is_file($match)) return '';
        require_once $match;
        if (!function_exists('mbo_build_ged_name')) return '';
        $st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id = ?");
        $st->execute([$fluxDocId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return '';
        // On alimente les champs MBO attendus par le moteur (sans écraser la BDD ici).
        $d['mbo_entity_type']  = strtoupper($entityType);
        $d['mbo_entity_id']    = $entityId;
        $d['mbo_type_propose'] = strtolower($mboType);
        try { return mbo_build_ged_name($pdo, $d, $ensure); }
        catch (Throwable $e) { error_log('[fluxbox_mbo_ged_name] '.$e->getMessage()); return ''; }
    }
}
