<?php
/**
 * inc/sidebar_bailleur_module.php — Sidebar module Bailleur
 */
declare(strict_types=1);

$_sbBase = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/';
$roleId  = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSA    = function_exists('is_super_admin') ? is_super_admin() : false;
$userId  = function_exists('current_user_id') ? (int)current_user_id() : 0;

// Modules autorisés pour ce bailleur (super admin = tous)
$allowedModules = [];
if ($isSA) {
    $allowedModules = ['patrimoine','ged','revision','bail','contentieux','edl','transaction','comptes','imports'];
} elseif ($userId > 0 && isset($GLOBALS['pdo'])) {
    $stmtMod = $GLOBALS['pdo']->prepare("SELECT module_code FROM user_bailleur_modules WHERE id_user=?");
    $stmtMod->execute([$userId]);
    $allowedModules = $stmtMod->fetchAll(\PDO::FETCH_COLUMN);
    if (empty($allowedModules)) {
        // Par défaut : patrimoine + ged si aucun module configuré
        $allowedModules = ['patrimoine','ged'];
    }
}

function sb_bail_active(string $page): string {
    return basename($_SERVER['SCRIPT_NAME'] ?? '') === $page ? 'active' : '';
}

$_bNom  = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$_bInit = strtoupper(
    mb_substr($_SESSION['prenom'] ?? $_SESSION['nom'] ?? 'B', 0, 1) .
    mb_substr($_SESSION['nom'] ?? '', 0, 1)
);
?>
<style>
.sb-bail { width:240px; min-width:240px; background:#1a1f3a; display:flex; flex-direction:column;
           height:100vh; overflow-y:auto; position:fixed; left:0; top:0; z-index:100; }
.sb-bail-logo { padding:18px 16px 12px; border-bottom:1px solid rgba(255,255,255,.1); }
.sb-bail-logo-title { color:white; font-weight:700; font-size:.92em; text-decoration:none; display:block; }
.sb-bail-logo-sub { color:rgba(255,255,255,.45); font-size:.7em; margin-top:2px; }
.sb-bail-section { padding:8px 0; border-bottom:1px solid rgba(255,255,255,.06); }
.sb-bail-section-title { padding:5px 16px 3px; font-size:.64em; text-transform:uppercase;
                          letter-spacing:.1em; color:rgba(255,255,255,.3); }
.sb-bail-link { display:flex; align-items:center; gap:9px; padding:8px 16px;
                text-decoration:none; color:rgba(255,255,255,.65); font-size:.82em;
                transition:all .15s; border-left:3px solid transparent; }
