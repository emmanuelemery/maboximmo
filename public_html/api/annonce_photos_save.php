<?php
declare(strict_types=1);

/**
 * Enregistre la sélection de photos pour une annonce.
 *
 * POST : _annonce_id (int), id_photos[] (array d'int dans l'ordre), csrf_token
 * Purge annonces_photos de l'annonce puis INSERT les nouveaux liens avec
 * l'ordre fourni.
 *
 * Renvoie JSON {ok:true, count:N} ou {ok:false, error:...}.
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

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;

$annonceId = isset($_POST['_annonce_id']) && ctype_digit((string)$_POST['_annonce_id']) ? (int)$_POST['_annonce_id'] : 0;
$idPhotos  = $_POST['id_photos'] ?? [];
if (!is_array($idPhotos)) $idPhotos = [];
$idPhotos = array_values(array_unique(array_map('intval', $idPhotos)));

if ($annonceId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_annonce manquant']));
}

// Vérif scope annonce
$st = $pdo->prepare("
    SELECT a.id_bien, a.statut, b.id_societe
    FROM annonces a
    JOIN biens b ON b.id = a.id_bien
    WHERE a.id = ?
    LIMIT 1
");
$st->execute([$annonceId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
}
if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
}
$bienId = (int)$row['id_bien'];

// Refuse modif uniquement si l'annonce est explicitement archivée
$statutLower = strtolower(trim((string)$row['statut']));
if (in_array($statutLower, ['archive', 'archivee', 'archivée', 'ferme', 'fermee', 'fermée', 'cloturee', 'clôturée'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'Annonce archivée — lecture seule']));
}

// Filtre : ne garder que les id_photos qui appartiennent vraiment au bien
if (!empty($idPhotos)) {
    $placeholders = implode(',', array_fill(0, count($idPhotos), '?'));
    $st = $pdo->prepare("SELECT id FROM biens_photos WHERE id_bien = ? AND id IN ($placeholders)");
    $st->execute(array_merge([$bienId], $idPhotos));
    $validIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    // Préserver l'ordre demandé
    $idPhotos = array_values(array_filter($idPhotos, fn($id) => in_array($id, $validIds, true)));
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM annonces_photos WHERE id_annonce = ?")->execute([$annonceId]);
    if (!empty($idPhotos)) {
        $ins = $pdo->prepare("INSERT INTO annonces_photos (id_annonce, id_biens_photo, ordre, date_creation) VALUES (?, ?, ?, NOW())");
        $ordre = 0;
        foreach ($idPhotos as $pid) {
            $ins->execute([$annonceId, $pid, $ordre++]);
        }
    }
    $pdo->commit();
    exit(json_encode(['ok' => true, 'count' => count($idPhotos)]));
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[annonce_photos_save] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
