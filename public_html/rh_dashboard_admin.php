<?php
declare(strict_types=1);

/*
 * rh_dashboard_admin.php — stub de redirection
 * ----------------------------------------------
 * Cette page était une variante "admin" du dashboard RH. Elle a été
 * fusionnée dans `rh_dashboard.php` qui s'adapte désormais au rôle
 * de l'utilisateur (admin/manager/user) via des guards conditionnels.
 *
 * Tout accès est redirigé vers le dashboard unifié pour ne pas
 * multiplier les pages. Conserver ce fichier évite de casser les
 * bookmarks et les liens existants.
 */
header('Location: rh_dashboard.php', true, 301);
exit;
