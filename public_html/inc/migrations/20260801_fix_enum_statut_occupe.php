<?php
/**
 * Migration : corrige les ENUM corrompus `statut_occupation` (biens) et `statut_trimestre`
 * (crg_situations_locataires).
 *
 * PROBLÈME : le libellé « occupé » y avait été défini avec des octets MOJIBAKE
 * (occup├® / parti-d├®biteur, soit U+251C U+00AE au lieu de « é »). Conséquence : toute
 * écriture propre « occupé » (utf8mb4, é = C3 A9) ne matchait plus le membre d'ENUM →
 * MySQL (non strict) stockait '' silencieusement. L'import CRG ne pouvait donc plus
 * renseigner l'occupation (1879 statut_trimestre vides, 44 statut_occupation vides).
 *
 * CORRECTIF (non destructif, idempotent, LOCAL + PROD) :
 *   1) MODIFY VARCHAR  → 2) normalise occup├®→occupé / parti-d├®biteur→parti-débiteur
 *   → 3) MODIFY ENUM propre  → 4) BACKFILL des '' restants depuis la vérité
 *      (bail actif / loyer_appele>0). L'ordre VARCHAR-puis-UPDATE est IMPÉRATIF :
 *      un UPDATE avant le passage en VARCHAR tomberait sur l'ENUM corrompu → ''.
 *
 * Le backfill corrige aussi les '' HISTORIQUES (statut_trimestre jamais renseigné).
 */
return [
    'id'          => '20260801_fix_enum_statut_occupe',
    'title'       => 'Fix ENUM corrompus statut_occupation / statut_trimestre (occupé) + backfill',
    'description' => "VARCHAR -> normalise occupé/parti-débiteur -> ENUM utf8 propre -> backfill des vides depuis bail/CRG. Debloque l'ecriture de l'occupation par l'import CRG.",
    'created_at'  => '2026-08-01',
    'sql' => <<<SQL
ALTER TABLE biens MODIFY statut_occupation VARCHAR(30) NULL DEFAULT 'vacant';
UPDATE biens SET statut_occupation='occupé' WHERE statut_occupation LIKE 'occup%';
UPDATE biens SET statut_occupation='parti-débiteur' WHERE statut_occupation LIKE 'parti%';
ALTER TABLE biens MODIFY statut_occupation ENUM('occupé','vacant','parti-débiteur','en_travaux') NULL DEFAULT 'vacant';
UPDATE biens b SET statut_occupation = CASE WHEN EXISTS(SELECT 1 FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif') OR EXISTS(SELECT 1 FROM crg_situations_locataires crg WHERE crg.id_bien=b.id AND crg.loyer_appele>0) THEN 'occupé' ELSE 'vacant' END WHERE b.statut_occupation='' OR b.statut_occupation IS NULL;
ALTER TABLE crg_situations_locataires MODIFY statut_trimestre VARCHAR(30) NOT NULL DEFAULT 'occupé';
UPDATE crg_situations_locataires SET statut_trimestre='occupé' WHERE statut_trimestre LIKE 'occup%';
UPDATE crg_situations_locataires SET statut_trimestre='parti-débiteur' WHERE statut_trimestre LIKE 'parti%';
ALTER TABLE crg_situations_locataires MODIFY statut_trimestre ENUM('occupé','parti-débiteur','vacant') NOT NULL DEFAULT 'occupé';
UPDATE crg_situations_locataires SET statut_trimestre = CASE WHEN locataire_nom='LOGEMENT VACANT' THEN 'vacant' WHEN loyer_appele>0 OR locataire_nom='OCCUPÉ PAR PROPRIÉTAIRE' THEN 'occupé' ELSE 'parti-débiteur' END WHERE statut_trimestre='' OR statut_trimestre IS NULL;
SQL,
];
