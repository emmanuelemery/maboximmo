<?php
/**
 * Migration : seed des role_code utilisés par le dossier de vente dans le
 * catalogue `tiers_roles_codes` (cohérence libellés/UI). Idempotent (INSERT IGNORE).
 *
 * Les rattachements d'acteurs sont déjà validés côté PHP (dv_roles_autorises),
 * mais on enregistre les codes manquants au catalogue pour cohérence applicative.
 */
return [
    'id'          => '20260614c_dossier_vente_roles_codes',
    'title'       => 'Catalogue : rôles dossier de vente (collaborateur, notaire_acquereur)',
    'description' => "Ajoute au catalogue tiers_roles_codes les codes manquants utilisés par le dossier de vente : collaborateur, notaire_acquereur.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
INSERT IGNORE INTO tiers_roles_codes (code) VALUES ('collaborateur');
INSERT IGNORE INTO tiers_roles_codes (code) VALUES ('notaire_acquereur');
SQL
    ,
    'down' => <<<'SQL'
DELETE FROM tiers_roles_codes WHERE code IN ('collaborateur','notaire_acquereur');
SQL
];
