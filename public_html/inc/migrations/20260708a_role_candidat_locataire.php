<?php
/**
 * Migration : rôle « candidat_locataire » (candidat provisoire, avant signature du bail).
 *
 * tiers_roles.role_code a une FK vers tiers_roles_codes.code → il faut enregistrer le code.
 * Le candidat locataire d'un projet de bail porte ce rôle (statut provisoire / RGPD) ; il devient
 * `locataire` à la clôture du bail signé, et redevient candidat si le bail est dévalidé.
 *
 * Règle d'or : ADDITIF + idempotent (INSERT … ON DUPLICATE).
 */
return [
    'id'          => '20260708a_role_candidat_locataire',
    'title'       => 'Tiers : rôle candidat_locataire (candidat provisoire)',
    'description' => "Ajoute le code de rôle 'candidat_locataire' dans tiers_roles_codes (candidat locataire provisoire d'un projet de bail).",
    'created_at'  => '2026-07-08',
    'sql' => <<<'SQL'
INSERT INTO `tiers_roles_codes` (`code`, `libelle`, `categorie`, `description`, `objet_type_defaut`, `actif`, `ordre_affichage`)
VALUES ('candidat_locataire', 'Candidat locataire', 'acteur_immo', 'Candidat locataire d''un projet de bail (provisoire, avant signature)', 'bail', 1, 85)
ON DUPLICATE KEY UPDATE `libelle`=VALUES(`libelle`), `categorie`=VALUES(`categorie`), `description`=VALUES(`description`), `objet_type_defaut`=VALUES(`objet_type_defaut`), `actif`=1;
SQL
];
