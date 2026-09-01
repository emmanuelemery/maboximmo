<?php
/**
 * Migration : LA QUALIFICATION HUMAINE D'UN « MÊME CLÉ, CONTENU DIFFÉRENT ».
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── POURQUOI CETTE COLONNE EXISTE ──────────────────────────────────────────────
 * `compte × période × date d'arrêté` a signalé 18 collisions sur le document réel.
 * Le signalement ne dit pas ce qu'elles sont. La comparaison des MONTANTS l'a dit :
 *   **A** — même situation, les écarts sont des coquilles d'OCR ;
 *   **B** — situation COMPLÉMENTAIRE : les deux occurrences portent des lots
 *           différents du même immeuble (`3062-0187 / 3062-0221` contre
 *           `3062-0171 / 3062-0205`) ;
 *   **C** — trop peu de matière pour trancher : arbitrage.
 *
 * ── CE QUE CELA ÉVITE ──────────────────────────────────────────────────────────
 * Sans elle, 13 comptes rendus complémentaires auraient été fondus dans un autre
 * au motif qu'ils partagent une clé — la faute même que la rectification FOCH/SABY
 * avait mise au jour côté P5, reproduite un cran plus loin.
 *
 * ⚠️ CES COLONNES NE SONT PAS DANS L'EMPREINTE DE LA PHASE 0. Le sceau porte sur le
 *    DÉCOUPAGE — pièce, pages, agence, compte, période, certitude. Qualifier un
 *    doublon n'y touche pas : la validation d'Emmanuel reste valide, et c'est
 *    voulu. Un sceau qui sauterait à chaque annotation ne protégerait plus rien.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260901d_crg_integration_qualification_doublon',
    'title'       => 'Intégration CRG — qualification A/B/C des collisions de clé',
    'description' => "Ajoute doublon_qualification et doublon_qualif_motif à crgi_crg : "
                   . "une collision de clé se qualifie par les MONTANTS, pas par la clé.",
    'created_at'  => '2026-09-01',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_crg`
  ADD COLUMN `doublon_qualification` CHAR(1) NULL DEFAULT NULL
      COMMENT 'A meme situation | B situation complementaire | C arbitrage necessaire'
      AFTER `doublon_statut`,
  ADD COLUMN `doublon_qualif_motif` VARCHAR(400) NULL DEFAULT NULL
      COMMENT 'Sur quoi repose le verdict : montants communs, isoles, ecart relatif.'
      AFTER `doublon_qualification`,
  ADD KEY `idx_qualification` (`doublon_qualification`);
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_crg`
  DROP COLUMN `doublon_qualif_motif`,
  DROP COLUMN `doublon_qualification`;
SQL,
];
