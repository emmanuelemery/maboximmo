<?php
/*
 * bailleur_sci_organigramme.php — Organigramme SCI mère/fille
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo']; $userId = (int)current_user_id(); $roleId = (int)current_role_id();
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$current_page = 'bailleur_sci_organigramme';

/* ── Propriétaires accessibles ── */
$stmtProp = $pdo->prepare("
    SELECT up.id_proprietaire, up.label, up.ordre,
           p.nom, p.prenom, p.societe, p.siren, p.forme_juridique,
           p.id_proprietaire_parent, p.est_sci_mere
    FROM user_proprietaires up
    JOIN proprietaires p ON p.id = up.id_proprietaire
    WHERE up.id_user = ?
    ORDER BY up.ordre, up.label
");
$stmtProp->execute([$userId]);
$allProp = $stmtProp->fetchAll(PDO::FETCH_ASSOC);

if (empty($allProp) && in_array($roleId, [1, 7], true)) {
    $allProp = $pdo->query("SELECT id AS id_proprietaire, COALESCE(societe,CONCAT(nom,' ',prenom)) AS label, 0 AS ordre, nom, prenom, societe, siren, forme_juridique, id_proprietaire_parent, est_sci_mere FROM proprietaires WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
}

/* ── Construire l'arbre mère→filles ── */
$byId = [];
foreach ($allProp as $p) { $byId[(int)$p['id_proprietaire']] = $p; }

$tree = []; // racines (pas de parent ou parent absent)
$orphans = []; // entités dont le parent n'est pas dans la liste

foreach ($allProp as $p) {
    $parentId = (int)($p['id_proprietaire_parent'] ?? 0);
    if ($parentId <= 0 || !isset($byId[$parentId])) {
        $tree[(int)$p['id_proprietaire']] = array_merge($p, ['filles' => []]);
    }
}
foreach ($allProp as $p) {
    $parentId = (int)($p['id_proprietaire_parent'] ?? 0);
    if ($parentId > 0 && isset($tree[$parentId])) {
        $tree[$parentId]['filles'][] = $p;
    } elseif ($parentId > 0 && !isset($tree[$parentId])) {
        // Parent pas dans l'arbre → rattacher comme racine
        if (!isset($tree[(int)$p['id_proprietaire']])) {
            $tree[(int)$p['id_proprietaire']] = array_merge($p, ['filles' => []]);
        }
    }
}

/* ── Stats par propriétaire ── */
$propIds = array_column($allProp, 'id_proprietaire');
$stats = [];
if (!empty($propIds)) {
    $ph = implode(',', array_fill(0, count($propIds), '?'));
    // Nb immeubles
    $stmtI = $pdo->prepare("SELECT id_proprietaire, COUNT(*) AS nb FROM immeubles WHERE id_proprietaire IN ($ph) GROUP BY id_proprietaire");
    $stmtI->execute($propIds);
    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[(int)$r['id_proprietaire']]['immeubles'] = (int)$r['nb']; }
    // Nb lots
    $stmtL = $pdo->prepare("SELECT b.id_proprietaire, COUNT(*) AS nb FROM biens b WHERE b.id_proprietaire IN ($ph) GROUP BY b.id_proprietaire");
    $stmtL->execute($propIds);
    foreach ($stmtL->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[(int)$r['id_proprietaire']]['lots'] = (int)$r['nb']; }
    // Dernier CRG
    $stmtC = $pdo->prepare("SELECT id_proprietaire, MAX(CONCAT(annee,'-',trimestre)) AS last_crg FROM crg_trimestres WHERE id_proprietaire IN ($ph) GROUP BY id_proprietaire");
    $stmtC->execute($propIds);
    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[(int)$r['id_proprietaire']]['last_crg'] = $r['last_crg']; }
}

$layout_title = 'Organigramme SCI'; $layout_module = 'Ma Box Bailleur'; $layout_sidebar = 'sidebar_bailleur_module';
$_act = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;';
$_on = $_act.'background:#4a6038;color:#fff;border:1px solid #4a6038;';
$_off = $_act.'background:#fff;color:#555;border:1px solid #d4d7de;';
$layout_head_kpis = '<div style="display:flex;gap:10px;justify-content:center;flex:1;">
    <a href="bailleur_dashboard.php" style="'.$_off.'">📊 Dashboard</a>
    <a href="bailleur_immeubles.php" style="'.$_off.'">🏢 Immeubles</a>
    <a href="bailleur_ged.php" style="'.$_off.'">📁 GED</a>
    <a href="bailleur_crg_audit.php" style="'.$_off.'">🔍 Audit CRG</a>
    <a href="bailleur_sci_organigramme.php" style="'.$_on.'">🏛 SCI</a>
