<?php
/**
 * Migration : PHASE 3 — LOCATAIRES / OCCUPATION.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE LA PHASE 3 ÉTABLIT ──────────────────────────────────────────────────
 * Pour chaque LOT, la suite de ses occupants au fil des périodes du dépôt :
 *   `LOT → locataire → statut → période → date de bail`
 * et le verdict de ce que cette suite démontre — même occupant, nouvel entrant,
 * succession locative, départ, ou situation à arbitrer.
 *
 * ── UNE OBSERVATION PAR LOT ET PAR PÉRIODE ─────────────────────────────────────
 * `crgi_occupation` porte UNE ligne par (lot, période) : c'est la matière de la
 * chronologie. Le verdict, lui, se calcule sur la SUITE de ces lignes — un
 * changement de titulaire ne se lit jamais sur une seule période.
 *
 * ── LES RÈGLES QUI COMMANDENT CES COLONNES ─────────────────────────────────────
 * ⚠️ `UN ANCIEN LOCATAIRE N'EST JAMAIS SUPPRIMÉ.` Il reste dans la table avec sa
 *    période, son bail et son verdict. Rien ici ne porte d'action de suppression.
 * ⚠️ `ABSENCE DANS LE NOUVEAU CORPUS ≠ DÉPART DÉMONTRÉ.` Un lot qui cesse
 *    d'apparaître ne prouve rien : le CRG peut simplement ne pas avoir été
 *    déposé. Le départ n'est retenu que si le lot est PRÉSENT à la période
 *    suivante avec un autre occupant, ou sans occupant.
 * ⚠️ `LE CHANGEMENT CHRONOLOGIQUE DU TITULAIRE SUR UN MÊME LOT EST UNE
 *    SUCCESSION LOCATIVE DÉMONTRÉE` — et la dette de l'ancien reste attachée à
 *    l'ancien : `P6A-CREANCE-07` l'a certifié, elle ne passe jamais au suivant.
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. La phase 3 lit le staging et n'écrit que dedans.
 *
 * ── L'ENCOURS N'EST PAS DANS CETTE TABLE PAR HASARD ────────────────────────────
 * `solde` et `solde_source` existent, mais sur le dépôt réel `solde_source` vaut
 * `NON DEMONTRABLE` : `pdftotext` détache « Solde » de son montant **1 018 fois
 * sur 1 018**, en lecture brute comme en `-layout`. Le rapprocher par proximité
 * serait une devinette. La colonne dit donc ce qu'elle sait, y compris qu'elle
 * ne sait pas.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260902a_crg_integration_occupation',
    'title'       => 'Intégration CRG — phase 3, locataires et occupation',
    'description' => "Table crgi_occupation : une observation par lot et par période, et le "
                   . "verdict de la suite. Aucune écriture métier, aucun ancien locataire "
                   . "supprimé.",
    'created_at'  => '2026-09-02',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_occupation` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `crg_id`         INT(10) UNSIGNED NOT NULL,
  `lot_reference`  VARCHAR(40)      NOT NULL,
  `code_immeuble`  VARCHAR(20)      NULL DEFAULT NULL,
  `periode_cle`    VARCHAR(40)      NULL DEFAULT NULL,
  `date_arrete`    DATE             NULL DEFAULT NULL,
  `locataire`      VARCHAR(190)     NULL DEFAULT NULL COMMENT 'NULL = aucune ligne locataire imprimee.',
  `bail_du`        DATE             NULL DEFAULT NULL,
  `solde`          DECIMAL(12,2)    NULL DEFAULT NULL,
  `solde_source`   VARCHAR(24)      NOT NULL DEFAULT 'NON DEMONTRABLE'
                   COMMENT 'LUE | NON DEMONTRABLE — le document detache Solde de son montant',
  `page`           INT(10) UNSIGNED NOT NULL,
  `statut`         VARCHAR(32)      NULL DEFAULT NULL
                   COMMENT 'IDENTIQUE | NOUVEL ENTRANT | CHANGEMENT DE LOCATAIRE | PARTI | ANCIEN LOCATAIRE AVEC DETTE | A ARBITRER',
  `statut_motif`   VARCHAR(400)     NULL DEFAULT NULL,
  `precedent`      VARCHAR(190)     NULL DEFAULT NULL COMMENT 'Le titulaire de la periode precedente.',
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_lot` (`import_id`, `lot_reference`, `date_arrete`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Une observation d occupation par lot et par periode. L ancien locataire y reste.';
SQL,

    'down' => <<<'SQL'
DROP TABLE IF EXISTS `crgi_occupation`;
SQL,
];
