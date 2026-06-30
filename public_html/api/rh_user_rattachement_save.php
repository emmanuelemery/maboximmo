<?php
declare(strict_types=1);
/**
 * api/rh_user_rattachement_save.php — Sauvegarde du rattachement d'un user :
 *   - société employeur (UNE) → users.id_societe
 *   - agence principale       → users.id_agence (ancre du filtrage tenant)
 *   - agences couvertes (N)   → table user_agences (N-N), même société
 *
 * Admin uniquement. Les agences doivent appartenir à la société choisie
 * (cohérence employeur). Accès cross-société = grants à périmètre, pas ici.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
if ($roleId !== 1) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Admin requis']); exit; }

$data     = json_decode(file_get_contents('php://input'), true) ?? [];
$targetId = (int)($data['user_id'] ?? 0);
if ($targetId <= 0) { echo json_encode(['success'=>false,'error'=>'user_id manquant']); exit; }

$idSoc = (int)($data['id_societe'] ?? 0) ?: null;

// Agences demandées
$agences = array_values(array_filter(array_unique(array_map('intval', (array)($data['agences'] ?? []))), fn($x) => $x > 0));

// Cohérence employeur : ne garder que les agences de la société choisie
if ($idSoc && $agences) {
    $in  = implode(',', array_fill(0, count($agences), '?'));
    $st  = $pdo->prepare("SELECT id FROM agences WHERE id_societe = ? AND id IN ($in)");
    $st->execute(array_merge([$idSoc], $agences));
    $agences = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// Agence principale = celle demandée si valide, sinon la 1ʳᵉ retenue
$idAgPrimary = (int)($data['id_agence'] ?? 0);
if (!in_array($idAgPrimary, $agences, true)) {
    $idAgPrimary = $agences[0] ?? 0;
}
$idAg = $idAgPrimary ?: null;

$pdo->exec("CREATE TABLE IF NOT EXISTS user_agences (
    id_user   INT NOT NULL,
    id_agence INT NOT NULL,
    PRIMARY KEY (id_user, id_agence),
    KEY idx_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->prepare("UPDATE users SET id_societe=?, id_agence=?, date_modification=NOW() WHERE id=?")
    ->execute([$idSoc, $idAg, $targetId]);

$pdo->prepare("DELETE FROM user_agences WHERE id_user=?")->execute([$targetId]);
if ($agences) {
    $ins = $pdo->prepare("INSERT IGNORE INTO user_agences (id_user, id_agence) VALUES (?,?)");
    foreach ($agences as $a) { $ins->execute([$targetId, $a]); }
}

echo json_encode(['success'=>true, 'id_societe'=>$idSoc, 'id_agence'=>$idAg, 'agences'=>$agences]);