</div>';
$layout_head_actions = '';
$layout_extra_css = '<style>
.bl{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.bl a{padding:7px 14px;background:#fff;border:1px solid #d4d7de;border-radius:9px;text-decoration:none;font-size:11px;font-weight:600;color:#555}
.bl a:hover{border-color:#4a6038;color:#4a6038}.bl a.on{background:#4a6038;color:#fff;border-color:#4a6038}
.sci-tree{display:flex;flex-direction:column;gap:16px}
.sci-card{background:#fff;border-radius:14px;padding:18px;box-shadow:3px 3px 10px rgba(0,0,0,.06),-3px -3px 8px #fff;border-left:4px solid #4878a6}
.sci-card.mere{border-left-color:#d4a843;background:linear-gradient(135deg,#fffdf5,#fff)}
.sci-card h3{font-size:15px;margin:0 0 6px}
.sci-meta{font-size:11px;color:#888;margin-bottom:8px}
.sci-stats{display:flex;gap:16px;font-size:12px}
.sci-stats span{font-weight:600}
.sci-filles{margin-left:32px;border-left:2px solid #e5e7eb;padding-left:16px;margin-top:12px;display:flex;flex-direction:column;gap:10px}
.sci-fille{background:#fff;border-radius:10px;padding:14px;box-shadow:2px 2px 6px rgba(0,0,0,.04);border-left:3px solid #4878a6}
.bb{display:inline-block;padding:2px 7px;border-radius:7px;font-size:10px;font-weight:600}
.bb-m{background:#fffbeb;color:#d4a843}.bb-f{background:#eff6ff;color:#4878a6}
</style>';

ob_start();
?>

<div class="sci-tree">
<?php if (empty($tree)): ?>
  <div style="text-align:center;padding:40px;background:#fff;border-radius:14px;">
    <div style="font-size:36px;margin-bottom:12px">🏛</div>
    <h3>Aucune entité</h3>
    <p style="color:#888;font-size:13px">Aucun propriétaire n'est associé à votre compte.</p>
  </div>
<?php endif; ?>

<?php foreach ($tree as $root):
    $pid = (int)$root['id_proprietaire'];
    $isMere = !empty($root['filles']) || (int)($root['est_sci_mere'] ?? 0) === 1;
    $st = $stats[$pid] ?? [];
?>
<div class="sci-card <?= $isMere ? 'mere' : '' ?>">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3><?= h($root['label'] ?: $root['societe'] ?: $root['nom']) ?></h3>
    <span class="bb <?= $isMere ? 'bb-m' : 'bb-f' ?>"><?= $isMere ? 'Société mère' : 'Entité' ?></span>
  </div>
  <div class="sci-meta">
    <?php if ($root['forme_juridique']): ?><?= h($root['forme_juridique']) ?> · <?php endif; ?>
    <?php if ($root['siren']): ?>SIREN <?= h($root['siren']) ?> · <?php endif; ?>
    <a href="bailleur_dashboard.php?prop=<?= $pid ?>" style="color:#4878a6">Voir dashboard →</a>
  </div>
  <div class="sci-stats">
    <div>🏢 <span><?= $st['immeubles'] ?? 0 ?></span> immeuble(s)</div>
    <div>🏠 <span><?= $st['lots'] ?? 0 ?></span> lot(s)</div>
    <div>📊 Dernier CRG : <span><?= h($st['last_crg'] ?? '—') ?></span></div>
  </div>

  <?php if (!empty($root['filles'])): ?>
  <div class="sci-filles">
    <?php foreach ($root['filles'] as $fille):
        $fpid = (int)$fille['id_proprietaire'];
        $fst = $stats[$fpid] ?? [];
    ?>
    <div class="sci-fille">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong><?= h($fille['label'] ?: $fille['societe'] ?: $fille['nom']) ?></strong>
        <span class="bb bb-f">Fille</span>
      </div>
      <div class="sci-meta">
        <?php if ($fille['forme_juridique']): ?><?= h($fille['forme_juridique']) ?> · <?php endif; ?>
        <?php if ($fille['siren']): ?>SIREN <?= h($fille['siren']) ?><?php endif; ?>
        · <a href="bailleur_dashboard.php?prop=<?= $fpid ?>" style="color:#4878a6">Dashboard →</a>
      </div>
      <div class="sci-stats">
        <div>🏢 <span><?= $fst['immeubles'] ?? 0 ?></span> imm.</div>
        <div>🏠 <span><?= $fst['lots'] ?? 0 ?></span> lots</div>
        <div>📊 <?= h($fst['last_crg'] ?? '—') ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
