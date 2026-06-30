<?php
/**
 * Migration : active le module « bailleur » sur les sociétés qui ont des comptes bailleurs.
 *
 * Bug : getAvailableServices() ne lisait pas societes.module_bailleur (colonne existante)
 * → le service « bailleur » était toujours filtré, et les comptes rôle 9/10 (PROPRIO,
 * PROPRIO_VIP) recevaient 403 sur le module Bailleur. Le code est corrigé (SELECT complété) ;
 * il faut aussi activer le module là où il y a des bailleurs.
 */

return [
    'id'          => '20260608e_enable_module_bailleur',
    'title'       => 'Active module_bailleur sur les sociétés ayant des comptes bailleurs',
    'description' => "Met societes.module_bailleur=1 pour toute société ayant au moins un utilisateur rôle 9/10. Débloque l'accès au module Bailleur (patrimoine, biens à proposer, portefeuilles).",
    'created_at'  => '2026-06-08',
    'sql' => <<<'SQL'
UPDATE societes s
SET s.module_bailleur = 1
WHERE EXISTS (
    SELECT 1 FROM users u
    WHERE u.id_societe = s.id AND u.id_role IN (9, 10)
);
SQL
];
