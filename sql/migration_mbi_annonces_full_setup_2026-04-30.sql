-- =====================================================================
-- MaBoxImmo — Setup BDD complet pour le portail public mbi_annonces_*
-- Date     : 2026-04-30
-- Cible    : MySQL 5.7+ / MariaDB 10.0+
-- =====================================================================
-- Bundle 1-shot : applique les 3 migrations nécessaires + vérifie le résultat.
-- 100% idempotent (safe à rejouer).
--
-- USAGE : phpMyAdmin → sélectionner la BDD MaBoxImmo dans le panneau gauche
--         → onglet SQL → coller TOUT ce fichier → Exécuter.
-- =====================================================================

-- Affiche la BDD courante (pour confirmer qu'on est au bon endroit)
SELECT DATABASE() AS bdd_active, NOW() AS executed_at;

-- ═══════════════════════════════════════════════════════════════════════
-- BLOC 1 — Colonnes canaux de diffusion sur `annonces`
-- ═══════════════════════════════════════════════════════════════════════

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `annonces` ADD COLUMN `visible_maboximmo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Canal MaBoxImmo : visible dans le portail public mbi_annonces_*''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND column_name = 'visible_maboximmo');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `annonces` ADD COLUMN `visible_site_perso` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Canal Site perso : visible sur le mini-site vitrine de l''''agence''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND column_name = 'visible_site_perso');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════
-- BLOC 2 — Colonnes environnement sur `biens` (fix #1060 quartier)
-- ═══════════════════════════════════════════════════════════════════════

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `ambiance` VARCHAR(255) DEFAULT NULL COMMENT ''CSV: calme,centre_ville,proche_transports,proche_commerces,residentiel''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'ambiance');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `quartier` VARCHAR(150) DEFAULT NULL COMMENT ''Quartier (important SEO local)''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'quartier');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `points_interet` TEXT DEFAULT NULL COMMENT ''Points d''''intérêt supplémentaires''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'points_interet');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `argument_phare` VARCHAR(255) DEFAULT NULL COMMENT ''Argument commercial clé en 1 phrase''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'argument_phare');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════
-- BLOC 3 — Indexes mbi pour optimiser les requêtes du portail
-- ═══════════════════════════════════════════════════════════════════════

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi` ON `annonces` (`visible_maboximmo`, `statut`, `type_transaction`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi_date` ON `annonces` (`visible_maboximmo`, `statut`, `date_mise_en_ligne`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi_date');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi_prix` ON `annonces` (`visible_maboximmo`, `statut`, `prix`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi_prix');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi_loyer` ON `annonces` (`visible_maboximmo`, `statut`, `loyer`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi_loyer');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_ville` ON `biens` (`statut_bien`, `ville`, `code_postal`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_ville');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_surface` ON `biens` (`statut_bien`, `surface_habitable`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_surface');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_pieces` ON `biens` (`statut_bien`, `nb_pieces`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_pieces');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_type` ON `biens` (`statut_bien`, `id_type_bien`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_type');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════
-- VÉRIFICATION FINALE
-- ═══════════════════════════════════════════════════════════════════════

-- Récap : tout doit être à 1 (présent) après exécution réussie
SELECT
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'annonces' AND column_name = 'visible_maboximmo')
    AS col_visible_maboximmo,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'annonces' AND column_name = 'visible_site_perso')
    AS col_visible_site_perso,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'quartier')
    AS col_quartier,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'ambiance')
    AS col_ambiance,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'points_interet')
    AS col_points_interet,
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'argument_phare')
    AS col_argument_phare,
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND index_name LIKE 'idx_mbi_%')
    AS nb_indexes_mbi;
-- attendu : col_* = 1 partout, nb_indexes_mbi = 8 (4 sur annonces + 4 sur biens × leurs colonnes)
