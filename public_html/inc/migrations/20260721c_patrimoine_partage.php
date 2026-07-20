<?php
/**
 * Migration : Partage patrimoine à jeton (avocat / créanciers).
 *
 * - patrimoine_partages : partage lecture seule à jeton d'un compte bailleur
 *   (périmètre, gestionnaire, scénarios CSV multi, flags colonnes, niveau d'accès,
 *   consentement/expiration/révocation).
 * - Lien explicite dossier créancier ↔ propriétaire + backfill (débiteur=propriétaire).
 *
 * Idempotent : suivi via _migrations_applied (n'est rejoué qu'en cas d'échec partiel).
 * Les ADD COLUMN ne sont pas conditionnels (MySQL) → à n'appliquer qu'une fois.
 */
return [
    'id'          => '20260721c_patrimoine_partage',
    'title'       => 'Partage patrimoine à jeton + lien créancier↔propriétaire',
    'description' => "patrimoine_partages (01-05) + backfill creancier_dossier_lien PROPRIETAIRE.",
    'created_at'  => '2026-07-21',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS patrimoine_partages (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token                 VARCHAR(64)  NOT NULL,
  id_user_bailleur      INT UNSIGNED NOT NULL,
  id_user_gestionnaire  INT UNSIGNED NULL,
  id_tiers_destinataire INT UNSIGNED NULL,
  destinataire_nom      VARCHAR(190) NOT NULL,
  destinataire_email    VARCHAR(190) NULL,
  scenario_code         VARCHAR(255) NOT NULL DEFAULT 'courant',
  montrer_prix_vente    TINYINT(1)   NOT NULL DEFAULT 0,
  montrer_creanciers    TINYINT(1)   NOT NULL DEFAULT 0,
  montrer_loyer         TINYINT(1)   NOT NULL DEFAULT 1,
  montrer_locataire     TINYINT(1)   NOT NULL DEFAULT 1,
  montrer_descriptif    TINYINT(1)   NOT NULL DEFAULT 1,
  niveau_acces          ENUM('lecture','contribution') NOT NULL DEFAULT 'lecture',
  expire_at             DATETIME     NULL,
  actif                 TINYINT(1)   NOT NULL DEFAULT 1,
  consent_at            DATETIME     NULL,
  revoked_at            DATETIME     NULL,
  premiere_consultation DATETIME     NULL,
  derniere_consultation DATETIME     NULL,
  nb_consultations      INT UNSIGNED NOT NULL DEFAULT 0,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  KEY idx_bailleur (id_user_bailleur),
  KEY idx_tiers (id_tiers_destinataire),
  KEY idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, note, created_at)
SELECT DISTINCT dl.id_dossier, 'PROPRIETAIRE', p.id, 'proprietaire_concerne',
       'backfill: débiteur = propriétaire', NOW()
FROM creancier_dossier_lien dl
JOIN proprietaires p ON p.id_tiers = dl.entity_id
WHERE dl.entity_type = 'TIERS'
  AND dl.role_dossier IN ('debiteur','debiteur_solidaire')
  AND NOT EXISTS (
      SELECT 1 FROM creancier_dossier_lien x
      WHERE x.id_dossier = dl.id_dossier AND x.entity_type = 'PROPRIETAIRE' AND x.entity_id = p.id
  );
SQL
];
