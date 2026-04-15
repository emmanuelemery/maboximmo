<?php
// agency_contrats.php — Contrats fournisseurs / prestataires / assurances
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

// ── Actions rapides ───────────────────────────────────────────────────────────
if (isset($_GET['delete']) && $role_id === 1) {
    $pdo->prepare("DELETE FROM agency_contrat WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: agency_contrats.php'); exit;
}

// ── Ajout / édition inline (POST) ────────────────────────────────────────────
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = null;
if ($edit_id) {
    $edit_row = $pdo->prepare("SELECT * FROM agency_contrat WHERE id=?");
    $edit_row->execute([$edit_id]);
    $edit_row = $edit_row->fetch(PDO::FETCH_ASSOC);
}

$form_errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contrat'])) {
    $data = [
        'type_contrat'     => $_POST['type_contrat']    ?? 'autre',
        'fournisseur'      => trim($_POST['fournisseur'] ?? ''),
        'objet'            => trim($_POST['objet']       ?? ''),
        'id_immeuble'      => (int)($_POST['id_immeuble'] ?? 0) ?: null,
        'montant_ht'       => str_replace(',','.', $_POST['montant_ht'] ?? '0'),
        'periodicite'      => $_POST['periodicite']     ?? 'annuel',
        'date_debut'       => $_POST['date_debut']      ?? '',
        'date_fin'         => ($_POST['date_fin']        ?? '') ?: null,
        'tacite'           => isset($_POST['tacite']) ? 1 : 0,
        'preavis_mois'     => (int)($_POST['preavis_mois'] ?? 0),
        'statut'           => $_POST['statut']          ?? 'actif',
        'contact_nom'      => trim($_POST['contact_nom'] ?? ''),
        'contact_tel'      => trim($_POST['contact_tel'] ?? ''),
        'contact_email'    => trim($_POST['contact_email'] ?? ''),
        'notes'            => trim($_POST['notes']       ?? ''),
        'id_etablissement' => $role_id===1 ? ((int)($_POST['id_etablissement']??0)?:null) : ($etab_id?:null),
        'id_createur'      => $user_id,
    ];
    if (!$data['fournisseur']) $form_errors[] = 'Fournisseur obligatoire.';
    if (!$data['date_debut'])  $form_errors[] = 'Date de début obligatoire.';

    if (empty($form_errors)) {
        $cid = (int)($_POST['contrat_id'] ?? 0);
        if ($cid) {
            $sql = "UPDATE agency_contrat SET type_contrat=:type_contrat,fournisseur=:fournisseur,objet=:objet,id_immeuble=:id_immeuble,montant_ht=:montant_ht,periodicite=:periodicite,date_debut=:date_debut,date_fin=:date_fin,tacite=:tacite,preavis_mois=:preavis_mois,statut=:statut,contact_nom=:contact_nom,contact_tel=:contact_tel,contact_email=:contact_email,notes=:notes,id_etablissement=:id_etablissement WHERE id=$cid";
        } else {
            $sql = "INSERT INTO agency_contrat (type_contrat,fournisseur,objet,id_immeuble,montant_ht,periodicite,date_debut,date_fin,tacite,preavis_mois,statut,contact_nom,contact_tel,contact_email,notes,id_etablissement,id_createur) VALUES (:type_contrat,:fournisseur,:objet,:id_immeuble,:montant_ht,:periodicite,:date_debut,:date_fin,:tacite,:preavis_mois,:statut,:contact_nom,:contact_tel,:contact_email,:notes,:id_etablissement,:id_createur)";
        }
        $pdo->prepare($sql)->execute($data);
        header('Location: agency_contrats.php'); exit;
    }
}

// ── Filtres ───────────────────────────────────────────────────────────────────
$f_statut  = $_GET['statut']  ?? 'actif';
$f_type    = $_GET['type']    ?? '';
$f_etab    = $role_id===1 ? (int)($_GET['etab']??0) : $etab_id;
$f_q       = trim($_GET['q'] ?? '');
$f_horizon = $_GET['horizon'] ?? '';

