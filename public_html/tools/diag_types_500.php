<?php
declare(strict_types=1);
/**
 * tools/diag_types_500.php — Diagnostic ciblé des 500 sur arbitrage_biens.php / plan_tresorerie.php.
 * Exécute la requête biens de chaque page (WHERE neutre) et affiche l'ERREUR SQL EXACTE.
 * Lecture seule, admin only. À supprimer après diagnostic.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_admin_or_super_admin();
header('Content-Type: text/plain; charset=utf-8');
$pdo = $GLOBALS['pdo'];

function diag(PDO $pdo, string $label, string $sql, array $params): void {
    echo "=== $label ===\n";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo "OK — " . count($rows) . " lignes. Exemple type: " . ($rows[0]['type_libelle'] ?? '(n/a)') . "\n\n";
    } catch (Throwable $e) {
        echo "❌ ERREUR : " . $e->getMessage() . "\n\n";
    }
}

// Requête d'arbitrage_biens.php (WHERE neutre pour isoler la structure).
$sqlArb = "
    SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.ville,
        b.surface_habitable, b.numero_lot, b.statut_occupation, b.prix_vente_estime, b.loyer_hc,
        COALESCE(bt.libelle, tb2.libelle) AS type_libelle,
        a.decision, a.posture, a.statut_locatif, a.vacance_debut, a.vacance_mois,
        a.loyer_actuel_mensuel, a.loyer_potentiel_mensuel,
        a.taxe_fonciere, a.charges_non_recup, a.assurance, a.entretien, a.frais_gestion, a.autres_couts_annuels,
        a.travaux_niveau, a.travaux_1an, a.travaux_3ans, a.travaux_5ans,
        a.prix_estime, a.prix_vente_realiste, a.frais_agence, a.frais_notaire, a.cout_acte_en_main,
        a.delai_vente_mois, a.liquidite_niveau, a.risque_niveau
    FROM biens b
    LEFT JOIN bien_types bt  ON bt.id  = b.id_bien_type
    LEFT JOIN types_bien tb2 ON tb2.id = b.id_type_bien
    LEFT JOIN arbitrage_biens a ON a.id_bien = b.id AND a.id_societe = ?
    WHERE 1=1
    ORDER BY b.ville ASC LIMIT 5";
diag($pdo, 'arbitrage_biens.php (requête)', $sqlArb, [1]);

// Existence + colonnes de la table arbitrage_biens (souvent la cause).
echo "=== SHOW COLUMNS arbitrage_biens ===\n";
try {
    foreach ($pdo->query("SHOW COLUMNS FROM arbitrage_biens") as $c) echo $c['Field'] . ' ';
    echo "\n\n";
} catch (Throwable $e) { echo "❌ " . $e->getMessage() . "\n\n"; }

echo "Fin du diagnostic. Supprime ce fichier après usage.\n";
