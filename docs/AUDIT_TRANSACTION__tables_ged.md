# Tables GED (migrations PHP)
### `ged_folders` (source: `public_html/inc/migrations/20260502_ged_v1_01_folders.php:26`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `uuid` — `CHAR(36) NOT NULL`
- `tenant_id` — `INT UNSIGNED NULL COMMENT 'NULL = système global ; sinon = id_societe'`
- `parent_id` — `BIGINT UNSIGNED NULL`
- `path_cache` — `VARCHAR(2048) NOT NULL DEFAULT '' COMMENT 'Chemin slash-separated (slugs)'`
- `slug` — `VARCHAR(120) NOT NULL`
- `name_display` — `VARCHAR(255) NOT NULL COMMENT 'Nom humain modifiable'`
- `name_canonical` — `VARCHAR(255) NOT NULL COMMENT 'Nom machine stable (auto-genere)'`
- `module` — `VARCHAR(50) NULL COMMENT 'DIRECTION|RH|SYNDIC|GESTION_LOCATIVE|TRANSACTION|COMPTABILITE|JURIDIQUE|MARKETING|MAILS|MODELES|ARCHIVES|...'`
- `entity_type` — `VARCHAR(40) NULL COMMENT 'IMB|BIEN|MDT|CTX|EMP|FOUR|TIERS|...'`
- `entity_id` — `BIGINT UNSIGNED NULL`
- `societe_id` — `INT UNSIGNED NULL`
- `agence_id` — `INT UNSIGNED NULL`
- `service_id` — `INT UNSIGNED NULL`
- `gdrive_folder_id` — `VARCHAR(120) NULL COMMENT 'ID Google Drive (si stockage Drive)'`
- `position` — `INT NOT NULL DEFAULT 0 COMMENT 'Ordre d''affichage parmi les freres'`
- `is_system` — `TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dossier systeme intouchable'`
- `is_archived` — `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by` — `INT UNSIGNED NULL`
- `updated_by` — `INT UNSIGNED NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- `deleted_at` — `DATETIME NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_folders_uuid` (`uuid`)`
- `UNIQUE KEY `uk_ged_folders_parent_slug` (`parent_id`, `slug`)`

### `ged_folder_levels` (source: `public_html/inc/migrations/20260502_ged_v1_01_folders.php:65`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `level_number` — `TINYINT UNSIGNED NOT NULL COMMENT '1 a 6'`
- `level_name` — `VARCHAR(80) NOT NULL COMMENT 'Metier / Domaine / Type / Entite / Annee / Mois'`
- `description` — `VARCHAR(255) NULL`
- `is_required` — `TINYINT(1) NOT NULL DEFAULT 0`
- `is_editable` — `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_folder_levels` (`tenant_id`, `module`, `level_number`)`

### `ged_folder_templates` (source: `public_html/inc/migrations/20260502_ged_v1_01_folders.php:81`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `module` — `VARCHAR(50) NOT NULL`
- `code` — `VARCHAR(80) NOT NULL COMMENT 'Identifiant stable du template'`
- `name` — `VARCHAR(180) NOT NULL`
- `description` — `TEXT NULL`
- `is_default` — `TINYINT(1) NOT NULL DEFAULT 0`
- `is_active` — `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_folder_templates_code` (`tenant_id`, `module`, `code`)`

