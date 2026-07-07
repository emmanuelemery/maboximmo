<?php
declare(strict_types=1);

/**
 * GED Classement v3 — helpers pour la nouvelle règle de classement
 *
 * Validé EMERY 2026-05-13 :
 *   - N0 société + N0bis agence préremplies depuis $_SESSION (user connecté)
 *   - N1 métier proposé selon profil/rôle user
 *   - Minimum obligatoire : N1 + N2 + N3 renseignés
 *   - N4 + N5 proposés par IA
 *   - N6 libre
 *
 * Pas de doublon avec inc/ged_import_functions.php (V2) :
 *   - V2 reste actif pour super_admin_ged_import.php
 *   - V3 est utilisé par FluxBox et les nouvelles pages
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ged_functions.php';

if (!function_exists('ged_v3_societe_code')) {
    /**
     * Génère un code société format "XX.YY" (4 lettres + point) depuis un nom.
     *  - 2 mots ou + → 2 premières lettres de chaque mot significatif (skip "le/la/de/et/du/des")
     *  - 1 mot       → 2 premières + 2 suivantes (ex EMERY → EM.ER)
     *
     * @example "Régie Emery"  → "RE.EM"
     * @example "Emery Immo"   → "EM.IM"
     * @example "AB Gestion"   → "AB.GE"
     * @example "EMERY"        → "EM.ER"
     */
    function ged_v3_societe_code(string $nom): string
    {
        $nom = trim($nom);
        if ($nom === '') return '??.??';

        // Normalisation accents → ASCII
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom);
            if ($tr !== false) $nom = $tr;
        }
        $nom = strtoupper($nom);

        // Mots significatifs (skip prépositions/articles courts)
        $skip = ['LE','LA','LES','DE','DU','DES','L','D','ET','LA','A','AU','AUX'];
        $words = preg_split('/[^A-Z0-9]+/', $nom, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn($w) => !in_array($w, $skip, true)));

        if (count($words) >= 2) {
            $a = substr($words[0], 0, 2);
            $b = substr($words[1], 0, 2);
            // Pad si moins de 2 chars
            $a = str_pad($a, 2, 'X');
            $b = str_pad($b, 2, 'X');
            return $a . '.' . $b;
        }
        if (count($words) === 1) {
            $w = $words[0];
            $a = substr($w, 0, 2);
            $b = substr($w, 2, 2);
            $a = str_pad($a, 2, 'X');
            $b = str_pad($b, 2, 'X');
            return $a . '.' . $b;
        }
        return '??.??';
    }
}

