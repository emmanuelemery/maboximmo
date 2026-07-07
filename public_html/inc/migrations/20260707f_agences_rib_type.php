<?php
/**
 * Migration : usage (type) du RIB principal de l'agence (carte « Coordonnées bancaires »).
 *
 * La carte historique édite le RIB unique de l'agence (agences.iban/bic/…). On lui ajoute
 * un `rib_type` (gestion / sequestre / societe) pour qu'il entre dans la résolution typée
 * (cb_resolve), au même titre que les comptes multiples de societes_rib.
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260707f_agences_rib_type',
    'title'       => 'Agences : usage du RIB principal (gestion/séquestre/société)',
    'description' => "Ajoute rib_type sur agences pour typer le RIB principal de l'agence.",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `agences` ADD COLUMN IF NOT EXISTS `rib_type` VARCHAR(20) NOT NULL DEFAULT 'gestion' AFTER `titulaire_compte`;
SQL
];
