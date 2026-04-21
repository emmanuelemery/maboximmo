<?php
declare(strict_types=1);

/**
 * Resync les annonces_photos d'une annonce depuis biens_photos du bien.
 * Purge les liens existants et recrée 1 lien par photo du bien (dans l'ordre).
 *
 * POST : _annonce_id, id_bien, csrf_token
 * Réponse : {ok:true, count:N} ou {ok:false, error:...}
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo = $GLOBALS['pdo'];
$annonceId = isset($_POST['_annonce_id']) && ctype_digit((string)$_POST['_annonce_id']) ? (int)$_POST['_annonce_id'] : 0;
$bienId    = isset($_POST['id_bien'])     && ctype_digit((string)$_POST['id_bien'])     ? (int)$_POST['id_bien']     : 0;

if ($annonceId <= 0 || $bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'IDs manquants']));
}

// Vérif scope (annonce appartient au bien)
$st = $pdo->prepare("SELECT id_bien FROM annonces WHERE id = ? LIMIT 1");
$st->execute([$annonceId]);
$dbBienId = (int)$st->fetchColumn();
if ($dbBienId !== $bienId) {
    exit(json_encode(['ok' => false, 'error' => 'Annonce hors scope']));
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM annonces_photos WHERE id_annonce = ?")->execute([$annonceId]);
    $st = $pdo->prepare("SELECT id FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
    $st->execute([$bienId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    $ins = $pdo->prepare("INSERT INTO annonces_photos (id_annonce, id_biens_photo, ordre, date_creation) VALUES (?, ?, ?, NOW())");
    $ordre = 0;
    foreach ($ids as $bpId) {
        $ins->execute([$annonceId, (int)$bpId, $ordre++]);
    }
    $pdo->commit();
    exit(json_encode(['ok' => true, 'count' => $ordre]));
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[annonce_photos_resync] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
