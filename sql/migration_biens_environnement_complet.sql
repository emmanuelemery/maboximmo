-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Colonnes environnement manquantes sur biens
-- Créé le 2026-04-19 · Exécuté sur dev le 2026-04-19
-- ═══════════════════════════════════════════════════════════════════════
-- Bug historique (cf. project_reprise_2026_04_20) : 5 trous dans le pipeline
-- Express → biens. Cette migration ajoute les colonnes manquantes pour que
-- les valeurs saisies dans l'Express soient toutes persistées.
--
-- À rejouer sur prod Hostinger lors du merge develop → main.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE `biens`
    ADD COLUMN `ambiance`        VARCHAR(255) DEFAULT NULL COMMENT 'CSV: calme,centre_ville,proche_transports,proche_commerces,residentiel' AFTER `exposition`,
    ADD COLUMN `quartier`        VARCHAR(150) DEFAULT NULL COMMENT 'Quartier (important SEO local)' AFTER `ville`,
    ADD COLUMN `points_interet`  TEXT DEFAULT NULL         COMMENT 'Points d''intérêt supplémentaires (école, parc, transport spécifique…)' AFTER `quartier`,
    ADD COLUMN `argument_phare`  VARCHAR(255) DEFAULT NULL COMMENT 'Argument commercial clé en 1 phrase (ex: terrasse plein sud, vue mer)' AFTER `points_interet`;
