<?php
/**
 * Migration : loyer décidé par le propriétaire (loyer intermédiaire).
 * En zone d'encadrement, le propriétaire a le droit d'imposer un loyer INFÉRIEUR au
 * loyer majoré (loyer intermédiaire) : il s'impose alors comme loyer HC et empêche
 * tout complément de loyer. Plafonné au loyer réf. majoré, jamais au-dessus.
 */
return [
    'id'          => '20260709a_annonce_loyer_proprietaire',
    'title'       => 'Annonces : loyer décidé par le propriétaire (≤ loyer majoré)',
    'description' => "Ajoute annonces.loyer_proprietaire : loyer intermédiaire imposé par le propriétaire, plafonné au loyer réf. majoré, prioritaire sur le mode et bloquant le complément.",
    'created_at'  => '2026-07-09',
    'sql' => <<<'SQL'
ALTER TABLE `annonces` ADD COLUMN IF NOT EXISTS `loyer_proprietaire` DECIMAL(10,2) NULL DEFAULT NULL AFTER `loyer_reference_majore`;
SQL
];
