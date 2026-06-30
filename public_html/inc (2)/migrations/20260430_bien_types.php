<?php
/**
 * Migration : nouvelle table référentielle `bien_types`
 *
 * CONTEXTE
 *   Avant cette migration, 3 tables coexistaient pour les types de bien :
 *     - types_bien        (13 entrées) → FK biens.id_type_bien
 *     - base_types_bien   (15 entrées) → lue par bien_detail / autosave
 *     - societe_types_bien (75 entrées, par société) → admin/param_types_bien
 *   IDs incohérents entre les 3 → ~30 % des biens diffusés avec un mauvais
 *   t_code, ce qui faisait skipper l'annonce dans le flux Ubiflow / LBC.
 *
 * OBJECTIF
 *   Référentiel UNIQUE `bien_types` aligné sur la granularité Ubiflow (LBC),
 *   avec colonnes ouvertes pour SeLoger (CSV Poliris) et FNAIM (XML IRIS) +
 *   évolutions futures (autres passerelles).
 *
 *   27 entrées regroupées par code_type_ubiflow :
 *     1100 Appartement     : appartement, loft, duplex, triplex, studio
 *     1200 Maison          : maison, villa, chateau, chalet, ferme, mas, peniche
 *     1300 Terrain         : terrain, terrain_agricole
 *     1500 Immeuble        : immeuble
 *     2000 Fonds/Bail      : fonds_commerce, droit_bail
 *     2100 Entrepôt        : entrepot
 *     2200 Local activité  : local_activite, atelier
 *     2300 Local commerce  : local_commercial, boutique
 *     2400 Bureau          : bureau
 *     3100 Parking         : parking
 *     3200 Garage/Box      : garage, box
 *     3300 Cave            : cave
 *
 *   `programme_neuf` est volontairement retiré (pas de code Ubiflow valide,
 *   cœur du bug actuel — les biens existants en `programme_neuf` sont
 *   reclassés sur `appartement` lors du backfill).
 *
 * STRATÉGIE NON-DESTRUCTIVE / ROLLBACK
 *   - L'ancienne table `types_bien` est RENOMMÉE en `types_bien_legacy`
 *     (la FK fk_biens_type suit automatiquement le rename MySQL)
 *   - `base_types_bien` et `societe_types_bien` ne sont PAS touchées
 *   - `biens.id_type_bien` reste alimentée (cible : types_bien_legacy.id)
 *     pour permettre un rollback du code sans perte de donnée
 *   - Une nouvelle colonne `biens.id_bien_type` est ajoutée + backfillée
 *
 * IDEMPOTENCE
 *   - CREATE TABLE IF NOT EXISTS pour bien_types
 *   - INSERT IGNORE pour les 27 lignes seed
 *   - ALTER TABLE … ADD COLUMN IF NOT EXISTS pour biens.id_bien_type
 *   - UPDATE conditionné par WHERE id_bien_type IS NULL
 *   - RENAME conditionné par PREPARE/EXECUTE sur information_schema
 *   → Migration rejouable sans casse.
 */

