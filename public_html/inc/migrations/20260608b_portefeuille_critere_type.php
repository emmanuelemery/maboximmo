<?php
/**
 * Migration : critère "type de bien" (habitation / professionnel) sur les portefeuilles.
 *
 * Le cahier des charges d'un portefeuille comporte déjà prix (min/max) et surface
 * (min/max). Il manquait le type de bien recherché par l'acquéreur :
 *   - habitation  → appartement, maison, studio, etc.
 *   - professionnel → local commercial, bureau, activité, etc.
 * Ce filtre était saisi dans l'UI mais n'était PAS persisté (perdu à chaque
 * enregistrement). On ajoute la colonne pour le sauvegarder comme les autres
 * critères.
 */

return [
    'id'          => '20260608b_portefeuille_critere_type',
    'title'       => 'Colonne portefeuilles.critere_type (habitation | professionnel)',
    'description' => "Persiste le critère type de bien du cahier des charges d'un portefeuille (habitation = appart/maison/studio ; professionnel = commercial/bureau/activité). Auparavant saisi mais non enregistré.",
    'created_at'  => '2026-06-08',
    'sql' => <<<'SQL'
ALTER TABLE `portefeuilles`
    ADD COLUMN IF NOT EXISTS `critere_type` VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'Critère type de bien : habitation | pro (NULL = indifférent)'
    AFTER `critere_surf_max`;
SQL
];
