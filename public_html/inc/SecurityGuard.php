<?php
/**
 * inc/SecurityGuard.php — Couche de sécurité centralisée multi-tenant.
 *
 * Fournit des helpers statiques pour le contrôle d'accès tenant-aware.
 * S'appuie sur inc/auth.php (déjà chargé via bootstrap) pour les fonctions
 * de session (current_user_id, current_role_id, current_societe_id, etc.)
 * sans les redéfinir.
 *
 * OBJECTIF : un seul endroit à appeler dans chaque endpoint API pour
 * sécuriser l'accès aux données. Plus besoin de copier-coller des filtres
 * SQL manuels dans chaque fichier.
 *
 * USAGE TYPIQUE :
 *   // En début d'endpoint API :
 *   SecurityGuard::requireAuth();
 *
 *   // Avant toute opération sur des données d'un autre user :
 *   SecurityGuard::requireAccessToUser($targetUserId);
 *
 *   // Dans une requête SQL :
 *   $sql = "SELECT * FROM rh_documents WHERE id = ?" . SecurityGuard::sqlAnd('id_user');
 *   $stmt = $pdo->prepare($sql);
 *   $stmt->execute(array_merge([$docId], SecurityGuard::sqlParams('id_user')));
 *
 *   // Ou plus simplement :
 *   [$where, $params] = SecurityGuard::tenantFilter('id_user', 'id_societe');
 *   $stmt = $pdo->prepare("SELECT * FROM table WHERE actif = 1 {$where}");
 *   $stmt->execute($params);
 */
declare(strict_types=1);

class SecurityGuard
{
    /* ══════════════════════════════════════════════════════════════
       IDENTITÉ DU USER COURANT
       ══════════════════════════════════════════════════════════════ */

    /** ID du user connecté (0 si pas de session). */
    public static function userId(): int
    {
        return function_exists('current_user_id') ? current_user_id() : (int)($_SESSION['user_id'] ?? 0);
    }

    /** ID du rôle (1=Admin, 2=Manager, 3=Collaborateur, 7=Super Admin). */
    public static function roleId(): int
    {
        return function_exists('current_role_id') ? current_role_id() : (int)($_SESSION['id_role'] ?? 0);
    }

    /** ID de la société (tenant de niveau 1). */
    public static function tenantId(): int
    {
        return function_exists('current_societe_id')
            ? (int)(current_societe_id() ?? 0)
            : (int)($_SESSION['id_societe'] ?? 0);
    }

    /** ID de l'agence (tenant de niveau 2). */
    public static function agenceId(): int
    {
        return function_exists('current_agence_id')
            ? (int)(current_agence_id() ?? 0)
            : (int)($_SESSION['id_agence'] ?? 0);
    }

    /** True si le user est admin (role 1) ou super admin (role 7 ou flag). */
    public static function isAdmin(): bool
    {
        $role = self::roleId();
        return $role === 1 || $role === 7
            || (function_exists('is_super_admin') && is_super_admin());
    }

    /** True si le user est manager ou admin. */
    public static function isManagerOrAdmin(): bool
    {
        return in_array(self::roleId(), [1, 2, 7], true)
            || (function_exists('is_super_admin') && is_super_admin());
    }

    /* ══════════════════════════════════════════════════════════════
       CONTRÔLE D'ACCÈS — ASSERTIONS
       ══════════════════════════════════════════════════════════════ */

    /**
     * Vérifie que le user est connecté. Sinon → 401 JSON + exit.
     */
    public static function requireAuth(): void
    {
        if (function_exists('require_login')) {
            require_login();
        } elseif (self::userId() <= 0) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Non authentifié']);
            exit;
        }
    }

    /**
     * Vérifie que le user courant peut accéder aux données d'un autre user.
     *
     * Règles :
     *   - Soi-même → toujours OK
     *   - Admin/Super Admin → accès à tout
     *   - Manager → accès aux users de son agence (si PDO fourni)
     *   - Collaborateur → accès uniquement à ses propres données
     */
    public static function requireAccessToUser(int $targetUserId, ?PDO $pdo = null): void
    {
        if ($targetUserId === self::userId()) return;
        if (self::isAdmin()) return;

        if (self::roleId() === 2 && $pdo !== null) {
            $agenceId = self::agenceId();
            if ($agenceId > 0) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ? LIMIT 1");
                $stmt->execute([$targetUserId, $agenceId]);
                if ($stmt->fetchColumn()) return;
            }
        }

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }

    /**
     * Vérifie que le user peut accéder aux données d'une société donnée.
     */
    public static function requireAccessToSociete(int $societeId): void
    {
        if (self::isAdmin()) return;
        if (self::tenantId() === $societeId) return;

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Accès refusé (société)']);
        exit;
    }

    /* ══════════════════════════════════════════════════════════════
       SQL HELPERS — Filtrage tenant dans les requêtes
       ══════════════════════════════════════════════════════════════ */

    /**
     * Retourne " AND col = ?" pour les non-admins, "" pour les admins.
     *
     * Usage :
     *   $sql = "SELECT * FROM rh_documents WHERE id = ?" . SecurityGuard::sqlAnd('id_user');
     *   $stmt->execute(array_merge([$docId], SecurityGuard::sqlParams('id_user')));
     */
    public static function sqlAnd(string $column): string
    {
        if (self::isAdmin()) return '';
        return " AND `{$column}` = ?";
    }

    /**
     * Retourne les paramètres PDO correspondant à sqlAnd().
     * Pour les admins, retourne un tableau vide.
     */
    public static function sqlParams(string $column): array
    {
        if (self::isAdmin()) return [];

        return match ($column) {
            'id_user'    => [self::userId()],
            'id_societe' => [self::tenantId()],
            'id_agence'  => [self::agenceId()],
            default      => [self::userId()],
        };
    }

    /**
     * Retourne [$whereSuffix, $params] prêt à injecter.
     * Filtre sur TOUTES les colonnes passées en paramètre.
     *
     * Usage :
     *   [$where, $params] = SecurityGuard::tenantFilter('id_user', 'id_societe');
     *   $stmt = $pdo->prepare("SELECT * FROM table WHERE actif = 1 {$where}");
     *   $stmt->execute($params);
     */
    public static function tenantFilter(string ...$columns): array
    {
        if (self::isAdmin()) return ['', []];

        $sql    = '';
        $params = [];
        foreach ($columns as $col) {
            $sql .= self::sqlAnd($col);
            $params = array_merge($params, self::sqlParams($col));
        }
        return [$sql, $params];
    }

    /** Shortcut : filtre par id_user. */
    public static function userFilter(): array
    {
        return self::tenantFilter('id_user');
    }

    /** Shortcut : filtre par id_societe. */
    public static function societeFilter(): array
    {
        return self::tenantFilter('id_societe');
    }
}
