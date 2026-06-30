<?php
declare(strict_types=1);

/**
 * GED — Glossaire centralisé des codes courts utilisés dans les noms de fichiers.
 *
 * Toutes les catégories (societe, agence, user, banque, immeuble, type_document, ...)
 * passent par ce helper. La dérivation auto n'est faite QUE si le code n'existe
 * pas encore dans le glossaire, et le résultat est persisté pour stabilité.
 *
 * Spec : validé EMERY 2026-05-15.
 *
 * Phase 1 : lecture / création / résolution / validation / dérivation
 * Phase 2 : seed initial (via admin/admin_ged_glossaire.php)
 * Phase 3 : intégration dans ged_v3_build_canonical_name() (à venir)
 *
 * Aucune dépendance vers le nommage de fichier dans cette phase — les codes
 * sont juste créés et consultables, mais pas encore utilisés.
 */

require_once __DIR__ . '/ged_functions.php';

// ════════════════════════════════════════════════════════════════════════
// CONFIG : catégories supportées + contraintes
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('ged_glossary_categories')) {
    /**
     * Retourne la config des catégories supportées.
     * @return array<string, array{label:string, max_len:int, source_table:?string, source_id_col:?string, source_label_col:?string}>
     */
    function ged_glossary_categories(): array
    {
        return [
            'societe' => [
                'label'           => 'Société',
                'max_len'         => 4,
                'source_table'    => 'societes',
                'source_id_col'   => 'id',
                'source_label_col'=> 'nom',
            ],
            'agence' => [
                'label'           => 'Agence',
                'max_len'         => 6,
                'source_table'    => 'agences',
                'source_id_col'   => 'id',
                'source_label_col'=> 'nom_agence',
            ],
            'user' => [
                'label'           => 'Utilisateur',
                'max_len'         => 8,
                'source_table'    => 'users',
                'source_id_col'   => 'id',
                'source_label_col'=> "CONCAT_WS(' ', prenom, nom)",
            ],
            'banque' => [
                'label'           => 'Banque',
                'max_len'         => 6,
                'source_table'    => null,
                'source_id_col'   => null,
                'source_label_col'=> null,
            ],
            'immeuble' => [
                'label'           => 'Immeuble',
                'max_len'         => 14,
                'source_table'    => 'immeubles',
                'source_id_col'   => 'id',
                'source_label_col'=> 'nom_immeuble',
            ],
            'type_document' => [
                'label'           => 'Type document',
                'max_len'         => 14,
                'source_table'    => null,
                'source_id_col'   => null,
                'source_label_col'=> null,
            ],
            'fournisseur' => [
                'label'           => 'Fournisseur',
                'max_len'         => 10,
                'source_table'    => null, // tiers, filtré par rôle (non seedé phase 2)
                'source_id_col'   => null,
                'source_label_col'=> null,
            ],
            'proprietaire' => [
                'label'           => 'Propriétaire',
                'max_len'         => 12,
                'source_table'    => null, // tiers (non seedé phase 2)
                'source_id_col'   => null,
                'source_label_col'=> null,
            ],
            'metier_n1' => [
                'label'           => 'Métier N1',
                'max_len'         => 6,
                'source_table'    => 'ged_level_codes',
                'source_id_col'   => 'id',
                'source_label_col'=> 'label',
            ],
            'metier_n2' => [
                'label'           => 'Métier N2',
                'max_len'         => 8,
                'source_table'    => 'ged_level_codes',
                'source_id_col'   => 'id',
                'source_label_col'=> 'label',
            ],
            'metier_n3' => [
                'label'           => 'Métier N3',
                'max_len'         => 6,
                'source_table'    => 'ged_level_codes',
                'source_id_col'   => 'id',
                'source_label_col'=> 'label',
            ],
            'exercice' => [
                'label'           => 'Exercice comptable',
                'max_len'         => 6,
                'source_table'    => null,
                'source_id_col'   => null,
                'source_label_col'=> null,
            ],
            'autre' => [
                'label'           => 'Autre',
                'max_len'         => 14,
                'source_table'    => null,
                'source_id_col'   => null,
                'source_label_col'=> null,
            ],
        ];
    }
}

