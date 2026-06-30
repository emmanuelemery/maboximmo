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
require_admin_or_super_admin();

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
                // query()+closeCursor() consomme tout result set (ex. SELECT de
                // vérification, CREATE ... AS SELECT) → évite l'erreur 2014
                // "Cannot execute queries while other unbuffered queries are active"
                // sur le statement suivant. (exec() laissait le curseur ouvert.)
                $st = $pdo->query($stmt);
                if ($st instanceof \PDOStatement) { $st->closeCursor(); }
                $ok++;
            } catch (Throwable $e) {
                $err++;
                $errLog[] = '#' . ($idx + 1) . ' — ' . $e->getMessage();
            }
        }
        // Reset connexion PDO : certaines migrations utilisent PREPARE/EXECUTE
        // côté MySQL qui laissent des result sets non consommés et bloquent
        // le prepare suivant ("Cannot execute queries while other unbuffered
        // queries are active"). On force une connexion fraîche pour le log.
        try {
            if (function_exists('db_reconnect_fresh')) {
                $pdo = db_reconnect_fresh();
                $GLOBALS['pdo'] = $pdo;
            } else {
                // Fallback : drain manuel des result sets pendants
                while (($extra = $pdo->query('SELECT 1')) && $extra->fetch()) { /* drain */ }
            }
        } catch (Throwable) { /* non bloquant pour le log */ }

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

$csrf = csrf_token('admin_migrations');

// Détection environnement : dev.maboximmo.fr → on affiche le bouton "Déployer sur prod"
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$isDevEnv = str_contains($host, 'dev.') || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
$prodUrl  = 'https://maboximmo.fr/admin/admin_migrations.php';
$ghPrUrl  = 'https://github.com/PIEM99/maboximmo/compare/main...develop?expand=1';

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
  <p style="margin:0 0 14px;">
    <a href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/agency_dashboard.php') : '/agency_dashboard.php') ?>"
       style="display:inline-flex;align-items:center;gap:6px;color:#4f46e5;text-decoration:none;font-size:13px;font-weight:600;">← Retour à Ma Box Agency</a>
  </p>
  <h1>🗄️ Migrations BDD</h1>
  <p class="sub">Gestion des migrations SQL — statements additifs, rejouables, suivis via <code>_migrations_applied</code>.</p>

  <?php
    // Diagnostic chemin : révèle IMMÉDIATEMENT un mismatch de répertoire (Hostinger
    // ~/public_html vs ~/domains/.../public_html, ou dossier dupliqué) qui ferait
    // que les fichiers uploadés ne sont pas vus par l'app live.
    $migDirReal = realpath($migrationsDir) ?: $migrationsDir;
  ?>
  <div class="mig-flash" style="background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;">
    📁 Dossier réellement scruté par l'app : <code><?= htmlspecialchars($migDirReal) ?></code>
    &nbsp;·&nbsp; <strong><?= count($files) ?></strong> fichier(s) <code>.php</code> trouvé(s)
    &nbsp;·&nbsp; <strong><?= count($migrations) ?></strong> migration(s) valides.
    <br><small>Si ce chemin ne correspond pas à l'endroit où tu déposes tes fichiers (ou si le compte ne grimpe pas après upload), tes migrations sont déposées au mauvais <code>public_html</code>.</small>
  </div>

  <?php if ($flash): ?>
    <div class="mig-flash <?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if (empty($migrations)): ?>
    <div class="mig-empty">
      Aucune migration trouvée dans <code>inc/migrations/</code>.
    </div>
  <?php else:
    // ─── Tri : non-appliquées EN HAUT (ordre croissant), appliquées en bas (ordre croissant) ───
    // Permet de voir immédiatement ce qui reste à appliquer sans scroller.
    $pendingMigs = []; $partialMigs = []; $appliedMigs = [];
    foreach ($migrations as $id => $mig) {
      $row = $applied[$id] ?? null;
      if (!$row)                              $pendingMigs[$id] = $mig;
      elseif ((int)$row['statements_err'] > 0) $partialMigs[$id] = $mig;
      else                                     $appliedMigs[$id] = $mig;
    }
    ksort($pendingMigs); ksort($partialMigs); ksort($appliedMigs);
    $orderedMigs = $pendingMigs + $partialMigs + $appliedMigs;

    $nbPending = count($pendingMigs);
    $nbPartial = count($partialMigs);
    $nbApplied = count($appliedMigs);
  ?>

    <?php if ($nbPending > 0 || $nbPartial > 0): ?>
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#92400e;">
        🔔 <strong><?= $nbPending + $nbPartial ?> migration<?= ($nbPending + $nbPartial) > 1 ? 's' : '' ?></strong> à traiter
        (<?= $nbPending ?> en attente<?php if ($nbPartial > 0): ?>, <?= $nbPartial ?> partielles<?php endif; ?>)
        — affichées en premier ci-dessous, ordre chronologique croissant.
        <span style="margin-left:8px;color:#a16207;">·  <?= $nbApplied ?> migration<?= $nbApplied > 1 ? 's' : '' ?> déjà appliquée<?= $nbApplied > 1 ? 's' : '' ?>.</span>
      </div>
    <?php else: ?>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#166534;">
        ✅ Toutes les migrations sont appliquées (<?= $nbApplied ?>). Rien à faire.
      </div>
    <?php endif; ?>

    <?php $_currentSection = null; foreach ($orderedMigs as $id => $mig):
      $row = $applied[$id] ?? null;
      $status = 'pending';
      if ($row) $status = ((int)$row['statements_err'] > 0) ? 'partial' : 'applied';
      $statements = admin_split_sql((string)$mig['sql']);

      // Séparateur visuel entre les sections
      if ($_currentSection !== $status) {
        $_currentSection = $status;
        if ($status === 'applied' && ($nbPending > 0 || $nbPartial > 0)):
    ?>
      <div style="margin:24px 0 12px;padding:8px 0;border-top:2px dashed #cbd5e1;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;">
        ↓ Migrations déjà appliquées (historique)
      </div>
    <?php
        endif;
      }
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

        <div class="mig-actions" style="margin-top: 12px; display: flex; gap: 8px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
          <form method="post" onsubmit="return confirm('Appliquer cette migration ?');" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="migration_id" value="<?= htmlspecialchars($id) ?>">
            <button type="submit" class="mig-btn <?= $status !== 'pending' ? 'rerun' : '' ?>">
              <?= $status === 'pending' ? '🚀 Appliquer' : '↻ Rejouer (additif)' ?>
            </button>
          </form>

          <?php if ($isDevEnv && $status === 'applied'): ?>
            <button type="button" class="mig-btn deploy-prod"
                    data-migration-id="<?= htmlspecialchars($id) ?>"
                    data-migration-title="<?= htmlspecialchars((string)$mig['title']) ?>"
                    style="background:#c87870;">📤 Déployer sur maboximmo.fr (prod)</button>
          <?php elseif (!$isDevEnv && $status === 'pending'): ?>
            <span style="font-size:11px;color:#0369a1;background:#f0f9ff;padding:4px 10px;border-radius:6px;border:1px solid #bae6fd;">
              🎯 Environnement prod — cliquer Appliquer après validation sur dev
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <p style="margin-top: 24px; font-size: 10px; color: #94a3b8;">
    Les migrations sont additives (<code>IF NOT EXISTS</code>) et peuvent être rejouées sans casse.
    Base : <?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : '?') ?> • Hôte : <?= htmlspecialchars(defined('DB_HOST') ? DB_HOST : '?') ?>
    <?php if ($isDevEnv): ?>
      <span style="color:#c87870;font-weight:700;">• Environnement DEV</span>
    <?php else: ?>
      <span style="color:#0369a1;font-weight:700;">• Environnement PROD</span>
    <?php endif; ?>
  </p>
