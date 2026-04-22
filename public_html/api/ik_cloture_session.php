<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ik_bareme.php';
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

// ── Calcul ik_montant + total_ik depuis la carte grise (vehicule_puissance_fiscale) ──
$stmtV = $pdo->prepare("SELECT vehicule_puissance_fiscale FROM users WHERE id = ?");
$stmtV->execute([$userId]);
$cv = (int)($stmtV->fetchColumn() ?: 0);

$ikMontant = null;   // €/km
$totalIk   = null;   // total €
$ikNote    = '';
if ($cv > 0 && $totalKm > 0) {
    $year = (int)substr($moisPaie, 0, 4);
    if ($year < 2020) $year = ik_bareme_default_year();
    $calc = ik_compute_amount($pdo, $cv, (int)round($totalKm), $year);
    if ($calc) {
        $ikMontant = round((float)$calc['per_km'], 4);
        $totalIk   = round((float)$calc['total'], 2);
    } else {
        $ikNote = " (barème introuvable pour CV={$cv})";
    }
} elseif ($cv <= 0) {
    $ikNote = " (carte grise non renseignée — saisis-la dans ton profil RH pour le calcul auto)";
}

// Cherche la fiche salaire existante
$stmtSal = $pdo->prepare("
    SELECT id FROM salaires
    WHERE id_user = ? AND mois_reference = ? AND (salaire_modele IS NULL OR salaire_modele = 0)
    LIMIT 1
");
$stmtSal->execute([$salUserId, $moisRef]);
$salaire = $stmtSal->fetch(PDO::FETCH_ASSOC);

if ($salaire) {
    // UPDATE des 3 colonnes (ik_montant et total_ik = NULL si CV manquant → reste vide)
    $pdo->prepare("UPDATE salaires SET ik_nb_km = ?, ik_montant = ?, total_ik = ? WHERE id = ?")
        ->execute([$totalKm, $ikMontant, $totalIk, $salaire['id']]);
    $salaireId = (int)$salaire['id'];
    $msg = "Fiche salaire mise à jour : {$totalKm} km" . ($totalIk !== null ? " · {$totalIk} €" : '') . $ikNote;
} else {
    // Création auto d'une fiche minimaliste avec les 3 IK calculés
    try {
        $insSal = $pdo->prepare("
            INSERT INTO salaires (id_user, mois_reference, salaire_modele, salaire_base_commentaire, ik_nb_km, ik_montant, total_ik, termine_user)
            VALUES (?, ?, 0, '', ?, ?, ?, 0)
        ");
        $insSal->execute([$salUserId, $moisRef, $totalKm, $ikMontant, $totalIk]);
        $salaireId = (int)$pdo->lastInsertId();
        $msg = "Fiche salaire créée auto : {$totalKm} km" . ($totalIk !== null ? " · {$totalIk} €" : '') . $ikNote;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Création fiche salaire impossible : ' . $e->getMessage()]);
        exit;
    }
}

// URL de redirection vers le détail du salaire
$redirectUrl = 'rh_salaire_detail.php?id_user=' . $salUserId . '&mois_ref=' . urlencode($moisRef);

echo json_encode([
    'ok'           => true,
    'total_km'     => $totalKm,
    'ik_montant'   => $ikMontant,
    'total_ik'     => $totalIk,
    'cv'           => $cv,
    'salaire_id'   => $salaireId,
    'message'      => $msg,
    'redirect_url' => $redirectUrl,
]);
