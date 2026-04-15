<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
verify_csrf_any();

// Admin uniquement
if (current_role_id() !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès refusé']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';

$allowedRubriques = ['personne', 'vehicule', 'societe', 'rh', 'divers'];

switch ($action) {

    // ── Ajouter / Modifier un type ────────────────────────────────────────────
    case 'save': {
        $rubrique    = trim($data['rubrique'] ?? '');
        $label       = trim($data['label'] ?? '');
        $obligatoire = !empty($data['obligatoire']) ? 1 : 0;
        $dispo       = in_array($data['dispo'] ?? '', ['public','manager','admin']) ? $data['dispo'] : 'public';
        $id          = isset($data['id']) ? (int)$data['id'] : 0;

        if (!in_array($rubrique, $allowedRubriques) || $label === '') {
            echo json_encode(['ok' => false, 'error' => 'Données invalides']); exit;
        }

        if ($id > 0) {
            // Mise à jour
            $pdo->prepare("UPDATE rh_doc_types SET rubrique=?, label=?, obligatoire=?, dispo=? WHERE id=?")
                ->execute([$rubrique, $label, $obligatoire, $dispo, $id]);
            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            // Insertion — générer un type_key unique
            $typeKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($label));
            $typeKey = substr($typeKey, 0, 50);
            // Éviter les doublons de clé dans la même rubrique
            $exist = $pdo->prepare("SELECT COUNT(*) FROM rh_doc_types WHERE rubrique=? AND type_key=?");
            $exist->execute([$rubrique, $typeKey]);
            if ($exist->fetchColumn() > 0) {
                $typeKey .= '_' . time();
            }
            // Ordre = max actuel + 1
            $ordre = (int)$pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM rh_doc_types WHERE rubrique=?")
                               ->execute([$rubrique]) ? 0 : 0;
            $stmtOrd = $pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM rh_doc_types WHERE rubrique=?");
            $stmtOrd->execute([$rubrique]);
            $ordre = (int)$stmtOrd->fetchColumn();

            $pdo->prepare("INSERT INTO rh_doc_types (rubrique, type_key, label, obligatoire, dispo, ordre) VALUES (?,?,?,?,?,?)")
                ->execute([$rubrique, $typeKey, $label, $obligatoire, $dispo, $ordre]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'type_key' => $typeKey]);
        }
        break;
    }

    // ── Basculer obligatoire ──────────────────────────────────────────────────
    case 'toggle_obligatoire': {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'id manquant']); exit; }
        $pdo->prepare("UPDATE rh_doc_types SET obligatoire = 1 - obligatoire WHERE id=?")->execute([$id]);
        $row = $pdo->prepare("SELECT obligatoire FROM rh_doc_types WHERE id=?");
        $row->execute([$id]);
        echo json_encode(['ok' => true, 'obligatoire' => (int)$row->fetchColumn()]);
        break;
    }

    // ── Définir la disponibilité (public → manager → admin → public) ──────────
    case 'set_dispo': {
        $id    = (int)($data['id'] ?? 0);
        $dispo = in_array($data['dispo'] ?? '', ['public','manager','admin']) ? $data['dispo'] : 'public';
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'id manquant']); exit; }
        $pdo->prepare("UPDATE rh_doc_types SET dispo=? WHERE id=?")->execute([$dispo, $id]);
        echo json_encode(['ok' => true, 'dispo' => $dispo]);
        break;
    }

    // ── Supprimer un type ─────────────────────────────────────────────────────
    case 'delete': {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'id manquant']); exit; }
        // Protéger les types système (non supprimables)
        $row = $pdo->prepare("SELECT systeme FROM rh_doc_types WHERE id=?");
        $row->execute([$id]);
        $type = $row->fetch(PDO::FETCH_ASSOC);
        if ($type && !empty($type['systeme'])) {
            echo json_encode(['ok' => false, 'error' => 'Type système non supprimable']); exit;
        }
        $pdo->prepare("DELETE FROM rh_doc_types WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
}
