<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_super_admin();
echo '<pre>';
echo 'super_admin = '; var_dump($_SESSION['super_admin'] ?? 'NON DÉFINI');
echo 'id_role     = '; var_dump($_SESSION['id_role'] ?? 'NON DÉFINI');
echo 'user_id     = '; var_dump($_SESSION['user_id'] ?? 'NON DÉFINI');
echo 'is_super_admin() = '; var_dump(is_super_admin());
echo '</pre>';
