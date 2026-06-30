<?php
/**
 * inc/ged_document_links.php
 *
 * GED CENTRALE UNIQUE — Pipeline + fonctions de liaison polymorphe.
 *
 * RÈGLE ARCHITECTURALE (Emmanuel 2026-05-25) :
 *  - 1 SEULE source de stockage : `ged_documents`
 *  - 1 SEULE table de liens métier : `ged_document_links` (polymorphe doc↔entité)
 *  - 1 SEUL pipeline d'upload/renommage/commit : `gus_commit_document()`
 *  - Toute page qui upload un doc DOIT passer par ces fonctions
 *  - Plus de tables locales `*_documents` parallèles (biens_documents, etc.)
 *
 * Fonctions exposées :
 *   ── Pipeline upload ────────────────────────────────────────────
 *   gus_commit_document($pdo, $file, $ctx, $links)
 *       → INSERT ged_documents + multi ged_document_links + renomme V3.1
 *
 *   ── Liens polymorphes (CRUD) ───────────────────────────────────
 *   gdl_attach($pdo, $doc_id, $entity_type, $entity_id, $opts)
 *   gdl_detach($pdo, $doc_id, $entity_type, $entity_id, $relation_type)
 *   gdl_entities_for_document($pdo, $doc_id)
 *
 *   ── Lecture documents pour une entité ──────────────────────────
 *   gdl_documents_for_entity($pdo, $entity_type, $entity_id, $filters)
 *   gdl_documents_count_for_entity($pdo, $entity_type, $entity_id)
 *
 * Conventions entity_type (ged_document_links) :
 *   BIEN | IMB | TIERS | MDT (mandat) | BAIL | CTX (contrat)
 *   FOUR (fournisseur) | EMP (employé/RH) | SOC (société) | AGE (agence) | SDC (syndic)
 */

declare(strict_types=1);

require_once __DIR__ . '/ged_doc_naming_v3.php';

if (!function_exists('gdl_normalize_entity_type')) {
    /**
     * Normalise l'entity_type pour la cohérence en BDD (toujours upper, alias unifiés).
     */
    function gdl_normalize_entity_type(string $type): string {
        $t = strtoupper(trim($type));
        return match ($t) {
            'IMMEUBLE'       => 'IMB',
            'MANDAT'         => 'MDT',
            'LOCATION', 'LOC'=> 'BAIL',
            'CONTRAT'        => 'CTX',
            'FOURNISSEUR'    => 'FOUR',
            'EMPLOYE', 'USER', 'RH' => 'EMP',
            'SOCIETE'        => 'SOC',
            'AGENCE'         => 'AGE',
            default          => $t,
        };
    }
}

if (!function_exists('gdl_attach')) {
    /**
     * Crée un lien polymorphe doc↔entité (idempotent via UK).
     *
     * @param PDO    $pdo
     * @param int    $doc_id     ID ged_documents.id
     * @param string $entity_type  BIEN | IMB | TIERS | MDT | BAIL | …
     * @param int    $entity_id  ID de l'entité (biens.id, immeubles.id, tiers.id, …)
     * @param array  $opts {
     *     tenant_id?:     int|null,
     *     relation_type?: string = 'main',  // main|annexe|reference|piece_jointe
     *     confidence?:    float|null,        // 0.000 à 1.000 si suggéré par IA
     *     is_validated?:  bool   = false,
     *     validated_by?:  int|null,
     * }
     * @return int ID du lien créé (ou existant si déjà là)
     */
    function gdl_attach(PDO $pdo, int $doc_id, string $entity_type, int $entity_id, array $opts = []): int {
        if ($doc_id <= 0 || $entity_id <= 0) return 0;
        $type = gdl_normalize_entity_type($entity_type);
        if ($type === '') return 0;

        $relation = (string)($opts['relation_type'] ?? 'main');
        $tenant   = isset($opts['tenant_id']) ? (int)$opts['tenant_id'] : null;
        $conf     = isset($opts['confidence']) ? (float)$opts['confidence'] : null;
        $isValid  = !empty($opts['is_validated']) ? 1 : 0;
        $validBy  = isset($opts['validated_by']) ? (int)$opts['validated_by'] : null;
        $validAt  = $isValid ? date('Y-m-d H:i:s') : null;

        // INSERT ... ON DUPLICATE KEY UPDATE (UK = document_id+entity_type+entity_id+relation_type)
        $sql = "INSERT INTO ged_document_links
                   (tenant_id, document_id, entity_type, entity_id, relation_type,
                    confidence, is_validated, validated_by, validated_at, created_at)
                VALUES (:tenant, :doc, :etype, :eid, :rel, :conf, :valid, :validby, :validat, NOW())
                ON DUPLICATE KEY UPDATE
                   confidence    = COALESCE(VALUES(confidence), confidence),
                   is_validated  = GREATEST(is_validated, VALUES(is_validated)),
                   validated_by  = COALESCE(VALUES(validated_by), validated_by),
                   validated_at  = COALESCE(VALUES(validated_at), validated_at)";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':tenant'  => $tenant,
            ':doc'     => $doc_id,
            ':etype'   => $type,
            ':eid'     => $entity_id,
            ':rel'     => $relation,
            ':conf'    => $conf,
            ':valid'   => $isValid,
            ':validby' => $validBy,
            ':validat' => $validAt,
        ]);

        // Récupère l'ID (insert ou update)
        $lastId = (int)$pdo->lastInsertId();
        if ($lastId > 0) return $lastId;

        $stSel = $pdo->prepare("SELECT id FROM ged_document_links
                                 WHERE document_id=? AND entity_type=? AND entity_id=? AND relation_type=?
                                 LIMIT 1");
        $stSel->execute([$doc_id, $type, $entity_id, $relation]);
        return (int)$stSel->fetchColumn();
    }
}

