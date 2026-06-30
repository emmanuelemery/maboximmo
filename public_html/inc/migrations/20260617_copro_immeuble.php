<?php
/**
 * Migration : déplacement des infos de COPROPRIÉTÉ au bon niveau.
 *
 * Infos communes à toute la copropriété (nb de lots, budget prévisionnel total,
 * total des tantièmes) → table immeubles. Infos propres au LOT vendu (charges du
 * lot, tantièmes du lot) → table biens.
 *
 * Idempotent : ADD COLUMN IF NOT EXISTS + UPDATE conditionnel. Rejouable sans casse.
 * biens.copro_nb_lots est conservée (dépréciée) ; backfill vers immeubles.
 */
return [
    'id'          => '20260617_copro_immeuble',
    'title'       => 'Copropriété : nb lots / budget prévisionnel / tantièmes au niveau immeuble + tantièmes du lot',
    'description' => "Ajoute immeubles.copro_nb_lots, copro_budget_previsionnel_annuel, copro_tantiemes_total et biens.lot_tantiemes. Backfill biens.copro_nb_lots → immeubles.copro_nb_lots.",
    'created_at'  => '2026-06-17',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `copro_nb_lots` SMALLINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Nombre total de lots de la copropriété (commun à tous les biens)',
  ADD COLUMN IF NOT EXISTS `copro_budget_previsionnel_annuel` DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Budget prévisionnel annuel total de la copropriété (€/an, commun)',
  ADD COLUMN IF NOT EXISTS `copro_tantiemes_total` INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Total des tantièmes / millièmes de la copropriété (commun)';

ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `lot_tantiemes` INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Quote-part en tantièmes du lot dans la copropriété (propre au bien)';

UPDATE `immeubles` i
JOIN (
    SELECT id_immeuble, MAX(copro_nb_lots) AS nb
    FROM biens
    WHERE id_immeuble IS NOT NULL AND copro_nb_lots > 0
    GROUP BY id_immeuble
) src ON src.id_immeuble = i.id
SET i.copro_nb_lots = src.nb
WHERE (i.copro_nb_lots IS NULL OR i.copro_nb_lots = 0);
SQL
];
