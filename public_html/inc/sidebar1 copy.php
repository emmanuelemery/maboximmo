<?php
declare(strict_types=1);
require_once __DIR__ . '/roles_services.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$roleId       = function_exists('current_role_id') ? current_role_id() : 0;
$userId       = function_exists('current_user_id') ? current_user_id() : 0;
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : false;
$pdo = $GLOBALS['pdo'] ?? null;

// Récupérer les services disponibles
$services = getAvailableServices($roleId);

function isActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'class="active"' : '';
}
?>
<aside class="mbi-sidebar">
    <div class="mbi-sidebar-head">
        <div class="mbi-sidebar-brand">
            <strong>MaBoxImmo</strong>
            <span><?=htmlspecialchars(array_key_first($services) ? ucfirst(array_key_first($services)) : 'Portail')?></span>
        </div>
    </div>

    <div class="mbi-sidebar-section">Principal</div>
    <ul class="mbi-nav">
        <li><a href="landing.php" <?=isActive('landing.php')?>>← Accueil</a></li>
    </ul>

    <!-- SERVICES ACCESSIBLES -->
    <?php foreach ($services as $slug => $config): ?>
    <div class="mbi-sidebar-section"><?=htmlspecialchars($config['nom'])?></div>
    <ul class="mbi-nav">
        <?php
        $pages = getServicePages($roleId, $slug);
        foreach ($pages as $page):
        ?>
        <li><a href="<?=htmlspecialchars($page['url'])?>" <?=isActive($page['url'])?>><?=htmlspecialchars($page['nom'])?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>

    <!-- ADMIN ONLY -->
    <?php if ($roleId === 1): ?>
    <div class="mbi-sidebar-section">Administration</div>
    <ul class="mbi-nav">
        <li><a href="rh_user.php" <?=isActive('rh_user.php')?>>👥 Gestion Utilisateurs</a></li>
        <li><a href="parametres.php" <?=isActive('parametres.php')?>>⚙️ Paramètres</a></li>
    </ul>
    <?php endif; ?>

    <!-- SUPER ADMIN -->
    <?php if ($isSuperAdmin): ?>
    <div class="mbi-sidebar-section">⚙ Super Admin</div>
    <ul class="mbi-nav">
        <li><a href="admin/admin_database.php" <?=isActive('admin_database.php')?>>🗄 Base de données</a></li>
        <li><a href="design-system.php" <?=isActive('design-system.php')?>>🎨 Design System</a></li>
    </ul>
    <?php endif; ?>

    <!-- COMPTE -->
    <div class="mbi-sidebar-section">Compte</div>
    <ul class="mbi-nav">
        <li><a href="parametres.php" <?=isActive('parametres.php')?>>⚙️ Paramètres</a></li>
        <li><a href="logout.php" class="logout">🚪 Déconnexion</a></li>
    </ul>
</aside>
