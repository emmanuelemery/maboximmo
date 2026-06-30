<?php
/**
 * api/bien_statut_set.php — Change le statut métier d'un bien (sortie de portefeuille).
 * Statuts autorisés ici : vendu | gestion_perdu | brouillon | archive.
 * (Le passage en 'actif' reste réservé à la VALIDATION via api/bien_validate.php.)
 * POST : id_bien, statut, csrf_token (form 'ajouter_bien').
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
$statut = strtolower(trim((string)($_POST['statut'] ?? '')));
$allowed = ['vendu', 'gestion_perdu', 'brouillon', 'archive'];
if ($bienId <= 0) { exit(json_encode(['ok'=>false,'error'=>'id_bien manquant'])); }
if (!in_array($statut, $allowed, true)) { exit(json_encode(['ok'=>false,'error'=>'statut non autorisé'])); }

try {
    $st = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ?");
    $st->execute([$bienId]);
    $bienSoc = (int)($st->fetchColumn() ?: 0);
    if ($bienSoc === 0) { exit(json_encode(['ok'=>false,'error'=>'bien introuvable'])); }
    if (!$isSuperAdmin && $societeId > 0 && $bienSoc !== $societeId) {
        http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Bien hors de votre société']));
    }
    $pdo->prepare("UPDATE biens SET statut_bien = ?, date_modification = NOW() WHERE id = ?")->execute([$statut, $bienId]);
    echo json_encode(['ok'=>true, 'statut_bien'=>$statut], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[bien_statut_set] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
