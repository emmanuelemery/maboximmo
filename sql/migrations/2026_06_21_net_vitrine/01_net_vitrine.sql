-- =====================================================================
-- Migration 2026-06-21 : Module "Ma Box Net" (vitrines SEO par agence)
-- ---------------------------------------------------------------------
-- Objet :
--   Chaque agence (sous-domaine dédié, ex regie-emery-lyon.maboximmo.fr)
--   sert le MÊME portail d'annonces (offre globale, non filtrée) mais avec
--   un habillage + un contenu éditorial LOCAL différencié (anti-duplicate
--   content SEO) : textes des pages métiers, intro de ville, équipe.
--
--   1) Table agence_net_page : textes éditoriaux par agence et par page.
--   2) Colonne users.visible_net : collaborateur affiché sur la vitrine.
--
-- Périmètre : agences des sociétés 1 (Régie EMERY) et 2 (EMERY IMMO).
-- Rappel règle : LOCAL = source de vérité. Appliquer en local d'abord,
--                puis sur prod via admin/admin_migrations.php.
-- =====================================================================

-- ── 1. Pages éditoriales par agence ──────────────────────────────────
CREATE TABLE IF NOT EXISTS agence_net_page (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_agence     INT NOT NULL,
  page_key      VARCHAR(40) NOT NULL
                COMMENT 'home|transaction|gestion|location|syndic|investissement|a-propos|contact',
  titre         VARCHAR(255) NULL,
  contenu_html  MEDIUMTEXT NULL
                COMMENT 'Contenu editorial local (HTML simple), differenciant pour le SEO',
  meta_title    VARCHAR(255) NULL,
  meta_description VARCHAR(320) NULL,
  actif         TINYINT(1) NOT NULL DEFAULT 1,
  updated_by    INT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_agence_page (id_agence, page_key),
  KEY idx_agence (id_agence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ma Box Net : textes editoriaux par agence (vitrines SEO)';

-- ── 2. Collaborateur visible sur la vitrine publique ─────────────────
ALTER TABLE users
  ADD COLUMN visible_net TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Affiche le collaborateur (photo/fonction/tel/mail) sur la vitrine Ma Box Net'
  AFTER avatar_url;

ALTER TABLE users
  ADD INDEX idx_visible_net (id_agence, visible_net);
