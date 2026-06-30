<?php
declare(strict_types=1);

/**
 * Migration one-shot : ajoute 3 colonnes ALUR copropriété sur `immeubles`.
 * Ouvrir dans le navigateur UNE fois après déploiement. Idempotent.
 * À supprimer une fois exécuté avec succès.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

// Garde : super-admin uniquement
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
    'copro_procedure'                  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Syndic en procédure (ALUR)'",
    'alur_copropriete_plan_sauvegarde' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Plan de sauvegarde (ALUR)'",
    'alur_copropriete_etat_carence'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'État de carence (ALUR)'",
];

echo "=== Migration immeubles ALUR copropriété ===\n\n";

foreach ($cols as $col => $def) {
    if (colExists($pdo, 'immeubles', $col)) {
        echo "[SKIP] immeubles.$col existe déjà.\n";
    } else {
        $pdo->exec("ALTER TABLE `immeubles` ADD COLUMN `$col` $def");
        echo "[OK]   Ajouté immeubles.$col\n";
    }
}

echo "\n=== Terminé. Tu peux supprimer ce fichier. ===\n";
