<?php
declare(strict_types=1);
/**
 * api/rh_contrat_save.php — Enregistre un contrat de travail dans l'historique du salarié.
 * Chaque appel crée une NOUVELLE ligne (un salarié peut avoir plusieurs contrats : CDD, CDD, CDI…).
 *
 * POST JSON : { user_id, type, niveau, salaire, fonction, date_debut, date_fin, motif, duree, mission }
 * Réponse : { ok, id }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) exit(json_encode(['ok'=>false,'error'=>'JSON invalide']));

$pdo    = $GLOBALS['pdo'];
$userId = (int)($body['user_id'] ?? 0);
if ($userId <= 0) exit(json_encode(['ok'=>false,'error'=>'user_id requis']));
$me = (int)($_SESSION['user_id'] ?? 0) ?: null;

$cut = static fn($v,$n) => mb_substr(trim((string)$v), 0, $n);
$id  = (int)($body['id'] ?? 0); // si fourni → mise à jour d'un brouillon existant
// Tous les champs du formulaire (prévoyance, santé, période d'essai, supérieur, délai…) pour reprise fidèle
$dataJson = isset($body['data']) ? json_encode($body['data'], JSON_UNESCAPED_UNICODE) : null;

try {
    $vals = [
        $cut($body['type'] ?? '', 10),
        $cut($body['niveau'] ?? '', 10),
        $cut($body['salaire'] ?? '', 20),
        $cut($body['fonction'] ?? '', 190),
        $cut($body['date_debut'] ?? '', 20),
        $cut($body['date_fin'] ?? '', 20),
        $cut($body['motif'] ?? '', 255),
        $cut($body['duree'] ?? '', 60),
        trim((string)($body['mission'] ?? '')),
    ];

    // UPDATE si l'id existe ET appartient bien à ce salarié
    $owned = false;
    if ($id > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM user_contrats WHERE id=? AND id_user=?");
        $chk->execute([$id, $userId]); $owned = (bool)$chk->fetchColumn();
    }

    // La colonne data_json peut ne pas exister (migration 20260629_user_contrats_data non passée).
    $hasData = false;
    try { $hasData = (bool)$pdo->query("SHOW COLUMNS FROM user_contrats LIKE 'data_json'")->fetchColumn(); } catch (Throwable) {}

    if ($owned) {
        if ($hasData) {
            $pdo->prepare("UPDATE user_contrats SET type_contrat=?, niveau=?, salaire_brut=?, fonction=?, date_debut=?, date_fin=?, motif=?, duree=?, mission=?, data_json=? WHERE id=? AND id_user=?")
                ->execute(array_merge($vals, [$dataJson, $id, $userId]));
        } else {
            $pdo->prepare("UPDATE user_contrats SET type_contrat=?, niveau=?, salaire_brut=?, fonction=?, date_debut=?, date_fin=?, motif=?, duree=?, mission=? WHERE id=? AND id_user=?")
                ->execute(array_merge($vals, [$id, $userId]));
        }
        echo json_encode(['ok'=>true, 'id'=>$id, 'updated'=>true, 'full'=>$hasData], JSON_UNESCAPED_UNICODE);
    } else {
        if ($hasData) {
            $pdo->prepare("INSERT INTO user_contrats (type_contrat, niveau, salaire_brut, fonction, date_debut, date_fin, motif, duree, mission, data_json, id_user, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($vals, [$dataJson, $userId, $me]));
        } else {
            $pdo->prepare("INSERT INTO user_contrats (type_contrat, niveau, salaire_brut, fonction, date_debut, date_fin, motif, duree, mission, id_user, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($vals, [$userId, $me]));
        }
        echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'updated'=>false, 'full'=>$hasData], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
