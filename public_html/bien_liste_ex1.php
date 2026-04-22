<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ── Filtres GET ───────────────────────────────────────── */
$filterType        = trim((string)($_GET['type']        ?? ''));
$filterTransaction = trim((string)($_GET['transaction']  ?? ''));
$filterStatut      = trim((string)($_GET['statut']       ?? 'actif'));
$filterSearch      = trim((string)($_GET['q']            ?? ''));
$page              = max(1, (int)($_GET['page'] ?? 1));
$perPage           = 12;
$offset            = ($page - 1) * $perPage;

/* ── Mode d'affichage (cards / list) ────────────────────
   Ordre de priorité : GET > cookie > défaut (list).
   Le GET écrit un cookie pour persister le choix entre pages. */
$viewMode = 'list'; // défaut : vue liste (plus dense, recherche rapide)
if (isset($_GET['view']) && in_array($_GET['view'], ['cards', 'list'], true)) {
    $viewMode = $_GET['view'];
    // Cookie 1 an (purement préférence d'affichage, pas de donnée sensible)
    setcookie('bl_view', $viewMode, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
} elseif (isset($_COOKIE['bl_view']) && in_array($_COOKIE['bl_view'], ['cards', 'list'], true)) {
    $viewMode = $_COOKIE['bl_view'];
}

/* ── Types de biens (pour le filtre) ───────────────────── */
try {
    $stmtTypes = $pdo->query("SELECT id, code, libelle FROM types_bien ORDER BY libelle ASC");
    $typesBien = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $typesBien = [];
}

/* ── Construction de la requête dynamique ──────────────── */
// Super-admin (role_id=1) voit TOUS les biens (cross-société/agence) ;
// les autres rôles sont restreints à leur société.
$isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if ($isSuperAdmin) {
    $where  = ['1=1'];
    $params = [];
} else {
    $where  = ["b.id_societe = :id_societe"];
    $params = [':id_societe' => $_SESSION['id_societe'] ?? 0];
}

if ($filterType !== '') {
    $where[]          = "tb.code = :type";
    $params[':type']  = $filterType;
}
if ($filterTransaction !== '') {
    // type_transaction vit sur annonces, pas sur biens — on filtre via EXISTS
    $where[]                = "EXISTS (SELECT 1 FROM annonces a3 WHERE a3.id_bien = b.id AND a3.type_transaction = :transaction)";
    $params[':transaction'] = $filterTransaction;
}
if ($filterStatut === 'en_diffusion') {
    // Statut "virtuel" : biens actifs avec au moins une annonce visible portails
    $where[] = "b.statut_bien = 'actif' AND EXISTS (SELECT 1 FROM annonces a2 WHERE a2.id_bien = b.id AND a2.visible_portails = 1)";
} elseif ($filterStatut !== '' && $filterStatut !== 'tous') {
    $where[]              = "b.statut_bien = :statut";
    $params[':statut']    = $filterStatut;
}
if ($filterSearch !== '') {
    // Recherche sur biens.* (avec fallback immeubles via COALESCE dans le SELECT)
    $where[]           = "(b.reference_bien LIKE :q OR b.designation LIKE :q OR b.ville LIKE :q OR b.adresse_1 LIKE :q OR i.ville LIKE :q OR i.adresse_1 LIKE :q)";
    $params[':q']      = '%' . $filterSearch . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

/* ── Count total ────────────────────────────────────────── */
try {
    $sqlCount = "
        SELECT COUNT(*)
        FROM biens b
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        $whereClause
    ";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalBiens = (int)$stmtCount->fetchColumn();
} catch (Throwable $ex) {
    $totalBiens = 0;
}

$totalPages = max(1, (int)ceil($totalBiens / $perPage));

/* ── Fetch biens ────────────────────────────────────────── */
// Transaction / prix / loyer vivent sur `annonces` — on récupère la dernière
// annonce du bien via sous-requête corrélée (id max). Pas besoin de GROUP BY.
try {
    $sqlBiens = "
        SELECT
            b.id,
            b.reference_bien,
            b.designation,
            b.statut_bien AS statut,
            b.surface_habitable,
            b.nb_pieces,
            b.nb_chambres,
            b.dpe_classe,
            b.date_creation,
            tb.code    AS type_code,
            tb.libelle AS type_libelle,
            COALESCE(i.adresse_1,   b.adresse_1)   AS adresse_1,
            COALESCE(i.code_postal, b.code_postal) AS code_postal,
            COALESCE(i.ville,       b.ville)       AS ville,
            (SELECT COUNT(*) FROM annonces a WHERE a.id_bien = b.id) AS nb_annonces,
            (SELECT a.type_transaction FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS type_transaction,
            (SELECT a.prix             FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS prix_vente,
            (SELECT a.loyer            FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS loyer_hc,
            (SELECT a.charges          FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS charges_locataire,
            -- Champs Ubiflow pour voyants de contrôle (ligne bien_liste)
            (SELECT a.id               FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS last_annonce_id,
            (SELECT a.titre            FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS last_titre,
            (SELECT a.description      FROM annonces a WHERE a.id_bien = b.id ORDER BY a.id DESC LIMIT 1) AS last_description,
            b.id_proprietaire,
            b.surface_totale,
            b.ges_classe,
            b.dpe_vierge,
            (SELECT COUNT(*) FROM biens_photos bp2 WHERE bp2.id_bien = b.id) AS nb_photos,
            (SELECT bp.url_photo FROM biens_photos bp WHERE bp.id_bien = b.id ORDER BY bp.ordre ASC, bp.id ASC LIMIT 1) AS photo_principale
        FROM biens b
        LEFT JOIN immeubles i  ON i.id  = b.id_immeuble
        LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        $whereClause
        ORDER BY b.date_creation DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmtBiens = $pdo->prepare($sqlBiens);
    foreach ($params as $k => $v) {
        $stmtBiens->bindValue($k, $v);
    }
    $stmtBiens->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmtBiens->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmtBiens->execute();
    $biens = $stmtBiens->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    $biens = [];
    // En dev (affichage des erreurs actif), on remonte l'erreur pour ne pas masquer
    // un bug SQL comme celui du 2026-04-11 (colonnes type_transaction/prix_vente/loyer_hc
    // référencées sur `biens` alors qu'elles vivent sur `annonces`).
    if (ini_get('display_errors')) {
        error_log('[bien_liste] fetch biens failed: ' . $ex->getMessage());
    }
}

/* ── Helpers ────────────────────────────────────────────── */
$typeEmoji = [
    'appartement'  => '🏢',
    'maison'       => '🏠',
    'garage'       => '🅿️',
    'terrain'      => '🌿',
    'local'        => '🏪',
    'immeuble'     => '🏗️',
];
$dpeColor = [
    'A' => '#00b050', 'B' => '#6abf4b', 'C' => '#ffcc00',
    'D' => '#ff9900', 'E' => '#ff6600', 'F' => '#e00000', 'G' => '#860000',
];
$statutLabel = [
    'actif'      => ['label' => 'Actif',      'class' => 'badge-success'],
    'inactif'    => ['label' => 'Inactif',    'class' => 'badge-muted'],
    'vendu'      => ['label' => 'Vendu',      'class' => 'badge-accent2'],
    'loue'       => ['label' => 'Loué',       'class' => 'badge-accent2'],
    'archive'    => ['label' => 'Archivé',    'class' => 'badge-danger'],
];

function formatPrix(array $bien): string {
    if (($bien['type_transaction'] ?? '') === 'vente') {
        $p = (float)($bien['prix_vente'] ?? 0);
        return $p > 0 ? number_format($p, 0, ',', ' ') . ' €' : '—';
    }
    $loyer   = (float)($bien['loyer_hc']           ?? 0);
    $charges = (float)($bien['charges_locataire']  ?? 0);
    if ($loyer <= 0) return '—';
    $total = $loyer + $charges;
    return number_format($total, 0, ',', ' ') . ' €/mois';
}

/**
 * Retourne l'URL publique absolue de la photo principale d'un bien,
 * ou null si aucune photo n'est rattachée. Utilise la colonne corrélée
 * `photo_principale` remplie côté SQL.
 */
function photoPrincipaleUrl(array $bien): ?string {
    $rel = trim((string)($bien['photo_principale'] ?? ''));
    if ($rel === '') return null;
    // url_photo est stocké relatif (ex: uploads/biens/1/2/01_abc.jpg)
    return function_exists('app_url') ? app_url('/' . ltrim($rel, '/')) : ('/' . ltrim($rel, '/'));
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mes biens — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <style>
    /* ─── TOKENS ─────────────────────────────────────────── */
    :root {
      --bg:      var(--bg-secondary);
      --card:    var(--bg-primary);
      --ink:     #1a1816;
      --muted:   #8a8680;
      --accent:  #36577d;
      --accent-2:#f59e0b;
      --accent-3:#7a9060;
      --danger:  #cc5c58;
      --stroke:  #d4d0ca;
      --sidebar-w: 220px;
      --topbar-h:  56px;
      --r-lg:  18px; --r-md: 12px; --r-sm: 8px; --r-pill: 999px;
      --neu-out: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      --neu-in:  inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Sora', system-ui, sans-serif;
      background: var(--bg);
      color: var(--ink);
      display: flex;
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }

    /* ─── LAYOUT MAIN ────────────────────────────────────── */
    .mbi-main {
      margin-left: var(--sidebar-w);
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    /* ─── TOPBAR V2 ──────────────────────────────────────── */
    .bl-topbar {
      height: var(--topbar-h);
      background: var(--card);
      box-shadow: 0 2px 8px var(--shadow-dark);
      border-bottom: 1px solid var(--stroke);
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 0 24px;
      position: sticky;
      top: 0;
      z-index: 50;
    }
    .topbar-nav-btn {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--bg); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out); color: var(--muted); transition: box-shadow .18s; flex-shrink: 0;
    }
    .topbar-nav-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .topbar-gap { width: 50px; flex-shrink: 0; }
    .topbar-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; color: var(--muted); }
    .topbar-breadcrumb .active { color: var(--accent); font-weight: 600; }
    .topbar-spacer { flex: 1; }
    .topbar-icon-btn {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--bg); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out); color: var(--muted); transition: box-shadow .18s; flex-shrink: 0;
    }
    .topbar-icon-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .topbar-avatar {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--accent); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 700;
      box-shadow: var(--neu-out); flex-shrink: 0;
    }

    /* ─── PAGE HEAD ──────────────────────────────────────── */
    .page-head {
      padding: 24px 28px 8px;
      display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap;
    }
    .page-head-info { flex: 1; }
    .page-head-label {
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
      letter-spacing: 1.2px; text-transform: uppercase; color: var(--accent-3); margin-bottom: 4px;
    }
    .page-head-title { font-size: 22px; font-weight: 700; color: var(--ink); }
    .page-head-sub { font-size: 13px; color: var(--muted); margin-top: 2px; }

    /* ─── BUTTONS ────────────────────────────────────────── */
    .bl-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 20px; border-radius: var(--r-pill);
      font-size: 13px; font-weight: 600; cursor: pointer;
      text-decoration: none; border: none; font-family: inherit;
      transition: opacity .15s; white-space: nowrap;
    }
    .bl-btn:active { opacity: .85; }
    .bl-btn-primary {
      background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to));
      color: #fff;
      box-shadow: 0 4px 12px rgba(249,115,22,0.35);
    }
    .bl-btn-ghost {
      background: var(--bg); color: var(--muted);
      box-shadow: var(--neu-out);
    }
    .bl-btn-ghost:hover { color: var(--ink); }

    /* ─── FILTERS BAR ────────────────────────────────────── */
    .bl-filters {
      background: var(--card);
      border-bottom: 1px solid var(--stroke);
      padding: 12px 28px;
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .bl-search {
      flex: 1; min-width: 220px; max-width: 360px;
      position: relative;
    }
    .bl-search input {
      width: 100%; padding: 8px 14px 8px 36px;
      background: var(--bg); border: none;
      border-radius: var(--r-pill); color: var(--ink);
      font-size: 13px; outline: none;
      box-shadow: var(--neu-in); font-family: inherit;
      transition: box-shadow .15s;
    }
    .bl-search input:focus { box-shadow: var(--neu-in), 0 0 0 2px rgba(54,87,125,0.2); }
    .bl-search .search-icon {
      position: absolute; left: 12px; top: 50%;
      transform: translateY(-50%); color: var(--muted);
      font-size: 13px; pointer-events: none;
    }
    .bl-select {
      padding: 8px 14px; background: var(--bg); border: none;
      border-radius: var(--r-pill); color: var(--ink);
      font-size: 13px; cursor: pointer; outline: none;
      box-shadow: var(--neu-in); font-family: inherit;
    }
    .bl-select:focus { box-shadow: var(--neu-in), 0 0 0 2px rgba(54,87,125,0.2); }
    .bl-filter-count {
      margin-left: auto; font-size: 12px; color: var(--muted); white-space: nowrap;
      font-family: 'DM Mono', monospace;
    }

    /* ─── CONTENT ────────────────────────────────────────── */
    .bl-content { padding: 24px 28px 60px; flex: 1; }

    /* ─── GRID ───────────────────────────────────────────── */
    .bl-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 18px;
    }

    /* ─── BIEN CARD ──────────────────────────────────────── */
    .bl-card {
      background: var(--card); border: none;
      border-radius: var(--r-lg);
      box-shadow: var(--neu-out);
      overflow: hidden;
      transition: box-shadow .18s, transform .18s;
      display: flex; flex-direction: column;
    }
    .bl-card:hover {
      transform: translateY(-3px);
      box-shadow: 10px 10px 24px #b8b4ae, -10px -10px 24px var(--shadow-light);
    }
    .bl-card-img {
      height: 160px;
      background: linear-gradient(135deg, #e4e6ec, var(--bg-primary));
      display: flex; align-items: center; justify-content: center;
      font-size: 2.8rem; position: relative; flex-shrink: 0;
    }
    .bl-card-img img {
      width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0;
    }
    .bl-card-badges {
      position: absolute; top: 10px; left: 10px;
      display: flex; gap: 6px; flex-wrap: wrap;
    }
    .bl-card-actions-top {
      position: absolute; top: 10px; right: 10px;
      display: flex; gap: 6px;
    }
    .badge {
      padding: 3px 10px; border-radius: var(--r-pill);
      font-size: 11px; font-weight: 700; letter-spacing: .04em;
    }
    .badge-transaction-location { background: rgba(54,87,125,.12); color: var(--accent); }
    .badge-transaction-vente    { background: rgba(249,115,22,.12); color: #c05000; }
    .badge-success              { background: #f0fdf4; color: #166534; }
    .badge-muted                { background: rgba(138,134,128,.10); color: var(--muted); }
    .badge-accent2              { background: rgba(245,158,11,.12); color: #92400e; }
    .badge-danger               { background: #fef2f2; color: #991b1b; }
    .icon-btn {
      width: 30px; height: 30px; border-radius: 8px;
      background: rgba(232,228,222,.85); border: none;
      color: var(--ink); font-size: .75rem;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      transition: background .15s; text-decoration: none;
      backdrop-filter: blur(4px);
    }
    .icon-btn:hover { background: var(--card); }

    .bl-card-body {
      padding: 16px; display: flex; flex-direction: column; gap: 10px; flex: 1;
    }
    .bl-card-ref {
      font-size: 11px; color: var(--muted);
      font-family: 'DM Mono', monospace;
    }
    .bl-card-title {
      font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.3;
    }
    .bl-card-addr {
      font-size: 12px; color: var(--muted);
      display: flex; align-items: center; gap: 5px;
    }
    .bl-card-meta { display: flex; gap: 12px; flex-wrap: wrap; }
    .bl-card-meta-item {
      font-size: 12px; color: var(--muted);
      display: flex; align-items: center; gap: 4px;
    }
    .bl-card-meta-item strong { color: var(--ink); }
    .bl-card-footer {
      border-top: 1px solid var(--stroke); padding: 12px 16px;
      display: flex; align-items: center; justify-content: space-between;
    }
    .bl-card-price {
      font-size: 16px; font-weight: 800; color: var(--accent);
    }
    .bl-card-footer-right { display: flex; align-items: center; gap: 8px; }
    .dpe-badge {
      width: 26px; height: 26px; border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 800; color: #fff;
    }
    .annonces-chip {
      font-size: 11px; color: var(--muted);
      background: rgba(54,87,125,.08);
      padding: 3px 8px; border-radius: var(--r-pill);
    }

    /* ─── EMPTY STATE ────────────────────────────────────── */
    .bl-empty { text-align: center; padding: 80px 20px; color: var(--muted); }
    .bl-empty-icon { font-size: 4rem; margin-bottom: 16px; opacity: .5; }
    .bl-empty h2 { font-size: 1.2rem; margin-bottom: 8px; color: var(--ink); }
    .bl-empty p  { font-size: .9rem; margin-bottom: 24px; }

    /* ─── PAGINATION ─────────────────────────────────────── */
    .bl-pagination {
      display: flex; align-items: center; justify-content: center;
      gap: 8px; padding: 28px 0 8px; flex-wrap: wrap;
    }
    .bl-page-btn {
      min-width: 36px; height: 36px; padding: 0 12px;
      border-radius: var(--r-sm); border: none;
      background: var(--bg); color: var(--muted);
      font-size: 13px; cursor: pointer; text-decoration: none;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out);
      transition: box-shadow .15s, color .15s;
    }
    .bl-page-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .bl-page-btn.current { box-shadow: var(--neu-in); color: var(--accent); font-weight: 700; }
    .bl-page-btn.disabled { opacity: .35; pointer-events: none; }

    /* ─── CONFIRM MODAL ──────────────────────────────────── */
    .bl-modal-overlay {
      position: fixed; inset: 0;
      background: rgba(26,24,22,.45); z-index: 200;
      display: none; align-items: center; justify-content: center;
    }
    .bl-modal-overlay.open { display: flex; }
    .bl-modal {
      background: var(--card); border: none;
      border-radius: var(--r-lg); box-shadow: var(--neu-out);
      padding: 28px; max-width: 420px; width: 90%;
    }
    .bl-modal h3 { font-size: 1.05rem; margin-bottom: 10px; color: var(--ink); }
    .bl-modal p  { font-size: .88rem; color: var(--muted); margin-bottom: 22px; }
    .bl-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

    /* ─── VIEW TOGGLE (cards / list) ─────────────────────── */
    .bl-view-toggle {
      display: inline-flex; gap: 0; margin-left: auto;
      background: var(--bg); border-radius: var(--r-sm);
      box-shadow: var(--neu-in); padding: 3px;
    }
    .bl-view-toggle a {
      display: flex; align-items: center; justify-content: center;
      width: 34px; height: 28px; border-radius: calc(var(--r-sm) - 2px);
      color: var(--muted); text-decoration: none;
      transition: background .15s, color .15s;
    }
    .bl-view-toggle a.active {
      background: var(--card); color: var(--accent);
      box-shadow: var(--neu-out);
    }
    .bl-view-toggle a:hover:not(.active) { color: var(--ink); }
    .bl-view-toggle svg { width: 16px; height: 16px; }

    /* ─── LIST VIEW ──────────────────────────────────────── */
    .bl-list {
      display: flex; flex-direction: column; gap: 10px;
    }
    .bl-list-row {
      background: var(--card); border-radius: var(--r-lg);
      box-shadow: var(--neu-out);
      display: grid;
      grid-template-columns: 96px 1fr auto auto auto auto;
      gap: 16px; align-items: center;
      padding: 10px 16px 10px 10px;
      transition: box-shadow .18s;
    }
    .bl-list-row:hover { box-shadow: var(--neu-in); }
    .bl-list-thumb {
      width: 96px; height: 72px; border-radius: var(--r-sm);
      background: linear-gradient(135deg, #e4e6ec, var(--bg-primary));
      display: flex; align-items: center; justify-content: center;
      font-size: 1.8rem; overflow: hidden; flex-shrink: 0;
      position: relative;
    }
    .bl-list-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .bl-list-main { min-width: 0; }
    .bl-list-ref {
      font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace;
    }
    .bl-list-title {
      font-size: 14px; font-weight: 700; color: var(--ink);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .bl-list-sub {
      font-size: 12px; color: var(--muted);
      display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap;
    }
    .bl-list-sub span { display: inline-flex; align-items: center; gap: 4px; }
    .bl-list-badges { display: flex; gap: 6px; flex-wrap: wrap; }
    .bl-list-price {
      font-size: 15px; font-weight: 800; color: var(--ink);
      white-space: nowrap;
    }
    .bl-list-actions { display: flex; gap: 6px; }

    /* Voyants Ubiflow sur chaque ligne */
    .bl-voyants {
      display: flex; gap: 3px; flex-wrap: nowrap; align-items: center;
      padding: 4px 6px; background: rgba(15,23,42,.03); border-radius: 99px;
    }
    .bl-v-dot {
      display: inline-flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; font-size: 11px; line-height: 1;
      border-radius: 50%; text-decoration: none;
      transition: transform .1s;
    }
    .bl-v-dot:hover { transform: scale(1.2); }
    .bl-v-ok   { background: #dcfce7; filter: grayscale(0); }
    .bl-v-warn { background: #fef3c7; box-shadow: 0 0 0 2px #f59e0b inset; }
    .bl-v-crit { background: #fee2e2; box-shadow: 0 0 0 2px #dc2626 inset;
                 animation: bl-pulse 1.5s ease-in-out infinite; }
    @keyframes bl-pulse {
      0%, 100% { box-shadow: 0 0 0 2px #dc2626 inset, 0 0 0 0 rgba(220,38,38,0.4); }
      50%      { box-shadow: 0 0 0 2px #dc2626 inset, 0 0 0 6px rgba(220,38,38,0); }
    }

    @media (max-width: 820px) {
      .bl-list-row {
        grid-template-columns: 72px 1fr auto;
      }
      .bl-list-row .bl-list-badges,
      .bl-list-row .bl-voyants,
      .bl-list-row .bl-list-price { grid-column: 2 / -1; }
      .bl-list-thumb { width: 72px; height: 54px; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

<div class="mbi-main">

  <?php if (isset($_GET['success']) && $_GET['success'] === 'bien_archive'): ?>
  <div id="flash-toast" style="position:fixed;top:16px;right:16px;z-index:99999;max-width:400px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.12);background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;">
    <div style="display:flex;align-items:center;gap:8px;">
      <span>📦</span><span>Bien archivé avec succès</span>
      <button onclick="this.parentElement.parentElement.remove()" style="margin-left:auto;background:none;border:none;font-size:16px;cursor:pointer;color:inherit;opacity:.6;">✕</button>
    </div>
  </div>
  <script>setTimeout(() => { const t = document.getElementById('flash-toast'); if (t) { t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(() => t.remove(), 300); }}, 4000);</script>
  <?php endif; ?>

  <!-- TOPBAR V2 -->
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <span class="active">Mes biens</span>
    </nav>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <!-- PAGE HEAD -->
  <div class="page-head">
    <div class="page-head-info">
      <div class="page-head-label">Gestion des biens</div>
      <h1 class="page-head-title">Mes biens</h1>
      <div class="page-head-sub"><?= $totalBiens ?> bien<?= $totalBiens > 1 ? 's' : '' ?> dans le portefeuille</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a href="<?= htmlspecialchars(app_url('/bien_detail.php')) ?>" class="bl-btn bl-btn-primary"
         title="Créer un nouveau bien — page unifiée : création, édition, documents, DPE, annonce, diffusion">
        ➕ Nouveau bien
      </a>
      <a href="<?= e(app_url('/guide_creation_bien.php')) ?>" class="bl-btn"
         style="background:#f97316;color:#fff;border:1px solid #ea580c;font-weight:700;text-decoration:none;"
         title="Guide pas-à-pas pour créer un bien et diffuser une annonce">
        📖 Guide création
      </a>
      <?php if ((int)($_SESSION['id_role'] ?? 0) === 1): ?>
      <button type="button" class="bl-btn" id="btn-purge-admin"
              style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-weight:700;cursor:pointer;font-family:inherit;"
              title="Supprimer réellement (avec cascade) les biens créés récemment — admin only">
        🗑 Nettoyage admin
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- FILTERS -->
  <form class="bl-filters" method="get" action="">
    <div class="bl-search">
      <span class="search-icon">🔍</span>
      <input type="text" name="q" placeholder="Référence, ville, désignation…" value="<?= e($filterSearch) ?>">
    </div>

    <select name="type" class="bl-select" onchange="this.form.submit()">
      <option value="">Tous les types</option>
      <?php foreach ($typesBien as $t): ?>
        <option value="<?= e($t['code']) ?>" <?= $filterType === $t['code'] ? 'selected' : '' ?>>
          <?= e($t['libelle']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="transaction" class="bl-select" onchange="this.form.submit()">
      <option value="">Location & Vente</option>
      <option value="location" <?= $filterTransaction === 'location' ? 'selected' : '' ?>>Location</option>
      <option value="vente"    <?= $filterTransaction === 'vente'    ? 'selected' : '' ?>>Vente</option>
    </select>

    <select name="statut" class="bl-select" onchange="this.form.submit()">
      <option value="tous" <?= $filterStatut === 'tous' ? 'selected' : '' ?>>Tous statuts</option>
      <option value="brouillon"    <?= $filterStatut === 'brouillon'    ? 'selected' : '' ?>>📝 Brouillon</option>
      <option value="actif"        <?= $filterStatut === 'actif'        ? 'selected' : '' ?>>Actif</option>
      <option value="en_diffusion" <?= $filterStatut === 'en_diffusion' ? 'selected' : '' ?>>🌐 En diffusion</option>
      <option value="inactif"      <?= $filterStatut === 'inactif'      ? 'selected' : '' ?>>Inactif</option>
      <option value="vendu"        <?= $filterStatut === 'vendu'        ? 'selected' : '' ?>>Vendu</option>
      <option value="loue"         <?= $filterStatut === 'loue'         ? 'selected' : '' ?>>Loué</option>
      <option value="archive"      <?= $filterStatut === 'archive'      ? 'selected' : '' ?>>Archivé</option>
    </select>

    <?php if ($filterSearch !== '' || $filterType !== '' || $filterTransaction !== '' || ($filterStatut !== '' && $filterStatut !== 'actif')): ?>
      <a href="bien_liste.php" class="bl-btn bl-btn-ghost" style="font-size:.8rem;">✕ Réinitialiser</a>
    <?php endif; ?>

    <span class="bl-filter-count">
      Page <?= $page ?> / <?= $totalPages ?>
    </span>

    <?php
      // URLs du toggle : conserve tous les filtres courants, on écrase seulement `view`
      $toggleBase = array_filter([
          'q'           => $filterSearch,
          'type'        => $filterType,
          'transaction' => $filterTransaction,
          'statut'      => $filterStatut,
      ], fn($v) => $v !== '');
      $urlCards = 'bien_liste.php?' . http_build_query($toggleBase + ['view' => 'cards']);
      $urlList  = 'bien_liste.php?' . http_build_query($toggleBase + ['view' => 'list']);
    ?>
    <div class="bl-view-toggle" role="group" aria-label="Mode d'affichage">
      <a href="<?= e($urlCards) ?>" class="<?= $viewMode === 'cards' ? 'active' : '' ?>" title="Vue cartes" aria-label="Vue cartes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      </a>
      <a href="<?= e($urlList) ?>" class="<?= $viewMode === 'list' ? 'active' : '' ?>" title="Vue liste" aria-label="Vue liste">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
      </a>
    </div>
  </form>

  <!-- CONTENT -->
  <div class="bl-content">

    <?php if (empty($biens)): ?>
      <div class="bl-empty">
        <div class="bl-empty-icon">🏠</div>
        <h2>Aucun bien trouvé</h2>
        <p>
          <?= ($filterSearch || $filterType || $filterTransaction || $filterStatut)
            ? 'Aucun résultat pour ces filtres. Essayez une autre combinaison.'
            : 'Vous n\'avez pas encore ajouté de bien. Commencez maintenant !' ?>
        </p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
          <a href="<?= htmlspecialchars(app_url('/bien_detail.php')) ?>" class="bl-btn bl-btn-primary">
            ➕ Nouveau bien
          </a>
        </div>
      </div>

    <?php else: ?>
      <?php
        // ── Pré-calcul des données communes aux deux vues (cards / list) ──
        // Évite de dupliquer la logique entre les deux boucles.

        /**
         * Calcule les 7 voyants Ubiflow pour un bien donné.
         * Retourne une liste de [key, label, ok, severity, section, focus].
         * - severity : 'critical' (rouge, bloque la diffusion) ou 'warning' (jaune, impact qualité)
         * - section  : l'onglet bien_detail à ouvrir au clic
         * - focus    : le paramètre ?focus= à scroller/focus
         */
        $ubiflowChecksBien = static function(array $b): array {
            $tc = strtolower((string)($b['type_code'] ?? ''));
            $exemptDpeSurface = in_array($tc, ['parking','stationnement','garage','box','terrain','terrain_agricole'], true);
            $trans = (string)($b['type_transaction'] ?? '');
            $checks = [];

            // Titre (annonce)
            $checks[] = [
                'key' => 'titre', 'label' => 'Titre annonce',
                'ok' => !empty($b['last_titre']),
                'severity' => 'critical', 'section' => 'annonce', 'focus' => 'titre',
            ];
            // Description (annonce)
            $checks[] = [
                'key' => 'description', 'label' => 'Description',
                'ok' => !empty($b['last_description']),
                'severity' => 'critical', 'section' => 'annonce', 'focus' => 'description',
            ];
            // Type transaction (annonce)
            $checks[] = [
                'key' => 'transaction', 'label' => 'Type de transaction (vente/location)',
                'ok' => $trans !== '',
                'severity' => 'critical', 'section' => 'annonce', 'focus' => 'type_transaction',
            ];
            // Prix ou loyer selon transaction
            $prixOk = true; $prixFocus = 'prix';
            if ($trans === 'vente') {
                $prixOk = (float)($b['prix_vente'] ?? 0) > 0;
            } elseif (in_array($trans, ['location','saisonnier'], true)) {
                $prixOk = (float)($b['loyer_hc'] ?? 0) > 0;
                $prixFocus = 'loyer';
            }
            $checks[] = [
                'key' => 'prix', 'label' => ($trans === 'vente' ? 'Prix de vente' : 'Loyer HC'),
                'ok' => $prixOk,
                'severity' => 'critical', 'section' => 'annonce', 'focus' => $prixFocus,
            ];
            // Photos
            $checks[] = [
                'key' => 'photos', 'label' => 'Au moins une photo',
                'ok' => (int)($b['nb_photos'] ?? 0) >= 1,
                'severity' => 'critical', 'section' => 'documents', 'focus' => '',
            ];
            // Adresse (via JOIN immeubles — COALESCE déjà fait dans SELECT)
            $adrOk = !empty($b['adresse_1']) && !empty($b['code_postal']) && !empty($b['ville']);
            $checks[] = [
                'key' => 'adresse', 'label' => 'Adresse complète',
                'ok' => $adrOk,
                'severity' => 'critical', 'section' => 'descriptif', 'focus' => 'adresse_1',
            ];
            // Propriétaire
            $checks[] = [
                'key' => 'proprio', 'label' => 'Propriétaire',
                'ok' => (int)($b['id_proprietaire'] ?? 0) > 0,
                'severity' => 'critical', 'section' => 'descriptif', 'focus' => 'proprio',
            ];
            // Secondaires (warning)
            if (!$exemptDpeSurface) {
                $checks[] = [
                    'key' => 'surface', 'label' => 'Surface habitable',
                    'ok' => (float)($b['surface_habitable'] ?? 0) > 0 || (float)($b['surface_totale'] ?? 0) > 0,
                    'severity' => 'warning', 'section' => 'descriptif', 'focus' => 'surface_habitable',
                ];
                $checks[] = [
                    'key' => 'dpe', 'label' => 'DPE (classe ou vierge)',
                    'ok' => !empty($b['dpe_classe']) || (int)($b['dpe_vierge'] ?? 0) === 1,
                    'severity' => 'warning', 'section' => 'dpe', 'focus' => 'dpe_classe',
                ];
            }
            return $checks;
        };

        $items = [];
        foreach ($biens as $b) {
            $trans  = $b['type_transaction'] ?? 'location';
            $statut = $b['statut'] ?? 'actif';
            if ($statut === 'brouillon') {
                $sl = ['label' => '📝 Brouillon', 'class' => 'badge-muted'];
            } else {
                $sl = $statutLabel[$statut] ?? ['label' => ucfirst($statut), 'class' => 'badge-muted'];
            }
            $needsAttention = false;
            $missingHints   = [];
            if ($statut === 'actif') {
                if (empty($b['dpe_classe'])) { $needsAttention = true; $missingHints[] = 'DPE'; }
                if ((int)($b['nb_pieces'] ?? 0) <= 0
                    && in_array($b['type_code'] ?? '', ['appartement','maison'], true)) {
                    $needsAttention = true; $missingHints[] = 'Pièces';
                }
                if (empty($b['designation']) || mb_strlen((string)$b['designation']) < 50) {
                    $needsAttention = true; $missingHints[] = 'Désignation';
                }
            }
            // Voyants Ubiflow (7-9 checks selon type)
            $ubiChecks = $ubiflowChecksBien($b);
            $ubiCritiqueKO = 0; $ubiWarnKO = 0;
            foreach ($ubiChecks as $c) {
                if (!$c['ok']) {
                    if ($c['severity'] === 'critical') $ubiCritiqueKO++;
                    else                               $ubiWarnKO++;
                }
            }

            $items[] = [
                'b'       => $b,
                'emoji'   => $typeEmoji[$b['type_code'] ?? ''] ?? '🏢',
                'trans'   => $trans,
                'statut'  => $statut,
                'sl'      => $sl,
                'needs'   => $needsAttention,
                'hints'   => $missingHints,
                'dpe'     => strtoupper($b['dpe_classe'] ?? ''),
                'dpeC'    => $dpeColor[strtoupper($b['dpe_classe'] ?? '')] ?? '#555',
                'titre'   => $b['designation'] ?: (($b['type_libelle'] ?? '') . ' — ' . ($b['ville'] ?? '')),
                'adresse' => trim(($b['adresse_1'] ?? '') . ', ' . ($b['code_postal'] ?? '') . ' ' . ($b['ville'] ?? ''), ', '),
                'photo'   => photoPrincipaleUrl($b),
                'urlEdit'     => app_url('/bien_detail.php?edit=' . (int)$b['id']),
                'urlAnnonces' => app_url('/annonce_nouvelle.php?id_bien=' . (int)$b['id']),
                'urlDiffuser' => app_url('/annonce_nouvelle.php?id_bien=' . (int)$b['id']),
                'ubiChecks'   => $ubiChecks,
                'ubiCritKO'   => $ubiCritiqueKO,
                'ubiWarnKO'   => $ubiWarnKO,
            ];
        }
      ?>

      <?php if ($viewMode === 'list'): ?>
        <!-- ═══ VUE LISTE ═══ -->
        <div class="bl-list">
          <?php foreach ($items as $it): $b = $it['b']; ?>
            <div class="bl-list-row">
              <a href="<?= e($it['urlEdit']) ?>" class="bl-list-thumb" title="Modifier">
                <?php if ($it['photo']): ?>
                  <img src="<?= e($it['photo']) ?>" alt="<?= e($it['titre']) ?>" loading="lazy">
                <?php else: ?>
                  <?= $it['emoji'] ?>
                <?php endif; ?>
              </a>

              <div class="bl-list-main">
                <div class="bl-list-ref"><?= e($b['reference_bien'] ?? '—') ?></div>
                <div class="bl-list-title">
                  <a href="<?= e($it['urlEdit']) ?>" style="color:inherit;text-decoration:none;"><?= e($it['titre']) ?></a>
                </div>
                <div class="bl-list-sub">
                  <?php if ($it['adresse'] !== ''): ?><span>📍 <?= e($it['adresse']) ?></span><?php endif; ?>
                  <?php if (!empty($b['surface_habitable'])): ?><span>📐 <strong><?= e($b['surface_habitable']) ?></strong> m²</span><?php endif; ?>
                  <?php if (!empty($b['nb_pieces'])): ?><span>🚪 <strong><?= (int)$b['nb_pieces'] ?></strong> p.</span><?php endif; ?>
                  <?php if (!empty($b['nb_chambres'])): ?><span>🛏 <strong><?= (int)$b['nb_chambres'] ?></strong> ch.</span><?php endif; ?>
                  <?php if ($b['nb_annonces'] > 0): ?><span>📢 <?= (int)$b['nb_annonces'] ?> annonce<?= $b['nb_annonces'] > 1 ? 's' : '' ?></span><?php endif; ?>
                </div>
              </div>

              <div class="bl-list-badges">
                <span class="badge badge-transaction-<?= e($it['trans']) ?>">
                  <?= $it['trans'] === 'vente' ? 'Vente' : 'Location' ?>
                </span>
                <span class="badge <?= e($it['sl']['class']) ?>"><?= e($it['sl']['label']) ?></span>
                <?php if ($it['dpe'] !== ''): ?>
                  <div class="dpe-badge" style="background:<?= $it['dpeC'] ?>" title="DPE"><?= e($it['dpe']) ?></div>
                <?php endif; ?>
              </div>

              <!-- Voyants Ubiflow — un clic envoie au champ à compléter -->
              <div class="bl-voyants" title="Ubiflow : <?= $it['ubiCritKO'] ?> bloquant(s) · <?= $it['ubiWarnKO'] ?> avertissement(s)">
                <?php foreach ($it['ubiChecks'] as $c):
                  $cls = $c['ok']
                    ? 'bl-v-ok'
                    : ($c['severity'] === 'critical' ? 'bl-v-crit' : 'bl-v-warn');
                  $lblIc = [
                    'titre' => '📝', 'description' => '📄', 'transaction' => '💼',
                    'prix' => '💰', 'photos' => '📸', 'adresse' => '📍',
                    'proprio' => '👤', 'surface' => '📐', 'dpe' => '⚡',
                  ][$c['key']] ?? '●';
                  $focusParam = $c['focus'] ? '&focus=' . rawurlencode((string)$c['focus']) : '';
                  $url = app_url('/bien_detail.php?edit=' . (int)$b['id']
                        . '&section=' . rawurlencode((string)$c['section']) . $focusParam);
                ?>
                  <a href="<?= e($url) ?>" class="bl-v-dot <?= $cls ?>"
                     title="<?= e($c['label']) ?><?= $c['ok'] ? ' — ✅ OK' : ($c['severity'] === 'critical' ? ' — ❌ BLOQUANT' : ' — ⚠️ À compléter') ?>">
                    <?= $lblIc ?>
                  </a>
                <?php endforeach; ?>
              </div>

              <div class="bl-list-price"><?= formatPrix($b) ?></div>

              <div class="bl-list-actions">
                <a href="<?= e($it['urlEdit']) ?>" class="icon-btn" title="Modifier">✏️</a>
                <a href="<?= e($it['urlAnnonces']) ?>" class="icon-btn" title="Voir les annonces">👁️</a>
                <a href="<?= e($it['urlDiffuser']) ?>" class="icon-btn" title="Diffuser">📡</a>
                <button class="icon-btn" title="Supprimer"
                        onclick="confirmDelete(<?= (int)$b['id'] ?>, '<?= e($b['reference_bien'] ?? 'ce bien') ?>')">🗑️</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php else: ?>
        <!-- ═══ VUE CARDS ═══ -->
        <div class="bl-grid">
          <?php foreach ($items as $it): $b = $it['b']; ?>
          <div class="bl-card">

            <!-- Image : photo principale si dispo, sinon emoji -->
            <div class="bl-card-img">
              <?php if ($it['photo']): ?>
                <img src="<?= e($it['photo']) ?>" alt="<?= e($it['titre']) ?>" loading="lazy">
              <?php else: ?>
                <?= $it['emoji'] ?>
              <?php endif; ?>

              <div class="bl-card-badges">
                <span class="badge badge-transaction-<?= e($it['trans']) ?>">
                  <?= $it['trans'] === 'vente' ? 'Vente' : 'Location' ?>
                </span>
                <span class="badge <?= e($it['sl']['class']) ?>"><?= e($it['sl']['label']) ?></span>
                <?php if ($it['needs']): ?>
                  <span class="badge badge-warning"
                        title="Champs manquants pour la diffusion : <?= e(implode(', ', $it['hints'])) ?>"
                        style="background:#fef3c7;color:#92400e;border:1px solid #f59e0b;">
                    ⚠ Diffusion
                  </span>
                <?php endif; ?>
              </div>

              <div class="bl-card-actions-top">
                <a href="<?= e($it['urlEdit']) ?>" class="icon-btn" title="Modifier">✏️</a>
                <a href="<?= e($it['urlAnnonces']) ?>" class="icon-btn" title="Voir les annonces">👁️</a>
                <button class="icon-btn" title="Supprimer"
                        onclick="confirmDelete(<?= (int)$b['id'] ?>, '<?= e($b['reference_bien'] ?? 'ce bien') ?>')">🗑️</button>
              </div>
            </div>

            <!-- Body -->
            <div class="bl-card-body">
              <div class="bl-card-ref"><?= e($b['reference_bien'] ?? '—') ?></div>
              <div class="bl-card-title"><?= e($it['titre']) ?></div>

              <?php if ($it['adresse'] !== ''): ?>
              <div class="bl-card-addr">
                <span>📍</span>
                <span><?= e($it['adresse']) ?></span>
              </div>
              <?php endif; ?>

              <div class="bl-card-meta">
                <?php if (!empty($b['surface_habitable'])): ?>
                <div class="bl-card-meta-item">
                  <span>📐</span><strong><?= e($b['surface_habitable']) ?></strong> m²
                </div>
                <?php endif; ?>
                <?php if (!empty($b['nb_pieces'])): ?>
                <div class="bl-card-meta-item">
                  <span>🚪</span><strong><?= e($b['nb_pieces']) ?></strong> pièce<?= $b['nb_pieces'] > 1 ? 's' : '' ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($b['nb_chambres'])): ?>
                <div class="bl-card-meta-item">
                  <span>🛏</span><strong><?= e($b['nb_chambres']) ?></strong> ch.
                </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Footer -->
            <div class="bl-card-footer">
              <div class="bl-card-price"><?= formatPrix($b) ?></div>
              <div class="bl-card-footer-right">
                <?php if ($b['nb_annonces'] > 0): ?>
                  <span class="annonces-chip">📢 <?= (int)$b['nb_annonces'] ?> annonce<?= $b['nb_annonces'] > 1 ? 's' : '' ?></span>
                <?php endif; ?>
                <?php if ($it['dpe'] !== ''): ?>
                  <div class="dpe-badge" style="background:<?= $it['dpeC'] ?>" title="DPE <?= e($it['dpe']) ?>">
                    <?= e($it['dpe']) ?>
                  </div>
                <?php endif; ?>
                <a href="<?= e($it['urlDiffuser']) ?>"
                   class="bl-btn bl-btn-ghost" style="padding:5px 12px;font-size:12px;">
                   📡 Diffuser
                </a>
              </div>
            </div>

          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- PAGINATION -->
      <?php if ($totalPages > 1):
        $queryBase = http_build_query(array_filter([
            'q'           => $filterSearch,
            'type'        => $filterType,
            'transaction' => $filterTransaction,
            'statut'      => $filterStatut,
            'view'        => $viewMode !== 'cards' ? $viewMode : '',
        ], fn($v) => $v !== ''));
        $qSep = $queryBase !== '' ? '&' : '';
      ?>
      <div class="bl-pagination">
        <a href="?<?= $queryBase . $qSep ?>page=<?= max(1, $page - 1) ?>"
           class="bl-page-btn <?= $page <= 1 ? 'disabled' : '' ?>">← Préc.</a>

        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="?<?= $queryBase . $qSep ?>page=<?= $p ?>"
             class="bl-page-btn <?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>

        <a href="?<?= $queryBase . $qSep ?>page=<?= min($totalPages, $page + 1) ?>"
           class="bl-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Suiv. →</a>
      </div>
      <?php endif; ?>

    <?php endif; ?>
  </div><!-- /bl-content -->
</div><!-- /mbi-main -->

<!-- CONFIRM DELETE MODAL -->
<div class="bl-modal-overlay" id="deleteModal">
  <div class="bl-modal">
    <h3>📦 Archiver ce bien ?</h3>
    <p id="deleteModalText">Le bien sera archivé et retiré des portails. Vous pourrez le réactiver à tout moment.</p>
    <div class="bl-modal-actions">
      <button class="bl-btn bl-btn-ghost" onclick="closeModal()">Annuler</button>
      <a id="deleteConfirmBtn" href="#" class="bl-btn" style="background:#e6a141;color:#fff;box-shadow:0 4px 10px rgba(230,161,65,0.3);">Archiver</a>
    </div>
  </div>
</div>

<script>
function confirmDelete(id, ref) {
    document.getElementById('deleteModalText').textContent =
        'Archiver le bien "' + ref + '" ? Il sera retiré des portails mais restera accessible.';
    document.getElementById('deleteConfirmBtn').href =
        'bien_supprimer.php?id=' + id + '&csrf=<?= csrf_token() ?>';
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php if ((int)($_SESSION['id_role'] ?? 0) === 1): ?>
<!-- ═══ MODAL : Nettoyage admin (suppression réelle + cascade) ═══ -->
<div id="purgeModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:1100;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;max-width:780px;width:calc(100% - 40px);max-height:90vh;overflow-y:auto;padding:28px;">
    <h2 style="margin:0 0 6px;color:#b91c1c;font-size:20px;">🗑 Suppression réelle + cascade (admin)</h2>
    <p style="margin:0 0 18px;color:#64748b;font-size:13px;">
      Supprime <strong>définitivement</strong> les biens correspondant au filtre + toutes les lignes liées dans 25+ tables (annonces, mandats, diagnostics, photos, documents, baux, actes…). <strong>Irréversible.</strong>
    </p>

    <!-- Étape 1 : scope -->
    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:14px;">
      <div style="font-weight:700;font-size:12px;color:#0f172a;margin-bottom:10px;">1️⃣ Définir le scope</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <label style="font-size:12px;color:#475569;">
          Créés depuis&nbsp;:
          <input type="datetime-local" id="purgeDateMin"
                 value="<?= date('Y-m-d\T00:00', strtotime('yesterday')) ?>"
                 style="display:block;margin-top:4px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;width:100%;">
        </label>
        <div style="font-size:12px;color:#475569;">
          Statuts :
          <div style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap;">
            <label><input type="checkbox" class="purgeStatut" value="brouillon" checked> brouillon</label>
            <label><input type="checkbox" class="purgeStatut" value="actif" checked> actif</label>
            <label><input type="checkbox" class="purgeStatut" value="suspendu"> suspendu</label>
            <label><input type="checkbox" class="purgeStatut" value="archive"> archive</label>
          </div>
        </div>
      </div>
      <button type="button" id="btnPurgePreview"
              style="margin-top:14px;padding:8px 14px;border-radius:8px;background:#0ea5e9;color:#fff;border:none;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;">
        📋 Prévisualiser
      </button>
    </div>

    <!-- Étape 2 : résultats + confirmation -->
    <div id="purgeResults" style="display:none;"></div>

    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
      <button type="button" id="btnPurgeClose"
              style="padding:8px 14px;border-radius:8px;background:#fff;color:#475569;border:1px solid #cbd5e1;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">
        Fermer
      </button>
      <button type="button" id="btnPurgeDelete" disabled
              style="padding:8px 14px;border-radius:8px;background:#dc2626;color:#fff;border:none;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;opacity:.4;pointer-events:none;">
        🗑 Supprimer définitivement
      </button>
    </div>
  </div>
</div>

<script>
(function() {
  const modal        = document.getElementById('purgeModal');
  const btnOpen      = document.getElementById('btn-purge-admin');
  const btnClose     = document.getElementById('btnPurgeClose');
  const btnPreview   = document.getElementById('btnPurgePreview');
  const btnDelete    = document.getElementById('btnPurgeDelete');
  const results      = document.getElementById('purgeResults');
  const csrf         = '<?= csrf_token('bien_delete_cascade') ?>';
  let previewToken   = null;

  function openModal()  { modal.style.display = 'flex'; }
  function closeModal() { modal.style.display = 'none'; results.innerHTML = ''; results.style.display = 'none'; lockDelete(); }
  function lockDelete() { btnDelete.disabled = true; btnDelete.style.opacity = .4; btnDelete.style.pointerEvents = 'none'; previewToken = null; }
  function unlockDelete() { btnDelete.disabled = false; btnDelete.style.opacity = 1; btnDelete.style.pointerEvents = 'auto'; }

  function buildFormData(action) {
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', action);
    fd.append('date_min', document.getElementById('purgeDateMin').value);
    document.querySelectorAll('.purgeStatut:checked').forEach(c => fd.append('statuts[]', c.value));
    if (action === 'delete' && previewToken) fd.append('token', previewToken);
    return fd;
  }

  function renderCandidates(data) {
    const cands = data.candidates || [];
    const byTbl = data.count_by_table || {};
    let html = '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:12px;color:#92400e;">';
    html += '<strong>' + data.count + ' bien' + (data.count > 1 ? 's' : '') + '</strong> dans le scope. ';
    const totalRelated = Object.values(byTbl).reduce((a, b) => a + b, 0);
    html += totalRelated + ' ligne(s) liée(s) dans ' + Object.keys(byTbl).length + ' table(s) filles.';
    html += '</div>';

    if (cands.length) {
      html += '<div style="max-height:220px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px;"><table style="width:100%;border-collapse:collapse;font-size:11px;">';
      html += '<thead style="position:sticky;top:0;background:#f1f5f9;"><tr><th style="padding:6px 8px;text-align:left;">ID</th><th style="padding:6px 8px;text-align:left;">Ref</th><th style="padding:6px 8px;text-align:left;">Statut</th><th style="padding:6px 8px;text-align:left;">Adresse</th><th style="padding:6px 8px;text-align:left;">Créé le</th></tr></thead><tbody>';
      cands.forEach(c => {
        html += '<tr style="border-top:1px solid #e5e7eb;">'
             +  '<td style="padding:5px 8px;font-family:monospace;color:#64748b;">' + c.id + '</td>'
             +  '<td style="padding:5px 8px;">' + (c.reference_bien || '—') + '</td>'
             +  '<td style="padding:5px 8px;"><span style="padding:2px 6px;border-radius:99px;background:' + (c.statut_bien === 'brouillon' ? '#fef3c7' : '#dbeafe') + ';font-size:10px;">' + c.statut_bien + '</span></td>'
             +  '<td style="padding:5px 8px;color:#475569;">' + ((c.adresse_1 || '') + ' ' + (c.ville || '')).trim() + '</td>'
             +  '<td style="padding:5px 8px;color:#94a3b8;font-size:10px;">' + (c.date_creation || '') + '</td>'
             +  '</tr>';
      });
      html += '</tbody></table></div>';
    }

    if (Object.keys(byTbl).length) {
      html += '<details style="margin-bottom:8px;"><summary style="cursor:pointer;color:#475569;font-size:11px;">Détail des lignes liées (' + Object.keys(byTbl).length + ' tables)</summary>';
      html += '<div style="padding:8px 12px;font-size:11px;color:#475569;font-family:monospace;line-height:1.7;">';
      Object.entries(byTbl).sort((a,b) => b[1] - a[1]).forEach(([t, n]) => {
        html += t + ' : ' + n + '<br>';
      });
      html += '</div></details>';
    }
    return html;
  }

  function renderDeleted(data) {
    const d = data.deleted || {};
    let html = '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px;font-size:13px;color:#14532d;">';
    html += '✅ <strong>Suppression terminée</strong> — ' + (data.total_biens || 0) + ' bien(s) supprimé(s) définitivement.';
    html += '</div>';
    html += '<details style="margin-top:10px;"><summary style="cursor:pointer;color:#475569;font-size:11px;">Détail par table (' + Object.keys(d).length + ')</summary>';
    html += '<div style="padding:8px 12px;font-size:11px;color:#475569;font-family:monospace;line-height:1.7;">';
    Object.entries(d).forEach(([t, n]) => { html += t + ' : ' + n + '<br>'; });
    html += '</div></details>';
    return html;
  }

  btnOpen.addEventListener('click', openModal);
  btnClose.addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

  btnPreview.addEventListener('click', async () => {
    lockDelete();
    btnPreview.disabled = true;
    btnPreview.textContent = '⏳ Chargement…';
    try {
      const r = await fetch('<?= htmlspecialchars(app_url('/api/bien_delete_cascade.php')) ?>', { method: 'POST', body: buildFormData('preview') });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Erreur inconnue');
      results.innerHTML = renderCandidates(data);
      results.style.display = 'block';
      if (data.count > 0) {
        previewToken = data.token;
        unlockDelete();
      }
    } catch (e) {
      results.innerHTML = '<div style="background:#fef2f2;color:#991b1b;padding:10px;border-radius:8px;font-size:12px;">❌ ' + e.message + '</div>';
      results.style.display = 'block';
    } finally {
      btnPreview.disabled = false;
      btnPreview.textContent = '📋 Prévisualiser';
    }
  });

  // Invalidate preview token if scope changes
  document.querySelectorAll('.purgeStatut, #purgeDateMin').forEach(el => {
    el.addEventListener('change', () => { lockDelete(); results.innerHTML = ''; results.style.display = 'none'; });
  });

  btnDelete.addEventListener('click', async () => {
    if (!previewToken) return;
    if (!confirm('Confirmer la SUPPRESSION DÉFINITIVE ? Cette action est IRRÉVERSIBLE.')) return;
    if (!confirm('Dernière confirmation — tape OK dans la prochaine boîte pour valider.')) return;
    const t = prompt('Tape "SUPPRIMER" pour confirmer :', '');
    if (t !== 'SUPPRIMER') { alert('Annulé.'); return; }

    btnDelete.disabled = true;
    btnDelete.textContent = '⏳ Suppression…';
    try {
      const r = await fetch('<?= htmlspecialchars(app_url('/api/bien_delete_cascade.php')) ?>', { method: 'POST', body: buildFormData('delete') });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Erreur inconnue');
      results.innerHTML = renderDeleted(data);
      lockDelete();
      setTimeout(() => location.reload(), 2000);
    } catch (e) {
      results.innerHTML = '<div style="background:#fef2f2;color:#991b1b;padding:10px;border-radius:8px;font-size:12px;">❌ ' + e.message + '</div>';
      btnDelete.disabled = false;
      btnDelete.textContent = '🗑 Supprimer définitivement';
    }
  });
})();
</script>
<?php endif; ?>

</body>
</html>
