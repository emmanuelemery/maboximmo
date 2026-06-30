<?php
/**
 * Migration : flag « lecture seule » sur les utilisateurs.
 *
 * Permet de créer un compte (ex. bailleur « consultation » comme Christelle SABY)
 * qui VOIT tout dans son périmètre (patrimoine assigné, historique des prix, etc.)
 * mais ne peut RIEN modifier : tous les endpoints d'écriture le rejettent
 * (cf. is_readonly_user() dans inc/auth.php).
 */

return [
    'id'          => '20260608d_users_lecture_seule',
    'title'       => 'Colonne users.lecture_seule (compte consultation, aucune écriture)',
    'description' => "Ajoute users.lecture_seule (0/1). Un utilisateur à 1 voit tout dans son périmètre mais ne peut rien enregistrer/envoyer/modifier.",
    'created_at'  => '2026-06-08',
    'sql' => <<<'SQL'
ALTER TABLE `users`
    ADD COLUMN `lecture_seule` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Compte consultation : voit tout (périmètre) mais ne modifie rien';
SQL
];
