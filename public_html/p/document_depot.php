<?php
declare(strict_types=1);
/**
 * p/document_depot.php — Page PUBLIQUE de dépôt de documents (sans authentification).
 *
 * Le destinataire d'une « demande de document » dépose chaque pièce dans sa carte.
 * Chaque fichier file droit dans la GED (dr_commit_deposit). Notif au demandeur.
 *
 * URL : /p/document_depot.php?t=<token>
 * Sécurité : token valide + non expiré/révoqué + (option) gate email du destinataire.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/document_requests.php';
require_once __DIR__ . '/../inc/mailer.php';

$pdo = $GLOBALS['pdo'];

// Notif au demandeur à chaque dépôt (définie avant tout appel).
function dr_notify_requester(PDO $pdo, array $req, string $pieceLabel): void
{
    try {
        $cb = (int)($req['created_by'] ?? 0);
        if ($cb <= 0) return;
        $st = $pdo->prepare("SELECT email, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) nom FROM users WHERE id=? LIMIT 1");
        $st->execute([$cb]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) return;
        $items = dr_items($pdo, (int)$req['id']);
        $recus = count(array_filter($items, fn($i) => $i['status'] === 'recu'));
        $body = '<p>Bonjour ' . htmlspecialchars((string)$u['nom'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p><strong>' . htmlspecialchars((string)$req['recipient_email'], ENT_QUOTES, 'UTF-8') . '</strong> '
            . 'a déposé : <strong>' . htmlspecialchars($pieceLabel, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>Avancement : ' . $recus . '/' . count($items) . ' pièce(s) reçue(s) — demande « '
            . htmlspecialchars((string)$req['titre'], ENT_QUOTES, 'UTF-8') . ' ».</p>';
        send_mail((string)$u['email'], 'Dépôt reçu : ' . (string)$req['titre'], $body, [], true);
    } catch (Throwable $e) { error_log('[dr_notify] ' . $e->getMessage()); }
}

$token = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); die('Lien invalide.'); }

$req = dr_get_by_token($pdo, $token);
if (!$req) { http_response_code(404); die('Ce lien n\'existe pas.'); }
if (!dr_is_valid($req)) { http_response_code(403); die('Ce lien a expiré ou a été révoqué.'); }

$reqId   = (int)$req['id'];
$sessKey = 'dr_auth_' . substr($token, 0, 16);

// ── Marque (logo + nom) de l'agence pour l'en-tête de la page de dépôt ──
$brandLogo = ''; $brandNom = '';
try {
    $ageId = (int)($req['agence_id'] ?? 0);
    $socId = (int)($req['societe_id'] ?? 0);
    if ($ageId > 0) {
        $ba = $pdo->prepare("SELECT nom_agence, logo_url, logo_path FROM agences WHERE id=?");
        $ba->execute([$ageId]); $a = $ba->fetch(PDO::FETCH_ASSOC) ?: [];
        $brandNom  = (string)($a['nom_agence'] ?? '');
        $brandLogo = trim((string)($a['logo_url'] ?? '')) ?: trim((string)($a['logo_path'] ?? ''));
    }
    if ($brandLogo === '' && $socId > 0) {
        $bs = $pdo->prepare("SELECT COALESCE(NULLIF(nom,''),raison_sociale) nom, logo_url FROM societes WHERE id=?");
        $bs->execute([$socId]); $s = $bs->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($brandNom === '') $brandNom = (string)($s['nom'] ?? '');
        $brandLogo = trim((string)($s['logo_url'] ?? ''));
    }
} catch (Throwable) {}
$brandLogoUrl = $brandLogo !== '' ? (function_exists('app_url') ? app_url('/' . ltrim($brandLogo, '/')) : '/' . ltrim($brandLogo, '/')) : '';

// ── Gate email ────────────────────────────────────────────────────────────
// Le gate d'identité ne concerne QUE le tiers destinataire. Un utilisateur
// interne connecté (staff) accède directement, pour visualiser/contrôler.
$staffView = !empty($_SESSION['user_id']) || !empty($_SESSION['id_user']);
$gated  = (int)$req['require_email_gate'] === 1;
$authed = !$gated || $staffView || !empty($_SESSION[$sessKey]);
$gateErr = null;
if ($gated && !$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_email'])) {
    $em = strtolower(trim((string)$_POST['gate_email']));
    if ($em !== '' && $em === strtolower(trim((string)$req['recipient_email']))) {
        $_SESSION[$sessKey] = 1; $authed = true;
    } else { $gateErr = 'Email non reconnu pour cette demande.'; }
}

// ── Dépôt d'une pièce ─────────────────────────────────────────────────────
$flash = null; $flashOk = true;
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $item = null;
    foreach (dr_items($pdo, $reqId) as $i) { if ((int)$i['id'] === $itemId) { $item = $i; break; } }
    if (!$item) { $flash = 'Pièce inconnue.'; $flashOk = false; }
    elseif ($item['kind'] === 'text') {
        $txt = trim((string)($_POST['text_value'] ?? ''));
        if ($txt === '') { $flash = 'Merci de saisir un texte.'; $flashOk = false; }
        else {
            $pdo->prepare("UPDATE document_request_items SET status='recu', text_value=?, received_at=NOW() WHERE id=?")
                ->execute([$txt, $itemId]);
            dr_recompute_status($pdo, $reqId);
            dr_notify_requester($pdo, $req, $item['label']);
            $flash = '« ' . $item['label'] . " » enregistré. Merci !";
        }
    } else {
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = 'Aucun fichier reçu (ou trop volumineux).'; $flashOk = false;
        } else {
            $f = $_FILES['file'];
            $chk = dr_allowed_upload((string)$f['name'], (string)($f['type'] ?? ''), (string)$f['tmp_name']);
            if ((int)$f['size'] > 25 * 1024 * 1024) { $flash = 'Fichier trop volumineux (max 25 Mo).'; $flashOk = false; }
            elseif (empty($chk['ok'])) { $flash = $chk['error'] ?? 'Fichier refusé.'; $flashOk = false; }
            else {
                $res = dr_commit_deposit($pdo, $req, $item, [
                    'tmp_path'      => $f['tmp_name'],
                    'name_original' => $f['name'],
                    'mime_type'     => $f['type'] ?? '',
                    'size_bytes'    => (int)$f['size'],
                ]);
                if (!empty($res['ok'])) {
                    dr_notify_requester($pdo, $req, $item['label']);
                    $flash = '« ' . $item['label'] . " » déposé. Merci !";
                } else { $flash = 'Échec du dépôt : ' . ($res['error'] ?? 'inconnu'); $flashOk = false; }
            }
        }
    }
    $req = dr_get_by_token($pdo, $token); // refresh statut
}

// ── Supprimer / remplacer un dépôt ────────────────────────────────────────
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $item = null;
    foreach (dr_items($pdo, $reqId) as $i) { if ((int)$i['id'] === $itemId) { $item = $i; break; } }
    if ($item && ($item['status'] ?? '') === 'recu') {
        try { dr_delete_deposit($pdo, $req, $item); $flash = 'Document retiré — vous pouvez en déposer un nouveau.'; }
        catch (Throwable $e) { $flash = 'Suppression impossible.'; $flashOk = false; }
    } else { $flash = 'Pièce introuvable.'; $flashOk = false; }
    $req = dr_get_by_token($pdo, $token);
}

// ── Rotation d'une image déposée ──────────────────────────────────────────
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rotate_item') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $deg    = (int)($_POST['deg'] ?? 90);
    $item = null;
    foreach (dr_items($pdo, $reqId) as $i) { if ((int)$i['id'] === $itemId) { $item = $i; break; } }
    if ($item && ($item['status'] ?? '') === 'recu') {
        $r = dr_rotate_item_image($pdo, $item, $deg);
        if (!empty($r['ok'])) { $flash = 'Image pivotée.'; } else { $flash = $r['error'] ?? 'Rotation impossible.'; $flashOk = false; }
    }
    $req = dr_get_by_token($pdo, $token);
}

// ── Dépôt d'une pièce LIBRE (hors checklist) ──────────────────────────────
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_free') {
    $freeLabel = trim((string)($_POST['free_label'] ?? '')) ?: 'Autre document';
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flash = 'Aucun fichier reçu.'; $flashOk = false;
    } else {
        $f = $_FILES['file'];
        $chk = dr_allowed_upload((string)$f['name'], (string)($f['type'] ?? ''), (string)$f['tmp_name']);
        if ((int)$f['size'] > 25 * 1024 * 1024) { $flash = 'Fichier trop volumineux (max 25 Mo).'; $flashOk = false; }
        elseif (empty($chk['ok'])) { $flash = $chk['error'] ?? 'Fichier refusé.'; $flashOk = false; }
        else {
            // Crée une pièce à la volée, rattachée à l'entité de la demande, puis dépose.
            $ins = $pdo->prepare("INSERT INTO document_request_items
                (request_id, label, doc_type, kind, max_files, entity_type, entity_id, required, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->execute([$reqId, $freeLabel, 'AUTRE', 'file', 1,
                $req['entity_type'] ?? null, isset($req['entity_id']) ? (int)$req['entity_id'] : null, 0, 999]);
            $newId = (int)$pdo->lastInsertId();
            $item = null;
            foreach (dr_items($pdo, $reqId) as $i) { if ((int)$i['id'] === $newId) { $item = $i; break; } }
            if ($item) {
                $res = dr_commit_deposit($pdo, $req, $item, [
                    'tmp_path' => $f['tmp_name'], 'name_original' => $f['name'],
                    'mime_type' => $f['type'] ?? '', 'size_bytes' => (int)$f['size'],
                ]);
                if (!empty($res['ok'])) { dr_notify_requester($pdo, $req, $freeLabel); $flash = '« ' . $freeLabel . " » déposé. Merci !"; }
                else { $flash = 'Échec du dépôt : ' . ($res['error'] ?? 'inconnu'); $flashOk = false; }
            }
        }
    }
    $req = dr_get_by_token($pdo, $token);
}

// Pièces déjà présentes en GED (déposées par l'agence) → marquées « reçues »
// pour que le déposant ne les recharge pas.
try { dr_autofulfill_from_ged($pdo, $reqId); } catch (Throwable) {}
$items = dr_items($pdo, $reqId);
$total = count($items);
$recus = count(array_filter($items, fn($i) => $i['status'] === 'recu'));
function dh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Rattachement lisible (calculé une fois) + titre affiché « propre ».
$rattach = function_exists('dr_entity_label') ? dr_entity_label($pdo, $req['entity_type'] ?? '', (int)($req['entity_id'] ?? 0)) : ['icon'=>'','type'=>'','label'=>''];
$titreAffiche = (string)$req['titre'];
// Si le titre est générique (ex. « BIEN #843 ») et qu'on a un libellé lisible, on le remplace.
if ($rattach['label'] !== '' && (trim($titreAffiche) === '' || preg_match('/#\s*\d+/', $titreAffiche))) {
    $titreAffiche = 'Documents — ' . $rattach['label'];
}
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dépôt de documents — <?= dh($req['titre']) ?></title>
<style>
*{box-sizing:border-box} body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1a2330}
.wrap{max-width:760px;margin:0 auto;padding:26px 18px 60px}
.brand{display:flex;align-items:center;gap:20px;background:#fff;border:1px solid #e3e8ef;border-radius:16px;padding:18px 22px;margin-bottom:16px;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.brand img{height:74px;max-width:200px;width:auto;object-fit:contain;flex-shrink:0}
.brand .brand-txt{min-width:0}
.brand .brand-nom{font-size:17px;font-weight:800;color:#243B5C;line-height:1.2}
.brand .brand-tag{font-size:13.5px;color:#5a6678;margin-top:4px;font-style:italic}
@media(max-width:560px){.brand{flex-direction:column;text-align:center;gap:12px}.brand img{height:60px}}
.head{background:#243B5C;color:#fff;border-radius:16px;padding:22px 24px;margin-bottom:20px}
.head h1{margin:0 0 6px;font-size:20px} .head p{margin:0;opacity:.85;font-size:14px}
.prog{height:9px;background:rgba(255,255,255,.25);border-radius:6px;margin-top:14px;overflow:hidden}
.prog>i{display:block;height:100%;background:#D4A047;border-radius:6px}
.flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
.flash.ok{background:#e7f6ec;color:#176a3a;border:1px solid #b6e3c6}
.flash.ko{background:#fdecec;color:#a01818;border:1px solid #f3bcbc}
.items-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
@media(max-width:680px){.items-grid{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:16px 18px;margin-bottom:0;box-shadow:0 1px 3px rgba(15,23,42,.05);display:flex;flex-direction:column}
.card .btn{margin-top:auto}
/* Dropzone glisser-déposer */
.drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;text-align:center;
  padding:18px 12px;border:2px dashed #c3ccd8;border-radius:12px;background:#fafbfc;cursor:pointer;transition:.15s}
