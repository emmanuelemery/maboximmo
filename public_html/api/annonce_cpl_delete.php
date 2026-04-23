<?php
// api/annonce_cpl_delete.php — Supprime une ligne de complément de loyer.
// Recalcule le total et met à jour annonces.complement_loyer.
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

$ligneId = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
if ($ligneId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id ligne manquant']));
}

try {
    $st = $pdo->prepare("
        SELECT l.id_annonce, b.id_societe
        FROM annonces_complement_loyer_lignes l
        JOIN annonces a ON a.id = l.id_annonce
        JOIN biens b ON b.id = a.id_bien
        WHERE l.id = ? LIMIT 1
    ");
    $st->execute([$ligneId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Ligne introuvable']));
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }
    $annonceId = (int)$row['id_annonce'];

    $pdo->prepare("DELETE FROM annonces_complement_loyer_lignes WHERE id = ?")->execute([$ligneId]);

    // Sync complement_loyer selon mode (0 si reference/minore/libre, SUM si majore)
    require_once dirname(__DIR__) . '/inc/honoraires_helper.php';
    $applied = complement_loyer_sync_from_mode($pdo, $annonceId);

    $stT = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM annonces_complement_loyer_lignes WHERE id_annonce = ?");
    $stT->execute([$annonceId]);
    $totalLignes = (float)$stT->fetchColumn();

    // Cascade : loyer HC (selon mode) → loyer CC
    $loyerHC = loyer_hc_recalc_save($pdo, $annonceId);
    $loyerCC = loyer_cc_recalc_save($pdo, $annonceId);

    exit(json_encode([
        'ok' => true, 'total' => $totalLignes, 'id_annonce' => $annonceId,
        'loyer_cc' => $loyerCC,
        'loyer_hc' => $loyerHC,
        'complement_loyer' => $applied,
    ]));
} catch (Throwable $e) {
    error_log('[annonce_cpl_delete] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
