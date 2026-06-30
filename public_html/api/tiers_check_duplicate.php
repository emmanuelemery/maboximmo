<?php
declare(strict_types=1);

/**
 * POST /api/tiers_check_duplicate.php
 * ───────────────────────────────────────────────────────────────────────
 *
 * Wrapper mince autour de em_match_tiers() — moteur central entity_matcher.php.
 *
 * Cherche les tiers (propriétaires, locataires, copro, agents…) qui pourraient
 * être doublons d'un candidat. NE CRÉE RIEN — propose seulement.
 *
 * Remplace à terme :
 *   - api/bailleur_check_duplicate.php (Sprint 2 migrera les appels)
 *   - logique inline dans api/transaction_bien_create_from_ia.php
 *   - logique inline dans admin/admin_tiers_merge.php
 *
 * Paramètres POST :
 *   - csrf_token
 *   - nom, prenom, raison_sociale (ou societe), email, telephone, siret (ou siren)
 *   - role_code (optionnel) : 'proprietaire' | 'locataire' | 'copropriétaire' | ...
 *   - exclude_id (optionnel)
 *
 * Réponse JSON (shape standardisé du moteur) :
 *   {
 *     ok: true,
 *     found: bool,
 *     best: { id, nom_affichage, ..., score, match_type, reasons } | null,
 *     matches: [...],
 *     confidence: int (0-100),
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
        'nom'            => (string)($_POST['nom'] ?? ''),
        'prenom'         => (string)($_POST['prenom'] ?? ''),
        'raison_sociale' => (string)($_POST['raison_sociale'] ?? $_POST['societe'] ?? ''),
        'email'          => (string)($_POST['email'] ?? ''),
        'telephone'      => (string)($_POST['telephone'] ?? ''),
        'siret'          => (string)($_POST['siret'] ?? ''),
        'siren'          => (string)($_POST['siren'] ?? ''),
        'type_tiers'     => (string)($_POST['type_tiers'] ?? ''),
    ];

    $roleCode  = trim((string)($_POST['role_code'] ?? ''));
    if ($roleCode === '') $roleCode = null;

    $excludeId = isset($_POST['exclude_id']) && ctype_digit((string)$_POST['exclude_id'])
                 ? (int)$_POST['exclude_id'] : null;

    $scopeSoc  = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

    $result = em_match_tiers($pdo, $data, $roleCode, $excludeId, $scopeSoc);

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
