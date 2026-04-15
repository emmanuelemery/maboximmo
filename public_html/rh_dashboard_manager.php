<?php
declare(strict_types=1);

/*
 * rh_dashboard_manager.php — stub de redirection
 * ------------------------------------------------
 * Cette page était une variante "manager" du dashboard RH. Elle a été
 * fusionnée dans `rh_dashboard.php` qui s'adapte désormais au rôle
 * de l'utilisateur (admin/manager/user) via des guards conditionnels.
 */
header('Location: rh_dashboard.php', true, 301);
exit;
