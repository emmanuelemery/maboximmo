<?php
/**
 * inc/sidebar_ged.php — Sidebar dédiée Module GED
 *
 * Ordre standard :
 *   1) Brand + navigation "haut" (retour modules MBI)
 *   2) Bloc USER       (toujours visible, require_login)
 *   3) Bloc ADMIN      (id_role <= 2, manager + admin)
 *   4) Bloc SUPERADMIN (id_role = 1)
 *
 * Activation : dans la page appelante, AVANT le require agency_layout_top.php :
 *   $layoutSidebar = 'sidebar_ged';
 *
 * Style : réutilise /css/sidebar.css du projet (mêmes classes sb-* que les
 * autres sidebars MaBoxImmo, point de modif global unique).
 */
declare(strict_types=1);

$roleId        = function_exists('current_role_id') ? current_role_id() : 0;
$isManager     = $roleId > 0 && $roleId <= 2;
$isAdmin       = $roleId === 1;
$isSuperAdmin  = function_exists('is_super_admin') ? is_super_admin() : ($roleId === 1);
$currentPage   = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!function_exists('sbActive')) {
    function sbActive(string $page): string {
        global $currentPage;
        return $currentPage === $page ? ' active' : '';
    }
}
if (!function_exists('sbActiveSection')) {
    function sbActiveSection(array $pages): bool {
        global $currentPage;
        return in_array($currentPage, $pages, true);
    }
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars(function_exists('asset_url') ? asset_url('/css/sidebar.css') : '/css/sidebar.css') ?>">

<aside class="mbi-sidebar">

    <!-- ── Brand ── -->
    <a class="sb-brand" href="./ged_dashboard.php">
        <div class="sb-brand-logo">📦</div>
        <div class="sb-brand-text">
            <strong>Ma GED Box</strong>
            <span><?= $isSuperAdmin ? 'Super Admin' : ($isAdmin ? 'Administrateur' : ($isManager ? 'Manager' : 'Utilisateur')) ?></span>
        </div>
    </a>

    <!-- ═══════════════════════════
         1) NAVIGATION HAUT — retour MBI
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section">Navigation</div>
        <ul class="sb-nav">
            <li><a href="./agency_dashboard.php">
                <span class="sb-icon">🏠</span><span class="sb-label">← Ma Box Agency</span>
            </a></li>
            <li><a href="./ged_dashboard.php" class="<?= sbActive('ged_dashboard.php') ?>">
                <span class="sb-icon">📦</span><span class="sb-label">GED Dashboard</span>
            </a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         2) USER — accessible à tous
    ═══════════════════════════ -->
    <div class="sb-group user">
        <div class="sb-section<?= sbActiveSection(['ged_inbox.php']) ? ' section-active' : '' ?>">
            Mes documents
        </div>
        <ul class="sb-nav">
            <li><a href="./modules/ged/ged_inbox.php" class="<?= sbActive('ged_inbox.php') ?>">
                <span class="sb-icon">📨</span><span class="sb-label">Inbox de validation</span>
            </a></li>
            <li><a href="./super_admin_ged_guide.php" target="_blank" rel="noopener">
                <span class="sb-icon">📖</span><span class="sb-label">Guide d'utilisation</span>
            </a></li>
        </ul>
    </div>

    <?php if ($isManager || $isAdmin): ?>
    <!-- ═══════════════════════════
         3) ADMIN — managers + admins
    ═══════════════════════════ -->
    <div class="sb-divider"></div>
    <div class="sb-group admin">
        <div class="sb-section<?= sbActiveSection(['admin_ged_arborescence.php','test_ged_arborescence.php','ged_dashboard.php']) ? ' section-active' : '' ?>">
            Administration
            <span class="sb-badge" style="background:#e0f2fe;color:#0369a1;font-size:9px;padding:1px 5px;border-radius:6px;margin-left:4px">ADM</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./modules/ged/ged_dashboard.php" class="<?= sbActive('ged_dashboard.php') . sbActive('ged_dashboard.php') ?>">
                <span class="sb-icon">📊</span><span class="sb-label">Dashboard analyses (V1)</span>
            </a></li>
            <li><a href="./admin/admin_ged_arborescence.php" class="<?= sbActive('admin_ged_arborescence.php') ?>">
                <span class="sb-icon">🌳</span><span class="sb-label">Arborescence par entité</span>
            </a></li>
            <li><a href="./test_ged_arborescence.php" class="<?= sbActive('test_ged_arborescence.php') ?>">
                <span class="sb-icon">🧪</span><span class="sb-label">Test arborescence</span>
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($isSuperAdmin): ?>
    <!-- ═══════════════════════════
         4) SUPERADMIN — id_role = 1
    ═══════════════════════════ -->
    <div class="sb-divider"></div>
    <div class="sb-group super">
        <div class="sb-section<?= sbActiveSection(['super_admin_ged_niveaux.php','super_admin_ged_import.php','super_admin_coffre_acces.php','super_admin_ged_guide.php']) ? ' section-active' : '' ?>">
            Super Admin
            <span class="sb-badge sup" style="background:#fef3c7;color:#92400e;font-size:9px;padding:1px 5px;border-radius:6px;margin-left:4px">SA</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./super_admin_ged_import.php" class="<?= sbActive('super_admin_ged_import.php') ?>">
                <span class="sb-icon">📥</span><span class="sb-label">Import GED V2</span>
            </a></li>
            <li><a href="./super_admin_ged_niveaux.php" class="<?= sbActive('super_admin_ged_niveaux.php') ?>">
                <span class="sb-icon">🗂️</span><span class="sb-label">Niveaux N1→N6</span>
            </a></li>
            <li><a href="./super_admin_coffre_acces.php" class="<?= sbActive('super_admin_coffre_acces.php') ?>">
                <span class="sb-icon">🔒</span><span class="sb-label">Coffre accès</span>
            </a></li>
            <li><a href="./super_admin_ged_guide.php" target="_blank" rel="noopener" class="<?= sbActive('super_admin_ged_guide.php') ?>">
                <span class="sb-icon">📖</span><span class="sb-label">Guide complet</span>
            </a></li>
            <li><a href="./admin/admin_migrations.php" class="<?= sbActive('admin_migrations.php') ?>">
                <span class="sb-icon">🛠️</span><span class="sb-label">Migrations BDD</span>
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         COMPTE
    ═══════════════════════════ -->
    <div class="sb-group account">
        <ul class="sb-nav">
            <li><a href="./logout.php">
                <span class="sb-icon">🚪</span><span class="sb-label">Déconnexion</span>
            </a></li>
        </ul>
    </div>

</aside>
