<?php
/**
 * bailleur_patrimoine_actif.php — État du patrimoine actif
 * Fusion de : bailleur_dashboard.php + gestion/patrimoine_sir.php + tableau_proprietaires.php
 * Accès : super admin uniquement (import CRG = régie)
 * 3 niveaux par immeuble : Actifs | Partis pliés (archivables) | Archivés pliés
 * Immeubles vendus archivés en bas (pliés)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();

// ── Accès : super admin OU service bailleur ─────────────
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403);
    exit('Accès réservé au module Bailleur.');
}

// ── Filtre propriétaires ─────────────────────────────────
// Priorité : 1) GET props[]  2) Session bailleur_props  3) Tous
$propFilterWhere = '';
if ($isSuperAdmin) {
    $getProps = [];
    if (isset($_GET['props'])) {
        // GET explicite → mémoriser en session
        foreach ((array)$_GET['props'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $getProps[] = $sid;
        }
        $_SESSION['bailleur_props'] = $getProps;
    } elseif (!empty($_SESSION['bailleur_props'])) {
        // Reprendre la session (navigation depuis dashboard)
        foreach ($_SESSION['bailleur_props'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $getProps[] = $sid;
        }
    }
    if (!empty($getProps)) {
        $in = implode(',', $getProps);
        $propFilterWhere = "AND ct.id_proprietaire IN ({$in})";
    }
    // Badge de filtre actif
    $filtreActif = !empty($getProps);
    $filtreIds   = $getProps;
} else {
    // Bailleur : limité à ses user_proprietaires
    $stmtP = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
    $stmtP->execute([$userId]);
    $allowedIds = $stmtP->fetchAll(\PDO::FETCH_COLUMN);
    if (empty($allowedIds)) {
        $propFilterWhere = 'AND 1=0';
    } else {
        $in = implode(',', array_map('intval', $allowedIds));
        $propFilterWhere = "AND ct.id_proprietaire IN ({$in})";
    }
    $filtreActif = false;
    $filtreIds   = [];
}

// Construire l'URL retour dashboard avec même sélection
$backQuery = !empty($filtreIds) ? '?' . http_build_query(['props' => $filtreIds]) : '';

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function fmtE(float $v): string {
    if ($v == 0) return '—';
    return '€' . number_format((float)$v, 0, ',', ' ');
}
function sumCols(array $locs, string $col): float {
    return array_sum(array_column($locs, $col));
}

// ── BASE SQL — UNIQUEMENT le dernier trimestre par propriétaire ──
// On prend le trimestre le plus récent chargé pour chaque propriétaire
// (ex: T1 2026) et on affiche TOUS les locataires de ce trimestre.
// Les locataires d'anciens trimestres ne sont PAS inclus.
$base_sql = "
    SELECT
      crg.id_bien,
      crg.locataire_nom,
      crg.loyer_appele,
      crg.total_loyers,
      crg.total_regle,
      crg.total_impaye,
      ct.id_proprietaire,
      ct.annee,
      ct.trimestre,
      CASE WHEN crg.loyer_appele > 0 THEN 'present' ELSE 'parti' END AS presence,
      b.id_immeuble,
      COALESCE(i.vendu, 0)    AS imm_vendu,
      COALESCE(ls.archive, 0) AS loc_archive
    FROM crg_situations_locataires crg
    JOIN crg_trimestres ct ON crg.id_crg = ct.id
    LEFT JOIN locataires_statuts ls
      ON ls.locataire_nom = crg.locataire_nom
     AND ls.id_bien = crg.id_bien
     AND ls.id_proprietaire = ct.id_proprietaire
     AND (ls.statut IS NULL OR ls.statut != 'irrecoverable')
    LEFT JOIN biens b ON b.id = crg.id_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    WHERE ct.parse_statut = 'ok' {$propFilterWhere}
      AND (ct.annee, ct.trimestre) = (
        SELECT ct2.annee, ct2.trimestre
        FROM crg_trimestres ct2
        WHERE ct2.id_proprietaire = ct.id_proprietaire
          AND ct2.parse_statut = 'ok'
        ORDER BY ct2.annee DESC, ct2.trimestre DESC
        LIMIT 1
      )
";

// ── STATS GLOBALES ───────────────────────────────────────
$stats = $pdo->query("
    SELECT
      COUNT(DISTINCT p.id) AS nb_prop,
      COUNT(DISTINCT CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_presents,
      COUNT(DISTINCT CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_partis,
      ROUND(SUM(CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                     THEN sub.total_impaye ELSE 0 END),0) AS impaye_actif,
      ROUND(SUM(CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0
                     THEN sub.total_impaye ELSE 0 END),0) AS creances_partis
    FROM proprietaires p
    JOIN ({$base_sql}) sub ON sub.id_proprietaire = p.id
")->fetch(\PDO::FETCH_ASSOC);

// ── LISTE PAR PROPRIÉTAIRE ──────────────────────────────
$result = $pdo->query("
    SELECT
      p.id, p.civilite, p.nom, p.prenom, p.societe, p.type_personne,
      COUNT(DISTINCT CASE WHEN sub.imm_vendu=0 THEN sub.id_bien END) AS nb_biens,
      COUNT(DISTINCT CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_presents,
      COUNT(DISTINCT CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_partis,
      ROUND(SUM(CASE WHEN sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.loyer_appele ELSE 0 END)/3,0) AS loyer_total,
      ROUND(SUM(CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.total_impaye ELSE 0 END),0) AS impaye_actif,
      ROUND(SUM(CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.total_impaye ELSE 0 END),0) AS creances_partis,
      ROUND((SELECT SUM(c2.total_regle) FROM crg_trimestres t2 JOIN crg_situations_locataires c2 ON c2.id_crg=t2.id WHERE t2.id_proprietaire=p.id AND t2.annee=2026 AND t2.trimestre=1),0) AS enc_t1_2026,
      ROUND((SELECT SUM(c2.total_regle) FROM crg_trimestres t2 JOIN crg_situations_locataires c2 ON c2.id_crg=t2.id WHERE t2.id_proprietaire=p.id AND t2.annee=2025),0) AS enc_2025
    FROM proprietaires p
    JOIN ({$base_sql}) sub ON sub.id_proprietaire = p.id
    GROUP BY p.id
    ORDER BY impaye_actif DESC, creances_partis DESC
");
$rows = $result->fetchAll(\PDO::FETCH_ASSOC);

// ── DÉTAIL PAR PROPRIÉTAIRE ──────────────────────────────
$details = [];
foreach ($rows as $row) {
    $pid = $row['id'];
    $stmt = $pdo->prepare("
        SELECT
          sub.id_bien, sub.locataire_nom, sub.loyer_appele,
          sub.total_regle, sub.total_impaye, sub.presence,
          sub.imm_vendu, sub.loc_archive,
          CONCAT(sub.annee,' T',sub.trimestre) AS dernier_crg,
          b.reference_bien,
          i.id AS id_immeuble, i.nom_immeuble, i.adresse_1, i.date_vente
        FROM ({$base_sql}) sub
        LEFT JOIN biens b ON b.id = sub.id_bien
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        WHERE sub.id_proprietaire = ?
        ORDER BY sub.imm_vendu ASC, sub.presence ASC, sub.loc_archive ASC,
                 b.reference_bien ASC, sub.locataire_nom
    ");
    $stmt->execute([$pid]);
    $details[$pid] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

// ── Mode affichage : 1 proprio → immeubles dépliés auto ─
// Si un seul propriétaire visible (filtre ou bailleur avec 1 prop)
$singlePropMode = (count($rows) === 1);

// ── HELPERS ─────────────────────────────────────────────
function groupByImmeuble(array $rows): array {
    $groups = []; $order = [];
    foreach ($rows as $d) {
        $iid = $d['id_immeuble'] ?? 0;
        if (!isset($groups[$iid])) {
            $groups[$iid] = [
                'id_immeuble'  => $iid,
                'nom_immeuble' => $d['nom_immeuble'] ?? '—',
                'adresse_1'    => $d['adresse_1'] ?? '',
                'date_vente'   => $d['date_vente'] ?? null,
                'vendu'        => (int)($d['imm_vendu'] ?? 0),
                'actifs'       => [],
                'partis'       => [],
                'archives'     => [],
            ];
            $order[] = $iid;
        }
        if ((int)$d['loc_archive'] === 1)     $groups[$iid]['archives'][] = $d;
        elseif ($d['presence'] === 'present')  $groups[$iid]['actifs'][]   = $d;
        else                                   $groups[$iid]['partis'][]   = $d;
    }
    return array_map(fn($iid) => $groups[$iid], $order);
}

function renderLocRows(array $locs, string $niveau, int $pid): string {
    if (empty($locs)) return '';
    $html = '';
    foreach ($locs as $d) {
        $nom   = e($d['locataire_nom']);
        $bien  = e($d['reference_bien'] ?? '—');
        $crg   = e($d['dernier_crg']);
        $loyer  = $d['loyer_appele'] > 0 ? '€'.number_format((float)$d['loyer_appele']/3, 0,',',' ') : '—';
        $regle  = $d['total_regle']  > 0 ? '€'.number_format((float)$d['total_regle'], 0,',',' ')  : '—';
        $impaye = $d['total_impaye'] > 0
            ? '<strong style="color:#c62828">€'.number_format((float)$d['total_impaye'], 0,',',' ').'</strong>'
            : '—';
        $ib   = (int)$d['id_bien'];
        $nenc = addslashes($d['locataire_nom']);
        if ($niveau === 'actif') {
            $badge = $d['total_impaye'] > 0
                ? "<span class='badge b-imp'>IMPAYÉ</span>"
                : "<span class='badge b-ok'>RÉGULIER</span>";
            $btn = '';
        } elseif ($niveau === 'parti') {
            $badge = "<span class='badge b-parti'>PARTI</span>";
            $btn = "<button class='btn-loc btn-archive' onclick=\"archiveLoc({$ib},'{$nenc}',{$pid},1)\">📦 Archiver</button>";
        } else {
            $badge = "<span class='badge b-arch'>ARCHIVÉ</span>";
            $btn = "<button class='btn-loc btn-restore' onclick=\"archiveLoc({$ib},'{$nenc}',{$pid},0)\">↩️ Restaurer</button>";
        }
        $tr_cls = match($niveau) { 'parti'=>'parti', 'archive'=>'archive', default=>'' };
        $onclick = "openLocHistory({$ib},".htmlspecialchars(json_encode($d['locataire_nom']), ENT_QUOTES).",{$pid})";
        $html .= "<tr class='{$tr_cls}'>
            <td class='nom-col'><strong class='loc-link' onclick=\"{$onclick}\" title='Voir historique CRG'>{$nom}</strong></td>
            <td><code style='font-size:.82em'>{$bien}</code></td>
            <td>{$badge}</td>
            <td class='num-r'>{$loyer}</td>
            <td class='num-r'>{$regle}</td>
            <td class='num-r'>{$impaye}</td>
            <td>{$crg}</td>
            <td class='action-col'>{$btn}</td>
        </tr>";
    }
    return $html;
}

$col_headers = "
<table class='detail'>
<thead><tr>
  <th data-col='0' onclick='sortTable(this)'>LOCATAIRE<span class='sort-icon'>⇅</span></th>
  <th data-col='1' onclick='sortTable(this)'>BIEN<span class='sort-icon'>⇅</span></th>
  <th data-col='2' onclick='sortTable(this)'>STATUT<span class='sort-icon'>⇅</span></th>
  <th data-col='3' class='num-r' onclick='sortTable(this)'>LOYER /mois €<span class='sort-icon'>⇅</span></th>
  <th data-col='4' class='num-r' onclick='sortTable(this)'>ENCAISSÉ €<span class='sort-icon'>⇅</span></th>
  <th data-col='5' class='num-r' onclick='sortTable(this)'>IMPAYÉ €<span class='sort-icon'>⇅</span></th>
  <th data-col='6' onclick='sortTable(this)'>DERNIER CRG<span class='sort-icon'>⇅</span></th>
  <th style='width:110px'></th>
</tr></thead>
<tbody>";

// ── LAYOUT MBI ──────────────────────────────────────────
$pageTitle    = 'État du Patrimoine Actif';
$pageSubtitle = 'Ma Box Bailleur · Patrimoine';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_patrimoine_actif';

$extraCss = '
<style>
/* ═══════════════ PATRIMOINE ACTIF ═══════════════ */
.pat-summary { background:#e8eaf6; padding:14px 18px; border-left:5px solid #3f51b5;
               border-radius:6px; margin:0 0 20px; font-size:.93em; }
