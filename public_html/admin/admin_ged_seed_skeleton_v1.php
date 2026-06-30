<?php
/**
 * admin/admin_ged_seed_skeleton_v1.php
 *
 * Sprint 4A — UI d'apply du seed squelette GED V1 (hybride C).
 *
 * Mode DRY-RUN par défaut, COMMIT uniquement après clic explicite + confirm().
 *
 * Charge la migration 20260524_ged_seed_skeleton_v1.php et invoque sa closure
 * `callable($pdo, $dryRun)`. Affiche :
 *   - compteur ged_folders avant/après
 *   - profondeur max actuelle (CTE récursive)
 *   - répartition N1/N2/N3 (avant/après)
 *   - temps d'exécution
 *   - badge vert si 202 rows canon présents en BDD
 *
 * Accès super admin uniquement (role=1).
 *
 * Réf : docs/ged_skeleton_v1.md
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

function ss_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Chargement de la migration ──────────────────────────────────────
$migrationFile = dirname(__DIR__) . '/inc/migrations/20260524_ged_seed_skeleton_v1.php';
if (!is_file($migrationFile)) {
    exit('❌ Migration 20260524_ged_seed_skeleton_v1.php introuvable.');
}
$migration = require $migrationFile;

if (!is_array($migration) || !isset($migration['callable']) || !is_callable($migration['callable'])) {
    exit('❌ Migration invalide (clé `callable` manquante ou non-callable).');
}

// ── Helpers stats ───────────────────────────────────────────────────
function ss_count_folders(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders")->fetchColumn();
}

function ss_max_depth(PDO $pdo): int {
    try {
        $sql = "WITH RECURSIVE ft AS (
                    SELECT id, parent_id, 0 AS d FROM ged_folders
                    WHERE parent_id IS NULL OR parent_id = 0
                    UNION ALL
                    SELECT f.id, f.parent_id, ft.d + 1
                    FROM ged_folders f JOIN ft ON f.parent_id = ft.id
                )
                SELECT COALESCE(MAX(d), 0) FROM ft";
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable) { return 0; }
}

function ss_repartition(PDO $pdo): array {
    try {
        $sql = "WITH RECURSIVE ft AS (
                    SELECT id, parent_id, 0 AS d FROM ged_folders
                    WHERE parent_id IS NULL OR parent_id = 0
                    UNION ALL
                    SELECT f.id, f.parent_id, ft.d + 1
                    FROM ged_folders f JOIN ft ON f.parent_id = ft.id
                )
                SELECT d AS depth, COUNT(*) AS n FROM ft GROUP BY d ORDER BY d";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out = ['N1' => 0, 'N2' => 0, 'N3' => 0, 'N4+' => 0];
        foreach ($rows as $r) {
            $d = (int)$r['depth'];
            if ($d === 0) $out['N1'] = (int)$r['n'];
            elseif ($d === 1) $out['N2'] = (int)$r['n'];
            elseif ($d === 2) $out['N3'] = (int)$r['n'];
            else $out['N4+'] += (int)$r['n'];
        }
        return $out;
    } catch (Throwable) { return ['N1'=>0,'N2'=>0,'N3'=>0,'N4+'=>0]; }
}

function ss_count_canon_slugs(PDO $pdo, array $canon): int {
    // Compte les rows BDD dont le slug appartient au canon V1
    $slugs = [];
    foreach ($canon as $n1) {
        $slugs[] = $n1['slug'];
        foreach ($n1['children_n2'] as $n2) {
            $slugs[] = $n2['slug'];
            foreach (($n2['children_n3'] ?? []) as $n3) $slugs[] = $n3['slug'];
        }
    }
    $slugs = array_unique($slugs);
    if (empty($slugs)) return 0;
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM ged_folders WHERE slug IN ($placeholders)");
    $st->execute($slugs);
    return (int)$st->fetchColumn();
}

function ss_count_seeded(PDO $pdo): int {
    // Marqueur du seed : storage_path = 'seed:skeleton_v1' (champ libre, jamais utilisé en local)
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE storage_path = 'seed:skeleton_v1'")->fetchColumn();
    } catch (Throwable) { return 0; }
}

// ── Snapshot initial (avant toute action) ──────────────────────────
$beforeTotal      = ss_count_folders($pdo);
$beforeDepth      = ss_max_depth($pdo);
$beforeRepart     = ss_repartition($pdo);
$beforeCanonSlugs = ss_count_canon_slugs($pdo, $migration['canon']);
$beforeSeeded     = ss_count_seeded($pdo);

// ── Détection mode (GET = dry-run, POST = apply) ────────────────────
$mode    = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') ? 'apply' : 'dry_run';
$report  = null;
$elapsed = 0.0;

// Lancement
$t0 = microtime(true);
$report = ($migration['callable'])($pdo, $mode === 'dry_run');
$elapsed = microtime(true) - $t0;

// ── Snapshot final (après) ─────────────────────────────────────────
$afterTotal      = ss_count_folders($pdo);
$afterDepth      = ss_max_depth($pdo);
$afterRepart     = ss_repartition($pdo);
$afterCanonSlugs = ss_count_canon_slugs($pdo, $migration['canon']);
$afterSeeded     = ss_count_seeded($pdo);

$totalInsert = ($report['totals']['n1_insert'] ?? 0)
             + ($report['totals']['n2_insert'] ?? 0)
             + ($report['totals']['n3_insert'] ?? 0);
$totalSkip   = ($report['totals']['n1_skip']   ?? 0)
             + ($report['totals']['n2_skip']   ?? 0)
             + ($report['totals']['n3_skip']   ?? 0);

// Badge : 202 rows canon présents = squelette complet
$canonComplete = ($afterCanonSlugs === 202);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Seed GED skeleton_v1 — Sprint 4A</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171; --info: #60a5fa;
            --insert: #84a98c; --skip: #94a3b8; --error: #f87171;
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
        }
        .stat {
            background: #0f172a; padding: 10px 14px; border-radius: 4px;
            border-left: 3px solid var(--line);
        }
        .stat.ok    { border-left-color: var(--ok); }
        .stat.warn  { border-left-color: var(--warn); }
        .stat.info  { border-left-color: var(--info); }
        .stat .lbl  { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat .val  { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .stat .sub  { font-size: 10px; color: var(--muted); margin-top: 2px; }
        .stat .delta { font-size: 11px; color: var(--ok); font-weight: 700; margin-left: 6px; }
        .stat .delta-neg { color: var(--ko); }

        .badge-complete {
            display: inline-block;
            background: var(--ok); color: #1a1a1a;
            padding: 4px 12px; border-radius: 4px; font-weight: 700;
            font-size: 12px;
        }
        .badge-partial {
            display: inline-block;
            background: var(--warn); color: #1a1a1a;
            padding: 4px 12px; border-radius: 4px; font-weight: 700;
            font-size: 12px;
        }

        /* Ops summary */
        .ops-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
        .ops-summary > div {
            background: #0f172a; padding: 12px; border-radius: 4px;
            border-left: 4px solid var(--line);
        }
        .ops-summary .insert { border-left-color: var(--insert); }
        .ops-summary .skip   { border-left-color: var(--skip); }
        .ops-summary .err    { border-left-color: var(--error); }
        .ops-summary h3 { font-size: 10.5px; margin: 0 0 4px; color: var(--muted); text-transform: uppercase; }
        .ops-summary .big { font-size: 22px; font-weight: 700; }

        /* Détail par niveau */
        .level-table {
            width: 100%; border-collapse: collapse; font-size: 11.5px;
            background: #0f172a; border-radius: 4px; overflow: hidden;
        }
        .level-table th, .level-table td {
            padding: 6px 12px; text-align: left;
            border-bottom: 1px solid var(--line);
        }
        .level-table th { background: var(--panel); color: var(--mbi-or); font-weight: 700; }
        .level-table td.num { text-align: right; font-variant-numeric: tabular-nums; }

        /* Log */
        pre.exec-log {
            background: #0a1424; padding: 12px 14px; border-radius: 4px;
            border-left: 4px solid var(--mbi-or);
            font-size: 10.5px; line-height: 1.7; overflow-x: auto;
            max-height: 300px; overflow-y: auto;
        }

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
        .btn-primary { background: var(--mbi-or); color: #1a1a1a; }
        .btn-primary:hover { background: #b8862f; }
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
    <h1>🌱 Seed GED skeleton_v1</h1>
    <span class="badge">SPRINT 4A</span>
    <?php if ($mode === 'apply' && empty($report['errors'])): ?>
        <span class="mode-badge mode-apply-ok">✅ APPLIQUÉ</span>
    <?php elseif ($mode === 'apply' && !empty($report['errors'])): ?>
        <span class="mode-badge mode-apply-ko">🔴 ROLLBACK</span>
    <?php else: ?>
        <span class="mode-badge mode-dry">🟡 DRY-RUN</span>
    <?php endif; ?>
    <nav>
        <a href="admin_ged_seed_skeleton_rollback.php">↶ Rollback</a>
        <a href="admin_audit_sprint3_tables.php">📊 Audit</a>
        <a href="admin_ged_arborescence.php">🌳 Arbo</a>
    </nav>
</header>

<div class="container">

    <?php if ($mode === 'apply' && empty($report['errors'])): ?>
        <div class="v0-ok">
            ✅ <b>Apply réussi.</b> Transaction commit OK. Le squelette V1 est désormais matérialisé en BDD.
        </div>
    <?php elseif ($mode === 'apply' && !empty($report['errors'])): ?>
        <div class="v0-ko">
            🔴 <b>ROLLBACK :</b> exception levée pendant l'apply. Aucune écriture persistée.
            <br>Détails : <?= ss_html(implode(' / ', $report['errors'])) ?>
        </div>
    <?php endif; ?>

    <!-- Stats globales -->
    <div class="panel">
        <h2>📊 Statistiques ged_folders</h2>

        <div class="stats-grid">
            <div class="stat ok">
                <div class="lbl">Total rows</div>
                <div class="val">
                    <?= $afterTotal ?>
                    <?php $delta = $afterTotal - $beforeTotal; if ($delta !== 0): ?>
                        <span class="delta <?= $delta > 0 ? '' : 'delta-neg' ?>">
                            <?= $delta > 0 ? '+' : '' ?><?= $delta ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="sub">avant: <?= $beforeTotal ?></div>
            </div>

            <div class="stat info">
                <div class="lbl">Profondeur max</div>
                <div class="val">N<?= $afterDepth + 1 ?></div>
                <div class="sub">depth = <?= $afterDepth ?></div>
            </div>

            <div class="stat">
                <div class="lbl">N1</div>
                <div class="val"><?= $afterRepart['N1'] ?></div>
                <div class="sub">canon: 15</div>
            </div>
            <div class="stat">
                <div class="lbl">N2</div>
                <div class="val"><?= $afterRepart['N2'] ?></div>
                <div class="sub">canon: 78</div>
            </div>
            <div class="stat">
                <div class="lbl">N3</div>
                <div class="val"><?= $afterRepart['N3'] ?></div>
                <div class="sub">canon: 109</div>
            </div>

            <div class="stat warn">
                <div class="lbl">Canon V1 présent</div>
                <div class="val"><?= $afterCanonSlugs ?>/202</div>
                <div class="sub">
                    <?php if ($canonComplete): ?>
                        <span class="badge-complete">✅ COMPLET</span>
                    <?php else: ?>
                        <span class="badge-partial">⏳ <?= 202 - $afterCanonSlugs ?> à créer</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat info">
                <div class="lbl">Tagués skeleton_v1</div>
                <div class="val"><?= $afterSeeded ?></div>
                <div class="sub">via storage_path</div>
            </div>
        </div>

        <div class="perf">
            ⏱️ Temps d'exécution : <?= sprintf('%.3f s', $elapsed) ?>
            · Mode : <code><?= $mode ?></code>
            · <?= ss_html($report['started_at']) ?> → <?= ss_html($report['finished_at']) ?>
        </div>
    </div>

    <!-- Résumé opérations -->
    <div class="panel">
        <h2>🎯 Résumé des opérations (<?= $mode === 'dry_run' ? 'PRÉVUES' : 'EXÉCUTÉES' ?>)</h2>

        <div class="ops-summary">
            <div class="insert">
                <h3>✨ INSERT</h3>
                <div class="big"><?= $totalInsert ?></div>
            </div>
            <div class="skip">
                <h3>⏭️  SKIP</h3>
                <div class="big"><?= $totalSkip ?></div>
            </div>
            <div class="err">
                <h3>❌ ERREURS</h3>
                <div class="big"><?= count($report['errors'] ?? []) ?></div>
            </div>
        </div>

        <table class="level-table">
            <thead>
                <tr>
                    <th>Niveau</th>
                    <th class="num">INSERT</th>
                    <th class="num">SKIP</th>
                    <th class="num">Total canon</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><b>N1</b> Métier</td>
                    <td class="num"><?= $report['totals']['n1_insert'] ?? 0 ?></td>
                    <td class="num"><?= $report['totals']['n1_skip'] ?? 0 ?></td>
                    <td class="num">15</td>
                </tr>
                <tr>
                    <td><b>N2</b> Domaine</td>
                    <td class="num"><?= $report['totals']['n2_insert'] ?? 0 ?></td>
                    <td class="num"><?= $report['totals']['n2_skip'] ?? 0 ?></td>
                    <td class="num">78</td>
                </tr>
                <tr>
                    <td><b>N3</b> Type doc</td>
                    <td class="num"><?= $report['totals']['n3_insert'] ?? 0 ?></td>
                    <td class="num"><?= $report['totals']['n3_skip'] ?? 0 ?></td>
                    <td class="num">109</td>
                </tr>
                <tr style="background: #0f172a; font-weight: 700;">
                    <td>TOTAL</td>
                    <td class="num"><?= $totalInsert ?></td>
                    <td class="num"><?= $totalSkip ?></td>
                    <td class="num">202</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Log exécution -->
    <?php if (!empty($report['log'])): ?>
    <div class="panel">
        <h2>📜 Log d'exécution (<?= count($report['log']) ?> lignes)</h2>
        <pre class="exec-log"><?= ss_html(implode("\n", $report['log'])) ?></pre>
    </div>
    <?php endif; ?>

    <!-- Action -->
    <form method="POST" action="">
        <div class="panel">
            <div class="actions">
                <a href="?" class="btn btn-ghost">🔄 Refresh dry-run</a>
                <button type="submit" name="action" value="apply" class="btn btn-danger"
                        <?= $mode === 'apply' && empty($report['errors']) ? 'disabled style="opacity:0.4;cursor:not-allowed;"' : '' ?>
                        onclick="return confirm('⚠️ COMMIT EN BDD : insérer <?= $totalInsert ?> nouveau(x) row(s) dans ged_folders ?\n\nDelta attendu après apply : +<?= $totalInsert ?> rows.\nNouveau total : <?= $beforeTotal + $totalInsert ?>.\n\nContinuer ?');">
                    💾 Appliquer (COMMIT)
                </button>
            </div>

            <div class="v0-warn">
                <b>Sécurités actives :</b>
                transaction PDO atomique · rollback auto sur exception ·
                INSERT IGNORE équivalent (SKIP si UK déjà respecté) ·
                re-exécution = idempotente · seed marqué <code>storage_path='seed:skeleton_v1'</code> ·
                <code>is_system=1</code> sur tous les nouveaux nodes.
            </div>

            <div class="v0-info">
                <b>4 rows legacy intouchées</b> (à traiter en Sprint 4A.2 séparé) :
                <code>98_referentiel_tech</code> (id=14) · <code>99_parametrage_ged</code> (id=15) ·
                <code>01_proprietaires</code> (id=24) · <code>99_a_classer_ia</code> (id=25)
            </div>
        </div>
    </form>

    <details>
        <summary>🔍 Debug : 10 premiers inserts prévus / exécutés</summary>
        <pre><?= ss_html(json_encode(array_slice($report['inserts'] ?? [], 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>

    <details>
        <summary>🔍 Debug : tous les SKIPs (<?= count($report['skips'] ?? []) ?>)</summary>
        <pre><?= ss_html(json_encode($report['skips'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>

    <details>
        <summary>🔍 Debug : état complet (snapshot avant/après)</summary>
        <pre><?= ss_html(json_encode([
            'before' => ['total'=>$beforeTotal,'depth_max'=>$beforeDepth,'repartition'=>$beforeRepart,'canon_slugs'=>$beforeCanonSlugs,'tagged_seeded'=>$beforeSeeded],
            'after'  => ['total'=>$afterTotal, 'depth_max'=>$afterDepth, 'repartition'=>$afterRepart, 'canon_slugs'=>$afterCanonSlugs, 'tagged_seeded'=>$afterSeeded],
            'totals' => $report['totals'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>

</div>

</body>
</html>
