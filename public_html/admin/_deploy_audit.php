<?php
declare(strict_types=1);

/**
 * Audit de déploiement V2 :
 *  - GET sans param   → formulaire upload manifest local + scan prod
 *  - POST avec fichier → compare et affiche les diffs côté prod (petite sortie)
 *  - GET ?json=1      → JSON brut prod (fallback)
 *
 * Super-admin uniquement. À SUPPRIMER après usage.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Super-admin uniquement.');
}

$publicRoot = realpath(dirname(__DIR__));
if (!$publicRoot) { http_response_code(500); exit('public_html introuvable'); }

$dirs = ['', 'api', 'api/flux', 'admin', 'config', 'inc', 'inc/migrations', 'gestion', 'investisseur', 'js', 'css'];
$exclude = ['_ex', '_old', 'backup', 'PHPMailer', 'tcpdf', 'vendor'];

function scanProd(string $publicRoot, array $dirs, array $exclude): array {
    $files = [];
    foreach ($dirs as $rel) {
        $abs = $publicRoot . ($rel === '' ? '' : '/' . $rel);
        if (!is_dir($abs)) continue;
        foreach (scandir($abs) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $abs . '/' . $name;
            if (!is_file($path)) continue;
            if (!preg_match('/\.(php|js|css|sql)$/i', $name)) continue;
            $skip = false;
            foreach ($exclude as $ex) {
                if (stripos($name, $ex) !== false) { $skip = true; break; }
            }
            if ($skip) continue;
            $relPath = $rel === '' ? $name : ($rel . '/' . $name);
            $files[$relPath] = [
                'md5'   => md5_file($path),
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($path)),
                'size'  => filesize($path),
            ];
        }
    }
    return $files;
}

$prod = scanProd($publicRoot, $dirs, $exclude);

// JSON fallback
if (!empty($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $files = [];
    foreach ($prod as $p => $info) $files[] = array_merge(['path' => $p], $info);
    echo json_encode(['env' => 'prod', 'files' => $files, 'count' => count($files)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}

// POST : compare avec manifest local uploadé
$missingInProd = [];
$diff = [];
$onlyInProd = [];
$ok = false;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['manifest']['tmp_name'])) {
    $localRaw = file_get_contents($_FILES['manifest']['tmp_name']);
    $local = json_decode($localRaw, true);
    if (!$local || !isset($local['files'])) {
        $err = 'Manifest local invalide';
    } else {
        $ok = true;
        $localMap = [];
        foreach ($local['files'] as $f) $localMap[$f['path']] = $f;

        foreach ($localMap as $path => $lf) {
            if (!isset($prod[$path])) {
                $missingInProd[] = ['path' => $path, 'local_mtime' => $lf['mtime']];
            } elseif ($lf['md5'] !== $prod[$path]['md5']) {
                $diff[] = [
                    'path'        => $path,
                    'local_mtime' => $lf['mtime'],
                    'prod_mtime'  => $prod[$path]['mtime'],
                    'local_size'  => $lf['size'],
                    'prod_size'   => $prod[$path]['size'],
                ];
            }
        }
        foreach ($prod as $path => $pf) {
            if (!isset($localMap[$path])) {
                $onlyInProd[] = ['path' => $path, 'prod_mtime' => $pf['mtime']];
            }
        }
        // Tri : plus récent d'abord
        usort($diff, fn($a, $b) => strcmp($b['local_mtime'], $a['local_mtime']));
        usort($missingInProd, fn($a, $b) => strcmp($b['local_mtime'], $a['local_mtime']));
    }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Deploy audit V2</title>
<style>
body{font-family:monospace;font-size:13px;padding:20px;background:#f8fafc;max-width:1200px;margin:0 auto}
h2{color:#0f172a}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:12px 0}
.diff{background:#fef3c7;border-color:#f59e0b}
.miss{background:#fee2e2;border-color:#dc2626}
.only{background:#e0f2fe;border-color:#0ea5e9}
.ok{background:#dcfce7;border-color:#16a34a}
table{border-collapse:collapse;width:100%;font-size:12px}
th,td{border:1px solid #e5e7eb;padding:5px 8px;text-align:left}
th{background:#f1f5f9}
input[type=file]{padding:8px;border:1px solid #cbd5e1;border-radius:6px}
button{background:#0f172a;color:#fff;padding:8px 20px;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-family:monospace}
.copy{margin-left:8px;background:#16a34a}
</style></head><body>

<h2>📋 Audit déploiement V2</h2>

<?php if ($err): ?>
<div class="box miss">❌ <?= h($err) ?></div>
<?php endif; ?>

<?php if (!$ok && !$err): ?>
<div class="box">
  <strong>Mode comparaison</strong> : uploade le manifest local que Claude t'a généré (<code>C:\tmp\deploy_audit_local.json</code>) — le script compare avec la prod et affiche les diffs.
  <form method="post" enctype="multipart/form-data" style="margin-top:10px">
    <input type="file" name="manifest" accept=".json" required>
    <button type="submit">Comparer</button>
  </form>
  <p style="margin-top:10px;color:#64748b">Sinon : <a href="?json=1">?json=1</a> = JSON prod brut.</p>
</div>
<?php endif; ?>

<?php if ($ok): ?>
<div class="box ok">
  <strong>✅ Comparaison effectuée</strong> · <?= count($diff) ?> diff(s) hash · <?= count($missingInProd) ?> manquant(s) en prod · <?= count($onlyInProd) ?> seulement en prod
</div>

<?php if ($missingInProd): ?>
<div class="box miss">
<h3>🔴 Fichiers MANQUANTS en prod (<?= count($missingInProd) ?>) — à uploader</h3>
<table>
<thead><tr><th>Chemin</th><th>Modifié local</th></tr></thead>
<tbody>
<?php foreach ($missingInProd as $f): ?>
<tr><td><?= h($f['path']) ?></td><td><?= h($f['local_mtime']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php if ($diff): ?>
<div class="box diff">
<h3>🟡 Hash différent (<?= count($diff) ?>) — local plus récent, à pousser</h3>
<table>
<thead><tr><th>Chemin</th><th>Local</th><th>Prod</th><th>Δ size</th></tr></thead>
<tbody>
<?php foreach ($diff as $f): ?>
<tr>
  <td><?= h($f['path']) ?></td>
  <td><?= h($f['local_mtime']) ?></td>
  <td><?= h($f['prod_mtime']) ?></td>
  <td><?= number_format($f['local_size'] - $f['prod_size']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php if ($onlyInProd): ?>
<div class="box only">
<h3>🔵 Seulement en prod (<?= count($onlyInProd) ?>) — info, pas d'action requise</h3>
<details><summary>Voir la liste</summary>
<table>
<thead><tr><th>Chemin</th><th>Modifié prod</th></tr></thead>
<tbody>
<?php foreach ($onlyInProd as $f): ?>
<tr><td><?= h($f['path']) ?></td><td><?= h($f['prod_mtime']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</details>
</div>
<?php endif; ?>

<?php if (empty($missingInProd) && empty($diff)): ?>
<div class="box ok"><h3>🎉 Tout est synchronisé entre local et prod !</h3></div>
<?php endif; ?>

<?php endif; ?>

</body></html>
