<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/arbitrage_access.php';

require_arbitrage_access();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('arbitrage');

$pdo = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$objectif = isset($input['objectif_tresorerie']) ? (float)$input['objectif_tresorerie'] : 15000000.0;
$horizon  = isset($input['horizon_mois']) ? (int)$input['horizon_mois'] : 18;
$rendPct  = isset($input['rendement_reinvest_cible_pct']) ? (float)$input['rendement_reinvest_cible_pct'] : 6.0;

if ($objectif <= 0) $objectif = 15000000.0;
if ($horizon < 6 || $horizon > 60) $horizon = 18;
if ($rendPct < 0 || $rendPct > 25) $rendPct = 6.0;

try {
    $stmt = $pdo->prepare("SELECT id FROM arbitrage_settings WHERE id_societe = ? LIMIT 1");
    $stmt->execute([$societeId]);
    $id = (int)$stmt->fetchColumn();

    if ($id > 0) {
        $pdo->prepare("UPDATE arbitrage_settings SET objectif_tresorerie=?, horizon_mois=?, rendement_reinvest_cible_pct=? WHERE id=?")
            ->execute([$objectif, $horizon, $rendPct, $id]);
    } else {
        $pdo->prepare("INSERT INTO arbitrage_settings (id_societe, objectif_tresorerie, horizon_mois, rendement_reinvest_cible_pct) VALUES (?,?,?,?)")
            ->execute([$societeId, $objectif, $horizon, $rendPct]);
        $id = (int)$pdo->lastInsertId();
    }

    echo json_encode(['ok' => true, 'id' => $id, 'settings' => [
        'objectif_tresorerie' => $objectif,
        'horizon_mois' => $horizon,
        'rendement_reinvest_cible_pct' => $rendPct,
    ]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur sauvegarde settings.']);
}

