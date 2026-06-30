-- ============================================================================
-- Migration : déplacement des infos de COPROPRIÉTÉ au bon niveau
-- Date      : 2026-06-17
-- Contexte  : les infos valables pour TOUTE la copropriété (nb de lots, budget
--             prévisionnel total, total des tantièmes) appartiennent à l'immeuble.
--             Les infos propres au LOT vendu (charges du lot, tantièmes du lot)
--             restent sur la table biens.
-- ----------------------------------------------------------------------------
-- Idempotent : IF NOT EXISTS (MariaDB 10.0.2+ / MySQL 8.0.29+).
-- Aucune suppression de colonne : biens.copro_nb_lots est conservée mais
-- dépréciée (on cesse d'y écrire ; lecture via immeubles en priorité).
-- ============================================================================

-- 1. Colonnes COMMUNES à la copropriété → table immeubles
ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `copro_nb_lots` SMALLINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Nombre total de lots de la copropriété (commun à tous les biens)',
  ADD COLUMN IF NOT EXISTS `copro_budget_previsionnel_annuel` DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Budget prévisionnel annuel total de la copropriété (€/an, commun)',
  ADD COLUMN IF NOT EXISTS `copro_tantiemes_total` INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Total des tantièmes / millièmes de la copropriété (commun)';

-- 2. Colonne propre au LOT vendu → table biens
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `lot_tantiemes` INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Quote-part en tantièmes du lot dans la copropriété (propre au bien)';

-- 3. Backfill : recopier le nb de lots déjà saisi sur les biens vers l'immeuble
--    (MAX non-null par immeuble). Ne touche pas les immeubles déjà renseignés.
UPDATE `immeubles` i
JOIN (
    SELECT id_immeuble, MAX(copro_nb_lots) AS nb
    FROM biens
    WHERE id_immeuble IS NOT NULL AND copro_nb_lots > 0
    GROUP BY id_immeuble
) src ON src.id_immeuble = i.id
SET i.copro_nb_lots = src.nb
WHERE (i.copro_nb_lots IS NULL OR i.copro_nb_lots = 0);

-- NB : biens.copro_nb_lots est dépréciée à partir de cette migration.
--      Elle reste en place pour rétro-compat lecture mais n'est plus écrite.
