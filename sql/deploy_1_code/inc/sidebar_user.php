<?php
/**
 * inc/sidebar_user.php — Sidebar minimale pour le rôle « user » (Collaborateur).
 *
 * Contient UNIQUEMENT les 4 liens de l'espace collaborateur :
 *   - Mon Profil      → rh_profil.php
 *   - Mes Documents   → rh_documents.php
 *   - Mes Congés      → rh_conges.php
 *   - Mon Agence      → rh_agence_user.php
 *
 * Utilisée via le layout central `inc/layout_maboximmo.php` en déclarant
 *   $layout_sidebar = 'sidebar_user';
 * dans la page appelante. Réutilise `css/sidebar.css` (même style que les
 * autres sidebars du projet → un seul point de modification global).
 */
declare(strict_types=1);

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');

/** Marque un lien actif si la page courante correspond. */
$sbUserActive = static function (string $page) use ($currentPage): string {
    return $currentPage === $page ? ' active' : '';
};

$sbUserId = function_exists('current_user_id') ? current_user_id() : 0;
$sbUserPrenom = $_SESSION['prenom'] ?? '';
$sbUserNom    = $_SESSION['nom']    ?? '';
?>
<link rel="stylesheet" href="css/sidebar.css">

<aside class="mbi-sidebar">

    <!-- ── Brand ── -->
    <a class="sb-brand" href="./rh_dashboard_user.php">
        <div class="sb-brand-logo">👤</div>
        <div class="sb-brand-text">
            <strong>Mon espace</strong>
            <span>Collaborateur</span>
        </div>
    </a>

    <!-- ═══════════════════════════
         NAVIGATION — 4 liens user
    ═══════════════════════════ -->
    <div class="sb-group info">
        <div class="sb-section">Mes infos</div>
        <ul class="sb-nav">
            <li><a href="./rh_dashboard_user.php" class="<?= $sbUserActive('rh_dashboard_user.php') ?>">
                <span class="sb-icon">🏠</span><span class="sb-label">Tableau de bord</span>
            </a></li>
            <li><a href="./rh_profil_prov.php" class="<?= $sbUserActive('rh_profil_prov.php') ?>">
                <span class="sb-icon">👤</span><span class="sb-label">Mon profil</span>
            </a></li>
            <li><a href="./rh_documents_prov.php" class="<?= $sbUserActive('rh_documents_prov.php') ?>">
                <span class="sb-icon">📁</span><span class="sb-label">Mes documents</span>
            </a></li>
            <li><a href="./rh_conges_historiq_prov.php" class="<?= $sbUserActive('rh_conges_historiq_prov.php') ?>">
                <span class="sb-icon">🏖️</span><span class="sb-label">Mes congés</span>
            </a></li>
            <li><a href="./rh_agence_prov.php" class="<?= $sbUserActive('rh_agence_prov.php') ?>">
                <span class="sb-icon">🏢</span><span class="sb-label">Mon agence</span>
            </a></li>
        </ul>
    </div>

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
