<?php
/**
 * Migration : flag `est_salarie` sur users + exclusion des prestataires externes
 *
 * Contexte
 * --------
 * Certaines entrées de la table `users` ne sont pas des salariés à proprement
 * parler (prestataires externes, investisseurs, comptes techniques) mais
 * apparaissent par défaut dans les listes salaires / fiches de paie / exports
 * PDF, ce qui pollue la gestion RH.
 *
 * Solution
 * --------
 * Ajout d'une colonne booléenne `users.est_salarie` (1 par défaut = tous les
 * users existants restent salariés). Les pages RH ajoutent
 * `WHERE u.est_salarie = 1` à leurs SELECT pour exclure les non-salariés.
 *
 * Le flag est togglable via un checkbox dans le formulaire de création /
 * édition user (admin uniquement).
 *
 * Pré-désactivation effectuée par cette migration
 * -----------------------------------------------
 *   - Tous les users de la société id=6 « GROUPE SIR & SABY » (prestataires)
 *     → id=65 (SIR Investisseur), id=67 (SABY Thomas)
 *   - User id=64 « Emery Pierre-Emmanuel » (Lyon, agence id=7) — n'est pas
 *     salarié de l'agence, à exclure des fiches de paie
 *
 * Ces ids sont visés explicitement par leurs codes (nom + prenom + société)
 * pour rester portable d'une instance à l'autre. Si un user n'existe pas
 * sur l'instance ciblée, le UPDATE n'a aucun effet (idempotent).
 */

return [
    'id'          => '20260430_users_est_salarie',
    'title'       => 'Users : flag est_salarie + exclusion prestataires externes (SIR/SABY/PE Emery)',
    'description' => "Ajoute `users.est_salarie` (TINYINT, défaut 1). Pré-désactive les users de la société GROUPE SIR & SABY et l'user EMERY Pierre-Emmanuel pour qu'ils n'apparaissent plus dans les listes salaires / fiches de paie / exports PDF.",
    'created_at'  => '2026-04-30',
    'sql' => <<<'SQL'
-- ────────────────────────────────────────────────────────────────────
-- 1. Ajout de la colonne (idempotent)
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `est_salarie` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Si 0 : user exclu des listes salaires / fiches de paie / exports PDF (prestataires externes, comptes techniques)',
  ADD INDEX IF NOT EXISTS `idx_users_est_salarie` (`est_salarie`);

-- ────────────────────────────────────────────────────────────────────
-- 2. Pré-désactivation des prestataires externes (idempotent)
--    Visés par société + nom/prenom pour rester portable.
-- ────────────────────────────────────────────────────────────────────

-- Tous les users des sociétés "GROUPE SIR & SABY" et "PRESTATAIRES EXTERNES"
-- (catégories génériques pour les non-salariés / prestataires)
UPDATE `users` u
JOIN `societes` s ON s.id = u.id_societe
SET u.est_salarie = 0
WHERE (
  s.nom LIKE '%SIR%'
  OR s.nom LIKE '%SABY%'
  OR s.nom LIKE '%PRESTATAIRE%EXTERNE%'
  OR s.raison_sociale LIKE '%SIR%'
  OR s.raison_sociale LIKE '%SABY%'
  OR s.raison_sociale LIKE '%PRESTATAIRE%EXTERNE%'
);

-- User Emery Pierre-Emmanuel (Lyon)
UPDATE `users`
SET `est_salarie` = 0
WHERE LOWER(`nom`)    = 'emery'
  AND (LOWER(`prenom`) LIKE '%pierre%emmanuel%' OR LOWER(`prenom`) LIKE '%pierre-emmanuel%');
SQL,
];
