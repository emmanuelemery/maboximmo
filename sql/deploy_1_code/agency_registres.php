<?php
// agency_registres.php — Registre des mandats MaBoxImmo (layout_maboximmo)
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);

// ── Filtres GET ───────────────────────────────────────────────────────────────
$f_statut   = $_GET['statut']  ?? 'actif';   // actif|suspendu|resilie|expire|archive|tous
$f_type     = $_GET['type']    ?? '';
$f_etab     = ($role_id === 1) ? (int)($_GET['etab'] ?? 0) : $etab_id;
$f_q        = trim($_GET['q']  ?? '');
$f_horizon  = $_GET['horizon'] ?? '';  // expire30|expire90
$sort       = $_GET['sort']    ?? 'numero_registre';
$dir        = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowed_sorts = ['numero_registre','mandant_nom','date_debut','date_fin','statut','type_mandat'];
if (!in_array($sort, $allowed_sorts)) $sort = 'numero_registre';

// ── Construction requête ──────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];

if ($f_statut && $f_statut !== 'tous') {
    $where[] = 'm.statut = ?';
    $params[] = $f_statut;
}
if ($f_type) {
    $where[] = 'm.type_mandat = ?';
    $params[] = $f_type;
}
if ($f_etab) {
    $where[] = 'm.id_etablissement = ?';
    $params[] = $f_etab;
} elseif ($role_id !== 1 && $etab_id) {
    $where[] = 'm.id_etablissement = ?';
    $params[] = $etab_id;
}
if ($f_q) {
    $where[] = '(m.mandant_nom LIKE ? OR m.immeuble_txt LIKE ? OR m.mandant_representant LIKE ?)';
    $like = '%' . $f_q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($f_horizon === 'expire30') {
    $where[] = 'm.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
} elseif ($f_horizon === 'expire90') {
    $where[] = 'm.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
}

$whereStr = implode(' AND ', $where);

// ── KPIs ──────────────────────────────────────────────────────────────────────
$etabCond = '';
$etabParam = [];
if ($f_etab) { $etabCond = 'WHERE id_etablissement = ?'; $etabParam = [$f_etab]; }
elseif ($role_id !== 1 && $etab_id) { $etabCond = 'WHERE id_etablissement = ?'; $etabParam = [$etab_id]; }

$kpi = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(statut='actif') AS nb_actif,
        SUM(statut IN('resilie','expire')) AS nb_inactif,
        SUM(statut='actif' AND date_fin IS NOT NULL AND date_fin < DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND date_fin >= CURDATE()) AS nb_expire_soon,
        SUM(statut='actif' AND date_fin IS NOT NULL AND date_fin < CURDATE()) AS nb_expires
    FROM agency_mandat $etabCond
");
$kpi->execute($etabParam);
$kpi = $kpi->fetch(PDO::FETCH_ASSOC);

// ── Données ───────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT m.*,
           i.nom_immeuble AS imm_nom, i.reference_immeuble AS imm_ref,
           e.nom AS etab_nom, NULL AS etab_sigle
    FROM agency_mandat m
    LEFT JOIN immeubles i ON i.id = m.id_immeuble
    LEFT JOIN etablissements e ON e.id = m.id_etablissement
    WHERE $whereStr
    ORDER BY m.$sort $dir
");
$stmt->execute($params);
$mandats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Établissements pour filtre admin ─────────────────────────────────────────
$etabs = [];
if ($role_id === 1) {
    $etabs = $pdo->query("SELECT id, nom AS raison_sociale FROM etablissements ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function statutBadgeM(string $s): string {
    $map = [
        'actif'     => ['#3a7a6a','#e8f5ee','Actif'],
        'suspendu'  => ['#7a6830','#fff3e0','Suspendu'],
        'resilie'   => ['#8a5040','#fdecea','Résilié'],
        'expire'    => ['#7a6830','#fff8e1','Expiré'],
        'archive'   => ['#808080','#f0f0f0','Archivé'],
    ];
    [$c,$bg,$l] = $map[$s] ?? ['#808080','#f0f0f0',$s];
    return "<span style='background:$bg;color:$c;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;white-space:nowrap'>$l</span>";
}
function typeBadgeM(string $t): string {
    $map = [
        'syndic'      => ['#4878a6','#e8f0f8','Syndic'],
        'gerance'     => ['#6a5acd','#f0eeff','Gérance'],
        'transaction' => ['#3a7a6a','#e8f5ee','Transaction'],
        'location'    => ['#7a6830','#fff3e0','Location'],
        'autre'       => ['#808080','#f0f0f0','Autre'],
    ];
    [$c,$bg,$l] = $map[$t] ?? ['#808080','#f0f0f0',$t];
    return "<span style='background:$bg;color:$c;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600'>$l</span>";
}
function sortUrl(string $col, string $cur, string $dir, array $get): string {
    $params = array_merge($get, ['sort'=>$col,'dir'=> ($cur===$col && $dir==='ASC')?'DESC':'ASC']);
    return '?' . http_build_query($params);
}
function sortArrow(string $col, string $cur, string $dir): string {
    if ($col !== $cur) return '<span style="opacity:.3">↕</span>';
    return $dir === 'ASC' ? '↑' : '↓';
}
$get_base = array_filter(['statut'=>$f_statut,'type'=>$f_type,'etab'=>$f_etab,'q'=>$f_q,'horizon'=>$f_horizon]);

// ── Layout ──────────────────────────────────────────────────────────
$layout_title   = 'Registre des mandats';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['total'].'</div><div class="ph-kpi-lbl">Total</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)$kpi['nb_actif'].'</div><div class="ph-kpi-lbl">Actifs</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.(int)$kpi['nb_expire_soon'].'</div><div class="ph-kpi-lbl">Exp. 90j</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.(int)$kpi['nb_expires'].'</div><div class="ph-kpi-lbl">Passés</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['nb_inactif'].'</div><div class="ph-kpi-lbl">Résil/Exp</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($mandats).'</div><div class="ph-kpi-lbl">Affichés</div></div>
';

$layout_head_actions = '
    <a href="agency_registre_form.php" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nouveau
    </a>
    <a href="agency_registres.php" class="ph-btn">Registre</a>
    <a href="#" class="ph-btn dispo">dispo</a>
    <a href="#" class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.filters{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:14px;padding:14px 18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);margin-bottom:22px;display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.pill-group{display:flex;gap:6px;flex-wrap:wrap}
.pill{padding:5px 14px;border-radius:999px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.pill:hover{box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 4px var(--shadow-light,#fff)}
.pill.active{background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);color:#4878a6}
.pill.p-warn.active{color:#7a6830}
.filter-sep{width:1px;height:24px;background:#d0ccc6;margin:0 4px}
input[type=search],.filters select{height:34px;padding:0 12px;border-radius:999px;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;outline:none}
input[type=search]{width:200px}
.mbi-table thead th a{color:inherit;text-decoration:none}.mbi-table thead th a:hover{color:#4878a6}
.num-badge{display:inline-block;background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;border-radius:8px;padding:3px 9px;font-family:'DM Mono',monospace;font-size:12px;font-weight:700;min-width:42px;text-align:center}
.td-meta{font-size:11px;color:#9a9690;margin-top:2px}
.expire-warn{color:#7a6830;font-weight:600}
.expire-danger{color:#8a5040;font-weight:600}
.btn-icon{width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 6px var(--shadow-light,#fff);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.btn-icon:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);color:#4878a6}
.btn-icon.danger:hover{color:#8a5040}
.empty{padding:48px 20px;text-align:center;color:#9a9690}
.empty svg{width:48px;height:48px;margin-bottom:12px;opacity:.3}
</style>
EXTRACSS;

ob_start();
?>

<!-- Filtres -->
<div class="filters">
  <!-- Statut -->
  <div class="pill-group">
    <?php
    $statuts = ['actif'=>'Actifs','suspendu'=>'Suspendus','resilie'=>'Résiliés','expire'=>'Expirés','archive'=>'Archives','tous'=>'Tous'];
    foreach ($statuts as $sv => $sl):
        $active = ($f_statut === $sv) ? ' active' : '';
        $href = '?' . http_build_query(array_merge($get_base, ['statut'=>$sv,'horizon'=>'']));
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="pill<?= $active ?>"><?= $sl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filter-sep"></div>
  <!-- Horizon expiration -->
  <div class="pill-group">
    <?php
    $horizons = [''=>'Toutes échéances','expire30'=>'Expire 30j','expire90'=>'Expire 90j'];
    foreach ($horizons as $hv => $hl):
        $active = ($f_horizon === $hv) ? ' active p-warn' : '';
        $href = '?' . http_build_query(array_merge($get_base, ['horizon'=>$hv]));
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="pill<?= $active ?>"><?= $hl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filter-sep"></div>
  <!-- Type -->
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{type:this.value}})">
    <option value="">Tous types</option>
    <?php foreach (['syndic'=>'Syndic','gerance'=>'Gérance','transaction'=>'Transaction','location'=>'Location','autre'=>'Autre'] as $tv=>$tl): ?>
    <option value="<?= $tv ?>" <?= $f_type===$tv?'selected':'' ?>><?= $tl ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($role_id===1 && $etabs): ?>
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{etab:this.value}})">
    <option value="">Tous établissements</option>
    <?php foreach ($etabs as $e): ?>
    <option value="<?= $e['id'] ?>" <?= $f_etab==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <!-- Recherche -->
  <input type="search" placeholder="Recherche mandant, immeuble…" value="<?= htmlspecialchars($f_q) ?>"
    oninput="clearTimeout(window._st);window._st=setTimeout(()=>{const p=new URLSearchParams(location.search);p.set('q',this.value);location='?'+p},400)">
</div>

<!-- Tableau -->
<div class="mbi-table-wrap">
  <div class="tbl-wrap" style="overflow-x:auto">
    <table class="mbi-table">
      <thead>
        <tr>
          <th><a href="<?= htmlspecialchars(sortUrl('numero_registre',$sort,$dir,$get_base)) ?>">N° <?= sortArrow('numero_registre',$sort,$dir) ?></a></th>
          <th><a href="<?= htmlspecialchars(sortUrl('type_mandat',$sort,$dir,$get_base)) ?>">Type <?= sortArrow('type_mandat',$sort,$dir) ?></a></th>
          <th><a href="<?= htmlspecialchars(sortUrl('mandant_nom',$sort,$dir,$get_base)) ?>">Mandant <?= sortArrow('mandant_nom',$sort,$dir) ?></a></th>
          <th>Immeuble</th>
          <th><a href="<?= htmlspecialchars(sortUrl('date_debut',$sort,$dir,$get_base)) ?>">Début <?= sortArrow('date_debut',$sort,$dir) ?></a></th>
          <th><a href="<?= htmlspecialchars(sortUrl('date_fin',$sort,$dir,$get_base)) ?>">Fin <?= sortArrow('date_fin',$sort,$dir) ?></a></th>
          <th><a href="<?= htmlspecialchars(sortUrl('statut',$sort,$dir,$get_base)) ?>">Statut <?= sortArrow('statut',$sort,$dir) ?></a></th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($mandats)): ?>
        <tr><td colspan="8">
          <div class="empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg>
            <div>Aucun mandat trouvé</div>
          </div>
        </td></tr>
      <?php else: foreach ($mandats as $m):
        $immNom = $m['imm_nom'] ?? $m['immeuble_txt'] ?? '—';
        $df = $m['date_fin'] ? date('d/m/Y', strtotime($m['date_fin'])) : 'Indéfinie';
        $dd = $m['date_debut'] ? date('d/m/Y', strtotime($m['date_debut'])) : '—';
        $expiryClass = '';
        if ($m['date_fin'] && $m['statut']==='actif') {
            $daysLeft = (strtotime($m['date_fin']) - time()) / 86400;
            if ($daysLeft < 0) $expiryClass = 'expire-danger';
            elseif ($daysLeft < 30) $expiryClass = 'expire-danger';
            elseif ($daysLeft < 90) $expiryClass = 'expire-warn';
        }
      ?>
        <tr>
          <td><span class="num-badge"><?= str_pad($m['numero_registre'], 4, '0', STR_PAD_LEFT) ?></span></td>
          <td><?= typeBadgeM($m['type_mandat']) ?></td>
          <td>
            <div style="font-weight:600;color:#1a1816"><?= htmlspecialchars($m['mandant_nom']) ?></div>
            <?php if ($m['mandant_representant']): ?>
            <div class="td-meta"><?= htmlspecialchars($m['mandant_representant']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($immNom) ?></td>
          <td><?= $dd ?></td>
          <td class="<?= $expiryClass ?>"><?= $df ?></td>
          <td><?= statutBadgeM($m['statut']) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="agency_registre_fiche.php?id=<?= $m['id'] ?>" class="btn-icon" title="Ouvrir">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="agency_registre_form.php?id=<?= $m['id'] ?>" class="btn-icon" title="Modifier">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </a>
            <a href="agency_pdf_registre.php?id=<?= $m['id'] ?>" class="btn-icon" title="PDF" target="_blank">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </a>
            <?php if ($role_id===1): ?>
            <a href="?delete=<?= $m['id'] ?>" class="btn-icon danger" title="Supprimer"
               onclick="return confirm('Supprimer ce mandat du registre ?')">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';

// Suppression (code d'origine conservé tel quel)
if (isset($_GET['delete']) && $role_id === 1) {
    $del_id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM agency_mandat_avenant WHERE id_mandat=?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM agency_mandat_document WHERE id_mandat=?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM agency_mandat WHERE id=?")->execute([$del_id]);
    header('Location: agency_registres.php');
    exit;
}
?>
