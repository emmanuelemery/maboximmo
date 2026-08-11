<?php
/**
 * Migration : l'historique des échanges accepte le BULLETIN CORRIGÉ.
 *
 * `rh_salaire_workflow_log.type_action` est un ENUM à 4 valeurs. Y écrire
 * 'bulletin_corrige' sans l'avoir déclaré donne, en MySQL non strict, une ligne
 * au type VIDE — enregistrée, mais sans libellé ni filtre possible ; en mode
 * strict, l'INSERT échoue et la trace est perdue. Dans les deux cas le classement
 * d'un bulletin corrigé n'apparaît nulle part, alors que l'historique est
 * précisément ce qui dit où en est le mois.
 *
 * Répare aussi les lignes déjà tombées à '' pour ce motif (elles portent le
 * commentaire « Classé au coffre : … », signature sans ambiguïté).
 *
 * Additif + idempotent.
 */
return [
    'id'          => '20260811i_wf_log_bulletin_corrige',
    'title'       => 'RH workflow — nouvelle étape « bulletin corrigé » dans l\'historique',
    'description' => "Étend l'ENUM rh_salaire_workflow_log.type_action avec 'bulletin_corrige' et répare les lignes tombées à '' faute de cette valeur.",
    'created_at'  => '2026-08-11',
    'sql' => <<<'SQL'
ALTER TABLE `rh_salaire_workflow_log`
  MODIFY COLUMN `type_action`
  ENUM('envoi_comptable','import_projet','import_bulletins','validation_projet','bulletin_corrige')
  NOT NULL;

UPDATE `rh_salaire_workflow_log`
   SET `type_action` = 'bulletin_corrige'
 WHERE `type_action` = ''
   AND `commentaire` LIKE 'Classé au coffre :%';
SQL
];
