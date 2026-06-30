<?php
/**
 * Migration : Ma GED Box V1 — Seed initial arborescence niveau 1 + sous-arbres clés
 *
 * Insère les 16 dossiers système racine + sous-dossiers MAILS + SYSTEME.
 * UUID générés via UUID() MySQL. tenant_id = NULL = système global (commun
 * à toutes les sociétés). Les seed sont marqués is_system=1 (intouchables
 * dans l'admin sauf super admin).
 *
 * Idempotent : INSERT IGNORE sur (parent_id, slug) — re-jouable sans casse.
 *
 * Niveau 1 (depth=0) : 16 dossiers métier
 *   00_A_CLASSER_IA, 01_DIRECTION, 02_REFERENTIEL, 03_RH,
 *   04_SYNDIC, 05_GESTION_LOCATIVE, 06_TRANSACTION, 07_COMPTABILITE,
 *   08_JURIDIQUE_CONTENTIEUX, 09_MARKETING_COMMUNICATION,
 *   10_MODELES_DOCUMENTS, 11_MAILS_COMMUNICATIONS, 12_ARCHIVES,
 *   98_REFERENTIEL_TECH, 99_PARAMETRAGE_GED, 99_SYSTEME
 *
 * Sous-arbres :
 *   11_MAILS_COMMUNICATIONS / 01_ENTRANTS, 02_SORTANTS, 03_ARCHIVES,
 *                            04_PIECES_JOINTES, 05_CONVERSATIONS_IMPORTANTES
 *   99_SYSTEME / CORBEILLE, COFFRE_SECURISE
 */

