<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur base de données']);
    exit;
}

$roleId      = current_role_id();
$userId      = current_user_id();
$agenceScope = can_manage_salaires_agence();

$data = json_decode(file_get_contents('php://input'), true);

$id_user = (int)($data['id_user'] ?? 0);

// Check authorization
if ($roleId !== 1 && $id_user !== $userId) {
    // Gestionnaire agence peut créer pour les utilisateurs de son agence
    if ($agenceScope > 0) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ? AND actif = 1");
        $chk->execute([$id_user, $agenceScope]);
        if (!$chk->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Utilisateur hors de votre agence']);
            exit;
        }
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cannot create leave for another user']);
        exit;
    }
}
$date_debut = trim($data['date_debut'] ?? '');
$date_fin = trim($data['date_fin'] ?? '');
$motif = trim($data['motif'] ?? '');
$commentaire = trim($data['commentaire'] ?? '');
$commentaire_admin = trim($data['commentaire_admin'] ?? '');
$demi_journee_debut = trim($data['demi_journee_debut'] ?? 'non');
$demi_journee_fin = trim($data['demi_journee_fin'] ?? 'non');

if (!$id_user || !$date_debut || !$date_fin || !$motif) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

// Validate demi_journee values
$validDemiJournee = ['non', 'matin', 'apres-midi'];
if (!in_array($demi_journee_debut, $validDemiJournee) || !in_array($demi_journee_fin, $validDemiJournee)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valeur demi-journée invalide']);
    exit;
}

// Validate dates
if (strtotime($date_debut) === false || strtotime($date_fin) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dates invalides']);
    exit;
}

if (strtotime($date_debut) > strtotime($date_fin)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Date début doit être avant date fin']);
    exit;
}

// Whitelist motifs
$validMotifs = [
    'conges_payes', 'rtt',
    'maladie_justifiee_non_deduite', 'maladie_justifiee_deduite', 'maladie_non_justifiee_deduite',
    'absence_justifiee_non_deduite', 'absence_justifiee_deduite_heures', 'absence_injustifiee_deduite',
    'autre_legal_non_deduit', 'autre_legal_deduit',
];
if (!in_array($motif, $validMotifs)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Type de congé invalide']);
    exit;
}

try {
    // Check user exists
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND actif = 1");
    $userStmt->execute([$id_user]);
    if (!$userStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
        exit;
    }

    // Insert leave — admin et gestionnaire agence créent directement en validé
    // commentaire_admin réservé strictement au role 1
    if ($roleId === 1 || $agenceScope > 0) {
        if ($roleId === 1) {
            $stmt = $pdo->prepare("
                INSERT INTO conges (id_user, date_demande, date_debut, date_fin, demi_journee_debut, demi_journee_fin, motif, commentaire, commentaire_admin, statut)
                VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, 'validé')
            ");
            $stmt->execute([$id_user, $date_debut, $date_fin, $demi_journee_debut, $demi_journee_fin, $motif, $commentaire, !empty($commentaire_admin) ? $commentaire_admin : null]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO conges (id_user, date_demande, date_debut, date_fin, demi_journee_debut, demi_journee_fin, motif, commentaire, statut)
                VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 'validé')
            ");
            $stmt->execute([$id_user, $date_debut, $date_fin, $demi_journee_debut, $demi_journee_fin, $motif, $commentaire]);
        }
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO conges (id_user, date_demande, date_debut, date_fin, demi_journee_debut, demi_journee_fin, motif, commentaire, statut)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 'validé')
        ");
        $stmt->execute([$id_user, $date_debut, $date_fin, $demi_journee_debut, $demi_journee_fin, $motif, $commentaire]);
    }

    echo json_encode(['success' => true, 'message' => 'Congé créé avec succès']);
} catch (Exception $e) {
    error_log("Create leave error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
    exit;
}
?>
