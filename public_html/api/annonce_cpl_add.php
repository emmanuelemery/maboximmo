<?php
// api/annonce_cpl_add.php — Ajoute une ligne vide de complément de loyer à une annonce
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

try {
    $st = $pdo->prepare("SELECT b.id_societe FROM annonces a JOIN biens b ON b.id = a.id_bien WHERE a.id = ? LIMIT 1");
    $st->execute([$annonceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }

    $stO = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM annonces_complement_loyer_lignes WHERE id_annonce = ?");
    $stO->execute([$annonceId]);
    $nextO = (int)$stO->fetchColumn();

    $pdo->prepare("INSERT INTO annonces_complement_loyer_lignes (id_annonce, libelle, montant, ordre, date_creation) VALUES (?, '', 0, ?, NOW())")
        ->execute([$annonceId, $nextO]);
    $id = (int)$pdo->lastInsertId();

    exit(json_encode(['ok' => true, 'id' => $id, 'ordre' => $nextO, 'total' => 0]));
} catch (Throwable $e) {
    error_log('[annonce_cpl_add] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
