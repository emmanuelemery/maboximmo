-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Préparation Phase 5 : ajout id_tiers sur les tables qui
-- référencent encore proprietaires / mandants (8 tables)
-- Créé le 2026-04-19 · Exécuté sur dev le 2026-04-19
-- ═══════════════════════════════════════════════════════════════════════
-- Objectif : préparer la bascule Phase 5 (DROP legacy) en ajoutant une
-- colonne id_tiers (nullable, FK vers tiers) sur chaque table dépendante,
-- puis backfill depuis l'id_proprietaire / id_mandant existant.
--
-- APRÈS cette migration :
-- - Les tables legacy proprietaires / mandants continuent d'exister et sont
--   opérationnelles (aucune page cassée).
-- - Les 8 tables dépendantes ont en parallèle un id_tiers prêt à l'emploi.
-- - Le code pourra migrer progressivement de id_proprietaire vers id_tiers.
-- - Quand 100% du code utilisera id_tiers : DROP des FK legacy + DROP des
--   tables legacy.
-- ═══════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Tables dépendantes de `proprietaires` ──────────────────────────────

ALTER TABLE `baux`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_proprietaire`,
    ADD KEY `idx_baux_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_baux_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `biens`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_proprietaire`,
    ADD KEY `idx_biens_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_biens_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `crg_trimestres`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_proprietaire`,
    ADD KEY `idx_crg_trimestres_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_crg_trimestres_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `documents`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_proprietaire`,
    ADD KEY `idx_documents_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_documents_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `mandats`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_proprietaire`,
    ADD KEY `idx_mandats_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_mandats_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `user_proprietaires`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_proprietaire`,
    ADD KEY `idx_user_proprietaires_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_user_proprietaires_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

-- ── Tables dépendantes de `mandants` ──────────────────────────────────

ALTER TABLE `factures`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_mandant`,
    ADD KEY `idx_factures_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_factures_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `reg_mandats`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id_mandant`,
    ADD KEY `idx_reg_mandats_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_reg_mandats_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

-- ── Backfill : propager id_tiers depuis les tables legacy ─────────────

UPDATE `baux` b
JOIN `proprietaires` p ON p.id = b.id_proprietaire
SET b.id_tiers = p.id_tiers
WHERE b.id_tiers IS NULL AND p.id_tiers IS NOT NULL;

UPDATE `biens` b
JOIN `proprietaires` p ON p.id = b.id_proprietaire
SET b.id_tiers = p.id_tiers
WHERE b.id_tiers IS NULL AND p.id_tiers IS NOT NULL;

UPDATE `crg_trimestres` c
JOIN `proprietaires` p ON p.id = c.id_proprietaire
SET c.id_tiers = p.id_tiers
WHERE c.id_tiers IS NULL AND p.id_tiers IS NOT NULL;

UPDATE `documents` d
JOIN `proprietaires` p ON p.id = d.id_proprietaire
SET d.id_tiers = p.id_tiers
WHERE d.id_tiers IS NULL AND p.id_tiers IS NOT NULL;

UPDATE `mandats` m
JOIN `proprietaires` p ON p.id = m.id_proprietaire
SET m.id_tiers = p.id_tiers
WHERE m.id_tiers IS NULL AND p.id_tiers IS NOT NULL;

UPDATE `user_proprietaires` up
JOIN `proprietaires` p ON p.id = up.id_proprietaire
SET up.id_tiers = p.id_tiers
WHERE up.id_tiers IS NULL AND p.id_tiers IS NOT NULL;

UPDATE `factures` f
JOIN `mandants` mn ON mn.id = f.id_mandant
SET f.id_tiers = mn.id_tiers
WHERE f.id_tiers IS NULL AND mn.id_tiers IS NOT NULL;

UPDATE `reg_mandats` rm
JOIN `mandants` mn ON mn.id = rm.id_mandant
SET rm.id_tiers = mn.id_tiers
WHERE rm.id_tiers IS NULL AND mn.id_tiers IS NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════
-- PROCHAINE ÉTAPE — Migration progressive du code :
--
-- Chaque page qui lit id_proprietaire / id_mandant doit basculer sur id_tiers.
-- Ex :
--   Ancien : SELECT b.* FROM biens b WHERE b.id_proprietaire = ?
--   Nouveau : SELECT b.* FROM biens b WHERE b.id_tiers = ?
--
-- Quand 100% du code utilise id_tiers :
-- 1. DROP FOREIGN KEY sur baux/biens/.../reg_mandats pointant vers proprietaires/mandants
-- 2. DROP COLUMN id_proprietaire / id_mandant (optionnel — on peut aussi garder)
-- 3. DROP TABLE proprietaires, mandants, agency_mandant
-- ═══════════════════════════════════════════════════════════════════════
