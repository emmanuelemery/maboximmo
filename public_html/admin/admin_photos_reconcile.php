<?php
declare(strict_types=1);

/**
 * MaBoxImmo — Réconciliation biens_photos vs filesystem.
 * Fichier : public_html/admin/admin_photos_reconcile.php
 *
 * Détecte les images présentes dans /uploads/biens/{id_societe}/{id_bien}/
 * mais NON référencées dans la table biens_photos, et ajoute les rows
 * manquantes (idempotent, INSERT IGNORE-style avec contrôle d'unicité).
 *
 * Aussi : signale les rows biens_photos qui pointent vers un fichier
 * désormais absent du disque (orphelines BDD).
 *
 * Mode dry_run par défaut : aucune modif tant que ?confirm=1 n'est pas passé.
 *
 * Accès : admin uniquement (rôle 1, 7, 8 ou super-admin).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$roleId = (int)current_role_id();
$isAdmin = in_array($roleId, [1, 7, 8], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isAdmin) {
    http_response_code(403);
    exit('Admin uniquement');
}

$pdo = $GLOBALS['pdo'];
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$dryRun  = !$confirm;

$uploadsRoot = dirname(__DIR__) . '/uploads/biens';
$results = [
    'biens_scanned'        => 0,
    'files_found'          => 0,
    'photos_already_in_db' => 0,
    'photos_to_add'        => 0,
    'photos_added'         => 0,
    'photos_orphan_db'     => 0,    // rows BDD pointant vers fichier absent disque
    'errors'               => [],
    'samples_added'        => [],
    'samples_orphan'       => [],
];

if (!is_dir($uploadsRoot)) {
    $results['errors'][] = "Dossier {$uploadsRoot} introuvable.";
} else {
    // Index des photos déjà en BDD : map "id_bien:url_photo" → row
    $existing = [];
    foreach ($pdo->query("SELECT id, id_bien, url_photo FROM biens_photos") as $r) {
        $key = (int)$r['id_bien'] . '|' . (string)$r['url_photo'];
        $existing[$key] = $r;
    }

    // Scan filesystem : /uploads/biens/{id_soc}/{id_bien}/*.jpg
    $exts = ['jpg', 'jpeg', 'png', 'webp'];
    $rowsToAdd = [];

    foreach (new DirectoryIterator($uploadsRoot) as $socDir) {
        if ($socDir->isDot() || !$socDir->isDir()) continue;
        $idSoc = (int)$socDir->getFilename();
        if ($idSoc <= 0) continue;

        foreach (new DirectoryIterator($socDir->getPathname()) as $bienDir) {
            if ($bienDir->isDot() || !$bienDir->isDir()) continue;
            $idBien = (int)$bienDir->getFilename();
            if ($idBien <= 0) continue;

            $results['biens_scanned']++;
            $files = [];
            foreach (new DirectoryIterator($bienDir->getPathname()) as $f) {
                if ($f->isDot() || !$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, $exts, true)) continue;
                // Ignore variantes type .lbc.jpg ou .thumb.jpg
                if (str_contains($f->getFilename(), '.lbc.') || str_contains($f->getFilename(), '.thumb.')) continue;
                $files[] = $f->getFilename();
            }
            sort($files);

            $results['files_found'] += count($files);

            foreach ($files as $idx => $fname) {
                // url_photo relatif à webroot, sans slash de tête
                $urlPhoto = "uploads/biens/{$idSoc}/{$idBien}/{$fname}";
                $key      = $idBien . '|' . $urlPhoto;

                if (isset($existing[$key])) {
                    $results['photos_already_in_db']++;
                    continue;
                }

                // Extrait l'ordre depuis le filename "NN_hash.jpg" si présent,
                // sinon fallback sur l'ordre alphabétique du tri.
                $ordre = $idx + 1;
                if (preg_match('/^(\d+)_/', $fname, $m)) {
                    $ordre = (int)$m[1];
                }

                $results['photos_to_add']++;
                $rowsToAdd[] = [
                    'id_bien'   => $idBien,
                    'url_photo' => $urlPhoto,
                    'titre'     => null,
                    'alt_photo' => null,
                    'ordre'     => $ordre,
                ];
                if (count($results['samples_added']) < 5) {
                    $results['samples_added'][] = $urlPhoto . " (ordre={$ordre})";
                }
            }
        }
    }

    // Détection orphelines BDD (row pointe vers fichier absent du disque)
    foreach ($existing as $key => $r) {
        $abs = dirname(__DIR__) . '/' . (string)$r['url_photo'];
        if (!is_file($abs)) {
            $results['photos_orphan_db']++;
            if (count($results['samples_orphan']) < 5) {
                $results['samples_orphan'][] = (string)$r['url_photo'];
            }
        }
    }

    // ── INSERT effectif si confirm=1 ─────────────────────────────────────
    if (!$dryRun && !empty($rowsToAdd)) {
        $stmt = $pdo->prepare("
            INSERT INTO biens_photos (id_bien, url_photo, titre, alt_photo, ordre, created_at)
            VALUES (:id_bien, :url_photo, :titre, :alt_photo, :ordre, NOW())
        ");
        foreach ($rowsToAdd as $row) {
            try {
                $stmt->execute($row);
                $results['photos_added']++;
            } catch (Throwable $e) {
                $results['errors'][] = "INSERT failed for {$row['url_photo']}: " . $e->getMessage();
            }
        }
    }
}

// ── Sortie ──────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Réconciliation biens_photos — MaBoxImmo</title>
<style>
    body { font: 14px/1.5 -apple-system, system-ui, sans-serif; background: #f5f6fa; padding: 30px; max-width: 960px; margin: 0 auto; color: #1f2937; }
    h1 { font-size: 22px; margin: 0 0 8px; }
    .sub { color: #6b7280; margin-bottom: 24px; }
    .card { background: #fff; border-radius: 10px; padding: 18px 22px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 16px; }
    .row { display: flex; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
    .row:last-child { border-bottom: 0; }
    .lbl { flex: 1; }
    .val { font-weight: 600; font-family: ui-monospace, monospace; }
    .ok { color: #10b981; }
    .warn { color: #f59e0b; }
    .err { color: #ef4444; }
    pre { background: #f3f4f6; padding: 10px 14px; border-radius: 6px; font-size: 12px; overflow-x: auto; }
    .btn { display: inline-block; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; }
    .btn-primary { background: #2563eb; color: white; }
    .btn-warn    { background: #f59e0b; color: white; }
    .banner { padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; }
    .banner.dry { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
    .banner.live { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
</style>
</head>
<body>

<h1>🔄 Réconciliation biens_photos vs filesystem</h1>
<div class="sub">Détecte les fichiers `/uploads/biens/{soc}/{bien}/*.jpg` non référencés en BDD et les ajoute.</div>

<?php if ($dryRun): ?>
    <div class="banner dry">
        <strong>🔍 Mode DRY-RUN actif</strong> — aucune modification BDD effectuée.
        <?php if ($results['photos_to_add'] > 0): ?>
            Pour appliquer : <a href="?confirm=1" class="btn btn-warn">▶ Lancer l'import (confirm=1)</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="banner live">
        <strong>✅ Import effectué</strong> — <?= (int)$results['photos_added'] ?> rows ajoutées en BDD.
    </div>
<?php endif; ?>

<div class="card">
    <h2 style="font-size:16px; margin:0 0 14px">📊 Statistiques</h2>
    <div class="row"><span class="lbl">Biens scannés (dossiers)</span> <span class="val"><?= $results['biens_scanned'] ?></span></div>
    <div class="row"><span class="lbl">Fichiers images trouvés sur disque</span> <span class="val"><?= $results['files_found'] ?></span></div>
    <div class="row"><span class="lbl ok">✓ Photos déjà référencées en BDD</span> <span class="val"><?= $results['photos_already_in_db'] ?></span></div>
    <div class="row"><span class="lbl warn">⚠ Photos à ajouter (manquent en BDD)</span> <span class="val"><?= $results['photos_to_add'] ?></span></div>
    <?php if (!$dryRun): ?>
    <div class="row"><span class="lbl ok">✓ Photos effectivement ajoutées</span> <span class="val"><?= $results['photos_added'] ?></span></div>
    <?php endif; ?>
    <div class="row"><span class="lbl err">✗ Rows BDD orphelines (fichier absent disque)</span> <span class="val"><?= $results['photos_orphan_db'] ?></span></div>
</div>

<?php if (!empty($results['samples_added'])): ?>
<div class="card">
    <h2 style="font-size:16px; margin:0 0 10px">📸 Échantillon — photos à ajouter (5 premières)</h2>
    <pre><?php foreach ($results['samples_added'] as $u) echo htmlspecialchars($u) . "\n"; ?></pre>
</div>
<?php endif; ?>

<?php if (!empty($results['samples_orphan'])): ?>
<div class="card">
    <h2 style="font-size:16px; margin:0 0 10px">⚠ Échantillon — rows orphelines (5 premières)</h2>
    <pre><?php foreach ($results['samples_orphan'] as $u) echo htmlspecialchars($u) . "\n"; ?></pre>
    <p style="font-size:12px; color:#6b7280; margin-top:10px">
        Ces rows pointent vers des fichiers qui n'existent plus sur le disque. Elles ne sont PAS supprimées par ce script (action manuelle si besoin).
    </p>
</div>
<?php endif; ?>

<?php if (!empty($results['errors'])): ?>
<div class="card" style="border-left: 3px solid #ef4444">
    <h2 style="font-size:16px; margin:0 0 10px; color:#991b1b">❌ Erreurs</h2>
    <pre><?php foreach ($results['errors'] as $e) echo htmlspecialchars($e) . "\n"; ?></pre>
</div>
<?php endif; ?>

</body>
</html>
