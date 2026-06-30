<?php
// rh_conges_historiq_prov.php — alias historique. Page renommée en rh_conges_historiq_user.php.
// Stub de redirection 301 pour ne pas casser les favoris/liens existants.
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: rh_conges_historiq_user.php' . $qs, true, 301);
exit;
