<?php
/**
 * admin/admin_migration_loca_to_regie.php
 *
 * Migration unique : tous les biens/immeubles/tiers/mandats/ged_documents
 * sous société #3 LOCA IMMO Holding (+ agence #7 ST MARTIN LA PLAINE)
 * → société #1 Régie EMERY (+ agence #3 REGIE EMERY LYON).
 *
 * EXCLUSIONS (préservés, NE PAS toucher) :
 *   - users           (3 rows sous soc #3 = RH)
 *   - tables rh_*     (entretiens, salaires, etc.)
 *   - tables conges*  (RH)
 *   - tables ik_*     (indemnités kilométriques RH)
 *
 * Sécurités :
 *   - Mode DRY-RUN par défaut (count + diff sans UPDATE)
 *   - Backup SQL auto avant exec dans c:\tmp\
 *   - Transaction PDO atomique, rollback total sur erreur
 *   - Confirmation explicite (clic + confirm() JS)
 *   - Super admin uniquement
 *
 * Réf : décision user 2026-05-24, GO explicite après diagnostic.
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

// ═══ Paramètres de migration ═══
const SRC_SOCIETE_ID = 3;   // LOCA IMMO Holding
const SRC_AGENCE_ID  = 7;   // ST MARTIN LA PLAINE
const DST_SOCIETE_ID = 1;   // Régie EMERY
const DST_AGENCE_ID  = 3;   // REGIE EMERY LYON

function aml_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ═══ Helpers stats (counts avant/après) ═══
function aml_stats(PDO $pdo, int $srcSoc, int $srcAge, int $dstSoc, int $dstAge): array {
    return [
        'biens_src'         => (int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_societe = $srcSoc")->fetchColumn(),
        'biens_dst'         => (int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_societe = $dstSoc")->fetchColumn(),
        'immeubles_src'     => (int)$pdo->query("SELECT COUNT(*) FROM immeubles WHERE id_societe = $srcSoc")->fetchColumn(),
        'immeubles_dst'     => (int)$pdo->query("SELECT COUNT(*) FROM immeubles WHERE id_societe = $dstSoc")->fetchColumn(),
        'tiers_src'         => (int)$pdo->query("SELECT COUNT(*) FROM tiers WHERE id_societe = $srcSoc")->fetchColumn(),
        'tiers_dst'         => (int)$pdo->query("SELECT COUNT(*) FROM tiers WHERE id_societe = $dstSoc")->fetchColumn(),
        'mandats_src'       => (int)$pdo->query("SELECT COUNT(*) FROM mandats WHERE id_agence = $srcAge")->fetchColumn(),
        'mandats_dst'       => (int)$pdo->query("SELECT COUNT(*) FROM mandats WHERE id_agence = $dstAge")->fetchColumn(),
        'ged_documents_src' => (int)$pdo->query("SELECT COUNT(*) FROM ged_documents WHERE societe_id = $srcSoc")->fetchColumn(),
        'ged_documents_dst' => (int)$pdo->query("SELECT COUNT(*) FROM ged_documents WHERE societe_id = $dstSoc")->fetchColumn(),
        'fluxbox_docs_src'  => (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_documents WHERE tenant_id = $srcSoc")->fetchColumn(),
        'fluxbox_docs_dst'  => (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_documents WHERE tenant_id = $dstSoc")->fetchColumn(),
        'fluxbox_cartes_src'=> (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_cartes WHERE tenant_id = $srcSoc")->fetchColumn(),
        'fluxbox_cartes_dst'=> (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_cartes WHERE tenant_id = $dstSoc")->fetchColumn(),
        'fluxbox_actions_src'=>(int)$pdo->query("SELECT COUNT(*) FROM fluxbox_actions_ia WHERE tenant_id = $srcSoc")->fetchColumn(),
        'fluxbox_actions_dst'=>(int)$pdo->query("SELECT COUNT(*) FROM fluxbox_actions_ia WHERE tenant_id = $dstSoc")->fetchColumn(),
        // EXCLUSIONS RH (doivent rester inchangés)
        'users_src_RH'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id_societe = $srcSoc")->fetchColumn(),
    ];
}

// ═══ Backup SQL avant exec ═══
function aml_backup(PDO $pdo): array {
    $ts = date('Ymd_His');
    $backupFile = 'c:/tmp/backup_migration_loca_to_regie_' . $ts . '.sql';
    $lines = ["-- Backup migration LOCA → REGIE généré le " . date('Y-m-d H:i:s')];

    $tables = ['biens', 'immeubles', 'tiers', 'mandats', 'ged_documents',
               'fluxbox_documents', 'fluxbox_cartes', 'fluxbox_actions_ia'];

    foreach ($tables as $t) {
        // Selectionner uniquement les rows concernés
        $where = match ($t) {
            'biens', 'immeubles', 'tiers', 'ged_documents'   => "id_societe = " . SRC_SOCIETE_ID
                                                              . (in_array($t, ['ged_documents'], true) ? '' : ' OR id_agence = ' . SRC_AGENCE_ID),
            'mandats'                                         => "id_agence = " . SRC_AGENCE_ID,
            'fluxbox_documents', 'fluxbox_cartes', 'fluxbox_actions_ia' => "tenant_id = " . SRC_SOCIETE_ID,
        };
        // Ajuste WHERE pour ged_documents
        if ($t === 'ged_documents') $where = "societe_id = " . SRC_SOCIETE_ID . " OR agence_id = " . SRC_AGENCE_ID;
        if ($t === 'biens' || $t === 'immeubles' || $t === 'tiers') {
            $where = "id_societe = " . SRC_SOCIETE_ID;
        }

        $lines[] = "\n-- Backup $t (WHERE $where)";
        try {
            $rows = $pdo->query("SELECT * FROM $t WHERE $where")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($r)));
                $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . str_replace("'", "\\'", (string)$v) . "'", array_values($r)));
                $lines[] = "INSERT INTO `$t` ($cols) VALUES ($vals);";
            }
        } catch (Throwable $e) {
            $lines[] = "-- ERREUR backup $t : " . $e->getMessage();
        }
    }

    file_put_contents($backupFile, implode("\n", $lines));
    return ['file' => $backupFile, 'size' => filesize($backupFile)];
}

// ═══ Migration en transaction atomique ═══
function aml_migrate(PDO $pdo): array {
    $log = [];
    $pdo->beginTransaction();
    $log[] = '🔓 BEGIN TRANSACTION';

    try {
        // 1. biens : id_societe + id_agence
        $st = $pdo->prepare("UPDATE biens SET id_societe = ?, id_agence = ?, date_modification = NOW()
                             WHERE id_societe = ?");
        $st->execute([DST_SOCIETE_ID, DST_AGENCE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE biens : " . $st->rowCount() . " rows";

        // 2. immeubles : id_societe + id_agence
        $st = $pdo->prepare("UPDATE immeubles SET id_societe = ?, id_agence = ?, date_modification = NOW()
                             WHERE id_societe = ?");
        $st->execute([DST_SOCIETE_ID, DST_AGENCE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE immeubles : " . $st->rowCount() . " rows";

        // 3. tiers : id_societe + id_agence
        $st = $pdo->prepare("UPDATE tiers SET id_societe = ?, id_agence = ?, date_modification = NOW()
                             WHERE id_societe = ?");
        $st->execute([DST_SOCIETE_ID, DST_AGENCE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE tiers : " . $st->rowCount() . " rows";

        // 4. mandats : id_agence (id_societe pas dans cette table)
        $st = $pdo->prepare("UPDATE mandats SET id_agence = ?, date_modification = NOW()
                             WHERE id_agence = ?");
        $st->execute([DST_AGENCE_ID, SRC_AGENCE_ID]);
        $log[] = "✅ UPDATE mandats : " . $st->rowCount() . " rows";

        // 5. ged_documents : societe_id + agence_id
        $st = $pdo->prepare("UPDATE ged_documents SET societe_id = ?, agence_id = ?, updated_at = NOW()
                             WHERE societe_id = ?");
        $st->execute([DST_SOCIETE_ID, DST_AGENCE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE ged_documents : " . $st->rowCount() . " rows";

        // 6. fluxbox_documents : tenant_id
        $st = $pdo->prepare("UPDATE fluxbox_documents SET tenant_id = ? WHERE tenant_id = ?");
        $st->execute([DST_SOCIETE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE fluxbox_documents : " . $st->rowCount() . " rows";

        // 7. fluxbox_cartes : tenant_id
        $st = $pdo->prepare("UPDATE fluxbox_cartes SET tenant_id = ? WHERE tenant_id = ?");
        $st->execute([DST_SOCIETE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE fluxbox_cartes : " . $st->rowCount() . " rows";

        // 8. fluxbox_actions_ia : tenant_id
        $st = $pdo->prepare("UPDATE fluxbox_actions_ia SET tenant_id = ? WHERE tenant_id = ?");
        $st->execute([DST_SOCIETE_ID, SRC_SOCIETE_ID]);
        $log[] = "✅ UPDATE fluxbox_actions_ia : " . $st->rowCount() . " rows";

        $pdo->commit();
        $log[] = '🔒 COMMIT';
        return ['ok' => true, 'log' => $log];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            $log[] = '🔴 ROLLBACK : ' . $e->getMessage();
        }
        return ['ok' => false, 'log' => $log, 'error' => $e->getMessage()];
    }
}

// ═══ Routing ═══
$mode = $_POST['mode'] ?? 'dry_run';
$report = null;
$backupInfo = null;

$statsBefore = aml_stats($pdo, SRC_SOCIETE_ID, SRC_AGENCE_ID, DST_SOCIETE_ID, DST_AGENCE_ID);

if ($mode === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Backup
    $backupInfo = aml_backup($pdo);
    // 2. Migration
    $report = aml_migrate($pdo);
}

$statsAfter = aml_stats($pdo, SRC_SOCIETE_ID, SRC_AGENCE_ID, DST_SOCIETE_ID, DST_AGENCE_ID);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Migration LOCA → REGIE EMERY</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --mbi-or:#D4A047;--bg:#0f172a;--panel:#1e293b;--line:#334155;--text:#f1f5f9;--muted:#94a3b8;--ok:#84a98c;--warn:#fde68a;--ko:#f87171;--info:#60a5fa; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: "DM Mono", monospace; background: var(--bg); color: var(--text); font-size: 12.5px; }
        header { display: flex; align-items: center; gap: 16px; padding: 10px 18px; background: #11203b; border-bottom: 2px solid var(--ko); }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .badge { background: var(--ko); color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
        .container { max-width: 1100px; margin: 0 auto; padding: 18px; }
        .panel { background: var(--panel); border-radius: 6px; padding: 16px 20px; margin-bottom: 14px; }
        .panel h2 { font-size: 13px; margin: 0 0 12px; color: var(--mbi-or); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--line); padding-bottom: 6px; }
        table.diff { width: 100%; border-collapse: collapse; font-size: 12px; }
        table.diff th, table.diff td { padding: 6px 12px; text-align: left; border-bottom: 1px solid var(--line); }
        table.diff th { background: #0f172a; color: var(--mbi-or); }
        table.diff td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.diff td.src { color: var(--ko); }
        table.diff td.dst { color: var(--ok); }
        .actions { display: flex; gap: 10px; justify-content: space-between; margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line); }
        .btn { padding: 10px 18px; border: none; border-radius: 4px; font-family: inherit; font-size: 12.5px; cursor: pointer; font-weight: 700; text-decoration: none; }
        .btn-danger { background: var(--ko); color: #fff; }
        .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .warn-box { background: #422006; color: var(--warn); padding: 10px 14px; border-radius: 4px; border-left: 4px solid var(--warn); font-size: 11.5px; margin-bottom: 14px; }
        .ok-box   { background: #14532d; color: #d1fae5; padding: 10px 14px; border-radius: 4px; border-left: 4px solid var(--ok); font-size: 11.5px; margin-bottom: 14px; }
        .ko-box   { background: #7f1d1d; color: #fee2e2; padding: 10px 14px; border-radius: 4px; border-left: 4px solid var(--ko); font-size: 11.5px; margin-bottom: 14px; }
        pre { background: #0a1424; padding: 12px 14px; border-radius: 4px; border-left: 4px solid var(--info); font-size: 11px; overflow-x: auto; max-height: 300px; overflow-y: auto; }
    </style>
</head>
<body>

<header>
    <h1>🔁 Migration LOCA IMMO → REGIE EMERY</h1>
    <span class="badge">SENSIBLE · DRY-RUN PAR DÉFAUT</span>
</header>

<div class="container">

<?php if ($report): ?>
    <?php if ($report['ok']): ?>
        <div class="ok-box">
            ✅ <b>Migration appliquée avec succès.</b><br>
            Backup SQL : <code><?= aml_html($backupInfo['file'] ?? '?') ?></code>
            (<?= number_format(($backupInfo['size'] ?? 0)/1024) ?> Ko)
        </div>
    <?php else: ?>
        <div class="ko-box">
            🔴 <b>ROLLBACK :</b> <?= aml_html($report['error'] ?? '?') ?>
            <br>Aucune modification persistée. Backup SQL généré quand même.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="warn-box">
    <b>⚠️ EXCLUSIONS PRÉSERVÉES (non touchées) :</b>
    users (3 RH), tables rh_*, conges*, salaires, ik_* → la société #3 LOCA IMMO Holding
    reste opérationnelle pour la fonction RH.
</div>

<div class="panel">
    <h2>📊 État BDD AVANT → APRÈS</h2>
    <table class="diff">
        <thead>
            <tr>
                <th>Table</th>
                <th class="num">Source #3 avant</th>
                <th class="num">Source #3 après</th>
                <th class="num">Cible #1 avant</th>
                <th class="num">Cible #1 après</th>
                <th class="num">Δ migré</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (['biens','immeubles','tiers','mandats','ged_documents',
                            'fluxbox_docs','fluxbox_cartes','fluxbox_actions'] as $t):
                $srcKey = $t . '_src';
                $dstKey = $t . '_dst';
                $srcAft = $statsAfter[$srcKey] ?? 0;
                $srcBef = $statsBefore[$srcKey] ?? 0;
                $dstAft = $statsAfter[$dstKey] ?? 0;
                $dstBef = $statsBefore[$dstKey] ?? 0;
                $delta  = $dstAft - $dstBef;
            ?>
                <tr>
                    <td><b><?= aml_html($t) ?></b></td>
                    <td class="num src"><?= $srcBef ?></td>
                    <td class="num"><?= $srcAft === 0 ? '<span style="color:var(--ok);">0</span>' : $srcAft ?></td>
                    <td class="num"><?= $dstBef ?></td>
                    <td class="num dst"><?= $dstAft ?></td>
                    <td class="num"><?= $delta > 0 ? "<b style=\"color:var(--ok);\">+$delta</b>" : ($delta < 0 ? "<b style=\"color:var(--ko);\">$delta</b>" : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="border-top: 2px solid var(--warn);">
                <td><b>⛔ users (PRÉSERVÉ)</b></td>
                <td class="num" colspan="2" style="text-align:center;color:var(--warn);">
                    <?= $statsAfter['users_src_RH'] ?? 0 ?> sous soc #3 (inchangé)
                </td>
                <td class="num" colspan="3" style="color:var(--muted);">RH non touché</td>
            </tr>
        </tbody>
    </table>
</div>

<?php if (!empty($report['log'])): ?>
<div class="panel">
    <h2>📜 Log d'exécution</h2>
    <pre><?= aml_html(implode("\n", $report['log'])) ?></pre>
</div>
<?php endif; ?>

<form method="POST" action="">
    <div class="panel">
        <div class="actions">
            <a href="?" class="btn btn-ghost">🔄 Refresh dry-run</a>
            <button type="submit" name="mode" value="apply" class="btn btn-danger"
                <?= ($report && $report['ok']) ? 'disabled style="opacity:0.4;cursor:not-allowed;"' : '' ?>
                onclick="return confirm('⚠️ MIGRATION DÉFINITIVE\n\nVa migrer :\n- 12 biens · 25 immeubles · 8 tiers · 2 mandats\n- 40 ged_documents · ~65 fluxbox_documents\n- ~64 cartes · 4 audits\n\nDe soc #3 LOCA IMMO → soc #1 Régie EMERY\nDe agence #7 ST MARTIN → agence #3 LYON\n\nUn backup SQL sera créé.\nLa RH (3 users + rh_*) NE SERA PAS touchée.\n\nContinuer ?');">
                💾 Appliquer migration (COMMIT)
            </button>
        </div>
        <div class="warn-box">
            <b>Garanties :</b> Backup SQL auto avant exec · Transaction PDO atomique · Rollback automatique sur erreur ·
            UPDATE only (aucun DELETE) · Tables RH préservées (jamais dans le WHERE).
        </div>
    </div>
</form>

</div>
</body>
</html>
