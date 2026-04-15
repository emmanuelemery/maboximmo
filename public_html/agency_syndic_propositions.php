<?php
// agency_syndic_propositions.php — Liste des propositions commerciales syndic
require_once __DIR__ . '/inc/init.php';
require_login();
if (current_role_id() > 2) { header('Location: agency_dashboard.php'); exit; }

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── AJAX : changer statut ──────────────────────────────────────────────────
if (isset($_POST['ajax_statut'])) {
    header('Content-Type: application/json');
    $pid    = (int)($_POST['id'] ?? 0);
    $statut = $_POST['statut'] ?? '';
    $ok_statuts = ['brouillon','envoyee','relancee','acceptee','refusee','expiree'];
    if (!$pid || !in_array($statut, $ok_statuts)) { echo json_encode(['ok'=>false]); exit; }
    $pdo->prepare("UPDATE agency_syndic_proposition SET statut=? WHERE id=?")->execute([$statut,$pid]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX : supprimer ───────────────────────────────────────────────────────
if (isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    $pid = (int)($_POST['id'] ?? 0);
    if (!$pid) { echo json_encode(['ok'=>false]); exit; }
    $pdo->prepare("DELETE FROM agency_syndic_proposition_ligne WHERE id_proposition=?")->execute([$pid]);
    $pdo->prepare("DELETE FROM agency_syndic_proposition_envoi  WHERE id_proposition=?")->execute([$pid]);
    $pdo->prepare("DELETE FROM agency_syndic_proposition WHERE id=?")->execute([$pid]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Filtres ────────────────────────────────────────────────────────────────
$fStatut = trim($_GET['statut'] ?? '');
$fSearch = trim($_GET['q'] ?? '');

// ── KPIs ───────────────────────────────────────────────────────────────────
$kpi = $pdo->query("SELECT
    COUNT(*) total,
    SUM(statut='brouillon') brouillons,
    SUM(statut IN('envoyee','relancee')) envoyees,
    SUM(statut='acceptee') acceptees,
    SUM(statut='refusee') refusees,
    SUM(statut='expiree') expirees,
    SUM(CASE WHEN statut='acceptee' THEN honoraires_base_ht ELSE 0 END) ca_ht
FROM agency_syndic_proposition
" . ($etab_id ? " WHERE id_etablissement=$etab_id OR id_etablissement IS NULL" : "") . "
")->fetch(PDO::FETCH_ASSOC);

// ── Requête principale ─────────────────────────────────────────────────────
$where = [];
$params = [];
if ($etab_id) { $where[] = '(p.id_etablissement=? OR p.id_etablissement IS NULL)'; $params[] = $etab_id; }
if ($fStatut)  { $where[] = 'p.statut=?'; $params[] = $fStatut; }
if ($fSearch)  { $where[] = '(p.reference LIKE ? OR p.prospect_nom LIKE ? OR p.immeuble_nom LIKE ? OR p.immeuble_ville LIKE ?)'; $s="%$fSearch%"; $params = array_merge($params,[$s,$s,$s,$s]); }
$sql = "SELECT p.*, e.nom AS etab_nom,
    (SELECT COUNT(*) FROM agency_syndic_proposition_envoi en WHERE en.id_proposition=p.id) nb_envois
FROM agency_syndic_proposition p
LEFT JOIN etablissements e ON e.id=p.id_etablissement"
    . ($where ? ' WHERE '.implode(' AND ',$where) : '')
    . " ORDER BY p.updated_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$propositions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statutColors = [
    'brouillon' => ['bg'=>'#f1f5f9','color'=>'#64748b','label'=>'Brouillon'],
    'envoyee'   => ['bg'=>'#dbeafe','color'=>'#2563eb','label'=>'Envoyée'],
    'relancee'  => ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Relancée'],
    'acceptee'  => ['bg'=>'#dcfce7','color'=>'#16a34a','label'=>'Acceptée'],
    'refusee'   => ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Refusée'],
    'expiree'   => ['bg'=>'#f3f4f6','color'=>'#9ca3af','label'=>'Expirée'],
];

function fmtNum($n, $dec=2) {
    return number_format((float)$n, $dec, ',', ' ');
}

// ── Layout config ──────────────────────────────────────────────────────────
$layout_title   = 'Propositions commerciales';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['total'].'</div><div class="ph-kpi-lbl">Total</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['brouillons'].'</div><div class="ph-kpi-lbl">Brouillons</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['envoyees'].'</div><div class="ph-kpi-lbl">Envoyées</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)$kpi['acceptees'].'</div><div class="ph-kpi-lbl">Acceptées</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.(int)$kpi['refusees'].'</div><div class="ph-kpi-lbl">Refusées</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.fmtNum($kpi['ca_ht'],0).' €</div><div class="ph-kpi-lbl">CA HT accepté</div></div>
';

$layout_head_actions = '
<a href="agency_syndic_proposition_form.php" class="ph-btn primary">
  <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Nouvelle
</a>
<a href="agency_syndic_contrats.php" class="ph-btn">Contrats</a>
<a href="agency_syndic_tarifs.php" class="ph-btn">Tarifs</a>
<a class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.toolbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
.search-box { flex:1; min-width:200px; position:relative; }
.search-box input {
    width:100%; padding:10px 14px 10px 38px;
    background:var(--bg-primary,#e4e8f0); border:none; border-radius:999px;
    box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de), inset -3px -3px 6px #ffffff; color:#1a1816; font-size:13px;
    outline:none;
}
.search-box svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#8a8680; }
.filter-pills { display:flex; gap:8px; flex-wrap:wrap; }
.pill {
    padding:6px 14px; border-radius:999px; border:none; cursor:pointer;
    font-size:12px; font-weight:600; font-family:inherit;
    background:var(--bg-primary,#e4e8f0); box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff; color:#8a8680;
    transition:all .15s;
}
.pill.active { background:#4878a6; color:#fff; box-shadow:none; }

.badge-statut {
    display:inline-block; padding:3px 10px; border-radius:999px;
    font-size:11px; font-weight:700; letter-spacing:.3px;
}
.row-actions { display:flex; gap:6px; align-items:center; }
.btn-icon {
    width:30px; height:30px; border-radius:8px; border:none; cursor:pointer;
    background:var(--bg-primary,#e4e8f0); box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff; color:#8a8680;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:14px; transition:all .15s; text-decoration:none;
}
.btn-icon:hover { color:#4878a6; box-shadow:inset 2px 2px 5px #d4d7de,inset -2px -2px 5px #fff; }
.btn-icon.danger:hover { color:#8a5040; }

.empty { padding:60px; text-align:center; color:#8a8680; }
.empty svg { margin-bottom:14px; opacity:.4; }

.ref-badge {
    font-family:'DM Mono',monospace; font-size:11px; font-weight:700;
    padding:3px 9px; border-radius:6px; background:rgba(72,120,166,.12); color:#4878a6;
}
.lots-badge { font-size:12px; color:#8a8680; }
.mbi-table thead th { color:#4a6038; font-weight:700; }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function setFilter(s) {
    const url = new URL(window.location);
    if (s) url.searchParams.set('statut', s);
    else url.searchParams.delete('statut');
    window.location = url.toString();
}

function filterRows() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#propTable tbody tr').forEach(tr => {
        const text = tr.getAttribute('data-search') || '';
        tr.style.display = text.includes(q) ? '' : 'none';
    });
}

function deleteProp(id, ref) {
    if (!confirm('Supprimer la proposition ' + ref + ' ? Cette action est irréversible.')) return;
    fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_delete=1&id='+id
    }).then(r=>r.json()).then(d=>{
        if (d.ok) location.reload();
        else alert('Erreur lors de la suppression');
    });
}
</script>
EXTRAJS;

ob_start();
?>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-box">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
            </svg>
            <input type="text" id="searchInput" placeholder="Rechercher prospect, immeuble, référence…"
                   value="<?= htmlspecialchars($fSearch) ?>"
                   oninput="filterRows()">
        </div>
        <div class="filter-pills">
            <button class="pill<?= !$fStatut?' active':'' ?>" onclick="setFilter('')">Tous</button>
            <button class="pill<?= $fStatut==='brouillon'?' active':'' ?>" onclick="setFilter('brouillon')">Brouillons</button>
            <button class="pill<?= $fStatut==='envoyee'?' active':'' ?>" onclick="setFilter('envoyee')">Envoyées</button>
            <button class="pill<?= $fStatut==='acceptee'?' active':'' ?>" onclick="setFilter('acceptee')">Acceptées</button>
            <button class="pill<?= $fStatut==='refusee'?' active':'' ?>" onclick="setFilter('refusee')">Refusées</button>
        </div>
    </div>

    <!-- Table -->
    <div class="mbi-table-wrap">
        <?php if (empty($propositions)): ?>
        <div class="empty">
            <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <div style="font-size:15px;font-weight:700;margin-bottom:6px">Aucune proposition</div>
            <div style="font-size:13px">Créez votre première proposition commerciale</div>
        </div>
        <?php else: ?>
        <table class="mbi-table" id="propTable">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Prospect</th>
                    <th>Immeuble</th>
                    <th>Lots</th>
                    <th>Forfait HT</th>
                    <th>Validité</th>
                    <th>Statut</th>
                    <th>Envois</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($propositions as $p):
                $sc = $statutColors[$p['statut']] ?? $statutColors['brouillon'];
                $expired = $p['date_validite'] && $p['date_validite'] < date('Y-m-d') && !in_array($p['statut'],['acceptee','refusee','expiree']);
            ?>
            <tr data-statut="<?= $p['statut'] ?>" data-search="<?= strtolower(htmlspecialchars($p['reference'].' '.$p['prospect_nom'].' '.$p['immeuble_nom'].' '.$p['immeuble_ville'])) ?>">
                <td><span class="ref-badge"><?= htmlspecialchars($p['reference']) ?></span></td>
                <td>
                    <div style="font-weight:600;color:#2f587d"><?= htmlspecialchars($p['prospect_nom']) ?></div>
                    <?php if ($p['prospect_fonction']): ?><div style="font-size:11px;color:#8a8680"><?= htmlspecialchars($p['prospect_fonction']) ?></div><?php endif; ?>
                </td>
                <td>
                    <div style="font-weight:600;color:#2f587d"><?= htmlspecialchars($p['immeuble_nom']) ?></div>
                    <?php if ($p['immeuble_ville']): ?><div style="font-size:11px;color:#8a8680"><?= htmlspecialchars($p['immeuble_code_postal'].' '.$p['immeuble_ville']) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($p['immeuble_nb_lots']): ?>
                    <span class="lots-badge"><?= (int)$p['immeuble_nb_lots'] ?> lots</span>
                    <?php else: ?><span style="color:#8a8680">—</span><?php endif; ?>
                </td>
                <td style="font-weight:700;color:#4878a6"><?= fmtNum($p['honoraires_base_ht']) ?> €</td>
                <td>
                    <?php if ($p['date_validite']): ?>
                    <span style="<?= $expired?'color:#8a5040;font-weight:700':'' ?>"><?= date('d/m/Y',strtotime($p['date_validite'])) ?></span>
                    <?php if ($expired): ?><div style="font-size:10px;color:#8a5040;font-weight:700">EXPIRÉE</div><?php endif; ?>
                    <?php else: ?><span style="color:#8a8680">—</span><?php endif; ?>
                </td>
                <td>
                    <span class="badge-statut" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= $sc['label'] ?></span>
                </td>
                <td>
                    <span style="font-size:12px;color:#8a8680"><?= (int)$p['nb_envois'] ?> envoi<?= $p['nb_envois']!=1?'s':'' ?></span>
                </td>
                <td>
                    <div class="row-actions">
                        <a href="agency_syndic_proposition_form.php?id=<?= $p['id'] ?>" class="btn-icon" title="Modifier">
                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <a href="agency_pdf_syndic_proposition.php?id=<?= $p['id'] ?>" target="_blank" class="btn-icon" title="PDF">
                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </a>
                        <a href="agency_syndic_proposition_form.php?dupliquer=<?= $p['id'] ?>" class="btn-icon" title="Dupliquer">
                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        </a>
                        <button class="btn-icon danger" title="Supprimer" onclick="deleteProp(<?= $p['id'] ?>, '<?= htmlspecialchars($p['reference']) ?>')">
                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6M10 11v6M14 11v6M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
