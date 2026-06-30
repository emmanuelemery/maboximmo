<?php
/**
 * admin/admin_ged_seed_skeleton_rollback.php
 *
 * Sprint 4A — UI de rollback du seed squelette GED V1.
 *
 * Soft archive (is_archived = 1) — JAMAIS de DELETE physique.
 * Conforme [[feedback_suppression_bancaire_inviolable]].
 *
 * Mode DRY-RUN par défaut, archive uniquement après clic explicite + confirm().
 *
 * Cibles : rows portant `storage_path = 'seed:skeleton_v1'` (marqueur du seed).
 * Garde-fous : refuse l'archive si rows liés à des documents ou enfants user-créés actifs.
 *
 * Accès super admin uniquement (role=1).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = $GLOBALS['pdo'];

function sr_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Chargement migration ──
$migrationFile = dirname(__DIR__) . '/inc/migrations/20260524_ged_seed_skeleton_v1.php';
if (!is_file($migrationFile)) {
    exit('❌ Migration 20260524_ged_seed_skeleton_v1.php introuvable.');
}
$migration = require $migrationFile;

if (!is_array($migration) || !isset($migration['rollback']) || !is_callable($migration['rollback'])) {
    exit('❌ Migration invalide (clé `rollback` manquante ou non-callable).');
}

// ── Helpers stats (réutilisés du seed) ──
function sr_count_folders(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders")->fetchColumn();
}
function sr_count_active(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE is_archived = 0")->fetchColumn();
}
function sr_count_archived(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE is_archived = 1")->fetchColumn();
}
function sr_count_seeded(PDO $pdo): int {
    // Marqueur du seed (cf. INSERT migration) : storage_path = 'seed:skeleton_v1'
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE storage_path = 'seed:skeleton_v1'")->fetchColumn();
    } catch (Throwable) { return 0; }
}
function sr_count_seeded_active(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE storage_path = 'seed:skeleton_v1' AND is_archived = 0")->fetchColumn();
    } catch (Throwable) { return 0; }
}
function sr_max_depth(PDO $pdo): int {
    try {
        $sql = "WITH RECURSIVE ft AS (
                    SELECT id, parent_id, 0 AS d FROM ged_folders
                    WHERE (parent_id IS NULL OR parent_id = 0) AND is_archived = 0
                    UNION ALL
                    SELECT f.id, f.parent_id, ft.d + 1
                    FROM ged_folders f JOIN ft ON f.parent_id = ft.id
                    WHERE f.is_archived = 0
                )
                SELECT COALESCE(MAX(d), 0) FROM ft";
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable) { return 0; }
}

// Snapshot avant
$beforeTotal     = sr_count_folders($pdo);
$beforeActive    = sr_count_active($pdo);
$beforeArchived  = sr_count_archived($pdo);
$beforeSeeded    = sr_count_seeded($pdo);
$beforeSeededAct = sr_count_seeded_active($pdo);
$beforeDepth     = sr_max_depth($pdo);

// Mode
$mode = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') ? 'apply' : 'dry_run';

// Exécution
$t0 = microtime(true);
$report = ($migration['rollback'])($pdo, $mode === 'dry_run');
$elapsed = microtime(true) - $t0;

// Snapshot après
$afterTotal     = sr_count_folders($pdo);
$afterActive    = sr_count_active($pdo);
$afterArchived  = sr_count_archived($pdo);
$afterSeeded    = sr_count_seeded($pdo);
$afterSeededAct = sr_count_seeded_active($pdo);
$afterDepth     = sr_max_depth($pdo);

$totArchive = $report['totals']['archive'] ?? 0;
$totBlocked = $report['totals']['blocked'] ?? 0;
$totSkipped = $report['totals']['skipped'] ?? 0;
$totErrors  = $report['totals']['errors']  ?? 0;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rollback seed skeleton_v1 — Sprint 4A</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171; --info: #60a5fa;
            --archive: #fde68a; --skip: #94a3b8; --blocked: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DM Mono", "JetBrains Mono", monospace;
            background: var(--bg); color: var(--text); font-size: 12.5px;
        }
        header {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 18px; background: #11203b;
            border-bottom: 2px solid var(--mbi-or);
        }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .badge {
            background: #7c3aed; color: #fff; padding: 2px 8px;
            border-radius: 4px; font-size: 10px; font-weight: 700;
        }
        header .mode-badge {
            font-size: 11px; padding: 3px 10px; border-radius: 4px; font-weight: 700;
        }
        .mode-dry { background: var(--warn); color: #1a1a1a; }
        .mode-apply-ok { background: var(--ok); color: #1a1a1a; }
        .mode-apply-ko { background: var(--ko); color: #fff; }
        header nav { margin-left: auto; display: flex; gap: 10px; }
        header nav a {
            color: var(--muted); text-decoration: none; font-size: 11px;
            border: 1px solid var(--line); padding: 4px 10px; border-radius: 4px;
        }
        header nav a:hover { color: var(--text); border-color: var(--mbi-or); }

        .container { max-width: 1200px; margin: 0 auto; padding: 18px; }

        .panel {
            background: var(--panel); border-radius: 6px; padding: 16px 20px;
            margin-bottom: 14px;
        }
        .panel h2 {
            font-size: 13px; margin: 0 0 12px; color: var(--mbi-or);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--line); padding-bottom: 6px;
        }

        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
        }
        .stat {
            background: #0f172a; padding: 10px 14px; border-radius: 4px;
            border-left: 3px solid var(--line);
        }
        .stat.ok { border-left-color: var(--ok); }
        .stat.warn { border-left-color: var(--warn); }
        .stat.info { border-left-color: var(--info); }
        .stat .lbl { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat .val { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .stat .sub { font-size: 10px; color: var(--muted); margin-top: 2px; }
        .stat .delta { font-size: 11px; color: var(--ok); font-weight: 700; margin-left: 6px; }
        .stat .delta-neg { color: var(--ko); }
        .stat .delta-archive { color: var(--archive); }

        .ops-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
        .ops-summary > div {
            background: #0f172a; padding: 12px; border-radius: 4px;
            border-left: 4px solid var(--line);
        }
        .ops-summary .archive { border-left-color: var(--archive); }
        .ops-summary .blocked { border-left-color: var(--blocked); }
        .ops-summary .skipped { border-left-color: var(--skip); }
        .ops-summary .err     { border-left-color: var(--ko); }
        .ops-summary h3 { font-size: 10.5px; margin: 0 0 4px; color: var(--muted); text-transform: uppercase; }
        .ops-summary .big { font-size: 22px; font-weight: 700; }

        pre.exec-log {
            background: #0a1424; padding: 12px 14px; border-radius: 4px;
            border-left: 4px solid var(--mbi-or);
            font-size: 10.5px; line-height: 1.7; overflow-x: auto;
            max-height: 300px; overflow-y: auto;
        }

        .blocked-list { background: #0f172a; padding: 10px; border-radius: 4px; }
        .blocked-list .item {
            padding: 6px 10px; margin-bottom: 4px;
            background: #1e293b; border-left: 3px solid var(--ko); border-radius: 3px;
            font-size: 11px;
        }
        .blocked-list .item .id { color: var(--mbi-or); font-weight: 700; }
        .blocked-list .item .reason { color: var(--ko); font-style: italic; margin-left: 8px; }

        .actions {
            display: flex; gap: 10px; justify-content: space-between;
            margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line);
        }
        .btn {
            padding: 9px 16px; border: none; border-radius: 4px;
            font-family: inherit; font-size: 12px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            font-weight: 700;
        }
        .btn-danger { background: var(--ko); color: #fff; }
        .btn-danger:hover { background: #ef4444; }
        .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .btn-ghost:hover { color: var(--text); border-color: var(--mbi-or); }

        .v0-warn {
            background: #422006; color: #fde68a; padding: 8px 12px;
            border-radius: 3px; font-size: 10.5px; margin-top: 14px;
            border-left: 3px solid var(--warn);
        }
        .v0-info {
            background: #082f49; color: #bae6fd; padding: 8px 12px;
            border-radius: 3px; font-size: 10.5px; margin-top: 14px;
            border-left: 3px solid var(--info);
        }
        .v0-ok {
            background: #14532d; color: #d1fae5; padding: 10px 14px;
            border-radius: 4px; font-size: 11.5px; margin-bottom: 14px;
            border-left: 4px solid var(--ok);
        }
        .v0-ko {
            background: #7f1d1d; color: #fee2e2; padding: 10px 14px;
            border-radius: 4px; font-size: 11.5px; margin-bottom: 14px;
            border-left: 4px solid var(--ko);
        }
        details summary { cursor: pointer; color: var(--muted); font-size: 11px; margin: 8px 0; }
        details pre { font-size: 10px; max-height: 200px; overflow: auto; }

        .perf { font-family: monospace; color: var(--info); font-size: 11px; }
    </style>
</head>
<body>

<header>
    <h1>↶ Rollback seed skeleton_v1</h1>
    <span class="badge">SPRINT 4A</span>
    <?php if ($mode === 'apply' && $totErrors === 0): ?>
        <span class="mode-badge mode-apply-ok">✅ ARCHIVÉ</span>
    <?php elseif ($mode === 'apply' && $totErrors > 0): ?>
        <span class="mode-badge mode-apply-ko">🔴 ROLLBACK</span>
    <?php else: ?>
        <span class="mode-badge mode-dry">🟡 DRY-RUN</span>
    <?php endif; ?>
    <nav>
        <a href="admin_ged_seed_skeleton_v1.php">🌱 Seed</a>
        <a href="admin_audit_sprint3_tables.php">📊 Audit</a>
        <a href="admin_ged_arborescence.php">🌳 Arbo</a>
    </nav>
</header>

<div class="container">

    <?php if ($mode === 'apply' && $totErrors === 0): ?>
        <div class="v0-ok">
            ✅ <b>Rollback exécuté (soft archive).</b> <?= $totArchive ?> row(s) marquée(s) <code>is_archived=1</code>.
            <br>Les rows restent en BDD, intégrité préservée. Un nouveau seed les ressuscitera (UK respecté → SKIP, désarchivage manuel possible).
        </div>
    <?php elseif ($mode === 'apply' && $totErrors > 0): ?>
        <div class="v0-ko">
            🔴 <b>ROLLBACK :</b> exception levée. Aucune archive persistée.
            <br>Détails : <?= sr_html(implode(' / ', $report['errors'])) ?>
        </div>
    <?php endif; ?>

    <!-- Stats globales -->
    <div class="panel">
        <h2>📊 Statistiques ged_folders</h2>

        <div class="stats-grid">
            <div class="stat">
                <div class="lbl">Total rows</div>
                <div class="val">
                    <?= $afterTotal ?>
                    <?php if ($afterTotal !== $beforeTotal): ?>
                        <span class="delta <?= $afterTotal > $beforeTotal ? '' : 'delta-neg' ?>">
                            (avant: <?= $beforeTotal ?>)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="sub">DELETE jamais</div>
            </div>

            <div class="stat ok">
                <div class="lbl">Actifs</div>
                <div class="val">
                    <?= $afterActive ?>
                    <?php $da = $afterActive - $beforeActive; if ($da !== 0): ?>
                        <span class="delta <?= $da > 0 ? '' : 'delta-neg' ?>"><?= $da > 0 ? '+' : '' ?><?= $da ?></span>
                    <?php endif; ?>
                </div>
                <div class="sub">is_archived = 0</div>
            </div>

            <div class="stat warn">
                <div class="lbl">Archivés</div>
                <div class="val">
                    <?= $afterArchived ?>
                    <?php $dr = $afterArchived - $beforeArchived; if ($dr !== 0): ?>
                        <span class="delta-archive">+<?= $dr ?></span>
                    <?php endif; ?>
                </div>
                <div class="sub">is_archived = 1</div>
            </div>

            <div class="stat info">
                <div class="lbl">Tagués skeleton_v1</div>
                <div class="val"><?= $afterSeeded ?></div>
                <div class="sub">dont <?= $afterSeededAct ?> actifs</div>
            </div>

            <div class="stat info">
                <div class="lbl">Profondeur max (actifs)</div>
                <div class="val">N<?= $afterDepth + 1 ?></div>
                <div class="sub">depth = <?= $afterDepth ?></div>
            </div>
        </div>

        <div class="perf">
            ⏱️ Temps : <?= sprintf('%.3f s', $elapsed) ?>
            · Mode : <code><?= $mode ?></code>
            · <?= sr_html($report['started_at']) ?> → <?= sr_html($report['finished_at']) ?>
        </div>
    </div>

    <!-- Résumé opérations -->
    <div class="panel">
        <h2>🎯 Résumé des opérations (<?= $mode === 'dry_run' ? 'PRÉVUES' : 'EXÉCUTÉES' ?>)</h2>

        <div class="ops-summary">
            <div class="archive">
                <h3>📦 ARCHIVE (is_archived=1)</h3>
                <div class="big"><?= $totArchive ?></div>
            </div>
            <div class="blocked">
                <h3>🛑 BLOCKED</h3>
                <div class="big"><?= $totBlocked ?></div>
            </div>
            <div class="skipped">
                <h3>⏭️ DÉJÀ ARCHIVÉ</h3>
                <div class="big"><?= $totSkipped ?></div>
            </div>
            <div class="err">
                <h3>❌ ERREURS</h3>
                <div class="big"><?= $totErrors ?></div>
            </div>
        </div>

        <?php if (!empty($report['blocked'])): ?>
            <h3 style="font-size: 11.5px; color: var(--warn); margin: 14px 0 6px;">🛑 Rows BLOQUÉS (non archivables)</h3>
            <div class="blocked-list">
                <?php foreach ($report['blocked'] as $b): ?>
                    <div class="item">
                        <span class="id">#<?= (int)$b['id'] ?></span>
                        <code><?= sr_html($b['slug']) ?></code>
                        <span class="reason"><?= sr_html($b['reason']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Log exécution -->
    <?php if (!empty($report['log'])): ?>
    <div class="panel">
        <h2>📜 Log d'exécution (<?= count($report['log']) ?> lignes)</h2>
        <pre class="exec-log"><?= sr_html(implode("\n", $report['log'])) ?></pre>
    </div>
    <?php endif; ?>

    <!-- Action -->
    <form method="POST" action="">
        <div class="panel">
            <div class="actions">
                <a href="?" class="btn btn-ghost">🔄 Refresh dry-run</a>
                <button type="submit" name="action" value="apply" class="btn btn-danger"
                        <?= ($totArchive === 0 || ($mode === 'apply' && $totErrors === 0)) ? 'disabled style="opacity:0.4;cursor:not-allowed;"' : '' ?>
                        onclick="return confirm('⚠️ ROLLBACK SEED skeleton_v1 ?\n\n<?= $totArchive ?> row(s) seront marquée(s) is_archived=1.\n<?= $totBlocked ?> row(s) seront bloquée(s) (docs ou enfants liés).\n\nAucune suppression physique. Continuer ?');">
                    📦 Appliquer rollback (soft archive)
                </button>
            </div>

            <div class="v0-warn">
                <b>Sécurités actives :</b>
                Soft archive (jamais DELETE) · transaction PDO atomique ·
                refuse l'archive si docs liés actifs OU enfants user-créés actifs ·
                limité aux rows <code>storage_path='seed:skeleton_v1'</code> ·
                idempotent (déjà archivés = SKIP).
            </div>

            <div class="v0-info">
                <b>4 rows legacy intouchables</b> par ce rollback (jamais taguées skeleton_v1) :
                <code>98_referentiel_tech</code> (id=14) · <code>99_parametrage_ged</code> (id=15) ·
                <code>01_proprietaires</code> (id=24) · <code>99_a_classer_ia</code> (id=25)
                <br>→ géré par Sprint 4A.2 séparé.
            </div>
        </div>
    </form>

    <details>
        <summary>🔍 Debug : état brut</summary>
        <pre><?= sr_html(json_encode([
            'before' => ['total'=>$beforeTotal,'active'=>$beforeActive,'archived'=>$beforeArchived,'seeded'=>$beforeSeeded,'seeded_active'=>$beforeSeededAct,'depth_max'=>$beforeDepth],
            'after'  => ['total'=>$afterTotal, 'active'=>$afterActive, 'archived'=>$afterArchived, 'seeded'=>$afterSeeded, 'seeded_active'=>$afterSeededAct, 'depth_max'=>$afterDepth],
            'totals' => $report['totals'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>

</div>

</body>
</html>
