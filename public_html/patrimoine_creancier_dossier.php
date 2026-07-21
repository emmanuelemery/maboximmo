<?php
/**
 * patrimoine_creancier_dossier.php — Dossier créancier 360° MINIMALISTE (vue jeton).
 *
 * Ouvert depuis patrimoine_creancier.php. 4 blocs seulement, destinés à informer
 * un tiers (avocat & autres) : Documents · Saisies · Échéances · Commentaires.
 * Les blocs financiers/versements/IA restent réservés au bailleur (creancier_dossier360.php).
 *
 * Lecture pour tous ; ÉCRITURE (upload doc, échéance, commentaire) si $canWrite
 * (partage 'contribution' ou staff en aperçu). Écritures branchées sur le module
 * Créanciers existant + GED centrale (gus_commit_document).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/patrimoine_partage_auth.php';
@include_once __DIR__ . '/inc/ged_document_links.php';

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$e   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

[$share, $isPreview, $canWrite] = pp_resolve_share($pdo);
if ((int)($share['montrer_creanciers'] ?? 0) !== 1) {
    pp_auth_stop('Non autorisé', 'Les dossiers créanciers ne sont pas partagés sur ce lien.');
}

// ── Dossier + vérification de périmètre (lié PROPRIETAIRE à un proprio du partage) ──
$isAdmin   = !empty($_GET['admin']);
$dossierId = (int)($_GET['dossier'] ?? 0);
$stD = $pdo->prepare("
    SELECT d.*, dl.entity_id AS id_proprietaire
    FROM creancier_dossier d
    JOIN creancier_dossier_lien dl ON dl.id_dossier = d.id AND dl.entity_type='PROPRIETAIRE'
    WHERE d.id = ? LIMIT 1");
$stD->execute([$dossierId]);
$dossier = $stD->fetch(PDO::FETCH_ASSOC);
if (!$dossier || !pp_perimeter_ok($pdo, $share, (int)$dossier['id_proprietaire'])) {
    pp_auth_stop('Hors périmètre', 'Ce dossier n\'est pas accessible depuis ce lien.');
}

$proprioId = (int)$dossier['id_proprietaire'];
$proprioNom = (string)($pdo->query("SELECT COALESCE(NULLIF(societe,''),TRIM(CONCAT_WS(' ',prenom,nom))) FROM proprietaires WHERE id=" . $proprioId)->fetchColumn() ?: '');
$subQS   = pp_sub_qs($share, $isPreview, $isAdmin, (string)($_GET['t'] ?? ''));
$backUrl = 'patrimoine_creancier.php?' . $subQS . '&proprio=' . $proprioId;
$selfUrl = 'patrimoine_creancier_dossier.php?' . $subQS . '&dossier=' . $dossierId;

$msg = ''; $msgType = '';
$auteur = $isPreview ? 'Staff (aperçu)' : (string)($share['destinataire_nom'] ?: 'Contributeur externe');
$createdBy = ($isPreview && function_exists('current_user_id')) ? (int)current_user_id() : null;

// ════════════════════════════════════════════════════════════════════
// ÉCRITURES (contribution / staff)
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) pp_auth_stop('Lecture seule', 'Vous n\'avez pas le droit de modifier ce dossier.');
    $action = $_POST['action'] ?? '';

    // ── Commentaire daté ──
    if ($action === 'add_comment') {
        $txt = trim((string)($_POST['message'] ?? ''));
        if ($txt !== '') {
            $pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, canal, id_user, message)
                           VALUES (?, 'user', 'feed', ?, ?)")
                ->execute([$dossierId, $createdBy, '[' . $auteur . '] ' . $txt]);
            $msg = 'Commentaire ajouté.'; $msgType = 'success';
        }
    }

    // ── Échéance ──
    if ($action === 'add_echeance') {
        $lib  = trim((string)($_POST['libelle'] ?? ''));
        $date = trim((string)($_POST['date_echeance'] ?? ''));
        $type = preg_replace('/[^a-z_]/', '', strtolower((string)($_POST['type'] ?? 'echeance'))) ?: 'echeance';
        if ($lib !== '' && $date !== '') {
            $pdo->prepare("INSERT INTO creancier_echeance (id_dossier, type, libelle, date_echeance, statut, source, note, created_by)
                           VALUES (?, ?, ?, ?, 'a_venir', 'manuel', ?, ?)")
                ->execute([$dossierId, $type, mb_substr($lib, 0, 190), $date, 'ajouté par ' . $auteur, $createdBy]);
            $msg = 'Échéance ajoutée.'; $msgType = 'success';
        } else { $msg = 'Libellé et date requis.'; $msgType = 'error'; }
    }

    // ── Upload document de procédure → GED centrale ──
    if ($action === 'upload_doc' && !empty($_FILES['doc']['tmp_name']) && is_uploaded_file($_FILES['doc']['tmp_name'])) {
        $typeDoc = preg_replace('/[^a-z_]/', '', strtolower((string)($_POST['type_doc'] ?? 'autre'))) ?: 'autre';
        $f = $_FILES['doc'];
        $sizeOk = $f['size'] > 0 && $f['size'] <= 25 * 1024 * 1024;
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','doc','docx'];
        if (!$sizeOk)                       { $msg = 'Fichier trop volumineux (max 25 Mo).'; $msgType = 'error'; }
        elseif (!in_array($ext, $allowed))  { $msg = 'Format non autorisé (PDF, image, Word).'; $msgType = 'error'; }
        elseif (!function_exists('gus_commit_document')) { $msg = 'GED indisponible.'; $msgType = 'error'; }
        else {
            $socId = (int)($dossier['id_societe'] ?? 0) ?: null;
            $ageId = (int)($dossier['id_agence'] ?? 0) ?: null;
            $n3map = ['assignation'=>'00_assignation','conclusions'=>'01_conclusions','jugement'=>'02_jugement',
                      'commandement'=>'03_commandement','acte_saisie'=>'04_acte_saisie','autre'=>'99_autre'];
            $socRaison = $socId ? (string)($pdo->query("SELECT raison_sociale FROM societes WHERE id=" . $socId)->fetchColumn() ?: '') : '';
            $namingCtx = [
                'upload_date'=>'now','n1_slug'=>'12_contentieux','n2_slug'=>'01_creanciers',
                'n3_slug'=>$n3map[$typeDoc] ?? '99_autre','date_doc'=>date('Y-m-d'),'type_doc'=>$typeDoc,
                'source_filename'=>(string)$f['name'],'user_id'=>$createdBy,
                'entity_type'=>'CREANCIER_DOSSIER','entity_id'=>$dossierId,'societe_raison'=>$socRaison ?: null,
            ];
            $ctx = ['tenant_id'=>$socId,'societe_id'=>$socId,'agence_id'=>$ageId,'document_type'=>strtoupper($typeDoc),
                    'source_module'=>'12_CONTENTIEUX','security_level'=>'confidentiel','created_by'=>$createdBy,'naming_ctx'=>$namingCtx];
            $links = [['entity_type'=>'CREANCIER_DOSSIER','entity_id'=>$dossierId,'relation_type'=>'main','is_validated'=>true,'validated_by'=>$createdBy]];
            try {
                $res = gus_commit_document($pdo, [
                    'path_on_disk'=>$f['tmp_name'],'name_original'=>(string)$f['name'],
                    'mime_type'=>(string)($f['type'] ?: 'application/octet-stream'),'size_bytes'=>(int)$f['size'],
                ], $ctx, $links);
                if (empty($res['ok'])) throw new RuntimeException(implode(' / ', $res['errors'] ?? ['échec']));
                // Trace au fil du dossier
                $pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, canal, id_user, message)
                               VALUES (?, 'user', 'feed', ?, ?)")
                    ->execute([$dossierId, $createdBy, '[' . $auteur . '] Document déposé : ' . ($res['name_display'] ?? $f['name']) . ' (' . $typeDoc . ').']);
                $msg = 'Document déposé en GED.'; $msgType = 'success';
            } catch (Throwable $ex) { $msg = 'Erreur dépôt : ' . $ex->getMessage(); $msgType = 'error'; }
        }
    }

    if ($msgType === 'success') { header('Location: ' . $selfUrl . '&ok=1'); exit; }
}
if (isset($_GET['ok'])) { $msg = 'Enregistré.'; $msgType = 'success'; }

// ════════════════════════════════════════════════════════════════════
// LECTURE DES 4 BLOCS
// ════════════════════════════════════════════════════════════════════
// 1) Documents (GED)
$docs = [];
if (function_exists('gdl_documents_for_entity')) {
    try { $docs = gdl_documents_for_entity($pdo, 'CREANCIER_DOSSIER', $dossierId, ['limit' => 50]); } catch (Throwable $e) { $docs = []; }
}
// 2) Saisies (via lien SAISIE)
$saisies = $pdo->prepare("
    SELECT s.* FROM creancier_dossier_lien dl
    JOIN creancier_saisie s ON s.id = dl.entity_id
    WHERE dl.id_dossier = ? AND dl.entity_type='SAISIE'
    ORDER BY (s.statut='en_cours') DESC, s.date_butoir ASC");
$saisies->execute([$dossierId]);
$saisies = $saisies->fetchAll(PDO::FETCH_ASSOC);
// 3) Échéances
$echeances = $pdo->prepare("SELECT * FROM creancier_echeance WHERE id_dossier=? ORDER BY (statut='a_venir') DESC, date_echeance ASC");
$echeances->execute([$dossierId]);
$echeances = $echeances->fetchAll(PDO::FETCH_ASSOC);
// 4) Commentaires (feed)
$feed = $pdo->prepare("SELECT * FROM creancier_dossier_message WHERE id_dossier=? AND canal IN ('feed','chat') ORDER BY created_at DESC LIMIT 100");
$feed->execute([$dossierId]);
$feed = $feed->fetchAll(PDO::FETCH_ASSOC);

$riskLabel = ['rouge' => '🔴 Urgent', 'orange' => '🟠 À suivre', 'vert' => '🟢 Maîtrisé'];
$saisieType = ['ATTRIBUTION_LOYER'=>'Attribution de loyers','IMMOBILIERE'=>'Saisie immobilière','CONSERVATOIRE'=>'Conservatoire','COMPTE'=>'Saisie compte','REMUNERATION'=>'Saisie rémunération'];
$saisieStatut = ['en_cours'=>'En cours','cantonnee'=>'Cantonnée','mainlevee_partielle'=>'Mainlevée partielle','mainlevee'=>'Mainlevée','soldee'=>'Soldée','contestee'=>'Contestée'];
?>
<!doctype html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dossier <?= $e($dossier['code']) ?> — <?= $e($proprioNom) ?></title>
<style>
  *{box-sizing:border-box;} body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#eef1f6;color:#1f2a44;}
  .wrap{max-width:960px;margin:0 auto;padding:22px 18px 60px;}
  .back{display:inline-block;color:#3a4b6e;text-decoration:none;font-size:.88em;margin-bottom:12px;}
  h1{font-size:1.25em;margin:0 0 3px;} .sub{color:#6b7796;font-size:.9em;margin-bottom:6px;}
  .head-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
  .chip{font-size:.78em;padding:3px 10px;border-radius:20px;}
  .c-rouge{background:#fdecec;color:#b52a2a;} .c-orange{background:#fff4e5;color:#a15c00;} .c-vert{background:#e8f5e9;color:#1b5e20;} .c-clos{background:#eceff4;color:#6b7796;}
  <?php if ($isPreview): ?>.band{background:#d4a047;color:#3a2a00;text-align:center;padding:6px;font-size:.82em;font-weight:700;}<?php endif; ?>
  .alert{border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.88em;}
  .alert.success{background:#e8f5e9;border-left:4px solid #2e7d32;color:#1b5e20;} .alert.error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;}
  .block{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px;overflow:hidden;}
  .block h2{font-size:.98em;margin:0;padding:13px 18px;border-bottom:1px solid #f0f2f7;display:flex;align-items:center;gap:8px;}
  .row{padding:11px 18px;border-bottom:1px solid #f4f6fb;font-size:.88em;}
  .row:last-child{border-bottom:none;} .row .r-meta{color:#8592ad;font-size:.85em;}
  .empty{padding:18px;color:#9aa6bd;font-size:.86em;}
  .num{font-variant-numeric:tabular-nums;}
  form.inline{padding:14px 18px;background:#fafbfe;border-top:1px solid #f0f2f7;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;}
  form.inline .fg{display:flex;flex-direction:column;gap:4px;} form.inline label{font-size:.74em;color:#6b7796;font-weight:600;}
  form.inline input,form.inline select,form.inline textarea{border:1px solid #cdd4e0;border-radius:7px;padding:7px 10px;font-size:.85em;font-family:inherit;}
  form.inline textarea{width:100%;min-height:44px;} .grow{flex:1;min-width:200px;}
  .btn{background:#243B5C;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-weight:700;font-size:.84em;cursor:pointer;white-space:nowrap;}
  .ro{padding:10px 18px;color:#9aa6bd;font-size:.8em;font-style:italic;border-top:1px solid #f0f2f7;}
</style></head>
<body>
<?php if ($isPreview): ?><div class="band">👁 APERÇU INTERNE — vue jeton « <?= $e($share['destinataire_nom']) ?> »</div><?php endif; ?>
<div class="wrap">
  <a class="back" href="<?= $e($backUrl) ?>">← <?= $e($proprioNom) ?> · dossiers</a>
  <h1>⚖️ <?= $e($dossier['libelle']) ?> <?php if ($dossier['debiteur_enerve']): ?><span title="Débiteur énervé">😤</span><?php endif; ?></h1>
  <div class="sub"><?= $e($dossier['code']) ?><?= $dossier['synthese'] ? ' · ' . $e($dossier['synthese']) : '' ?></div>
  <div class="head-chips">
    <span class="chip <?= $dossier['statut']==='clos' ? 'c-clos' : 'c-'.$e($dossier['niveau_risque']) ?>">
      <?= $dossier['statut']==='clos' ? 'Clos' : ($riskLabel[$dossier['niveau_risque']] ?? $dossier['niveau_risque']) ?>
    </span>
    <span class="chip c-clos"><?= $e(ucfirst($dossier['statut'])) ?></span>
    <?php if (!$canWrite): ?><span class="chip c-clos">🔒 lecture seule</span><?php else: ?><span class="chip c-vert">✍️ contribution</span><?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msgType ?>"><?= $e($msg) ?></div><?php endif; ?>

  <!-- 1) DOCUMENTS -->
  <div class="block">
    <h2>📄 Documents de procédure</h2>
    <?php if (empty($docs)): ?><div class="empty">Aucun document.</div>
    <?php else: foreach ($docs as $d): ?>
      <div class="row">
        <?= $e($d['name_display'] ?? $d['name_file'] ?? 'Document') ?>
        <span class="r-meta"> · <?= $dfr($d['created_at'] ?? null) ?></span>
        <?php if ($isPreview && !empty($d['id'])): ?>
          <a href="api/ged_doc_serve.php?id=<?= (int)$d['id'] ?>" target="_blank" style="margin-left:6px;">ouvrir</a>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($canWrite): ?>
    <form class="inline" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_doc">
      <div class="fg"><label>Type</label>
        <select name="type_doc">
          <option value="assignation">Assignation</option>
          <option value="commandement">Commandement</option>
          <option value="jugement">Jugement</option>
          <option value="conclusions">Conclusions</option>
          <option value="acte_saisie">Acte de saisie</option>
          <option value="autre">Autre</option>
        </select>
      </div>
      <div class="fg grow"><label>Fichier (PDF, image, Word — 25 Mo max)</label>
        <input type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required></div>
      <button class="btn" type="submit">Déposer</button>
    </form>
    <?php else: ?><div class="ro">Dépôt réservé au contributeur.</div><?php endif; ?>
  </div>

  <!-- 2) SAISIES (lecture) -->
  <div class="block">
    <h2>⚖️ Saisies</h2>
    <?php if (empty($saisies)): ?><div class="empty">Aucune saisie.</div>
    <?php else: foreach ($saisies as $s): ?>
      <div class="row">
        <strong><?= $e($saisieType[$s['type_saisie']] ?? $s['type_saisie']) ?></strong>
        — <?= $e($saisieStatut[$s['statut']] ?? $s['statut']) ?>
        <div class="r-meta num">
          Réclamé <?= $eur($s['montant_reclame']) ?> · Bloqué <?= $eur($s['montant_net_bloque']) ?>
          <?php if ((float)$s['loyer_mensuel_capte'] > 0): ?> · Loyer capté <?= $eur($s['loyer_mensuel_capte']) ?>/mois<?php endif; ?>
          <?php if ($s['date_butoir']): ?> · Butoir <?= $dfr($s['date_butoir']) ?><?php endif; ?>
          <?php if ($s['reference_acte']): ?> · <?= $e($s['reference_acte']) ?><?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
    <div class="ro">Saisies gérées côté régie — lecture seule.</div>
  </div>

  <!-- 3) ÉCHÉANCES -->
  <div class="block">
    <h2>📅 Prochaines échéances</h2>
    <?php if (empty($echeances)): ?><div class="empty">Aucune échéance.</div>
    <?php else: foreach ($echeances as $ec): ?>
      <div class="row">
        <strong><?= $dfr($ec['date_echeance']) ?></strong> — <?= $e($ec['libelle']) ?>
        <span class="r-meta"> · <?= $e($ec['type']) ?> · <?= $e($ec['statut']) ?></span>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($canWrite): ?>
    <form class="inline" method="POST">
      <input type="hidden" name="action" value="add_echeance">
      <div class="fg"><label>Type</label>
        <select name="type">
          <option value="audience">Audience</option>
          <option value="butoir">Date butoir</option>
          <option value="relance">Relance</option>
          <option value="echeance">Échéance</option>
        </select>
      </div>
      <div class="fg"><label>Date</label><input type="date" name="date_echeance" required></div>
      <div class="fg grow"><label>Libellé</label><input type="text" name="libelle" placeholder="Audience de mise en état…" required></div>
      <button class="btn" type="submit">Ajouter</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- 4) COMMENTAIRES -->
  <div class="block">
    <h2>💬 Commentaires</h2>
    <?php if ($canWrite): ?>
    <form class="inline" method="POST" style="flex-direction:column;align-items:stretch;">
      <input type="hidden" name="action" value="add_comment">
      <div class="fg grow"><label>Nouveau commentaire</label>
        <textarea name="message" placeholder="Note datée…" required></textarea></div>
      <div><button class="btn" type="submit">Publier</button></div>
    </form>
    <?php endif; ?>
    <?php if (empty($feed)): ?><div class="empty">Aucun commentaire.</div>
    <?php else: foreach ($feed as $m): ?>
      <div class="row">
        <?= nl2br($e($m['message'])) ?>
        <div class="r-meta"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

</div>
</body></html>
