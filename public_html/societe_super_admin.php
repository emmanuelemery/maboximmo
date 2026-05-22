<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors  = [];
$success = '';

// ── Actions POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('sa_societes');

    $action   = trim((string)($_POST['action'] ?? ''));
    $targetId = (int)($_POST['societe_id'] ?? 0);

    if ($action === 'toggle_actif' && $targetId > 0) {
        try {
            $pdo->prepare("UPDATE societes SET actif = NOT actif WHERE id = ?")->execute([$targetId]);
            $success = 'Statut mis à jour.';
        } catch (PDOException $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }

    if ($action === 'create_societe') {
        $nom = trim((string)($_POST['nom'] ?? ''));
        if ($nom === '') {
            $errors[] = 'Le nom est obligatoire.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO societes (nom, actif) VALUES (?, 1)")->execute([$nom]);
                $newId = (int)$pdo->lastInsertId();

                // Duplication automatique du paramétrage de base
                require_once __DIR__ . '/inc/societe_duplication.php';
                dupliquerParametrageSociete($pdo, $newId);

                $pdo->commit();
                $success = "Société créée (id=$newId) avec paramétrage de base dupliqué.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    }
}

// ── Recherche ──────────────────────────────────────────────────
$search = trim((string)($_GET['q'] ?? ''));
$filter = trim((string)($_GET['f'] ?? 'all')); // all | actif | inactif

$whereClause = 'WHERE 1=1';
$params      = [];
if ($search !== '') {
    $whereClause .= ' AND (nom LIKE :q OR raison_sociale LIKE :q OR siret LIKE :q OR ville LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($filter === 'actif')   { $whereClause .= ' AND actif = 1'; }
if ($filter === 'inactif') { $whereClause .= ' AND actif = 0'; }

try {
    $stmt = $pdo->prepare("
        SELECT id, nom, raison_sociale, siret, ville, code_postal,
               telephone, email, numero_carte_t, carte_t_date_expiration,
               carte_t_activites, actif, date_modification
        FROM societes
        $whereClause
        ORDER BY nom ASC
    ");
    $stmt->execute($params);
    $societes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $societes = [];
    $errors[] = 'Impossible de charger les sociétés : ' . $e->getMessage();
}

$total      = count($societes);
$totalActif = count(array_filter($societes, fn($s) => (bool)$s['actif']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Super Admin — Sociétés — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/tokens.css">
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/layout.css">
  <link rel="stylesheet" href="css/theme-rh.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg-secondary); color: #1a1816;
      display: flex; min-height: 100vh;
    }

    /* ── SHELL ── */
    .sa-main {
      margin-left: 220px; flex: 1;
      display: flex; flex-direction: column;
      height: 100vh; overflow: hidden;
    }

    /* ── TOPBAR ── */
    .sa-topbar {
      background: var(--bg-primary);
      box-shadow: 0 4px 12px rgba(196,192,186,0.45);
      padding: 0 24px; height: 56px;
      display: flex; align-items: center; gap: 10px;
      position: sticky; top: 0; z-index: 100; flex-shrink: 0;
    }
    .topbar-back {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; border: none; flex-shrink: 0;
    }
    .topbar-back:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .topbar-back svg { width:15px; height:15px; stroke:#9aaa84; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .topbar-breadcrumb {
      display: flex; align-items: center; gap: 8px;
      font-family: 'DM Mono', monospace; font-size: 13px;
      letter-spacing: 0.06em; color: #8a8680; margin-left: 12px;
    }
    .topbar-breadcrumb a { color: #8a8680; text-decoration: none; }
    .topbar-breadcrumb a:hover { color: #4a6038; }
    .topbar-breadcrumb .active { color: #36577d; font-weight: 600; font-size: 14px; }
    .topbar-sep { color: #c8c4be; font-size: 16px; }
    .topbar-spacer { flex: 1; }
    .topbar-notif {
      position: relative; width: 36px; height: 36px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; border: none; flex-shrink: 0;
    }
    .topbar-notif svg { width:16px; height:16px; stroke:#8a8680; fill:none; stroke-width:1.6; }
    .topbar-notif-dot {
      position: absolute; top: 6px; right: 6px;
      width: 7px; height: 7px; border-radius: 50%;
      background: #cc5c58; border: 2px solid var(--bg-primary);
    }
    .topbar-avatar {
      width: 32px; height: 32px; border-radius: 50%; background: #36577d;
      display: flex; align-items: center; justify-content: center;
      font-family: 'DM Mono', monospace; font-size: 10px;
      color: #fff; font-weight: 500;
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      flex-shrink: 0;
    }

    /* ── SCROLL ── */
    .sa-scroll { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0 24px 40px; }
    .sa-content { max-width: 1100px; margin: 0 auto; }

    /* ── PAGE HEAD ── */
    .page-head {
      display: flex; align-items: flex-end; justify-content: space-between;
      padding: 20px 0 16px;
      border-bottom: 1px solid rgba(196,192,186,0.3);
      margin-bottom: 24px; gap: 16px;
    }
    .page-head-module {
      font-family: 'DM Mono', monospace; font-size: 9px;
      text-transform: uppercase; letter-spacing: 0.22em; color: #a8a49e; margin-bottom: 4px;
    }
    .page-head-title {
      font-family: 'Sora', sans-serif; font-size: 20px; font-weight: 700; color: #1a1816;
    }
    .page-head-sub {
      font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680;
      letter-spacing: 0.04em; margin-top: 3px;
    }

    /* ── ALERTS ── */
    .sa-alert {
      padding: 13px 18px; border-radius: 14px;
      font-size: 13px; font-weight: 600; margin-bottom: 20px;
    }
    .sa-alert.error {
      background: rgba(204,92,88,0.08);
      box-shadow: inset 3px 3px 8px rgba(204,92,88,0.12);
      border-left: 4px solid #cc5c58; color: #8a3030;
    }
    .sa-alert.success {
      background: rgba(122,144,96,0.1);
      box-shadow: inset 3px 3px 8px rgba(122,144,96,0.15);
      border-left: 4px solid #7a9060; color: #2a4020;
    }

    /* ── TOOLBAR ── */
    .sa-toolbar {
      display: flex; align-items: center; gap: 14px;
      margin-bottom: 20px; flex-wrap: wrap;
    }
    .sa-search {
      position: relative; flex: 1; min-width: 240px;
    }
    .sa-search-icon {
      position: absolute; left: 14px; top: 50%;
      transform: translateY(-50%); font-size: 14px; pointer-events: none;
    }
    .sa-search-input {
      width: 100%; padding: 10px 36px 10px 40px;
      background: var(--bg-primary);
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      border: none; border-radius: 12px;
      font-family: 'Sora', sans-serif; font-size: 13px; color: #1a1816;
      outline: none; transition: box-shadow .2s;
    }
    .sa-search-input:focus {
      box-shadow: inset 4px 4px 9px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light),
                  0 0 0 2px rgba(54,87,125,0.18);
    }
    .sa-search-input::placeholder { color: #b8b4ae; }
    .sa-clear {
      position: absolute; right: 12px; top: 50%;
      transform: translateY(-50%);
      font-family: 'DM Mono', monospace; font-size: 13px;
      color: #8a8680; text-decoration: none; line-height: 1;
    }
    .sa-filters { display: flex; gap: 6px; flex-wrap: wrap; }
    .sa-filter-btn {
      padding: 8px 16px; border-radius: 999px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
      letter-spacing: 0.05em; color: #8a8680;
      text-decoration: none; white-space: nowrap;
      transition: box-shadow .15s;
    }
    .sa-filter-btn:hover { box-shadow: 5px 5px 10px var(--shadow-dark), -5px -5px 12px var(--shadow-light); color: #1a1816; }
    .sa-filter-btn.active {
      background: rgba(54,87,125,0.1);
      box-shadow: inset 3px 3px 6px rgba(54,87,125,0.15), inset -3px -3px 6px rgba(255,255,255,0.8);
      color: #36577d;
    }

    /* ── LISTE CARTES ── */
    .sa-list { display: flex; flex-direction: column; gap: 12px; }

    .sa-card {
      background: var(--bg-primary);
      border-radius: 20px;
      box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
      padding: 18px 22px;
      display: flex; align-items: center; gap: 18px;
      transition: box-shadow .2s;
    }
    .sa-card:hover { box-shadow: 10px 10px 22px var(--shadow-dark), -10px -10px 24px var(--shadow-light); }
    .sa-card.inactive { opacity: .55; }

    .sa-card-bullet {
      width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
    }
    .sa-card-bullet.actif   { background: #7a9060; box-shadow: 0 0 6px rgba(122,144,96,0.5); }
    .sa-card-bullet.inactif { background: #c8c4be; }

    .sa-card-body { flex: 1; min-width: 0; }
    .sa-card-nom {
      font-size: 14px; font-weight: 700; color: #1a1816;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sa-card-sub {
      font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680;
      margin-top: 2px; letter-spacing: 0.03em;
    }

    .sa-card-meta {
      display: flex; align-items: center; gap: 12px;
      flex-shrink: 0; flex-wrap: wrap;
    }

    /* Carte T info */
    .sa-carte-block { text-align: right; }
    .sa-carte-num {
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 600;
      color: #1a1816; letter-spacing: 0.04em;
    }
    .sa-carte-exp {
      font-family: 'DM Mono', monospace; font-size: 10px; margin-top: 2px;
      letter-spacing: 0.03em;
    }
    .expiry-red    { color: #cc5c58; }
    .expiry-orange { color: #b86c28; }
    .expiry-green  { color: #7a9060; }
    .sa-no-carte {
      font-family: 'DM Mono', monospace; font-size: 10px;
      color: #b8b4ae; font-style: italic;
    }

    /* Activités chips */
    .sa-activites { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; justify-content: flex-end; }
    .sa-chip {
      padding: 2px 8px; border-radius: 999px;
      font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
      letter-spacing: 0.06em; text-transform: uppercase;
      background: rgba(54,87,125,0.1); color: #36577d;
      box-shadow: inset 1px 1px 3px rgba(54,87,125,0.12), inset -1px -1px 3px rgba(255,255,255,0.7);
    }
    .sa-chip-more {
      background: rgba(196,192,186,0.2); color: #8a8680;
      box-shadow: none;
    }

    /* Statut */
    .sa-status {
      font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 700;
      letter-spacing: 0.06em; white-space: nowrap;
      padding: 4px 10px; border-radius: 999px;
    }
    .status-actif {
      background: rgba(122,144,96,0.12); color: #4a6038;
      box-shadow: inset 2px 2px 4px rgba(122,144,96,0.15), inset -2px -2px 4px rgba(255,255,255,0.8);
    }
    .status-inactif {
      background: rgba(196,192,186,0.2); color: #8a8680;
    }

    /* Date modif */
    .sa-date {
      font-family: 'DM Mono', monospace; font-size: 10px;
      color: #a8a49e; white-space: nowrap; text-align: right;
    }

    /* Actions */
    .sa-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

    .btn-sa-edit {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 0 16px; height: 34px; border-radius: 999px;
      background: rgba(54,87,125,0.1); color: #36577d;
      border: 1px solid rgba(54,87,125,0.25);
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 600;
      letter-spacing: 0.05em; text-decoration: none;
      transition: background .15s;
    }
    .btn-sa-edit:hover { background: rgba(54,87,125,0.18); }

    .sa-toggle-form { display: inline; }
    .btn-sa-toggle {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; transition: box-shadow .15s;
    }
    .btn-sa-toggle:hover { box-shadow: 4px 4px 9px var(--shadow-dark), -4px -4px 10px var(--shadow-light); }
    .btn-sa-toggle:active { box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }

    /* Empty state */
    .sa-empty {
      text-align: center; padding: 80px 20px;
      color: #8a8680;
    }
    .sa-empty-icon { font-size: 48px; margin-bottom: 16px; opacity: .6; }
    .sa-empty p { font-family: 'DM Mono', monospace; font-size: 13px; }

    /* ── BOUTON NOUVELLE SOCIÉTÉ ── */
    .btn-new-societe {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 0 22px; height: 38px; border-radius: 999px; border: none;
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; transition: box-shadow .2s;
      background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to)); color: #fff;
      box-shadow: 5px 5px 12px rgba(249,115,22,0.4), -2px -2px 7px rgba(255,255,255,0.5);
    }
    .btn-new-societe:hover { box-shadow: 6px 6px 16px rgba(249,115,22,0.5), -2px -2px 7px rgba(255,255,255,0.6); }

    /* ── MODAL ── */
    .sa-modal-overlay {
      position: fixed; inset: 0;
      background: rgba(26,24,22,0.55);
      z-index: 900;
      display: flex; align-items: center; justify-content: center;
    }
    .sa-modal {
      background: var(--bg-primary);
      border-radius: 24px;
      box-shadow: 20px 20px 40px var(--shadow-dark), -20px -20px 40px var(--shadow-light);
      width: 100%; max-width: 440px;
      overflow: hidden;
    }
    .sa-modal-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 24px 16px;
      border-bottom: 1px solid rgba(196,192,186,0.35);
    }
    .sa-modal-head h3 {
      font-family: 'Sora', sans-serif; font-size: 16px; font-weight: 700;
      color: #1a1816; margin: 0;
    }
    .sa-modal-close {
      width: 30px; height: 30px; border-radius: 8px;
      background: var(--bg-primary);
      box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; color: #8a8680;
    }
    .sa-modal-close:hover { color: #1a1816; }
    .sa-modal-body { padding: 22px 24px; }
    .sa-modal-body label {
      font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
      text-transform: uppercase; letter-spacing: 0.1em; color: #8a8680;
      display: block; margin-bottom: 8px;
    }
    .sa-modal-body input[type="text"] {
      width: 100%; padding: 11px 14px;
      background: var(--bg-primary);
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      border: none; border-radius: 10px;
      font-family: 'Sora', sans-serif; font-size: 13px; color: #1a1816;
      outline: none; transition: box-shadow .2s;
    }
    .sa-modal-body input[type="text"]:focus {
      box-shadow: inset 4px 4px 9px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light),
                  0 0 0 2px rgba(232,121,249,0.2);
    }
    .sa-modal-body input::placeholder { color: #b8b4ae; }
    .sa-modal-foot {
      display: flex; justify-content: flex-end; gap: 10px;
      padding: 16px 24px 22px;
      border-top: 1px solid rgba(196,192,186,0.35);
    }
    .btn-modal-sec {
      padding: 0 18px; height: 38px; border-radius: 999px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      border: none; cursor: pointer;
      font-family: 'DM Mono', monospace; font-size: 11px; color: #8a8680;
    }
    .btn-modal-sec:hover { color: #1a1816; }
    .btn-modal-create {
      padding: 0 22px; height: 38px; border-radius: 999px; border: none;
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; transition: box-shadow .2s;
      background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to)); color: #fff;
      box-shadow: 4px 4px 10px rgba(249,115,22,0.4), -2px -2px 6px rgba(255,255,255,0.5);
    }
    .btn-modal-create:hover { box-shadow: 5px 5px 14px rgba(249,115,22,0.5), -2px -2px 5px var(--shadow-light); }

    /* ── SECTION TITLE ── */
    .sec-head { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
    .sec-txt {
      font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
      letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
      background: linear-gradient(180deg, #7a9060 0%, #4a6038 40%, #304828 70%, #607848 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .line-l { height: 1.5px; width: 28px; flex-shrink: 0; background: linear-gradient(90deg, transparent 0%, #304828 40%, #9ab870 100%); border-radius: 2px; }
    .line-r { height: 1.5px; flex: 1; background: linear-gradient(90deg, #9ab870 0%, #607848 30%, #4a6038 55%, transparent 100%); border-radius: 2px; }

    @media (max-width: 900px) {
      .sa-main { margin-left: 0; }
      .sa-card { flex-wrap: wrap; }
      .sa-card-meta { width: 100%; justify-content: flex-start; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/sidebar_rh.php'; ?>

<div class="sa-main">

  <!-- TOPBAR -->
  <header class="sa-topbar">
    <button class="topbar-back" onclick="history.back()" title="Retour">
      <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <button class="topbar-back" onclick="history.forward()" title="Avancer">
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
    <nav class="topbar-breadcrumb">
      <span>Admin</span>
      <span class="topbar-sep">›</span>
      <span class="active">Sociétés</span>
    </nav>
    <span class="topbar-badge-sa">Super Admin</span>
    <div class="topbar-spacer"></div>
    <button class="topbar-notif" title="Notifications">
      <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      <span class="topbar-notif-dot"></span>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom']??'?',0,1).substr($_SESSION['nom']??'',0,1)) ?></div>
  </header>

  <!-- SCROLL ZONE -->
  <div class="sa-scroll">
  <div class="sa-content">

    <!-- ── PAGE HEAD ── -->
    <div class="page-head">
      <div>
        <div class="page-head-module">Administration · Super Admin</div>
        <div class="page-head-title">Toutes les sociétés</div>
        <div class="page-head-sub">
          <?= $totalActif ?> active<?= $totalActif > 1 ? 's' : '' ?>
          &nbsp;·&nbsp;
          <?= $total ?> au total
          <?php if ($search !== ''): ?>&nbsp;·&nbsp; Recherche : «&nbsp;<?= h($search) ?>&nbsp;»<?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;">
        <a href="/admin/admin_migrations.php" class="btn-migrations"
           title="Gestionnaire de migrations SQL (réservé super admin)"
           style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:10px;background:#fff;color:#0369a1;border:1px solid #0ea5e9;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;">
          🗄️ Migrations BDD
        </a>
        <button class="btn-new-societe" onclick="openModal('modal-create')">+ Nouvelle société</button>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="sa-alert error">⚠ <?= h(implode(' · ', $errors)) ?></div>
    <?php elseif ($success !== ''): ?>
    <div class="sa-alert success">✓ <?= h($success) ?></div>
    <?php endif; ?>

    <!-- ── Barre de recherche & filtres ── -->
    <form method="get" class="sa-toolbar">
      <div class="sa-search">
        <span class="sa-search-icon">🔍</span>
        <input type="text" name="q" value="<?= h($search) ?>"
               placeholder="Nom, SIRET, ville…"
               class="sa-search-input" autocomplete="off">
        <?php if ($search !== ''): ?>
        <a href="?f=<?= h($filter) ?>" class="sa-clear">✕</a>
        <?php endif; ?>
      </div>
      <div class="sa-filters">
        <a href="?q=<?= h($search) ?>&f=all"     class="sa-filter-btn <?= $filter === 'all'    ? 'active' : '' ?>">Toutes</a>
        <a href="?q=<?= h($search) ?>&f=actif"   class="sa-filter-btn <?= $filter === 'actif'  ? 'active' : '' ?>">Actives</a>
        <a href="?q=<?= h($search) ?>&f=inactif" class="sa-filter-btn <?= $filter === 'inactif'? 'active' : '' ?>">Inactives</a>
      </div>
    </form>

    <!-- ── Section title ── -->
    <div class="sec-head">
      <div class="line-l"></div>
      <span class="sec-txt">Toutes les sociétés</span>
      <div class="line-r"></div>
    </div>

    <!-- ── Liste ── -->
    <?php if ($societes): ?>
    <div class="sa-list">
    <?php foreach ($societes as $s):
      $actif       = (bool)$s['actif'];
      $carteExpiry = $s['carte_t_date_expiration'] ?? '';
      $carteNum    = $s['numero_carte_t'] ?? '';
      $activites   = [];
      if (!empty($s['carte_t_activites'])) {
          $dec = json_decode((string)$s['carte_t_activites'], true);
          if (is_array($dec)) $activites = $dec;
      }
      $expiryClass = '';
      $expiryTxt   = '';
      if ($carteExpiry !== '') {
          $exp  = new DateTime($carteExpiry);
          $now  = new DateTime();
          $diff = (int)$now->diff($exp)->days * ($exp >= $now ? 1 : -1);
          if ($diff < 0)        { $expiryClass = 'expiry-red';    $expiryTxt = '✕ Exp. '.$exp->format('d/m/Y'); }
          elseif ($diff <= 60)  { $expiryClass = 'expiry-orange'; $expiryTxt = '⚠ '.$exp->format('d/m/Y').' ('.$diff.'j)'; }
          else                  { $expiryClass = 'expiry-green';  $expiryTxt = '✓ '.$exp->format('d/m/Y'); }
      }
      $dateModif = $s['date_modification'] ? (new DateTime($s['date_modification']))->format('d/m/Y') : '—';
    ?>
    <div class="sa-card <?= $actif ? '' : 'inactive' ?>">
      <div class="sa-card-bullet <?= $actif ? 'actif' : 'inactif' ?>"></div>

      <div class="sa-card-body">
        <div class="sa-card-nom"><?= h($s['nom']) ?></div>
        <div class="sa-card-sub">
          <?php if (!empty($s['raison_sociale']) && $s['raison_sociale'] !== $s['nom']): ?>
          <?= h($s['raison_sociale']) ?> &nbsp;·&nbsp;
          <?php endif; ?>
          <?php if ($s['siret']): ?><?= h($s['siret']) ?>&nbsp;·&nbsp;<?php endif; ?>
          <?php if ($s['ville']): ?><?= h(($s['code_postal'] ? $s['code_postal'].' ' : '').$s['ville']) ?><?php endif; ?>
        </div>
      </div>

      <div class="sa-card-meta">
        <!-- Carte T -->
        <?php if ($carteNum): ?>
        <div class="sa-carte-block">
          <div class="sa-carte-num"><?= h($carteNum) ?></div>
          <?php if ($expiryTxt): ?>
          <div class="sa-carte-exp <?= $expiryClass ?>"><?= h($expiryTxt) ?></div>
          <?php endif; ?>
          <?php if ($activites): ?>
          <div class="sa-activites">
            <?php foreach (array_slice($activites, 0, 3) as $act): ?>
            <span class="sa-chip"><?= h(ucfirst(str_replace('_', ' ', $act))) ?></span>
            <?php endforeach; ?>
            <?php if (count($activites) > 3): ?>
            <span class="sa-chip sa-chip-more">+<?= count($activites) - 3 ?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="sa-no-carte">Carte T non renseignée</div>
        <?php endif; ?>

        <span class="sa-status <?= $actif ? 'status-actif' : 'status-inactif' ?>">
          <?= $actif ? '● Active' : '○ Inactive' ?>
        </span>

        <div class="sa-date"><?= $dateModif ?></div>
      </div>

      <div class="sa-actions">
        <a href="societe.php?sa_id=<?= (int)$s['id'] ?>" class="btn-sa-edit" title="Modifier la société">
          ✏ Gérer
        </a>
        <form method="post" class="sa-toggle-form" onsubmit="return confirm('Changer le statut de cette société ?')">
          <?= csrf_field('sa_societes') ?>
          <input type="hidden" name="action"     value="toggle_actif">
          <input type="hidden" name="societe_id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="btn-sa-toggle" title="<?= $actif ? 'Désactiver' : 'Activer' ?>">
            <?= $actif ? '⏸' : '▶' ?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="sa-empty">
      <div class="sa-empty-icon">🏢</div>
      <p>Aucune société trouvée<?= $search ? ' pour «&nbsp;' . h($search) . '&nbsp;»' : '' ?>.</p>
    </div>
    <?php endif; ?>

  </div><!-- /sa-content -->
  </div><!-- /sa-scroll -->
</div><!-- /sa-main -->

<!-- ── Modal : créer une société ── -->
<div id="modal-create" class="sa-modal-overlay" style="display:none" onclick="if(event.target===this)closeModal('modal-create')">
  <div class="sa-modal">
    <div class="sa-modal-head">
      <h3>Nouvelle société</h3>
      <button class="sa-modal-close" onclick="closeModal('modal-create')">✕</button>
    </div>
    <form method="post" class="sa-modal-body">
      <?= csrf_field('sa_societes') ?>
      <input type="hidden" name="action" value="create_societe">
      <label for="new-nom">Nom de la société *</label>
      <input type="text" id="new-nom" name="nom" placeholder="Ex : Dupont Immobilier" required autofocus>
    </form>
    <div class="sa-modal-foot">
      <button type="button" class="btn-modal-sec" onclick="closeModal('modal-create')">Annuler</button>
      <button type="submit" form="" class="btn-modal-create" onclick="this.closest('.sa-modal').querySelector('form').submit()">Créer</button>
    </div>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// Auto-submit search (debounced)
(function () {
    const input = document.querySelector('.sa-search-input');
    if (!input) return;
    let t;
    input.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.set('q', input.value);
            window.location.href = url.toString();
        }, 400);
    });
})();
</script>
</body>
</html>
