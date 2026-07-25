<?php
/**
 * Migration : consolider l'email des propriétaires vers le TIERS (source unique de vérité).
 *
 * Historiquement, un email a pu être saisi sur `proprietaires.email` alors que la donnée
 * canonique vit sur `tiers.email` (archi TIERS). On remonte l'email vers le tiers UNIQUEMENT
 * quand celui-ci n'en a pas encore → plus jamais de double saisie ; tout se lit sur le tiers.
 *
 * Règle d'or : non-destructif (ne touche que les tiers sans email) + idempotent.
 */
return [
    'id'          => '20260725d_consolider_email_proprio_vers_tiers',
    'title'       => 'Consolider proprietaires.email → tiers.email',
    'description' => "Remonte l'email saisi sur la fiche propriétaire vers le tiers lié quand le tiers n'en a pas.",
    'created_at'  => '2026-07-25',
    'sql' => <<<'SQL'
UPDATE `tiers` t
  JOIN `proprietaires` p ON p.`id_tiers` = t.`id`
   SET t.`email` = p.`email`
 WHERE p.`email` IS NOT NULL AND p.`email` <> ''
   AND (t.`email` IS NULL OR t.`email` = '');
SQL
];
