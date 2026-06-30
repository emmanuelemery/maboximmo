<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/rh_helpers.php';

require_login();

$csrfToken = csrf_token('mail_team');

$roleId = current_role_id();
// Mail RH : réservé Admin (8) + Super Admin (1, 7). Ni manager ni collaborateur.
if (!in_array($roleId, [1, 7, 8], true)) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Historique des mails envoyés (50 derniers)
$mailHistory = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sent_by INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        recipient_type VARCHAR(50),
        recipients_count INT DEFAULT 0,
        recipients_json TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (sent_by),
        INDEX (sent_at)
    )");
    $stmtHist = $pdo->prepare("
        SELECT mh.*, CONCAT_WS(' ', u.prenom, u.nom) AS sender_name
        FROM mail_history mh
        LEFT JOIN users u ON mh.sent_by = u.id
        ORDER BY mh.sent_at DESC
        LIMIT 50
    ");
    $stmtHist->execute();
    $mailHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Récupère les sociétés
$societes = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Récupère les agences
$agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);

// Create service column if it doesn't exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'service'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN service VARCHAR(20) DEFAULT 'gestion' AFTER id_societe");
    }
} catch (Exception $e) {}

// Récupère TOUS les users actifs avec leur rôle et service
$users = $pdo->query("
    SELECT u.id, u.prenom, u.nom, u.email, u.id_societe, u.id_agence, u.id_role,
           COALESCE(u.service, 'gestion') as service,
           s.nom as societe_nom, a.nom_agence
    FROM users u
    LEFT JOIN societes s ON u.id_societe = s.id
    LEFT JOIN agences a ON u.id_agence = a.id
    WHERE u.actif = 1
    ORDER BY s.nom, a.nom_agence, u.nom, u.prenom
")->fetchAll(PDO::FETCH_ASSOC);

// Déterminer le service de chaque user
$usersByService = ['gestion' => [], 'syndic' => []];
foreach ($users as &$u) {
    $uRoleId = (int)($u['id_role'] ?? 0);
    if (in_array($uRoleId, [1, 2, 3])) $usersByService['gestion'][] = $u;
    if (in_array($uRoleId, [1, 2, 4])) $usersByService['syndic'][] = $u;
}
unset($u);

$current_page = 'mails';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── KPI counts ──
$kpiTotal    = count($users);
$kpiSocietes = count($societes);
$kpiAgences  = count($agences);
$kpiGestion  = count($usersByService['gestion']);
$kpiSyndic   = count($usersByService['syndic']);
$kpiHistory  = count($mailHistory);

// ══════════════════════════════════════════════════════════════════════════
// Layout variables
// ══════════════════════════════════════════════════════════════════════════
$layout_title   = 'Emails Équipe';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = <<<HTML
<div class="ph-kpi">
    <div class="ph-kpi-val">{$kpiTotal}</div>
    <div class="ph-kpi-lbl">Utilisateurs</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val" style="color:#3a7a6a">{$kpiGestion}</div>
    <div class="ph-kpi-lbl">Gestion</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val" style="color:#7a6830">{$kpiSyndic}</div>
    <div class="ph-kpi-lbl">Syndic</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val" style="color:#8a5040">{$kpiSocietes}</div>
    <div class="ph-kpi-lbl">Sociétés</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val">{$kpiAgences}</div>
    <div class="ph-kpi-lbl">Agences</div>
</div>
<div class="ph-kpi">
    <div class="ph-kpi-val">{$kpiHistory}</div>
    <div class="ph-kpi-lbl">Envoyés</div>
</div>
HTML;

$layout_head_actions = <<<'HTML'
<button class="ph-btn primary" onclick="openTemplateModal()">Modèle</button>
<a class="ph-btn" href="rh_mail_templates.php">+ Créer</a>
<button class="ph-btn" onclick="openHistoryDrawer()">Historique</button>
<span class="ph-btn dispo"></span>
HTML;

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ── Layout mail 3 colonnes ── */
    .mail-layout {
      display: grid;
      grid-template-columns: 2fr 4fr 3fr;
      gap: 15px;
      padding: 10px 12px;
      height: calc(100vh - 56px - 17px - 96px - 28px);
      overflow: hidden;
    }

    /* ── Colonne destinataires ── */
    .mail-sidebar {
      display: flex; flex-direction: column; gap: 14px;
      overflow-y: auto; height: 100%; padding: 8px 10px 8px 6px;
    }
    .mail-sidebar::-webkit-scrollbar { width: 5px; }
    .mail-sidebar::-webkit-scrollbar-thumb { background: var(--shadow-dark); border-radius: 3px; }

    .mail-card {
      background: #d4e8d4; border-radius: 16px;
      box-shadow: 6px 6px 14px #b2ccb2, -6px -6px 14px #f6fff6; padding: 16px;
    }
    .mail-card-title {
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.18em; color: #a8a49e; margin-bottom: 12px;
    }
    .filter-section-title {
      display: flex; align-items: center; gap: 10px;
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.18em; color: #a8a49e; font-weight: 500; margin: 14px 0 8px;
    }
    .filter-section-title::after {
      content: ''; flex: 1; height: 1px; background: rgba(196,192,186,0.5);
    }
    .filter-collapse-btn {
      display: flex; align-items: center; justify-content: space-between;
      width: 100%; padding: 5px 12px;
      background: var(--bg-primary); border: none; border-radius: 999px;
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      cursor: pointer; margin-bottom: 4px; transition: box-shadow 0.12s;
    }
    .filter-collapse-btn:hover { box-shadow: 3px 3px 8px #b2ccb2, -3px -3px 8px #f6fff6; }
    .filter-collapse-btn[aria-expanded="true"] { box-shadow: inset 2px 2px 5px #b2ccb2, inset -2px -2px 5px #f6fff6; }
    .filter-collapse-label {
      display: flex; align-items: center; gap: 6px;
      font-family: 'DM Mono', monospace; font-size: 10px; text-transform: uppercase;
      letter-spacing: 0.12em; color: #6a6660; font-weight: 500;
    }
    .filter-collapse-count {
      background: rgba(196,192,186,0.4); border-radius: 999px;
      padding: 1px 7px; font-size: 10px; color: #8a8680;
    }
    .filter-collapse-arrow {
      font-size: 11px; color: #a8a49e; transition: transform 0.2s; display: inline-block;
    }
    .users-collapsible {
      overflow: hidden; max-height: 0; transition: max-height 0.3s ease; margin-bottom: 2px;
    }
    .users-collapsible.open { max-height: 600px; }
    .mail-filter-item {
      display: flex; align-items: center; gap: 8px;
      padding: 3px 0; cursor: pointer; user-select: none;
    }
    .mail-filter-item input[type="radio"],
    .mail-filter-item input[type="checkbox"] {
      position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none;
    }
    .mail-filter-count {
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 700;
      color: #6a6660; min-width: 32px; text-align: center;
      padding: 4px 8px; border-radius: 999px; flex-shrink: 0;
      background: var(--bg-primary);
      box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      transition: box-shadow 0.15s;
    }
    .mail-filter-item:has(input:checked) .mail-filter-count {
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      color: #4a6038; font-weight: 700;
    }
    .mail-filter-item:hover .mail-filter-count {
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
    }
    .mail-filter-label {
      font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 400;
      color: #6a6660; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .mail-filter-item:has(input:checked) .mail-filter-label {
      color: #4a6038; font-weight: 600;
    }
    .agences-grid { display: flex; flex-direction: column; gap: 0; }
    .users-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px; }
    .users-grid .mail-filter-label { font-size: 11px; }
    .services-row { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }

    .recipients-list {
      background: #dce8f0; border-radius: 10px;
      box-shadow: inset 2px 2px 5px #bdd0de, inset -2px -2px 5px var(--shadow-light);
      padding: 10px 12px; margin-bottom: 14px; min-height: 42px;
    }
    .recipients-list-title {
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.14em; color: #a8a49e; margin-bottom: 7px;
    }
    .recipients-list-content { display: flex; flex-wrap: wrap; gap: 5px; }
    .recipient-item {
      display: inline-flex; align-items: center; gap: 4px;
      background: var(--bg-primary); border-radius: 999px;
      box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
      padding: 3px 10px; font-family: 'Sora', sans-serif; font-size: 11px;
      color: #3a3632; font-weight: 500;
    }
    .recipient-remove {
      background: none; border: none; color: #8a5040; cursor: pointer;
      font-size: 14px; padding: 0; line-height: 1;
    }

    .mail-composer {
      display: flex; flex-direction: column; gap: 12px;
      height: 100%; overflow-y: auto; padding: 8px 6px;
    }
    .mail-composer::-webkit-scrollbar { width: 5px; }
    .mail-composer::-webkit-scrollbar-thumb { background: var(--shadow-dark); border-radius: 3px; }
    .mail-composer form { display: flex; flex-direction: column; gap: 12px; }
    .composer-message-card { display: flex !important; flex-direction: column; }
    .composer-body {
      min-height: 120px; resize: none !important; overflow: hidden; field-sizing: content;
    }

    .ia-column {
      display: flex; flex-direction: column; gap: 12px;
      overflow-y: auto; height: 100%; padding: 8px 6px 8px 4px;
    }
    .ia-column::-webkit-scrollbar { width: 5px; }
    .ia-column::-webkit-scrollbar-thumb { background: var(--shadow-dark); border-radius: 3px; }
    .ia-card {
      background: var(--bg-primary); border-radius: 16px;
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      padding: 18px; display: flex; flex-direction: column; gap: 12px;
    }
    .ia-card-title {
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.18em; color: #a8a49e; margin-bottom: 2px;
    }
    .ia-textarea {
      background: var(--bg-primary); border: none; border-radius: 10px;
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      padding: 10px 12px; font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
      outline: none; resize: vertical; line-height: 1.6; width: 100%; min-height: 90px;
    }

    .composer-section {
      background: var(--bg-primary); border-radius: 16px;
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      padding: 16px 20px; flex-shrink: 0;
    }
    .composer-section-title {
      font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
      letter-spacing: 0.18em; color: #a8a49e; margin-bottom: 14px;
    }
    .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
    .form-group:last-child { margin-bottom: 0; }
    .form-label {
      font-family: 'DM Mono', monospace; font-size: 10px; text-transform: uppercase;
      letter-spacing: 0.10em; color: #8a8680; font-weight: 500;
    }
    .form-control {
      background: var(--bg-primary); border: none; border-radius: 10px;
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      padding: 9px 14px; font-family: 'Sora', sans-serif; font-size: 13px;
      color: #1a1816; outline: none; width: 100%; box-sizing: border-box;
    }
    .form-control:focus {
      box-shadow: inset 4px 4px 9px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light), 0 0 0 2px rgba(54,87,125,0.2);
    }
    textarea.form-control { min-height: 200px; resize: vertical; line-height: 1.6; }

    .attach-zone {
      display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
      background: var(--bg-primary); border: 2px dashed rgba(74,96,56,0.25);
      border-radius: 10px; font-family: 'Sora', sans-serif;
      font-size: 12px; font-weight: 600; color: #8a8680;
      cursor: pointer; transition: border-color 0.15s, color 0.15s;
    }
    .attach-zone:hover { border-color: rgba(74,96,56,0.5); color: #4a6038; }
    #attachments-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }

    .button-group { display: flex; gap: 10px; justify-content: flex-end; }
    .btn-primary {
      padding: 10px 22px; border: none; border-radius: 12px;
      background: #36577d; color: var(--bg-secondary);
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
      cursor: pointer; box-shadow: 3px 3px 8px rgba(54,87,125,0.35);
    }
    .btn-primary:hover { opacity: 0.9; }
    .btn-secondary {
      padding: 10px 18px; border: none; border-radius: 12px;
      background: var(--bg-primary); color: #6a6660;
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
      cursor: pointer; box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-secondary:hover { color: #1a1816; }

    #history-drawer {
      display: none; position: fixed; top: 0; right: 0; bottom: 0; width: 480px;
      max-width: 95vw; background: #eae6e0; border-left: 1px solid rgba(196,192,186,0.6);
      z-index: 9001; flex-direction: column; overflow: hidden;
      box-shadow: -8px 0 24px rgba(26,24,22,0.12);
    }
    .drawer-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 20px 16px; border-bottom: 1px solid rgba(196,192,186,0.4); flex-shrink: 0;
    }
    .drawer-header-title { font-family:'Sora'; font-size:15px; font-weight:700; color:#1a1816; }
    .drawer-header-sub { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; margin-top:2px; }
    .drawer-close {
      background: var(--bg-primary); border: none; border-radius: 8px;
      box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
      width: 32px; height: 32px; cursor: pointer; font-size: 18px; color: #6a6660;
      display: flex; align-items: center; justify-content: center;
    }
    .drawer-body { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
    .hist-item {
      background: var(--bg-primary); border-radius: 12px;
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); overflow: hidden;
    }
    .hist-summary {
      padding: 10px 14px; cursor: pointer;
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      list-style: none; user-select: none;
    }
    .hist-summary::-webkit-details-marker { display: none; }
    .hist-date { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; white-space:nowrap; min-width:90px; }
    .hist-subject { font-family:'Sora'; font-size:13px; font-weight:700; color:#1a1816; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .hist-count-badge { font-size:10px; padding:2px 8px; background:var(--bg-primary); border-radius:999px; box-shadow:inset 1px 1px 3px var(--shadow-dark),inset -1px -1px 3px var(--shadow-light); color:#36577d; font-weight:600; white-space:nowrap; }
    .hist-body { padding:12px 14px; border-top:1px solid rgba(196,192,186,0.4); }
    .hist-meta { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; margin-bottom:8px; }
    .hist-names { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
    .hist-name-tag { padding:2px 8px; background:var(--bg-primary); border-radius:999px; box-shadow:1px 1px 3px var(--shadow-dark),-1px -1px 3px var(--shadow-light); font-size:11px; color:#3a3632; }
    .hist-preview { font-family:'Sora'; font-size:12px; color:#6a6660; line-height:1.6; white-space:pre-wrap; background:rgba(196,192,186,0.2); padding:8px 10px; border-radius:8px; margin-top:8px; }

    .modal-overlay { position:fixed; inset:0; background:rgba(26,24,22,0.45); z-index:1000; display:none; align-items:center; justify-content:center; }
    .modal-overlay.active { display:flex; }
    .modal-box {
      background: var(--bg-primary); border-radius: 20px;
      box-shadow: 10px 10px 30px rgba(26,24,22,0.2), -6px -6px 20px rgba(255,255,255,0.8);
      width: 480px; max-width: 95vw; max-height: 80vh; overflow-y: auto; padding: 24px;
    }
    .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid rgba(196,192,186,0.4); }
    .modal-header h2 { font-family:'Sora'; font-size:16px; font-weight:700; color:#1a1816; }
    .modal-close { background:var(--bg-primary); border:none; border-radius:8px; box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); width:30px; height:30px; cursor:pointer; font-size:16px; color:#6a6660; display:flex; align-items:center; justify-content:center; }
    .template-category-name { font-family:'DM Mono',monospace; font-size:9px; text-transform:uppercase; letter-spacing:0.18em; color:#a8a49e; margin:12px 0 6px; padding-bottom:5px; border-bottom:1px solid rgba(196,192,186,0.35); }
    .template-list-item { padding:9px 12px; background:var(--bg-primary); border-radius:10px; box-shadow:2px 2px 6px var(--shadow-dark),-2px -2px 5px var(--shadow-light); margin-bottom:6px; cursor:pointer; transition:box-shadow 0.12s; }
    .template-list-item:hover { box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); }
    .template-list-item-title { font-family:'Sora'; font-size:13px; font-weight:600; color:#1a1816; }
    .template-list-item-sujet { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }

    .ia-generate-btn {
      padding:10px 16px; border:none; border-radius:12px;
      background:linear-gradient(135deg,#4a6038,#36577d);
      color:var(--bg-secondary); font-family:'Sora',sans-serif; font-size:12px; font-weight:700;
      cursor:pointer; display:flex; align-items:center; gap:7px; justify-content:center; width:100%;
    }
    .ia-generate-btn:disabled { opacity:0.55; cursor:not-allowed; }
    .ia-status { font-family:'DM Mono',monospace; font-size:11px; padding:8px 12px; border-radius:8px; }
    .ia-status.loading { background:rgba(54,87,125,0.08); color:#36577d; }
    .ia-status.ok { background:rgba(74,96,56,0.1); color:#4a6038; }
    .ia-status.err { background:rgba(138,80,64,0.1); color:#8a5040; }
    .ia-divider { height:1px; background:rgba(196,192,186,0.4); margin:2px 0; }

    @media (max-width: 1100px) {
      .mail-layout { grid-template-columns: 220px 1fr 300px; }
    }
    @media (max-width: 800px) {
      .mail-layout { grid-template-columns: 1fr; height:auto; overflow:visible; }
      .mail-sidebar, .mail-composer, .ia-column { height:auto; overflow:visible; }
    }
</style>
EXTRACSS;

$layout_extra_js = '';

// ══════════════════════════════════════════════════════════════════════════
// Content
// ══════════════════════════════════════════════════════════════════════════
ob_start();
?>

<!-- ── Section: Messagerie ── -->
<div class="section-header">
  <div class="section-title">
    <span class="line-l"></span>
    <span class="sec-txt">Messagerie Équipe</span>
    <span class="line-r"></span>
  </div>
</div>

<div class="mail-layout">

  <!-- SIDEBAR FILTRES -->
  <aside class="mail-sidebar">
    <div class="mail-card">

      <div class="filter-section-title">Destinataires</div>

      <label class="mail-filter-item">
        <input type="radio" name="recipient-type" value="all" checked>
        <span class="mail-filter-count"><?= count($users) ?></span>
        <span class="mail-filter-label">Tous les utilisateurs</span>
      </label>

      <button type="button" class="filter-collapse-btn" id="users-toggle" onclick="toggleUsers()" aria-expanded="false">
        <span class="filter-collapse-label">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Individuels
          <span class="filter-collapse-count"><?= count($users) ?></span>
        </span>
        <span class="filter-collapse-arrow" id="users-arrow">&#9656;</span>
      </button>
      <div class="users-collapsible" id="users-collapsible">
        <div class="users-grid" id="users-checkboxes">
          <?php foreach ($users as $usr): ?>
          <label class="mail-filter-item">
            <input type="checkbox" class="user-checkbox" value="<?= (int)$usr['id'] ?>" data-prenom="<?= h($usr['prenom'] ?? '') ?>" data-nom="<?= h($usr['nom'] ?? '') ?>">
            <span class="mail-filter-label"><?= h(trim(($usr['prenom'] ?? '') . ' ' . ($usr['nom'] ?? ''))) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-section-title" style="margin-top:6px;">Par Société</div>
      <?php foreach ($societes as $soc):
        $cnt = count(array_filter($users, fn($u) => (int)$u['id_societe'] === (int)$soc['id'])); ?>
      <label class="mail-filter-item">
        <input type="radio" name="recipient-type" value="societe-<?= (int)$soc['id'] ?>">
        <span class="mail-filter-count"><?= $cnt ?></span>
        <span class="mail-filter-label"><?= h($soc['nom']) ?></span>
      </label>
      <?php endforeach; ?>

      <div class="filter-section-title" style="margin-top:6px;">Par Agence</div>
      <div class="agences-grid">
        <?php foreach ($agences as $ag):
          $cnt = count(array_filter($users, fn($u) => (int)$u['id_agence'] === (int)$ag['id'])); ?>
        <label class="mail-filter-item">
          <input type="radio" name="recipient-type" value="agence-<?= (int)$ag['id'] ?>">
          <span class="mail-filter-count"><?= $cnt ?></span>
          <span class="mail-filter-label"><?= h($ag['nom_agence']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="filter-section-title" style="margin-top:6px;">Service</div>
      <div class="services-row">
        <label class="mail-filter-item">
          <input type="radio" name="recipient-type" value="service-gestion">
          <span class="mail-filter-count"><?= count($usersByService['gestion']) ?></span>
          <span class="mail-filter-label">Gestion (RH)</span>
        </label>
        <label class="mail-filter-item">
          <input type="radio" name="recipient-type" value="service-syndic">
          <span class="mail-filter-count"><?= count($usersByService['syndic']) ?></span>
          <span class="mail-filter-label">Syndic</span>
        </label>
      </div>
    </div>
  </aside>

  <!-- COMPOSER -->
  <section class="mail-composer">
    <form id="mail-form" method="POST" action="./api/send_mail_team.php">

      <div class="composer-section">
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;">
          <div class="composer-section-title" style="margin:0;flex:1;">Détails du mail</div>
          <button type="button" class="btn-secondary" onclick="openTemplateModal()" style="padding:6px 11px;font-size:12px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Modèle
          </button>
          <a href="rh_mail_templates.php" class="btn-secondary" style="padding:6px 11px;font-size:12px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Créer
          </a>
        </div>

        <div class="recipients-list">
          <div class="recipients-list-title">Destinataires</div>
          <div class="recipients-list-content" id="recipients-list"></div>
        </div>

        <div class="form-group">
          <label class="form-label">Objet</label>
          <input type="text" name="subject" class="form-control" placeholder="Objet du mail…" required>
        </div>
      </div>

      <div class="composer-section composer-message-card">
        <div class="button-group" style="flex-shrink:0;margin-bottom:10px;">
          <button type="reset" class="btn-secondary" onclick="clearAttachments()" style="padding:7px 14px;font-size:12px;">Réinitialiser</button>
          <button type="button" id="preview-btn" class="btn-secondary" style="padding:7px 14px;font-size:12px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Visualiser
          </button>
          <button type="submit" class="btn-primary" style="padding:7px 18px;font-size:12px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Envoyer
          </button>
        </div>
        <textarea name="body" id="mail-body" class="form-control composer-body" placeholder="Écrivez votre message ici…" required></textarea>
      </div>

      <input type="hidden" name="recipient_type" id="recipient-type-input">
      <input type="hidden" name="recipient_id" id="recipient-id-input">
      <input type="hidden" name="recipient_emails" id="recipient-emails-input">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" id="csrf-token-input">
    </form>
  </section>

  <!-- COLONNE IA -->
  <div class="ia-column">
    <div class="ia-card">
      <div class="ia-card-title">Assistant IA</div>
      <label style="font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.13em;color:#a8a49e;">Décrivez le mail à rédiger</label>
      <textarea class="ia-textarea" id="ia-prompt" placeholder="Ex : Rappel bulletins de paie de mars, mentionner la date limite de validation…"></textarea>
      <button class="ia-generate-btn" id="ia-generate-btn" onclick="generateWithAI()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Générer
      </button>
      <div id="ia-status" style="display:none;" class="ia-status"></div>
    </div>

    <div class="ia-card">
      <div class="ia-card-title">Modifier / Affiner</div>
      <label style="font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.13em;color:#a8a49e;">Instructions de modification</label>
      <textarea class="ia-textarea" id="ia-refine-prompt" placeholder="Ex : Rends le ton plus formel, ajoute une date limite de 48h, raccourcis le message…"></textarea>
      <button class="ia-generate-btn" id="ia-refine-btn" onclick="refineWithAI()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Modifier avec l'IA
      </button>
      <div id="ia-refine-status" style="display:none;" class="ia-status"></div>
    </div>

    <div class="ia-card">
      <div class="ia-card-title">Pièces jointes</div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <label for="mail-attachments" class="attach-zone">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          Ajouter un fichier
        </label>
        <input type="file" id="mail-attachments" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt" style="display:none;" onchange="uploadAttachments(this.files)">
        <span style="font-family:'DM Mono',monospace;font-size:10px;color:#a8a49e;">PDF, Word, Excel, images — max 10 Mo</span>
      </div>
      <div id="attachments-list"></div>
    </div>
  </div>

</div>

<!-- History Drawer overlay -->
<div id="history-drawer-overlay" onclick="closeHistoryDrawer()" style="display:none;position:fixed;inset:0;background:rgba(26,24,22,0.35);z-index:9000;"></div>

<!-- History Drawer -->
<div id="history-drawer">
  <div class="drawer-header">
    <div>
      <div class="drawer-header-title">Historique des envois</div>
      <div class="drawer-header-sub">50 derniers mails</div>
    </div>
    <button class="drawer-close" onclick="closeHistoryDrawer()">×</button>
  </div>
  <div class="drawer-body">
    <?php if (empty($mailHistory)): ?>
      <div style="padding:32px;text-align:center;font-family:'DM Mono',monospace;font-size:11px;color:#a8a49e;">Aucun mail envoyé pour l'instant.</div>
    <?php else: ?>
      <?php foreach ($mailHistory as $hist):
        $recips = json_decode($hist['recipients_json'] ?? '[]', true) ?: [];
        $names  = array_map(fn($r) => trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')), $recips);
        $sentAt = $hist['sent_at'] ? (new DateTime($hist['sent_at']))->format('d/m/Y H:i') : '';
        $bodyPreview = mb_strimwidth(strip_tags($hist['body']), 0, 140, '…');
      ?>
      <details class="hist-item">
        <summary class="hist-summary">
          <span class="hist-date"><?= h($sentAt) ?></span>
          <span class="hist-subject"><?= h($hist['subject']) ?></span>
          <span class="hist-count-badge"><?= (int)$hist['recipients_count'] ?> dest.</span>
        </summary>
        <div class="hist-body">
          <div class="hist-meta">Par <?= h($hist['sender_name'] ?? '—') ?></div>
          <div style="font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.12em;color:#a8a49e;margin-bottom:5px;">Destinataires</div>
          <div class="hist-names">
            <?php foreach ($names as $name): ?>
            <span class="hist-name-tag"><?= h($name) ?></span>
            <?php endforeach; ?>
          </div>
          <div style="font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.12em;color:#a8a49e;margin:10px 0 5px;">Message</div>
          <p class="hist-preview"><?= h($bodyPreview) ?></p>
        </div>
      </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Template Modal -->
<div class="modal-overlay" id="template-modal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>Choisir un modèle</h2>
      <button class="modal-close" onclick="closeTemplateModal()">×</button>
    </div>
    <div id="template-modal-list"></div>
  </div>
</div>

<script>
const usersByType = {
    all: <?= json_encode($users) ?>,
    societe: {},
    agence: {},
    user: {},
    service: {}
};

<?php foreach ($societes as $soc): ?>
usersByType.societe['<?= (int)$soc['id'] ?>'] = <?= json_encode(array_values(array_filter($users, fn($u) => (int)$u['id_societe'] === (int)$soc['id']))) ?>;
<?php endforeach; ?>

<?php foreach ($agences as $ag): ?>
usersByType.agence['<?= (int)$ag['id'] ?>'] = <?= json_encode(array_values(array_filter($users, fn($u) => (int)$u['id_agence'] === (int)$ag['id']))) ?>;
<?php endforeach; ?>

<?php foreach ($users as $usr): ?>
usersByType.user[<?= (int)$usr['id'] ?>] = [<?= json_encode($usr) ?>];
<?php endforeach; ?>

usersByType.service.gestion = <?= json_encode($usersByService['gestion']) ?>;
usersByType.service.syndic  = <?= json_encode($usersByService['syndic']) ?>;

// Handle recipient type changes (radio buttons)
document.querySelectorAll('input[name="recipient-type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
        updateRecipients();
    });
});

// Handle user checkbox changes
document.querySelectorAll('.user-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        if (this.checked) {
            document.querySelectorAll('input[name="recipient-type"]').forEach(r => r.checked = false);
        }
        updateRecipients();
    });
});

function updateRecipients() {
    const selectedRadio = document.querySelector('input[name="recipient-type"]:checked');
    const checkedUsers  = document.querySelectorAll('.user-checkbox:checked');

    let recipients = [];

    if (checkedUsers.length > 0) {
        recipients = Array.from(checkedUsers).map(cb => {
            const fullUser = usersByType.all.find(user => user.id == cb.value);
            return fullUser || { id: cb.value, prenom: cb.dataset.prenom, nom: cb.dataset.nom, email: '' };
        });
    } else if (selectedRadio) {
        const value = selectedRadio.value;
        const [type, id] = value.includes('-') ? value.split('-') : [value, null];

        if (type === 'all') {
            recipients = usersByType.all;
        } else if (type === 'societe') {
            recipients = usersByType.societe[id] || [];
        } else if (type === 'agence') {
            recipients = usersByType.agence[id] || [];
        } else if (type === 'service') {
            recipients = usersByType.service[id] || [];
        }

        document.getElementById('recipient-type-input').value = type;
        document.getElementById('recipient-id-input').value   = id || '';
    }

    const listContainer = document.getElementById('recipients-list');
    const count = recipients.length;

    if (count > 30) {
        listContainer.innerHTML = `<span style="font-size:12px;color:#8a5040;font-weight:600;">⚠️ Limite atteinte — max 30 destinataires. Actuellement ${count}.</span>`;
    } else {
        renderRecipientsList(recipients);
    }

    window.currentRecipients = [...recipients];
    const emails = recipients.map(u => u.email).filter(e => e).join(',');
    document.getElementById('recipient-emails-input').value = emails;
}

function removeRecipient(userId) {
    window.currentRecipients = (window.currentRecipients || []).filter(u => String(u.id) !== String(userId));

    const checkbox = document.querySelector(`.user-checkbox[value="${userId}"]`);
    if (checkbox) checkbox.checked = false;

    renderRecipientsList(window.currentRecipients);

    const emails = window.currentRecipients.map(u => u.email).filter(e => e).join(',');
    document.getElementById('recipient-emails-input').value = emails;
}

function renderRecipientsList(recipients) {
    const listContainer = document.getElementById('recipients-list');
    const count = recipients.length;
    if (count === 0) {
        listContainer.innerHTML = '<span style="font-family:\'DM Mono\',monospace;font-size:11px;color:#a8a49e;">Aucun destinataire</span>';
    } else {
        listContainer.innerHTML = recipients.map(u =>
            `<div class="recipient-item">
                ${u.prenom} ${u.nom}
                <button type="button" class="recipient-remove" onclick="removeRecipient('${u.id}')" title="Supprimer">×</button>
            </div>`
        ).join('');
    }
}

updateRecipients();

// ── Auto-resize textarea body ────────────────────────────────────────────────
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}
const bodyTA = document.getElementById('mail-body');
if (bodyTA) {
    bodyTA.addEventListener('input', () => autoResize(bodyTA));
    autoResize(bodyTA);
}

// Preview button
document.getElementById('preview-btn').addEventListener('click', function() {
    const subject    = document.querySelector('input[name="subject"]').value;
    const body       = document.querySelector('textarea[name="body"]').value;
    const recipients = window.currentRecipients || [];

    if (!subject || !body) { alert('Veuillez remplir l\'objet et le contenu du mail'); return; }

    let recipientsHtml = '';
    if (recipients.length === 0) {
        recipientsHtml = '<span style="color:#c00;">Aucun destinataire</span>';
    } else {
        const names = recipients.map(u => {
            const name  = `${u.prenom || ''} ${u.nom || ''}`.trim();
            const email = u.email ? ` <span style="color:#999;font-size:11px;">&lt;${u.email}&gt;</span>` : '';
            return `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:#e8f4fd;border:1px solid #c9e2f7;border-radius:12px;margin:2px;font-size:12px;color:#1a2a3a;">${name}${email}</span>`;
        });
        recipientsHtml = `<div style="display:flex;flex-wrap:wrap;gap:2px;margin-top:6px;">${names.join('')}</div>`;
    }

    const attachChips = document.querySelectorAll('#attachments-list > div');
    let attachHtml = '';
    if (attachChips.length > 0) {
        const names = Array.from(attachChips).map(c => c.querySelector('span').textContent);
        attachHtml = `<p style="margin:12px 0 0;font-size:12px;color:#666;"><strong>Pièces jointes :</strong></p>
            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">${names.map(n=>`<span style="display:inline-flex;padding:2px 8px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;font-size:12px;color:#856404;">${n}</span>`).join('')}</div>`;
    }

    const previewHtml = `
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
            <div style="background:#f5f7fa;padding:20px;border-radius:8px;margin-bottom:16px;">
                <p style="margin:0 0 4px;font-size:12px;color:#666;"><strong>Destinataires (${recipients.length}) :</strong></p>
                ${recipientsHtml}
                <p style="margin:12px 0 0;font-size:12px;color:#666;"><strong>Objet:</strong> ${subject}</p>
                ${attachHtml}
            </div>
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">
                <p style="white-space:pre-wrap;color:#000;font-size:14px;line-height:1.6;">${body}</p>
            </div>
        </div>`;

    const modal = document.createElement('div');
    modal.className = 'preview-modal-overlay';
    modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;';
    modal.innerHTML = `
        <div style="background:#fff;border-radius:12px;max-width:700px;width:90%;max-height:80vh;overflow-y:auto;padding:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <h2 style="margin:0;color:#000;font-size:18px;">Aperçu du mail</h2>
                <button onclick="this.closest('.preview-modal-overlay').remove()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;">×</button>
            </div>
            ${previewHtml}
            <div style="margin-top:20px;display:flex;gap:12px;justify-content:flex-end;">
                <button onclick="this.closest('.preview-modal-overlay').remove()" style="padding:10px 20px;border:1px solid #ddd;border-radius:8px;background:#f5f7fa;color:#333;cursor:pointer;font-weight:600;">Fermer</button>
                <button id="preview-send-btn" style="padding:10px 24px;border:none;border-radius:8px;background:#36577d;color:#fff;cursor:pointer;font-weight:700;font-size:14px;">Envoyer</button>
            </div>
        </div>`;

    const sendBtn = modal.querySelector('#preview-send-btn');
    sendBtn.addEventListener('click', async () => {
        sendBtn.disabled = true;
        sendBtn.textContent = '⏳ Envoi…';
        const ok = await sendMail();
        if (ok) modal.remove();
        else { sendBtn.disabled = false; sendBtn.textContent = 'Envoyer'; }
    });
    document.body.appendChild(modal);
});

async function sendMail() {
    const formData = new FormData(document.getElementById('mail-form'));
    const data = Object.fromEntries(formData);

    // Source de vérité = la liste affichée à l'écran (window.currentRecipients).
    // Si l'utilisateur est en mode "Tous/Société/Agence/Service" et retire
    // manuellement des destinataires via le bouton ×, la liste filtrée doit
    // être envoyée telle quelle — sinon le backend récupère TOUS les users
    // et ignore les suppressions (bug signalé 2026-04-22).
    const recipients = window.currentRecipients || [];
    if (recipients.length > 30) { alert('⚠️ Maximum 30 destinataires. Actuellement : ' + recipients.length); return false; }
    if (recipients.length === 0) { alert('Veuillez sélectionner des destinataires'); return false; }

    data.recipient_type = 'users';
    data.user_ids = recipients.map(u => u.id).join(',');
    delete data.recipient_id;

    try {
        data.attachment_ids = window.mailAttachmentIds || [];
        const response = await fetch('./api/send_mail_team.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            alert(`✅ Mail envoyé à ${result.count} destinataire(s)`);
            document.getElementById('mail-form').reset();
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
            updateRecipients();
            window.mailAttachmentIds = [];
            document.getElementById('attachments-list').innerHTML = '';
            return true;
        } else {
            alert(`❌ Erreur : ${result.message}`);
            return false;
        }
    } catch (err) {
        alert(`❌ Erreur : ${err.message}`);
        return false;
    }
}

document.getElementById('mail-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    await sendMail();
});

// ── Pièces jointes ──
window.mailAttachmentIds = [];

async function uploadAttachments(files) {
    const csrfToken = document.getElementById('csrf-token-input').value;
    for (const file of Array.from(files)) {
        if (file.size > 10 * 1024 * 1024) { alert(`❌ "${file.name}" dépasse 10 Mo`); continue; }
        const fd = new FormData();
        fd.append('file', file);
        fd.append('csrf_token', csrfToken);
        try {
            const res  = await fetch('./api/upload_mail_attachment.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                window.mailAttachmentIds.push(data.id);
                renderAttachmentChip(data.id, data.name);
            } else {
                alert(`❌ ${file.name} : ${data.message}`);
            }
        } catch (err) {
            alert(`❌ Erreur upload : ${err.message}`);
        }
    }
    document.getElementById('mail-attachments').value = '';
}

function renderAttachmentChip(id, name) {
    const list = document.getElementById('attachments-list');
    const chip = document.createElement('div');
    chip.id = 'attach-' + id;
    chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:var(--bg-primary);border-radius:999px;box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light);font-family:\'Sora\',sans-serif;font-size:12px;color:#36577d;font-weight:600;';
    chip.innerHTML = `<span>📎 ${name.replace(/</g,'&lt;')}</span><button type="button" onclick="removeAttachment('${id}')" style="background:none;border:none;color:#8a5040;cursor:pointer;font-size:15px;line-height:1;padding:0;">×</button>`;
    list.appendChild(chip);
}

async function removeAttachment(id) {
    window.mailAttachmentIds = window.mailAttachmentIds.filter(x => x !== id);
    const chip = document.getElementById('attach-' + id);
    if (chip) chip.remove();
    try {
        await fetch('./api/delete_mail_attachment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
    } catch (_) {}
}

async function clearAttachments() {
    for (const id of [...window.mailAttachmentIds]) await removeAttachment(id);
    window.mailAttachmentIds = [];
    document.getElementById('attachments-list').innerHTML = '';
}

// ── History Drawer ──
function openHistoryDrawer() {
    document.getElementById('history-drawer-overlay').style.display = 'block';
    document.getElementById('history-drawer').style.display = 'flex';
}
function closeHistoryDrawer() {
    document.getElementById('history-drawer-overlay').style.display = 'none';
    document.getElementById('history-drawer').style.display = 'none';
}

// ── Template Modal ──
function openTemplateModal() {
    fetch('api/get_mail_templates.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderTemplateModal(data.templates);
                document.getElementById('template-modal').classList.add('active');
            }
        })
        .catch(err => alert('Erreur : ' + err.message));
}

function closeTemplateModal() {
    document.getElementById('template-modal').classList.remove('active');
}

function renderTemplateModal(templates) {
    const list = document.getElementById('template-modal-list');
    let html = '';
    for (const category in templates) {
        html += `<div class="template-category-name">${category}</div>`;
        templates[category].forEach(t => {
            html += `<div class="template-list-item" onclick="loadTemplateContent(${t.id})">
                <div class="template-list-item-title">${t.titre}</div>
                <div class="template-list-item-sujet">${t.sujet}</div>
            </div>`;
        });
    }
    list.innerHTML = html;
}

function loadTemplateContent(templateId) {
    fetch('api/get_mail_template.php?id=' + templateId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const t = data.template;
                document.querySelector('input[name="subject"]').value   = t.sujet;
                document.getElementById('mail-body').value = t.corps; autoResize(document.getElementById('mail-body'));
                closeTemplateModal();
            } else {
                alert('Erreur : ' + (data.message || 'Template non trouvé'));
            }
        })
        .catch(err => alert('Erreur : ' + err.message));
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeTemplateModal(); closeIAPanel(); }
});
document.getElementById('template-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeTemplateModal();
});

