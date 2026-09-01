<?php
/**
 * Migration : PHASE 2 — LE PATRIMOINE, CONFRONTÉ À MBI.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE LA PHASE 2 CONFRONTE ────────────────────────────────────────────────
 *   `PROPRIÉTAIRE → COMPTE MANDANT → IMMEUBLE → BIEN / LOT`
 * entre ce que MBI enregistre aujourd'hui et ce que le nouveau corpus rapporte.
 *
 * ── DEUX TABLES, PARCE QUE DEUX OBJETS ─────────────────────────────────────────
 *   `crgi_immeuble`  un immeuble lu dans un CRG, et le verdict de sa confrontation.
 *   `crgi_lot`       un lot lu dans un CRG, et le sien.
 * Toutes deux portent la PAGE où l'objet a été lu : un verdict sans provenance
 * n'est pas vérifiable, et un verdict non vérifiable ne vaut rien.
 *
 * ── LE CHAMP `avant_apres` ─────────────────────────────────────────────────────
 * Emmanuel l'a demandé explicitement : « Je ne veux pas simplement MODIFIÉ. »
 * Quand un objet diffère, on écrit ce qui diffère, champ par champ, dans les deux
 * états. Un « modifié » sans son avant/après oblige à rouvrir le PDF pour savoir
 * ce qui a bougé — et personne ne le fait.
 *
 * ── LES RÈGLES QUI COMMANDENT CES COLONNES ─────────────────────────────────────
 * ⚠️ `TIERS ≠ PROPRIÉTAIRE ≠ COMPTE MANDANT.` Un propriétaire porte plusieurs
 *    comptes ; un nouveau compte ne fait pas un nouveau propriétaire.
 * ⚠️ `AUCUN FUZZY MATCHING NE CRÉE UNE IDENTITÉ.` `mbi_*_id` n'est renseigné que
 *    sur une correspondance exacte après normalisation. Une ressemblance produit
 *    `A ARBITRER` et le candidat est NOMMÉ, jamais retenu d'office.
 * ⚠️ `ABSENT DU CORPUS ≠ SUPPRIMÉ DE MBI.` Rien dans ce socle ne porte d'action
 *    de suppression : ce que MBI connaît et que le dépôt ne rapporte pas se
 *    calcule à la lecture, et ne s'écrit nulle part.
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. Ces tables observent ; elles ne référencent MBI que
 *    par un identifiant nu, sans clé étrangère, pour que le module reste
 *    supprimable d'un `DROP`.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260901f_crg_integration_patrimoine',
    'title'       => 'Intégration CRG — phase 2, patrimoine confronté à MBI',
    'description' => "Tables crgi_immeuble et crgi_lot, plus la qualification A/B/C/D des "
                   . "comptes inconnus. Aucune écriture métier, aucune clé étrangère.",
    'created_at'  => '2026-09-01',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_immeuble` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `crg_id`         INT(10) UNSIGNED NOT NULL,
  `code`           VARCHAR(20)      NULL DEFAULT NULL COMMENT 'Code lu dans la reference de lot.',
  `nom`            VARCHAR(255)     NOT NULL,
  `code_postal`    VARCHAR(10)      NULL DEFAULT NULL,
  `ville`          VARCHAR(150)     NULL DEFAULT NULL,
  `page`           INT(10) UNSIGNED NOT NULL,
  `statut`         VARCHAR(24)      NOT NULL DEFAULT 'INDETERMINE'
                   COMMENT 'IDENTIQUE | NOUVEAU | MODIFIE | A ARBITRER | INDETERMINE',
  `mbi_immeuble_id` INT(10) UNSIGNED NULL DEFAULT NULL
                   COMMENT 'immeubles.id, sur correspondance EXACTE seulement.',
  `avant_apres`    TEXT             NULL DEFAULT NULL COMMENT 'Ce qui differe, champ par champ.',
  `motif`          VARCHAR(400)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un immeuble lu dans un CRG, et le verdict de sa confrontation a MBI.';

CREATE TABLE IF NOT EXISTS `crgi_lot` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `crg_id`         INT(10) UNSIGNED NOT NULL,
  `reference`      VARCHAR(40)      NOT NULL,
  `code_immeuble`  VARCHAR(20)      NULL DEFAULT NULL,
  `numero`         VARCHAR(20)      NULL DEFAULT NULL,
  `libelle`        VARCHAR(160)     NULL DEFAULT NULL,
  `locataire`      VARCHAR(190)     NULL DEFAULT NULL,
  `page`           INT(10) UNSIGNED NOT NULL,
  `statut`         VARCHAR(24)      NOT NULL DEFAULT 'INDETERMINE',
  `mbi_bien_id`    INT(10) UNSIGNED NULL DEFAULT NULL,
  `avant_apres`    TEXT             NULL DEFAULT NULL,
  `motif`          VARCHAR(400)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_ref` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un lot lu dans un CRG, et le verdict de sa confrontation a MBI.';

ALTER TABLE `crgi_crg`
  ADD COLUMN `compte_qualification` CHAR(1) NULL DEFAULT NULL
      COMMENT 'A nouveau compte d un proprietaire connu | B nouveau proprietaire | C compte present non rapproche | D arbitrage'
      AFTER `mbi_trimestre_id`,
  ADD COLUMN `compte_qualif_motif` VARCHAR(400) NULL DEFAULT NULL
      AFTER `compte_qualification`,
  ADD COLUMN `mbi_proprietaire_id` INT(10) UNSIGNED NULL DEFAULT NULL
      COMMENT 'Candidat NOMME, jamais retenu d office : aucune identite n est creee ici.'
      AFTER `compte_qualif_motif`,
  ADD KEY `idx_compte_qualif` (`compte_qualification`);
SQL,

    'down' => <<<'SQL'
DROP TABLE IF EXISTS `crgi_lot`;
DROP TABLE IF EXISTS `crgi_immeuble`;
ALTER TABLE `crgi_crg`
  DROP COLUMN `mbi_proprietaire_id`,
  DROP COLUMN `compte_qualif_motif`,
  DROP COLUMN `compte_qualification`;
SQL,
];
