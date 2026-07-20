-- Cache des INFOS PUBLIQUES d'un immeuble (cadastre/PLU, urbanisme, altitude,
-- risques ERP, registre copro) — évite de retaper les APIs externes à chaque
-- sélection. Ancré sur l'IMMEUBLE ; google_place_id + addr_hash = résolveurs.
-- 2026-07-20 · MaBoxImmo · mission « Mettre un bien en location »
CREATE TABLE IF NOT EXISTS `immeuble_infos_publiques` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_immeuble`      INT UNSIGNED NULL,               -- le vrai lien, dès qu'il est connu
  `google_place_id`  VARCHAR(255) NULL,               -- résolveur rapide (adresse via Google)
  `addr_hash`        CHAR(40)     NULL,               -- résolveur de secours (sha1 de cp|voie normalisés)
  `lat`              DECIMAL(10,7) NULL,
  `lng`              DECIMAL(10,7) NULL,
  `adresse_formatee` VARCHAR(255) NULL,
  `data`             LONGTEXT     NULL,               -- blob JSON {lines, enrich, reco}
  `fetched_at`       DATETIME     NULL,               -- dernier rafraîchissement effectif
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_place` (`google_place_id`),          -- (NULL multiples autorisés par MySQL/MariaDB)
  KEY `idx_immeuble` (`id_immeuble`),
  KEY `idx_addr` (`addr_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
