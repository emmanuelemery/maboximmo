<?php
/**
 * Migration : table agences_documents_officiels (Lot 4.B)
 *
 * Centralise le stockage + l'OCR + le tracking des dates de validité des
 * documents officiels d'une agence : carte pro, KBIS, garant financier,
 * RC pro, barème honoraires.
 *
 * Pattern :
 *   - 1 ligne = 1 version d'un document (versioning natif via statut)
 *   - Quand on remplace une carte pro, l'ancienne passe statut='remplace'
 *   - Les supports continuent de lire `agences.carte_pro_*` (réplication
 *     applicative depuis le doc statut='actif' lors de l'upload).
 *
 * Alertes : 3 timestamps `alerte_120/90/60j_envoyee_at` pour le cron
 * idempotent (n'envoie qu'une fois chaque seuil).
 *
 * Pas de FK physiques (cohérent avec le reste du schéma MBI), index
 * seuls + contrôles applicatifs.
 */

return [
    'id'          => '20260505_agences_documents_officiels',
    'title'       => 'Documents officiels d\'agence (carte pro, KBIS, garant, RC, barème) + alertes',
    'description' => 'Table centralisée des documents officiels d\'agence avec OCR, versioning et tracking des alertes 120/90/60 jours avant expiration. Réplication applicative vers agences.carte_pro_* pour compat avec les supports existants.',
    'created_at'  => '2026-05-05',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `agences_documents_officiels` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `id_agence`         INT UNSIGNED NOT NULL,
  `type_document`     ENUM(
                        'carte_pro',
                        'kbis',
                        'garant_financier',
                        'rc_pro',
                        'bareme_honoraires'
                      ) NOT NULL,

  -- Stockage du fichier
  `ged_document_id`   INT UNSIGNED NULL COMMENT 'FK logique vers ged_documents si stocké en GED',
  `fichier_path`      VARCHAR(500) NULL COMMENT 'Fallback chemin local relatif depuis public_html',
  `fichier_mime`      VARCHAR(100) NULL,
  `fichier_taille`    INT UNSIGNED NULL,
  `fichier_hash`      VARCHAR(64)  NULL COMMENT 'SHA-256 pour détection doublon',

  -- Champs OCR communs
  `numero`            VARCHAR(150) NULL COMMENT 'Numéro carte pro / RCS / contrat / etc.',
  `emetteur`          VARCHAR(255) NULL COMMENT 'CCI / Greffe / Assureur / Garant',
  `montant_garantie`  DECIMAL(12,2) NULL COMMENT 'Plafond garant financier (€)',

  -- Dates pivot
  `date_emission`     DATE NULL,
  `date_validite`     DATE NULL COMMENT 'Pivot des alertes 120/90/60 jours',

  -- Audit OCR
  `ocr_modele`        VARCHAR(50)  NULL COMMENT 'Ex: claude-sonnet-4-6',
  `ocr_confidence`    TINYINT UNSIGNED NULL COMMENT '0-100',
  `ocr_cout_centimes` INT UNSIGNED NULL,
  `ocr_json`          JSON NULL COMMENT 'Snapshot brut de la réponse OCR pour audit',
  `ocr_at`            TIMESTAMP NULL,

  -- Cycle de vie
  `statut`            ENUM('actif','remplace','expire','archive') NOT NULL DEFAULT 'actif',

  -- Tracking alertes (NULL = jamais envoyé, TIMESTAMP = envoyé à cet instant)
  `alerte_120j_envoyee_at` TIMESTAMP NULL,
  `alerte_90j_envoyee_at`  TIMESTAMP NULL,
  `alerte_60j_envoyee_at`  TIMESTAMP NULL,

  -- Audit
  `uploaded_by`       INT UNSIGNED NOT NULL,
  `commentaire`       TEXT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_agences_docs_off_agence`     (`id_agence`, `type_document`, `statut`),
  KEY `idx_agences_docs_off_validite`   (`date_validite`, `statut`),
  KEY `idx_agences_docs_off_actifs`     (`statut`, `type_document`),
  KEY `idx_agences_docs_off_hash`       (`fichier_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colonnes de validité manquantes sur agences (pour réplication depuis les docs officiels)
-- Idempotent : ALTER ... ADD COLUMN IF NOT EXISTS (MySQL 8 / MariaDB 10.4+)
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `garant_validite`   DATE NULL COMMENT 'Réplique depuis agences_documents_officiels.date_validite (type=garant_financier)',
  ADD COLUMN IF NOT EXISTS `rc_pro_validite`   DATE NULL COMMENT 'Réplique depuis agences_documents_officiels.date_validite (type=rc_pro)',
  ADD COLUMN IF NOT EXISTS `kbis_date`         DATE NULL COMMENT 'Réplique depuis agences_documents_officiels.date_emission (type=kbis)',
  ADD COLUMN IF NOT EXISTS `kbis_numero`       VARCHAR(50)  NULL,
  ADD COLUMN IF NOT EXISTS `garant_montant`    DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS `bareme_url_doc`    VARCHAR(500) NULL COMMENT 'URL téléchargement du barème PDF (différent de bareme_url qui peut être une page)';
SQL,
];
