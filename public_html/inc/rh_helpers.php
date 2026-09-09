<?php
declare(strict_types=1);

if (!function_exists('rh_user_name_expr')) {
    function rh_user_name_expr(string $alias = 'u'): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        if ($alias === '') {
            $alias = 'u';
        }
        return "TRIM(CONCAT_WS(' ', {$alias}.prenom, {$alias}.nom))";
    }
}

if (!function_exists('rh_user_display_name')) {
    function rh_user_display_name(array $row): string
    {
        $nomComplet = trim((string)($row['nom_complet'] ?? ''));
        if ($nomComplet !== '') {
            return $nomComplet;
        }

        $prenom = trim((string)($row['prenom'] ?? ''));
        $nom = trim((string)($row['nom'] ?? ''));
        $full = trim($prenom . ' ' . $nom);
        if ($full !== '') {
            return $full;
        }

        $username = trim((string)($row['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        return 'Utilisateur';
    }
}

if (!function_exists('rh_table_exists')) {
    function rh_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = 't:' . $table;
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }
        $stmt = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return (bool)$cache[$key];
    }
}

if (!function_exists('rh_column_exists')) {
    function rh_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }
        $stmt = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return (bool)$cache[$key];
    }
}

if (!function_exists('rh_user_join_on')) {
    function rh_user_join_on(PDO $pdo, string $userAlias, string $foreignAlias, string $foreignCol = 'id_user'): string
    {
        $userAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $userAlias);
        if ($userAlias === '') {
            $userAlias = 'u';
        }
        $foreignAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $foreignAlias);
        if ($foreignAlias === '') {
            $foreignAlias = 's';
        }
        $foreignCol = preg_replace('/[^a-zA-Z0-9_]/', '', $foreignCol);
        if ($foreignCol === '') {
            $foreignCol = 'id_user';
        }

        $clauses = ["{$userAlias}.id = {$foreignAlias}.{$foreignCol}"];
        if (rh_column_exists($pdo, 'users', 'id_legacy')) {
            $clauses[] = "{$userAlias}.id_legacy = {$foreignAlias}.{$foreignCol}";
        }
        return '(' . implode(' OR ', $clauses) . ')';
    }
}
if (!function_exists('rh_user_salary_id')) {
    function rh_user_salary_id(PDO $pdo, int $userId): int
    {
        if ($userId <= 0) {
            return $userId;
        }
        if (!rh_column_exists($pdo, 'users', 'id_legacy')) {
            return $userId;
        }
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return (int)$cache[$userId];
        }
        $st = $pdo->prepare("SELECT id_legacy FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $legacy = (int)$st->fetchColumn();
        $cache[$userId] = ($legacy > 0 ? $legacy : $userId);
        return (int)$cache[$userId];
    }
}

if (!function_exists('rh_user_salary_ids')) {
    function rh_user_salary_ids(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $primary = $userId;
        $legacy = $userId;
        if (rh_column_exists($pdo, 'users', 'id_legacy')) {
            static $cache = [];
            if (array_key_exists($userId, $cache)) {
                $legacy = (int)$cache[$userId];
            } else {
                $st = $pdo->prepare("SELECT id_legacy FROM users WHERE id = :id LIMIT 1");
                $st->execute([':id' => $userId]);
                $legacy = (int)$st->fetchColumn();
                $cache[$userId] = $legacy;
            }
        }
        $ids = [$primary];
        if ($legacy > 0 && $legacy !== $primary) {
            $ids[] = $legacy;
        }
        return array_values(array_unique($ids));
    }
}
if (!function_exists('rh_abs_url')) {
    function rh_abs_url(string $path): string
    {
        $path = app_url($path);
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $path;
    }
}

if (!function_exists('rh_is_admin')) {
    function rh_is_admin(): bool
    {
        return function_exists('current_role_id') && current_role_id() === 1;
    }
}

