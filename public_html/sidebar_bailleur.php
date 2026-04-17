<?php
// sidebar_bailleur.php — Composant sidebar MaBoxImmo / Module Bailleur
// Inclusion : include 'sidebar_bailleur.php';

$current_page = $current_page ?? ($_GET['page'] ?? 'dashboard');
$current_script = basename($_SERVER['PHP_SELF'] ?? '');

$bailleur_modules = [
  'biens'      => ['label' => 'Biens locatifs',      'color' => '#4e6e90', 'icon' => 'home'],
  'locataires' => ['label' => 'Locataires',           'color' => '#4e7080', 'icon' => 'users'],
  'loyers'     => ['label' => 'Loyers & Quittances',  'color' => '#4e7270', 'icon' => 'dollar'],
  'baux'       => ['label' => 'Contrats & Baux',      'color' => '#4e7460', 'icon' => 'file'],
  'travaux'    => ['label' => 'Travaux & Entretien',  'color' => '#4e7650', 'icon' => 'wrench'],
];

$bailleur_utilitaires = [
  'arbitrage'      => ['label' => 'Arbitrage patrimonial', 'href' => 'arbitrage_biens.php',      'color' => '#36577d', 'icon' => 'briefcase'],
  'plan_tresorerie'=> ['label' => 'Plan trésorerie 15 M€', 'href' => 'plan_tresorerie.php',      'color' => '#4a6038', 'icon' => 'dollar'],
  'upload_crg'     => ['label' => 'Import CRG',            'href' => 'gestion/upload_crg.php',   'color' => '#5a6e8a', 'icon' => 'file'],
  'crg_audit'      => ['label' => 'Audit CRG',             'href' => 'bailleur_crg_audit.php',   'color' => '#b8922a', 'icon' => 'monitor'],
];

$icons = [
  'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
  'monitor'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
  'briefcase'=> '<path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/>',
  'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
  'users-2'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'mail'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
  'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
  'globe'    => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
  'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
  'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
  'dollar'   => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>',
  'wrench'   => '<path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>',
  'syndic'   => '<rect x="2" y="3" width="20" height="18" rx="2"/><path d="M2 9h20M8 3v18"/>',
  'bailleur' => '<path d="M21 10.5V19a2 2 0 01-2 2H5a2 2 0 01-2-2v-8.5"/><path d="M3 7l9-4 9 4v4H3V7z"/><line x1="12" y1="3" x2="12" y2="22"/>',
];

function svg_icon(string $path, string $stroke = '#8a8680', string $size = '15px'): string {
  return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}
?>

<!-- ════════════════════════════════════
     STYLES SIDEBAR
     ════════════════════════════════════ -->
<style>
/* == SIDEBAR == */
.sb {
  width: 272px;
  min-width: 272px;
  background: #ede8e0;
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow-y: auto;
  box-shadow: 4px 0 16px var(--shadow-dark);
  position: fixed;
  left: 0;
  top: 0;
  z-index: 100;
}

/* Logo */
.sb-logo {
  padding: 22px 20px 16px;
  border-bottom: 1px solid #e4e6ec;
}
.sb-logo-title {
  font-family: 'Sora', sans-serif;
  font-size: 24px;
  font-weight: 700;
  color: #36577d;
  letter-spacing: .02em;
}
.sb-logo-sub {
  font-family: 'DM Mono', monospace;
  font-size: 9px;
  color: #a8a49e;
  letter-spacing: .14em;
  text-transform: uppercase;
  margin-top: 2px;
}
.sb-logo-company {
  font-family: 'Sora', sans-serif;
  font-size: 11px;
  font-weight: 600;
  color: #4a6038;
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid rgba(196,192,186,0.4);
  display: flex;
  align-items: center;
  gap: 6px;
}
.sb-logo-company::before {
  content: '';
  width: 6px; height: 6px; border-radius: 50%;
  background: #7a9060;
  flex-shrink: 0;
}

/* Titres de section */
.sb-section {
  padding: 16px 20px 7px;
  font-family: 'DM Mono', monospace;
  font-size: 10px;
  font-weight: 500;
  letter-spacing: .22em;
  text-transform: uppercase;
  color: #b8922a;
}

