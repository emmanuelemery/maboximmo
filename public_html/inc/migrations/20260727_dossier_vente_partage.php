<?php
/**
 * Migration : partage à jeton d'un dossier de vente (acquéreur / notaire).
 * Page publique lecture seule : infos bien + photos + documents SÉLECTIONNÉS.
 * Idempotente.
 */
return [
    'id'          => '20260727_dossier_vente_partage',
    'title'       => 'Transaction : partage acquéreur/notaire (jeton, docs sélectionnés)',
    'description' => "Table dossier_vente_partage : lien public read-only vers un dossier de vente (photos + documents choisis).",
    'created_at'  => '2026-07-27',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `dossier_vente_partage` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `id_dossier`            INT UNSIGNED NOT NULL,
  `role_destinataire`     ENUM('acquereur','notaire','commercialisateur') NOT NULL DEFAULT 'acquereur',
  `id_tiers_destinataire` INT UNSIGNED NULL,
  `libelle`               VARCHAR(160) NULL COMMENT 'Nom du destinataire (affichage interne)',
  `token`                 CHAR(48) NOT NULL,
  `docs_json`             LONGTEXT NULL COMMENT 'IDs ged_documents autorisés (JSON array)',
  `inclure_photos`        TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at`            DATETIME NULL,
  `revoked_at`            DATETIME NULL,
  `nb_vues`               INT UNSIGNED NOT NULL DEFAULT 0,
  `last_view_at`          DATETIME NULL,
  `created_by`            INT UNSIGNED NULL,
  `created_at`            DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_dvp_token` (`token`),
  INDEX `idx_dvp_dossier` (`id_dossier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL,
];
