<?php
/**
 * Migration : Pipeline Ultime 01 — Backfill tenant_id orphelins
 *
 * Sprint Pipeline Auto (2026-05-26).
 * Corrige les rows fluxbox_cartes, fluxbox_documents, ged_documents,
 * ged_document_links qui ont tenant_id = 0 ou NULL.
 *
 * Règle métier : défaut Régie EMERY (société #1) pour les orphelins.
 *
 * Pourquoi :
 *  - Cause : ingestion antérieure ne renseignait pas tenant_id systématiquement
 *  - Symptôme : cartes invisibles dans la pile FluxBox (filtre tenant)
 *  - Symptôme : ged_documents avec societe_id/agence_id NULL → noms V3 cassés
 *  - Impact : ~2 cartes orphelines + ~1 ged_document NULL en dev (variable en prod)
 *
 * Idempotent : si tenant déjà = 1, rien ne change.
 */

return [
    'id'          => '20260526_pipeline_ultime_01_backfill_tenant_ids',
    'title'       => 'Pipeline Ultime — Backfill tenant_id orphelins (Régie EMERY par défaut)',
    'description' => "Backfill tenant_id = 1 (Régie EMERY) sur les rows fluxbox_cartes, fluxbox_documents, ged_documents et ged_document_links qui ont tenant_id = 0 ou NULL. Aligne aussi societe_id/agence_id sur ged_documents NULL via cascade (bien → immeuble → défaut 1/3).",
    'created_at'  => '2026-05-26',
    'sql'         => <<<'SQL'

-- ─── fluxbox_cartes : tenant=0 → tenant=1 ─────────────────────────────
UPDATE `fluxbox_cartes`
SET `tenant_id` = 1
WHERE `tenant_id` = 0 OR `tenant_id` IS NULL;

-- ─── fluxbox_documents : tenant=0 → tenant=1 ──────────────────────────
-- ATTENTION : peut violer UK(tenant_id, hash_sha256) si doublons existent.
-- Pour traiter les doublons : la migration 02 (cleanup_docs_dupliques) doit
-- être lancée AVANT celle-ci. Sinon ce UPDATE échoue sur les conflits.
-- IGNORE pour passer outre les conflits restants (rare).
UPDATE IGNORE `fluxbox_documents`
SET `tenant_id` = 1
WHERE `tenant_id` = 0 OR `tenant_id` IS NULL;

-- ─── ged_documents : tenant_id NULL → 1 (cascade via lien BIEN) ───────
-- Étape 1 : backfill tenant_id depuis le bien lié (via ged_document_links)
UPDATE `ged_documents` ge
INNER JOIN `ged_document_links` l ON l.document_id = ge.id AND l.entity_type = 'BIEN'
INNER JOIN `biens` b ON b.id = l.entity_id
LEFT JOIN `immeubles` i ON i.id = b.id_immeuble
SET ge.tenant_id  = COALESCE(b.id_societe, i.id_societe, 1),
    ge.societe_id = COALESCE(ge.societe_id, b.id_societe, i.id_societe, 1),
    ge.agence_id  = COALESCE(ge.agence_id,  b.id_agence,  i.id_agence,  3),
    ge.updated_at = NOW()
WHERE ge.tenant_id IS NULL AND ge.status = 'active';

-- Étape 2 : fallback Régie EMERY pour ce qui reste sans bien lié
UPDATE `ged_documents`
SET `tenant_id`  = 1,
    `societe_id` = COALESCE(`societe_id`, 1),
    `agence_id`  = COALESCE(`agence_id`,  3),
    `updated_at` = NOW()
WHERE `tenant_id` IS NULL AND `status` = 'active';

-- ─── ged_document_links : tenant_id NULL → tenant du doc parent ───────
UPDATE `ged_document_links` l
INNER JOIN `ged_documents` d ON d.id = l.document_id
SET l.tenant_id = d.tenant_id
WHERE l.tenant_id IS NULL AND d.tenant_id IS NOT NULL;

-- ─── fluxbox_actions_ia : tenant_id NULL → tenant de la carte parent ──
UPDATE `fluxbox_actions_ia` a
INNER JOIN `fluxbox_cartes` c ON c.id = a.carte_id
SET a.tenant_id = c.tenant_id
WHERE (a.tenant_id IS NULL OR a.tenant_id = 0) AND c.tenant_id > 0;

-- ─── Vérification post-migration (read-only via comment) ──────────────
-- SELECT COUNT(*) FROM fluxbox_cartes WHERE tenant_id IS NULL OR tenant_id = 0;  -- attendu 0
-- SELECT COUNT(*) FROM ged_documents WHERE tenant_id IS NULL AND status='active'; -- attendu 0

SQL
];
