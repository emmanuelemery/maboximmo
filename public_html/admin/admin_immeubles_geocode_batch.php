<?php
// admin/admin_immeubles_geocode_batch.php — Géocodage en masse des immeubles sans lat/lon
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }

@set_time_limit(600); // 10 min max

// Vérifier que GOOGLE_MAPS_API_KEY est configurée
$googleKey = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
if ($googleKey === '') {
    exit('⚠️ GOOGLE_MAPS_API_KEY non configurée. Impossible de géocoder.');
}

// Vérifier la colonne google_place_id sur immeubles
$hasPlaceId = false;
try {
    $st = $pdo->query("SHOW COLUMNS FROM immeubles LIKE 'google_place_id'");
    $hasPlaceId = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

// Compteurs
$stats = ['total_sans_geoloc' => 0, 'ok' => 0, 'fail' => 0, 'skipped' => 0, 'cost_cents' => 0];
$failures = [];

$run    = (isset($_GET['run']) && $_GET['run'] === '1');
$maxRun = (int)($_GET['max'] ?? 50);

// Compte global des immeubles
$nbTotal = (int)$pdo->query('SELECT COUNT(*) FROM immeubles')->fetchColumn();
$stats['total_sans_geoloc'] = (int)$pdo->query("SELECT COUNT(*) FROM immeubles
    WHERE (latitude IS NULL OR longitude IS NULL
           OR adresse_formatee IS NULL OR adresse_formatee = '')
      AND adresse_1 IS NOT NULL AND adresse_1 <> ''")->fetchColumn();

if ($run) {
    $st = $pdo->prepare("SELECT id, adresse_1, code_postal, ville
        FROM immeubles
        WHERE (latitude IS NULL OR longitude IS NULL
               OR adresse_formatee IS NULL OR adresse_formatee = '')
          AND adresse_1 IS NOT NULL AND adresse_1 <> ''
        ORDER BY id LIMIT ?");
    $st->bindValue(1, $maxRun, PDO::PARAM_INT);
    $st->execute();
    $immeubles = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($immeubles as $im) {
        $adresse = trim((string)$im['adresse_1'] . ' ' . $im['code_postal'] . ' ' . $im['ville']);
        if ($adresse === '') { $stats['skipped']++; continue; }

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($adresse) . '&key=' . urlencode($googleKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $raw) {
            $body = json_decode((string)$raw, true);
            if (($body['status'] ?? '') === 'OK' && !empty($body['results'])) {
                $g = $body['results'][0];
                $lat = $g['geometry']['location']['lat'] ?? null;
                $lng = $g['geometry']['location']['lng'] ?? null;
                $placeId = $g['place_id'] ?? null;
                $adresseFmt = $g['formatted_address'] ?? null;

                if ($lat && $lng) {
                    $upd = ['latitude = ?', 'longitude = ?', "gps_source = 'google'"];
                    $params = [$lat, $lng];
                    if ($hasPlaceId && $placeId) { $upd[] = 'google_place_id = ?'; $params[] = $placeId; }
                    if ($adresseFmt)            { $upd[] = 'adresse_formatee = ?'; $params[] = $adresseFmt; }
                    $params[] = $im['id'];
                    $sqlU = 'UPDATE immeubles SET ' . implode(', ', $upd) . ' WHERE id = ?';
                    $stU = $pdo->prepare($sqlU);
                    $stU->execute($params);
                    $stats['ok']++;
                    $stats['cost_cents'] += 1; // ~0.005 USD = ~0.5 ct, on arrondit à 1 ct
                    continue;
                }
            }
        }
        $stats['fail']++;
        $failures[] = ['id' => $im['id'], 'adresse' => $adresse, 'status' => ($body['status'] ?? 'unknown')];
        // Petite pause pour ne pas spammer Google
        usleep(100000); // 100ms
    }
}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Géocodage batch immeubles</title>
<style>
body { font-family: Sora, sans-serif; padding: 28px; background: #f7f4ef; max-width: 900px; }
.card { background: #fff; border-radius: 12px; padding: 22px; margin-bottom: 14px; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
.kpi { background:#fafafa; padding:12px; border-radius:8px; text-align:center; }
.kpi strong { display:block; font-size:22px; color:#2c5687; }
.kpi span { font-size:11px; color:#7a766f; }
.btn { display:inline-block; padding:11px 22px; background:#4878a6; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; }
.btn:hover { background:#3a6890; }
table { width:100%; border-collapse:collapse; font-size:12px; }
td, th { padding:6px 10px; border-bottom:1px solid #f0ece6; text-align:left; }
.err { color:#a8323b; }
.ok  { color:#2d6a35; }
</style></head><body>

<h1>🌍 Géocodage batch immeubles</h1>

<div class="card">
    <h3>État actuel</h3>
    <div class="kpis">
        <div class="kpi"><strong><?= $nbTotal ?></strong><span>Immeubles total</span></div>
        <div class="kpi"><strong><?= $stats['total_sans_geoloc'] ?></strong><span>Sans lat/lon (à géocoder)</span></div>
        <div class="kpi"><strong><?= $hasPlaceId ? '✓' : '✗' ?></strong><span>Colonne google_place_id</span></div>
        <div class="kpi"><strong><?= $googleKey ? '✓' : '✗' ?></strong><span>Clé Google Maps</span></div>
    </div>
</div>

<?php if (!$run): ?>
<div class="card">
    <h3>Lancement</h3>
    <p>Le script géocode <strong>jusqu'à <?= $maxRun ?> immeubles</strong> à la fois (~0,5 ct par appel Google Geocoding).</p>
    <p style="font-size:12px; color:#7a766f;">Cas typique <strong><?= $stats['total_sans_geoloc'] ?> immeubles à géocoder</strong> → coût total estimé ~<?= number_format($stats['total_sans_geoloc'] * 0.005, 2) ?> €</p>
    <p>
        <a class="btn" href="?run=1&max=50">▶️ Géocoder 50 immeubles</a>
        <a class="btn" href="?run=1&max=200" style="margin-left:10px;">▶️ Géocoder 200 immeubles</a>
        <a class="btn" href="?run=1&max=1000" style="margin-left:10px; background:#a8323b;">▶️ Géocoder TOUT (1000 max)</a>
    </p>
</div>
<?php else: ?>
<div class="card">
    <h2 class="ok">✅ Batch terminé</h2>
    <div class="kpis">
        <div class="kpi"><strong class="ok"><?= $stats['ok'] ?></strong><span>Géocodés OK</span></div>
        <div class="kpi"><strong class="err"><?= $stats['fail'] ?></strong><span>Échecs</span></div>
        <div class="kpi"><strong><?= $stats['skipped'] ?></strong><span>Skipped (adresse vide)</span></div>
        <div class="kpi"><strong><?= number_format($stats['cost_cents'] / 100, 2) ?> €</strong><span>Coût approx</span></div>
    </div>

    <?php if (!empty($failures)): ?>
        <h3 class="err">⚠️ Échecs (<?= count($failures) ?>)</h3>
        <table>
            <thead><tr><th>ID</th><th>Adresse essayée</th><th>Statut Google</th></tr></thead>
            <tbody>
                <?php foreach ($failures as $f): ?>
                    <tr>
                        <td>#<?= (int)$f['id'] ?></td>
                        <td><?= htmlspecialchars($f['adresse']) ?></td>
                        <td class="err"><?= htmlspecialchars($f['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p style="margin-top:18px;">
        <a class="btn" href="?">↻ Recharger l'état</a>
        <?php if ($stats['total_sans_geoloc'] - $stats['ok'] > 0): ?>
            <a class="btn" href="?run=1&max=<?= $maxRun ?>" style="margin-left:10px;">▶️ Continuer batch suivant</a>
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

</body></html>