.pat-legend  { background:#fff9c4; padding:9px 14px; border-left:4px solid #f9a825;
               border-radius:4px; font-size:.84em; margin-bottom:16px; }

/* TABLE PRINCIPALE */
table.main-pat { border-collapse:collapse; width:100%; background:white;
                 box-shadow:0 2px 6px rgba(0,0,0,.1); border-radius:6px; overflow:hidden; }
table.main-pat thead tr { background:#1a237e; color:white; }
table.main-pat thead th { padding:10px 12px; text-align:left; font-size:.82em; white-space:nowrap; }
table.main-pat tbody tr.prop-row { cursor:pointer; transition:background .15s; }
table.main-pat tbody tr.prop-row:hover td { background:#e8f4fd; }
table.main-pat tbody tr.prop-row.active td { background:#dbeafe; }
table.main-pat tbody td { padding:9px 12px; border-bottom:1px solid #eee; font-size:.88em; vertical-align:middle; }
table.main-pat tr.total-row td { background:#e8eaf6; font-weight:bold; border-top:2px solid #3f51b5; }

/* DÉTAIL INLINE */
tr.detail-row { display:none; }
tr.detail-row.open { display:table-row; }
tr.detail-row td { padding:0; background:#f8f9ff; border-bottom:3px solid #3f51b5; }
.detail-inner { padding:14px 18px; }
.detail-inner > h4 { margin:0 0 10px; color:#1a237e; font-size:.92em; }

/* BLOC IMMEUBLE */
.imm-block { margin-bottom:12px; border:1px solid #c5cae9; border-radius:6px; overflow:hidden; }
.imm-block.vendu-block { border-color:#e0e0e0; opacity:.72; }
.imm-header { display:flex; align-items:center; gap:10px; padding:7px 12px; background:#e8eaf6; }
.imm-header.vendu-header { background:#f5f5f5; }
.imm-title { flex:1; font-weight:bold; font-size:.86em; color:#1a237e; }
.imm-title.vendu-title { color:#9e9e9e; text-decoration:line-through; }
.imm-addr { font-size:.78em; color:#666; font-weight:normal; }
.imm-meta { font-size:.76em; color:#666; font-weight:normal; margin-left:8px; }

/* BARRE TOTAUX IMMEUBLE */
.imm-totals { display:flex; gap:0; border-top:2px solid #3f51b5; background:#e8eaf6; font-size:.81em; font-weight:bold; }
.imm-totals .it-cell { padding:5px 12px; border-right:1px solid #c5cae9; }
.imm-totals .it-cell:last-child { border-right:none; }
.imm-totals .it-label { color:#555; font-weight:normal; font-size:.9em; display:block; }
.imm-totals .it-val.enc { color:#2e7d32; }
.imm-totals .it-val.imp { color:#c62828; }
.imm-totals .it-val.loyer { color:#1565c0; }

/* TOTAUX PROPRIÉTAIRE */
.prop-totals { display:flex; gap:0; border:2px solid #1a237e; border-radius:6px;
               background:#e8eaf6; font-size:.86em; font-weight:bold;
               margin-top:14px; overflow:hidden; }
.prop-totals .pt-cell { padding:7px 14px; border-right:1px solid #c5cae9; flex:1; }
.prop-totals .pt-cell:last-child { border-right:none; }
.prop-totals .pt-label { color:#555; font-size:.83em; font-weight:normal; display:block; margin-bottom:2px; }
.prop-totals .pt-val.enc { color:#2e7d32; }
.prop-totals .pt-val.imp { color:#c62828; }
.prop-totals .pt-val.loyer { color:#1565c0; }

/* SECTIONS PLIABLES */
.fold-toggle { display:flex; align-items:center; gap:8px; padding:5px 12px;
               cursor:pointer; user-select:none; font-size:.81em; font-weight:bold;
               border-top:1px solid #e0e0e0; }
.fold-toggle:hover { filter:brightness(.96); }
.fold-toggle.partis-toggle  { color:#880e4f; background:#fdf2f5; }
.fold-toggle.archive-toggle { color:#616161; background:#f5f5f5; }
.fold-toggle.vendus-toggle  { color:#616161; background:#eeeeee; border-radius:6px;
                               border:1px solid #bdbdbd; display:inline-flex;
                               margin-top:12px; padding:6px 14px; }
.fold-arrow { transition:transform .2s; display:inline-block; font-size:.83em; }
.fold-toggle.open .fold-arrow { transform:rotate(90deg); }
.fold-content { display:none; }
.fold-content.open { display:block; }

/* TABLE DÉTAIL */
table.detail { border-collapse:collapse; width:100%; font-size:.83em; }
table.detail th { background:#3f51b5; color:white; padding:6px 10px;
                  text-align:left; white-space:nowrap; cursor:pointer; user-select:none; }
table.detail th:hover { background:#303f9f; }
.sort-icon { margin-left:4px; font-size:.78em; opacity:.7; }
table.detail td { padding:5px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
table.detail tr:hover td { background:#f0f4ff; }
table.detail tr.parti   td { background:#fff5f7; color:#5d1a2e; }
table.detail tr.archive td { background:#fafafa; color:#9e9e9e; }
table.detail tr.tot-sub td { background:#f5f0ff; color:#4a148c; font-weight:bold;
                              font-size:.82em; border-top:1px solid #ce93d8; }

/* BOUTONS LOC */
.btn-loc { border:none; border-radius:10px; padding:2px 8px; font-size:.74em;
           cursor:pointer; font-weight:bold; white-space:nowrap; }
.btn-archive { background:#fff3e0; color:#e65100; border:1px solid #ffb74d; }
.btn-archive:hover { background:#ffe0b2; }
.btn-restore { background:#e8f5e9; color:#2e7d32; border:1px solid #81c784; }
.btn-restore:hover { background:#c8e6c9; }
.btn-vendu { border:none; border-radius:12px; padding:3px 10px; font-size:.76em;
             cursor:pointer; font-weight:bold; white-space:nowrap; }
.btn-vendu.mark   { background:#ffecb3; color:#e65100; border:1px solid #ffb300; }
.btn-vendu.mark:hover   { background:#ffe082; }
.btn-vendu.unmark { background:#c8e6c9; color:#1b5e20; border:1px solid #81c784; }

/* BADGES */
.badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:.77em; font-weight:bold; }
.b-soc   { background:#bbdefb; color:#0d47a1; }
.b-phy   { background:#e1bee7; color:#4a148c; }
.b-ok    { background:#c8e6c9; color:#1b5e20; }
.b-warn  { background:#ffe0b2; color:#bf360c; }
.b-bad   { background:#ffcdd2; color:#b71c1c; }
.b-parti { background:#fce4ec; color:#880e4f; }
.b-imp   { background:#fff3e0; color:#e65100; }
.b-arch  { background:#eeeeee; color:#757575; }

/* MISC */
.arrow { font-size:.78em; margin-left:5px; transition:transform .2s; display:inline-block; }
.active .arrow { transform:rotate(180deg); }
.num-r { text-align:right; }
.txt-g { color:#2e7d32; font-weight:bold; }
.txt-r { color:#880e4f; }
.txt-b { color:#1565c0; font-weight:bold; }
.action-col { text-align:right; white-space:nowrap; }
.loc-link { cursor:pointer; text-decoration:underline dotted; text-underline-offset:2px; }
.loc-link:hover { color:#1a237e; }

/* MODAL VENDU */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:2000;
                 align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:white; border-radius:10px; padding:26px 30px; min-width:300px;
             box-shadow:0 8px 32px rgba(0,0,0,.25); }
.modal-box h3 { margin:0 0 14px; color:#1a237e; font-size:.95em; }
.modal-box label { display:block; margin-bottom:8px; font-size:.88em; }
.modal-box input[type=date] { width:100%; padding:7px; border:1px solid #ccc; border-radius:4px; font-size:.92em; margin-top:3px; }
.modal-box .modal-btns { display:flex; gap:8px; margin-top:16px; justify-content:flex-end; }
.modal-box button { padding:6px 16px; border-radius:6px; border:none; cursor:pointer; font-size:.85em; font-weight:bold; }
.btn-confirm { background:#e65100; color:white; }
.btn-cancel  { background:#e0e0e0; color:#333; }

/* MODAL HISTORIQUE LOCATAIRE */
.hist-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:2100;
                align-items:center; justify-content:center; padding:20px; }
.hist-overlay.open { display:flex; }
.hist-box { background:white; border-radius:12px; width:100%; max-width:820px; max-height:88vh;
            display:flex; flex-direction:column; box-shadow:0 12px 48px rgba(0,0,0,.3); overflow:hidden; }
.hist-header { background:#1a237e; color:white; padding:14px 20px; display:flex; align-items:center; gap:10px; }
.hist-header h3 { margin:0; font-size:.95em; flex:1; }
.hist-close { background:rgba(255,255,255,.2); border:none; color:white; border-radius:50%;
              width:26px; height:26px; cursor:pointer; font-size:.9em; }
.hist-tabs { display:flex; gap:0; border-bottom:2px solid #e0e0e0; padding:0 14px;
             background:#f8f9ff; overflow-x:auto; flex-shrink:0; }
.hist-tab { padding:8px 14px; border:none; background:none; cursor:pointer; font-size:.82em;
            font-weight:bold; color:#666; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; }
.hist-tab:hover { color:#1a237e; }
.hist-tab.active { color:#1a237e; border-bottom-color:#1a237e; background:white; }
.hist-tab.latest { color:#2e7d32; }
.hist-tab.latest.active { border-bottom-color:#2e7d32; }
.hist-body { flex:1; overflow-y:auto; padding:18px 22px; }
.hist-panel { display:none; }
.hist-panel.active { display:block; }
.crg-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
.crg-card { background:#f5f7ff; border:1px solid #c5cae9; border-radius:8px; padding:12px 14px; }
.crg-card.enc { background:#f1f8f1; border-color:#a5d6a7; }
.crg-card.imp { background:#fff5f5; border-color:#ef9a9a; }
.crg-card.ok  { background:#f1f8f1; border-color:#a5d6a7; }
.crg-card .cc-label { font-size:.76em; color:#666; margin-bottom:3px; }
.crg-card .cc-val { font-size:1.2em; font-weight:bold; color:#1a237e; }
.crg-card.enc .cc-val { color:#2e7d32; }
.crg-card.imp .cc-val { color:#c62828; }
.crg-delta { font-size:.76em; margin-top:3px; }
.delta-up { color:#c62828; } .delta-down { color:#2e7d32; }
.hist-loading { text-align:center; padding:36px; color:#888; font-size:.92em; }
.pdf-viewer-wrap { margin-top:12px; border:1px solid #c5cae9; border-radius:6px; overflow:hidden; }
.pdf-viewer-bar  { background:#3f51b5; color:white; padding:6px 14px; font-size:.8em; display:flex; align-items:center; gap:10px; }
.pdf-viewer-bar a { color:#bbdefb; font-size:.88em; margin-left:auto; }
.pdf-iframe { width:100%; height:500px; border:none; display:block; background:#f5f5f5; }
.pdf-missing { padding:16px; text-align:center; color:#999; font-size:.86em; background:#fafafa; }
table.timeline { border-collapse:collapse; width:100%; font-size:.82em; }
table.timeline th { background:#e8eaf6; color:#1a237e; padding:5px 10px; text-align:left; }
table.timeline td { padding:5px 10px; border-bottom:1px solid #eee; }
table.timeline tr.active-row td { background:#fff9c4; font-weight:bold; }
.hist-timeline h4 { font-size:.82em; color:#555; margin:0 0 8px; text-transform:uppercase; letter-spacing:.05em; }
</style>
';

require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<!-- ══════════════════════════════════════════════════════════
     CONTENU PRINCIPAL
     ══════════════════════════════════════════════════════════ -->
<div style="padding:20px 24px; max-width:1400px; margin:0 auto;">

<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
  <h2 style="margin:0;color:#1a237e;font-size:1.2em;">🏛️ État du Patrimoine Actif</h2>
  <span style="background:#e8eaf6;color:#3f51b5;padding:3px 10px;border-radius:10px;font-size:.8em;font-weight:bold;">
    <?= $isSuperAdmin ? 'SUPER ADMIN' : 'BAILLEUR' ?> — <?= date('d/m/Y') ?>
  </span>
  <?php if ($filtreActif): ?>
  <span style="background:#fff3e0;color:#e65100;padding:3px 10px;border-radius:10px;font-size:.8em;font-weight:bold;">
    📊 Vue filtrée
  </span>
  <?php endif; ?>
  <a href="bailleur_dashboard.php<?= $backQuery ?>" style="margin-left:auto;font-size:.82em;color:#3f51b5;text-decoration:none;">
    ← Retour au tableau de bord
  </a>
</div>

<?php if ($filtreActif && $isSuperAdmin):
  // Récupérer les labels des filtres
  $stmtFilt = $pdo->prepare("SELECT COALESCE(societe, CONCAT(prenom,' ',nom)) AS label FROM proprietaires WHERE id=?");
  $filtLabels = [];
  foreach ($filtreIds as $fid) {
      $stmtFilt->execute([$fid]);
      $r = $stmtFilt->fetchColumn();
      if ($r) $filtLabels[] = $r;
  }
?>
<div style="background:#e8eaf6;border-left:4px solid #3f51b5;border-radius:6px;padding:8px 14px;
            margin-bottom:12px;font-size:.85em;color:#1a237e;display:flex;align-items:center;gap:10px;">
  Vue filtrée :
  <?php foreach ($filtLabels as $i => $lbl): ?>
    <strong><?= e($lbl) ?></strong><?= $i < count($filtLabels)-1 ? ' · ' : '' ?>
  <?php endforeach; ?>
  <a href="bailleur_patrimoine_actif.php" style="margin-left:auto;color:#666;text-decoration:none;font-size:.9em;">✕ Voir tout</a>
</div>
<?php endif; ?>

<div class="pat-legend">
  📌 Immeubles vendus &amp; locataires archivés exclus des totaux &nbsp;|&nbsp;
  🟢 <strong>Actifs</strong> = loyer &gt; 0 &nbsp;|&nbsp;
  🔴 <strong>Partis</strong> = créance résiduelle (pliés, archivables) &nbsp;|&nbsp;
  📦 <strong>Archivés</strong> = dossiers clos &nbsp;|&nbsp;
  🏷️ <strong>Immeuble vendu</strong> = bouton dans le détail
</div>

<div class="pat-summary">
  👤 <strong><?= $stats['nb_prop'] ?></strong> propriétaires &nbsp;|&nbsp;
  🏠 Actifs : <strong><?= $stats['nb_presents'] ?></strong> &nbsp;|&nbsp;
  🚪 Partis-débiteurs : <strong><?= $stats['nb_partis'] ?></strong><br><br>
  🟢 Impayés actifs : <strong class="txt-g">€<?= number_format((float)$stats['impaye_actif'],0,',',' ') ?></strong>
  &nbsp;&nbsp;
  🔴 Créances partis : <strong style="color:#c62828">€<?= number_format((float)$stats['creances_partis'],0,',',' ') ?></strong>
</div>

<table class="main-pat">
<thead<?= $singlePropMode ? ' style="display:none"' : '' ?>><tr>
  <th>PROPRIÉTAIRE</th><th>TYPE</th>
  <th style="text-align:center">BIENS</th>
  <th style="text-align:center">ACTIFS</th>
  <th class="num-r">LOYERS /mois €</th>
  <th class="num-r">ENCAISSÉ T1 2026</th>
  <th class="num-r">ENCAISSÉ 2025</th>
  <th class="num-r">IMPAYÉS ACTIFS €</th>
  <th class="num-r">CRÉANCES PARTIS €</th>
  <th>QUALITÉ</th>
</tr></thead>
<tbody>
<?php
$tot_loyer=$tot_actif=$tot_partis=$tot_enc_t1=$tot_enc_2025=0;
foreach ($rows as $row):
    $pid = $row['id'];
    $nom = trim(implode(' ', array_filter([
        $row['civilite'], $row['prenom'], $row['nom'],
        ($row['societe'] && $row['societe'] !== $row['nom']) ? $row['societe'] : null,
    ])));
    $type = $row['societe']
        ? "<span class='badge b-soc'>SOCIÉTÉ</span>"
        : "<span class='badge b-phy'>PHYSIQUE</span>";
    $pct = $row['loyer_total'] > 0 ? round($row['impaye_actif']/$row['loyer_total']*100) : 0;
    $enc_t1   = $row['enc_t1_2026'] ?? 0;
    $enc_2025 = $row['enc_2025']    ?? 0;
    $q = $row['impaye_actif']==0
        ? "<span class='badge b-ok'>🟢 OK</span>"
        : ($pct<50 ? "<span class='badge b-warn'>🟠 {$pct}%</span>"
                   : "<span class='badge b-bad'>🔴 {$pct}%</span>");
    $tot_loyer+=$row['loyer_total']; $tot_actif+=$row['impaye_actif'];
    $tot_partis+=$row['creances_partis']; $tot_enc_t1+=$enc_t1; $tot_enc_2025+=$enc_2025;

    $groups     = groupByImmeuble($details[$pid]);
    $actifs_imm = array_values(array_filter($groups, fn($g)=>$g['vendu']==0));
    $vendus_imm = array_values(array_filter($groups, fn($g)=>$g['vendu']==1));
    $nb_vendus  = count($vendus_imm);

    $ptot = ['loyer'=>0,'regle'=>0,'impaye_a'=>0,'impaye_p'=>0,'impaye_ar'=>0];
    foreach ($actifs_imm as $g) {
        $ta  = ['loyer'=>array_sum(array_column($g['actifs'],'loyer_appele')),
                'regle'=>array_sum(array_column($g['actifs'],'total_regle')),
                'impaye'=>array_sum(array_column($g['actifs'],'total_impaye'))];
        $tp  = ['regle'=>array_sum(array_column($g['partis'],'total_regle')),
                'impaye'=>array_sum(array_column($g['partis'],'total_impaye'))];
        $tar = ['regle'=>array_sum(array_column($g['archives'],'total_regle')),
                'impaye'=>array_sum(array_column($g['archives'],'total_impaye'))];
        $ptot['loyer']     += $ta['loyer'];
        $ptot['regle']     += $ta['regle']+$tp['regle']+$tar['regle'];
        $ptot['impaye_a']  += $ta['impaye'];
        $ptot['impaye_p']  += $tp['impaye'];
        $ptot['impaye_ar'] += $tar['impaye'];
    }
?>
<tr class="prop-row<?= $singlePropMode ? ' active' : '' ?>"
    onclick="toggleDetail(<?=$pid?>)" id="row-<?=$pid?>"
    <?= $singlePropMode ? 'style="display:none"' : '' ?>>
  <td><strong><?=e($nom)?></strong><span class="arrow" id="arr-<?=$pid?>">▼</span></td>
  <td><?=$type?></td>
  <td style="text-align:center"><?=$row['nb_biens']?>
    <?=$nb_vendus>0?"<span style='color:#9e9e9e;font-size:.76em'>(+{$nb_vendus}v)</span>":""?>
  </td>
  <td style="text-align:center"><?=$row['nb_presents']?>
    <?=$row['nb_partis']>0?"<span style='color:#880e4f;font-size:.76em'>(+{$row['nb_partis']}p)</span>":""?>
  </td>
  <td class="num-r">€<?=number_format((float)$row['loyer_total'], 0,',',' ')?></td>
  <td class="num-r txt-b"><?=$enc_t1>0?'€'.number_format((float)$enc_t1, 0,',',' '):'—'?></td>
  <td class="num-r" style="color:#1565c0"><?=$enc_2025>0?'€'.number_format((float)$enc_2025, 0,',',' '):'—'?></td>
  <td class="num-r txt-g">€<?=number_format((float)$row['impaye_actif'], 0,',',' ')?></td>
  <td class="num-r txt-r">€<?=number_format((float)$row['creances_partis'], 0,',',' ')?></td>
  <td><?=$q?></td>
</tr>
<tr class="detail-row<?= $singlePropMode ? ' open' : '' ?>" id="detail-<?=$pid?>">
  <td colspan="10">
    <div class="detail-inner">
      <h4>
        👤 <?=e($nom)?> —
        <?=$row['nb_presents']?> actif(s) · <?=$row['nb_partis']?> parti(s) · <?=$row['nb_biens']?> bien(s)
        <?=$nb_vendus>0?"· <span style='color:#9e9e9e'>{$nb_vendus} vendu(s)</span>":""?>
      </h4>

<?php foreach ($actifs_imm as $g):
    $iid   = (int)$g['id_immeuble'];
    $iname = e($g['nom_immeuble']);
    $iaddr = e($g['adresse_1']);
    $nb_a  = count($g['actifs']); $nb_p = count($g['partis']); $nb_ar = count($g['archives']);
    $uid   = "imm-{$pid}-{$iid}";
    $ta    = ['loyer'=>array_sum(array_column($g['actifs'],'loyer_appele')),
              'regle'=>array_sum(array_column($g['actifs'],'total_regle')),
              'impaye'=>array_sum(array_column($g['actifs'],'total_impaye'))];
    $tp    = ['regle'=>array_sum(array_column($g['partis'],'total_regle')),
              'impaye'=>array_sum(array_column($g['partis'],'total_impaye'))];
    $tar   = ['regle'=>array_sum(array_column($g['archives'],'total_regle')),
              'impaye'=>array_sum(array_column($g['archives'],'total_impaye'))];
    $it_loyer = $ta['loyer'] / 3; // ÷3 : loyer_appele est trimestriel
    $it_regle = $ta['regle']+$tp['regle']+$tar['regle'];
    $it_imp_a = $ta['impaye']; $it_imp_p = $tp['impaye']; $it_imp_ar = $tar['impaye'];
?>
      <div class="imm-block" id="imm-block-<?=$iid?>">
        <div class="imm-header">
          <span class="imm-title">
            🏢 <?=$iname?>
            <?=$iaddr?"<span class='imm-addr'>— {$iaddr}</span>":""?>
            <span class="imm-meta">
              <?=$nb_a?> actif(s)<?=$nb_p>0?"·<span style='color:#c62828'> {$nb_p}p</span>":""?><?=$nb_ar>0?"·<span style='color:#9e9e9e'> {$nb_ar}ar</span>":""?>
            </span>
          </span>
          <button class="btn-vendu mark"
                  onclick="event.stopPropagation();openVenduModal(<?=$iid?>,'<?=addslashes($g['nom_immeuble'])?>',<?=$pid?>)">
            🏷️ Marquer vendu
          </button>
        </div>

        <?php if ($nb_a > 0): ?>
        <?=$col_headers?><?=renderLocRows($g['actifs'],'actif',$pid)?></tbody></table>
        <?php endif; ?>

        <?php if ($nb_p > 0): ?>
        <div class="fold-toggle partis-toggle" onclick="toggleFold(this,'<?=$uid?>-partis')">
          <span class="fold-arrow">▶</span>
          🚪 <?=$nb_p?> parti(s) —
          <span style="color:#c62828;margin-left:4px">€<?=number_format((float)$tp['impaye'], 0,',',' ')?> impayés</span>
          <span style="color:#2e7d32;margin-left:10px">€<?=number_format((float)$tp['regle'], 0,',',' ')?> encaissé</span>
        </div>
        <div class="fold-content" id="<?=$uid?>-partis">
          <?=$col_headers?><?=renderLocRows($g['partis'],'parti',$pid)?></tbody></table>
        </div>
        <?php endif; ?>

        <?php if ($nb_ar > 0): ?>
        <div class="fold-toggle archive-toggle" onclick="toggleFold(this,'<?=$uid?>-archives')">
          <span class="fold-arrow">▶</span>
          📦 <?=$nb_ar?> archivé(s)
          <?=$tar['impaye']>0?"— <span style='color:#9e9e9e'>€".number_format((float)$tar['impaye'], 0,',',' ')." résiduel</span>":""?>
        </div>
        <div class="fold-content" id="<?=$uid?>-archives">
          <?=$col_headers?><?=renderLocRows($g['archives'],'archive',$pid)?></tbody></table>
        </div>
        <?php endif; ?>

        <!-- Totaux immeuble -->
        <div class="imm-totals">
          <div class="it-cell"><span class="it-label">Loyers /mois</span>
            <span class="it-val loyer"><?=$it_loyer>0?'€'.number_format((float)$it_loyer,0,',',' '):'—'?></span></div>
          <div class="it-cell"><span class="it-label">Total encaissé</span>
            <span class="it-val enc"><?=$it_regle>0?'€'.number_format((float)$it_regle,0,',',' '):'—'?></span></div>
          <div class="it-cell"><span class="it-label">Impayés actifs</span>
            <span class="it-val imp"><?=$it_imp_a>0?'€'.number_format((float)$it_imp_a,0,',',' '):'—'?></span></div>
          <?php if ($it_imp_p > 0): ?>
          <div class="it-cell"><span class="it-label">Créances partis</span>
            <span class="it-val imp">€<?=number_format((float)$it_imp_p,0,',',' ')?></span></div>
          <?php endif; ?>
        </div>
      </div>

<?php endforeach; ?>

<?php if ($nb_vendus > 0): ?>
      <div class="fold-toggle vendus-toggle" onclick="toggleFold(this,'vendus-<?=$pid?>')">
        <span class="fold-arrow">▶</span>
        🏷️ <?=$nb_vendus?> immeuble(s) vendu(s)
      </div>
      <div class="fold-content" id="vendus-<?=$pid?>">
<?php foreach ($vendus_imm as $g):
    $iid   = (int)$g['id_immeuble'];
    $iname = e($g['nom_immeuble']); $iaddr = e($g['adresse_1']);
    $dvente = $g['date_vente'] ? ' · vendu '.date('d/m/Y',strtotime($g['date_vente'])) : '';
    $all_nb = count($g['actifs'])+count($g['partis'])+count($g['archives']);
?>
        <div class="imm-block vendu-block">
          <div class="imm-header vendu-header">
            <span class="imm-title vendu-title">
              🏢 <?=$iname?><?=$iaddr?"<span class='imm-addr'> — {$iaddr}</span>":""?>
              <span style="color:#9e9e9e;font-size:.76em"><?=$dvente?></span>
            </span>
            <button class="btn-vendu unmark"
                    onclick="event.stopPropagation();unmarquerVendu(<?=$iid?>,<?=$pid?>)">↩️ Restaurer</button>
          </div>
          <?php if ($all_nb > 0): ?>
          <?=$col_headers?>
          <?=renderLocRows($g['actifs'],'actif',$pid)?>
          <?=renderLocRows($g['partis'],'parti',$pid)?>
          <?=renderLocRows($g['archives'],'archive',$pid)?>
          </tbody></table>
          <?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>

    </div>
  </td>
</tr>
<?php endforeach; ?>
<tr class="total-row">
  <td colspan="4"><strong>TOTAL (<?=count($rows)?> propriétaires)</strong></td>
  <td class="num-r">€<?=number_format((float)$tot_loyer, 0,',',' ')?></td>
  <td class="num-r txt-b">€<?=number_format((float)$tot_enc_t1, 0,',',' ')?></td>
  <td class="num-r" style="color:#1565c0">€<?=number_format((float)$tot_enc_2025, 0,',',' ')?></td>
  <td class="num-r txt-g">€<?=number_format((float)$tot_actif, 0,',',' ')?></td>
  <td class="num-r txt-r">€<?=number_format((float)$tot_partis, 0,',',' ')?></td>
  <td colspan="2"></td>
</tr>
</tbody>
</table>

</div><!-- /main container -->

<!-- MODAL VENDU -->
<div class="modal-overlay" id="modal-vendu">
  <div class="modal-box">
    <h3>🏷️ Marquer l'immeuble comme vendu</h3>
    <p id="modal-imm-name" style="font-weight:bold;color:#1a237e;margin:0 0 12px"></p>
    <label>Date de vente (optionnelle) :
      <input type="date" id="modal-date-vente">
    </label>
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeVenduModal()">Annuler</button>
      <button class="btn-confirm" onclick="confirmerVendu()">Confirmer</button>
    </div>
  </div>
</div>

<!-- MODAL HISTORIQUE LOCATAIRE -->
<div class="hist-overlay" id="hist-overlay" onclick="if(event.target===this)closeLocHistory()">
  <div class="hist-box">
    <div class="hist-header">
      <div style="flex:1">
        <h3 id="hist-nom">—</h3>
        <div style="font-size:.8em;opacity:.8" id="hist-sub">—</div>
      </div>
      <button class="hist-close" onclick="closeLocHistory()">✕</button>
    </div>
    <div class="hist-tabs" id="hist-tabs"></div>
    <div class="hist-body" id="hist-body"><div class="hist-loading">⏳ Chargement…</div></div>
  </div>
</div>

<?php
// JS inline
$extraJs = <<<'JS'
<script>
const API_BASE = '/';

// ── ACCORDION PRINCIPAL ─────────────────────────────────
function toggleDetail(pid) {
    const d=document.getElementById('detail-'+pid), r=document.getElementById('row-'+pid);
    const isOpen=d.classList.contains('open');
    document.querySelectorAll('.detail-row.open').forEach(el=>el.classList.remove('open'));
    document.querySelectorAll('.prop-row.active').forEach(el=>el.classList.remove('active'));
    if(!isOpen){d.classList.add('open');r.classList.add('active');}
}

// ── SECTIONS PLIABLES ───────────────────────────────────
function toggleFold(toggle,contentId){
    const c=document.getElementById(contentId); if(!c)return;
    const open=c.classList.toggle('open');
    toggle.classList.toggle('open',open);
}

// ── TRI COLONNES ────────────────────────────────────────
function sortTable(th){
    const table=th.closest('table'),tbody=table.querySelector('tbody');
    const ths=Array.from(th.closest('tr').querySelectorAll('th[data-col]'));
    const col=parseInt(th.dataset.col),cur=th.dataset.dir||'asc',next=cur==='asc'?'desc':'asc';
    ths.forEach(h=>{h.dataset.dir='';h.querySelector('.sort-icon').textContent='⇅';});
    th.dataset.dir=next;th.querySelector('.sort-icon').textContent=next==='asc'?'▲':'▼';
    const allRows=Array.from(tbody.querySelectorAll('tr'));
    const dataRows=allRows.filter(r=>!r.classList.contains('tot-sub'));
    function getVal(row){
        const cell=row.querySelectorAll('td')[col];if(!cell)return'';
        const txt=cell.innerText.trim();
        if(col===1){const m=txt.match(/^(\S+)-(\d+)$/);if(m)return m[1]+'-'+m[2].padStart(8,'0');return txt.toLowerCase();}
        const clean=txt.replace(/[€\s ]/g,'').replace(',','.');
        const num=parseFloat(clean);return isNaN(num)?txt.toLowerCase():num;
    }
    function sortRows(rows){return rows.sort((a,b)=>{const va=getVal(a),vb=getVal(b);
        if(typeof va==='number'&&typeof vb==='number')return next==='asc'?va-vb:vb-va;
        return next==='asc'?String(va).localeCompare(String(vb),'fr'):String(vb).localeCompare(String(va),'fr');});}
    sortRows(dataRows).forEach(r=>tbody.appendChild(r));
    allRows.filter(r=>r.classList.contains('tot-sub')).forEach(r=>tbody.appendChild(r));
}

// ── ARCHIVER / RESTAURER LOCATAIRE ──────────────────────
function archiveLoc(idBien,nom,pid,archive){
    if(!confirm(archive?'Archiver ce locataire (dossier clos) ?':'Restaurer ce locataire ?'))return;
    const fd=new FormData();
    fd.append('id_bien',idBien);fd.append('locataire_nom',nom);
    fd.append('id_proprietaire',pid);fd.append('archive',archive);
    fetch('toggle_locataire_archive.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert('Erreur');});
}

// ── IMMEUBLE VENDU ──────────────────────────────────────
let _pendingIid=null;
function openVenduModal(iid,nom,pid){
    _pendingIid=iid;
    document.getElementById('modal-imm-name').textContent=nom;
    document.getElementById('modal-date-vente').value='';
    document.getElementById('modal-vendu').classList.add('open');
}
function closeVenduModal(){document.getElementById('modal-vendu').classList.remove('open');_pendingIid=null;}
function confirmerVendu(){
    if(!_pendingIid)return;
    const date=document.getElementById('modal-date-vente').value;
    const fd=new FormData();fd.append('id_immeuble',_pendingIid);fd.append('vendu',1);
    if(date)fd.append('date_vente',date);
    fetch('toggle_immeuble_vendu.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{if(d.ok){closeVenduModal();location.reload();}else alert('Erreur: '+(d.msg||'?'));});
}
function unmarquerVendu(iid){
    if(!confirm('Restaurer cet immeuble ?'))return;
    const fd=new FormData();fd.append('id_immeuble',iid);fd.append('vendu',0);
    fetch('toggle_immeuble_vendu.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert('Erreur');});
}
document.getElementById('modal-vendu').addEventListener('click',function(e){if(e.target===this)closeVenduModal();});

// ── HISTORIQUE LOCATAIRE ────────────────────────────────
function openLocHistory(idBien,nom,pid){
    const overlay=document.getElementById('hist-overlay');
    document.getElementById('hist-nom').textContent=nom;
    document.getElementById('hist-sub').textContent='Chargement…';
    document.getElementById('hist-tabs').innerHTML='';
    document.getElementById('hist-body').innerHTML='<div class="hist-loading">⏳ Chargement…</div>';
    overlay.classList.add('open');
    fetch('get_locataire_history.php?id_bien='+idBien+'&locataire_nom='+encodeURIComponent(nom)+'&id_proprietaire='+pid)
        .then(r=>r.json()).then(data=>{
        if(!data.ok||!data.trimestres.length){
            document.getElementById('hist-body').innerHTML='<div class="hist-loading">Aucun historique trouvé.</div>';return;
        }
        const trims=data.trimestres,last=trims[trims.length-1];
        document.getElementById('hist-sub').textContent=(last.nom_immeuble||'')+(last.adresse?' — '+last.adresse:'')+'  · '+last.reference_bien+'  · '+last.proprietaire;
        const tabsEl=document.getElementById('hist-tabs'),bodyEl=document.getElementById('hist-body');
        tabsEl.innerHTML='';bodyEl.innerHTML='';
        const panels=[];
        const tabSynth=document.createElement('button');
        tabSynth.className='hist-tab active';tabSynth.textContent='📊 Synthèse';
        tabSynth.onclick=()=>switchTab(0);tabsEl.appendChild(tabSynth);
        const synthPanel=document.createElement('div');
        synthPanel.className='hist-panel active';synthPanel.innerHTML=buildSynthesePanel(trims);
        bodyEl.appendChild(synthPanel);panels.push(synthPanel);
        [...trims].reverse().forEach((t,idx)=>{
            const isLatest=idx===0;
            const tab=document.createElement('button');
            tab.className='hist-tab'+(isLatest?' latest':'');
            tab.textContent=t.label+(isLatest?' ★':'');tab.onclick=()=>switchTab(idx+1);
            tabsEl.appendChild(tab);
            const panel=document.createElement('div');
            panel.className='hist-panel';panel.innerHTML=buildTrimPanel(t,trims);
            bodyEl.appendChild(panel);panels.push(panel);
        });
        function switchTab(n){tabsEl.querySelectorAll('.hist-tab').forEach((t,i)=>t.classList.toggle('active',i===n));panels.forEach((p,i)=>p.classList.toggle('active',i===n));}
    });
}
function fmtE(v){if(!v||v==0)return'—';return'€'+parseFloat(v).toLocaleString('fr-FR',{maximumFractionDigits:0});}
function deltaHtml(d){if(d==null)return'';if(d===0)return'<span class="crg-delta" style="color:#888">= identique</span>';const sign=d>0?'▲ +':'▼ ';const cls=d>0?'delta-up':'delta-down';return`<span class="crg-delta ${cls}">${sign}€${Math.abs(d).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`;}
function buildTrimPanel(t,allTrims){
    const actif=t.loyer>0;
    const statut=actif?(t.impaye>0?'🟠 Actif — impayé':'🟢 Actif — régulier'):(t.impaye>0?'🔴 Parti — créance':'⚪ Parti — soldé');
    let pdfHtml='';
    if(t.pdf_url){
        const page=t.page_pdf||1;
        pdfHtml=`<div class="pdf-viewer-wrap"><div class="pdf-viewer-bar">📄 CRG ${t.label} — page ${page}<a href="${t.pdf_url}" target="_blank">🔗 Nouvel onglet</a></div><iframe class="pdf-iframe" src="${t.pdf_url}#page=${page}"></iframe></div>`;
    } else {
        pdfHtml='<div class="pdf-missing">📄 PDF non disponible pour ce trimestre</div>';
    }
    return`<div class="crg-grid"><div class="crg-card"><div class="cc-label">Loyer appelé</div><div class="cc-val">${fmtE(t.loyer)}</div><div class="crg-delta">${actif?'✓ Présent':'— Parti'}</div></div><div class="crg-card enc"><div class="cc-label">Total encaissé</div><div class="cc-val">${fmtE(t.regle)}</div>${deltaHtml(t.delta_regle)}</div><div class="crg-card ${t.impaye===0?'ok':'imp'}"><div class="cc-label">Solde impayé</div><div class="cc-val">${fmtE(t.impaye)}</div>${deltaHtml(t.delta_impaye)}</div></div><p style="font-size:.82em;color:#555;margin:0 0 10px">Statut : <strong>${statut}</strong></p>${pdfHtml}<div class="hist-timeline" style="margin-top:12px"><h4>Tous les trimestres</h4>${buildTimeline(allTrims,t.label)}</div>`;
}
function buildSynthesePanel(trims){
    const last=trims[trims.length-1],first=trims[0];
    const totalEnc=trims.reduce((s,t)=>s+t.regle,0);
    const trend=last.impaye-first.impaye;
    const evHtml=trims.length>1?(trend>0?`<span style="color:#c62828">▲ En hausse de €${Math.abs(trend).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:trend<0?`<span style="color:#2e7d32">▼ En baisse de €${Math.abs(trend).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:'<span style="color:#888">= Stable</span>'):'';
    return`<div class="crg-grid"><div class="crg-card"><div class="cc-label">Loyer actuel (${last.label})</div><div class="cc-val">${fmtE(last.loyer)}</div><div class="crg-delta">${last.loyer>0?'✓ Présent':'— Parti'}</div></div><div class="crg-card enc"><div class="cc-label">Cumulé encaissé</div><div class="cc-val">${fmtE(totalEnc)}</div><div class="crg-delta">${trims.length} trimestre(s)</div></div><div class="crg-card ${last.impaye===0?'ok':'imp'}"><div class="cc-label">Solde impayé actuel</div><div class="cc-val">${fmtE(last.impaye)}</div><div class="crg-delta">${evHtml}</div></div></div><div class="hist-timeline"><h4>Historique complet</h4>${buildTimeline(trims,last.label)}</div>`;
}
function buildTimeline(trims,activeLabel){
    const rows=[...trims].reverse().map(t=>{
        const isActive=t.label===activeLabel;
        const delta=t.delta_impaye!=null?(t.delta_impaye>0?`<span style="color:#c62828;font-size:.83em">▲+€${Math.abs(t.delta_impaye).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:t.delta_impaye<0?`<span style="color:#2e7d32;font-size:.83em">▼-€${Math.abs(t.delta_impaye).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:'<span style="color:#888;font-size:.83em">=</span>'):'';
        const pr=t.loyer>0?'🟢':'🔴';
        return`<tr class="${isActive?'active-row':''}"><td>${pr} <strong>${t.label}</strong></td><td style="text-align:right">${fmtE(t.loyer)||'—'}</td><td style="text-align:right;color:#2e7d32">${fmtE(t.regle)||'—'}</td><td style="text-align:right;color:${t.impaye>0?'#c62828':'#2e7d32'}">${fmtE(t.impaye)||'—'}</td><td>${delta}</td></tr>`;
    }).join('');
    return`<table class="timeline"><thead><tr><th>Trimestre</th><th style="text-align:right">Loyer</th><th style="text-align:right">Encaissé</th><th style="text-align:right">Impayé</th><th>Évolution</th></tr></thead><tbody>${rows}</tbody></table>`;
}
function closeLocHistory(){document.getElementById('hist-overlay').classList.remove('open');}
</script>
JS;

require_once __DIR__ . '/inc/agency_layout_bottom.php';
