<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$roleId      = current_role_id();
$userId      = current_user_id();
$agenceScope = can_manage_salaires_agence();

$id = (int)($_POST['id'] ?? 0);
$id_user = (int)($_POST['id_user'] ?? 0);
$date_debut = trim($_POST['date_debut'] ?? '');
$date_fin = trim($_POST['date_fin'] ?? '');
$motif = trim($_POST['motif'] ?? '');
$demi_journee_debut = trim($_POST['demi_journee_debut'] ?? 'non');
$demi_journee_fin = trim($_POST['demi_journee_fin'] ?? 'non');
$commentaire = trim($_POST['commentaire'] ?? '');
$commentaire_admin = trim($_POST['commentaire_admin'] ?? '');
$statut = trim($_POST['statut'] ?? '');

if (!$id || !$id_user || !$date_debut || !$date_fin || !$motif) {
    http_response_code(400);
    exit('Données manquantes');
}

try {
    // Check leave exists
    $stmt = $pdo->prepare("SELECT id_user FROM conges WHERE id = ?");
    $stmt->execute([$id]);
    $leave = $stmt->fetch();

    if (!$leave) {
        http_response_code(404);
        exit('Congé non trouvé');
    }

    // Check authorization
    if ($roleId !== 1 && $leave['id_user'] != $userId) {
        if ($agenceScope > 0) {
            // Vérifier que le congé appartient à un utilisateur de l'agence
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
            $chk->execute([$leave['id_user'], $agenceScope]);
            if (!$chk->fetch()) {
                http_response_code(403);
                exit('Accès refusé');
            }
        } else {
            http_response_code(403);
            exit('Accès refusé');
        }
    }

    // Update
    $sql = "UPDATE conges SET id_user = ?, date_debut = ?, date_fin = ?, motif = ?, demi_journee_debut = ?, demi_journee_fin = ?, commentaire = ?";
    $params = [$id_user, $date_debut, $date_fin, $motif, $demi_journee_debut, $demi_journee_fin, $commentaire];

    if ($roleId === 1 || $agenceScope > 0) {
        if (!empty($statut)) {
            $sql .= ", statut = ?";
            $params[] = $statut;
        }
    }
    // commentaire_admin strictement réservé au role 1
    if ($roleId === 1) {
        $sql .= ", commentaire_admin = ?";
        $params[] = !empty($commentaire_admin) ? $commentaire_admin : null;
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo "success";
} catch (Exception $e) {
    error_log("Update leave error: " . $e->getMessage());
    http_response_code(500);
    exit('Erreur: ' . $e->getMessage());
}
?>
