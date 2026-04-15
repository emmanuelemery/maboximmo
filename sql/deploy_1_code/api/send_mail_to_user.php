<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
verify_csrf_any();
require_once __DIR__ . '/../inc/mailer.php';

// Admin only
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['userId']) || !isset($data['message'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$userId = (int)$data['userId'];
$message = trim($data['message']);

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
    exit;
}

try {
    // Get user info
    $stmt = $pdo->prepare("SELECT email, prenom, nom FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['email'])) {
        echo json_encode(['success' => false, 'message' => 'User not found or no email']);
        exit;
    }

    $userFullName = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $subject = 'MaBoxImmo - Message de l\'administrateur';

    $htmlMessage = <<<HTML
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .header { background-color: #2d5f6b; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; line-height: 1.6; }
        .footer { background-color: #f5f5f5; padding: 10px; text-align: center; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MaBoxImmo</h1>
    </div>
    <div class="content">
        <p>Bonjour <strong>$userFullName</strong>,</p>
        <p>$message</p>
        <p><strong>Cordialement,</strong><br>L'équipe MaBoxImmo</p>
    </div>
    <div class="footer">
        <p>Email automatisé - Veuillez ne pas répondre à cet email</p>
    </div>
</body>
</html>
HTML;

    $success = send_mail($user['email'], $subject, $htmlMessage, [], true);

    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Email sent']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send email']);
    }
} catch (Exception $e) {
    error_log("Error sending email: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error sending email']);
    exit;
}
?>

