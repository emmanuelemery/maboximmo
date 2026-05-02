<?php
/**
 * Migration v1.1 — Module COFFRE ACCES sécurisé (secure_credentials)
 *
 * Tables :
 *   - secure_credentials             : entrées chiffrées AES-256-GCM
 *   - secure_credentials_access_log  : journalisation tout accès (reveal/copy/edit)
 *
 * INTERDICTION : aucun mot de passe ne doit transiter par la GED standard.
 * Toujours stocker via cette table chiffrée + accès via super_admin_coffre_acces.php.
 *
 * Chiffrement :
 *   - openssl AES-256-GCM
 *   - clé maître = COFFRE_MASTER_KEY (config/coffre.php, gitignored)
 *   - IV unique par enregistrement (généré random_bytes)
 *   - tag d'authentification stocké pour vérifier intégrité au déchiffrement
 *
 * Partage :
 *   - shared_with_users JSON array d'id utilisateur autorisés
 *   - owner_user_id : créateur (toujours autorisé)
 *   - super admin (id_role=1) toujours autorisé
 *
 * AJOUT uniquement, idempotent.
 */

return [
    'id'          => '20260502_ged_v1_12_secure_credentials',
    'title'       => 'Ma GED Box V1.1 — module COFFRE ACCES (secure_credentials chiffré AES + log)',
    'description' => "Crée 2 tables : secure_credentials (entrées chiffrées AES-256-GCM avec IV+tag par enregistrement, label, category, hint masqué, owner + shared_with_users JSON, last_accessed_at) et secure_credentials_access_log (journal complet : actor, action reveal/copy/edit/delete, ip, user_agent). Interdiction stricte de mettre des mots de passe en GED. Page super_admin_coffre_acces.php. Clé maître COFFRE_MASTER_KEY dans config/coffre.php (gitignored, à créer manuellement).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `secure_credentials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` INT UNSIGNED NULL,
  `label` VARCHAR(180) NOT NULL COMMENT 'Nom humain (ex: Banque CIC compte courant)',
  `category` ENUM('compte','identifiant','contrat','banque','fiscal','autre') NOT NULL DEFAULT 'autre',
  `hint` VARCHAR(255) NULL COMMENT 'Indication non chiffrée (ex: dernier 4 chiffres carte)',
  `encrypted_value` LONGBLOB NOT NULL COMMENT 'Valeur chiffrée AES-256-GCM',
  `iv` VARBINARY(16) NOT NULL COMMENT 'Initialization vector (12 bytes pour GCM)',
  `auth_tag` VARBINARY(16) NOT NULL COMMENT 'Tag d''authentification GCM',
  `cipher` VARCHAR(40) NOT NULL DEFAULT 'aes-256-gcm',
  `owner_user_id` INT UNSIGNED NOT NULL COMMENT 'Créateur, toujours autorisé',
  `shared_with_users` JSON NULL COMMENT 'Array d''user_id supplémentaires autorisés',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL COMMENT 'Soft delete uniquement (jamais hard)',
  `last_accessed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_secure_credentials_uuid` (`uuid`),
  INDEX `idx_secure_credentials_tenant` (`tenant_id`),
  INDEX `idx_secure_credentials_owner` (`owner_user_id`),
  INDEX `idx_secure_credentials_category` (`category`),
  INDEX `idx_secure_credentials_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `secure_credentials_access_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `credential_id` BIGINT UNSIGNED NULL COMMENT 'NULL pour actions globales (list, search)',
  `actor_user_id` INT UNSIGNED NOT NULL,
  `actor_role_code` VARCHAR(40) NULL,
  `action` VARCHAR(40) NOT NULL COMMENT 'reveal | copy | create | update | delete | list | share | unshare',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_secure_log_credential` (`credential_id`),
  INDEX `idx_secure_log_actor` (`actor_user_id`),
  INDEX `idx_secure_log_action_date` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
