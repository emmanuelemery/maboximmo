<?php
declare(strict_types=1);
/**
 * api/tiers_infos_juridiques_save.php — Persiste le snapshot juridique (Pappers)
 * sur le tiers, pour qu'il s'affiche directement à la prochaine ouverture.
 *
 * POST JSON : { tiers_id, csrf, data:{...snapshot pappers...} }
 * Réponse : { ok, maj }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) exit(json_encode(['ok'=>false,'error'=>'JSON invalide']));
$_POST['csrf_token'] = (string)($body['csrf'] ?? '');
if (function_exists('verify_csrf')) { try { verify_csrf('tiers_infos_juridiques'); } catch (Throwable) { /* token souple */ } }

$pdo     = $GLOBALS['pdo'];
$tiersId = (int)($body['tiers_id'] ?? 0);
$data    = is_array($body['data'] ?? null) ? $body['data'] : null;
if ($tiersId <= 0 || $data === null) exit(json_encode(['ok'=>false,'error'=>'tiers_id + data requis']));

$now = date('Y-m-d H:i:s');
// Renommage SCI : on aligne la raison sociale + le nom d'affichage sur le nom OFFICIEL Pappers
// (le contact reste dans tiers.nom / tiers.prenom, on n'y touche pas). + SIREN officiel.
$officialName = trim((string)($data['raison_sociale'] ?? ''));
$officialSiren = preg_replace('/\D+/', '', (string)($data['siren'] ?? ''));
try {
    $set = ["infos_juridiques_json = ?", "infos_juridiques_maj = ?"];
    $par = [json_encode($data, JSON_UNESCAPED_UNICODE), $now];
    if ($officialName !== '') { $set[] = "raison_sociale = ?"; $par[] = $officialName; $set[] = "nom_affichage = ?"; $par[] = $officialName; }
    if ($officialSiren !== '') { $set[] = "siren = ?"; $par[] = $officialSiren; }
    $par[] = $tiersId;
    $pdo->prepare("UPDATE tiers SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
}
echo json_encode(['ok'=>true, 'maj'=>$now, 'renamed'=>$officialName ?: null], JSON_UNESCAPED_UNICODE);
