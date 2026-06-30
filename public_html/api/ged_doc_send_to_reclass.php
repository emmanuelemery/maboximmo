<?php
/**
 * api/ged_doc_send_to_reclass.php
 *
 * Transforme un document GED existant en carte FluxBox de type "reclass".
 * → réutilise l'UI "Ajuster" de fluxbox_pile.php pour modifier le classement.
 *
 * À la validation de la carte (bouton "Tout valider" ou "Ajuster + valider"),
 * `fluxbox_carte_validate()` détecte le mode reclass et met à jour ged_documents
 * directement (au lieu de créer un nouveau ged_documents via promote_to_ged).
 *
 * POST JSON : { "doc_id": 81 }
 * Response : { "ok": true, "carte_id": 99, "redirect_url": "/fluxbox_pile.php?carte=99" }
 *
 * Auth : tout user authentifié, scope check par societe_id (sauf super admin).
 * Sprint R-RECLASS — Option A (2026-05-25).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']    ?? 0);
$userSocId = (int)($_SESSION['id_societe'] ?? 0);
$tenantId  = $userSocId; // Convention MaBoxImmo : tenant_id = id_societe
$isAdmin   = ((int)($_SESSION['id_role'] ?? 0) === 1);

$body  = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$docId = (int)($body['doc_id'] ?? 0);
if ($docId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'doc_id requis']));
}

// Lecture ged_doc + scope check
$st = $pdo->prepare("SELECT id, name_display, name_file, document_type, source_module,
                            societe_id, agence_id, tenant_id, metadata
                     FROM ged_documents WHERE id = ?");
$st->execute([$docId]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Document GED introuvable']));
}
if (!$isAdmin && !empty($doc['societe_id']) && (int)$doc['societe_id'] !== $userSocId) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Document hors de votre société']));
}

$metadata  = json_decode((string)($doc['metadata'] ?? '{}'), true) ?: [];
$namingCtx = is_array($metadata['naming_ctx'] ?? null) ? $metadata['naming_ctx'] : [];

// Tenant de la carte = tenant du doc (pour qu'il apparaisse bien dans la pile de l'user)
$carteTenant = (int)($doc['tenant_id'] ?? 0) ?: $tenantId;
if (!$carteTenant) $carteTenant = 1;

// Vérifie qu'on n'a pas déjà une carte reclass pending pour ce doc (évite doublons)
try {
    $stDup = $pdo->prepare("SELECT id FROM fluxbox_cartes
                             WHERE tenant_id = ? AND statut IN ('pending','in_progress')
                               AND JSON_UNQUOTE(JSON_EXTRACT(proposition_json, '$.reclass_ged_doc_id')) = ?
                             LIMIT 1");
    $stDup->execute([$carteTenant, (string)$docId]);
    $existingCarteId = (int)$stDup->fetchColumn();
} catch (Throwable) {
    $existingCarteId = 0;
}

if ($existingCarteId > 0) {
    exit(json_encode([
        'ok'           => true,
        'carte_id'     => $existingCarteId,
        'redirect_url' => '/fluxbox_pile.php?carte=' . $existingCarteId,
        'already_existing' => true,
    ]));
}

// Construction de proposition_json (compatible avec le format attendu par fluxbox_carte_validate)
$proposition = [
    'reclass'              => true,
    'reclass_ged_doc_id'   => $docId,
    'target_societe_id'    => (int)($doc['societe_id'] ?? 0),
    'target_agence_id'     => (int)($doc['agence_id']  ?? 0),
    'classement'           => [
        'n1'        => $namingCtx['n1_slug'] ?? null,
        'n2'        => $namingCtx['n2_slug'] ?? null,
        'n3'        => $namingCtx['n3_slug'] ?? null,
        'n4'        => $namingCtx['n4_slug'] ?? null,
        'n5'        => $namingCtx['n5_slug'] ?? null,
        'type_doc'  => $doc['document_type'],
        'date'      => $namingCtx['date_doc'] ?? null,
        'source_module' => $doc['source_module'],
    ],
    'entity_instance'      => $namingCtx['entity_ref'] ?? null,
    'user_label'           => null,
    'preview_doc_id'       => $docId,  // pour iframe PDF preview
    'current_name_display' => $doc['name_display'],
    'current_name_file'    => $doc['name_file'],
];

try {
    $stIns = $pdo->prepare("INSERT INTO fluxbox_cartes
        (tenant_id, document_id, titre, sous_titre, priorite, statut, confiance_ia,
         proposition_json, created_by, created_at, updated_at)
        VALUES (?, NULL, ?, ?, 'normal', 'pending', 100,
                ?, ?, NOW(), NOW())");
    $stIns->execute([
        $carteTenant,
        '♻️ Re-classer : ' . ($doc['name_display'] ?: ('GED #' . $docId)),
        'Doc GED #' . $docId . ' · ' . ($doc['document_type'] ?: '—') . ' · modifier classement (type, module, N1/N2/N3, dates)',
        json_encode($proposition, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        $userId ?: null,
    ]);
    $carteId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Création carte échouée : ' . $e->getMessage()]));
}

echo json_encode([
    'ok'           => true,
    'carte_id'     => $carteId,
    'doc_id'       => $docId,
    'redirect_url' => '/fluxbox_pile.php?carte=' . $carteId,
    'message'      => 'Carte FluxBox de re-classement créée. La validation appliquera les changements directement à ged_documents.',
], JSON_UNESCAPED_UNICODE);
