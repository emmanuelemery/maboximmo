<?php
/**
 * Migration : table `dossier_vente` — PIVOT du cycle de vente (Phase 1).
 *
 * RÈGLE ARCHITECTURALE (audit Bailleur↔Transaction 2026-06-13) :
 *   Le dossier de vente AGRÈGE l'existant par référence, il ne duplique RIEN.
 *   - Acteurs (vendeur/acquéreur/notaire…) → via `tiers_roles`
 *       (objet_type='dossier_vente', id_objet=dossier_vente.id) — AUCUNE table d'acteurs.
 *   - Documents (offre/compromis/acte/facture) → via `ged_document_links`
 *       (entity_type='DOSSIER', entity_id=dossier_vente.id) — GED unique conservée.
 *   - Prix/montants → restent dans biens.prix_*, bien_prix, estimation_agence_*
 *       (lus par référence, JAMAIS recopiés ici).
 *
 * `etape` = état macro, source de vérité unique de l'étape. Se synchronise depuis
 * les sous-systèmes maîtres de leur phase (mandats, leads_annonces, hook acte→bien).
 *
 * Convention créateur : colonne `id_user` (alignée sur `mandats.id_user`, pas created_by).
 *
 * Idempotent : CREATE TABLE IF NOT EXISTS. UK(id_bien) = 1 dossier de vente par bien
 *   (la revente d'un même bien des années plus tard sera traitée en phase ultérieure).
 *
 * Le runner (admin_migrations.php) n'exécute que la clé `sql`. La clé `down` est
 * fournie comme script de rollback documenté (à exécuter manuellement si besoin).
 */
return [
    'id'          => '20260614_dossier_vente',
    'title'       => 'Pivot dossier_vente (Phase 1)',
    'description' => "Crée dossier_vente : pivot léger du cycle de vente (estimation→mandat→commercialisation→offre→compromis→acte→solde, + sans_suite/perdu). Agrège l'existant par référence (acteurs via tiers_roles, docs via ged_document_links, prix via biens/bien_prix). Aucune duplication.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `dossier_vente` (
    `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_bien`            INT(10) UNSIGNED NOT NULL,
    `id_mandat`          INT(10) UNSIGNED NULL,            -- rempli à la signature du mandat de vente
    `id_societe`         INT(10) UNSIGNED NOT NULL,
    `id_agence`          INT(10) UNSIGNED NULL,
    `etape`              ENUM('estimation','mandat','commercialisation','offre','compromis','acte','solde','sans_suite','perdu')
                         NOT NULL DEFAULT 'estimation',
    `date_estimation`    DATE NULL,
    `date_mandat`        DATE NULL,
    `date_compromis`     DATE NULL,
    `date_acte`          DATE NULL,
    `honoraires_montant` DECIMAL(12,2) NULL,               -- renseigné en phase ultérieure (barème honoraires)
    `source`             VARCHAR(30) NOT NULL DEFAULT 'estimation', -- estimation|backfill|offre|manual
    `commentaire`        TEXT NULL,
    `id_user`            INT(10) UNSIGNED NULL,             -- créateur (convention mandats.id_user)
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dossier_bien` (`id_bien`),
    KEY `idx_etape` (`etape`),
    KEY `idx_societe` (`id_societe`),
    KEY `idx_mandat` (`id_mandat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
    ,
    // ── Rollback (non exécuté par le runner — manuel) ────────────────────
    'down' => <<<'SQL'
-- Supprime aussi les liens créés par le pivot (acteurs + documents).
DELETE FROM tiers_roles        WHERE objet_type = 'dossier_vente';
DELETE FROM ged_document_links WHERE entity_type = 'DOSSIER';
DROP TABLE IF EXISTS `dossier_vente`;
SQL
];
