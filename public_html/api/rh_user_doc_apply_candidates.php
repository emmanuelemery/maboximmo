<?php
/**
 * POST /api/rh_user_doc_apply_candidates.php
 *
 * Applique (écrase) certains champs "candidates" sur le profil utilisateur
 * après décision explicite de l'utilisateur. Utilisé par la modale de
 * résolution de conflits qui apparaît après un upload quand des champs
 * extraits par l'IA entrent en conflit avec des valeurs déjà saisies.
 *
 * Logique de sécurité :
 *   - require_login()
 *   - l'user ne peut modifier QUE son propre profil (sauf admin/manager)
 *   - les champs autorisés sont STRICTEMENT limités au mapping
 *     `rhDxFieldMapping()[$docType]` (impossible de modifier n'importe quoi)
 *
 * Body (application/json) :
 *   {
 *     "user_id":  64,             // id cible (vérifié scope)
 *     "doc_type": "permis",       // type de doc (pour valider le mapping)
 *     "fields":   {               // champs extractor_key => new_value
 *       "nom":    "Emery-Duvareille",
 *       "prenom": "Pierre-Emmanuel Edouard Rene"
 *     }
 *   }
 *
 * Réponse :
 *   {
 *     "ok":      true,
 *     "applied": { "nom": "...", "prenom": "..." },
 *     "skipped": { "champ_x": "not_in_mapping" },
 *     "error":   null
 *   }
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/rh_document_extractor.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']); exit;
    }

    // CSRF : le JS envoie le token via header X-CSRF-Token
    // On vérifie via verify_csrf_any qui accepte POST ou header
    verify_csrf_any();

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) throw new RuntimeException('PDO non disponible');

    // Lecture body JSON (le JS envoie du JSON propre pour éviter les
    // ambiguïtés avec les valeurs contenant des caractères spéciaux)
    $rawBody = file_get_contents('php://input') ?: '';
    $body    = json_decode($rawBody, true);
    if (!is_array($body)) {
        throw new RuntimeException('Corps de requête invalide (JSON attendu)');
    }

    $currentUserId = current_user_id();
    $currentRoleId = current_role_id();
    $targetUserId  = isset($body['user_id']) ? (int)$body['user_id'] : $currentUserId;
    $docType       = (string)($body['doc_type'] ?? '');
    $fields        = $body['fields'] ?? [];

    if (!is_array($fields) || empty($fields)) {
        throw new RuntimeException('Aucun champ à appliquer');
    }
    if ($docType === '') {
        throw new RuntimeException('doc_type manquant');
    }

    // Contrôle d'accès centralisé via SecurityGuard
    SecurityGuard::requireAccessToUser($targetUserId, $pdo);

    // Récupère le mapping autorisé pour ce type de doc
    $mapping = rhDxFieldMapping()[$docType] ?? [];
    if (empty($mapping)) {
        throw new RuntimeException("Pas de mapping pour le type '{$docType}'");
    }

    // Construit la liste des colonnes autorisées à écrire, en filtrant
    // STRICTEMENT les champs envoyés par le client (sécurité : aucune
    // colonne hors mapping ne peut être modifiée, même si envoyée).
    $sets    = [];
    $params  = [];
    $applied = [];
    $skipped = [];

    foreach ($fields as $extractorKey => $newValue) {
        if (!array_key_exists($extractorKey, $mapping)) {
            $skipped[$extractorKey] = 'not_in_mapping';
            continue;
        }
        if ($newValue === null || $newValue === '') {
            $skipped[$extractorKey] = 'empty_value';
            continue;
        }

        $usersCol = $mapping[$extractorKey];
        $sets[]   = "`{$usersCol}` = ?";
        $params[] = is_bool($newValue) ? ($newValue ? 1 : 0) : (string)$newValue;
        $applied[$usersCol] = $newValue;
    }

    if (empty($sets)) {
        echo json_encode([
            'ok'      => true,
            'applied' => [],
            'skipped' => $skipped,
            'error'   => null,
        ], JSON_UNESCAPED_UNICODE); exit;
    }

    $sql = "UPDATE users SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = ?";
    $params[] = $targetUserId;

    try {
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        error_log('[rh_user_doc_apply_candidates] UPDATE failed: ' . $e->getMessage());
        throw new RuntimeException('Erreur base de données : ' . $e->getMessage());
    }

    echo json_encode([
        'ok'      => true,
        'applied' => $applied,
        'skipped' => $skipped,
        'error'   => null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