if (!function_exists('ged_glossary_max_length')) {
    function ged_glossary_max_length(string $category): int
    {
        $cats = ged_glossary_categories();
        return (int)($cats[$category]['max_len'] ?? 14);
    }
}

if (!function_exists('ged_glossary_pdo')) {
    function ged_glossary_pdo(): PDO
    {
        return ged_pdo();
    }
}

if (!function_exists('ged_glossary_current_tenant')) {
    function ged_glossary_current_tenant(): int
    {
        $tid = ged_current_tenant_id();
        return $tid !== null ? (int)$tid : 0;
    }
}

// ════════════════════════════════════════════════════════════════════════
// VALIDATION
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('ged_glossary_slug_code')) {
    /**
     * Normalise un code brut en code valide :
     *  - ASCII only (suppression accents)
     *  - UPPERCASE
     *  - Caractères autorisés : A-Z 0-9 . -
     *  - Pas de doublons _ ni séparateurs en bord
     */
    function ged_glossary_slug_code(string $raw): string
    {
        if ($raw === '') return '';
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
            if ($tr !== false) $raw = $tr;
        }
        $raw = strtoupper(trim($raw));
        // Remplace tout ce qui n'est pas A-Z 0-9 . - par rien (pour rester court)
        $raw = preg_replace('/[^A-Z0-9.\-]+/', '', $raw) ?? '';
        // Compaction caractères répétés (-- → -)
        $raw = preg_replace('/(\.\.+)/', '.', $raw) ?? $raw;
        $raw = preg_replace('/(--+)/', '-', $raw) ?? $raw;
        return trim($raw, '.-');
    }
}

