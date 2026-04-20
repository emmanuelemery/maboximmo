<?php
declare(strict_types=1);

/**
 * Outil admin : rattrapage compression photos biens.
 *
 * Parcourt `biens_photos` et, pour chaque ligne qui n'a pas été traitée par
 * le nouveau BienPhotosManager, :
 *   1. Recompresse l'original à 2500 px max, JPEG q85 (supprime métadata EXIF)
 *   2. Génère la variante LBC 1200 px, JPEG < 1,9 Mo
 *   3. Met à jour largeur/hauteur/poids/hash_md5/url_lbc (+ url_photo si
 *      l'extension change : .png/.webp → .jpg)
 *
 * Traitement en batch (par défaut 10 photos / requête) pour éviter les
 * timeouts PHP sur les gros catalogues.
 *
 * Accès : role_id = 1 uniquement.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/image_tools.php';
require_once __DIR__ . '/../inc/bien_photos_manager.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = $GLOBALS['pdo'];

const BATCH_SIZE             = 10;
const NEED_RECOMPRESS_WIDTH  = BienPhotosManager::ORIGINAL_MAX_WIDTH;
const NEED_RECOMPRESS_BYTES  = 2_000_000;

/**
 * Critère : photo à retraiter si url_lbc NULL, OU largeur > 2500, OU poids > 2Mo.
 * (On utilise COALESCE pour gérer les anciennes lignes sans largeur/poids : on les
 * considère comme « à vérifier ».)
 */
function photos_needing_work_count(PDO $pdo): int
{
    $sql = "SELECT COUNT(*) FROM biens_photos
            WHERE url_lbc IS NULL
               OR largeur IS NULL
               OR largeur > " . (int)NEED_RECOMPRESS_WIDTH . "
               OR poids_octets IS NULL
               OR poids_octets > " . (int)NEED_RECOMPRESS_BYTES;
    return (int)$pdo->query($sql)->fetchColumn();
}

function photos_to_process(PDO $pdo, int $limit): array
{
    $sql = "SELECT id, id_bien, url_photo, url_lbc, largeur, hauteur, poids_octets
            FROM biens_photos
            WHERE url_lbc IS NULL
               OR largeur IS NULL
               OR largeur > " . (int)NEED_RECOMPRESS_WIDTH . "
               OR poids_octets IS NULL
               OR poids_octets > " . (int)NEED_RECOMPRESS_BYTES . "
            ORDER BY id ASC
            LIMIT " . (int)$limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Traite une photo. Retourne ['ok'=>bool, 'msg'=>string, 'gain_bytes'=>int].
 */
function process_one_photo(PDO $pdo, array $row): array
{
    $baseDir = dirname(__DIR__) . '/';
    $urlPhoto = (string)($row['url_photo'] ?? '');
    $srcRel   = ltrim($urlPhoto, '/');
    $srcAbs   = $baseDir . $srcRel;

    if (!is_file($srcAbs) || !is_readable($srcAbs)) {
        return ['ok' => false, 'msg' => "Fichier introuvable : {$urlPhoto}", 'gain_bytes' => 0];
    }

    $origBytes = (int)(@filesize($srcAbs) ?: 0);

    $loaded = it_load_and_orient($srcAbs);
    if (!$loaded) {
        return ['ok' => false, 'msg' => "Format illisible : {$urlPhoto}", 'gain_bytes' => 0];
    }
    [$imgRes, $srcExt] = $loaded;

    $srcDirAbs = dirname($srcAbs) . '/';
    $srcBaseNoExt = pathinfo($srcRel, PATHINFO_FILENAME);
    $srcDirRel = dirname($srcRel) . '/';

    // Chemin final JPEG (même dossier, même nom, extension .jpg)
    $destFile = $srcBaseNoExt . '.jpg';
    $destAbs  = $srcDirAbs . $destFile;
    $destRel  = $srcDirRel . $destFile;
    $lbcFile  = $srcBaseNoExt . BienPhotosManager::LBC_SUFFIX;
    $lbcAbs   = $srcDirAbs . $lbcFile;
    $lbcRel   = $srcDirRel . $lbcFile;

    // Original compressé
    $origRes = it_resize_max_width($imgRes, BienPhotosManager::ORIGINAL_MAX_WIDTH);
    $origIsNew = ($origRes !== $imgRes);
    // Écriture dans un fichier temporaire pour ne pas corrompre la source si
    // elle est aussi la destination (même chemin quand l'extension est déjà .jpg).
    $tmpOrig = $destAbs . '.tmp';
    if (!it_save_jpeg($origRes, $tmpOrig, BienPhotosManager::ORIGINAL_QUALITY)) {
        if ($origIsNew) imagedestroy($origRes);
        imagedestroy($imgRes);
        return ['ok' => false, 'msg' => "Échec écriture JPEG pour {$urlPhoto}", 'gain_bytes' => 0];
    }

    // Variante LBC
    $lbcRes = it_resize_max_width($imgRes, BienPhotosManager::LBC_MAX_WIDTH);
    $lbcIsNew = ($lbcRes !== $imgRes);
    $lbcTmp  = $lbcAbs . '.tmp';
    $lbcQ = it_save_jpeg_under_size($lbcRes, $lbcTmp, BienPhotosManager::LBC_MAX_BYTES);
    if ($lbcIsNew) imagedestroy($lbcRes);

    if ($origIsNew) imagedestroy($origRes);
    imagedestroy($imgRes);

    // Activation atomique : rename du .tmp vers le nom final
    if (!@rename($tmpOrig, $destAbs)) {
        @unlink($tmpOrig);
        @unlink($lbcTmp);
        return ['ok' => false, 'msg' => "Échec rename JPEG pour {$urlPhoto}", 'gain_bytes' => 0];
    }
    if ($lbcQ > 0) {
        if (!@rename($lbcTmp, $lbcAbs)) @unlink($lbcTmp);
    }

    // Si l'extension a changé (ex: .png → .jpg), supprime le vieux fichier
    if ($srcAbs !== $destAbs && is_file($srcAbs)) {
        @unlink($srcAbs);
    }

    // Mesures finales
    $m = it_measure($destAbs);
    $newBytes = $m['poids'];
    $hash = md5_file($destAbs) ?: null;

    // Update BDD
    $stmt = $pdo->prepare(
        "UPDATE biens_photos
         SET url_photo = ?, url_lbc = ?, largeur = ?, hauteur = ?, poids_octets = ?, hash_md5 = ?, mime_type = 'image/jpeg'
         WHERE id = ?"
    );
    $stmt->execute([
        $destRel,
        $lbcQ > 0 ? $lbcRel : null,
        $m['largeur'] ?: null,
        $m['hauteur'] ?: null,
        $newBytes ?: null,
        $hash,
        (int)$row['id'],
    ]);

    $gain = max(0, $origBytes - $newBytes);
    return [
        'ok'         => true,
        'msg'        => "#{$row['id']} {$urlPhoto} → {$destRel} (" . number_format($origBytes / 1024, 0, ',', ' ') . " Ko → " . number_format($newBytes / 1024, 0, ',', ' ') . " Ko) · LBC q={$lbcQ}",
        'gain_bytes' => $gain,
    ];
}

$flash = null;
$logs  = [];
$gainTotal = 0;
$processed = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_batch') {
    verify_csrf('tools_photos_recompress');
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $rows = photos_to_process($pdo, BATCH_SIZE);
    foreach ($rows as $r) {
        $res = process_one_photo($pdo, $r);
        $logs[] = ($res['ok'] ? '✅ ' : '❌ ') . $res['msg'];
        if ($res['ok']) {
            $processed++;
            $gainTotal += $res['gain_bytes'];
        }
    }
    $flash = [
        'type' => $processed > 0 ? 'success' : 'warning',
        'msg'  => "Batch traité : {$processed} photo(s) recompressée(s) — gain " . number_format($gainTotal / 1024 / 1024, 2, ',', ' ') . " Mo",
    ];
}

