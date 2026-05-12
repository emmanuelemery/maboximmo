<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Test upload Google Drive (web).
 * Fichier : public_html/api/test_drive_upload.php
 *
 * But : preuve fonctionnelle simple que l'API Drive est bien configurée et que
 * l'upload fonctionne vers le dossier racine configuré.
 *
 * Sécurité :
 * - admin only (rôles 1/7/8)
 * - ne révèle aucun secret
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 7, 8], true)) {
    http_response_code(403);
    exit('Accès refusé (admin uniquement).');
}

require_once dirname(__DIR__) . '/modules/ged/ged_storage.php';
require_once dirname(__DIR__) . '/modules/ged/ged_storage_google.php';

// Charge la config Drive si présente (même stratégie que ged_storage_default)
$driveConfigCandidates = [
    dirname(__DIR__, 2) . '/config/google_drive.php',
    dirname(__DIR__, 2) . '/u630423897/maboximmo_drive_config.php',
    dirname(__DIR__, 2) . '/u630423897/dev_maboximmo_drive_config.php',
    '/home/u630423897/maboximmo_drive_config.php',
];
foreach ($driveConfigCandidates as $f) {
    if (is_file($f) && is_readable($f)) { require_once $f; break; }
}

$driveReady = defined('GOOGLE_DRIVE_CLIENT_ID')
    && defined('GOOGLE_DRIVE_CLIENT_SECRET')
    && defined('GOOGLE_DRIVE_REFRESH_TOKEN')
    && defined('GOOGLE_DRIVE_ROOT_FOLDER_ID')
    && is_string(GOOGLE_DRIVE_CLIENT_ID) && GOOGLE_DRIVE_CLIENT_ID !== '' && !str_starts_with(GOOGLE_DRIVE_CLIENT_ID, 'PASTE_')
    && is_string(GOOGLE_DRIVE_CLIENT_SECRET) && GOOGLE_DRIVE_CLIENT_SECRET !== '' && !str_starts_with(GOOGLE_DRIVE_CLIENT_SECRET, 'PASTE_')
    && is_string(GOOGLE_DRIVE_REFRESH_TOKEN) && GOOGLE_DRIVE_REFRESH_TOKEN !== '' && !str_starts_with(GOOGLE_DRIVE_REFRESH_TOKEN, 'PASTE_')
    && is_string(GOOGLE_DRIVE_ROOT_FOLDER_ID) && GOOGLE_DRIVE_ROOT_FOLDER_ID !== '' && !str_starts_with(GOOGLE_DRIVE_ROOT_FOLDER_ID, 'PASTE_');

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$driveReady) {
        $error = "Config Drive incomplète. Remplis `u630423897/maboximmo_drive_config.php` (cf. `.template`).";
    } elseif (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Fichier manquant ou erreur upload (champ `file`).';
    } else {
        $tmp = (string)($_FILES['file']['tmp_name'] ?? '');
        $name = (string)($_FILES['file']['name'] ?? 'test.bin');
        $size = (int)($_FILES['file']['size'] ?? 0);

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = 'Upload invalide (tmp non reconnu).';
        } elseif ($size <= 0) {
            $error = 'Fichier vide.';
        } else {
            try {
                $drv = new GedStorageGoogleDrive([
                    'client_id'      => (string)GOOGLE_DRIVE_CLIENT_ID,
                    'client_secret'  => (string)GOOGLE_DRIVE_CLIENT_SECRET,
                    'refresh_token'  => (string)GOOGLE_DRIVE_REFRESH_TOKEN,
                    'root_folder_id' => (string)GOOGLE_DRIVE_ROOT_FOLDER_ID,
                    'drive_id'       => defined('GOOGLE_DRIVE_DRIVE_ID') ? (string)GOOGLE_DRIVE_DRIVE_ID : null,
                ]);

                $testRoot = $drv->ensureFolder('_GED_TEST_UPLOADS');
                $sub      = $drv->ensureFolder(date('Y-m-d'), $testRoot);

                $uniq = bin2hex(random_bytes(4));
                $remoteName = "TESTDRIVE_{$uniq}_" . preg_replace('/[\\r\\n\\t]+/', ' ', $name);

                $up = $drv->upload($tmp, $remoteName, $sub);
                $fileId = (string)($up['file_id'] ?? '');

                if ($fileId === '') {
                    throw new RuntimeException("Upload OK mais ID Drive manquant en réponse.");
                }

                $result = [
                    'ok'       => true,
                    'file_id'  => $fileId,
                    'url'      => ged_drive_file_url($fileId),
                    'folder_id'=> (string)($up['folder_id'] ?? $sub),
                    'name'     => (string)($up['name'] ?? $remoteName),
                    'size'     => (int)($up['size'] ?? $size),
                    'mime'     => (string)($up['mime'] ?? ''),
                ];
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Google Drive Upload — GED</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; color: #111827; }
        .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; max-width: 860px; }
        .row { margin: 10px 0; }
        .ok { color: #065f46; }
        .bad { color: #991b1b; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 6px; }
        .btn { display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; }
        .btn:hover { border-color:#2563eb; color:#2563eb; }
    </style>
</head>
<body>

<h1>🧪 Test upload Google Drive (GED)</h1>

<div class="card">
    <div class="row">
        <strong>Config Drive :</strong>
        <?php if ($driveReady): ?>
            <span class="ok">OK</span>
            <?php if (defined('GOOGLE_DRIVE_DRIVE_ID') && is_string(GOOGLE_DRIVE_DRIVE_ID) && GOOGLE_DRIVE_DRIVE_ID !== '' && !str_starts_with(GOOGLE_DRIVE_DRIVE_ID, 'PASTE_')): ?>
                <small>(Shared Drive)</small>
            <?php else: ?>
                <small>(Mon Drive)</small>
            <?php endif; ?>
        <?php else: ?>
            <span class="bad">INCOMPLÈTE</span>
            <div class="row"><small>Remplis <code>u630423897/maboximmo_drive_config.php</code> (voir <code>u630423897/maboximmo_drive_config.php.template</code>).</small></div>
        <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data">
        <div class="row">
            <input type="file" name="file" required>
        </div>
        <div class="row">
            <button class="btn" type="submit">Uploader vers Google Drive</button>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="row bad"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (is_array($result) && !empty($result['ok'])): ?>
        <div class="row ok"><strong>Succès :</strong> upload Drive terminé.</div>
        <div class="row"><strong>File ID :</strong> <code><?= htmlspecialchars((string)$result['file_id']) ?></code></div>
        <div class="row"><strong>URL :</strong> <a href="<?= htmlspecialchars((string)$result['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string)$result['url']) ?></a></div>
        <div class="row"><strong>Folder ID :</strong> <code><?= htmlspecialchars((string)$result['folder_id']) ?></code></div>
        <div class="row"><strong>Nom :</strong> <?= htmlspecialchars((string)$result['name']) ?></div>
        <div class="row"><strong>Taille :</strong> <?= (int)$result['size'] ?> octets</div>
        <div class="row"><strong>MIME :</strong> <?= htmlspecialchars((string)$result['mime']) ?></div>
    <?php endif; ?>
</div>

</body>
</html>