if (!function_exists('gdl_detach')) {
    function gdl_detach(PDO $pdo, int $doc_id, string $entity_type, int $entity_id, string $relation_type = 'main'): bool {
        if ($doc_id <= 0 || $entity_id <= 0) return false;
        $type = gdl_normalize_entity_type($entity_type);
        $st = $pdo->prepare("DELETE FROM ged_document_links
                              WHERE document_id=? AND entity_type=? AND entity_id=? AND relation_type=?");
        return $st->execute([$doc_id, $type, $entity_id, $relation_type]);
    }
}

if (!function_exists('gdl_entities_for_document')) {
    function gdl_entities_for_document(PDO $pdo, int $doc_id): array {
        if ($doc_id <= 0) return [];
        $st = $pdo->prepare("SELECT id, entity_type, entity_id, relation_type, confidence,
                                    is_validated, validated_by, validated_at, created_at
                              FROM ged_document_links
                              WHERE document_id = ?
                              ORDER BY id ASC");
        $st->execute([$doc_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('gdl_documents_for_entity')) {
    /**
     * Liste les documents GED rattachés à une entité métier.
     *
     * @param array $filters {
     *     document_type?: string|array,  // 'DPE' | ['DPE','DIAG_AMIANTE']
     *     source_module?: string,
     *     status?:        string|array,  // 'active' (default)
     *     relation_type?: string,         // 'main' (default ['main','annexe'])
     *     limit?:         int = 200,
     *     order_by?:      string = 'created_at DESC',
     * }
     * @return array rows avec colonnes ged_documents + relation_type/confidence du lien
     */
    function gdl_documents_for_entity(PDO $pdo, string $entity_type, int $entity_id, array $filters = []): array {
        if ($entity_id <= 0) return [];
        $type = gdl_normalize_entity_type($entity_type);

        $where = ["dl.entity_type = ?", "dl.entity_id = ?"];
        $args  = [$type, $entity_id];

        // status
        $status = $filters['status'] ?? 'active';
        if ($status !== null && $status !== '') {
            if (is_array($status)) {
                $where[] = "d.status IN (" . implode(',', array_fill(0, count($status), '?')) . ")";
                $args = array_merge($args, $status);
            } else {
                $where[] = "d.status = ?";
                $args[] = (string)$status;
            }
        }

        // document_type
        if (!empty($filters['document_type'])) {
            $dt = $filters['document_type'];
            if (is_array($dt)) {
                $where[] = "d.document_type IN (" . implode(',', array_fill(0, count($dt), '?')) . ")";
                $args = array_merge($args, $dt);
            } else {
                $where[] = "d.document_type = ?";
                $args[] = (string)$dt;
            }
        }

        // source_module
        if (!empty($filters['source_module'])) {
            $where[] = "d.source_module = ?";
            $args[] = (string)$filters['source_module'];
        }

        // relation_type
        if (!empty($filters['relation_type'])) {
            $where[] = "dl.relation_type = ?";
            $args[] = (string)$filters['relation_type'];
        }

        $limit   = max(1, min(2000, (int)($filters['limit'] ?? 200)));
        $orderBy = preg_match('/^[a-zA-Z_\.]+(\s+(ASC|DESC))?$/i', (string)($filters['order_by'] ?? '')) > 0
            ? (string)$filters['order_by']
            : 'd.created_at DESC';

        $sql = "SELECT d.id, d.uuid, d.tenant_id, d.folder_id, d.societe_id, d.agence_id,
                       d.name_display, d.name_canonical, d.name_file, d.document_type,
                       d.source_module, d.storage_provider, d.mime_type, d.size_bytes,
                       d.hash_sha256, d.metadata, d.security_level, d.status, d.version,
                       d.created_by, d.created_at, d.updated_at,
                       dl.relation_type AS link_relation_type,
                       dl.confidence    AS link_confidence,
                       dl.is_validated  AS link_is_validated
                FROM ged_document_links dl
                INNER JOIN ged_documents d ON d.id = dl.document_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY $orderBy
                LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('gdl_documents_count_for_entity')) {
    function gdl_documents_count_for_entity(PDO $pdo, string $entity_type, int $entity_id, array $filters = []): int {
        if ($entity_id <= 0) return 0;
        $type = gdl_normalize_entity_type($entity_type);
        $status = $filters['status'] ?? 'active';

        $where = ["dl.entity_type = ?", "dl.entity_id = ?"];
        $args  = [$type, $entity_id];
        if ($status !== null && $status !== '') {
            $where[] = "d.status = ?";
            $args[] = (string)$status;
        }
        if (!empty($filters['document_type'])) {
            $where[] = "d.document_type = ?";
            $args[] = (string)$filters['document_type'];
        }
        $sql = "SELECT COUNT(*) FROM ged_document_links dl
                INNER JOIN ged_documents d ON d.id = dl.document_id
                WHERE " . implode(' AND ', $where);
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// PIPELINE UPLOAD UNIQUE — gus_commit_document()
// ═══════════════════════════════════════════════════════════════════════

if (!function_exists('gus_commit_document')) {
    /**
     * Pipeline central d'enregistrement d'un document dans la GED.
     *
     * À utiliser par TOUTE page qui upload un doc (bien_intake_upload,
     * fluxbox_auto_commit, transaction_upload_commit, tiers_upload, etc.).
     *
     * Le fichier doit DÉJÀ être sur disque (`$file['path_on_disk']`).
     * L'appelant fait `move_uploaded_file()` avant pour permettre l'analyse
     * IA/OCR avant le commit.
     *
     * Étapes :
     *  1. Calcule hash SHA-256 si absent
     *  2. Cherche un doc identique (même hash, même tenant) → retourne ses infos si trouvé
     *  3. Construit le nom V3.1 via gdn_v3_build($naming_ctx)
     *  4. INSERT ged_documents
     *  5. Pour chaque lien dans $links, appelle gdl_attach()
     *  6. Met à jour ged_documents.linked_entities (snapshot lecture rapide)
     *
     * @param PDO    $pdo
     * @param array  $file {
     *     path_on_disk:  string,  // chemin absolu disque
     *     name_original: string,  // nom client du fichier
     *     mime_type:     string,
     *     size_bytes:    int,
     *     public_url?:   string,  // URL relative servable (storage local)
     *     hash_sha256?:  string,
     * }
     * @param array  $ctx {
     *     document_type:  string,           // 'DIAG_DPE' | 'MANDAT_VENTE' | 'BAIL' | …
     *     source_module:  string,           // '03_GESTION_LOCATIVE' | '05_TRANSACTION' | …
     *     security_level?: string = 'interne',
     *     societe_id?:    int|null,
     *     agence_id?:     int|null,
     *     service_id?:    int|null,
     *     tenant_id?:     int|null,
     *     created_by?:    int|null,
     *     storage_provider?: string = 'local',
     *     naming_ctx:     array,            // pour gdn_v3_build() — voir ged_doc_naming_v3.php
     *     name_display?:  string,           // surcharge nom humain (default = naming_ctx résultat sans ext)
     *     metadata_extra?: array,           // injecté dans metadata JSON sous "extra"
     *     folder_id?:     int|null,
     * }
     * @param array  $links [
     *     ['entity_type'=>'BIEN','entity_id'=>733],
     *     ['entity_type'=>'TIERS','entity_id'=>456,'relation_type'=>'main','confidence'=>0.95],
     *     …
     * ]
     * @return array {
     *     ok:       bool,
     *     doc_id:   int,
     *     uuid:     string,
     *     name_file: string,
     *     name_display: string,
     *     deduplicated: bool,  // true si doc identique déjà en BDD (hash match)
     *     errors:   array,
     * }
     */
    function gus_commit_document(PDO $pdo, array $file, array $ctx, array $links = []): array {
        $errors = [];

        $path = (string)($file['path_on_disk'] ?? '');
        if ($path === '' || !is_file($path)) {
            return ['ok' => false, 'errors' => ["Fichier introuvable: $path"]];
        }

        // 1. Hash SHA-256 (déduplication)
        $hash = (string)($file['hash_sha256'] ?? '');
        if ($hash === '') {
            $hash = hash_file('sha256', $path) ?: '';
        }
        $tenantId = isset($ctx['tenant_id']) ? (int)$ctx['tenant_id'] : null;

        // 2. Déduplication : doc identique déjà en BDD ?
        $existing = null;
        if ($hash !== '') {
            try {
                $stDup = $pdo->prepare("SELECT id, uuid, name_file, name_display
                                         FROM ged_documents
                                         WHERE hash_sha256 = ? AND " .
                                        ($tenantId !== null ? "tenant_id = ?" : "tenant_id IS NULL") . "
                                         AND status IN ('active','archived')
                                         ORDER BY id ASC LIMIT 1");
                $stDup->execute($tenantId !== null ? [$hash, $tenantId] : [$hash]);
                $existing = $stDup->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                error_log('[gus_commit_document] dedup check failed: ' . $e->getMessage());
            }
        }

        if ($existing) {
            // Doc déjà en BDD → on attache juste les liens manquants
            $docId = (int)$existing['id'];
            foreach ($links as $link) {
                gdl_attach($pdo, $docId, (string)($link['entity_type'] ?? ''), (int)($link['entity_id'] ?? 0), $link);
            }
            gus_refresh_linked_entities_snapshot($pdo, $docId);
            return [
                'ok'           => true,
                'doc_id'       => $docId,
                'uuid'         => (string)$existing['uuid'],
                'name_file'    => (string)$existing['name_file'],
                'name_display' => (string)$existing['name_display'],
                'deduplicated' => true,
                'errors'       => [],
            ];
        }

        // 3. Nommage V3.1
        $namingCtx = (array)($ctx['naming_ctx'] ?? []);
        if (empty($namingCtx['ext'])) {
            $namingCtx['ext'] = strtolower(pathinfo($file['name_original'] ?? $path, PATHINFO_EXTENSION)) ?: 'pdf';
        }
        if (empty($namingCtx['source_filename']) && !empty($file['name_original'])) {
            $namingCtx['source_filename'] = (string)$file['name_original'];
        }
        // Nom physique disque : V3.1 complet 11 segments (fingerprint hors logiciel)
        $nameFile = gdn_v3_build($namingCtx);
        // Nom affichage humain : V4 simplifié 5 segments (UI/email/export utilisateur)
        $nameDisplay = (string)($ctx['name_display'] ?? '');
        if ($nameDisplay === '') {
            $nameDisplay = function_exists('gdn_v4_build_display')
                ? gdn_v4_build_display($namingCtx)
                : (string)($file['name_original'] ?? $nameFile);
        }
        $nameCanonical = pathinfo($nameDisplay, PATHINFO_FILENAME);

        // 4. INSERT ged_documents
        $uuid = function_exists('com_create_guid')
            ? trim(com_create_guid(), '{}')
            : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                mt_rand(0,0x0fff) | 0x4000, mt_rand(0,0x3fff) | 0x8000,
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
            );

        $metadata = [
            'naming_ctx'  => $namingCtx,
            'public_url'  => $file['public_url'] ?? null,
            'source_path' => $path,
        ];
        if (!empty($ctx['metadata_extra'])) {
            $metadata['extra'] = $ctx['metadata_extra'];
        }

        try {
            // FIX 2026-05-25 : ajout final_destination + old_filename (colonnes natives
            // utilisées par les pages de visualisation pour retrouver le fichier physique).
            // Avant : ces colonnes restaient NULL → "Chemin tenté : (aucun)" en preview.
            $st = $pdo->prepare("
                INSERT INTO ged_documents
                    (uuid, tenant_id, folder_id, societe_id, agence_id, service_id,
                     name_display, name_canonical, name_file,
                     document_type, source_module,
                     storage_provider, mime_type, size_bytes, hash_sha256,
                     metadata, security_level, status, version,
                     final_destination, old_filename,
                     created_by, created_at, updated_at)
                VALUES
                    (:uuid, :tenant, :folder, :soc, :age, :svc,
                     :name_disp, :name_canon, :name_file,
                     :doctype, :module,
                     :storage, :mime, :size, :hash,
                     :meta, :security, 'active', 1,
                     :final_dest, :old_name,
                     :creator, NOW(), NOW())
            ");
            $st->execute([
                ':uuid'      => $uuid,
                ':tenant'    => $tenantId,
                ':folder'    => $ctx['folder_id'] ?? null,
                ':soc'       => $ctx['societe_id'] ?? null,
                ':age'       => $ctx['agence_id'] ?? null,
                ':svc'       => $ctx['service_id'] ?? null,
                ':name_disp' => $nameDisplay,
                ':name_canon'=> $nameCanonical,
                ':name_file' => $nameFile,
                ':doctype'   => $ctx['document_type'] ?? null,
                ':module'    => $ctx['source_module'] ?? null,
                ':storage'   => $ctx['storage_provider'] ?? 'local',
                ':mime'      => $file['mime_type'] ?? 'application/pdf',
                ':size'      => (int)($file['size_bytes'] ?? 0),
                ':hash'      => $hash ?: null,
                ':meta'      => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ':security'  => $ctx['security_level'] ?? 'interne',
                ':final_dest' => $file['public_url'] ?? null,
                ':old_name'   => $file['name_original'] ?? null,
                ':creator'   => $ctx['created_by'] ?? null,
            ]);
            $docId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['INSERT ged_documents : ' . $e->getMessage()]];
        }

        // ─── Anti-collision name_display (post-INSERT) ────────────
        // gdn_v4_build_display génère ACTIVITE_ENTITE_TYPE_DATE.ext ; même date +
        // même type sur la même entité = noms identiques. On suffixe par #doc_id
        // (unique par construction) dès qu'on détecte une collision existante.
        try {
            $stColl = $pdo->prepare("
                SELECT COUNT(*) FROM ged_documents
                 WHERE name_display = :n
                   AND id <> :self
                   AND COALESCE(status, 'active') = 'active'
                   AND " . ($tenantId !== null ? "tenant_id = :tn" : "1=1")
            );
            $params = [':n' => $nameDisplay, ':self' => $docId];
            if ($tenantId !== null) $params[':tn'] = $tenantId;
            $stColl->execute($params);
            if ((int)$stColl->fetchColumn() > 0) {
                $extPart = pathinfo($nameDisplay, PATHINFO_EXTENSION);
                $baseDsp = pathinfo($nameDisplay, PATHINFO_FILENAME);
                $newDisplay   = $baseDsp . '_' . $docId . ($extPart ? '.' . $extPart : '');
                $newCanonical = pathinfo($newDisplay, PATHINFO_FILENAME);
                $pdo->prepare("UPDATE ged_documents
                                  SET name_display = :nd, name_canonical = :nc
                                WHERE id = :id")
                    ->execute([':nd' => $newDisplay, ':nc' => $newCanonical, ':id' => $docId]);
                $nameDisplay   = $newDisplay;
                $nameCanonical = $newCanonical;
            }
        } catch (Throwable $e) {
            error_log('[gus_commit_document anti-collision] ' . $e->getMessage());
        }

        // 5. Liens polymorphes
        foreach ($links as $link) {
            $linkOpts = $link;
            if ($tenantId !== null && !isset($linkOpts['tenant_id'])) {
                $linkOpts['tenant_id'] = $tenantId;
            }
            gdl_attach($pdo, $docId, (string)($link['entity_type'] ?? ''), (int)($link['entity_id'] ?? 0), $linkOpts);
        }

        // 6. Snapshot lecture rapide
        gus_refresh_linked_entities_snapshot($pdo, $docId);

        return [
            'ok'           => true,
            'doc_id'       => $docId,
            'uuid'         => $uuid,
            'name_file'    => $nameFile,
            'name_display' => $nameDisplay,
            'deduplicated' => false,
            'errors'       => $errors,
        ];
    }
}

if (!function_exists('gus_refresh_linked_entities_snapshot')) {
    /**
     * Recalcule ged_documents.linked_entities (JSON snapshot pour lecture rapide
     * sans JOIN). Format : [{"type":"BIEN","id":733}, {"type":"TIERS","id":12}].
     */
    function gus_refresh_linked_entities_snapshot(PDO $pdo, int $doc_id): void {
        if ($doc_id <= 0) return;
        try {
            $st = $pdo->prepare("SELECT entity_type, entity_id FROM ged_document_links
                                  WHERE document_id = ? ORDER BY id ASC");
            $st->execute([$doc_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $snap = array_map(fn($r) => ['type' => $r['entity_type'], 'id' => (int)$r['entity_id']], $rows);
            $pdo->prepare("UPDATE ged_documents SET linked_entities = ?, updated_at = NOW() WHERE id = ?")
                ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), $doc_id]);
        } catch (Throwable $e) {
            error_log('[gus_refresh_linked_entities_snapshot] ' . $e->getMessage());
        }
    }
}
