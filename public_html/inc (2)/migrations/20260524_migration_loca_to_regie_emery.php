<?php
/**
 * Migration : Bascule LOCA IMMO Holding → REGIE EMERY (prod)
 *
 * Origine :
 *   - Erreur d'affectation des biens/immeubles/docs au chargement initial
 *   - Tout est tagué soc #3 (LOCA IMMO) + agence #7 (ST MARTIN LA PLAINE)
 *     alors qu'ils devraient être sous soc #1 (Régie EMERY) + agence #3 (LYON)
 *
 * Validation user : décision explicite GO 2026-05-24 (5 questions clarifiées)
 *
 * Périmètre migration (8 tables, ~150 rows en dev — chiffres prod à vérifier) :
 *   - biens, immeubles, tiers : id_societe=3 → id_societe=1 + id_agence=3
 *   - mandats : id_agence=7 → id_agence=3
 *   - ged_documents : societe_id=3 → societe_id=1 + agence_id=3
 *   - fluxbox_documents, fluxbox_cartes, fluxbox_actions_ia : tenant_id=3 → tenant_id=1
 *
 * EXCLUSIONS (RH PRÉSERVÉE — NE PAS TOUCHER) :
 *   - users id_societe=3 (collaborateurs RH)
 *   - Tables rh_*, conges*, salaires*, ik_* (non concernées par cette migration)
 *
 * Idempotence :
 *   - WHERE strict sur la source (id_societe=3 ou tenant_id=3)
 *   - Re-exécution = 0 row affecté (puisque plus aucune row source après 1ère exec)
 *   - Le runner _migrations_applied empêche la double exécution
 *
 * ⚠️ PRÉREQUIS PROD AVANT APPLY :
 *   - Backup global mysqldump (filet sécurité)
 *   - Vérifier que les IDs societes/agences sont identiques en prod :
 *       SELECT id, raison_sociale FROM societes WHERE id IN (1, 3);
 *       SELECT id, code_agence, nom_agence FROM agences WHERE id IN (3, 7);
 *     → Si IDs différents en prod, ADAPTER les constantes (ou créer une migration prod-spécifique)
 *
 * Pas de DDL, pas de DELETE. UPDATE only.
 */

return [
    'id'          => '20260524_migration_loca_to_regie_emery',
    'title'       => 'Migration biens/immeubles/docs : LOCA IMMO Holding → Régie EMERY (Lyon)',
    'description' => "Bascule de TOUS les biens/immeubles/tiers/mandats/ged_documents tagués société #3 (LOCA IMMO Holding) + agence #7 (ST MARTIN LA PLAINE) vers société #1 (Régie EMERY) + agence #3 (REGIE EMERY LYON). Aussi tenant_id=3 → tenant_id=1 sur fluxbox_documents/cartes/actions_ia. EXCLUSIONS: users id_societe=3 (3 rows RH PRÉSERVÉE), tables rh_*, conges*, salaires*, ik_*. UPDATE only, pas de DDL. Idempotent par WHERE strict. Exécuté en dev 2026-05-24 (11/11 vérifs PASS, backup SQL dispo dans c:/tmp/).",
    'created_at'  => '2026-05-24',
    'sql' => <<<'SQL'

-- ════════════════════════════════════════════════════════════════════════
-- MIGRATION LOCA IMMO → REGIE EMERY (8 UPDATE atomiques, idempotents)
-- ════════════════════════════════════════════════════════════════════════
--
-- Source : société #3 LOCA IMMO Holding (+ agence #7 ST MARTIN LA PLAINE)
-- Cible  : société #1 Régie EMERY    (+ agence #3 REGIE EMERY LYON)
--
-- ⚠️ AUCUNE COLONNE users TOUCHÉE — RH PRÉSERVÉE
-- ⚠️ AUCUNE TABLE rh_*, conges*, salaires*, ik_* TOUCHÉE
-- ════════════════════════════════════════════════════════════════════════

-- ─── 1. biens : id_societe + id_agence ─────────────────────────────────
UPDATE `biens`
SET `id_societe` = 1,
    `id_agence`  = 3,
    `date_modification` = NOW()
WHERE `id_societe` = 3;

-- ─── 2. immeubles : id_societe + id_agence ─────────────────────────────
UPDATE `immeubles`
SET `id_societe` = 1,
    `id_agence`  = 3,
    `date_modification` = NOW()
WHERE `id_societe` = 3;

-- ─── 3. tiers : id_societe + id_agence ─────────────────────────────────
UPDATE `tiers`
SET `id_societe` = 1,
    `id_agence`  = 3,
    `date_modification` = NOW()
WHERE `id_societe` = 3;

-- ─── 4. mandats : id_agence (pas de id_societe sur cette table) ────────
UPDATE `mandats`
SET `id_agence` = 3,
    `date_modification` = NOW()
WHERE `id_agence` = 7;

-- ─── 5. ged_documents : societe_id + agence_id ─────────────────────────
UPDATE `ged_documents`
SET `societe_id` = 1,
    `agence_id`  = 3,
    `updated_at` = NOW()
WHERE `societe_id` = 3;

-- ─── 6. fluxbox_documents : tenant_id (multi-tenant) ───────────────────
UPDATE `fluxbox_documents`
SET `tenant_id` = 1
WHERE `tenant_id` = 3;

-- ─── 7. fluxbox_cartes : tenant_id ─────────────────────────────────────
UPDATE `fluxbox_cartes`
SET `tenant_id` = 1
WHERE `tenant_id` = 3;

-- ─── 8. fluxbox_actions_ia : tenant_id ─────────────────────────────────
UPDATE `fluxbox_actions_ia`
SET `tenant_id` = 1
WHERE `tenant_id` = 3;

-- ════════════════════════════════════════════════════════════════════════
-- VÉRIFICATIONS POST-MIGRATION (à exécuter manuellement après apply)
-- ════════════════════════════════════════════════════════════════════════
--
-- Source vidée côté immo :
--   SELECT COUNT(*) FROM biens             WHERE id_societe = 3;  -- attendu 0
--   SELECT COUNT(*) FROM immeubles         WHERE id_societe = 3;  -- attendu 0
--   SELECT COUNT(*) FROM tiers             WHERE id_societe = 3;  -- attendu 0
--   SELECT COUNT(*) FROM mandats           WHERE id_agence  = 7;  -- attendu 0
--   SELECT COUNT(*) FROM ged_documents     WHERE societe_id = 3;  -- attendu 0
--   SELECT COUNT(*) FROM fluxbox_documents WHERE tenant_id  = 3;  -- attendu 0
--   SELECT COUNT(*) FROM fluxbox_cartes    WHERE tenant_id  = 3;  -- attendu 0
--   SELECT COUNT(*) FROM fluxbox_actions_ia WHERE tenant_id = 3;  -- attendu 0
--
-- RH PRÉSERVÉE :
--   SELECT COUNT(*) FROM users WHERE id_societe = 3;  -- attendu INCHANGÉ (3 en dev)
--
-- Cible a reçu :
--   SELECT COUNT(*) FROM biens WHERE id_societe = 1 AND id_agence = 3;  -- attendu +12 (dev)
--   SELECT COUNT(*) FROM immeubles WHERE id_societe = 1 AND id_agence = 3;  -- attendu +25 (dev)
--
-- Test nom V3 : un nouveau commit MVP-T sur bien #902 doit produire un nom
-- préfixé "REGI_RE69-2_*" (au lieu de "LOCA_LI42-1_*" avant migration).
SQL,
];
