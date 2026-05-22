<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Paris');

// ── Headers de sécurité HTTP (appliqués sur toutes les pages) ──────
// SAMEORIGIN + frame-ancestors 'self' : autorise les iframes/embeds depuis le même domaine
// (ex: visualisation PDF dans bien_detail_v2), bloque les sites tiers → anti-clickjacking OK
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https: blob:; connect-src 'self' https:; frame-src 'self'; frame-ancestors 'self';");

/**
 * MODE APPLICATION
 * - localhost / 127.0.0.1 / dev.maboximmo.fr → affiche les erreurs (dev)
 * - maboximmo.fr                             → masque les erreurs (prod)
 */
$_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
define('APP_DEBUG', (
    str_contains($_host, 'localhost') ||
    str_contains($_host, '127.0.0.1') ||
    str_contains($_host, 'dev.maboximmo')
));

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/CacheManager.php';
require_once __DIR__ . '/seo_jsonld.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/SecurityGuard.php';

$pdo = db();
$GLOBALS['pdo'] = $pdo;

/**
 * Initialisation du CacheManager pour optimisation SEO/Cache
 */
$cacheManager = new CacheManager($pdo);

/**
 * Chargement OpenAI optionnel
 */
$possibleOpenAiConfigs = [
    __DIR__ . '/../openai_config.php',
    __DIR__ . '/../maboximmo_openai_config.php',
    __DIR__ . '/../../openai_config.php',
    __DIR__ . '/../../maboximmo_openai_config.php',
    __DIR__ . '/../../u630423897/maboximmo_openai_config.php',
    __DIR__ . '/../../u630423897/openai_config.php',
    __DIR__ . '/../home/openai_config.php',
    '/home/u630423897/openai_config.php',
    '/home/u630423897/maboximmo_openai_config.php',
    '/home/u630423897/u630423897/maboximmo_openai_config.php',
    '/home/u630423897/u630423897/openai_config.php',
];

$openAiConfigLoaded = false;

foreach ($possibleOpenAiConfigs as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        $openAiConfigLoaded = true;
        break;
    }
}

$OPENAI_API_KEY = getenv('OPENAI_API_KEY')
    ?: ($_ENV['OPENAI_API_KEY'] ?? '')
    ?: ($_SERVER['OPENAI_API_KEY'] ?? '')
    ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');

$OPENAI_TEXT_MODEL = getenv('OPENAI_TEXT_MODEL')
    ?: ($_ENV['OPENAI_TEXT_MODEL'] ?? '')
    ?: ($_SERVER['OPENAI_TEXT_MODEL'] ?? '')
    ?: (defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-5');

/**
 * Chargement config Anthropic (Claude API) optionnelle
 * Convention de SÉCURITÉ : la config doit être HORS webroot.
 *   ✓ /home/u630423897/anthropic_config.php (Hostinger prod/dev)
 *   ✓ project_root/u630423897/anthropic_config.php (local XAMPP, sibling de public_html)
 *   ✗ public_html/anthropic_config.php (à NE JAMAIS utiliser — web-accessible)
 *
 * Ordre des candidats : sécurisé > legacy. Premier trouvé gagne.
 */
$possibleAnthropicConfigs = [
    // 1. Hors webroot — emplacements canoniques (Hostinger)
    '/home/u630423897/anthropic_config.php',
    '/home/u630423897/maboximmo_anthropic_config.php',
    // 2. Hors webroot — sous-dossier u630423897/ (XAMPP local et Hostinger sub)
    __DIR__ . '/../../u630423897/anthropic_config.php',
    __DIR__ . '/../../u630423897/maboximmo_anthropic_config.php',
    __DIR__ . '/../../u630423897/dev_anthropic_config.php',
    '/home/u630423897/u630423897/anthropic_config.php',
    // 3. Project root (encore acceptable, hors webroot)
    __DIR__ . '/../../anthropic_config.php',
    __DIR__ . '/../../maboximmo_anthropic_config.php',
    // 4. ⚠️ Legacy DANGEREUX — public_html/. Garde en dernier recours uniquement,
    //    à supprimer dès que possible (web-accessible, leak potentiel).
    __DIR__ . '/../anthropic_config.php',
    __DIR__ . '/../maboximmo_anthropic_config.php',
];

foreach ($possibleAnthropicConfigs as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        break;
    }
}

$ANTHROPIC_API_KEY = getenv('ANTHROPIC_API_KEY')
    ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '')
    ?: ($_SERVER['ANTHROPIC_API_KEY'] ?? '')
    ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');

$ANTHROPIC_MODEL = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';

/**
 * Chargement config applicative optionnelle (base path, etc.)
 */
$possibleAppConfigs = [
    __DIR__ . '/../app_config.php',
    __DIR__ . '/../../app_config.php',
];

foreach ($possibleAppConfigs as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        break;
    }
}

$APP_BASE_PATH = getenv('APP_BASE_PATH')
    ?: ($_ENV['APP_BASE_PATH'] ?? '')
    ?: ($_SERVER['APP_BASE_PATH'] ?? '')
    ?: (defined('APP_BASE_PATH') ? APP_BASE_PATH : '');

// Exposé au reste de l'app via security.php (app_url/asset_url).
$GLOBALS['APP_BASE_PATH'] = $APP_BASE_PATH;

/**
 * Chargement Google Maps/Places optionnel
 */
$possibleGoogleConfigs = [
    __DIR__ . '/../google_config.php',
    __DIR__ . '/../../google_config.php',
    __DIR__ . '/../../u630423897/google_config.php',
    __DIR__ . '/../home/google_config.php',
    '/home/u630423897/google_config.php',
    '/home/u630423897/u630423897/google_config.php',
];

foreach ($possibleGoogleConfigs as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        break;
    }
}

$GOOGLE_MAPS_API_KEY = getenv('GOOGLE_MAPS_API_KEY')
    ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? '')
    ?: ($_SERVER['GOOGLE_MAPS_API_KEY'] ?? '')
    ?: (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
