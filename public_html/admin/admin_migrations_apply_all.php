<?php
declare(strict_types=1);

/**
 * ADMIN — Applique toutes les migrations en attente en un clic.
 *
 * Réutilise le moteur de admin_migrations.php : scan inc/migrations/,
 * skippe celles déjà tracées dans _migrations_applied, applique les
 * autres dans l'ordre alphabétique, log le résultat.
 *
 * Idempotent : les migrations elles-mêmes sont en IF NOT EXISTS / INSERT
 * IGNORE → rejouables sans casse. Cette page peut être rappelée.
 *
 * URL : /admin/admin_migrations_apply_all.php
 *   ?confirm=1 → applique réellement (sinon affiche juste le plan)
 *   ?force=1   → applique aussi les déjà appliquées (rejeu complet)
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo     = $GLOBALS['pdo'];
$confirm = isset($_GET['confirm']);
$force   = isset($_GET['force']);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `_migrations_applied` (
        `id` VARCHAR(100) NOT NULL,
        `title` VARCHAR(200) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `applied_by` INT UNSIGNED NULL,
        `statements_ok` INT NOT NULL DEFAULT 0,
        `statements_err` INT NOT NULL DEFAULT 0,
        `error_log` TEXT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$migrationsDir = __DIR__ . '/../inc/migrations/';
$files = is_dir($migrationsDir) ? glob($migrationsDir . '*.php') : [];
sort($files);

$migrations = [];
foreach ($files as $file) {
    $data = @require $file;
    if (!is_array($data) || empty($data['id']) || empty($data['sql'])) continue;
    $data['_file'] = basename($file);
    $migrations[$data['id']] = $data;
}

$applied = [];
$stmt = $pdo->query("SELECT id FROM `_migrations_applied`");
foreach ($stmt as $row) $applied[$row['id']] = true;

function admin_split_sql_v2(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $inS = $inD = $inB = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $nx = ($i + 1 < $len) ? $sql[$i + 1] : '';
        if (!$inS && !$inD && !$inB) {
            if ($ch === '-' && $nx === '-') { while ($i < $len && $sql[$i] !== "\n") $i++; continue; }
            if ($ch === '#') { while ($i < $len && $sql[$i] !== "\n") $i++; continue; }
            if ($ch === '/' && $nx === '*') { $i += 2; while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) $i++; $i++; continue; }
        }
        if ($ch === '\\' && ($inS || $inD)) { $buf .= $ch; if ($i + 1 < $len) { $buf .= $sql[$i + 1]; $i++; } continue; }
        if (!$inD && !$inB && $ch === "'") { $inS = !$inS; $buf .= $ch; continue; }
        if (!$inS && !$inB && $ch === '"') { $inD = !$inD; $buf .= $ch; continue; }
        if (!$inS && !$inD && $ch === '`') { $inB = !$inB; $buf .= $ch; continue; }
        if (!$inS && !$inD && !$inB && $ch === ';') { $t = trim($buf); if ($t !== '') $stmts[] = $t; $buf = ''; continue; }
        $buf .= $ch;
    }
    $tail = trim($buf); if ($tail !== '') $stmts[] = $tail;
    return $stmts;
}

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Apply pending migrations</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:1100px;margin:24px auto;padding:0 20px;line-height:1.5}'
   . 'h1{color:#0f172a;margin-bottom:6px}.sub{color:#64748b;font-size:13px;margin-bottom:20px}'
   . '.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:12px}'
   . '.pending{border-left:4px solid #f59e0b;background:#fffbeb}'
   . '.ok{border-left:4px solid #16a34a;background:#f0fdf4}'
   . '.err{border-left:4px solid #dc2626;background:#fef2f2}'
   . '.applied{opacity:.6}'
   . '.btn{display:inline-block;padding:10px 18px;border-radius:8px;background:#0ea5e9;color:#fff;text-decoration:none;font-weight:700;font-size:14px;margin:8px 8px 8px 0}'
   . '.btn.danger{background:#dc2626}.btn.warn{background:#f59e0b}'
   . 'code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}'
   . '.errlog{background:#fee;padding:8px;border-radius:6px;font-family:monospace;font-size:11px;white-space:pre-wrap}'
   . '</style></head><body>';

echo '<h1>🚀 Migrations BDD — Apply pending (un clic)</h1>';
echo '<p class="sub">Applique toutes les migrations <code>inc/migrations/*.php</code> non encore tracées dans <code>_migrations_applied</code>. Idempotent : les migrations sont rejouables sans casse.</p>';

$pending = [];
foreach ($migrations as $id => $mig) {
    if ($force || !isset($applied[$id])) $pending[$id] = $mig;
}

if (empty($migrations)) {
    echo '<div class="box err"><strong>Aucune migration trouvée.</strong> Vérifie que <code>public_html/inc/migrations/*.php</code> contient bien les fichiers.</div>';
    echo '</body></html>'; exit;
}

if (empty($pending)) {
    echo '<div class="box ok"><strong>✅ Toutes les migrations sont déjà appliquées</strong> (' . count($migrations) . ' au total).</div>';
    echo '<p><a href="admin_migrations_apply_all.php?force=1" class="btn warn">↻ Forcer le rejeu de TOUTES les migrations (idempotent)</a></p>';
    echo '<p><a href="admin_migrations.php" class="btn">→ Page Migrations BDD</a></p>';
    echo '</body></html>'; exit;
}

if (!$confirm) {
    echo '<div class="box pending"><strong>🔔 ' . count($pending) . ' migration' . (count($pending) > 1 ? 's' : '') . ' en attente</strong> :</div>';
    foreach ($pending as $id => $mig) {
        echo '<div class="box pending">';
        echo '<strong>' . htmlspecialchars($id) . '</strong> — ' . htmlspecialchars((string)$mig['title']);
        if (!empty($mig['description'])) echo '<br><small>' . htmlspecialchars((string)$mig['description']) . '</small>';
        echo '</div>';
    }
    $href = 'admin_migrations_apply_all.php?confirm=1' . ($force ? '&force=1' : '');
    echo '<p style="margin-top:20px"><a href="' . htmlspecialchars($href) . '" class="btn danger" onclick="return confirm(\'Appliquer ces ' . count($pending) . ' migrations ?\')">🚀 APPLIQUER MAINTENANT</a></p>';
    echo '</body></html>'; exit;
}

$totalOk = 0; $totalErr = 0; $appliedNow = 0;
$insert = $pdo->prepare("
    INSERT INTO `_migrations_applied` (id, title, applied_at, applied_by, statements_ok, statements_err, error_log)
    VALUES (?, ?, NOW(), ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        applied_at = NOW(), applied_by = VALUES(applied_by),
        statements_ok = VALUES(statements_ok), statements_err = VALUES(statements_err),
        error_log = VALUES(error_log)
");

foreach ($pending as $id => $mig) {
    $statements = admin_split_sql_v2((string)$mig['sql']);
    $ok = 0; $err = 0; $errLog = [];
    foreach ($statements as $idx => $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (Throwable $e) {
            $err++;
            $errLog[] = '#' . ($idx + 1) . ' — ' . $e->getMessage() . "\n  SQL: " . substr($stmt, 0, 200);
        }
    }
    $insert->execute([
        $id, (string)$mig['title'],
        (int)($_SESSION['user_id'] ?? 0),
        $ok, $err, $errLog ? implode("\n\n", $errLog) : null,
    ]);
    $totalOk += $ok; $totalErr += $err; $appliedNow++;

    $cls = $err === 0 ? 'ok' : 'err';
    echo '<div class="box ' . $cls . '">';
    echo ($err === 0 ? '✅' : '⚠️') . ' <strong>' . htmlspecialchars($id) . '</strong> — ' . htmlspecialchars((string)$mig['title']);
    echo '<br><small>' . $ok . ' statements OK · ' . $err . ' en erreur</small>';
    if ($errLog) echo '<div class="errlog">' . htmlspecialchars(implode("\n\n", $errLog)) . '</div>';
    echo '</div>';
}

$cls = $totalErr === 0 ? 'ok' : 'err';
echo '<div class="box ' . $cls . '" style="margin-top:24px;font-size:15px">';
echo '<strong>' . ($totalErr === 0 ? '✅ Terminé' : '⚠️ Terminé avec erreurs') . '</strong> — '
   . $appliedNow . ' migrations traitées · ' . $totalOk . ' statements OK · ' . $totalErr . ' en erreur';
echo '</div>';
echo '<p><a href="admin_migrations.php" class="btn">→ Page Migrations BDD (vérification)</a></p>';
echo '</body></html>';
