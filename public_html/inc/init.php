<?php
/**
 * inc/init.php — Point d'entrée unifié pour les pages agency_*
 * Charge bootstrap + auth, expose $pdo
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

// Alias $pdo accessible directement dans les pages incluantes
if (!isset($GLOBALS['pdo'])) {
    $GLOBALS['pdo'] = db();
}
$pdo = $GLOBALS['pdo'];
