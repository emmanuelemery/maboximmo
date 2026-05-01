<?php
declare(strict_types=1);

require_once __DIR__ . '/roles_services.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');

/**
 * Récupération robuste du rôle
 * Adapte ici si besoin selon ton système.
 */
$roleId       = function_exists('current_role_id') ? (int) current_role_id() : 0;
$isSuperAdmin = function_exists('is_super_admin') ? (bool) is_super_admin() : false;

/**
 * Si tu connais exactement les IDs de rôles :
 * - admin = 1
 * - manager = 2
 * - user/collaborateur = 3
 * adapte simplement ici si besoin.
 */
$isAdmin   = ($roleId === 1) || $isSuperAdmin;
$isManager = ($roleId === 2) || $isAdmin;

/**
 * Helpers
 */
if (!function_exists('h')) {
    function h(?string $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function pageIsActive(array $pages, string $currentPage): bool {
    return in_array($currentPage, $pages, true);
}

function renderSidebarSection(string $title, string $icon, array $items, string $currentPage): void
{
    $pages = array_column($items, 'page');
    $isOpen = pageIsActive($pages, $currentPage);

    echo '<div class="rh-sidebar-section ' . ($isOpen ? 'is-open' : '') . '">';
    echo '  <div class="rh-sidebar-section-title">';
    echo '      <span class="rh-sidebar-section-icon">' . $icon . '</span>';
    echo '      <span>' . h($title) . '</span>';
    echo '  </div>';
    echo '  <ul class="rh-sidebar-nav">';

    foreach ($items as $item) {
        $active = ($currentPage === $item['page']) ? 'active' : '';
        echo '<li>';
        echo '  <a class="rh-sidebar-link ' . $active . '" href="' . h($item['url']) . '">';
        echo '      <span class="rh-link-icon">' . $item['icon'] . '</span>';
        echo '      <span class="rh-link-label">' . h($item['label']) . '</span>';
        echo '  </a>';
        echo '</li>';
    }

    echo '  </ul>';
    echo '</div>';
}

/**
 * MENU COMMUN À TOUS
 */
$sectionNavigation = [
    [
        'label' => 'Ma Box Agency',
        'url'   => '/public_html/agency/dashboard.php',
        'page'  => 'dashboard.php',
        'icon'  => '🏢',
    ],
    [
        'label' => 'Ma Box RH',
        'url'   => '/public_html/rh/rh_dashboard.php',
        'page'  => 'rh_dashboard.php',
        'icon'  => '🧑‍💼',
    ],
];

$sectionMesInfos = [
    [
        'label' => 'Mon compte',
        'url'   => '/public_html/rh/mon_compte.php',
        'page'  => 'mon_compte.php',
        'icon'  => '👤',
    ],
    [
        'label' => 'Mes salaires',
        'url'   => '/public_html/rh/mes_salaires.php',
        'page'  => 'mes_salaires.php',
        'icon'  => '💶',
    ],
    [
        'label' => 'Mes documents',
        'url'   => '/public_html/rh/mes_documents.php',
        'page'  => 'mes_documents.php',
        'icon'  => '📁',
    ],
    [
        'label' => 'Mes IK',
        'url'   => '/public_html/rh/mes_ik.php',
        'page'  => 'mes_ik.php',
        'icon'  => '🚗',
    ],
    [
        'label' => 'Mes congés',
        'url'   => '/public_html/rh/mes_conges.php',
        'page'  => 'mes_conges.php',
        'icon'  => '🌴',
    ],
    [
        'label' => 'Mes entretiens indiv',
        'url'   => '/public_html/rh/mes_entretiens.php',
        'page'  => 'mes_entretiens.php',
        'icon'  => '🗣️',
    ],
];

$sectionSociete = [
    [
        'label' => 'Documents société',
        'url'   => '/public_html/rh/documents_societe.php',
        'page'  => 'documents_societe.php',
        'icon'  => '🏛️',
    ],
    [
        'label' => 'Organigramme agences',
        'url'   => '/public_html/rh/organigramme_agences.php',
        'page'  => 'organigramme_agences.php',
        'icon'  => '🧩',
    ],
];

/**
 * MANAGERS
 */
$sectionManagement = [
    [
        'label' => 'Gérer les payes',
        'url'   => '/public_html/rh/gerer_payes.php',
        'page'  => 'gerer_payes.php',
        'icon'  => '💳',
    ],
    [
        'label' => 'Gérer les congés',
        'url'   => '/public_html/rh/gerer_conges.php',
        'page'  => 'gerer_conges.php',
        'icon'  => '📅',
    ],
    [
        'label' => 'Gérer les IK',
        'url'   => '/public_html/rh/gerer_ik.php',
        'page'  => 'gerer_ik.php',
        'icon'  => '🛣️',
    ],
];

/**
 * ADMINS
 */
$sectionAdministration = [
    [
        'label' => 'Paramétrage',
        'url'   => '/public_html/parametrage.php',
        'page'  => 'parametrage.php',
        'icon'  => '🎛️',
    ],
    [
        'label' => 'Gérer les users',
        'url'   => '/public_html/admin/gerer_users.php',
        'page'  => 'gerer_users.php',
        'icon'  => '👥',
    ],
    [
        'label' => 'Gérer les sociétés et agences',
        'url'   => '/public_html/admin/gerer_societes_agences.php',
        'page'  => 'gerer_societes_agences.php',
        'icon'  => '🏬',
    ],
    [
        'label' => 'Les salaires',
        'url'   => '/public_html/admin/salaires.php',
        'page'  => 'salaires.php',
        'icon'  => '💰',
    ],
    [
        'label' => 'Config entretien',
        'url'   => '/public_html/admin/config_entretien.php',
        'page'  => 'config_entretien.php',
        'icon'  => '⚙️',
    ],
    [
        'label' => 'Emails collectifs',
        'url'   => '/public_html/admin/emails_collectifs.php',
        'page'  => 'emails_collectifs.php',
        'icon'  => '✉️',
    ],
    [
        'label' => 'Organigrammes',
        'url'   => '/public_html/admin/organigrammes.php',
        'page'  => 'organigrammes.php',
        'icon'  => '🧭',
    ],
    [
        'label' => 'Gérer les payes',
        'url'   => '/public_html/admin/gerer_payes.php',
        'page'  => 'gerer_payes.php',
        'icon'  => '💳',
    ],
    [
        'label' => 'Gérer les congés',
        'url'   => '/public_html/admin/gerer_conges.php',
        'page'  => 'gerer_conges.php',
        'icon'  => '📆',
    ],
    [
        'label' => 'Gérer les IK',
        'url'   => '/public_html/admin/gerer_ik.php',
        'page'  => 'gerer_ik.php',
        'icon'  => '🚘',
    ],
    [
        'label' => 'Gérer les entretiens',
        'url'   => '/public_html/admin/gerer_entretiens.php',
        'page'  => 'gerer_entretiens.php',
        'icon'  => '📝',
    ],
];

/**
 * SUPER ADMIN
 */
$sectionSuperAdmin = [
    [
        'label' => 'BDD',
        'url'   => '/public_html/admin/admin_database.php',
        'page'  => 'admin_database.php',
        'icon'  => '🗄️',
    ],
    [
        'label' => 'Design',
        'url'   => '/public_html/admin/design-system.php',
        'page'  => 'design-system.php',
        'icon'  => '🎨',
    ],
];

?>
<aside class="rh-sidebar">
    <div class="rh-sidebar-top">
        <a href="/public_html/default.php" class="rh-sidebar-brand">
            <div class="rh-brand-logo">RH</div>
            <div class="rh-brand-text">
                <strong>MaBoxImmo</strong>
                <span>Espace RH</span>
            </div>
        </a>
    </div>

    <div class="rh-sidebar-scroll">

        <?php renderSidebarSection('Navigation', '🧭', $sectionNavigation, $currentPage); ?>
        <?php renderSidebarSection('Mes informations', '👤', $sectionMesInfos, $currentPage); ?>
        <?php renderSidebarSection('Société', '🏛️', $sectionSociete, $currentPage); ?>

        <?php if ($isManager): ?>
            <?php renderSidebarSection('Management agence', '🧑‍💼', $sectionManagement, $currentPage); ?>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <?php renderSidebarSection('Administration', '⚙️', $sectionAdministration, $currentPage); ?>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
            <?php renderSidebarSection('Super admin', '🛠️', $sectionSuperAdmin, $currentPage); ?>
        <?php endif; ?>

        <?php
        $sectionOutils = [
            [
                'label' => 'Ma GED Box',
                'url'   => '/public_html/modules/ged/ged_dashboard.php',
                'page'  => 'ged_dashboard.php',
                'icon'  => '📦',
            ],
        ];
        renderSidebarSection('Outils MaBoxImmo', '🧰', $sectionOutils, $currentPage);

        $sectionCompte = [
            [
                'label' => 'Paramètres',
                'url'   => '/public_html/parametres.php',
                'page'  => 'parametres.php',
                'icon'  => '⚙️',
            ],
            [
                'label' => 'Déconnexion',
                'url'   => '/public_html/logout.php',
                'page'  => 'logout.php',
                'icon'  => '🚪',
            ],
        ];
        renderSidebarSection('Compte', '🔐', $sectionCompte, $currentPage);
        ?>
    </div>
</aside>

