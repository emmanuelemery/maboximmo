<?php
/**
 * Migration : mention manuscrite du signataire (« bon pour acceptation » + date recopiées).
 *
 * Au moment de la signature à distance, le signataire recopie la mention d'acceptation
 * (« Lu et approuvé, bon pour acceptation » / « Bon pour caution solidaire… ») et inscrit
 * la date à la main. On conserve cette saisie comme renfort probatoire (faisceau de preuves),
 * en plus du tracé, de l'IP et de l'horodatage.
 *
 * Règle d'or : ADDITIF + idempotent. Le code écrit cette colonne en best-effort
 * (try/catch), donc la signature fonctionne même si la migration n'est pas encore passée.
 */
return [
    'id'          => '20260724d_bail_signatures_mention_manuscrite',
    'title'       => 'Signatures bail : mention manuscrite + date',
    'description' => "Ajoute mention_manuscrite (mention d'acceptation recopiée + date) sur bail_signatures.",
    'created_at'  => '2026-07-24',
    'sql' => <<<'SQL'
ALTER TABLE `bail_signatures` ADD COLUMN IF NOT EXISTS `mention_manuscrite` VARCHAR(255) NULL AFTER `lu_approuve`;
SQL
];
