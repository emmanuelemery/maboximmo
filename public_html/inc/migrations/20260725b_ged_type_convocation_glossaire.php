<?php
/**
 * Migration corrective : aligner le type « Convocation d'AG » sur le GLOSSAIRE GED.
 *
 * La 20260725a avait créé un code maison CONVOCATION_AG (abbr CVAG) — non conforme.
 * Le glossaire canonique (ged_level_codes, N1 04_SYNDIC) utilise CV_AGO (Convocation AG
 * Ordinaire) et CV_AGE (Convocation AG Extraordinaire), sur le même modèle que PV_AGO/PV_AGE.
 *
 * On retire le code maison et on enregistre les 2 types conformes dans ged_document_types
 * (métier syndic → onglet Syndic du sélecteur ; abbr = code glossaire → position 8 du nom GED).
 *
 * Règle d'or : idempotent.
 */
return [
    'id'          => '20260725b_ged_type_convocation_glossaire',
    'title'       => 'Type GED Convocation AG : conforme glossaire (CV_AGO / CV_AGE)',
    'description' => "Remplace CONVOCATION_AG (CVAG) par CV_AGO et CV_AGE (codes glossaire 04_SYNDIC).",
    'created_at'  => '2026-07-25',
    'sql' => <<<'SQL'
DELETE FROM `ged_document_types` WHERE `code` = 'CONVOCATION_AG';

INSERT INTO `ged_document_types` (`code`, `libelle`, `abbr`, `metier`, `actif`, `created_at`)
SELECT 'CV_AGO', 'Convocation AG Ordinaire', 'CV_AGO', 'syndic', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `ged_document_types` WHERE `code` = 'CV_AGO');

INSERT INTO `ged_document_types` (`code`, `libelle`, `abbr`, `metier`, `actif`, `created_at`)
SELECT 'CV_AGE', 'Convocation AG Extraordinaire', 'CV_AGE', 'syndic', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `ged_document_types` WHERE `code` = 'CV_AGE');

-- RÈGLE D'OR : tout code de type utilisé dans un nom GED DOIT exister au GLOSSAIRE
-- (ged_level_codes). Garantie idempotente (no-op si déjà présents — cas standard 04_SYNDIC).
INSERT INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `is_active`)
SELECT 0, 5, '04_SYNDIC', 'IMMEUBLES', 'CV_AGO', 'Convocation AG Ordinaire', 1
WHERE NOT EXISTS (SELECT 1 FROM `ged_level_codes` WHERE `code` = 'CV_AGO');

INSERT INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `is_active`)
SELECT 0, 5, '04_SYNDIC', 'IMMEUBLES', 'CV_AGE', 'Convocation AG Extraordinaire', 1
WHERE NOT EXISTS (SELECT 1 FROM `ged_level_codes` WHERE `code` = 'CV_AGE');
SQL
];
