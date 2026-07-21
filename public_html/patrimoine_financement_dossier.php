<?php
/**
 * patrimoine_financement_dossier.php — Financement (prêt bailleur) MINIMALISTE.
 *
 * Miroir de patrimoine_creancier_dossier.php pour le comptable.
 * Champs : date, montant, mensualités, solde, échéance, commentaire, biens concernés.
 * + Documents (GED entity FINANCEMENT). Écriture si $canWrite.
 * Se branche sur fin_dossier + fin_dossier_lien (socle Financement existant).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/patrimoine_partage_auth.php';
@include_once __DIR__ . '/inc/ged_document_links.php';

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$e   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$eur = fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

[$share, $isPreview, $canWrite] = pp_resolve_share($pdo);
if ((int)($share['montrer_financements'] ?? 0) !== 1) {
    pp_auth_stop('Non autorisé', 'Les financements ne sont pas partagés sur ce lien.');
}

// ── Dossier + périmètre (lié PROPRIETAIRE à un proprio du partage) ──
$dossierId = (int)($_GET['dossier'] ?? 0);
$stD = $pdo->prepare("
    SELECT d.*, dl.entity_id AS id_proprietaire
    FROM fin_dossier d
    JOIN fin_dossier_lien dl ON dl.id_dossier = d.id AND dl.entity_type='PROPRIETAIRE'
    WHERE d.id = ?
      AND dl.entity_id IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?)
    LIMIT 1");
$stD->execute([$dossierId, (int)$share['id_user_bailleur']]);
$dossier = $stD->fetch(PDO::FETCH_ASSOC);
if (!$dossier) pp_auth_stop('Hors périmètre', 'Ce financement n\'est pas accessible depuis ce lien.');

$proprioId = (int)$dossier['id_proprietaire'];
$proprioNom = (string)($pdo->query("SELECT COALESCE(NULLIF(societe,''),TRIM(CONCAT_WS(' ',prenom,nom))) FROM proprietaires WHERE id=" . $proprioId)->fetchColumn() ?: '');
$idSociete = (int)($dossier['id_societe'] ?? 0) ?: null;
$backUrl = ($isPreview ? 'patrimoine_financement.php?preview=' . (int)$share['id'] : 'patrimoine_financement.php?t=' . urlencode((string)($_GET['t'] ?? ''))) . '&proprio=' . $proprioId;
$selfUrl = ($isPreview ? 'patrimoine_financement_dossier.php?preview=' . (int)$share['id'] : 'patrimoine_financement_dossier.php?t=' . urlencode((string)($_GET['t'] ?? ''))) . '&dossier=' . $dossierId;

$msg = ''; $msgType = '';
$createdBy = ($isPreview && function_exists('current_user_id')) ? (int)current_user_id() : null;

// Biens du propriétaire (pour rattachement)
$biensProp = $pdo->prepare("SELECT id, reference_bien, adresse_1, ville FROM biens WHERE id_proprietaire=? ORDER BY reference_bien");
$biensProp->execute([$proprioId]);
$biensProp = $biensProp->fetchAll(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════════
// ÉCRITURES
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) pp_auth_stop('Lecture seule', 'Vous n\'avez pas le droit de modifier ce financement.');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $num = fn($k) => ($_POST[$k] ?? '') !== '' ? (float)str_replace([' ', ','], ['', '.'], $_POST[$k]) : null;
        $dat = fn($k) => ($_POST[$k] ?? '') !== '' ? (string)$_POST[$k] : null;
        $statut = in_array($_POST['statut'] ?? '', ['ouvert','en_cours','suspendu','clos'], true) ? $_POST['statut'] : 'en_cours';
        $pdo->prepare("UPDATE fin_dossier SET
              libelle=?, organisme=?, date_financement=?, montant_total=?, mensualite=?, solde_restant=?,
              date_echeance=?, statut=?, commentaire=?, updated_at=NOW()
            WHERE id=?")->execute([
              trim((string)($_POST['libelle'] ?? $dossier['libelle'])) ?: $dossier['libelle'],
              trim((string)($_POST['organisme'] ?? '')) ?: null,
              $dat('date_financement'), $num('montant_total'), $num('mensualite'), $num('solde_restant'),
              $dat('date_echeance'), $statut, trim((string)($_POST['commentaire'] ?? '')) ?: null,
              $dossierId
            ]);
        // Biens concernés : on remplace les liens BIEN
        $selected = array_map('intval', (array)($_POST['biens'] ?? []));
        $valid = array_column($biensProp, 'id');
        $selected = array_values(array_intersect($selected, array_map('intval', $valid)));
        $pdo->prepare("DELETE FROM fin_dossier_lien WHERE id_dossier=? AND entity_type='BIEN'")->execute([$dossierId]);
        $insB = $pdo->prepare("INSERT INTO fin_dossier_lien (id_societe, id_dossier, entity_type, entity_id, role_lien, created_by) VALUES (?,?, 'BIEN', ?, 'bien_finance', ?)");
        foreach ($selected as $bid) { $insB->execute([$idSociete, $dossierId, $bid, $createdBy]); }
        $msg = 'Financement enregistré.'; $msgType = 'success';
    }

    if ($action === 'upload_doc' && !empty($_FILES['doc']['tmp_name']) && is_uploaded_file($_FILES['doc']['tmp_name'])) {
        $f = $_FILES['doc'];
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
        if ($f['size'] <= 0 || $f['size'] > 25 * 1024 * 1024) { $msg = 'Fichier trop volumineux (max 25 Mo).'; $msgType = 'error'; }
        elseif (!in_array($ext, $allowed))                    { $msg = 'Format non autorisé.'; $msgType = 'error'; }
        elseif (!function_exists('gus_commit_document'))      { $msg = 'GED indisponible.'; $msgType = 'error'; }
        else {
            $ageId = (int)($dossier['id_agence'] ?? 0) ?: null;
            $socRaison = $idSociete ? (string)($pdo->query("SELECT raison_sociale FROM societes WHERE id=" . $idSociete)->fetchColumn() ?: '') : '';
            $namingCtx = ['upload_date'=>'now','n1_slug'=>'11_financement','n2_slug'=>'01_prets','n3_slug'=>'99_autre',
                          'date_doc'=>date('Y-m-d'),'type_doc'=>'financement','source_filename'=>(string)$f['name'],
                          'user_id'=>$createdBy,'entity_type'=>'FINANCEMENT','entity_id'=>$dossierId,'societe_raison'=>$socRaison ?: null];
            $ctx = ['tenant_id'=>$idSociete,'societe_id'=>$idSociete,'agence_id'=>$ageId,'document_type'=>'FINANCEMENT',
                    'source_module'=>'11_FINANCEMENT','security_level'=>'confidentiel','created_by'=>$createdBy,'naming_ctx'=>$namingCtx];
            $links = [['entity_type'=>'FINANCEMENT','entity_id'=>$dossierId,'relation_type'=>'main','is_validated'=>true,'validated_by'=>$createdBy]];
            try {
                $res = gus_commit_document($pdo, ['path_on_disk'=>$f['tmp_name'],'name_original'=>(string)$f['name'],
                        'mime_type'=>(string)($f['type'] ?: 'application/octet-stream'),'size_bytes'=>(int)$f['size']], $ctx, $links);
                if (empty($res['ok'])) throw new RuntimeException(implode(' / ', $res['errors'] ?? ['échec']));
                $msg = 'Document déposé en GED.'; $msgType = 'success';
            } catch (Throwable $ex) { $msg = 'Erreur dépôt : ' . $ex->getMessage(); $msgType = 'error'; }
        }
    }

    if ($msgType === 'success') { $_SESSION['pf_flash'] = $msg; header('Location: ' . $selfUrl); exit; }
}
if (!empty($_SESSION['pf_flash'])) { $msg = (string)$_SESSION['pf_flash']; $msgType = 'success'; unset($_SESSION['pf_flash']); }

// Recharge dossier après édition + biens liés + docs
$stD->execute([$dossierId, (int)$share['id_user_bailleur']]);
$dossier = $stD->fetch(PDO::FETCH_ASSOC);
$linkedBiens = $pdo->prepare("SELECT entity_id FROM fin_dossier_lien WHERE id_dossier=? AND entity_type='BIEN'");
$linkedBiens->execute([$dossierId]);
$linkedBiens = array_map('intval', $linkedBiens->fetchAll(PDO::FETCH_COLUMN));
$docs = [];
if (function_exists('gdl_documents_for_entity')) {
    try { $docs = gdl_documents_for_entity($pdo, 'FINANCEMENT', $dossierId, ['limit' => 50]); } catch (Throwable $ex) { $docs = []; }
}
$statutLbl = ['ouvert'=>'Ouvert','en_cours'=>'En cours','suspendu'=>'Suspendu','clos'=>'Soldé'];
?>
<!doctype html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Financement — <?= $e($proprioNom) ?></title>
<style>
  *{box-sizing:border-box;} body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#eef1f6;color:#1f2a44;}
  .wrap{max-width:900px;margin:0 auto;padding:22px 18px 60px;}
  .back{display:inline-block;color:#3a4b6e;text-decoration:none;font-size:.88em;margin-bottom:12px;}
  h1{font-size:1.25em;margin:0 0 3px;} .sub{color:#6b7796;font-size:.9em;margin-bottom:16px;}
  <?php if ($isPreview): ?>.band{background:#d4a047;color:#3a2a00;text-align:center;padding:6px;font-size:.82em;font-weight:700;}<?php endif; ?>
  .alert{border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.88em;}
  .alert.success{background:#e8f5e9;border-left:4px solid #2e7d32;color:#1b5e20;} .alert.error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;}
  .block{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px;padding:16px 18px;}
  .block h2{font-size:.98em;margin:0 0 12px;}
  .grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
  .fg{display:flex;flex-direction:column;gap:5px;} .fg label{font-size:.78em;font-weight:600;color:#555;}
  .fg input,.fg select,.fg textarea{border:1px solid #cdd4e0;border-radius:8px;padding:8px 10px;font-size:.9em;font-family:inherit;}
  .fg.full{grid-column:1/-1;}
  .kv{display:flex;flex-wrap:wrap;gap:16px 28px;} .kv div{font-size:.9em;} .kv b{display:block;font-size:1.05em;}
  .kv .lbl{color:#8592ad;font-size:.78em;font-weight:600;}
  .biens{display:flex;flex-wrap:wrap;gap:6px;} .bchk{display:flex;align-items:center;gap:6px;border:1px solid #e2e7f0;border-radius:8px;padding:6px 10px;font-size:.82em;cursor:pointer;}
  .bchk:has(input:checked){background:#e9f2ee;border-color:#bfe0d1;}
  .row{padding:8px 0;border-top:1px solid #f4f6fb;font-size:.88em;} .row:first-child{border-top:none;}
  .btn{background:#1f6b4e;color:#fff;border:none;border-radius:9px;padding:10px 20px;font-weight:700;font-size:.88em;cursor:pointer;}
  .chip{font-size:.76em;padding:3px 10px;border-radius:20px;background:#e9f2ee;color:#1f6b4e;}
  .ro{color:#9aa6bd;font-size:.8em;font-style:italic;}
  form.inline{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:10px;}
</style></head>
<body>
<?php if ($isPreview): ?><div class="band">👁 APERÇU INTERNE — vue comptable « <?= $e($share['destinataire_nom']) ?> »</div><?php endif; ?>
<div class="wrap">
  <a class="back" href="<?= $e($backUrl) ?>">← <?= $e($proprioNom) ?> · financements</a>
  <h1>💶 <?= $e($dossier['libelle']) ?></h1>
  <div class="sub"><?= $dossier['organisme'] ? $e($dossier['organisme']) . ' · ' : '' ?><span class="chip"><?= $statutLbl[$dossier['statut']] ?? $dossier['statut'] ?></span> <?= $canWrite ? '· <span style="color:#1f6b4e;font-weight:600;">contribution</span>' : '· lecture seule' ?></div>

  <?php if ($msg): ?><div class="alert <?= $msgType ?>"><?= $e($msg) ?></div><?php endif; ?>

  <?php if ($canWrite): ?>
  <!-- ÉDITION -->
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <div class="block">
      <h2>Caractéristiques du prêt</h2>
      <div class="grid">
        <div class="fg full"><label>Intitulé</label><input type="text" name="libelle" value="<?= $e($dossier['libelle']) ?>"></div>
        <div class="fg"><label>Organisme</label><input type="text" name="organisme" value="<?= $e($dossier['organisme']) ?>"></div>
        <div class="fg"><label>Date du financement</label><input type="date" name="date_financement" value="<?= $e($dossier['date_financement']) ?>"></div>
        <div class="fg"><label>Échéance (fin)</label><input type="date" name="date_echeance" value="<?= $e($dossier['date_echeance']) ?>"></div>
        <div class="fg"><label>Montant emprunté (€)</label><input type="text" name="montant_total" value="<?= $dossier['montant_total'] !== null ? (int)$dossier['montant_total'] : '' ?>"></div>
        <div class="fg"><label>Mensualité (€)</label><input type="text" name="mensualite" value="<?= $dossier['mensualite'] !== null ? (int)$dossier['mensualite'] : '' ?>"></div>
        <div class="fg"><label>Solde restant dû (€)</label><input type="text" name="solde_restant" value="<?= $dossier['solde_restant'] !== null ? (int)$dossier['solde_restant'] : '' ?>"></div>
        <div class="fg"><label>Statut</label>
          <select name="statut">
            <?php foreach ($statutLbl as $k => $v): ?><option value="<?= $k ?>" <?= $dossier['statut']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fg full"><label>Commentaire</label><textarea name="commentaire" rows="2"><?= $e($dossier['commentaire']) ?></textarea></div>
      </div>
    </div>
    <div class="block">
      <h2>Biens concernés</h2>
      <?php if (empty($biensProp)): ?><div class="ro">Aucun bien pour ce propriétaire.</div>
      <?php else: ?>
      <div class="biens">
        <?php foreach ($biensProp as $b): ?>
        <label class="bchk"><input type="checkbox" name="biens[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $linkedBiens, true)?'checked':'' ?>>
          <?= $e($b['reference_bien'] ?: ('#'.$b['id'])) ?> <span class="ro"><?= $e(trim(($b['adresse_1']??'').' '.($b['ville']??''))) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn">💾 Enregistrer</button>
  </form>
  <?php else: ?>
  <!-- LECTURE -->
  <div class="block">
    <h2>Caractéristiques du prêt</h2>
    <div class="kv">
      <div><span class="lbl">Organisme</span><b><?= $e($dossier['organisme'] ?: '—') ?></b></div>
      <div><span class="lbl">Date</span><b><?= $dfr($dossier['date_financement']) ?></b></div>
      <div><span class="lbl">Montant</span><b><?= $eur($dossier['montant_total']) ?></b></div>
      <div><span class="lbl">Mensualité</span><b><?= $eur($dossier['mensualite']) ?></b></div>
      <div><span class="lbl">Solde restant</span><b><?= $eur($dossier['solde_restant']) ?></b></div>
      <div><span class="lbl">Échéance</span><b><?= $dfr($dossier['date_echeance']) ?></b></div>
    </div>
    <?php if ($dossier['commentaire']): ?><p style="margin:12px 0 0;font-size:.9em;color:#3a4b6e;"><?= nl2br($e($dossier['commentaire'])) ?></p><?php endif; ?>
  </div>
  <div class="block">
    <h2>Biens concernés</h2>
    <?php if (empty($linkedBiens)): ?><div class="ro">Aucun bien rattaché.</div>
    <?php else: foreach ($biensProp as $b): if (!in_array((int)$b['id'],$linkedBiens,true)) continue; ?>
      <div class="row"><?= $e($b['reference_bien'] ?: ('#'.$b['id'])) ?> <span class="ro"><?= $e(trim(($b['adresse_1']??'').' '.($b['ville']??''))) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>

  <!-- DOCUMENTS -->
  <div class="block">
    <h2>📄 Documents</h2>
    <?php if (empty($docs)): ?><div class="ro">Aucun document.</div>
    <?php else: foreach ($docs as $d): ?>
      <div class="row"><?= $e($d['name_display'] ?? $d['name_file'] ?? 'Document') ?>
        <?php if ($isPreview && !empty($d['id'])): ?><a href="api/ged_doc_serve.php?id=<?= (int)$d['id'] ?>" target="_blank" style="margin-left:6px;">ouvrir</a><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($canWrite): ?>
    <form class="inline" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_doc">
      <div class="fg" style="flex:1;min-width:220px;"><label>Ajouter un document (offre de prêt, tableau d'amortissement…)</label>
        <input type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" required></div>
      <button class="btn" type="submit">Déposer</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body></html>
