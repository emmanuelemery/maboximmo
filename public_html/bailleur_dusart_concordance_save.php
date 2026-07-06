<?php
/**
 * bailleur_dusart_concordance_save.php — Valide une concordance : recopie la valeur d'un
 * lot placeholder de scénario (ex. SCN-DUSART-XXX) sur le VRAI bien choisi, puis retire
 * la valeur du placeholder (pour ne pas la compter deux fois).
 *
 * POST : placeholder_id, target_id, scenario (def 'dusart')
 * Réservé super-admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/bien_prix.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Réservé super-admin']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$userId    = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$placeholder = (int)($_POST['placeholder_id'] ?? 0);
$target      = (int)($_POST['target_id'] ?? 0);
$action      = (string)($_POST['action'] ?? 'concordance');
$scenario    = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_POST['scenario'] ?? 'dusart'))) ?: 'dusart';
if ($scenario === 'courant') { echo json_encode(['ok'=>false,'error'=>'Interdit sur le Courant']); exit; }
if ($placeholder <= 0) { echo json_encode(['ok'=>false,'error'=>'Lot manquant']); exit; }

// ── Action « vendu » : retire le lot de la file (valeur non comptée + marquée VENDU) ──
if ($action === 'vendu') {
    try {
        $n = $pdo->prepare("UPDATE bien_prix SET is_courant=0, commentaire='VENDU (concordance)'
                            WHERE id_bien=? AND type_valeur='prix_vente' AND scenario_code=? AND is_courant=1");
        $n->execute([$placeholder, $scenario]);
        echo json_encode(['ok'=>true, 'vendu'=>true]); exit;
    } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
}

if ($target <= 0) { echo json_encode(['ok'=>false,'error'=>'Paramètres manquants']); exit; }
if ($placeholder === $target)          { echo json_encode(['ok'=>false,'error'=>'Placeholder = cible']); exit; }

// Montant du placeholder pour ce scénario
$st = $pdo->prepare("SELECT montant, scenario_label FROM bien_prix
                     WHERE id_bien=? AND type_valeur='prix_vente' AND scenario_code=? AND is_courant=1 AND montant>0
                     ORDER BY id DESC LIMIT 1");
$st->execute([$placeholder, $scenario]);
$src = $st->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo json_encode(['ok'=>false,'error'=>'Aucune valeur '.$scenario.' sur le lot source']); exit; }
$montant = (float)$src['montant'];
$label   = $src['scenario_label'] ?: 'Valorisation DUSART';

// Le bien cible existe ?
$stT = $pdo->prepare("SELECT id, reference_bien FROM biens WHERE id=? LIMIT 1");
$stT->execute([$target]);
$tb = $stT->fetch(PDO::FETCH_ASSOC);
if (!$tb) { echo json_encode(['ok'=>false,'error'=>'Bien cible introuvable']); exit; }

try {
    $pdo->beginTransaction();
    // 1) écrit la valeur sur le vrai bien
    $res = bien_prix_valider($pdo, $target, 'prix_vente', $montant, 'concordance', $userId,
                             'Concordance depuis lot ' . $placeholder, false, $scenario, $label);
    if (empty($res['ok'])) { throw new RuntimeException($res['error'] ?? 'échec écriture'); }
    // 2) retire la valeur du placeholder (plus comptée dans ce scénario)
    $pdo->prepare("UPDATE bien_prix SET is_courant=0
                   WHERE id_bien=? AND type_valeur='prix_vente' AND scenario_code=? AND is_courant=1")
        ->execute([$placeholder, $scenario]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}
echo json_encode(['ok'=>true, 'target'=>$target, 'target_ref'=>$tb['reference_bien'], 'montant'=>$montant], JSON_UNESCAPED_UNICODE);
