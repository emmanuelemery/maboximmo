<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

header('Content-Type: application/json; charset=utf-8');

// --- Lire le body JSON ---
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Corps JSON invalide.']);
    exit;
}

// --- Valider les champs requis ---
$entretienId = isset($data['entretien_id']) ? (int)$data['entretien_id'] : 0;
$action      = isset($data['action'])       ? trim((string)$data['action']) : '';
$texte       = isset($data['texte'])        ? trim((string)$data['texte'])  : '';
$critereId   = isset($data['critere_id'])   ? (int)$data['critere_id']   : 0;
$rubriqueId  = isset($data['rubrique_id'])  ? (int)$data['rubrique_id']  : 0;

if ($entretienId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'entretien_id manquant ou invalide.']);
    exit;
}
if (!in_array($action, ['valider', 'desaccord', 'remarque'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Action invalide. Valeurs acceptées : valider, desaccord, remarque.']);
    exit;
}
if (in_array($action, ['desaccord', 'remarque']) && $texte === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Le champ texte est requis pour cette action.']);
    exit;
}

// --- Charger l'entretien ---
try {
    $stmt = $pdo->prepare("SELECT id, collaborateur_id, statut, verrouille FROM rh_entretiens WHERE id = ?");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('rh_entretien_collab_action: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur base de données.']);
    exit;
}

if (!$entretien) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Entretien introuvable.']);
    exit;
}

// --- Vérifier que l'utilisateur est bien le collaborateur ---
if ((int)$entretien['collaborateur_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé. Vous n\'êtes pas le collaborateur de cet entretien.']);
    exit;
}

// --- Vérifier que l'entretien n'est pas archivé ---
if ($entretien['statut'] === 'archive') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Entretien archivé, modification impossible.']);
    exit;
}

// --- Vérifier qu'il n'est pas totalement verrouillé (signé ok pour remarques) ---
// On autorise les remarques même sur un entretien signé (politique RH permissive)
// Mais pas sur archivé (déjà géré ci-dessus)

// --- Chercher la réponse concernée ---
$whereReponse = "WHERE entretien_id = ?";
$paramsR      = [$entretienId];

if ($critereId > 0) {
    $whereReponse .= " AND critere_id = ?";
    $paramsR[]    = $critereId;
} elseif ($rubriqueId > 0) {
    $whereReponse .= " AND rubrique_id = ? AND critere_id IS NULL";
    $paramsR[]    = $rubriqueId;
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'critere_id ou rubrique_id requis.']);
    exit;
}

try {
    $stmtR = $pdo->prepare("SELECT id FROM rh_entretien_reponses $whereReponse ORDER BY id DESC LIMIT 1");
    $stmtR->execute($paramsR);
    $reponse = $stmtR->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('rh_entretien_collab_action reponse: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur base de données.']);
    exit;
}

if (!$reponse) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Réponse introuvable pour ce critère/rubrique.']);
    exit;
}

$reponseId = (int)$reponse['id'];

// --- Appliquer l'action ---
try {
    $pdo->beginTransaction();

    switch ($action) {
        case 'valider':
            $pdo->prepare("UPDATE rh_entretien_reponses SET validee_collaborateur = 1, updated_at = NOW() WHERE id = ?")
                ->execute([$reponseId]);
            break;

        case 'desaccord':
            $pdo->prepare("UPDATE rh_entretien_reponses SET desaccord_collaborateur = 1, remarque_collaborateur = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$texte, $reponseId]);
            break;

        case 'remarque':
            $pdo->prepare("UPDATE rh_entretien_reponses SET remarque_collaborateur = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$texte, $reponseId]);
            break;
    }

    // --- Log ---
    try {
        $logMessage = match($action) {
            'valider'   => 'Collaborateur a validé le critère/rubrique.',
            'desaccord' => 'Collaborateur a exprimé un désaccord : ' . mb_substr($texte, 0, 200),
            'remarque'  => 'Collaborateur a ajouté une remarque : ' . mb_substr($texte, 0, 200),
            default     => $action
        };
        $pdo->prepare("INSERT INTO rh_entretien_logs (entretien_id, user_id, source, action, message, created_at) VALUES (?, ?, 'collaborateur', ?, ?, NOW())")
            ->execute([$entretienId, $userId, $action, $logMessage]);
    } catch (PDOException $eLog) {
        error_log('rh_entretien_collab_action log: ' . $eLog->getMessage());
        // Le log échoue silencieusement
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('rh_entretien_collab_action update: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'enregistrement.']);
    exit;
}

$savedAt = (new DateTime())->format('H:i');
echo json_encode([
    'success'    => true,
    'action'     => $action,
    'reponse_id' => $reponseId,
    'saved_at'   => $savedAt,
]);

