-- =====================================================================
-- Migration 2026-07-20 : Partage patrimoine par lien à jeton (lecture seule)
-- ---------------------------------------------------------------------
-- Un « partage » = accès public tokenisé au patrimoine d'un COMPTE BAILLEUR
-- existant (il hérite de ses user_proprietaires + scénarios), destiné à un
-- tiers externe (banquier, associé, famille…). SANS login.
-- Calqué sur le pattern portefeuille_envois / document_requests :
--   token imprévisible, expiration, révocation, consentement 1er accès.
-- Colonnes autorisées finement (prix de vente / loyer / locataire).
-- =====================================================================

CREATE TABLE IF NOT EXISTS patrimoine_partages (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token                 VARCHAR(64)  NOT NULL COMMENT 'bin2hex(random_bytes(24)) = 48 hex',
  id_user_bailleur      INT UNSIGNED NOT NULL COMMENT 'compte bailleur dont on partage le périmètre (users.id)',
  id_user_gestionnaire  INT UNSIGNED NULL     COMMENT 'gestionnaire dont on affiche les coordonnées (users.id)',
  destinataire_nom      VARCHAR(190) NOT NULL COMMENT 'nom du tiers destinataire',
  destinataire_email    VARCHAR(190) NULL,
  scenario_code         VARCHAR(60)  NOT NULL DEFAULT 'courant' COMMENT 'scénario prix de vente partagé',
  montrer_prix_vente    TINYINT(1)   NOT NULL DEFAULT 0,
  montrer_loyer         TINYINT(1)   NOT NULL DEFAULT 1,
  montrer_locataire     TINYINT(1)   NOT NULL DEFAULT 1,
  montrer_descriptif    TINYINT(1)   NOT NULL DEFAULT 1,
  expire_at             DATETIME     NULL,
  actif                 TINYINT(1)   NOT NULL DEFAULT 1,
  consent_at            DATETIME     NULL COMMENT 'horodatage du consentement au 1er accès',
  revoked_at            DATETIME     NULL,
  premiere_consultation DATETIME     NULL,
  derniere_consultation DATETIME     NULL,
  nb_consultations      INT UNSIGNED NOT NULL DEFAULT 0,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  KEY idx_bailleur (id_user_bailleur),
  KEY idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Partages patrimoine par lien à jeton (patrimoine_partage.php)';
