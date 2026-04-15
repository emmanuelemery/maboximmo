<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$userId = current_user_id();
$roleId = current_role_id();

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$entretienId = (int)($data['entretien_id'] ?? 0);
$rubriqueId  = (int)($data['rubrique_id']  ?? 0);
$critereId   = (int)($data['critere_id']   ?? 0);
$note        = isset($data['note']) && $data['note'] !== null ? max(1, min(5, (int)$data['note'])) : null;
$submit      = !empty($data['submit']);
// Scope explicite : 'collab' (par défaut, auto-évaluation) ou 'manager'
// (évaluation préalable par le manager, confidentielle jusqu'à l'entretien)
$scope       = ($data['scope'] ?? 'collab') === 'manager' ? 'manager' : 'collab';

if ($entretienId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'entretien_id manquant']); exit;
}

// ─── Charge l'entretien + vérifie le droit d'accès selon le scope ───
$stmt = $pdo->prepare("SELECT id, collaborateur_id, manager_id, statut FROM rh_entretiens WHERE id = ?");
$stmt->execute([$entretienId]);
$entretien = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entretien) {
    echo json_encode(['ok' => false, 'error' => 'Entretien introuvable']); exit;
}

$isCollabOwner  = ((int)$entretien['collaborateur_id'] === $userId);
$isManagerOwner = ((int)$entretien['manager_id'] === $userId) || $roleId === 1;

if ($scope === 'collab' && !$isCollabOwner) {
    echo json_encode(['ok' => false, 'error' => 'Accès refusé (scope collab réservé au collaborateur)']); exit;
}
if ($scope === 'manager' && !$isManagerOwner) {
    echo json_encode(['ok' => false, 'error' => 'Accès refusé (scope manager réservé au manager)']); exit;
}

// Clôture : ni le collab ni le manager ne peuvent modifier un entretien archivé/signé
if (in_array($entretien['statut'], ['archive', 'signe'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Entretien clôturé']); exit;
}
// Statut 'termine' : le collab ne peut plus modifier, mais le manager
// peut encore ajuster son évaluation préalable si la signature n'est pas faite.
if ($scope === 'collab' && $entretien['statut'] === 'termine') {
    echo json_encode(['ok' => false, 'error' => 'Entretien terminé']); exit;
}

// ─── Action : sauvegarde d'un commentaire rubrique ───
// Détecté via la présence du champ `rubrique_commentaire` dans le payload.
// Une valeur vide = supprime le commentaire (stocke NULL).
if (array_key_exists('rubrique_commentaire', $data)) {
    if ($rubriqueId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'rubrique_id requis']); exit;
    }
    $texte = is_string($data['rubrique_commentaire']) ? trim($data['rubrique_commentaire']) : '';
    $texte = ($texte === '') ? null : mb_substr($texte, 0, 5000); // hard cap 5k chars
    $col   = ($scope === 'manager') ? 'commentaire_manager' : 'commentaire_collaborateur';

    try {
        // Upsert sur (entretien_id, rubrique_id) — contrainte unique.
        $check = $pdo->prepare("
            SELECT id FROM rh_entretien_rubriques_comments
            WHERE entretien_id = ? AND rubrique_id = ?
            LIMIT 1
        ");
        $check->execute([$entretienId, $rubriqueId]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            $pdo->prepare("
                UPDATE rh_entretien_rubriques_comments
                   SET `$col` = :t
                 WHERE id = :id
            ")->execute([':t' => $texte, ':id' => $existingId]);
        } else {
            $pdo->prepare("
                INSERT INTO rh_entretien_rubriques_comments
                    (entretien_id, rubrique_id, `$col`)
                VALUES
                    (:eid, :rid, :t)
            ")->execute([
                ':eid' => $entretienId,
                ':rid' => $rubriqueId,
                ':t'   => $texte,
            ]);
        }
        echo json_encode(['ok' => true, 'scope' => $scope, 'type' => 'rubrique_comment']);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Erreur BDD : ' . $e->getMessage()]);
    }
    exit;
}

// ─── Action : soumission globale (collab uniquement) ───
if ($submit) {
    if ($scope !== 'collab' || !$isCollabOwner) {
        echo json_encode(['ok' => false, 'error' => 'Soumission réservée au collaborateur']); exit;
    }
    $pdo->prepare("UPDATE rh_entretiens SET auto_eval_submitted_at = NOW() WHERE id = ?")
        ->execute([$entretienId]);
    echo json_encode(['ok' => true, 'submitted' => true]); exit;
}

// ─── Sauvegarde d'une note pour un critère ───
if ($rubriqueId <= 0 || $critereId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'rubrique_id et critere_id requis']); exit;
}

// Colonne cible selon le scope
$col = ($scope === 'manager') ? 'note_manager' : 'note_collaborateur';

try {
    // On utilise INSERT ... ON DUPLICATE KEY UPDATE — la contrainte unique
    // (entretien_id, rubrique_id, critere_id) doit exister. Si elle n'existe
    // pas, le INSERT crée une nouvelle ligne à chaque save ; à défaut, on
    // fait une approche SELECT-then-UPDATE/INSERT plus tolérante.
    $check = $pdo->prepare("
        SELECT id FROM rh_entretien_reponses
        WHERE entretien_id = ? AND rubrique_id = ? AND critere_id = ?
        LIMIT 1
    ");
    $check->execute([$entretienId, $rubriqueId, $critereId]);
    $existingId = $check->fetchColumn();

    if ($existingId) {
        $pdo->prepare("
            UPDATE rh_entretien_reponses
               SET `$col` = :n, saved_at = NOW()
             WHERE id = :id
        ")->execute([':n' => $note, ':id' => $existingId]);
    } else {
        $pdo->prepare("
            INSERT INTO rh_entretien_reponses
                (entretien_id, rubrique_id, critere_id, `$col`, saved_at)
            VALUES
                (:eid, :rid, :cid, :n, NOW())
        ")->execute([
            ':eid' => $entretienId,
            ':rid' => $rubriqueId,
            ':cid' => $critereId,
            ':n'   => $note,
        ]);
    }

    echo json_encode(['ok' => true, 'scope' => $scope]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur BDD : ' . $e->getMessage()]);
}
