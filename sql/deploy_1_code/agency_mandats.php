<?php
// agency_mandats.php — Vue liste des mandats (vue métier, pas registre)
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);

// ── Filtres ───────────────────────────────────────────────────────────────────
$f_statut  = $_GET['statut']  ?? 'actif';
$f_type    = $_GET['type']    ?? '';
$f_etab    = $role_id === 1 ? (int)($_GET['etab'] ?? 0) : $etab_id;
$f_horizon = $_GET['horizon'] ?? '';
$f_q       = trim($_GET['q'] ?? '');
$sort      = in_array($_GET['sort']??'', ['date_debut','date_fin','mandant_nom','honoraires_ht','statut']) ? $_GET['sort'] : 'date_fin';
$dir       = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

$where = ['1=1']; $params = [];
if ($f_statut && $f_statut !== 'tous') { $where[] = 'm.statut=?'; $params[] = $f_statut; }
if ($f_type) { $where[] = 'm.type_mandat=?'; $params[] = $f_type; }
if ($f_etab) { $where[] = 'm.id_etablissement=?'; $params[] = $f_etab; }
elseif ($role_id !== 1 && $etab_id) { $where[] = 'm.id_etablissement=?'; $params[] = $etab_id; }
if ($f_horizon === 'expire30') $where[] = 'm.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
if ($f_horizon === 'expire90') $where[] = 'm.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
if ($f_q) { $where[] = '(m.mandant_nom LIKE ? OR m.immeuble_txt LIKE ?)'; $l='%'.$f_q.'%'; $params[]=$l; $params[]=$l; }

