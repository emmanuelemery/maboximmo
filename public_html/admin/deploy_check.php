<?php
declare(strict_types=1);

/**
 * Page de vérification déploiement.
 *
 * Affiche en un seul écran l'état du déploiement :
 *   - Environnement (dev / prod)
 *   - Nom de la BDD connectée (détecte si on est sur la bonne)
 *   - Commit git déployé (lu depuis git_version.txt écrit par le workflow)
 *   - Liste des fichiers critiques (existence + date)
 *   - Migrations pending (présentes dans inc/migrations/ mais pas dans _migrations_applied)
 *
 * Accès : role_id = 1.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = $GLOBALS['pdo'];

// ── Détection environnement ────────────────────────────────────────
$httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$isProd = in_array($httpHost, ['maboximmo.fr', 'www.maboximmo.fr'], true);
$isDev  = str_contains($httpHost, 'dev.');

// ── BDD connectée ──────────────────────────────────────────────────
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$dbIsDev  = str_ends_with($dbName, '_dev');
$dbIsProd = !$dbIsDev;

// Incohérence si on est sur maboximmo.fr mais connecté à BDD dev, ou inverse
$dbMismatch = ($isProd && $dbIsDev) || ($isDev && $dbIsProd);

// ── Commit git déployé ──────────────────────────────────────────────
$gitVersionFile = dirname(__DIR__) . '/git_version.txt';
$gitSha = is_file($gitVersionFile) ? trim((string)file_get_contents($gitVersionFile)) : null;

// ── Migrations pending ─────────────────────────────────────────────
$migrationsDir = dirname(__DIR__) . '/inc/migrations/';
$migrationFiles = is_dir($migrationsDir) ? glob($migrationsDir . '*.php') : [];
sort($migrationFiles);

$available = [];
foreach ($migrationFiles as $f) {
    $data = @require $f;
    if (is_array($data) && !empty($data['id'])) {
        $available[$data['id']] = [
            'title'    => $data['title'] ?? '(sans titre)',
            'file'     => basename($f),
            'created'  => $data['created_at'] ?? null,
        ];
    }
}

$applied = [];
try {
    $stmt = $pdo->query("SELECT id, applied_at, statements_ok, statements_err FROM `_migrations_applied`");
    foreach ($stmt as $row) $applied[$row['id']] = $row;
} catch (Throwable $e) {
    // Table _migrations_applied pas encore créée
}

$pending = array_diff_key($available, $applied);
$errored = [];
foreach ($applied as $id => $row) {
    if ((int)$row['statements_err'] > 0) $errored[$id] = $row;
}

// ── Fichiers critiques à vérifier ──────────────────────────────────
$criticalFiles = [
    'Config' => [
        'config/db.php',
        'config/ubiflow_mapping.php',
    ],
    'Helpers' => [
        'inc/bootstrap.php',
        'inc/auth.php',
        'inc/image_tools.php',
        'inc/bien_photos_manager.php',
        'inc/annonce_photos_manager.php',
    ],
    'Admin' => [
        'admin/admin_migrations.php',
        'admin/tools_photos_recompress.php',
        'admin/deploy_check.php',
    ],
    'Migrations récentes' => [
        'inc/migrations/20260421_annonces_ancien_loyer.php',
        'inc/migrations/20260421_biens_photos_lbc_variant.php',
    ],
];

$fileChecks = [];
foreach ($criticalFiles as $group => $paths) {
    foreach ($paths as $p) {
        $abs = dirname(__DIR__) . '/' . $p;
        $fileChecks[$group][] = [
            'path'    => $p,
            'exists'  => is_file($abs),
            'mtime'   => is_file($abs) ? filemtime($abs) : null,
            'size'    => is_file($abs) ? filesize($abs) : null,
        ];
    }
}

// ── Action : Vider OPCache ─────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_opcache') {
    verify_csrf('deploy_check');
    if (function_exists('opcache_reset')) {
        $ok = @opcache_reset();
        $flash = ['type' => $ok ? 'success' : 'warning', 'msg' => $ok ? '✅ OPCache vidé.' : '⚠️ opcache_reset() a échoué (peut-être désactivé).'];
    } else {
        $flash = ['type' => 'warning', 'msg' => 'opcache_reset() non disponible sur ce serveur.'];
    }
}

$csrf = csrf_token('deploy_check');

$appLayout = true;
$pageTitle = 'Vérification déploiement';
$bodyClass = '';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
  .dc-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 20px; }
  .dc-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .dc-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; }
  .dc-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
  .dc-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .dc-flash.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
  .dc-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }

  .dc-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 18px; }
  @media (max-width: 780px) { .dc-row { grid-template-columns: 1fr; } }
  .dc-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; }
  .dc-card h3 { font-size: 13px; margin: 0 0 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
  .dc-kv { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #e5e7eb; font-size: 13px; }
  .dc-kv:last-child { border-bottom: 0; }
  .dc-kv .k { color: #64748b; }
  .dc-kv .v { color: #0f172a; font-weight: 600; font-family: monospace; font-size: 12px; }
  .dc-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .dc-badge.ok    { background: #f0fdf4; color: #166534; }
  .dc-badge.err   { background: #fef2f2; color: #991b1b; }
  .dc-badge.warn  { background: #fffbeb; color: #92400e; }

  .dc-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
  .dc-section h2 { font-size: 16px; margin: 0 0 14px; color: #0f172a; display: flex; align-items: center; gap: 10px; }
  .dc-section table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .dc-section th { text-align: left; padding: 8px 10px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .04em; font-size: 11px; }
  .dc-section td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-family: monospace; font-size: 12px; }
  .dc-section tr:last-child td { border-bottom: 0; }
  .dc-group-title { font-weight: 700; color: #475569; background: #f8fafc; }

  .dc-btn { padding: 8px 14px; border-radius: 8px; background: #0ea5e9; color: #fff; border: none; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .dc-btn:hover { background: #0284c7; }
  .dc-btn.secondary { background: #fff; color: #0369a1; border: 1px solid #0ea5e9; }
</style>

<div class="dc-wrap">
  <h1>🔍 Vérification déploiement</h1>
  <p class="sub">Un coup d'œil pour savoir si tout est aligné entre le code déployé, la BDD et les migrations.</p>

  <?php if ($flash): ?>
    <div class="dc-flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if ($dbMismatch): ?>
    <div class="dc-flash error">
      🚨 <strong>INCOHÉRENCE CRITIQUE</strong> : Le domaine <code><?= htmlspecialchars($httpHost) ?></code>
      est connecté à la BDD <code><?= htmlspecialchars($dbName) ?></code>. Vérifier la présence d'un
      <code>db_config_dev.php</code> qui traînerait en prod (ou inverse).
    </div>
  <?php endif; ?>

  <!-- Environnement + BDD + commit -->
  <div class="dc-row">
    <div class="dc-card">
      <h3>🌍 Environnement</h3>
      <div class="dc-kv"><span class="k">HTTP_HOST</span><span class="v"><?= htmlspecialchars($httpHost) ?></span></div>
      <div class="dc-kv"><span class="k">Type</span><span class="v">
        <?php if ($isProd): ?>
          <span class="dc-badge ok">PROD</span>
        <?php elseif ($isDev): ?>
          <span class="dc-badge warn">DEV</span>
        <?php else: ?>
          <span class="dc-badge warn">LOCAL</span>
        <?php endif; ?>
      </span></div>
      <div class="dc-kv"><span class="k">PHP version</span><span class="v"><?= PHP_VERSION ?></span></div>
      <div class="dc-kv"><span class="k">OPCache</span><span class="v">
        <?php if (function_exists('opcache_get_status') && @opcache_get_status()): ?>
          <span class="dc-badge ok">ACTIF</span>
        <?php else: ?>
          <span class="dc-badge warn">INACTIF</span>
        <?php endif; ?>
      </span></div>
    </div>

    <div class="dc-card">
      <h3>🗄️ Base de données</h3>
      <div class="dc-kv"><span class="k">Nom</span><span class="v"><?= htmlspecialchars($dbName) ?></span></div>
      <div class="dc-kv"><span class="k">Cohérence</span><span class="v">
        <?php if ($dbMismatch): ?>
          <span class="dc-badge err">MISMATCH</span>
        <?php else: ?>
          <span class="dc-badge ok">OK</span>
        <?php endif; ?>
      </span></div>
      <div class="dc-kv"><span class="k">Nb biens</span><span class="v"><?= (int)($pdo->query('SELECT COUNT(*) FROM biens')->fetchColumn() ?? 0) ?></span></div>
      <div class="dc-kv"><span class="k">Nb users</span><span class="v"><?= (int)($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() ?? 0) ?></span></div>
    </div>
  </div>

  <!-- Commit git + OPCache action -->
  <div class="dc-row">
    <div class="dc-card">
      <h3>📦 Commit déployé</h3>
      <?php if ($gitSha): ?>
        <div class="dc-kv"><span class="k">SHA</span><span class="v"><?= htmlspecialchars(substr($gitSha, 0, 12)) ?></span></div>
        <div class="dc-kv"><span class="k">Lien GitHub</span><span class="v"><a href="https://github.com/PIEM99/maboximmo/commit/<?= htmlspecialchars($gitSha) ?>" target="_blank">voir le commit</a></span></div>
      <?php else: ?>
        <p style="color:#92400e; font-size:12px; margin:6px 0 0;">
          ⚠️ Pas de fichier <code>git_version.txt</code>. Le workflow doit être mis à jour pour en générer un.
        </p>
      <?php endif; ?>
    </div>

    <div class="dc-card">
      <h3>🧹 OPCache</h3>
      <p style="color:#64748b; font-size:12px; margin:0 0 10px;">À vider après un FTP si le code semble ne pas prendre.</p>
      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="clear_opcache">
        <button type="submit" class="dc-btn">🗑️ Vider OPCache maintenant</button>
      </form>
    </div>
  </div>

  <!-- Migrations -->
  <div class="dc-section">
    <h2>🗃️ Migrations BDD
      <?php if (count($pending) > 0): ?>
        <span class="dc-badge err"><?= count($pending) ?> en attente</span>
      <?php elseif (count($errored) > 0): ?>
        <span class="dc-badge warn"><?= count($errored) ?> avec erreurs</span>
      <?php else: ?>
        <span class="dc-badge ok">Toutes appliquées</span>
      <?php endif; ?>
    </h2>

    <?php if (count($pending) > 0): ?>
      <p style="color:#991b1b; font-size:12px; margin:0 0 12px;">
        Va sur <a href="admin_migrations.php">admin_migrations.php</a> pour les appliquer.
      </p>
    <?php endif; ?>

    <table>
      <thead><tr><th style="width:30%;">ID</th><th>Titre</th><th style="width:20%;">Statut</th></tr></thead>
      <tbody>
        <?php foreach ($available as $id => $m):
          $st = $applied[$id] ?? null;
        ?>
          <tr>
            <td><?= htmlspecialchars($id) ?></td>
            <td style="font-family:inherit; font-size:13px;"><?= htmlspecialchars($m['title']) ?></td>
            <td>
              <?php if (!$st): ?>
                <span class="dc-badge err">EN ATTENTE</span>
              <?php elseif ((int)$st['statements_err'] > 0): ?>
                <span class="dc-badge warn">PARTIELLE</span>
              <?php else: ?>
                <span class="dc-badge ok">OK</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Fichiers critiques -->
  <div class="dc-section">
    <h2>📁 Fichiers critiques</h2>
    <table>
      <thead><tr><th style="width:50%;">Chemin</th><th style="width:15%;">Statut</th><th style="width:20%;">Modifié le</th><th style="width:15%;">Taille</th></tr></thead>
      <tbody>
        <?php foreach ($fileChecks as $group => $rows): ?>
          <tr><td colspan="4" class="dc-group-title"><?= htmlspecialchars($group) ?></td></tr>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['path']) ?></td>
              <td>
                <?php if ($r['exists']): ?>
                  <span class="dc-badge ok">OK</span>
                <?php else: ?>
                  <span class="dc-badge err">MANQUANT</span>
                <?php endif; ?>
              </td>
              <td><?= $r['mtime'] ? date('Y-m-d H:i', $r['mtime']) : '—' ?></td>
              <td><?= $r['size'] ? number_format($r['size'] / 1024, 1, ',', ' ') . ' Ko' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
