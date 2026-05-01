<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Test reproductible du sous-système STOCKAGE.
 * Fichier : modules/ged/test_storage.php
 *
 * Cible : driver local OBLIGATOIRE, driver Google Drive OPTIONNEL (skipped si
 *         credentials Drive absents).
 *
 * Usage :
 *   php public_html/modules/ged/test_storage.php
 *
 * Sortie : ligne par étape avec PASS / FAIL et résumé final.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('test_storage.php : exécutable uniquement en CLI.');
}

require_once __DIR__ . '/ged_storage.php';
require_once __DIR__ . '/ged_storage_local.php';

$pass = 0; $fail = 0; $skip = 0;
function step(string $label, bool $ok, string $detail = '', bool $skipped = false): void
{
    global $pass, $fail, $skip;
    if ($skipped) { $skip++; echo "[SKIP] {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL; return; }
    if ($ok)      { $pass++; echo "[PASS] {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL; }
    else          { $fail++; echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL; }
}

echo "=== GED Storage Tests ===" . PHP_EOL;

// ── Préparation : fichier source ────────────────────────────────────────────
$tmpDir = sys_get_temp_dir();
$srcPath = $tmpDir . '/ged_test_src_' . bin2hex(random_bytes(4)) . '.txt';
$srcContent = "MaBoxImmo GED test " . date('c') . "\nLine 2\n";
file_put_contents($srcPath, $srcContent);
$srcSha = hash_file('sha256', $srcPath);
step('Préparation fichier source', $srcSha !== false, "sha256={$srcSha}");

// ──────────────────────────────────────────────────────────────────────────────
// SECTION 1 — Driver LOCAL
// ──────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── Driver LOCAL ──" . PHP_EOL;

$localBase = dirname(__DIR__, 3) . '/storage/ged_test_' . bin2hex(random_bytes(3));
try {
    $local = new GedStorageLocal($localBase);
    step('Instanciation GedStorageLocal', true, $localBase);
} catch (Throwable $e) {
    step('Instanciation GedStorageLocal', false, $e->getMessage());
    echo PHP_EOL . "Test arrêté." . PHP_EOL; exit(1);
}

try {
    $folderId = $local->ensureFolder('00_A_CLASSER_IA');
    step('ensureFolder(00_A_CLASSER_IA)', true, "id={$folderId}");
} catch (Throwable $e) {
    step('ensureFolder(00_A_CLASSER_IA)', false, $e->getMessage());
}

try {
    $up = $local->upload($srcPath, 'TEST_2026-05-01_RE_AGLYON_IMB-000001_TEST_HELLO_V1.txt', $folderId);
    $localFileId = $up['file_id'];
    $shaMatches = ($up['sha256'] === $srcSha);
    step('upload + SHA256 cohérent', $shaMatches, "fileId={$up['file_id']}, size={$up['size']}");
} catch (Throwable $e) {
    step('upload', false, $e->getMessage());
    echo PHP_EOL . "Test arrêté." . PHP_EOL; exit(1);
}

try {
    $meta = $local->getMetadata($localFileId);
    step('getMetadata existant', $meta['exists'] === true, "size={$meta['size']}, mime={$meta['mime']}");
} catch (Throwable $e) {
    step('getMetadata', false, $e->getMessage());
}

try {
    $dlPath = $tmpDir . '/ged_test_dl_' . bin2hex(random_bytes(4)) . '.txt';
    $size = $local->download($localFileId, $dlPath);
    $dlSha = hash_file('sha256', $dlPath);
    step('download + SHA256 round-trip', $dlSha === $srcSha, "size={$size}");
    @unlink($dlPath);
} catch (Throwable $e) {
    step('download', false, $e->getMessage());
}

try {
    $ok = $local->delete($localFileId);
    $meta2 = $local->getMetadata($localFileId);
    step('delete + getMetadata absent', $ok && $meta2['exists'] === false);
} catch (Throwable $e) {
    step('delete', false, $e->getMessage());
}

// Cleanup local base
if (is_dir($localBase)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localBase, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($localBase);
}

// ──────────────────────────────────────────────────────────────────────────────
// SECTION 2 — Driver GOOGLE DRIVE (skipped si config absente)
// ──────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── Driver GOOGLE DRIVE ──" . PHP_EOL;

$driveConfigCandidates = [
    dirname(__DIR__, 3) . '/u630423897/maboximmo_drive_config.php',
    dirname(__DIR__, 3) . '/u630423897/dev_maboximmo_drive_config.php',
    '/home/u630423897/maboximmo_drive_config.php',
];
$driveConfigPath = null;
foreach ($driveConfigCandidates as $f) {
    if (is_file($f) && is_readable($f)) { require_once $f; $driveConfigPath = $f; break; }
}

$driveReady = defined('GOOGLE_DRIVE_CLIENT_ID')
           && defined('GOOGLE_DRIVE_CLIENT_SECRET')
           && defined('GOOGLE_DRIVE_REFRESH_TOKEN')
           && defined('GOOGLE_DRIVE_ROOT_FOLDER_ID')
           && !str_starts_with(GOOGLE_DRIVE_CLIENT_ID, 'PASTE_')
           && !str_starts_with(GOOGLE_DRIVE_CLIENT_SECRET, 'PASTE_')
           && !str_starts_with(GOOGLE_DRIVE_REFRESH_TOKEN, 'PASTE_')
           && !str_starts_with(GOOGLE_DRIVE_ROOT_FOLDER_ID, 'PASTE_');

if (!$driveReady) {
    step('Driver Google Drive', true, '', true);
    echo "       (config manquante ou non remplie : " . ($driveConfigPath ?? 'aucun fichier trouvé') . ")" . PHP_EOL;
    echo "       Pour l'activer : remplir u630423897/maboximmo_drive_config.php (cf. .template)" . PHP_EOL;
} else {
    require_once __DIR__ . '/ged_storage_google.php';
    try {
        $drv = new GedStorageGoogleDrive([
            'client_id'      => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret'  => GOOGLE_DRIVE_CLIENT_SECRET,
            'refresh_token'  => GOOGLE_DRIVE_REFRESH_TOKEN,
            'root_folder_id' => GOOGLE_DRIVE_ROOT_FOLDER_ID,
            'drive_id'       => defined('GOOGLE_DRIVE_DRIVE_ID') ? (string)GOOGLE_DRIVE_DRIVE_ID : null,
        ]);
        step('Instanciation GedStorageGoogleDrive', true);
    } catch (Throwable $e) {
        step('Instanciation GedStorageGoogleDrive', false, $e->getMessage());
        echo PHP_EOL . "Skip Drive section."; goto endTests;
    }

    try {
        $testFolder = $drv->ensureFolder('00_A_CLASSER_IA_TEST');
        step('ensureFolder Drive', true, "id={$testFolder}");
    } catch (Throwable $e) {
        step('ensureFolder Drive', false, $e->getMessage());
        echo PHP_EOL . "Skip suite (auth ou perms)."; goto endTests;
    }

    $remoteId = null;
    try {
        $upD = $drv->upload($srcPath, 'TEST_' . date('Y-m-d') . '_RE_AGLYON_IMB-000999_MBITEST_DRIVE_V1.txt', $testFolder);
        $remoteId = $upD['file_id'];
        step('upload Drive + sha cohérent', $upD['sha256'] === $srcSha, "fileId={$upD['file_id']}, size={$upD['size']}");
    } catch (Throwable $e) {
        step('upload Drive', false, $e->getMessage());
    }

    if ($remoteId) {
        try {
            $metaD = $drv->getMetadata($remoteId);
            step('getMetadata Drive', $metaD['exists'] === true, "name={$metaD['name']}, mime={$metaD['mime']}");
        } catch (Throwable $e) {
            step('getMetadata Drive', false, $e->getMessage());
        }

        try {
            $dlPath = $tmpDir . '/ged_test_dl_drive_' . bin2hex(random_bytes(4)) . '.txt';
            $sz = $drv->download($remoteId, $dlPath);
            $dlSha = hash_file('sha256', $dlPath);
            step('download Drive + SHA256 round-trip', $dlSha === $srcSha, "size={$sz}");
            @unlink($dlPath);
        } catch (Throwable $e) {
            step('download Drive', false, $e->getMessage());
        }

        try {
            $ok = $drv->delete($remoteId);
            step('delete Drive', $ok);
        } catch (Throwable $e) {
            step('delete Drive', false, $e->getMessage());
        }
    }

    // Cleanup folder de test (best-effort)
    try {
        if (isset($testFolder)) $drv->delete($testFolder);
    } catch (Throwable $e) { /* ignore */ }
}

endTests:

@unlink($srcPath);

echo PHP_EOL . "=== Résumé : {$pass} PASS, {$fail} FAIL, {$skip} SKIP ===" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
