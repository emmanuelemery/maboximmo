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
if ($fProp <= 0 && count($propIds) === 1) $fProp = (int)$propIds[0];
$activePropIds = ($fProp > 0 && in_array($fProp, $propIds)) ? [$fProp] : $propIds;

$immeubles = [];
if (!empty($activePropIds)) {
    $ph = implode(',', array_fill(0, count($activePropIds), '?'));
    $where = "i.id_proprietaire IN ($ph)";
    $params = $activePropIds;
    if ($fSearch !== '') { $where .= " AND (i.nom_immeuble LIKE ? OR i.adresse_1 LIKE ? OR i.code_crg LIKE ?)"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; }

    $stmtImm = $pdo->prepare("
        SELECT i.*, p.societe AS prop_nom,
            (SELECT COUNT(*) FROM biens b WHERE b.id_immeuble = i.id) AS nb_lots,
            (SELECT COUNT(*) FROM baux ba JOIN biens b2 ON b2.id = ba.id_bien WHERE b2.id_immeuble = i.id AND ba.statut = 'actif') AS nb_baux_actifs
        FROM immeubles i
        LEFT JOIN proprietaires p ON p.id = i.id_proprietaire
        WHERE $where
        ORDER BY i.nom_immeuble
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
</style>';

ob_start();
?>

<form method="get" class="bf">
  <div><label>Propriétaire</label><select name="prop" onchange="this.form.submit()"><option value="0">— Tous —</option>
    <?php foreach ($proprietaires as $p): ?><option value="<?= $p['id_proprietaire'] ?>" <?= $fProp==$p['id_proprietaire']?'selected':'' ?>><?= h($p['societe']?:$p['label']?:$p['nom']) ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Recherche</label><input type="text" name="q" value="<?= h($fSearch) ?>" placeholder="nom, adresse, code..."></div>
  <div><button type="submit" style="padding:5px 12px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer">🔍</button></div>
</form>

<div style="font-size:13px;color:#666;margin-bottom:14px;"><?= count($immeubles) ?> immeuble(s)</div>

<div class="imm-grid">
<?php foreach ($immeubles as $im): ?>
<div class="imm-card">
    <h3><?= h($im['nom_immeuble'] ?: $im['code_crg']) ?></h3>
    <div class="addr"><?= h(trim(($im['adresse_1']??'').' '.($im['code_postal']??'').' '.($im['ville']??''))) ?></div>
    <?php if ($im['compte_gestion']): ?><div class="mandat">Mandat : <?= h($im['compte_gestion']) ?> · CRG : <?= h($im['code_crg'] ?? '') ?></div><?php endif; ?>
    <div class="stats">
        <div>🏠 <span><?= $im['nb_lots'] ?></span> lot(s)</div>
        <div>📝 <span><?= $im['nb_baux_actifs'] ?></span> bail(s) actif(s)</div>
        <div><?= h($im['prop_nom'] ?? '') ?></div>
    </div>
    <div class="actions">
        <a href="bailleur_dashboard.php?prop=<?= $fProp ?>&imm=<?= $im['id'] ?>&annee=<?= date('Y') ?>">📊 Voir le détail</a>
        <a href="bailleur_ged.php?prop=<?= (int)$im['id_proprietaire'] ?>">📁 GED</a>
    </div>
</div>
<?php endforeach; ?>
</div>

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
