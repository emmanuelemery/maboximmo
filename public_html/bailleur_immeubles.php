<?php
/*
 * bailleur_immeubles.php — Liste des immeubles du bailleur
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo']; $userId = (int)current_user_id(); $roleId = (int)current_role_id();
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }

$current_page = 'bailleur_immeubles';

/* ── Propriétaires accessibles ── */
$stmtProp = $pdo->prepare("SELECT up.id_proprietaire, up.label, p.nom, p.societe FROM user_proprietaires up JOIN proprietaires p ON p.id = up.id_proprietaire WHERE up.id_user = ? ORDER BY up.ordre");
$stmtProp->execute([$userId]);
$proprietaires = $stmtProp->fetchAll(PDO::FETCH_ASSOC);
$propIds = array_column($proprietaires, 'id_proprietaire');
if (empty($propIds) && in_array($roleId, [1, 7], true)) {
    $proprietaires = $pdo->query("SELECT id AS id_proprietaire, COALESCE(societe,CONCAT(nom,' ',prenom)) AS label, nom, societe FROM proprietaires WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $propIds = array_column($proprietaires, 'id_proprietaire');
}

$fProp = isset($_GET['prop']) ? (int)$_GET['prop'] : 0;
$fSearch = trim($_GET['q'] ?? '');
$fStatut = in_array($_GET['statut'] ?? '', ['actif','vendu','archive','tous'], true) ? $_GET['statut'] : 'actif';
if ($fProp <= 0 && count($propIds) === 1) $fProp = (int)$propIds[0];
$activePropIds = ($fProp > 0 && in_array($fProp, $propIds)) ? [$fProp] : $propIds;

// Comptage par statut (pour pills)
$counts = ['actif' => 0, 'vendu' => 0, 'archive' => 0, 'tous' => 0];
if (!empty($activePropIds)) {
    $ph0 = implode(',', array_fill(0, count($activePropIds), '?'));
    $st = $pdo->prepare("SELECT statut_immeuble, COUNT(*) AS n FROM immeubles WHERE id_proprietaire IN ($ph0) GROUP BY statut_immeuble");
    $st->execute($activePropIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = $row['statut_immeuble'] ?: 'actif';
        if (isset($counts[$k])) $counts[$k] = (int)$row['n'];
        $counts['tous'] += (int)$row['n'];
    }
}

// Flash
$flash = '';
if (!empty($_GET['flash'])) {
    [$kind, $msg] = explode('|', (string)$_GET['flash'], 2) + [null, null];
    if ($msg) $flash = ['kind' => $kind, 'msg' => $msg];
}

$immeubles = [];
if (!empty($activePropIds)) {
    $ph = implode(',', array_fill(0, count($activePropIds), '?'));
    $where = "i.id_proprietaire IN ($ph)";
    $params = $activePropIds;
    if ($fStatut !== 'tous') {
        if ($fStatut === 'actif') {
            $where .= " AND (i.statut_immeuble = 'actif' OR i.statut_immeuble IS NULL OR i.statut_immeuble = '')";
        } else {
            $where .= " AND i.statut_immeuble = ?";
            $params[] = $fStatut;
        }
    }
    if ($fSearch !== '') { $where .= " AND (i.nom_immeuble LIKE ? OR i.adresse_1 LIKE ? OR i.code_crg LIKE ?)"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; }

    $stmtImm = $pdo->prepare("
        SELECT i.*, p.societe AS prop_nom,
            (SELECT COUNT(*) FROM biens b WHERE b.id_immeuble = i.id) AS nb_lots,
            (SELECT COUNT(*) FROM baux ba JOIN biens b2 ON b2.id = ba.id_bien WHERE b2.id_immeuble = i.id AND ba.statut = 'actif') AS nb_baux_actifs
        FROM immeubles i
        LEFT JOIN proprietaires p ON p.id = i.id_proprietaire
        WHERE $where
        ORDER BY CASE WHEN i.statut_immeuble IN ('vendu','archive') THEN 1 ELSE 0 END, i.nom_immeuble
    ");
    $stmtImm->execute($params);
    $immeubles = $stmtImm->fetchAll(PDO::FETCH_ASSOC);
}

/* ── Layout ── */
$layout_title = 'Immeubles Bailleur'; $layout_module = 'Ma Box Bailleur'; $layout_sidebar = 'sidebar_bailleur';
$_act = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;';
$_on = $_act.'background:#4a6038;color:#fff;border:1px solid #4a6038;';
$_off = $_act.'background:#fff;color:#555;border:1px solid #d4d7de;';
$layout_head_kpis = '<div style="display:flex;gap:10px;justify-content:center;flex:1;">
    <a href="bailleur_dashboard.php?prop='.$fProp.'" style="'.$_off.'">📊 Dashboard</a>
    <a href="bailleur_immeubles.php?prop='.$fProp.'" style="'.$_on.'">🏢 Immeubles</a>
    <a href="bailleur_ged.php?prop='.$fProp.'" style="'.$_off.'">📁 GED</a>
    <a href="bailleur_crg_audit.php?prop='.$fProp.'" style="'.$_off.'">🔍 Audit CRG</a>
    <a href="bailleur_sci_organigramme.php" style="'.$_off.'">🏛 SCI</a>
</div>';
$layout_head_actions = '';

$layout_extra_css = '<style>
.bf{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:12px 16px;background:#fff;border-radius:12px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bf select,.bf input{padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px}
.bf label{font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px}
.imm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.imm-card{background:#fff;border-radius:14px;padding:18px;box-shadow:3px 3px 10px rgba(0,0,0,.06),-3px -3px 8px #fff;border-left:4px solid #4878a6;transition:transform .15s}
.imm-card:hover{transform:translateY(-2px)}
.imm-card h3{font-size:14px;margin:0 0 4px;color:#333}
.imm-card .addr{font-size:11px;color:#888;margin-bottom:8px}
.imm-card .stats{display:flex;gap:14px;font-size:12px;margin-bottom:8px}
.imm-card .stats span{font-weight:600}
.imm-card .mandat{font-family:"DM Mono",monospace;font-size:10px;color:#4878a6;margin-bottom:8px}
.imm-card .actions{display:flex;gap:6px}
.imm-card .actions a{font-size:11px;padding:4px 10px;border-radius:6px;border:1px solid #d4d7de;text-decoration:none;color:#555;font-weight:600}
.imm-card .actions a:hover{border-color:#4a6038;color:#4a6038}
.bb{display:inline-block;padding:2px 7px;border-radius:7px;font-size:10px;font-weight:600}
.bb-ok{background:#f0fdf4;color:#16a34a}.bb-w{background:#fffbeb;color:#d97706}
.bb-vendu{background:#fef3c7;color:#92400e}.bb-arc{background:#e5e7eb;color:#555}
.imm-card.vendu,.imm-card.archive{border-left-color:#9a9690;opacity:.75}
.pills{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.pill{padding:5px 12px;border-radius:999px;background:#fff;border:1px solid #d4d7de;font-size:11.5px;font-weight:600;color:#555;text-decoration:none;cursor:pointer}
.pill.active{background:#4a6038;color:#fff;border-color:#4a6038}
.imm-card .actions button{font-size:11px;padding:4px 10px;border-radius:6px;border:1px solid #d4d7de;background:#fff;color:#555;font-weight:600;cursor:pointer}
.imm-card .actions button.sell{border-color:#d97706;color:#d97706}.imm-card .actions button.arc{border-color:#9a9690}.imm-card .actions button.ra{border-color:#4a6038;color:#4a6038}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:9999}
.modal.open{display:flex}
.modal-box{background:#fff;border-radius:14px;padding:24px 28px;max-width:420px;width:92%}
.modal-box h3{margin:0 0 10px;color:#333;font-size:16px}
.modal-box label{display:block;font-size:11px;font-weight:600;color:#666;margin:10px 0 4px}
.modal-box input,.modal-box textarea{width:100%;padding:8px 10px;border:1px solid #d4d7de;border-radius:8px;font-size:13px;box-sizing:border-box}
.modal-box .btns{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.modal-box button{padding:8px 16px;border-radius:8px;border:none;font-size:12px;font-weight:600;cursor:pointer}
.modal-box .cancel{background:#eee;color:#555}.modal-box .go{background:#4a6038;color:#fff}.modal-box .go.dangr{background:#d97706}
</style>';

ob_start();
?>

<?php if (!empty($flash)): ?>
<div style="padding:10px 16px;border-radius:10px;margin-bottom:14px;background:<?= $flash['kind']==='ok'?'#ecfdf5':'#fef2f2' ?>;border-left:4px solid <?= $flash['kind']==='ok'?'#16a34a':'#b4443a' ?>;font-size:13px;color:#333;">
    <?= $flash['kind']==='ok'?'✓ ':'⚠ ' ?><?= h($flash['msg']) ?>
</div>
<?php endif; ?>

<form method="get" class="bf">
  <div><label>Propriétaire</label><select name="prop" onchange="this.form.submit()"><option value="0">— Tous —</option>
    <?php foreach ($proprietaires as $p): ?><option value="<?= $p['id_proprietaire'] ?>" <?= $fProp==$p['id_proprietaire']?'selected':'' ?>><?= h($p['societe']?:$p['label']?:$p['nom']) ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Recherche</label><input type="text" name="q" value="<?= h($fSearch) ?>" placeholder="nom, adresse, code..."></div>
  <input type="hidden" name="statut" value="<?= h($fStatut) ?>">
  <div><button type="submit" style="padding:5px 12px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer">🔍</button></div>
</form>

<!-- Pills statut -->
<div class="pills">
    <a class="pill <?= $fStatut==='actif'?'active':'' ?>"   href="?<?= h(http_build_query(array_merge($_GET, ['statut'=>'actif']))) ?>">En portefeuille (<?= $counts['actif'] ?>)</a>
    <a class="pill <?= $fStatut==='vendu'?'active':'' ?>"   href="?<?= h(http_build_query(array_merge($_GET, ['statut'=>'vendu']))) ?>">🔑 Vendus (<?= $counts['vendu'] ?>)</a>
    <a class="pill <?= $fStatut==='archive'?'active':'' ?>" href="?<?= h(http_build_query(array_merge($_GET, ['statut'=>'archive']))) ?>">🗄 Archivés (<?= $counts['archive'] ?>)</a>
    <a class="pill <?= $fStatut==='tous'?'active':'' ?>"    href="?<?= h(http_build_query(array_merge($_GET, ['statut'=>'tous']))) ?>">Tous (<?= $counts['tous'] ?>)</a>
</div>

<div style="font-size:13px;color:#666;margin-bottom:14px;">
    <?= count($immeubles) ?> immeuble(s) affiché(s)
    <?php if (count($immeubles) > 0): ?>
        · <a href="#" onclick="document.querySelectorAll('.imm-card details').forEach(d=>d.open=true); return false;" style="color:#4a6038;">Tout déplier</a>
        · <a href="#" onclick="document.querySelectorAll('.imm-card details').forEach(d=>d.open=false); return false;" style="color:#4a6038;">Tout replier</a>
    <?php endif; ?>
</div>

<div class="imm-grid">
<?php foreach ($immeubles as $im):
    $statut = $im['statut_immeuble'] ?: 'actif';
    $isVendu = ($statut === 'vendu');
    $isArc = ($statut === 'archive');
?>
<div class="imm-card <?= h($statut) ?>">
    <details>
        <summary style="cursor:pointer; list-style:none; outline:none;">
            <h3 style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                <span><?= h($im['nom_immeuble'] ?: $im['code_crg']) ?></span>
                <span style="font-weight:normal;">
                    <?php if ($isVendu): ?><span class="bb bb-vendu">VENDU<?php if ($im['date_vente']): ?> · <?= date('m/Y', strtotime((string)$im['date_vente'])) ?><?php endif; ?></span>
                    <?php elseif ($isArc): ?><span class="bb bb-arc">ARCHIVÉ</span>
                    <?php endif; ?>
                </span>
            </h3>
            <div class="stats" style="margin-bottom:0;">
                <div>🏠 <span><?= $im['nb_lots'] ?></span> lot(s)</div>
                <div>📝 <span><?= $im['nb_baux_actifs'] ?></span> bail(s) actif(s)</div>
                <div><?= h($im['prop_nom'] ?? '') ?></div>
            </div>
        </summary>
        <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #eee;">
            <div class="addr"><?= h(trim(($im['adresse_1']??'').' '.($im['code_postal']??'').' '.($im['ville']??''))) ?></div>
            <?php if ($im['compte_gestion']): ?><div class="mandat">Mandat : <?= h($im['compte_gestion']) ?> · CRG : <?= h($im['code_crg'] ?? '') ?></div><?php endif; ?>
            <?php if ($isVendu && $im['prix_vente']): ?>
                <div style="background:#fef3c7; border-radius:6px; padding:6px 10px; margin:6px 0; font-size:12px;">
                    💰 Vendu <?= $im['date_vente'] ? 'le ' . date('d/m/Y', strtotime((string)$im['date_vente'])) : '' ?> — prix : <strong><?= fmt((float)$im['prix_vente']) ?></strong>
                </div>
            <?php endif; ?>
            <?php if ($isArc && $im['motif_archivage']): ?>
                <div style="background:#e5e7eb; border-radius:6px; padding:6px 10px; margin:6px 0; font-size:12px; color:#555;">
                    🗄 <?= h($im['motif_archivage']) ?>
                </div>
            <?php endif; ?>
            <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
                <a href="bailleur_dashboard.php?prop=<?= $fProp ?>&imm=<?= $im['id'] ?>&annee=<?= date('Y') ?>">📊 Détail</a>
                <a href="bailleur_ged.php?prop=<?= (int)$im['id_proprietaire'] ?>">📁 GED</a>
                <?php if ($statut === 'actif'): ?>
                    <button type="button" class="sell" onclick="openModal('sell', <?= (int)$im['id'] ?>, <?= htmlspecialchars(json_encode($im['nom_immeuble'] ?: 'Immeuble #'.$im['id']), ENT_QUOTES) ?>)">🔑 Marquer vendu</button>
                    <button type="button" class="arc"  onclick="openModal('arc',  <?= (int)$im['id'] ?>, <?= htmlspecialchars(json_encode($im['nom_immeuble'] ?: 'Immeuble #'.$im['id']), ENT_QUOTES) ?>)">🗄 Archiver</button>
                <?php else: ?>
                    <form method="post" action="bailleur_immeuble_action.php" style="display:inline;">
                        <input type="hidden" name="_csrf_token" value="<?= h(function_exists('csrf_token') ? csrf_token() : '') ?>">
                        <input type="hidden" name="id" value="<?= (int)$im['id'] ?>">
                        <input type="hidden" name="action" value="reactiver">
                        <button type="submit" class="ra">↩ Réactiver</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </details>
</div>
<?php endforeach; ?>
</div>

<!-- Modal Vendre -->
<div class="modal" id="modal-sell">
    <div class="modal-box">
        <h3>🔑 Marquer l'immeuble comme vendu</h3>
        <p style="color:#666; font-size:12px; margin:0 0 10px;"><strong id="sell-nom"></strong></p>
        <form method="post" action="bailleur_immeuble_action.php">
            <input type="hidden" name="_csrf_token" value="<?= h(function_exists('csrf_token') ? csrf_token() : '') ?>">
            <input type="hidden" name="action" value="vendre">
            <input type="hidden" name="id" id="sell-id" value="">
            <label>Prix de vente (€)</label>
            <input type="number" step="0.01" name="prix_vente" required>
            <label>Date de vente</label>
            <input type="date" name="date_vente" value="<?= date('Y-m-d') ?>" required>
            <div class="btns">
                <button type="button" class="cancel" onclick="closeModal()">Annuler</button>
                <button type="submit" class="go dangr">Confirmer la vente</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Archiver -->
<div class="modal" id="modal-arc">
    <div class="modal-box">
        <h3>🗄 Archiver l'immeuble</h3>
        <p style="color:#666; font-size:12px; margin:0 0 10px;"><strong id="arc-nom"></strong></p>
        <form method="post" action="bailleur_immeuble_action.php">
            <input type="hidden" name="_csrf_token" value="<?= h(function_exists('csrf_token') ? csrf_token() : '') ?>">
            <input type="hidden" name="action" value="archiver">
            <input type="hidden" name="id" id="arc-id" value="">
            <label>Motif d'archivage</label>
            <textarea name="motif_archivage" rows="3" placeholder="Fin de mandat, démolition, changement de gérance..."></textarea>
            <div class="btns">
                <button type="button" class="cancel" onclick="closeModal()">Annuler</button>
                <button type="submit" class="go">Archiver</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(kind, id, nom) {
    const m = document.getElementById('modal-' + kind);
    if (!m) return;
    document.getElementById(kind + '-id').value = id;
    document.getElementById(kind + '-nom').textContent = nom;
    m.classList.add('open');
}
function closeModal() {
    document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
}
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

<?php if (empty($immeubles)): ?>
<div style="text-align:center;padding:40px;background:#fff;border-radius:14px;">
    <div style="font-size:36px;margin-bottom:12px">🏢</div>
    <h3>Aucun immeuble</h3>
    <p style="color:#888;font-size:13px">Importez un CRG pour créer automatiquement vos immeubles.</p>
</div>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
