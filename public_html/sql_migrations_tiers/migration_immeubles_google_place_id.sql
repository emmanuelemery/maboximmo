-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Ajout google_place_id + adresse_formatee sur immeubles
-- Créé le 2026-04-19 · Exécuté sur dev le 2026-04-19
-- ═══════════════════════════════════════════════════════════════════════
-- Objectif : support complet Google Places sur la saisie d'immeuble
-- (latitude et longitude existaient déjà sur la table).
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE `immeubles`
    ADD COLUMN `google_place_id`  VARCHAR(190) DEFAULT NULL AFTER `longitude`,
    ADD COLUMN `adresse_formatee` VARCHAR(500) DEFAULT NULL AFTER `google_place_id`;
