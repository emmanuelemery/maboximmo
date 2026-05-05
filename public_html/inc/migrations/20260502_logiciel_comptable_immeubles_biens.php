<?php
/**
 * Migration : logiciel comptable (SEPTEO / LOJJI / AUTRE) sur immeubles + biens
 *
 * Objectif : permettre le regroupement comptable global par progiciel lors des
 * imports GED (ex: relevés bancaires).
 *
 * Règle métier :
 *   - priorité à immeubles.logiciel_comptable
 *   - biens.logiciel_comptable peut overrider si nécessaire (cas particuliers)
 */

return [
    'id'          => '20260502_logiciel_comptable_immeubles_biens',
    'title'       => 'Immeubles/Biens : logiciel_comptable (SEPTEO/LOJJI/AUTRE)',
    'description' => "Ajoute un champ logiciel_comptable sur immeubles et biens pour distinguer le progiciel comptable (SEPTEO/LOJJI).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
    ADD COLUMN IF NOT EXISTS `logiciel_comptable` ENUM('SEPTEO','LOJJI','ICS','MABOXIMMO','AUTRE') NULL DEFAULT NULL
        COMMENT 'Progiciel comptable rattaché (prioritaire sur immeuble)' AFTER `reference_immeuble`;

ALTER TABLE `biens`
    ADD COLUMN IF NOT EXISTS `logiciel_comptable` ENUM('SEPTEO','LOJJI','ICS','MABOXIMMO','AUTRE') NULL DEFAULT NULL
        COMMENT 'Progiciel comptable (override éventuel)' AFTER `id_immeuble`;
SQL,
];
