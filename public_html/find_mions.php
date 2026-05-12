<?php
/**
 * Script temporaire pour trouver l'immeuble MIONS et lier les 60 relevés CREDIT MUTUEL
 */
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];

// 1. Trouver MIONS
$st = $pdo->prepare("
    SELECT id, reference_immeuble, nom_immeuble, ville, logiciel_comptable
    FROM immeubles
    WHERE LOWER(CONCAT(reference_immeuble, ' ', nom_immeuble, ' ', ville)) LIKE '%mions%'
    LIMIT 10
");
$st->execute();
$immeubles_mions = $st->fetchAll(PDO::FETCH_ASSOC);

if (empty($immeubles_mions)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Aucun immeuble trouvé avec "MIONS"',
        'immeubles_mions' => $immeubles_mions
    ]);
    exit;
}

// 2. Compter les relevés sans immeuble pour CREDIT MUTUEL
$st = $pdo->prepare("
    SELECT COUNT(*) as cnt
    FROM ged_import_releves_items
    WHERE statut IN ('extracted', 'analyzed', 'uncertain', 'recognized')
    AND banque_detectee LIKE '%CREDIT MUTUEL%'
    AND id_immeuble IS NULL
");
$st->execute();
$unlinked = $st->fetch(PDO::FETCH_ASSOC);

// 3. Proposer lien automatique
$results = [
    'immeubles_mions_found' => $immeubles_mions,
    'unlinked_credit_mutuel_count' => $unlinked['cnt'] ?? 0,
    'next_step' => 'Lier les ' . ($unlinked['cnt'] ?? 0) . ' relevés à l\'immeuble ID ' . ($immeubles_mions[0]['id'] ?? 'N/A')
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
