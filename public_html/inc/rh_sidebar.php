<?php
declare(strict_types=1);
require_once __DIR__ . '/roles_services.php';

$roleId        = function_exists('current_role_id')  ? current_role_id()  : 0;
$sidebarUserId = function_exists('current_user_id')  ? current_user_id()  : 0;
$isSuperAdmin  = function_exists('is_super_admin')   ? is_super_admin()   : false;
$isManager     = $roleId <= 2 && $roleId > 0;  // role 1 = admin, role 2 = manager
$isAdmin       = $roleId === 1;
$currentPage   = basename($_SERVER['SCRIPT_NAME'] ?? '');

function sbActive(string $page): string {
    global $currentPage;
    return $currentPage === $page ? ' active' : '';
}
function sbActiveSection(array $pages): bool {
    global $currentPage;
    return in_array($currentPage, $pages, true);
}

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
        'url'   => './rh_dashboard.php',
        'page'  => $rhDashboard
    ],
    'agency' => [
        'label' => 'Ma Box Agency',
        'icon'  => '🏠',
        'url'   => './agency_dashboard.php',
        'page'  => 'agency_dashboard.php'
    ]
];

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

$moisRef    = date('Y-m-01');
$profileUrl = './rh_salaires_detail.php?id_user=' . $sidebarUserId . '&mois_ref=' . urlencode($moisRef);

