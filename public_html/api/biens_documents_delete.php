<?php
// api/biens_documents_delete.php — Supprime un document rattaché à un bien
//
// Source unique = ged_documents + ged_document_links (Sprint 7D 2026-05-25).
// La table biens_documents est conservée en lecture pour la legacy mais on ne
// la cible plus en INSERT depuis bien_intake_upload. Ce endpoint accepte donc
// les deux préfixes côté front : "ged_<id>" (cas nominal) et "<id>" (legacy).
//
// POST { id_doc, csrf_token }
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$roleId    = (int)($_SESSION['id_role']    ?? 0);

$rawIdDoc = isset($_POST['id_doc']) ? trim((string)$_POST['id_doc']) : '';
if ($rawIdDoc === '') exit(json_encode(['ok' => false, 'error' => 'id_doc manquant']));

// Préfixe "ged_" → GED unique (cas nominal). Sinon legacy biens_documents.
$useGed = false;
if (str_starts_with($rawIdDoc, 'ged_')) {
    $useGed = true;
    $rawIdDoc = substr($rawIdDoc, 4);
}
if (!ctype_digit($rawIdDoc)) exit(json_encode(['ok' => false, 'error' => 'id_doc invalide']));
$idDoc = (int)$rawIdDoc;
if ($idDoc <= 0) exit(json_encode(['ok' => false, 'error' => 'id_doc manquant']));

try {
    if ($useGed) {
        // ─── Suppression GED : soft-delete ged_documents + DELETE ged_document_links ──
        $st = $pdo->prepare("SELECT id, name_display, name_file, metadata,
                                    societe_id, tenant_id
                               FROM ged_documents
                              WHERE id = ? LIMIT 1");
        $st->execute([$idDoc]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document GED introuvable']));

        // Scope société (sauf super admin)
        $docSoc = (int)($row['societe_id'] ?? 0);
        if ($roleId !== 1 && $societeId > 0 && $docSoc > 0 && $docSoc !== $societeId) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
        }

        // Récup le path physique (metadata.public_url) pour le retirer du disque
        $meta = json_decode((string)$row['metadata'], true) ?: [];
        $publicUrl = (string)($meta['public_url'] ?? '');
        $fileDeleted = false;
        if ($publicUrl !== '') {
            $fp = dirname(__DIR__) . '/' . ltrim($publicUrl, '/');
            if (is_file($fp) && @unlink($fp)) $fileDeleted = true;
        }

        // Casse les liens polymorphes + soft-delete
        $pdo->prepare("DELETE FROM ged_document_links WHERE document_id = ?")->execute([$idDoc]);
        $pdo->prepare("UPDATE ged_documents
                          SET status = 'deleted', updated_at = NOW()
                        WHERE id = ?")->execute([$idDoc]);

        exit(json_encode([
            'ok'           => true,
            'id_doc'       => 'ged_' . $idDoc,
            'source'       => 'ged_documents',
            'file_deleted' => $fileDeleted,
        ]));
    }

    // ─── Legacy : biens_documents ────────────────────────────
    $st = $pdo->prepare("
        SELECT bd.id, bd.id_bien, bd.url_fichier, bd.nom_original, bd.type_document,
               b.id_societe
        FROM biens_documents bd
        JOIN biens b ON b.id = bd.id_bien
        WHERE bd.id = ?
        LIMIT 1
    ");
    $st->execute([$idDoc]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document introuvable']));

    if ($roleId !== 1 && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }

    $fileDeleted = false;
    if (!empty($row['url_fichier'])) {
        $fp = dirname(__DIR__) . '/' . ltrim((string)$row['url_fichier'], '/');
        if (is_file($fp) && @unlink($fp)) $fileDeleted = true;
    }

    $pdo->prepare("DELETE FROM biens_documents WHERE id = ?")->execute([$idDoc]);

    exit(json_encode([
        'ok'           => true,
        'id_doc'       => $idDoc,
        'id_bien'      => (int)$row['id_bien'],
        'file_deleted' => $fileDeleted,
        'type'         => (string)$row['type_document'],
        'source'       => 'biens_documents',
    ]));
} catch (Throwable $e) {
    error_log('[biens_documents_delete] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
