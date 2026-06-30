<?php
/**
 * Migration : email PERSONNEL du collaborateur.
 * `users.email` est l'identifiant de connexion (unique). On ajoute `email_perso`
 * pour stocker l'adresse personnelle extraite du CV / de la lettre de motivation.
 * Idempotente (ADD COLUMN IF NOT EXISTS).
 */
return [
    'id'          => '20260629_users_email_perso',
    'title'       => 'Users : email personnel (email_perso)',
    'description' => "Ajoute `email_perso` sur `users` (distinct de l'email de connexion).",
    'created_at'  => '2026-06-29',
    'sql' => <<<'SQL'
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_perso` VARCHAR(190) NULL AFTER `email`;
SQL
];
