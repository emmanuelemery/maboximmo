<?php
declare(strict_types=1);

/**
 * GED — Liste les Shared Drives accessibles via l'API Drive.
 * Aide à identifier le bon GOOGLE_DRIVE_DRIVE_ID quand on a confondu
 * folder_id et drive_id.
 *
 * Usage : php public_html/modules/ged/list_drives.php
 */

if (PHP_SAPI !== 'cli') exit('CLI only');

require_once __DIR__ . '/ged_storage.php';

// Charge la config Drive (sans révéler de secret)
$candidates = [
    dirname(__DIR__, 3) . '/u630423897/maboximmo_drive_config.php',
    dirname(__DIR__, 3) . '/u630423897/dev_maboximmo_drive_config.php',
];
foreach ($candidates as $f) {
    if (is_file($f) && is_readable($f)) { require_once $f; break; }
}

if (!defined('GOOGLE_DRIVE_CLIENT_ID') || !defined('GOOGLE_DRIVE_REFRESH_TOKEN')) {
    fwrite(STDERR, "Config Drive incomplète. Lance d'abord check_drive_config.php\n");
    exit(1);
}

// Étape 1 : obtenir un access_token via OAuth refresh
echo "→ Récupération access_token..." . PHP_EOL;
$tokenBody = http_build_query([
    'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
    'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
    'refresh_token' => GOOGLE_DRIVE_REFRESH_TOKEN,
    'grant_type'    => 'refresh_token',
]);
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => $tokenBody,
    CURLOPT_TIMEOUT        => 30,
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    fwrite(STDERR, "OAuth HTTP {$code}\n");
    exit(1);
}
$tok = json_decode((string)$raw, true);
$accessToken = $tok['access_token'] ?? '';
if ($accessToken === '') {
    fwrite(STDERR, "Pas d'access_token reçu.\n");
    exit(1);
}
echo "  ✓ access_token OK" . PHP_EOL . PHP_EOL;

// Étape 2 : lister les Shared Drives
echo "→ Liste des Shared Drives accessibles avec ce compte :" . PHP_EOL . PHP_EOL;
$ch = curl_init('https://www.googleapis.com/drive/v3/drives?fields=drives(id,name,createdTime)');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 30,
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    fwrite(STDERR, "drives.list HTTP {$code}: " . substr((string)$raw, 0, 300) . "\n");
    exit(1);
}
$body = json_decode((string)$raw, true);
$drives = $body['drives'] ?? [];

if (empty($drives)) {
    echo "  ⚠ Aucun Shared Drive accessible avec ce compte." . PHP_EOL;
    echo "  Vérifie que administrateur@maboximmo.fr est bien membre du Shared Drive." . PHP_EOL;
    exit(1);
}

printf("  %-32s  %-50s  %s\n", "ID (← à coller dans DRIVE_ID)", "Nom", "Créé");
echo "  " . str_repeat('─', 100) . PHP_EOL;
foreach ($drives as $d) {
    printf("  %-32s  %-50s  %s\n",
        (string)($d['id'] ?? ''),
        (string)($d['name'] ?? ''),
        substr((string)($d['createdTime'] ?? ''), 0, 10)
    );
}

echo PHP_EOL;
echo "→ Si tu vois 'MaBoxImmo' dans la liste, son ID est ce qu'il faut mettre dans" . PHP_EOL;
echo "  define('GOOGLE_DRIVE_DRIVE_ID', '...');" . PHP_EOL;
echo PHP_EOL;

// Étape 3 (bonus) : lister les dossiers à la racine du Shared Drive si DRIVE_ID est correct
if (defined('GOOGLE_DRIVE_DRIVE_ID') && !str_starts_with(GOOGLE_DRIVE_DRIVE_ID, 'PASTE_')) {
    $driveId = GOOGLE_DRIVE_DRIVE_ID;
    $matchingDrive = null;
    foreach ($drives as $d) {
        if (($d['id'] ?? '') === $driveId) { $matchingDrive = $d; break; }
    }
    if ($matchingDrive) {
        echo "→ DRIVE_ID configuré correspond à : \"" . $matchingDrive['name'] . "\" ✓" . PHP_EOL;
        echo "→ Liste des dossiers à la racine de ce Shared Drive :" . PHP_EOL . PHP_EOL;

        $q = "'$driveId' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false";
        $url = 'https://www.googleapis.com/drive/v3/files'
             . '?q=' . rawurlencode($q)
             . '&fields=files(id,name)'
             . '&supportsAllDrives=true&includeItemsFromAllDrives=true'
             . '&corpora=drive&driveId=' . rawurlencode($driveId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $body = json_decode((string)$raw, true);
            $files = $body['files'] ?? [];
            if (empty($files)) {
                echo "  (aucun dossier à la racine du Shared Drive)" . PHP_EOL;
            } else {
                printf("  %-32s  %s\n", "ID (← à coller dans ROOT_FOLDER_ID)", "Nom");
                echo "  " . str_repeat('─', 70) . PHP_EOL;
                foreach ($files as $f) {
                    printf("  %-32s  %s\n", (string)($f['id'] ?? ''), (string)($f['name'] ?? ''));
                }
            }
        } else {
            echo "  ⚠ Impossible de lister la racine : HTTP {$code}" . PHP_EOL;
        }
    } else {
        echo "→ ⚠ Le DRIVE_ID actuel (" . substr($driveId, 0, 8) . "...) ne correspond à AUCUN Shared Drive ci-dessus." . PHP_EOL;
        echo "  Remplace-le par un des IDs ci-dessus." . PHP_EOL;
    }
}
