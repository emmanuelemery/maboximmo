<?php
/**
 * AJAX — Archiver/restaurer un locataire parti
 * POST: id_bien, locataire_nom, id_proprietaire, archive (0|1)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'msg'=>'Non connecté']); exit; }

$pdo = $GLOBALS['pdo'];

$id_bien       = (int)($_POST['id_bien'] ?? 0);
$locataire_nom = trim($_POST['locataire_nom'] ?? '');
$id_prop       = (int)($_POST['id_proprietaire'] ?? 0);
$archive       = (int)($_POST['archive'] ?? 0) ? 1 : 0;

if (!$id_bien || !$locataire_nom || !$id_prop) {
    echo json_encode(['ok'=>false,'msg'=>'paramètres manquants']); exit;
}

$stmt = $pdo->prepare(
    "UPDATE locataires_statuts SET archive=? WHERE id_bien=? AND locataire_nom=? AND id_proprietaire=?"
);
$stmt->execute([$archive, $id_bien, $locataire_nom, $id_prop]);

echo json_encode(['ok' => true, 'archive' => $archive]);
