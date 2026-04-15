<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Développement rapide';
$bodyClass = ''; 
$robots = 'noindex, nofollow';

$current_user_name = trim(
    (string)(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? ''))
);

if ($current_user_name === '') {
    $current_user_name = (string)($_SESSION['user_email'] ?? 'Utilisateur');
}

$current_user_role = (string)($_SESSION['role_nom'] ?? $_SESSION['user_role'] ?? 'Collaborateur');
$current_user_societe = (string)($_SESSION['societe_nom'] ?? 'MaBoxImmo');

$actionbar = [
    'show_back' => true,
    'show_home' => true,
    'home_link' => '/default.php',
    'right' => [
        [
            'type' => 'dropdown',
            'label' => '➕ Nouveau',
            'primary' => true,
            'items' => [
                ['label' => 'Bien', 'url' => '/bien_ajouter.php'],
                ['label' => 'Mandat', 'url' => '/ajouter_mandat.php'],
                ['label' => 'Bail', 'url' => '/ajouter_bail.php'],
                ['label' => 'Mandant', 'url' => '/ajouter_mandant.php'],
                ['label' => 'Visite', 'url' => '/ajouter_visite.php'],
            ]
        ],
        [
            'type' => 'dropdown',
            'label' => '⚙️ Actions',
            'items' => [
                ['label' => 'Exporter', 'url' => '#'],
                ['label' => 'Imprimer', 'url' => '#'],
                ['label' => 'Paramètres page', 'url' => '#'],
            ]
        ]
    ]
];

include __DIR__ . '/inc/header.php';
?>

<div class="app-shell">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>

    <div class="main-panel">
        <header class="topbar">
            <div class="topbar-left">
                <h1><?= h($pageTitle) ?></h1>
                <p>Création rapide et navigation par modules</p>
            </div>

            <div class="topbar-user">
                <div class="user-avatar">
                    <?= h(function_exists('mb_strtoupper')
                        ? mb_strtoupper(mb_substr($current_user_name, 0, 1))
                        : strtoupper(substr($current_user_name, 0, 1))) ?>
                </div>
                <div class="user-meta">
                    <strong><?= h($current_user_name) ?></strong>
                    <span><?= h($current_user_role) ?> · <?= h($current_user_societe) ?></span>
                </div>
            </div>
        </header>

        <main class="content-wrapper">
            <?php include __DIR__ . '/inc/actionbar.php'; ?>

            <section class="cards-grid">
                <div class="card">
                    <h3>Biens</h3>
                    <p>Créer, modifier et suivre les biens rapidement.</p>
                </div>

                <div class="card">
                    <h3>Mandats</h3>
                    <p>Accès direct aux mandats et renouvellements.</p>
                </div>

                <div class="card">
                    <h3>Baux</h3>
                    <p>Gestion des baux et échéances.</p>
                </div>

                <div class="card">
                    <h3>Visites</h3>
                    <p>Organisation des visites et comptes rendus.</p>
                </div>

                <div class="card">
                    <h3>Documents</h3>
                    <p>Classement, upload et consultation rapide.</p>
                </div>

                <div class="card">
                    <h3>Outils bailleurs</h3>
                    <p>Révision de loyer, calculs et assistance.</p>
                </div>
            </section>
        </main>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>

