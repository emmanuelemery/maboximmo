<?php
/**
 * Migration : schéma OneDrive PAR AGENCE (multi-racines) + cache dossier PAR PROPRIÉTAIRE.
 *
 * Problème résolu : chaque agence organise son OneDrive différemment, et une même
 * agence peut avoir PLUSIEURS racines selon le type de document. Ex. LYON :
 *   - une racine « propriétaires » (mandats, baux)            → organisée par propriétaire
 *   - une racine « diagnostics »   (DPE, amiante, plomb…)     → organisée par immeuble
 * Le code forçait la racine RIOM pour tout le monde → « dossier introuvable » partout ailleurs.
 *
 *  - agence_onedrive_schema : N lignes par code_agence (1 par racine) = base_path + mode + label.
 *  - proprietaire_onedrive  : cache du dossier résolu PAR RACINE (clé proprio+schema_id), auto OU
 *                             saisi à la main → une fois trouvé, plus jamais « introuvable ».
 *
 * Idempotent : CREATE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY UPDATE.
 */
return [
    'id'          => '20260616_onedrive_schema_agence',
    'title'       => 'OneDrive : schéma multi-racines par agence + cache dossier par propriétaire',
    'description' => "Crée agence_onedrive_schema (N racines base_path/mode/label par agence) et proprietaire_onedrive (dossier résolu mémorisé par racine). Seede RIOM, CHAMALIERES, CHAPONOST, LYON (propriétaires + diagnostics).",
    'created_at'  => '2026-06-16',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `agence_onedrive_schema` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_agence` VARCHAR(20)  NOT NULL,
  `label`       VARCHAR(60)  NOT NULL DEFAULT 'principal',
  `base_path`   VARCHAR(512) NOT NULL,
  `mode`        ENUM('par_proprietaire','par_immeuble') NOT NULL DEFAULT 'par_proprietaire',
  `drive_user`  VARCHAR(190) NULL,
  `actif`       TINYINT(1)   NOT NULL DEFAULT 1,
  `note`        VARCHAR(255) NULL,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agence_label` (`code_agence`,`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `proprietaire_onedrive` (
  `id_proprietaire` INT(10) UNSIGNED NOT NULL,
  `schema_id`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `folder_path`     VARCHAR(768) NOT NULL,
  `source`          ENUM('auto','manuel') NOT NULL DEFAULT 'auto',
  `resolved_by`     INT(10) UNSIGNED NULL,
  `resolved_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_proprietaire`,`schema_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `agence_onedrive_schema` (`code_agence`,`label`,`base_path`,`mode`,`note`) VALUES
  ('63-1','proprietaires','01_SERVICE_GESTION/05_RIOM GESTION/0001 - NOUVEAUX DOSSIERS ONEDRIVE/00 - PROPRIETAIRES','par_proprietaire','EMERY IMMO RIOM'),
  ('63-2','proprietaires','01_SERVICE_GESTION/05_RIOM GESTION/0001 - NOUVEAUX DOSSIERS ONEDRIVE/00 - PROPRIETAIRES','par_proprietaire','CHAMALIERES = même OneDrive que RIOM'),
  ('69-1','proprietaires','01_SERVICE_GESTION/03_CHAPONOST GESTION','par_proprietaire','CHAPONOST — affiner le sous-dossier PROPRIETAIRES si besoin'),
  ('69-2','proprietaires','01_SERVICE_GESTION/02_LYON GESTION','par_proprietaire','LYON — racine propriétaires (mandats, baux)'),
  ('69-2','diagnostics','01_SERVICE_GESTION/02_LYON GESTION/022-DIAGNOSTICS','par_immeuble','LYON — racine diagnostics, organisée par immeuble')
ON DUPLICATE KEY UPDATE `base_path`=VALUES(`base_path`), `mode`=VALUES(`mode`), `note`=VALUES(`note`);
SQL
];
