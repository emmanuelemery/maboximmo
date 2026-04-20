-- ─────────────────────────────────────────────────────────────
-- Migration : annonces_photos → schéma V2 (N:N vers biens_photos)
-- Date      : 2026-04-20
-- Auteur    : Refonte bien_detail_v2
--
-- Contexte
-- --------
-- L'ancienne table `annonces_photos` stockait des COPIES PHYSIQUES des
-- photos (url_photo, url_webp, variante thumb/medium/large, nom SEO).
-- La V2 de bien_detail utilise un modèle de LIAISON N:N : les photos
-- vivent dans `biens_photos` (source unique), et `annonces_photos` est
-- juste une table de sélection/ordre (id_annonce, id_biens_photo, ordre).
--
-- La migration `migration_express_flow_2026-04-18.sql` prévoyait déjà
-- cette nouvelle table avec CREATE TABLE IF NOT EXISTS, mais comme
-- l'ancienne table existait, le CREATE n'a rien fait. Résultat : tout
-- le code V2 (annonce_photo_toggle, bulk, reorder, create, intake auto-
-- link, bien_detail_v2.php) échoue silencieusement sur un INSERT avec
-- la colonne `id_biens_photo` inexistante.
--
-- Ce qu'on fait
-- -------------
-- 1. On RENOMME l'ancienne table en `annonces_photos_legacy_20260420`
--    (zéro perte — c'est un simple rename, on peut revenir en arrière)
-- 2. On CRÉE la nouvelle `annonces_photos` avec le schéma N:N
-- 3. Les fichiers physiques sur disque restent intacts dans
--    uploads/biens/<societe>/<bien>/ — la refonte V2 n'en duplique pas
-- 4. Le code `config/ubiflow_mapping.php` + `api/flux/*` sont adaptés
--    pour JOIN `annonces_photos → biens_photos` (commit séparé)
--
-- Risque
-- ------
-- Perte des métadonnées par-annonce sauvegardées dans l'ancienne table
-- (alt_photo, caption, titre, ordre_affichage, principale). Sur dev
-- ces colonnes sont quasi-vides. La legacy table étant conservée, on
-- peut ré-importer ces métadonnées plus tard si besoin.
--
-- Rollback
-- --------
-- DROP TABLE annonces_photos;
-- RENAME TABLE annonces_photos_legacy_20260420 TO annonces_photos;
-- (et revert le commit code de ubiflow_mapping)
-- ─────────────────────────────────────────────────────────────

-- 0. Vérification pré-migration (à exécuter manuellement, non bloquante)
-- SHOW CREATE TABLE annonces_photos;
-- SELECT COUNT(*) AS nb_lignes_legacy FROM annonces_photos;

-- 1. Backup : renomme l'ancienne table
RENAME TABLE `annonces_photos` TO `annonces_photos_legacy_20260420`;

-- 2. Crée la nouvelle table N:N (alignée sur le code V2)
CREATE TABLE `annonces_photos` (
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
  COMMENT='Sélection/ordre des photos du bien (biens_photos) pour chaque annonce';

-- 3. Vérifications post-migration (à exécuter manuellement)
-- DESCRIBE annonces_photos;
-- SELECT COUNT(*) AS nb_lignes_nouvelle FROM annonces_photos; -- = 0 attendu
-- SELECT COUNT(*) AS nb_lignes_legacy_preservee FROM annonces_photos_legacy_20260420;
