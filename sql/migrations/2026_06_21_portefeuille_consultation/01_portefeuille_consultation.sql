-- =====================================================================
-- Migration 2026-06-21 : suivi enrichi des consultations de portefeuilles
-- ---------------------------------------------------------------------
-- Journal d'événements pour chaque envoi (p.php / api) :
--   - open       : ouverture de l'espace par le destinataire
--   - view_bien  : ouverture de la fiche d'un bien (beacon JS)
--   - download   : téléchargement d'un document GED autorisé
-- Complète les timestamps existants (date_premiere/derniere_consultation)
-- par un historique daté + compteurs (qui, quand, combien, quoi).
-- =====================================================================

CREATE TABLE IF NOT EXISTS portefeuille_consultation (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_envoi        INT UNSIGNED NOT NULL,
  id_portefeuille INT UNSIGNED NULL,
  email           VARCHAR(190) NULL,
  type            VARCHAR(20) NOT NULL COMMENT 'open | view_bien | download',
  id_bien         INT UNSIGNED NULL,
  doc_id          INT UNSIGNED NULL,
  doc_label       VARCHAR(190) NULL,
  ip              VARCHAR(64) NULL,
  user_agent      VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_envoi (id_envoi),
  KEY idx_pf (id_portefeuille),
  KEY idx_type (type),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Journal des consultations de portefeuilles partagés (p.php)';
