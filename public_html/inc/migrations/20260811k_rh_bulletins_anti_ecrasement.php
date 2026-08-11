<?php
/**
 * Migration : un bulletin CORRIGÉ ne peut plus être enterré par un dépôt plus ancien.
 *
 * Le cas vécu (CALCHERA, juin 2026, 11/08) :
 *   18:35 le bulletin corrigé est classé → v1 (le coffre venait d'être créé, elle
 *         n'avait aucune version).
 *   18:58 « À classer dans les dossiers (4 restant(s)) » reclasse le dépôt d'agence
 *         du 01/07 pour rattraper 4 collègues → il repasse AUSSI sur CALCHERA et
 *         crée une v2 depuis le PDF d'ORIGINE. La correction devient `remplace`.
 *   19:14 et 20:08 le même corrigé est redéposé pour réparer → refusé en silence.
 *
 * Deux causes, deux colonnes, un index en moins :
 *
 * 1) `source_date` — le numéro de version était calculé en `max + 1` sans jamais
 *    regarder DE QUAND date la source. Un dépôt de juillet rejoué en août passait
 *    donc devant une correction d'août. La date de la source permet à
 *    rhbc_classer_fichier() de refuser une source antérieure à la version courante.
 *    `origine` dit d'où vient la pièce ('depot' = jeu du comptable, 'corrige' =
 *    bulletin isolé renvoyé après coup) — lisible dans l'historique.
 *
 * 2) `uk_source` UNIQUE (id_user, annee, mois, source_sig) — le garde-fou
 *    anti-doublon était gravé en base : la même source ne pouvait exister qu'UNE
 *    fois par salarié et par mois. Conséquence, une correction écrasée ne pouvait
 *    plus JAMAIS être reclassée — exactement le geste de réparation. Le
 *    dédoublonnage repasse en PHP (rhbc_deja_classe), où il peut tenir compte du
 *    statut : « même source ET toujours courante » = inchangé ; « même source mais
 *    remplacée » = on la remet en tête. Index non unique conservé pour la lecture.
 *
 * Aucune ligne n'est supprimée : l'historique des versions reste entier.
 * Additif + idempotent (information_schema + PREPARE, comme 20260811j).
 */
return [
    'id'          => '20260811k_rh_bulletins_anti_ecrasement',
    'title'       => 'RH bulletins — une source antérieure ne peut plus écraser une correction',
    'description' => "Ajoute rh_bulletins.source_date et rh_bulletins.origine, et supprime l'unicité uk_source qui interdisait de reclasser un bulletin corrigé écrasé. Reprend l'existant : origine déduite de source_depot_id, source_date reprise du dépôt d'agence quand il y en a un.",
    'created_at'  => '2026-08-11',
    'sql' => <<<'SQL'
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_bulletins'
              AND COLUMN_NAME = 'source_date');
SET @s := IF(@c = 0,
  'ALTER TABLE `rh_bulletins` ADD COLUMN `source_date` DATETIME NULL AFTER `source_sig`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_bulletins'
              AND COLUMN_NAME = 'origine');
SET @s := IF(@c = 0,
  'ALTER TABLE `rh_bulletins` ADD COLUMN `origine` ENUM(''depot'',''corrige'',''manuel'') NOT NULL DEFAULT ''depot'' AFTER `source_date`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_bulletins'
              AND INDEX_NAME = 'uk_source');
SET @s := IF(@c > 0, 'ALTER TABLE `rh_bulletins` DROP INDEX `uk_source`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_bulletins'
              AND INDEX_NAME = 'idx_source');
SET @s := IF(@c = 0,
  'ALTER TABLE `rh_bulletins` ADD INDEX `idx_source` (`id_user`,`annee`,`mois`,`source_sig`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Reprise de l'existant : seule la route « bulletin corrigé » laisse source_depot_id à NULL.
UPDATE `rh_bulletins` SET `origine` = 'corrige' WHERE `source_depot_id` IS NULL;

-- Date de la SOURCE, pas du classement : celle du dépôt d'agence quand il y en a un
-- (souvent bien antérieure), sinon celle de la ligne.
UPDATE `rh_bulletins` b
  LEFT JOIN `rh_salaires_comparaisons` c ON c.`id` = b.`source_depot_id`
   SET b.`source_date` = COALESCE(c.`created_at`, b.`created_at`)
 WHERE b.`source_date` IS NULL;
SQL
];
