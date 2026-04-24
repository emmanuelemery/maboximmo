<?php
/**
 * api/ged_classification_get.php — Retourne l'état d'un item de classification
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ((int)current_role_id() !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Super admin only']));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id manquant']));
}

try {
    $pdo = $GLOBALS['pdo'];
    $st = $pdo->prepare("
        SELECT c.id, c.id_manifest, c.id_proprietaire, c.id_immeuble, c.id_bien,
               c.confidence, c.score, c.comment, c.validated, c.hint_filename,
               c.hint_proprio, c.hint_adresse, c.creation_needed_json,
               m.filename, m.path_source, m.famille_doc
        FROM ged_classification_staging c
        JOIN ged_manifest m ON m.id = c.id_manifest
        WHERE c.id = ? LIMIT 1
    ");
    $st->execute([$id]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('Item introuvable');
    if (!empty($item['creation_needed_json'])) {
        $item['creation_needed'] = json_decode($item['creation_needed_json'], true);
    }
    echo json_encode(['ok' => true, 'item' => $item]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
