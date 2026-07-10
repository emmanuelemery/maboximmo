<?php
/**
 * Migration : ajoute au glossaire des types de documents (ged_document_types) les 6 mandats,
 * avec leur code court (abbr) utilisé dans le nom GED. Un type avec `metier` renseigné
 * apparaît automatiquement dans le bon onglet du modal de chargement (Gestion / Transaction)
 * et devient reconnu/chargeable. Idempotent : ne remplit abbr/metier que s'ils sont vides.
 *
 *   mandat gestion          → MGestNS   (gestion)
 *   mandat gestion signé    → MGestS    (gestion)
 *   mandat vente            → MventeNS  (transaction)
 *   mandat vente signé      → MVenteS   (transaction)
 *   mandat location         → MLocNS    (gestion)
 *   mandat location signé   → MlocS     (gestion)
 */
return [
    'id'          => '20260710a_types_mandats_glossaire',
    'title'       => 'Glossaire : 6 types de mandats (gestion/vente/location, signé/non signé)',
    'description' => "Ajoute au glossaire ged_document_types les mandats gestion/vente/location (signés et non signés) avec leur code court (abbr) et leur métier, pour qu'ils soient chargeables et reconnus.",
    'created_at'  => '2026-07-10',
    'sql' => <<<'SQL'
INSERT INTO `ged_document_types` (`code`, `libelle`, `abbr`, `metier`, `actif`) VALUES
  ('MANDAT_GESTION',        'mandat gestion',        'MGestNS',  'gestion',     1),
  ('MANDAT_GESTION_SIGNE',  'mandat gestion signé',  'MGestS',   'gestion',     1),
  ('MANDAT_VENTE',          'mandat vente',          'MventeNS', 'transaction', 1),
  ('MANDAT_VENTE_SIGNE',    'mandat vente signé',    'MVenteS',  'transaction', 1),
  ('MANDAT_LOCATION',       'mandat location',       'MLocNS',   'gestion',     1),
  ('MANDAT_LOCATION_SIGNE', 'mandat location signé', 'MlocS',    'gestion',     1)
ON DUPLICATE KEY UPDATE
  `abbr`   = COALESCE(NULLIF(`abbr`, ''),   VALUES(`abbr`)),
  `metier` = COALESCE(NULLIF(`metier`, ''), VALUES(`metier`)),
  `actif`  = 1;
SQL
];
