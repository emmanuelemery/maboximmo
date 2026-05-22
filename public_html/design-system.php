<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_admin_or_super_admin();

// Services disponibles
$services = [
    'rh'           => ['label' => 'RH',           'icon' => '👥', 'file' => 'css/vars/rh.css'],
    'agency'       => ['label' => 'Agency',        'icon' => '🏠', 'file' => 'css/vars/agency.css'],
    'syndic'       => ['label' => 'Syndic',        'icon' => '🏢', 'file' => 'css/vars/syndic.css'],
    'proprietaire' => ['label' => 'Propriétaire',  'icon' => '🔑', 'file' => 'css/vars/proprietaire.css'],
];

// Sauvegarde du CSS via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['css_content'])) {
    verify_csrf_any('design_system');
    $service = $_POST['service'] ?? 'rh';
    if (!array_key_exists($service, $services)) $service = 'rh';
    $targetFile = __DIR__ . '/' . $services[$service]['file'];

    $css = $_POST['css_content'];
    $css = strip_tags($css);
    $result = file_put_contents($targetFile, $css);
    echo json_encode(['success' => $result !== false, 'bytes' => $result, 'file' => $services[$service]['file']]);
    exit;
}

// Lecture des valeurs actuelles pour chaque service
function parseVarsFromCss(string $file): array {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    preg_match_all('/(-{2}[\w-]+)\s*:\s*([^;]+);/', $content, $m);
    $vars = [];
    foreach ($m[1] as $i => $name) {
        $vars[trim($name)] = trim($m[2][$i]);
    }
    return $vars;
}

$serviceVars = [];
foreach ($services as $key => $svc) {
    $serviceVars[$key] = parseVarsFromCss(__DIR__ . '/' . $svc['file']);
}

