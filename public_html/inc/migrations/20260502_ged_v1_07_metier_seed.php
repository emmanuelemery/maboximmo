<?php
/**
 * Migration : Ma GED Box V1 — Seed templates métier + 99_A_CLASSER_IA
 *
 * Crée :
 *   1. Le sous-arbre statique 05_GESTION_LOCATIVE / 01_PROPRIETAIRES
 *   2. Le dossier 99_A_CLASSER_IA (envoi auto si confidence_score < 80)
 *   3. 3 templates d'arborescence (PROPRIETAIRE, BIEN, LOCATAIRE)
 *      qui seront instanciés sous chaque entité métier réelle via
 *      ged_instantiate_template_for_entity() (cf. ged_functions.php)
 *
 * Templates (réutilisables) :
 *   - PROPRIETAIRE : 01_ADMINISTRATIF, 02_MANDATS, 03_FISCALITE,
 *                    04_COMPTABILITE, 05_BIENS
 *   - BIEN         : 01_ADMINISTRATIF, 02_DIAGNOSTICS, 03_TRAVAUX_SINISTRES,
 *                    04_LOCATAIRES
 *   - LOCATAIRE    : 01_DOSSIER, 02_BAIL, 03_ETATS_DES_LIEUX, 04_CAF,
 *                    05_COURRIERS, 06_MAILS, 07_CONTENTIEUX
 *
 * AJOUT uniquement — INSERT IGNORE pour idempotence.
 */

return [
    'id'          => '20260502_ged_v1_07_metier_seed',
    'title'       => 'Ma GED Box V1 — seed métier (sous-arbre PROPRIETAIRES + 99_A_CLASSER_IA + 3 templates)',
    'description' => "Crée 01_PROPRIETAIRES sous 05_GESTION_LOCATIVE (folder_kind=business_view), le dossier 99_A_CLASSER_IA (folder_kind=system pour l'inbox basse confiance IA <80), et 3 templates d'arborescence réutilisables (PROPRIETAIRE 5 nœuds, BIEN 4 nœuds, LOCATAIRE 7 nœuds) instanciables sous chaque entité métier réelle via ged_instantiate_template_for_entity().",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ─── 01_PROPRIETAIRES sous 05_GESTION_LOCATIVE (business_view) ──────
INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`, `folder_kind`, `is_virtual`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/01_proprietaires'), '01_proprietaires', '01 - Propriétaires', '01_PROPRIETAIRES', 'GESTION_LOCATIVE', 'global', 'auto', 1, 1, 'business_view', 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '05_gestion_locative' LIMIT 1;

-- ─── 99_A_CLASSER_IA : inbox basse confiance ─────────────────────────
INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`, `folder_kind`, `is_virtual`)
VALUES
  (UUID(), NULL, NULL, 0, '99_a_classer_ia', '99_a_classer_ia', '99 - À classer (IA basse confiance)', '99_A_CLASSER_IA', 'INBOX', 'global', 'auto', 97, 1, 'system', 1);

-- ─── Templates métier ────────────────────────────────────────────────
INSERT IGNORE INTO `ged_folder_templates`
  (`tenant_id`, `module`, `code`, `name`, `description`, `is_default`, `is_active`)
VALUES
  (NULL, 'GESTION_LOCATIVE', 'TPL_PROPRIETAIRE',
   'Template Propriétaire',
   'Sous-dossiers automatiques sous chaque propriétaire (01 ADMIN, 02 MANDATS, 03 FISCALITE, 04 COMPTA, 05 BIENS)',
   1, 1),
  (NULL, 'GESTION_LOCATIVE', 'TPL_BIEN',
   'Template Bien',
   'Sous-dossiers automatiques sous chaque bien (01 ADMIN, 02 DIAGNOSTICS, 03 TRAVAUX/SINISTRES, 04 LOCATAIRES)',
   1, 1),
  (NULL, 'GESTION_LOCATIVE', 'TPL_LOCATAIRE',
   'Template Locataire',
   'Sous-dossiers automatiques sous chaque locataire (01 DOSSIER, 02 BAIL, 03 EDL, 04 CAF, 05 COURRIERS, 06 MAILS, 07 CONTENTIEUX)',
   1, 1);

-- ─── Nœuds du template PROPRIETAIRE ─────────────────────────────────
INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '01_administratif', '01 - Administratif', 1, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_PROPRIETAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '02_mandats', '02 - Mandats', 2, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_PROPRIETAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '03_fiscalite', '03 - Fiscalité', 3, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_PROPRIETAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '04_comptabilite', '04 - Comptabilité', 4, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_PROPRIETAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '05_biens', '05 - Biens', 5, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_PROPRIETAIRE' LIMIT 1;

-- ─── Nœuds du template BIEN ─────────────────────────────────────────
INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '01_administratif', '01 - Administratif', 1, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_BIEN' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '02_diagnostics', '02 - Diagnostics', 2, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_BIEN' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '03_travaux_sinistres', '03 - Travaux & Sinistres', 3, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_BIEN' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '04_locataires', '04 - Locataires', 4, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_BIEN' LIMIT 1;

-- ─── Nœuds du template LOCATAIRE ─────────────────────────────────────
INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '01_dossier', '01 - Dossier locataire', 1, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '02_bail', '02 - Bail', 2, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '03_etats_des_lieux', '03 - États des lieux', 3, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '04_caf', '04 - CAF', 4, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '05_courriers', '05 - Courriers', 5, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '06_mails', '06 - Mails', 6, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '07_contentieux', '07 - Contentieux', 7, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_LOCATAIRE' LIMIT 1;
SQL,
];
