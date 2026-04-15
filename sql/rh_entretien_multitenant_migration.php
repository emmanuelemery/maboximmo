<?php
/**
 * Migration multi-tenant : ajout societe_id + is_suggestion aux tables catalogue,
 * et création des tables de config par société.
 * Exécuter via : php sql/rh_entretien_multitenant_migration.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../public_html/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

$steps = [];

// 1. ALTER rh_entretien_rubriques
try {
    $pdo->exec("ALTER TABLE rh_entretien_rubriques
        ADD COLUMN IF NOT EXISTS societe_id INT NULL AFTER actif,
        ADD COLUMN IF NOT EXISTS is_suggestion TINYINT(1) NOT NULL DEFAULT 0 AFTER societe_id");
    $steps[] = "✅ rh_entretien_rubriques : colonnes societe_id + is_suggestion ajoutées";
} catch (PDOException $e) {
    $steps[] = "❌ rh_entretien_rubriques : " . $e->getMessage();
}

// 2. ALTER rh_entretien_questions_user
try {
    $pdo->exec("ALTER TABLE rh_entretien_questions_user
        ADD COLUMN IF NOT EXISTS societe_id INT NULL AFTER actif,
        ADD COLUMN IF NOT EXISTS is_suggestion TINYINT(1) NOT NULL DEFAULT 0 AFTER societe_id");
    $steps[] = "✅ rh_entretien_questions_user : colonnes societe_id + is_suggestion ajoutées";
} catch (PDOException $e) {
    $steps[] = "❌ rh_entretien_questions_user : " . $e->getMessage();
}

// 3. ALTER rh_entretien_question_options
try {
    $pdo->exec("ALTER TABLE rh_entretien_question_options
        ADD COLUMN IF NOT EXISTS societe_id INT NULL AFTER actif,
        ADD COLUMN IF NOT EXISTS is_suggestion TINYINT(1) NOT NULL DEFAULT 0 AFTER societe_id");
    $steps[] = "✅ rh_entretien_question_options : colonnes societe_id + is_suggestion ajoutées";
} catch (PDOException $e) {
    $steps[] = "❌ rh_entretien_question_options : " . $e->getMessage();
}

// 4. CREATE rh_entretien_societe_rubriques
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_entretien_societe_rubriques (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        rubrique_id INT NOT NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        ordre INT NULL,
        created_at DATETIME DEFAULT NOW(),
        UNIQUE KEY uq_soc_rub (societe_id, rubrique_id)
    )");
    $steps[] = "✅ rh_entretien_societe_rubriques : table créée";
} catch (PDOException $e) {
    $steps[] = "❌ rh_entretien_societe_rubriques : " . $e->getMessage();
}

// 5. CREATE rh_entretien_societe_questions
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_entretien_societe_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        question_id INT NOT NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        ordre INT NULL,
        created_at DATETIME DEFAULT NOW(),
        UNIQUE KEY uq_soc_q (societe_id, question_id)
    )");
    $steps[] = "✅ rh_entretien_societe_questions : table créée";
} catch (PDOException $e) {
    $steps[] = "❌ rh_entretien_societe_questions : " . $e->getMessage();
}

// 6. CREATE rh_entretien_societe_options
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_entretien_societe_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        societe_id INT NOT NULL,
        option_id INT NOT NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT NOW(),
        UNIQUE KEY uq_soc_opt (societe_id, option_id)
    )");
    $steps[] = "✅ rh_entretien_societe_options : table créée";
} catch (PDOException $e) {
    $steps[] = "❌ rh_entretien_societe_options : " . $e->getMessage();
}

$isCli = PHP_SAPI === 'cli';
foreach ($steps as $s) {
    if ($isCli) {
        echo $s . "\n";
    } else {
        echo nl2br(htmlspecialchars($s)) . "<br>\n";
    }
}
if ($isCli) {
    echo "\nMigration terminée.\n";
} else {
    echo "<br><strong>Migration terminée.</strong>\n";
}
