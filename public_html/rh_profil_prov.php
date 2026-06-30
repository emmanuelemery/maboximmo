<?php
// rh_profil_prov.php — alias historique. Page renommée en rh_profil_user.php.
// Stub de redirection 301 pour ne pas casser les favoris/liens existants.
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: rh_profil_user.php' . $qs, true, 301);
exit;
