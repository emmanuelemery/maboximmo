<?php
/**
 * Migration : autoriser plusieurs comptes avec le même IBAN.
 *
 * L'index UNIQUE `uniq_soc_rib` (id_societe, iban) empêchait deux agences d'une même société
 * de partager un compte bancaire (même IBAN). Or c'est un cas légitime → on retire l'unicité.
 * L'index de recherche `idx_rib_agence_type` (posé en 20260707e) reste pour les perfs.
 *
 * Règle d'or : ADDITIF/idempotent (DROP INDEX IF EXISTS).
 */
return [
    'id'          => '20260707g_rib_drop_unique',
    'title'       => 'RIB : autoriser les doublons d\'IBAN (retrait UNIQUE uniq_soc_rib)',
    'description' => "Supprime l'index UNIQUE (id_societe, iban) pour permettre à 2 agences de partager un même compte bancaire.",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `societes_rib` DROP INDEX IF EXISTS `uniq_soc_rib`;
SQL
];
