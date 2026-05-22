<?php
/**
 * Migration : Module Transaction V0 (2026-05-18)
 *
 * Ajoute le socle SQL du module Transaction :
 *   - biens : suivi commercialisation (date_mise_en_vente, prix_demande_initial,
 *     prix_final_vente, date_retrait_commercialisation, priorite_vente)
 *   - leads_annonces : structuration des offres d'achat (prix_propose,
 *     financement_type, statut_offre)
 *   - transaction_chargement_staging : table de staging pour le module
 *     "Chargement par lot" (fichiers en attente de validation, reload-safe)
 *   - vw_transactions : vue agrégée bien + annonce courante + KPIs leads
 *
 * Compatibilité : MariaDB 10.0.2+ / MySQL 8.0.29+ (ADD COLUMN IF NOT EXISTS).
 * Statements additifs uniquement → rejouables sans casse.
 */

return [
    'id'          => '20260518_transaction_v0',
    'title'       => 'Module Transaction V0 — ALTER biens + leads_annonces, vue vw_transactions, table staging',
    'description' => "Socle SQL du module Transaction : 5 colonnes sur biens (commercialisation + priorite_vente), 3 colonnes sur leads_annonces (offres), table transaction_chargement_staging, vue vw_transactions agrégée.",
    'created_at'  => '2026-05-18',
    'sql'         => <<<'SQL'

-- ─── A. biens : suivi commercialisation + priorité ──────────────────────────
ALTER TABLE `biens`
    ADD COLUMN IF NOT EXISTS `date_mise_en_vente` DATE NULL DEFAULT NULL COMMENT 'Date mise en commercialisation' AFTER `prix_vente_estime`,
    ADD COLUMN IF NOT EXISTS `date_retrait_commercialisation` DATE NULL DEFAULT NULL COMMENT 'Date retrait marché' AFTER `date_mise_en_vente`,
    ADD COLUMN IF NOT EXISTS `prix_demande_initial` DECIMAL(14,2) NULL DEFAULT NULL AFTER `date_retrait_commercialisation`,
    ADD COLUMN IF NOT EXISTS `prix_final_vente` DECIMAL(14,2) NULL DEFAULT NULL AFTER `prix_demande_initial`,
    ADD COLUMN IF NOT EXISTS `priorite_vente` VARCHAR(20) NULL DEFAULT NULL COMMENT 'haute|normale|differee' AFTER `prix_final_vente`,
    ADD INDEX IF NOT EXISTS `idx_biens_date_mise_en_vente` (`date_mise_en_vente`),
    ADD INDEX IF NOT EXISTS `idx_biens_priorite_vente` (`priorite_vente`);

-- ─── B. leads_annonces : structuration offres ───────────────────────────────
ALTER TABLE `leads_annonces`
    ADD COLUMN IF NOT EXISTS `prix_propose` DECIMAL(14,2) NULL DEFAULT NULL AFTER `message`,
    ADD COLUMN IF NOT EXISTS `financement_type` VARCHAR(20) NULL DEFAULT NULL COMMENT 'cash|emprunt|mixte|inconnu' AFTER `prix_propose`,
    ADD COLUMN IF NOT EXISTS `statut_offre` VARCHAR(30) NULL DEFAULT NULL COMMENT 'recue|transmise_vendeur|acceptee|refusee|contre_offre|expiree' AFTER `financement_type`,
    ADD INDEX IF NOT EXISTS `idx_leads_statut_offre` (`statut_offre`);

-- ─── C. Table staging chargement par lot ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transaction_chargement_staging` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) NULL,
  `size_bytes` BIGINT UNSIGNED NULL,
  `metadata` JSON NULL COMMENT 'detected_type, ia_data, match_biens, selected_bien_id, etc.',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_staging_user` (`user_id`),
  INDEX `idx_staging_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── D. Vue agrégée vw_transactions ─────────────────────────────────────────
DROP VIEW IF EXISTS `vw_transactions`;

CREATE VIEW `vw_transactions` AS
SELECT
  b.id                              AS bien_id,
  b.reference_bien,
  b.designation,
  b.type_commercialisation,
  b.usage_bien,
  b.statut_bien,
  b.id_societe,
  b.id_agence,
  b.id_proprietaire,
  COALESCE(NULLIF(b.ville, ''), i.ville)             AS ville,
  COALESCE(NULLIF(b.code_postal, ''), i.code_postal) AS code_postal,
  COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1)     AS adresse_1,
  b.surface_habitable,
  b.nb_pieces,
  b.dpe_classe,
  b.ges_classe,
  b.loyer_hc,
  b.charges_locatives,
  b.prix_vente_estime,
  b.rendement_brut,
  b.date_mise_en_vente,
  b.date_retrait_commercialisation,
  b.prix_demande_initial,
  b.prix_final_vente,
  b.priorite_vente,
  b.estimation_agence_vente,
  b.estimation_agence_location,
  a.id                              AS annonce_id,
  a.type_transaction,
  a.statut                          AS annonce_statut,
  a.etat_publication,
  a.prix                            AS annonce_prix,
  a.loyer                           AS annonce_loyer,
  a.honoraires                      AS annonce_honoraires,
  a.visible_portails,
  a.visible_maboximmo,
  a.date_mise_en_ligne,
  a.score_qualite                   AS annonce_score_qualite,
  COALESCE(leads.nb_leads_total, 0)      AS nb_leads_total,
  COALESCE(leads.nb_visites, 0)           AS nb_visites,
  COALESCE(leads.nb_offres_actives, 0)    AS nb_offres_actives,
  leads.meilleure_offre,
  leads.dernier_contact,
  leads.derniere_offre_date,
  COALESCE(docs.nb_docs, 0)               AS nb_docs,
  CASE
    WHEN b.prix_final_vente IS NOT NULL OR b.date_retrait_commercialisation IS NOT NULL THEN 'vendu'
    WHEN COALESCE(leads.nb_offres_actives, 0) > 0 THEN 'offre_recue'
    WHEN a.etat_publication = 'diffusee' OR a.visible_portails = 1 OR a.visible_maboximmo = 1 THEN 'commercialise'
    WHEN b.statut_bien = 'actif' THEN 'pret'
    ELSE 'a_preparer'
  END                                     AS statut_transaction,
  b.date_creation                         AS bien_date_creation,
  b.date_modification                     AS bien_date_modification
FROM biens b
LEFT JOIN immeubles i ON i.id = b.id_immeuble
LEFT JOIN annonces a
  ON a.id_bien = b.id
 AND a.id = (
       SELECT MAX(a2.id) FROM annonces a2
       WHERE a2.id_bien = b.id
         AND (a2.statut IS NULL OR a2.statut <> 'archivee')
     )
LEFT JOIN (
  SELECT
    l.id_bien,
    COUNT(*) AS nb_leads_total,
    SUM(CASE WHEN l.type_contact IN ('visite','demande_visite') THEN 1 ELSE 0 END) AS nb_visites,
    SUM(CASE WHEN l.type_contact = 'offre'
              AND (l.statut_offre IS NULL OR l.statut_offre NOT IN ('refusee','expiree')) THEN 1 ELSE 0 END) AS nb_offres_actives,
    MAX(CASE WHEN l.type_contact = 'offre' THEN l.prix_propose END) AS meilleure_offre,
    MAX(l.date_creation) AS dernier_contact,
    MAX(CASE WHEN l.type_contact = 'offre' THEN l.date_creation END) AS derniere_offre_date
  FROM leads_annonces l
  WHERE l.id_bien IS NOT NULL
  GROUP BY l.id_bien
) AS leads ON leads.id_bien = b.id
LEFT JOIN (
  SELECT
    CAST(JSON_UNQUOTE(JSON_EXTRACT(d.metadata, '$.classement.bien_id_bdd')) AS UNSIGNED) AS bien_id,
    COUNT(*) AS nb_docs
  FROM ged_documents d
  WHERE d.status = 'active'
    AND d.source_module = '05_TRANSACTION'
    AND JSON_EXTRACT(d.metadata, '$.classement.bien_id_bdd') IS NOT NULL
  GROUP BY bien_id
) AS docs ON docs.bien_id = b.id
WHERE b.type_commercialisation IS NOT NULL
  AND b.type_commercialisation <> ''
  AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'));

SQL
];
