<?php
// agency_reunions.php — Liste des réunions / AG V2 MaBoxImmo
$current_page = 'reunions';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId  = (int)current_role_id();
$pdo     = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['user_id'] ?? 0);

// ── Filtres ──────────────────────────────────────────────────────────
$f_immeuble = $_GET['immeuble'] ?? '';
$f_statut   = $_GET['statut']   ?? '';       // planifiee / en_cours / cloturee
$f_type     = $_GET['type']     ?? '';       // AG / CS / autre
$f_horizon  = $_GET['horizon']  ?? 'all';   // all / futur / passe
$f_etab     = ($roleId === 1) ? ($_GET['etab'] ?? '') : ($_SESSION['id_etablissement'] ?? '');
$f_organisateur = $_GET['organisateur'] ?? '';
$sort       = in_array($_GET['sort'] ?? '', ['date_reunion','titre','statut']) ? $_GET['sort'] : 'date_reunion';
$dir        = ($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// ── Listes de filtres ────────────────────────────────────────────────
$immeubles = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles ORDER BY nom_immeuble")->fetchAll(PDO::FETCH_ASSOC);
$etabs     = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$organisateurs = $pdo->query("SELECT DISTINCT u.id, CONCAT(u.prenom,' ',u.nom) AS nom FROM users u JOIN agency_reunion r ON r.cree_par=u.id WHERE u.actif=1 ORDER BY u.nom,u.prenom")->fetchAll(PDO::FETCH_ASSOC);

// ── Requête principale ───────────────────────────────────────────────
$where = ['1=1'];
$bind  = [];

if ($roleId >= 2) {
    $where[] = 'r.id_etablissement = :etab';
    $bind[':etab'] = $f_etab;
} elseif ($f_etab) {
    $where[] = 'r.id_etablissement = :etab';
    $bind[':etab'] = $f_etab;
}
if ($f_immeuble) { $where[] = 'r.id_immeuble = :imm'; $bind[':imm'] = $f_immeuble; }
if ($f_statut)   { $where[] = 'r.statut = :stat';      $bind[':stat'] = $f_statut; }
if ($f_type)     { $where[] = 'r.type_reunion = :typ';  $bind[':typ'] = $f_type; }
if ($f_organisateur) { $where[] = 'r.cree_par = :org';  $bind[':org'] = $f_organisateur; }
if ($f_horizon === 'futur') { $where[] = 'r.date_reunion >= NOW() AND r.statut != "cloturee"'; }
if ($f_horizon === 'passe') { $where[] = '(r.date_reunion < NOW() OR r.statut = "cloturee")'; }

$sql = "
    SELECT r.*,
           i.nom_immeuble AS immeuble_nom, i.reference_immeuble AS immeuble_ref,
           e.nom AS etab_nom,
           u.nom AS auteur_nom, u.prenom AS auteur_prenom,
           (SELECT COUNT(*) FROM agency_reunion_odj WHERE id_reunion = r.id) AS nb_odj,
           (SELECT COUNT(*) FROM agency_reunion_participant WHERE id_reunion = r.id) AS nb_participants
    FROM agency_reunion r
    LEFT JOIN immeubles i ON i.id = r.id_immeuble
    LEFT JOIN etablissements e ON e.id = r.id_etablissement
    LEFT JOIN users u ON u.id = r.cree_par
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $sort $dir
";
$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$reunions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs ─────────────────────────────────────────────────────────────
$all  = count($reunions);
$plan = count(array_filter($reunions, fn($r) => $r['statut'] === 'planifiee'));
$enc  = count(array_filter($reunions, fn($r) => $r['statut'] === 'en_cours'));
$clo  = count(array_filter($reunions, fn($r) => $r['statut'] === 'cloturee'));

// ── Helpers ───────────────────────────────────────────────────────────
function statutBadge(string $s): string {
    $map = [
        'planifiee' => ['Planifiée',  '#4878a6','#d8e8f5'],
        'en_cours'  => ['En cours',   '#3a7a6a','#d8eee3'],
        'cloturee'  => ['Clôturée',   '#808080','#e8e8e8'],
    ];
    $v = $map[$s] ?? ['?','#808080','#e8e8e8'];
    return '<span style="background:'.$v[2].';color:'.$v[1].';padding:2px 9px;border-radius:999px;font-size:10px;font-weight:700;font-family:\'DM Mono\',monospace">'.$v[0].'</span>';
}
function typeBadgeR(string $t): string {
    $map = [
        'AG'    => ['AG',    '#7a6830','#f8eddc'],
        'CS'    => ['CS',    '#4878a6','#d8e8f5'],
        'autre' => ['Autre', '#808080','#e8e8e8'],
    ];
    $v = $map[$t] ?? ['?','#808080','#e8e8e8'];
    return '<span style="background:'.$v[2].';color:'.$v[1].';padding:2px 9px;border-radius:999px;font-size:10px;font-weight:700;font-family:\'DM Mono\',monospace">'.$v[0].'</span>';
}
function sortLinkR(string $col, string $label, string $cur, string $curDir): string {
    $nd = ($cur === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    $p  = array_merge($_GET, ['sort' => $col, 'dir' => $nd]);
    $ic = $cur === $col ? ($curDir === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="?' . http_build_query($p) . '" style="color:inherit;text-decoration:none">' . $label . $ic . '</a>';
}

// ── Layout ───────────────────────────────────────────────────────────
$layout_title   = 'Réunions / AG';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.$all.'</div><div class="ph-kpi-lbl">Total</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.$plan.'</div><div class="ph-kpi-lbl">Planifiées</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.$enc.'</div><div class="ph-kpi-lbl">En cours</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#808080">'.$clo.'</div><div class="ph-kpi-lbl">Clôturées</div></div>
';

$btnNew = ($roleId <= 2)
    ? '<a href="agency_reunion_form.php" class="ph-btn primary"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Nouvelle</a>'
    : '<a class="ph-btn dispo">—</a>';
$layout_head_actions = $btnNew . '
    <a href="agency_reunions.php" class="ph-btn">Reset</a>
    <a href="agency_dashboard.php" class="ph-btn">Dashboard</a>
    <a class="ph-btn dispo">dispo</a>
';

// Filtres dans page-head : Immeuble + Établissement + Organisateur
$layout_head_filters = '
<style>
.mbi-page-head form#filter-form .ph-filter label { font-size: 7px !important; }
.mbi-page-head form#filter-form .ph-filter select {
    height: 24px !important; padding: 0 6px !important; min-width: 130px !important;
    font-size: 10px !important;
}
</style>
<form method="GET" id="filter-form" style="display:flex;gap:10px">
    <div class="ph-filter">
        <label>Immeuble</label>
        <select name="immeuble" onchange="this.form.submit()">
            <option value="">— Tous —</option>';
foreach ($immeubles as $i) {
    $sel = (string)$f_immeuble === (string)$i['id'] ? ' selected' : '';
    $layout_head_filters .= '<option value="'.$i['id'].'"'.$sel.'>'.htmlspecialchars($i['nom']).'</option>';
}
$layout_head_filters .= '
        </select>
    </div>
    <div class="ph-filter">
        <label>Établissement</label>
        <select name="etab" onchange="this.form.submit()">
            <option value="">— Tous —</option>';
foreach ($etabs as $e) {
    $sel = (string)$f_etab === (string)$e['id'] ? ' selected' : '';
    $layout_head_filters .= '<option value="'.$e['id'].'"'.$sel.'>'.htmlspecialchars($e['nom']).'</option>';
}
$layout_head_filters .= '
        </select>
    </div>
    <div class="ph-filter">
        <label>Organisateur</label>
        <select name="organisateur" onchange="this.form.submit()">
            <option value="">— Tous —</option>';
foreach ($organisateurs as $o) {
    $sel = (string)$f_organisateur === (string)$o['id'] ? ' selected' : '';
    $layout_head_filters .= '<option value="'.$o['id'].'"'.$sel.'>'.htmlspecialchars($o['nom']).'</option>';
}
$layout_head_filters .= '
        </select>
    </div>
</form>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.filter-bar{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:14px;box-shadow:5px 5px 14px var(--shadow-dark,#d4d7de),-5px -5px 12px var(--shadow-light,#fff);padding:14px 18px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}
.filter-group select,.filter-group input{background:var(--bg-secondary,#eef1f6);border:none;border-radius:8px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:6px 10px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;height:34px}
.pill-group{display:flex;gap:6px;flex-wrap:wrap}
.pill{padding:5px 14px;border-radius:999px;font-family:'DM Mono',monospace;font-size:10px;font-weight:600;text-decoration:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 8px var(--shadow-light,#fff);color:#6a6864;border:none;cursor:pointer;transition:box-shadow .15s}
.pill.active{background:#4878a6;color:#fff;box-shadow:inset 2px 2px 5px #355f88,inset -2px -2px 5px #6898bf}
.reun-table{width:100%;border-collapse:separate;border-spacing:0 6px}
.reun-table th{font-family:'DM Mono',monospace;font-size:9px;color:#4a6038;text-transform:uppercase;letter-spacing:.14em;padding:0 14px 6px;font-weight:700}
.reun-table td{padding:12px 14px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28}
.reun-table tr td:first-child{border-radius:12px 0 0 12px;box-shadow:-4px 0 10px var(--shadow-dark,#d4d7de)}
.reun-table tr td:last-child{border-radius:0 12px 12px 0;box-shadow:4px 0 10px var(--shadow-dark,#d4d7de)}
.reun-table tr:hover td{background:var(--bg-secondary,#eef1f6)}
.reun-table tr td{box-shadow:0 4px 10px var(--shadow-dark,#d4d7de),0 -2px 6px var(--shadow-light,#fff)}
.action-row{display:flex;gap:6px}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;text-decoration:none;border:none;cursor:pointer;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);transition:box-shadow .15s}
.btn-xs:hover{box-shadow:1px 1px 4px var(--shadow-dark,#d4d7de),-1px -1px 4px var(--shadow-light,#fff)}
.btn-xs.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de)}
.btn-xs.orange{background:linear-gradient(135deg,#a88060,#7a6830);color:#fff;box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de)}
.empty-state{text-align:center;padding:48px 20px;font-family:'DM Mono',monospace;font-size:12px;color:#9a9690}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function applyFilter(key, val) {
    const p = new URLSearchParams(window.location.search);
    if (val) p.set(key, val); else p.delete(key);
    window.location.href = '?' + p.toString();
}
</script>
EXTRAJS;

ob_start();
?>

<!-- Pills filtres horizon/type/statut -->
<div class="filter-bar" style="gap:18px">
    <div class="filter-group">
        <label>Horizon</label>
        <div class="pill-group">
            <?php foreach (['all'=>'Toutes','futur'=>'À venir','passe'=>'Passées'] as $v => $l):
                $active = ($f_horizon === $v) ? ' active' : '';
                $p = array_merge($_GET, ['horizon' => $v]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pill<?= $active ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="filter-group">
        <label>Type</label>
        <div class="pill-group">
            <?php foreach (['' => 'Tous', 'AG' => 'AG', 'CS' => 'CS', 'autre' => 'Autre'] as $v => $l):
                $active = ($f_type === $v) ? ' active' : '';
                $p = array_merge($_GET, ['type' => $v]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pill<?= $active ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="filter-group">
        <label>Statut</label>
        <div class="pill-group">
            <?php foreach (['' => 'Tous', 'planifiee' => 'Planifiée', 'en_cours' => 'En cours', 'cloturee' => 'Clôturée'] as $v => $l):
                $active = ($f_statut === $v) ? ' active' : '';
                $p = array_merge($_GET, ['statut' => $v]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pill<?= $active ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Table -->
<?php if (empty($reunions)): ?>
<div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--shadow-dark,#d4d7de)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    <div>Aucune réunion trouvée</div>
</div>
<?php else: ?>
<table class="reun-table">
    <thead>
        <tr>
            <th style="text-align:left"><?= sortLinkR('date_reunion', 'Date', $sort, $dir) ?></th>
            <th style="text-align:left"><?= sortLinkR('titre', 'Titre', $sort, $dir) ?></th>
            <th>Type</th>
            <th>Immeuble</th>
            <th>ODJ</th>
            <th>Participants</th>
            <th><?= sortLinkR('statut', 'Statut', $sort, $dir) ?></th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($reunions as $r):
        $ts = $r['date_reunion'] ? strtotime($r['date_reunion']) : null;
    ?>
    <tr>
        <td>
            <div style="font-weight:700;color:#2c2a28"><?= $ts ? date('d/m/Y', $ts) : '<em style="color:#9a9690">—</em>' ?></div>
            <?php if ($ts): ?><div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690"><?= date('H:i', $ts) ?></div><?php endif; ?>
        </td>
        <td>
            <div style="font-weight:600"><?= htmlspecialchars($r['titre'] ?: '—') ?></div>
            <?php if ($r['lieu']): ?><div style="font-size:10px;color:#9a9690;font-family:'DM Mono',monospace"><?= htmlspecialchars($r['lieu']) ?></div><?php endif; ?>
        </td>
        <td style="text-align:center"><?= typeBadgeR($r['type_reunion'] ?? 'autre') ?></td>
        <td>
            <?php if ($r['immeuble_nom']): ?>
            <a href="agency_immeuble_fiche.php?id=<?= $r['id_immeuble'] ?>" style="color:#4878a6;text-decoration:none;font-size:11px;font-weight:600">
                <?= htmlspecialchars($r['immeuble_nom']) ?>
            </a>
            <?php else: ?><span style="color:#9a9690">—</span><?php endif; ?>
        </td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;color:#4878a6"><?= (int)$r['nb_odj'] ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700"><?= (int)$r['nb_participants'] ?></td>
        <td style="text-align:center"><?= statutBadge($r['statut'] ?? 'planifiee') ?></td>
        <td>
            <div class="action-row">
                <a href="agency_reunion_detail.php?id=<?= $r['id'] ?>" class="btn-xs primary">Détail</a>
                <?php if ($roleId <= 2 && ($r['statut'] ?? '') !== 'cloturee'): ?>
                <a href="agency_reunion_tenir.php?id=<?= $r['id'] ?>" class="btn-xs orange">Tenir</a>
                <?php endif; ?>
                <?php if ($roleId <= 2): ?>
                <a href="agency_reunion_form.php?id=<?= $r['id'] ?>" class="btn-xs">Modifier</a>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
