<?php
// admin/admin_ia_cache_stats.php — Statistiques du cache IA + export SQL
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ia_extract_cache (
        hash_sha256 CHAR(64) NOT NULL, model VARCHAR(60) NOT NULL,
        prompt_version VARCHAR(20) NOT NULL DEFAULT '1',
        response_json LONGTEXT NOT NULL, cout_centimes INT DEFAULT 0,
        hit_count INT UNSIGNED DEFAULT 1,
        first_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        confidence TINYINT UNSIGNED NULL, source_origin VARCHAR(40) NULL,
        PRIMARY KEY (hash_sha256,model,prompt_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$stats = $pdo->query("SELECT
    COUNT(*) AS nb_entries,
    SUM(hit_count) AS total_hits,
    SUM(cout_centimes) AS cout_total_centimes,
    SUM((hit_count - 1) * cout_centimes) AS economies_centimes,
    MIN(first_at) AS first_at, MAX(last_at) AS last_at
    FROM ia_extract_cache")->fetch(PDO::FETCH_ASSOC);

$byModel = $pdo->query("SELECT model, prompt_version, COUNT(*) AS n,
    SUM(hit_count) AS hits, SUM(cout_centimes) AS cout,
    SUM((hit_count - 1) * cout_centimes) AS economies
    FROM ia_extract_cache GROUP BY model, prompt_version ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);

$recent = $pdo->query("SELECT LEFT(hash_sha256,12) AS hash_short, model, prompt_version,
    cout_centimes, hit_count, first_at, last_at, confidence
    FROM ia_extract_cache ORDER BY last_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Cache IA — Stats</title>
<style>
body { font-family: Sora, sans-serif; padding:30px; background:#f7f4ef; max-width:1100px; }
h1 { font-size:22px; }
.card { background:#fff; border-radius:12px; padding:18px 22px; margin-bottom:14px; box-shadow:0 4px 14px rgba(0,0,0,.08); }
.kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
.kpi { background:#fff; padding:14px; border-radius:10px; box-shadow:2px 2px 6px #e3dfd8; }
.kpi strong { display:block; font-size:24px; color:#2c5687; }
.kpi span { font-size:11px; color:#7a766f; text-transform:uppercase; }
.kpi.econo strong { color:#2d6a35; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { padding:7px 10px; border-bottom:1px solid #f0ece6; text-align:left; }
th { background:#f4f1ec; font-size:10.5px; text-transform:uppercase; color:#5a5650; }
.mono { font-family:'DM Mono',monospace; }
.btn { display:inline-block; padding:9px 16px; background:#4878a6; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; font-size:13px; }
.btn:hover { background:#3a6890; }
</style></head><body>

<h1>💰 Cache IA — Statistiques</h1>

<div class="card">
    <div class="kpis">
        <div class="kpi"><strong><?= (int)$stats['nb_entries'] ?></strong><span>Fichiers en cache</span></div>
        <div class="kpi"><strong><?= (int)$stats['total_hits'] ?></strong><span>Lectures totales (analyses + cache hits)</span></div>
        <div class="kpi"><strong><?= number_format(((int)$stats['cout_total_centimes']) / 100, 2, ',', ' ') ?> €</strong><span>Coût initial cumulé</span></div>
        <div class="kpi econo"><strong>+<?= number_format(((int)$stats['economies_centimes']) / 100, 2, ',', ' ') ?> €</strong><span>💰 Économies cache</span></div>
    </div>
    <p style="font-size:11px; color:#7a766f; margin-top:10px;">
        Première entrée : <?= htmlspecialchars((string)($stats['first_at'] ?? '-')) ?> ·
        Dernière utilisation : <?= htmlspecialchars((string)($stats['last_at'] ?? '-')) ?>
    </p>
</div>

<div class="card">
    <h2 style="font-size:15px; margin:0 0 12px;">Par modèle / version prompt</h2>
    <table>
        <thead><tr><th>Modèle</th><th>Version prompt</th><th>Entrées</th><th>Lectures</th><th>Coût initial</th><th>Économies</th></tr></thead>
        <tbody>
        <?php foreach ($byModel as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['model']) ?></strong></td>
                <td class="mono">v<?= htmlspecialchars($r['prompt_version']) ?></td>
                <td><?= (int)$r['n'] ?></td>
                <td><?= (int)$r['hits'] ?></td>
                <td><?= number_format(((int)$r['cout']) / 100, 2, ',', ' ') ?> €</td>
                <td style="color:#2d6a35; font-weight:700;">+<?= number_format(((int)$r['economies']) / 100, 2, ',', ' ') ?> €</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="font-size:15px; margin:0 0 12px;">30 dernières utilisations</h2>
    <table>
        <thead><tr><th>Hash</th><th>Modèle</th><th>v</th><th>Coût</th><th>Hits</th><th>Conf</th><th>Première</th><th>Dernière</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars($r['hash_short']) ?>…</td>
                <td><?= htmlspecialchars($r['model']) ?></td>
                <td class="mono">v<?= htmlspecialchars($r['prompt_version']) ?></td>
                <td><?= $r['cout_centimes'] ?> ct</td>
                <td><strong><?= (int)$r['hit_count'] ?></strong></td>
                <td><?= $r['confidence'] !== null ? (int)$r['confidence'] . '%' : '-' ?></td>
                <td style="font-size:10.5px;"><?= htmlspecialchars(date('d/m/y H:i', strtotime((string)$r['first_at']))) ?></td>
                <td style="font-size:10.5px;"><?= htmlspecialchars(date('d/m/y H:i', strtotime((string)$r['last_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" style="background:#eef4fb;">
    <h2 style="font-size:15px; margin:0 0 8px;">📦 Export / Import entre environnements</h2>
    <p style="font-size:12.5px; color:#5a5650;">
        Pour qu'un fichier analysé en local NE SOIT PAS RE-PAYÉ sur dev ou prod, exporte le cache SQL et importe-le dans l'autre environnement.
    </p>
    <p>
        <a class="btn" href="<?= htmlspecialchars(app_url('/admin/admin_ia_cache_export.php')) ?>">📥 Télécharger l'export SQL</a>
    </p>
</div>

</body></html>
