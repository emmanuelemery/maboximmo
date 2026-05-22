<?php
// api/transaction_bien_docs.php — Liste des docs Transaction d'un bien (V0)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$idBien = (int)($_GET['id_bien'] ?? 0);
if ($idBien <= 0) { echo json_encode(['items'=>[]]); exit; }

try {
    $sql = "SELECT id, name_display, name_file, document_type, size_bytes, created_at,
                   JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.visibilite')) AS visibilite
            FROM ged_documents
            WHERE status = 'active'
              AND source_module = '05_TRANSACTION'
              AND JSON_EXTRACT(metadata, '$.classement.bien_id_bdd') = :id_bien
            ORDER BY created_at DESC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id_bien', $idBien, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['items' => $items]);
} catch (Throwable $e) {
    error_log('[transaction_bien_docs] ' . $e->getMessage());
    echo json_encode(['items'=>[], 'error'=>$e->getMessage()]);
}
