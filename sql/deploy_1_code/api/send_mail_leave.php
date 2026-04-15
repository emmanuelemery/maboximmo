<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$leaveId = (int)($_GET['id'] ?? 0);
if (!$leaveId) {
    http_response_code(400);
    exit('ID congé manquant');
}

try {
    // Get leave details
    $stmt = $pdo->prepare("
        SELECT c.*, u.email, u.prenom, u.nom
        FROM conges c
        JOIN users u ON c.id_user = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$leaveId]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leave) {
        http_response_code(404);
        exit('Congé non trouvé');
    }

    if (empty($leave['email'])) {
        http_response_code(400);
        exit('Email utilisateur manquant');
    }

    // Build email content
    $subject = "Récapitulatif de votre demande de congé";
    $body = "Bonjour " . htmlspecialchars($leave['prenom'] . ' ' . $leave['nom']) . ",\n\n";
    $body .= "Voici le récapitulatif de votre demande de congé :\n\n";
    $body .= "Période : " . $leave['date_debut'] . " au " . $leave['date_fin'] . "\n";
    $body .= "Type : " . $leave['motif'] . "\n";
    $body .= "Statut : " . $leave['statut'] . "\n";
    $body .= "Date de demande : " . $leave['date_demande'] . "\n";

    if (!empty($leave['commentaire'])) {
        $body .= "\nCommentaire : " . $leave['commentaire'] . "\n";
    }

    $body .= "\nCordialement,\nL'équipe RH";

    // Send email using PHPMailer if available
    require_once __DIR__ . '/../inc/send_email.php';

    $result = sendEmail(
        $leave['email'],
        $subject,
        $body,
        $leave['prenom'] . ' ' . $leave['nom']
    );

    if ($result) {
        http_response_code(200);
        echo "✅ Email envoyé avec succès à " . htmlspecialchars($leave['email']);
    } else {
        http_response_code(500);
        echo "❌ Erreur lors de l'envoi de l'email";
    }

} catch (Exception $e) {
    error_log("Send mail leave error: " . $e->getMessage());
    http_response_code(500);
    echo "❌ Erreur serveur: " . $e->getMessage();
    exit;
}
?>
