<?php
/**
 * api/bien_set_immeuble.php — Rattache un bien à un autre immeuble (change id_immeuble).
 * Simple relink (PAS une fusion). POST : id_bien, id_immeuble, csrf_token (form 'bien_immeuble').
 * Sécurité : login + CSRF + manager (rôle 1,2,7) ou super admin.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('bien_immeuble');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isManager = in_array($roleId, [1, 2, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isManager) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$idBien = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$idImm  = isset($_POST['id_immeuble']) && ctype_digit((string)$_POST['id_immeuble']) ? (int)$_POST['id_immeuble'] : 0;
if ($idBien <= 0 || $idImm <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien + id_immeuble requis']); exit; }

try {
    $st = $pdo->prepare("SELECT nom_immeuble, adresse_1, code_postal, ville FROM immeubles WHERE id = ? LIMIT 1");
    $st->execute([$idImm]);
    $imm = $st->fetch(PDO::FETCH_ASSOC);
    if (!$imm) { echo json_encode(['ok'=>false,'error'=>'Immeuble introuvable']); exit; }

    $stB = $pdo->prepare("SELECT id FROM biens WHERE id = ? LIMIT 1");
    $stB->execute([$idBien]);
    if (!$stB->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Bien introuvable']); exit; }

    $pdo->prepare("UPDATE biens SET id_immeuble = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$idImm, $idBien]);

    echo json_encode([
        'ok'           => true,
        'id_bien'      => $idBien,
        'id_immeuble'  => $idImm,
        'immeuble_nom' => trim(($imm['nom_immeuble'] ?? '') . ' — ' . ($imm['adresse_1'] ?? '') . ' ' . ($imm['ville'] ?? '')),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
