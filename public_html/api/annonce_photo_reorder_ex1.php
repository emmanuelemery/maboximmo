<?php
// api/annonce_photo_reorder.php — Reordonne les photos selectionnees d'une annonce
//   POST: id_annonce + ordered_ids[] (ou ordered_ids CSV)
//   - Met a jour annonces_photos.ordre selon l'ordre recu
//   - Ignore les IDs qui ne sont pas deja lies a l'annonce (pas d'insert ici)
//   - Applique en transaction
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

$annonceId = isset($_POST['id_annonce']) && ctype_digit((string)$_POST['id_annonce']) ? (int)$_POST['id_annonce'] : 0;
if ($annonceId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_annonce manquant']));
}

// Accepte ordered_ids[] (tableau) ou ordered_ids en CSV
$rawIds = $_POST['ordered_ids'] ?? '';
if (is_array($rawIds)) {
    $orderedIds = array_values(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0));
} else {
    $parts = array_map('trim', explode(',', (string)$rawIds));
    $orderedIds = array_values(array_filter(array_map('intval', $parts), fn($v) => $v > 0));
}
if (empty($orderedIds)) {
    exit(json_encode(['ok' => false, 'error' => 'ordered_ids vide']));
}

// Scope
try {
    $st = $pdo->prepare("SELECT b.id_societe FROM annonces a JOIN biens b ON b.id = a.id_bien WHERE a.id = ? LIMIT 1");
    $st->execute([$annonceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope societe']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur verif scope']));
}

// Verifie que les IDs appartiennent bien a l'annonce
try {
    $ph = implode(',', array_fill(0, count($orderedIds), '?'));
    $stC = $pdo->prepare("SELECT id_biens_photo FROM annonces_photos WHERE id_annonce = ? AND id_biens_photo IN ($ph)");
    $stC->execute(array_merge([$annonceId], $orderedIds));
    $existing = array_map('intval', $stC->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $filtered = array_values(array_intersect($orderedIds, $existing));
    if (empty($filtered)) {
        exit(json_encode(['ok' => false, 'error' => 'Aucune photo valide a reordonner']));
    }

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE annonces_photos SET ordre = ? WHERE id_annonce = ? AND id_biens_photo = ?");
    foreach ($filtered as $i => $pid) {
        $upd->execute([$i, $annonceId, $pid]);
    }
    $pdo->commit();

    exit(json_encode([
        'ok'    => true,
        'count' => count($filtered),
        'order' => $filtered,
    ]));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[annonce_photo_reorder] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
