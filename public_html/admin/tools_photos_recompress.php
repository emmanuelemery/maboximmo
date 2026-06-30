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
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];

const BATCH_SIZE             = 10;
const NEED_RECOMPRESS_WIDTH  = BienPhotosManager::ORIGINAL_MAX_WIDTH;
const MIN_PROCESS_BYTES      = 800 * 1024;  // plancher : en dessous, on NE touche PAS

/**
 * Critère « à traiter » :
 *   - PLANCHER : la photo doit peser > 800 Ko (en dessous, recompresser dégrade /
 *     regonfle inutilement → on n'y touche jamais).
 *   - ET elle doit réellement avoir besoin de travail : pas encore de variante LBC,
 *     OU trop grande en pixels (> 2500 px).
 * On NE déclenche PAS sur « > 2 Mo » : une photo déjà ≤ 2500 px et q85 ne peut pas
 * descendre davantage — la reprendre bouclerait à l'infini sans aucun gain. Chaque
 * photo n'est donc traitée qu'UNE fois (création LBC / resize), puis sort de la liste.
 */
function photos_needing_work_count(PDO $pdo): int
{
    $sql = "SELECT COUNT(*) FROM biens_photos
            WHERE poids_octets > " . (int)MIN_PROCESS_BYTES . "
              AND (url_lbc IS NULL OR largeur > " . (int)NEED_RECOMPRESS_WIDTH . ")";
    return (int)$pdo->query($sql)->fetchColumn();
}

