<?php
/**
 * admin/admin_diag_ged_cascade.php
 *
 * Diag bug sous-domaine FluxBox 2026-05-23 :
 * vérifier que le glossaire ged_level_codes contient bien la cascade
 * 03_GESTION_LOCATIVE > BIENS > BIEN > … en BDD dev.
 *
 * Si la BDD retourne des items → bug = timing JS.
 * Si la BDD retourne vide → bug = seed BDD manquant.
 *
 * Page temporaire de diag. À supprimer après usage.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_classement_v3.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');

$pdo = db();

// Tests cascade : on cherche les enfants à chaque niveau
$tests = [
    'N1 racines'                  => ['level' => 1, 'parents' => []],
    'N2 sous 03_GESTION_LOCATIVE' => ['level' => 2, 'parents' => ['n1' => '03_GESTION_LOCATIVE']],
    'N3 sous 03_GL > BIENS'       => ['level' => 3, 'parents' => ['n1' => '03_GESTION_LOCATIVE', 'n2' => 'BIENS']],
    'N4 sous 03_GL > BIENS > BIEN' => ['level' => 4, 'parents' => ['n1' => '03_GESTION_LOCATIVE', 'n2' => 'BIENS', 'n3' => 'BIEN']],
    'N3 sous 03_GL > PROPRIETAIRES'  => ['level' => 3, 'parents' => ['n1' => '03_GESTION_LOCATIVE', 'n2' => 'PROPRIETAIRES']],
    'N4 sous 03_GL > PROP > PROPRIETAIRE' => ['level' => 4, 'parents' => ['n1' => '03_GESTION_LOCATIVE', 'n2' => 'PROPRIETAIRES', 'n3' => 'PROPRIETAIRE']],
    'N2 sous 05_TRANSACTION'      => ['level' => 2, 'parents' => ['n1' => '05_TRANSACTION']],
    'N3 sous 05_TR > PROPRIETAIRES'  => ['level' => 3, 'parents' => ['n1' => '05_TRANSACTION', 'n2' => 'PROPRIETAIRES']],
    'N4 sous 05_TR > PROP > PROPRIETAIRE' => ['level' => 4, 'parents' => ['n1' => '05_TRANSACTION', 'n2' => 'PROPRIETAIRES', 'n3' => 'PROPRIETAIRE']],
];

$results = [];
foreach ($tests as $name => $t) {
    try {
        if ($t['level'] === 1) {
            $st = $pdo->query("SELECT code, label FROM ged_level_codes
                WHERE level_number = 1 AND is_active = 1 AND COALESCE(is_virtual,0) = 0
                ORDER BY position ASC");
            $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $items = ged_v3_get_children($t['level'], $t['parents'], $pdo);
        }
        $results[$name] = ['count' => count($items), 'items' => $items];
    } catch (Throwable $e) {
        $results[$name] = ['error' => $e->getMessage()];
    }
}

// Test direct SQL (bypass de ged_v3_get_children) pour valider que ça vient pas du COLLATE
$rawTests = [];
try {
    $st = $pdo->prepare("SELECT code, label, parent_n1, parent_n2, is_active, is_virtual, is_entity_placeholder
        FROM ged_level_codes
        WHERE level_number = 3 AND parent_n1 = ? AND parent_n2 = ?");
    $st->execute(['03_GESTION_LOCATIVE', 'BIENS']);
    $rawTests['N3 direct SQL 03_GL > BIENS'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rawTests['N3 direct SQL 03_GL > BIENS'] = ['error' => $e->getMessage()];
}

try {
    $stExists = $pdo->query("SELECT COUNT(*) FROM ged_level_codes WHERE level_number = 2 AND code = 'BIENS'");
    $rawTests['Total entrées N2 code=BIENS (toutes parent_n1)'] = (int)$stExists->fetchColumn();
} catch (Throwable $e) {
    $rawTests['Total entrées N2 code=BIENS (toutes parent_n1)'] = ['error' => $e->getMessage()];
}

try {
    $stPh = $pdo->prepare("SELECT code, parent_n1, parent_n2, is_entity_placeholder
        FROM ged_level_codes WHERE level_number = 3 AND code = 'BIEN'");
    $stPh->execute();
    $rawTests['Toutes les entrées N3 code=BIEN'] = $stPh->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rawTests['Toutes les entrées N3 code=BIEN'] = ['error' => $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Diag GED cascade</title>
    <style>
        body { font-family: "DM Mono", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1200px; margin: 0 auto; }
        h1 { color: #fde68a; margin: 0 0 6px; }
        h2 { color: #84a98c; font-size: 14px; margin: 22px 0 6px; }
        h3 { color: #0e7490; font-size: 12px; margin: 14px 0 4px; }
        .ok { color: #84a98c; font-weight: 700; }
        .ko { color: #f87171; font-weight: 700; }
        .warn { color: #fde68a; }
        pre { background: #1e293b; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 11px; line-height: 1.5; }
        .verdict { background: #1e293b; padding: 14px 18px; border-radius: 8px; border-left: 4px solid #fde68a; margin: 24px 0; }
    </style>
</head>
<body>
    <h1>🧪 Diag cascade GED — bug sous-domaine FluxBox</h1>
    <p>Vérifie que les codes <code>03_GESTION_LOCATIVE > BIENS > BIEN > BAUX</code> existent en BDD (dev).</p>

    <h2>📊 Résultats via ged_v3_get_children()</h2>
    <?php foreach ($results as $name => $r): ?>
        <h3><?= htmlspecialchars($name) ?></h3>
        <?php if (isset($r['error'])): ?>
            <pre class="ko">ERREUR : <?= htmlspecialchars($r['error']) ?></pre>
        <?php else: ?>
            <pre><span class="<?= $r['count'] > 0 ? 'ok' : 'ko' ?>"><?= $r['count'] ?> item(s)</span>
<?= htmlspecialchars(json_encode($r['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php endif; ?>
    <?php endforeach; ?>

    <h2>🔍 Tests SQL bruts (bypass ged_v3_get_children)</h2>
    <?php foreach ($rawTests as $name => $r): ?>
        <h3><?= htmlspecialchars($name) ?></h3>
        <pre><?= htmlspecialchars(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php endforeach; ?>

    <div class="verdict">
        <strong>📌 Diagnostic :</strong><br>
        <?php
        $n3Items = $results['N3 sous 03_GL > BIENS']['items'] ?? [];
        if (empty($n3Items)):
        ?>
            <span class="ko">❌ La cascade backend retourne VIDE pour N3 sous 03_GESTION_LOCATIVE > BIENS.</span><br>
            <span class="warn">→ Le bug est côté BDD (seed manquant ou bug COLLATE).</span><br>
            Vérifie les "Tests SQL bruts" ci-dessus :
            <ul>
                <li>Si "Toutes les entrées N3 code=BIEN" est vide → migration <code>20260502_ged_v1_10_levels_seed</code> non appliquée en dev</li>
                <li>Si elles existent mais sans parent_n1/n2 correct → bug seed</li>
                <li>Si parent_n1='03_GESTION_LOCATIVE' et parent_n2='BIENS' existe → bug COLLATE</li>
            </ul>
        <?php else: ?>
            <span class="ok">✅ La cascade backend FONCTIONNE pour N3 sous 03_GL > BIENS.</span><br>
            <span class="warn">→ Le bug est côté JS (timing applyPrefillCascade).</span><br>
            Action : ajouter des console.log dans <code>fluxbox_upload_modal.php applyPrefillCascade()</code> pour tracer le déroulé async.
        <?php endif; ?>
    </div>

</body>
</html>
