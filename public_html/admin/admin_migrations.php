<?php
declare(strict_types=1);

/**
 * Gestionnaire de migrations SQL (permanent).
 *
 * Lit les fichiers de migration dans public_html/inc/migrations/ et affiche
 * leur statut (appliquée / en attente) via la table _migrations_applied.
 *
 * Réservé aux admins (role_id = 1).
 *
 * Convention migration : fichier PHP renvoyant un array { id, title, description, created_at, sql }
 * Règle d'or : statements ADDITIFS uniquement (IF NOT EXISTS) → rejouables sans casse.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = $GLOBALS['pdo'];

// ─── Crée la table de suivi si absente (idempotent) ──────────────────
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

// ─── Chargement des migrations disponibles ───────────────────────────
$migrationsDir = __DIR__ . '/../inc/migrations/';
$files = is_dir($migrationsDir) ? glob($migrationsDir . '*.php') : [];
sort($files);

$migrations = [];
foreach ($files as $file) {
    $data = require $file;
    if (!is_array($data) || empty($data['id']) || empty($data['sql'])) continue;
    $data['_file'] = basename($file);
    $migrations[$data['id']] = $data;
}

// ─── Statut courant ───────────────────────────────────────────────────
$applied = [];
$stmt = $pdo->query("SELECT id, title, applied_at, applied_by, statements_ok, statements_err, error_log FROM `_migrations_applied`");
foreach ($stmt as $row) {
    $applied[$row['id']] = $row;
}

// ─── Helper : split SQL en statements (gère strings et commentaires) ─
function admin_split_sql(string $sql): array
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

// ─── Action appliquer une migration ──────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    verify_csrf('admin_migrations');
    $id = (string)($_POST['migration_id'] ?? '');
    if (!isset($migrations[$id])) {
        $flash = ['type' => 'error', 'msg' => 'Migration inconnue : ' . htmlspecialchars($id)];
    } else {
        $mig = $migrations[$id];
        $statements = admin_split_sql((string)$mig['sql']);
        $ok = 0; $err = 0; $errLog = [];
        foreach ($statements as $idx => $stmt) {
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (Throwable $e) {
                $err++;
                $errLog[] = '#' . ($idx + 1) . ' — ' . $e->getMessage();
            }
        }
        $pdo->prepare("
            INSERT INTO `_migrations_applied` (id, title, applied_at, applied_by, statements_ok, statements_err, error_log)
            VALUES (?, ?, NOW(), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                applied_at = NOW(), applied_by = VALUES(applied_by),
                statements_ok = VALUES(statements_ok), statements_err = VALUES(statements_err),
                error_log = VALUES(error_log)
        ")->execute([
            $id, (string)$mig['title'],
            (int)($_SESSION['user_id'] ?? 0),
            $ok, $err, $errLog ? implode("\n", $errLog) : null,
        ]);

        $flash = $err === 0
            ? ['type' => 'success', 'msg' => '✅ Migration « ' . htmlspecialchars((string)$mig['title']) . ' » appliquée — ' . $ok . ' statements OK']
            : ['type' => 'warning', 'msg' => '⚠️ Migration partielle — ' . $ok . ' OK, ' . $err . ' en erreur. Voir détails ci-dessous.'];

        // Rafraîchit la liste appliquée
        $stmt = $pdo->query("SELECT id, title, applied_at, applied_by, statements_ok, statements_err, error_log FROM `_migrations_applied`");
        $applied = [];
        foreach ($stmt as $row) $applied[$row['id']] = $row;
    }
}

$csrf = generate_csrf_token('admin_migrations');

$appLayout = true;
$pageTitle = 'Migrations BDD';
$bodyClass = '';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
  .mig-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 20px; }
  .mig-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .mig-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 24px; }
  .mig-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; }
  .mig-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .mig-flash.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
  .mig-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .mig-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 20px; margin-bottom: 12px; }
  .mig-head { display: flex; align-items: center; gap: 12px; }
  .mig-id { font-family: monospace; font-size: 11px; color: #64748b; }
  .mig-title { font-size: 14px; font-weight: 700; color: #0f172a; flex: 1; }
  .mig-badge { padding: 3px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .mig-badge.pending { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
  .mig-badge.applied { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
  .mig-badge.partial { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .mig-desc { color: #475569; font-size: 12px; margin: 8px 0 12px; line-height: 1.5; }
  .mig-meta { display: flex; gap: 16px; font-size: 11px; color: #64748b; margin-bottom: 10px; }
  .mig-sql { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; font-family: monospace; font-size: 11px; max-height: 240px; overflow-y: auto; white-space: pre-wrap; line-height: 1.5; margin-bottom: 10px; }
  .mig-actions { display: flex; gap: 8px; align-items: center; }
  .mig-btn { padding: 8px 14px; border-radius: 8px; background: #0ea5e9; color: #fff; border: none; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .mig-btn:hover { background: #0284c7; }
  .mig-btn.rerun { background: #fff; color: #0369a1; border: 1px solid #0ea5e9; }
  .mig-errlog { background: #fef2f2; border-left: 3px solid #dc2626; padding: 8px 12px; border-radius: 6px; font-size: 11px; color: #991b1b; white-space: pre-wrap; margin-top: 8px; }
  .mig-empty { background: #f8fafc; border: 2px dashed #cbd5e1; padding: 30px; border-radius: 12px; text-align: center; color: #64748b; }
  details > summary { cursor: pointer; font-size: 12px; color: #0369a1; user-select: none; }
</style>

<div class="mig-wrap">
  <h1>🗄️ Migrations BDD</h1>
  <p class="sub">Gestion des migrations SQL — statements additifs, rejouables, suivis via <code>_migrations_applied</code>.</p>

  <?php if ($flash): ?>
    <div class="mig-flash <?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if (empty($migrations)): ?>
    <div class="mig-empty">
      Aucune migration trouvée dans <code>inc/migrations/</code>.
    </div>
  <?php else: ?>
    <?php foreach ($migrations as $id => $mig):
      $row = $applied[$id] ?? null;
      $status = 'pending';
      if ($row) $status = ((int)$row['statements_err'] > 0) ? 'partial' : 'applied';
      $statements = admin_split_sql((string)$mig['sql']);
    ?>
      <div class="mig-card">
        <div class="mig-head">
          <span class="mig-id"><?= htmlspecialchars((string)$id) ?></span>
          <span class="mig-title"><?= htmlspecialchars((string)$mig['title']) ?></span>
          <?php if ($status === 'applied'): ?>
            <span class="mig-badge applied">✓ Appliquée</span>
          <?php elseif ($status === 'partial'): ?>
            <span class="mig-badge partial">⚠ Partielle</span>
          <?php else: ?>
            <span class="mig-badge pending">En attente</span>
          <?php endif; ?>
        </div>

        <?php if (!empty($mig['description'])): ?>
          <div class="mig-desc"><?= htmlspecialchars((string)$mig['description']) ?></div>
        <?php endif; ?>

        <div class="mig-meta">
          <span>📅 Créée le <?= htmlspecialchars((string)($mig['created_at'] ?? '—')) ?></span>
          <span>📄 <code><?= htmlspecialchars((string)$mig['_file']) ?></code></span>
          <span>🔢 <?= count($statements) ?> statements</span>
          <?php if ($row): ?>
            <span>▶️ Exécutée le <?= htmlspecialchars((string)$row['applied_at']) ?></span>
            <span>✓ <?= (int)$row['statements_ok'] ?> OK • ✗ <?= (int)$row['statements_err'] ?></span>
          <?php endif; ?>
        </div>

        <details>
          <summary>Voir le SQL</summary>
          <div class="mig-sql"><?= htmlspecialchars((string)$mig['sql']) ?></div>
        </details>

        <?php if (!empty($row['error_log'])): ?>
          <div class="mig-errlog"><strong>Erreurs dernier run :</strong>
<?= htmlspecialchars((string)$row['error_log']) ?></div>
        <?php endif; ?>

        <div class="mig-actions" style="margin-top: 12px;">
          <form method="post" onsubmit="return confirm('Appliquer cette migration ?');" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="migration_id" value="<?= htmlspecialchars($id) ?>">
            <button type="submit" class="mig-btn <?= $status !== 'pending' ? 'rerun' : '' ?>">
              <?= $status === 'pending' ? '🚀 Appliquer' : '↻ Rejouer (additif)' ?>
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <p style="margin-top: 24px; font-size: 10px; color: #94a3b8;">
    Les migrations sont additives (<code>IF NOT EXISTS</code>) et peuvent être rejouées sans casse.
    Base : <?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : '?') ?> • Hôte : <?= htmlspecialchars(defined('DB_HOST') ? DB_HOST : '?') ?>
  </p>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
