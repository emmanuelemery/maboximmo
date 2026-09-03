<?php
/**
 * Migration : LE TEMPS MACHINE D'UNE PHASE — MESURÉ, PLUS DÉDUIT D'UNE HORLOGE MURALE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CETTE COLONNE EXISTE PARCE QUE LE TABLEAU DE BORD ANNONÇAIT
 *    « TEMPS MACHINE : 9 h 21 ». C'était l'écart entre le dépôt du fichier et la
 *    dernière validation humaine — donc une journée d'atelier, pauses et
 *    réflexions comprises. Présenter cela comme du temps de calcul aurait fait
 *    chercher une lenteur du moteur là où il n'y avait qu'un humain qui déjeune.
 *    Un indicateur faux est pire qu'un indicateur absent : on l'optimise.
 *
 * ⚠️ NULL VEUT DIRE « PAS MESURÉ », JAMAIS ZÉRO. Les phases déjà jouées avant
 *    cette migration n'ont pas de durée, et l'écran doit l'afficher comme
 *    inconnue — `ABSENCE D'INFORMATION ≠ ZÉRO`.
 *
 * ⚠️ Statement additif et rejouable : l'ALTER est gardé par un test de présence.
 */

return [
    'id'          => '20260903b_crgi_temps_machine',
    'title'       => 'Intégration CRG — durée réelle d’analyse par phase',
    'description' => "Ajoute crgi_phase.secondes_machine : la durée mesurée de l'analyse, "
                   . "pour que le KPI « temps machine » cesse d'être une horloge murale. "
                   . "NULL = non mesurée. Aucune écriture métier.",
    'created_at'  => '2026-09-03',

    'sql' => <<<'SQL'
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crgi_phase'
              AND COLUMN_NAME = 'secondes_machine');
SET @s := IF(@x = 0,
  "ALTER TABLE `crgi_phase` ADD COLUMN `secondes_machine` INT UNSIGNED NULL DEFAULT NULL
     COMMENT 'Duree reelle de l analyse. NULL = non mesuree, jamais 0.' AFTER `analyse_le`",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SQL,
];
