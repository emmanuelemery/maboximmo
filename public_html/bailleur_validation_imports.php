<?php
/**
 * bailleur_validation_imports.php
 * ─────────────────────────────────
 * Page de suivi & validation des imports GED en masse.
 *
 * 4 phases :
 *   1. Inventaire       (ged_manifest)            — affichage stats
 *   2. Classification   (ged_classification_staging) — validation humaine
 *   3. Extraction IA    (ged_extractions_staging)    — validation humaine
 *   4. Upload prod      (ged_upload_log)              — suivi upload
 *
 * Scope : id_societe=1 (Régie EMERY) id_agence=3 (REGIE EMERY LYON)
 */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = (int)current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé Super Admin.</h1>');
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$phase = (int)($_GET['phase'] ?? 1);
$batch = (string)($_GET['batch'] ?? 'sir_2026_04_24');

$current_page = 'bailleur_validation_imports';

// ─── Phase 1 — Stats inventaire ────────────────────────────
$stats = [];
$recentBatches = [];
try {
    $stB = $pdo->query("SELECT batch_id, COUNT(*) AS nb, MIN(created_at) AS debut
                        FROM ged_manifest GROUP BY batch_id ORDER BY debut DESC LIMIT 10");
    $recentBatches = $stB->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table peut-être absente */ }

try {
    $st = $pdo->prepare("SELECT famille_doc, COUNT(*) AS nb,
                                SUM(taille_octets) AS octets,
                                MIN(created_at) AS premier,
                                MAX(created_at) AS dernier
                         FROM ged_manifest WHERE batch_id = ?
                         GROUP BY famille_doc ORDER BY nb DESC");
    $st->execute([$batch]);
    $stats = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $stats = []; }

$totalFiles = array_sum(array_column($stats, 'nb'));
$totalSize  = array_sum(array_column($stats, 'octets'));

// ─── Doublons MD5 (intra-batch) ────────────────────────────
$doublons = [];
try {
    $stD = $pdo->prepare("
        SELECT md5, COUNT(*) AS nb, GROUP_CONCAT(filename SEPARATOR '  |  ') AS fichiers
        FROM ged_manifest WHERE batch_id = ?
        GROUP BY md5 HAVING nb > 1 ORDER BY nb DESC LIMIT 50
    ");
    $stD->execute([$batch]);
    $doublons = $stD->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ─── Liste paginée ─────────────────────────────────────────
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$fFam = (string)($_GET['fam'] ?? '');

$files = [];
try {
    $where = 'batch_id = :b';
    $params = [':b' => $batch];
    if ($fFam !== '') { $where .= ' AND famille_doc = :f'; $params[':f'] = $fFam; }
    $st = $pdo->prepare("SELECT id, filename, path_source, famille_doc, md5, taille_octets, status, created_at
                         FROM ged_manifest WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ─── Layout ────────────────────────────────────────────────
$layout_title   = 'Imports GED — Validation';
$layout_module  = 'Bailleur';
$layout_sidebar = 'sidebar_bailleur';

$famIcons = [
    'loyer' => '💶', 'crg' => '📊', 'bail' => '📜',
    'taxe_fonciere' => '🏛️', 'diagnostic' => '🔬',
    'valorisation' => '💰', 'autre' => '📄',
];

$layout_head_actions = '<a href="bailleur_ged.php" class="ph-btn secondary">← GED</a>';

require_once __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
.vi-wrap { max-width: 1200px; margin: 0 auto; padding: 18px 20px 80px; }
.vi-tabs { display: flex; gap: 6px; margin-bottom: 22px; border-bottom: 2px solid #e5e7eb; }
.vi-tab { padding: 10px 20px; border: none; background: transparent; cursor: pointer;
          font-size: 13px; font-weight: 600; color: #64748b; border-bottom: 3px solid transparent;
          margin-bottom: -2px; text-decoration: none; }
.vi-tab.active { color: #0f172a; border-bottom-color: #36577d; }
.vi-tab:hover { color: #36577d; }

.vi-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px; margin-bottom: 20px; }
.vi-stat { background: #fff; border-radius: 10px; padding: 14px; text-align: center;
           box-shadow: 0 1px 3px rgba(0,0,0,.06); border-left: 4px solid #e5e7eb; }
.vi-stat .icon { font-size: 22px; line-height: 1; }
.vi-stat .nb { font-size: 22px; font-weight: 800; color: #0f172a; margin: 4px 0 2px; }
.vi-stat .lbl { font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: .04em; }
.vi-stat.is-loyer { border-left-color: #10b981; }
.vi-stat.is-crg { border-left-color: #3b82f6; }
.vi-stat.is-bail { border-left-color: #8b5cf6; }
.vi-stat.is-taxe_fonciere { border-left-color: #f59e0b; }
.vi-stat.is-diagnostic { border-left-color: #06b6d4; }
.vi-stat.is-valorisation { border-left-color: #ec4899; }

.vi-card { background: #fff; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px;
           box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.vi-card h3 { margin: 0 0 12px; font-size: 14px; color: #0f172a; }

.vi-table { width: 100%; font-size: 12px; border-collapse: collapse; }
.vi-table th { text-align: left; padding: 8px; background: #f8fafc; color: #475569;
               font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; }
.vi-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; }
.vi-table tr:hover td { background: #fafafa; }

.vi-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px;
            font-weight: 700; text-transform: uppercase; }
.vi-badge.ok { background: #dcfce7; color: #14532d; }
.vi-badge.warn { background: #fef3c7; color: #78350f; }
.vi-badge.err { background: #fee2e2; color: #991b1b; }

.vi-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.vi-chip { padding: 5px 12px; border-radius: 99px; background: #fff; border: 1px solid #cbd5e1;
           font-size: 11px; font-weight: 600; color: #475569; cursor: pointer; text-decoration: none; }
.vi-chip:hover { border-color: #36577d; color: #36577d; }
.vi-chip.active { background: #36577d; color: #fff; border-color: #36577d; }

.vi-actions { margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
.vi-btn { padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 700;
          cursor: pointer; border: 1px solid transparent; text-decoration: none;
          display: inline-block; }
.vi-btn-primary { background: #36577d; color: #fff; }
.vi-btn-primary:hover { background: #24324a; }
.vi-btn-secondary { background: #fff; color: #36577d; border-color: #36577d; }
.vi-btn-disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }

.vi-phase-nav { display: flex; gap: 4px; justify-content: center; margin-bottom: 24px; }
.vi-phase { flex: 0 0 200px; padding: 10px; border-radius: 10px; background: #f1f5f9; text-align: center;
            border: 2px solid #e5e7eb; font-size: 11px; color: #64748b; text-decoration: none; }
.vi-phase.done { background: #dcfce7; border-color: #86efac; color: #14532d; }
.vi-phase.active { background: #36577d; border-color: #36577d; color: #fff; }
.vi-phase.upcoming { opacity: .5; }
.vi-phase strong { display: block; font-size: 13px; margin-bottom: 2px; }
</style>

<main class="mbi-main">
  <div class="vi-wrap">
    <h1 style="margin:0 0 6px; font-size:22px; color:#0f172a;">📥 Imports GED — Validation</h1>
    <p style="margin:0 0 20px; font-size:12px; color:#64748b;">
      Batch courant : <strong><?= h($batch) ?></strong> ·
      Scope : <strong>REGIE EMERY LYON</strong> (société Régie EMERY)
    </p>

    <!-- Navigation 4 phases -->
    <div class="vi-phase-nav">
      <a class="vi-phase <?= $phase === 1 ? 'active' : ($totalFiles > 0 ? 'done' : '') ?>" href="?phase=1&batch=<?= h($batch) ?>">
        <strong>1 · Inventaire</strong><?= $totalFiles ?> fichiers
      </a>
      <a class="vi-phase <?= $phase === 2 ? 'active' : 'upcoming' ?>" href="?phase=2&batch=<?= h($batch) ?>">
        <strong>2 · Classification</strong>À valider
      </a>
      <a class="vi-phase <?= $phase === 3 ? 'active' : 'upcoming' ?>" href="?phase=3&batch=<?= h($batch) ?>">
        <strong>3 · Extraction IA</strong>À valider
      </a>
      <a class="vi-phase <?= $phase === 4 ? 'active' : 'upcoming' ?>" href="?phase=4&batch=<?= h($batch) ?>">
        <strong>4 · Upload prod</strong>À lancer
      </a>
    </div>

    <?php if ($phase === 1): ?>

      <!-- Stats globales -->
      <div class="vi-stats">
        <div class="vi-stat" style="border-left-color:#0f172a;">
          <div class="icon">📦</div>
          <div class="nb"><?= $totalFiles ?></div>
          <div class="lbl">Total fichiers</div>
        </div>
        <div class="vi-stat">
          <div class="icon">💾</div>
          <div class="nb"><?= number_format($totalSize / 1024 / 1024, 1, ',', ' ') ?></div>
          <div class="lbl">Mo cumulés</div>
        </div>
        <div class="vi-stat" style="border-left-color:<?= count($doublons) > 0 ? '#f59e0b' : '#10b981' ?>;">
          <div class="icon"><?= count($doublons) > 0 ? '⚠️' : '✓' ?></div>
          <div class="nb"><?= count($doublons) ?></div>
          <div class="lbl">Doublons MD5</div>
        </div>
        <?php foreach ($stats as $s): $fam = $s['famille_doc']; ?>
          <div class="vi-stat is-<?= h($fam) ?>">
            <div class="icon"><?= $famIcons[$fam] ?? '📄' ?></div>
            <div class="nb"><?= (int)$s['nb'] ?></div>
            <div class="lbl"><?= h(ucfirst(str_replace('_', ' ', $fam))) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Doublons -->
      <?php if (!empty($doublons)): ?>
        <div class="vi-card">
          <h3>⚠️ <?= count($doublons) ?> MD5 dupliqués détectés</h3>
          <p style="font-size:11px;color:#64748b;margin-bottom:10px;">
            Fichiers avec contenu identique (même MD5). Ce sont des copies dans plusieurs dossiers,
            l'import ne les comptera qu'une seule fois côté prod (INSERT IGNORE).
          </p>
          <table class="vi-table">
            <thead><tr><th>MD5</th><th>Nb copies</th><th>Fichiers</th></tr></thead>
            <tbody>
              <?php foreach ($doublons as $d): ?>
                <tr>
                  <td><code style="font-size:10px;"><?= h(substr($d['md5'], 0, 10)) ?>…</code></td>
                  <td><span class="vi-badge warn"><?= (int)$d['nb'] ?></span></td>
                  <td style="font-size:10px;"><?= h($d['fichiers']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- Filtres famille + liste -->
      <div class="vi-card">
        <h3>📄 Liste des fichiers (page <?= $page ?>)</h3>
        <div class="vi-chips">
          <a class="vi-chip <?= $fFam === '' ? 'active' : '' ?>" href="?phase=1&batch=<?= h($batch) ?>">Toutes (<?= $totalFiles ?>)</a>
          <?php foreach ($stats as $s): ?>
            <a class="vi-chip <?= $fFam === $s['famille_doc'] ? 'active' : '' ?>" href="?phase=1&batch=<?= h($batch) ?>&fam=<?= h($s['famille_doc']) ?>">
              <?= $famIcons[$s['famille_doc']] ?? '📄' ?> <?= h($s['famille_doc']) ?> (<?= (int)$s['nb'] ?>)
            </a>
          <?php endforeach; ?>
        </div>

        <table class="vi-table">
          <thead><tr><th>Fichier</th><th>Famille</th><th>Taille</th><th>MD5</th><th>Chemin</th><th>Statut</th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr>
                <td style="font-weight:600;"><?= h($f['filename']) ?></td>
                <td><?= $famIcons[$f['famille_doc']] ?? '📄' ?> <?= h($f['famille_doc']) ?></td>
                <td style="text-align:right;"><?= number_format($f['taille_octets'] / 1024, 0, ',', ' ') ?> Ko</td>
                <td><code style="font-size:10px;"><?= h(substr($f['md5'], 0, 8)) ?>…</code></td>
                <td style="font-size:10px;color:#94a3b8;"><?= h(str_replace('\\', '/', dirname($f['path_source']))) ?></td>
                <td><span class="vi-badge ok"><?= h($f['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php
          $totalPages = (int)ceil($totalFiles / $perPage);
          if ($totalPages > 1):
        ?>
          <div style="margin-top:14px;display:flex;gap:6px;justify-content:center;">
            <?php if ($page > 1): ?>
              <a class="vi-chip" href="?phase=1&batch=<?= h($batch) ?>&fam=<?= h($fFam) ?>&p=<?= $page - 1 ?>">← Précédent</a>
            <?php endif; ?>
            <span style="font-size:11px;color:#64748b;padding:5px 10px;">Page <?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
              <a class="vi-chip" href="?phase=1&batch=<?= h($batch) ?>&fam=<?= h($fFam) ?>&p=<?= $page + 1 ?>">Suivant →</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="vi-actions">
        <a class="vi-btn vi-btn-primary" href="?phase=2&batch=<?= h($batch) ?>">
          ✓ Passer à la Phase 2 — Classification
        </a>
      </div>

    <?php elseif ($phase === 2): ?>
      <div class="vi-card">
        <h3>Phase 2 — Classification propriétaire / immeuble / bien</h3>
        <p style="color:#64748b;font-size:12px;">
          Cette phase matchera chaque fichier au bon propriétaire + immeuble + bien,
          en détectant les doublons existants avant toute création.
        </p>
        <p><strong>📝 À venir — lancement du classifieur</strong></p>
      </div>

    <?php elseif ($phase === 3): ?>
      <div class="vi-card">
        <h3>Phase 3 — Extraction IA structurée</h3>
        <p style="color:#64748b;font-size:12px;">Extraction GPT-5 par famille de document avec validation humaine des cas suspects.</p>
        <p><strong>📝 À venir après Phase 2</strong></p>
      </div>

    <?php elseif ($phase === 4): ?>
      <div class="vi-card">
        <h3>Phase 4 — Upload Hostinger + bascule prod</h3>
        <p style="color:#64748b;font-size:12px;">Upload FTP par lots avec vérification MD5 + import SQL staging → prod idempotent.</p>
        <p><strong>📝 À venir après Phase 3</strong></p>
      </div>
    <?php endif; ?>

  </div>
</main>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