return [
    'id'          => '20260502_ged_v1_05_seed_arborescence',
    'title'       => 'Ma GED Box V1 — seed initial arborescence racine + sous-dossiers MAILS + SYSTEME',
    'description' => "Insère les 16 dossiers système racine (00_A_CLASSER_IA → 99_SYSTEME), les 5 sous-dossiers de 11_MAILS_COMMUNICATIONS, et les 2 sous-dossiers de 99_SYSTEME (CORBEILLE + COFFRE_SECURISE). Tous marqués is_system=1, tenant_id=NULL (global). Idempotent via INSERT IGNORE sur uk_ged_folders_parent_slug. Backfill path_cache et depth en SQL.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ── Niveau 1 : dossiers metier racine ───────────────────────────────────
INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
VALUES
  (UUID(), NULL, NULL, 0, '00_a_classer_ia', '00_a_classer_ia', '00 - À classer (IA)', '00_A_CLASSER_IA', 'INBOX', 'global', 'auto', 0, 1),
  (UUID(), NULL, NULL, 0, '01_direction', '01_direction', '01 - Direction', '01_DIRECTION', 'DIRECTION', 'global', 'auto', 1, 1),
  (UUID(), NULL, NULL, 0, '02_referentiel', '02_referentiel', '02 - Référentiel', '02_REFERENTIEL', 'REFERENTIEL', 'global', 'auto', 2, 1),
  (UUID(), NULL, NULL, 0, '03_rh', '03_rh', '03 - Ressources humaines', '03_RH', 'RH', 'global', 'auto', 3, 1),
  (UUID(), NULL, NULL, 0, '04_syndic', '04_syndic', '04 - Syndic', '04_SYNDIC', 'SYNDIC', 'global', 'auto', 4, 1),
  (UUID(), NULL, NULL, 0, '05_gestion_locative', '05_gestion_locative', '05 - Gestion locative', '05_GESTION_LOCATIVE', 'GESTION_LOCATIVE', 'global', 'auto', 5, 1),
  (UUID(), NULL, NULL, 0, '06_transaction', '06_transaction', '06 - Transaction', '06_TRANSACTION', 'TRANSACTION', 'global', 'auto', 6, 1),
  (UUID(), NULL, NULL, 0, '07_comptabilite', '07_comptabilite', '07 - Comptabilité', '07_COMPTABILITE', 'COMPTABILITE', 'global', 'auto', 7, 1),
  (UUID(), NULL, NULL, 0, '08_juridique_contentieux', '08_juridique_contentieux', '08 - Juridique & Contentieux', '08_JURIDIQUE_CONTENTIEUX', 'JURIDIQUE', 'global', 'auto', 8, 1),
  (UUID(), NULL, NULL, 0, '09_marketing_communication', '09_marketing_communication', '09 - Marketing & Communication', '09_MARKETING_COMMUNICATION', 'MARKETING', 'global', 'auto', 9, 1),
  (UUID(), NULL, NULL, 0, '10_modeles_documents', '10_modeles_documents', '10 - Modèles de documents', '10_MODELES_DOCUMENTS', 'MODELES', 'global', 'auto', 10, 1),
  (UUID(), NULL, NULL, 0, '11_mails_communications', '11_mails_communications', '11 - Mails & Communications', '11_MAILS_COMMUNICATIONS', 'MAILS', 'global', 'auto', 11, 1),
  (UUID(), NULL, NULL, 0, '12_archives', '12_archives', '12 - Archives', '12_ARCHIVES', 'ARCHIVES', 'global', 'auto', 12, 1),
  (UUID(), NULL, NULL, 0, '98_referentiel_tech', '98_referentiel_tech', '98 - Référentiel technique', '98_REFERENTIEL_TECH', 'REFERENTIEL_TECH', 'global', 'auto', 98, 1),
  (UUID(), NULL, NULL, 0, '99_parametrage_ged', '99_parametrage_ged', '99 - Paramétrage GED', '99_PARAMETRAGE_GED', 'PARAMETRAGE', 'global', 'auto', 99, 1),
  (UUID(), NULL, NULL, 0, '99_systeme', '99_systeme', '99 - Système', '99_SYSTEME', 'SYSTEME', 'global', 'auto', 100, 1);

-- ── Niveau 2 : sous-dossiers MAILS_COMMUNICATIONS ──────────────────────
INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/01_entrants'), '01_entrants', '01 - Entrants', '01_ENTRANTS', 'MAILS', 'global', 'auto', 1, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '11_mails_communications' LIMIT 1;

INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/02_sortants'), '02_sortants', '02 - Sortants', '02_SORTANTS', 'MAILS', 'global', 'auto', 2, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '11_mails_communications' LIMIT 1;

INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/03_archives'), '03_archives', '03 - Archives', '03_ARCHIVES', 'MAILS', 'global', 'auto', 3, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '11_mails_communications' LIMIT 1;

INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/04_pieces_jointes'), '04_pieces_jointes', '04 - Pièces jointes', '04_PIECES_JOINTES', 'MAILS', 'global', 'auto', 4, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '11_mails_communications' LIMIT 1;

INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/05_conversations_importantes'), '05_conversations_importantes', '05 - Conversations importantes', '05_CONVERSATIONS_IMPORTANTES', 'MAILS', 'global', 'auto', 5, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '11_mails_communications' LIMIT 1;

-- ── Niveau 2 : sous-dossiers SYSTEME ────────────────────────────────────
INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/corbeille'), 'corbeille', 'Corbeille', 'CORBEILLE', 'SYSTEME', 'global', 'auto', 1, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '99_systeme' LIMIT 1;

INSERT IGNORE INTO `ged_folders`
  (`uuid`, `tenant_id`, `parent_id`, `depth`, `path_cache`, `slug`, `name_display`, `name_canonical`, `module`, `scope`, `source_type`, `position`, `is_system`)
SELECT UUID(), NULL, p.id, 1, CONCAT(p.slug, '/coffre_securise'), 'coffre_securise', 'Coffre sécurisé', 'COFFRE_SECURISE', 'SYSTEME', 'global', 'auto', 2, 1
FROM `ged_folders` p WHERE p.parent_id IS NULL AND p.slug = '99_systeme' LIMIT 1;

-- ── Seed des niveaux par module (paramétrage 6 niveaux par défaut) ─────
INSERT IGNORE INTO `ged_folder_levels`
  (`tenant_id`, `module`, `level_number`, `level_name`, `description`, `is_required`, `is_editable`)
VALUES
  (NULL, 'DEFAULT', 1, 'Métier',     'Service ou domaine principal',                 1, 0),
  (NULL, 'DEFAULT', 2, 'Domaine',    'Sous-domaine du métier',                       0, 1),
  (NULL, 'DEFAULT', 3, 'Type',       'Type de dossier ou de document',               0, 1),
  (NULL, 'DEFAULT', 4, 'Entité',     'Immeuble, bien, mandat, contrat, employé...',  0, 1),
  (NULL, 'DEFAULT', 5, 'Année',      'Année du document (YYYY)',                     0, 1),
  (NULL, 'DEFAULT', 6, 'Mois/Sous-type', 'Mois (MM) ou sous-type fin',               0, 1);
SQL,
];
