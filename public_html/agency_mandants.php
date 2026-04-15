<?php
// agency_mandants.php — Liste des mandants (layout_maboximmo)
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);

// ── DELETE ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && $role_id === 1) {
    $did = (int)$_GET['delete'];
    $pdo->prepare("UPDATE agency_mandat SET id_mandant=NULL WHERE id_mandant=?")->execute([$did]);
    $pdo->prepare("DELETE FROM agency_mandant WHERE id=?")->execute([$did]);
    header('Location: agency_mandants.php'); exit;
}

// ── Filtres ───────────────────────────────────────────────────────────────────
$f_type  = $_GET['type'] ?? '';
$f_etab  = $role_id === 1 ? (int)($_GET['etab'] ?? 0) : $etab_id;
$f_q     = trim($_GET['q'] ?? '');
$sort    = in_array($_GET['sort'] ?? '', ['raison_sociale','type_mandant','ville','nb_mandats']) ? $_GET['sort'] : 'raison_sociale';
$dir     = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

$where  = ['1=1']; $params = [];
if ($f_type) { $where[] = 'mn.type_mandant=?'; $params[] = $f_type; }
if ($f_etab) { $where[] = 'mn.id_etablissement=?'; $params[] = $f_etab; }
elseif ($role_id !== 1 && $etab_id) { $where[] = 'mn.id_etablissement=?'; $params[] = $etab_id; }
if ($f_q) {
    $where[] = '(mn.raison_sociale LIKE ? OR mn.representant LIKE ? OR mn.email LIKE ?)';
    $l = '%'.$f_q.'%'; $params[] = $l; $params[] = $l; $params[] = $l;
}
$whereStr = implode(' AND ', $where);

