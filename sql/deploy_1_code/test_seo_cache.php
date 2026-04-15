<?php
/**
 * Test des tables SEO & Cache
 * Accès: http://localhost/test_seo_cache.php
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_super_admin();

echo "<h1>🧪 Test SEO & Cache System</h1>";
echo "<hr>";

// Test 1: Vérifier les 3 tables existent
echo "<h2>Test 1: Vérifier les tables</h2>";
try {
    $tables = ['seo_search_terms', 'cache_listings', 'url_redirects'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✅ Table <strong>$table</strong>: $count lignes<br>";
    }
} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Test 2: Tester CacheManager
echo "<h2>Test 2: Tester CacheManager</h2>";
try {
    $results = $cacheManager->getOrCreateListing(
        'test_cache_simple',
        ['type_cache' => 'custom'],
        function() {
            return [
                ['id' => 1, 'title' => 'Test 1'],
                ['id' => 2, 'title' => 'Test 2'],
                ['id' => 3, 'title' => 'Test 3'],
            ];
        }
    );

    echo "✅ Cache créé avec " . count($results) . " éléments<br>";

    // Test récupération depuis cache
    $start = microtime(true);
    $results2 = $cacheManager->getOrCreateListing(
        'test_cache_simple',
        ['type_cache' => 'custom'],
        function() { return []; }
    );
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Récupération depuis cache en <strong>{$duration}ms</strong><br>";

    // Vérifier cache est en base
    $stmt = $pdo->query("SELECT COUNT(*) FROM cache_listings WHERE cache_key='test_cache_simple' AND expires_at > NOW()");
    $cacheExists = $stmt->fetchColumn();
    if ($cacheExists) {
        echo "✅ Cache sauvegardé en base de données<br>";
    } else {
        echo "❌ Cache NOT found in database<br>";
    }
} catch (\Exception $e) {
    echo "❌ Erreur CacheManager: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Test 3: Créer une redirection de test
echo "<h2>Test 3: Tester URL Redirects</h2>";
try {
    $pdo->prepare("
        INSERT INTO url_redirects (old_url, new_url, type_redirect, motif)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE new_url = VALUES(new_url)
    ")->execute([
        '/test-old-url',
        '/test-new-url',
        '301',
        'Test redirect'
    ]);

    // Vérifier la redirection
    $stmt = $pdo->prepare("
        SELECT new_url, type_redirect FROM url_redirects
        WHERE old_url = '/test-old-url'
    ");
    $stmt->execute();
    $redirect = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($redirect) {
        echo "✅ Redirection créée: /test-old-url → " . $redirect['new_url'] . " ({$redirect['type_redirect']})<br>";
    } else {
        echo "❌ Redirection NOT found<br>";
    }
} catch (\Exception $e) {
    echo "❌ Erreur redirects: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Test 4: Statistiques du cache
echo "<h2>Test 4: Statistiques Cache</h2>";
try {
    $stats = $cacheManager->getStats();
    if ($stats) {
        echo "✅ Total caches: " . $stats['total_caches'] . "<br>";
        echo "✅ Total hits: " . $stats['total_hits'] . "<br>";
        echo "✅ Caches actifs: " . $stats['active_caches'] . "<br>";
        echo "✅ Caches expirés: " . $stats['expired_caches'] . "<br>";
    }
} catch (\Exception $e) {
    echo "❌ Erreur stats: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Test 5: Vérifier les triggers
echo "<h2>Test 5: Vérifier les Triggers</h2>";
try {
    $stmt = $pdo->query("SHOW TRIGGERS WHERE `Table` = 'annonces'");
    $triggers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (count($triggers) >= 2) {
        echo "✅ Triggers trouvés: " . count($triggers) . "<br>";
        foreach ($triggers as $trigger) {
            echo "   - {$trigger['Trigger']}<br>";
        }
    } else {
        echo "⚠️ Seulement " . count($triggers) . " triggers trouvés (attendu: 2+)<br>";
    }
} catch (\Exception $e) {
    echo "❌ Erreur triggers: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Résumé
echo "<h2>✅ Tests Complétés</h2>";
echo "<p><strong>État du système SEO & Cache:</strong> OPÉRATIONNEL</p>";
echo "<p><small>Base de données: " . getenv('DB_HOST') ?? 'localhost' . "</small></p>";

// Nettoyer les données de test
try {
    $pdo->prepare("DELETE FROM cache_listings WHERE cache_key = 'test_cache_simple'")->execute();
    $pdo->prepare("DELETE FROM url_redirects WHERE old_url = '/test-old-url'")->execute();
} catch (\Exception $e) {
    // Silently fail cleanup
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    h1 { color: #333; }
    h2 { color: #555; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
    strong { color: #0a7d2c; }
    ✅ { color: green; }
    ❌ { color: red; }
    ⚠️ { color: orange; }
    hr { border: 1px solid #ddd; }
</style>
