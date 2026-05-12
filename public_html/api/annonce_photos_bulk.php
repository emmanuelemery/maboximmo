<?php
// api/annonce_photos_bulk.php — Sélection/désélection massive des photos de l'annonce
//   action=all  → lie toutes les biens_photos du bien à l'annonce (dédup)
//   action=none → supprime toutes les lignes annonces_photos de l'annonce
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;

$annonceId = isset($_POST['id_annonce']) && ctype_digit((string)$_POST['id_annonce']) ? (int)$_POST['id_annonce'] : 0;
$action    = isset($_POST['action']) ? (string)$_POST['action'] : '';

if ($annonceId <= 0 || !in_array($action, ['all', 'none'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres invalides']));
}

try {
    $st = $pdo->prepare("SELECT a.id_bien, b.id_societe FROM annonces a JOIN biens b ON b.id = a.id_bien WHERE a.id = ? LIMIT 1");
    $st->execute([$annonceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
    if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }
    $bienId = (int)$row['id_bien'];

    // Limite portails : 7 photos max par annonce
    $MAX_PHOTOS = 7;

    if ($action === 'none') {
        $pdo->prepare("DELETE FROM annonces_photos WHERE id_annonce = ?")->execute([$annonceId]);
    } else {
        // Récupère les photos déjà liées et toutes les photos du bien
        $stL = $pdo->prepare("SELECT id_biens_photo FROM annonces_photos WHERE id_annonce = ?");
        $stL->execute([$annonceId]);
        $existing = array_map('intval', $stL->fetchAll(PDO::FETCH_COLUMN) ?: []);

        // Si déjà saturé, on ne rajoute rien
        $slots = max(0, $MAX_PHOTOS - count($existing));
        if ($slots > 0) {
            $stP = $pdo->prepare("SELECT id FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
            $stP->execute([$bienId]);
            $all = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $missing = array_values(array_diff($all, $existing));
            // On ne prend que ce qui rentre dans la limite de 7
            $missing = array_slice($missing, 0, $slots);
            if ($missing) {
                $stO = $pdo->prepare("SELECT COALESCE(MAX(ordre), -1) + 1 FROM annonces_photos WHERE id_annonce = ?");
                $stO->execute([$annonceId]);
                $nextO = (int)$stO->fetchColumn();
                $ins = $pdo->prepare("INSERT INTO annonces_photos (id_annonce, id_biens_photo, ordre) VALUES (?, ?, ?)");
                foreach ($missing as $pid) {
                    $ins->execute([$annonceId, $pid, $nextO++]);
                }
            }
        }
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM annonces_photos WHERE id_annonce = ?");
    $cnt->execute([$annonceId]);
    $count = (int)$cnt->fetchColumn();

    $ids = $pdo->prepare("SELECT id_biens_photo FROM annonces_photos WHERE id_annonce = ?");
    $ids->execute([$annonceId]);
    $selectedIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);

    exit(json_encode(['ok' => true, 'action' => $action, 'count' => $count, 'selected_ids' => $selectedIds]));
} catch (Throwable $e) {
    error_log('[annonce_photos_bulk] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
