<?php
/**
 * Migration : Ma GED Box V1 — Ajout du niveau IMMEUBLE dans la hiérarchie
 *
 * Hiérarchie métier corrigée :
 *
 *   PROPRIÉTAIRE
 *     ├── 01_ADMINISTRATIF
 *     ├── 02_MANDATS
 *     ├── 03_FISCALITE
 *     ├── 04_COMPTABILITE
 *     └── 05_IMMEUBLES (renommé depuis "05_BIENS")
 *           └── {IMMEUBLE} ← NOUVEAU NIVEAU
 *                 ├── 01_ADMINISTRATIF
 *                 ├── 02_DIAGNOSTICS_COMMUNS
 *                 ├── 03_TRAVAUX_PARTIES_COMMUNES
 *                 ├── 04_COPROPRIETE
 *                 └── 05_BIENS
 *                       └── {BIEN}
 *                             ├── 01_ADMINISTRATIF
 *                             ├── 02_DIAGNOSTICS
 *                             ├── 03_TRAVAUX_SINISTRES
 *                             └── 04_LOCATAIRES
 *                                   └── {LOCATAIRE}
 *                                         (7 sous-dossiers)
 *
 * Actions :
 *   1. Renomme dans TPL_PROPRIETAIRE : "05_biens" → "05_immeubles"
 *   2. Crée TPL_IMMEUBLE (5 sous-dossiers)
 *   3. Met à jour également les ged_folders existants déjà créés sous le seed 07
 *      (si la migration 07 avait déjà créé les sous-dossiers en BDD)
 *
 * AJOUT/MODIFICATION du seed uniquement — aucune table touchée, idempotent.
 */

return [
    'id'          => '20260502_ged_v1_08_immeuble_template',
    'title'       => 'Ma GED Box V1 — niveau IMMEUBLE intercalé entre propriétaire et bien',
    'description' => "Corrige la hiérarchie métier : propriétaire → IMMEUBLE → bien → locataire (l'immeuble manquait). Renomme 05_BIENS en 05_IMMEUBLES dans TPL_PROPRIETAIRE et dans les ged_folders existants. Crée TPL_IMMEUBLE avec 5 sous-dossiers (01_ADMINISTRATIF, 02_DIAGNOSTICS_COMMUNS, 03_TRAVAUX_PARTIES_COMMUNES, 04_COPROPRIETE, 05_BIENS). Idempotent.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ─── 1. Renommer 05_BIENS → 05_IMMEUBLES dans le template propriétaire ──
UPDATE `ged_folder_template_nodes` n
JOIN `ged_folder_templates` t ON t.id = n.template_id
SET n.slug = '05_immeubles',
    n.name_display = '05 - Immeubles'
WHERE t.code = 'TPL_PROPRIETAIRE'
  AND n.slug = '05_biens';

-- ─── 2. Renommer aussi dans les ged_folders existants déjà créés ───────
-- (cas où le seed 07 a déjà créé des sous-dossiers proprio en BDD)
UPDATE `ged_folders`
SET `slug` = '05_immeubles',
    `name_display` = '05 - Immeubles',
    `name_canonical` = '05_IMMEUBLES'
WHERE `slug` = '05_biens'
  AND `module` = 'GESTION_LOCATIVE'
  AND `entity_type` = 'proprietaire';

-- ─── 2bis. Met à jour le path_cache des dossiers renommés + descendants ─
-- Sinon les anciens paths "test_dupont_jean/05_biens/..." restent en cache
-- alors que le slug est passé à 05_immeubles. REPLACE est compatible MySQL 5.7+.
UPDATE `ged_folders`
SET `path_cache` = REPLACE(`path_cache`, '/05_biens/', '/05_immeubles/')
WHERE `path_cache` LIKE '%/05_biens/%';

UPDATE `ged_folders`
SET `path_cache` = REPLACE(`path_cache`, '/05_biens', '/05_immeubles')
WHERE `path_cache` LIKE '%/05_biens';

-- ─── 3. Créer le template TPL_IMMEUBLE ─────────────────────────────────
INSERT IGNORE INTO `ged_folder_templates`
  (`tenant_id`, `module`, `code`, `name`, `description`, `is_default`, `is_active`)
VALUES
  (NULL, 'GESTION_LOCATIVE', 'TPL_IMMEUBLE',
   'Template Immeuble',
   'Sous-dossiers automatiques sous chaque immeuble (01 ADMIN, 02 DIAGNOSTICS COMMUNS, 03 TRAVAUX PARTIES COMMUNES, 04 COPROPRIETE, 05 BIENS)',
   1, 1);

-- ─── 4. Nœuds du template IMMEUBLE ─────────────────────────────────────
INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '01_administratif', '01 - Administratif', 1, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_IMMEUBLE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '02_diagnostics_communs', '02 - Diagnostics communs', 2, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_IMMEUBLE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '03_travaux_parties_communes', '03 - Travaux parties communes', 3, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_IMMEUBLE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '04_copropriete', '04 - Copropriété', 4, 0
FROM `ged_folder_templates` t WHERE t.code = 'TPL_IMMEUBLE' LIMIT 1;

INSERT IGNORE INTO `ged_folder_template_nodes`
  (`template_id`, `parent_node_id`, `depth`, `slug`, `name_display`, `position`, `is_required`)
SELECT t.id, NULL, 0, '05_biens', '05 - Biens', 5, 1
FROM `ged_folder_templates` t WHERE t.code = 'TPL_IMMEUBLE' LIMIT 1;
SQL,
];
