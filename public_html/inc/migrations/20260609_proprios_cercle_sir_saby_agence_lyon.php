<?php
/**
 * Migration : rattache les propriétaires du CERCLE GROUPE SIR & SABY à l'agence
 * REGIE EMERY LYON (code 69-2). Les biens de ce cercle sont gérés par cette agence ;
 * sans cette info, les documents étaient tamponnés avec la société/agence de
 * l'utilisateur connecté (bug). On pose proprietaires.id_agence pour que le résolveur
 * (inc/bien_scope_resolver.php) renvoie la bonne agence/société.
 *
 * Portabilité local/prod : on cible par RAISON SOCIALE / NOM (stables), et on résout
 * l'agence par CODE '69-2' (et non par id, qui diffère entre environnements).
 * Idempotent.
 */
return [
    'id'          => '20260609_proprios_cercle_sir_saby_agence_lyon',
    'title'       => 'Cercle GROUPE SIR & SABY → agence REGIE EMERY LYON (69-2)',
    'description' => "Renseigne proprietaires.id_agence (REGIE EMERY LYON, code 69-2) pour SABY/SMH/FOCH/GROUPE SIR/SIR IMMO/SIRES/ELYSEE I-II/EVEREST/HIMMALAYA/CB FINANCES/SIR.",
    'created_at'  => '2026-06-09',
    'sql' => <<<'SQL'
UPDATE `proprietaires`
SET id_agence = (SELECT id FROM `agences` WHERE code_agence = '69-2' ORDER BY id LIMIT 1)
WHERE (SELECT id FROM `agences` WHERE code_agence = '69-2' LIMIT 1) IS NOT NULL
  AND (
        societe IN (
            'SARL GROUPE SIR','SARL GROUPE SIR (immo)','GROUPE SIR','MR & MME SABY',
            'SCI SMH','SCI FOCH','SCI SIRES','SCI ELYSEE I','SCI ELYSEE II',
            'SCI EVEREST','SCI HIMMALAYA','CB FINANCES','SOCIETE IMMOBILIERE DU RHONE (SIR)',
            'SCI TISSOT'
        )
        OR nom = 'SABY'
      );
SQL,
];
