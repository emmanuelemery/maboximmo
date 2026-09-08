<?php
/**
 * Migration : LE JOURNAL D'INTÉGRATION — CE QUI PERMET DE TOUT DÉFAIRE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ SANS LUI, « ANNULABLE » EST UN MOT. Le moteur d'écriture existant ne sait
 *    revenir en arrière que sur DEUX tables — `biens` et `immeubles`, passées en
 *    « archive ». Les propriétaires, tiers, mandats, baux et statuts de locataires
 *    qu'il crée sont « sautés » : ils restent. Un import raté laissait donc des
 *    personnes et des baux qu'il fallait retrouver à la main.
 *
 * ⚠️ ON JOURNALISE L'ÉCRITURE, PAS L'INTENTION. Chaque ligne dit la table, la
 *    clé du document qui l'a produite, l'identifiant créé ou modifié, et — pour
 *    une modification — L'ÉTAT D'AVANT en clair. C'est cet état d'avant qui rend
 *    l'annulation possible : sans lui, on ne saurait que supprimer, jamais
 *    restaurer.
 *
 * ⚠️ ET ON NE SUPPRIME PAS CE QU'ON N'A PAS CRÉÉ. Une ligne modifiée revient à
 *    sa valeur d'avant ; une ligne créée par CET import est supprimée ; une ligne
 *    qui existait déjà n'est jamais touchée. La distinction se lit dans
 *    `action` — c'est elle qui empêche une annulation de détruire du patrimoine
 *    saisi à la main.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER — une table de traçabilité.
 */
return [
    'id'          => '20260908d_crgi_journal_integration',
    'title'       => 'Intégration CRG — le journal qui rend l’import annulable',
    'description' => "Crée crgi_journal : une ligne par écriture métier (table, clé CRG, id "
                   . "touché, action CREER|MODIFIER, état d'avant en JSON). C'est ce qui "
                   . "permet d'annuler un import ENTIER — pas seulement les biens et les "
                   . "immeubles, comme le moteur historique. Aucune écriture métier.",
    'created_at'  => '2026-09-08',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_journal` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`   INT UNSIGNED NOT NULL,
  `passe`       INT UNSIGNED NOT NULL COMMENT 'Numero de passe : on peut integrer plusieurs fois.',
  `famille`     VARCHAR(20)  NOT NULL COMMENT 'TIERS | IMMEUBLE | BIEN | BAIL | OCCUPATION | ROLE',
  `table_cible` VARCHAR(64)  NOT NULL,
  `id_objet`    INT UNSIGNED NOT NULL,
  `action`      VARCHAR(10)  NOT NULL COMMENT 'CREER = supprimable | MODIFIER = restaurable',
  `cle_crg`     VARCHAR(190) NOT NULL COMMENT 'La cle du document qui a produit cette ecriture.',
  `avant`       LONGTEXT     DEFAULT NULL COMMENT 'L etat d AVANT, en JSON. NULL pour une creation.',
  `motif`       VARCHAR(400) NOT NULL DEFAULT '',
  `ecrit_le`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ecrit_par`   INT UNSIGNED DEFAULT NULL,
  `annule_le`   DATETIME     DEFAULT NULL COMMENT 'Renseigne quand la ligne a ete defaite.',
  PRIMARY KEY (`id`),
  KEY `idx_import_passe` (`import_id`, `passe`),
  KEY `idx_cible` (`table_cible`, `id_objet`),
  KEY `idx_cle` (`import_id`, `famille`, `cle_crg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Journal des ecritures metier d un import CRG. Sans lui, annulable est un mot.';
SQL,
];
