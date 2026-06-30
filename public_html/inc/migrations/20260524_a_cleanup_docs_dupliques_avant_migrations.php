<?php
/**
 * Migration : Cleanup docs dupliqués par hash AVANT migrations LOCA→REGIE et backfill tenant
 *
 * Sprint Pipeline Auto (2026-05-26).
 * ⚠️ À LANCER EN PREMIER (préfixe "a_" pour ordre alphabétique).
 *
 * Problème prévenu :
 *  - La migration `20260524_migration_loca_to_regie_emery` UPDATE fluxbox_documents
 *    SET tenant_id=1 WHERE tenant_id=3 → viole UK(tenant_id, hash_sha256) si
 *    un doc tenant=1 avec le même hash existe déjà.
 *  - Bug constaté en prod : "Duplicate entry '1-92ae61e7...' for key 'uk_fluxbox_documents_tenant_hash'"
 *
 * Solution généralisée :
 *  - Identifier TOUS les fluxbox_documents NON-tenant=1 ayant un jumeau tenant=1 (même hash)
 *  - Repointer fluxbox_cartes.document_id → jumeau
 *  - Repointer ged_documents.fluxbox_source_id → jumeau
 *  - DELETE les docs orphelins/duplicates
 *
 * Couvre :
 *   - Anciens orphelins tenant=0/NULL (cas dev résiduel)
 *   - Doublons LOCA (tenant=3) avec REGIE (tenant=1)
 *   - Tout autre cas de doublon hash entre tenants
 *
 * Idempotent : si aucun doublon, ne fait rien (UPDATE 0 row, DELETE 0 row).
 */

return [
    'id'          => '20260524_a_cleanup_docs_dupliques_avant_migrations',
    'title'       => 'PRÉ-MIGRATION — Cleanup fluxbox_documents dupliqués par hash (avant LOCA→REGIE)',
    'description' => "À LANCER EN PREMIER. Identifie tous les fluxbox_documents NON-tenant=1 ayant un jumeau (même hash_sha256) sous tenant=1. Repointe les fluxbox_cartes liées + ged_documents.fluxbox_source_id, puis DELETE les docs en doublon. Évite les violations UK(tenant_id, hash_sha256) lors de la migration LOCA→REGIE et des backfill tenant.",
    'created_at'  => '2026-05-26',
    'sql'         => <<<'SQL'

-- ─── ÉTAPE 1 : Repointer fluxbox_cartes vers le jumeau tenant=1 ────────
UPDATE `fluxbox_cartes` c
INNER JOIN `fluxbox_documents` orphan ON orphan.id = c.document_id
INNER JOIN `fluxbox_documents` twin
        ON twin.hash_sha256 = orphan.hash_sha256
       AND twin.tenant_id = 1
       AND twin.id != orphan.id
SET c.document_id = twin.id,
    c.tenant_id   = 1
WHERE orphan.tenant_id != 1;

-- ─── ÉTAPE 2 : Repointer ged_documents.fluxbox_source_id vers le jumeau
UPDATE `ged_documents` ge
INNER JOIN `fluxbox_documents` orphan ON orphan.id = ge.fluxbox_source_id
INNER JOIN `fluxbox_documents` twin
        ON twin.hash_sha256 = orphan.hash_sha256
       AND twin.tenant_id = 1
       AND twin.id != orphan.id
SET ge.fluxbox_source_id = twin.id,
    ge.updated_at = NOW()
WHERE orphan.tenant_id != 1;

-- ─── ÉTAPE 3 : DELETE les docs orphelins/dupliqués (plus aucune référence)
DELETE orphan FROM `fluxbox_documents` orphan
INNER JOIN `fluxbox_documents` twin
        ON twin.hash_sha256 = orphan.hash_sha256
       AND twin.tenant_id = 1
       AND twin.id != orphan.id
WHERE orphan.tenant_id != 1;

-- ─── Vérification post-migration ──────────────────────────────────────
-- SELECT COUNT(*) FROM fluxbox_documents fd1
--   INNER JOIN fluxbox_documents fd2
--     ON fd1.hash_sha256 = fd2.hash_sha256 AND fd1.id != fd2.id
--   WHERE fd1.tenant_id = 1 AND fd2.tenant_id != 1;
-- → attendu 0 (plus aucun doublon hash entre tenant=1 et autres)

SQL
];
