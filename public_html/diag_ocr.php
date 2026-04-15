<?php
/**
 * Diagnostic OCR — Vérifie que l'extraction documentaire fonctionne.
 * À SUPPRIMER après vérification.
 */
header('Content-Type: application/json; charset=utf-8');

$diag = [
    'timestamp'       => date('Y-m-d H:i:s'),
    'php_version'     => PHP_VERSION,
    'os'              => PHP_OS_FAMILY,
    'checks'          => [],
    'result'          => 'OK',
];

// 1. shell_exec disponible ?
$shellOk = function_exists('shell_exec')
    && !in_array('shell_exec', explode(',', ini_get('disable_functions')));
$diag['checks']['shell_exec'] = $shellOk ? 'disponible' : 'BLOQUÉ';

// 2. pdftotext / pdftoppm ?
$diag['checks']['pdftotext'] = 'non testé (shell_exec bloqué)';
if ($shellOk) {
    $out = @shell_exec('pdftotext -v 2>&1');
    $diag['checks']['pdftotext'] = $out ? trim(substr($out, 0, 100)) : 'introuvable';
}

// 3. smalot/pdfparser installé ?
if (is_dir(__DIR__ . '/vendor'))              { $vendorBase = __DIR__ . '/vendor'; }
elseif (is_dir(__DIR__ . '/../vendor'))        { $vendorBase = __DIR__ . '/../vendor'; }
elseif (is_dir(__DIR__ . '/../../vendor'))     { $vendorBase = __DIR__ . '/../../vendor'; }
elseif (is_dir('/home/u630423897/vendor'))     { $vendorBase = '/home/u630423897/vendor'; }
else                                           { $vendorBase = 'INTROUVABLE'; }
$parserPath = $vendorBase . '/smalot/pdfparser/src/Smalot/PdfParser/Parser.php';
$autoload   = $vendorBase . '/autoload.php';
$diag['checks']['vendor_path'] = $vendorBase;
$diag['checks']['pdfparser_file']    = is_file($parserPath) ? 'OK' : 'MANQUANT — ' . $parserPath;
$diag['checks']['vendor_autoload']   = is_file($autoload)   ? 'OK' : 'MANQUANT — ' . $autoload;

// 4. Imagick ?
$diag['checks']['imagick'] = extension_loaded('imagick') ? 'disponible' : 'non disponible';

// 5. GD ?
$diag['checks']['gd'] = extension_loaded('gd') ? 'disponible' : 'non disponible';

// 6. OpenAI config ?
$possibleConfigs = [
    __DIR__ . '/openai_config.php',
    __DIR__ . '/maboximmo_openai_config.php',
    __DIR__ . '/inc/openai_config.php',
    __DIR__ . '/inc/maboximmo_openai_config.php',
    __DIR__ . '/config/openai_config.php',
    __DIR__ . '/../openai_config.php',
    __DIR__ . '/../maboximmo_openai_config.php',
    '/home/u630423897/openai_config.php',
    '/home/u630423897/maboximmo_openai_config.php',
    '/home/u630423897/u630423897/maboximmo_openai_config.php',
];
$foundConfig = null;
$checkedPaths = [];
foreach ($possibleConfigs as $c) {
    $checkedPaths[] = $c . ' → ' . (is_file($c) ? 'EXISTE' : 'non');
    if (!$foundConfig && is_file($c)) { $foundConfig = $c; }
}
$diag['checks']['openai_config'] = $foundConfig ? 'TROUVÉ — ' . $foundConfig : 'MANQUANT';
$diag['checks']['openai_paths_checked'] = $checkedPaths;

// 7. Clé API chargée ?
if ($foundConfig) {
    require_once $foundConfig;
}
$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
$diag['checks']['openai_api_key'] = $apiKey
    ? 'OK (commence par ' . substr($apiKey, 0, 8) . '...)'
    : 'VIDE ou non définie';

// 8. Test pdfparser sur un PDF fictif
if (is_file($autoload) && is_file($parserPath)) {
    try {
        require_once $autoload;
        $parser = new \Smalot\PdfParser\Parser();
        $diag['checks']['pdfparser_load'] = 'OK — classe chargée';
    } catch (Throwable $e) {
        $diag['checks']['pdfparser_load'] = 'ERREUR — ' . $e->getMessage();
    }
} else {
    $diag['checks']['pdfparser_load'] = 'SKIPPED (fichiers manquants)';
}

// 9. Chemins uploads
$uploadsDir = __DIR__ . '/uploads/rh_docs';
$diag['checks']['uploads_dir'] = is_dir($uploadsDir) ? 'OK' : 'MANQUANT';
$diag['checks']['uploads_writable'] = is_writable($uploadsDir) ? 'OK' : 'NON WRITABLE';

// Résultat global
$critical = ['pdfparser_file', 'vendor_autoload', 'openai_api_key'];
foreach ($critical as $k) {
    if (strpos($diag['checks'][$k] ?? '', 'OK') === false
        && strpos($diag['checks'][$k] ?? '', 'commence par') === false) {
        $diag['result'] = 'PROBLÈME';
    }
}

echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
