<?php
// rh_dashboard.php — Aiguilleur RH : une seule URL d'entrée, redirige vers le
// dashboard adapté au rôle.
//   • Collaborateur (3)                         → rh_dashboard_user.php
//   • Manager (2)                               → rh_dashboard_manager.php
//   • Administrateur (1) / Super Admin (7) /
//     Administrateur Régie (8)                  → rh_dashboard_admin.php
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$__rhRole = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);

if (in_array($__rhRole, [1, 7, 8], true)) {
    require __DIR__ . '/rh_dashboard_admin.php';
    return;
}
if ($__rhRole === 2) {
    require __DIR__ . '/rh_dashboard_manager.php';
    return;
}

// Collaborateur (3) et tout autre rôle non-RH-pilote → parcours collaborateur.
require __DIR__ . '/rh_dashboard_user.php';
return;
