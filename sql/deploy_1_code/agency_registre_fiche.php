<?php
// agency_registre_fiche.php — Fiche détail d'un mandat + avenants + documents (layout_maboximmo)
require_once __DIR__ . '/inc/init.php';
require_login();

$role_id = (int)current_role_id();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: agency_registres.php'); exit; }

// ── Chargement mandat ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT m.*,
           i.nom_immeuble AS imm_nom, i.reference_immeuble AS imm_ref, i.adresse_1 AS imm_adr, i.ville AS imm_ville,
           e.nom AS etab_nom, e.adresse AS etab_adr, e.code_postal AS etab_cp,
           e.ville AS etab_ville, e.telephone AS etab_tel, e.email AS etab_email,
           e.siret AS etab_siret, NULL AS etab_sigle
    FROM agency_mandat m
    LEFT JOIN immeubles i ON i.id = m.id_immeuble
    LEFT JOIN etablissements e ON e.id = m.id_etablissement
    WHERE m.id = ?
");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { header('Location: agency_registres.php'); exit; }

// ── Avenants ──────────────────────────────────────────────────────────────────
$avenants = $pdo->prepare("
    SELECT a.*, u.nom AS u_nom, u.prenom AS u_prenom
    FROM agency_mandat_avenant a
    LEFT JOIN users u ON u.id = a.id_user
    WHERE a.id_mandat = ?
    ORDER BY a.numero
");
$avenants->execute([$id]);
$avenants = $avenants->fetchAll(PDO::FETCH_ASSOC);

// ── Documents ─────────────────────────────────────────────────────────────────
$docs = $pdo->prepare("
    SELECT d.*, u.nom AS u_nom, u.prenom AS u_prenom
    FROM agency_mandat_document d
    LEFT JOIN users u ON u.id = d.id_user
    WHERE d.id_mandat = ?
    ORDER BY d.created_at DESC
");
$docs->execute([$id]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);

// ── Actions POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Ajout avenant
    if (isset($_POST['add_avenant'])) {
        $obj  = trim($_POST['avenant_objet']   ?? '');
        $cont = trim($_POST['avenant_contenu'] ?? '');
        $date = $_POST['avenant_date']         ?? date('Y-m-d');
        if ($obj) {
            $num = count($avenants) + 1;
            $pdo->prepare("INSERT INTO agency_mandat_avenant (id_mandat,numero,date_avenant,objet,contenu,id_user) VALUES(?,?,?,?,?,?)")
                ->execute([$id, $num, $date, $obj, $cont, $user_id]);
        }
        header("Location: agency_registre_fiche.php?id=$id#avenants"); exit;
    }

    // Suppression avenant
    if (isset($_POST['del_avenant']) && $role_id <= 2) {
        $pdo->prepare("DELETE FROM agency_mandat_avenant WHERE id=? AND id_mandat=?")
            ->execute([(int)$_POST['del_avenant'], $id]);
        header("Location: agency_registre_fiche.php?id=$id#avenants"); exit;
    }

    // Upload document
    if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $type_doc = $_POST['type_doc'] ?? 'autre';
        $upDir    = __DIR__ . '/uploads/mandats/';
        if (!is_dir($upDir)) mkdir($upDir, 0755, true);
        $ext      = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
        $allowed  = ['pdf','doc','docx','jpg','jpeg','png'];
        if (in_array($ext, $allowed) && $_FILES['doc_file']['size'] <= 10*1024*1024) {
            $fname    = 'mandat_' . $id . '_' . time() . '.' . $ext;
            $dest     = $upDir . $fname;
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $dest)) {
                $pdo->prepare("INSERT INTO agency_mandat_document (id_mandat,id_user,type_doc,nom_fichier,chemin,mime_type,taille)
                    VALUES(?,?,?,?,?,?,?)")->execute([
                    $id, $user_id, $type_doc,
                    $_FILES['doc_file']['name'],
                    'uploads/mandats/' . $fname,
                    $_FILES['doc_file']['type'],
                    (int)$_FILES['doc_file']['size'],
                ]);
            }
        }
        header("Location: agency_registre_fiche.php?id=$id#documents"); exit;
    }

    // Suppression document
    if (isset($_POST['del_doc']) && $role_id <= 2) {
        $doc = $pdo->prepare("SELECT chemin FROM agency_mandat_document WHERE id=? AND id_mandat=?");
        $doc->execute([(int)$_POST['del_doc'], $id]);
        $doc = $doc->fetch(PDO::FETCH_ASSOC);
        if ($doc) {
            $path = __DIR__ . '/' . $doc['chemin'];
            if (file_exists($path)) unlink($path);
            $pdo->prepare("DELETE FROM agency_mandat_document WHERE id=?")->execute([(int)$_POST['del_doc']]);
        }
        header("Location: agency_registre_fiche.php?id=$id#documents"); exit;
    }

    // Changement statut rapide
    if (isset($_POST['change_statut']) && $role_id <= 2) {
        $new_statut = $_POST['change_statut'];
        $allowed_st = ['actif','suspendu','resilie','expire','archive'];
        if (in_array($new_statut, $allowed_st)) {
            $pdo->prepare("UPDATE agency_mandat SET statut=? WHERE id=?")->execute([$new_statut, $id]);
        }
        header("Location: agency_registre_fiche.php?id=$id"); exit;
    }
}

