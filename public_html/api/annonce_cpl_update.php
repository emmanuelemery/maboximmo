<?php
// api/annonce_cpl_update.php — Met à jour libelle / montant d'une ligne complément de loyer.
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

    $fields = [];
    $params = [];
    if (array_key_exists('libelle', $_POST)) {
        $fields[] = 'libelle = ?';
        $params[] = substr(trim((string)$_POST['libelle']), 0, 255);
    }
    if (array_key_exists('montant', $_POST)) {
        $fields[] = 'montant = ?';
        $params[] = $_POST['montant'] === '' ? 0 : (float)$_POST['montant'];
    }
    if (!$fields) {
        exit(json_encode(['ok' => false, 'error' => 'Aucun champ à mettre à jour']));
    }
    $params[] = $ligneId;
    $pdo->prepare("UPDATE annonces_complement_loyer_lignes SET " . implode(', ', $fields) . " WHERE id = ?")
        ->execute($params);

    // Recalcul total + sync annonces.complement_loyer
    $stT = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM annonces_complement_loyer_lignes WHERE id_annonce = ?");
    $stT->execute([$annonceId]);
    $total = (float)$stT->fetchColumn();
    $pdo->prepare("UPDATE annonces SET complement_loyer = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$total, $annonceId]);

    // Cascade : loyer HC (si majoré > 0) → loyer CC
    require_once dirname(__DIR__) . '/inc/honoraires_helper.php';
    $loyerHC = loyer_hc_recalc_save($pdo, $annonceId);
    $loyerCC = loyer_cc_recalc_save($pdo, $annonceId);

    exit(json_encode([
        'ok' => true, 'total' => $total, 'id_annonce' => $annonceId,
        'loyer_cc' => $loyerCC,
        'loyer_hc' => $loyerHC,
        'complement_loyer' => $total,
    ]));
} catch (Throwable $e) {
    error_log('[annonce_cpl_update] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
