<?php
/**
 * api/bien_immeuble_unlink.php — Dissocie l'immeuble d'un bien (id_immeuble → NULL).
 * Bloqué si le bien est actif (adresse/immeuble verrouillés). POST : id_bien, csrf_token('ajouter_bien').
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
verify_csrf_any('ajouter_bien');

$pdo          = $GLOBALS['pdo'];
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
if ($bienId <= 0) { exit(json_encode(['ok'=>false,'error'=>'id_bien manquant'])); }

try {
    $st = $pdo->prepare("SELECT id_societe, statut_bien FROM biens WHERE id = ?");
    $st->execute([$bienId]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { exit(json_encode(['ok'=>false,'error'=>'bien introuvable'])); }
    if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Bien hors de votre société']));
    }
    if (($row['statut_bien'] ?? '') === 'actif') {
        exit(json_encode(['ok'=>false,'error'=>'Bien actif : dé-valide-le d\'abord pour changer d\'immeuble']));
    }
    $pdo->prepare("UPDATE biens SET id_immeuble = NULL WHERE id = ?")->execute([$bienId]);
    echo json_encode(['ok'=>true, 'id_bien'=>$bienId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[bien_immeuble_unlink] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
