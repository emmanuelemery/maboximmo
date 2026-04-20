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
 * Au 2e run, le RENAME plantera (source inexistante) mais le runner
 * continue au statement suivant. Le CREATE TABLE IF NOT EXISTS est
 * idempotent. La migration sera marquée "partielle" (1 OK · 1 ERR) ;
 * c'est normal et inoffensif.
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
RENAME TABLE `annonces_photos` TO `annonces_photos_legacy_20260420`;

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
