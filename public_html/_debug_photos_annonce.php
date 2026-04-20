<?php
// _debug_photos_annonce.php — DIAGNOSTIC TEMPORAIRE : photos bien <-> annonce
// Usage : /MaBoxImmo2026/public_html/_debug_photos_annonce.php?id_bien=123
// A supprimer apres resolution du probleme de chargement des photos en Card Annonce.
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);
$idBien    = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien']) ? (int)$_GET['id_bien'] : 0;

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Debug photos annonce</title>
<style>
  body { font-family: -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif; max-width:900px; margin:20px auto; padding:0 14px; color:#222; }
  h1 { font-size: 18px; }
  h2 { font-size: 15px; margin-top: 24px; color:#36577d; }
  table { border-collapse: collapse; margin: 8px 0; font-size: 13px; }
  td, th { border: 1px solid #ddd; padding: 5px 10px; text-align: left; }
  th { background: #f5f7fa; }
  .ok { color: #0ea572; font-weight: 700; }
  .ko { color: #dc2626; font-weight: 700; }
  .warn { color: #b45309; }
  code { background: #f5f7fa; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
  form { margin: 14px 0; }
  input[type=number] { padding: 6px 10px; font-size: 14px; border: 1px solid #ccc; border-radius: 6px; }
  button { padding: 7px 14px; background: #36577d; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
  img { max-height: 60px; border-radius: 4px; vertical-align: middle; }
</style></head><body>
<h1>🔍 Debug photos annonce — <small>session user #<?= $userId ?> · société #<?= $societeId ?></small></h1>

<form method="get">
  <label>id_bien à inspecter : <input type="number" name="id_bien" value="<?= $idBien ?: '' ?>" min="1" required></label>
  <button type="submit">Inspecter</button>
</form>

<?php if ($idBien <= 0): ?>
  <p>👉 Entre un <code>id_bien</code> ci-dessus. Tu peux le lire dans l'URL de <code>bien_detail_v2.php?edit=<strong>X</strong></code>.</p>
<?php else: ?>

<?php
// 1. Le bien existe-t-il ? + scope
$stB = $pdo->prepare("SELECT id, reference_bien, designation, id_societe, id_agence FROM biens WHERE id = ? LIMIT 1");
$stB->execute([$idBien]);
$bien = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
?>
<h2>1. Bien #<?= $idBien ?> en base</h2>
<?php if (!$bien): ?>
  <p class="ko">❌ Aucune ligne dans <code>biens</code> avec id = <?= $idBien ?></p>
  <p>→ Impossible d'afficher des photos : le bien n'existe pas (ou a été supprimé).</p>
<?php else: ?>
  <table>
    <tr><th>id</th><td><?= (int)$bien['id'] ?></td></tr>
    <tr><th>reference_bien</th><td><?= htmlspecialchars((string)($bien['reference_bien'] ?? '')) ?></td></tr>
    <tr><th>designation</th><td><?= htmlspecialchars((string)($bien['designation'] ?? '')) ?></td></tr>
    <tr><th>id_societe</th><td><?= (int)$bien['id_societe'] ?> <?= ($societeId > 0 && (int)$bien['id_societe'] !== $societeId) ? '<span class="ko">⚠️ Pas dans TA société ('.$societeId.')</span>' : '<span class="ok">(OK)</span>' ?></td></tr>
    <tr><th>id_agence</th><td><?= (int)$bien['id_agence'] ?></td></tr>
  </table>
<?php endif; ?>

<h2>2. Photos du bien (<code>biens_photos</code> WHERE id_bien = <?= $idBien ?>)</h2>
<?php
$stP = $pdo->prepare("SELECT id, url_photo, nom_original, ordre, description_ia FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
$stP->execute([$idBien]);
$photos = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
$countPhotos = count($photos);
?>
<p><strong><?= $countPhotos ?> photo(s)</strong> trouvée(s) en base pour ce bien.</p>
<?php if ($countPhotos === 0): ?>
  <p class="ko">❌ Aucune photo n'est rattachée à ce bien en base.</p>
  <p>→ C'est la cause la plus probable du problème : il faut uploader des photos via Documents → Chargement (la dropzone photos) <strong>sur CE bien</strong>.</p>
  <p class="warn">⚠️ Si tu avais uploadé des photos avant, elles ont peut-être été attachées à un autre <code>id_bien</code> (brouillon intake). Vérifie aussi :</p>
  <?php
    $stOther = $pdo->prepare("SELECT id_bien, COUNT(*) c FROM biens_photos GROUP BY id_bien HAVING c > 0 ORDER BY MAX(id) DESC LIMIT 10");
    $stOther->execute();
    $otherBiens = $stOther->fetchAll(PDO::FETCH_ASSOC) ?: [];
  ?>
  <table>
    <tr><th>id_bien</th><th>nb photos</th><th>lien</th></tr>
    <?php foreach ($otherBiens as $row): ?>
      <tr>
        <td><?= (int)$row['id_bien'] ?></td>
        <td><?= (int)$row['c'] ?></td>
        <td><a href="?id_bien=<?= (int)$row['id_bien'] ?>">Inspecter</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p><small>Les 10 biens avec le plus de photos en base (toutes sociétés confondues).</small></p>
<?php else: ?>
  <table>
    <tr><th>id</th><th>ordre</th><th>preview</th><th>url_photo</th><th>IA ?</th></tr>
    <?php foreach ($photos as $p): ?>
      <tr>
        <td><?= (int)$p['id'] ?></td>
        <td><?= (int)$p['ordre'] ?></td>
        <td><?php if (!empty($p['url_photo'])): ?><img src="<?= htmlspecialchars(app_url('/' . ltrim((string)$p['url_photo'], '/'))) ?>" alt=""><?php endif; ?></td>
        <td><small><?= htmlspecialchars((string)($p['url_photo'] ?? '')) ?></small></td>
        <td><?= !empty($p['description_ia']) ? '<span class="ok">✓</span>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<h2>3. Annonce du bien (<code>annonces</code> WHERE id_bien = <?= $idBien ?>)</h2>
<?php
$stA = $pdo->prepare("SELECT id, type_transaction, etat_publication, date_creation FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
$stA->execute([$idBien]);
$ann = $stA->fetch(PDO::FETCH_ASSOC) ?: null;
?>
<?php if (!$ann): ?>
  <p class="ko">❌ Aucune annonce pour ce bien.</p>
  <p>→ Va sur Annonce → Card 1 et clique « ➕ Créer l'annonce (brouillon) ».</p>
<?php else: ?>
  <table>
    <tr><th>id_annonce</th><td><?= (int)$ann['id'] ?></td></tr>
    <tr><th>type_transaction</th><td><?= htmlspecialchars((string)($ann['type_transaction'] ?? '')) ?></td></tr>
    <tr><th>etat_publication</th><td><?= htmlspecialchars((string)($ann['etat_publication'] ?? '')) ?></td></tr>
    <tr><th>date_creation</th><td><?= htmlspecialchars((string)($ann['date_creation'] ?? '')) ?></td></tr>
  </table>

  <h2>4. Photos sélectionnées pour cette annonce (<code>annonces_photos</code> WHERE id_annonce = <?= (int)$ann['id'] ?>)</h2>
  <?php
  $stAP = $pdo->prepare("SELECT ap.id_biens_photo, ap.ordre, bp.url_photo, bp.id_bien AS photo_id_bien
                         FROM annonces_photos ap
                         LEFT JOIN biens_photos bp ON bp.id = ap.id_biens_photo
                         WHERE ap.id_annonce = ?
                         ORDER BY ap.ordre ASC, ap.id ASC");
  $stAP->execute([(int)$ann['id']]);
  $selPhotos = $stAP->fetchAll(PDO::FETCH_ASSOC) ?: [];
  ?>
  <p><strong><?= count($selPhotos) ?> photo(s)</strong> rattachée(s) à l'annonce.</p>
  <?php if ($countPhotos > 0 && count($selPhotos) === 0): ?>
    <p class="warn">⚠️ Les photos existent sur le bien mais <strong>aucune n'est sélectionnée pour l'annonce</strong>. C'est ici que tu dois cliquer sur « 🔄 Récupérer les photos du bien » dans la Card 4 Annonce.</p>
  <?php endif; ?>
  <?php if (!empty($selPhotos)): ?>
    <table>
      <tr><th>id_biens_photo</th><th>ordre</th><th>preview</th><th>id_bien de la photo</th></tr>
      <?php foreach ($selPhotos as $sp): ?>
        <tr>
          <td><?= (int)$sp['id_biens_photo'] ?></td>
          <td><?= (int)$sp['ordre'] ?></td>
          <td><?php if (!empty($sp['url_photo'])): ?><img src="<?= htmlspecialchars(app_url('/' . ltrim((string)$sp['url_photo'], '/'))) ?>" alt=""><?php endif; ?></td>
          <td><?= (int)$sp['photo_id_bien'] ?>
              <?= ((int)$sp['photo_id_bien'] !== $idBien) ? ' <span class="ko">⚠️ MISMATCH (pas le bon bien)</span>' : ' <span class="ok">(OK)</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php endif; ?>

<h2>5. Verdict</h2>
<ul>
  <?php if (!$bien): ?>
    <li class="ko">Bien inexistant → impossible.</li>
  <?php elseif ($countPhotos === 0): ?>
    <li class="ko">biens_photos = 0 pour id_bien <?= $idBien ?> → upload via Documents → Chargement nécessaire.</li>
  <?php elseif (!$ann): ?>
    <li class="warn">Photos présentes sur le bien mais pas d'annonce → crée l'annonce (Card 1 Annonce).</li>
  <?php elseif (count($selPhotos) === 0): ?>
    <li class="ok">Photos présentes (<?= $countPhotos ?>) et annonce existante (#<?= (int)$ann['id'] ?>) → clique « 🔄 Récupérer les photos du bien » dans Card 4 Annonce.</li>
  <?php else: ?>
    <li class="ok">Tout est OK : <?= $countPhotos ?> photos sur le bien, <?= count($selPhotos) ?> sélectionnées dans l'annonce.</li>
  <?php endif; ?>
</ul>

<?php endif; ?>

<p><small>📄 <code>_debug_photos_annonce.php</code> — script temporaire. À supprimer après résolution.</small></p>
</body></html>
