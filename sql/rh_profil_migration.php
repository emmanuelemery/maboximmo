<?php
require_once __DIR__ . '/../public_html/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

$alters = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS date_naissance DATE NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS lieu_naissance VARCHAR(100) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS nationalite VARCHAR(60) NULL DEFAULT 'Française'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS num_secu VARCHAR(20) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS adresse VARCHAR(255) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS adresse2 VARCHAR(255) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS code_postal VARCHAR(10) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS ville VARCHAR(100) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS pays VARCHAR(60) NULL DEFAULT 'France'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS date_entree DATE NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS date_sortie DATE NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS type_contrat ENUM('CDI','CDD','Alternance','Stage','Freelance','Autre') NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS temps_travail ENUM('Temps plein','Temps partiel') NULL DEFAULT 'Temps plein'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS taux_horaire DECIMAL(8,2) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS iban VARCHAR(34) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS bic VARCHAR(11) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS permis_conduire VARCHAR(20) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS vehicule_immat VARCHAR(20) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_urgence_nom VARCHAR(150) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_urgence_tel VARCHAR(30) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS photo_url VARCHAR(255) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notes_rh TEXT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS civilite ENUM('M.','Mme','Autre') NULL",
];

foreach ($alters as $sql) {
    try { $pdo->exec($sql); echo "OK " . substr($sql, 30, 70) . "\n"; }
    catch (PDOException $e) { echo "ERR " . $e->getMessage() . "\n"; }
}
echo "Migration terminée.\n";
