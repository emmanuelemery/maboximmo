<?php
// api/transaction_doc_rematch.php — Re-match bien depuis ia_data déjà extrait (coût zéro IA)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_once __DIR__ . '/../inc/transaction_doc_match.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

tr_staging_ensure_table($pdo);

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$stagingId = (int)(post('staging_id') ?? 0);
if ($stagingId <= 0) { echo json_encode(['ok'=>false,'error'=>'staging_id manquant']); exit; }

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    $row = tr_staging_check_owner($pdo, $stagingId, $userId);
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'staging introuvable']); exit; }

    $meta = is_string($row['metadata']) ? (json_decode($row['metadata'], true) ?: []) : [];
    $iaData = $meta['ia_data'] ?? null;
    if (!is_array($iaData)) {
        echo json_encode(['ok'=>false,'error'=>'pas d\'extraction IA — lancer l\'analyse d\'abord']);
        exit;
    }

    $roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
    $isManager = ($roleId === 1 || $roleId === 2);
    $idSoc = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

    $match = transaction_doc_match_bien($pdo, $iaData, $isManager, $idSoc);
    $matchBiens = $match['match_biens'] ?: [];
    $creationNeeded = $match['creation_needed'];
    $bestScore = !empty($matchBiens) ? (int)($matchBiens[0]['score'] ?? 0) : 0;
    $bestBienId = !empty($matchBiens) && !$creationNeeded ? (int)$matchBiens[0]['id'] : null;

    // Persiste les nouveaux match
    $meta['match_biens'] = $matchBiens;
    $meta['creation_needed'] = $creationNeeded;
    $meta['selected_bien_id'] = $bestBienId;
    $meta['confidence'] = $bestScore;
    $pdo->prepare('UPDATE transaction_chargement_staging SET metadata = ? WHERE id = ?')
        ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $stagingId]);

    echo json_encode([
        'ok'              => true,
        'match_biens'     => $matchBiens,
        'creation_needed' => $creationNeeded,
        'selected_bien_id'=> $bestBienId,
        'confidence'      => $bestScore,
    ]);
} catch (Throwable $e) {
    error_log('[transaction_doc_rematch] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
