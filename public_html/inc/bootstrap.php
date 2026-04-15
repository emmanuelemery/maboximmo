<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Paris');

// ── Headers de sécurité HTTP (appliqués sur toutes les pages) ──────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https: blob:; connect-src 'self' https:; frame-ancestors 'none';");

/**
 * MODE APPLICATION
 * true  = développement local (XAMPP) — affiche les erreurs à l'écran
 * false = production (Hostinger)      — masque les erreurs aux visiteurs
 */
const APP_DEBUG = false;

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
 */
$possibleAnthropicConfigs = [
    __DIR__ . '/../anthropic_config.php',
    __DIR__ . '/../../anthropic_config.php',
    '/home/u630423897/anthropic_config.php',
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