</div>

<!-- Modal : Déployer sur prod -->
<div id="deploy-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;max-width:580px;width:calc(100% - 40px);padding:24px;">
    <h3 style="margin:0 0 10px;color:#0f172a;">📤 Déployer sur <span style="color:#c87870;">maboximmo.fr</span></h3>
    <p style="font-size:12px;color:#475569;line-height:1.5;margin:0 0 12px;">
      Migration : <strong id="deploy-mig-title">—</strong><br>
      <code id="deploy-mig-id" style="font-size:11px;color:#64748b;"></code>
    </p>
    <div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:8px;font-size:12px;color:#92400e;margin-bottom:16px;">
      ⚠️ Le déploiement prod se fait en <strong>deux étapes</strong> — dans l'ordre.
    </div>

    <div style="margin-bottom:14px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <span style="width:24px;height:24px;border-radius:50%;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;">1</span>
        <strong style="font-size:13px;">Pousser le code sur la branche <code>main</code></strong>
      </div>
      <div style="font-size:12px;color:#475569;margin-left:34px;margin-bottom:6px;">
        Ouvre une Pull Request GitHub <code>develop → main</code>. Une fois mergée, le workflow <code>deploy-prod.yml</code> déploie le code sur maboximmo.fr automatiquement (1-2 min).
      </div>
      <a id="deploy-pr-link" href="<?= htmlspecialchars($ghPrUrl) ?>" target="_blank" rel="noopener"
         style="display:inline-block;margin-left:34px;padding:6px 12px;border-radius:6px;background:#24292f;color:#fff;font-size:11px;text-decoration:none;font-weight:700;">
        🔗 Ouvrir la PR GitHub (develop → main)
      </a>
    </div>

    <div style="margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <span style="width:24px;height:24px;border-radius:50%;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;">2</span>
        <strong style="font-size:13px;">Appliquer la migration SQL sur prod</strong>
      </div>
      <div style="font-size:12px;color:#475569;margin-left:34px;margin-bottom:6px;">
        Ouvre la page Migrations BDD sur <code>maboximmo.fr</code> et clique 🚀 Appliquer sur la même migration.
      </div>
      <a id="deploy-prod-link" href="<?= htmlspecialchars($prodUrl) ?>" target="_blank" rel="noopener"
         style="display:inline-block;margin-left:34px;padding:6px 12px;border-radius:6px;background:#c87870;color:#fff;font-size:11px;text-decoration:none;font-weight:700;">
        🎯 Ouvrir maboximmo.fr/admin/admin_migrations.php
      </a>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" id="deploy-close" style="padding:8px 14px;border-radius:6px;background:#fff;color:#475569;border:1px solid #cbd5e1;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">Fermer</button>
    </div>
  </div>
</div>

<script>
(function() {
  const modal = document.getElementById('deploy-modal');
  const close = document.getElementById('deploy-close');
  document.querySelectorAll('.deploy-prod').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('deploy-mig-id').textContent = btn.dataset.migrationId;
      document.getElementById('deploy-mig-title').textContent = btn.dataset.migrationTitle;
      modal.style.display = 'flex';
    });
  });
  if (close) close.addEventListener('click', () => modal.style.display = 'none');
  modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });
})();
</script>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
