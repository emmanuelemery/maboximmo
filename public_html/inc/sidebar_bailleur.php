<?php
/**
 * inc/sidebar_bailleur.php — Sidebar complète module Bailleur
 */
declare(strict_types=1);

// Marque le contexte de navigation pour les pages partagées (bien_liste, etc.)
// afin qu'elles rechargent la bonne sidebar et ne basculent pas sur agency.
if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
    $_SESSION['nav_ctx'] = 'bailleur';
}

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$sbActive = static function (string ...$pages) use ($currentPage): string {
    return in_array($currentPage, $pages, true) ? ' active' : '';
};
?>
<link rel="stylesheet" href="css/sidebar.css">

<aside class="mbi-sidebar">

    <!-- ── Brand ── -->
    <a class="sb-brand" href="./bailleur_dashboard.php">
        <div class="sb-brand-logo">🏠</div>
        <div class="sb-brand-text">
            <strong>Ma Box Bailleur</strong>
            <span>Espace bailleur</span>
        </div>
    </a>

    <!-- ═══════════════════════════
         GESTION
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section">Gestion</div>
        <ul class="sb-nav">
            <li><a href="./ged_dashboard.php" class="<?= $sbActive('ged_dashboard.php') ?>">
                <span class="sb-icon">📦</span><span class="sb-label">Ma GED Box</span>
            </a></li>
            <li><a href="./bailleur_dashboard.php" class="<?= $sbActive('bailleur_dashboard.php') ?>">
                <span class="sb-icon">📊</span><span class="sb-label">Dashboard</span>
            </a></li>
            <li><a href="./bailleur_immeubles.php" class="<?= $sbActive('bailleur_immeubles.php') ?>">
                <span class="sb-icon">🏢</span><span class="sb-label">Immeubles</span>
            </a></li>
            <li><a href="./bien_liste.php" class="<?= $sbActive('bien_liste.php', 'bien_ajouter.php') ?>">
                <span class="sb-icon">🏠</span><span class="sb-label">Mes biens</span>
            </a></li>
            <li><a href="./bailleur_revision_loyer.php" class="<?= $sbActive('bailleur_revision_loyer.php') ?>">
                <span class="sb-icon">📐</span><span class="sb-label">Révision loyer</span>
            </a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         LOCATAIRES
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section">Locataires</div>
        <ul class="sb-nav">
            <li><a href="./bailleur_dashboard.php?archives=0" class="<?= $sbActive('bailleur_locataires.php') ?>">
                <span class="sb-icon">👥</span><span class="sb-label">Locataires</span>
            </a></li>
            <li><a href="./bailleur_contentieux.php" class="<?= $sbActive('bailleur_contentieux.php') ?>">
                <span class="sb-icon">⚖️</span><span class="sb-label">Contentieux</span>
            </a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         DOCUMENTS & DIFFUSION
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section">Documents & Diffusion</div>
        <ul class="sb-nav">
            <li><a href="./bailleur_ged.php" class="<?= $sbActive('bailleur_ged.php') ?>">
                <span class="sb-icon">📁</span><span class="sb-label">GED Documents</span>
            </a></li>
            <li><a href="./bailleur_crg_audit.php" class="<?= $sbActive('bailleur_crg_audit.php') ?>">
                <span class="sb-icon">🔍</span><span class="sb-label">Audit CRG</span>
            </a></li>
            <li><a href="./bien_recherche.php" class="<?= $sbActive('bailleur_annonces.php') ?>">
                <span class="sb-icon">📡</span><span class="sb-label">Diffusion MaBoxImmo</span>
            </a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         PATRIMOINE
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section">Patrimoine</div>
        <ul class="sb-nav">
            <li><a href="./bailleur_sci_organigramme.php" class="<?= $sbActive('bailleur_sci_organigramme.php') ?>">
                <span class="sb-icon">🏛</span><span class="sb-label">Organigramme SCI</span>
            </a></li>
        </ul>
    </div>

    <div class="sb-divider"></div>

    <!-- ═══════════════════════════
         ANALYSE INVESTISSEUR
    ═══════════════════════════ -->
    <div class="sb-group nav">
        <div class="sb-section">Analyse Investisseur</div>
        <ul class="sb-nav">
            <li><a href="./investisseur/" class="<?= $sbActive('index.php', 'detail.php', 'nouvelle.php') && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/investisseur/') !== false ? 'active' : '' ?>">
                <span class="sb-icon">📊</span><span class="sb-label">Dashboard analyses</span>
            </a></li>
            <li><a href="./investisseur/nouvelle.php" class="<?= $sbActive('nouvelle.php') && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/investisseur/') !== false ? 'active' : '' ?>">
                <span class="sb-icon">✍️</span><span class="sb-label">Nouvelle analyse</span>
            </a></li>
            <li><a href="./investisseur/comparaison.php" class="<?= $sbActive('comparaison.php') && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/investisseur/') !== false ? 'active' : '' ?>">
                <span class="sb-icon">⚖️</span><span class="sb-label">Comparer des biens</span>
            </a></li>
            <li><a href="./investisseur/valorisation.php" class="<?= $sbActive('valorisation.php') && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/investisseur/') !== false ? 'active' : '' ?>">
                <span class="sb-icon">💰</span><span class="sb-label">Simulateur valeur</span>
            </a></li>
            <li><a href="./investisseur/prix_priorites.php" class="<?= $sbActive('prix_priorites.php') && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/investisseur/') !== false ? 'active' : '' ?>">
                <span class="sb-icon">🎯</span><span class="sb-label">Prix &amp; priorités</span>
            </a></li>
            <li><a href="./investisseur/contacts.php" class="<?= $sbActive('contacts.php') && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/investisseur/') !== false ? 'active' : '' ?>">
                <span class="sb-icon">👤</span><span class="sb-label">Contacts &amp; partages</span>
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