.drop:hover{border-color:#0e7490;background:#f3fbfd}
.drop.drag{border-color:#0e7490;background:#e6f7fb;transform:scale(1.01)}
.drop .drop-hint{font-size:13px;color:#5a6678;font-weight:600;pointer-events:none}
.drop .drop-file{font-size:12.5px;color:#0e7490;font-weight:700;pointer-events:none;word-break:break-all}
.drop input[type=file]{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden}
.card.done{border-color:#b6e3c6;background:#f6fcf8}
.card .lbl{font-weight:700;font-size:15px;margin-bottom:4px}
.card .meta{font-size:12px;color:#7a8694;margin-bottom:12px}
.badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px}
.badge.req{background:#fef3d8;color:#b7791f} .badge.opt{background:#eef1f5;color:#7a8694}
.badge.recu{background:#e7f6ec;color:#176a3a}
.drop input[type=file]{width:100%;padding:10px;border:1.5px dashed #c3ccd8;border-radius:10px;background:#fafbfc}
textarea{width:100%;min-height:110px;padding:11px;border:1px solid #c3ccd8;border-radius:10px;font-family:inherit;font-size:14px}
.btn{margin-top:10px;background:#0e7490;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:700;font-size:14px;cursor:pointer}
.btn:hover{background:#0c6480}
.gate{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:24px;max-width:440px;margin:30px auto}
.gate input{width:100%;padding:11px;border:1px solid #c3ccd8;border-radius:10px;font-size:14px;margin:10px 0}
.foot{text-align:center;color:#9aa4b1;font-size:12px;margin-top:24px}
.note-rappel{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:8px 11px;margin-bottom:10px;font-size:12.5px;color:#78350f;line-height:1.5}
.acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:auto}
.mini{background:#fff;border:1px solid #c3ccd8;border-radius:8px;padding:6px 10px;font-size:12.5px;font-weight:700;color:#0e7490;cursor:pointer}
.mini:hover{background:#f3fbfd;border-color:#0e7490}
.dmodal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.7);z-index:9999;padding:24px}
.dmodal.open{display:flex;align-items:center;justify-content:center}
.dmodal-box{background:#fff;border-radius:14px;max-width:920px;width:100%;max-height:92vh;position:relative;padding:14px;overflow:auto}
.dmodal-x{position:absolute;top:8px;right:10px;background:#243B5C;color:#fff;border:none;border-radius:8px;width:32px;height:32px;font-size:15px;cursor:pointer;z-index:2}
.dmodal-box img{max-width:100%;height:auto;display:block;margin:0 auto}
.dmodal-box iframe{width:100%;height:80vh;border:none}
</style></head><body>
<div class="wrap">
  <?php if ($staffView): ?>
  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#92400e">
    👁️ <strong>Aperçu interne</strong> — voici la page telle que la voit le destinataire (vous n'avez pas à confirmer votre identité).
    <?php if ($rattach && $rattach['label']): ?><br>📎 Rattachée à : <strong><?= dh($rattach['icon'].' '.$rattach['type'].' — '.$rattach['label']) ?></strong><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($brandLogoUrl !== '' || $brandNom !== ''): ?>
  <div class="brand">
    <?php if ($brandLogoUrl !== ''): ?><img src="<?= dh($brandLogoUrl) ?>" alt="<?= dh($brandNom ?: 'Logo agence') ?>"><?php endif; ?>
    <div class="brand-txt">
      <?php if ($brandNom !== ''): ?><div class="brand-nom"><?= dh($brandNom) ?></div><?php endif; ?>
      <div class="brand-tag">Qualité et innovation au service de nos clients — pour plus de simplicité, de sérénité et d'efficacité.</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="head">
    <h1><?= dh($titreAffiche) ?></h1>
    <p>Merci de déposer les documents demandés ci-dessous. Dépôt sécurisé.</p>
    <?php if ($authed): ?><div class="prog"><i style="width:<?= $total ? round($recus*100/$total) : 0 ?>%"></i></div>
    <p style="margin-top:8px"><?= $recus ?>/<?= $total ?> pièce(s) reçue(s)</p><?php endif; ?>
  </div>

  <?php if ($flash): ?><div class="flash <?= $flashOk?'ok':'ko' ?>"><?= dh($flash) ?></div><?php endif; ?>

  <?php if ($gated && !$authed): ?>
    <div class="gate">
      <h2 style="margin:0 0 6px;font-size:17px">Confirmez votre identité</h2>
      <p style="color:#7a8694;font-size:13px;margin:0">Saisissez l'adresse email à laquelle cette demande vous a été envoyée.</p>
      <?php if ($gateErr): ?><div class="flash ko" style="margin-top:12px"><?= dh($gateErr) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="t" value="<?= dh($token) ?>">
        <input type="email" name="gate_email" placeholder="votre@email.fr" required>
        <button class="btn" type="submit">Accéder à la demande</button>
      </form>
    </div>
  <?php else: ?>
    <div class="items-grid">
    <?php foreach ($items as $it): $done = $it['status'] === 'recu'; ?>
      <div class="card <?= $done?'done':'' ?>">
        <?php if (!empty($it['note'])): ?><div class="note-rappel">📝 <strong>À noter :</strong> <?= nl2br(dh($it['note'])) ?></div><?php endif; ?>
        <div class="lbl"><?= dh($it['label']) ?>
          <?php if ($done): ?><span class="badge recu"><?= (trim((string)($it['original_name'] ?? ''))==='[déjà présent en GED]') ? '✅ Déjà fourni (dans nos dossiers)' : '✅ Reçu' ?></span>
          <?php elseif ((int)$it['required']===1): ?><span class="badge req">Requis</span>
          <?php else: ?><span class="badge opt">Optionnel</span><?php endif; ?>
        </div>
        <?php if ($it['period']): ?><div class="meta">Période : <?= dh($it['period']) ?></div><?php endif; ?>
        <?php if (!$done): ?>
          <form method="post" <?= $it['kind']!=='text'?'enctype="multipart/form-data"':'' ?>>
            <input type="hidden" name="t" value="<?= dh($token) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <?php if ($it['kind']==='text'): ?>
              <textarea name="text_value" placeholder="Saisissez ici…" required></textarea>
            <?php else: ?>
              <label class="drop">
                <span class="drop-hint">📎 Glissez le fichier ici<br>ou cliquez pour choisir</span>
                <span class="drop-file"></span>
                <input type="file" name="file" required>
              </label>
            <?php endif; ?>
            <button class="btn" type="submit"><?= $it['kind']==='text'?'Enregistrer':'Déposer' ?></button>
          </form>
        <?php elseif ($it['kind']==='text' && $it['text_value']): ?>
          <div class="meta" style="white-space:pre-wrap;color:#1a2330"><?= dh($it['text_value']) ?></div>
        <?php else:
          $oName  = trim((string)($it['original_name'] ?? ''));
          $dejaGed = ($oName === '[déjà présent en GED]');
          $oExt   = strtolower(pathinfo($oName, PATHINFO_EXTENSION));
          $isImg  = in_array($oExt, ['jpg','jpeg','png','gif','webp','bmp'], true);
          $viewUrl = 'document_depot_file.php?t=' . dh($token) . '&item=' . (int)$it['id'];
        ?>
          <?php if ($oName !== '' && !$dejaGed): ?><div class="meta" style="color:#176a3a">📄 <?= dh($oName) ?></div><?php endif; ?>
          <div class="acts">
            <button type="button" class="mini" onclick="depotView('<?= $viewUrl ?>', <?= $isImg?'1':'0' ?>)">👁️ Voir</button>
            <?php if ($isImg): ?>
              <form method="post" style="display:inline"><input type="hidden" name="t" value="<?= dh($token) ?>"><input type="hidden" name="action" value="rotate_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="deg" value="90">
                <button type="submit" class="mini" title="Pivoter à droite">⟳</button></form>
              <form method="post" style="display:inline"><input type="hidden" name="t" value="<?= dh($token) ?>"><input type="hidden" name="action" value="rotate_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="deg" value="270">
                <button type="submit" class="mini" title="Pivoter à gauche">⟲</button></form>
            <?php endif; ?>
            <?php if (!$dejaGed): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Retirer ce document pour en déposer un autre ?')"><input type="hidden" name="t" value="<?= dh($token) ?>"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <button type="submit" class="mini">🗑️ Remplacer / supprimer</button></form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
      <?php if ($authed): ?>
      <div class="card">
        <div class="lbl">➕ Autre document <span class="badge opt">Optionnel</span></div>
        <div class="meta">Un document en plus, hors liste ci-dessus ? Déposez-le ici.</div>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="t" value="<?= dh($token) ?>">
          <input type="hidden" name="action" value="upload_free">
          <input type="text" name="free_label" placeholder="Intitulé (ex. RIB, attestation…)" style="width:100%;padding:9px;border:1px solid #c3ccd8;border-radius:9px;margin-bottom:8px;font-size:13px">
          <label class="drop">
            <span class="drop-hint">📎 Glissez le fichier ici<br>ou cliquez pour choisir</span>
            <span class="drop-file"></span>
            <input type="file" name="file" required>
          </label>
          <button class="btn" type="submit">Déposer</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <div id="depotModal" class="dmodal" onclick="if(event.target===this)depotClose()">
      <div class="dmodal-box">
        <button type="button" class="dmodal-x" onclick="depotClose()">✕</button>
        <div id="depotModalBody"></div>
      </div>
    </div>
    <?php if ($total && $recus >= $total): ?>
      <div class="flash ok" style="margin-top:14px">🎉 Tous les documents ont été déposés. Merci !</div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="foot">MaBoxImmo · dépôt sécurisé</div>
<script>
(function(){
  document.querySelectorAll('label.drop').forEach(function(zone){
    var input = zone.querySelector('input[type=file]');
    var fileLbl = zone.querySelector('.drop-file');
    var form = zone.closest('form');
    function show(){ if(input.files && input.files.length){ fileLbl.textContent = '✅ ' + (input.files.length>1 ? input.files.length+' fichiers' : input.files[0].name) + ' — envoi…'; } }
    ['dragenter','dragover'].forEach(function(e){ zone.addEventListener(e,function(ev){ ev.preventDefault(); zone.classList.add('drag'); }); });
    ['dragleave','dragend','drop'].forEach(function(e){ zone.addEventListener(e,function(ev){ ev.preventDefault(); zone.classList.remove('drag'); }); });
    zone.addEventListener('drop', function(ev){
      var files = ev.dataTransfer && ev.dataTransfer.files; if(!files || !files.length) return;
      try { input.files = files; } catch(e){ var dt=new DataTransfer(); for(var i=0;i<files.length;i++) dt.items.add(files[i]); input.files=dt.files; }
      show(); if(form) (form.requestSubmit?form.requestSubmit():form.submit());
    });
    input.addEventListener('change', function(){ show(); if(input.files && input.files.length && form) (form.requestSubmit?form.requestSubmit():form.submit()); });
  });
})();
function depotView(url, isImg){
  var body = document.getElementById('depotModalBody');
  body.innerHTML = isImg
    ? '<img src="'+url+'" alt="aperçu">'
    : '<iframe src="'+url+'"></iframe>';
  body.innerHTML += '<div style="text-align:center;margin-top:10px"><a href="'+url+'&dl=1" class="btn" style="text-decoration:none;display:inline-block">⬇️ Télécharger</a></div>';
  document.getElementById('depotModal').classList.add('open');
}
function depotClose(){ document.getElementById('depotModal').classList.remove('open'); document.getElementById('depotModalBody').innerHTML=''; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') depotClose(); });
</script>
</div>
</body></html>
