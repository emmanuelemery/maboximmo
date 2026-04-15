-- ============================================================
-- MaBoxImmo — Migration v2
-- ALTER TABLE sur tables existantes + INSERT rôles
-- À exécuter sur la base `maboximmo` existante
-- ============================================================

-- ── USERS ────────────────────────────────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `code_acces` varchar(20) DEFAULT NULL
    COMMENT 'ADMIN | PROPRIO | SIR | AGENCE | NEGO',
  ADD COLUMN IF NOT EXISTS `id_proprietaire` int(10) unsigned DEFAULT NULL
    COMMENT 'FK proprietaires.id — pour rôle PROPRIO/SIR',
  ADD COLUMN IF NOT EXISTS `dashboard_type` varchar(30) DEFAULT 'standard',
  ADD COLUMN IF NOT EXISTS `acces_maboximmo` tinyint(1) DEFAULT 0
    COMMENT '1 = peut se connecter au portail MaBoxImmo',
  ADD COLUMN IF NOT EXISTS `token_reset` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `token_reset_expire` datetime DEFAULT NULL;

-- ── ROLES ────────────────────────────────────────────────────
INSERT IGNORE INTO `roles` (`nom`, `code`, `niveau_acces`) VALUES
('Administrateur Régie', 'ADMIN_REGIE', 10),
('Propriétaire', 'PROPRIO', 3),
('Propriétaire VIP', 'PROPRIO_VIP', 3),
('Agence Partenaire', 'AGENCE_EXT', 2),
('Négociateur', 'NEGO_EXT', 1);

-- ── PROPRIETAIRES ────────────────────────────────────────────
ALTER TABLE `proprietaires`
  ADD COLUMN IF NOT EXISTS `code_compte` varchar(20) DEFAULT NULL
    COMMENT 'Code CRG ex: 01040000',
  ADD COLUMN IF NOT EXISTS `type_dashboard` enum('standard','groupe_sir') DEFAULT 'standard',
  ADD COLUMN IF NOT EXISTS `regie` varchar(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `notes_internes` text DEFAULT NULL
    COMMENT 'Jamais visible du propriétaire';

-- ── IMMEUBLES ────────────────────────────────────────────────
ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `code_crg` varchar(20) DEFAULT NULL
    COMMENT 'Code issu du PDF CRG ex: 01040076',
  ADD COLUMN IF NOT EXISTS `id_proprietaire` int(10) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `compte_gestion` varchar(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `gps_source` varchar(30) DEFAULT NULL
    COMMENT 'nominatim | manuel | google';

-- ── BIENS ────────────────────────────────────────────────────
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `numero_lot` varchar(20) DEFAULT NULL
    COMMENT 'Ex: 0001 — numéro de lot dans l immeuble',
  ADD COLUMN IF NOT EXISTS `code_crg` varchar(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `statut_occupation`
    enum('occupé','vacant','parti-débiteur','en_travaux') DEFAULT 'vacant',
  ADD COLUMN IF NOT EXISTS `disponible_le` date DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `loyer_proposé` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `charges_proposées` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `depot_garantie` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `annonce_texte_lbc` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `annonce_lbc_generee_le` timestamp NULL DEFAULT NULL;

-- ── MANDATS ──────────────────────────────────────────────────
ALTER TABLE `mandats`
  ADD COLUMN IF NOT EXISTS `mandat_commercialisation` tinyint(1) DEFAULT 0
    COMMENT '1 = mandat de diffusion vers agences externes',
  ADD COLUMN IF NOT EXISTS `docs_autorises` text DEFAULT NULL
    COMMENT 'JSON: ["dpe","carrez","plans"] — docs visibles par les agences',
  ADD COLUMN IF NOT EXISTS `loyer_mandat` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `charges_mandat` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `notes_agence` text DEFAULT NULL
    COMMENT 'Instructions visibles par les agences assignées';

-- ── BIENS_DOCUMENTS ──────────────────────────────────────────
ALTER TABLE `biens_documents`
  ADD COLUMN IF NOT EXISTS `visible_proprietaire` tinyint(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `visible_agences` tinyint(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `confidentiel` tinyint(1) DEFAULT 0
    COMMENT '1 = admin seulement',
  ADD COLUMN IF NOT EXISTS `id_user_upload` int(10) unsigned DEFAULT NULL;

-- ── BAUX ─────────────────────────────────────────────────────
ALTER TABLE `baux`
  ADD COLUMN IF NOT EXISTS `locataire_societe` varchar(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `loyer_hc` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `taxe_fonciere_prov` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `statut_paiement`
    enum('à_jour','impayé','parti-débiteur') DEFAULT 'à_jour',
  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL;