// Entretiens actifs du collaborateur
$sidebarEntretiensCollab = [];
if ($sidebarUserId > 0 && isset($GLOBALS['pdo'])) {
    try {
        $stmt = $GLOBALS['pdo']->prepare("
            SELECT id, statut, date_planifiee FROM rh_entretiens
            WHERE collaborateur_id = ?
              AND statut IN ('questionnaire_envoye','en_cours','termine','signe')
            ORDER BY date_planifiee DESC LIMIT 3
        ");
        $stmt->execute([$sidebarUserId]);
        $sidebarEntretiensCollab = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignoré */ }
}
?>
<link rel="stylesheet" href="css/sidebar.css">

<aside class="mbi-sidebar">

    <!-- ── Brand ── -->
    <a class="sb-brand" href="./rh_dashboard.php">
        <div class="sb-brand-logo">🏢</div>
        <div class="sb-brand-text">
            <strong>Ma Box RH</strong>
            <span><?= $isAdmin ? 'Administrateur' : ($roleId===2 ? 'Manager' : 'Collaborateur') ?></span>
        </div>
    </a>

    <!-- Sélecteur société retiré 2026-05-06 : le filtre Sté/Agc/Col du
         page-head de rh_documents.php couvre déjà ce besoin (voir
         `.ph-scope` dans le contenu de page). -->

    <!-- ═══════════════════════════
         R1 — NAVIGATION (tous)
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section<?= sbActiveSection(['agency_dashboard.php','dashboard_net.php','rh_dashboard.php']) ? ' section-active' : '' ?>">
            Navigation
        </div>
        <ul class="sb-nav">
            <li><a href="./modules/ged/ged_dashboard.php" class="<?= sbActive('ged_dashboard.php') ?><?= sbActive('ged_inbox.php') ?>">
                <span class="sb-icon">📦</span><span class="sb-label">Ma GED Box</span>
            </a></li>
            <?php foreach ($navServices as $key => $cfg): ?>
                <?php sbRenderServiceNav($key, $cfg); ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         R2 — MES INFORMATIONS (tous)
    ═══════════════════════════ -->
    <div class="sb-group info">
        <div class="sb-section<?= sbActiveSection(['rh_salaires_detail.php','rh_salaires.php','rh_salaires_user_list.php','rh_salaires_user.php','rh_documents.php','rh_indemnite_km.php','rh_conges.php','rh_entretien_auto_eval.php','rh_entretien_vue_collaborateur.php','rh_entretien_questionnaire_collaborateur.php']) ? ' section-active' : '' ?>">
            Mes informations
        </div>
        <ul class="sb-nav">
            <?php if ($sidebarUserId): ?>
            <li><a href="./rh_profil.php" class="<?= sbActive('rh_profil.php') ?>">
                <span class="sb-icon">👤</span><span class="sb-label">Mon profil</span>
            </a></li>
            <li><a href="./rh_salaires_user_list.php" class="<?= sbActive('rh_salaires_user_list.php') ?>">
                <span class="sb-icon">💶</span><span class="sb-label">Mes salaires</span>
            </a></li>
            <li><a href="./rh_documents.php?user_id=<?= $sidebarUserId ?>" class="<?= sbActive('rh_documents.php') ?>">
                <span class="sb-icon">📁</span><span class="sb-label">Mes documents</span>
            </a></li>
            <li><a href="./rh_indemnite_km.php" class="<?= sbActive('rh_indemnite_km.php') ?>">
                <span class="sb-icon">🚗</span><span class="sb-label">Mes indemnités KM</span>
            </a></li>
            <li><a href="./rh_conges.php" class="<?= sbActive('rh_conges.php') ?>">
                <span class="sb-icon">🏖️</span><span class="sb-label">Mes congés</span>
            </a></li>
            <?php endif; ?>
            <!-- Entretiens collaborateur — visible admin uniquement -->
            <?php if ($isAdmin): ?>
            <?php if (!empty($sidebarEntretiensCollab)): ?>
                <?php foreach ($sidebarEntretiensCollab as $se):
                    if ($se['statut'] === 'questionnaire_envoye') {
                        $seIcon='⭐'; $seLabel='Auto-évaluation';
                        $seUrl='./rh_entretien_auto_eval.php?id='.(int)$se['id'];
                    } elseif (in_array($se['statut'],['termine','signe'],true)) {
                        $seIcon='📋'; $seLabel='Mon compte rendu';
                        $seUrl='./rh_entretien_vue_collaborateur.php?id='.(int)$se['id'];
                    } else {
                        $seIcon='🔴'; $seLabel='Entretien en cours';
                        $seUrl='./rh_entretien_vue_collaborateur.php?id='.(int)$se['id'];
                    }
                ?>
                <li><a href="<?= htmlspecialchars($seUrl) ?>" class="<?= sbActive(basename(strtok($seUrl,'?'))) ?>">
                    <span class="sb-icon"><?= $seIcon ?></span>
                    <span class="sb-label"><?= htmlspecialchars($seLabel) ?>
                        <?php if ($se['date_planifiee']): ?>
                        <span class="sb-sub"><?= date('d/m/Y', strtotime($se['date_planifiee'])) ?></span>
                        <?php endif; ?>
                    </span>
                </a></li>
                <?php endforeach; ?>
            <?php else: ?>
            <li><a href="./rh_entretien_vue_collaborateur.php" class="<?= sbActive('rh_entretien_vue_collaborateur.php') ?>">
                <span class="sb-icon">🤝</span><span class="sb-label">Mes entretiens</span>
            </a></li>
            <?php endif; ?>
            <?php endif; ?>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         R3 — VIE EN AGENCE (tous)
    ═══════════════════════════ -->
    <div class="sb-group agency">
        <div class="sb-section<?= sbActiveSection(['dev_organisation.php','rh_modeles.php']) ? ' section-active' : '' ?>">
            Vie en agence
        </div>
        <ul class="sb-nav">
            <li><a href="./dev_organisation.php" class="<?= sbActive('dev_organisation.php') ?>">
                <span class="sb-icon">🧩</span><span class="sb-label">Organigramme agences</span>
            </a></li>
        </ul>
    </div>

    <!-- ═══════════════════════════
         R4 — MANAGEMENT AGENCE (manager + admin)
    ═══════════════════════════ -->
    <?php if ($isManager): ?>
    <div class="sb-divider"></div>
    <div class="sb-group mgmt">
        <div class="sb-section<?= sbActiveSection(['rh_salaires.php','rh_conges_validation.php','rh_entretien_liste.php','rh_entretien_dashboard.php','rh_entretien_ajouter.php','rh_entretien_config_societe.php']) ? ' section-active' : '' ?>">
            Management agence
            <span class="sb-badge mgr">MGR</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./rh_salaires.php" class="<?= sbActive('rh_salaires.php') ?>">
                <span class="sb-icon">💶</span><span class="sb-label">Gérer les payes</span>
            </a></li>
            <li><a href="./rh_user.php" class="<?= sbActive('rh_user.php') ?>">
                <span class="sb-icon">👥</span><span class="sb-label">Gérer les users</span>
            </a></li>
            <li><a href="./rh_conges_validation.php" class="<?= sbActive('rh_conges_validation.php') ?>">
                <span class="sb-icon">🏖️</span><span class="sb-label">Gérer les congés</span>
            </a></li>
            <li><a href="./rh_indemnite_km.php" class="<?= sbActive('rh_indemnite_km.php') ?>">
                <span class="sb-icon">🚗</span><span class="sb-label">Gérer les IK</span>
            </a></li>
            <li><a href="./rh_entretien_liste.php" class="<?= sbActive('rh_entretien_liste.php') ?>">
                <span class="sb-icon">📋</span><span class="sb-label">Gérer les entretiens</span>
            </a></li>
            <li><a href="./rh_entretien_dashboard.php" class="<?= sbActive('rh_entretien_dashboard.php') ?>">
                <span class="sb-icon">📊</span><span class="sb-label">Tableau de bord RH</span>
            </a></li>
            <li><a href="./rh_entretien_config_societe.php" class="<?= sbActive('rh_entretien_config_societe.php') ?>">
                <span class="sb-icon">⚙️</span><span class="sb-label">Config entretiens</span>
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════
         R5 — ADMINISTRATION (admin)
    ═══════════════════════════ -->
    <?php if ($isAdmin): ?>
    <div class="sb-divider"></div>
    <div class="sb-group admin">
        <div class="sb-section<?= sbActiveSection(['rh_user.php','rh_user_add.php','societe.php','rh_entretien_admin.php','rh_mails.php','rh_salaires_historiq.php','rh_conges_historiq.php']) ? ' section-active' : '' ?>">
            Administration
            <span class="sb-badge adm">ADMIN</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./rh_user.php" class="<?= sbActive('rh_user.php') ?>">
                <span class="sb-icon">👥</span><span class="sb-label">Gérer les utilisateurs</span>
            </a></li>
            <li><a href="./societe.php" class="<?= sbActive('societe.php') ?>">
                <span class="sb-icon">🏢</span><span class="sb-label">Sociétés &amp; agences</span>
            </a></li>
            <li><a href="./rh_salaires_historiq.php" class="<?= sbActive('rh_salaires_historiq.php') ?>">
                <span class="sb-icon">💶</span><span class="sb-label">Gérer les payes</span>
            </a></li>
            <li><a href="./rh_conges_validation.php" class="<?= sbActive('rh_conges_validation.php') ?>">
                <span class="sb-icon">🏖️</span><span class="sb-label">Gérer les congés</span>
            </a></li>
            <li><a href="./rh_indemnite_km.php" class="<?= sbActive('rh_indemnite_km.php') ?>">
                <span class="sb-icon">🚗</span><span class="sb-label">Gérer les IK</span>
            </a></li>
            <li><a href="./rh_entretien_liste.php" class="<?= sbActive('rh_entretien_liste.php') ?>">
                <span class="sb-icon">🤝</span><span class="sb-label">Gérer les entretiens</span>
            </a></li>
            <li><a href="./rh_entretien_admin.php" class="<?= sbActive('rh_entretien_admin.php') ?>">
                <span class="sb-icon">🔧</span><span class="sb-label">Catalogue RH global</span>
            </a></li>
            <li><a href="./rh_mails.php" class="<?= sbActive('rh_mails.php') ?>">
                <span class="sb-icon">📧</span><span class="sb-label">Emails collectifs</span>
            </a></li>
            <li><a href="./dev_organisation.php" class="<?= sbActive('dev_organisation.php') ?>">
                <span class="sb-icon">🧩</span><span class="sb-label">Organigrammes</span>
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════
         R6 — SUPER ADMIN
    ═══════════════════════════ -->
    <?php if ($isSuperAdmin): ?>
    <div class="sb-divider"></div>
    <div class="sb-group super">
        <div class="sb-section<?= sbActiveSection(['admin_database.php','design-system.php','societe_super_admin.php']) ? ' section-active' : '' ?>">
            Super Admin
            <span class="sb-badge sup">SA</span>
        </div>
        <ul class="sb-nav">
            <li><a href="./societe_super_admin.php" class="<?= sbActive('societe_super_admin.php') ?>">
                <span class="sb-icon">🏢</span><span class="sb-label">Toutes les sociétés</span>
            </a></li>
            <li><a href="./admin/admin_database.php" class="<?= sbActive('admin_database.php') ?>">
                <span class="sb-icon">🗄</span><span class="sb-label">Base de données</span>
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