if (!function_exists('ged_glossary_validate_code')) {
    /**
     * Vérifie qu'un code est valide pour une catégorie donnée.
     *
     * @return array{ok:bool, errors:array<string>, normalized:string}
     */
    function ged_glossary_validate_code(string $category, string $code, ?PDO $pdo = null, ?int $ignoreId = null): array
    {
        $errors = [];
        $cats = ged_glossary_categories();
        if (!isset($cats[$category])) {
            $errors[] = "Catégorie inconnue : $category";
        }
        $norm = ged_glossary_slug_code($code);
        if ($norm === '') {
            $errors[] = 'Code vide après normalisation';
        }
        $maxLen = ged_glossary_max_length($category);
        if (mb_strlen($norm) > $maxLen) {
            $errors[] = "Code trop long ($maxLen chars max, $norm = " . mb_strlen($norm) . ')';
        }
        if (mb_strlen($norm) < 2) {
            $errors[] = "Code trop court (2 chars min)";
        }

        // Vérification unicité par tenant + category
        if (count($errors) === 0) {
            if ($pdo === null) $pdo = ged_glossary_pdo();
            $tenantId = ged_glossary_current_tenant();
            $sql = "SELECT id FROM ged_codes_glossaire
                    WHERE tenant_id = ? AND category = ? AND code = ?";
            $params = [$tenantId, $category, $norm];
            if ($ignoreId !== null) {
                $sql .= " AND id <> ?";
                $params[] = $ignoreId;
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            if ($st->fetchColumn()) {
                $errors[] = "Code déjà utilisé dans cette catégorie : $norm";
            }
        }

        return [
            'ok'         => count($errors) === 0,
            'errors'     => $errors,
            'normalized' => $norm,
        ];
    }
}

// ════════════════════════════════════════════════════════════════════════
// LECTURE
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('ged_glossary_get')) {
    /**
     * Lit une entrée glossaire par (category, entity_id).
     *
     * @return array|null
     */
    function ged_glossary_get(string $category, int $entityId, ?PDO $pdo = null): ?array
    {
        if ($entityId <= 0) return null;
        if ($pdo === null) $pdo = ged_glossary_pdo();
        $tenantId = ged_glossary_current_tenant();
        try {
            $st = $pdo->prepare("
                SELECT * FROM ged_codes_glossaire
                WHERE tenant_id = ? AND category = ? AND entity_id = ? AND is_active = 1
                LIMIT 1
            ");
            $st->execute([$tenantId, $category, $entityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('ged_glossary_get_by_code')) {
    /**
     * Lit une entrée glossaire par (category, code).
     */
    function ged_glossary_get_by_code(string $category, string $code, ?PDO $pdo = null): ?array
    {
        $norm = ged_glossary_slug_code($code);
        if ($norm === '') return null;
        if ($pdo === null) $pdo = ged_glossary_pdo();
        $tenantId = ged_glossary_current_tenant();
        try {
            $st = $pdo->prepare("
                SELECT * FROM ged_codes_glossaire
                WHERE tenant_id = ? AND category = ? AND code = ?
                LIMIT 1
            ");
            $st->execute([$tenantId, $category, $norm]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('ged_glossary_list')) {
    /**
     * Liste paginée du glossaire avec filtres optionnels.
     *
     * @param array{category?:string, search?:string, limit?:int, offset?:int} $filters
     * @return array{rows:array<array>, total:int}
     */
    function ged_glossary_list(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_glossary_pdo();
        $tenantId = ged_glossary_current_tenant();
        $where  = ['tenant_id = ?'];
        $params = [$tenantId];
        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = (string)$filters['category'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(code LIKE ? OR label LIKE ?)';
            $needle = '%' . $filters['search'] . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        $whereSql = implode(' AND ', $where);

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM ged_codes_glossaire WHERE $whereSql");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $limit  = max(1, min(500, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $st = $pdo->prepare("
            SELECT * FROM ged_codes_glossaire
            WHERE $whereSql
            ORDER BY category ASC, code ASC
            LIMIT $limit OFFSET $offset
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }
}

// ════════════════════════════════════════════════════════════════════════
// CRÉATION / MISE À JOUR
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('ged_glossary_set')) {
    /**
     * Crée ou met à jour une entrée glossaire.
     *
     * @param array{
     *   category:string,
     *   entity_table?:?string,
     *   entity_id?:?int,
     *   code:string,
     *   label:string,
     *   is_locked?:bool,
     *   is_active?:bool,
     *   notes?:?string,
     * } $input
     * @return array{ok:bool, id:?int, errors:array<string>, code:string}
     */
    function ged_glossary_set(array $input, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_glossary_pdo();
        $tenantId = ged_glossary_current_tenant();
        if ($tenantId <= 0) {
            return ['ok'=>false, 'id'=>null, 'errors'=>['tenant_id manquant'], 'code'=>''];
        }
        $category = (string)($input['category'] ?? '');
        $code     = (string)($input['code'] ?? '');
        $label    = trim((string)($input['label'] ?? ''));

        // Récupère un éventuel id existant pour l'ignorer dans la check unicité
        $entityTable = (string)($input['entity_table'] ?? '');
        $entityId    = isset($input['entity_id']) ? (int)$input['entity_id'] : null;

        $existingId = null;
        if ($entityId !== null && $entityId > 0 && $entityTable !== '') {
            try {
                $st = $pdo->prepare("
                    SELECT id FROM ged_codes_glossaire
                    WHERE tenant_id = ? AND category = ? AND entity_table = ? AND entity_id = ?
                    LIMIT 1
                ");
                $st->execute([$tenantId, $category, $entityTable, $entityId]);
                $existingId = $st->fetchColumn();
                $existingId = $existingId ? (int)$existingId : null;
            } catch (Throwable) {}
        }

        $check = ged_glossary_validate_code($category, $code, $pdo, $existingId);
        if (!$check['ok']) {
            return ['ok'=>false, 'id'=>$existingId, 'errors'=>$check['errors'], 'code'=>$check['normalized']];
        }

        $norm = $check['normalized'];
        if ($label === '') $label = $norm;
        $isLocked = !empty($input['is_locked']) ? 1 : 0;
        $isActive = (!isset($input['is_active']) || !empty($input['is_active'])) ? 1 : 0;
        $notes = isset($input['notes']) ? (string)$input['notes'] : null;
        $createdBy = function_exists('current_user_id') ? (int)current_user_id() : null;

        try {
            if ($existingId !== null) {
                $pdo->prepare("
                    UPDATE ged_codes_glossaire
                    SET code = ?, label = ?, is_locked = ?, is_active = ?, notes = ?
                    WHERE id = ?
                ")->execute([$norm, $label, $isLocked, $isActive, $notes, $existingId]);
                return ['ok'=>true, 'id'=>$existingId, 'errors'=>[], 'code'=>$norm];
            }

            $pdo->prepare("
                INSERT INTO ged_codes_glossaire
                  (tenant_id, category, entity_table, entity_id, code, label, is_locked, is_active, notes, created_by)
                VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $tenantId, $category, $entityTable, $entityId, $norm, $label,
                $isLocked, $isActive, $notes, $createdBy ?: null,
            ]);
            return ['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'errors'=>[], 'code'=>$norm];
        } catch (Throwable $e) {
            return ['ok'=>false, 'id'=>null, 'errors'=>[$e->getMessage()], 'code'=>$norm];
        }
    }
}

// ════════════════════════════════════════════════════════════════════════
// DÉRIVATION AUTO (par catégorie)
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('ged_glossary_derive_code')) {
    /**
     * Génère un code court depuis un libellé, selon les règles de la catégorie.
     * Tente de respecter la longueur max. Garantit qu'il est UNIQUE dans le glossaire.
     *
     * Si $opts['taken_codes'] est fourni (array<string,true>), la vérification d'unicité
     * se fait en mémoire (plus de requête BDD) — utilisé par le seed initial pour la perf.
     *
     * @param string $category
     * @param string $label   Libellé source (ex "Régie Emery")
     * @param array $opts     Optionnel : 'matricule_paie' pour user, 'code_existant' pour agence/immeuble, 'taken_codes' set en mémoire
     * @return string Code dérivé, validé unique
     */
    function ged_glossary_derive_code(string $category, string $label, array $opts = [], ?PDO $pdo = null): string
    {
        $maxLen = ged_glossary_max_length($category);

        $candidate = '';
        switch ($category) {
            case 'user':
                $mat = trim((string)($opts['matricule_paie'] ?? ''));
                if ($mat !== '') {
                    $candidate = ged_glossary_slug_code($mat);
                } else {
                    $userId = (int)($opts['user_id'] ?? 0);
                    $candidate = 'U' . $userId;
                }
                break;

            case 'agence':
                $existing = trim((string)($opts['code_existant'] ?? ''));
                if ($existing !== '') {
                    $candidate = ged_glossary_slug_code($existing);
                } else {
                    $candidate = ged_glossary_derive_from_label($label, $maxLen);
                }
                break;

            case 'immeuble':
                $existing = trim((string)($opts['code_existant'] ?? ''));
                if ($existing !== '') {
                    $candidate = ged_glossary_slug_code($existing);
                } else {
                    $candidate = ged_glossary_derive_from_label($label, $maxLen);
                }
                break;

            case 'metier_n1':
            case 'metier_n2':
            case 'metier_n3':
                // Enlever préfixe numérique "06_COMPTABILITE" → "COMPTABILITE"
                $clean = preg_replace('/^\d+\s*[-_]?\s*/', '', $label) ?? $label;
                $candidate = ged_glossary_derive_from_label($clean, $maxLen);
                break;

            default:
                $candidate = ged_glossary_derive_from_label($label, $maxLen);
                break;
        }

        // Tronque si dépassement
        if (mb_strlen($candidate) > $maxLen) {
            $candidate = mb_substr($candidate, 0, $maxLen);
        }

        // Vérification d'unicité : en mémoire si taken_codes fourni, sinon BDD
        $takenCodes = $opts['taken_codes'] ?? null; // ['CODE1' => true, 'CODE2' => true, …] ou null
        $isAvailable = static function (string $c) use ($category, $pdo, &$takenCodes): bool {
            if ($c === '') return false;
            if (is_array($takenCodes)) return !isset($takenCodes[$c]);
            return ged_glossary_is_code_available($category, $c, $pdo);
        };

        $reserve = static function (string $c) use (&$takenCodes): void {
            if (is_array($takenCodes)) $takenCodes[$c] = true;
        };

        if ($candidate === '' || !$isAvailable($candidate)) {
            $base = $candidate !== '' ? $candidate : 'X';
            for ($i = 1; $i <= 99; $i++) {
                $suffix = (string)$i;
                $room = $maxLen - mb_strlen($suffix);
                if ($room < 1) { $base = mb_substr($base, 0, 1); $room = $maxLen - mb_strlen($suffix); }
                $try = mb_substr($base, 0, $room) . $suffix;
                if ($isAvailable($try)) {
                    $reserve($try);
                    if (is_array($takenCodes)) $opts['taken_codes'] = $takenCodes; // mutate caller's ref by-ref impossible ici sans &; on documente l'usage
                    return $try;
                }
            }
            // Fallback ultime : random
            $rand = mb_substr(strtoupper(bin2hex(random_bytes(4))), 0, $maxLen);
            $reserve($rand);
            return $rand;
        }

        $reserve($candidate);
        return $candidate;
    }
}

if (!function_exists('ged_glossary_derive_from_label')) {
    /**
     * Algorithme générique : prend les 2 premières lettres significatives de chaque mot,
     * tronque à maxLen.
     */
    function ged_glossary_derive_from_label(string $label, int $maxLen): string
    {
        if ($label === '') return '';
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if ($tr !== false) $label = $tr;
        }
        $label = strtoupper($label);

        $skip = ['LE','LA','LES','DE','DU','DES','L','D','ET','A','AU','AUX','SDC','SCI','SAS','SARL','SA','EURL'];
        $words = preg_split('/[^A-Z0-9]+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $significant = array_values(array_filter($words, fn($w) => !in_array($w, $skip, true)));

        if (count($significant) === 0) $significant = $words; // fallback si tous filtrés

        if (count($significant) === 1) {
            // Un seul mot : prend les N premières lettres
            return mb_substr($significant[0], 0, $maxLen);
        }

        // Plusieurs mots : 2 premières lettres de chaque mot, jusqu'à atteindre maxLen
        $code = '';
        foreach ($significant as $w) {
            $code .= mb_substr($w, 0, 2);
            if (mb_strlen($code) >= $maxLen) break;
        }
        return mb_substr($code, 0, $maxLen);
    }
}

if (!function_exists('ged_glossary_is_code_available')) {
    function ged_glossary_is_code_available(string $category, string $code, ?PDO $pdo = null): bool
    {
        if ($code === '') return false;
        if ($pdo === null) $pdo = ged_glossary_pdo();
        $tenantId = ged_glossary_current_tenant();
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM ged_codes_glossaire
                WHERE tenant_id = ? AND category = ? AND code = ?
            ");
            $st->execute([$tenantId, $category, $code]);
            return (int)$st->fetchColumn() === 0;
        } catch (Throwable) { return false; }
    }
}

// ════════════════════════════════════════════════════════════════════════
// RÉSOLUTION (lecture + dérivation + persist si absent)
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('ged_glossary_sync_entity')) {
    /**
     * Wrapper utilisé par les hooks CRUD : à appeler après INSERT/UPDATE d'une entité.
     *
     * Comportement :
     *  - Si pas d'entrée glossaire pour (category, entity_id) → crée auto via derive_code
     *  - Si entrée existe et label différent → met à jour SEULEMENT le label (jamais le code)
     *  - Si entrée existe et label identique → no-op
     *
     * Ne touche JAMAIS au code (pour ne pas casser les noms de fichiers déjà créés).
     * Code reste modifiable manuellement via l'UI glossaire.
     *
     * @return array{status:string, code:?string, action:string}
     *   status: 'created' | 'updated_label' | 'unchanged' | 'error'
     *   action: brève description de ce qui s'est passé
     */
    function ged_glossary_sync_entity(string $category, int $entityId, string $label, string $entityTable, array $opts = [], ?PDO $pdo = null): array
    {
        if ($entityId <= 0 || $label === '') {
            return ['status' => 'error', 'code' => null, 'action' => 'invalid input'];
        }
        if ($pdo === null) $pdo = ged_glossary_pdo();

        $existing = ged_glossary_get($category, $entityId, $pdo);
        if ($existing === null) {
            // Création auto
            $code = ged_glossary_resolve($category, $entityId, array_merge($opts, [
                'label' => $label,
                'entity_table' => $entityTable,
            ]), true, $pdo);
            return ['status' => 'created', 'code' => $code, 'action' => "Code créé : $code"];
        }

        // Existe : maj label si différent (jamais le code)
        if ((string)$existing['label'] !== $label) {
            try {
                $pdo->prepare("UPDATE ged_codes_glossaire SET label = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$label, (int)$existing['id']]);
                return ['status' => 'updated_label', 'code' => (string)$existing['code'],
                        'action' => "Label mis à jour : « {$existing['label']} » → « $label »"];
            } catch (Throwable $e) {
                return ['status' => 'error', 'code' => (string)$existing['code'],
                        'action' => 'Erreur UPDATE label: ' . $e->getMessage()];
            }
        }
        return ['status' => 'unchanged', 'code' => (string)$existing['code'], 'action' => 'rien à faire'];
    }
}

if (!function_exists('ged_glossary_resolve')) {
    /**
     * Renvoie le code utilisable pour une entité.
     * - Si présent dans le glossaire → retourne tel quel
     * - Sinon, si autoSeed=true → dérive et persiste
     * - Sinon → null
     *
     * @param string $category
     * @param int $entityId
     * @param array $opts     ['label'=>string, 'entity_table'=>string, 'matricule_paie'=>string, 'code_existant'=>string]
     * @param bool $autoSeed
     */
    function ged_glossary_resolve(string $category, int $entityId, array $opts = [], bool $autoSeed = false, ?PDO $pdo = null): ?string
    {
        static $cache = [];
        $key = $category . ':' . $entityId;
        if (isset($cache[$key])) return $cache[$key];

        $row = ged_glossary_get($category, $entityId, $pdo);
        if ($row !== null) {
            return $cache[$key] = (string)$row['code'];
        }

        if (!$autoSeed) return null;

        $label = (string)($opts['label'] ?? '');
        if ($label === '') $label = "Entity#$entityId";
        $code = ged_glossary_derive_code($category, $label, array_merge($opts, ['user_id' => $entityId]), $pdo);

        $set = ged_glossary_set([
            'category'     => $category,
            'entity_table' => (string)($opts['entity_table'] ?? ''),
            'entity_id'    => $entityId,
            'code'         => $code,
            'label'        => $label,
            'is_locked'    => false, // codes auto-dérivés modifiables
            'notes'        => 'Auto-généré le ' . date('Y-m-d H:i:s'),
        ], $pdo);

        return $cache[$key] = ($set['ok'] ? $set['code'] : null);
    }
}
