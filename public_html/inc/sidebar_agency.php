<?php
declare(strict_types=1);
require_once __DIR__ . '/roles_services.php';

$roleId        = function_exists('current_role_id')  ? current_role_id()  : 0;
$sidebarUserId = function_exists('current_user_id')  ? current_user_id()  : 0;
$isSuperAdmin  = function_exists('is_super_admin')   ? is_super_admin()   : false;
$isManager     = $roleId <= 2 && $roleId > 0;
$isAdmin       = $roleId === 1;
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

$rhDashboard = 'rh_dashboard.php';

$navServices = [
    'net' => [
        'label' => 'Ma Box Net',
        'icon'  => '🌐',
        'url'   => './dashboard_net.php',
        'page'  => 'dashboard_net.php'
    ],
    'rh' => [
        'label' => 'Ma Box RH',
        'icon'  => '👥',
        'url'   => './' . $rhDashboard,
        'page'  => $rhDashboard
    ],
    'agency' => [
        'label' => 'Ma Box Agency',
        'icon'  => '🏠',
        'url'   => './agency_dashboard.php',
        'page'  => 'agency_dashboard.php'
    ],
];

if (!function_exists('sbRenderServiceNav')) {
function sbRenderServiceNav(string $serviceKey, array $cfg): void {
    global $currentPage, $roleId;
    $hasAccess = function_exists('hasServiceAccess') ? hasServiceAccess((int)$roleId, $serviceKey) : false;
    $classes = [];
    if ($hasAccess && $currentPage === $cfg['page']) {
        $classes[] = 'active';
    }
    if (!$hasAccess) {
        $classes[] = 'sb-locked';
    }
    $classAttr = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
    $attrs = $hasAccess
        ? ''
        : ' data-locked="1" data-service-name="' . htmlspecialchars((string)$cfg['label'], ENT_QUOTES, 'UTF-8') . '" data-discover-url="./landing.php" aria-disabled="true"';
    echo '<li><a href="' . htmlspecialchars((string)$cfg['url'], ENT_QUOTES, 'UTF-8') . '"' . $classAttr . $attrs . '><span class="sb-icon">' . htmlspecialchars((string)$cfg['icon'], ENT_QUOTES, 'UTF-8') . '</span><span class="sb-label">' . htmlspecialchars((string)$cfg['label'], ENT_QUOTES, 'UTF-8') . '</span></a></li>';
}
}
?>
<link rel="stylesheet" href="css/sidebar.css">