function photos_to_process(PDO $pdo, int $limit): array
{
    $sql = "SELECT id, id_bien, url_photo, url_lbc, largeur, hauteur, poids_octets
            FROM biens_photos
            WHERE poids_octets > " . (int)MIN_PROCESS_BYTES . "
              AND (url_lbc IS NULL OR largeur > " . (int)NEED_RECOMPRESS_WIDTH . ")
            ORDER BY id ASC
            LIMIT " . (int)$limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Diagnostic : liste les lignes biens_photos dont le fichier original est absent
 * du disque (photos orphelines). Ne modifie RIEN. Retourne un tableau de lignes
 * ['id','id_bien','url_photo'] groupables par bien.
 */
function photos_orphans(PDO $pdo): array
{
    $baseDir = dirname(__DIR__) . '/';
    $rows = $pdo->query(
        "SELECT bp.id, bp.id_bien, bp.url_photo, b.id_societe AS bien_societe
         FROM biens_photos bp
         LEFT JOIN biens b ON b.id = bp.id_bien
         ORDER BY bp.id_bien, bp.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $orphans = [];
    foreach ($rows as $r) {
        $rel = ltrim((string)($r['url_photo'] ?? ''), '/');
        if ($rel !== '' && is_file($baseDir . $rel)) {
            continue; // OK, fichier présent à l'emplacement BDD
        }

        // Fichier absent au chemin BDD → on cherche où il est réellement.
        $file       = basename($rel);
        $idBien     = (int)$r['id_bien'];
        $bienSoc    = $r['bien_societe'] !== null ? (int)$r['bien_societe'] : null;
        $verdict    = 'missing';   // par défaut : vraiment absent
        $foundRel   = null;

        if ($file !== '') {
            // 1) Au bon endroit selon la société réelle du bien
            if ($bienSoc !== null) {
                $cand = "uploads/biens/{$bienSoc}/{$idBien}/{$file}";
                if (is_file($baseDir . $cand)) { $verdict = 'wrong_societe'; $foundRel = $cand; }
            }
            // 2) Sinon, dans n'importe quelle société (glob)
            if ($verdict === 'missing') {
                foreach (glob($baseDir . "uploads/biens/*/{$idBien}/" . $file) ?: [] as $hit) {
                    $verdict  = 'wrong_path';
                    $foundRel = ltrim(str_replace($baseDir, '', $hit), '/');
                    break;
                }
            }
        }

        $orphans[] = [
            'id'          => (int)$r['id'],
            'id_bien'     => $idBien,
            'bien_societe'=> $bienSoc,
            'url_photo'   => (string)$r['url_photo'],
            'verdict'     => $verdict,     // wrong_societe | wrong_path | missing
            'found_rel'   => $foundRel,    // chemin réel trouvé (si corrigeable)
        ];
    }
    return $orphans;
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
$orphans = null; // null = pas encore scanné ; array = résultat du diagnostic

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan_orphans') {
    verify_csrf('tools_photos_recompress');
    @set_time_limit(300);
    $orphans = photos_orphans($pdo);
    $nWrongSoc  = count(array_filter($orphans, fn($o) => $o['verdict'] === 'wrong_societe'));
    $nWrongPath = count(array_filter($orphans, fn($o) => $o['verdict'] === 'wrong_path'));
    $nMissing   = count(array_filter($orphans, fn($o) => $o['verdict'] === 'missing'));
    $flash = [
        'type' => empty($orphans) ? 'success' : 'warning',
        'msg'  => empty($orphans)
            ? 'Diagnostic : aucune photo orpheline. Tous les fichiers existent à l\'emplacement BDD.'
            : 'Diagnostic : ' . count($orphans) . ' ligne(s) dont le fichier n\'est PAS à l\'emplacement BDD — '
              . "🟠 {$nWrongSoc} chemin société erroné (corrigeable) · 🟡 {$nWrongPath} autre chemin (corrigeable) · 🔴 {$nMissing} vraiment absent.",
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_missing') {
    verify_csrf('tools_photos_recompress');
    @set_time_limit(300);

    // Re-scan FRAIS : on ne supprime QUE les lignes dont le fichier est
    // introuvable partout (verdict 'missing'). Jamais une ligne corrigeable.
    $scan = photos_orphans($pdo);
    $toDelete = array_values(array_filter($scan, fn($o) => $o['verdict'] === 'missing'));
    $ids = array_map(fn($o) => (int)$o['id'], $toDelete);

    if (empty($ids)) {
        $flash = ['type' => 'success', 'msg' => 'Aucune ligne « vraiment absente » à supprimer.'];
    } else {
        // 1) Backup des lignes supprimées (table de récupération)
        $pdo->exec("CREATE TABLE IF NOT EXISTS biens_photos_orphans_backup LIKE biens_photos");
        // colonne d'horodatage de purge (ajoutée si absente)
        try { $pdo->exec("ALTER TABLE biens_photos_orphans_backup ADD COLUMN purged_at DATETIME NULL"); } catch (Throwable) {}
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("INSERT INTO biens_photos_orphans_backup
                       SELECT *, NOW() FROM biens_photos WHERE id IN ($in)")->execute($ids);

        // 2) Nettoyage des sélections d'annonces pointant vers ces lignes
        $delAnn = $pdo->prepare("DELETE FROM annonces_photos WHERE id_biens_photo IN ($in)");
        $delAnn->execute($ids);
        $nAnn = $delAnn->rowCount();

        // 3) Suppression des lignes orphelines
        $del = $pdo->prepare("DELETE FROM biens_photos WHERE id IN ($in)");
        $del->execute($ids);
        $nDel = $del->rowCount();

        foreach ($toDelete as $o) {
            $logs[] = '🗑️ supprimé · bien #' . (int)$o['id_bien'] . ' · id ' . (int)$o['id'] . ' · ' . $o['url_photo'];
        }
        $flash = [
            'type' => 'success',
            'msg'  => "Purge : {$nDel} ligne(s) orpheline(s) supprimée(s) (sauvegardées dans biens_photos_orphans_backup)"
                      . ($nAnn > 0 ? " · {$nAnn} sélection(s) d'annonce nettoyée(s)." : '.'),
        ];
    }
}

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
  <p style="margin:0 0 14px;">
    <a href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/admin_dashboard.php') : '/admin_dashboard.php') ?>"
       style="display:inline-flex;align-items:center;gap:6px;color:#0ea5e9;text-decoration:none;font-size:13px;font-weight:600;">← Retour au dashboard admin</a>
    <span style="color:#cbd5e1;margin:0 8px;">·</span>
    <a href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/rh_dashboard_user.php') : '/rh_dashboard_user.php') ?>"
       style="color:#64748b;text-decoration:none;font-size:13px;">Accueil RH</a>
  </p>
  <h1>🖼️ Rattrapage compression photos</h1>
  <p class="sub">Recompresse uniquement les photos <strong>&gt; 800 Ko</strong> ou trop grandes (&gt; 2500 px), en JPEG q85, et génère la variante Le Bon Coin (1200 px). Les photos &le; 800 Ko sont laissées intactes (pas de perte de qualité). Lance le batch autant de fois qu'il reste des photos à traiter.</p>

  <?php if ($flash): ?>
    <div class="rec-flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="rec-stats">
    <div class="rec-card"><div class="k">Total photos</div><div class="v"><?= $total ?></div></div>
    <div class="rec-card"><div class="k">Déjà traitées</div><div class="v" style="color:#16a34a;"><?= $done ?></div></div>
    <div class="rec-card"><div class="k">Restantes</div><div class="v" style="color:<?= $remaining > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $remaining ?></div></div>
  </div>

  <div class="rec-bar"><span style="width: <?= $pct ?>%"></span></div>

  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <form method="post" style="display:flex; gap:10px; align-items:center;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="process_batch">
      <button type="submit" class="rec-btn" <?= $remaining === 0 ? 'disabled' : '' ?>>
        <?= $remaining === 0 ? '✅ Tout est traité' : "▶️ Traiter le prochain batch (" . min(BATCH_SIZE, $remaining) . ' photos)' ?>
      </button>
    </form>
    <form method="post" style="display:flex; gap:10px; align-items:center;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="scan_orphans">
      <button type="submit" class="rec-btn" style="background:#64748b;">🔎 Diagnostic orphelines</button>
    </form>
    <form method="post" style="display:flex; gap:10px; align-items:center;"
          onsubmit="return confirm('Supprimer les lignes biens_photos VRAIMENT absentes (🔴) ?\n\nUne sauvegarde est faite dans biens_photos_orphans_backup. Les lignes corrigeables (🟠/🟡) ne sont jamais touchées.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="delete_missing">
      <button type="submit" class="rec-btn" style="background:#dc2626;">🗑️ Purger les orphelines 🔴</button>
    </form>
    <span style="color:#64748b; font-size:12px;">Progression : <?= $pct ?>%</span>
  </div>

  <?php if ($orphans !== null): ?>
    <div class="rec-logs" style="background:#1e293b;">
      <?php if (empty($orphans)): ?>
        <div>✅ Aucune photo orpheline — tous les fichiers référencés existent sur le disque.</div>
      <?php else:
        // Regroupe par bien pour la lisibilité
        $byBien = [];
        foreach ($orphans as $o) { $byBien[(int)$o['id_bien']][] = $o; }
        $icon = ['wrong_societe' => '🟠', 'wrong_path' => '🟡', 'missing' => '🔴'];
        ?>
        <div style="color:#fca5a5;">⚠️ <?= count($orphans) ?> ligne(s) sur <?= count($byBien) ?> bien(s) dont le fichier n'est PAS à l'emplacement BDD :</div>
        <div style="margin-top:4px;color:#cbd5e1;">🟠 chemin société erroné = corrigeable · 🟡 fichier trouvé sous un autre chemin = corrigeable · 🔴 vraiment absent</div>
        <?php foreach ($byBien as $idBien => $list): ?>
        <div style="margin-top:8px;color:#fcd34d;">— Bien #<?= (int)$idBien ?> (société <?= $list[0]['bien_societe'] !== null ? (int)$list[0]['bien_societe'] : '?' ?>, <?= count($list) ?> photo·s) :</div>
        <?php foreach ($list as $o): ?>
        <div>   <?= $icon[$o['verdict']] ?? '❔' ?> id <?= (int)$o['id'] ?> · BDD : <?= htmlspecialchars((string)$o['url_photo']) ?><?= $o['found_rel'] ? '  →  RÉEL : ' . htmlspecialchars((string)$o['found_rel']) : '' ?></div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($logs)): ?>
    <div class="rec-logs">
      <?php foreach ($logs as $line): ?>
        <div><?= htmlspecialchars($line) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
