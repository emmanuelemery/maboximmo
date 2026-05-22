<?php
/**
 * Migration : bien_baux — Refonte ASSURANCE détaillée
 *
 * L'ancien champ `renonciation_recours_reciproque` était trop simpliste : il ne
 * distinguait pas une renonciation UNILATÉRALE (un sens) d'une RÉCIPROQUE (2 sens).
 *
 * On AJOUTE 5 nouveaux champs assurance + on conserve l'ancien pour rétrocompat.
 * Les caractéristiques du BIEN extraites par l'IA (lot, tantièmes, parkings,
 * surfaces, descriptif) sont propagées vers la table `biens` par
 * transaction_bail_save.php — pas de nouvelle colonne nécessaire côté `biens`
 * (les colonnes existent déjà : numero_lot, copro_quote_part_charges, parking_nb,
 * surface_habitable, etc.).
 *
 * Compatibilité : MariaDB 10.0.2+ / MySQL 8.0.29+.
 */

return [
    'id'          => '20260518_bail_assurance_et_descriptif',
    'title'       => 'bien_baux — Assurance détaillée (renonciation L/B, surprimes, risques)',
    'description' => "Ajout de 5 colonnes à bien_baux pour la clause assurance : renonciation_recours_locataire et _bailleur (unilatérale/réciproque), assurance_surprimes_a_charge (qui paie si activité génère surprimes), assurance_justification_annuelle (obligation preuve annuelle), assurance_risques_couverts (JSON liste). L'ancien champ renonciation_recours_reciproque reste pour rétrocompat.",
    'created_at'  => '2026-05-18',
    'sql'         => <<<'SQL'

ALTER TABLE `bien_baux`
    ADD COLUMN IF NOT EXISTS `renonciation_recours_locataire` TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Le locataire renonce à se retourner contre le bailleur en cas de sinistre. 0=non, 1=oui, NULL=non précisé.'
        AFTER `renonciation_recours_reciproque`,
    ADD COLUMN IF NOT EXISTS `renonciation_recours_bailleur` TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Le bailleur renonce à se retourner contre le locataire en cas de sinistre. Si les 2 sont à 1 = renonciation réciproque.'
        AFTER `renonciation_recours_locataire`,
    ADD COLUMN IF NOT EXISTS `assurance_surprimes_a_charge` VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'locataire|bailleur|partage : qui paie si activité génère surprimes'
        AFTER `renonciation_recours_bailleur`,
    ADD COLUMN IF NOT EXISTS `assurance_justification_annuelle` TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Le bail impose au locataire de justifier annuellement de son assurance'
        AFTER `assurance_surprimes_a_charge`,
    ADD COLUMN IF NOT EXISTS `assurance_risques_couverts` JSON NULL DEFAULT NULL
        COMMENT 'Liste des risques OBLIGATOIREMENT couverts (incendie, risques_locatifs, recours_voisins, degats_eaux, etc.)'
        AFTER `assurance_justification_annuelle`;

SQL
];
