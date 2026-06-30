<?php
/**
 * Migration : ACL GRANTS — droits d'accès par personne (modules × pages × périmètre).
 *
 * Doctrine (memory project_acl_grants_cage_acces) : partage des modules par
 * PERSONNE avec 3 niveaux de granularité :
 *   1. MODULE   (acl_modules)        — quoi faire
 *   2. PAGE     (acl_module_pages)   — quelle page du module (cochable)
 *   3. PÉRIMÈTRE(acl_grants.scope_*) — sur quelles données
 * Une identité = un collaborateur (users) OU un tiers à jeton (acl_jeton).
 * Tout lit acl_grants ; la cage enforce_scope() applique en default-deny.
 *
 * AUCUNE suppression. user_bailleur_modules conservé et repris (étape finale).
 * Statements additifs / idempotents → rejouables sans casse.
 */

return [
    'id'          => '20260626_acl_grants',
    'title'       => 'ACL Grants — droits par personne (module × page × périmètre)',
    'description' => "Crée acl_modules, acl_module_pages, acl_grants, acl_jeton. Seed du catalogue (7 modules, ~40 pages). patrimoine_actif = module distinct. Reprise des droits user_bailleur_modules vers acl_grants (patrimoine→patrimoine_actif, reste→bailleur). Base de la cage enforce_scope().",
    'created_at'  => '2026-06-26',
    'sql' => <<<'SQL'
-- ── 1. Catalogue des modules (les "tuiles") ──────────────────────────
CREATE TABLE IF NOT EXISTS acl_modules (
  code         VARCHAR(50)  NOT NULL,
  label        VARCHAR(100) NOT NULL,
  icone        VARCHAR(50)  NULL,
  couleur      VARCHAR(20)  NULL,
  page_entree  VARCHAR(120) NULL,
  ordre        INT          NOT NULL DEFAULT 0,
  actif        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Catalogue des pages cochables de chaque module ────────────────
CREATE TABLE IF NOT EXISTS acl_module_pages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_code  VARCHAR(50)  NOT NULL,
  page         VARCHAR(120) NOT NULL,
  label        VARCHAR(150) NOT NULL,
  est_entree   TINYINT(1)   NOT NULL DEFAULT 0,
  ordre        INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_module_page (module_code, page),
  KEY idx_page (page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Identités à jeton (tiers ponctuel, modèle Deal Room) ──────────
CREATE TABLE IF NOT EXISTS acl_jeton (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token       VARCHAR(64)  NOT NULL,
  email       VARCHAR(190) NULL,
  label       VARCHAR(150) NULL,
  expires_at  DATETIME     NULL,
  revoked     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT          NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_token (token),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Attributions (le cœur). page NULL = tout le module.
--     scope_type NULL = large ; 'dossier'|'proprio'|'groupe_proprio'|'portefeuille'
CREATE TABLE IF NOT EXISTS acl_grants (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identite_type ENUM('user','tiers','jeton') NOT NULL DEFAULT 'user',
  identite_id   INT          NOT NULL,
  module_code   VARCHAR(50)  NOT NULL,
  page          VARCHAR(120) NULL,
  scope_type    VARCHAR(20)  NULL,
  scope_id      INT          NULL,
  actif         TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT          NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_identite (identite_type, identite_id),
  KEY idx_module (module_code),
  KEY idx_scope (scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Seed des modules ──────────────────────────────────────────────
INSERT INTO acl_modules (code, label, icone, couleur, page_entree, ordre) VALUES
  ('bien_descriptif', 'Biens & descriptifs', 'home',      '#84a98c', 'bien_liste.php',                   10),
  ('annonces',        'Annonces',            'megaphone', '#84a98c', 'annonce_liste.php',                20),
  ('transaction',     'Transaction / Vente', 'handshake', '#eab308', 'transaction_index.php',            30),
  ('portefeuilles',   'Portefeuilles',       'briefcase', '#eab308', 'transaction_portefeuilles_hub.php',40),
  ('bailleur',        'Ma Box Bailleur',     'building',  '#0e7490', 'bailleur_dashboard.php',           50),
  ('patrimoine_actif','Patrimoine actif',    'chart',     '#0e7490', 'bailleur_patrimoine_actif.php',    60),
  ('creancier',       'Créanciers',          'gavel',     '#7c3aed', 'creancier_dashboard.php',          70)
ON DUPLICATE KEY UPDATE label=VALUES(label), couleur=VALUES(couleur), page_entree=VALUES(page_entree), ordre=VALUES(ordre);

-- ── 6. Seed des pages cochables ──────────────────────────────────────
INSERT INTO acl_module_pages (module_code, page, label, est_entree, ordre) VALUES
  ('bien_descriptif','bien_liste.php',          'Liste des biens',      1, 10),
  ('bien_descriptif','bien_detail.php',         'Fiche bien (édition)', 0, 20),
  ('bien_descriptif','bien_360.php',            'Fiche bien 360°',      0, 30),
  ('bien_descriptif','bien_documents_list.php', 'Documents du bien',    0, 40),
  ('bien_descriptif','bien_doc_360.php',        'Document 360°',        0, 50),
  ('bien_descriptif','bien_intake.php',         'Import IA (intake)',   0, 60),
  ('annonces','annonce_liste.php',          'Liste des annonces', 1, 10),
  ('annonces','annonce_creation.php',       'Créer une annonce',  0, 20),
  ('annonces','annonce_photos.php',         'Photos de l annonce',0, 30),
  ('annonces','annonce_reglementations.php','Réglementations',    0, 40),
  ('transaction','transaction_index.php',           'Biens en commercialisation', 1, 10),
  ('transaction','transaction_dossier.php',         'Dossier de vente',           0, 20),
  ('transaction','transaction_dossier_nouveau.php', 'Nouveau dossier',            0, 30),
  ('transaction','transaction_chargement.php',      'Chargement documents',       0, 40),
  ('transaction','transaction_avant_contrat.php',   'Avant-contrat',              0, 50),
  ('transaction','transaction_mandat_preview.php',  'Mandat de vente',            0, 60),
  ('transaction','transaction_modeles.php',         'Modèles',                    0, 70),
  ('transaction','transaction_baux_overview.php',   'Baux du dossier',            0, 80),
  ('portefeuilles','transaction_portefeuilles_hub.php',       'Hub portefeuilles',      1, 10),
  ('portefeuilles','transaction_portefeuilles.php',           'Sélection (gestion)',    0, 20),
  ('portefeuilles','transaction_portefeuilles_selection.php', 'Constituer un portef.',  0, 30),
  ('portefeuilles','transaction_portefeuilles_liste.php',     'Portefeuilles enreg.',   0, 40),
  ('portefeuilles','transaction_portefeuilles_envois.php',    'Liens envoyés / suivi',  0, 50),
  ('bailleur','bailleur_dashboard.php',         'Dashboard bailleur', 1, 10),
  ('bailleur','bailleur_immeubles.php',         'Immeubles',          0, 20),
  ('bailleur','bailleur_ged.php',               'GED bailleur',       0, 30),
  ('bailleur','bailleur_crg_audit.php',         'Audit CRG',          0, 40),
  ('bailleur','bailleur_revision_loyer.php',    'Révision loyer',     0, 50),
  ('bailleur','bailleur_scenarios.php',         'Scénarios',          0, 60),
  ('bailleur','bailleur_validation_imports.php','Validation imports', 0, 70),
  ('patrimoine_actif','bailleur_patrimoine_actif.php',  'État du patrimoine actif', 1, 10),
  ('patrimoine_actif','bailleur_prix_historique.php',   'Historique des prix',      0, 20),
  ('patrimoine_actif','bailleur_patrimoine_export.php', 'Export patrimoine',        0, 30),
  ('creancier','creancier_dashboard.php',    'Dashboard créanciers', 1, 10),
  ('creancier','creancier_liste.php',        'Liste des dossiers',   0, 20),
  ('creancier','creancier_dossier_form.php', 'Créer un dossier',     0, 30),
  ('creancier','creancier_dossier360.php',   'Dossier 360°',         0, 40),
  ('creancier','creancier_creancier360.php', 'Créancier 360°',       0, 50),
  ('creancier','creancier_scan.php',         'Scan / analyse IA',    0, 60)
ON DUPLICATE KEY UPDATE label=VALUES(label), est_entree=VALUES(est_entree), ordre=VALUES(ordre);

-- ── 7. Reprise user_bailleur_modules -> acl_grants (anti-doublon) ────
INSERT INTO acl_grants (identite_type, identite_id, module_code, page, scope_type, scope_id, actif, created_by)
SELECT DISTINCT
  'user',
  ubm.id_user,
  CASE WHEN ubm.module_code = 'patrimoine' THEN 'patrimoine_actif' ELSE 'bailleur' END,
  NULL, NULL, NULL, 1, NULL
FROM user_bailleur_modules ubm
WHERE NOT EXISTS (
  SELECT 1 FROM acl_grants g
  WHERE g.identite_type = 'user'
    AND g.identite_id   = ubm.id_user
    AND g.module_code   = CASE WHEN ubm.module_code = 'patrimoine' THEN 'patrimoine_actif' ELSE 'bailleur' END
    AND g.page IS NULL
    AND g.scope_type IS NULL
);
SQL
];
