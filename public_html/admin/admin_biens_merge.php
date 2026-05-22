<?php
// admin/admin_biens_merge.php — Page de fusion 2 biens en doublon
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 7 || $roleId === 2);
if (!$isManager) { http_response_code(403); exit('Accès refusé.'); }

$q = trim((string)($_GET['q'] ?? ''));

// Recherche libre
$results = [];
if ($q !== '') {
    $st = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.code_postal, b.ville,
        b.surface_habitable, b.statut_bien, b.usage_bien, b.date_creation,
        COALESCE(p.societe, CONCAT_WS(' ', p.prenom, p.nom)) AS proprio,
        (SELECT COUNT(*) FROM bien_baux WHERE id_bien = b.id) AS nb_baux,
        (SELECT COUNT(*) FROM ged_documents WHERE
            status='active' AND (
                (id_bien IS NOT NULL AND id_bien = b.id)
                OR JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.bien_id_bdd')) = CAST(b.id AS CHAR)
            )) AS nb_docs
        FROM biens b
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE (LOWER(b.reference_bien) LIKE LOWER(?) OR LOWER(b.designation) LIKE LOWER(?)
            OR LOWER(b.adresse_1) LIKE LOWER(?) OR LOWER(b.ville) LIKE LOWER(?))
        ORDER BY b.id DESC LIMIT 50");
    $like = '%' . $q . '%';
    $st->execute([$like, $like, $like, $like]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Fusion doublons biens';
$pageSubtitle = 'Admin · Fusion 2 biens';
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Fusion biens — Admin</title>
<style>
body { font-family: Sora, sans-serif; padding: 28px; background: #f7f4ef; max-width: 1300px; }
h1 { font-size: 22px; }
.card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 14px; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.search { display: flex; gap: 10px; }
.search input { flex:1; padding:10px 14px; border:1px solid #e3dfd8; border-radius:8px; font-size:13px; }
.btn { padding:10px 16px; background:#4878a6; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:700; }
.btn.danger { background:#a8323b; }
.bien-row { display:grid; grid-template-columns:32px 120px 1fr 1fr 130px 100px 130px; gap:10px; padding:10px 14px;
    border-bottom:1px solid #f0ece6; align-items:center; font-size:12px; background:#fafafa; border-radius:6px; margin-bottom:6px; }
.bien-row .ref { font-family:'DM Mono',monospace; font-weight:700; color:#4878a6; }
.bien-row .nb { text-align:center; font-family:'DM Mono',monospace; }
.bien-row .badge { padding:2px 7px; border-radius:99px; font-size:10px; font-weight:700; background:#f4f1ec; color:#5a5650; }
.bien-row .badge.supprime { background:#fbe9e9; color:#a8323b; }
.bien-row .badge.actif    { background:#d9f0db; color:#2d6a35; }
.action-bar { display:flex; gap:10px; align-items:center; margin-top:14px; padding:12px; background:#fef3c7; border-radius:8px; }
.action-bar strong { color:#92400e; }
</style></head><body>

<h1>🔀 Fusion de 2 biens en doublon</h1>

<div class="card">
    <h3>🔍 Recherche</h3>
    <p style="font-size:12px; color:#7a766f;">Tape une référence, désignation, adresse ou ville. Coche le bien à GARDER (cible) et clique sur les sources à fusionner.</p>
    <form method="get" class="search">
        <input type="text" name="q" placeholder="ref / désignation / adresse / ville" value="<?= htmlspecialchars($q) ?>" autofocus>
        <button class="btn">Chercher</button>
    </form>
</div>

<?php if (!empty($results)): ?>
<div class="card">
    <h3><?= count($results) ?> résultat(s)</h3>
    <p style="font-size:11.5px; color:#7a766f;">Sélectionne <strong>1 destination</strong> (radio) et <strong>1+ sources</strong> (checkbox).</p>

    <div style="display:grid; grid-template-columns:32px 120px 1fr 1fr 130px 100px 130px; gap:10px; padding:8px 14px; font-size:10px; color:#9a9690; text-transform:uppercase;">
        <div></div><div>ID / Ref</div><div>Désignation / Adresse</div><div>Propriétaire</div><div>Surface · Usage</div><div>Statut</div><div>Stats</div>
    </div>

    <?php foreach ($results as $b): ?>
    <div class="bien-row">
        <div>
            <input type="radio" name="dst" value="<?= (int)$b['id'] ?>" id="dst_<?= $b['id'] ?>" title="Garder">
            <input type="checkbox" name="src" value="<?= (int)$b['id'] ?>" id="src_<?= $b['id'] ?>" title="Fusionner">
        </div>
        <div class="ref">
            #<?= (int)$b['id'] ?><br>
            <span style="font-size:10px; color:#7a766f;"><?= htmlspecialchars((string)$b['reference_bien']) ?></span>
        </div>
        <div>
            <strong><?= htmlspecialchars((string)$b['designation']) ?></strong>
            <div style="font-size:10.5px; color:#7a766f;"><?= htmlspecialchars((string)($b['adresse_1'] ?? '—')) ?> · <?= htmlspecialchars((string)($b['code_postal'] ?? '')) ?> <?= htmlspecialchars((string)($b['ville'] ?? '')) ?></div>
        </div>
        <div><?= htmlspecialchars((string)($b['proprio'] ?? '—')) ?></div>
        <div>
            <?= $b['surface_habitable'] ? (int)$b['surface_habitable'] . ' m²' : '—' ?>
            <div style="font-size:10px; color:#7a766f;"><?= htmlspecialchars((string)($b['usage_bien'] ?? '')) ?></div>
        </div>
        <div><span class="badge <?= htmlspecialchars((string)$b['statut_bien']) ?>"><?= htmlspecialchars((string)$b['statut_bien']) ?></span></div>
        <div class="nb">
            🗓 <?= (int)$b['nb_baux'] ?> baux<br>
            📎 <?= (int)$b['nb_docs'] ?> docs
        </div>
    </div>
    <?php endforeach; ?>

    <div class="action-bar">
        <strong>Action :</strong>
        <button class="btn danger" type="button" onclick="lancerFusion()">🔀 Fusionner sélection → destination cochée</button>
        <span style="font-size:11px; color:#7a766f;">Tout sera transféré (baux, docs, leads, tiers_roles, photos). Source = soft delete.</span>
    </div>
</div>
<?php elseif ($q !== ''): ?>
    <div class="card"><p style="text-align:center; color:#9a9690;">Aucun résultat.</p></div>
<?php endif; ?>

<script>
const APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;
async function lancerFusion() {
    const dst = document.querySelector('input[name="dst"]:checked');
    const srcs = Array.from(document.querySelectorAll('input[name="src"]:checked')).map(i => parseInt(i.value, 10));
    if (!dst) { alert('Coche le bien DESTINATION (radio).'); return; }
    const dstId = parseInt(dst.value, 10);
    const sourcesToMerge = srcs.filter(id => id !== dstId);
    if (sourcesToMerge.length === 0) { alert('Coche au moins 1 bien SOURCE (checkbox) différent de la destination.'); return; }
    if (!confirm('Fusionner ' + sourcesToMerge.length + ' bien(s) [#' + sourcesToMerge.join(', #') + '] DANS le bien #' + dstId + ' ?\n\nLes sources seront soft-deletées, tous leurs liens (baux, docs, leads, photos) transférés.\n\nIrréversible sans intervention manuelle BDD.')) return;

    const results = [];
    for (const srcId of sourcesToMerge) {
        const fd = new FormData();
        fd.append('source_id', srcId);
        fd.append('destination_id', dstId);
        try {
            const res = await fetch(APP_BASE + '/api/admin_biens_merge_action.php', { method:'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                results.push('✓ #' + srcId + ' → #' + dstId + ' : ' + Object.entries(data.stats).map(([k,v]) => k + '=' + v).join(', '));
            } else {
                results.push('✗ #' + srcId + ' : ' + (data.error || '?'));
            }
        } catch (e) { results.push('✗ #' + srcId + ' (réseau)'); }
    }
    alert('Fusion terminée :\n\n' + results.join('\n\n'));
    location.reload();
}
</script>
</body></html>
