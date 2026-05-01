<?php
declare(strict_types=1);
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="mbi-sidebar">
    <div class="mbi-sidebar-head">
        <div class="mbi-sidebar-brand"><strong>Syndic</strong><span>MaBoxImmo</span></div>
    </div>

    <div class="mbi-sidebar-section">Principal</div>
    <ul class="mbi-nav">
        <li><a href="dashboard_syndic.php" <?=($currentPage === 'dashboard_syndic.php' ? 'class="active"' : '')?>>⊞ Dashboard</a></li>
        <li><a href="landing.php">← Retour Accueil</a></li>
    </ul>

    <div class="mbi-sidebar-section">Gestion Syndic</div>
    <ul class="mbi-nav">
        <li><a href="immeubles_syndic.php" <?=($currentPage === 'immeubles_syndic.php' ? 'class="active"' : '')?>>🏢 Immeubles</a></li>
        <li><a href="coproprietes_syndic.php" <?=($currentPage === 'coproprietes_syndic.php' ? 'class="active"' : '')?>>👥 Copropriétaires</a></li>
        <li><a href="lots_syndic.php" <?=($currentPage === 'lots_syndic.php' ? 'class="active"' : '')?>>📋 Lots</a></li>
        <li><a href="charges_syndic.php" <?=($currentPage === 'charges_syndic.php' ? 'class="active"' : '')?>>💰 Charges</a></li>
        <li><a href="assembles_syndic.php" <?=($currentPage === 'assembles_syndic.php' ? 'class="active"' : '')?>>📅 Assemblées</a></li>
    </ul>

    <div class="mbi-sidebar-section">Documents</div>
    <ul class="mbi-nav">
        <li><a href="docs_syndic.php" <?=($currentPage === 'docs_syndic.php' ? 'class="active"' : '')?>>📁 Documents</a></li>
        <li><a href="archives_syndic.php" <?=($currentPage === 'archives_syndic.php' ? 'class="active"' : '')?>>🗂️ Archives</a></li>
    </ul>

    <div class="mbi-sidebar-section">Outils MaBoxImmo</div>
    <ul class="mbi-nav">
        <li><a href="modules/ged/ged_dashboard.php" <?=($currentPage === 'ged_dashboard.php' ? 'class="active"' : '')?>>📦 Ma GED Box</a></li>
    </ul>

    <div class="mbi-sidebar-section">Compte</div>
    <ul class="mbi-nav">
        <li><a href="parametres.php" <?=($currentPage === 'parametres.php' ? 'class="active"' : '')?>>⚙️ Paramètres</a></li>
        <li><a href="logout.php" class="logout">🚪 Déconnexion</a></li>
    </ul>
</aside>
