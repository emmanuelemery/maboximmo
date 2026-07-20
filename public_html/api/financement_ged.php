<?php
declare(strict_types=1);
/**
 * api/financement_ged.php — Documents GED d'un dossier financier (T1).
 * Les documents RESTENT dans la GED ; on ne fait que les RELIER (ged_document_links,
 * entity_type='FINANCEMENT'). La catégorie du lien est rangée dans relation_type.
 *   GET  ?op=search&q=...                          → documents GED de la société
 *   POST {op:'attach', id_dossier, doc_id, categorie}   (relie un doc existant)
 *   POST {op:'detach', id_dossier, doc_id}              (retire le lien, NE supprime PAS le doc)
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/financement.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
function fo(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$pdo = $GLOBALS['pdo'];
$soc = fin_soc();
$isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)current_role_id(), [1, 7], true);

if (($_GET['op'] ?? '') === 'search') {
    $like = '%' . trim((string)($_GET['q'] ?? '')) . '%';
    try {
        $st = $pdo->prepare("SELECT id, name_display, document_type, created_at FROM ged_documents
                             WHERE (societe_id=? OR societe_id IS NULL) AND status='active' AND name_display LIKE ?
                             ORDER BY created_at DESC LIMIT 25");
        $st->execute([$soc, $like]);
        fo(['ok' => true, 'results' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { fo(['ok' => false, 'error' => $e->getMessage()], 500); }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fo(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('financement');
if (!$isAdmin) fo(['ok' => false, 'error' => 'Réservé administrateurs.'], 403);
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$dossierId = (int)($body['id_dossier'] ?? 0);
if (!fin_scope_ok($pdo, $dossierId)) fo(['ok' => false, 'error' => 'Dossier hors périmètre'], 403);
$op = (string)($body['op'] ?? '');
$docId = (int)($body['doc_id'] ?? 0);
if ($docId <= 0) fo(['ok' => false, 'error' => 'Document requis'], 400);

try {
    if ($op === 'attach') {
        $cat = (string)($body['categorie'] ?? 'autre');
        if (!isset(fin_ged_categories()[$cat])) $cat = 'autre';
        gdl_attach($pdo, $docId, 'FIN', $dossierId, ['relation_type' => $cat]); // FIN = type canonique GED pour FINANCEMENT
        AuditLog::log($pdo, 'GED_ATTACH', 'fin_dossier', $dossierId, [], ['doc_id' => $docId, 'categorie' => $cat]);
        fo(['ok' => true]);
    }
    if ($op === 'detach') {
        // Retire TOUS les liens de ce doc vers ce dossier (le document reste en GED)
        $pdo->prepare("DELETE FROM ged_document_links WHERE document_id=? AND entity_type='FIN' AND entity_id=?")->execute([$docId, $dossierId]);
        AuditLog::log($pdo, 'GED_DETACH', 'fin_dossier', $dossierId, ['doc_id' => $docId], []);
        fo(['ok' => true]);
    }
    fo(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) { fo(['ok' => false, 'error' => $e->getMessage()], 500); }
