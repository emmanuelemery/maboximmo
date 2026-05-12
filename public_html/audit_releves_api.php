<?php
require_once __DIR__ . '/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

echo "=== AUDIT TABLE RELEVES API ===" . PHP_EOL . PHP_EOL;

// 1. Trouver la table
echo "1. RECHERCHE DE LA TABLE:" . PHP_EOL;
$tables = [
    'releves',
    'releves_banques',
    'releves_comptes',
    'bank_statements',
    'relevés',
    'releves_bancaires'
];

$foundTable = null;
foreach ($tables as $t) {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM $t");
        $count = $st->fetchColumn();
        echo "  ✓ Table $t existe: $count lignes" . PHP_EOL;
        if ($count > 700) {
            $foundTable = $t;
            echo "    ^ C'EST CELLE-CI!" . PHP_EOL;
        }
    } catch (Throwable) {
        // Table n'existe pas
    }
}

if ($foundTable) {
    echo "\n2. STRUCTURE DE $foundTable:" . PHP_EOL;
    try {
        $st = $pdo->query("DESCRIBE $foundTable");
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  - {$c['Field']}: {$c['Type']} " .
                 ($c['Null'] === 'YES' ? '(nullable)' : '(NOT NULL)') .
                 ($c['Key'] ? " [KEY: {$c['Key']}]" : '') . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo "  Erreur: " . $e->getMessage() . PHP_EOL;
    }

    echo "\n3. STATISTIQUES:" . PHP_EOL;
    try {
        $st = $pdo->query("SELECT COUNT(*) as total,
                                  COUNT(DISTINCT banque) as nb_banques,
                                  COUNT(DISTINCT annee) as nb_annees,
                                  COUNT(DISTINCT mois) as nb_mois
                           FROM $foundTable");
        $stats = $st->fetch(PDO::FETCH_ASSOC);
        foreach ($stats as $k => $v) {
            echo "  $k: $v" . PHP_EOL;
        }
    } catch (Throwable) {}

    echo "\n4. BANQUES PRÉSENTES:" . PHP_EOL;
    try {
        $st = $pdo->query("SELECT banque, COUNT(*) as cnt FROM $foundTable GROUP BY banque ORDER BY cnt DESC LIMIT 10");
        $banques = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($banques as $b) {
            echo "  - {$b['banque']}: {$b['cnt']} relevés" . PHP_EOL;
        }
    } catch (Throwable) {}

    echo "\n5. PÉRIODES PRÉSENTES:" . PHP_EOL;
    try {
        $st = $pdo->query("SELECT annee, mois, COUNT(*) as cnt FROM $foundTable GROUP BY annee, mois ORDER BY annee DESC, mois DESC LIMIT 15");
        $periodes = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($periodes as $p) {
            echo "  - {$p['annee']}-{$p['mois']}: {$p['cnt']} relevés" . PHP_EOL;
        }
    } catch (Throwable) {}

    echo "\n6. AGENCES PRÉSENTES:" . PHP_EOL;
    try {
        $st = $pdo->query("SELECT agence, COUNT(*) as cnt FROM $foundTable GROUP BY agence ORDER BY cnt DESC LIMIT 15");
        $agences = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($agences as $a) {
            echo "  - {$a['agence']}: {$a['cnt']} relevés" . PHP_EOL;
        }
    } catch (Throwable) {}

    echo "\n7. EXEMPLE DE LIGNE:" . PHP_EOL;
    try {
        $st = $pdo->query("SELECT * FROM $foundTable LIMIT 1");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            foreach ($row as $k => $v) {
                $display = is_string($v) ? mb_substr($v, 0, 80) : $v;
                echo "  $k: $display" . PHP_EOL;
            }
        }
    } catch (Throwable) {}

} else {
    echo "  ✗ Table introuvable!" . PHP_EOL;

    // Lister TOUTES les tables
    echo "\n  Tables disponibles:" . PHP_EOL;
    $st = $pdo->query('SHOW TABLES');
    $allTables = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($allTables as $t) {
        $st2 = $pdo->query("SELECT COUNT(*) FROM $t");
        $cnt = $st2->fetchColumn();
        if ($cnt > 50) {
            echo "    - $t: $cnt lignes" . PHP_EOL;
        }
    }
}
