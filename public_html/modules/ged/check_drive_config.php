<?php
declare(strict_types=1);

/**
 * GED — Vérifie le statut de la config Drive SANS révéler aucune valeur.
 * Sortie : un booléen par constante (OK / NON-REMPLIE / ABSENTE).
 * Aucun préfixe, aucune longueur, aucune valeur ne fuite vers le terminal.
 *
 * Usage : php public_html/modules/ged/check_drive_config.php
 */

if (PHP_SAPI !== 'cli') exit('CLI only');

$candidates = [
    dirname(__DIR__, 3) . '/u630423897/maboximmo_drive_config.php',
    dirname(__DIR__, 3) . '/u630423897/dev_maboximmo_drive_config.php',
    '/home/u630423897/maboximmo_drive_config.php',
];

$loaded = null;
foreach ($candidates as $f) {
    if (is_file($f) && is_readable($f)) { require_once $f; $loaded = $f; break; }
}

echo "Fichier chargé : " . ($loaded ?? '❌ AUCUN') . PHP_EOL . PHP_EOL;

$constants = [
    'GOOGLE_DRIVE_CLIENT_ID',
    'GOOGLE_DRIVE_CLIENT_SECRET',
    'GOOGLE_DRIVE_REFRESH_TOKEN',
    'GOOGLE_DRIVE_ROOT_FOLDER_ID',
];

$allOk = true;
foreach ($constants as $c) {
    if (!defined($c)) {
        echo "  ❌ {$c} : NON DÉFINIE" . PHP_EOL;
        $allOk = false;
        continue;
    }
    $v = constant($c);
    if (!is_string($v) || $v === '') {
        echo "  ❌ {$c} : VIDE" . PHP_EOL;
        $allOk = false;
    } elseif (str_starts_with($v, 'PASTE_')) {
        echo "  ❌ {$c} : PLACEHOLDER NON REMPLACÉ (commence par PASTE_)" . PHP_EOL;
        $allOk = false;
    } else {
        echo "  ✓  {$c} : remplie" . PHP_EOL;
    }
}

// Optionnel
echo PHP_EOL;
if (defined('GOOGLE_DRIVE_DRIVE_ID') && is_string(GOOGLE_DRIVE_DRIVE_ID) && GOOGLE_DRIVE_DRIVE_ID !== '') {
    echo "  ℹ  GOOGLE_DRIVE_DRIVE_ID : définie (Shared Drive)" . PHP_EOL;
} else {
    echo "  ℹ  GOOGLE_DRIVE_DRIVE_ID : non définie (Mon Drive)" . PHP_EOL;
}

echo PHP_EOL . ($allOk ? "✅ Config prête" : "❌ Config incomplète") . PHP_EOL;
exit($allOk ? 0 : 1);
