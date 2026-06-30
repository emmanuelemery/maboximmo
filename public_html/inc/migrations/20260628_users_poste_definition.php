<?php
/**
 * Migration : définition du poste (fiche de poste générale) sur le collaborateur.
 * Texte volontairement GÉNÉRAL (pas de liste exhaustive) — rédigé/assisté par l'IA
 * depuis des consignes libres saisies par l'utilisateur.
 * Idempotente (ADD COLUMN IF NOT EXISTS).
 */
return [
    'id'          => '20260628_users_poste_definition',
    'title'       => 'Users : définition du poste (fiche de poste générale)',
    'description' => "Ajoute `poste_definition` (TEXT) sur `users` pour stocker la définition générale du poste du collaborateur.",
    'created_at'  => '2026-06-28',
    'sql' => <<<'SQL'
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `poste_definition` TEXT NULL;
SQL
];