/* Séparateur */
.sb-divider {
  height: 1px;
  background: linear-gradient(90deg, transparent, #ccc8c2 20%, #ccc8c2 80%, transparent);
  margin: 8px 0;
}

/* Pilules nav */
.sb-pill {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 0 16px;
  height: 48px;
  cursor: pointer;
  border: none;
  outline: none;
  background: #ede8e0;
  width: calc(100% - 24px);
  margin: 0 12px 10px;
  border-radius: 999px;
  font-family: 'Sora', sans-serif;
  box-shadow: 5px 5px 12px #c8c4be, -5px -5px 12px #fff;
  text-decoration: none;
}
.sb-pill.active {
  box-shadow: inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px #fff;
}
.sb-pill.active .sb-lbl { color: #36577d; font-weight: 700; }
.sb-pill-ico {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: #ede8e0;
  box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px #fff;
}
.sb-pill.active .sb-pill-ico {
  box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px #fff;
}
.sb-lbl {
  font-size: 11.5px;
  font-weight: 500;
  color: #1a1816;
  flex: 1;
  letter-spacing: .06em;
  text-transform: uppercase;
}

/* Boutons module avec trait gauche coloré + fond teinté */
.sb-rh {
  display: flex;
  align-items: center;
  padding: 0 14px 0 0;
  height: 50px;
  cursor: pointer;
  border: none;
  outline: none;
  background: color-mix(in srgb, var(--rh-color) 18%, #ede8e0);
  width: calc(100% - 24px);
  margin: 0 12px 10px;
  border-radius: 14px;
  font-family: 'Sora', sans-serif;
  gap: 10px;
  position: relative;
  overflow: hidden;
  box-shadow: 5px 5px 12px #c8c4be, -5px -5px 12px #fff;
  text-decoration: none;
}
.sb-rh.active {
  background: color-mix(in srgb, var(--rh-color) 30%, #ede8e0);
  box-shadow: inset 4px 4px 10px color-mix(in srgb, var(--rh-color) 40%, var(--shadow-dark)),
              inset -4px -4px 10px #fff;
}
.sb-rh.active .sb-rh-lbl { font-weight: 700; color: color-mix(in srgb, var(--rh-color) 80%, #1a1816); }

/* Trait gauche dégradé vertical */
.sb-rh-bar {
  width: 6px;
  height: 100%;
  flex-shrink: 0;
  border-radius: 14px 0 0 14px;
  background: linear-gradient(180deg, var(--rh-color) 0%, color-mix(in srgb, var(--rh-color) 50%, transparent) 100%);
  opacity: 0;
  transition: opacity .2s;
}
.sb-rh.active .sb-rh-bar { opacity: 1; }

.sb-rh-ico {
  width: 26px;
  height: 26px;
  border-radius: 7px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.sb-rh-ico svg {
  width: 14px;
  height: 14px;
  fill: none;
  stroke: color-mix(in srgb, var(--rh-color) 70%, #4a4840);
  stroke-width: 1.9;
  stroke-linecap: round;
  stroke-linejoin: round;
  transition: stroke .2s;
}
.sb-rh-lbl {
  font-size: 11px;
  font-weight: 500;
  color: #3a3830;
  flex: 1;
  letter-spacing: .04em;
  text-transform: uppercase;
  text-align: left;
}

/* Mini toggle */
.sb-mtog {
  position: relative;
  width: 46px;
  height: 24px;
  border-radius: 999px;
  background: #ede8e0;
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px #fff;
  flex-shrink: 0;
  pointer-events: none;
}
.sb-mthumb {
  position: absolute;
  top: 4px;
  left: 4px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #cc5c58;
  box-shadow: 2px 2px 4px #a84844, -1px -1px 3px #f07c78;
  transition: left .28s cubic-bezier(.4,0,.2,1), background .25s;
}
.sb-rh.active .sb-mthumb {
  left: calc(100% - 20px);
  background: #7a9060;
  box-shadow: 2px 2px 4px #587040, -1px -1px 3px #9ab880;
}

/* Profil admin */
.sb-profile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  cursor: pointer;
  border: none;
  outline: none;
  background: transparent;
  width: 100%;
  text-decoration: none;
}
.sb-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #36577d;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'DM Mono', monospace;
  font-size: 11px;
  font-weight: 500;
  color: var(--bg-primary);
  flex-shrink: 0;
}
.sb-profile-name { font-size: 12px; font-weight: 600; color: #1a1816; }
.sb-profile-role {
  font-family: 'DM Mono', monospace;
  font-size: 8px;
  color: #a8a49e;
  letter-spacing: .1em;
  text-transform: uppercase;
}

/* Déconnexion */
.sb-pill.danger .sb-lbl { color: #cc5c58; }
</style>

<!-- ════════════════════════════════════
     HTML SIDEBAR
     ════════════════════════════════════ -->
<nav class="sb" aria-label="Navigation principale">

  <!-- Logo -->
  <div class="sb-logo">
    <a href="index.php" class="sb-logo-title" style="text-decoration:none;">MaBoxImmo</a>
    <div class="sb-logo-sub">Espace de gestion</div>
    <div class="sb-logo-company">
      <?php echo htmlspecialchars($_SESSION['societe_nom'] ?? 'Agence Demo'); ?>
    </div>
  </div>

  <!-- MA BOX IMMO -->
  <div class="sb-section">Ma Box Immo</div>

  <a href="agency_dashboard.php" class="sb-pill">
    <div class="sb-pill-ico"><?= svg_icon($icons['home'], '#36577d') ?></div>
    <span class="sb-lbl">Agency</span>
  </a>

  <a href="syndic_dashboard.php" class="sb-pill">
    <div class="sb-pill-ico"><?= svg_icon($icons['syndic'], '#5a6e8a') ?></div>
    <span class="sb-lbl">Syndic</span>
  </a>

  <a href="bailleur_dashboard.php" class="sb-pill active">
    <div class="sb-pill-ico"><?= svg_icon($icons['bailleur'], '#4e6e90') ?></div>
    <span class="sb-lbl">Bailleur</span>
  </a>

  <a href="net_dashboard.php" class="sb-pill">
    <div class="sb-pill-ico"><?= svg_icon($icons['globe'], '#3a7ab8') ?></div>
    <span class="sb-lbl">Net</span>
  </a>

  <a href="rh_dashboard.php" class="sb-pill">
    <div class="sb-pill-ico"><?= svg_icon($icons['users-2'], '#5b7eba') ?></div>
    <span class="sb-lbl">RH</span>
  </a>

  <div class="sb-divider"></div>

  <!-- BAILLEUR -->
  <div class="sb-section">Bailleur</div>

  <?php foreach ($bailleur_modules as $key => $mod): ?>
  <a href="?page=<?= $key ?>"
     class="sb-rh <?= ($current_page === $key) ? 'active' : '' ?>"
     style="--rh-color: <?= $mod['color'] ?>;">
    <div class="sb-rh-bar"></div>
    <div class="sb-rh-ico">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
           stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
        <?= $icons[$mod['icon']] ?>
      </svg>
    </div>
    <span class="sb-rh-lbl"><?= htmlspecialchars($mod['label']) ?></span>
    <div class="sb-mtog">
      <div class="sb-mthumb"></div>
    </div>
  </a>
  <?php endforeach; ?>

  <div class="sb-divider"></div>

  <!-- UTILITAIRES -->
  <div class="sb-section">Utilitaires</div>

  <?php foreach ($bailleur_utilitaires as $key => $mod):
      $href = function_exists('app_url') ? app_url('/' . ltrim($mod['href'], '/')) : $mod['href'];
      $isActive = ($current_page === $key) || ($current_script === basename((string)$mod['href']));
  ?>
  <a href="<?= htmlspecialchars($href) ?>"
     class="sb-rh <?= $isActive ? 'active' : '' ?>"
     style="--rh-color: <?= $mod['color'] ?>;">
    <div class="sb-rh-bar"></div>
    <div class="sb-rh-ico">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
           stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
        <?= $icons[$mod['icon']] ?>
      </svg>
    </div>
    <span class="sb-rh-lbl"><?= htmlspecialchars($mod['label']) ?></span>
    <div class="sb-mtog">
      <div class="sb-mthumb"></div>
    </div>
  </a>
  <?php endforeach; ?>

  <div class="sb-divider"></div>

  <!-- ADMIN -->
  <div class="sb-section">Admin</div>

  <a href="?page=profil" class="sb-profile">
    <div class="sb-avatar">MA</div>
    <div style="flex:1;text-align:left;">
      <div class="sb-profile-name">Marie Admin</div>
      <div class="sb-profile-role">Administrateur</div>
    </div>
    <?= svg_icon($icons['settings'], '#a8a49e', '14px') ?>
  </a>

  <a href="logout.php" class="sb-pill danger" style="margin-bottom:16px;">
    <div class="sb-pill-ico"><?= svg_icon($icons['logout'], '#cc5c58') ?></div>
    <span class="sb-lbl">Déconnexion</span>
  </a>

</nav>
