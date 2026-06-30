<?php
/**
 * bailleur_scenario_save_bulk.php — Enregistre EN MASSE les prix affichés dans un
 * scénario de valorisation (IFI, mini, maxi, snapshot…). Prend les valeurs indiquées,
 * sans jamais toucher le scénario 'courant' (prix diffusé annonce/Ubiflow).
 * POST : scenario_code, scenario_label, items=JSON [{b:id_bien, p:id_proprietaire, prix:N}]
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_once __DIR__ . '/inc/bien_prix.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès réservé au module Bailleur']); exit;
}

$scenario = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_POST['scenario_code'] ?? '')));
$label    = trim((string)($_POST['scenario_label'] ?? '')) ?: $scenario;
$items    = json_decode((string)($_POST['items'] ?? '[]'), true) ?: [];

// PROTECTION : on n'écrase JAMAIS le prix courant (diffusé) par un enregistrement de masse.
if ($scenario === '' || $scenario === 'courant') {
    echo json_encode(['ok'=>false, 'error'=>'Enregistrement de masse réservé aux scénarios (pas le Courant, pour ne pas écraser le prix diffusé). Validez le Courant bien par bien.']); exit;
}
if (!$items) { echo json_encode(['ok'=>false, 'error'=>'Aucun prix à enregistrer.']); exit; }

// Périmètre : biens autorisés pour cet utilisateur
$bienScope = $isSuperAdmin
    ? "SELECT id FROM biens"
    : "SELECT b.id FROM biens b WHERE
         b.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=".$userId.")
         OR b.id IN (SELECT s.id_bien FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg
                     WHERE t.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=".$userId."))";
$allowed = [];
foreach ($pdo->query($bienScope) as $r) { $allowed[(int)$r['id']] = true; }

$n = 0; $skipped = 0;
try {
    $pdo->beginTransaction();
    foreach ($items as $it) {
        $bid  = (int)($it['b'] ?? 0);
        $prix = (float)($it['prix'] ?? 0);
        if ($bid <= 0 || $prix <= 0)    { continue; }
        if (empty($allowed[$bid]))      { $skipped++; continue; }   // hors périmètre
        $res = bien_prix_valider($pdo, $bid, 'prix_vente', $prix, 'patrimoine', $userId, $label, false, $scenario, $label);
        if (!empty($res['ok'])) $n++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}
echo json_encode(['ok'=>true, 'scenario'=>$scenario, 'n'=>$n, 'hors_perimetre'=>$skipped], JSON_UNESCAPED_UNICODE);
