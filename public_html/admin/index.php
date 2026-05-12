<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$roleId = (int)current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs.');
}

header('Location: ' . app_url('/admin_dashboard.php'));
exit;

