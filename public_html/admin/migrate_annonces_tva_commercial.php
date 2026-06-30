<?php
declare(strict_types=1);

/**
 * Migration one-shot : ajoute 5 colonnes TVA sur `annonces` pour les baux commerciaux/pro.
 * Ouvrir dans le navigateur UNE fois après déploiement. Idempotent.
 * À supprimer une fois exécuté avec succès.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès refusé (super-admin uniquement).');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = $GLOBALS['pdo'];

function colExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

$cols = [
    'tva_assujetti'     => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Annonce assujettie TVA 20%'",
    'loyer_hc_ht'       => "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Loyer HC HT (TVA)'",
    'charges_ht'        => "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Charges HT (TVA)'",
    'tva_montant'       => "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Montant TVA calculé'",
    'periodicite_loyer' => "VARCHAR(20) NULL DEFAULT 'mensuel' COMMENT 'mensuel | trimestriel'",
];

echo "=== Migration annonces TVA commercial ===\n\n";

foreach ($cols as $col => $def) {
    if (colExists($pdo, 'annonces', $col)) {
        echo "[SKIP] annonces.$col existe déjà.\n";
    } else {
        $pdo->exec("ALTER TABLE `annonces` ADD COLUMN `$col` $def");
        echo "[OK]   Ajouté annonces.$col\n";
    }
}

echo "\n=== Terminé. Tu peux supprimer ce fichier. ===\n";