$sortCol = $sort === 'nb_mandats' ? 'nb_mandats' : 'mn.'.$sort;
$stmt = $pdo->prepare("
    SELECT mn.*,
           i.nom AS imm_nom,
           COUNT(m.id) AS nb_mandats,
           SUM(m.statut='actif') AS nb_actif
    FROM agency_mandant mn
    LEFT JOIN immeubles i ON i.id = mn.id_immeuble
    LEFT JOIN agency_mandat m ON m.id_mandant = mn.id
    WHERE $whereStr
    GROUP BY mn.id
    ORDER BY $sortCol $dir
");
$stmt->execute($params);
$mandants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$etabs = $role_id === 1
    ? $pdo->query("SELECT id, nom AS raison_sociale FROM etablissements ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$kpi = $pdo->query("SELECT COUNT(*) AS total,
    SUM(type_mandant='copropriete') AS nb_cop,
    SUM(type_mandant='proprietaire') AS nb_prop,
    SUM(type_mandant='sci') AS nb_sci
    FROM agency_mandant")->fetch(PDO::FETCH_ASSOC);

function sArr(string $col, string $cur, string $dir, array $g): string {
    $g = array_merge($g, ['sort'=>$col,'dir'=>($cur===$col&&$dir==='ASC')?'DESC':'ASC']);
    return '?'.http_build_query($g);
}
function sArr2(string $col, string $cur, string $dir): string {
    return $col===$cur ? ($dir==='ASC'?'↑':'↓') : '<span style="opacity:.3">↕</span>';
}
$gb = array_filter(['type'=>$f_type,'etab'=>$f_etab,'q'=>$f_q]);

// ── Layout ──────────────────────────────────────────────────────────
$layout_title   = 'Mandants';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['total'].'</div><div class="ph-kpi-lbl">Total</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.(int)$kpi['nb_cop'].'</div><div class="ph-kpi-lbl">Copros</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)$kpi['nb_prop'].'</div><div class="ph-kpi-lbl">Propriét.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.(int)$kpi['nb_sci'].'</div><div class="ph-kpi-lbl">SCI</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($mandants).'</div><div class="ph-kpi-lbl">Filtrés</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">-</div><div class="ph-kpi-lbl">—</div></div>
';

$layout_head_actions = '
    <a href="agency_mandant_form.php" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nouveau
    </a>
    <a href="agency_mandants.php" class="ph-btn">Liste</a>
    <a href="#" class="ph-btn dispo">dispo</a>
    <a href="#" class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.filters{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:14px;padding:13px 18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.pill-group{display:flex;gap:5px;flex-wrap:wrap}
.pill{padding:5px 14px;border-radius:999px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.pill.active{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);color:#4878a6}
.filter-sep{width:1px;height:22px;background:#d0ccc6}
input[type=search],.filters select{height:33px;padding:0 12px;border-radius:999px;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;outline:none}
input[type=search]{width:200px}
.mbi-table thead th a{color:inherit;text-decoration:none}.mbi-table thead th a:hover{color:#4878a6}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6898bf,#4878a6);display:inline-flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.type-badge{display:inline-block;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600}
.btn-icon{width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 6px var(--shadow-light,#fff);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.btn-icon:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);color:#4878a6}
.btn-icon.danger:hover{color:#8a5040}
.empty{padding:48px;text-align:center;color:#9a9690;font-size:13px}
.td-meta{font-size:11px;color:#9a9690;margin-top:2px}
</style>
EXTRACSS;

ob_start();
?>

<!-- Filtres -->
<div class="filters">
  <div class="pill-group">
    <?php foreach (['' => 'Tous', 'copropriete'=>'Copropriété','proprietaire'=>'Propriétaire','sci'=>'SCI','autre'=>'Autre'] as $tv=>$tl): ?>
    <a href="?<?= http_build_query(array_merge($gb,['type'=>$tv])) ?>" class="pill <?= $f_type===$tv?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($role_id===1 && $etabs): ?>
  <div class="filter-sep"></div>
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{etab:this.value}})">
    <option value="">Tous établissements</option>
    <?php foreach ($etabs as $e): ?><option value="<?= $e['id'] ?>" <?= $f_etab==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <div class="filter-sep"></div>
  <input type="search" placeholder="Rechercher…" value="<?= htmlspecialchars($f_q) ?>"
    oninput="clearTimeout(window._st);window._st=setTimeout(()=>{const p=new URLSearchParams(location.search);p.set('q',this.value);location='?'+p},400)">
</div>

<!-- Table -->
<div class="mbi-table-wrap">
  <table class="mbi-table">
    <thead>
      <tr>
        <th style="width:44px"></th>
        <th><a href="<?= sArr('raison_sociale',$sort,$dir,$gb) ?>">Mandant <?= sArr2('raison_sociale',$sort,$dir) ?></a></th>
        <th><a href="<?= sArr('type_mandant',$sort,$dir,$gb) ?>">Type <?= sArr2('type_mandant',$sort,$dir) ?></a></th>
        <th>Immeuble / Ville</th>
        <th>Contact</th>
        <th><a href="<?= sArr('nb_mandats',$sort,$dir,$gb) ?>">Mandats <?= sArr2('nb_mandats',$sort,$dir) ?></a></th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($mandants)): ?>
      <tr><td colspan="7"><div class="empty">Aucun mandant trouvé</div></td></tr>
    <?php else: foreach ($mandants as $mn):
      $initials = strtoupper(substr($mn['raison_sociale'],0,1).substr(explode(' ',$mn['raison_sociale'])[1]??'',0,1));
      $typeColors = ['copropriete'=>['#4878a6','#e8f0f8'],'proprietaire'=>['#3a7a6a','#e8f5ee'],'sci'=>['#6a5acd','#f0eeff'],'autre'=>['#808080','#f0f0f0']];
      [$tc,$tbg] = $typeColors[$mn['type_mandant']] ?? ['#808080','#f0f0f0'];
      $typeLabels = ['copropriete'=>'Copropriété','proprietaire'=>'Propriétaire','sci'=>'SCI','autre'=>'Autre'];
    ?>
      <tr>
        <td><div class="avatar"><?= htmlspecialchars($initials) ?></div></td>
        <td>
          <div style="font-weight:600;color:#1a1816"><?= htmlspecialchars($mn['raison_sociale']) ?></div>
          <?php if ($mn['representant']): ?><div class="td-meta"><?= htmlspecialchars($mn['representant']) ?></div><?php endif; ?>
        </td>
        <td><span class="type-badge" style="background:<?= $tbg ?>;color:<?= $tc ?>"><?= $typeLabels[$mn['type_mandant']]??$mn['type_mandant'] ?></span></td>
        <td>
          <?php if ($mn['imm_nom']): ?><div><?= htmlspecialchars($mn['imm_nom']) ?></div><?php endif; ?>
          <?php if ($mn['ville']): ?><div class="td-meta"><?= htmlspecialchars(trim(($mn['code_postal']??'').' '.$mn['ville'])) ?></div><?php endif; ?>
        </td>
        <td>
          <?php if ($mn['email']): ?><div style="font-size:12px"><a href="mailto:<?= htmlspecialchars($mn['email']) ?>" style="color:#4878a6;text-decoration:none"><?= htmlspecialchars($mn['email']) ?></a></div><?php endif; ?>
          <?php if ($mn['telephone']): ?><div class="td-meta"><?= htmlspecialchars($mn['telephone']) ?></div><?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:6px">
            <span style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:#4878a6"><?= (int)$mn['nb_mandats'] ?></span>
            <?php if ($mn['nb_actif']): ?><span style="font-size:10px;color:#3a7a6a;font-weight:600">(<?= (int)$mn['nb_actif'] ?> actif<?= $mn['nb_actif']>1?'s':'' ?>)</span><?php endif; ?>
          </div>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <a href="agency_mandant_fiche.php?id=<?= $mn['id'] ?>" class="btn-icon" title="Ouvrir">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
          <a href="agency_mandant_form.php?id=<?= $mn['id'] ?>" class="btn-icon" title="Modifier">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </a>
          <?php if ($role_id===1): ?>
          <a href="?delete=<?= $mn['id'] ?>" class="btn-icon danger" onclick="return confirm('Supprimer ce mandant ?')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
          </a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