<aside class="mbi-sidebar">

    <!-- ── Brand ── -->
    <a class="sb-brand" href="./agency_dashboard.php">
        <div class="sb-brand-logo">🏠</div>
        <div class="sb-brand-text">
            <strong>Ma Box Agency</strong>
            <span><?= $isAdmin ? 'Administrateur' : ($roleId===2 ? 'Manager' : 'Collaborateur') ?></span>
        </div>
    </a>

    <?php if (in_array($roleId, [1, 7], true)):
        $allSoc = $pdo->query("SELECT id, nom FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
        $currentSocId = (int)($_SESSION['id_societe'] ?? 0);
    ?>
    <div style="padding:4px 12px 8px;margin-bottom:4px;">
        <select onchange="if(this.value)fetch('api/switch_societe.php?id_societe='+this.value).then(()=>location.reload())" style="width:100%;padding:5px 8px;border-radius:6px;border:1px solid #d4d7de;font-size:11px;background:#f8f7f5;">
            <?php foreach ($allSoc as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] === $currentSocId ? 'selected' : '' ?>><?= htmlspecialchars($s['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════
         NAVIGATION
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section<?= sbActiveSection(['agency_dashboard.php','dashboard_net.php','rh_dashboard.php']) ? ' section-active' : '' ?>">
            Navigation
        </div>
        <ul class="sb-nav">
            <?php foreach ($navServices as $key => $cfg): ?>
                <?php sbRenderServiceNav($key, $cfg); ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         AGENCY
    ═══════════════════════════ -->
    <div class="sb-group agency">
        <div class="sb-section<?= sbActiveSection(['bien_liste.php','agency_proprietaires.php','agency_proprietaire_fiche.php','agency_immeubles.php','agency_immeuble_form.php','agency_immeuble_fiche.php','agency_reunions.php','agency_reunion_form.php','agency_reunion_detail.php','agency_reunion_tenir.php','agency_taches.php','agency_tache_detail.php','agency_registres.php','agency_registre_form.php','agency_registre_fiche.php','agency_factures.php','agency_facture_form.php','agency_dashboard_diffusion.php']) ? ' section-active' : '' ?>">
            Agency
        </div>
        <ul class="sb-nav">
            <li><a href="./bien_liste.php" class="<?= sbActive('bien_liste.php') ?>">
                <span class="sb-icon">🏘️</span><span class="sb-label">Mes biens</span>
            </a></li>
            <li><a href="./agency_proprietaires.php" class="<?= sbActive('agency_proprietaires.php') ?><?= sbActive('agency_proprietaire_fiche.php') ?>">
                <span class="sb-icon">👥</span><span class="sb-label">Propriétaires</span>
            </a></li>
            <li><a href="./agency_dashboard_diffusion.php" class="<?= sbActive('agency_dashboard_diffusion.php') ?>">
                <span class="sb-icon">📡</span><span class="sb-label">Diffusion</span>
            </a></li>
            <li><a href="./agency_immeubles.php" class="<?= sbActive('agency_immeubles.php') ?>">
                <span class="sb-icon">🏢</span><span class="sb-label">Immeubles</span>
            </a></li>
            <li><a href="./agency_reunions.php" class="<?= sbActive('agency_reunions.php') ?>">
                <span class="sb-icon">📅</span><span class="sb-label">Réunions</span>
            </a></li>
            <li><a href="./agency_taches.php" class="<?= sbActive('agency_taches.php') ?>">
                <span class="sb-icon">✅</span><span class="sb-label">Tâches</span>
            </a></li>
            <li><a href="./agency_registres.php" class="<?= sbActive('agency_registres.php') ?>">
                <span class="sb-icon">📂</span><span class="sb-label">Registres</span>
            </a></li>
            <li><a href="./agency_factures.php" class="<?= sbActive('agency_factures.php') ?>">
                <span class="sb-icon">💰</span><span class="sb-label">Factures</span>
            </a></li>
        </ul>
    </div>

    <!-- ═══════════════════════════
         ADMINISTRATION (admin)
    ═══════════════════════════ -->
    <?php if ($isAdmin): ?>
    <div class="sb-divider"></div>
    <div class="sb-group admin">
        <div class="sb-section<?= sbActiveSection(['rh_user.php','rh_user_add.php','agency_honoraires_config.php','admin_documents.php','rh_mails.php','param_types_bien.php','param_chauffage.php','param_dependances.php','param_vues.php']) ? ' section-active' : '' ?>">
            Administration
            <span class="sb-badge adm">ADMIN</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./rh_user.php" class="<?= sbActive('rh_user.php') ?>">
                <span class="sb-icon">👥</span><span class="sb-label">Gérer les utilisateurs</span>
            </a></li>
            <li><a href="./agency_honoraires_config.php" class="<?= sbActive('agency_honoraires_config.php') ?>">
                <span class="sb-icon">💼</span><span class="sb-label">Mes honoraires</span>
            </a></li>
            <li><a href="./admin_documents.php" class="<?= sbActive('admin_documents.php') ?>">
                <span class="sb-icon">📄</span><span class="sb-label">Documents administratifs</span>
            </a></li>
            <li><a href="./rh_mails.php" class="<?= sbActive('rh_mails.php') ?>">
                <span class="sb-icon">📧</span><span class="sb-label">Emails collectifs</span>
            </a></li>
            <li style="margin-top:8px; padding:6px 14px 2px; font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:.08em; text-transform:uppercase;">
                Paramétrage
            </li>
            <li><a href="./admin/param_types_bien.php" class="<?= sbActive('param_types_bien.php') ?>">
                <span class="sb-icon">🏠</span><span class="sb-label">Types de bien</span>
            </a></li>
            <li><a href="./admin/param_chauffage.php" class="<?= sbActive('param_chauffage.php') ?>">
                <span class="sb-icon">🔥</span><span class="sb-label">Chauffage / Énergie</span>
            </a></li>
            <li><a href="./admin/param_dependances.php" class="<?= sbActive('param_dependances.php') ?>">
                <span class="sb-icon">📦</span><span class="sb-label">Dépendances</span>
            </a></li>
            <li><a href="./admin/param_vues.php" class="<?= sbActive('param_vues.php') ?>">
                <span class="sb-icon">🌅</span><span class="sb-label">Vues / Exposition</span>
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════
         SUPER ADMIN
    ═══════════════════════════ -->
    <?php if ($isSuperAdmin): ?>
    <div class="sb-divider"></div>
    <div class="sb-group super">
        <div class="sb-section<?= sbActiveSection(['admin_database.php','admin_migrations.php','admin_honoraires_recalc.php','admin_bareme.php','admin_flux_ubiflow.php','admin_deploy.php','tools_photos_recompress.php','design-system.php','societe_super_admin.php']) ? ' section-active' : '' ?>">
            Super Admin
            <span class="sb-badge sup">SA</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./societe_super_admin.php" class="<?= sbActive('societe_super_admin.php') ?>">
                <span class="sb-icon">🏢</span><span class="sb-label">Toutes les sociétés</span>
            </a></li>
            <li style="margin-top:8px; padding:6px 14px 2px; font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:.08em; text-transform:uppercase;">
                Compliance / Flux
            </li>
            <li><a href="./admin/admin_honoraires_recalc.php" class="<?= sbActive('admin_honoraires_recalc.php') ?>">
                <span class="sb-icon">⚖️</span><span class="sb-label">Rattrapage honoraires</span>
            </a></li>
            <li><a href="./admin/admin_bareme.php" class="<?= sbActive('admin_bareme.php') ?>">
                <span class="sb-icon">📜</span><span class="sb-label">URL barème honoraires</span>
            </a></li>
            <li><a href="./admin/admin_flux_ubiflow.php" class="<?= sbActive('admin_flux_ubiflow.php') ?>">
                <span class="sb-icon">📡</span><span class="sb-label">Flux XML Ubiflow</span>
            </a></li>
            <li style="margin-top:8px; padding:6px 14px 2px; font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:.08em; text-transform:uppercase;">
                Infra / Tech
            </li>
            <li><a href="./admin/admin_database.php" class="<?= sbActive('admin_database.php') ?>">
                <span class="sb-icon">🗄</span><span class="sb-label">Base de données</span>
            </a></li>
            <li><a href="./admin/admin_migrations.php" class="<?= sbActive('admin_migrations.php') ?>">
                <span class="sb-icon">🚀</span><span class="sb-label">Migrations BDD</span>
            </a></li>
            <li><a href="./admin/admin_deploy.php" class="<?= sbActive('admin_deploy.php') ?>">
                <span class="sb-icon">🛠️</span><span class="sb-label">Déploiement FTP</span>
            </a></li>
            <li><a href="./admin/tools_photos_recompress.php" class="<?= sbActive('tools_photos_recompress.php') ?>">
                <span class="sb-icon">🖼️</span><span class="sb-label">Optimiser photos LBC</span>
            </a></li>
            <li><a href="./design-system.php" class="<?= sbActive('design-system.php') ?>">
                <span class="sb-icon">🎨</span><span class="sb-label">Design System</span>
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════
         COMPTE
    ═══════════════════════════ -->
    <div class="sb-divider"></div>
    <div class="sb-group account">
        <ul class="sb-nav">
            <li><a href="./logout.php">
                <span class="sb-icon">🚪</span><span class="sb-label">Déconnexion</span>
            </a></li>
        </ul>
    </div>


</aside>

<script>
document.addEventListener('click', function(e){
    const link = e.target.closest('a[data-locked="1"]');
    if (!link) return;
    e.preventDefault();
    const name = link.getAttribute('data-service-name') || 'ce service';
    const url = link.getAttribute('data-discover-url') || 'landing.php';
    if (confirm(name + " n'est pas activé sur votre compte. Découvrir le service et ses fonctionnalités ?")) {
        window.location.href = url;
    }
});
</script>
