<?php
// agency_syndic_contrats.php — Liste des contrats de syndic
require_once __DIR__ . '/inc/init.php';
require_login();
if (current_role_id() > 2) { header('Location: agency_dashboard.php'); exit; }

$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── AJAX delete ────────────────────────────────────────────────────────────
if (isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id) $pdo->prepare("DELETE FROM contrat_syndic WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Filtres ────────────────────────────────────────────────────────────────
$fEtab   = (int)($_GET['etab'] ?? $etab_id);
$fSearch = trim($_GET['q'] ?? '');

// ── KPIs ───────────────────────────────────────────────────────────────────
$kWhere = $fEtab ? "WHERE id_etablissement=$fEtab" : '';
$kpi = $pdo->query("SELECT
    COUNT(*) total,
    SUM(CASE WHEN date_fin IS NULL OR date_fin >= CURDATE() THEN 1 ELSE 0 END) actifs,
    SUM(CASE WHEN date_fin < CURDATE() THEN 1 ELSE 0 END) expires,
    SUM(nb_lots_principaux) total_lots,
    SUM(remuneration_annuelle_ht) ca_ht
FROM contrat_syndic $kWhere")->fetch(PDO::FETCH_ASSOC);

// ── Requête principale ─────────────────────────────────────────────────────
$where = [];
$params = [];
if ($fEtab) { $where[] = 'cs.id_etablissement=?'; $params[] = $fEtab; }
if ($fSearch) {
    $where[] = '(cs.nom_copropriete LIKE ? OR cs.adresse_copropriete LIKE ? OR i.nom_immeuble LIKE ?)';
    $s = "%$fSearch%"; $params = array_merge($params, [$s,$s,$s]);
}
$sql = "SELECT cs.*, i.nom_immeuble AS imm_nom, e.nom AS etab_nom
FROM contrat_syndic cs
LEFT JOIN immeubles i ON i.id=cs.id_immeuble
LEFT JOIN etablissements e ON e.id=cs.id_etablissement"
. ($where ? ' WHERE '.implode(' AND ',$where) : '')
. " ORDER BY cs.date_debut DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contrats = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmtNum($n,$d=2) { return number_format((float)$n,$d,',',' '); }

// ── Layout config ──────────────────────────────────────────────────────────
$layout_title   = 'Contrats de syndic';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['total'].'</div><div class="ph-kpi-lbl">Total</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)$kpi['actifs'].'</div><div class="ph-kpi-lbl">Actifs</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.(int)$kpi['expires'].'</div><div class="ph-kpi-lbl">Expirés</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['total_lots'].'</div><div class="ph-kpi-lbl">Lots gérés</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.fmtNum($kpi['ca_ht'],0).' €</div><div class="ph-kpi-lbl">CA HT/an</div></div>
';

$layout_head_actions = '
<a href="agency_syndic_contrat_form.php" class="ph-btn primary">
  <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Nouveau
</a>
<a href="agency_syndic_propositions.php" class="ph-btn">Propositions</a>
<a href="agency_syndic_tarifs.php" class="ph-btn">Tarifs</a>
<a class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.search-box{flex:1;min-width:200px;position:relative}
.search-box input{width:100%;padding:10px 14px 10px 38px;background:var(--bg-primary,#e4e8f0);border:none;border-radius:999px;box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px #ffffff;color:#1a1816;font-size:13px;outline:none}
.search-box svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a8680}
.row-actions{display:flex;gap:6px}
.btn-icon{width:30px;height:30px;border-radius:8px;border:none;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff;color:#8a8680;display:inline-flex;align-items:center;justify-content:center;font-size:14px;transition:all .15s;text-decoration:none}
.btn-icon:hover{color:#4878a6;box-shadow:inset 2px 2px 5px #d4d7de,inset -2px -2px 5px #fff}
.btn-icon.danger:hover{color:#8a5040}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge.actif{background:#dcfce7;color:#3a7a6a}
.badge.expire{background:#fee2e2;color:#8a5040}
.empty{padding:60px;text-align:center;color:#8a8680}
.mbi-table thead th{color:#4a6038;font-weight:700}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function filterRows() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#contratTable tbody tr').forEach(tr => {
        tr.style.display = (tr.dataset.search||'').includes(q) ? '' : 'none';
    });
}
function del(id, nom) {
    if (!confirm('Supprimer le contrat de « ' + nom + ' » ?')) return;
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_delete=1&id='+id})
    .then(r=>r.json()).then(() => location.reload());
}
</script>
EXTRAJS;

ob_start();
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="search-box">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="searchInput" placeholder="Rechercher copropriété, adresse…" value="<?= htmlspecialchars($fSearch) ?>" oninput="filterRows()">
    </div>
</div>

<!-- Table -->
<div class="mbi-table-wrap">
<?php if (empty($contrats)): ?>
<div class="empty">
    <div style="font-size:15px;font-weight:700;margin-bottom:6px">Aucun contrat</div>
    <div style="font-size:13px">Créez votre premier contrat de syndic</div>
</div>
<?php else: ?>
<table class="mbi-table" id="contratTable">
    <thead><tr>
        <th>Copropriété</th>
        <th>Adresse</th>
        <th>Lots</th>
        <th>Honoraires HT/an</th>
        <th>Période</th>
        <th>Statut</th>
        <th>Agence</th>
        <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($contrats as $c):
        $actif = !$c['date_fin'] || $c['date_fin'] >= date('Y-m-d');
    ?>
    <tr data-search="<?= strtolower(htmlspecialchars(($c['nom_copropriete']??'').' '.($c['adresse_copropriete']??'').' '.($c['imm_nom']??''))) ?>">
        <td>
            <div style="font-weight:600;color:#2f587d"><?= htmlspecialchars($c['nom_copropriete'] ?: $c['imm_nom'] ?: '—') ?></div>
            <?php if ($c['immatriculation_copropriete']): ?>
            <div style="font-size:11px;color:#8a8680;font-family:'DM Mono',monospace"><?= htmlspecialchars($c['immatriculation_copropriete']) ?></div>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;color:#8a8680"><?= htmlspecialchars(mb_substr($c['adresse_copropriete']??'',0,50)) ?></td>
        <td><?= $c['nb_lots_principaux'] ? (int)$c['nb_lots_principaux'].' lots' : '—' ?></td>
        <td style="font-weight:700;color:#4878a6"><?= $c['remuneration_annuelle_ht'] ? fmtNum($c['remuneration_annuelle_ht']).' €' : '—' ?></td>
        <td style="font-size:12px">
            <?= $c['date_debut'] ? date('d/m/Y',strtotime($c['date_debut'])) : '?' ?>
            → <?= $c['date_fin'] ? date('d/m/Y',strtotime($c['date_fin'])) : 'En cours' ?>
        </td>
        <td><span class="badge <?= $actif?'actif':'expire' ?>"><?= $actif?'Actif':'Expiré' ?></span></td>
        <td style="font-size:12px;color:#8a8680"><?= htmlspecialchars($c['etab_nom']??'—') ?></td>
        <td>
            <div class="row-actions">
                <a href="agency_syndic_contrat_form.php?id=<?= $c['id'] ?>" class="btn-icon" title="Modifier">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <a href="agency_pdf_contrat_syndic.php?id=<?= $c['id'] ?>" target="_blank" class="btn-icon" title="PDF">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                </a>
                <button class="btn-icon danger" onclick="del(<?= $c['id'] ?>, '<?= htmlspecialchars($c['nom_copropriete']??$c['imm_nom']??'ce contrat') ?>')" title="Supprimer">
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