### `ged_folder_template_nodes` (source: `public_html/inc/migrations/20260502_ged_v1_01_folders.php:96`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_id` — `BIGINT UNSIGNED NOT NULL`
- `parent_node_id` — `BIGINT UNSIGNED NULL`
- `depth` — `TINYINT UNSIGNED NOT NULL DEFAULT 0`
- `slug` — `VARCHAR(120) NOT NULL`
- `name_display` — `VARCHAR(255) NOT NULL`
- `position` — `INT NOT NULL DEFAULT 0`
- `is_required` — `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_documents` (source: `public_html/inc/migrations/20260502_ged_v1_02_documents.php:24`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `uuid` — `CHAR(36) NOT NULL`
- `tenant_id` — `INT UNSIGNED NULL`
- `folder_id` — `BIGINT UNSIGNED NULL COMMENT 'FK ged_folders (NULL si non encore range)'`
- `societe_id` — `INT UNSIGNED NULL`
- `agence_id` — `INT UNSIGNED NULL`
- `service_id` — `INT UNSIGNED NULL`
- `name_display` — `VARCHAR(255) NOT NULL COMMENT 'Nom humain modifiable'`
- `name_canonical` — `VARCHAR(255) NOT NULL COMMENT 'Nom machine stable'`
- `name_file` — `VARCHAR(255) NOT NULL COMMENT 'Nom physique sur disque/Drive (avec extension)'`
- `document_type` — `VARCHAR(60) NULL COMMENT 'FACTURE | RIB | BAIL | MANDAT | DPE | MAIL | ...'`
- `gdrive_file_id` — `VARCHAR(120) NULL`
- `mime_type` — `VARCHAR(100) NULL`
- `size_bytes` — `BIGINT UNSIGNED NULL`
- `hash_sha256` — `CHAR(64) NULL COMMENT 'Deduplication'`
- `search_vector` — `TEXT NULL COMMENT 'Texte indexe (FULLTEXT)'`
- `metadata` — `JSON NULL`
- `linked_entities` — `JSON NULL COMMENT 'Snapshot rapide des entites liees (lecture rapide)'`
- `version` — `INT UNSIGNED NOT NULL DEFAULT 1`
- `parent_document_id` — `BIGINT UNSIGNED NULL COMMENT 'Version anterieure ou doc maitre'`
- `created_by` — `INT UNSIGNED NULL`
- `updated_by` — `INT UNSIGNED NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- `deleted_at` — `DATETIME NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_documents_uuid` (`uuid`)`

### `ged_document_links` (source: `public_html/inc/migrations/20260502_ged_v1_02_documents.php:67`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `document_id` — `BIGINT UNSIGNED NOT NULL`
- `entity_type` — `VARCHAR(40) NOT NULL COMMENT 'IMB | BIEN | MDT | CTX | EMP | FOUR | TIERS | SDC | ...'`
- `entity_id` — `BIGINT UNSIGNED NOT NULL`
- `relation_type` — `VARCHAR(40) NOT NULL DEFAULT 'main' COMMENT 'main | annexe | reference | piece_jointe'`
- `is_validated` — `TINYINT(1) NOT NULL DEFAULT 0`
- `validated_by` — `INT UNSIGNED NULL`
- `validated_at` — `DATETIME NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_document_links` (`document_id`, `entity_type`, `entity_id`, `relation_type`)`

### `ged_document_relations` (source: `public_html/inc/migrations/20260502_ged_v1_02_documents.php:86`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `from_document_id` — `BIGINT UNSIGNED NOT NULL`
- `to_document_id` — `BIGINT UNSIGNED NOT NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_document_relations` (`from_document_id`, `to_document_id`, `relation_type`)`

### `ged_tags` (source: `public_html/inc/migrations/20260502_ged_v1_03_tags_index.php:20`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `slug` — `VARCHAR(80) NOT NULL`
- `label` — `VARCHAR(120) NOT NULL`
- `color` — `VARCHAR(20) NULL COMMENT 'Hex #RRGGBB ou nom de couleur'`
- `description` — `VARCHAR(255) NULL`
- `is_system` — `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by` — `INT UNSIGNED NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_tags_slug` (`tenant_id`, `slug`)`

### `ged_document_tags` (source: `public_html/inc/migrations/20260502_ged_v1_03_tags_index.php:34`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `document_id` — `BIGINT UNSIGNED NOT NULL`
- `tag_id` — `BIGINT UNSIGNED NOT NULL`
- `created_by` — `INT UNSIGNED NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_document_tags` (`document_id`, `tag_id`)`

### `ged_index` (source: `public_html/inc/migrations/20260502_ged_v1_03_tags_index.php:46`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `document_id` — `BIGINT UNSIGNED NOT NULL`
- `index_key` — `VARCHAR(80) NOT NULL COMMENT 'annee | mois | fournisseur | montant | locataire | ...'`
- `index_value` — `VARCHAR(255) NOT NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_jobs` (source: `public_html/inc/migrations/20260502_ged_jobs_queue.php:22`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NULL`
- `job_type` — `VARCHAR(60) NOT NULL COMMENT 'ex: ged_analyze_advanced'`
- `run_at` — `DATETIME NULL COMMENT 'NULL = asap'`
- `analysis_id` — `INT UNSIGNED NULL COMMENT 'Référence ged_analyses.id si applicable'`
- `attempts` — `TINYINT UNSIGNED NOT NULL DEFAULT 0`
- `max_attempts` — `TINYINT UNSIGNED NOT NULL DEFAULT 3`
- `last_error` — `TEXT NULL`
- `locked_by` — `VARCHAR(80) NULL COMMENT 'worker identity'`
- `locked_until` — `DATETIME NULL COMMENT 'lease'`
- `started_at` — `DATETIME NULL`
- `finished_at` — `DATETIME NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_status` (`status`)`
- `KEY `idx_type_status` (`job_type`, `status`)`
- `KEY `idx_run_at` (`run_at`)`
- `KEY `idx_analysis` (`analysis_id`)`
- `KEY `idx_locked_until` (`locked_until`)`

### `ged_permissions` (source: `public_html/inc/migrations/20260502_ged_v1_04_security_audit.php:21`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `target_id` — `BIGINT UNSIGNED NOT NULL`
- `subject_id` — `INT UNSIGNED NULL COMMENT 'NULL pour role generique'`
- `subject_role_code` — `VARCHAR(40) NULL COMMENT 'Pour subject_type = role'`
- `granted_by` — `INT UNSIGNED NULL`
- `granted_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `expires_at` — `DATETIME NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_audit_log` (source: `public_html/inc/migrations/20260502_ged_v1_04_security_audit.php:39`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `actor_id` — `INT UNSIGNED NULL COMMENT 'User a l''origine'`
- `actor_role` — `VARCHAR(40) NULL`
- `action` — `VARCHAR(60) NOT NULL COMMENT 'create_folder | update_folder | archive_folder | create_doc | update_doc | move_doc | link_doc | unlink_doc | view_doc | download_doc | recalc_tree | resync_drive | ...'`
- `target_type` — `VARCHAR(40) NULL`
- `target_id` — `BIGINT UNSIGNED NULL`
- `before_json` — `TEXT NULL`
- `after_json` — `TEXT NULL`
- `message` — `VARCHAR(500) NULL`
- `ip_address` — `VARCHAR(45) NULL`
- `user_agent` — `VARCHAR(255) NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_retention_rules` (source: `public_html/inc/migrations/20260502_ged_v1_04_security_audit.php:60`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `code` — `VARCHAR(80) NOT NULL`
- `name` — `VARCHAR(180) NOT NULL`
- `description` — `TEXT NULL`
- `document_type` — `VARCHAR(60) NULL`
- `module` — `VARCHAR(50) NULL`
- `min_keep_years` — `SMALLINT UNSIGNED NULL COMMENT 'Annees minimales de conservation'`
- `max_keep_years` — `SMALLINT UNSIGNED NULL`
- `is_legal` — `TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = obligation legale'`
- `legal_reference` — `VARCHAR(255) NULL`
- `is_active` — `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_retention_rules_code` (`tenant_id`, `code`)`

### `ged_arbo_sync_log` (source: `public_html/inc/migrations/20260502_ged_v1_04_security_audit.php:82`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `actor_id` — `INT UNSIGNED NULL`
- `started_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `finished_at` — `DATETIME NULL`
- `nb_folders_processed` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_errors` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `error_log` — `TEXT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_import_batches` (source: `public_html/inc/migrations/20260502_ged_v1_09_import_tables.php:30`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `uuid` — `CHAR(36) NOT NULL`
- `tenant_id` — `INT UNSIGNED NULL`
- `batch_name` — `VARCHAR(180) NOT NULL`
- `uploaded_by` — `INT UNSIGNED NULL`
- `nb_items` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_validated` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_ignored` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `validated_at` — `DATETIME NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_import_batches_uuid` (`uuid`)`

### `ged_import_items` (source: `public_html/inc/migrations/20260502_ged_v1_09_import_tables.php:49`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `batch_id` — `BIGINT UNSIGNED NOT NULL`
- `tenant_id` — `INT UNSIGNED NULL`
- `old_folder_path` — `VARCHAR(1024) NULL COMMENT 'Chemin source (si upload dossier)'`
- `old_filename` — `VARCHAR(255) NOT NULL`
- `file_extension` — `VARCHAR(20) NULL`
- `mime_type` — `VARCHAR(100) NULL`
- `size_bytes` — `BIGINT UNSIGNED NULL`
- `hash_sha256` — `CHAR(64) NULL COMMENT 'Pour deduplication'`
- `storage_path` — `VARCHAR(500) NULL COMMENT 'Chemin disque temporaire du fichier upload'`
- `detected_text_preview` — `TEXT NULL COMMENT 'Premiers 1-2 ko de texte extrait (PDF/image)'`
- `selected_n1` — `VARCHAR(80) NULL`
- `selected_n2` — `VARCHAR(80) NULL`
- `selected_n3` — `VARCHAR(80) NULL`
- `selected_n4` — `VARCHAR(80) NULL`
- `selected_n5` — `VARCHAR(80) NULL`
- `title_user` — `VARCHAR(255) NULL COMMENT 'Titre humain modifiable'`
- `name_display` — `VARCHAR(255) NULL`
- `name_canonical` — `VARCHAR(500) NULL COMMENT 'Format : N1_SOC_AGENCE_REF_NOM_N2_..._N6_TITRE_YYMM'`
- `proposed_destination` — `VARCHAR(1024) NULL COMMENT 'Path GED MBI proposé (slugs/separated)'`
- `final_destination` — `VARCHAR(1024) NULL COMMENT 'Path GED MBI choisi (après validation)'`
- `confidence_score` — `TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100'`
- `error_message` — `TEXT NULL`
- `created_document_id` — `BIGINT UNSIGNED NULL COMMENT 'FK ged_documents.id si validé'`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_import_releves_batches` (source: `public_html/inc/migrations/20260502_ged_import_releves_banque_zip.php:19`)

**Colonnes**
- `id` — `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `id_societe` — `INT UNSIGNED NULL`
- `created_by` — `INT UNSIGNED NULL`
- `mois_annee_defaut` — `CHAR(7) NULL COMMENT 'YYYY-MM si période non reconnue'`
- `commentaire` — `TEXT NULL`
- `nb_zip` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_pdf` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_reconnus` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_a_valider` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `nb_erreurs` — `INT UNSIGNED NOT NULL DEFAULT 0`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `KEY `idx_societe` (`id_societe`)`
- `KEY `idx_statut` (`statut`)`
- `KEY `idx_created_at` (`created_at`)`

### `ged_import_releves_items` (source: `public_html/inc/migrations/20260502_ged_import_releves_banque_zip.php:41`)

**Colonnes**
- `id` — `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `batch_id` — `INT UNSIGNED NOT NULL`
- `zip_original` — `VARCHAR(255) NULL`
- `fichier_original` — `VARCHAR(255) NOT NULL`
- `pdf_rel_path` — `VARCHAR(500) NULL COMMENT 'Chemin staging (storage/ged/...)'`
- `pdf_sha256` — `CHAR(64) NULL`
- `pdf_taille_octets` — `INT UNSIGNED NULL`
- `id_immeuble` — `INT UNSIGNED NULL`
- `id_bien` — `INT UNSIGNED NULL`
- `banque_detectee` — `VARCHAR(80) NULL`
- `periode_annee` — `SMALLINT UNSIGNED NULL`
- `periode_mois` — `TINYINT UNSIGNED NULL`
- `raison_detection` — `TEXT NULL`
- `erreur` — `TEXT NULL`
- `drive_file_id_immeuble` — `VARCHAR(80) NULL`
- `drive_file_id_compta` — `VARCHAR(80) NULL`
- `drive_url_immeuble` — `VARCHAR(255) NULL`
- `drive_url_compta` — `VARCHAR(255) NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `validated_at` — `DATETIME NULL`
- `validated_by` — `INT UNSIGNED NULL`

**Index / clés**
- `KEY `idx_batch_sha` (`batch_id`, `pdf_sha256`)`
- `KEY `idx_batch` (`batch_id`)`
- `KEY `idx_statut` (`statut`)`
- `KEY `idx_immeuble` (`id_immeuble`)`
- `KEY `idx_logiciel` (`logiciel_comptable`)`
- `KEY `idx_periode` (`periode_annee`, `periode_mois`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_releves_batch` FOREIGN KEY (`batch_id`) REFERENCES `ged_import_releves_batches`(`id`) ON DELETE CASCADE`

### `ged_level_codes` (source: `public_html/inc/migrations/20260502_ged_v1_10_levels_seed.php:25`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `level_number` — `TINYINT UNSIGNED NOT NULL COMMENT '1 a 6'`
- `parent_n1` — `VARCHAR(80) NULL`
- `parent_n2` — `VARCHAR(80) NULL`
- `parent_n3` — `VARCHAR(80) NULL`
- `parent_n4` — `VARCHAR(80) NULL`
- `label` — `VARCHAR(180) NOT NULL COMMENT 'Libellé humain affiché dans le bouton'`
- `position` — `INT NOT NULL DEFAULT 0`
- `is_active` — `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_level_codes_path` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`)`

### `ged_synonyms` (source: `public_html/inc/migrations/20260502_ged_v2_19_synonyms_feedback_rules.php:26`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `target_level` — `TINYINT UNSIGNED NULL COMMENT 'Niveau visé (1-5) si connu'`
- `weight` — `SMALLINT NOT NULL DEFAULT 100 COMMENT 'Poids 0-1000 pour scoring IA (defaut 100)'`
- `auto_validated` — `TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = synonyme valide humainement (poids x2)'`
- `created_by` — `INT UNSIGNED NULL`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_synonyms_keyword_code` (`tenant_id`, `keyword`, `normalized_code`, `module`)`

### `ged_classification_feedback` (source: `public_html/inc/migrations/20260502_ged_v2_19_synonyms_feedback_rules.php:97`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `import_item_id` — `BIGINT UNSIGNED NULL COMMENT 'ged_import_items.id si applicable'`
- `document_id` — `BIGINT UNSIGNED NULL COMMENT 'ged_documents.id si applicable'`
- `old_filename` — `VARCHAR(255) NULL`
- `field` — `VARCHAR(40) NOT NULL COMMENT 'n1 | n2 | n3 | n4 | n5 | n6 | entity | date | title'`
- `suggestion_value` — `VARCHAR(255) NULL COMMENT 'Ce que l IA avait propose'`
- `correction_value` — `VARCHAR(255) NULL COMMENT 'Ce que l humain a corrige'`
- `module` — `VARCHAR(50) NULL`
- `user_id` — `INT UNSIGNED NULL`
- `weight_applied` — `SMALLINT NOT NULL DEFAULT 100 COMMENT 'Pour reprendre le poids dans les futures suggestions'`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `ged_naming_rules` (source: `public_html/inc/migrations/20260502_ged_v2_19_synonyms_feedback_rules.php:118`)

**Colonnes**
- `id` — `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tenant_id` — `INT UNSIGNED NULL`
- `template` — `VARCHAR(255) NOT NULL DEFAULT '{N1}_{SOC}_{AGE}_[{REF}_{ENTITE}_]{N2}[_{N3}[_{N4}[_{N5}]]]_[{N6}_]{TITLE}_{YYMMDD}.{ext}'`
- `separator` — `VARCHAR(5) NOT NULL DEFAULT '_'`
- `max_length` — `SMALLINT NOT NULL DEFAULT 120`
- `entity_max_length` — `TINYINT NOT NULL DEFAULT 15 COMMENT 'NOM_ENTITE max chars'`
- `title_max_length` — `TINYINT NOT NULL DEFAULT 30 COMMENT 'TITLE max chars'`
- `n6_max_length` — `TINYINT NOT NULL DEFAULT 25`
- `is_active` — `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at` — `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_ged_naming_rules_module` (`tenant_id`, `module`)`

