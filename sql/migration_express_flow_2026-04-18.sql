-- ═══════════════════════════════════════════════════════════════════════
-- MIGRATION Express flow + annonces — 2026-04-18
--
-- Scope :
--   1. Patterns de référence multi-société/agence (bien + annonce)
--   2. Séquences par agence pour générer les références
--   3. Colonnes annonces (slug, titre_seo, titre_lbc, meta_description, mots_cles)
--   4. Table annonces_photos (sélection/ordre par annonce)
--   5. Colonne slug sur biens (pour URLs parlantes côté site public)
--   6. Colonne id_user_actuel sur biens (commercial courant, distinct de l'historique)
--
-- Sécurité :
--   - Additive uniquement (pas de DROP, pas de modification destructive)
--   - IF NOT EXISTS sur chaque ALTER (nécessite MariaDB ≥ 10.0.2 ou MySQL ≥ 8.0)
--     → Si votre serveur ne supporte pas, commentez les lignes déjà appliquées
--   - Les anciennes colonnes SEO sur `biens` (titre_seo, meta_description, mots_cles)
--     RESTENT EN PLACE pour backward-compat — la migration de données se fera dans
--     un script séparé quand tout le code sera bascule sur annonces.*
-- ═══════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────
-- 1. PATTERNS DE RÉFÉRENCE — Société (défaut)
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `ref_pattern_bien` VARCHAR(200) NOT NULL
    DEFAULT '{TYPE3}-{VILLE3}-{YY}-{SEQ:04}-{USER3}'
    COMMENT 'Pattern référence bien par défaut société (tokens : {TYPE3} {VILLE3} {YY} {YYYY} {SOC} {AGE} {USER3} {SEQ:nn})',
  ADD COLUMN IF NOT EXISTS `ref_pattern_annonce` VARCHAR(200) NOT NULL
    DEFAULT '{BIEN_REF}-{TRANS3}-{ANN_SEQ:02}'
    COMMENT 'Pattern référence annonce (tokens supplémentaires : {BIEN_REF} {TRANS3} {ANN_SEQ:nn})',
  ADD COLUMN IF NOT EXISTS `ref_annual_reset` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = réinit séquence chaque 1er janvier ; 0 = continue';

-- ─────────────────────────────────────────────────────────────
-- 2. PATTERNS & COMPTEURS — Agence (override + séquence)
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `ref_pattern_bien` VARCHAR(200) NULL
    COMMENT 'Override du pattern société pour cette agence (NULL = hérite société)',
  ADD COLUMN IF NOT EXISTS `ref_pattern_annonce` VARCHAR(200) NULL
    COMMENT 'Override du pattern annonce pour cette agence',
  ADD COLUMN IF NOT EXISTS `ref_seq_bien_current` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Compteur bien courant de cette agence',
  ADD COLUMN IF NOT EXISTS `ref_seq_annonce_current` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Compteur annonce courant de cette agence',
  ADD COLUMN IF NOT EXISTS `ref_seq_year` INT UNSIGNED NULL
    COMMENT 'Année du compteur (pour détecter le reset annuel)';

-- ─────────────────────────────────────────────────────────────
-- 3. BIENS — slug public + commercial courant
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(200) NULL
    COMMENT 'Slug SEO pour URL publique (/bien/{slug})',
  ADD COLUMN IF NOT EXISTS `id_user_actuel` INT UNSIGNED NULL
    COMMENT 'Commercial actuellement en charge (peut différer de celui encodé dans reference_bien)',
  ADD INDEX IF NOT EXISTS `idx_biens_slug` (`slug`),
  ADD INDEX IF NOT EXISTS `idx_biens_user_actuel` (`id_user_actuel`);

-- ─────────────────────────────────────────────────────────────
-- 4. ANNONCES — référence + slug + SEO + LBC
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `reference_annonce` VARCHAR(100) NULL
    COMMENT 'Référence générée par pattern agence, unique',
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(200) NULL
    COMMENT 'Slug SEO pour URL publique',
  ADD COLUMN IF NOT EXISTS `titre_seo` VARCHAR(100) NULL
    COMMENT 'Titre <title> Google (50-60 chars)',
  ADD COLUMN IF NOT EXISTS `titre_lbc` VARCHAR(100) NULL
    COMMENT 'Titre Le Bon Coin (≤70 chars, punchy)',
  ADD COLUMN IF NOT EXISTS `meta_description` VARCHAR(200) NULL
    COMMENT 'Meta description Google (150-160 chars + CTA)',
  ADD COLUMN IF NOT EXISTS `mots_cles` TEXT NULL
    COMMENT 'Mots-clés SEO (séparés par virgule)',
  ADD COLUMN IF NOT EXISTS `h1_public` VARCHAR(150) NULL
    COMMENT 'H1 affiché sur la page publique (peut différer du titre_seo)',
  ADD INDEX IF NOT EXISTS `idx_annonces_ref` (`reference_annonce`),
  ADD INDEX IF NOT EXISTS `idx_annonces_slug` (`slug`);

-- ─────────────────────────────────────────────────────────────
-- 5. ANNONCES_PHOTOS — sélection/ordre des photos par annonce
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `annonces_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annonce` INT UNSIGNED NOT NULL,
  `id_biens_photo` INT UNSIGNED NOT NULL,
  `ordre` INT NOT NULL DEFAULT 0,
  `alt_text` VARCHAR(255) NULL COMMENT 'Override SEO de description_ia pour cette annonce',
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annonce_photo` (`id_annonce`, `id_biens_photo`),
  KEY `idx_annonce_ordre` (`id_annonce`, `ordre`),
  KEY `idx_biens_photo` (`id_biens_photo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sélection/ordre des photos du bien pour chaque annonce';

-- ─────────────────────────────────────────────────────────────
-- Vérifications post-migration (à exécuter manuellement)
-- ─────────────────────────────────────────────────────────────
-- SHOW COLUMNS FROM societes LIKE 'ref_%';
-- SHOW COLUMNS FROM agences LIKE 'ref_%';
-- SHOW COLUMNS FROM biens LIKE 'slug';
-- SHOW COLUMNS FROM biens LIKE 'id_user_actuel';
-- SHOW COLUMNS FROM annonces LIKE 'titre_%';
-- SHOW COLUMNS FROM annonces LIKE 'slug';
-- SHOW TABLES LIKE 'annonces_photos';
