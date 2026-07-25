<?php
/**
 * Migration : ajoute le type de document « Convocation d'assemblée générale » (Syndic).
 *
 * Rejoint PV_AG dans l'onglet Syndic du sélecteur de type. Reconnu comme type à part entière
 * (plus besoin de « Autre… »), avec abréviation CVAG en position 8 du nom GED. Comme pour un PV,
 * le modal proposera la « Date de l'assemblée » (position 9 du nom) — géré côté JS.
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260725a_ged_type_convocation_ag',
    'title'       => 'Type GED : Convocation AG (Syndic)',
    'description' => "Ajoute le type CONVOCATION_AG (abbr CVAG, métier syndic) dans ged_document_types.",
    'created_at'  => '2026-07-25',
    'sql' => <<<'SQL'
INSERT INTO `ged_document_types` (`code`, `libelle`, `abbr`, `metier`, `actif`, `created_at`)
SELECT 'CONVOCATION_AG', "Convocation d'assemblée générale", 'CVAG', 'syndic', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `ged_document_types` WHERE `code` = 'CONVOCATION_AG');
SQL
];
