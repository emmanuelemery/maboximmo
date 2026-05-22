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

// ─── Phase 2 : classification stats + items à trier ───────
$classStats = [];
$classItems = [];
$fConf = (string)($_GET['conf'] ?? 'ambigu'); // par défaut on montre les ambigus à trier
try {
    $stCS = $pdo->prepare("
        SELECT c.confidence, SUM(IF(c.validated = 1, 1, 0)) AS val_ok,
               SUM(IF(c.validated = -1, 1, 0)) AS val_ko,
               SUM(IF(c.validated = 0, 1, 0)) AS val_wait,
               COUNT(*) AS total
        FROM ged_classification_staging c
        JOIN ged_manifest m ON m.id = c.id_manifest
        WHERE m.batch_id = :b
        GROUP BY c.confidence
    ");
    $stCS->execute([':b' => $batch]);
    $classStats = $stCS->fetchAll(PDO::FETCH_ASSOC);

    $stItems = $pdo->prepare("
        SELECT c.id, c.id_manifest, c.id_proprietaire, c.id_immeuble, c.id_bien,
               c.confidence, c.score, c.hint_filename, c.hint_proprio, c.hint_adresse,
               c.validated, c.comment, c.creation_needed_json,
               m.filename, m.famille_doc, m.taille_octets, m.path_source,
               p.societe AS prop_nom,
               i.adresse_1 AS imm_adresse, i.ville AS imm_ville
        FROM ged_classification_staging c
        JOIN ged_manifest m ON m.id = c.id_manifest
        LEFT JOIN proprietaires p ON p.id = c.id_proprietaire
        LEFT JOIN immeubles i ON i.id = c.id_immeuble
        WHERE m.batch_id = :b AND c.confidence = :conf AND c.validated = 0
        ORDER BY c.score DESC, m.filename
        LIMIT 80
    ");
    $stItems->execute([':b' => $batch, ':conf' => $fConf]);
    $classItems = $stItems->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Propriétaires et immeubles pour selects de réassignation
$allProprios = [];
$allImmeubles = [];
try {
    $allProprios = $pdo->query("
        SELECT id, COALESCE(societe, CONCAT(prenom, ' ', nom)) AS label
        FROM proprietaires WHERE actif = 1 ORDER BY label
    ")->fetchAll(PDO::FETCH_ASSOC);
    $allImmeubles = $pdo->query("
        SELECT id, CONCAT(COALESCE(adresse_1,''), ' · ', COALESCE(ville,''), ' ', COALESCE(code_postal,'')) AS label
        FROM immeubles WHERE id_societe IN (1,6) ORDER BY ville, adresse_1 LIMIT 1000
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$csrfToken = function_exists('csrf_token') ? csrf_token() : '';

// ─── Layout ────────────────────────────────────────────────
$layout_title   = 'Imports GED — Validation';
$layout_module  = 'Bailleur';
$layout_sidebar = 'sidebar_agency';

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
      <!-- Stats classification -->
      <div class="vi-stats">
        <?php
          $confColors = ['certain'=>'#10b981','probable'=>'#3b82f6','ambigu'=>'#f59e0b','non_classe'=>'#ef4444'];
          $confLabels = ['certain'=>'Certain','probable'=>'Probable','ambigu'=>'Ambigu','non_classe'=>'Non classé'];
          $totalCS = 0; $totalValOk = 0; $totalValWait = 0;
          foreach ($classStats as $cs) { $totalCS += (int)$cs['total']; $totalValOk += (int)$cs['val_ok']; $totalValWait += (int)$cs['val_wait']; }
        ?>
        <div class="vi-stat" style="border-left-color:#0f172a;">
          <div class="icon">📦</div><div class="nb"><?= $totalCS ?></div>
          <div class="lbl">Total classés</div>
        </div>
        <div class="vi-stat" style="border-left-color:#10b981;">
          <div class="icon">✅</div><div class="nb"><?= $totalValOk ?></div>
          <div class="lbl">Validés</div>
        </div>
        <div class="vi-stat" style="border-left-color:#f59e0b;">
          <div class="icon">⏳</div><div class="nb"><?= $totalValWait ?></div>
          <div class="lbl">En attente</div>
        </div>
        <?php foreach ($classStats as $cs): ?>
          <?php $c = $cs['confidence']; ?>
          <div class="vi-stat" style="border-left-color:<?= $confColors[$c] ?? '#94a3b8' ?>;">
            <div class="icon"><?= $c === 'certain' ? '🟢' : ($c === 'probable' ? '🔵' : ($c === 'ambigu' ? '🟠' : '🔴')) ?></div>
            <div class="nb"><?= (int)$cs['total'] ?></div>
            <div class="lbl"><?= h($confLabels[$c] ?? $c) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Actions globales -->
      <div class="vi-card">
        <h3>⚡ Actions rapides</h3>
        <div class="vi-chips" style="margin:0;">
          <button class="vi-chip <?= $fConf === 'ambigu' ? 'active' : '' ?>" onclick="location.href='?phase=2&batch=<?= h($batch) ?>&conf=ambigu'">🟠 Trier les ambigus</button>
          <button class="vi-chip <?= $fConf === 'non_classe' ? 'active' : '' ?>" onclick="location.href='?phase=2&batch=<?= h($batch) ?>&conf=non_classe'">🔴 Voir les non classés</button>
          <button class="vi-chip <?= $fConf === 'probable' ? 'active' : '' ?>" onclick="location.href='?phase=2&batch=<?= h($batch) ?>&conf=probable'">🔵 Voir les probables</button>
          <button class="vi-chip <?= $fConf === 'certain' ? 'active' : '' ?>" onclick="location.href='?phase=2&batch=<?= h($batch) ?>&conf=certain'">🟢 Voir les certains</button>
          <button class="vi-chip" style="background:#10b981;color:#fff;border-color:#10b981;" onclick="validateAllCertain()">✅ Valider tous les certains en masse</button>
        </div>
      </div>

      <!-- Écran de tri split : liste à gauche, preview à droite -->
      <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap: 14px; min-height: 70vh;">

        <!-- Liste scrollable -->
        <div class="vi-card" style="max-height: 80vh; overflow-y: auto; padding: 10px;">
          <h3 style="position:sticky;top:-10px;background:#fff;padding:8px 10px;margin:-10px -10px 10px;z-index:10;border-bottom:1px solid #eee;">
            <?= count($classItems) ?> <?= h($fConf) ?> à trier
          </h3>
          <?php if (empty($classItems)): ?>
            <p style="padding:30px;text-align:center;color:#94a3b8;">Rien à trier dans cette catégorie ✨</p>
          <?php else: ?>
            <?php foreach ($classItems as $it): ?>
              <div class="vi-trier-item" id="item-<?= (int)$it['id'] ?>" onclick="selectItem(<?= (int)$it['id'] ?>, <?= (int)$it['id_manifest'] ?>)">
                <div style="display:flex;justify-content:space-between;gap:8px;">
                  <strong style="font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($it['filename']) ?>">
                    <?= $famIcons[$it['famille_doc']] ?? '📄' ?> <?= h($it['filename']) ?>
                  </strong>
                  <span class="vi-badge" style="background:<?= $confColors[$it['confidence']] ?? '#94a3b8' ?>;color:#fff;">
                    <?= (int)$it['score'] ?>
                  </span>
                </div>
                <div style="font-size:10px;color:#64748b;margin-top:4px;">
                  <?php if ($it['prop_nom']): ?>
                    👤 <?= h($it['prop_nom']) ?>
                  <?php elseif ($it['hint_proprio']): ?>
                    👤 <em><?= h($it['hint_proprio']) ?> (suggéré)</em>
                  <?php endif; ?>
                  <?php if ($it['imm_adresse']): ?>
                    · 🏢 <?= h($it['imm_adresse']) ?> <?= h($it['imm_ville']) ?>
                  <?php elseif ($it['hint_adresse']): ?>
                    · 🏢 <em><?= h($it['hint_adresse']) ?></em>
                  <?php endif; ?>
                  <?php if (!empty($it['creation_needed_json'])): ?>
                    <span class="vi-badge" style="background:#fef3c7;color:#78350f;margin-left:6px;">🏢 À créer</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Panneau détail + preview -->
        <div>
          <div class="vi-card" id="vi-detail" style="display:none;">
            <h3>📄 Détail</h3>
            <div id="vi-detail-meta" style="font-size:12px;color:#475569;line-height:1.5;margin-bottom:12px;"></div>

            <div style="background:#fafafa;padding:10px;border-radius:8px;margin-bottom:12px;">
              <label style="font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;">Propriétaire</label>
              <select id="vi-prop-select" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;" onchange="reassignProp()">
                <option value="">— Non assigné —</option>
                <?php foreach ($allProprios as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"><?= h($p['label']) ?></option>
                <?php endforeach; ?>
              </select>

              <label style="font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;margin-top:10px;display:block;">Immeuble</label>
              <select id="vi-imm-select" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;" onchange="reassignImm()">
                <option value="">— Non assigné —</option>
                <?php foreach ($allImmeubles as $i): ?>
                  <option value="<?= (int)$i['id'] ?>"><?= h($i['label']) ?></option>
                <?php endforeach; ?>
              </select>

              <label style="font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;margin-top:10px;display:block;">Commentaire</label>
              <input id="vi-comment" type="text" placeholder="Note optionnelle" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;" onblur="saveComment()">
            </div>

            <div id="vi-create-imm" style="display:none;background:#fef3c7;border:1px solid #fcd34d;padding:10px;border-radius:8px;margin-bottom:12px;">
              <div style="font-size:11px;font-weight:700;color:#78350f;margin-bottom:8px;">🏢 Aucun immeuble existant — proposition de création</div>
              <label style="font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;">Adresse *</label>
              <input id="vi-ci-adresse" type="text" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;margin:4px 0 8px;">
              <div style="display:grid;grid-template-columns:1fr 2fr;gap:6px;">
                <div>
                  <label style="font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;">CP</label>
                  <input id="vi-ci-cp" type="text" maxlength="5" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;">
                </div>
                <div>
                  <label style="font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;">Ville</label>
                  <input id="vi-ci-ville" type="text" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;margin-top:4px;">
                </div>
              </div>
              <button class="vi-btn vi-btn-primary" style="width:100%;margin-top:8px;" onclick="createImmeuble()">🏢 Créer l'immeuble et rattacher</button>
            </div>

            <div style="display:flex;gap:8px;margin-bottom:12px;">
              <button class="vi-btn vi-btn-primary" style="flex:1;" onclick="actionItem('validate')">✅ Valider</button>
              <button class="vi-btn" style="flex:1;background:#ef4444;color:#fff;" onclick="actionItem('reject')">❌ Rejeter</button>
            </div>

            <iframe id="vi-preview" style="width:100%;height:500px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;"></iframe>
          </div>
          <div class="vi-card" id="vi-detail-placeholder" style="text-align:center;padding:40px;color:#94a3b8;">
            ← Clique sur un item à gauche pour afficher le détail
          </div>
        </div>
      </div>

      <script>
      const CSRF = <?= json_encode($csrfToken) ?>;
      const BATCH = <?= json_encode($batch) ?>;
      let currentId = 0;

      function selectItem(classId, manifestId) {
        currentId = classId;
        document.querySelectorAll('.vi-trier-item').forEach(el => el.classList.remove('is-active'));
        const el = document.getElementById('item-' + classId);
        if (el) el.classList.add('is-active');

        // Remplit les selects avec les valeurs actuelles
        const data = el ? el.dataset : null;
        document.getElementById('vi-detail').style.display = 'block';
        document.getElementById('vi-detail-placeholder').style.display = 'none';
        document.getElementById('vi-preview').src = './api/ged_preview.php?id=' + manifestId;

        // Meta (filename + path)
        const filename = el.querySelector('strong').textContent.trim();
        const sub = el.querySelectorAll('div')[1].textContent.trim();
        document.getElementById('vi-detail-meta').innerHTML =
          '<strong>' + filename + '</strong><br>' + sub;

        // Pré-remplit les selects
        fetch('./api/ged_classification_get.php?id=' + classId, {credentials:'same-origin'})
          .then(r => r.json())
          .then(j => {
            if (j.ok) {
              document.getElementById('vi-prop-select').value = j.item.id_proprietaire || '';
              document.getElementById('vi-imm-select').value = j.item.id_immeuble || '';
              document.getElementById('vi-comment').value = j.item.comment || '';

              // Bloc création immeuble si creation_needed présent ET pas d'immeuble rattaché
              const ciBlock = document.getElementById('vi-create-imm');
              const cn = j.item.creation_needed;
              if (cn && cn.immeuble && !j.item.id_immeuble) {
                ciBlock.style.display = 'block';
                document.getElementById('vi-ci-adresse').value = cn.immeuble.adresse_1 || '';
                document.getElementById('vi-ci-cp').value = cn.immeuble.code_postal || '';
                document.getElementById('vi-ci-ville').value = cn.immeuble.ville || '';
              } else {
                ciBlock.style.display = 'none';
              }
            }
          }).catch(()=>{});
      }

      async function createImmeuble() {
        if (!currentId) return;
        const adr = document.getElementById('vi-ci-adresse').value.trim();
        if (!adr) { alert('Adresse requise'); return; }
        const fd = new FormData();
        fd.append('id', currentId); fd.append('action', 'create_immeuble_from_ged');
        fd.append('adresse_1', adr);
        fd.append('code_postal', document.getElementById('vi-ci-cp').value.trim());
        fd.append('ville', document.getElementById('vi-ci-ville').value.trim());
        fd.append('csrf_token', CSRF);
        const r = await fetch('./api/ged_action.php', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json();
        if (j.ok) {
          alert('🏢 Immeuble créé #' + j.id_immeuble + ' — rattaché à la ligne.');
          location.reload();
        } else {
          alert('Erreur : ' + (j.error || 'inconnue'));
        }
      }

      async function actionItem(action) {
        if (!currentId) return;
        const fd = new FormData();
        fd.append('id', currentId);
        fd.append('action', action);
        fd.append('csrf_token', CSRF);
        const r = await fetch('./api/ged_action.php', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json();
        if (j.ok) {
          document.getElementById('item-' + currentId).style.opacity = '0.3';
          document.getElementById('item-' + currentId).style.pointerEvents = 'none';
          setTimeout(() => location.reload(), 600);
        } else {
          alert('Erreur : ' + (j.error || j.message || 'inconnue'));
        }
      }

      async function reassignProp() {
        if (!currentId) return;
        const v = document.getElementById('vi-prop-select').value;
        const fd = new FormData();
        fd.append('id', currentId); fd.append('action', 'reassign_prop');
        fd.append('new_id', v); fd.append('csrf_token', CSRF);
        await fetch('./api/ged_action.php', {method:'POST', body:fd, credentials:'same-origin'});
      }
      async function reassignImm() {
        if (!currentId) return;
        const v = document.getElementById('vi-imm-select').value;
        const fd = new FormData();
        fd.append('id', currentId); fd.append('action', 'reassign_imm');
        fd.append('new_id', v); fd.append('csrf_token', CSRF);
        await fetch('./api/ged_action.php', {method:'POST', body:fd, credentials:'same-origin'});
      }
      async function saveComment() {
        if (!currentId) return;
        const c = document.getElementById('vi-comment').value;
        const fd = new FormData();
        fd.append('id', currentId); fd.append('action', 'comment');
        fd.append('comment', c); fd.append('csrf_token', CSRF);
        await fetch('./api/ged_action.php', {method:'POST', body:fd, credentials:'same-origin'});
      }
      async function validateAllCertain() {
        if (!confirm('Valider automatiquement TOUS les items en statut "certain" non encore validés ?')) return;
        const fd = new FormData();
        fd.append('id', '0'); fd.append('action', 'validate_all_certain');
        fd.append('batch', BATCH); fd.append('csrf_token', CSRF);
        const r = await fetch('./api/ged_action.php', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json();
        if (j.ok) {
          alert('✅ ' + (j.affected || 0) + ' items validés');
          location.reload();
        } else {
          alert('Erreur : ' + (j.error || 'inconnue'));
        }
      }
      </script>

      <style>
      .vi-trier-item {
        padding: 8px 10px; margin-bottom: 6px; border-radius: 6px;
        background: #fff; border: 1px solid #e5e7eb; cursor: pointer; transition: all .15s;
      }
      .vi-trier-item:hover { border-color: #36577d; background: #f8fafc; }
      .vi-trier-item.is-active { border-color: #36577d; background: #eff6ff; box-shadow: 0 0 0 2px rgba(54,87,125,.15); }
      </style>

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