if (!function_exists('ged_v3_list_societes_accessible')) {
    /**
     * Liste les sociétés accessibles à l'utilisateur courant selon son rôle.
     *  - super admin (role=1) : toutes
     *  - autres                : sa société uniquement
     *
     * @return array<array{id:int, nom:string, code:string}>
     */
    function ged_v3_list_societes_accessible(?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_pdo();
        $roleId = (int)($_SESSION['id_role'] ?? 0);
        $isSuper = $roleId === 1 || (!empty($_SESSION['super_admin']));
        $userSoc = (int)($_SESSION['id_societe'] ?? 0);

        try {
            if ($isSuper) {
                $st = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY ordre_affichage, nom");
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($userSoc > 0) {
                $st = $pdo->prepare("SELECT id, nom FROM societes WHERE id = ? AND actif = 1");
                $st->execute([$userSoc]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                return [];
            }
        } catch (Throwable) { return []; }

        return array_map(static fn($r) => [
            'id'   => (int)$r['id'],
            'nom'  => (string)$r['nom'],
            'code' => ged_v3_societe_code((string)$r['nom']),
        ], $rows);
    }
}

if (!function_exists('ged_v3_list_agences_accessible')) {
    /**
     * Liste les agences accessibles à l'utilisateur courant pour une société donnée.
     *  - super admin / role≤2 : toutes les agences de la société
     *  - autres                : son agence uniquement
     *
     * @return array<array{id:int, nom:string, code:string}>
     */
    function ged_v3_list_agences_accessible(int $societeId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_pdo();
        if ($societeId <= 0) return [];

        $roleId = (int)($_SESSION['id_role'] ?? 0);
        $canSwitch = $roleId === 1 || $roleId === 2 || (!empty($_SESSION['super_admin']));
        $userAgence = (int)($_SESSION['id_agence'] ?? 0);

        try {
            if ($canSwitch) {
                $st = $pdo->prepare("
                    SELECT id, nom_agence AS nom,
                           COALESCE(NULLIF(code_agence,''), NULLIF(code_interne,''), '') AS code_raw
                    FROM agences
                    WHERE id_societe = ?
                    ORDER BY nom_agence
                ");
                $st->execute([$societeId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($userAgence > 0) {
                $st = $pdo->prepare("
                    SELECT id, nom_agence AS nom,
                           COALESCE(NULLIF(code_agence,''), NULLIF(code_interne,''), '') AS code_raw
                    FROM agences
                    WHERE id = ? AND id_societe = ?
                ");
                $st->execute([$userAgence, $societeId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                return [];
            }
        } catch (Throwable) { return []; }

        return array_map(static function ($r) {
            $code = trim((string)$r['code_raw']);
            if ($code === '') {
                // Dérivation auto depuis le nom (3-4 lettres)
                $code = ged_v3_societe_code((string)$r['nom']);
            }
            return [
                'id'   => (int)$r['id'],
                'nom'  => (string)$r['nom'],
                'code' => $code,
            ];
        }, $rows);
    }
}

if (!function_exists('ged_v3_get_user_context')) {
    /**
     * Renvoie le contexte de classement préremplissable depuis la session.
     * Société + agence + code société + code agence + rôle + droits modification.
     *
     * @return array{
     *   user_id:int, role_id:int,
     *   societe_id:?int, societe_code:string, societe_label:string,
     *   agence_id:?int, agence_code:string, agence_label:string,
     *   can_change_societe:bool, can_change_agence:bool, available_agences:array
     * }
     */
    function ged_v3_get_user_context(?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_pdo();

        $userId    = current_user_id();
        $roleId    = (int)($_SESSION['id_role'] ?? 0);
        $societeId = current_societe_id();
        $agenceId  = current_agence_id();

        $societeCode  = '';
        $societeLabel = '';
        $agenceCode   = '';
        $agenceLabel  = '';

        if ($societeId !== null) {
            try {
                $st = $pdo->prepare("SELECT code, nom FROM societes WHERE id = ? LIMIT 1");
                $st->execute([$societeId]);
                if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $societeCode  = (string)($row['code'] ?? '');
                    $societeLabel = (string)($row['nom'] ?? '');
                }
            } catch (Throwable) { /* table peut-être absente / colonnes différentes */ }
        }

        if ($agenceId !== null) {
            try {
                $st = $pdo->prepare("SELECT code, nom FROM agences WHERE id = ? LIMIT 1");
                $st->execute([$agenceId]);
                if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $agenceCode  = (string)($row['code'] ?? '');
                    $agenceLabel = (string)($row['nom'] ?? '');
                }
            } catch (Throwable) {}
        }

        // Droits modification :
        // - super admin (role=1) : tout modifiable
        // - manager (role=2)     : peut changer d'agence dans son périmètre (à étendre)
        // - autres               : société + agence verrouillées
        $isSuper = $roleId === 1;
        $isMgr   = $roleId === 2;

        $availableAgences = [];
        if ($isSuper) {
            try {
                $st = $pdo->query("SELECT id, code, nom FROM agences ORDER BY nom ASC");
                $availableAgences = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {}
        } elseif ($isMgr && $societeId !== null) {
            try {
                $st = $pdo->prepare("SELECT id, code, nom FROM agences WHERE id_societe = ? ORDER BY nom ASC");
                $st->execute([$societeId]);
                $availableAgences = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {}
        }

        return [
            'user_id'             => $userId,
            'role_id'             => $roleId,
            'societe_id'          => $societeId,
            'societe_code'        => $societeCode,
            'societe_label'       => $societeLabel,
            'agence_id'           => $agenceId,
            'agence_code'         => $agenceCode,
            'agence_label'        => $agenceLabel,
            'can_change_societe'  => $isSuper,
            'can_change_agence'   => $isSuper || $isMgr,
            'available_agences'   => $availableAgences,
        ];
    }
}

if (!function_exists('ged_v3_suggest_n1_by_role')) {
    /**
     * Propose un N1 par défaut selon le rôle / service du user connecté.
     * Heuristique simple — l'IA peut surcharger via le contexte document.
     *
     * @return string Code N1 (ex "03_GESTION_LOCATIVE") ou '' si aucun défaut
     */
    function ged_v3_suggest_n1_by_role(?PDO $pdo = null): string
    {
        if ($pdo === null) $pdo = ged_pdo();
        $userId = current_user_id();
        if ($userId <= 0) return '';

        // On essaie de lire le service du user (heuristique sur table users / users_services)
        try {
            $st = $pdo->prepare("
                SELECT s.code AS service_code, s.libelle AS service_label
                FROM users u
                LEFT JOIN services s ON s.id = u.id_service
                WHERE u.id = ? LIMIT 1
            ");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $svcCode = strtoupper((string)($row['service_code'] ?? ''));
        } catch (Throwable) {
            $svcCode = '';
        }

        // Mapping service → N1 canon (cohérent avec [[project_ged_arbitrage_modele_n1_2026-05-12]])
        $map = [
            'GESTION'      => '03_GESTION_LOCATIVE',
            'SYNDIC'       => '04_SYNDIC',
            'TRANSACTION'  => '05_TRANSACTION',
            'RH'           => '02_RH',
            'COMPTA'       => '06_COMPTABILITE',
            'COMPTABILITE' => '06_COMPTABILITE',
            'DIRECTION'    => '01_DIRECTION',
            'AGENCE'       => '01_AGENCE',
            'JURIDIQUE'    => '07_JURIDIQUE_CONTENTIEUX',
        ];

        return $map[$svcCode] ?? '';
    }
}

if (!function_exists('ged_v3_validate_minimum')) {
    /**
     * Valide le minimum obligatoire d'un classement candidat.
     *
     * MODÈLE 11 ZONES (2026-07) : le rangement est déterminé par le Type de document
     * + l'entité (Société · Agence · Métier · Propriétaire · Immeuble · Bien · Bail ·
     * Type · Réf · Libellé · Date). Le Domaine (N2) et le Sous-domaine (N3) sont
     * désormais DÉRIVÉS/optionnels — plus saisis à la main. On n'exige donc plus que
     * le Métier (N1). (Auparavant : N1+N2+N3, ce qui bloquait à tort les types comme la CNI.)
     *
     * @return array{ok:bool, errors:array<string>}
     */
    function ged_v3_validate_minimum(array $classement): array
    {
        $errors = [];
        if (trim((string)($classement['n1'] ?? '')) === '') {
            $errors[] = 'Métier (N1) requis';
        }
        return ['ok' => count($errors) === 0, 'errors' => $errors];
    }
}

if (!function_exists('ged_v3_get_n1_groups')) {
    /**
     * Renvoie les 8 groupes UI groupant les N1 canon.
     *
     * @return array<string, array{label:string, icon:string, n1_codes:array<array{code:string,label:string}>}>
     */
    function ged_v3_get_n1_groups(?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = ged_pdo();

        $groups = [
            'RH'           => ['label' => 'RH',           'icon' => '👥', 'n1_codes' => []],
            'COMPTA'       => ['label' => 'Comptabilité', 'icon' => '💰', 'n1_codes' => []],
            'BAILLEUR'     => ['label' => 'Gestion',      'icon' => '🏠', 'n1_codes' => []],
            'SYNDIC'       => ['label' => 'Syndic',       'icon' => '🏢', 'n1_codes' => []],
            'AGENCE'       => ['label' => 'Agence',       'icon' => '🤝', 'n1_codes' => []],
            'FOURNISSEURS' => ['label' => 'Fournisseurs', 'icon' => '🔧', 'n1_codes' => []],
            'DIRECTION'    => ['label' => 'Direction',    'icon' => '⚙️', 'n1_codes' => []],
            'JURIDIQUE'    => ['label' => 'Juridique',    'icon' => '⚖️', 'n1_codes' => []],
        ];

        try {
            $st = $pdo->query("
                SELECT code, label, COALESCE(business_group, '') AS bg
                FROM ged_level_codes
                WHERE level_number = 1
                  AND is_active = 1
                  AND COALESCE(is_virtual, 0) = 0
                ORDER BY position ASC, label ASC
            ");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $bg = (string)$row['bg'];
                if ($bg !== '' && isset($groups[$bg])) {
                    $groups[$bg]['n1_codes'][] = [
                        'code'  => (string)$row['code'],
                        'label' => (string)$row['label'],
                    ];
                }
            }
        } catch (Throwable) {}

        return $groups;
    }
}

if (!function_exists('ged_v3_get_children')) {
    /**
     * Charge les enfants d'un niveau parent (N2 sous un N1, N3 sous un N2, etc.).
     * Filtre les codes virtuels (is_virtual=1) qui ne sont que des filtres UI.
     *
     * @param int $level Niveau cible (2..5)
     * @param array{n1?:string,n2?:string,n3?:string,n4?:string} $parents
     */
    function ged_v3_get_children(int $level, array $parents, ?PDO $pdo = null): array
    {
        if ($level < 2 || $level > 5) return [];
        if ($pdo === null) $pdo = ged_pdo();

        // 2026-05-17 : si N3 est une INSTANCE (ex 2024_ILOT_17) et pas un placeholder (IMMEUBLE),
        // on remonte automatiquement au placeholder pour charger les N4/N5. Les sous-niveaux
        // sont définis UNE seule fois sous le placeholder et s'appliquent à toutes les instances.
        if ($level >= 4 && !empty($parents['n3']) && !empty($parents['n1']) && !empty($parents['n2'])) {
            try {
                $stPh = $pdo->prepare("
                    SELECT 1 FROM ged_level_codes
                    WHERE level_number = 3
                      AND code COLLATE utf8mb4_unicode_ci = ?
                      AND COALESCE(is_entity_placeholder, 0) = 1
                    LIMIT 1
                ");
                $stPh->execute([$parents['n3']]);
                $isPlaceholder = (bool)$stPh->fetchColumn();
                if (!$isPlaceholder) {
                    // C'est une instance → cherche le placeholder N3 sous les mêmes N1/N2
                    $stPh2 = $pdo->prepare("
                        SELECT code FROM ged_level_codes
                        WHERE level_number = 3
                          AND parent_n1 COLLATE utf8mb4_unicode_ci = ?
                          AND parent_n2 COLLATE utf8mb4_unicode_ci = ?
                          AND COALESCE(is_entity_placeholder, 0) = 1
                          AND is_active = 1
                        LIMIT 1
                    ");
                    $stPh2->execute([$parents['n1'], $parents['n2']]);
                    $placeholderCode = (string)$stPh2->fetchColumn();
                    if ($placeholderCode !== '') {
                        $parents['n3'] = $placeholderCode;
                    }
                }
            } catch (Throwable) {}
        }

        $where  = ["level_number = ?", "is_active = 1", "COALESCE(is_virtual, 0) = 0"];
        $params = [$level];

        // Force COLLATE sur les comparaisons : la colonne est en utf8mb4_unicode_ci mais le
        // paramètre PHP arrive en utf8mb4_general_ci → sans COLLATE explicite, MySQL peut
        // ne pas matcher (le code existe en BDD mais le SELECT le rate silencieusement).
        // ⚠️ Le COLLATE doit porter sur la COLONNE, pas sur le paramètre `?` : en prepared
        // natif (ATTR_EMULATE_PREPARES=0), le paramètre lié est en charset 'binary' et
        // « ? COLLATE utf8mb4_unicode_ci » lève « COLLATION not valid for CHARACTER SET binary »
        // → toute la requête échoue (cascade GED vide : « Aucune catégorie seedée »).
        foreach (['n1', 'n2', 'n3', 'n4'] as $i => $k) {
            if ($level > $i + 1) {
                $val = trim((string)($parents[$k] ?? ''));
                if ($val !== '') {
                    $where[]  = "parent_{$k} COLLATE utf8mb4_unicode_ci = ?";
                    $params[] = $val;
                } else {
                    $where[] = "(parent_{$k} IS NULL OR parent_{$k} = '')";
                }
            }
        }

        $sql = "SELECT id, code, label,
                       COALESCE(is_entity_placeholder, 0) AS is_entity_placeholder
                FROM ged_level_codes
                WHERE " . implode(' AND ', $where) . "
                ORDER BY position ASC, label ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
