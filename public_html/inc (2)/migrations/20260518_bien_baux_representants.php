<?php
/**
 * Migration : bien_baux — Représentants + Assurance + Conditions particulières
 *
 * Enrichit bien_baux pour stocker les éléments extraits par l'IA Vision sur
 * les baux commerciaux/habitation/professionnels :
 *   - Représentant légal du bailleur + contact
 *   - Représentant légal du locataire + contact
 *   - Clause de renonciation à recours réciproque (assurance)
 *   - Conditions particulières (texte libre intégral)
 *
 * Compatibilité : MariaDB 10.0.2+ / MySQL 8.0.29+ (ADD COLUMN IF NOT EXISTS).
 */

return [
    'id'          => '20260518_bien_baux_representants',
    'title'       => 'bien_baux — Représentants bailleur/locataire + renonciation recours + conditions particulières',
    'description' => "Ajout de 10 colonnes à bien_baux : 4 pour le représentant bailleur (nom, qualité, email, tél), 4 pour le représentant locataire, 1 pour la renonciation à recours réciproque (assurance), 1 pour le texte intégral des conditions particulières. Tous champs alimentés automatiquement par l'extraction IA Vision Claude.",
    'created_at'  => '2026-05-18',
    'sql'         => <<<'SQL'

ALTER TABLE `bien_baux`
    ADD COLUMN IF NOT EXISTS `bailleur_representant_nom`        VARCHAR(150) NULL COMMENT 'Représentant légal du bailleur' AFTER `id_proprietaire`,
    ADD COLUMN IF NOT EXISTS `bailleur_representant_qualite`    VARCHAR(80)  NULL AFTER `bailleur_representant_nom`,
    ADD COLUMN IF NOT EXISTS `bailleur_representant_email`      VARCHAR(190) NULL AFTER `bailleur_representant_qualite`,
    ADD COLUMN IF NOT EXISTS `bailleur_representant_telephone`  VARCHAR(30)  NULL AFTER `bailleur_representant_email`,
    ADD COLUMN IF NOT EXISTS `locataire_representant_nom`       VARCHAR(150) NULL COMMENT 'Représentant légal du locataire' AFTER `locataire_telephone`,
    ADD COLUMN IF NOT EXISTS `locataire_representant_qualite`   VARCHAR(80)  NULL AFTER `locataire_representant_nom`,
    ADD COLUMN IF NOT EXISTS `locataire_representant_email`     VARCHAR(190) NULL AFTER `locataire_representant_qualite`,
    ADD COLUMN IF NOT EXISTS `locataire_representant_telephone` VARCHAR(30)  NULL AFTER `locataire_representant_email`,
    ADD COLUMN IF NOT EXISTS `renonciation_recours_reciproque`  TINYINT(1)   NULL DEFAULT NULL COMMENT '0=non, 1=oui, NULL=non précisé' AFTER `clause_resolutoire`,
    ADD COLUMN IF NOT EXISTS `conditions_particulieres`         TEXT         NULL COMMENT 'Conditions particulières du bail' AFTER `metadata`;

SQL
];
