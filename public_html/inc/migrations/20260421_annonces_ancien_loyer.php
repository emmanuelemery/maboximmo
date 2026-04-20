<?php
/**
 * Migration : ancien loyer pratiqué + table annonces_complement_loyer_lignes
 *
 * Contexte
 * --------
 * Obligation Loi Alur en zone d'encadrement des loyers : le bailleur doit
 * communiquer le dernier loyer du précédent locataire et justifier les
 * éventuels compléments de loyer.
 *
 * Bug corrigé
 * -----------
 * La table `annonces_complement_loyer_lignes` avait été créée à la main sur
 * la base dev (via `sql/deploy_1_code/sql/migration_ancien_loyer.sql`) mais
 * jamais intégrée dans le système de migrations `admin_migrations.php`.
 * Sur la prod, la table n'existe pas → `bien_detail.php` section « annonce »
 * crashe sur le SELECT ligne 198, l'exception est attrapée silencieusement
 * et la page affiche à tort « Aucune photo chargée » alors que les photos
 * sont bien présentes dans `biens_photos`.
 *
 * Cette migration aligne la BDD prod avec la BDD dev.
 */

return [
    'id'          => '20260421_annonces_ancien_loyer',
    'title'       => 'Annonces : colonnes ancien loyer + table complement_loyer_lignes (Loi Alur)',
    'description' => "Ajoute les colonnes ancien_loyer_* sur `annonces` et crée la table `annonces_complement_loyer_lignes` pour les justifications Loi Alur. Sans cette table, la page bien_detail.php onglet Annonce plante silencieusement et masque les photos.",
    'created_at'  => '2026-04-21',
    'sql' => <<<'SQL'
ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `ancien_loyer_montant` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Dernier loyer mensuel HC du précédent locataire',
  ADD COLUMN IF NOT EXISTS `ancien_loyer_charges` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Charges mensuelles du précédent locataire',
  ADD COLUMN IF NOT EXISTS `ancien_loyer_date_revision` DATE DEFAULT NULL
    COMMENT 'Date de la dernière révision du loyer',
  ADD COLUMN IF NOT EXISTS `ancien_locataire_date_sortie` DATE DEFAULT NULL
    COMMENT 'Date de sortie du précédent locataire',
  ADD COLUMN IF NOT EXISTS `ancien_loyer_communique` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Le bailleur souhaite communiquer ces infos (obligatoire en zone encadrement)';

CREATE TABLE IF NOT EXISTS `annonces_complement_loyer_lignes` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_annonce`    INT UNSIGNED NOT NULL,
    `libelle`       VARCHAR(255) NOT NULL COMMENT 'Justification (ex: vue exceptionnelle, terrasse plein sud…)',
    `montant`       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Part mensuelle correspondante',
    `ordre`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_annonce` (`id_annonce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lignes de justification du complément de loyer (obligation Loi Alur)';
SQL,
];