return [
    'id'          => '20260430_bien_types',
    'title'       => 'Référentiel unique bien_types (LBC + SeLoger + FNAIM) + rename types_bien legacy',
    'description' => "Crée la table bien_types (27 codes alignés Ubiflow/LBC), backfille biens.id_bien_type, renomme types_bien → types_bien_legacy. Non destructive : base_types_bien + societe_types_bien intactes, id_type_bien legacy préservée pour rollback.",
    'created_at'  => '2026-04-30',
    'sql' => <<<'SQL'
-- ────────────────────────────────────────────────────────────────────
-- 1. Création de la nouvelle table bien_types
-- ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bien_types` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`              VARCHAR(40)  NOT NULL COMMENT 'Code interne stable (appartement, maison, ...)',
  `libelle`           VARCHAR(100) NOT NULL COMMENT 'Libellé affiché en UI',
  `categorie`         ENUM('habitation','professionnel','commerce','terrain','stationnement','annexe') NOT NULL,
  `code_type_ubiflow` SMALLINT UNSIGNED NOT NULL COMMENT 'Code type Ubiflow → LBC (1100, 1200, ...)',
  `type_seloger`      VARCHAR(40)  NULL COMMENT 'Type SeLoger (champ 4 CSV Poliris)',
  `sous_type_seloger` VARCHAR(40)  NULL COMMENT 'Sous-type SeLoger (champ 181 CSV Poliris)',
  `balise_fnaim`      VARCHAR(30)  NULL COMMENT 'Balise XML FNAIM IRIS (MAISON, APPARTEMENT, ...)',
  `categorie_fnaim`   SMALLINT UNSIGNED NULL COMMENT '<CATEGORIE> FNAIM (dictionnaire IRIS)',
  `icone`             VARCHAR(60)  NULL COMMENT 'Classe Font Awesome',
  `ordre_affichage`   SMALLINT NOT NULL DEFAULT 0,
  `actif`             TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bien_types_code` (`code`),
  KEY `idx_bien_types_categorie` (`categorie`),
  KEY `idx_bien_types_actif_ordre` (`actif`, `ordre_affichage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────
-- 2. Seed des 27 lignes (INSERT IGNORE pour idempotence)
-- ────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `bien_types`
  (`code`, `libelle`, `categorie`, `code_type_ubiflow`, `type_seloger`, `sous_type_seloger`, `balise_fnaim`, `categorie_fnaim`, `icone`, `ordre_affichage`)
VALUES
  ('appartement',      'Appartement',       'habitation',     1100, 'Appartement',          NULL,                'APPARTEMENT',         1,    'fa-solid fa-building-user',         1),
  ('studio',           'Studio',            'habitation',     1100, 'Appartement',          'Studette',          'APPARTEMENT',         1,    'fa-solid fa-warehouse',             2),
  ('loft',             'Loft',              'habitation',     1100, 'loft/atelier/surface', 'Loft',              'APPARTEMENT',         1,    'fa-solid fa-vector-square',         3),
  ('duplex',           'Duplex',            'habitation',     1100, 'Appartement',          'Duplex',            'APPARTEMENT',         1,    'fa-solid fa-stairs',                4),
  ('triplex',          'Triplex',           'habitation',     1100, 'Appartement',          'Triplex',           'APPARTEMENT',         1,    'fa-solid fa-stairs',                5),
  ('maison',           'Maison',            'habitation',     1200, 'maison/villa',         NULL,                'MAISON',              20,   'fa-solid fa-house',                 6),
  ('villa',            'Villa',             'habitation',     1200, 'maison/villa',         'villa',             'MAISON',              17,   'fa-solid fa-house-chimney-window',  7),
  ('chateau',          'Château',           'habitation',     1200, 'château',              NULL,                'DEMEURE',             21,   'fa-solid fa-chess-rook',            8),
  ('chalet',           'Chalet',            'habitation',     1200, 'maison/villa',         'Chalet',            'MAISON',              11,   'fa-solid fa-mountain',              9),
  ('ferme',            'Ferme',             'habitation',     1200, 'maison/villa',         'ferme',             'MAISON',              10,   'fa-solid fa-tractor',               10),
  ('mas',              'Mas',               'habitation',     1200, 'maison/villa',         'mas',               'MAISON',              18,   'fa-solid fa-sun',                   11),
  ('peniche',          'Péniche',           'habitation',     1200, 'maison/villa',         NULL,                'MAISON',              20,   'fa-solid fa-ship',                  12),
  ('terrain',          'Terrain',           'terrain',        1300, 'terrain',              NULL,                'TERRAIN',             47,   'fa-solid fa-map',                   20),
  ('terrain_agricole', 'Terrain agricole',  'terrain',        1300, 'terrain',              'Terrain agricole',  'TERRAIN',             46,   'fa-solid fa-seedling',              21),
  ('immeuble',         'Immeuble',          'habitation',     1500, 'immeuble',             NULL,                'IMMEUBLE',            39,   'fa-solid fa-building',              30),
  ('fonds_commerce',   'Fonds de commerce', 'commerce',       2000, 'local',                NULL,                'FOND_COMMERCE',       28,   'fa-solid fa-file-signature',        40),
  ('droit_bail',       'Droit au bail',     'commerce',       2000, 'local',                NULL,                'LOCAL_COMMERCIAL',    54,   'fa-solid fa-handshake',             41),
  ('local_commercial', 'Local commercial',  'commerce',       2300, 'boutique',             NULL,                'LOCAL_COMMERCIAL',    54,   'fa-solid fa-store',                 50),
  ('boutique',         'Boutique',          'commerce',       2300, 'boutique',             NULL,                'LOCAL_COMMERCIAL',    54,   'fa-solid fa-shop',                  51),
  ('bureau',           'Bureau',            'professionnel',  2400, 'bureaux',              NULL,                'LOCAL_PROFESSIONNEL', 50,   'fa-solid fa-briefcase',             52),
  ('local_activite',   'Local d''activité', 'professionnel',  2200, 'local',                'Local d''activités','LOCAL_INDUSTRIEL',    52,   'fa-solid fa-industry',              53),
  ('atelier',          'Atelier',           'professionnel',  2200, 'loft/atelier/surface', NULL,                'LOCAL_INDUSTRIEL',    52,   'fa-solid fa-screwdriver-wrench',    54),
  ('entrepot',         'Entrepôt',          'professionnel',  2100, 'local',                'Entrepôt',          'LOCAL_INDUSTRIEL',    53,   'fa-solid fa-warehouse',             55),
  ('parking',          'Parking',           'stationnement',  3100, 'parking/box',          NULL,                'PARKING',             37,   'fa-solid fa-square-parking',        60),
  ('garage',           'Garage',            'stationnement',  3200, 'parking/box',          NULL,                'PARKING',             38,   'fa-solid fa-warehouse',             61),
  ('box',              'Box',               'stationnement',  3200, 'parking/box',          NULL,                'PARKING',             38,   'fa-solid fa-box',                   62),
  ('cave',             'Cave',              'annexe',         3300, 'local',                NULL,                NULL,                  NULL, 'fa-solid fa-wine-bottle',           70);

-- ────────────────────────────────────────────────────────────────────
-- 3. Ajout de la nouvelle colonne biens.id_bien_type (la legacy
--    biens.id_type_bien reste intacte pour rollback)
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `id_bien_type` INT UNSIGNED NULL
    COMMENT 'FK vers bien_types.id — référentiel unifié LBC/SeLoger/FNAIM (la legacy id_type_bien est préservée pour rollback)'
    AFTER `id_type_bien`,
  ADD INDEX IF NOT EXISTS `idx_biens_id_bien_type` (`id_bien_type`);

-- ────────────────────────────────────────────────────────────────────
-- 4. Backfill biens.id_bien_type
--    Stratégie : 3 passes successives, chacune ne remplit que les biens
--    encore NULL (idempotent en cas de rejeu).
-- ────────────────────────────────────────────────────────────────────

-- Passe A : via types_bien si elle existe encore, sinon via types_bien_legacy
--           (PREPARE conditionnel : MySQL résout les noms de tables au parsing,
--           donc on ne peut pas mettre les deux dans un même UPDATE).
SET @passeA_target := (SELECT IF(
  (SELECT COUNT(*) FROM `information_schema`.`TABLES`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'types_bien' AND `TABLE_TYPE` = 'BASE TABLE') = 1,
  'types_bien',
  'types_bien_legacy'
));

SET @passeA_legacy_exists := (SELECT COUNT(*) FROM `information_schema`.`TABLES`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @passeA_target AND `TABLE_TYPE` = 'BASE TABLE');

SET @sql := IF(@passeA_legacy_exists = 1,
  CONCAT('UPDATE `biens` b ',
         'JOIN `', @passeA_target, '` tb ON tb.id = b.id_type_bien ',
         'JOIN `bien_types` bt ON bt.code = tb.code ',
         'SET b.id_bien_type = bt.id ',
         'WHERE b.id_bien_type IS NULL'),
  'DO 1');
PREPARE _mig_passeA FROM @sql;
EXECUTE _mig_passeA;
DEALLOCATE PREPARE _mig_passeA;

-- Passe B : via base_types_bien (cas où autosave a écrit un id pointant
--           en réalité vers base_types_bien suite au bug 2026-04-23)
UPDATE `biens` b
JOIN `base_types_bien` btb ON btb.id = b.id_type_bien
JOIN `bien_types` bt ON bt.code = btb.code
SET b.id_bien_type = bt.id
WHERE b.id_bien_type IS NULL;

-- Passe C : fallback final pour les orphelins (programme_neuf, codes
--           inconnus) → reclassés sur 'appartement' par défaut
UPDATE `biens` b
JOIN `bien_types` bt ON bt.code = 'appartement'
SET b.id_bien_type = bt.id
WHERE b.id_bien_type IS NULL AND b.id_type_bien IS NOT NULL;

-- ────────────────────────────────────────────────────────────────────
-- 5. Rename types_bien → types_bien_legacy (idempotent via PREPARE)
--    La FK fk_biens_type suit automatiquement le rename MySQL.
-- ────────────────────────────────────────────────────────────────────
SET @types_bien_exists := (SELECT COUNT(*) FROM `information_schema`.`TABLES`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'types_bien' AND `TABLE_TYPE` = 'BASE TABLE');

SET @types_bien_legacy_exists := (SELECT COUNT(*) FROM `information_schema`.`TABLES`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'types_bien_legacy' AND `TABLE_TYPE` = 'BASE TABLE');

SET @do_rename := (@types_bien_exists = 1 AND @types_bien_legacy_exists = 0);

SET @sql := IF(@do_rename = 1,
  'RENAME TABLE `types_bien` TO `types_bien_legacy`',
  'DO 1');
PREPARE _mig_stmt FROM @sql;
EXECUTE _mig_stmt;
DEALLOCATE PREPARE _mig_stmt;
SQL,
];
