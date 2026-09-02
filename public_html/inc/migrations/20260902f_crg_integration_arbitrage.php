<?php
/**
 * Migration : LES RÉPONSES D'EMMANUEL AUX ARBITRAGES.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── POURQUOI CETTE TABLE EXISTE ────────────────────────────────────────────────
 * ⚠️ ON DEMANDAIT D'ARBITRER SANS DONNER OÙ RÉPONDRE. L'écran posait sept
 *    décisions, listait leurs choix et leurs conséquences — et n'offrait aucun
 *    champ. Un arbitrage qu'on ne peut pas enregistrer n'est pas un arbitrage :
 *    c'est un constat qu'on relit indéfiniment.
 *
 * ── CE QU'ELLE PORTE, ET CE QU'ELLE NE PORTE PAS ───────────────────────────────
 * Une ligne = UNE décision humaine sur UN objet : le choix retenu parmi ceux que
 * la règle propose, et la précision libre qu'Emmanuel juge utile.
 *
 * ⚠️ ELLE N'ÉCRIT RIEN DANS LES DONNÉES MÉTIER. Décider n'est pas intégrer. La
 *    décision est enregistrée en staging, datée et signée ; c'est la phase
 *    d'intégration — non livrée — qui l'exécutera.
 *
 * ⚠️ ELLE NE MODIFIE PAS LE PLAN. Le plan décrit ce que le DOCUMENT démontre ;
 *    l'arbitrage décrit ce qu'EMMANUEL décide. Deux choses différentes, deux
 *    tables : confondre les deux ferait disparaître la trace de ce que le
 *    document disait avant qu'on tranche.
 *
 * ⚠️ ET UNE DÉCISION SE CHANGE. La contrainte d'unicité porte sur la cible :
 *    répondre deux fois remplace la réponse, en gardant sa date. On ne empile
 *    pas des avis contradictoires sur le même objet.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260902f_crg_integration_arbitrage',
    'title'       => 'Intégration CRG — les réponses aux arbitrages',
    'description' => "Table crgi_arbitrage : une décision humaine par objet à arbitrer, avec "
                   . "le choix retenu et la précision libre. Aucune écriture métier.",
    'created_at'  => '2026-09-02',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_arbitrage` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`   INT(10) UNSIGNED NOT NULL,
  `groupe`      VARCHAR(60)      NOT NULL COMMENT 'La regle qui produit cet arbitrage.',
  `cible_type`  VARCHAR(20)      NOT NULL COMMENT 'IMMEUBLE | OCCUPATION | MOUVEMENT',
  `cible_id`    INT(10) UNSIGNED NOT NULL COMMENT 'id de la ligne de staging concernee',
  `choix`       VARCHAR(120)     NOT NULL DEFAULT '' COMMENT 'Le choix retenu parmi ceux proposes.',
  `precision_h` VARCHAR(1000)    NULL DEFAULT NULL COMMENT 'Ce qu Emmanuel ajoute en clair.',
  `decide_par`  INT(10) UNSIGNED NULL DEFAULT NULL,
  `decide_le`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cible` (`import_id`, `cible_type`, `cible_id`),
  KEY `idx_groupe` (`import_id`, `groupe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Les decisions humaines sur les arbitrages. Decider n est pas integrer.';
SQL,

    'down' => <<<'SQL'
DROP TABLE IF EXISTS `crgi_arbitrage`;
SQL,
];
