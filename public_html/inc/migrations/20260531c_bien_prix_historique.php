<?php
/**
 * Migration : table bien_prix — source de vérité + historique du prix/loyer.
 *
 * Le prix de vente / loyer d'un bien vit désormais dans bien_prix :
 *   - chaque VALIDATION crée une ligne (historique daté, qui/quand/source) ;
 *   - is_courant=1 marque la valeur en vigueur (une seule par bien+type) ;
 *   - les colonnes biens.prix_demande_initial / loyer_hc et annonces.prix /
 *     prix_net_vendeur / loyer sont des MIROIRS alimentés par le helper
 *     inc/bien_prix.php (jamais écrits ailleurs) → Ubiflow et le tableau
 *     Transaction restent alimentés sans modification de leur code.
 *
 * Tester un prix dans le simulateur n'écrit rien : seule la validation historise.
 * Statements additifs / rejouables (IF NOT EXISTS + NOT EXISTS sur le backfill).
 */

return [
    'id'          => '20260531c_bien_prix_historique',
    'title'       => 'Bien : table bien_prix (prix/loyer validés + historique)',
    'description' => "Crée bien_prix (autorité + historique des prix/loyers validés, is_courant). Reprend les prix/loyers actuels des biens en is_courant=1.",
    'created_at'  => '2026-05-31',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `bien_prix` (
    `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_bien`         INT(10) UNSIGNED NOT NULL,
    `type_valeur`     ENUM('prix_vente','prix_net_vendeur','loyer') NOT NULL DEFAULT 'prix_vente',
    `montant`         DECIMAL(14,2) NOT NULL,
    `source`          VARCHAR(30) NULL,
    `id_user`         INT(10) UNSIGNED NULL,
    `commentaire`     VARCHAR(255) NULL,
    `is_courant`      TINYINT(1) NOT NULL DEFAULT 1,
    `date_validation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_courant` (`id_bien`,`type_valeur`,`is_courant`),
    KEY `idx_hist` (`id_bien`,`type_valeur`,`date_validation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `bien_prix` (`id_bien`,`type_valeur`,`montant`,`source`,`is_courant`,`date_validation`)
SELECT b.`id`, 'prix_vente', b.`prix_demande_initial`, 'import', 1, COALESCE(b.`date_modification`, NOW())
FROM `biens` b
WHERE b.`prix_demande_initial` > 0
  AND NOT EXISTS (SELECT 1 FROM `bien_prix` bp WHERE bp.`id_bien`=b.`id` AND bp.`type_valeur`='prix_vente');

INSERT INTO `bien_prix` (`id_bien`,`type_valeur`,`montant`,`source`,`is_courant`,`date_validation`)
SELECT b.`id`, 'loyer', b.`loyer_hc`, 'import', 1, COALESCE(b.`date_modification`, NOW())
FROM `biens` b
WHERE b.`loyer_hc` > 0
  AND NOT EXISTS (SELECT 1 FROM `bien_prix` bp WHERE bp.`id_bien`=b.`id` AND bp.`type_valeur`='loyer');
SQL,
];
