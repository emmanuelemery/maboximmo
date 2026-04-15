<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Variables de base
$pageTitle = $pageTitle ?? 'MaBoxImmo';
$bodyClass = $bodyClass ?? '';
$theme = $_SESSION['ui_theme'] ?? 'light';
$themeClass = ($theme === 'dark') ? 'theme-awards' : 'theme-light';
$bodyClass = trim($bodyClass . ' ' . $themeClass);
$appLayout = $appLayout ?? false;
$pageDescription = $pageDescription ?? 'MaBoxImmo, portail immobilier professionnel : biens, mandats, baux, visites et services pour bailleurs.';
$pageCanonical = $pageCanonical ?? '';
$robots = $robots ?? 'index, follow';

$canonicalUrl = '';
if ($pageCanonical !== '') {
    $canonicalUrl = $pageCanonical;
} elseif (function_exists('app_url')) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $canonicalUrl = app_url($path);
    }
}

// Statut utilisateur
$isLoggedIn = !empty($_SESSION['user_id']);

// Nom affiché utilisateur
$userDisplay = '';
if ($isLoggedIn) {
    $prenom = trim((string)($_SESSION['user_prenom'] ?? ''));
    $nom    = trim((string)($_SESSION['user_nom'] ?? ''));

    $userDisplay = trim($prenom . ' ' . $nom);

    if ($userDisplay === '') {
        $userDisplay = (string)($_SESSION['user_email'] ?? 'Utilisateur');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="robots" content="<?= htmlspecialchars($robots) ?>">
    <?php if ($canonicalUrl !== ''): ?>
        <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
        <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <?php endif; ?>
    <meta property="og:site_name" content="MaBoxImmo">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="twitter:card" content="summary_large_image">

    <!-- CSS global -->
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('/css/variables.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('/css/style.css')) ?>">
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">

<?php if (!$appLayout): ?>

<!-- ===== HEADER PUBLIC ===== -->
<header class="site-header">
    <div class="site-header-inner">

        <!-- Logo -->
        <a href="<?= htmlspecialchars(app_url('/default.php')) ?>" class="site-brand">
            <div class="site-logo">🏠</div>
            <div>
                <div class="site-brand-title">MaBoxImmo</div>
                <div class="site-brand-subtitle">Portail immobilier</div>
            </div>
        </a>

        <!-- Navigation -->
        <nav class="site-nav">
            <a href="<?= htmlspecialchars(app_url('/default.php')) ?>">Accueil</a>
            <a href="<?= htmlspecialchars(app_url('/bien_recherche.php')) ?>">Rechercher</a>
            <a href="<?= htmlspecialchars(app_url('/agences.php')) ?>">Agences</a>
            <a href="<?= htmlspecialchars(app_url('/contact.php')) ?>">Contact</a>

            <?php if ($isLoggedIn): ?>

                <!-- Accès appli -->
                <a href="<?= htmlspecialchars(app_url('/dev.php')) ?>" class="btn-outline">Organisation</a>
                <form method="post" action="<?= htmlspecialchars(app_url('/sso_registres.php')) ?>" style="display:inline;">
                    <?php if (function_exists('csrf_field')): ?>
                        <?= csrf_field('sso_registres') ?>
                    <?php endif; ?>
                    <input type="hidden" name="target" value="/dashboard.php">
                    <button type="submit" class="btn-outline" style="border:none; background:none; padding:0; cursor:pointer;">
                        Registres
                    </button>
                </form>
                <?php if (!empty($_SESSION['id_role']) && (int)$_SESSION['id_role'] === 1): ?>
                    <a href="<?= htmlspecialchars(app_url('/admin_user_create.php')) ?>" class="btn-outline">+ Utilisateur</a>
                <?php endif; ?>

                <!-- Utilisateur -->
                <div class="site-user">
                    <div class="site-user-avatar">
                        <?= htmlspecialchars(function_exists('mb_strtoupper')
                            ? mb_strtoupper(mb_substr($userDisplay !== '' ? $userDisplay : 'U', 0, 1))
                            : strtoupper(substr($userDisplay !== '' ? $userDisplay : 'U', 0, 1))) ?>
                    </div>
                    <div>
                        <div class="site-user-name"><?= htmlspecialchars($userDisplay) ?></div>
                        <div class="site-user-role">Connecté</div>
                    </div>
                </div>

                <!-- Déconnexion -->
                <a href="<?= htmlspecialchars(app_url('/logout.php')) ?>" class="btn-primary">Déconnexion</a>

            <?php else: ?>

                <a href="<?= htmlspecialchars(app_url('/login.php')) ?>" class="btn-primary">Connexion</a>

            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- ===== CONTENEUR SITE PUBLIC ===== -->
<div class="page-shell">

<?php endif; ?>