.sb-bail-link:hover { background:rgba(255,255,255,.07); color:white; border-left-color:rgba(255,255,255,.25); }
.sb-bail-link.active { background:rgba(99,117,255,.22); color:white; border-left-color:#6375ff; font-weight:600; }
.sb-bail-link.escape { color:rgba(255,255,255,.45); font-size:.78em; }
.sb-bail-link.escape:hover { color:rgba(255,255,255,.8); }
.sb-bail-icon { font-size:.95em; width:18px; text-align:center; }
.sb-bail-divider { height:1px; background:rgba(255,255,255,.08); margin:4px 16px; }
.sb-bail-footer { margin-top:auto; padding:12px 16px; border-top:1px solid rgba(255,255,255,.08);
                  display:flex; align-items:center; gap:10px; flex-shrink:0; }
.sb-bail-avatar { width:30px; height:30px; border-radius:50%; background:#3f51b5;
                  display:flex; align-items:center; justify-content:center;
                  color:white; font-size:.72em; font-weight:bold; flex-shrink:0; }
.sb-bail-user-name { color:rgba(255,255,255,.75); font-size:.76em; line-height:1.3; }
.sb-bail-logout { color:rgba(255,255,255,.35); font-size:.7em; text-decoration:none; }
.sb-bail-logout:hover { color:rgba(255,255,255,.8); }
.agency-content, .sb-content { margin-left:240px !important; }
/* Compat layout_maboximmo : .mbi-layout-main est en position:fixed left:220px
   (calé pour sidebar_agency) → on le recale sur la largeur 240px de cette sidebar. */
.mbi-layout-main { left:240px !important; }
</style>

<nav class="sb-bail" aria-label="Navigation Bailleur">

  <!-- Logo -->
  <div class="sb-bail-logo">
    <a href="<?= $_sbBase ?>bailleur_dashboard.php" class="sb-bail-logo-title">🏦 Ma Box Bailleur</a>
    <div class="sb-bail-logo-sub">Gestion patrimoniale</div>
  </div>

  <!-- Super admin : retour MBI en haut bien visible -->
  <?php if ($isSA): ?>
  <div class="sb-bail-section" style="padding:6px 0;">
    <a href="<?= $_sbBase ?>agency_dashboard.php" class="sb-bail-link escape">
      <span class="sb-bail-icon">←</span> Retour Ma Box Agency
    </a>
    <a href="<?= $_sbBase ?>rh_dashboard_user.php" class="sb-bail-link escape">
      <span class="sb-bail-icon">←</span> Retour RH
    </a>
  </div>
  <?php endif; ?>

  <!-- Vue d'ensemble (toujours visible) -->
  <div class="sb-bail-section">
    <div class="sb-bail-section-title">Vue d'ensemble</div>
    <a href="<?= $_sbBase ?>bailleur_dashboard.php" class="sb-bail-link <?= sb_bail_active('bailleur_dashboard.php') ?>">
      <span class="sb-bail-icon">📊</span> Tableau de bord
    </a>
    <?php if ($isSA || in_array('patrimoine', $allowedModules)): ?>
    <a href="<?= $_sbBase ?>bailleur_patrimoine_actif.php" class="sb-bail-link <?= sb_bail_active('bailleur_patrimoine_actif.php') ?>">
      <span class="sb-bail-icon">🏛️</span> Patrimoine actif
    </a>
    <a href="<?= $_sbBase ?>bailleur_immeubles.php" class="sb-bail-link <?= sb_bail_active('bailleur_immeubles.php') ?>">
      <span class="sb-bail-icon">🏢</span> Immeubles
    </a>
    <a href="<?= $_sbBase ?>bailleur_biens.php" class="sb-bail-link <?= sb_bail_active('bailleur_biens.php') ?>">
      <span class="sb-bail-icon">🏠</span> Mes biens
    </a>
    <a href="<?= $_sbBase ?>bailleur_sci_organigramme.php" class="sb-bail-link <?= sb_bail_active('bailleur_sci_organigramme.php') ?>">
      <span class="sb-bail-icon">🗂️</span> Organigramme SCI
    </a>
    <?php endif; ?>
  </div>

  <!-- Gestion (selon modules autorisés) -->
  <?php $hasGestion = $isSA || !empty(array_intersect(['revision','bail','ged','contentieux'], $allowedModules)); ?>
  <?php if ($hasGestion): ?>
  <div class="sb-bail-section">
    <div class="sb-bail-section-title">Gestion</div>
    <?php if ($isSA || in_array('revision', $allowedModules)): ?>
    <a href="<?= $_sbBase ?>bailleur_revision_loyer.php" class="sb-bail-link <?= sb_bail_active('bailleur_revision_loyer.php') ?>">
      <span class="sb-bail-icon">📈</span> Révision des loyers
    </a>
    <?php endif; ?>
    <?php if ($isSA || in_array('bail', $allowedModules)): ?>
    <a href="<?= $_sbBase ?>bail_360.php" class="sb-bail-link <?= sb_bail_active('bail_360.php') ?>">
      <span class="sb-bail-icon">📋</span> Bail 360°
    </a>
    <?php endif; ?>
    <?php if ($isSA || in_array('ged', $allowedModules)): ?>
    <a href="<?= $_sbBase ?>bailleur_ged.php" class="sb-bail-link <?= sb_bail_active('bailleur_ged.php') ?>">
      <span class="sb-bail-icon">📁</span> GED Documents
    </a>
    <a href="<?= $_sbBase ?>bailleur_crg_audit.php" class="sb-bail-link <?= sb_bail_active('bailleur_crg_audit.php') ?>">
      <span class="sb-bail-icon">🔎</span> Audit CRG
    </a>
    <?php endif; ?>
    <?php if ($isSA || in_array('contentieux', $allowedModules)): ?>
    <a href="<?= $_sbBase ?>bailleur_contentieux.php" class="sb-bail-link <?= sb_bail_active('bailleur_contentieux.php') ?>">
      <span class="sb-bail-icon">⚖️</span> Contentieux
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Transaction (bailleur avec module transaction) -->
  <?php if (!$isSA && in_array('transaction', $allowedModules, true)): ?>
  <div class="sb-bail-section">
    <div class="sb-bail-section-title">Transaction</div>
    <a href="<?= $_sbBase ?>bailleur_transactions.php" class="sb-bail-link <?= sb_bail_active('bailleur_transactions.php') ?>">
      <span class="sb-bail-icon">🎯</span> Mes ventes
    </a>
  </div>
  <?php endif; ?>

  <!-- Transaction & analyses (super admin) -->
  <?php if ($isSA): ?>
  <div class="sb-bail-section">
    <div class="sb-bail-section-title">Transaction &amp; analyses</div>
    <a href="<?= $_sbBase ?>transaction_index.php" class="sb-bail-link <?= sb_bail_active('transaction_index.php') ?: sb_bail_active('transaction_chargement.php') ?>">
      <span class="sb-bail-icon">🎯</span> Transactions
    </a>
    <a href="<?= $_sbBase ?>creancier_dashboard.php" class="sb-bail-link <?= sb_bail_active('creancier_dashboard.php') ?: (sb_bail_active('creancier_liste.php') ?: sb_bail_active('creancier_dossier360.php')) ?>">
      <span class="sb-bail-icon">⚖️</span> Créanciers
    </a>
    <a href="<?= $_sbBase ?>transaction_portefeuilles_hub.php" class="sb-bail-link <?= sb_bail_active('transaction_portefeuilles_hub.php') ?>">
      <span class="sb-bail-icon">📁</span> Portefeuilles
    </a>
    <a href="<?= $_sbBase ?>investisseur/index.php" class="sb-bail-link <?= sb_bail_active('index.php') ?>">
      <span class="sb-bail-icon">📊</span> Investisseur / Analyses
    </a>
    <a href="<?= $_sbBase ?>gestion/dashboard_sir.php" class="sb-bail-link <?= sb_bail_active('dashboard_sir.php') ?>">
      <span class="sb-bail-icon">🏛️</span> Groupe SIR
    </a>
  </div>
  <?php endif; ?>

  <!-- Administration (super admin uniquement) -->
  <?php if ($isSA): ?>
  <div class="sb-bail-section">
    <div class="sb-bail-section-title">Administration</div>
    <a href="<?= $_sbBase ?>bailleur_admin_comptes.php" class="sb-bail-link <?= sb_bail_active('bailleur_admin_comptes.php') ?>">
      <span class="sb-bail-icon">👥</span> Comptes bailleurs
    </a>
    <a href="<?= $_sbBase ?>bailleur_validation_imports.php" class="sb-bail-link <?= sb_bail_active('bailleur_validation_imports.php') ?>">
      <span class="sb-bail-icon">✅</span> Validation imports
    </a>
    <a href="<?= $_sbBase ?>bailleur_admin.php" class="sb-bail-link <?= sb_bail_active('bailleur_admin.php') ?>">
      <span class="sb-bail-icon">🏢</span> Admin propriétaires
    </a>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="sb-bail-footer">
    <div class="sb-bail-avatar"><?= htmlspecialchars($_bInit) ?></div>
    <div style="flex:1;min-width:0;">
      <div class="sb-bail-user-name"><?= htmlspecialchars(mb_substr($_bNom ?: 'Bailleur', 0, 22)) ?></div>
      <a href="<?= $_sbBase ?>logout.php" class="sb-bail-logout">Déconnexion</a>
    </div>
  </div>

</nav>
