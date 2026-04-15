<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/mailer.php';

require_login();

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur DB']);
    exit;
}

// Get JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// Verify CSRF token
$sessionToken = $_SESSION['_csrf_mail_team'] ?? '';
$postedToken = (string)($data['csrf_token'] ?? '');

if (!$sessionToken || !$postedToken || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Requête invalide (CSRF)']);
    exit;
}

$subject = (string)($data['subject'] ?? '');
$body = (string)($data['body'] ?? '');
$recipientType = (string)($data['recipient_type'] ?? '');
$recipientId = (string)($data['recipient_id'] ?? '');

if (empty($subject) || empty($body) || empty($recipientType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Champs requis manquants']);
    exit;
}

// Get recipients based on type
$recipients = [];

try {
    if ($recipientType === 'all') {
        $stmt = $pdo->query("
            SELECT id, email, prenom, nom FROM users
            WHERE actif = 1 AND email IS NOT NULL AND email != ''
            ORDER BY nom, prenom
        ");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($recipientType === 'societe' && !empty($recipientId)) {
        $stmt = $pdo->prepare("
            SELECT id, email, prenom, nom FROM users
            WHERE actif = 1 AND id_societe = ? AND email IS NOT NULL AND email != ''
            ORDER BY nom, prenom
        ");
        $stmt->execute([(int)$recipientId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($recipientType === 'agence' && !empty($recipientId)) {
        $stmt = $pdo->prepare("
            SELECT id, email, prenom, nom FROM users
            WHERE actif = 1 AND id_agence = ? AND email IS NOT NULL AND email != ''
            ORDER BY nom, prenom
        ");
        $stmt->execute([(int)$recipientId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($recipientType === 'service' && !empty($recipientId)) {
        $serviceRoles = $recipientId === 'gestion' ? [1, 2, 3] : [1, 2, 4];
        $placeholders = implode(',', array_fill(0, count($serviceRoles), '?'));
        $stmt = $pdo->prepare("
            SELECT id, email, prenom, nom FROM users
            WHERE actif = 1 AND id_role IN ($placeholders) AND email IS NOT NULL AND email != ''
            ORDER BY nom, prenom
        ");
        $stmt->execute($serviceRoles);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($recipientType === 'users' && !empty($data['user_ids'])) {
        $userIds = array_filter(array_map('intval', explode(',', (string)$data['user_ids'])));
        if (empty($userIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Aucun utilisateur sélectionné']);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, email, prenom, nom FROM users
            WHERE actif = 1 AND id IN ($placeholders) AND email IS NOT NULL AND email != ''
            ORDER BY nom, prenom
        ");
        $stmt->execute($userIds);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des destinataires']);
    exit;
}

if (empty($recipients)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Aucun destinataire trouvé']);
    exit;
}

// Get current user email
$currentUserId = current_user_id();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
$senderEmail = $currentUser['email'] ?? '';

if (empty($senderEmail)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email de l\'expéditeur non trouvé']);
    exit;
}

// Resolve temp attachments
$attachmentPaths = [];
$attachmentIds = isset($data['attachment_ids']) && is_array($data['attachment_ids'])
    ? $data['attachment_ids']
    : [];

$sessionAttachments = $_SESSION['mail_attachments'] ?? [];
foreach ($attachmentIds as $aid) {
    $aid = (string)$aid;
    if (!preg_match('/^[0-9a-f]{16}$/', $aid)) continue;
    if (!isset($sessionAttachments[$aid])) continue;
    $path = $sessionAttachments[$aid]['path'];
    if (is_file($path) && is_readable($path)) {
        $attachmentPaths[$aid] = $path;
    }
}

// Send single email with all recipients in CC
$ccEmails = array_map(function($r) { return $r['email']; }, $recipients);
$ccString = implode(',', array_filter($ccEmails));

if (empty($ccString)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Aucun email valide dans les destinataires']);
    exit;
}

// Adresse de réponse fixe
const REPLY_TO_EMAIL = 'emmanuel.emery@regie-emery.com';

// Créer la table d'historique si elle n'existe pas
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sent_by INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        recipient_type VARCHAR(50),
        recipients_count INT DEFAULT 0,
        recipients_json TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (sent_by),
        INDEX (sent_at)
    )");
} catch (Exception $e) {}

try {
        $result = send_mail(
        $senderEmail,
        $subject,
        $body,
        array_values($attachmentPaths),
        false,
        $ccString,
        REPLY_TO_EMAIL,
        'contact@maboximmo.fr',
        'MaBoxImmo RH'
    );

    if ($result) {
        // Cleanup temp attachments
        foreach ($attachmentPaths as $aid => $path) {
            if (is_file($path)) @unlink($path);
            unset($_SESSION['mail_attachments'][$aid]);
        }

        // Enregistrer dans l'historique
        try {
            $recipientsJson = json_encode(array_map(
                fn($r) => ['prenom' => $r['prenom'], 'nom' => $r['nom'], 'email' => $r['email']],
                $recipients
            ));
            $stmt = $pdo->prepare("INSERT INTO mail_history (sent_by, subject, body, recipient_type, recipients_count, recipients_json) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$currentUserId, $subject, $body, $recipientType, count($recipients), $recipientsJson]);
        } catch (Exception $e) {
            error_log('Historique mail error: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'count' => count($recipients),
            'failed' => 0,
            'message' => 'Mail envoyé avec succès à ' . count($recipients) . ' destinataire(s)'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'envoi du mail']);
    }
} catch (Exception $e) {
    error_log('Mail error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur lors de l\'envoi du mail']);
}
