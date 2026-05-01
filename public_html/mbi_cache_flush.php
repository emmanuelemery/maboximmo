<?php
// ─────────────────────────────────────────────────────────────────────────────
// Force le reset de l'OPcache PHP côté Hostinger.
// Accessible uniquement avec ?key=<une_clé_secrète> pour éviter abus.
// À appeler une fois quand un fichier PHP a été modifié mais pas pris en compte.
// ─────────────────────────────────────────────────────────────────────────────
declare(strict_types=1);

// Clé simple — change-la à chaque utilisation pour rester safe
$expectedKey = 'mbi-2026-cache-flush-x9k4';

if (($_GET['key'] ?? '') !== $expectedKey) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// 1. OPcache PHP — invalide le cache compilé (le coupable du retard de mise à jour)
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo "opcache_reset(): " . ($ok ? "OK" : "FAILED") . "\n";
} else {
    echo "opcache_reset(): NOT AVAILABLE\n";
}

// 2. realpath_cache (PHP file system stat cache)
if (function_exists('clearstatcache')) {
    clearstatcache(true);
    echo "clearstatcache(): OK\n";
}

// 3. Status final
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    if (is_array($status)) {
        echo "opcache enabled: " . ($status['opcache_enabled'] ? "yes" : "no") . "\n";
        echo "scripts cached:  " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . "\n";
    }
}

echo "\n✓ Cache flushed at " . date('Y-m-d H:i:s') . "\n";
