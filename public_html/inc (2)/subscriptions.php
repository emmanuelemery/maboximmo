<?php
declare(strict_types=1);

/**
 * Subscription helpers for MaBoxImmo
 */

/**
 * Get all available subscription plans
 */
function getSubscriptionPlans($pdo) {
    try {
        // Create tables if they don't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS `subscription_plans` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `nom` VARCHAR(100) NOT NULL UNIQUE,
          `slug` VARCHAR(50) NOT NULL UNIQUE,
          `description` TEXT,
          `icone` VARCHAR(50) DEFAULT '📦',
          `couleur` VARCHAR(7) DEFAULT '#4878a6',
          `actif` TINYINT DEFAULT 1,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_subscriptions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `id_user` INT NOT NULL,
          `id_plan` INT NOT NULL,
          `date_activation` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `date_expiration` DATETIME,
          `statut` ENUM('actif', 'suspendu', 'expiré') DEFAULT 'actif',
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `unique_user_plan` (`id_user`, `id_plan`),
          KEY `idx_user_id` (`id_user`),
          KEY `idx_plan_id` (`id_plan`),
          KEY `idx_statut` (`statut`),
          FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`id_plan`) REFERENCES `subscription_plans`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Insert default plans if empty
        $check = $pdo->query("SELECT COUNT(*) as cnt FROM subscription_plans");
        $count = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        if ($count === 0) {
            $pdo->exec("INSERT INTO `subscription_plans` (`nom`, `slug`, `description`, `icone`, `couleur`) VALUES
            ('Syndic', 'syndic', 'Gestion des syndics et immeubles', '🏢', '#4878a6'),
            ('RH', 'rh', 'Gestion des ressources humaines', '👥', '#4a6038'),
            ('Agency', 'agency', 'Gestion immobilière et agences', '🏠', '#ffd479'),
            ('Propriétaire Pro', 'proprietaire', 'Portail propriétaire avancé', '👤', '#ff9aab')");
        }

        $stmt = $pdo->query("SELECT * FROM subscription_plans WHERE actif = 1 ORDER BY nom");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting plans: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's active subscriptions
 */
function getUserSubscriptions($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT sp.*, us.statut, us.date_activation, us.date_expiration
            FROM user_subscriptions us
            JOIN subscription_plans sp ON us.id_plan = sp.id
            WHERE us.id_user = ? AND us.statut = 'actif'
            AND (us.date_expiration IS NULL OR us.date_expiration > NOW())
            ORDER BY sp.nom
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting user subscriptions: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if user has access to a service
 */
function hasSubscription($pdo, $userId, $slug) {
    try {
        $stmt = $pdo->prepare("
            SELECT us.id FROM user_subscriptions us
            JOIN subscription_plans sp ON us.id_plan = sp.id
            WHERE us.id_user = ?
            AND sp.slug = ?
            AND us.statut = 'actif'
            AND (us.date_expiration IS NULL OR us.date_expiration > NOW())
            LIMIT 1
        ");
        $stmt->execute([$userId, $slug]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        error_log("Error checking subscription: " . $e->getMessage());
        return false;
    }
}

/**
 * Require subscription to access a page
 */
function requireSubscription($pdo, $userId, $slug, $redirectTo = '/landing.php') {
    if (!hasSubscription($pdo, $userId, $slug)) {
        http_response_code(403);
        header("Location: " . htmlspecialchars($redirectTo));
        exit('Accès non autorisé. Vous n\'êtes pas abonné à ce service.');
    }
}

/**
 * Add subscription to user
 */
function grantSubscription($pdo, $userId, $slug) {
    try {
        // Get plan ID by slug
        $stmt = $pdo->prepare("SELECT id FROM subscription_plans WHERE slug = ?");
        $stmt->execute([$slug]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            return false;
        }

        // Insert or update subscription
        $stmt = $pdo->prepare("
            INSERT INTO user_subscriptions (id_user, id_plan, statut)
            VALUES (?, ?, 'actif')
            ON DUPLICATE KEY UPDATE statut = 'actif', updated_at = NOW()
        ");
        return $stmt->execute([$userId, $plan['id']]);
    } catch (Exception $e) {
        error_log("Error granting subscription: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke subscription from user
 */
function revokeSubscription($pdo, $userId, $slug) {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_subscriptions us
            JOIN subscription_plans sp ON us.id_plan = sp.id
            SET us.statut = 'suspendu'
            WHERE us.id_user = ? AND sp.slug = ?
        ");
        return $stmt->execute([$userId, $slug]);
    } catch (Exception $e) {
        error_log("Error revoking subscription: " . $e->getMessage());
        return false;
    }
}
?>
