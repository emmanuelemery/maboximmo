<?php
declare(strict_types=1);
/**
 * api/ged_rename.php — Renomme UNIQUEMENT le nom affiché d'un document GED existant.
 *
 * Cas d'usage : on re-dépose un fichier déjà classé (empreinte identique) pour lui donner
 * un meilleur nom. Le fichier physique et TOUS les liens (bail, bien, immeuble…) restent
 * intacts ; seul `name_display` (+ `name_canonical`) change. AUCUNE suppression.
 *
 * POST JSON/form : ged_id, name, csrf (ou en-tête X-CSRF-Token).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function gr_out(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gr_out(['ok' => false, 'error' => 'POST requis'], 405);

// Corps JSON (fetch) fusionné dans $_POST.
$raw = file_get_contents('php://input');
if ($raw && ($j = json_decode($raw, true)) && is_array($j)) {
    $_POST = array_merge($_POST, $j);
}

// CSRF : même schéma que api/fluxbox_action.php (session csrf_token via POST `csrf` ou en-tête).
$csrfReceived = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfExpected = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfExpected !== '' && !hash_equals($csrfExpected, $csrfReceived)) {
    gr_out(['ok' => false, 'error' => 'CSRF invalide'], 403);
}

// Réservé admin / super admin (même politique que les actions GED sensibles).
$isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)(current_role_id() ?? 0), [1, 7], true);
if (!$isAdmin) gr_out(['ok' => false, 'error' => 'Réservé administrateurs.'], 403);

$pdo   = $GLOBALS['pdo'];
$gedId = (int)($_POST['ged_id'] ?? 0);
$name  = trim((string)($_POST['name'] ?? ''));
if ($gedId <= 0)  gr_out(['ok' => false, 'error' => 'ged_id manquant'], 400);
if ($name === '') gr_out(['ok' => false, 'error' => 'Nom vide'], 400);
if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);

// Charge le doc + contrôle de périmètre société (super admin bypass).
$st = $pdo->prepare("SELECT id, tenant_id, name_display FROM ged_documents WHERE id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1");
$st->execute([$gedId]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) gr_out(['ok' => false, 'error' => 'Document introuvable'], 404);

if (!is_super_admin()) {
    $soc = (int)($_SESSION['id_societe'] ?? 0);
    if (!empty($doc['tenant_id']) && (int)$doc['tenant_id'] !== $soc) {
        gr_out(['ok' => false, 'error' => 'Hors périmètre société'], 403);
    }
}

$old = (string)$doc['name_display'];
if ($old === $name) gr_out(['ok' => true, 'unchanged' => true, 'name' => $name]);

// name_canonical = base du nom sans extension (cohérent avec gus_commit_document).
$canon = pathinfo($name, PATHINFO_FILENAME) ?: $name;

try {
    $pdo->prepare("UPDATE ged_documents SET name_display = ?, name_canonical = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$name, $canon, $gedId]);
} catch (Throwable $e) {
    gr_out(['ok' => false, 'error' => 'Échec renommage : ' . $e->getMessage()], 500);
}

if (class_exists('AuditLog')) {
    try { AuditLog::log($pdo, 'GED_RENAME', 'ged_documents', $gedId, ['name_display' => $old], ['name_display' => $name]); } catch (Throwable) {}
}

gr_out(['ok' => true, 'ged_id' => $gedId, 'name' => $name]);
