<?php
declare(strict_types=1);

/**
 * dashboard_net_manager.php — Dashboard MaBoxNet (Manager).
 * Réutilise la version User (mêmes actions pour l'instant).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

header('Location: dashboard_net.php');
exit;

