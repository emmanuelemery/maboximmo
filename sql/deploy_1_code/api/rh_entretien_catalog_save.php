<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_entretien_catalog.php';
require_login();

header('Content-Type: application/json');

$roleId = current_role_id();
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Accès refusé.']));
}

verify_csrf_any();

$pdo      = $GLOBALS['pdo'];
$payload  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action   = (string)($payload['action'] ?? '');
$societeId = current_societe_id();

if (!$societeId) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Société non définie pour cet utilisateur.']));
}

try {
    switch ($action) {

        case 'toggle_rubrique': {
            $rubriqueId = (int)($payload['rubrique_id'] ?? 0);
            $actif      = (bool)($payload['actif'] ?? false);
            if ($rubriqueId <= 0) throw new InvalidArgumentException("rubrique_id invalide.");
            catalog_set_rubrique($pdo, $societeId, $rubriqueId, $actif);
            echo json_encode(['success' => true]);
            break;
        }

        case 'toggle_question': {
            $questionId = (int)($payload['question_id'] ?? 0);
            $actif      = (bool)($payload['actif'] ?? false);
            if ($questionId <= 0) throw new InvalidArgumentException("question_id invalide.");
            catalog_set_question($pdo, $societeId, $questionId, $actif);
            echo json_encode(['success' => true]);
            break;
        }

        case 'toggle_option': {
            $optionId = (int)($payload['option_id'] ?? 0);
            $actif    = (bool)($payload['actif'] ?? false);
            if ($optionId <= 0) throw new InvalidArgumentException("option_id invalide.");
            $stmt = $pdo->prepare(
                "INSERT INTO rh_entretien_societe_options (societe_id, option_id, actif)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE actif = VALUES(actif)"
            );
            $stmt->execute([$societeId, $optionId, $actif ? 1 : 0]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'add_question': {
            $rubriqueId  = (int)($payload['rubrique_id'] ?? 0);
            $label       = trim((string)($payload['label'] ?? ''));
            $questionText = trim((string)($payload['question_text'] ?? ''));
            $description = trim((string)($payload['description'] ?? ''));
            $options     = $payload['options'] ?? [];

            if ($rubriqueId <= 0) throw new InvalidArgumentException("rubrique_id invalide.");
            if ($label === '') throw new InvalidArgumentException("Le libellé est requis.");

            // Déterminer l'ordre max
            $stmtMax = $pdo->prepare(
                "SELECT COALESCE(MAX(ordre), 0) + 1 FROM rh_entretien_questions_user WHERE rubrique_id = ?"
            );
            $stmtMax->execute([$rubriqueId]);
            $ordre = (int)$stmtMax->fetchColumn();

            $stmtQ = $pdo->prepare(
                "INSERT INTO rh_entretien_questions_user
                    (rubrique_id, label, question_text, description, ordre, actif, societe_id, is_suggestion)
                 VALUES (?, ?, ?, ?, ?, 1, ?, 0)"
            );
            $stmtQ->execute([$rubriqueId, $label, $questionText ?: null, $description ?: null, $ordre, $societeId]);
            $questionId = (int)$pdo->lastInsertId();

            // Ajouter dans la config société
            catalog_set_question($pdo, $societeId, $questionId, true, $ordre);

            // Ajouter les options
            if (is_array($options)) {
                $stmtOpt = $pdo->prepare(
                    "INSERT INTO rh_entretien_question_options
                        (question_id, label, ordre, actif, societe_id)
                     VALUES (?, ?, ?, 1, ?)"
                );
                $oOrdre = 1;
                foreach ($options as $optLabel) {
                    $optLabel = trim((string)$optLabel);
                    if ($optLabel === '') continue;
                    $stmtOpt->execute([$questionId, $optLabel, $oOrdre, $societeId]);
                    $oOrdre++;
                }
            }

            echo json_encode(['success' => true, 'question_id' => $questionId]);
            break;
        }

        case 'add_option': {
            $questionId = (int)($payload['question_id'] ?? 0);
            $label      = trim((string)($payload['label'] ?? ''));
            if ($questionId <= 0) throw new InvalidArgumentException("question_id invalide.");
            if ($label === '') throw new InvalidArgumentException("Le libellé est requis.");

            $stmtMax = $pdo->prepare(
                "SELECT COALESCE(MAX(ordre), 0) + 1 FROM rh_entretien_question_options WHERE question_id = ?"
            );
            $stmtMax->execute([$questionId]);
            $ordre = (int)$stmtMax->fetchColumn();

            $stmtO = $pdo->prepare(
                "INSERT INTO rh_entretien_question_options (question_id, label, ordre, actif, societe_id)
                 VALUES (?, ?, ?, 1, ?)"
            );
            $stmtO->execute([$questionId, $label, $ordre, $societeId]);
            $optionId = (int)$pdo->lastInsertId();

            $stmtSo = $pdo->prepare(
                "INSERT INTO rh_entretien_societe_options (societe_id, option_id, actif)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE actif = 1"
            );
            $stmtSo->execute([$societeId, $optionId]);

            echo json_encode(['success' => true, 'option_id' => $optionId]);
            break;
        }

        case 'share_suggestion': {
            $questionId = (int)($payload['question_id'] ?? 0);
            if ($questionId <= 0) throw new InvalidArgumentException("question_id invalide.");

            // Vérifier que la question appartient bien à cette société
            $stmtChk = $pdo->prepare(
                "SELECT id FROM rh_entretien_questions_user WHERE id = ? AND societe_id = ?"
            );
            $stmtChk->execute([$questionId, $societeId]);
            if (!$stmtChk->fetch()) {
                throw new RuntimeException("Question non trouvée ou non autorisée.");
            }

            $pdo->prepare("UPDATE rh_entretien_questions_user SET is_suggestion = 1 WHERE id = ?")
                ->execute([$questionId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'adopt_suggestion': {
            $questionId = (int)($payload['question_id'] ?? 0);
            if ($questionId <= 0) throw new InvalidArgumentException("question_id invalide.");
            catalog_adopt_suggestion($pdo, $societeId, $questionId);
            echo json_encode(['success' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Action inconnue : {$action}"]);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur base de données.']);
}
