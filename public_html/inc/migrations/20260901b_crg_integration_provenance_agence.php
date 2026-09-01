<?php
/**
 * Migration : LA PROVENANCE DE L'AGENCE ET DE LA PÉRIODE, EN PHASE 0.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── LA CORRECTION DEMANDÉE PAR EMMANUEL LE 01/09/2026 ──────────────────────────
 * « La Phase 0 doit répondre complètement à : QUEL CRG ? → QUELLE AGENCE ? →
 *   QUELLE PÉRIODE ? → QUEL COMPTE/PROPRIÉTAIRE ? → QUELLES PAGES ? »
 *
 * Le premier moteur laissait l'agence à NULL pour la famille `lyon`, qui ne
 * l'imprime pas, en renvoyant l'identification à la phase 1. C'était valider un
 * découpage documentaire sans avoir terminé l'identification documentaire exigée.
 *
 * ── CE QUE PORTENT LES NOUVELLES COLONNES ──────────────────────────────────────
 * `agence_id`       l'agence MBI, quand elle est établie SANS AMBIGUÏTÉ.
 * `agence_source`   d'où elle vient — et c'est le champ qui compte :
 *                     LUE            imprimée dans le PDF ;
 *                     RAPPROCHEE     retrouvée dans MBI par le compte mandant,
 *                                    avec une seule agence possible ;
 *                     INDETERMINABLE ni lue, ni rapprochée sans ambiguïté.
 * `agence_motif`    la phrase qui explique le verdict, pour qu'on n'ait jamais à
 *                   deviner pourquoi une ligne est indéterminable.
 * `periode_source`  LUE | INDETERMINABLE. Une période absente bloque la phase.
 *
 * ── POURQUOI UNE COLONNE DE PROVENANCE, ET PAS SEULEMENT UNE AGENCE ────────────
 * Parce qu'une agence rapprochée et une agence imprimée n'engagent pas la même
 * chose. La première est une conclusion de MBI, la seconde un fait du document.
 * Les afficher côte à côte sans les distinguer ferait passer une déduction pour
 * une lecture — exactement ce que la doctrine CRG interdit depuis P1.
 *
 * ⚠️ ON N'ÉCRIT RIEN DANS `proprietaire_comptes_crg`. Sa propre doctrine dit que
 *    son `id_agence` est « JAMAIS déduit de proprietaires.id_agence — lu dans le
 *    CRG SPI ou désigné ». Le rapprochement calculé ici vit dans le staging et n'y
 *    remonte pas.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260901b_crg_integration_provenance_agence',
    'title'       => 'Intégration CRG — provenance de l’agence et de la période en phase 0',
    'description' => "Ajoute agence_id, agence_source, agence_motif et periode_source à "
                   . "crgi_crg : distingue LUE / RAPPROCHEE / INDETERMINABLE.",
    'created_at'  => '2026-09-01',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_crg`
  ADD COLUMN `agence_id` INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Agence MBI etablie SANS AMBIGUITE. NULL = non etablie, et cela se voit.'
      AFTER `agence`,
  ADD COLUMN `agence_source` VARCHAR(20) NOT NULL DEFAULT 'INDETERMINABLE'
      COMMENT 'LUE | RAPPROCHEE | INDETERMINABLE'
      AFTER `agence_id`,
  ADD COLUMN `agence_motif` VARCHAR(300) NULL DEFAULT NULL
      COMMENT 'Pourquoi ce verdict. Jamais laisse vide sur un INDETERMINABLE.'
      AFTER `agence_source`,
  ADD COLUMN `periode_source` VARCHAR(20) NOT NULL DEFAULT 'INDETERMINABLE'
      COMMENT 'LUE | INDETERMINABLE'
      AFTER `periode_cle`,
  ADD KEY `idx_agence_source` (`agence_source`),
  ADD KEY `idx_periode_source` (`periode_source`);
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_crg`
  DROP COLUMN `periode_source`,
  DROP COLUMN `agence_motif`,
  DROP COLUMN `agence_source`,
  DROP COLUMN `agence_id`;
SQL,
];