$remaining = photos_needing_work_count($pdo);
$total     = (int)$pdo->query("SELECT COUNT(*) FROM biens_photos")->fetchColumn();
$done      = $total - $remaining;
$pct       = $total > 0 ? (int)round($done / $total * 100) : 100;
$csrf      = csrf_token('tools_photos_recompress');

$appLayout = true;
$pageTitle = 'Rattrapage compression photos';
$bodyClass = '';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
  .rec-wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px; }
  .rec-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .rec-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; }
  .rec-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
  .rec-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; }
  .rec-card .k { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
  .rec-card .v { color: #0f172a; font-size: 22px; font-weight: 700; margin-top: 4px; }
  .rec-bar { height: 10px; background: #f1f5f9; border-radius: 99px; overflow: hidden; margin: 8px 0 18px; }
  .rec-bar > span { display: block; height: 100%; background: linear-gradient(90deg, #22c55e, #16a34a); transition: width .4s ease; }
  .rec-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
  .rec-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .rec-flash.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
  .rec-btn { padding: 10px 18px; border-radius: 10px; background: #0ea5e9; color: #fff; border: none; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .rec-btn:hover { background: #0284c7; }
  .rec-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
  .rec-logs { background: #0f172a; color: #e2e8f0; font-family: monospace; font-size: 11px; padding: 14px 16px; border-radius: 10px; max-height: 360px; overflow-y: auto; margin-top: 18px; line-height: 1.6; }
  .rec-logs div { white-space: pre-wrap; word-break: break-all; }
</style>

<div class="rec-wrap">
  <h1>🖼️ Rattrapage compression photos</h1>
  <p class="sub">Recompresse les photos existantes (2500 px / JPEG q85) et génère la variante Le Bon Coin (1200 px / JPEG &lt; 2 Mo). Lance le batch autant de fois qu'il reste des photos à traiter.</p>

  <?php if ($flash): ?>
    <div class="rec-flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="rec-stats">
    <div class="rec-card"><div class="k">Total photos</div><div class="v"><?= $total ?></div></div>
    <div class="rec-card"><div class="k">Déjà traitées</div><div class="v" style="color:#16a34a;"><?= $done ?></div></div>
    <div class="rec-card"><div class="k">Restantes</div><div class="v" style="color:<?= $remaining > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $remaining ?></div></div>
  </div>

  <div class="rec-bar"><span style="width: <?= $pct ?>%"></span></div>

  <form method="post" style="display:flex; gap:10px; align-items:center;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="process_batch">
    <button type="submit" class="rec-btn" <?= $remaining === 0 ? 'disabled' : '' ?>>
      <?= $remaining === 0 ? '✅ Tout est traité' : "▶️ Traiter le prochain batch (" . min(BATCH_SIZE, $remaining) . ' photos)' ?>
    </button>
    <span style="color:#64748b; font-size:12px;">Progression : <?= $pct ?>%</span>
  </form>

  <?php if (!empty($logs)): ?>
    <div class="rec-logs">
      <?php foreach ($logs as $line): ?>
        <div><?= htmlspecialchars($line) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
