<?php
declare(strict_types=1);
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="mbi-sidebar">
    <div class="mbi-sidebar-head">
        <div class="mbi-sidebar-brand"><strong>Propriétaire</strong><span>MaBoxImmo</span></div>
    </div>

    <div class="mbi-sidebar-section">Principal</div>
    <ul class="mbi-nav">
        <li><a href="modules/ged/ged_dashboard.php" <?=(in_array($currentPage, ['ged_dashboard.php','ged_inbox.php'], true) ? 'class="active"' : '')?>>📦 Ma GED Box</a></li>
        <li><a href="dashboard_proprietaire.php" <?=($currentPage === 'dashboard_proprietaire.php' ? 'class="active"' : '')?>>⊞ Dashboard</a></li>
        <li><a href="landing.php">← Retour Accueil</a></li>
    </ul>

    <div class="mbi-sidebar-section">Mes Biens</div>
    <ul class="mbi-nav">
        <li><a href="mes_biens.php" <?=($currentPage === 'mes_biens.php' ? 'class="active"' : '')?>>🏠 Mes Propriétés</a></li>
        <li><a href="mes_locations.php" <?=($currentPage === 'mes_locations.php' ? 'class="active"' : '')?>>🔑 Locations Actives</a></li>
        <li><a href="mes_locataires.php" <?=($currentPage === 'mes_locataires.php' ? 'class="active"' : '')?>>👥 Locataires</a></li>
    </ul>

    <div class="mbi-sidebar-section">Gestion</div>
    <ul class="mbi-nav">
        <li><a href="mes_documents.php" <?=($currentPage === 'mes_documents.php' ? 'class="active"' : '')?>>📄 Documents</a></li>
        <li><a href="mes_baux.php" <?=($currentPage === 'mes_baux.php' ? 'class="active"' : '')?>>📋 Baux</a></li>
        <li><a href="mes_financements.php" <?=($currentPage === 'mes_financements.php' ? 'class="active"' : '')?>>💰 Financements</a></li>
        <li><a href="mes_sinistres.php" <?=($currentPage === 'mes_sinistres.php' ? 'class="active"' : '')?>>⚠️ Sinistres</a></li>
    </ul>

    <div class="mbi-sidebar-section">Communication</div>
    <ul class="mbi-nav">
        <li><a href="mes_messages.php" <?=($currentPage === 'mes_messages.php' ? 'class="active"' : '')?>>💬 Messages</a></li>
        <li><a href="mes_demandes.php" <?=($currentPage === 'mes_demandes.php' ? 'class="active"' : '')?>>✉️ Demandes</a></li>
    </ul>

    <div class="mbi-sidebar-section">Compte</div>
    <ul class="mbi-nav">
        <li><a href="parametres.php" <?=($currentPage === 'parametres.php' ? 'class="active"' : '')?>>⚙️ Paramètres</a></li>
        <li><a href="logout.php" class="logout">🚪 Déconnexion</a></li>
    </ul>
</aside>
