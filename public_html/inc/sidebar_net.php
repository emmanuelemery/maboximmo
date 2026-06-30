<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/net_context.php';

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

$homeRh     = $_sbBase . 'rh_dashboard_user.php'; // « RH » = dashboard personnel, pour tous
$homeAgency = $_sbBase . ($isManager ? 'agency_dashboard.php' : 'agency_dashboard_user.php');
$homeFlux   = $_sbBase . ($isManager ? 'fluxbox_manager.php' : 'fluxbox.php');
$homeNet    = $_sbBase . ($isManager ? 'dashboard_net_manager.php' : 'net_dashboard.php');

$dashUser    = $_sbBase . 'net_dashboard.php';
$dashManager = $_sbBase . 'dashboard_net_manager.php';
$dashAdmin   = $_sbBase . 'super_admin_dashboard.php';

// Lien « voir le site agence » = le VRAI portail agence (mbi_annonces_index.php habillé
// via net_context). En prod il est aussi servi par /regie-emery/lyon-7 etc., mais
// ?net_agence=<id> fonctionne partout (local + prod) → on l'utilise comme lien universel.
// NB : /vitrine/<slug> est une page SEO séparée, ce n'est PAS le site agence.
$_idAgenceSess = (int)($_SESSION['id_agence'] ?? 0);
$_netSiteUrl   = function_exists('net_agence_site_url') ? net_agence_site_url($_idAgenceSess) : '';
?>
<?php
$__sbCss = function_exists('asset_url') ? asset_url('/css/sidebar.css') : '/css/sidebar.css';
$__sbVer = @filemtime(__DIR__ . '/../css/sidebar.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sbCss) ?>?v=<?= (int)$__sbVer ?>">

<aside class="mbi-sidebar">
    <a class="sb-brand" href="<?= htmlspecialchars($homeNet) ?>">
        <div class="sb-brand-logo">🌐</div>
        <div class="sb-brand-text">
            <strong>Ma Box Net</strong>
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
            <li><a href="<?= $_sbBase ?>bailleur_dashboard.php" class="<?= sb_active('bailleur_dashboard.php') ?>"><span class="sb-icon">🏦</span><span class="sb-label">Ma Box Bailleur</span></a></li>
            <?php endif; ?>
            <li><a href="<?= $_sbBase ?>transaction_index.php" class="<?= sb_active('transaction_index.php') ?: sb_active('transaction_chargement.php') ?>"><span class="sb-icon">🎯</span><span class="sb-label">Transactions</span></a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <?php if ($isManager): ?>
    <div class="sb-group vitrines">
        <div class="sb-section">Vitrines SEO</div>
        <ul class="sb-nav">
            <?php if ($_netSiteUrl !== ''): ?>
            <li><a href="<?= htmlspecialchars($_netSiteUrl) ?>" target="_blank" rel="noopener"><span class="sb-icon">🌍</span><span class="sb-label">Voir le site agence ↗</span></a></li>
            <?php endif; ?>
            <li><a href="<?= $_sbBase ?>net_admin_pages.php" class="<?= sb_active('net_admin_pages.php') ?>"><span class="sb-icon">📝</span><span class="sb-label">Textes des pages</span></a></li>
            <li><a href="<?= $_sbBase ?>net_admin_contact.php" class="<?= sb_active('net_admin_contact.php') ?>"><span class="sb-icon">📍</span><span class="sb-label">Coordonnées & horaires</span></a></li>
            <li><a href="<?= $_sbBase ?>net_admin_collaborateurs.php" class="<?= sb_active('net_admin_collaborateurs.php') ?>"><span class="sb-icon">👥</span><span class="sb-label">Collaborateurs</span></a></li>
            <li><a href="<?= $_sbBase ?>agency_honoraires_config.php" class="<?= sb_active('agency_honoraires_config.php') ?>"><span class="sb-icon">💶</span><span class="sb-label">Tarifs &amp; honoraires</span></a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>
    <?php endif; ?>

    <div class="sb-group dash">
        <div class="sb-section">Dashboards</div>
        <ul class="sb-nav">
            <li><a href="<?= htmlspecialchars($dashUser) ?>" class="<?= sb_active('net_dashboard.php') ?>"><span class="sb-icon">👤</span><span class="sb-label">Dashboard User</span></a></li>
            <li><a href="<?= htmlspecialchars($dashManager) ?>" class="<?= sb_active('dashboard_net_manager.php') ?>"><span class="sb-icon">🧭</span><span class="sb-label">Dashboard Manager</span></a></li>
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
