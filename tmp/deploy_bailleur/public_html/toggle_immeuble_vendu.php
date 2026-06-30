<?php
/**
 * AJAX — Marquer/démarquer un immeuble comme vendu
 * POST: id_immeuble, vendu (0|1), date_vente (optionnel)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'msg'=>'Non connecté']); exit; }

$pdo    = $GLOBALS['pdo'];
$id     = (int)($_POST['id_immeuble'] ?? 0);
$vendu  = (int)($_POST['vendu'] ?? 0) ? 1 : 0;
$date_v = trim($_POST['date_vente'] ?? '');

if (!$id) { echo json_encode(['ok'=>false,'msg'=>'id manquant']); exit; }

if ($vendu && $date_v) {
    $pdo->prepare("UPDATE immeubles SET vendu=1, date_vente=? WHERE id=?")->execute([$date_v, $id]);
} elseif ($vendu) {
    $pdo->prepare("UPDATE immeubles SET vendu=1, date_vente=NULL WHERE id=?")->execute([$id]);
} else {
    $pdo->prepare("UPDATE immeubles SET vendu=0, date_vente=NULL WHERE id=?")->execute([$id]);
}

$r = $pdo->prepare("SELECT vendu, date_vente, nom_immeuble, adresse_1 FROM immeubles WHERE id=?");
$r->execute([$id]);
$row = $r->fetch(\PDO::FETCH_ASSOC);

echo json_encode([
    'ok'         => true,
    'vendu'      => (int)($row['vendu'] ?? 0),
    'date_vente' => $row['date_vente'] ?? null,
    'nom'        => $row['nom_immeuble'] ?? '',
    'adresse'    => $row['adresse_1'] ?? '',
]);