$saved = isset($_GET['saved']);

// ── Helpers ───────────────────────────────────────────────────────────────────
function statutBadgeF(string $s): string {
    $map = ['actif'=>['#3a7a6a','#e8f5ee','Actif'],'suspendu'=>['#7a6830','#fff3e0','Suspendu'],
            'resilie'=>['#8a5040','#fdecea','Résilié'],'expire'=>['#7a6830','#fff8e1','Expiré'],'archive'=>['#808080','#f0f0f0','Archivé']];
    [$c,$bg,$l] = $map[$s] ?? ['#808080','#f0f0f0',$s];
    return "<span style='background:$bg;color:$c;border-radius:20px;padding:4px 14px;font-size:12px;font-weight:600'>$l</span>";
}
function typeLabelF(string $t): string {
    return ['syndic'=>'Syndic','gerance'=>'Gérance','transaction'=>'Transaction','location'=>'Location','autre'=>'Autre'][$t] ?? $t;
}
function fmtEurF(float $v): string { return number_format($v,2,',',' ') . ' €'; }
function fmtDateF(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

$honorairesTtc = (float)$m['honoraires_ht'] * (1 + (float)$m['tva_pct']/100);

// Calcul jours restants
$daysLeft = null;
if ($m['date_fin'] && $m['statut'] === 'actif') {
    $daysLeft = (int)round((strtotime($m['date_fin']) - time()) / 86400);
}

// ── Layout ──────────────────────────────────────────────────────────
$layout_title   = 'Mandat N° ' . str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT);
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$_expLabel = $daysLeft === null ? '—' : ($daysLeft < 0 ? 'Expiré' : 'J-'.$daysLeft);
$_expColor = $daysLeft === null ? '#2f587d' : ($daysLeft < 30 ? '#8a5040' : ($daysLeft < 90 ? '#7a6830' : '#3a7a6a'));

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT).'</div><div class="ph-kpi-lbl">N° Reg.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.fmtEurF((float)$m['honoraires_ht']).'</div><div class="ph-kpi-lbl">Hono HT</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($avenants).'</div><div class="ph-kpi-lbl">Avenants</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($docs).'</div><div class="ph-kpi-lbl">Docs</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:'.$_expColor.'">'.$_expLabel.'</div><div class="ph-kpi-lbl">Échéance</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.htmlspecialchars(typeLabelF($m['type_mandat'])).'</div><div class="ph-kpi-lbl">Type</div></div>
';

