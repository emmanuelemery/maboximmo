<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/config/registres.php';

require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

verify_csrf('sso_registres');

$societeId = current_societe_id();
if ($societeId === null) {
    http_response_code(403);
    exit('Aucune société associée à ce compte.');
}

try {
    $stmtAccess = $pdo->prepare("SELECT statut FROM registres_acces WHERE id_societe = :id LIMIT 1");
    $stmtAccess->execute([':id' => $societeId]);
    $statutAccess = (string)($stmtAccess->fetchColumn() ?: '');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Module REGISTRE non initialisé. Appliquez le patch SQL.');
}

if ($statutAccess !== 'active') {
    http_response_code(403);
    exit('Accès REGISTRE non activé. Faites la demande depuis votre espace agence.');
}

// Cible optionnelle (chemin relatif côté REGISTRES)
$target = trim((string)post('target', '/dashboard.php'));
if ($target === '') {
    $target = '/dashboard.php';
}

// Interdire URL externes
$parsed = parse_url($target);
if ($parsed !== false) {
    if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
        $target = '/dashboard.php';
    }
}
if (!str_starts_with($target, '/')) {
    $target = '/' . ltrim($target, '/');
}

// Utilisateur portail
$email    = (string)($_SESSION['user_email'] ?? '');
$username = (string)($_SESSION['user_username'] ?? '');

if ($email === '' && $username === '') {
    http_response_code(403);
    exit('Utilisateur invalide.');
}

// Recherche utilisateur côté REGISTRES (priorité email)
$pdoReg = registres_db();

$sql = "SELECT id FROM users WHERE actif = 1 AND (email = :email OR username = :username) LIMIT 1";
$stmt = $pdoReg->prepare($sql);
$stmt->execute([
    ':email' => $email,
    ':username' => $username,
]);
$regUserId = (int)($stmt->fetchColumn() ?: 0);

if ($regUserId <= 0) {
    http_response_code(403);
    exit('Aucun compte REGISTRES correspondant. Demandez à un admin de créer le compte.');
}

// Génération token SSO
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

$insert = $pdoReg->prepare("
    INSERT INTO sso_tokens (token_hash, user_id, target_url, expires_at, ip_address, user_agent)
    VALUES (:token_hash, :user_id, :target_url, :expires_at, :ip, :ua)
");
$insert->execute([
    ':token_hash' => $tokenHash,
    ':user_id'    => $regUserId,
    ':target_url' => $target,
    ':expires_at' => $expiresAt,
    ':ip'         => client_ip(),
    ':ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
]);

$baseUrl = getenv('REGISTRES_BASE_URL')
    ?: ($_ENV['REGISTRES_BASE_URL'] ?? '')
    ?: ($_SERVER['REGISTRES_BASE_URL'] ?? '')
    ?: REGISTRES_BASE_URL;

$baseUrl = rtrim($baseUrl, '/');
header('Location: ' . $baseUrl . '/sso_login.php?token=' . urlencode($token));
exit;
