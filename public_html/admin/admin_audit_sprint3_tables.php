<?php
/**
 * admin/admin_audit_sprint3_tables.php
 *
 * Sprint 3 — audit BDD AVANT toute création de table.
 * Vérifie si les 3 tables proposées par le prompt Sprint 3 existent déjà :
 *   - document_extractions
 *   - document_processing_logs
 *   - ged_classification_feedback
 *
 * + Inventaire des tables connexes (ia_*, ged_*, fluxbox_*) avec colonnes clés.
 *
 * LECTURE SEULE. Aucune écriture BDD.
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

$report = [];

// ─── 1. Tables proposées par le prompt Sprint 3 ──────────────────────
$proposed = ['document_extractions', 'document_processing_logs', 'ged_classification_feedback'];
$report['1_tables_proposees_par_prompt_sprint3'] = [];
foreach ($proposed as $t) {
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
        $cols = [];
        $nbRows = null;
        if ($exists) {
            foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) $cols[] = $c['Field'];
            try { $nbRows = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable) {}
        }
        $report['1_tables_proposees_par_prompt_sprint3'][$t] = [
            'exists' => $exists ? '✅ EXISTE — NE PAS RECRÉER' : '❌ absente — création possible',
            'columns' => $cols,
            'nb_rows' => $nbRows,
        ];
    } catch (Throwable $e) {
        $report['1_tables_proposees_par_prompt_sprint3'][$t] = ['ERREUR' => $e->getMessage()];
    }
}

// ─── 2. Tables existantes du domaine documentaire ────────────────────
$patterns = ['ged_%', 'ia_%', 'fluxbox_%', 'transaction_chargement_%', 'document_%', 'extraction_%'];
$report['2_tables_existantes_par_pattern'] = [];
foreach ($patterns as $p) {
    try {
        $st = $pdo->query("SHOW TABLES LIKE '$p'");
        $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $report['2_tables_existantes_par_pattern'][$p] = $rows;
    } catch (Throwable $e) {
        $report['2_tables_existantes_par_pattern'][$p] = ['ERREUR' => $e->getMessage()];
    }
}

// ─── 3. Volumétrie des tables clés du pipeline doc ───────────────────
$keyTables = ['ged_documents', 'ged_document_links', 'ia_extract_cache',
              'fluxbox_documents', 'fluxbox_cartes', 'fluxbox_actions_ia',
              'fluxbox_ia_usage', 'transaction_chargement_staging'];
$report['3_volumetrie_tables_cles'] = [];
foreach ($keyTables as $t) {
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
        if (!$exists) { $report['3_volumetrie_tables_cles'][$t] = '❌ absente'; continue; }
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $report['3_volumetrie_tables_cles'][$t] = $n . ' rows';
    } catch (Throwable $e) {
        $report['3_volumetrie_tables_cles'][$t] = 'ERREUR: ' . $e->getMessage();
    }
}

// ─── 4. Verdict pour Sprint 3 ────────────────────────────────────────
$verdicts = [];
foreach ($proposed as $t) {
    $exists = ($report['1_tables_proposees_par_prompt_sprint3'][$t]['exists'] ?? '') === '✅ EXISTE — NE PAS RECRÉER';
    $verdicts[$t] = $exists
        ? '✅ Réutiliser l\'existante (ne PAS créer)'
        : '⚠️ Création possible — mais demander validation user avant';
}
$report['4_verdict_sprint3'] = $verdicts;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Audit Sprint 3 — tables</title>
    <style>
        body { font-family: "DM Mono", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1200px; margin: 0 auto; }
        h1 { color: #fde68a; }
        h2 { color: #84a98c; font-size: 14px; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #334155; }
        pre { background: #1e293b; padding: 10px 14px; border-radius: 6px; overflow-x: auto; font-size: 11.5px; line-height: 1.55; }
        .ok { color: #84a98c; font-weight: 700; }
        .ko { color: #f87171; }
        .warn { color: #fde68a; }
    </style>
</head>
<body>
<h1>🧪 Audit Sprint 3 — tables proposées (lecture seule)</h1>
<p>Avant de créer une table additive, on vérifie qu'elle n'existe pas déjà.</p>

<?php foreach ($report as $key => $val): ?>
    <h2><?= htmlspecialchars($key) ?></h2>
    <pre><?= htmlspecialchars(json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
<?php endforeach; ?>

</body>
</html>
