<?php
declare(strict_types=1);

/**
 * dashboard_net.php — Redirection legacy.
 * Le dashboard du module Net est désormais net_dashboard.php (nom conforme aux
 * autres pages net_*). Cet alias est conservé pour ne casser aucun lien existant.
 */
require_once __DIR__ . '/inc/bootstrap.php';

$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: ' . app_url('/net_dashboard.php' . $qs), true, 301);
exit;
