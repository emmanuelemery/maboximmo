<?php
declare(strict_types=1);

/**
 * DEPRECATED (migration V2, 2026-04-20)
 *
 * Ancien endpoint de suppression de photos annonces_photos (schéma "copies
 * physiques"). La V2 utilise une liaison N:N : retirer une photo d'une
 * annonce se fait par toggle via /api/annonce_photo_toggle.php (qui retire
 * la ligne de sélection sans toucher aux fichiers du bien).
 *
 * L'ancien code est consultable dans l'historique Git.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
exit(json_encode([
    'ok'    => false,
    'error' => 'Endpoint déprécié (migration V2 du 2026-04-20). Utiliser annonce_photo_toggle.php pour retirer une photo de l\'annonce.',
]));
