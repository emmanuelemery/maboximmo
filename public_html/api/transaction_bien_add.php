<?php
// api/transaction_bien_add.php — Met le bien dans le tableau Transaction (V0)
// Ne crée PAS de nouveau bien. Marque seulement type_commercialisation + statut.
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien = (int)(post('id_bien') ?? 0);
$type   = (string)(post('type_commercialisation') ?? '');
if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }
if (!in_array($type, ['vente','location'], true)) { echo json_encode(['ok'=>false,'error'=>'type invalide']); exit; }

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSociete    = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

try {
    // Scope check
    $stmt = $pdo->prepare('SELECT id, id_societe, id_agence, statut_bien, type_commercialisation FROM biens WHERE id = ? LIMIT 1');
    $stmt->execute([$idBien]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }
    if (!$isSuperAdmin && $idSociete !== null && $b['id_societe'] !== null && (int)$b['id_societe'] !== $idSociete) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope société']); exit;
    }

    // Met à jour type_commercialisation + date_mise_en_vente si vide
    $upd = $pdo->prepare('UPDATE biens
        SET type_commercialisation = :t,
            date_mise_en_vente = IFNULL(date_mise_en_vente, CURDATE()),
            date_modification = NOW()
        WHERE id = :id');
    $upd->execute([':t' => $type, ':id' => $idBien]);

    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'type'=>$type]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
