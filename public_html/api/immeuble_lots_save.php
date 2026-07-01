<?php
/**
 * api/immeuble_lots_save.php — Correction manuelle du nombre de lots de copropriété
 * sur la fiche immeuble (immeubles.nb_lots — colonne canonique, prioritaire sur
 * copro_nb_lots issue du registre public). Permet de corriger une erreur d'auto-remplissage.
 *
 * POST : { immeuble_id:int, nb_lots:int, csrf_token }
 * Réponse : { ok:bool, nb_lots?:int, error?:string }
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
verify_csrf_any('immeuble_lots');

$pdo   = $GLOBALS['pdo'];
$immId = (int)($_POST['immeuble_id'] ?? 0);
$nb    = (int)($_POST['nb_lots'] ?? 0);
if ($immId <= 0) exit(json_encode(['ok'=>false,'error'=>'immeuble_id requis']));
if ($nb < 0 || $nb > 100000) exit(json_encode(['ok'=>false,'error'=>'Valeur invalide']));

// Scope société
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1 || (function_exists('is_super_admin') && is_super_admin());
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$st = $pdo->prepare("SELECT id_societe FROM immeubles WHERE id = ? LIMIT 1");
$st->execute([$immId]);
$soc = $st->fetchColumn();
if ($soc === false) exit(json_encode(['ok'=>false,'error'=>'Immeuble introuvable']));
if (!$isSuperAdmin && $societeId > 0 && (int)$soc !== $societeId) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre']));
}

try {
    $pdo->prepare("UPDATE immeubles SET nb_lots = ? WHERE id = ?")->execute([$nb, $immId]);
    echo json_encode(['ok'=>true, 'nb_lots'=>$nb], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[immeuble_lots_save] ' . $e->getMessage());
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
