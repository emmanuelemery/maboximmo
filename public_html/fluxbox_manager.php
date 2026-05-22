<?php
declare(strict_types=1);

/**
 * fluxbox_manager.php — Dashboard "Manager".
 * Pour l'instant, réutilise la home FluxBox (mêmes actions) et permet un lien dédié côté sidebar.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

header('Location: fluxbox.php');
exit;

