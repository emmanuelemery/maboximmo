<?php
/**
 * Migration : L'ARBITRAGE DEVIENT AUDITABLE, ET LA DÉCISION SE DISTINGUE DE LA RÈGLE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── POURQUOI CES COLONNES ──────────────────────────────────────────────────────
 * `crgi_arbitrage` savait enregistrer un choix. Elle ne savait pas dire QUAND on
 * l'avait pris, avec QUEL moteur, sur QUELLE preuve, ni si Emmanuel avait choisi
 * une proposition ou écrit la sienne. Une décision qu'on ne peut pas rejouer
 * n'est pas auditable : c'est un souvenir.
 *
 * ⚠️ UNE DÉCISION INDIVIDUELLE N'EST PAS UNE RÈGLE, ET LA BASE DOIT LE DIRE.
 *    `portee` sépare les deux : `CAS` ne concerne que cet événement, `GROUPE` a
 *    été appliquée explicitement à un ensemble décrit, `APPRENTISSAGE` a été
 *    proposée comme phénomène généralisable. Sans cette colonne, la première
 *    décision commode devient une règle universelle par accident.
 *
 * ⚠️ ET LA PROPAGATION N'EST JAMAIS SILENCIEUSE. `groupe_applique` porte la
 *    définition exacte de l'ensemble auquel une décision groupée a été étendue,
 *    et `groupe_taille` le nombre de lignes touchées. Une validation groupée
 *    dont on ne peut plus dire ce qu'elle a couvert est irrattrapable.
 *
 * ⚠️ LA PREUVE VOYAGE AVEC LA DÉCISION. `preuve_page` et `preuve_sha` figent le
 *    document et la page sur lesquels Emmanuel a tranché. Le PDF de staging
 *    disparaît à l'annulation de l'import : sans ces deux colonnes, la décision
 *    survivrait à sa preuve.
 *
 * ⚠️ ET LA VERSION DU MOTEUR EST UNE DONNÉE DE LA DÉCISION. `moteur_commit` et
 *    `registre_sha` disent avec quelles règles la question a été posée. Une
 *    réponse juste sous un moteur donné peut ne plus l'être sous un autre : on
 *    veut pouvoir le savoir, pas le deviner.
 *
 * ⚠️ Statements additifs et rejouables : chaque ALTER est gardé par un test de
 *    présence, la migration peut donc tourner deux fois sans casse.
 */

return [
    'id'          => '20260903a_crgi_arbitrage_audit',
    'title'       => 'Intégration CRG — arbitrages auditables, décision ≠ règle',
    'description' => "Ajoute à crgi_arbitrage : statut, portée (CAS/GROUPE/APPRENTISSAGE), "
                   . "proposition et confiance de l'agent, preuve documentaire figée "
                   . "(pdf/page/sha), version du moteur et du registre, définition du groupe "
                   . "appliqué. Aucune écriture métier.",
    'created_at'  => '2026-09-03',

    'sql' => <<<'SQL'
-- Le statut d'une décision : une file d'attente a besoin de savoir ce qui reste à faire.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'statut');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage` ADD COLUMN `statut` VARCHAR(20) NOT NULL DEFAULT 'VALIDE'
     COMMENT 'VALIDE | REPORTE | INDETERMINABLE' AFTER `choix`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- La portée : ce qui empêche une décision commode de devenir une règle par accident.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'portee');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage` ADD COLUMN `portee` VARCHAR(16) NOT NULL DEFAULT 'CAS'
     COMMENT 'CAS | GROUPE | APPRENTISSAGE' AFTER `statut`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Ce que l'agent proposait, et avec quelle confiance : on veut savoir s'il avait raison.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'agent_proposition');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage`
     ADD COLUMN `agent_proposition` VARCHAR(120) NULL DEFAULT NULL
       COMMENT 'La qualification que l agent proposait en premier.' AFTER `portee`,
     ADD COLUMN `agent_confiance` TINYINT(3) UNSIGNED NULL DEFAULT NULL
       COMMENT 'Sa confiance en pourcent. NULL = il ne proposait rien.' AFTER `agent_proposition`,
     ADD COLUMN `agent_suivi` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT '1 si Emmanuel a retenu la proposition de l agent.' AFTER `agent_confiance`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- La preuve, figée : le PDF de staging disparaît, la décision reste.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'preuve_page');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage`
     ADD COLUMN `preuve_page` INT(10) UNSIGNED NULL DEFAULT NULL
       COMMENT 'La page du PDF sur laquelle la decision a ete prise.' AFTER `agent_suivi`,
     ADD COLUMN `preuve_pdf` VARCHAR(255) NULL DEFAULT NULL
       COMMENT 'Le nom d origine du document.' AFTER `preuve_page`,
     ADD COLUMN `preuve_sha` CHAR(64) NULL DEFAULT NULL
       COMMENT 'L empreinte du document : la preuve survit au staging.' AFTER `preuve_pdf`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Avec quelles règles la question a été posée.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'moteur_commit');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage`
     ADD COLUMN `moteur_commit` CHAR(40) NULL DEFAULT NULL
       COMMENT 'Le commit du moteur au moment de la decision.' AFTER `preuve_sha`,
     ADD COLUMN `registre_sha` CHAR(64) NULL DEFAULT NULL
       COMMENT 'L empreinte du registre d apprentissage a ce moment.' AFTER `moteur_commit`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Une décision groupée dit ce qu'elle a couvert, ou elle est irrattrapable.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'groupe_applique');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage`
     ADD COLUMN `groupe_applique` VARCHAR(400) NULL DEFAULT NULL
       COMMENT 'La definition exacte de l ensemble auquel la decision a ete etendue.'
       AFTER `registre_sha`,
     ADD COLUMN `groupe_taille` INT(10) UNSIGNED NULL DEFAULT NULL
       COMMENT 'Le nombre de lignes couvertes par cette extension.' AFTER `groupe_applique`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Le temps humain se mesure, il ne s'estime pas.
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND COLUMN_NAME = 'secondes_humain');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage` ADD COLUMN `secondes_humain` INT(10) UNSIGNED NULL DEFAULT NULL
     COMMENT 'Secondes passees sur cet arbitrage, mesurees par l ecran.' AFTER `groupe_taille`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- On interroge la file par statut : elle sera lue à chaque « valider et suivant ».
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_arbitrage'
              AND INDEX_NAME = 'idx_statut');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_arbitrage` ADD KEY `idx_statut` (`import_id`, `statut`)",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SQL,

    'down' => <<<'SQL'
ALTER TABLE `crgi_arbitrage`
  DROP COLUMN `statut`, DROP COLUMN `portee`,
  DROP COLUMN `agent_proposition`, DROP COLUMN `agent_confiance`, DROP COLUMN `agent_suivi`,
  DROP COLUMN `preuve_page`, DROP COLUMN `preuve_pdf`, DROP COLUMN `preuve_sha`,
  DROP COLUMN `moteur_commit`, DROP COLUMN `registre_sha`,
  DROP COLUMN `groupe_applique`, DROP COLUMN `groupe_taille`,
  DROP COLUMN `secondes_humain`;
SQL,
];
