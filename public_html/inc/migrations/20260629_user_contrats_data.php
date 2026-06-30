<?php
/**
 * Migration : stockage complet du contrat (tous les champs du formulaire) en JSON.
 * Les colonnes dédiées (type, niveau, salaire…) servent à l'affichage de la liste ;
 * `data_json` garde TOUS les champs (prévoyance, santé, période d'essai, supérieur,
 * délai de prévenance, URSSAF, employeur…) pour une reprise fidèle du brouillon.
 * Idempotente (ADD COLUMN IF NOT EXISTS).
 */
return [
    'id'          => '20260629_user_contrats_data',
    'title'       => 'user_contrats : données complètes du contrat (data_json)',
    'description' => "Ajoute `data_json` (TEXT) sur `user_contrats` pour conserver l'intégralité des champs du contrat et permettre une reprise/modification fidèle jusqu'à la signature.",
    'created_at'  => '2026-06-29',
    'sql' => <<<'SQL'
ALTER TABLE `user_contrats` ADD COLUMN IF NOT EXISTS `data_json` TEXT NULL;
SQL
];