$stmt = $pdo->prepare("
    SELECT m.*,
           i.nom AS imm_nom,
           e.nom AS etab_nom, NULL AS etab_sigle,
           (SELECT COUNT(*) FROM agency_mandat_avenant WHERE id_mandat=m.id) AS nb_avenants,
           (SELECT COUNT(*) FROM agency_mandat_document WHERE id_mandat=m.id) AS nb_docs
    FROM agency_mandat m
    LEFT JOIN immeubles i ON i.id = m.id_immeuble
    LEFT JOIN etablissements e ON e.id = m.id_etablissement
    WHERE ".implode(' AND ',$where)."
    ORDER BY m.$sort $dir
");
$stmt->execute($params);
$mandats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$kpiq = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(statut='actif') AS actifs,
    SUM(statut='actif' AND date_fin IS NOT NULL AND date_fin < DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND date_fin >= CURDATE()) AS expirent,
    SUM(statut='actif' AND date_fin IS NOT NULL AND date_fin < CURDATE()) AS expires,
    ROUND(SUM(CASE WHEN statut='actif' THEN honoraires_ht ELSE 0 END),2) AS ca_ht
    FROM agency_mandat")->fetch(PDO::FETCH_ASSOC);

$etabs = $role_id===1 ? $pdo->query("SELECT id, nom AS raison_sociale FROM etablissements ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC) : [];

function sBadge(string $s): string {
    $map=['actif'=>['#3a7a6a','#e8f5ee','Actif'],'suspendu'=>['#7a6830','#fff3e0','Suspendu'],'resilie'=>['#8a5040','#fdecea','Résilié'],'expire'=>['#7a6830','#fff8e1','Expiré'],'archive'=>['#808080','#f0f0f0','Archivé']];
    [$c,$bg,$l]=$map[$s]??['#808080','#f0f0f0',$s];
    return "<span style='background:$bg;color:$c;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600'>$l</span>";
}
$gb = array_filter(['statut'=>$f_statut,'type'=>$f_type,'etab'=>$f_etab,'horizon'=>$f_horizon,'q'=>$f_q]);

$layout_title   = 'Mandats';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpiq['total'].'</div><div class="ph-kpi-lbl">Total</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)$kpiq['actifs'].'</div><div class="ph-kpi-lbl">Actifs</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.(int)$kpiq['expirent'].'</div><div class="ph-kpi-lbl">&lt; 90j</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.(int)$kpiq['expires'].'</div><div class="ph-kpi-lbl">Dépassés</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.number_format((float)$kpiq['ca_ht'],0,',',' ').' €</div><div class="ph-kpi-lbl">CA HT</div></div>
';

$layout_head_actions = '
<a href="agency_registre_form.php" class="ph-btn primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Nouveau
</a>
<span class="ph-btn dispo">dispo</span>
<span class="ph-btn dispo">dispo</span>
<span class="ph-btn dispo">dispo</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.filters{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:14px;padding:13px 18px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.pill-group{display:flex;gap:5px;flex-wrap:wrap}
.pill{padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.pill.active{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);color:#3a7a6a}
.pill.warn.active{color:#7a6830}
.filter-sep{width:1px;height:22px;background:#d0ccc6}
.filters input[type=search],.filters select{height:33px;padding:0 12px;border-radius:999px;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#1a1816;outline:none}
.filters input[type=search]{width:190px}
thead th a{color:inherit;text-decoration:none}
thead th a:hover{color:#3a7a6a}
.num-badge{display:inline-block;background:linear-gradient(135deg,#4a8a7a,#3a7a6a);color:#fff;border-radius:7px;padding:2px 8px;font-family:'DM Mono',monospace;font-size:11px;font-weight:700}
.td-meta{font-size:11px;color:#8a8680;margin-top:2px}
.expire-warn{color:#7a6830;font-weight:600}
.expire-danger{color:#8a5040;font-weight:600}
.btn-icon{width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 6px var(--shadow-light,#fff);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.btn-icon:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);color:#3a7a6a}
.meta-icons{display:flex;gap:8px;font-size:11px;color:#8a8680;margin-top:2px}
.empty{padding:48px;text-align:center;color:#8a8680;font-size:13px}
</style>
EXTRACSS;

$layout_extra_js = '';

ob_start();
?>

<!-- Filtres -->
<div class="filters">
  <div class="pill-group">
    <?php foreach(['actif'=>'Actifs','suspendu'=>'Suspendus','resilie'=>'Résiliés','expire'=>'Expirés','tous'=>'Tous'] as $sv=>$sl): ?>
    <a href="?<?= http_build_query(array_merge($gb,['statut'=>$sv])) ?>" class="pill <?= $f_statut===$sv?'active':'' ?>"><?= $sl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filter-sep"></div>
  <div class="pill-group">
    <?php foreach([''  =>'Toutes échéances','expire30'=>'Expire 30j','expire90'=>'Expire 90j'] as $hv=>$hl): ?>
    <a href="?<?= http_build_query(array_merge($gb,['horizon'=>$hv])) ?>" class="pill warn <?= $f_horizon===$hv?'active':'' ?>"><?= $hl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filter-sep"></div>
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{type:this.value}})">
    <option value="">Tous types</option>
    <?php foreach(['syndic'=>'Syndic','gerance'=>'Gérance','transaction'=>'Transaction','location'=>'Location','autre'=>'Autre'] as $tv=>$tl): ?>
    <option value="<?= $tv ?>" <?= $f_type===$tv?'selected':'' ?>><?= $tl ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($role_id===1 && $etabs): ?>
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{etab:this.value}})">
    <option value="">Tous établissements</option>
    <?php foreach($etabs as $e): ?><option value="<?= $e['id'] ?>" <?= $f_etab==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <input type="search" placeholder="Mandant, immeuble…" value="<?= htmlspecialchars($f_q) ?>"
    oninput="clearTimeout(window._st);window._st=setTimeout(()=>{const p=new URLSearchParams(location.search);p.set('q',this.value);location='?'+p},400)">
</div>

<!-- Table -->
<div class="mbi-table-wrap">
  <table class="mbi-table">
    <thead>
      <tr>
        <th>N°</th>
        <th><a href="?<?= http_build_query(array_merge($gb,['sort'=>'mandant_nom','dir'=>$sort==='mandant_nom'&&$dir==='ASC'?'DESC':'ASC'])) ?>">Mandant <?= $sort==='mandant_nom'?($dir==='ASC'?'↑':'↓'):'<span style="opacity:.3">↕</span>' ?></a></th>
        <th>Immeuble</th>
        <th>Type</th>
        <th><a href="?<?= http_build_query(array_merge($gb,['sort'=>'date_debut','dir'=>$sort==='date_debut'&&$dir==='ASC'?'DESC':'ASC'])) ?>">Début</a></th>
        <th><a href="?<?= http_build_query(array_merge($gb,['sort'=>'date_fin','dir'=>$sort==='date_fin'&&$dir==='ASC'?'DESC':'ASC'])) ?>">Fin</a></th>
        <th><a href="?<?= http_build_query(array_merge($gb,['sort'=>'honoraires_ht','dir'=>$sort==='honoraires_ht'&&$dir==='ASC'?'DESC':'ASC'])) ?>">Hon. HT/an</a></th>
        <th>Statut</th>
        <th>Docs</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($mandats)): ?>
      <tr><td colspan="10"><div class="empty">Aucun mandat trouvé</div></td></tr>
    <?php else: foreach ($mandats as $m):
      $immNom = $m['imm_nom'] ?? $m['immeuble_txt'] ?? '—';
      $ddf    = $m['date_debut'] ? date('d/m/Y', strtotime($m['date_debut'])) : '—';
      $dtf    = $m['date_fin']   ? date('d/m/Y', strtotime($m['date_fin']))   : '—';
      $expClass = '';
      if ($m['date_fin'] && $m['statut']==='actif') {
          $dl = (strtotime($m['date_fin'])-time())/86400;
          if ($dl<0) $expClass='expire-danger'; elseif($dl<30) $expClass='expire-danger'; elseif($dl<90) $expClass='expire-warn';
      }
      $typeColors=['syndic'=>['#3a7a6a','#e8f5ee'],'gerance'=>['#6a5acd','#f0eeff'],'transaction'=>['#3a7a6a','#e8f5ee'],'location'=>['#7a6830','#fff3e0'],'autre'=>['#808080','#f0f0f0']];
      [$tc,$tbg]=$typeColors[$m['type_mandat']]??['#808080','#f0f0f0'];
      $typeLabels=['syndic'=>'Syndic','gerance'=>'Gérance','transaction'=>'Transaction','location'=>'Location','autre'=>'Autre'];
    ?>
      <tr>
        <td><span class="num-badge"><?= str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT) ?></span></td>
        <td>
          <div style="font-weight:600;color:#1a1816"><?= htmlspecialchars($m['mandant_nom']) ?></div>
          <?php if ($m['etab_nom']): ?><div class="td-meta"><?= htmlspecialchars($m['etab_sigle']??$m['etab_nom']) ?></div><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($immNom) ?></td>
        <td><span style="background:<?= $tbg ?>;color:<?= $tc ?>;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600"><?= $typeLabels[$m['type_mandat']]??$m['type_mandat'] ?></span></td>
        <td><?= $ddf ?></td>
        <td class="<?= $expClass ?>"><?= $dtf ?></td>
        <td style="font-family:'DM Mono',monospace;font-weight:600"><?= number_format((float)$m['honoraires_ht'],0,',',' ') ?> €</td>
        <td><?= sBadge($m['statut']) ?></td>
        <td>
          <div class="meta-icons">
            <?php if ($m['nb_avenants']): ?><span>📋 <?= $m['nb_avenants'] ?></span><?php endif; ?>
            <?php if ($m['nb_docs']): ?><span>📎 <?= $m['nb_docs'] ?></span><?php endif; ?>
          </div>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <a href="agency_registre_fiche.php?id=<?= $m['id'] ?>" class="btn-icon" title="Fiche">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
          <a href="agency_pdf_registre.php?id=<?= $m['id'] ?>" class="btn-icon" title="PDF" target="_blank">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          </a>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