// Vérification mot de passe (même session que admin_database)
$isVerified = !empty($_SESSION['superadmin_verified']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Design System — MaBoxImmo Super Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ================================================================
       DESIGN SYSTEM PAGE — AUTO-RÉFÉRENTIEL
       Utilise ses propres variables pour se prévisualiser
    ================================================================ */

    /* Variables live (injectées depuis PHP / modifiées par JS) */
    :root {
      --ds-color-primary:         #6b8e6f;
      --ds-color-primary-light:   #7a9e7f;
      --ds-color-primary-dark:    #5a7d5e;
      --ds-color-secondary:       #2d5f6b;
      --ds-color-secondary-light: #3d7f8b;
      --ds-color-secondary-dark:  #1d4f5b;
      --ds-color-white:           #fffbf8;
      --ds-color-beige:           #f4e8d8;
      --ds-color-beige-light:     #faf6f0;
      --ds-color-gray-dark:       #6b6b6b;
      --ds-color-gray-medium:     #757575;
      --ds-color-gray-light:      #b0b0b0;
      --ds-color-gray-lightest:   #e5e5e5;
      --ds-color-success:         #10b981;
      --ds-color-warning:         #f59e0b;
      --ds-color-danger:          #ef4444;
      --ds-color-info:            #0ea5e9;

      /* Thème dark (modules RH/Syndic/Agence) */
      --ds-dark-bg:      #1a2a3a;
      --ds-dark-soft:    #ffffff;
      --ds-dark-sidebar: #2a3a4a;
      --ds-dark-ink:     #d0e4ff;
      --ds-dark-muted:   #7a91a8;
      --ds-dark-accent:  #4878a6;
      --ds-dark-accent2: #ffd479;
      --ds-dark-accent3: #4a6038;

      /* Textes typographiques personnalisables */
      --ds-sidebar-section-color: #7a91a8;   /* Titres de rubriques sidebar */
      --ds-card-title-color:      #d0e4ff;   /* Titres des cards (dark) */
      --ds-card-title-light-color:#2d3a2e;   /* Titres des cards (light) */

      --ds-radius-sm:   4px;
      --ds-radius-md:   8px;
      --ds-radius-lg:   12px;
      --ds-radius-xl:   16px;
      --ds-radius-full: 9999px;

      --ds-btn-opacity:       1;
      --ds-btn-opacity-hover: 0.9;
    }

    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Manrope', sans-serif;
      background: #0f1822;
      color: var(--ds-dark-ink);
      display: flex;
      min-height: 100vh;
      font-size: 14px;
    }

    /* ================================================================
       SIDEBAR CONTRÔLES
    ================================================================ */
    .ds-sidebar {
      width: 300px;
      min-width: 300px;
      background: #111c28;
      border-right: 1px solid #ffffff;
      height: 100vh;
      position: sticky;
      top: 0;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
    }

    .ds-sidebar-header {
      padding: 20px 20px 16px;
      border-bottom: 1px solid #ffffff;
      background: #0d1520;
    }

    .ds-sidebar-header .ds-logo {
      font-size: 16px;
      font-weight: 800;
      color: var(--ds-dark-accent);
      letter-spacing: -0.3px;
    }

    .ds-sidebar-header .ds-logo span {
      color: var(--ds-dark-accent2);
    }

    .ds-sidebar-header p {
      font-size: 11px;
      color: var(--ds-dark-muted);
      margin-top: 4px;
    }

    .ds-section-title {
      padding: 14px 20px 8px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: var(--ds-dark-muted);
      background: rgba(255,255,255,0.02);
      border-bottom: 1px solid #f7f8fa;
    }

    .ds-vars-list {
      padding: 8px 0;
    }

    .ds-var-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 20px;
      transition: background 0.15s;
      cursor: pointer;
    }

    .ds-var-row:hover {
      background: #f7f8fa;
    }

    .ds-color-swatch {
      width: 28px;
      height: 28px;
      border-radius: 6px;
      border: 2px solid #f0f1f3;
      cursor: pointer;
      position: relative;
      flex-shrink: 0;
      transition: transform 0.15s, border-color 0.15s;
      overflow: hidden;
    }

    .ds-color-swatch:hover {
      transform: scale(1.15);
      border-color: rgba(255,255,255,0.4);
    }

    .ds-color-swatch input[type="color"] {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
      border: none;
      padding: 0;
    }

    .ds-var-info {
      flex: 1;
      min-width: 0;
    }

    .ds-var-name {
      font-size: 11px;
      font-weight: 600;
      color: var(--ds-dark-ink);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ds-var-value {
      font-size: 10px;
      color: var(--ds-dark-muted);
      font-family: 'Courier New', monospace;
      margin-top: 1px;
    }

    /* ── Radius sliders ── */
    .ds-slider-row {
      padding: 6px 20px;
    }

    .ds-slider-label {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: var(--ds-dark-ink);
      margin-bottom: 4px;
    }

    .ds-slider-label span {
      color: var(--ds-dark-accent);
      font-family: monospace;
    }

    input[type="range"] {
      width: 100%;
      accent-color: var(--ds-dark-accent);
      cursor: pointer;
    }

    /* ── Actions sidebar ── */
    .ds-actions {
      padding: 16px 20px;
      border-top: 1px solid #ffffff;
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: auto;
    }

    .ds-btn-save {
      background: var(--ds-dark-accent);
      color: #0f1822;
      border: none;
      border-radius: 8px;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.15s, transform 0.1s;
      font-family: 'Manrope', sans-serif;
    }

    .ds-btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
    .ds-btn-save:active { transform: translateY(0); }

    .ds-btn-reset {
      background: #ffffff;
      color: var(--ds-dark-muted);
      border: 1px solid #ffffff;
      border-radius: 8px;
      padding: 8px 16px;
      font-size: 12px;
      cursor: pointer;
      font-family: 'Manrope', sans-serif;
      transition: background 0.15s;
    }

    .ds-btn-reset:hover { background: #ffffff; color: var(--ds-dark-ink); }

    .ds-save-status {
      font-size: 11px;
      text-align: center;
      padding: 4px;
      border-radius: 4px;
      display: none;
    }

    .ds-save-status.success { display: block; background: rgba(124,245,214,0.15); color: var(--ds-dark-accent3); }
    .ds-save-status.error   { display: block; background: rgba(255,107,122,0.15); color: #ff6b7a; }

    /* ================================================================
       MAIN CONTENT
    ================================================================ */
    .ds-main {
      flex: 1;
      overflow-y: auto;
      padding: 32px;
    }

    .ds-page-title {
      font-size: 24px;
      font-weight: 800;
      color: var(--ds-dark-ink);
      margin-bottom: 4px;
    }

    .ds-page-title span { color: var(--ds-dark-accent); }

    .ds-page-sub {
      font-size: 13px;
      color: var(--ds-dark-muted);
      margin-bottom: 36px;
    }

    /* ── Sections ── */
    .ds-block {
      margin-bottom: 48px;
    }

    .ds-block-title {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--ds-dark-accent2);
      padding-bottom: 10px;
      border-bottom: 1px solid #ffffff;
      margin-bottom: 20px;
    }

    .ds-preview-zone {
      background: #f7f8fa;
      border: 1px solid #ffffff;
      border-radius: 12px;
      padding: 24px;
    }

    .ds-preview-zone.light {
      background: var(--ds-color-white);
      border-color: var(--ds-color-gray-lightest);
    }

    .ds-preview-row {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: flex-start;
    }

    .ds-label {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--ds-dark-muted);
      margin-bottom: 8px;
    }

    /* ================================================================
       COMPOSANTS PREVIEW — BOUTONS
    ================================================================ */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 0 20px;
      height: 40px;
      border-radius: var(--ds-radius-full);
      font-family: 'Manrope', sans-serif;
      font-size: 13px;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: all 0.2s;
      text-decoration: none;
      white-space: nowrap;
      opacity: var(--ds-btn-opacity);
    }

    .btn:hover {
      opacity: var(--ds-btn-opacity-hover);
    }

    .btn-primary {
      background: var(--ds-color-primary);
      color: #fff;
    }
    .btn-primary:hover { background: var(--ds-color-primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(107,142,111,0.35); }

    .btn-secondary {
      background: var(--ds-color-secondary);
      color: #fff;
    }
    .btn-secondary:hover { background: var(--ds-color-secondary-dark); transform: translateY(-1px); }

    .btn-outline {
      background: transparent;
      color: var(--ds-color-primary);
      border: 2px solid var(--ds-color-primary);
    }
    .btn-outline:hover { background: var(--ds-color-primary); color: #fff; }

    .btn-ghost {
      background: #ffffff;
      color: var(--ds-dark-ink);
      border: 1px solid #ffffff;
    }
    .btn-ghost:hover { background: #ffffff; }

    .btn-success { background: var(--ds-color-success); color: #fff; }
    .btn-success:hover { filter: brightness(1.1); }

    .btn-warning { background: var(--ds-color-warning); color: #fff; }
    .btn-warning:hover { filter: brightness(1.1); }

    .btn-danger { background: var(--ds-color-danger); color: #fff; }
    .btn-danger:hover { filter: brightness(1.1); }

    .btn-info { background: var(--ds-color-info); color: #fff; }
    .btn-info:hover { filter: brightness(1.1); }

    .btn-sm { height: 30px; padding: 0 12px; font-size: 11px; }
    .btn-lg { height: 50px; padding: 0 28px; font-size: 15px; }

    .btn-accent {
      background: var(--ds-dark-accent);
      color: #0f1822;
      font-weight: 700;
    }
    .btn-accent:hover { opacity: 0.88; transform: translateY(-1px); }

    /* ================================================================
       COMPOSANTS PREVIEW — BADGES
    ================================================================ */
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 3px 10px;
      border-radius: var(--ds-radius-full);
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .badge-primary  { background: rgba(107,142,111,0.2);  color: var(--ds-color-primary); }
    .badge-success  { background: rgba(16,185,129,0.2);   color: var(--ds-color-success); }
    .badge-warning  { background: rgba(245,158,11,0.2);   color: var(--ds-color-warning); }
    .badge-danger   { background: rgba(239,68,68,0.2);    color: var(--ds-color-danger); }
    .badge-info     { background: rgba(14,165,233,0.2);   color: var(--ds-color-info); }
    .badge-pending  { background: rgba(255,165,0,0.2);    color: #ffd700; }
    .badge-done     { background: rgba(124,245,214,0.15); color: var(--ds-dark-accent3); }
    .badge-overdue  { background: rgba(255,107,122,0.2);  color: #ff6b7a; }
    .badge-accent   { background: rgba(72,120,166,0.1); color: var(--ds-dark-accent); }

    /* ================================================================
       COMPOSANTS PREVIEW — CARDS
    ================================================================ */

    /* Card générique (fond clair) */
    .card {
      background: var(--ds-color-white);
      border: 1px solid var(--ds-color-gray-lightest);
      border-radius: var(--ds-radius-lg);
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .card h3 { font-size: 16px; color: #2d3748; margin-bottom: 6px; }
    .card p  { font-size: 13px; color: var(--ds-color-gray-medium); line-height: 1.5; }

    /* Card de navigation (dark) */
    .nav-card {
      background: #ffffff;
      border: 1px solid #ffffff;
      border-radius: 14px;
      padding: 20px;
      cursor: pointer;
      transition: all 0.25s;
    }

    .nav-card:hover {
      border-color: var(--ds-dark-accent);
      background: rgba(72,120,166,0.08);
      transform: translateY(-2px);
    }

    .nav-card-icon  { font-size: 32px; margin-bottom: 10px; display: block; }
    .nav-card-title { font-size: 15px; font-weight: 700; color: var(--ds-card-title-color); }
    .nav-card-desc  { font-size: 12px; color: var(--ds-dark-muted); margin-top: 4px; }

    /* Card KPI / résumé */
    .summary-card {
      background: #ffffff;
      border: 1px solid #ffffff;
      border-radius: 12px;
      padding: 16px;
    }

    .summary-card.accent {
      border-color: var(--ds-dark-accent);
      background: rgba(72,120,166,0.06);
    }

    .summary-card-label { font-size: 11px; font-weight: 600; color: var(--ds-dark-muted); text-transform: uppercase; }
    .summary-card-value { font-size: 28px; font-weight: 800; color: var(--ds-dark-accent3); margin: 4px 0; }
    .summary-card-meta  { font-size: 10px; color: var(--ds-dark-muted); }

    /* Card tâche */
    .task-card {
      background: #ffffff;
      border: 1px solid #ffffff;
      border-radius: 12px;
      padding: 14px;
    }

    .task-card.critical { border-color: #ff6b7a; background: rgba(255,107,122,0.08); }
    .task-card.warning  { border-color: var(--ds-dark-accent2); background: rgba(255,212,121,0.08); }
    .task-card.info-t   { border-color: var(--ds-dark-accent3); background: rgba(124,245,214,0.08); }

    .task-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .task-icon   { font-size: 18px; }
    .task-title  { font-size: 13px; font-weight: 700; color: var(--ds-card-title-color); }
    .task-date   { font-size: 10px; color: var(--ds-dark-muted); }
    .task-desc   { font-size: 11px; color: var(--ds-dark-muted); line-height: 1.4; }

    /* Card service (fond clair) */
    .service-card {
      background: var(--ds-color-white);
      border: 1px solid var(--ds-color-gray-lightest);
      border-radius: var(--ds-radius-xl);
      padding: 24px;
      transition: all 0.25s;
    }

    .service-card:hover {
      border-color: var(--ds-color-primary);
      box-shadow: 0 8px 24px rgba(107,142,111,0.15);
      transform: translateY(-2px);
    }

    .service-card.active { border-color: var(--ds-color-primary); background: rgba(107,142,111,0.04); }
    .service-card.locked { opacity: 0.5; pointer-events: none; }

    .service-icon  { font-size: 36px; margin-bottom: 12px; display: block; }
    .service-title { font-size: 16px; font-weight: 700; color: var(--ds-card-title-light-color); margin-bottom: 6px; }
    .service-desc  { font-size: 13px; color: var(--ds-color-gray-medium); line-height: 1.5; }

    /* Card tarif */
    .pricing-card {
      background: var(--ds-color-white);
      border: 2px solid var(--ds-color-gray-lightest);
      border-radius: var(--ds-radius-xl);
      padding: 28px;
    }

    .pricing-card.featured {
      border-color: var(--ds-color-warning);
      background: linear-gradient(135deg, rgba(245,158,11,0.04), var(--ds-color-white));
    }

    .price-title  { font-size: 17px; font-weight: 700; color: var(--ds-card-title-light-color); }
    .price-amount { font-size: 36px; font-weight: 800; color: var(--ds-color-primary); margin: 8px 0 4px; }
    .price-period { font-size: 12px; color: var(--ds-color-gray-medium); }

    /* Card annonce */
    .announcement-card {
      background: var(--ds-color-white);
      border-radius: var(--ds-radius-lg);
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .announcement-header {
      background: linear-gradient(135deg, var(--ds-color-primary), var(--ds-color-secondary));
      padding: 14px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .announcement-type { font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; }
    .announcement-body { padding: 14px 16px; }
    .announcement-title { font-size: 14px; font-weight: 700; color: #2d3748; margin-bottom: 4px; }
    .announcement-desc  { font-size: 12px; color: var(--ds-color-gray-medium); line-height: 1.4; }

    /* ================================================================
       COMPOSANTS PREVIEW — FORMULAIRES
    ================================================================ */
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .form-group label {
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--ds-color-gray-dark);
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      padding: 10px 14px;
      border: 1.5px solid var(--ds-color-gray-lightest);
      border-radius: var(--ds-radius-md);
      font-family: 'Manrope', sans-serif;
      font-size: 13px;
      color: #2d3748;
      background: var(--ds-color-white);
      transition: border-color 0.2s;
      outline: none;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      border-color: var(--ds-color-primary);
      box-shadow: 0 0 0 3px rgba(107,142,111,0.15);
    }

    /* Dark form variant */
    .form-group.dark label { color: var(--ds-dark-muted); }

    .form-group.dark input,
    .form-group.dark select {
      background: #ffffff;
      border-color: #ffffff;
      color: var(--ds-dark-ink);
    }

    .form-group.dark input:focus {
      border-color: var(--ds-dark-accent);
      box-shadow: 0 0 0 3px rgba(72,120,166,0.1);
    }

    /* Messages */
    .msg {
      padding: 10px 14px;
      border-radius: var(--ds-radius-md);
      font-size: 13px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .msg-success { background: rgba(16,185,129,0.1); color: var(--ds-color-success); border: 1px solid rgba(16,185,129,0.2); }
    .msg-error   { background: rgba(239,68,68,0.1);  color: var(--ds-color-danger);  border: 1px solid rgba(239,68,68,0.2); }
    .msg-warning { background: rgba(245,158,11,0.1); color: var(--ds-color-warning); border: 1px solid rgba(245,158,11,0.2); }
    .msg-info    { background: rgba(14,165,233,0.1); color: var(--ds-color-info);    border: 1px solid rgba(14,165,233,0.2); }

    /* ================================================================
       COMPOSANTS PREVIEW — NAVIGATION
    ================================================================ */
    .preview-topbar {
      background: #ffffff;
      border: 1px solid #ffffff;
      border-radius: 10px;
      padding: 0 20px;
      height: 56px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .preview-topbar-title { font-size: 16px; font-weight: 700; color: var(--ds-dark-ink); }

    .preview-topbar-right { display: flex; align-items: center; gap: 10px; }

    .preview-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--ds-dark-accent), var(--ds-dark-accent3));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      font-weight: 700;
      color: #0f1822;
    }

    .preview-sidebar-mini {
      background: #2a3a4a;
      border-radius: 10px;
      padding: 12px;
      width: 180px;
    }

    /* ── Labels rubriques sidebar (preview) ── */
    .preview-sidebar-section-label {
      padding: 10px 10px 4px;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: var(--ds-sidebar-section-color);
    }

    .preview-sidebar-mini-item {
      padding: 7px 10px;
      border-radius: 6px;
      font-size: 12px;
      color: var(--ds-dark-muted);
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
    }

    .preview-sidebar-mini-item.active {
      background: rgba(72,120,166,0.08);
      color: var(--ds-dark-accent);
    }

    .preview-sidebar-mini-item:hover:not(.active) {
      background: #ffffff;
      color: var(--ds-dark-ink);
    }

    /* ================================================================
       COMPOSANTS PREVIEW — MODAL
    ================================================================ */
    .modal-preview {
      border: 1px solid #ffffff;
      border-radius: 16px;
      overflow: hidden;
      max-width: 400px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    }

    .modal-preview-header {
      background: #ffffff;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid #ffffff;
    }

    .modal-preview-header h3 { font-size: 15px; font-weight: 700; color: var(--ds-dark-ink); }

    .modal-close {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #ffffff;
      border: none;
      color: var(--ds-dark-muted);
      font-size: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .modal-preview-body { background: #1a2a3a; padding: 20px; }

    .modal-preview-footer {
      background: #1a2a3a;
      padding: 12px 20px;
      border-top: 1px solid #ffffff;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }

    /* ================================================================
       COMPOSANTS PREVIEW — COULEURS PALETTE
    ================================================================ */
    .color-palette {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .color-chip {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      position: relative;
      cursor: pointer;
      transition: transform 0.15s;
      display: flex;
      align-items: flex-end;
      justify-content: center;
      padding-bottom: 3px;
    }

    .color-chip:hover { transform: scale(1.1); }

    .color-chip-label {
      font-size: 8px;
      font-weight: 700;
      color: rgba(255,255,255,0.7);
      text-shadow: 0 1px 2px rgba(0,0,0,0.5);
    }

    /* ================================================================
       GRID HELPERS
    ================================================================ */
    .ds-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .ds-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .ds-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }

    @media (max-width: 900px) {
      .ds-grid-3, .ds-grid-4 { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 600px) {
      .ds-sidebar { display: none; }
      .ds-grid-2, .ds-grid-3, .ds-grid-4 { grid-template-columns: 1fr; }
    }

    /* ── Divider ── */
    .ds-divider { height: 1px; background: #ffffff; margin: 8px 0; }

    /* ── Tooltip on swatch ── */
    .ds-var-row:hover .ds-var-value { color: var(--ds-dark-accent); }

    /* ── Indicateurs de variables sur les composants ── */
    .ds-vars-used {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 14px;
      padding-top: 12px;
      border-top: 1px solid #ffffff;
    }

    .ds-var-tag {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 20px;
      background: #ffffff;
      border: 1px solid #ffffff;
      font-size: 10px;
      color: var(--ds-dark-muted);
      cursor: pointer;
      transition: all 0.15s;
      text-decoration: none;
    }

    .ds-var-tag:hover {
      background: rgba(72,120,166,0.08);
      border-color: var(--ds-dark-accent);
      color: var(--ds-dark-accent);
    }

    .ds-var-tag-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0;
      border: 1px solid rgba(255,255,255,0.2);
    }

    .ds-vars-used-title {
      font-size: 10px;
      color: var(--ds-dark-muted);
      width: 100%;
      margin-bottom: 2px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* ── Prévisualisation thème clair ── */
    .ds-main[data-preview="light"] {
      background: var(--ds-color-beige-light, #faf6f0);
    }
    .ds-main[data-preview="light"] .ds-block {
      background: var(--ds-color-white, #fffbf8);
      border-color: var(--ds-color-gray-lightest, #e5e5e5);
    }
    .ds-main[data-preview="light"] .ds-page-title,
    .ds-main[data-preview="light"] .ds-block-title { color: #2d3a2e; }
    .ds-main[data-preview="light"] .ds-label,
    .ds-main[data-preview="light"] .ds-page-sub { color: #7a8a7b; }
    .ds-main[data-preview="light"] .ds-preview-zone {
      background: var(--ds-color-white-alt, #faf6f3);
      border-color: var(--ds-color-gray-lightest, #e5e5e5);
    }
    /* Cards en light */
    .ds-main[data-preview="light"] .nav-card { background: #fff; border-color: rgba(107,142,111,0.18); }
    .ds-main[data-preview="light"] .nav-card-title { color: #2d3a2e; }
    .ds-main[data-preview="light"] .nav-card-desc  { color: #7a8a7b; }
    .ds-main[data-preview="light"] .summary-card { background: #fff; border-color: rgba(107,142,111,0.18); }
    .ds-main[data-preview="light"] .summary-card-label { color: #7a8a7b; }
    .ds-main[data-preview="light"] .summary-card-value { color: var(--ds-color-primary, #6b8e6f); }
    /* Bouton ghost en light */
    .ds-main[data-preview="light"] .btn-ghost { background: rgba(107,142,111,0.08); color: #2d3a2e; border-color: rgba(107,142,111,0.2); }
    .ds-main[data-preview="light"] .btn-accent { background: var(--ds-color-primary, #6b8e6f); color: #fff; }
    /* Formulaires en light */
    .ds-main[data-preview="light"] input,
    .ds-main[data-preview="light"] select,
    .ds-main[data-preview="light"] textarea { background: #fff; color: #2d3a2e; border-color: #d9d9d9; }
    /* Modal en light */
    .ds-main[data-preview="light"] .modal-preview { border-color: #e5e5e5; }
    .ds-main[data-preview="light"] .modal-preview-header { background: #f8f6f2; border-color: #e5e5e5; }
    .ds-main[data-preview="light"] .modal-preview-header h3 { color: #2d3a2e; }
    .ds-main[data-preview="light"] .modal-preview-body,
    .ds-main[data-preview="light"] .modal-preview-footer { background: #fff; }
    .ds-main[data-preview="light"] .ds-vars-used { border-top-color: #e5e5e5; }
    .ds-main[data-preview="light"] .ds-var-tag { background: rgba(107,142,111,0.06); border-color: rgba(107,142,111,0.2); color: #7a8a7b; }

    /* ── Modal password ── */
    body.password-required { overflow: hidden; }

    .password-modal {
      position: fixed;
      inset: 0;
      background: rgba(10,15,25,0.92);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .password-modal.hidden { display: none; }

    .password-modal-content {
      background: #1a2a3a;
      border: 1px solid #ffffff;
      border-radius: 16px;
      padding: 36px;
      width: 380px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.5);
    }

    .password-modal-title {
      font-size: 20px;
      font-weight: 800;
      color: var(--ds-dark-ink);
      margin-bottom: 6px;
    }

    .password-modal-subtitle {
      font-size: 13px;
      color: var(--ds-dark-muted);
      margin-bottom: 24px;
      line-height: 1.5;
    }

    .password-input-group { margin-bottom: 20px; }

    .password-input-label {
      display: block;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--ds-dark-muted);
      margin-bottom: 8px;
    }

    .password-input-field {
      width: 100%;
      padding: 11px 14px;
      background: #ffffff;
      border: 1.5px solid #ffffff;
      border-radius: 8px;
      color: var(--ds-dark-ink);
      font-size: 14px;
      font-family: 'Manrope', sans-serif;
      outline: none;
      transition: border-color 0.2s;
    }

    .password-input-field:focus { border-color: var(--ds-dark-accent); }

    .password-error {
      font-size: 12px;
      color: #ff6b7a;
      margin-top: 6px;
      display: none;
    }

    .password-error.show { display: block; }

    .password-actions { display: flex; gap: 10px; }

    .btn-password-submit {
      flex: 1;
      padding: 11px;
      background: var(--ds-dark-accent);
      color: #0f1822;
      border: none;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      font-family: 'Manrope', sans-serif;
      transition: opacity 0.15s;
    }

    .btn-password-submit:hover:not(:disabled) { opacity: 0.85; }
    .btn-password-submit:disabled { opacity: 0.5; cursor: not-allowed; }

    .btn-password-cancel {
      padding: 11px 16px;
      background: #ffffff;
      color: var(--ds-dark-muted);
      border: 1px solid #ffffff;
      border-radius: 8px;
      font-size: 13px;
      cursor: pointer;
      font-family: 'Manrope', sans-serif;
      transition: background 0.15s;
    }

    .btn-password-cancel:hover { background: #ffffff; }
  </style>
</head>
<body<?php if (!$isVerified) echo ' class="password-required"'; ?>>

<!-- ── MODAL MOT DE PASSE ── -->
<div class="password-modal<?php if ($isVerified) echo ' hidden'; ?>" id="passwordModal">
  <div class="password-modal-content">
    <div class="password-modal-title">🎨 Design System</div>
    <div class="password-modal-subtitle">Entrez votre mot de passe pour accéder au Design System</div>
    <div class="password-input-group">
      <label class="password-input-label">Mot de passe</label>
      <input type="password" id="adminPassword" class="password-input-field" placeholder="••••••••" autocomplete="off">
      <div class="password-error" id="passwordError">Mot de passe incorrect</div>
    </div>
    <div class="password-actions">
      <button class="btn-password-submit" onclick="verifyAdminPassword()">Accéder</button>
      <button class="btn-password-cancel" onclick="window.history.back()">Annuler</button>
    </div>
  </div>
</div>

<!-- ================================================================
     SIDEBAR — CONTRÔLES DE DESIGN
================================================================ -->
<aside class="ds-sidebar" id="dsSidebar">
  <div class="ds-sidebar-header">
    <div class="ds-logo">Ma<span>Box</span>Immo</div>
    <p>Design System · Super Admin</p>
    <a href="landing.php" style="
      display:inline-flex;align-items:center;gap:6px;margin-top:10px;
      padding:7px 12px;border-radius:8px;font-size:11px;font-weight:700;
      background:#ffffff;border:1px solid #ffffff;
      color:var(--ds-dark-muted);text-decoration:none;transition:all 0.18s;
    " onmouseover="this.style.color='var(--ds-dark-ink)';this.style.background='#ffffff'"
       onmouseout="this.style.color='var(--ds-dark-muted)';this.style.background='#ffffff'">
      ← Retour au portail
    </a>
  </div>

  <!-- SÉLECTEUR DE SERVICE -->
  <div style="padding:14px 16px 4px;">
    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--ds-dark-muted);margin-bottom:8px;">Service</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;" id="service-tabs">
      <?php foreach ($services as $key => $svc): ?>
      <button onclick="setService('<?=$key?>')" id="svc-btn-<?=$key?>" data-service="<?=$key?>" style="
        padding:8px 6px;border-radius:8px;border:1px solid #ffffff;
        background:<?=$key==='rh'?'var(--ds-dark-accent)':'#ffffff'?>;
        color:<?=$key==='rh'?'#0f1822':'var(--ds-dark-muted)'?>;
        font-family:Manrope,sans-serif;font-size:11px;font-weight:700;cursor:pointer;
        display:flex;flex-direction:column;align-items:center;gap:2px;transition:all 0.15s;">
        <span style="font-size:16px;"><?=$svc['icon']?></span>
        <span><?=$svc['label']?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="height:1px;background:#ffffff;margin:10px 0 0;"></div>

  <!-- SÉLECTEUR DE THÈME À MODIFIER -->
  <div style="padding:12px 16px 4px;">
    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--ds-dark-muted);margin-bottom:8px;">Thème à modifier</div>
    <div style="display:flex;gap:6px;">
      <button onclick="setEditTheme('light')" id="btn-edit-light" style="flex:1;padding:7px;border-radius:8px;border:1px solid var(--ds-color-primary);background:var(--ds-color-primary);color:#fff;font-family:Manrope,sans-serif;font-size:11px;font-weight:700;cursor:pointer;">☀️ Clair</button>
      <button onclick="setEditTheme('dark')"  id="btn-edit-dark"  style="flex:1;padding:7px;border-radius:8px;border:1px solid #f0f1f3;background:#ffffff;color:var(--ds-dark-muted);font-family:Manrope,sans-serif;font-size:11px;font-weight:700;cursor:pointer;">🌙 Sombre</button>
    </div>
  </div>
  <div style="height:1px;background:#ffffff;margin:12px 0 4px;"></div>

  <!-- COULEURS PRIMAIRES (Thème Clair) -->
  <div class="ds-section-title" id="section-light" style="display:none;">☀️ Thème Clair — Couleurs Primaires</div>
  <div class="ds-vars-list">
    <?php
    $colorVars = [
      // [cssVar, label, defaultValue]
      ['--ds-color-primary',         'Primaire (Vert Kaki)',      '#6b8e6f'],
      ['--ds-color-primary-light',   'Primaire · Light',          '#7a9e7f'],
      ['--ds-color-primary-dark',    'Primaire · Dark',           '#5a7d5e'],
      ['--ds-color-secondary',       'Secondaire (Bleu Pétrole)', '#2d5f6b'],
      ['--ds-color-secondary-light', 'Secondaire · Light',        '#3d7f8b'],
      ['--ds-color-secondary-dark',  'Secondaire · Dark',         '#1d4f5b'],
    ];
    foreach ($colorVars as [$var, $label, $default]) {
      $id = 'swatch-' . str_replace(['--','-'], ['','_'], $var);
      echo "<div class='ds-var-row'>
        <div class='ds-color-swatch' style='background:{$default}' id='{$id}-box'>
          <input type='color' value='{$default}' data-var='{$var}' data-default='{$default}' id='{$id}' onchange='updateColor(this)' oninput='updateColor(this)'>
        </div>
        <div class='ds-var-info'>
          <div class='ds-var-name'>{$label}</div>
          <div class='ds-var-value' id='{$id}-val'>{$default}</div>
        </div>
      </div>";
    }
    ?>
  </div>

  <!-- ═══ GROUPE THÈME CLAIR ═══ -->
  <div id="group-light" style="display:none;">
  <div class="ds-section-title">☀️ Fonctionnelles (Clair)</div>
  <div class="ds-vars-list">
    <?php
    $funcVars = [
      ['--ds-color-success', 'Succès',  '#10b981'],
      ['--ds-color-warning', 'Warning', '#f59e0b'],
      ['--ds-color-danger',  'Danger',  '#ef4444'],
      ['--ds-color-info',    'Info',    '#0ea5e9'],
    ];
    foreach ($funcVars as [$var, $label, $default]) {
      $id = 'swatch-' . str_replace(['--','-'], ['','_'], $var);
      echo "<div class='ds-var-row'>
        <div class='ds-color-swatch' style='background:{$default}' id='{$id}-box'>
          <input type='color' value='{$default}' data-var='{$var}' data-default='{$default}' id='{$id}' onchange='updateColor(this)' oninput='updateColor(this)'>
        </div>
        <div class='ds-var-info'>
          <div class='ds-var-name'>{$label}</div>
          <div class='ds-var-value' id='{$id}-val'>{$default}</div>
        </div>
      </div>";
    }
    ?>
  </div>

  <div class="ds-section-title">☀️ Neutres (Fonds clairs)</div>
  <div class="ds-vars-list">
    <?php
    $neutralVars = [
      ['--ds-color-white',       'Blanc Naturel',  '#fffbf8'],
      ['--ds-color-beige',       'Beige',          '#f4e8d8'],
      ['--ds-color-beige-light', 'Beige Clair',    '#faf6f0'],
      ['--ds-color-gray-dark',   'Gris Foncé',     '#6b6b6b'],
      ['--ds-color-gray-medium', 'Gris Médium',    '#757575'],
      ['--ds-color-gray-light',  'Gris Clair',     '#b0b0b0'],
      ['--ds-color-gray-lightest','Gris Très Clair','#e5e5e5'],
    ];
    foreach ($neutralVars as [$var, $label, $default]) {
      $id = 'swatch-' . str_replace(['--','-'], ['','_'], $var);
      echo "<div class='ds-var-row'>
        <div class='ds-color-swatch' style='background:{$default}' id='{$id}-box'>
          <input type='color' value='{$default}' data-var='{$var}' data-default='{$default}' id='{$id}' onchange='updateColor(this)' oninput='updateColor(this)'>
        </div>
        <div class='ds-var-info'>
          <div class='ds-var-name'>{$label}</div>
          <div class='ds-var-value' id='{$id}-val'>{$default}</div>
        </div>
      </div>";
    }
    ?>
  </div>
  </div><!-- /group-light -->

  <!-- ═══ GROUPE THÈME SOMBRE ═══ -->
  <div id="group-dark">
  <div class="ds-section-title">🌙 Thème Sombre — Modules</div>
  <div class="ds-vars-list">
    <?php
    $darkVars = [
      ['--ds-dark-bg',      'Fond Principal',  '#1a2a3a'],
      ['--ds-dark-soft',    'Fond Doux',       '#ffffff'],
      ['--ds-dark-sidebar', 'Sidebar',         '#2a3a4a'],
      ['--ds-dark-ink',     'Texte',           '#d0e4ff'],
      ['--ds-dark-muted',   'Texte Atténué',   '#7a91a8'],
      ['--ds-dark-accent',  'Accent (Cyan)',   '#4878a6'],
      ['--ds-dark-accent2', 'Accent (Or)',     '#ffd479'],
      ['--ds-dark-accent3', 'Accent (Vert)',   '#4a6038'],
    ];
    foreach ($darkVars as [$var, $label, $default]) {
      $id = 'swatch-' . str_replace(['--','-'], ['','_'], $var);
      echo "<div class='ds-var-row'>
        <div class='ds-color-swatch' style='background:{$default}' id='{$id}-box'>
          <input type='color' value='{$default}' data-var='{$var}' data-default='{$default}' id='{$id}' onchange='updateColor(this)' oninput='updateColor(this)'>
        </div>
        <div class='ds-var-info'>
          <div class='ds-var-name'>{$label}</div>
          <div class='ds-var-value' id='{$id}-val'>{$default}</div>
        </div>
      </div>";
    }
    ?>
  </div>

  </div><!-- /group-dark -->

  <!-- COULEURS TYPOGRAPHIQUES -->
  <div class="ds-section-title">🔤 Textes — Titres & Rubriques</div>
  <div class="ds-vars-list">
    <?php
    $typoVars = [
      ['--ds-sidebar-section-color', 'Titres rubriques sidebar',  '#7a91a8'],
      ['--ds-card-title-color',      'Titres cards (thème dark)', '#d0e4ff'],
      ['--ds-card-title-light-color','Titres cards (thème clair)','#2d3a2e'],
    ];
    foreach ($typoVars as [$var, $label, $default]) {
      $id = 'swatch-' . str_replace(['--','-'], ['','_'], $var);
      echo "<div class='ds-var-row'>
        <div class='ds-color-swatch' style='background:{$default}' id='{$id}-box'>
          <input type='color' value='{$default}' data-var='{$var}' data-default='{$default}' id='{$id}' onchange='updateColor(this)' oninput='updateColor(this)'>
        </div>
        <div class='ds-var-info'>
          <div class='ds-var-name'>{$label}</div>
          <div class='ds-var-value' id='{$id}-val'>{$default}</div>
        </div>
      </div>";
    }
    ?>
  </div>

  <!-- BORDER RADIUS (commun aux deux thèmes) -->
  <div class="ds-section-title">⬛ Border Radius (commun)</div>
  <div class="ds-vars-list">
    <?php
    $radii = [
      ['--ds-radius-sm',  'Radius SM',  4,   0, 24],
      ['--ds-radius-md',  'Radius MD',  8,   0, 32],
      ['--ds-radius-lg',  'Radius LG',  12,  0, 40],
      ['--ds-radius-xl',  'Radius XL',  16,  0, 48],
    ];
    foreach ($radii as [$var, $label, $default, $min, $max]) {
      $id = 'slider-' . str_replace(['--','-'], ['','_'], $var);
      echo "<div class='ds-slider-row'>
        <div class='ds-slider-label'>{$label} <span id='{$id}-val'>{$default}px</span></div>
        <input type='range' min='{$min}' max='{$max}' value='{$default}' data-var='{$var}' data-unit='px' id='{$id}' oninput='updateRadius(this)'>
      </div>";
    }
    ?>
  </div>

  <!-- BOUTONS — OPACITÉ -->
  <div class="ds-section-title">Boutons — Opacité</div>
  <div class="ds-vars-list">
    <div class="ds-slider-row">
      <div class="ds-slider-label">Opacité normale <span id="slider-btn-opacity-val">100%</span></div>
      <input type="range" min="10" max="100" value="100" id="slider-btn-opacity" oninput="updateBtnOpacity(this)">
    </div>
    <div class="ds-slider-row">
      <div class="ds-slider-label">Opacité au survol <span id="slider-btn-opacity-hover-val">90%</span></div>
      <input type="range" min="10" max="100" value="90" id="slider-btn-opacity-hover" oninput="updateBtnOpacity(this)">
    </div>
  </div>

  <!-- ACTIONS -->
  <div class="ds-actions">
    <button class="ds-btn-save" onclick="saveCSS()">💾 Sauvegarder le CSS</button>
    <button class="ds-btn-reset" onclick="resetAll()">↺ Réinitialiser</button>
    <div class="ds-save-status" id="saveStatus"></div>
  </div>
</aside>

<!-- ================================================================
     MAIN CONTENT — PRÉVISUALISATION
================================================================ -->
<main class="ds-main">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:8px;">
    <div>
      <h1 class="ds-page-title" style="margin-bottom:6px;">Design System <span>MaBoxImmo</span></h1>
      <p class="ds-page-sub">Modifiez les couleurs dans le panneau gauche — la prévisualisation se met à jour en temps réel.<br>
      <span style="color:var(--ds-dark-accent);font-size:12px">💡 Cliquez sur un tag coloré pour aller directement à la variable dans la sidebar.</span></p>
    </div>
    <!-- Toggle prévisualisation -->
    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;padding-top:4px;">
      <span style="font-size:11px;color:var(--ds-dark-muted);font-weight:600;">Prévisualiser en :</span>
      <div style="display:flex;border:1px solid #ffffff;border-radius:8px;overflow:hidden;">
        <button id="prev-dark-btn"  onclick="setPreviewTheme('dark')"  style="padding:7px 14px;background:var(--ds-dark-accent);color:#0f1822;font-family:Manrope,sans-serif;font-size:11px;font-weight:700;border:none;cursor:pointer;">🌙 Sombre</button>
        <button id="prev-light-btn" onclick="setPreviewTheme('light')" style="padding:7px 14px;background:transparent;color:var(--ds-dark-muted);font-family:Manrope,sans-serif;font-size:11px;font-weight:700;border:none;cursor:pointer;">☀️ Clair</button>
      </div>
    </div>
  </div>

  <!-- ── 1. BOUTONS ── -->
  <section class="ds-block">
    <div class="ds-block-title">Boutons</div>
    <div class="ds-preview-zone">
      <div class="ds-label" style="margin-bottom:14px">Variantes principales</div>
      <div class="ds-preview-row" style="margin-bottom:20px">
        <button class="btn btn-primary">Primaire</button>
        <button class="btn btn-secondary">Secondaire</button>
        <button class="btn btn-outline">Outline</button>
        <button class="btn btn-ghost">Ghost</button>
        <button class="btn btn-accent">Accent</button>
      </div>
      <div class="ds-label" style="margin-bottom:14px">Variantes fonctionnelles</div>
      <div class="ds-preview-row" style="margin-bottom:20px">
        <button class="btn btn-success">✓ Succès</button>
        <button class="btn btn-warning">⚠ Warning</button>
        <button class="btn btn-danger">✕ Danger</button>
        <button class="btn btn-info">ℹ Info</button>
      </div>
      <div class="ds-label" style="margin-bottom:14px">Tailles</div>
      <div class="ds-preview-row">
        <button class="btn btn-primary btn-sm">Petit</button>
        <button class="btn btn-primary">Normal</button>
        <button class="btn btn-primary btn-lg">Grand</button>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées — cliquez pour scroller</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-primary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-primary)"></span>--color-primary</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-secondary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-secondary)"></span>--color-secondary</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-success')"><span class="ds-var-tag-dot" style="background:var(--ds-color-success)"></span>--color-success</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-warning')"><span class="ds-var-tag-dot" style="background:var(--ds-color-warning)"></span>--color-warning</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-danger')"><span class="ds-var-tag-dot" style="background:var(--ds-color-danger)"></span>--color-danger</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-info')"><span class="ds-var-tag-dot" style="background:var(--ds-color-info)"></span>--color-info</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent)"></span>--dark-accent</a>
      </div>
    </div>
  </section>

  <!-- ── 2. BADGES ── -->
  <section class="ds-block">
    <div class="ds-block-title">Badges & Statuts</div>
    <div class="ds-preview-zone">
      <div class="ds-preview-row">
        <span class="badge badge-primary">Primaire</span>
        <span class="badge badge-success">Succès</span>
        <span class="badge badge-warning">Warning</span>
        <span class="badge badge-danger">Danger</span>
        <span class="badge badge-info">Info</span>
        <span class="badge badge-pending">En attente</span>
        <span class="badge badge-done">Terminé</span>
        <span class="badge badge-overdue">En retard</span>
        <span class="badge badge-accent">Nouveau</span>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-primary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-primary)"></span>--color-primary</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-success')"><span class="ds-var-tag-dot" style="background:var(--ds-color-success)"></span>--color-success</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-warning')"><span class="ds-var-tag-dot" style="background:var(--ds-color-warning)"></span>--color-warning</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-danger')"><span class="ds-var-tag-dot" style="background:var(--ds-color-danger)"></span>--color-danger</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-info')"><span class="ds-var-tag-dot" style="background:var(--ds-color-info)"></span>--color-info</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent)"></span>--dark-accent</a>
      </div>
    </div>
  </section>

  <!-- ── 3. CARDS DARK ── -->
  <section class="ds-block">
    <div class="ds-block-title">Cards — Thème Dark (Modules)</div>

    <div class="ds-label" style="margin-bottom:12px">Cards Navigation</div>
    <div class="ds-grid-4" style="margin-bottom:24px">
      <div class="nav-card">
        <span class="nav-card-icon">👥</span>
        <div class="nav-card-title">Salariés</div>
        <div class="nav-card-desc">Gestion des employés</div>
      </div>
      <div class="nav-card">
        <span class="nav-card-icon">📅</span>
        <div class="nav-card-title">Congés</div>
        <div class="nav-card-desc">Suivi des absences</div>
      </div>
      <div class="nav-card">
        <span class="nav-card-icon">💶</span>
        <div class="nav-card-title">Salaires</div>
        <div class="nav-card-desc">Fiches de paie</div>
      </div>
      <div class="nav-card">
        <span class="nav-card-icon">📄</span>
        <div class="nav-card-title">Documents</div>
        <div class="nav-card-desc">Gestion documentaire</div>
      </div>
    </div>

    <div class="ds-label" style="margin-bottom:12px">Cards KPI / Résumé</div>
    <div class="ds-grid-4" style="margin-bottom:24px">
      <div class="summary-card">
        <div class="summary-card-label">Salariés</div>
        <div class="summary-card-value">24</div>
        <div class="summary-card-meta">+2 ce mois</div>
      </div>
      <div class="summary-card accent">
        <div class="summary-card-label">Congés actifs</div>
        <div class="summary-card-value">7</div>
        <div class="summary-card-meta">Dont 2 critiques</div>
      </div>
      <div class="summary-card">
        <div class="summary-card-label">Documents</div>
        <div class="summary-card-value">142</div>
        <div class="summary-card-meta">3 en attente</div>
      </div>
      <div class="summary-card">
        <div class="summary-card-label">Masse salariale</div>
        <div class="summary-card-value">86k€</div>
        <div class="summary-card-meta">Mois courant</div>
      </div>
    </div>

    <div class="ds-label" style="margin-bottom:12px">Cards Tâches</div>
    <div class="ds-grid-3">
      <div class="task-card critical">
        <div class="task-header">
          <span class="task-icon">🔴</span>
          <div>
            <div class="task-title">Contrat expiré</div>
            <div class="task-date">Échéance : 28/03/2026</div>
          </div>
        </div>
        <div class="task-desc">Le contrat de M. Dupont arrive à expiration cette semaine.</div>
      </div>
      <div class="task-card warning">
        <div class="task-header">
          <span class="task-icon">⚠️</span>
          <div>
            <div class="task-title">Visite médicale</div>
            <div class="task-date">Prévu : 02/04/2026</div>
          </div>
        </div>
        <div class="task-desc">3 salariés n'ont pas encore effectué leur visite annuelle.</div>
      </div>
      <div class="task-card info-t">
        <div class="task-header">
          <span class="task-icon">ℹ️</span>
          <div>
            <div class="task-title">Formation planifiée</div>
            <div class="task-date">Prévu : 10/04/2026</div>
          </div>
        </div>
        <div class="task-desc">Formation sécurité incendie pour l'équipe technique.</div>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-bg')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-bg)"></span>--dark-bg (fond)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-soft')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-soft)"></span>--dark-soft (card)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-ink')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-ink)"></span>--dark-ink (texte)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-muted')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-muted)"></span>--dark-muted (sous-texte)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent)"></span>--dark-accent (hover/KPI)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent2')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent2)"></span>--dark-accent2 (warning)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent3')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent3)"></span>--dark-accent3 (valeurs KPI)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-card-title-color')"><span class="ds-var-tag-dot" style="background:var(--ds-card-title-color)"></span>--card-title-color (titres cards dark)</a>
      </div>
    </div>
  </section>

  <!-- ── 4. CARDS LIGHT ── -->
  <section class="ds-block">
    <div class="ds-block-title">Cards — Thème Clair (Landing / Public)</div>
    <div class="ds-preview-zone light">

      <div class="ds-label" style="color:#6b6b6b;margin-bottom:12px">Cards Services</div>
      <div class="ds-grid-3" style="margin-bottom:24px">
        <div class="service-card active">
          <span class="service-icon">🏢</span>
          <div class="service-title">Syndic</div>
          <div class="service-desc">Gestion de copropriété complète et simplifiée.</div>
        </div>
        <div class="service-card">
          <span class="service-icon">🏠</span>
          <div class="service-title">Agence</div>
          <div class="service-desc">Portail agence immobilière tout-en-un.</div>
        </div>
        <div class="service-card locked">
          <span class="service-icon">🔒</span>
          <div class="service-title">Pro (bientôt)</div>
          <div class="service-desc">Module professionnel en cours de développement.</div>
        </div>
      </div>

      <div class="ds-label" style="color:#6b6b6b;margin-bottom:12px">Cards Tarifs</div>
      <div class="ds-grid-3" style="margin-bottom:24px">
        <div class="pricing-card">
          <div class="price-title">Starter</div>
          <div class="price-amount">29€</div>
          <div class="price-period">/ mois · 1 agence</div>
        </div>
        <div class="pricing-card featured">
          <div class="price-title">Pro ⭐</div>
          <div class="price-amount">79€</div>
          <div class="price-period">/ mois · 5 agences</div>
        </div>
        <div class="pricing-card">
          <div class="price-title">Enterprise</div>
          <div class="price-amount">Sur devis</div>
          <div class="price-period">Illimité</div>
        </div>
      </div>

      <div class="ds-label" style="color:#6b6b6b;margin-bottom:12px">Cards Annonces</div>
      <div class="ds-grid-3">
        <div class="announcement-card">
          <div class="announcement-header">
            <span class="announcement-type">Vente</span>
            <span style="font-size:11px;color:rgba(255,255,255,0.7)">3 pièces</span>
          </div>
          <div class="announcement-body">
            <div class="announcement-title">Appartement T3 — Marseille 13ème</div>
            <div class="announcement-desc">85m² — Vue dégagée — Parking inclus</div>
          </div>
        </div>
        <div class="announcement-card">
          <div class="announcement-header">
            <span class="announcement-type">Location</span>
            <span style="font-size:11px;color:rgba(255,255,255,0.7)">2 pièces</span>
          </div>
          <div class="announcement-body">
            <div class="announcement-title">Studio meublé — Centre-ville</div>
            <div class="announcement-desc">32m² — Toutes charges comprises</div>
          </div>
        </div>
        <div class="announcement-card">
          <div class="announcement-header">
            <span class="announcement-type">Copropriété</span>
          </div>
          <div class="announcement-body">
            <div class="announcement-title">AG Résidence Les Pins</div>
            <div class="announcement-desc">Convocation assemblée générale annuelle</div>
          </div>
        </div>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-primary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-primary)"></span>--color-primary (header annonce)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-secondary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-secondary)"></span>--color-secondary (gradient header)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-warning')"><span class="ds-var-tag-dot" style="background:var(--ds-color-warning)"></span>--color-warning (tarif featured)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-white')"><span class="ds-var-tag-dot" style="background:var(--ds-color-white)"></span>--color-white (fond cards)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-beige-light')"><span class="ds-var-tag-dot" style="background:var(--ds-color-beige-light)"></span>--color-beige-light (sections)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-card-title-light-color')"><span class="ds-var-tag-dot" style="background:var(--ds-card-title-light-color)"></span>--card-title-light-color (titres cards clair)</a>
      </div>
    </div>
  </section>

  <!-- ── 5. CARD GÉNÉRIQUE ── -->
  <section class="ds-block">
    <div class="ds-block-title">Card Générique</div>
    <div class="ds-preview-zone light">
      <div class="ds-grid-3">
        <div class="card">
          <h3>Titre de la card</h3>
          <p>Contenu générique avec du texte explicatif pour illustrer la typographie et l'espacement interne.</p>
        </div>
        <div class="card" style="border-color: var(--ds-color-primary); border-width: 2px;">
          <h3>Card avec bordure</h3>
          <p>Variante avec bordure colorée pour mettre en avant un contenu important.</p>
        </div>
        <div class="card" style="background: var(--ds-color-beige-light);">
          <h3>Card fond beige</h3>
          <p>Variante sur fond beige clair pour un rendu plus naturel et chaleureux.</p>
        </div>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-white')"><span class="ds-var-tag-dot" style="background:var(--ds-color-white)"></span>--color-white (fond)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-primary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-primary)"></span>--color-primary (bordure)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-beige-light')"><span class="ds-var-tag-dot" style="background:var(--ds-color-beige-light)"></span>--color-beige-light (fond variante)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-color-gray-lightest')"><span class="ds-var-tag-dot" style="background:var(--ds-color-gray-lightest)"></span>--color-gray-lightest (bordure)</a>
      </div>
    </div>
  </section>

  <!-- ── 6. FORMULAIRES ── -->
  <section class="ds-block">
    <div class="ds-block-title">Formulaires & Inputs</div>
    <div class="ds-grid-2">
      <div class="ds-preview-zone light">
        <div class="ds-label" style="color:#6b6b6b;margin-bottom:16px">Thème Clair</div>
        <div style="display:flex;flex-direction:column;gap:14px">
          <div class="form-group">
            <label>Nom complet</label>
            <input type="text" placeholder="Jean Dupont">
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" placeholder="jean@exemple.fr">
          </div>
          <div class="form-group">
            <label>Module</label>
            <select>
              <option>Syndic</option>
              <option>Agence</option>
              <option>RH</option>
            </select>
          </div>
          <button class="btn btn-primary">Enregistrer</button>
        </div>
      </div>
      <div class="ds-preview-zone">
        <div class="ds-label" style="margin-bottom:16px">Thème Dark</div>
        <div style="display:flex;flex-direction:column;gap:14px">
          <div class="form-group dark">
            <label>Nom complet</label>
            <input type="text" placeholder="Jean Dupont">
          </div>
          <div class="form-group dark">
            <label>Email</label>
            <input type="email" placeholder="jean@exemple.fr">
          </div>
          <div class="form-group dark">
            <label>Module</label>
            <select>
              <option>Syndic</option>
              <option>Agence</option>
              <option>RH</option>
            </select>
          </div>
          <button class="btn btn-accent">Enregistrer</button>
        </div>
      </div>
    </div>

    <div style="margin-top:16px">
      <div class="ds-preview-zone">
        <div class="ds-label" style="margin-bottom:12px">Messages système</div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <div class="msg msg-success">✓ Enregistrement effectué avec succès.</div>
          <div class="msg msg-error">✕ Une erreur est survenue. Veuillez réessayer.</div>
          <div class="msg msg-warning">⚠ Certains champs sont incomplets.</div>
          <div class="msg msg-info">ℹ Mise à jour disponible pour ce module.</div>
        </div>
        <div class="ds-vars-used">
          <div class="ds-vars-used-title">Variables utilisées</div>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-color-primary')"><span class="ds-var-tag-dot" style="background:var(--ds-color-primary)"></span>--color-primary (focus input)</a>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-color-white')"><span class="ds-var-tag-dot" style="background:var(--ds-color-white)"></span>--color-white (fond input)</a>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-color-gray-lightest')"><span class="ds-var-tag-dot" style="background:var(--ds-color-gray-lightest)"></span>--gray-lightest (bordure)</a>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent)"></span>--dark-accent (focus dark)</a>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-color-success')"><span class="ds-var-tag-dot" style="background:var(--ds-color-success)"></span>--color-success (msg)</a>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-color-danger')"><span class="ds-var-tag-dot" style="background:var(--ds-color-danger)"></span>--color-danger (msg)</a>
          <a class="ds-var-tag" onclick="scrollToVar('--ds-color-warning')"><span class="ds-var-tag-dot" style="background:var(--ds-color-warning)"></span>--color-warning (msg)</a>
        </div>
      </div>
    </div>
  </section>

  <!-- ── 7. NAVIGATION ── -->
  <section class="ds-block">
    <div class="ds-block-title">Navigation</div>
    <div class="ds-preview-zone" style="margin-bottom:16px">
      <div class="ds-label" style="margin-bottom:12px">Topbar</div>
      <div class="preview-topbar">
        <div class="preview-topbar-title">Tableau de bord RH</div>
        <div class="preview-topbar-right">
          <span class="badge badge-accent">Super Admin</span>
          <div class="preview-avatar">JD</div>
        </div>
      </div>
    </div>
    <div class="ds-preview-zone">
      <div class="ds-label" style="margin-bottom:12px">Sidebar (aperçu)</div>
      <div class="preview-sidebar-mini">
        <div class="preview-sidebar-section-label">Principal</div>
        <div class="preview-sidebar-mini-item active">⊞ Dashboard</div>
        <div class="preview-sidebar-section-label">Métier Agency</div>
        <div class="preview-sidebar-mini-item">📝 Mandats</div>
        <div class="preview-sidebar-mini-item">👥 Contacts / CRM</div>
        <div class="preview-sidebar-mini-item">✅ Tâches</div>
        <div class="preview-sidebar-section-label">Biens & Annonces</div>
        <div class="preview-sidebar-mini-item">🏢 Mes biens</div>
        <div class="preview-sidebar-mini-item">📡 Diffusion</div>
        <div class="preview-sidebar-section-label">Mon espace</div>
        <div class="preview-sidebar-mini-item">👤 Mon profil</div>
        <div class="preview-sidebar-mini-item">🚪 Déconnexion</div>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-soft')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-soft)"></span>--dark-soft (topbar)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-sidebar')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-sidebar)"></span>--dark-sidebar (sidebar)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-ink')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-ink)"></span>--dark-ink (texte items)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent)"></span>--dark-accent (item actif)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-muted')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-muted)"></span>--dark-muted (items inactifs)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-sidebar-section-color')"><span class="ds-var-tag-dot" style="background:var(--ds-sidebar-section-color)"></span>--sidebar-section-color (titres rubriques)</a>
      </div>
    </div>
  </section>

  <!-- ── 8. MODAL ── -->
  <section class="ds-block">
    <div class="ds-block-title">Modal</div>
    <div class="ds-preview-zone">
      <div class="modal-preview">
        <div class="modal-preview-header">
          <h3>Ajouter un salarié</h3>
          <button class="modal-close">✕</button>
        </div>
        <div class="modal-preview-body">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div class="form-group dark">
              <label>Nom</label>
              <input type="text" placeholder="Dupont">
            </div>
            <div class="form-group dark">
              <label>Prénom</label>
              <input type="text" placeholder="Jean">
            </div>
          </div>
        </div>
        <div class="modal-preview-footer">
          <button class="btn btn-ghost btn-sm">Annuler</button>
          <button class="btn btn-accent btn-sm">Confirmer</button>
        </div>
      </div>
      <div class="ds-vars-used">
        <div class="ds-vars-used-title">Variables utilisées</div>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-bg')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-bg)"></span>--dark-bg (corps modal)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-soft')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-soft)"></span>--dark-soft (header/footer)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-ink')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-ink)"></span>--dark-ink (titre)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-accent')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-accent)"></span>--dark-accent (bouton confirmer)</a>
        <a class="ds-var-tag" onclick="scrollToVar('--ds-dark-muted')"><span class="ds-var-tag-dot" style="background:var(--ds-dark-muted)"></span>--dark-muted (labels)</a>
      </div>
    </div>
  </section>

  <!-- ── 9. PALETTE COULEURS ── -->
  <section class="ds-block">
    <div class="ds-block-title">Palette Couleurs (référence visuelle)</div>
    <div class="ds-preview-zone">
      <div class="color-palette" id="colorPalette">
        <!-- Généré par JS -->
      </div>
    </div>
  </section>

</main>

<script>
// ================================================================
//  DESIGN SYSTEM — LOGIQUE INTERACTIVE
// ================================================================

// ── Données services (injectées depuis PHP) ──
const serviceVarsData = <?=json_encode($serviceVars, JSON_UNESCAPED_UNICODE)?>;
let currentService = 'rh';

// Balise <style> dédiée pour injecter les variables live
const liveStyle = document.createElement('style');
liveStyle.id = 'ds-live-vars';
document.head.appendChild(liveStyle);

// Map de toutes les variables avec leur valeur courante
const currentVars = {};

// Init depuis les inputs
document.querySelectorAll('input[type="color"]').forEach(input => {
  currentVars[input.dataset.var] = input.dataset.default;
});
document.querySelectorAll('input[type="range"][data-var]').forEach(input => {
  currentVars[input.dataset.var] = input.value + (input.dataset.unit || '');
});

// Init opacité boutons
currentVars['--ds-btn-opacity']       = '1';
currentVars['--ds-btn-opacity-hover'] = '0.9';

const defaults = { ...currentVars };

// ── Toggle : thème à modifier dans la sidebar ──
let currentEditTheme = 'dark';
function setEditTheme(theme) {
  currentEditTheme = theme;
  // Sidebar : afficher/masquer les groupes
  document.getElementById('group-light').style.display = theme === 'light' ? '' : 'none';
  document.getElementById('group-dark').style.display  = theme === 'dark'  ? '' : 'none';
  document.getElementById('section-light').style.display = theme === 'light' ? '' : 'none';
  // Boutons sidebar
  const btnL = document.getElementById('btn-edit-light');
  const btnD = document.getElementById('btn-edit-dark');
  if (theme === 'light') {
    btnL.style.background = 'var(--ds-color-primary)'; btnL.style.color = '#fff'; btnL.style.borderColor = 'var(--ds-color-primary)';
    btnD.style.background = '#ffffff';  btnD.style.color = 'var(--ds-dark-muted)'; btnD.style.borderColor = '#f0f1f3';
  } else {
    btnD.style.background = 'var(--ds-dark-accent)'; btnD.style.color = '#0f1822'; btnD.style.borderColor = 'var(--ds-dark-accent)';
    btnL.style.background = '#ffffff'; btnL.style.color = 'var(--ds-dark-muted)'; btnL.style.borderColor = '#f0f1f3';
  }
  // Synchroniser le preview avec le thème édité
  setPreviewTheme(theme);
}

// ── Toggle : thème de prévisualisation dans le main ──
function setPreviewTheme(theme) {
  const main = document.querySelector('.ds-main');
  main.setAttribute('data-preview', theme);
  const btnD = document.getElementById('prev-dark-btn');
  const btnL = document.getElementById('prev-light-btn');
  if (theme === 'dark') {
    btnD.style.background = 'var(--ds-dark-accent)'; btnD.style.color = '#0f1822';
    btnL.style.background = 'transparent'; btnL.style.color = 'var(--ds-dark-muted)';
  } else {
    btnL.style.background = 'var(--ds-color-primary)'; btnL.style.color = '#fff';
    btnD.style.background = 'transparent'; btnD.style.color = 'var(--ds-dark-muted)';
  }
}

// Init au chargement
setEditTheme('dark');
setService('rh');

// ── Injecte toutes les variables dans le :root via la balise live ──
function applyLiveVars() {
  const rules = Object.entries(currentVars)
    .map(([k, v]) => `  ${k}: ${v};`)
    .join('\n');
  liveStyle.textContent = `:root {\n${rules}\n}`;
}

// ── Mise à jour couleur ──
function updateColor(input) {
  const val = input.value;
  const cssVar = input.dataset.var;

  currentVars[cssVar] = val;
  applyLiveVars();

  // Mettre à jour le swatch de fond
  const swatch = document.getElementById(input.id + '-box');
  if (swatch) swatch.style.background = val;

  // Mettre à jour le texte de valeur
  const valEl = document.getElementById(input.id + '-val');
  if (valEl) valEl.textContent = val;

  buildPalette();
}

// ── Mise à jour opacité boutons ──
function updateBtnOpacity(input) {
  const pct = parseInt(input.value);
  const val = (pct / 100).toFixed(2);
  const isHover = input.id === 'slider-btn-opacity-hover';
  const cssVar = isHover ? '--ds-btn-opacity-hover' : '--ds-btn-opacity';
  const labelId = isHover ? 'slider-btn-opacity-hover-val' : 'slider-btn-opacity-val';

  currentVars[cssVar] = val;
  applyLiveVars();

  const valEl = document.getElementById(labelId);
  if (valEl) valEl.textContent = pct + '%';
}

// ── Mise à jour radius ──
function updateRadius(input) {
  const val = input.value + (input.dataset.unit || '');
  currentVars[input.dataset.var] = val;
  applyLiveVars();
  const valEl = document.getElementById(input.id + '-val');
  if (valEl) valEl.textContent = val;
}

// ── Réinitialisation ──
function resetAll() {
  document.querySelectorAll('input[type="color"]').forEach(input => {
    const def = input.dataset.default;
    input.value = def;
    currentVars[input.dataset.var] = def;
    const swatch = document.getElementById(input.id + '-box');
    if (swatch) swatch.style.background = def;
    const valEl = document.getElementById(input.id + '-val');
    if (valEl) valEl.textContent = def;
  });
  document.querySelectorAll('input[type="range"]').forEach(input => {
    const def = defaults[input.dataset.var];
    const numVal = parseInt(def);
    input.value = numVal;
    currentVars[input.dataset.var] = def;
    const valEl = document.getElementById(input.id + '-val');
    if (valEl) valEl.textContent = def;
  });
  applyLiveVars();
  buildPalette();
  showStatus('', '');
}

// ── Génération du CSS ──
function generateCSS() {
  const now = new Date().toISOString().slice(0,10);

  // Récupérer toutes les valeurs actuelles
  const values = {};
  document.querySelectorAll('input[type="color"]').forEach(input => {
    values[input.dataset.var.replace('--ds-', '--')] = getComputedStyle(document.documentElement).getPropertyValue(input.dataset.var).trim() || input.value;
  });

  // Mapping ds-vars → variables.css vars
  const mapping = {
    '--color-primary':          values['--color-primary']          || getVar('--ds-color-primary'),
    '--color-primary-light':    values['--color-primary-light']    || getVar('--ds-color-primary-light'),
    '--color-primary-dark':     values['--color-primary-dark']     || getVar('--ds-color-primary-dark'),
    '--color-secondary':        values['--color-secondary']        || getVar('--ds-color-secondary'),
    '--color-secondary-light':  values['--color-secondary-light']  || getVar('--ds-color-secondary-light'),
    '--color-secondary-dark':   values['--color-secondary-dark']   || getVar('--ds-color-secondary-dark'),
    '--color-white':            getVar('--ds-color-white'),
    '--color-beige':            getVar('--ds-color-beige'),
    '--color-beige-light':      getVar('--ds-color-beige-light'),
    '--color-gray-dark':        getVar('--ds-color-gray-dark'),
    '--color-gray-medium':      getVar('--ds-color-gray-medium'),
    '--color-gray-light':       getVar('--ds-color-gray-light'),
    '--color-gray-lightest':    getVar('--ds-color-gray-lightest'),
    '--color-success':          getVar('--ds-color-success'),
    '--color-warning':          getVar('--ds-color-warning'),
    '--color-danger':           getVar('--ds-color-danger'),
    '--color-info':             getVar('--ds-color-info'),
  };

  const darkMapping = {
    '--bg':       getVar('--ds-dark-bg'),
    '--bg-soft':  getVar('--ds-dark-soft'),
    '--sidebar':  getVar('--ds-dark-sidebar'),
    '--ink':      getVar('--ds-dark-ink'),
    '--muted':    getVar('--ds-dark-muted'),
    '--accent':   getVar('--ds-dark-accent'),
    '--accent-2': getVar('--ds-dark-accent2'),
    '--accent-3': getVar('--ds-dark-accent3'),
  };

  // Radius
  const radii = {
    '--radius-sm':  getVar('--ds-radius-sm'),
    '--radius-md':  getVar('--ds-radius-md'),
    '--radius-lg':  getVar('--ds-radius-lg'),
    '--radius-xl':  getVar('--ds-radius-xl'),
    '--radius-2xl': '20px',
    '--radius-full': '9999px',
  };

  let css = `/**
 * ============================================================
 * MABOXIMMO - VARIABLES COULEURS & DESIGN
 * Généré par Design System · ${now}
 * ============================================================
 */

:root {
  /* ========== COULEURS PRIMAIRES ========== */
  --color-primary:          ${mapping['--color-primary']};
  --color-primary-light:    ${mapping['--color-primary-light']};
  --color-primary-lighter:  ${lighten(mapping['--color-primary'], 0.15)};
  --color-primary-dark:     ${mapping['--color-primary-dark']};
  --color-primary-darker:   ${darken(mapping['--color-primary'], 0.15)};

  --color-secondary:        ${mapping['--color-secondary']};
  --color-secondary-light:  ${mapping['--color-secondary-light']};
  --color-secondary-lighter:${lighten(mapping['--color-secondary'], 0.15)};
  --color-secondary-dark:   ${mapping['--color-secondary-dark']};
  --color-secondary-darker: ${darken(mapping['--color-secondary'], 0.15)};

  /* ========== COULEURS NEUTRES ========== */
  --color-white:        ${mapping['--color-white']};
  --color-white-alt:    ${darken(mapping['--color-white'], 0.02)};
  --color-white-light:  ${darken(mapping['--color-white'], 0.04)};

  --color-beige:        ${mapping['--color-beige']};
  --color-beige-light:  ${mapping['--color-beige-light']};
  --color-beige-lighter:${lighten(mapping['--color-beige'], 0.05)};

  --color-gray-dark:    ${mapping['--color-gray-dark']};
  --color-gray-medium:  ${mapping['--color-gray-medium']};
  --color-gray-light:   ${mapping['--color-gray-light']};
  --color-gray-lighter: #d9d9d9;
  --color-gray-lightest:${mapping['--color-gray-lightest']};

  /* ========== COULEURS FONCTIONNELLES ========== */
  --color-success:       ${mapping['--color-success']};
  --color-success-light: ${lighten(mapping['--color-success'], 0.2)};
  --color-success-dark:  ${darken(mapping['--color-success'], 0.1)};

  --color-warning:       ${mapping['--color-warning']};
  --color-warning-light: ${lighten(mapping['--color-warning'], 0.2)};
  --color-warning-dark:  ${darken(mapping['--color-warning'], 0.1)};

  --color-danger:        ${mapping['--color-danger']};
  --color-danger-light:  ${lighten(mapping['--color-danger'], 0.2)};
  --color-danger-dark:   ${darken(mapping['--color-danger'], 0.1)};

  --color-info:          ${mapping['--color-info']};
  --color-info-light:    ${lighten(mapping['--color-info'], 0.2)};
  --color-info-dark:     ${darken(mapping['--color-info'], 0.1)};

  /* ========== FONDS ========== */
  --bg-primary:      var(--color-white);
  --bg-secondary:    var(--color-white-alt);
  --bg-section-alt:  var(--color-beige-light);
  --bg-hover:        var(--color-beige);

  /* ========== TEXTES ========== */
  --text-primary:    var(--color-gray-dark);
  --text-secondary:  var(--color-gray-medium);
  --text-light:      var(--color-gray-light);
  --text-on-dark:    var(--color-white);
  --text-on-primary: var(--color-secondary-dark);

  /* ========== BORDURES ========== */
  --border-light:    var(--color-gray-lightest);
  --border-medium:   var(--color-gray-lighter);
  --border-dark:     var(--color-gray-light);
  --border-primary:  var(--color-primary);

  /* ========== OMBRES ========== */
  --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
  --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
  --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
  --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.15);

  /* ========== ESPACEMENTS ========== */
  --spacing-xs:  4px;
  --spacing-sm:  8px;
  --spacing-md:  16px;
  --spacing-lg:  24px;
  --spacing-xl:  32px;
  --spacing-2xl: 48px;

  /* ========== TYPOGRAPHIE ========== */
  --font-family-base: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  --font-family-serif: 'Georgia', 'Garamond', serif;

  --font-size-sm:  12px;
  --font-size-base:14px;
  --font-size-md:  16px;
  --font-size-lg:  18px;
  --font-size-xl:  24px;
  --font-size-2xl: 30px;
  --font-size-3xl: 36px;

  --font-weight-light:    300;
  --font-weight-normal:   400;
  --font-weight-medium:   500;
  --font-weight-semibold: 600;
  --font-weight-bold:     700;

  --line-height-tight:   1.2;
  --line-height-normal:  1.5;
  --line-height-relaxed: 1.75;

  /* ========== BORDER RADIUS ========== */
  --radius-sm:   ${radii['--radius-sm']};
  --radius-md:   ${radii['--radius-md']};
  --radius-lg:   ${radii['--radius-lg']};
  --radius-xl:   ${radii['--radius-xl']};
  --radius-2xl:  ${radii['--radius-2xl']};
  --radius-full: ${radii['--radius-full']};

  /* ========== TRANSITIONS ========== */
  --transition-fast:   150ms ease-in-out;
  --transition-normal: 250ms ease-in-out;
  --transition-slow:   300ms ease-in-out;

  /* ========== Z-INDEX ========== */
  --z-dropdown: 100;
  --z-sticky:   200;
  --z-modal:    1000;
  --z-popover:  1100;
  --z-tooltip:  1200;

  /* ========== LAYOUT ========== */
  --sidebar-width:      200px;
  --header-height:      70px;
  --container-max-width:1200px;
}

/* ========== THÈME DARK — MODULES ========== */
:root {
  --dark-bg:      ${darkMapping['--bg']};
  --dark-bg-soft: ${darkMapping['--bg-soft']};
  --dark-sidebar: ${darkMapping['--sidebar']};
  --dark-ink:     ${darkMapping['--ink']};
  --dark-muted:   ${darkMapping['--muted']};
  --dark-accent:  ${darkMapping['--accent']};
  --dark-accent-2:${darkMapping['--accent-2']};
  --dark-accent-3:${darkMapping['--accent-3']};
  --dark-stroke:  rgba(255, 255, 255, 0.15);
}
`;

  return css;
}

// ── Helpers couleur ──
function getVar(v) {
  return currentVars[v] || getComputedStyle(document.documentElement).getPropertyValue(v).trim();
}

function hexToRgb(hex) {
  hex = hex.replace('#','');
  if (hex.length === 3) hex = hex.split('').map(c=>c+c).join('');
  const n = parseInt(hex, 16);
  return [n>>16, (n>>8)&0xff, n&0xff];
}

function rgbToHex(r,g,b) {
  return '#' + [r,g,b].map(v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2,'0')).join('');
}

function lighten(hex, amount) {
  try {
    let [r,g,b] = hexToRgb(hex);
    r = r + (255 - r) * amount;
    g = g + (255 - g) * amount;
    b = b + (255 - b) * amount;
    return rgbToHex(r,g,b);
  } catch(e) { return hex; }
}

function darken(hex, amount) {
  try {
    let [r,g,b] = hexToRgb(hex);
    r = r * (1 - amount);
    g = g * (1 - amount);
    b = b * (1 - amount);
    return rgbToHex(r,g,b);
  } catch(e) { return hex; }
}

// ── Changement de service ──
function setService(service) {
  currentService = service;

  // Boutons service : actif/inactif
  document.querySelectorAll('[data-service]').forEach(btn => {
    const isActive = btn.dataset.service === service;
    btn.style.background = isActive ? 'var(--ds-dark-accent)' : '#ffffff';
    btn.style.color       = isActive ? '#0f1822' : 'var(--ds-dark-muted)';
    btn.style.borderColor = isActive ? 'var(--ds-dark-accent)' : '#ffffff';
  });

  // Charger les valeurs du service dans les color inputs
  const vars = serviceVarsData[service] || {};
  const svcMap = {
    '--svc-bg':       '--ds-dark-bg',
    '--svc-bg-soft':  '--ds-dark-soft',
    '--svc-sidebar':  '--ds-dark-sidebar',
    '--svc-ink':      '--ds-dark-ink',
    '--svc-muted':    '--ds-dark-muted',
    '--svc-accent':   '--ds-dark-accent',
    '--svc-accent-2': '--ds-dark-accent2',
    '--svc-accent-3': '--ds-dark-accent3',
  };

  Object.entries(svcMap).forEach(([svcVar, dsVar]) => {
    let val = vars[svcVar];
    if (!val || !val.startsWith('#')) return; // ignorer les non-hex pour l'instant
    const input = document.querySelector(`input[data-var="${dsVar}"]`);
    if (input) {
      input.value = val;
      updateColor(input);
    }
  });

  // Mettre à jour le label de sauvegarde
  const labels = { rh:'RH', agency:'Agency', syndic:'Syndic', proprietaire:'Propriétaire' };
  document.querySelector('.ds-btn-save').textContent = `💾 Sauvegarder ${labels[service] || service}`;
}

// ── Génération CSS pour le service courant ──
function generateServiceCSS() {
  const darkVars = {
    '--svc-bg':       getVar('--ds-dark-bg'),
    '--svc-bg-soft':  getVar('--ds-dark-soft'),
    '--svc-sidebar':  getVar('--ds-dark-sidebar'),
    '--svc-ink':      getVar('--ds-dark-ink'),
    '--svc-muted':    getVar('--ds-dark-muted'),
    '--svc-accent':   getVar('--ds-dark-accent'),
    '--svc-accent-2': getVar('--ds-dark-accent2'),
    '--svc-accent-3': getVar('--ds-dark-accent3'),
    '--svc-danger':   getVar('--ds-color-danger'),
    '--svc-success':  getVar('--ds-color-success'),
    '--svc-warning':  getVar('--ds-color-warning'),
  };
  // Light : pour l'instant on reprend les valeurs actuelles du fichier (non modifiables dans cette version)
  const existingVars = serviceVarsData[currentService] || {};
  const lightVarNames = ['--svc-bg','--svc-bg-soft','--svc-sidebar','--svc-ink','--svc-muted',
    '--svc-accent','--svc-accent-2','--svc-accent-3','--svc-danger','--svc-success','--svc-warning'];

  const serviceLabels = { rh:'RH', agency:'Agency', syndic:'Syndic', proprietaire:'Propriétaire' };
  const accentColors  = { rh:'#4878a6', agency:'#ff9f43', syndic:'#a55eea', proprietaire:'#26de81' };

  let css = `/**\n * Variables ${serviceLabels[currentService]} — MaBoxImmo\n`;
  css += ` * Accent : ${accentColors[currentService] || darkVars['--svc-accent']}\n`;
  css += ` * Modifié le : ${new Date().toLocaleString('fr-FR')}\n */\n\n`;
  css += `/* ── Thème sombre ── */\n:root {\n`;
  Object.entries(darkVars).forEach(([k,v]) => { css += `  ${k}: ${v};\n`; });
  css += `}\n\n/* ── Thème clair ── */\n[data-theme="light"] {\n`;
  lightVarNames.forEach(name => {
    // Chercher la valeur light dans le fichier existant (dans le bloc [data-theme="light"])
    const existing = existingVars[name + '__light'] || null;
    // On garde les valeurs light du fichier d'origine si pas modifiées
    const fallback = existingVars[name] || '';
    css += `  ${name}: ${fallback};\n`;
  });
  css += `}\n`;
  return css;
}

// ── Sauvegarde ──
function saveCSS() {
  const css = generateServiceCSS();
  const btn = document.querySelector('.ds-btn-save');
  const labels = { rh:'RH', agency:'Agency', syndic:'Syndic', proprietaire:'Propriétaire' };
  btn.textContent = '⏳ Sauvegarde...';
  btn.disabled = true;

  fetch('design-system.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'css_content=' + encodeURIComponent(css) + '&service=' + encodeURIComponent(currentService)
  })
  .then(r => r.json())
  .then(data => {
    btn.textContent = `💾 Sauvegarder ${labels[currentService]}`;
    btn.disabled = false;
    if (data.success) {
      showStatus(`✓ vars/${currentService}.css mis à jour !`, 'success');
      // Mettre à jour le cache local
      if (serviceVarsData[currentService]) {
        const darkVars = {
          '--svc-accent': getVar('--ds-dark-accent'),
          '--svc-bg':     getVar('--ds-dark-bg'),
        };
        Object.assign(serviceVarsData[currentService], darkVars);
      }
    } else {
      showStatus('✕ Erreur d\'écriture', 'error');
    }
  })
  .catch(() => {
    btn.textContent = `💾 Sauvegarder ${labels[currentService]}`;
    btn.disabled = false;
    showStatus('✕ Erreur réseau', 'error');
  });
}

function showStatus(msg, type) {
  const el = document.getElementById('saveStatus');
  el.className = 'ds-save-status';
  if (type) {
    el.textContent = msg;
    el.classList.add(type);
    setTimeout(() => el.className = 'ds-save-status', 4000);
  }
}

// ── Palette visuelle ──
function buildPalette() {
  const palette = document.getElementById('colorPalette');
  const allVars = [
    '--ds-color-primary', '--ds-color-primary-light', '--ds-color-primary-dark',
    '--ds-color-secondary', '--ds-color-secondary-light', '--ds-color-secondary-dark',
    '--ds-color-success', '--ds-color-warning', '--ds-color-danger', '--ds-color-info',
    '--ds-color-white', '--ds-color-beige', '--ds-color-beige-light',
    '--ds-color-gray-dark', '--ds-color-gray-medium', '--ds-color-gray-light', '--ds-color-gray-lightest',
    '--ds-dark-bg', '--ds-dark-soft', '--ds-dark-sidebar', '--ds-dark-ink',
    '--ds-dark-accent', '--ds-dark-accent2', '--ds-dark-accent3',
  ];

  palette.innerHTML = allVars.map(v => {
    const val = getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const shortName = v.replace('--ds-color-','').replace('--ds-dark-','').replace('--ds-','');
    return `<div class="color-chip" style="background:${val}" title="${v}: ${val}">
      <span class="color-chip-label">${shortName}</span>
    </div>`;
  }).join('');
}

// ── Scroll vers la variable dans la sidebar ──
function scrollToVar(cssVar) {
  const input = document.querySelector(`input[data-var="${cssVar}"]`);
  if (!input) return;
  const sidebar = document.getElementById('dsSidebar');
  const row = input.closest('.ds-var-row') || input.closest('.ds-slider-row');
  if (row) {
    sidebar.scrollTo({ top: row.offsetTop - 80, behavior: 'smooth' });
    // Flash pour indiquer la variable
    row.style.transition = 'background 0.2s';
    row.style.background = 'rgba(72,120,166,0.12)';
    setTimeout(() => row.style.background = '', 1200);
    // Ouvrir le color picker
    if (input.type === 'color') setTimeout(() => input.click(), 300);
  }
}

// ── Vérification mot de passe ──
function verifyAdminPassword() {
  const password = document.getElementById('adminPassword').value;
  const errorDiv = document.getElementById('passwordError');
  const submitBtn = document.querySelector('.btn-password-submit');

  if (!password) {
    errorDiv.textContent = 'Veuillez entrer un mot de passe';
    errorDiv.classList.add('show');
    return;
  }

  submitBtn.disabled = true;
  errorDiv.classList.remove('show');

  fetch('/MaBoxImmo2026/public_html/admin/verify_password.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ password })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      location.reload();
    } else {
      errorDiv.textContent = data.message || 'Mot de passe incorrect';
      errorDiv.classList.add('show');
      submitBtn.disabled = false;
      document.getElementById('adminPassword').value = '';
      document.getElementById('adminPassword').focus();
    }
  })
  .catch(e => {
    errorDiv.textContent = 'Erreur: ' + e.message;
    errorDiv.classList.add('show');
    submitBtn.disabled = false;
  });
}

// ── Entrée sur le champ mot de passe ──
document.addEventListener('DOMContentLoaded', () => {
  const passwordField = document.getElementById('adminPassword');
  if (passwordField) {
    passwordField.addEventListener('keypress', e => {
      if (e.key === 'Enter') verifyAdminPassword();
    });
    if (!document.querySelector('.password-modal.hidden')) {
      passwordField.focus();
    }
  }
});

// Init
applyLiveVars();
buildPalette();
</script>
</body>
</html>
