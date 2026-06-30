<?php
/**
 * Migration : email PROFESSIONNEL du collaborateur (distinct de l'email de connexion).
 * Les coordonnées professionnelles (email_pro + telephone_pro) sont celles utilisées
 * pour les annonces / diffusion Ubiflow. `users.email` reste l'identifiant de connexion.
 * Idempotente (ADD COLUMN IF NOT EXISTS).
 */
return [
    'id'          => '20260629_users_email_pro',
    'title'       => 'Users : email professionnel (email_pro)',
    'description' => "Ajoute `email_pro` sur `users` (coordonnée pro, distincte de l'email de connexion et de l'email perso).",
    'created_at'  => '2026-06-29',
    'sql' => <<<'SQL'
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_pro` VARCHAR(190) NULL AFTER `email_perso`;
SQL
];
