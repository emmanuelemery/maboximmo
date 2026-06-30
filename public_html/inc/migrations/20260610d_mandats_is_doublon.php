<?php
/**
 * Migration : flag is_doublon sur mandats_registre.
 * Permet de MASQUER un doublon du registre sans le supprimer (réversible, 0 perte).
 * Idempotent : ADD COLUMN IF NOT EXISTS.
 */
return [
    'id'          => '20260610d_mandats_is_doublon',
    'title'       => 'Mandats : flag doublon (masquage réversible)',
    'description' => "Ajoute is_doublon à mandats_registre : marquer une ligne comme doublon la masque du registre/stats sans la supprimer. Annulable.",
    'created_at'  => '2026-06-10',
    'sql' => <<<'SQL'
ALTER TABLE `mandats_registre`
  ADD COLUMN IF NOT EXISTS `is_doublon` TINYINT(1) NOT NULL DEFAULT 0 AFTER `statut`;
SQL
];
