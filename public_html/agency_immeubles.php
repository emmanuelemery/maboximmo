<?php
/*
 * agency_immeubles.php — Liste des immeubles — Module Syndic (layout_maboximmo)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = current_role_id();   // 1=admin, 2=manager, 3=user
$pdo    = $GLOBALS['pdo'];

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Filtres GET ──────────────────────────────────────────────
$fRef     = trim($_GET['reference']    ?? '');
$fNom     = trim($_GET['nom']          ?? '');
$fVille   = trim($_GET['ville']        ?? '');
$fType    = $_GET['type']              ?? '';
$fEtab    = (int)($_GET['etablissement'] ?? 0);
$fGest    = (int)($_GET['gestionnaire']  ?? 0);
$fVue     = $_GET['vue']               ?? 'cards';   // cards | table
$sort     = $_GET['sort']              ?? 'i.reference_immeuble';
$order    = strtoupper($_GET['order']  ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

// Colonnes triables autorisées
$sortAllowed = ['i.reference_immeuble','i.nom_immeuble','i.ville','i.nb_lots','infos.honoraires_ht'];
if (!in_array($sort, $sortAllowed, true)) $sort = 'i.reference_immeuble';

// ── Construction requête ─────────────────────────────────────
$conds  = [];
$params = [];

if ($fRef !== '') { $conds[] = 'i.reference_immeuble LIKE ?'; $params[] = "%$fRef%"; }
if ($fNom !== '') { $conds[] = 'i.nom_immeuble LIKE ?';       $params[] = "%$fNom%"; }
if ($fVille !== '') { $conds[] = 'i.ville LIKE ?';   $params[] = "%$fVille%"; }
if ($fType !== '') { $conds[] = 'i.type_immeuble = ?';        $params[] = $fType; }
if ($fEtab > 0)   { $conds[] = 'i.id_agence = ?'; $params[] = $fEtab; }
if ($fGest > 0)   { $conds[] = 'i.id_societe = ?'; $params[] = $fGest; }

// Non-admin : restreindre à sa société
if (!in_array($roleId, [1, 7], true)) {
    $mySoc = (int)($_SESSION['id_societe'] ?? 0);
    if ($mySoc > 0) { $conds[] = 'i.id_societe = ?'; $params[] = $mySoc; }
}

$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';

$sql = "
    SELECT i.*,
           e.nom  AS nom_etablissement,
           CONCAT(u.prenom, ' ', u.nom) AS nom_gestionnaire,
           infos.honoraires_ht, infos.date_ag_prochaine,
           infos.hono_2026, infos.hono_2027
    FROM immeubles i
    LEFT JOIN etablissements e    ON i.id_agence = e.id
    LEFT JOIN users u             ON i.id_societe = u.id
    LEFT JOIN immeubles_infos infos ON infos.id_immeuble = i.id
    $where
    ORDER BY $sort $order
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$immeubles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs ─────────────────────────────────────────────────────
$totalImm  = count($immeubles);
$totalLots = array_sum(array_column($immeubles, 'nb_lots'));
$totalHono = array_sum(array_map(fn($r) => (float)($r['honoraires_ht'] ?? 0), $immeubles));
$nbAGMonth = count(array_filter($immeubles, fn($r) =>
    !empty($r['date_ag_prochaine']) &&
    substr($r['date_ag_prochaine'], 0, 7) === date('Y-m')
));

// ── Référentiels filtres ─────────────────────────────────────
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$gestionnaires  = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) AS nom_complet FROM users WHERE actif=1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);

// ── Helper : sort link ────────────────────────────────────────
function sortLink(string $field, string $label, string $cur, string $ord): string {
    $params = $_GET; $params['sort'] = $field;
    $params['order'] = ($cur === $field && $ord === 'ASC') ? 'DESC' : 'ASC';
    $arrow = ($cur === $field) ? ($ord === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . http_build_query($params) . '" style="color:inherit;text-decoration:none">'
         . htmlspecialchars($label) . $arrow . '</a>';
}

// ── Helper : type badge ───────────────────────────────────────
function typeBadge(?string $type): string {
    $map = [
        'sdc'        => ['sdc',    'SDC'],
        'Appartement'=> ['sdc',    'Appt'],
        'Maison individuelle' => ['maison','Maison'],
        'Maison  - Jumelée'  => ['maison','Jumelée'],
        'Local commercial'   => ['local', 'Local'],
        'Garage'     => ['garage', 'Garage'],
        'autre'      => ['autre',  'Autre'],
    ];
    $t = $type ?? '';
    foreach ($map as $key => [$cls, $lbl]) {
        if (stripos($t, $key) !== false) return "<span class=\"imm-type-badge $cls\">$lbl</span>";
    }
    return '<span class="imm-type-badge autre">' . htmlspecialchars($t ?: '—') . '</span>';
}

// ── Date AG proche ? ──────────────────────────────────────────
function agStatus(?string $dateAG): string {
    if (!$dateAG) return '';
    $ts  = strtotime($dateAG);
    $now = time();
    $diff = ($ts - $now) / 86400;
    if ($diff < 0)  return '<span style="color:#a8a49e;font-size:10px">AG passée</span>';
    if ($diff < 30) return '<span style="color:#7a6830;font-size:10px;font-weight:600">⚡ AG dans ' . (int)$diff . 'j</span>';
    if ($diff < 90) return '<span style="color:#4878a6;font-size:10px">AG dans ' . (int)$diff . 'j</span>';
    return '<span style="color:#a8a49e;font-size:10px">' . date('d/m/Y', $ts) . '</span>';
}

// ── Layout ──────────────────────────────────────────────────
$layout_title    = 'Immeubles';
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)$totalImm.'</div><div class="ph-kpi-lbl">Immeubles</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.number_format((int)$totalLots,0,',',' ').'</div><div class="ph-kpi-lbl">Lots</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.number_format($totalHono,0,',',' ').' €</div><div class="ph-kpi-lbl">Hono. HT</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.(int)$nbAGMonth.'</div><div class="ph-kpi-lbl">AG ce mois</div></div>
';

$layout_head_actions = '
    <div style="position:relative;display:inline-block;" id="add-imm-wrap">
        <button type="button" class="ph-btn primary" onclick="var m=document.getElementById(\'add-imm-menu\');m.style.display=m.style.display===\'block\'?\'none\':\'block\';">
            <svg viewBox="0 0 24 24" style="width:14px;height:14px"><line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2"/><line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2"/></svg>
            Ajouter un immeuble
        </button>
        <div id="add-imm-menu" style="display:none;position:absolute;top:100%;left:0;margin-top:6px;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,0.15);padding:8px 0;min-width:220px;z-index:100;">
            <a href="agency_immeuble_form.php" style="display:block;padding:10px 18px;text-decoration:none;color:#333;font-size:13px;font-weight:500;" onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'\'">🏢 Syndic — Saisie manuelle</a>
            <a href="agency_immeubles.php?modal_crg=1" style="display:block;padding:10px 18px;text-decoration:none;color:#333;font-size:13px;font-weight:500;" onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'\'">📊 Gestion — Importer un CRG</a>
        </div>
    </div>
    <a href="agency_immeubles.php" class="ph-btn">Reset</a>
    <a href="agency_dashboard.php" class="ph-btn">Dashboard</a>
';

// Filtres dans page-head (2 lignes via grille)
$layout_head_filters = '
<style>
.mbi-page-head form#filter-form .ph-filter label { font-size: 7px !important; }
.mbi-page-head form#filter-form .ph-filter input,
.mbi-page-head form#filter-form .ph-filter select {
    height: 24px !important; padding: 0 6px !important; min-width: 90px !important;
    font-size: 10px !important;
}
</style>
<form method="GET" id="filter-form" style="display:grid;grid-template-columns:repeat(3,auto);gap:3px 8px">
    <input type="hidden" name="vue" value="'.h($fVue).'">
    <div class="ph-filter">
        <label>Référence</label>
        <input type="text" name="reference" value="'.h($fRef).'" placeholder="ex. 1070" data-debounce-submit>
    </div>
    <div class="ph-filter">
        <label>Nom</label>
        <input type="text" name="nom" value="'.h($fNom).'" placeholder="Nom immeuble" data-debounce-submit>
    </div>
    <div class="ph-filter">
        <label>Ville</label>
        <input type="text" name="ville" value="'.h($fVille).'" data-debounce-submit>
    </div>
    <div class="ph-filter">
        <label>Type</label>
        <select name="type" onchange="this.form.submit()">
            <option value="">— Tous —</option>';
foreach (['sdc','Appartement','Maison individuelle','Maison  - Jumelée','Local commercial','Garage'] as $t) {
    $sel = $fType === $t ? ' selected' : '';
    $layout_head_filters .= '<option value="'.h($t).'"'.$sel.'>'.h($t).'</option>';
}
$layout_head_filters .= '
        </select>
    </div>';
if ($roleId === 1) {
    $layout_head_filters .= '
    <div class="ph-filter">
        <label>Établissement</label>
        <select name="etablissement" onchange="this.form.submit()">
            <option value="0">— Tous —</option>';
    foreach ($etablissements as $e) {
        $sel = $fEtab === (int)$e['id'] ? ' selected' : '';
        $layout_head_filters .= '<option value="'.$e['id'].'"'.$sel.'>'.h($e['nom']).'</option>';
    }
    $layout_head_filters .= '
        </select>
    </div>
    <div class="ph-filter">
        <label>Gestionnaire</label>
        <select name="gestionnaire" onchange="this.form.submit()">
            <option value="0">— Tous —</option>';
    foreach ($gestionnaires as $g) {
        $sel = $fGest === (int)$g['id'] ? ' selected' : '';
        $layout_head_filters .= '<option value="'.$g['id'].'"'.$sel.'>'.h($g['nom_complet']).'</option>';
    }
    $layout_head_filters .= '
        </select>
    </div>';
}
$layout_head_filters .= '
</form>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
/* Filtres */
.filter-bar { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; background: var(--bg-primary,var(--bg-primary,#e4e8f0)); border-radius: 14px; box-shadow: 5px 5px 12px var(--shadow-dark,#d4d7de), -5px -5px 12px #ffffff; padding: 14px 18px; }
.tf { display: flex; flex-direction: column; gap: 4px; }
.tf label { font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: #a8a49e; }
.tf input, .tf select { height: 32px; padding: 0 10px; background: var(--bg-primary,var(--bg-primary,#e4e8f0)); box-shadow: inset 3px 3px 6px var(--shadow-dark,#d4d7de), inset -3px -3px 8px #ffffff; border: none; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 11px; color: #1a1816; outline: none; min-width: 90px; }
.tf input:focus, .tf select:focus { box-shadow: inset 3px 3px 6px var(--shadow-dark,#d4d7de), inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(72,120,166,0.3); }
.filter-actions { display: flex; gap: 6px; align-items: flex-end; padding-bottom: 0; }
.v2-btn { padding: 0 16px; height: 34px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.04em; background: var(--bg-primary,var(--bg-primary,#e4e8f0)); box-shadow: 4px 4px 10px var(--shadow-dark,#d4d7de), -4px -4px 10px #ffffff; font-weight: 600; color: #3a3830; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: box-shadow 0.15s; }
.v2-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark,#d4d7de), inset -3px -3px 8px #ffffff; }
.v2-btn.primary { background: #4878a6; color: #fff; }

/* Grille cards */
.imm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }

/* Card immeuble */
.imm-card { background: var(--bg-primary,var(--bg-primary,#e4e8f0)); border-radius: 16px; box-shadow: 6px 6px 14px var(--shadow-dark,#d4d7de), -6px -6px 14px #ffffff; padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; transition: box-shadow 0.15s, transform 0.12s; }
.imm-card:hover { box-shadow: 8px 8px 18px #c8cbd2, -8px -8px 18px #ffffff; transform: translateY(-2px); }
.imm-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.imm-card-ref { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.12em; color: #4878a6; text-transform: uppercase; }
.imm-card-nom { font-size: 13px; font-weight: 700; color: #1a1816; line-height: 1.3; margin-top: 2px; }
.imm-card-adresse { font-size: 11px; color: #8a8680; margin-top: 2px; display: flex; align-items: center; gap: 4px; }
.imm-card-body { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.meta-chip { display: flex; align-items: center; gap: 4px; font-family: 'DM Mono', monospace; font-size: 10px; color: #6a6660; }
.meta-chip strong { color: #1a1816; font-size: 12px; }
.imm-card-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid rgba(196,192,186,0.4); }
.imm-card-actions { display: flex; gap: 6px; }

/* Btn icon */
.btn-icon { width: 30px; height: 30px; border-radius: 10px; background: var(--bg-primary,var(--bg-primary,#e4e8f0)); box-shadow: 3px 3px 7px var(--shadow-dark,#d4d7de), -3px -3px 8px #ffffff; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; text-decoration: none; color: #3a3830; transition: box-shadow 0.12s; flex-shrink: 0; }
.btn-icon:hover { box-shadow: 4px 4px 10px var(--shadow-dark,#d4d7de), -4px -4px 10px #ffffff; }
.btn-icon:active { box-shadow: inset 2px 2px 5px var(--shadow-dark,#d4d7de), inset -2px -2px 5px #ffffff; }
.btn-icon.primary { background: #4878a6; color: #fff; }
.btn-icon.edit    { background: var(--bg-primary,var(--bg-primary,#e4e8f0)); }

/* Table view */
.tbl-wrap { background: var(--bg-primary,var(--bg-primary,#e4e8f0)); border-radius: 14px; box-shadow: 5px 5px 12px var(--shadow-dark,#d4d7de), -5px -5px 12px #ffffff; overflow: hidden; }
.imm-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.imm-table th { padding: 10px 12px; text-align: left; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #4a6038; border-bottom: 2px solid rgba(196,192,186,0.5); background: var(--bg-secondary,var(--bg-secondary,#eef1f6)); white-space: nowrap; }
.imm-table td { padding: 10px 12px; border-bottom: 1px solid rgba(196,192,186,0.3); vertical-align: middle; }
.imm-table tr:last-child td { border-bottom: none; }
.imm-table tr:hover td { background: rgba(72,120,166,0.04); }
.td-ref { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600; color: #4878a6; letter-spacing: 0.08em; }
.td-nom { font-weight: 600; color: #1a1816; }
.td-num { text-align: right; font-family: 'DM Mono', monospace; font-size: 11px; }
.td-actions { white-space: nowrap; }
.td-actions a, .td-actions button { margin-right: 4px; }

/* Badge type */
.imm-type-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; white-space: nowrap; }
.imm-type-badge.sdc    { background: #dce8f2; color: #25486a; }
.imm-type-badge.maison { background: #e8f4e8; color: #2a5a2a; }
.imm-type-badge.local  { background: #fef5db; color: #7a6830; }
.imm-type-badge.garage { background: #f0f0f0; color: #4a4a4a; }
.imm-type-badge.autre  { background: #f5eeff; color: #5a3a7a; }

/* Empty */
.empty-state { text-align: center; padding: 60px 20px; color: #8a8680; }
.empty-state-icon { font-size: 40px; margin-bottom: 14px; opacity: 0.5; }
.empty-state-txt { font-size: 14px; }
</style>
EXTRACSS;

// ── Contenu ──────────────────────────────────────────────────
ob_start();
?>

<?php
$pVueCards = $_GET; $pVueCards['vue'] = 'cards';
$pVueTable = $_GET; $pVueTable['vue'] = 'table';
?>
<!-- SEC HEAD avec toggle vue à gauche -->
<div class="section-header">
    <div class="view-toggle">
        <a href="?<?= h(http_build_query($pVueTable)) ?>" class="view-btn <?= $fVue === 'table' ? 'active' : '' ?>" title="Liste">
            <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </a>
        <a href="?<?= h(http_build_query($pVueCards)) ?>" class="view-btn <?= $fVue === 'cards' ? 'active' : '' ?>" title="Mini-cards">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        </a>
    </div>
    <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt"><?= $totalImm ?> immeuble<?= $totalImm > 1 ? 's' : '' ?></span>
        <div class="line-r"></div>
    </div>
</div>

<?php if (empty($immeubles)): ?>
<div class="empty-state">
    <div class="empty-state-icon">🏢</div>
    <div class="empty-state-txt">Aucun immeuble trouvé avec ces critères.</div>
    <a href="agency_immeubles.php" class="v2-btn" style="margin-top:14px;display:inline-flex">Réinitialiser les filtres</a>
</div>

<?php elseif ($fVue === 'cards'): ?>
<!-- ═══ VUE CARDS ═══ -->
<div class="imm-grid">
    <?php foreach ($immeubles as $imm):
        $ficheParams = $_GET;
        $ficheParams['id'] = $imm['id'];
        unset($ficheParams['vue']);
        $ficheUrl = 'agency_immeuble_fiche.php?' . http_build_query($ficheParams);
    ?>
    <div class="imm-card" onclick="location.href='<?= h($ficheUrl) ?>'" style="cursor:pointer">
        <div class="imm-card-head">
            <div>
                <div class="imm-card-ref"><?= h($imm['reference_immeuble']) ?></div>
                <div class="imm-card-nom"><?= h($imm['nom_immeuble']) ?></div>
                <div class="imm-card-adresse">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= h($imm['ville']) ?>
                </div>
            </div>
            <?= typeBadge($imm['type_immeuble']) ?>
        </div>

        <div class="imm-card-body">
            <div class="meta-chip">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#7a9ab8" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M2 9h20M8 3v18"/></svg>
                <strong><?= (int)$imm['nb_lots'] ?></strong>&nbsp;lots
            </div>
            <?php if ($imm['honoraires_ht']): ?>
            <div class="meta-chip">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#7a9ab8" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                <strong><?= number_format((float)$imm['honoraires_ht'], 0, ',', ' ') ?> €</strong>
            </div>
            <?php endif; ?>
            <?php if ($imm['nom_gestionnaire']): ?>
            <div class="meta-chip" style="color:#6a6660">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= h($imm['nom_gestionnaire']) ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="imm-card-footer">
            <div><?= agStatus($imm['date_ag_prochaine']) ?></div>
            <div class="imm-card-actions" onclick="event.stopPropagation()">
                <a href="<?= h($ficheUrl) ?>" class="btn-icon primary" title="Fiche">📄</a>
                <?php if ($roleId <= 2): ?>
                <a href="agency_immeuble_form.php?id=<?= (int)$imm['id'] ?>" class="btn-icon edit" title="Modifier">✏️</a>
                <?php endif; ?>
                <a href="agency_reunion_tenir.php?id_immeuble=<?= (int)$imm['id'] ?>&annee=<?= date('Y') ?>" class="btn-icon" title="Retour AG">📋</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ═══ VUE TABLEAU ═══ -->
<div class="tbl-wrap">
    <table class="imm-table">
        <thead>
            <tr>
                <th><?= sortLink('i.reference_immeuble', 'Réf.', $sort, $order) ?></th>
                <th><?= sortLink('i.nom_immeuble', 'Nom', $sort, $order) ?></th>
                <th><?= sortLink('i.ville', 'Ville', $sort, $order) ?></th>
                <th>Type</th>
                <th><?= sortLink('i.nb_lots', 'Lots', $sort, $order) ?></th>
                <th><?= sortLink('infos.honoraires_ht', 'Hono. HT', $sort, $order) ?></th>
                <th>Établ.</th>
                <th>Gestionnaire</th>
                <th>Prochaine AG</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($immeubles as $imm):
            $ficheParams = $_GET;
            $ficheParams['id'] = $imm['id'];
            unset($ficheParams['vue']);
            $ficheUrl = 'agency_immeuble_fiche.php?' . http_build_query($ficheParams);
        ?>
            <tr>
                <td class="td-ref"><?= h($imm['reference_immeuble']) ?></td>
                <td class="td-nom">
                    <a href="<?= h($ficheUrl) ?>" style="color:inherit;text-decoration:none"><?= h($imm['nom_immeuble']) ?></a>
                </td>
                <td><?= h($imm['ville']) ?></td>
                <td><?= typeBadge($imm['type_immeuble']) ?></td>
                <td class="td-num"><?= (int)$imm['nb_lots'] ?></td>
                <td class="td-num"><?= $imm['honoraires_ht'] ? number_format((float)$imm['honoraires_ht'], 0, ',', ' ') . ' €' : '—' ?></td>
                <td style="font-size:11px;color:#6a6660"><?= h($imm['nom_etablissement'] ?? '—') ?></td>
                <td style="font-size:11px;color:#6a6660"><?= h($imm['nom_gestionnaire'] ?? '—') ?></td>
                <td><?= agStatus($imm['date_ag_prochaine']) ?></td>
                <td class="td-actions">
                    <a href="<?= h($ficheUrl) ?>" class="btn-icon primary" title="Fiche" style="display:inline-flex">📄</a>
                    <?php if ($roleId <= 2): ?>
                    <a href="agency_immeuble_form.php?id=<?= (int)$imm['id'] ?>" class="btn-icon" title="Modifier" style="display:inline-flex">✏️</a>
                    <?php endif; ?>
                    <a href="agency_reunion_tenir.php?id_immeuble=<?= (int)$imm['id'] ?>&annee=<?= date('Y') ?>" class="btn-icon" title="Retour AG" style="display:inline-flex">📋</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($_GET['modal_crg'])): ?>
<!-- ══ MODALE IMPORT CRG ══ -->
<div id="crg-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;">
  <div style="background:#fff;border-radius:16px;padding:32px;max-width:700px;width:100%;box-shadow:0 8px 30px rgba(0,0,0,0.2);">

    <!-- STEP 1 : Upload -->
    <div id="crg-step1">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h2 style="font-size:18px;font-weight:700;margin:0;">📊 Importer un CRG</h2>
        <a href="agency_immeubles.php" style="font-size:24px;text-decoration:none;color:#999;line-height:1;">&times;</a>
      </div>
      <p style="font-size:13px;color:#666;margin-bottom:20px;">
        Uploadez un Compte-Rendu de Gestion (PDF). L'IA extraira automatiquement propriétaire, immeubles, lots, locataires et écritures.
      </p>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Propriétaire existant (optionnel)</label>
        <select id="crg-proprio" style="width:100%;padding:8px 12px;border:1px solid #d4d7de;border-radius:8px;font-size:13px;">
          <option value="0">— Nouveau (sera créé à partir du CRG) —</option>
          <?php
          $stmtProp = $pdo->prepare("SELECT id, CONCAT(nom, ' ', COALESCE(prenom,'')) AS label FROM proprietaires WHERE actif=1 ORDER BY nom");
          $stmtProp->execute();
          foreach ($stmtProp as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= h($p['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Fichier CRG (PDF)</label>
        <input type="file" id="crg-file" accept="application/pdf" style="font-size:13px;">
      </div>
      <div id="crg-error" style="display:none;padding:10px;background:#fef2f2;color:#991b1b;border-radius:8px;font-size:13px;margin-bottom:14px;"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <a href="agency_immeubles.php" style="padding:10px 20px;border:1px solid #d4d7de;border-radius:8px;text-decoration:none;color:#666;font-size:13px;font-weight:600;">Annuler</a>
        <button type="button" id="crg-analyze-btn" onclick="crgAnalyze()" style="padding:10px 20px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">🔍 Analyser le CRG</button>
      </div>
    </div>

    <!-- STEP 2 : Preview & Confirm -->
    <div id="crg-step2" style="display:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="font-size:18px;font-weight:700;margin:0;">✅ Données extraites — Confirmation</h2>
        <a href="agency_immeubles.php" style="font-size:24px;text-decoration:none;color:#999;line-height:1;">&times;</a>
      </div>
      <div id="crg-preview" style="max-height:500px;overflow-y:auto;margin-bottom:16px;"></div>
      <div id="crg-error2" style="display:none;padding:10px;background:#fef2f2;color:#991b1b;border-radius:8px;font-size:13px;margin-bottom:14px;"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('crg-step1').style.display='';document.getElementById('crg-step2').style.display='none';" style="padding:10px 20px;border:1px solid #d4d7de;border-radius:8px;color:#666;font-size:13px;font-weight:600;cursor:pointer;background:#fff;">← Retour</button>
        <button type="button" id="crg-confirm-btn" onclick="crgConfirm()" style="padding:10px 20px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">✅ Confirmer l'import</button>
      </div>
    </div>

    <!-- STEP 3 : Success -->
    <div id="crg-step3" style="display:none;text-align:center;padding:30px 0;">
      <div style="font-size:48px;margin-bottom:16px;">🎉</div>
      <h2 style="font-size:18px;font-weight:700;margin-bottom:8px;">Import terminé !</h2>
      <div id="crg-result" style="font-size:14px;color:#666;margin-bottom:20px;"></div>
      <a href="agency_immeubles.php" style="padding:10px 28px;background:#4a6038;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;">Voir les immeubles</a>
    </div>

  </div>
</div>

<script>
var crgCsrf = document.querySelector('input[name="csrf_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';

async function crgAnalyze() {
    var file = document.getElementById('crg-file').files[0];
    if (!file) { crgErr('crg-error', 'Sélectionnez un fichier PDF.'); return; }
    var btn = document.getElementById('crg-analyze-btn');
    btn.disabled = true; btn.textContent = '⏳ Analyse IA en cours (30-60s)…';
    document.getElementById('crg-error').style.display = 'none';

    var fd = new FormData();
    fd.append('action', 'parse');
    fd.append('fichier_crg', file);
    fd.append('csrf_token', crgCsrf);

    try {
        var r = await fetch('api/import_crg.php', { method: 'POST', headers: {'X-CSRF-Token': crgCsrf}, body: fd });
        var d = await r.json();
        if (!d.ok) { crgErr('crg-error', (d.error || 'Erreur analyse.') + (d.raw ? '\n\nRéponse IA brute:\n' + d.raw : '')); btn.disabled = false; btn.textContent = '🔍 Analyser le CRG'; return; }
        crgShowPreview(d.data);
        document.getElementById('crg-step1').style.display = 'none';
        document.getElementById('crg-step2').style.display = '';
    } catch(e) {
        crgErr('crg-error', 'Erreur réseau : ' + e.message);
    }
    btn.disabled = false; btn.textContent = '🔍 Analyser le CRG';
}

function crgShowPreview(data) {
    var h = '';
    // Propriétaire
    var p = data.proprietaire || {};
    h += '<div style="background:#f0fdf4;padding:12px;border-radius:8px;margin-bottom:12px;">';
    h += '<strong>👤 Propriétaire :</strong> ' + esc(p.nom || '(non détecté)');
    if (p.adresse) h += ' — ' + esc(p.adresse);
    h += '</div>';
    // Période
    var per = data.periode || {};
    h += '<div style="background:#eff6ff;padding:12px;border-radius:8px;margin-bottom:12px;">';
    h += '<strong>📅 Période :</strong> ' + (per.annee||'?') + ' T' + (per.trimestre||'?');
    if (per.date_arrete) h += ' — Arrêté au ' + esc(per.date_arrete);
    h += '</div>';
    // Soldes
    h += '<div style="background:#fefce8;padding:12px;border-radius:8px;margin-bottom:12px;">';
    h += '<strong>💶 Soldes :</strong> Report: ' + fmt(data.solde_report) + ' | Débits: ' + fmt(data.total_debits) + ' | Crédits: ' + fmt(data.total_credits);
    h += '</div>';
    // Immeubles
    (data.immeubles || []).forEach(function(imm) {
        h += '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px;">';
        h += '<strong>🏢 ' + esc(imm.nom || imm.code) + '</strong>';
        if (imm.adresse) h += '<br><span style="color:#888;font-size:12px;">' + esc(imm.adresse) + '</span>';
        h += '<div style="margin-top:8px;font-size:12px;">';
        (imm.lots || []).forEach(function(lot) {
            var color = lot.statut === 'occupe' ? '#16a34a' : (lot.statut === 'vacant' ? '#dc2626' : '#d97706');
            h += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f3f4f6;">';
            h += '<span>Lot ' + esc(lot.numero_lot) + ' — ' + esc(lot.locataire_nom || 'Vacant') + '</span>';
            h += '<span style="color:' + color + ';font-weight:600;">' + esc(lot.statut) + (lot.total_impaye > 0 ? ' ⚠ ' + fmt(lot.total_impaye) + ' impayé' : '') + '</span>';
            h += '</div>';
        });
        h += '</div></div>';
    });
    document.getElementById('crg-preview').innerHTML = h;
}

async function crgConfirm() {
    var btn = document.getElementById('crg-confirm-btn');
    btn.disabled = true; btn.textContent = '⏳ Import en cours…';
    document.getElementById('crg-error2').style.display = 'none';
    var proprioId = document.getElementById('crg-proprio').value;

    var fd = new FormData();
    fd.append('action', 'confirm');
    fd.append('id_proprietaire', proprioId);
    fd.append('csrf_token', crgCsrf);

    try {
        var r = await fetch('api/import_crg.php', { method: 'POST', headers: {'X-CSRF-Token': crgCsrf}, body: fd });
        var d = await r.json();
        if (!d.ok) { crgErr('crg-error2', d.error || 'Erreur import.'); btn.disabled = false; btn.textContent = '✅ Confirmer l\'import'; return; }
        document.getElementById('crg-step2').style.display = 'none';
        document.getElementById('crg-step3').style.display = '';
        document.getElementById('crg-result').textContent = d.message;
    } catch(e) {
        crgErr('crg-error2', 'Erreur réseau : ' + e.message);
        btn.disabled = false; btn.textContent = '✅ Confirmer l\'import';
    }
}

function crgErr(id, msg) { var el = document.getElementById(id); el.textContent = msg; el.style.display = ''; }
function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function fmt(n) { return (parseFloat(n) || 0).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €'; }
</script>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
$layout_extra_js = '<script>
document.addEventListener("click",function(e){var w=document.getElementById("add-imm-wrap");var m=document.getElementById("add-imm-menu");if(w&&m&&!w.contains(e.target))m.style.display="none";});
// Debounce : soumet le form de filtres seulement quand l utilisateur arrete de taper
// (evite le submit a chaque caractere qui interrompait la saisie).
(function(){
  var inputs = document.querySelectorAll("#filter-form [data-debounce-submit]");
  var timer = null;
  inputs.forEach(function(el){
    el.addEventListener("input", function(){
      clearTimeout(timer);
      timer = setTimeout(function(){ el.form && el.form.submit(); }, 400);
    });
    // Soumet immediatement si Entree
    el.addEventListener("keydown", function(ev){
      if (ev.key === "Enter") { ev.preventDefault(); clearTimeout(timer); el.form && el.form.submit(); }
    });
  });
})();
</script>';
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
