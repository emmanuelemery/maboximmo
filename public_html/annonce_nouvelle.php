<?php
// annonce_nouvelle.php — Redirection permanente vers bien_detail.php section annonce
//
// Historique : annonce_nouvelle.php était la page "créer une annonce guidée"
// qui utilisait l'ancien schéma `annonces_photos` (copies physiques avec
// variantes WebP gérées par AnnoncePhotosManager).
//
// Depuis la migration V2 du 2026-04-20 :
//   - `annonces_photos` est une liaison N:N vers `biens_photos`
//   - Le flux complet création bien + annonce est dans `bien_detail.php`
//     (sections documents / descriptif / dpe / annonce)
//   - Cette page n'est plus maintenue et plantait sur les INSERT/UPDATE
//
// Comportement : on redirige vers `bien_detail.php?edit=X&section=annonce`
// si un `id_bien` est fourni, sinon vers `bien_liste.php`.
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$idBien = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien']) ? (int)$_GET['id_bien'] : 0;

if ($idBien > 0) {
    header('Location: ' . app_url('/bien_detail.php?edit=' . $idBien . '&section=annonce'), true, 301);
} else {
    header('Location: ' . app_url('/bien_liste.php'), true, 301);
}
exit;
