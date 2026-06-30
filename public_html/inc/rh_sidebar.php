<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

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

$homeRh     = $_sbBase . 'rh_dashboard_user.php'; // « RH » = dashboard personnel, pour TOUS les rôles
$homeAgency = $_sbBase . ($isManager ? 'agency_dashboard.php' : 'agency_dashboard_user.php');
$homeFlux   = $_sbBase . ($isManager ? 'fluxbox_manager.php' : 'fluxbox.php');
$homeNet    = $_sbBase . ($isManager ? 'dashboard_net_manager.php' : 'dashboard_net.php');

$dashAdmin   = $_sbBase . 'super_admin_dashboard.php';
?>
<?php
$__sbCss = function_exists('asset_url') ? asset_url('/css/sidebar.css') : '/css/sidebar.css';
$__sbVer = @filemtime(__DIR__ . '/../css/sidebar.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sbCss) ?>?v=<?= (int)$__sbVer ?>">

<aside class="mbi-sidebar">
    <a class="sb-brand" href="<?= htmlspecialchars($homeRh) ?>">
        <div class="sb-brand-logo">👥</div>
        <div class="sb-brand-text">
            <strong>Ma Box RH</strong>
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
            <li><a href="<?= $_sbBase ?>bailleur_dashboard.php" class="<?= sb_active('bailleur_dashboard.php') ?>"><span class="sb-icon">🏦</span><span class="sb-label">Ma Box Bailleur</span></a></li>
            <li><a href="<?= $_sbBase ?>transaction_index.php" class="<?= sb_active('transaction_index.php') ?: sb_active('transaction_chargement.php') ?>"><span class="sb-icon">🎯</span><span class="sb-label">Transactions</span></a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <div class="sb-group shortcuts">
        <div class="sb-section">Raccourcis</div>
        <ul class="sb-nav">
            <li><a href="<?= $_sbBase ?>rh_profil_user.php" class="<?= sb_active('rh_profil_user.php') ?: sb_active('rh_profil.php') ?>"><span class="sb-icon">👤</span><span class="sb-label">Mon profil</span></a></li>
            <li><a href="<?= $_sbBase ?>rh_salaires_user_list.php" class="<?= sb_active('rh_salaires_user_list.php') ?>"><span class="sb-icon">💶</span><span class="sb-label">Mes salaires</span></a></li>
            <li><a href="<?= $_sbBase ?>rh_documents_user.php" class="<?= sb_active('rh_documents_user.php') ?>"><span class="sb-icon">📁</span><span class="sb-label">Mes documents</span></a></li>
            <li><a href="<?= $_sbBase ?>rh_indemnite_km.php" class="<?= sb_active('rh_indemnite_km.php') ?>"><span class="sb-icon">🚗</span><span class="sb-label">Mes indemnités KM</span></a></li>
            <li><a href="<?= $_sbBase ?>rh_conges_historiq_user.php" class="<?= sb_active('rh_conges_historiq_user.php') ?>"><span class="sb-icon">🏖️</span><span class="sb-label">Mes congés</span></a></li>
            <li><a href="<?= $_sbBase ?>rh_entretien_vue_collaborateur.php" class="<?= sb_active('rh_entretien_vue_collaborateur.php') ?>"><span class="sb-icon">🤝</span><span class="sb-label">Mes entretiens</span></a></li>
        </ul>
    </div>

    <?php
    // Dashboards de pilotage selon le rôle (le dashboard perso est déjà « RH » en navigation).
    //   Manager (2)        → Dashboard Manager (son équipe / son agence)
    //   Admin (8)          → + Dashboard Admin (RH complet, SA seule société)
    //   Super Admin (1, 7) → + Dashboard Super Admin (toutes les sociétés)
    $__rhSuper   = in_array($roleId, [1, 7], true);  // toutes sociétés
    $__rhAdmin   = ($roleId === 8);                    // admin une société
    $__rhManager = ($roleId === 2);
    if ($__rhSuper || $__rhAdmin || $__rhManager):
    ?>
    <div class="sb-divider"></div>

    <div class="sb-group dash">
        <div class="sb-section">Dashboards</div>
        <ul class="sb-nav">
            <?php if ($__rhManager || $__rhAdmin || $__rhSuper): ?>
                <li><a href="<?= $_sbBase ?>rh_dashboard_manager.php" class="<?= sb_active('rh_dashboard_manager.php') ?>"><span class="sb-icon">👔</span><span class="sb-label">Manager</span></a></li>
            <?php endif; ?>
            <?php if ($__rhAdmin || $__rhSuper): ?>
                <li><a href="<?= $_sbBase ?>rh_dashboard_admin.php" class="<?= sb_active('rh_dashboard_admin.php') ?>"><span class="sb-icon">🧰</span><span class="sb-label">Admin</span></a></li>
            <?php endif; ?>
            <?php if ($__rhSuper): ?>
                <li><a href="<?= $_sbBase ?>rh_dashboard_admin.php" class="<?= sb_active('rh_dashboard_admin.php') ?>"><span class="sb-icon">🛡️</span><span class="sb-label">Super Admin · toutes sociétés</span></a></li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="sb-divider"></div>

    <div class="sb-group account">
        <ul class="sb-nav">
            <li><a href="<?= $_sbBase ?>logout.php"><span class="sb-icon">🚪</span><span class="sb-label">Déconnexion</span></a></li>
        </ul>
    </div>
</aside>
