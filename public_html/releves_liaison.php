<?php
/**
 * Interface simple pour lier les relevés CREDIT MUTUEL à MIONS
 * Accès: http://localhost/releves_liaison.php
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liaison Relevés MIONS</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 20px; background: #f5f5f5; }
        .card { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 800px; }
        h1 { color: #333; }
        h2 { color: #555; margin-top: 30px; }
        .info { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 10px 0; }
        .success { background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 15px; margin: 10px 0; }
        .error { background: #ffebee; border-left: 4px solid #f44336; padding: 15px; margin: 10px 0; }
        .warning { background: #fff3e0; border-left: 4px solid #ff9800; padding: 15px; margin: 10px 0; }
        button { background: #2196F3; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1976D2; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table th, table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        table th { background: #f5f5f5; font-weight: 600; }
        table tr:hover { background: #f9f9f9; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-box { background: #f9f9f9; padding: 15px; border-radius: 4px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #2196F3; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
    </style>
</head>
<body>

<div class="card">
    <h1>🔗 Liaison des Relevés CREDIT MUTUEL à MIONS</h1>

<?php

// 1. TROUVER IMMEUBLES MIONS
$st = $pdo->prepare("
    SELECT id, reference_immeuble, nom_immeuble, ville, logiciel_comptable
    FROM immeubles
    WHERE LOWER(CONCAT(reference_immeuble, ' ', nom_immeuble, ' ', ville)) LIKE '%mions%'
    ORDER BY reference_immeuble ASC, nom_immeuble ASC
    LIMIT 20
");
$st->execute();
$immeubles_mions = $st->fetchAll(PDO::FETCH_ASSOC);

echo '<h2>Étape 1: Immeubles MIONS détectés</h2>';

if (empty($immeubles_mions)) {
    echo '<div class="error">❌ Aucun immeuble trouvé avec "MIONS" dans le nom/référence/ville</div>';

    // Proposer une recherche alternative
    echo '<div class="info">💡 Essayons une recherche plus large...</div>';
    $st = $pdo->prepare("SELECT id, reference_immeuble, nom_immeuble, ville FROM immeubles LIMIT 5");
    $st->execute();
    $tous = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '<p>Voici les 5 premiers immeubles (complète la recherche manuellement):</p>';
    foreach ($tous as $im) {
        echo '<li>ID ' . htmlspecialchars((string)$im['id']) . ': ' . htmlspecialchars((string)$im['nom_immeuble']) . ' (' . htmlspecialchars((string)$im['ville']) . ')</li>';
    }
} else {
    echo '<div class="success">✅ ' . count($immeubles_mions) . ' immeuble(s) MIONS trouvé(s)</div>';
    echo '<table>';
    echo '<tr><th>ID</th><th>Référence</th><th>Nom</th><th>Ville</th><th>Logiciel</th><th>Action</th></tr>';

    foreach ($immeubles_mions as $im) {
        $immId = (int)$im['id'];
        echo '<tr>';
        echo '<td><strong>' . $immId . '</strong></td>';
        echo '<td>' . htmlspecialchars((string)($im['reference_immeuble'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($im['nom_immeuble'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($im['ville'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($im['logiciel_comptable'] ?? 'non défini')) . '</td>';
        echo '<td><a href="?action=link&immeuble_id=' . $immId . '"><button>Lier à cet immeuble</button></a></td>';
        echo '</tr>';
    }
    echo '</table>';
}

// 2. STATISTIQUES RELEVÉS CREDIT MUTUEL
echo '<h2>Étape 2: Statistiques relevés CREDIT MUTUEL</h2>';

$st = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN id_immeuble IS NULL THEN 1 ELSE 0 END) as non_lies,
        SUM(CASE WHEN id_immeuble IS NOT NULL THEN 1 ELSE 0 END) as lies
    FROM ged_import_releves_items
    WHERE banque_detectee LIKE '%CREDIT MUTUEL%'
");
$st->execute();
$stats = $st->fetch(PDO::FETCH_ASSOC);

echo '<div class="stats">';
echo '<div class="stat-box">';
echo '<div class="stat-number">' . ($stats['total'] ?? 0) . '</div>';
echo '<div class="stat-label">Relevés CREDIT MUTUEL au total</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . ($stats['non_lies'] ?? 0) . '</div>';
echo '<div class="stat-label">Sans immeuble (à lier)</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . ($stats['lies'] ?? 0) . '</div>';
echo '<div class="stat-label">Déjà liés</div>';
echo '</div>';
echo '</div>';

// 3. EXÉCUTER LA LIAISON SI DEMANDÉE
if ($action === 'link' && isset($_GET['immeuble_id'])) {
    $immeubleId = (int)$_GET['immeuble_id'];

    // Vérifier immeuble existe
    $st = $pdo->prepare("SELECT id, nom_immeuble FROM immeubles WHERE id = ?");
    $st->execute([$immeubleId]);
    $immeuble = $st->fetch(PDO::FETCH_ASSOC);

    if (!$immeuble) {
        echo '<div class="error">❌ Immeuble ID ' . $immeubleId . ' introuvable</div>';
    } else {
        echo '<h2>Étape 3: Exécution de la liaison</h2>';

        // Compter combien seront liés
        $st = $pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM ged_import_releves_items
            WHERE banque_detectee LIKE '%CREDIT MUTUEL%'
            AND id_immeuble IS NULL
        ");
        $st->execute();
        $count_to_link = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        if ($count_to_link === 0) {
            echo '<div class="info">ℹ️ Aucun relevé à lier (tous sont déjà liés)</div>';
        } else {
            // Exécuter la liaison
            $upd = $pdo->prepare("
                UPDATE ged_import_releves_items
                SET id_immeuble = :imm,
                    statut = 'recognized'
                WHERE banque_detectee LIKE '%CREDIT MUTUEL%'
                AND id_immeuble IS NULL
            ");
            $upd->execute([':imm' => $immeubleId]);
            $linked_count = $upd->rowCount();

            echo '<div class="success">✅ ' . $linked_count . ' relevés liés à ' . htmlspecialchars((string)$immeuble['nom_immeuble']) . '</div>';

            // Afficher les détails
            echo '<h3>Détails de la liaison</h3>';
            echo '<div class="info">';
            echo '<strong>Immeuble:</strong> ID ' . $immeubleId . ' - ' . htmlspecialchars((string)$immeuble['nom_immeuble']) . '<br>';
            echo '<strong>Relevés liés:</strong> ' . $linked_count . '<br>';
            echo '<strong>Statut:</strong> recognized<br>';
            echo '<strong>Banque:</strong> CREDIT MUTUEL<br>';
            echo '</div>';

            // Montrer quelques exemples
            echo '<h3>Exemples de relevés liés</h3>';
            $st = $pdo->prepare("
                SELECT id, fichier_original, periode_annee, periode_mois, statut
                FROM ged_import_releves_items
                WHERE banque_detectee LIKE '%CREDIT MUTUEL%'
                AND id_immeuble = :imm
                ORDER BY id DESC
                LIMIT 5
            ");
            $st->execute([':imm' => $immeubleId]);
            $examples = $st->fetchAll(PDO::FETCH_ASSOC);

            echo '<table>';
            echo '<tr><th>ID</th><th>Fichier</th><th>Période</th><th>Statut</th></tr>';
            foreach ($examples as $ex) {
                echo '<tr>';
                echo '<td>' . $ex['id'] . '</td>';
                echo '<td>' . htmlspecialchars((string)$ex['fichier_original']) . '</td>';
                echo '<td>' . $ex['periode_annee'] . '-' . str_pad((string)$ex['periode_mois'], 2, '0', STR_PAD_LEFT) . '</td>';
                echo '<td><strong>' . htmlspecialchars((string)$ex['statut']) . '</strong></td>';
                echo '</tr>';
            }
            echo '</table>';

            echo '<div class="warning">
                ⚠️ <strong>Prochaine étape:</strong> Les relevés sont maintenant liés à l\'immeuble MIONS.
                Ils devraient maintenant apparaître dans l\'espace immeuble à
                <a href="http://localhost:3080/app/immeuble-space?id=' . $immeubleId . '" target="_blank">
                http://localhost:3080/app/immeuble-space?id=' . $immeubleId . '
                </a>
            </div>';
        }
    }
}

?>

    <hr style="margin: 40px 0; border: none; border-top: 1px solid #ddd;">
    <p style="color: #999; font-size: 12px;">
        Script temporaire pour gérer les liaisons.
        <a href="?">Réinitialiser</a>
    </p>
</div>

</body>
</html>
