<?php
/**
 * Migration : LA MÉMOIRE D'IDENTITÉ — une décision d'identité ne se redemande jamais.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CETTE TABLE EXISTE PARCE QU'UN DÉPÔT POSAIT 33 FOIS LA MÊME QUESTION, ET QUE
 *    LE SUIVANT LES AURAIT REPOSÉES TOUTES. Les arbitrages vivent dans
 *    `crgi_arbitrage`, qui est indexée par `import_id` : une réponse donnée sur un
 *    dépôt ne servait à rien au dépôt d'après. Emmanuel aurait retranché les mêmes
 *    homonymes à chaque trimestre, indéfiniment.
 *
 * ⚠️ CE N'EST PAS UN CACHE, C'EST UNE DÉCISION. Elle porte son auteur, sa date, la
 *    preuve sur laquelle elle a été prise et la version du moteur qui posait la
 *    question. On peut la relire, la contester, la retirer — mais on ne la
 *    redemande pas.
 *
 * ⚠️ ET ELLE EST BORNÉE PAR L'AGENCE. `UN CODE DE COMPTE N'EST JAMAIS GLOBAL` vaut
 *    aussi pour un code d'immeuble : « 0081 » chez une régie n'est pas « 0081 »
 *    chez une autre. La clé est donc (type, agence, clé métier), jamais la seule
 *    clé métier — sinon la mémoire ferait le contraire de ce qu'on attend d'elle.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. Cette table est du staging : elle dit ce qu'Emmanuel a
 *    tranché, pas ce que MBI porte. L'écriture dans MBI reste une phase à part.
 */

return [
    'id'          => '20260904a_crgi_identite_apprise',
    'title'       => 'Intégration CRG — mémoire des décisions d’identité',
    'description' => "Crée crgi_identite : une décision d'identité (quel immeuble MBI, quel "
                   . "occupant) prise sur un dépôt vaut pour tous les suivants, bornée par "
                   . "l'agence. Sans elle, le même homonyme est réarbitré à chaque trimestre. "
                   . "Aucune écriture métier.",
    'created_at'  => '2026-09-04',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_identite` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`          VARCHAR(24)  NOT NULL COMMENT 'IMMEUBLE | OCCUPANT | PROPRIETAIRE',
  `agence`        VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Le referentiel qui borne la cle.',
  `cle`           VARCHAR(190) NOT NULL COMMENT 'Code, ou nom|code postal — ce que le document imprime.',
  `choix`         VARCHAR(120) NOT NULL COMMENT 'La decision, telle qu Emmanuel l a formulee.',
  `mbi_id`        INT UNSIGNED NULL COMMENT 'L objet MBI designe, quand la decision en designe un.',
  `precision_h`   VARCHAR(1000) NULL,
  `preuve_pdf`    VARCHAR(255) NULL,
  `preuve_page`   INT UNSIGNED NULL,
  `import_origine` INT UNSIGNED NULL COMMENT 'Le depot ou la question a ete posee la premiere fois.',
  `moteur_commit` CHAR(40) NULL,
  `decide_par`    INT UNSIGNED NULL,
  `decide_le`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reutilisations` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Combien de fois elle a evite une question.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identite` (`type`, `agence`, `cle`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Decisions d identite durables : ce qu Emmanuel a tranche une fois pour toutes.';
SQL,
];
