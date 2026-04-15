<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/public_html/inc/bootstrap.php';
    
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        die("❌ Erreur: PDO non disponible\n");
    }
    
    echo "📋 Lecture du fichier de migration...\n";
    $migrationFile = __DIR__ . '/sql/conges_migration.sql';
    
    if (!file_exists($migrationFile)) {
        die("❌ Fichier de migration non trouvé: $migrationFile\n");
    }
    
    $sql = file_get_contents($migrationFile);
    
    echo "⚙️  Exécution de la migration...\n";
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s) && substr($s, 0, 2) !== '--'
    );
    
    $count = 0;
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            $count++;
            echo "  ✓ Instruction $count exécutée\n";
        }
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    echo "📊 $count instructions exécutées\n";
    echo "\n📋 Vérification des tables:\n";
    
    $tables = ['conges', 'conges_soldes'];
    foreach ($tables as $table) {
        $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
        echo $check ? "  ✓ Table '$table' créée\n" : "  ❌ Table '$table' manquante\n";
    }
    
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'couleur'")->fetchColumn();
    echo $colCheck ? "  ✓ Colonne 'couleur' ajoutée à users\n" : "  ❌ Colonne manquante\n";
    
    echo "\n✨ Module Gestion des Congés prêt!\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    exit(1);
}
?>
