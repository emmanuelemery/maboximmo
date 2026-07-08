<?php
/**
 * Migration : corriger le type des comptes de NIVEAU SOCIÉTÉ.
 *
 * La migration 20260707e a posé type_compte='gestion' par DÉFAUT sur toutes les lignes
 * existantes — y compris les comptes société (id_agence NULL). Résultat : le compte société
 * ressortait comme compte de GESTION dans les baux. Or un document de gestion ne doit JAMAIS
 * afficher le compte société.
 *
 * On retype donc les comptes société-level (id_agence NULL) en 'societe'. Les comptes rattachés
 * à une agence gardent leur type (gestion/syndic/séquestre/société).
 *
 * Règle d'or : ciblé + idempotent (ne touche que id_agence NULL encore typés 'gestion').
 */
return [
    'id'          => '20260707h_rib_societe_retype',
    'title'       => 'RIB : comptes niveau société retypés en « societe »',
    'description' => "Passe les comptes societes_rib sans agence (id_agence NULL) de 'gestion' à 'societe' pour ne plus les utiliser comme compte de gestion.",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
UPDATE `societes_rib` SET `type_compte` = 'societe' WHERE `id_agence` IS NULL AND `type_compte` = 'gestion';
SQL
];
