<?php
// agency_taches.php — Liste des tâches (layout_maboximmo)
$current_page = 'taches';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Filtres ──────────────────────────────────────────────────────────
$f_q        = trim($_GET['q']        ?? '');
$f_statut   = $_GET['statut']        ?? '';
$f_priorite = $_GET['priorite']      ?? '';
$f_cat      = $_GET['categorie']     ?? '';
$f_imm      = $_GET['immeuble']      ?? '';
$f_assigne  = $_GET['assigne']       ?? ($roleId >= 2 ? (string)$userId : '');
$f_echeance = $_GET['echeance']      ?? '';
$f_arc      = isset($_GET['archive']) ? (int)$_GET['archive'] : 0;
$sort       = in_array($_GET['sort'] ?? '', ['date_echeance','priorite','titre','statut','date_creation']) ? $_GET['sort'] : 'date_echeance';
$dir        = ($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

// ── Data listes ───────────────────────────────────────────────────────
$immeubles = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$all_users = $pdo->query("SELECT id, nom, prenom FROM users WHERE actif=1 ORDER BY nom,prenom")->fetchAll(PDO::FETCH_ASSOC);

// ── Requête ───────────────────────────────────────────────────────────
$where = ['1=1'];
$bind  = [];

if ($roleId >= 2 && $f_assigne === '') {
    $where[] = '(t.id_createur = :myid OR EXISTS(SELECT 1 FROM taches_utilisateurs ta WHERE ta.id_tache=t.id AND ta.id_utilisateur=:myid2))';
    $bind[':myid'] = $userId; $bind[':myid2'] = $userId;
}
if ($f_assigne !== '' && $f_assigne !== '0') {
    $where[] = 'EXISTS(SELECT 1 FROM taches_utilisateurs ta WHERE ta.id_tache=t.id AND ta.id_utilisateur=:ass)';
    $bind[':ass'] = (int)$f_assigne;
}
if ($f_statut)   { $where[] = 't.statut = :stat'; $bind[':stat'] = $f_statut; }
else             { $where[] = ($f_arc ? "t.statut = 'archivee'" : "t.statut != 'archivee'"); }
if ($f_priorite) { $where[] = 't.priorite = :prio'; $bind[':prio'] = $f_priorite; }
if ($f_cat)      { $where[] = 't.categorie = :cat'; $bind[':cat'] = $f_cat; }
if ($f_imm)      { $where[] = 't.id_immeuble = :imm'; $bind[':imm'] = (int)$f_imm; }
if ($f_q)        { $where[] = '(t.titre LIKE :q OR t.description LIKE :q2)'; $bind[':q'] = "%$f_q%"; $bind[':q2'] = "%$f_q%"; }

if ($f_echeance === 'today') { $where[] = 'DATE(t.date_echeance) = CURDATE()'; }
elseif ($f_echeance === 'week') { $where[] = 'DATE(t.date_echeance) BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)'; }
elseif ($f_echeance === 'late') { $where[] = "t.date_echeance < CURDATE() AND t.statut NOT IN ('terminee','archivee')"; }

$sort_sql = $sort === 'priorite'
    ? "FIELD(t.priorite,'critique','haute','normale','basse') $dir"
    : "t.$sort $dir";

$sql = "
    SELECT t.*,
           i.nom_immeuble AS imm_nom, i.reference_immeuble AS imm_ref,
           u.nom AS auteur_nom, u.prenom AS auteur_prenom,
           (SELECT COUNT(*) FROM taches_commentaires WHERE id_tache=t.id) AS nb_comments,
           (SELECT COUNT(*) FROM taches_documents WHERE id_tache=t.id) AS nb_docs,
           (SELECT GROUP_CONCAT(CONCAT(u2.prenom,' ',u2.nom) SEPARATOR ', ')
            FROM taches_utilisateurs ta JOIN users u2 ON u2.id=ta.id_utilisateur
            WHERE ta.id_tache=t.id) AS assignes_noms
    FROM taches t
    LEFT JOIN immeubles i ON i.id = t.id_immeuble
    LEFT JOIN users u ON u.id = t.id_createur
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $sort_sql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$taches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs ─────────────────────────────────────────────────────────────
$kpi = $pdo->prepare("
    SELECT
        SUM(CASE WHEN statut != 'archivée' THEN 1 ELSE 0 END) AS total,
        SUM(CASE WHEN statut = 'à faire' THEN 1 ELSE 0 END) AS a_faire,
        SUM(CASE WHEN statut = 'en cours' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN statut = 'terminée' THEN 1 ELSE 0 END) AS terminees,
        SUM(CASE WHEN date_echeance < CURDATE() AND statut NOT IN ('terminée','archivée') THEN 1 ELSE 0 END) AS en_retard
    FROM taches
    " . ($roleId >= 2 ? "WHERE id_createur = :uid OR id IN (SELECT id_tache FROM taches_utilisateurs WHERE id_utilisateur=:uid2)" : "WHERE 1=1")
);
if ($roleId >= 2) $kpi->execute([':uid'=>$userId,':uid2'=>$userId]);
else              $kpi->execute();
$k = $kpi->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Helpers ───────────────────────────────────────────────────────────
$PRIORITE_MAP = [
    'critique' => ['Critique','#8a5040','#fce8e8'],
    'haute'    => ['Haute',   '#7a6830','#f8eddc'],
    'normale'  => ['Normale', '#4878a6','#d8e8f5'],
    'basse'    => ['Basse',   '#808080','#e8e8e8'],
];
$STATUT_MAP = [
    'à faire'  => ['À faire',  '#9a9690','var(--bg-primary,#e4e8f0)'],
    'en cours' => ['En cours', '#4878a6','#d8e8f5'],
    'en attente'=> ['Attente', '#7a6830','#f8eddc'],
    'terminee' => ['Terminée', '#3a7a6a','#d8eee3'],
    'archivee' => ['Archivée', 'var(--shadow-dark,#d4d7de)','#f0eee8'],
];
$CATEGORIES = ['comptable','mutation','rappel','juridique','technique','administratif','communication','autre'];

function prioBadge(string $p, array $map): string {
    $v = $map[$p] ?? ['?','#808080','#e8e8e8'];
    return '<span style="background:'.$v[2].';color:'.$v[1].';padding:2px 9px;border-radius:999px;font-size:10px;font-weight:700;font-family:\'DM Mono\',monospace">'.$v[0].'</span>';
}
function statutBadgeT(string $s, array $map): string {
    $v = $map[$s] ?? ['?','#808080','#e8e8e8'];
    return '<span style="background:'.$v[2].';color:'.$v[1].';padding:2px 9px;border-radius:999px;font-size:10px;font-weight:700;font-family:\'DM Mono\',monospace">'.$v[0].'</span>';
}
function sortLinkT(string $col, string $label, string $cur, string $curDir): string {
    $nd = ($cur === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    $p = array_merge($_GET, ['sort'=>$col,'dir'=>$nd]);
    $ic = $cur === $col ? ($curDir === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="?'.http_build_query($p).'" style="color:inherit;text-decoration:none">'.$label.$ic.'</a>';
}
function echeanceFmt(?string $d, string $statut): string {
    if (!$d) return '<span style="color:var(--shadow-dark,#d4d7de)">—</span>';
    $ts   = strtotime($d);
    $days = (int)(($ts - time()) / 86400);
    $fmt  = date('d/m/Y', $ts);
    if (in_array($statut, ['terminee','archivee'])) return '<span style="color:#9a9690">'.$fmt.'</span>';
    if ($days < 0)  return '<span style="color:#8a5040;font-weight:700">'.$fmt.' ('.abs($days).'j retard)</span>';
    if ($days === 0) return '<span style="color:#7a6830;font-weight:700">Auj.</span>';
    if ($days <= 3) return '<span style="color:#7a6830">'.$fmt.'</span>';
    return '<span>'.$fmt.'</span>';
}

// ── Layout ──
$layout_title    = 'Tâches';
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)($k['total'] ?? 0).'</div><div class="ph-kpi-lbl">Total</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#9a9690">'.(int)($k['a_faire'] ?? 0).'</div><div class="ph-kpi-lbl">À faire</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.(int)($k['en_cours'] ?? 0).'</div><div class="ph-kpi-lbl">En cours</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)($k['terminees'] ?? 0).'</div><div class="ph-kpi-lbl">Terminées</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.(int)($k['en_retard'] ?? 0).'</div><div class="ph-kpi-lbl">En retard</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($taches).'</div><div class="ph-kpi-lbl">Affichées</div></div>
';

$layout_head_actions = '
    <a href="agency_tache_detail.php?new=1" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nouvelle
    </a>
    <a href="agency_taches.php" class="ph-btn">Reset</a>
    <a href="agency_dashboard.php" class="ph-btn">Dashboard</a>
    <a class="ph-btn dispo">—</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
/* Filter bar */
.filter-bar{background:var(--bg-primary,#e4e8f0);border-radius:14px;box-shadow:5px 5px 14px var(--shadow-dark,#d4d7de),-5px -5px 12px #ffffff;padding:14px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}
.fg input,.fg select{background:var(--bg-secondary,#eef1f6);border:none;border-radius:8px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:6px 10px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;height:34px}
.fg input{min-width:140px}
/* Pills */
.pill-row{display:flex;gap:5px;flex-wrap:wrap}
.pf{padding:4px 12px;border-radius:999px;font-family:'DM Mono',monospace;font-size:10px;font-weight:600;text-decoration:none;background:var(--bg-primary,#e4e8f0);box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px #ffffff;color:#6a6864;border:none;cursor:pointer;transition:box-shadow .15s;white-space:nowrap}
.pf.active{background:#4878a6;color:#fff;box-shadow:inset 2px 2px 5px #355f88,inset -2px -2px 5px #6898bf}
/* Table */
.t-wrap{background:var(--bg-primary,#e4e8f0);border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;overflow:hidden}
.t-taches{width:100%;border-collapse:separate;border-spacing:0}
.t-taches thead tr{background:var(--bg-secondary,#eef1f6)}
.t-taches th{font-family:'DM Mono',monospace;font-size:10px;color:#4a6038;font-weight:700;text-transform:uppercase;letter-spacing:.1em;padding:10px 14px;border-bottom:1px solid #e4e6ec;white-space:nowrap}
.t-taches tbody tr{border-bottom:1px solid #ece8e2;transition:background .12s}
.t-taches tbody tr:hover{background:var(--bg-secondary,#eef1f6)}
.t-taches tbody tr:last-child{border-bottom:none}
.t-taches td{padding:11px 14px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;vertical-align:middle}
.prio-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block;margin-right:6px}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;text-decoration:none;border:none;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px #ffffff;transition:box-shadow .15s;color:#4878a6}
.btn-xs:hover{box-shadow:1px 1px 4px var(--shadow-dark,#d4d7de),-1px -1px 4px #ffffff}
.btn-xs.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de)}
.meta-ico{display:inline-flex;align-items:center;gap:3px;font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin-right:8px}
.meta-ico svg{width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.empty-state-t{text-align:center;padding:50px 20px;font-family:'DM Mono',monospace;font-size:12px;color:#9a9690}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function applyF(key, val) {
    const p = new URLSearchParams(window.location.search);
    if (val) p.set(key, val); else p.delete(key);
    window.location.href = '?' + p.toString();
}
let _qt;
document.getElementById('f_q')?.addEventListener('input', function() {
    clearTimeout(_qt);
    _qt = setTimeout(() => applyF('q', this.value), 500);
});
</script>
EXTRAJS;

// ── Contenu ──
ob_start();
?>

<div class="section-header">
    <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Suivi des tâches et actions à mener</span>
        <div class="line-r"></div>
    </div>
</div>

<!-- Filtres -->
<div class="filter-bar">
    <div class="fg">
        <label>Recherche</label>
        <input type="text" id="f_q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Titre, description…">
    </div>
    <div class="fg">
        <label>Statut</label>
        <div class="pill-row">
            <?php foreach (['' => 'Tous', 'à faire' => 'À faire', 'en cours' => 'En cours', 'en attente' => 'Attente', 'terminee' => 'Terminée'] as $v => $l):
                $a = ($f_statut === $v && !$f_arc) ? ' active' : '';
                $p = array_merge($_GET, ['statut'=>$v,'archive'=>0]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pf<?= $a ?>"><?= $l ?></a>
            <?php endforeach; ?>
            <?php $pa = array_merge($_GET, ['statut'=>'','archive'=>1]); ?>
            <a href="?<?= http_build_query($pa) ?>" class="pf<?= $f_arc ? ' active' : '' ?>">Archivées</a>
        </div>
    </div>
    <div class="fg">
        <label>Priorité</label>
        <div class="pill-row">
            <?php foreach (['' => 'Toutes', 'critique' => '🔴 Critique', 'haute' => '🟠 Haute', 'normale' => '🔵 Normale', 'basse' => '⚪ Basse'] as $v => $l):
                $a = ($f_priorite === $v) ? ' active' : '';
                $p = array_merge($_GET, ['priorite'=>$v]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pf<?= $a ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="fg">
        <label>Échéance</label>
        <div class="pill-row">
            <?php foreach (['' => 'Toutes', 'today' => 'Auj.', 'week' => '7 jours', 'late' => 'En retard'] as $v => $l):
                $a = ($f_echeance === $v) ? ' active' : '';
                $p = array_merge($_GET, ['echeance'=>$v]);
            ?>
            <a href="?<?= http_build_query($p) ?>" class="pf<?= $a ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="fg">
        <label>Catégorie</label>
        <select onchange="applyF('categorie',this.value)">
            <option value="">— Toutes —</option>
            <?php foreach ($CATEGORIES as $c): ?>
            <option value="<?= $c ?>" <?= $f_cat === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>Immeuble</label>
        <select onchange="applyF('immeuble',this.value)">
            <option value="">— Tous —</option>
            <?php foreach ($immeubles as $im): ?>
            <option value="<?= $im['id'] ?>" <?= $f_imm == $im['id'] ? 'selected' : '' ?>><?= htmlspecialchars($im['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($roleId === 1): ?>
    <div class="fg">
        <label>Assigné à</label>
        <select onchange="applyF('assigne',this.value)">
            <option value="">— Tous —</option>
            <?php foreach ($all_users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $f_assigne == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($u['prenom'].' '.$u['nom'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <a href="agency_taches.php" class="btn-xs" style="height:34px;margin-top:auto">✕ Reset</a>
</div>

<!-- Table -->
<?php if (empty($taches)): ?>
<div class="empty-state-t">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--shadow-dark,#d4d7de)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:10px"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
    <div>Aucune tâche trouvée</div>
</div>
<?php else: ?>
<div class="t-wrap">
<table class="t-taches">
    <thead>
        <tr>
            <th style="width:8px;padding-right:4px"></th>
            <th><?= sortLinkT('titre','Titre',$sort,$dir) ?></th>
            <th><?= sortLinkT('categorie','Catégorie',$sort,$dir) ?></th>
            <th><?= sortLinkT('priorite','Priorité',$sort,$dir) ?></th>
            <th>Immeuble</th>
            <th>Assignés</th>
            <th><?= sortLinkT('date_echeance','Échéance',$sort,$dir) ?></th>
            <th><?= sortLinkT('statut','Statut',$sort,$dir) ?></th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($taches as $t):
        $pc = $PRIORITE_MAP[$t['priorite'] ?? 'normale'][1] ?? '#808080';
    ?>
    <tr>
        <td style="padding-right:0;padding-left:12px">
            <span class="prio-dot" style="background:<?= $pc ?>"></span>
        </td>
        <td>
            <a href="agency_tache_detail.php?id=<?= $t['id'] ?>" style="font-weight:600;color:#1a1816;text-decoration:none;display:block">
                <?= htmlspecialchars($t['titre']) ?>
            </a>
            <div style="margin-top:3px">
                <?php if ($t['nb_comments'] > 0): ?>
                <span class="meta-ico"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><?= $t['nb_comments'] ?></span>
                <?php endif; ?>
                <?php if ($t['nb_docs'] > 0): ?>
                <span class="meta-ico"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?= $t['nb_docs'] ?></span>
                <?php endif; ?>
                <?php if ($t['categorie']): ?>
                <span style="font-family:'DM Mono',monospace;font-size:9px;color:#9a9690"><?= htmlspecialchars($t['categorie']) ?></span>
                <?php endif; ?>
            </div>
        </td>
        <td><span style="font-family:'DM Mono',monospace;font-size:10px;color:#6a6864"><?= htmlspecialchars(ucfirst($t['categorie'] ?? '—')) ?></span></td>
        <td><?= prioBadge($t['priorite'] ?? 'normale', $PRIORITE_MAP) ?></td>
        <td>
            <?php if ($t['imm_nom']): ?>
            <a href="agency_immeuble_fiche.php?id=<?= $t['id_immeuble'] ?>" style="color:#4878a6;text-decoration:none;font-size:11px">
                <?= htmlspecialchars($t['imm_nom']) ?>
            </a>
            <?php else: ?><span style="color:var(--shadow-dark,#d4d7de)">—</span><?php endif; ?>
        </td>
        <td style="max-width:160px">
            <div style="font-size:11px;color:#4a4844;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($t['assignes_noms'] ?? '—') ?>
            </div>
        </td>
        <td><?= echeanceFmt($t['date_echeance'] ?? null, $t['statut'] ?? '') ?></td>
        <td><?= statutBadgeT($t['statut'] ?? 'à faire', $STATUT_MAP) ?></td>
        <td>
            <div style="display:flex;gap:5px">
                <a href="agency_tache_detail.php?id=<?= $t['id'] ?>" class="btn-xs primary">Détail</a>
                <?php if ($roleId <= 2 || (int)$t['id_createur'] === $userId): ?>
                <a href="agency_tache_detail.php?id=<?= $t['id'] ?>&edit=1" class="btn-xs">Modifier</a>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin-top:8px;text-align:right">
    <?= count($taches) ?> tâche<?= count($taches) > 1 ? 's' : '' ?>
</div>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
