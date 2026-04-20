<?php
declare(strict_types=1);

/**
 * DEPRECATED (migration V2, 2026-04-20)
 *
 * Ancien endpoint d'upload de photos pour annonces_photos (schéma "copies
 * physiques" avec variantes WebP). La V2 remplace ce modèle par une liaison
 * N:N vers biens_photos. Le nouveau flux est :
 *
 *   1. Upload de la photo sur le bien  → /api/bien_intake_photo_upload.php
 *   2. Sélection pour l'annonce        → /api/annonce_photo_toggle.php (par photo)
 *                                        ou /api/annonce_photos_bulk.php (tout)
 *
 * L'ancien code est consultable dans l'historique Git (commits antérieurs
 * au 2026-04-20). Il dépendait de AnnoncePhotosManager et des colonnes
 * `url_photo`, `url_webp`, `variante`, `ordre_affichage`, `principale` qui
 * n'existent plus dans le nouveau schéma `annonces_photos`.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
exit(json_encode([
    'ok'    => false,
    'error' => 'Endpoint déprécié (migration V2 du 2026-04-20). Utiliser bien_intake_photo_upload.php + annonce_photo_toggle.php.',
]));