// ── Toggle liste utilisateurs ──
function toggleUsers() {
    const btn  = document.getElementById('users-toggle');
    const box  = document.getElementById('users-collapsible');
    const arr  = document.getElementById('users-arrow');
    const open = box.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    arr.style.transform = open ? 'rotate(90deg)' : '';
}

// ── IA Panel ──

function refineWithAI() {
    const instructions = document.getElementById('ia-refine-prompt').value.trim();
    if (!instructions) { alert('Décrivez les modifications souhaitées'); return; }

    const currentSubject = document.querySelector('input[name="subject"]').value;
    const currentBody    = document.querySelector('textarea[name="body"]').value;

    if (!currentSubject && !currentBody) { alert('Aucun contenu à modifier — générez d\'abord un mail'); return; }

    const btn    = document.getElementById('ia-refine-btn');
    const status = document.getElementById('ia-status');
    btn.disabled = true;
    status.style.display = 'block';
    status.className = 'ia-status loading';
    status.textContent = '⏳ Modification en cours…';

    const prompt = `Modifie ce mail professionnel en français selon les instructions données. Réponds UNIQUEMENT en JSON avec les clés "sujet" et "corps".\n\nMail actuel :\nsujet: ${currentSubject}\ncorps: ${currentBody}\n\nInstructions : ${instructions}`;

    const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
    fetch(isLocal ? 'api/claude_draft.php?debug=1' : 'api/claude_draft.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt })
    })
    .then(r => { if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t); }); return r.json(); })
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            document.querySelector('input[name="subject"]').value = data.sujet || currentSubject;
            document.getElementById('mail-body').value = data.corps || currentBody; autoResize(document.getElementById('mail-body'));
            status.className = 'ia-status ok';
            status.innerHTML = '✓ Contenu modifié — <button onclick="closeIAPanel()" style="background:none;border:none;cursor:pointer;color:#4a6038;font-weight:700;text-decoration:underline;font-size:11px;padding:0;">voir le résultat →</button>';
            document.getElementById('ia-refine-prompt').value = '';
            setTimeout(() => closeIAPanel(), 3000);
        } else {
            status.className = 'ia-status err';
            status.textContent = '❌ ' + (data.message || 'Impossible de modifier');
        }
    })
    .catch(err => { btn.disabled = false; status.className = 'ia-status err'; status.textContent = '❌ ' + err.message; });
}

function generateWithAI() {
    const prompt = document.getElementById('ia-prompt').value;
    if (!prompt.trim()) { alert('Décrivez ce que vous souhaitez rédiger'); return; }

    const btn    = document.getElementById('ia-generate-btn');
    const status = document.getElementById('ia-status');
    btn.disabled = true;
    status.style.display = 'block';
    status.className = 'ia-status loading';
    status.textContent = '⏳ Génération en cours…';

    const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
    const url = isLocal ? 'api/claude_draft.php?debug=1' : 'api/claude_draft.php';

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt })
    })
    .then(r => {
        if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t); });
        return r.json();
    })
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            document.querySelector('input[name="subject"]').value = data.sujet || '';
            document.getElementById('mail-body').value = data.corps || ''; autoResize(document.getElementById('mail-body'));
            status.className = 'ia-status ok';
            status.textContent = '✓ Contenu généré';
            setTimeout(() => { status.style.display = 'none'; }, 3000);
        } else {
            status.className = 'ia-status err';
            status.textContent = '❌ Erreur : ' + (data.message || 'Impossible de générer');
        }
    })
    .catch(err => {
        btn.disabled = false;
        status.className = 'ia-status err';
        status.textContent = '❌ ' + err.message;
    });
}
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
