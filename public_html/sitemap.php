<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/inc/bootstrap.php';

$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$baseUrl  = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
$today    = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

// ── Pages statiques ─────────────────────────────────────────
$staticPages = [
    ['loc' => '/',                     'priority' => '1.0', 'freq' => 'daily'],
    ['loc' => '/default.php',          'priority' => '0.9', 'freq' => 'daily'],
    ['loc' => '/accueil.php',          'priority' => '0.9', 'freq' => 'weekly'],
    ['loc' => '/bien_recherche.php',   'priority' => '0.9', 'freq' => 'daily'],
    ['loc' => '/portail.php',          'priority' => '0.7', 'freq' => 'weekly'],
    ['loc' => '/maboximmo_homepage.php','priority' => '0.7', 'freq' => 'weekly'],
];
foreach ($staticPages as $p) {
    echo '<url><loc>' . htmlspecialchars($baseUrl . $p['loc'], ENT_QUOTES, 'UTF-8') . '</loc>';
    echo '<lastmod>' . $today . '</lastmod>';
    echo '<changefreq>' . $p['freq'] . '</changefreq>';
    echo '<priority>' . $p['priority'] . '</priority></url>';
}

// ── Annonces publiées (biens) ───────────────────────────────
try {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT slug, date_modification
            FROM biens
            WHERE statut_bien = 'publie' AND slug IS NOT NULL AND slug != ''
            ORDER BY date_modification DESC
            LIMIT 2000
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $date = substr($row['date_modification'] ?? $today, 0, 10);
            echo '<url><loc>' . htmlspecialchars($baseUrl . '/bien_recherche.php?slug=' . $row['slug'], ENT_QUOTES, 'UTF-8') . '</loc>';
            echo '<lastmod>' . $date . '</lastmod>';
            echo '<changefreq>weekly</changefreq>';
            echo '<priority>0.8</priority></url>';
        }
    }
} catch (PDOException $e) {
    // Silencieux — pas de biens publiés ou table absente
}

// ── Agences actives ─────────────────────────────────────────
try {
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT slug, date_modification
            FROM agences
            WHERE actif = 1 AND slug IS NOT NULL AND slug != ''
            ORDER BY nom_agence
            LIMIT 500
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $date = substr($row['date_modification'] ?? $today, 0, 10);
            echo '<url><loc>' . htmlspecialchars($baseUrl . '/agence_portail.php?slug=' . $row['slug'], ENT_QUOTES, 'UTF-8') . '</loc>';
            echo '<lastmod>' . $date . '</lastmod>';
            echo '<changefreq>monthly</changefreq>';
            echo '<priority>0.6</priority></url>';
        }
    }
} catch (PDOException $e) {}

echo '</urlset>';
