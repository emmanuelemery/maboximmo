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

// Accès : super admin OU agent scopé à la société du document (même règle que
// api/ged_doc_serve.php). Avant : super-admin-only → tout user « tous niveaux »
// tombait en 403 alors que le binaire (serve), lui, autorisait l'accès scopé.
// Le contrôle de société se fait APRÈS lecture du doc (on a besoin de societe_id).
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);

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
                                mime_type, size_bytes, hash_sha256, metadata,
                                status, version, created_by, created_at, updated_at, fluxbox_source_id
                         FROM ged_documents WHERE id = ?");
    $st->execute([$id]);
    $out['ged_document'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Scope société (bypass super admin) — aligné sur ged_doc_serve.php.
    if ($out['ged_document'] && !$isAdmin
        && !empty($out['ged_document']['societe_id'])
        && (int)$out['ged_document']['societe_id'] !== (int)($_SESSION['id_societe'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['error' => 'Hors société']);
        exit;
    }

    if ($out['ged_document']) {
        // Links
        $st = $pdo->prepare("SELECT id, tenant_id, entity_type, entity_id, relation_type,
                                    is_validated, validated_by, validated_at, created_at
                             FROM ged_document_links WHERE document_id = ? ORDER BY id ASC");
        $st->execute([$id]);
        $out['links'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Libellé humain de chaque entité liée (nom immeuble / adresse bien / nom tiers…) — pas le code.
        $gdiLabel = function(string $et, int $eid) use ($pdo): string {
            $et = strtoupper($et); if ($eid <= 0) return '';
            try {
                if ($et === 'BIEN') { $q=$pdo->prepare("SELECT COALESCE(NULLIF(designation,''),NULLIF(adresse_1,''),reference_bien) FROM biens WHERE id=?"); $q->execute([$eid]); return (string)($q->fetchColumn() ?: ''); }
                if ($et === 'IMB' || $et === 'IMMEUBLE') { $q=$pdo->prepare("SELECT COALESCE(NULLIF(nom_immeuble,''),NULLIF(adresse_1,'')) FROM immeubles WHERE id=?"); $q->execute([$eid]); return (string)($q->fetchColumn() ?: ''); }
                if ($et === 'TIERS') { $q=$pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''),NULLIF(societe,''),NULLIF(TRIM(CONCAT_WS(' ',prenom,nom)),'')) FROM tiers WHERE id=?"); $q->execute([$eid]); return (string)($q->fetchColumn() ?: ''); }
                if ($et === 'BAIL') { $q=$pdo->prepare("SELECT COALESCE(NULLIF(locataire_raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',locataire_prenom,locataire_nom)),'')) FROM bien_baux WHERE id=?"); $q->execute([$eid]); return (string)($q->fetchColumn() ?: ''); }
            } catch (Throwable) {}
            return '';
        };
        foreach ($out['links'] as &$_lk) { $_lk['entity_label'] = $gdiLabel((string)$_lk['entity_type'], (int)$_lk['entity_id']); }
        unset($_lk);

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
