<?php
require_once __DIR__ . '/../public_html/inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

// Table for user documents
$pdo->exec("CREATE TABLE IF NOT EXISTS rh_user_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    categorie VARCHAR(40) NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,
    nom_original VARCHAR(255) NOT NULL,
    taille INT NULL,
    mime_type VARCHAR(100) NULL,
    uploaded_by INT NOT NULL,
    created_at DATETIME DEFAULT NOW(),
    INDEX idx_user_cat (user_id, categorie)
)");
echo "✅ Table rh_user_documents créée\n";

// Add indemnite_km field to users
$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS indemnite_km DECIMAL(6,4) NULL COMMENT 'Taux IK en €/km'");
echo "✅ Colonne indemnite_km ajoutée\n";
