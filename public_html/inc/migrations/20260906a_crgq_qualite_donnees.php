<?php
/**
 * Migration : LE REGISTRE DE QUALITÉ — la mémoire technique des anomalies de MBI.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CES TABLES EXISTENT PARCE QU'UNE PAGE QUI RECALCULE TOUT EST AMNÉSIQUE. Sans
 *    registre, l'écran Qualité redécouvre les mêmes 27 lignes à chaque ouverture,
 *    et une anomalie qu'Emmanuel a examinée hier revient demain comme neuve. Le
 *    travail humain serait à refaire indéfiniment — exactement le défaut que la
 *    mémoire d'identité a corrigé côté CRG.
 *
 * ⚠️ ÉCRITURE TECHNIQUE ≠ ÉCRITURE MÉTIER. Emmanuel, 06/09/2026. Ces deux tables
 *    sont écrites par l'agent ; `immeubles`, `biens`, `bien_baux`, `tiers` et
 *    `proprietaires` ne le sont JAMAIS. Le préfixe `crgq_` le dit à l'œil nu.
 *
 * ⚠️ AUCUNE COLONNE `statut`. Un état mutable — NOUVELLE / TRAITÉE / LÉGITIME —
 *    se désynchronise du réel au premier changement de la base : une anomalie
 *    « fermée » que le détecteur voit toujours reste fermée, et personne ne le
 *    sait. L'état se DÉDUIT de deux séries de faits : ce que le détecteur a vu, et
 *    ce qu'Emmanuel a décidé. `détectée le X + jugée légitime le Y` = LÉGITIME ;
 *    `détectée le X + plus détectée aujourd'hui` = DISPARUE ; `détectée, aucune
 *    décision` = À EXAMINER. Rien à maintenir, rien à désynchroniser.
 *
 * ⚠️ L'EMPREINTE NE DÉPEND JAMAIS D'UN `AUTO_INCREMENT`, D'UN `import_id`, D'UNE
 *    DATE NI D'UN ORDRE D'EXÉCUTION. Elle est construite sur le phénomène réel —
 *    détecteur, périmètre, identités stables des objets concernés, triées. La même
 *    anomalie demain rend la même empreinte, sinon la décision d'hier ne se
 *    retrouve pas et le registre ne sert à rien.
 *
 * ⚠️ LE JOURNAL EST APPEND-ONLY. Une décision ne se corrige pas en place : on en
 *    pose une nouvelle, et l'historique dit ce qui a été pensé, quand, sur quelle
 *    preuve. `HISTORIQUE DES DÉCISIONS : JAMAIS DE DELETE`.
 */

return [
    'id'          => '20260906a_crgq_qualite_donnees',
    'title'       => 'Qualité des données — registre technique des anomalies et journal des décisions',
    'description' => "Crée crgq_occurrence (ce qu'un détecteur a vu, avec sa preuve et son "
                   . "empreinte stable) et crgq_decision (ce qu'Emmanuel en a décidé, "
                   . "append-only). Aucune colonne d'état : l'état se déduit des faits. "
                   . "Écriture technique uniquement — aucune donnée métier n'est touchée.",
    'created_at'  => '2026-09-06',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgq_occurrence` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empreinte`       CHAR(64)     NOT NULL COMMENT 'Identite STABLE du phenomene : detecteur + perimetre + objets tries. Jamais un id de ligne, jamais une date.',
  `detecteur`       VARCHAR(48)  NOT NULL COMMENT 'Code stable du detecteur, declare au catalogue.',
  `detecteur_version` VARCHAR(16) NOT NULL COMMENT 'La regle change ; la preuve d hier doit rester lisible.',
  `famille`         VARCHAR(48)  NOT NULL COMMENT 'MBI | INFRASTRUCTURE — jamais melangees.',
  `certitude`       VARCHAR(16)  NOT NULL COMMENT 'CERTAIN | PROBABLE | A EXAMINER. Une heuristique ne devient jamais une certitude.',
  `objet_type`      VARCHAR(32)  NOT NULL COMMENT 'immeubles, biens, parametre... ce sur quoi porte l anomalie.',
  `objet_ids`       TEXT         NOT NULL COMMENT 'Les identifiants EXACTS des faits ayant produit la detection. Un agregat sans ses ids n est pas opposable.',
  `preuve_sha`      CHAR(64)     NOT NULL COMMENT 'Empreinte des VALEURS observees : si elle change, la preuve a change.',
  `preuve`          MEDIUMTEXT   NULL COMMENT 'La preuve depliable, en JSON : ce que l ecran doit pouvoir ouvrir.',
  `contexte`        TEXT         NULL COMMENT 'Contexte technique utile au diagnostic.',
  `vue_le_premier`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `vue_le_dernier`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `vues`            INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Combien de passages l ont revue : une anomalie qui persiste se voit.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_empreinte` (`empreinte`),
  KEY `idx_detecteur` (`detecteur`),
  KEY `idx_famille` (`famille`, `certitude`),
  KEY `idx_dernier` (`vue_le_dernier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Ce qu un detecteur de qualite a VU. Aucun etat : l etat se deduit des faits.';

CREATE TABLE IF NOT EXISTS `crgq_decision` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empreinte`       CHAR(64)     NOT NULL COMMENT 'L occurrence concernee, par son empreinte stable.',
  `decision`        VARCHAR(32)  NOT NULL COMMENT 'LEGITIME | A CORRIGER | ORPHELIN PROBABLE | DOUBLON PROBABLE | A EXAMINER | REOUVERTE',
  `portee`          VARCHAR(24)  NOT NULL DEFAULT 'OCCURRENCE' COMMENT 'OCCURRENCE | FAMILLE — jusqu ou vaut la decision.',
  `commentaire`     VARCHAR(1000) NULL,
  `preuve_sha`      CHAR(64)     NOT NULL COMMENT 'La preuve EXAMINEE. Si la preuve d aujourd hui differe, la decision est a reexaminer.',
  `detecteur_version` VARCHAR(16) NOT NULL COMMENT 'Sous quelle regle la decision a ete prise.',
  `decide_par`      INT UNSIGNED NULL,
  `decide_le`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empreinte` (`empreinte`, `decide_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Journal APPEND-ONLY des decisions humaines. On ne corrige pas une decision : on en pose une autre.';

CREATE TABLE IF NOT EXISTS `crgq_infra_reglage` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `environnement`   VARCHAR(16)  NOT NULL COMMENT 'LOCAL | VPS — le VPS ne se modifie pas.',
  `parametre`       VARCHAR(64)  NOT NULL,
  `valeur_avant`    VARCHAR(190) NOT NULL,
  `valeur_apres`    VARCHAR(190) NOT NULL,
  `raison`          VARCHAR(500) NOT NULL COMMENT 'Le blocage CONSTATE, pas une bonne pratique generale.',
  `effet_mesure`    VARCHAR(500) NULL COMMENT 'Ce qui a change apres, mesure — sinon on ne sait pas si ca a servi.',
  `applique_le`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applique_par`    INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_env` (`environnement`, `applique_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Reglages d infrastructure appliques en LOCAL : avant, apres, raison, effet. Reversible et trace.';
SQL,
];