$where = ['1=1']; $params = [];
if ($f_statut && $f_statut !== 'tous') { $where[] = 'c.statut=?'; $params[] = $f_statut; }
if ($f_type) { $where[] = 'c.type_contrat=?'; $params[] = $f_type; }
if ($f_etab) { $where[] = 'c.id_etablissement=?'; $params[] = $f_etab; }
elseif ($role_id!==1 && $etab_id) { $where[] = 'c.id_etablissement=?'; $params[] = $etab_id; }
if ($f_horizon==='expire30') $where[] = 'c.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)';
if ($f_horizon==='expire90') $where[] = 'c.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)';
if ($f_q) { $where[]='(c.fournisseur LIKE ? OR c.objet LIKE ?)'; $l='%'.$f_q.'%'; $params[]=$l; $params[]=$l; }

$stmt = $pdo->prepare("SELECT c.*, i.nom AS imm_nom FROM agency_contrat c LEFT JOIN immeubles i ON i.id=c.id_immeuble WHERE ".implode(' AND ',$where)." ORDER BY c.date_fin ASC, c.fournisseur ASC");
$stmt->execute($params);
$contrats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$immeubles = $pdo->query("SELECT id,nom FROM immeubles WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$etabs     = $role_id===1 ? $pdo->query("SELECT id, nom AS raison_sociale FROM etablissements ORDER BY raison_sociale")->fetchAll(PDO::FETCH_ASSOC) : [];

$kpi = $pdo->query("SELECT COUNT(*) AS total, SUM(statut='actif') AS actifs,
    SUM(statut='actif' AND date_fin IS NOT NULL AND date_fin < DATE_ADD(CURDATE(),INTERVAL 90 DAY) AND date_fin>=CURDATE()) AS expirent,
    ROUND(SUM(CASE WHEN statut='actif' AND periodicite='annuel' THEN montant_ht WHEN statut='actif' AND periodicite='mensuel' THEN montant_ht*12 ELSE 0 END),2) AS engage_ht
    FROM agency_contrat")->fetch(PDO::FETCH_ASSOC);

$typeColors=['maintenance'=>['#3a7a6a','#e8f5ee'],'assurance'=>['#3a7a6a','#e8f5ee'],'nettoyage'=>['#7a6830','#fff3e0'],'ascenseur'=>['#6a5acd','#f0eeff'],'espaces_verts'=>['#3a7a6a','#e8f5ee'],'securite'=>['#8a5040','#fdecea'],'energie'=>['#7a6830','#fff3e0'],'telecom'=>['#3a7a6a','#e8f0f8'],'juridique'=>['#9a5090','#f8eeff'],'autre'=>['#808080','#f0f0f0']];
$typeLabels=['maintenance'=>'Maintenance','assurance'=>'Assurance','nettoyage'=>'Nettoyage','ascenseur'=>'Ascenseur','espaces_verts'=>'Espaces verts','securite'=>'Sécurité','energie'=>'Énergie','telecom'=>'Télécom','juridique'=>'Juridique','autre'=>'Autre'];

$gb = array_filter(['statut'=>$f_statut,'type'=>$f_type,'etab'=>$f_etab,'horizon'=>$f_horizon,'q'=>$f_q]);
$v  = fn($k,$d='') => htmlspecialchars($edit_row[$k] ?? $_POST[$k] ?? $d);

$layout_title   = 'Contrats';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">'.(int)$kpi['total'].'</div><div class="ph-kpi-lbl">Total</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.(int)$kpi['actifs'].'</div><div class="ph-kpi-lbl">Actifs</div></div>
<div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.(int)$kpi['expirent'].'</div><div class="ph-kpi-lbl">&lt; 90j</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">'.number_format((float)$kpi['engage_ht'],0,',',' ').' €</div><div class="ph-kpi-lbl">Engagé/an</div></div>
';

$layout_head_actions = '
<a href="?edit=0" class="ph-btn primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Nouveau
</a>
<span class="ph-btn dispo">dispo</span>
<span class="ph-btn dispo">dispo</span>
<span class="ph-btn dispo">dispo</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.layout-ct{display:grid;grid-template-columns:1fr 360px;gap:22px;align-items:start}
.filters{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:14px;padding:13px 18px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.pill{padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.pill.active{box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);color:#3a7a6a}
.pill.warn.active{color:#7a6830}
.pill-group{display:flex;gap:5px;flex-wrap:wrap}
.filter-sep{width:1px;height:22px;background:#d0ccc6}
.filters input[type=search],.filters select{height:34px;padding:0 12px;border-radius:999px;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#1a1816;outline:none}
.filters input[type=search]{width:180px}
.td-meta{font-size:11px;color:#8a8680;margin-top:2px}
.expire-warn{color:#7a6830;font-weight:600}
.expire-danger{color:#8a5040;font-weight:600}
.btn-icon{width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 6px var(--shadow-light,#fff);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#6a6864;text-decoration:none;transition:box-shadow .15s}
.btn-icon:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);color:#3a7a6a}
.btn-icon.danger:hover{color:#8a5040}
.empty{padding:40px;text-align:center;color:#8a8680;font-size:13px}
.form-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:20px 22px;position:sticky;top:10px}
.form-card h3{font-size:13px;font-weight:700;color:#8a5040;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid #e4e6ec}
.fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
.fg label{font-size:11px;font-weight:600;color:#7a6830}
.fg input,.fg select,.fg textarea{height:auto;padding:8px 12px;border-radius:8px;width:100%;border:none;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 7px var(--shadow-dark,#d4d7de),inset -3px -3px 6px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#1a1816;outline:none}
.fg textarea{min-height:60px;resize:vertical;border-radius:8px}
.fg-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fg-check{display:flex;align-items:center;gap:6px;font-size:12px;color:#4a4844;margin-bottom:12px}
.fg-check input[type=checkbox]{width:16px;height:16px;accent-color:#3a7a6a;box-shadow:none}
.btn-save{width:100%;padding:10px;border-radius:999px;background:linear-gradient(135deg,#4a8a7a,#3a7a6a);color:#fff;font-family:'Sora',sans-serif;font-size:13px;font-weight:700;border:none;cursor:pointer;box-shadow:3px 4px 12px rgba(58,122,106,.3)}
.btn-cancel-form{width:100%;padding:9px;border-radius:999px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;border:none;cursor:pointer;text-decoration:none;text-align:center;display:block;margin-top:6px}
.alert-error{background:#fdecea;border-radius:8px;padding:10px 14px;font-size:12px;color:#8a5040;margin-bottom:12px}
</style>
EXTRACSS;

$layout_extra_js = '';

ob_start();
?>

<!-- Filtres -->
<div class="filters">
  <div class="pill-group">
    <?php foreach(['actif'=>'Actifs','suspendu'=>'Suspendus','resilie'=>'Résiliés','tous'=>'Tous'] as $sv=>$sl): ?>
    <a href="?<?= http_build_query(array_merge($gb,['statut'=>$sv])) ?>" class="pill <?= $f_statut===$sv?'active':'' ?>"><?= $sl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filter-sep"></div>
  <div class="pill-group">
    <?php foreach([''  =>'Toutes','expire30'=>'Expire 30j','expire90'=>'Expire 90j'] as $hv=>$hl): ?>
    <a href="?<?= http_build_query(array_merge($gb,['horizon'=>$hv])) ?>" class="pill warn <?= $f_horizon===$hv?'active':'' ?>"><?= $hl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filter-sep"></div>
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{type:this.value}})">
    <option value="">Tous types</option>
    <?php foreach($typeLabels as $tv=>$tl): ?><option value="<?= $tv ?>" <?= $f_type===$tv?'selected':'' ?>><?= $tl ?></option><?php endforeach; ?>
  </select>
  <?php if ($role_id===1 && $etabs): ?>
  <select onchange="location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{etab:this.value}})">
    <option value="">Tous établissements</option>
    <?php foreach($etabs as $e): ?><option value="<?= $e['id'] ?>" <?= $f_etab==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <input type="search" placeholder="Fournisseur, objet…" value="<?= htmlspecialchars($f_q) ?>"
    oninput="clearTimeout(window._st);window._st=setTimeout(()=>{const p=new URLSearchParams(location.search);p.set('q',this.value);location='?'+p},400)">
</div>

<div class="layout-ct">
<!-- Table -->
<div>
<div class="mbi-table-wrap">
  <table class="mbi-table">
    <thead>
      <tr>
        <th>Type</th><th>Fournisseur / Objet</th><th>Immeuble</th>
        <th>Début</th><th>Fin</th><th>Montant HT</th><th>Statut</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($contrats)): ?>
      <tr><td colspan="8"><div class="empty">Aucun contrat — <a href="?edit=0" style="color:#3a7a6a">Ajouter le premier</a></div></td></tr>
    <?php else: foreach ($contrats as $c):
      [$tc,$tbg] = $typeColors[$c['type_contrat']] ?? ['#808080','#f0f0f0'];
      $df  = $c['date_debut'] ? date('d/m/Y',strtotime($c['date_debut'])) : '—';
      $dt  = $c['date_fin']   ? date('d/m/Y',strtotime($c['date_fin']))   : '—';
      $ecl = '';
      if ($c['date_fin'] && $c['statut']==='actif') {
          $dl=(strtotime($c['date_fin'])-time())/86400;
          if ($dl<0) $ecl='expire-danger'; elseif($dl<30) $ecl='expire-danger'; elseif($dl<90) $ecl='expire-warn';
      }
      $periLabels=['mensuel'=>'/mois','trimestriel'=>'/trim.','annuel'=>'/an','unique'=>'forfait'];
    ?>
      <tr>
        <td><span style="background:<?= $tbg ?>;color:<?= $tc ?>;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600;white-space:nowrap"><?= $typeLabels[$c['type_contrat']]??$c['type_contrat'] ?></span></td>
        <td>
          <div style="font-weight:600;color:#1a1816"><?= htmlspecialchars($c['fournisseur']) ?></div>
          <?php if ($c['objet']): ?><div class="td-meta"><?= htmlspecialchars($c['objet']) ?></div><?php endif; ?>
          <?php if ($c['contact_nom']): ?><div class="td-meta">✆ <?= htmlspecialchars($c['contact_nom']) ?><?= $c['contact_tel']?' · '.$c['contact_tel']:'' ?></div><?php endif; ?>
        </td>
        <td><?= $c['imm_nom'] ? htmlspecialchars($c['imm_nom']) : '<span style="color:#8a8680">—</span>' ?></td>
        <td><?= $df ?></td>
        <td class="<?= $ecl ?>">
          <?= $dt ?>
          <?php if ($c['tacite']): ?><div class="td-meta" style="color:#3a7a6a">↺ tacite</div><?php endif; ?>
        </td>
        <td style="font-family:'DM Mono',monospace;font-weight:600">
          <?= number_format((float)$c['montant_ht'],0,',',' ') ?> €
          <span style="font-size:10px;color:#8a8680"><?= $periLabels[$c['periodicite']]??'' ?></span>
        </td>
        <td>
          <?php
          $stmap=['actif'=>['#3a7a6a','#e8f5ee','Actif'],'suspendu'=>['#7a6830','#fff3e0','Suspendu'],'resilie'=>['#8a5040','#fdecea','Résilié']];
          [$sc,$sbg,$sl]=$stmap[$c['statut']]??['#808080','#f0f0f0',$c['statut']];
          ?><span style="background:<?= $sbg ?>;color:<?= $sc ?>;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600"><?= $sl ?></span>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <a href="?edit=<?= $c['id'] ?>" class="btn-icon" title="Modifier">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </a>
          <?php if ($role_id===1): ?>
          <a href="?delete=<?= $c['id'] ?>" class="btn-icon danger" onclick="return confirm('Supprimer ce contrat ?')">
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

<!-- Formulaire latéral -->
<div>
<div class="form-card">
  <h3><?= ($edit_id && $edit_row) ? 'Modifier le contrat' : '+ Nouveau contrat' ?></h3>
  <?php if ($form_errors): ?><div class="alert-error"><?= implode('<br>',$form_errors) ?></div><?php endif; ?>
  <form method="POST">
    <input type="hidden" name="save_contrat" value="1">
    <input type="hidden" name="contrat_id" value="<?= $edit_id ?>">
    <div class="fg">
      <label>Type</label>
      <select name="type_contrat">
        <?php foreach($typeLabels as $tv=>$tl): ?><option value="<?= $tv ?>" <?= $v('type_contrat','maintenance')===$tv?'selected':'' ?>><?= $tl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Fournisseur *</label>
      <input type="text" name="fournisseur" value="<?= $v('fournisseur') ?>" placeholder="Nom du prestataire">
    </div>
    <div class="fg">
      <label>Objet / description</label>
      <input type="text" name="objet" value="<?= $v('objet') ?>" placeholder="Ex. : Contrat ascenseur Schindler">
    </div>
    <div class="fg">
      <label>Immeuble</label>
      <select name="id_immeuble">
        <option value="">— Tous immeubles —</option>
        <?php foreach($immeubles as $im): ?><option value="<?= $im['id'] ?>" <?= $v('id_immeuble')==$im['id']?'selected':'' ?>><?= htmlspecialchars($im['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($role_id===1 && $etabs): ?>
    <div class="fg">
      <label>Établissement</label>
      <select name="id_etablissement">
        <option value="">—</option>
        <?php foreach($etabs as $e): ?><option value="<?= $e['id'] ?>" <?= $v('id_etablissement')==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['raison_sociale']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="fg-row">
      <div class="fg"><label>Montant HT (€)</label><input type="number" name="montant_ht" value="<?= $v('montant_ht','0') ?>" step="0.01" min="0"></div>
      <div class="fg"><label>Périodicité</label>
        <select name="periodicite">
          <?php foreach(['mensuel'=>'Mensuel','trimestriel'=>'Trimestriel','annuel'=>'Annuel','unique'=>'Forfait unique'] as $pv=>$pl): ?>
          <option value="<?= $pv ?>" <?= $v('periodicite','annuel')===$pv?'selected':'' ?>><?= $pl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="fg-row">
      <div class="fg"><label>Début *</label><input type="date" name="date_debut" value="<?= $v('date_debut',date('Y-m-d')) ?>"></div>
      <div class="fg"><label>Fin</label><input type="date" name="date_fin" value="<?= $v('date_fin') ?>"></div>
    </div>
    <div class="fg-check">
      <input type="checkbox" name="tacite" id="cb_tacite" <?= $v('tacite')=='1'?'checked':'' ?>>
      <label for="cb_tacite">Tacite reconduction</label>
    </div>
    <div class="fg-row">
      <div class="fg"><label>Préavis (mois)</label><input type="number" name="preavis_mois" value="<?= $v('preavis_mois','3') ?>" min="0" max="24"></div>
      <div class="fg"><label>Statut</label>
        <select name="statut">
          <option value="actif" <?= $v('statut','actif')==='actif'?'selected':'' ?>>Actif</option>
          <option value="suspendu" <?= $v('statut')==='suspendu'?'selected':'' ?>>Suspendu</option>
          <option value="resilie" <?= $v('statut')==='resilie'?'selected':'' ?>>Résilié</option>
        </select>
      </div>
    </div>
    <div style="font-size:11px;font-weight:700;color:#7a6830;text-transform:uppercase;letter-spacing:.07em;margin:4px 0 8px">Contact prestataire</div>
    <div class="fg"><label>Nom contact</label><input type="text" name="contact_nom" value="<?= $v('contact_nom') ?>" placeholder="Nom du responsable"></div>
    <div class="fg-row">
      <div class="fg"><label>Téléphone</label><input type="text" name="contact_tel" value="<?= $v('contact_tel') ?>"></div>
      <div class="fg"><label>E-mail</label><input type="email" name="contact_email" value="<?= $v('contact_email') ?>"></div>
    </div>
    <div class="fg"><label>Notes</label><textarea name="notes" style="border-radius:8px;height:60px"><?= $v('notes') ?></textarea></div>
    <button type="submit" class="btn-save"><?= $edit_id?'Enregistrer':'Ajouter le contrat' ?></button>
    <?php if ($edit_id): ?><a href="agency_contrats.php" class="btn-cancel-form">Annuler</a><?php endif; ?>
  </form>
</div>
</div><!-- /form col -->
</div><!-- /layout -->

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
