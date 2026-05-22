<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

$_sbBase = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/';

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager    = in_array($roleId, [1, 2], true);
$isAdminOrSup = function_exists('is_admin_or_super_admin') ? is_admin_or_super_admin() : ($roleId === 1 || $roleId === 7);

if (!function_exists('sb_active')) {
    function sb_active(string $page): string {
        $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
        return $cur === $page ? 'active' : '';
    }
}

$homeRh     = $_sbBase . ($isManager ? 'rh_dashboard.php' : 'rh_dashboard_user.php');
$homeAgency = $_sbBase . ($isManager ? 'agency_dashboard.php' : 'agency_dashboard_user.php');
$homeFlux   = $_sbBase . ($isManager ? 'fluxbox_manager.php' : 'fluxbox.php');
$homeNet    = $_sbBase . ($isManager ? 'dashboard_net_manager.php' : 'dashboard_net.php');

$dashUser    = $_sbBase . 'fluxbox.php';
$dashManager = $_sbBase . 'fluxbox_manager.php';
$dashAdmin   = $_sbBase . 'super_admin_dashboard.php';
?>
<?php
$__sbCss = function_exists('asset_url') ? asset_url('/css/sidebar.css') : '/css/sidebar.css';
$__sbVer = @filemtime(__DIR__ . '/../css/sidebar.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sbCss) ?>?v=<?= (int)$__sbVer ?>">

<aside class="mbi-sidebar">
    <a class="sb-brand" href="<?= htmlspecialchars($homeFlux) ?>">
        <div class="sb-brand-logo">🃏</div>
        <div class="sb-brand-text">
            <strong>FluxBox</strong>
            <span><?= $isManager ? 'Manager' : 'Utilisateur' ?></span>
        </div>
    </a>

    <div class="sb-group nav">
        <div class="sb-section">Navigation</div>
        <ul class="sb-nav">
            <li><a href="<?= htmlspecialchars($homeRh) ?>" class="<?= sb_active(basename($homeRh)) ?>"><span class="sb-icon">👥</span><span class="sb-label">RH</span></a></li>
            <li><a href="<?= htmlspecialchars($homeAgency) ?>" class="<?= sb_active(basename($homeAgency)) ?>"><span class="sb-icon">🏠</span><span class="sb-label">Agency</span></a></li>
            <li><a href="<?= htmlspecialchars($homeFlux) ?>" class="<?= sb_active(basename($homeFlux)) ?>"><span class="sb-icon">🃏</span><span class="sb-label">FluxBox</span></a></li>
            <li><a href="<?= htmlspecialchars($homeNet) ?>" class="<?= sb_active(basename($homeNet)) ?>"><span class="sb-icon">🌐</span><span class="sb-label">Ma Box Net</span></a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <div class="sb-group dash">
        <div class="sb-section">Dashboards</div>
        <ul class="sb-nav">
            <li><a href="<?= htmlspecialchars($dashUser) ?>" class="<?= sb_active('fluxbox.php') ?>"><span class="sb-icon">👤</span><span class="sb-label">Dashboard User</span></a></li>
            <li><a href="<?= htmlspecialchars($dashManager) ?>" class="<?= sb_active('fluxbox_manager.php') ?>"><span class="sb-icon">🧭</span><span class="sb-label">Dashboard Manager</span></a></li>
            <?php if ($isAdminOrSup): ?>
                <li><a href="<?= htmlspecialchars($dashAdmin) ?>" class="<?= sb_active('super_admin_dashboard.php') ?>"><span class="sb-icon">🧰</span><span class="sb-label">Dashboard Admin</span></a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <div class="sb-group account">
        <ul class="sb-nav">
            <li><a href="<?= $_sbBase ?>logout.php"><span class="sb-icon">🚪</span><span class="sb-label">Déconnexion</span></a></li>
        </ul>
    </div>
</aside>
