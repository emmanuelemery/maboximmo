<?php
/**
 * Migration : PHASE 4 — FINANCES.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE LA PHASE 4 ÉTABLIT ──────────────────────────────────────────────────
 * UN euro = UNE ligne, avec sa NATURE, sa MAILLE et sa PROVENANCE. `crgi_mouvement`
 * ne porte aucun total : elle porte les mouvements élémentaires, et tout agrégat
 * se recalcule à partir d'eux. On ne stocke jamais une somme qu'on ne peut pas
 * rouvrir jusqu'à sa page.
 *
 * ── POURQUOI `colonne` EST UNE COLONNE DE LA TABLE ─────────────────────────────
 * ⚠️ DANS CE DOCUMENT, LA COLONNE EST LA NATURE. Le même « 474,49 » est un loyer
 *    APPELÉ sous l'en-tête `Loyers`, un ENCAISSEMENT sous `Crédit`, un IMPAYÉ sous
 *    `Reste dû`. La colonne d'origine est donc une donnée de preuve, pas un détail
 *    d'implémentation : elle reste dans la table pour qu'on puisse toujours
 *    remonter de la catégorie au document.
 *
 * ── LES RÈGLES QUI COMMANDENT CES COLONNES ─────────────────────────────────────
 * ⚠️ `APPEL ≠ ENCAISSEMENT ≠ AFFECTATION ≠ SOLDE.` Quatre catégories distinctes,
 *    jamais fondues. Un encaissement peut solder une période ANTÉRIEURE : l'écart
 *    `appelé − encaissé` n'est JAMAIS « l'impayé de la période ».
 * ⚠️ `STOCK ≠ FLUX.` `ENCOURS` et `SOLDE` sont des photographies. Deux d'entre
 *    elles ne s'additionnent jamais ; `flux` = 0 le dit dans la table elle-même.
 * ⚠️ `AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE.` Le « Récapitulatif des immeubles » rejoue
 *    ce que les blocs ont déjà dit. Il est LU et CONSERVÉ — car il sert de
 *    contrôle — mais `additionnable = 0` interdit qu'il entre dans un total.
 * ⚠️ `DÉPENSE ≠ APPEL LOCATAIRE.` Une provision appelée au locataire n'est pas une
 *    dépense du propriétaire : `CHARGE APPELEE AU LOCATAIRE` et `CHARGE` sont deux
 *    catégories, jamais une seule.
 * ⚠️ `LA MAILLE D'AFFICHAGE NE PEUT JAMAIS ÊTRE PLUS FINE QUE LA MAILLE DE LA
 *    PREUVE.` `maille` dit où le document démontre le montant — COMPTE, IMMEUBLE
 *    ou LOT. Aucun prorata, aucun rattachement forcé : une charge démontrée au
 *    compte RESTE au compte.
 * ⚠️ `DOCUMENT DISTINCT ≠ ÉVÉNEMENT MÉTIER DISTINCT.` `reimpression` marque les
 *    pages qu'un CRG réimprime à l'identique de ses propres pages. Elles sont
 *    conservées et traçables, jamais additionnées — et cela ne dédoublonne AUCUN
 *    montant : la démonstration porte sur le texte de la page, pas sur les sommes.
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. La phase 4 lit le staging et n'écrit que dedans.
 *
 * ── CE QUE LA TABLE SAIT NE PAS SAVOIR ─────────────────────────────────────────
 * `INDETERMINABLE` est une catégorie à part entière. Un montant qui ne tombe dans
 * aucune colonne connue n'est jamais rangé dans la colonne d'à côté : il est
 * conservé, compté, et remonté à l'écran pour arbitrage.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260902b_crg_integration_finances',
    'title'       => 'Intégration CRG — phase 4, finances',
    'description' => "Table crgi_mouvement : un euro, une nature, une maille, une page. "
                   . "Agrégats et réimpressions conservés mais non additionnables. "
                   . "Aucune écriture métier.",
    'created_at'  => '2026-09-02',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_mouvement` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`     INT(10) UNSIGNED NOT NULL,
  `crg_id`        INT(10) UNSIGNED NOT NULL,
  `page`          INT(10) UNSIGNED NOT NULL COMMENT 'Page PDF : on doit toujours pouvoir y revenir.',
  `section`       VARCHAR(60)      NULL DEFAULT NULL COMMENT 'La section imprimee par le document.',
  `immeuble`      VARCHAR(190)     NULL DEFAULT NULL,
  `lot_reference` VARCHAR(40)      NULL DEFAULT NULL COMMENT 'NULL si le document ne le demontre pas au lot.',
  `locataire`     VARCHAR(190)     NULL DEFAULT NULL COMMENT 'NULL si le document ne le demontre pas.',
  `date_piece`    DATE             NULL DEFAULT NULL,
  `periode_cle`   VARCHAR(40)      NULL DEFAULT NULL,
  `date_arrete`   DATE             NULL DEFAULT NULL,
  `libelle`       VARCHAR(220)     NOT NULL DEFAULT '',
  `colonne`       VARCHAR(20)      NOT NULL DEFAULT ''
                  COMMENT 'loyers|charges|autres|reste_du|debit|credit|inline — la colonne EST la nature',
  `montant`       DECIMAL(14,2)    NOT NULL,
  `categorie`     VARCHAR(40)      NOT NULL
                  COMMENT 'LOYER APPELE | CHARGE APPELEE AU LOCATAIRE | AUTRE APPELE AU LOCATAIRE | ENCAISSEMENT | ENCOURS | CHARGE | FRAIS ET ASSURANCES | VERSEMENT PROPRIETAIRE | SOLDE | AGREGAT | DETAIL | INDETERMINABLE',
  `maille`        VARCHAR(12)      NOT NULL DEFAULT 'COMPTE' COMMENT 'COMPTE | IMMEUBLE | LOT',
  `flux`          TINYINT(1)       NOT NULL DEFAULT 1
                  COMMENT '0 = STOCK (encours, solde) : jamais additionne a un flux, jamais cumule entre deux periodes.',
  `additionnable` TINYINT(1)       NOT NULL DEFAULT 1
                  COMMENT '0 = agregat, detail ou reimpression : conserve et tracable, jamais somme.',
  `reimpression`  TINYINT(1)       NOT NULL DEFAULT 0
                  COMMENT 'Page reimprimee a l identique dans le MEME CRG. Demontre sur le texte, jamais sur les montants.',
  `provenance`    VARCHAR(16)      NOT NULL DEFAULT 'LUE' COMMENT 'LUE — la phase 4 ne deduit aucun montant.',
  `motif`         VARCHAR(400)     NULL DEFAULT NULL,
  `x1`            DECIMAL(7,1)     NULL DEFAULT NULL COMMENT 'Bord droit du montant : la preuve de sa colonne.',
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`),
  KEY `idx_cat` (`import_id`, `categorie`),
  KEY `idx_lot` (`import_id`, `lot_reference`),
  KEY `idx_page` (`import_id`, `page`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un euro, une nature, une maille, une page. Aucun total n y est stocke.';
SQL,

    'down' => <<<'SQL'
DROP TABLE IF EXISTS `crgi_mouvement`;
SQL,
];