$layout_head_actions = '
    <a href="agency_registre_form.php?id='.$id.'" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Modifier
    </a>
    <a href="agency_pdf_registre.php?id='.$id.'" class="ph-btn" target="_blank">PDF</a>
    <a href="agency_registres.php" class="ph-btn">Registre</a>
    '.($role_id===1 ? '<a href="agency_registres.php?delete='.$id.'" class="ph-btn" onclick="return confirm(\'Supprimer définitivement ce mandat ?\')">Supprimer</a>' : '<a href="#" class="ph-btn dispo">dispo</a>').'
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.fiche-grid{display:grid;grid-template-columns:1fr 260px;gap:22px;align-items:start}
.main-col{display:flex;flex-direction:column;gap:18px}
.side-col{display:flex;flex-direction:column;gap:14px;position:sticky;top:0}
.card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:22px 24px}
.card-title{font-size:11px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #e4e6ec}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.info-item label{font-size:10px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:3px}
.info-item .val{font-size:13px;color:#1a1816;font-weight:500}
.expiry-bar{border-radius:8px;padding:10px 14px;font-size:12px;font-weight:600;margin-top:10px}
.expiry-bar.warn{background:#fff3e0;color:#7a6830}
.expiry-bar.danger{background:#fdecea;color:#8a5040}
.expiry-bar.ok{background:#e8f5ee;color:#3a7a6a}
.avenant-row{border-top:1px solid #e4e6ec;padding:12px 0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.avenant-row:first-child{border-top:none}
.av-num{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#4878a6;background:#e8f0f8;border-radius:6px;padding:2px 8px;white-space:nowrap}
.av-body{flex:1}
.av-objet{font-size:13px;font-weight:600;color:#1a1816}
.av-meta{font-size:11px;color:#9a9690;margin-top:2px}
.av-content{font-size:12px;color:#6a6864;margin-top:6px;white-space:pre-line}
.doc-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #e4e6ec}
.doc-row:first-child{border-top:none}
.doc-icon{width:32px;height:32px;border-radius:8px;background:#e0dbd4;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.doc-name{font-size:13px;font-weight:600;color:#1a1816;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}
.doc-meta{font-size:11px;color:#9a9690}
.add-form{background:#e0dbd4;border-radius:12px;padding:14px;margin-top:12px;display:none}
.add-form.open{display:block}
.add-form input,.add-form textarea,.add-form select{border:none;border-radius:8px;padding:8px 12px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;outline:none;width:100%;margin-bottom:8px}
.add-form textarea{min-height:70px;resize:vertical}
.action-btn{display:flex;align-items:center;gap:8px;width:100%;padding:10px 14px;border-radius:10px;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#4a4844;text-decoration:none;transition:box-shadow .15s;margin-bottom:6px}
.action-btn:hover{box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff)}
.action-btn.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:3px 4px 12px rgba(72,120,166,.3)}
.action-btn.primary:hover{box-shadow:3px 6px 16px rgba(72,120,166,.4)}
.action-btn.danger{color:#8a5040}
.action-btn.danger:hover{color:#fff;background:linear-gradient(135deg,#a06050,#8a5040);box-shadow:2px 4px 10px rgba(138,80,64,.3)}
.stat-group{display:flex;flex-direction:column;gap:4px;margin-top:6px}
.stat-btn{padding:7px 12px;border-radius:8px;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:2px 2px 6px var(--shadow-dark,#d4d7de),-2px -2px 5px var(--shadow-light,#fff);color:#6a6864;transition:box-shadow .12s;text-align:left}
.stat-btn:hover{box-shadow:inset 2px 2px 4px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff)}
.stat-btn.active-stat{box-shadow:inset 2px 2px 5px #b0acaa,inset -2px -2px 4px var(--shadow-light,#fff);color:#4878a6;font-weight:700}
.alert-success{background:#e8f5ee;border-radius:10px;padding:10px 16px;font-size:13px;color:#3a7a6a;margin-bottom:16px}
.btn-sm{padding:5px 12px;border-radius:8px;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 4px var(--shadow-light,#fff);color:#6a6864;transition:box-shadow .12s;text-decoration:none;display:inline-block}
.btn-sm:hover{box-shadow:inset 1px 1px 4px var(--shadow-dark,#d4d7de),inset -1px -1px 3px var(--shadow-light,#fff)}
.btn-sm.primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:none}
.btn-sm.danger{color:#8a5040}
.drop-zone{border:2px dashed #b0b8c8;border-radius:10px;padding:18px;text-align:center;cursor:pointer;transition:border-color .2s;margin-top:10px}
.drop-zone:hover,.drop-zone.over{border-color:#4878a6;background:rgba(72,120,166,.04)}
.drop-zone p{font-size:12px;color:#9a9690;margin-top:6px}
@media (max-width:900px){.fiche-grid{grid-template-columns:1fr}.side-col{position:static}}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('drop_zone').classList.remove('over');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('doc_input');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.closest('form').submit();
}
</script>
EXTRAJS;

ob_start();
?>

<?php if ($saved): ?>
<div class="alert-success">✓ Mandat enregistré avec succès.</div>
<?php endif; ?>

<div class="fiche-grid">

<!-- Colonne principale -->
<div class="main-col">

  <!-- Informations générales -->
  <div class="card">
    <div class="card-title">Informations générales — <?= htmlspecialchars($m['mandant_nom']) ?> <?= statutBadgeF($m['statut']) ?></div>
    <div class="info-grid">
      <div class="info-item">
        <label>Type de mandat</label>
        <div class="val"><?= typeLabelF($m['type_mandat']) ?></div>
      </div>
      <div class="info-item">
        <label>Date d'inscription</label>
        <div class="val"><?= fmtDateF($m['date_inscription']) ?></div>
      </div>
      <div class="info-item">
        <label>Mandant / Représentant</label>
        <div class="val"><?= htmlspecialchars($m['mandant_nom']) ?></div>
        <?php if ($m['mandant_representant']): ?>
        <div style="font-size:11px;color:#9a9690"><?= htmlspecialchars($m['mandant_representant']) ?></div>
        <?php endif; ?>
      </div>
      <div class="info-item">
        <label>Immeuble</label>
        <div class="val">
          <?php
          $immNom = $m['imm_nom'] ?? $m['immeuble_txt'] ?? '—';
          echo htmlspecialchars($immNom);
          if ($m['imm_ref']) echo ' <span style="color:#9a9690;font-size:11px">('.$m['imm_ref'].')</span>';
          ?>
        </div>
      </div>
      <div class="info-item">
        <label>Date de début</label>
        <div class="val"><?= fmtDateF($m['date_debut']) ?></div>
      </div>
      <div class="info-item">
        <label>Date de fin</label>
        <div class="val"><?= $m['date_fin'] ? fmtDateF($m['date_fin']) : '<span style="color:#9a9690">Indéfinie</span>' ?></div>
      </div>
      <div class="info-item">
        <label>Renouvellement</label>
        <div class="val"><?= ['tacite'=>'Tacite reconduction','express'=>'Exprès','sans'=>'Sans renouvellement'][$m['renouvellement']??'tacite'] ?? '—' ?></div>
      </div>
      <div class="info-item">
        <label>Préavis</label>
        <div class="val"><?= $m['preavis_mois'] ?> mois</div>
      </div>
    </div>

    <?php if ($daysLeft !== null): ?>
    <div class="expiry-bar <?= $daysLeft < 0 ? 'danger' : ($daysLeft < 30 ? 'danger' : ($daysLeft < 90 ? 'warn' : 'ok')) ?>">
      <?php if ($daysLeft < 0): ?>
        ⚠ Mandat expiré depuis <?= abs($daysLeft) ?> jour<?= abs($daysLeft)>1?'s':'' ?>
      <?php elseif ($daysLeft === 0): ?>
        ⚠ Mandat expire aujourd'hui
      <?php else: ?>
        <?= $daysLeft < 90 ? '⚠' : '✓' ?> Expire dans <?= $daysLeft ?> jour<?= $daysLeft>1?'s':'' ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Honoraires -->
  <div class="card">
    <div class="card-title">Honoraires</div>
    <div class="info-grid">
      <div class="info-item">
        <label>Honoraires HT (annuels)</label>
        <div class="val" style="font-size:20px;font-weight:700;color:#4878a6"><?= fmtEurF((float)$m['honoraires_ht']) ?></div>
      </div>
      <div class="info-item">
        <label>TVA</label>
        <div class="val"><?= $m['tva_pct'] ?> %</div>
      </div>
      <div class="info-item">
        <label>Total TTC</label>
        <div class="val"><?= fmtEurF($honorairesTtc) ?></div>
      </div>
      <div class="info-item">
        <label>Mensualité HT estimée</label>
        <div class="val"><?= fmtEurF((float)$m['honoraires_ht']/12) ?></div>
      </div>
    </div>
  </div>

  <!-- Conditions particulières -->
  <?php if ($m['conditions'] || $m['observations']): ?>
  <div class="card">
    <div class="card-title">Conditions &amp; Observations</div>
    <?php if ($m['conditions']): ?>
    <div style="margin-bottom:12px">
      <div style="font-size:11px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Conditions particulières</div>
      <div style="font-size:13px;color:#2c2a28;white-space:pre-line"><?= htmlspecialchars($m['conditions']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($m['observations']): ?>
    <div>
      <div style="font-size:11px;font-weight:700;color:#4a6038;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Observations</div>
      <div style="font-size:13px;color:#6a6864;white-space:pre-line"><?= htmlspecialchars($m['observations']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($m['statut'] === 'resilie' && ($m['date_resiliation'] || $m['motif_resiliation'])): ?>
  <div class="card" style="border-left:4px solid #8a5040">
    <div class="card-title" style="color:#8a5040">Résiliation</div>
    <div class="info-grid">
      <?php if ($m['date_resiliation']): ?>
      <div class="info-item"><label>Date de résiliation</label><div class="val"><?= fmtDateF($m['date_resiliation']) ?></div></div>
      <?php endif; ?>
      <?php if ($m['motif_resiliation']): ?>
      <div class="info-item" style="grid-column:1/-1"><label>Motif</label><div class="val"><?= htmlspecialchars($m['motif_resiliation']) ?></div></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Avenants -->
  <div class="card" id="avenants">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
      <span>Avenants (<?= count($avenants) ?>)</span>
      <button class="btn-sm primary" onclick="document.getElementById('av_form').classList.toggle('open')">+ Avenant</button>
    </div>

    <form method="POST" class="add-form" id="av_form">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
        <input type="date" name="avenant_date" value="<?= date('Y-m-d') ?>" required>
        <input type="text" name="avenant_objet" placeholder="Objet de l'avenant *" required>
      </div>
      <textarea name="avenant_contenu" placeholder="Détail des modifications…"></textarea>
      <div style="display:flex;gap:8px">
        <button type="submit" name="add_avenant" class="btn-sm primary">Enregistrer</button>
        <button type="button" class="btn-sm" onclick="document.getElementById('av_form').classList.remove('open')">Annuler</button>
      </div>
    </form>

    <?php if (empty($avenants)): ?>
    <div style="padding:16px 0;text-align:center;color:#9a9690;font-size:12px">Aucun avenant</div>
    <?php else: foreach ($avenants as $av): ?>
    <div class="avenant-row">
      <span class="av-num">Av. <?= $av['numero'] ?></span>
      <div class="av-body">
        <div class="av-objet"><?= htmlspecialchars($av['objet']) ?></div>
        <div class="av-meta"><?= date('d/m/Y', strtotime($av['date_avenant'])) ?> — <?= htmlspecialchars(trim($av['u_prenom'].' '.$av['u_nom'])) ?></div>
        <?php if ($av['contenu']): ?><div class="av-content"><?= htmlspecialchars($av['contenu']) ?></div><?php endif; ?>
      </div>
      <?php if ($role_id <= 2): ?>
      <form method="POST" onsubmit="return confirm('Supprimer cet avenant ?')">
        <input type="hidden" name="del_avenant" value="<?= $av['id'] ?>">
        <button type="submit" class="btn-sm danger">✕</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Documents -->
  <div class="card" id="documents">
    <div class="card-title">Documents attachés (<?= count($docs) ?>)</div>

    <form method="POST" enctype="multipart/form-data">
      <select name="type_doc" style="width:auto;display:inline-block;padding:6px 10px;font-size:12px;border:none;border-radius:8px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 2px 2px 5px var(--shadow-dark,#d4d7de),inset -2px -2px 4px var(--shadow-light,#fff);margin-bottom:8px;outline:none">
        <optgroup label="Mandat">
          <option value="mandat_signe">Mandat signé</option>
          <option value="avenant">Avenant signé</option>
          <option value="resiliation">Résiliation / congé</option>
        </optgroup>
        <optgroup label="Propriété &amp; bien">
          <option value="attestation_propriete">Attestation de propriété</option>
          <option value="titre_propriete">Titre de propriété</option>
          <option value="reglement_copropriete">Règlement de copropriété</option>
          <option value="etat_descriptif">État descriptif de division</option>
          <option value="carnet_entretien">Carnet d'entretien</option>
        </optgroup>
        <optgroup label="Assurance &amp; diagnostics">
          <option value="attestation_assurance">Attestation d'assurance</option>
          <option value="diagnostic_dpe">DPE</option>
          <option value="diagnostic_amiante">Amiante</option>
          <option value="diagnostic_autre">Autre diagnostic</option>
        </optgroup>
        <optgroup label="Assemblée générale">
          <option value="pv_ag">PV d'AG</option>
          <option value="convocation_ag">Convocation AG</option>
          <option value="budget_previsionnel">Budget prévisionnel</option>
        </optgroup>
        <optgroup label="Comptabilité">
          <option value="releve_charges">Relevé de charges</option>
          <option value="appel_fonds">Appel de fonds</option>
          <option value="facture_fournisseur">Facture fournisseur</option>
        </optgroup>
        <optgroup label="Autre">
          <option value="correspondance">Courrier / correspondance</option>
          <option value="autre">Autre document</option>
        </optgroup>
      </select>
      <div class="drop-zone" id="drop_zone" onclick="document.getElementById('doc_input').click()"
           ondragover="event.preventDefault();this.classList.add('over')"
           ondragleave="this.classList.remove('over')"
           ondrop="handleDrop(event)">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#b0b8c8" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <p>Glisser-déposer ou cliquer pour importer<br><span style="font-size:10px">PDF, DOC, DOCX, JPG, PNG — max 10 Mo</span></p>
      </div>
      <input type="file" id="doc_input" name="doc_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display:none" onchange="this.form.submit()">
    </form>

    <div style="margin-top:12px">
    <?php foreach ($docs as $doc):
      $icons = ['application/pdf'=>'#8a5040','image/jpeg'=>'#7a6830','image/png'=>'#7a6830'];
      $icolor = $icons[$doc['mime_type']] ?? '#4878a6';
      $typeLabels = [
          'mandat_signe'          => 'Mandat signé',
          'avenant'               => 'Avenant signé',
          'resiliation'           => 'Résiliation / congé',
          'attestation_propriete' => 'Attestation de propriété',
          'titre_propriete'       => 'Titre de propriété',
          'reglement_copropriete' => 'Règlement de copropriété',
          'etat_descriptif'       => 'État descriptif de division',
          'carnet_entretien'      => "Carnet d'entretien",
          'attestation_assurance' => "Attestation d'assurance",
          'diagnostic_dpe'        => 'DPE',
          'diagnostic_amiante'    => 'Amiante',
          'diagnostic_autre'      => 'Autre diagnostic',
          'pv_ag'                 => "PV d'AG",
          'convocation_ag'        => 'Convocation AG',
          'budget_previsionnel'   => 'Budget prévisionnel',
          'releve_charges'        => 'Relevé de charges',
          'appel_fonds'           => 'Appel de fonds',
          'facture_fournisseur'   => 'Facture fournisseur',
          'correspondance'        => 'Courrier / correspondance',
          'autre'                 => 'Autre document',
      ];
    ?>
    <div class="doc-row">
      <div class="doc-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?= $icolor ?>" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div style="flex:1;min-width:0">
        <div class="doc-name"><?= htmlspecialchars($doc['nom_fichier']) ?></div>
        <div class="doc-meta"><?= $typeLabels[$doc['type_doc']]??$doc['type_doc'] ?> · <?= number_format($doc['taille']/1024,1) ?> Ko · <?= date('d/m/Y', strtotime($doc['created_at'])) ?></div>
      </div>
      <?php if (in_array($doc['type_doc'], ['pv_ag','convocation_ag','budget_previsionnel','releve_charges'])): ?>
      <a href="agency_analyse_doc.php?doc_id=<?= $doc['id'] ?>" class="btn-sm primary" title="Analyser avec l'IA">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="vertical-align:middle"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
        IA
      </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($doc['chemin']) ?>" download class="btn-sm" title="Télécharger">↓</a>
      <?php if ($role_id <= 2): ?>
      <form method="POST" onsubmit="return confirm('Supprimer ce document ?')" style="display:inline">
        <input type="hidden" name="del_doc" value="<?= $doc['id'] ?>">
        <button type="submit" class="btn-sm danger">✕</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

</div><!-- /main-col -->

<!-- Colonne latérale -->
<div class="side-col">

  <!-- Actions principales -->
  <div class="card">
    <div class="card-title">Actions</div>
    <a href="agency_registre_form.php?id=<?= $id ?>" class="action-btn primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Modifier
    </a>
    <a href="agency_pdf_registre.php?id=<?= $id ?>" class="action-btn" target="_blank">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      PDF du mandat
    </a>
    <a href="agency_pdf_registre.php?registre=1&etab=<?= $m['id_etablissement'] ?>" class="action-btn" target="_blank">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg>
      Imprimer le registre
    </a>
    <?php if ($role_id === 1): ?>
    <a href="agency_registres.php?delete=<?= $id ?>" class="action-btn danger"
       onclick="return confirm('Supprimer définitivement ce mandat ?')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
      Supprimer
    </a>
    <?php endif; ?>
  </div>

  <!-- Statut rapide -->
  <?php if ($role_id <= 2): ?>
  <div class="card">
    <div class="card-title">Changer le statut</div>
    <form method="POST" class="stat-group">
      <?php foreach (['actif'=>'✓ Actif','suspendu'=>'⏸ Suspendu','resilie'=>'✕ Résilié','expire'=>'⏰ Expiré','archive'=>'📦 Archivé'] as $sv=>$sl): ?>
      <button type="submit" name="change_statut" value="<?= $sv ?>"
              class="stat-btn <?= $m['statut']===$sv?'active-stat':'' ?>">
        <?= $sl ?>
      </button>
      <?php endforeach; ?>
    </form>
  </div>
  <?php endif; ?>

  <!-- Infos établissement -->
  <?php if ($m['etab_nom']): ?>
  <div class="card">
    <div class="card-title">Syndic gestionnaire</div>
    <div style="font-size:13px;font-weight:600;color:#1a1816;margin-bottom:4px"><?= htmlspecialchars($m['etab_nom']) ?></div>
    <?php if ($m['etab_adr']): ?><div style="font-size:11px;color:#9a9690"><?= htmlspecialchars($m['etab_adr']) ?></div><?php endif; ?>
    <?php if ($m['etab_tel']): ?><div style="font-size:11px;color:#9a9690;margin-top:3px">✆ <?= htmlspecialchars($m['etab_tel']) ?></div><?php endif; ?>
    <?php if ($m['etab_email']): ?><div style="font-size:11px;color:#4878a6;margin-top:3px">✉ <?= htmlspecialchars($m['etab_email']) ?></div><?php endif; ?>
    <?php if ($m['etab_siret']): ?><div style="font-size:10px;color:#9a9690;margin-top:3px;font-family:'DM Mono',monospace">SIRET <?= htmlspecialchars($m['etab_siret']) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /side-col -->

</div><!-- /fiche-grid -->

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
