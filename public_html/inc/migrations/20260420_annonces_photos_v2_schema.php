<?php
/**
 * Migration : annonces_photos → schéma V2 (liaison N:N vers biens_photos)
 *
 * Contexte
 * --------
 * La migration `20260418_express_flow` prévoyait déjà une nouvelle
 * `annonces_photos` avec `id_biens_photo` (liaison N:N vers biens_photos),
 * mais son `CREATE TABLE IF NOT EXISTS` n'a rien fait puisque la table
 * existait déjà sur le serveur avec l'ANCIEN schéma (copies physiques
 * url_photo / url_webp / variante / principale / ordre_affichage).
 *
 * Résultat : tous les endpoints V2 (annonce_photo_toggle, bulk, reorder,
 * create, bien_intake_photo_upload auto-link) échouaient silencieusement
 * sur les INSERT avec la colonne `id_biens_photo` inexistante. D'où le
 * bug : photos du bien ne remontent jamais dans la Card Photos de
 * l'annonce V2.
 *
 * Ce que fait cette migration
 * ---------------------------
 *   1. RENAME l'ancienne table en `annonces_photos_legacy_20260420`
 *      (backup complet, aucune perte de donnée)
 *   2. CREATE la nouvelle `annonces_photos` avec le schéma N:N attendu
 *      par le code V2.
 *
 * Rejeu
 * -----
 * Idempotente depuis 2026-04-30 : le RENAME est gardé par information_schema
 * (ne se déclenche que si l'ancienne table existe encore et que la cible legacy
 * n'existe pas). Le CREATE TABLE IF NOT EXISTS est nativement idempotent.
 * La migration peut être rejouée sans risque ni statut "partielle".
 *
 * Code touché côté PHP (déjà commit d3a695a) :
 *   - config/ubiflow_mapping.php : ubiflow_get_photos utilise un JOIN
 *     annonces_photos.id_biens_photo = biens_photos.id
 *   - api/annonce_photo_upload.php, annonce_photo_delete.php : 410 Gone
 *   - annonce_nouvelle.php : 301 redirect vers bien_detail_v2
 *   - api/flux/mapping_maboximmo_ubiflow.json : source mise à jour
 */

return [
    'id'          => '20260420_annonces_photos_v2_schema',
    'title'       => 'Annonces photos : bascule schéma V2 (liaison N:N vers biens_photos)',
    'description' => "Renomme l'ancienne `annonces_photos` (copies physiques) en backup `annonces_photos_legacy_20260420` et crée la nouvelle table de liaison N:N (id_annonce, id_biens_photo, ordre) attendue par bien_detail_v2. Corrige le bug où les photos d'un bien ne remontaient jamais dans la Card Photos de l'annonce.",
    'created_at'  => '2026-04-20',
    'sql' => <<<'SQL'
-- ────────────────────────────────────────────────────────────────────
-- 1. RENAME idempotent : seulement si la source existe encore en BASE TABLE
--    (la nouvelle annonces_photos peut exister mais elle est différente :
--    si la legacy existe déjà → on n'a rien à renommer).
-- ────────────────────────────────────────────────────────────────────
SET @old_exists := (SELECT COUNT(*) FROM `information_schema`.`TABLES`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'annonces_photos' AND `TABLE_TYPE` = 'BASE TABLE');

SET @legacy_exists := (SELECT COUNT(*) FROM `information_schema`.`TABLES`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'annonces_photos_legacy_20260420' AND `TABLE_TYPE` = 'BASE TABLE');

-- Heuristique : on renomme si la legacy n'existe pas (1er run). Si elle existe,
-- la migration a déjà eu lieu — on no-op.
SET @do_rename := (@old_exists = 1 AND @legacy_exists = 0);

SET @sql := IF(@do_rename = 1,
  'RENAME TABLE `annonces_photos` TO `annonces_photos_legacy_20260420`',
  'DO 1');
PREPARE _mig_aphv2 FROM @sql;
EXECUTE _mig_aphv2;
DEALLOCATE PREPARE _mig_aphv2;

-- ────────────────────────────────────────────────────────────────────
-- 2. CREATE nouvelle annonces_photos (idempotent natif)
-- ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `annonces_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annonce` INT UNSIGNED NOT NULL,
  `id_biens_photo` INT UNSIGNED NOT NULL,
  `ordre` INT NOT NULL DEFAULT 0,
  `alt_text` VARCHAR(255) NULL COMMENT 'Override SEO de description_ia pour cette annonce',
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annonce_photo` (`id_annonce`, `id_biens_photo`),
  KEY `idx_annonce_ordre` (`id_annonce`, `ordre`),
  KEY `idx_biens_photo` (`id_biens_photo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sélection/ordre des photos du bien pour chaque annonce (modèle V2)';
SQL,
];
