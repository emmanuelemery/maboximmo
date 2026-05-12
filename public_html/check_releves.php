<?php
require_once __DIR__ . '/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

echo "=== VÉRIFICATION BASE DE DONNÉES ===\n\n";

// Vérifier les batches
echo "Batches récents:\n";
$st = $pdo->query('SELECT id, statut, nb_pdf, nb_reconnus, nb_a_valider, created_at FROM ged_import_releves_batches ORDER BY id DESC LIMIT 5');
$batches = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($batches as $b) {
    echo "  Batch #{$b['id']} | Statut: {$b['statut']} | PDFs: {$b['nb_pdf']} | Reconnus: {$b['nb_reconnus']} | À valider: {$b['nb_a_valider']}\n";
}

// Items du batch 1
echo "\nItems batch #1:\n";
$st = $pdo->query('SELECT COUNT(*) FROM ged_import_releves_items WHERE batch_id=1');
$count = $st->fetchColumn();
echo "  Total: $count\n";

// Items par statut
$st = $pdo->query('SELECT statut, COUNT(*) as cnt FROM ged_import_releves_items WHERE batch_id=1 GROUP BY statut');
$statuts = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($statuts as $s) {
    echo "    - {$s['statut']}: {$s['cnt']}\n";
}

// Tables
echo "\nTables relevés:\n";
$st = $pdo->query('SHOW TABLES LIKE "%relev%"');
$tables = $st->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "  - $t\n";
}

// Vérifier la structure de ged_import_releves_items
echo "\nStructure ged_import_releves_items:\n";
$st = $pdo->query('DESCRIBE ged_import_releves_items');
$cols = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']})\n";
}
