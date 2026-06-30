<?php
/**
 * Migration : mandat de GESTION automatique sur les biens en gestion CRG.
 *
 * Tout bien rattaché à un CRG (crg_situations_locataires) est, par définition, géré
 * par la régie → il doit porter un mandat de gestion ACTIF. Cette migration crée ce
 * mandat pour les biens CRG qui n'en ont pas encore.
 *
 * Idempotente : NOT EXISTS sur un mandat gestion/gérance actif ; numéro déterministe
 * unique par bien (AUTO-G-CRG-<id>). Rejouable sans doublon.
 */
return [
    'id'          => '20260611_crg_biens_mandat_gestion',
    'title'       => 'Biens CRG : mandat de gestion automatique',
    'description' => "Crée un mandat de gestion ACTIF pour chaque bien rattaché à un CRG (crg_situations_locataires) qui n'en a pas déjà un. Idempotent.",
    'created_at'  => '2026-06-11',
    'sql' => <<<'SQL'
INSERT INTO mandats
    (id_bien, id_proprietaire, id_agence, numero_mandat,
     type_mandat, nature_mandat, exclusif, date_debut, statut, date_creation)
SELECT b.id, b.id_proprietaire, b.id_agence,
       CONCAT('AUTO-G-CRG-', b.id),
       'gestion', NULL, 0, CURDATE(), 'actif', NOW()
  FROM biens b
 WHERE EXISTS (SELECT 1 FROM crg_situations_locataires csl WHERE csl.id_bien = b.id)
   AND b.statut_bien <> 'supprime'
   AND NOT EXISTS (
        SELECT 1 FROM mandats m
         WHERE m.id_bien = b.id
           AND m.statut = 'actif'
           AND m.type_mandat IN ('gestion','gerance')
   );
SQL
];