if (!function_exists('rh_is_manager')) {
    function rh_is_manager(): bool
    {
        return function_exists('current_role_id') && current_role_id() === 2;
    }
}
if (!function_exists('rh_is_salary_month_closed')) {
    /**
     * Le mois de paie est-il clôturé ?
     *
     * ⚠️🔥 SANS `$idUser`, LA RÉPONSE EST GLOBALE — et « globale » veut dire
     *    qu'UNE SEULE fiche de paie clôturée, celle de n'importe qui, dans
     *    n'importe quelle société, ferme le mois pour TOUT LE MONDE. La requête
     *    d'origine ne portait ni `id_user` ni `id_societe` :
     *
     *        SELECT 1 FROM salaires
     *         WHERE mois_reference LIKE '2026-06-%' AND mois_cloture IS NOT NULL
     *
     *    Constaté le 09/09/2026 : une collaboratrice ne pouvait plus saisir ses
     *    déplacements de juin à septembre — écran entièrement grisé, badge
     *    « Mois de paie clôturé » — alors qu'aucune de SES fiches n'était close.
     *
     * ⚠️ IL EXISTE UN SECOND MAGASIN DE CLÔTURE, `mois_clos` (par société,
     *    migration `20260811_mois_clos_par_societe`, lu par `inc/rh_cloture.php`).
     *    On ne l'interroge PAS ici, délibérément : il dit qu'une PAIE est
     *    arrêtée, pas que les kilomètres d'un salarié y ont été déclarés. Un
     *    mois arrêté pour la société n'empêche pas de saisir des déplacements
     *    à reporter sur une paie ultérieure — c'est le cas d'usage même de
     *    l'écran. Les deux registres répondent à deux questions différentes.
     *
     * @param ?int $idUser Le collaborateur concerné. Fourni = « SA fiche de ce
     *                     mois est-elle clôturée ». Omis = ancienne réponse
     *                     globale, conservée pour les écrans de paie qui n'ont
     *                     pas de salarié en main (pilotage, fiches manquantes).
     */
    function rh_is_salary_month_closed(PDO $pdo, string $ym, ?int $idUser = null): bool
    {
        static $cache = [];
        $ym = trim($ym);
        if ($ym === '') {
            return false;
        }
        // ⚠️ LA CLÉ DE CACHE PORTE L'UTILISATEUR. Sans lui, la réponse du
        //    premier salarié servait à tous les suivants dans la même page —
        //    et l'écran des IK en affiche justement plusieurs mois d'affilée.
        $cle = $ym . '|' . ($idUser ?? 0);
        if (array_key_exists($cle, $cache)) {
            return (bool)$cache[$cle];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $cache[$cle] = false;
            return false;
        }

        // ── Réponse SCOPÉE : SA fiche de paie de ce mois-là, et rien d'autre ──
        //
        // 🔥 LA RÈGLE MÉTIER, ÉNONCÉE PAR EMMANUEL LE 09/09/2026 :
        //
        //    « On doit pouvoir créer des IK sur les mois qui n'ont JAMAIS été
        //      déclarés dans les salaires, et les mettre sur le mois en cours.
        //      Il ne faut bloquer que si ces IK ont été déclarées dans un
        //      salaire d'avant qui est bloqué. »
        //
        // C'est tout l'objet des deux menus de l'écran : on choisit le mois du
        // DÉPLACEMENT, puis le mois de PAIE où on veut le reporter. Un trajet
        // de juin peut donc parfaitement partir sur la paie de septembre.
        //
        // Un mois sans aucune fiche de paie pour ce salarié n'est PAS clos : il
        // n'a jamais été déclaré. Le refuser interdisait de rattraper un
        // arriéré, ce qui est exactement le cas d'usage.
        //
        // ⚠️ `salaires.id_user` peut porter l'identifiant LEGACY du salarié :
        //    la reprise a laissé les deux, et `ik_cloture_session.php` résout
        //    déjà `id_legacy` avant d'écrire. Chercher sur le seul `id` moderne
        //    ne trouverait pas la fiche, et rendrait « ouvert » un mois clos.
        if ($idUser !== null && $idUser > 0) {
            $st = $pdo->prepare("SELECT id_legacy FROM users WHERE id = ? LIMIT 1");
            $st->execute([$idUser]);
            $legacy = (int)($st->fetchColumn() ?: 0);

            $st = $pdo->prepare(
                "SELECT 1 FROM salaires
                  WHERE mois_reference LIKE :mois
                    AND mois_cloture IS NOT NULL
                    AND id_user IN (:u1, :u2)
                  LIMIT 1"
            );
            // ⚠️ Deux marqueurs distincts pour la même valeur quand il n'y a pas
            //    de legacy : un placeholder nommé ne se réutilise pas avec
            //    EMULATE_PREPARES à false.
            $st->execute([
                'mois' => $ym . '-%',
                'u1'   => $idUser,
                'u2'   => $legacy > 0 ? $legacy : $idUser,
            ]);
            $cache[$cle] = (bool)$st->fetchColumn();
            return (bool)$cache[$cle];
        }

        // ── Réponse GLOBALE, historique — voir l'avertissement en tête ──────
        if (!rh_table_exists($pdo, 'salaires')
            || !rh_column_exists($pdo, 'salaires', 'mois_reference')
            || !rh_column_exists($pdo, 'salaires', 'mois_cloture')) {
            $cache[$cle] = false;
            return false;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM salaires WHERE mois_reference LIKE ? AND mois_cloture IS NOT NULL LIMIT 1");
        $stmt->execute([$ym . '-%']);
        $cache[$cle] = (bool)$stmt->fetchColumn();
        return (bool)$cache[$cle];
    }
}
if (!function_exists('rh_bank_table_name')) {
    function rh_bank_table_name(): string
    {
        return 'rh_coordonnees_bancaires';
    }
}

if (!function_exists('rh_bank_hist_table_name')) {
    function rh_bank_hist_table_name(): string
    {
        return 'rh_coordonnees_bancaires_hist';
    }
}

if (!function_exists('rh_bank_allowed_fields')) {
    function rh_bank_allowed_fields(): array
    {
        return ['iban', 'bic'];
    }
}

if (!function_exists('rh_bank_normalize')) {
    function rh_bank_normalize(string $field, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $val = is_string($value) ? trim($value) : (string)$value;
        if ($val === '') {
            return null;
        }
        if ($field === 'iban' || $field === 'bic') {
            $val = strtoupper(str_replace(' ', '', $val));
        }
        return $val;
    }
}

if (!function_exists('rh_bank_get')) {
    function rh_bank_get(PDO $pdo, int $userId): array
    {
        $table = rh_bank_table_name();
        if ($userId <= 0 || !rh_table_exists($pdo, $table)) {
            return [];
        }
        $stmt = $pdo->prepare("SELECT iban, bic FROM {$table} WHERE id_user = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('rh_bank_save')) {
    function rh_bank_save(PDO $pdo, int $userId, int $actorId, array $incoming): bool
    {
        $table = rh_bank_table_name();
        if ($userId <= 0 || !rh_table_exists($pdo, $table)) {
            return false;
        }

        $allowed = array_flip(rh_bank_allowed_fields());
        $data = [];
        foreach ($incoming as $field => $value) {
            if (!isset($allowed[$field])) {
                continue;
            }
            $data[$field] = rh_bank_normalize($field, $value);
        }
        if (!$data) {
            return false;
        }

        $histTable = rh_bank_hist_table_name();
        $actor = $actorId > 0 ? $actorId : null;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, iban, bic FROM {$table} WHERE id_user = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $changes = [];
            foreach ($data as $field => $newVal) {
                $oldVal = $row[$field] ?? null;
                if ($oldVal !== $newVal) {
                    $changes[$field] = [$oldVal, $newVal];
                }
            }

            if (!$changes) {
                $pdo->commit();
                return true;
            }

            if (!$row) {
                $cols = ['id_user', 'created_by', 'updated_by'];
                $vals = [$userId, $actor, $actor];
                foreach ($data as $field => $val) {
                    $cols[] = $field;
                    $vals[] = $val;
                }
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$placeholders})")
                    ->execute($vals);
                $ribId = (int)$pdo->lastInsertId();
                $action = 'create';
            } else {
                $sets = [];
                $vals = [];
                foreach ($data as $field => $val) {
                    $sets[] = "{$field} = ?";
                    $vals[] = $val;
                }
                $sets[] = "updated_by = ?";
                $vals[] = $actor;
                $vals[] = (int)$row['id'];
                $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?")
                    ->execute($vals);
                $ribId = (int)$row['id'];
                $action = 'update';
            }

            if (rh_table_exists($pdo, $histTable)) {
                $changedFields = array_keys($changes);
                $oldValues = [];
                $newValues = [];
                foreach ($changes as $field => [$oldVal, $newVal]) {
                    $oldValues[$field] = $oldVal;
                    $newValues[$field] = $newVal;
                }
                $pdo->prepare("\n                    INSERT INTO {$histTable}\n                        (rib_id, id_user, action, changed_by, changed_at, changed_fields, old_values, new_values)\n                    VALUES\n                        (?, ?, ?, ?, NOW(), ?, ?, ?)\n                ")->execute([
                    $ribId,
                    $userId,
                    $action,
                    $actor,
                    json_encode($changedFields, JSON_UNESCAPED_UNICODE),
                    json_encode($oldValues, JSON_UNESCAPED_UNICODE),
                    json_encode($newValues, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * ── Indemnités kilométriques : gestion multi-collaborateur ────────────────────
 * Périmètre de délégation IK :
 *   - super admin (rôle 1) : n'importe quel collaborateur de sa société ;
 *   - manager    (rôle 2) : uniquement les collaborateurs de SON agence ;
 *   - user       (rôle 3) : uniquement lui-même.
 */
if (!function_exists('rh_ik_can_manage')) {
    function rh_ik_can_manage(PDO $pdo, int $targetUserId): bool
    {
        $cur = (int) current_user_id();
        if ($targetUserId <= 0 || $targetUserId === $cur) { return true; }
        $role = (int) current_role_id();
        if ($role === 1) { return true; }
        if ($role === 2) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND id_agence=? AND actif=1");
            $st->execute([$targetUserId, (int) current_agence_id()]);
            return (int) $st->fetchColumn() > 0;
        }
        return false;
    }
}

if (!function_exists('rh_ik_resolve_target')) {
    /** Résout l'utilisateur cible (GET/POST id_user) avec contrôle de périmètre ; retombe sur soi. */
    function rh_ik_resolve_target(PDO $pdo, int $requested): int
    {
        $cur = (int) current_user_id();
        if ($requested > 0 && $requested !== $cur && rh_ik_can_manage($pdo, $requested)) {
            return $requested;
        }
        return $cur;
    }
}

if (!function_exists('rh_ik_collab_list')) {
    /** Liste des collaborateurs sélectionnables selon le rôle (vide = pas de sélecteur). */
    function rh_ik_collab_list(PDO $pdo): array
    {
        $role = (int) current_role_id();
        if ($role === 1) {
            $soc = (int) ($_SESSION['id_societe'] ?? 0);
            if ($soc > 0) {
                $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE actif=1 AND id_societe=? ORDER BY nom, prenom");
                $st->execute([$soc]);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            }
            return $pdo->query("SELECT id, prenom, nom FROM users WHERE actif=1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($role === 2) {
            $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE actif=1 AND id_agence=? ORDER BY nom, prenom");
            $st->execute([(int) current_agence_id()]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return [];
    }
}
