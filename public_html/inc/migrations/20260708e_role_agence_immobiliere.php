<?php
/**
 * Migration : rôle "agence_immobiliere" (agence partenaire créée en tiers pour un co-mandat)
 *  + colonne mandats.id_tiers_mandataire (partenaire EXTERNE non-MBI = tiers).
 */
return [
    'id'          => '20260708e_role_agence_immobiliere',
    'title'       => 'Tiers/Mandats : rôle agence_immobiliere + partenaire externe sur mandat',
    'description' => "Ajoute le rôle 'agence_immobiliere' (tiers) et mandats.id_tiers_mandataire pour un co-mandat avec une agence externe.",
    'created_at'  => '2026-07-08',
    'sql' => <<<'SQL'
INSERT INTO `tiers_roles_codes` (`code`,`libelle`,`categorie`,`description`,`objet_type_defaut`,`actif`,`ordre_affichage`)
VALUES ('agence_immobiliere','Agence immobilière','acteur_immo','Agence immobilière partenaire (co-mandat)','global',1,205)
ON DUPLICATE KEY UPDATE `libelle`=VALUES(`libelle`),`actif`=1;
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `id_tiers_mandataire` INT(11) NULL AFTER `id_agence_collaborateur`;
SQL
];
