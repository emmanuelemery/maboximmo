<?php
declare(strict_types=1);

/**
 * GED Dashboard — Hub d'entrée unique du module GED
 *
 * Page d'accueil centralisée avec 3 cartes selon le rôle :
 *   1) Mes documents (USER)
 *   2) Administration GED (ADMIN — id_role <= 2)
 *   3) Super Admin GED (SUPERADMIN — id_role = 1)
 *
 * Sidebar : sidebar_ged.php (dédiée GED, 3 niveaux d'accès).
 * Layout  : agency_layout_top.php (standard MBI).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$roleId       = function_exists('current_role_id') ? current_role_id() : 0;
$isManager    = $roleId > 0 && $roleId <= 2;
$isAdmin      = $roleId === 1;
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : ($roleId === 1);

$pageTitle    = 'GED Dashboard';
$pageSubtitle = 'Ma GED Box · Gestion documentaire';
$layoutSidebar = 'sidebar_ged';

// Bouton "Guide GED" en topbar via $topbarActions
ob_start();
?>
<a href="./super_admin_ged_guide.php" target="_blank" rel="noopener"
   class="btn-icon" title="Guide d'utilisation GED">
  <span style="font-size:14px">📖</span>
</a>
<?php
$topbarActions = ob_get_clean();

require_once __DIR__ . '/inc/agency_layout_top.php';

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>

<style>
  .gedh-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
  }
  .gedh-card {
    background: #fff;
    border-radius: 14px;
    padding: 22px 24px;
    box-shadow: 4px 4px 14px #c8c4be, -4px -4px 14px #fff;
    transition: box-shadow .2s ease;
  }
  .gedh-card:hover { box-shadow: 2px 2px 6px #c8c4be, -2px -2px 6px #fff; }
  .gedh-card-header {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 14px; padding-bottom: 12px;
    border-bottom: 1px solid #f0eeec;
  }
  .gedh-card-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    box-shadow: inset 2px 2px 5px #c8c4be, inset -2px -2px 5px #fff;
  }
  .gedh-card.user .gedh-card-icon { background: linear-gradient(135deg,#e0f2fe,#dbeafe); }
  .gedh-card.admin .gedh-card-icon { background: linear-gradient(135deg,#dcfce7,#a7f3d0); }
  .gedh-card.super .gedh-card-icon { background: linear-gradient(135deg,#fef3c7,#fed7aa); }
  .gedh-card-title { flex: 1; }
  .gedh-card-title h2 { margin: 0; font-size: 16px; font-weight: 700; color: #2c2a28; }
  .gedh-card-title p  { margin: 2px 0 0; font-size: 11.5px; color: #9a9690; font-family: 'DM Mono', monospace; }
  .gedh-card-badge {
    font-size: 10px; font-weight: 700; padding: 3px 8px;
    border-radius: 99px; text-transform: uppercase; letter-spacing: .04em;
  }
  .gedh-card.user  .gedh-card-badge { background: #e0f2fe; color: #0369a1; }
  .gedh-card.admin .gedh-card-badge { background: #dcfce7; color: #14532d; }
  .gedh-card.super .gedh-card-badge { background: #fef3c7; color: #92400e; }
  .gedh-links { list-style: none; padding: 0; margin: 0; }
  .gedh-links li { margin: 6px 0; }
  .gedh-links a {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 9px;
    color: #4a4640; text-decoration: none;
    font-size: 13.5px; font-weight: 500;
    transition: background .12s ease, color .12s ease;
  }
  .gedh-links a:hover { background: #f8f7f5; color: #4878a6; }
  .gedh-links .gedh-link-icon { font-size: 14px; width: 20px; text-align: center; }
  .gedh-card-empty {
    padding: 14px; text-align: center; font-size: 12px;
    color: #9a9690; font-style: italic;
  }

  .gedh-intro {
    background: #fff;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 22px;
    border-left: 4px solid #4878a6;
  }
  .gedh-intro h3 { margin: 0 0 6px; font-size: 14px; color: #2c2a28; }
  .gedh-intro p  { margin: 0; font-size: 13px; color: #4a4640; line-height: 1.55; }
</style>

<div class="gedh-intro">
  <h3>📦 Bienvenue dans Ma GED Box</h3>
  <p>
    Centralise la gestion documentaire MaBoxImmo : import en masse, classement
    par cascade N1→N6, validation par lot, archivage par entité métier.
    Choisis ci-dessous l'action selon ton rôle, ou utilise la sidebar à gauche.
  </p>
</div>

<div class="gedh-grid">

  <!-- ─── CARTE USER ─── -->
  <div class="gedh-card user">
    <div class="gedh-card-header">
      <div class="gedh-card-icon">👤</div>
      <div class="gedh-card-title">
        <h2>Mes documents</h2>
        <p>Workflow quotidien — accessible à tous</p>
      </div>
      <span class="gedh-card-badge">USER</span>
    </div>
    <ul class="gedh-links">
      <li><a href="./modules/ged/ged_inbox.php">
        <span class="gedh-link-icon">📨</span>
        <span>Inbox de validation</span>
      </a></li>
      <li><a href="./super_admin_ged_guide.php" target="_blank" rel="noopener">
        <span class="gedh-link-icon">📖</span>
        <span>Guide d'utilisation</span>
      </a></li>
    </ul>
  </div>

  <!-- ─── CARTE ADMIN ─── -->
  <?php if ($isManager || $isAdmin): ?>
  <div class="gedh-card admin">
    <div class="gedh-card-header">
      <div class="gedh-card-icon">⚙️</div>
      <div class="gedh-card-title">
        <h2>Administration GED</h2>
        <p>Arborescence + tests métier</p>
      </div>
      <span class="gedh-card-badge">ADMIN</span>
    </div>
    <ul class="gedh-links">
      <li><a href="./modules/ged/ged_dashboard.php">
        <span class="gedh-link-icon">📊</span>
        <span>Dashboard analyses (V1)</span>
      </a></li>
      <li><a href="./admin/admin_ged_arborescence.php">
        <span class="gedh-link-icon">🌳</span>
        <span>Arborescence par entité</span>
      </a></li>
      <li><a href="./test_ged_arborescence.php">
        <span class="gedh-link-icon">🧪</span>
        <span>Test arborescence</span>
      </a></li>
      <li><a href="./test_ged_metier.php">
        <span class="gedh-link-icon">🧪</span>
        <span>Test scénario métier</span>
      </a></li>
    </ul>
  </div>
  <?php else: ?>
  <div class="gedh-card admin" style="opacity:.55">
    <div class="gedh-card-header">
      <div class="gedh-card-icon">🔒</div>
      <div class="gedh-card-title">
        <h2>Administration GED</h2>
        <p>Réservé managers et admins</p>
      </div>
      <span class="gedh-card-badge">ADMIN</span>
    </div>
    <div class="gedh-card-empty">Accès restreint — contacte l'administrateur si besoin</div>
  </div>
  <?php endif; ?>

  <!-- ─── CARTE SUPERADMIN ─── -->
  <?php if ($isSuperAdmin): ?>
  <div class="gedh-card super">
    <div class="gedh-card-header">
      <div class="gedh-card-icon">👑</div>
      <div class="gedh-card-title">
        <h2>Super Admin GED</h2>
        <p>Configuration profonde du système</p>
      </div>
      <span class="gedh-card-badge">SA</span>
    </div>
    <ul class="gedh-links">
      <li><a href="./super_admin_ged_import.php">
        <span class="gedh-link-icon">📥</span>
        <span>Import GED V2 (bulk)</span>
      </a></li>
      <li><a href="./super_admin_ged_niveaux.php">
        <span class="gedh-link-icon">🗂️</span>
        <span>Niveaux N1→N6</span>
      </a></li>
      <li><a href="./super_admin_coffre_acces.php">
        <span class="gedh-link-icon">🔒</span>
        <span>Coffre accès (mots de passe)</span>
      </a></li>
      <li><a href="./admin/admin_migrations.php">
        <span class="gedh-link-icon">🛠️</span>
        <span>Migrations BDD</span>
      </a></li>
      <li><a href="./super_admin_ged_guide.php" target="_blank" rel="noopener">
        <span class="gedh-link-icon">📖</span>
        <span>Guide complet (HTML)</span>
      </a></li>
    </ul>
  </div>
  <?php else: ?>
  <div class="gedh-card super" style="opacity:.55">
    <div class="gedh-card-header">
      <div class="gedh-card-icon">🔒</div>
      <div class="gedh-card-title">
        <h2>Super Admin GED</h2>
        <p>Réservé id_role = 1</p>
      </div>
      <span class="gedh-card-badge">SA</span>
    </div>
    <div class="gedh-card-empty">Accès restreint super admin</div>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php';
