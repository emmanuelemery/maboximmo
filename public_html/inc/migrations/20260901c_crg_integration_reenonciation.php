<?php
/**
 * Migration : DISTINGUER LA RÉÉNONCIATION DÉMONTRÉE DE LA SIMPLE COLLISION DE CLÉ.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── LA MISE EN GARDE D'EMMANUEL, 01/09/2026 ────────────────────────────────────
 * « `compte × période × date d'arrêté` est un excellent contrôle, mais je ne veux
 *   pas qu'elle devienne automatiquement l'identité métier universelle. Une même
 *   clé ne doit jamais écraser un CRG complémentaire. »
 *
 * Elle était fondée, et le document réel l'a prouvé sur-le-champ : sur 72 CRG
 * partageant leur clé avec un autre, **54 portent un contenu identique** — ce sont
 * des réénonciations — et **18 portent un contenu DIFFÉRENT**. Fondre les 72 aurait
 * détruit 18 comptes rendus complémentaires en silence, exactement le défaut que la
 * rectification FOCH/SABY avait mis au jour côté P5.
 *
 * ── CE QUE PORTENT LES COLONNES ────────────────────────────────────────────────
 * `empreinte`      SHA-256 du texte lu du CRG, pages comprises. C'est elle qui
 *                  transforme « même clé » en « même contenu démontré ».
 * `caracteres_lus` combien de texte a servi à l'empreinte. Une empreinte calculée
 *                  sur trois caractères ne démontre rien : le chiffre doit être
 *                  visible pour qu'on puisse en juger.
 * `doublon_de`     l'occurrence dont celle-ci est la réénonciation. Les DEUX
 *                  occurrences restent en base, avec leurs pages : on conserve la
 *                  trace documentaire, on ne compte qu'une situation.
 * `doublon_statut` UNIQUE | REENONCIATION | MEME CLE CONTENU DIFFERENT.
 *
 * ⚠️ AUCUNE SUPPRESSION, NI EN BASE NI DANS LE PDF. « Document ≠ situation métier ≠
 *    événement métier » : les trois se comptent séparément et aucun n'efface l'autre.
 *
 * ⚠️ Statements additifs : rejouables sans casse.
 */

return [
    'id'          => '20260901c_crg_integration_reenonciation',
    'title'       => 'Intégration CRG — réénonciation démontrée vs collision de clé',
    'description' => "Ajoute empreinte, caracteres_lus, doublon_de et doublon_statut à "
                   . "crgi_crg : une même clé ne vaut réédition que si le contenu concorde.",
    'created_at'  => '2026-09-01',

    'sql' => <<<'SQL'
ALTER TABLE `crgi_crg`
  ADD COLUMN `empreinte` CHAR(64) NULL DEFAULT NULL
      COMMENT 'SHA-256 du texte lu. Transforme « meme cle » en « meme contenu demontre ».'
      AFTER `motif`,
  ADD COLUMN `caracteres_lus` INT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Volume de texte ayant servi a l empreinte. Trois caracteres ne demontrent rien.'
      AFTER `empreinte`,
  ADD COLUMN `doublon_de` INT(10) UNSIGNED NULL DEFAULT NULL
      COMMENT 'Occurrence dont celle-ci est la reenonciation. Les deux restent en base.'
      AFTER `caracteres_lus`,
  ADD COLUMN `doublon_statut` VARCHAR(32) NOT NULL DEFAULT 'UNIQUE'
      COMMENT 'UNIQUE | REENONCIATION | MEME CLE CONTENU DIFFERENT'
      AFTER `doublon_de`,
  ADD KEY `idx_empreinte` (`empreinte`),
  ADD KEY `idx_doublon` (`doublon_statut`);
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_crg`
  DROP COLUMN `doublon_statut`,
  DROP COLUMN `doublon_de`,
  DROP COLUMN `caracteres_lus`,
  DROP COLUMN `empreinte`;
SQL,
];
