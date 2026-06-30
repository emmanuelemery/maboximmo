<?php
declare(strict_types=1);

/**
 * POST /api/immeuble_check_duplicate.php
 * ───────────────────────────────────────────────────────────────────────
 *
 * Wrapper mince autour de em_match_immeuble() — moteur central entity_matcher.php.
 *
 * Cherche les immeubles existants qui pourraient être doublons d'un candidat,
 * AVANT toute création. Ne crée rien — propose seulement.
 *
 * Cascade de matching (cf. inc/entity_matcher.php) :
 *   1. Google Place ID exact          → 100
 *   2. Latitude/Longitude < 30m       → 95
 *   3. Adresse normalisée + CP exact  → 85
 *   4. Tokens adresse + CP + ville    → 50-75 (fuzzy)
 *
 * Paramètres POST :
 *   - csrf_token (verify_csrf_any('ajouter_bien'))
 *   - adresse_1, code_postal, ville (au moins adresse_1 + cp)
 *   - latitude, longitude (optionnel — déclenche stratégie 2)
 *   - google_place_id (optionnel — déclenche stratégie 1)
 *   - reference_immeuble (optionnel)
 *   - exclude_id (optionnel)
 *
 * Réponse JSON :
 *   {
 *     ok: true,
 *     found: bool,
 *     best: { id, nom_immeuble, adresse_1, ..., score, match_type, reasons } | null,
 *     matches: [...],
 *     confidence: int (0-100),
 *     match_type: 'google_place_id' | 'geoloc' | 'normalized_address' | 'fuzzy_tokens',
 *     needs_user_validation: bool,
 *     can_create: bool,
 *     thresholds: { auto, validate, create_ok }
 *   }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/entity_matcher.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
    }
    if (function_exists('verify_csrf_any')) {
        verify_csrf_any('ajouter_bien');
    }

    $pdo = $GLOBALS['pdo'] ?? db();

    $data = [
        'adresse_1'          => (string)($_POST['adresse_1'] ?? ''),
        'code_postal'        => (string)($_POST['code_postal'] ?? ''),
        'ville'              => (string)($_POST['ville'] ?? ''),
        'latitude'           => isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null,
        'longitude'          => isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null,
        'google_place_id'    => (string)($_POST['google_place_id'] ?? ''),
        'reference_immeuble' => (string)($_POST['reference_immeuble'] ?? ''),
    ];

    $excludeId = isset($_POST['exclude_id']) && ctype_digit((string)$_POST['exclude_id'])
                 ? (int)$_POST['exclude_id'] : null;

    $result = em_match_immeuble($pdo, $data, $excludeId);

    $result['ok']         = true;
    $result['thresholds'] = [
        'auto'      => ENTITY_MATCHER_AUTO,
        'validate'  => ENTITY_MATCHER_VALIDATE,
        'create_ok' => ENTITY_MATCHER_CREATE_OK,
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
