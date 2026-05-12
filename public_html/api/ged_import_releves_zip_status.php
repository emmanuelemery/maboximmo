<?php
declare(strict_types=1);

/**
 * GED — Import ZIP relevés bancaires : status + items
 * Fichier : public_html/api/ged_import_releves_zip_status.php
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo       = $GLOBALS['pdo'];
$userId    = (int)current_user_id();
$roleId    = (int)current_role_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

$batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
if ($batchId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'batch_id manquant']);
    exit;
}

try {
    $where = "id = :id";
    $params = ['id' => $batchId];
    if (!$isAdmin && $societeId > 0) {
        $where .= " AND (id_societe = :sid OR id_societe IS NULL)";
        $params['sid'] = $societeId;
    }

    $stB = $pdo->prepare("SELECT * FROM ged_import_releves_batches WHERE {$where} LIMIT 1");
    $stB->execute($params);
    $batch = $stB->fetch(PDO::FETCH_ASSOC);
    if (!$batch) throw new RuntimeException('Batch introuvable');

    $stI = $pdo->prepare("
        SELECT id, zip_original, fichier_original, pdf_sha256, pdf_taille_octets,
               id_immeuble, id_bien, logiciel_comptable, banque_detectee,
               periode_annee, periode_mois,
               confiance_globale, confiance_immeuble,
               statut, raison_detection, erreur,
               drive_url_immeuble, drive_url_compta,
               created_at, validated_at
        FROM ged_import_releves_items
        WHERE batch_id = ?
        ORDER BY id ASC
        LIMIT 2000
    ");
    $stI->execute([$batchId]);
    $items = $stI->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'    => true,
        'batch' => $batch,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

