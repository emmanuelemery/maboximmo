<?php
require_once __DIR__ . '/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

echo "=== AUDIT BASE DE DONNÉES ===\n\n";

// 1. Lister TOUTES les tables
echo "1. TOUTES LES TABLES:\n";
$st = $pdo->query('SHOW TABLES');
$allTables = $st->fetchAll(PDO::FETCH_COLUMN);
echo "Total: " . count($allTables) . " tables\n";
foreach ($allTables as $t) {
    echo "  - $t\n";
}

// 2. Tables relatives aux relevés bancaires
echo "\n2. TABLES RELEVÉS BANCAIRES:\n";
$st = $pdo->query('SHOW TABLES LIKE "%relev%"');
$relevTables = $st->fetchAll(PDO::FETCH_COLUMN);
foreach ($relevTables as $t) {
    echo "  - $t\n";
    $st2 = $pdo->query("SELECT COUNT(*) FROM $t");
    $count = $st2->fetchColumn();
    echo "    → Nombre de lignes: $count\n";
}

// 3. Tables GED
echo "\n3. TABLES GED:\n";
$st = $pdo->query('SHOW TABLES LIKE "%ged%"');
$gedTables = $st->fetchAll(PDO::FETCH_COLUMN);
foreach ($gedTables as $t) {
    echo "  - $t\n";
    $st2 = $pdo->query("SELECT COUNT(*) FROM $t");
    $count = $st2->fetchColumn();
    echo "    → Nombre de lignes: $count\n";
}

// 4. Tables documents/fichiers
echo "\n4. TABLES DOCUMENTS/FICHIERS:\n";
$st = $pdo->query('SHOW TABLES LIKE "%document%"');
$docTables = $st->fetchAll(PDO::FETCH_COLUMN);
foreach ($docTables as $t) {
    echo "  - $t\n";
    $st2 = $pdo->query("SELECT COUNT(*) FROM $t");
    $count = $st2->fetchColumn();
    echo "    → Nombre de lignes: $count\n";
}

// 5. Tables immeuble/bien
echo "\n5. TABLES IMMEUBLE/BIEN:\n";
$st = $pdo->query('SHOW TABLES LIKE "%immeuble%"');
$immTables = $st->fetchAll(PDO::FETCH_COLUMN);
foreach ($immTables as $t) {
    echo "  - $t\n";
    $st2 = $pdo->query("SELECT COUNT(*) FROM $t");
    $count = $st2->fetchColumn();
    echo "    → Nombre de lignes: $count\n";
}

// 6. Chercher des colonnes avec "relev" ou "banque"
echo "\n6. COLONNES AVEC 'RELEV' OU 'BANQUE':\n";
foreach ($allTables as $table) {
    try {
        $st = $pdo->query("DESCRIBE $table");
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            $field = strtolower($col['Field']);
            if (strpos($field, 'relev') !== false || strpos($field, 'banque') !== false) {
                echo "  [$table] {$col['Field']} ({$col['Type']})\n";
            }
        }
    } catch (Throwable) {}
}

// 7. Rechercher 755 ou des statistiques
echo "\n7. COMPTE DES ITEMS PAR TABLE:\n";
$tables_to_check = [
    'ged_import_releves_items',
    'ged_import_releves_batches',
    'documents',
    'ged_inbox_items',
    'immeubles_documents'
];

foreach ($tables_to_check as $t) {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM $t");
        $count = $st->fetchColumn();
        echo "  $t: $count\n";
    } catch (Throwable) {
        echo "  $t: [INTROUVABLE]\n";
    }
}

// 8. Vérifier les fichiers de storage
echo "\n8. STOCKAGE FICHIERS:\n";
$storageBase = dirname(__DIR__) . '/storage';
if (is_dir($storageBase)) {
    echo "  Dossier storage existe: $storageBase\n";

    // Compter les fichiers
    $dirs = [
        'ged' => 'GED',
        'documents' => 'Documents',
        'immeuble' => 'Immeuble',
        'releves' => 'Relevés'
    ];

    foreach ($dirs as $dir => $label) {
        $path = $storageBase . '/' . $dir;
        if (is_dir($path)) {
            $count = 0;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file->isFile()) $count++;
            }
            echo "    - $label ($dir): $count fichiers\n";
        }
    }
} else {
    echo "  Dossier storage: INTROUVABLE\n";
}

// 9. Vérifier la structure ged_import_releves_items
echo "\n9. STRUCTURE DÉTAILLÉE ged_import_releves_items:\n";
try {
    $st = $pdo->query('DESCRIBE ged_import_releves_items');
    $cols = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - {$c['Field']}: {$c['Type']} " . ($c['Null'] === 'YES' ? '(nullable)' : '(NOT NULL)') . "\n";
    }
} catch (Throwable) {
    echo "  Table introuvable\n";
}

// 10. Exemples de données dans ged_import_releves_items
echo "\n10. EXEMPLES DONNÉES ged_import_releves_items:\n";
try {
    $st = $pdo->query("SELECT batch_id, COUNT(*) as cnt, statut, banque_detectee, periode_annee, periode_mois FROM ged_import_releves_items GROUP BY batch_id, statut ORDER BY batch_id DESC LIMIT 20");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  Batch #{$r['batch_id']} | Statut: {$r['statut']} | Banque: {$r['banque_detectee']} | Période: {$r['periode_annee']}-{$r['periode_mois']} | Nombre: {$r['cnt']}\n";
    }
} catch (Throwable $e) {
    echo "  Erreur: " . $e->getMessage() . "\n";
}

echo "\n=== FIN AUDIT ===\n";
