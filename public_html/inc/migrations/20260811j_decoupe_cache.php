<?php
/**
 * Migration : mémoriser l'analyse de découpe d'un dépôt de bulletins.
 *
 * Ouvrir « Télécharger les bulletins » relisait TOUS les PDF d'agence à chaque
 * fois (smalot + repli page à page), pour un résultat rigoureusement identique
 * tant que ni le fichier ni les fiches salariés n'ont bougé. D'où plusieurs
 * secondes d'attente à chaque ouverture, et une « analyse » redemandée alors
 * qu'elle était déjà faite.
 *
 * `decoupe_sig` = empreinte du PDF **et** des fiches salariés retenues. Elle
 * change donc si le comptable redépose un fichier, mais AUSSI si l'on corrige un
 * matricule ou un n° de sécu : le cache ne peut pas masquer une reconnaissance
 * qui vient d'être réparée.
 *
 * Additif + idempotent.
 */
return [
    'id'          => '20260811j_decoupe_cache',
    'title'       => 'Bulletins — mémoriser l\'analyse de découpe (plus de relecture inutile)',
    'description' => "Ajoute decoupe_sig / decoupe_json à rh_salaires_comparaisons : l'attribution page→salarié est conservée tant que le PDF et les fiches salariés n'ont pas changé.",
    'created_at'  => '2026-08-11',
    'sql' => <<<'SQL'
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_salaires_comparaisons'
              AND COLUMN_NAME = 'decoupe_sig');
SET @s := IF(@c = 0,
  'ALTER TABLE `rh_salaires_comparaisons` ADD COLUMN `decoupe_sig` CHAR(64) NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_salaires_comparaisons'
              AND COLUMN_NAME = 'decoupe_json');
SET @s := IF(@c = 0,
  'ALTER TABLE `rh_salaires_comparaisons` ADD COLUMN `decoupe_json` LONGTEXT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SQL
];
