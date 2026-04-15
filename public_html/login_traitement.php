<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$email    = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    $_SESSION['login_error'] = 'Merci de remplir tous les champs.';
    $_SESSION['login_old_email'] = $email;
    redirect('/login.php');
}

$sql = "SELECT
            id,
            id_role,
            id_societe,
            id_agence,
            nom,
            prenom,
            username,
            email,
            mot_de_passe,
            actif,
            super_admin
        FROM users
        WHERE email = :email
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':email' => $email
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['login_error'] = 'Identifiants invalides.';
    $_SESSION['login_old_email'] = $email;
    redirect('/login.php');
}

if ((int)($user['actif'] ?? 0) !== 1) {
    $_SESSION['login_error'] = 'Votre compte est inactif.';
    $_SESSION['login_old_email'] = $email;
    redirect('/login.php');
}

$hash = (string)($user['mot_de_passe'] ?? '');

if ($hash === '' || !password_verify($password, $hash)) {
    $_SESSION['login_error'] = 'Identifiants invalides.';
    $_SESSION['login_old_email'] = $email;
    redirect('/login.php');
}

session_regenerate_id(true);

$_SESSION['user_id']        = (int)$user['id'];
$_SESSION['user_nom']       = (string)($user['nom'] ?? '');
$_SESSION['user_prenom']    = (string)($user['prenom'] ?? '');
$_SESSION['user_username']  = (string)($user['username'] ?? '');
$_SESSION['user_email']     = (string)($user['email'] ?? '');
$_SESSION['user_role']      = (int)($user['id_role'] ?? 0);
$_SESSION['id_role']        = (int)($user['id_role'] ?? 0);
$_SESSION['id_societe']     = !empty($user['id_societe']) ? (int)$user['id_societe'] : null;
$_SESSION['id_agence']      = !empty($user['id_agence']) ? (int)$user['id_agence'] : null;
$_SESSION['super_admin']    = (int)($user['super_admin'] ?? 0) === 1;

$update = $pdo->prepare("
    UPDATE users
    SET last_login_at = NOW(),
        last_login_ip = :ip
    WHERE id = :id
");
$update->execute([
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ':id' => (int)$user['id']
]);

$redirect = '/dashboard.php';
$target = trim((string)($_POST['target'] ?? ''));
if ($target !== '') {
    $parsed = parse_url($target);
    if ($parsed !== false && empty($parsed['scheme']) && empty($parsed['host'])) {
        if (!str_starts_with($target, '/')) {
            $target = '/' . ltrim($target, '/');
        }
        $redirect = $target;
    }
}

redirect($redirect);
