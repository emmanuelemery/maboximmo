<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();

if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id   = (int)($data['id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false, 'error' => 'id manquant']); exit; }

// Vérifier que la session appartient à l'utilisateur
$stmt = $pdo->prepare("SELECT id_user, mois_paie FROM rh_ik_sessions WHERE id = ?");
$stmt->execute([$id]);
$sess = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sess || $sess['id_user'] != $userId) {
    echo json_encode(['ok' => false, 'error' => 'Accès refusé']); exit;
}

$moisPaie = $sess['mois_paie']; // format YYYY-MM
$moisRef  = $moisPaie . '-01';  // format YYYY-MM-01 pour la table salaires

// ── Calculer le total KM de TOUTES les sessions de ce mois_paie ──────────────
// (sessions clôturées ou non — tout reste modifiable jusqu'au blocage admin)
$stmtKm = $pdo->prepare("
    SELECT COALESCE(SUM(l.km_aller), 0) + COALESCE(SUM(l.km_retour), 0) AS total_km
    FROM rh_ik_lignes l
    JOIN rh_ik_sessions s ON s.id = l.id_session
    WHERE s.id_user = ?
      AND s.mois_paie = ?
");
$stmtKm->execute([$userId, $moisPaie]);
$totalKm = round((float)$stmtKm->fetchColumn(), 1);

// Résoudre l'id_user dans la table salaires (id_legacy si existant)
$stmtLeg = $pdo->prepare("SELECT id_legacy FROM users WHERE id = ?");
$stmtLeg->execute([$userId]);
$legacyRow = $stmtLeg->fetch(PDO::FETCH_ASSOC);
$salUserId = (!empty($legacyRow['id_legacy'])) ? (int)$legacyRow['id_legacy'] : $userId;

// Mettre à jour ik_nb_km si la fiche salaire existe déjà
$stmtSal = $pdo->prepare("
    SELECT id FROM salaires
    WHERE id_user = ? AND mois_reference = ? AND (salaire_modele IS NULL OR salaire_modele = 0)
    LIMIT 1
");
$stmtSal->execute([$salUserId, $moisRef]);
$salaire = $stmtSal->fetch(PDO::FETCH_ASSOC);

if ($salaire) {
    $pdo->prepare("UPDATE salaires SET ik_nb_km = ? WHERE id = ?")
        ->execute([$totalKm, $salaire['id']]);
    $msg = "Fiche salaire mise à jour : {$totalKm} km";
} else {
    $msg = "Fiche salaire inexistante — les KM ({$totalKm} km) seront intégrés à sa création";
}

echo json_encode(['ok' => true, 'total_km' => $totalKm, 'message' => $msg]);
