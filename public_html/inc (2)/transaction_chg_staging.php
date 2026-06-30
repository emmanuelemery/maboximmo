<?php
// inc/transaction_chg_staging.php — Helpers staging chargement par lot
declare(strict_types=1);

if (!function_exists('tr_staging_ensure_table')) {
    /**
     * Auto-création de la table staging si absente.
     * Évite de dépendre d'une migration manuelle préalable.
     * Cached (static) pour ne s'exécuter qu'une fois par requête.
     */
    function tr_staging_ensure_table(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `transaction_chargement_staging` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `stored_path` VARCHAR(500) NOT NULL,
                `mime_type` VARCHAR(100) NULL,
                `size_bytes` BIGINT UNSIGNED NULL,
                `metadata` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_staging_user` (`user_id`),
                INDEX `idx_staging_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $checked = true;
        } catch (Throwable $e) {
            error_log('[tr_staging_ensure_table] ' . $e->getMessage());
        }
    }
}

if (!function_exists('tr_staging_dir')) {
    function tr_staging_dir(int $userId): string {
        $base = __DIR__ . '/../uploads/transaction_staging/' . $userId;
        if (!is_dir($base)) @mkdir($base, 0755, true);
        return $base;
    }
}

if (!function_exists('tr_staging_safe_name')) {
    function tr_staging_safe_name(string $original): string {
        $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $base = preg_replace('~[^a-zA-Z0-9._-]+~', '_', pathinfo($original, PATHINFO_FILENAME));
        $base = substr((string)$base, 0, 100);
        return $base . ($ext ? '.' . $ext : '');
    }
}

if (!function_exists('tr_staging_check_owner')) {
    function tr_staging_check_owner(PDO $pdo, int $stagingId, int $userId): ?array {
        $st = $pdo->prepare('SELECT * FROM transaction_chargement_staging WHERE id = ? LIMIT 1');
        $st->execute([$stagingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ((int)$r['user_id'] !== $userId) {
            // Super admin a accès aux stagings de tout le monde
            $roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
            if ($roleId !== 1) return null;
        }
        return $r;
    }
}
