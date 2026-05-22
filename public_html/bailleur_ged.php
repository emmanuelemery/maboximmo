<?php
/*
 * bailleur_ged.php — GED Bailleur (Gestion Électronique de Documents)
 * Upload, consultation et recherche de documents par propriétaire/immeuble/type
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$current_page = 'bailleur_ged';

/* ── Propriétaires accessibles ── */
$stmtProp = $pdo->prepare("
    SELECT up.id_proprietaire, up.label, p.nom, p.societe
    FROM user_proprietaires up JOIN proprietaires p ON p.id = up.id_proprietaire
    WHERE up.id_user = ? ORDER BY up.ordre, up.label
");
$stmtProp->execute([$userId]);
$proprietaires = $stmtProp->fetchAll(PDO::FETCH_ASSOC);
$propIds = array_column($proprietaires, 'id_proprietaire');

if (empty($propIds) && in_array($roleId, [1, 7], true)) {
    $proprietaires = $pdo->query("SELECT id AS id_proprietaire, COALESCE(societe,CONCAT(nom,' ',prenom)) AS label, nom, societe FROM proprietaires WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $propIds = array_column($proprietaires, 'id_proprietaire');
}

/* ── Filtres ── */
$fProp   = isset($_GET['prop'])   ? (int)$_GET['prop']   : 0;
$fType   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$fAnnee  = isset($_GET['annee'])  ? (int)$_GET['annee']   : 0;
$fSearch = isset($_GET['q'])      ? trim($_GET['q'])       : '';

if ($fProp <= 0 && count($propIds) === 1) $fProp = (int)$propIds[0];
$activePropIds = ($fProp > 0 && in_array($fProp, $propIds)) ? [$fProp] : $propIds;

/* ── Upload POST ── */
$msg = ''; $msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['doc_file']['tmp_name'])) {
    verify_csrf_any();
    $upProp = (int)($_POST['id_proprietaire'] ?? 0);
    $upImm  = (int)($_POST['id_immeuble'] ?? 0) ?: null;
    $upBien = (int)($_POST['id_bien'] ?? 0) ?: null;
    $upType = trim($_POST['type_document'] ?? 'autre');
    $upTitre = trim($_POST['titre'] ?? '');
    $upAnnee = (int)($_POST['annee'] ?? 0) ?: null;
    $upTrim  = (int)($_POST['trimestre'] ?? 0) ?: null;
    $upComment = trim($_POST['commentaire'] ?? '');

    if ($upProp <= 0 || !in_array($upProp, $propIds)) {
        $msg = 'Propriétaire invalide.'; $msgType = 'error';
    } elseif ($_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Erreur upload.'; $msgType = 'error';
    } elseif ($_FILES['doc_file']['size'] > 20 * 1024 * 1024) {
        $msg = 'Fichier trop volumineux (max 20 Mo).'; $msgType = 'error';
    } else {
        $origName = $_FILES['doc_file']['name'];
        $mime = mime_content_type($_FILES['doc_file']['tmp_name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $destDir = __DIR__ . '/uploads/bailleur_docs/' . $upProp;
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $safeName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
        $destPath = $destDir . '/' . $safeName;
        $relPath = 'uploads/bailleur_docs/' . $upProp . '/' . $safeName;

        if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $destPath)) {
            $pdo->prepare("INSERT INTO bailleur_documents (id_proprietaire, id_immeuble, id_bien, type_document, titre, nom_fichier, chemin_fichier, annee, trimestre, taille, mime_type, uploaded_by, commentaire) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$upProp, $upImm, $upBien, $upType, $upTitre ?: $origName, $origName, $relPath, $upAnnee, $upTrim, $_FILES['doc_file']['size'], $mime, $userId, $upComment]);
            $msg = 'Document ajouté.'; $msgType = 'success';
        } else {
            $msg = 'Erreur lors de la copie du fichier.'; $msgType = 'error';
        }
    }
}

/* ── Charger documents ── */
$docs = [];
if (!empty($activePropIds)) {
    $ph = implode(',', array_fill(0, count($activePropIds), '?'));
    $where = "bd.id_proprietaire IN ($ph)";
    $params = $activePropIds;

    if ($fType !== '') { $where .= " AND bd.type_document = ?"; $params[] = $fType; }
    if ($fAnnee > 0) { $where .= " AND bd.annee = ?"; $params[] = $fAnnee; }
    if ($fSearch !== '') { $where .= " AND (bd.titre LIKE ? OR bd.nom_fichier LIKE ?)"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; }

    $stmtDocs = $pdo->prepare("
        SELECT bd.*, p.societe AS prop_nom, p.nom AS prop_nom2,
               i.nom_immeuble, u.nom AS uploader_nom, u.prenom AS uploader_prenom
        FROM bailleur_documents bd
        LEFT JOIN proprietaires p ON p.id = bd.id_proprietaire
        LEFT JOIN immeubles i ON i.id = bd.id_immeuble
        LEFT JOIN users u ON u.id = bd.uploaded_by
        WHERE $where
        ORDER BY bd.date_upload DESC
        LIMIT 200
    ");
    $stmtDocs->execute($params);
    $docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

    // Types disponibles
    $stmtTypes = $pdo->prepare("SELECT DISTINCT type_document FROM bailleur_documents WHERE id_proprietaire IN ($ph) ORDER BY type_document");
    $stmtTypes->execute($activePropIds);
    $types = array_column($stmtTypes->fetchAll(), 'type_document');

    // Années
    $stmtYears = $pdo->prepare("SELECT DISTINCT annee FROM bailleur_documents WHERE id_proprietaire IN ($ph) AND annee IS NOT NULL ORDER BY annee DESC");
    $stmtYears->execute($activePropIds);
    $docAnnees = array_column($stmtYears->fetchAll(), 'annee');
} else {
    $types = []; $docAnnees = [];
}

// Immeubles pour le formulaire upload
$immForUpload = [];
if ($fProp > 0) {
    $stmtImmU = $pdo->prepare("SELECT id, nom_immeuble, adresse_1 FROM immeubles WHERE id_proprietaire = ? ORDER BY nom_immeuble");
    $stmtImmU->execute([$fProp]);
    $immForUpload = $stmtImmU->fetchAll(PDO::FETCH_ASSOC);
}

$typeLabels = ['CRG'=>'📊 CRG','bail'=>'📝 Bail','quittance'=>'🧾 Quittance','appel_charges'=>'📋 Appel charges','pv_ag'=>'📜 PV AG','avis_imposition'=>'💶 Avis imposition','assurance'=>'🛡 Assurance','facture'=>'🧾 Facture','autre'=>'📄 Autre'];

/* ── Layout ── */
$layout_title   = 'GED Bailleur';
$layout_module  = 'Ma Box Bailleur';
$layout_sidebar = 'sidebar_agency';
$_act = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;';
$_on = $_act.'background:#4a6038;color:#fff;border:1px solid #4a6038;';
$_off = $_act.'background:#fff;color:#555;border:1px solid #d4d7de;';
$layout_head_kpis = '<div style="display:flex;gap:10px;justify-content:center;flex:1;">
    <a href="bailleur_dashboard.php?prop='.$fProp.'" style="'.$_off.'">📊 Dashboard</a>
    <a href="bailleur_immeubles.php?prop='.$fProp.'" style="'.$_off.'">🏢 Immeubles</a>
    <a href="bailleur_ged.php?prop='.$fProp.'" style="'.$_on.'">📁 GED</a>
    <a href="bailleur_crg_audit.php?prop='.$fProp.'" style="'.$_off.'">🔍 Audit CRG</a>
    <a href="bailleur_sci_organigramme.php" style="'.$_off.'">🏛 SCI</a>
</div>';
$layout_head_actions = ($roleId === 1)
    ? '<a href="bailleur_validation_imports.php" class="ph-btn primary" style="font-size:12px;padding:7px 14px;">📥 Imports</a>'
    : '';

$layout_extra_css = '<style>
.bf{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:12px 16px;background:#fff;border-radius:12px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bf select,.bf input{padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px}
.bf label{font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px}
.bs{background:#fff;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bs-t{font-size:14px;font-weight:700;margin-bottom:12px}
.bt{width:100%;border-collapse:collapse;font-size:12px}
.bt th{text-align:left;font-size:9px;text-transform:uppercase;color:#888;padding:6px 8px;border-bottom:2px solid #eee}
.bt td{padding:6px 8px;border-bottom:1px solid #f3f4f6}
.bt tr:hover{background:#f9fafb}
.bl{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.bl a{padding:7px 14px;background:#fff;border:1px solid #d4d7de;border-radius:9px;text-decoration:none;font-size:11px;font-weight:600;color:#555}
.bl a:hover{border-color:#4a6038;color:#4a6038}.bl a.on{background:#4a6038;color:#fff;border-color:#4a6038}
.ged-upload{background:#f8f7f5;border:2px dashed #d4d7de;border-radius:12px;padding:20px;margin-bottom:18px}
.ged-upload input[type=file]{font-size:12px}
.ged-msg{padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:14px}
.ged-msg.ok{background:#f0fdf4;color:#16a34a}.ged-msg.err{background:#fef2f2;color:#dc2626}
</style>';

ob_start();
?>


<?php if ($msg): ?><div class="ged-msg <?= $msgType==='success'?'ok':'err' ?>"><?= h($msg) ?></div><?php endif; ?>

<!-- Filtres -->
<form method="get" class="bf">
  <div><label>Propriétaire</label><select name="prop" onchange="this.form.submit()"><option value="0">— Tous —</option>
    <?php foreach ($proprietaires as $p): ?><option value="<?= $p['id_proprietaire'] ?>" <?= $fProp==$p['id_proprietaire']?'selected':'' ?>><?= h($p['societe']?:$p['label']?:$p['nom']) ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Type</label><select name="type" onchange="this.form.submit()"><option value="">— Tous —</option>
    <?php foreach ($types as $t): ?><option value="<?= h($t) ?>" <?= $fType===$t?'selected':'' ?>><?= h($typeLabels[$t] ?? $t) ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Année</label><select name="annee" onchange="this.form.submit()"><option value="0">— Toutes —</option>
    <?php foreach ($docAnnees as $a): ?><option value="<?= $a ?>" <?= $fAnnee==$a?'selected':'' ?>><?= $a ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Recherche</label><input type="text" name="q" value="<?= h($fSearch) ?>" placeholder="titre, fichier..."></div>
  <div><button type="submit" style="padding:5px 12px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer">🔍</button></div>
</form>

<!-- Upload -->
<?php if ($fProp > 0): ?>
<div class="ged-upload">
  <div style="font-weight:600;font-size:13px;margin-bottom:10px">📤 Ajouter un document</div>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id_proprietaire" value="<?= $fProp ?>">
    <div><label style="font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px">Type</label>
      <select name="type_document" style="padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px">
        <?php foreach ($typeLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if (!empty($immForUpload)): ?>
    <div><label style="font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px">Immeuble</label>
      <select name="id_immeuble" style="padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px">
        <option value="0">— Général —</option>
        <?php foreach ($immForUpload as $im): ?><option value="<?= $im['id'] ?>"><?= h($im['nom_immeuble']?:$im['adresse_1']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div><label style="font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px">Année</label>
      <input type="number" name="annee" value="<?= date('Y') ?>" min="2020" max="2030" style="width:70px;padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px">
    </div>
    <div><label style="font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px">Trim.</label>
      <select name="trimestre" style="padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px">
        <option value="0">—</option><option value="1">T1</option><option value="2">T2</option><option value="3">T3</option><option value="4">T4</option>
      </select>
    </div>
    <div><label style="font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px">Fichier</label>
      <input type="file" name="doc_file" required style="font-size:11px">
    </div>
    <div><button type="submit" style="padding:6px 16px;background:#4a6038;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer">📤 Ajouter</button></div>
  </form>
</div>
<?php endif; ?>

<!-- Liste documents -->
<div class="bs">
  <div class="bs-t">Documents (<?= count($docs) ?>)</div>
  <?php if (empty($docs)): ?>
    <p style="color:#888;font-size:13px;text-align:center;padding:20px">Aucun document trouvé.</p>
  <?php else: ?>
  <table class="bt">
    <thead><tr><th>Type</th><th>Titre / Fichier</th><th>Propriétaire</th><th>Immeuble</th><th>Année</th><th>Taille</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d):
        $icon = $typeLabels[$d['type_document']] ?? ('📄 ' . $d['type_document']);
        $size = $d['taille'] < 1024*1024 ? round($d['taille']/1024) . ' Ko' : round($d['taille']/1024/1024, 1) . ' Mo';
    ?>
    <tr>
      <td style="font-size:11px"><?= h($icon) ?></td>
      <td><strong><?= h($d['titre'] ?: $d['nom_fichier']) ?></strong></td>
      <td style="font-size:11px;color:#666"><?= h($d['prop_nom'] ?: $d['prop_nom2'] ?: '') ?></td>
      <td style="font-size:11px;color:#666"><?= h($d['nom_immeuble'] ?? '—') ?></td>
      <td><?= $d['annee'] ? $d['annee'] . ($d['trimestre'] ? ' T'.$d['trimestre'] : '') : '—' ?></td>
      <td style="font-size:11px;color:#888"><?= $size ?></td>
      <td style="font-size:11px;color:#888"><?= $d['date_upload'] ? date('d/m/Y', strtotime($d['date_upload'])) : '' ?></td>
      <td><a href="<?= h($d['chemin_fichier']) ?>" target="_blank" style="font-size:11px;color:#4878a6">📥</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
