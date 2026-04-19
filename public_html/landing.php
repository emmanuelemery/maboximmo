<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$userId   = current_user_id();
$roleId   = current_role_id();
$prenom   = $_SESSION['user_prenom'] ?? $_SESSION['prenom'] ?? '';
$nom      = $_SESSION['user_nom']    ?? $_SESSION['nom']    ?? '';

$userServices = getAvailableServices($roleId);
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$roleNom    = '';
$societeNom = '';
$agenceNom  = '';
$logoAgence = '';

try {
    $s = $pdo->prepare("SELECT nom FROM roles WHERE id = ? LIMIT 1");
    $s->execute([$roleId]);
    $roleNom = $s->fetchColumn() ?: '';

    $idSociete = $_SESSION['id_societe'] ?? null;
    $logoSociete = '';
    if ($idSociete) {
        $s = $pdo->prepare("SELECT nom, logo_url FROM societes WHERE id = ? LIMIT 1");
        $s->execute([$idSociete]);
        $socRow = $s->fetch(PDO::FETCH_ASSOC);
        $societeNom  = $socRow['nom'] ?? '';
        $logoSociete = $socRow['logo_url'] ?? '';
    }

    $idAgence = $_SESSION['id_agence'] ?? null;
    if ($idAgence) {
        $s = $pdo->prepare("SELECT nom_agence, logo_url FROM agences WHERE id = ? LIMIT 1");
        $s->execute([$idAgence]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $agenceNom  = $row['nom_agence'] ?? '';
        $logoAgence = $row['logo_url']   ?? '';
    }
} catch (Throwable $ex) {}
?><!doctype html>
<html lang="fr" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Portail — MaBoxImmo</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>

    /* ============================================================
       THÈMES
    ============================================================ */
    [data-theme="dark"] {
      --bg: var(--bg-secondary);
      --bg-card:       #ffffff;
      --bg-card-hover: rgba(72,120,166,0.04);
      --topbar-bg:     rgba(26,42,58,0.97);
      --ink:           #d0e4ff;
      --ink-strong:    #e8f4ff;
      --muted:         #7a91a8;
      --accent:        #4878a6;
      --accent-2:      #ffd479;
      --accent-3:      #4a6038;
      --stroke:        rgba(255,255,255,0.09);
      --stroke-soft:   #ffffff;
      --shadow:        0 12px 40px rgba(0,0,0,0.25);
      --card-border:   rgba(255,255,255,0.09);
      --card-border-h: #4878a6;
      --status-bg:     rgba(124,245,214,0.12);
      --status-c:      #4a6038;
      --logout-bg:     rgba(255,107,122,0.1);
      --logout-border: rgba(255,107,122,0.22);
      --logout-c:      #ff9aab;
      --toggle-bg:     #ffffff;
      --divider:       #ffffff;
      --time-c:        #4878a6;
      --logo-dash:     #f0f1f3;
      --logo-bg:       #f7f8fa;
      --logo-c:        rgba(122,145,168,0.5);
      --profile-glow:  rgba(102,217,255,0.07);
      --digit-bg:      rgba(72,120,166,0.06);
      --digit-sep:     rgba(72,120,166,0.25);
    }

    [data-theme="light"] {
      --bg: var(--bg-secondary);
      --bg-card:       #ffffff;
      --bg-card-hover: rgba(107,142,111,0.03);
      --topbar-bg:     rgba(255,251,248,0.97);
      --ink:           #2d3a2e;
      --ink-strong:    #1a261b;
      --muted:         #7a8a7b;
      --accent:        #6b8e6f;
      --accent-2:      #c97b2e;
      --accent-3:      #2d5f6b;
      --stroke:        rgba(107,142,111,0.14);
      --stroke-soft:   rgba(107,142,111,0.07);
      --shadow:        0 6px 24px rgba(45,58,46,0.08);
      --card-border:   rgba(107,142,111,0.14);
      --card-border-h: #6b8e6f;
      --status-bg:     rgba(107,142,111,0.1);
      --status-c:      #4a6d4e;
      --logout-bg:     rgba(220,53,69,0.07);
      --logout-border: rgba(220,53,69,0.18);
      --logout-c:      #b02a37;
      --toggle-bg:     rgba(107,142,111,0.1);
      --divider:       rgba(107,142,111,0.1);
      --time-c:        #6b8e6f;
      --logo-dash:     rgba(107,142,111,0.25);
      --logo-bg:       rgba(107,142,111,0.04);
      --logo-c:        rgba(107,142,111,0.4);
      --profile-glow:  rgba(107,142,111,0.06);
      --digit-bg:      rgba(107,142,111,0.07);
      --digit-sep:     rgba(107,142,111,0.4);
    }

    /* ============================================================
       BASE
    ============================================================ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: "Manrope", sans-serif;
      background: var(--bg);
      color: var(--ink);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      transition: background 0.3s, color 0.3s;
    }

    a { color: inherit; text-decoration: none; }

    /* ============================================================
       TOPBAR
    ============================================================ */
    .topbar {
      height: 62px;
      background: var(--topbar-bg);
      border-bottom: 1px solid var(--stroke);
      display: flex;
      align-items: center;
      padding: 0 28px;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      backdrop-filter: blur(14px);
    }

    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 16px;
      font-weight: 800;
      color: var(--ink-strong);
    }

    .brand-icon {
      width: 34px;
      height: 34px;
      border-radius: 9px;
      background: linear-gradient(135deg, var(--accent), var(--accent-3));
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 13px;
      color: #fff;
    }

    [data-theme="dark"] .brand-icon { color: #07121b; }

    .topbar-right { display: flex; align-items: center; gap: 10px; }

    .btn-theme {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--toggle-bg);
      border: 1px solid var(--stroke);
      font-size: 15px;
      cursor: pointer;
      display: grid;
      place-items: center;
      transition: all 0.2s;
    }

    .btn-theme:hover { transform: rotate(22deg) scale(1.12); }

    .user-chip {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 4px 12px 4px 5px;
      background: var(--stroke-soft);
      border: 1px solid var(--stroke);
      border-radius: 40px;
    }

    .user-avatar {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent), var(--accent-3));
      display: grid;
      place-items: center;
      font-weight: 700;
      font-size: 12px;
      color: #fff;
    }

    [data-theme="dark"] .user-avatar { color: #0f1822; }

    .user-chip-name { font-size: 12px; font-weight: 600; color: var(--ink); }

    .btn-logout {
      padding: 6px 13px;
      background: var(--logout-bg);
      border: 1px solid var(--logout-border);
      border-radius: 20px;
      color: var(--logout-c);
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      font-family: "Manrope", sans-serif;
      transition: filter 0.18s;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .btn-logout:hover { filter: brightness(1.18); }

    /* ============================================================
       MAIN
    ============================================================ */
    .main {
      flex: 1;
      padding: 36px 36px 40px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 28px;
    }

    /* ============================================================
       LIGNE DU HAUT — identification + logo agence côte à côte
    ============================================================ */
    .top-row {
      display: flex;
      gap: 18px;
      width: 80%;
      max-width: 1100px;
      align-items: stretch;
    }

    /* ── Carte identification ── */
    .profile-card {
      flex: 1;
      background: var(--bg-card);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      padding: 28px 30px;
      display: flex;
      align-items: center;
      gap: 22px;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }

    .profile-card::before {
      content: '';
      position: absolute;
      width: 260px;
      height: 260px;
      right: -50px;
      top: -70px;
      background: radial-gradient(circle, var(--profile-glow), transparent 70%);
      pointer-events: none;
    }

    .profile-avatar {
      width: 62px;
      height: 62px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent), var(--accent-3));
      display: grid;
      place-items: center;
      font-size: 24px;
      font-weight: 800;
      color: #fff;
      flex-shrink: 0;
    }

    [data-theme="dark"] .profile-avatar { color: #0f1822; }

    .profile-info { flex: 1; min-width: 0; }

    .profile-greeting {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: var(--accent);
      margin-bottom: 3px;
    }

    .profile-name {
      font-size: 22px;
      font-weight: 800;
      color: #2d5f6b;
      margin-bottom: 12px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .profile-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; }

    .profile-tag {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600;
      border: 1px solid var(--stroke);
      color: var(--muted);
      background: var(--stroke-soft);
      white-space: nowrap;
    }

    .profile-tag.role    { background: rgba(72,120,166,0.06); border-color: rgba(72,120,166,0.12);  color: var(--accent);   }
    .profile-tag.societe { background: rgba(255,212,121,0.08); border-color: rgba(255,212,121,0.2);  color: var(--accent-2); }
    .profile-tag.agence  { background: rgba(124,245,214,0.08); border-color: rgba(124,245,214,0.2);  color: var(--accent-3); }

    [data-theme="light"] .profile-tag.role    { background: rgba(107,142,111,0.08); border-color: rgba(107,142,111,0.2); color: #4a6d4e; }
    [data-theme="light"] .profile-tag.societe { background: rgba(201,123,46,0.08);  border-color: rgba(201,123,46,0.2);  color: #9a5a1a; }
    [data-theme="light"] .profile-tag.agence  { background: rgba(45,95,107,0.08);   border-color: rgba(45,95,107,0.2);   color: #1d4f5b; }

    /* Barre d'accès rapide dans la carte identification */
    .profile-divider {
      width: 100%;
      height: 1px;
      background: var(--divider);
      margin-bottom: 14px;
    }

    .profile-stats {
      display: flex;
      gap: 0;
      width: 100%;
    }

    .profile-stat {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      padding: 10px 8px;
      border-radius: 12px;
      transition: background 0.18s;
      cursor: default;
    }

    .profile-stat:hover { background: var(--stroke-soft); }

    .profile-stat + .profile-stat {
      border-left: 1px solid var(--divider);
    }

    .stat-icon { font-size: 20px; line-height: 1; }

    .stat-label {
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--muted);
      text-align: center;
    }

    .stat-val {
      font-size: 18px;
      font-weight: 800;
      color: var(--ink-strong);
      line-height: 1;
    }

    /* ── Carte logo agence ── */
    .logo-card {
      width: 240px;
      flex-shrink: 0;
      background: var(--bg-card);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      padding: 20px 18px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      box-shadow: var(--shadow);
      justify-content: flex-start;
    }

    .logo-card-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--muted);
      align-self: flex-start;
    }

    .logo-frame {
      width: 100%;
      aspect-ratio: 1 / 1;
      border: 2px dashed var(--logo-dash);
      border-radius: 12px;
      background: var(--logo-bg);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 14px;
      transition: border-color 0.2s;
      flex-shrink: 0;
    }

    .logo-frame:hover { border-color: var(--accent); }

    .logo-frame img {
      max-width: 100%;
      max-height: 48px;
      object-fit: contain;
    }

    .logo-placeholder-content {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }

    .logo-placeholder-icon { font-size: 20px; opacity: 0.35; }

    .logo-placeholder-text {
      font-size: 9px;
      font-weight: 600;
      color: var(--logo-c);
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      line-height: 1.3;
    }

    /* ============================================================
       HORLOGE À DÉFILEMENT (flip digits)
    ============================================================ */
    .clock-wrap {
      display: flex;
      align-items: center;
      gap: 5px;
      justify-content: center;
      width: 100%;
    }

    .flip-digit {
      width: 34px;
      height: 46px;
      background: var(--digit-bg);
      border: 1px solid var(--stroke);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      font-weight: 800;
      color: var(--time-c);
      font-variant-numeric: tabular-nums;
      overflow: hidden;
      position: relative;
    }

    .flip-digit span {
      display: block;
      transition: transform 0.35s cubic-bezier(0.4,0,0.2,1), opacity 0.35s;
      will-change: transform, opacity;
    }

    .flip-digit span.slide-out {
      transform: translateY(-100%);
      opacity: 0;
    }

    .flip-digit span.slide-in {
      position: absolute;
      transform: translateY(100%);
      opacity: 0;
    }

    .flip-digit span.slide-in.active {
      transform: translateY(0);
      opacity: 1;
    }

    .clock-sep {
      font-size: 20px;
      font-weight: 800;
      color: var(--digit-sep);
      line-height: 1;
      animation: blink 1s step-end infinite;
      margin-bottom: 4px;
    }

    @keyframes blink {
      0%, 100% { opacity: 1; }
      50%       { opacity: 0.2; }
    }

    .clock-date {
      font-size: 10px;
      font-weight: 600;
      color: var(--muted);
      text-align: center;
      text-transform: capitalize;
    }

    /* ============================================================
       SERVICES
    ============================================================ */
    .services-wrap {
      width: 80%;
      max-width: 1100px;
    }

    .services-label {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: #2d5f6b;
      margin-bottom: 14px;
    }

    .services-scroll {
      overflow-x: auto;
      overflow-y: visible;
      padding: 10px 4px 14px;
      margin: -10px -4px -14px;
      scrollbar-width: thin;
      scrollbar-color: var(--stroke) transparent;
    }

    .services-scroll::-webkit-scrollbar { height: 4px; }
    .services-scroll::-webkit-scrollbar-track { background: transparent; }
    .services-scroll::-webkit-scrollbar-thumb { background: var(--stroke); border-radius: 4px; }

    .services-grid {
      display: flex;
      flex-wrap: nowrap;
      gap: 16px;
      min-width: max-content;
    }

    .service-card {
      background: var(--bg-card);
      border: 1px solid var(--card-border);
      border-radius: 18px;
      padding: 26px 24px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      transition: all 0.28s cubic-bezier(0.22,1,0.36,1);
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
      min-height: 220px;
      width: calc(25% - 12px);
      min-width: 200px;
      flex: 1 0 200px;
    }

    /* Reflet lumineux en haut de la card */
    .service-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--card-border-h), transparent);
      opacity: 0;
      transition: opacity 0.3s;
    }

    .service-card::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: radial-gradient(ellipse at 80% 0%, #ffffff 0%, transparent 60%);
      pointer-events: none;
    }

    /* Dark — effet lueur cyan */
    [data-theme="dark"] .service-card:hover {
      border-color: var(--card-border-h);
      transform: translateY(-6px) scale(1.015);
      box-shadow: 0 24px 56px #f7f8fa, 0 0 0 1px var(--card-border-h), 0 0 28px rgba(72,120,166,0.08);
    }

    [data-theme="dark"] .service-card:hover::before { opacity: 1; }

    /* Light — effet élévation + ombre verte */
    [data-theme="light"] .service-card {
      box-shadow: 0 2px 8px rgba(107,142,111,0.08), 0 1px 2px rgba(107,142,111,0.06);
    }

    [data-theme="light"] .service-card::after {
      background: radial-gradient(ellipse at 80% 0%, rgba(107,142,111,0.07) 0%, transparent 60%);
    }

    [data-theme="light"] .service-card:hover {
      border-color: var(--card-border-h);
      transform: translateY(-6px) scale(1.015);
      box-shadow: 0 20px 48px rgba(107,142,111,0.18), 0 0 0 1px rgba(107,142,111,0.3), 0 0 30px rgba(107,142,111,0.1);
    }

    [data-theme="light"] .service-card:hover::before { opacity: 1; }

    .service-icon  { font-size: 44px; line-height: 1; }
    .service-name  { font-size: 18px; font-weight: 800; color: #2d5f6b; }
    .service-desc  { font-size: 13px; color: var(--muted); line-height: 1.65; flex: 1; }

    .service-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding-top: 14px;
      border-top: 1px solid var(--divider);
      margin-top: auto;
    }

    .service-status {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      background: var(--status-bg);
      color: var(--status-c);
    }

    .service-btn {
      padding: 8px 18px;
      border: 1.5px solid var(--accent);
      border-radius: 20px;
      background: transparent;
      color: var(--accent);
      font-weight: 700;
      font-size: 12px;
      cursor: pointer;
      font-family: "Manrope", sans-serif;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.2s;
    }

    .service-btn:hover { background: var(--accent); color: #0f1822; }
    [data-theme="light"] .service-btn:hover { color: #fff; }

    /* ============================================================
       EMPTY STATE / FOOTER
    ============================================================ */
    .empty-state {
      text-align: center;
      padding: 70px 20px;
      color: var(--muted);
    }

    .empty-state-icon { font-size: 54px; margin-bottom: 16px; opacity: 0.4; }
    .empty-state h3 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
    .empty-state p { font-size: 13px; line-height: 1.6; }

    .footer {
      padding: 18px 36px;
      text-align: center;
      color: var(--muted);
      font-size: 11px;
      border-top: 1px solid var(--stroke);
    }

    /* ============================================================
       RESPONSIVE
    ============================================================ */
    @media (max-width: 760px) {
      .top-row { flex-direction: column; }
      .logo-card { width: 100%; flex-direction: row; padding: 18px 22px; }
      .logo-frame { width: 120px; height: 60px; flex-shrink: 0; }
      .clock-wrap { justify-content: flex-start; }
    }

    @media (max-width: 560px) {
      .topbar { padding: 0 16px; }
      .main { padding: 18px 16px; gap: 18px; }
      .profile-card { flex-direction: column; align-items: flex-start; padding: 22px; }
      .profile-name { font-size: 18px; }
      .user-chip-name { display: none; }
      .service-card { min-width: 200px; }
    }

  </style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-brand">
    <?php if ($logoSociete && is_file(__DIR__ . '/' . $logoSociete)): ?>
      <img src="<?= e($logoSociete) ?>" alt="<?= e($societeNom) ?>" style="height:40px;width:auto;object-fit:contain;">
    <?php else: ?>
      <div class="brand-icon">MI</div>
    <?php endif; ?>
    <span><?= $societeNom ?: 'MaBoxImmo' ?></span>
  </div>
  <div class="topbar-right">
    <div class="user-chip">
      <div class="user-avatar"><?=strtoupper(mb_substr($prenom ?: $nom, 0, 1))?></div>
      <span class="user-chip-name"><?=e($prenom)?></span>
    </div>
    <a href="logout.php" class="btn-logout">🚪 Déconnexion</a>
  </div>
</div>

<!-- MAIN -->
<div class="main">

  <!-- LIGNE SUPÉRIEURE : identification + logo agence -->
  <div class="top-row">

    <!-- Carte identification -->
    <div class="profile-card">
      <div class="profile-avatar"><?=strtoupper(mb_substr($prenom ?: $nom, 0, 1))?></div>
      <div class="profile-info">
        <div class="profile-greeting">Bonjour 👋</div>
        <div class="profile-name"><?=e($prenom . ' ' . $nom)?></div>
        <div class="profile-tags">
          <?php if ($roleNom):    ?><span class="profile-tag role"   >🎭 <?=e($roleNom)?></span><?php endif; ?>
          <?php if ($societeNom): ?><span class="profile-tag societe">🏢 <?=e($societeNom)?></span><?php endif; ?>
          <?php if ($agenceNom):  ?><span class="profile-tag agence" >📍 <?=e($agenceNom)?></span><?php endif; ?>
        </div>
        <?php /* KPI masques temporairement — a reactiver quand les donnees seront branchees
        <div class="profile-divider"></div>
        <div class="profile-stats">
          <div class="profile-stat">
            <span class="stat-icon">📋</span>
            <span class="stat-val"><?=count($userServices)?></span>
            <span class="stat-label">Services</span>
          </div>
          <div class="profile-stat">
            <span class="stat-icon">✅</span>
            <span class="stat-val">—</span>
            <span class="stat-label">Tâches</span>
          </div>
          <div class="profile-stat">
            <span class="stat-icon">🔔</span>
            <span class="stat-val">—</span>
            <span class="stat-label">Alertes</span>
          </div>
          <div class="profile-stat">
            <span class="stat-icon">📅</span>
            <span class="stat-val" id="wd">—</span>
            <span class="stat-label">Semaine</span>
          </div>
        </div>
        */ ?>
      </div>
    </div>

    <!-- Carte horloge + logo agence -->
    <div class="logo-card">
      <div class="logo-card-label"><?= e($societeNom ?: 'Agence') ?></div>

      <div class="logo-frame">
        <?php if ($logoSociete && file_exists(__DIR__ . '/' . ltrim($logoSociete, '/'))): ?>
          <img src="<?=e($logoSociete)?>" alt="<?=e($societeNom)?>" style="max-width:90%;max-height:90%;object-fit:contain;">
        <?php elseif ($logoAgence && file_exists(__DIR__ . '/' . ltrim($logoAgence, '/'))): ?>
          <img src="<?=e($logoAgence)?>" alt="<?=e($agenceNom)?>">
        <?php else: ?>
          <div class="logo-placeholder-content">
            <div class="logo-placeholder-icon">🏢</div>
            <div class="logo-placeholder-text"><?=e($societeNom ?: $agenceNom ?: 'Logo à venir')?></div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Séparateur + horloge poussés en bas -->
      <div style="width:100%;height:1px;background:var(--divider);margin-top:auto;"></div>

      <!-- Horloge à défilement -->
      <div class="clock-wrap">
        <div class="flip-digit" id="d-h1"><span>0</span></div>
        <div class="flip-digit" id="d-h2"><span>0</span></div>
        <div class="clock-sep">:</div>
        <div class="flip-digit" id="d-m1"><span>0</span></div>
        <div class="flip-digit" id="d-m2"><span>0</span></div>
        <div class="clock-sep">:</div>
        <div class="flip-digit" id="d-s1"><span>0</span></div>
        <div class="flip-digit" id="d-s2"><span>0</span></div>
      </div>
      <div class="clock-date" id="dateline">--</div>
    </div>

  </div>

  <!-- SERVICES -->
  <div class="services-wrap">
    <div class="services-label">Vos services</div>
    <?php if (!empty($userServices)): ?>
    <div class="services-scroll"><div class="services-grid">
      <?php foreach ($userServices as $slug => $config): ?>
      <div class="service-card">
        <div class="service-icon"><?=e($config['icon'])?></div>
        <div class="service-name"><?=e($config['nom'])?></div>
        <div class="service-desc"><?=e($config['description'])?></div>
        <div class="service-footer">
          <span class="service-status">✓ Accessible</span>
          <a href="<?=e($config['dashboard'])?>" class="service-btn">Accéder →</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div></div>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-state-icon">🔒</div>
      <h3>Aucun service disponible</h3>
      <p>Contactez votre administrateur.</p>
    </div>
    <?php endif; ?>
  </div>

</div>

<div class="footer">© 2026 MaBoxImmo — Tous droits réservés</div>

<script>
  // ── Horloge à défilement ──
  const JOURS = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
  const MOIS  = ['janvier','février','mars','avril','mai','juin','juillet','août',
                 'septembre','octobre','novembre','décembre'];

  const digits = {
    h1: document.getElementById('d-h1'),
    h2: document.getElementById('d-h2'),
    m1: document.getElementById('d-m1'),
    m2: document.getElementById('d-m2'),
    s1: document.getElementById('d-s1'),
    s2: document.getElementById('d-s2'),
  };

  // Valeurs précédentes pour détecter un changement
  const prev = { h1:'', h2:'', m1:'', m2:'', s1:'', s2:'' };

  function animateDigit(el, newVal) {
    const current = el.querySelector('span:not(.slide-in)');
    if (!current || current.textContent === newVal) return;

    // Créer le nouveau chiffre (entrant par le bas)
    const incoming = document.createElement('span');
    incoming.className = 'slide-in';
    incoming.textContent = newVal;
    el.appendChild(incoming);

    // Forcer reflow
    incoming.getBoundingClientRect();

    // Lancer l'animation
    current.classList.add('slide-out');
    incoming.classList.add('active');

    setTimeout(() => {
      current.remove();
      incoming.className = '';
    }, 380);
  }

  function tick() {
    const n   = new Date();
    const h   = String(n.getHours()).padStart(2, '0');
    const m   = String(n.getMinutes()).padStart(2, '0');
    const s   = String(n.getSeconds()).padStart(2, '0');

    animateDigit(digits.h1, h[0]);
    animateDigit(digits.h2, h[1]);
    animateDigit(digits.m1, m[0]);
    animateDigit(digits.m2, m[1]);
    animateDigit(digits.s1, s[0]);
    animateDigit(digits.s2, s[1]);

    document.getElementById('dateline').textContent =
      JOURS[n.getDay()] + ' ' + n.getDate() + ' ' + MOIS[n.getMonth()] + ' ' + n.getFullYear();
  }

  tick();
  setInterval(tick, 1000);

  // Numéro de semaine ISO
  (function() {
    const d = new Date();
    const jan4 = new Date(d.getFullYear(), 0, 4);
    const startOfWeek1 = new Date(jan4);
    startOfWeek1.setDate(jan4.getDate() - ((jan4.getDay() + 6) % 7));
    const week = Math.floor(((d - startOfWeek1) / 86400000) / 7) + 1;
    const el = document.getElementById('wd');
    if (el) el.textContent = 'S' + week;
  })();
</script>
<canvas id="bgCanvas" style="position:fixed;inset:0;z-index:0;pointer-events:none;"></canvas>
<script>window.BG_ORBS_COUNT=5;window.BG_ORBS_DARK=false;</script>
<script src="js/bg_orbs.js"></script>
</body>
</html>
