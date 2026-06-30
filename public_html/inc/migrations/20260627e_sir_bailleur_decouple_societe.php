<?php
/**
 * Migration : un BAILLEUR n'est pas une SOCIÉTÉ — pilote SIR.
 *
 * RÈGLE D'ARCHITECTURE (figée 2026-06-27) :
 *   - `societes` = UNIQUEMENT vos entités exploitantes (Régie Emery, EMERY IMMOBILIER…).
 *   - Un BAILLEUR = une/des `proprietaires` + un compte `users` (rôle bailleur,
 *     id_societe = NULL) relié par `user_proprietaires`. JAMAIS via une fausse société.
 *
 * Le « GROUPE SIR & SABY » avait été créé comme une `societes` juste pour héberger
 * le compte de connexion bailleur (users #65, rôle 10 Propriétaire VIP). Ce compte
 * accède en réalité à ses 13 SCI via `user_proprietaires` + modules ged/patrimoine —
 * donc `id_societe` ne lui sert à rien.
 *
 * Cette migration :
 *   1. Détache les comptes bailleurs (rôles 9/10) rattachés à la société « GROUPE SIR & SABY »
 *      → id_societe = NULL (leur accès reste 100 % via user_proprietaires).
 *   2. Supprime la société conteneur, devenue orpheline.
 *
 * Sécurité : ciblage par NOM (robuste si l'id diffère entre local/prod). Si la société
 * est encore référencée ailleurs (biens/agences/annonces…), la contrainte FK fera
 * échouer le DELETE de façon visible (RESTRICT) plutôt que de casser des données.
 */

return [
    'id'          => '20260627e_sir_bailleur_decouple_societe',
    'title'       => 'Bailleur ≠ société : décrochage + suppression de la société conteneur « GROUPE SIR & SABY »',
    'description' => "Passe id_societe=NULL sur les comptes bailleurs (rôles 9/10) rattachés à la société « GROUPE SIR & SABY », puis supprime cette société (conteneur artificiel d'un bailleur). L'accès bailleur reste assuré par user_proprietaires + user_bailleur_modules.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
-- 1) Décrocher les comptes bailleurs de la société conteneur SIR (un bailleur n'a pas de société)
UPDATE `users` u
JOIN `societes` s ON s.id = u.id_societe
SET u.id_societe = NULL
WHERE s.nom = 'GROUPE SIR & SABY'
  AND u.id_role IN (9, 10);

-- 2) Supprimer la société conteneur SIR, désormais orpheline
DELETE FROM `societes` WHERE `nom` = 'GROUPE SIR & SABY';
SQL
];
