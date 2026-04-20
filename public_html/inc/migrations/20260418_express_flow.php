<?php
/**
 * Migration : Express flow — patterns référence, colonnes annonces, annonces_photos
 *
 * Chaque migration est un fichier autonome dans inc/migrations/ renvoyant un array :
 *   id          : identifiant unique (préfixé par la date YYYYMMDD_)
 *   title       : titre court
 *   description : contexte (facultatif)
 *   created_at  : date de création
 *   sql         : contenu SQL (statements séparés par « ; »)
 *
 * Règle d'or : statements additifs uniquement (IF NOT EXISTS sur ADD COLUMN / CREATE TABLE).
 * Pas de DROP ni de rename destructif.
 */

return [
    'id'          => '20260418_express_flow',
    'title'       => 'Express flow — refs + annonces enrichies + photos sélection',
    'description' => "Patterns référence multi-société/agence, colonnes SEO/LBC sur annonces, table annonces_photos, slug et commercial courant sur biens.",
    'created_at'  => '2026-04-18',
    'sql' => <<<'SQL'
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `ref_pattern_bien` VARCHAR(200) NOT NULL
    DEFAULT '{TYPE3}-{VILLE3}-{YY}-{SEQ:04}-{USER3}',
  ADD COLUMN IF NOT EXISTS `ref_pattern_annonce` VARCHAR(200) NOT NULL
    DEFAULT '{BIEN_REF}-{TRANS3}-{ANN_SEQ:02}',
  ADD COLUMN IF NOT EXISTS `ref_annual_reset` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `ref_pattern_bien` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `ref_pattern_annonce` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `ref_seq_bien_current` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ref_seq_annonce_current` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ref_seq_year` INT UNSIGNED NULL;

ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `id_user_actuel` INT UNSIGNED NULL;

ALTER TABLE `biens`
  ADD INDEX IF NOT EXISTS `idx_biens_slug` (`slug`),
  ADD INDEX IF NOT EXISTS `idx_biens_user_actuel` (`id_user_actuel`);

ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `reference_annonce` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `titre_seo` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `titre_lbc` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `meta_description` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `mots_cles` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `h1_public` VARCHAR(150) NULL;

ALTER TABLE `annonces`
  ADD INDEX IF NOT EXISTS `idx_annonces_ref` (`reference_annonce`),
  ADD INDEX IF NOT EXISTS `idx_annonces_slug` (`slug`);

CREATE TABLE IF NOT EXISTS `annonces_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annonce` INT UNSIGNED NOT NULL,
  `id_biens_photo` INT UNSIGNED NOT NULL,
  `ordre` INT NOT NULL DEFAULT 0,
  `alt_text` VARCHAR(255) NULL,
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annonce_photo` (`id_annonce`, `id_biens_photo`),
  KEY `idx_annonce_ordre` (`id_annonce`, `ordre`),
  KEY `idx_biens_photo` (`id_biens_photo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
