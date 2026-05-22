<?php
// admin/admin_migration_transaction.php — Exécution one-shot de la migration V0 Transaction (2026-05-18)
// Accès super admin uniquement. Idempotent : ré-exécutable sans dégât.
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_doc_lib.php';      // → tr_bail_ensure_columns
require_once __DIR__ . '/../inc/transaction_chg_staging.php';  // → tr_staging_ensure_table
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès refusé (super admin uniquement).');
}

$sqlFile = __DIR__ . '/../../sql/migration_transaction_v0_2026-05-18.sql';
if (!is_file($sqlFile)) {
    exit('Fichier migration introuvable : ' . htmlspecialchars($sqlFile));
}

$run     = isset($_GET['run']) && $_GET['run'] === '1';
$results = [];
$errors  = [];

if ($run) {
    $sqlAll = file_get_contents($sqlFile);
    // Découpe en statements (gère les commentaires --, vide lignes, sépare sur ;)
    // Attention : la VIEW contient un sub-SELECT avec ';' uniquement à la fin.
    $statements = [];
    $buffer = '';
    foreach (preg_split("/\r?\n/", (string)$sqlAll) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--')) continue;
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') $statements[] = trim($buffer);

    foreach ($statements as $stmt) {
        if (trim($stmt) === '' || trim($stmt) === ';') continue;
        try {
            $pdo->exec($stmt);
            $results[] = ['ok' => true,  'sql' => substr($stmt, 0, 200) . (strlen($stmt) > 200 ? '…' : '')];
        } catch (Throwable $e) {
            $errors[] = ['msg' => $e->getMessage(), 'sql' => substr($stmt, 0, 300)];
        }
    }

    // ─── Extensions auto-ALTER (idempotentes via fonctions helpers) ────────
    try {
        tr_staging_ensure_table($pdo);
        $results[] = ['ok' => true, 'sql' => '✓ tr_staging_ensure_table() — table transaction_chargement_staging'];
    } catch (Throwable $e) { $errors[] = ['msg' => $e->getMessage(), 'sql' => 'tr_staging_ensure_table']; }

    try {
        tr_bail_ensure_columns($pdo);
        $results[] = ['ok' => true, 'sql' => '✓ tr_bail_ensure_columns() — 10 colonnes bien_baux (représentants + renonciation + conditions particulières)'];
    } catch (Throwable $e) { $errors[] = ['msg' => $e->getMessage(), 'sql' => 'tr_bail_ensure_columns']; }

    // Vérification finale : la vue existe-t-elle ?
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'vw_transactions'")->fetchColumn();
        $viewExists = (bool)$check;
        $sample = $viewExists ? (int)$pdo->query('SELECT COUNT(*) FROM vw_transactions')->fetchColumn() : null;
    } catch (Throwable $e) {
        $viewExists = false;
        $sample = null;
    }
}
?><!doctype html>
<html lang="fr"><head>
<meta charset="utf-8">
<title>Migration Transaction V0</title>
<style>
body { font-family: 'DM Mono', monospace, sans-serif; padding: 30px; max-width: 1000px; background: #f7f4ef; color: #2c2a28; }
h1 { font-family: Sora, sans-serif; }
.card { background: #fff; border-radius: 12px; padding: 22px; margin-bottom: 18px; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.ok { color: #2d6a35; }
.err { color: #a8323b; }
.btn { display: inline-block; background: #4878a6; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; }
.btn:hover { background: #3a6890; }
pre { background: #f4f1ec; padding: 8px; border-radius: 6px; font-size: 11px; overflow-x: auto; max-width: 100%; white-space: pre-wrap; word-break: break-word; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
td { padding: 6px 8px; border-bottom: 1px solid #f0ece6; vertical-align: top; }
</style></head><body>

<h1>🔧 Migration Transaction V0 — 2026-05-18</h1>

<?php if (!$run): ?>
    <div class="card">
        <p>Cette migration ajoute :</p>
        <ul>
            <li>4 colonnes à <code>biens</code> (date_mise_en_vente, date_retrait_commercialisation, prix_demande_initial, prix_final_vente)</li>
            <li>3 colonnes à <code>leads_annonces</code> (prix_propose, financement_type, statut_offre)</li>
            <li>2 index sur ces colonnes</li>
            <li>1 VUE SQL <code>vw_transactions</code></li>
        </ul>
        <p><strong>Idempotent</strong> : ré-exécutable sans risque (vérifications information_schema).</p>
        <p><a href="?run=1" class="btn">▶️ Lancer la migration maintenant</a></p>
    </div>
<?php else: ?>
    <div class="card">
        <h2>Résultat</h2>
        <p><strong><?= count($results) ?></strong> statement(s) exécuté(s), <strong class="<?= empty($errors) ? 'ok' : 'err' ?>"><?= count($errors) ?></strong> erreur(s).</p>
        <?php if (!empty($errors)): ?>
            <h3 class="err">❌ Erreurs</h3>
            <table>
            <?php foreach ($errors as $e): ?>
                <tr><td class="err"><?= htmlspecialchars($e['msg']) ?></td><td><pre><?= htmlspecialchars($e['sql']) ?></pre></td></tr>
            <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h3>✅ Vérification finale</h3>
        <?php if (!empty($viewExists)): ?>
            <p class="ok">✅ La vue <code>vw_transactions</code> existe — <?= (int)$sample ?> lignes accessibles.</p>
            <p><a href="<?= htmlspecialchars(app_url('/transaction_index.php')) ?>" class="btn">🎯 Ouvrir le module Transaction</a></p>
        <?php else: ?>
            <p class="err">⚠️ La vue <code>vw_transactions</code> n'a PAS été créée. Voir les erreurs ci-dessus.</p>
        <?php endif; ?>

        <details style="margin-top:18px;">
            <summary>Détail de tous les statements (<?= count($results) ?>)</summary>
            <table>
            <?php foreach ($results as $r): ?>
                <tr><td class="ok">✅</td><td><pre><?= htmlspecialchars($r['sql']) ?></pre></td></tr>
            <?php endforeach; ?>
            </table>
        </details>
    </div>
<?php endif; ?>

</body></html>
