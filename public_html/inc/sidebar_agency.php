<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php'; // app_url()
require_once __DIR__ . '/roles_services.php'; // hasServiceAccess()

// Sidebar minimale : navigation uniquement (RH / Agency / FluxBox / Net)
$_sbBase = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/';

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager    = in_array($roleId, [1, 2], true);
$isAdminOrSup = function_exists('is_admin_or_super_admin') ? is_admin_or_super_admin() : ($roleId === 1 || $roleId === 7);
$currentPage  = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!function_exists('sb_active')) {
    function sb_active(string $page): string {
        $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
        return $cur === $page ? 'active' : '';
    }
}

$homeRh     = $_sbBase . 'rh_dashboard_user.php'; // « RH » = dashboard personnel, pour tous
$homeAgency = $_sbBase . ($isManager ? 'agency_dashboard.php' : 'agency_dashboard_user.php');
$homeFlux   = $_sbBase . ($isManager ? 'fluxbox_manager.php' : 'fluxbox.php');
$homeNet    = $_sbBase . ($isManager ? 'dashboard_net_manager.php' : 'dashboard_net.php');

$dashMetier  = $_sbBase . 'agency_dashboard_metier.php';
$dashBiens   = $_sbBase . 'agency_biens.php';
$linkProps   = $_sbBase . 'agency_proprietaires.php';
$linkLocs    = $_sbBase . 'agency_locataires.php';
$linkImms    = $_sbBase . 'agency_immeubles.php';
$linkTrans   = $_sbBase . 'transaction_index.php';
$dashDiff    = $_sbBase . 'agency_dashboard_diffusion.php';
$dashAdmin   = $_sbBase . 'super_admin_dashboard.php';
$linkPatrimoine = $_sbBase . 'bailleur_patrimoine_actif.php';
$linkCreanciers = $_sbBase . 'creancier_dashboard.php';

// Module CRÉANCIERS (sensible) : visible si super admin OU au moins 1 dossier en ACL.
$canCreanciers = $isAdminOrSup;
if (!$canCreanciers) {
    try {
        $__pdo = $GLOBALS['pdo'] ?? null;
        $__uid = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
        if ($__pdo instanceof PDO && $__uid > 0) {
            $__st = $__pdo->prepare("SELECT 1 FROM creancier_dossier_acces WHERE id_user = ? LIMIT 1");
            $__st->execute([$__uid]);
            $canCreanciers = (bool)$__st->fetchColumn();
        }
    } catch (Throwable $e) { $canCreanciers = false; } // table absente (pré-migration) → non bloquant
}
?>
<?php
$__sbCss = function_exists('asset_url') ? asset_url('/css/sidebar.css') : '/css/sidebar.css';
$__sbVer = @filemtime(__DIR__ . '/../css/sidebar.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sbCss) ?>?v=<?= (int)$__sbVer ?>">

<aside class="mbi-sidebar">
    <a class="sb-brand" href="<?= htmlspecialchars($homeAgency) ?>">
        <div class="sb-brand-logo">🏠</div>
        <div class="sb-brand-text">
            <strong>Ma Box Agency</strong>
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
            <?php if ($isAdminOrSup || (function_exists('hasServiceAccess') && hasServiceAccess($roleId, 'bailleur'))): ?>
            <li><a href="<?= htmlspecialchars($_sbBase . 'bailleur_dashboard.php') ?>" class="<?= sb_active('bailleur_dashboard.php') ?>"><span class="sb-icon">🏦</span><span class="sb-label">Ma Box Bailleur</span></a></li>
            <?php endif; ?>
            <li><a href="<?= htmlspecialchars($linkTrans) ?>" class="<?= sb_active('transaction_index.php') ?: sb_active('transaction_chargement.php') ?>"><span class="sb-icon">🎯</span><span class="sb-label">Transactions</span></a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <div class="sb-group shortcuts">
        <div class="sb-section">Raccourcis</div>
        <ul class="sb-nav">
            <li><a href="<?= htmlspecialchars($dashBiens) ?>" class="<?= sb_active('agency_biens.php') ?>"><span class="sb-icon">🏘️</span><span class="sb-label">Biens</span></a></li>
            <li><a href="<?= htmlspecialchars($linkProps) ?>" class="<?= sb_active('agency_proprietaires.php') ?>"><span class="sb-icon">👥</span><span class="sb-label">Propriétaires</span></a></li>
            <li><a href="<?= htmlspecialchars($linkLocs) ?>" class="<?= sb_active('agency_locataires.php') ?>"><span class="sb-icon">🔑</span><span class="sb-label">Locataires</span></a></li>
            <li><a href="<?= htmlspecialchars($linkImms) ?>" class="<?= sb_active('agency_immeubles.php') ?>"><span class="sb-icon">🏢</span><span class="sb-label">Immeubles</span></a></li>
            <li><a href="<?= htmlspecialchars($dashDiff) ?>" class="<?= sb_active('agency_dashboard_diffusion.php') ?>"><span class="sb-icon">📡</span><span class="sb-label">Diffusion</span></a></li>
            <li><a href="<?= htmlspecialchars($dashMetier) ?>" class="<?= sb_active('agency_dashboard_metier.php') ?>"><span class="sb-icon">🧭</span><span class="sb-label">Métier</span></a></li>
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
