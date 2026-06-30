<?php
/**
 * api/ged_doc_info.php
 *
 * Endpoint debug JSON pour inspecter un ged_documents sans phpMyAdmin.
 * Demandé par Claude for Chrome (4ème passe test E2E).
 *
 * GET ?id=X → JSON avec champs essentiels + liens + audit MVP.
 * Accès super admin uniquement.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'super admin uniquement']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$id  = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['error' => 'id requis']);
    exit;
}

$out = ['ged_document' => null, 'links' => [], 'audit_mvp' => null, 'folder' => null];

try {
    // Doc
    $st = $pdo->prepare("SELECT id, uuid, tenant_id, folder_id, societe_id, agence_id,
                                name_display, name_canonical, name_file,
                                document_type, source_module, storage_provider,
                                mime_type, size_bytes, hash_sha256,
                                status, version, created_by, created_at, updated_at, fluxbox_source_id
                         FROM ged_documents WHERE id = ?");
    $st->execute([$id]);
    $out['ged_document'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($out['ged_document']) {
        // Links
        $st = $pdo->prepare("SELECT id, tenant_id, entity_type, entity_id, relation_type,
                                    is_validated, validated_by, validated_at, created_at
                             FROM ged_document_links WHERE document_id = ? ORDER BY id ASC");
        $st->execute([$id]);
        $out['links'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Audit MVP-T (via fluxbox_source_id → carte → audit)
        if (!empty($out['ged_document']['fluxbox_source_id'])) {
            $st = $pdo->prepare("SELECT a.id, a.carte_id, a.tenant_id, a.action_label, a.statut,
                                        a.executed_at,
                                        JSON_EXTRACT(a.result_json, '$.ged_document_id') AS gid
                                 FROM fluxbox_actions_ia a
                                 JOIN fluxbox_cartes c ON c.id = a.carte_id
                                 WHERE c.document_id = ?
                                   AND a.action_label LIKE 'MVP Transaction%'
                                 ORDER BY a.id DESC LIMIT 5");
            $st->execute([(int)$out['ged_document']['fluxbox_source_id']]);
            $out['audit_mvp'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Folder cible
        if (!empty($out['ged_document']['folder_id'])) {
            $st = $pdo->prepare("SELECT id, slug, name_display, path_cache, module
                                 FROM ged_folders WHERE id = ?");
            $st->execute([(int)$out['ged_document']['folder_id']]);
            $out['folder'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
