<?php
/**
 * api/geo_save_coords.php — Enregistre la position GPS ajustée manuellement (pin déplacé
 * sur le bâtiment exact) pour un bien ou un immeuble.
 *
 * POST JSON : { type: 'BIEN'|'IMB', id, lat, lng }  →  { ok }
 * Auth : user + scope société (bypass admin).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$isAdmin=((int)($_SESSION['id_role']??0)===1); $userSoc=(int)($_SESSION['id_societe']??0);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$type=strtoupper(trim((string)($body['type']??'')));
$id=(int)($body['id']??0);
$lat=(float)($body['lat']??0); $lng=(float)($body['lng']??0);
if (!in_array($type,['BIEN','IMB'],true) || $id<=0) exit(json_encode(['ok'=>false,'error'=>'type/id invalides']));
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat==0 && $lng==0)) exit(json_encode(['ok'=>false,'error'=>'Coordonnées invalides']));

$table = $type==='IMB' ? 'immeubles' : 'biens';
// Scope société (bypass admin).
$st=$pdo->prepare("SELECT id_societe FROM $table WHERE id=? LIMIT 1");
$st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC);
if (!$r){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Entité introuvable'])); }
if (!$isAdmin && !empty($r['id_societe']) && (int)$r['id_societe']!==$userSoc){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }

// Marqueur de source "manuel" si la colonne existe.
$srcCol = $type==='IMB' ? 'gps_source' : 'precision_geoloc';
try {
    $pdo->prepare("UPDATE $table SET latitude=?, longitude=?, `$srcCol`='manuel' WHERE id=?")->execute([$lat,$lng,$id]);
} catch (Throwable) {
    $pdo->prepare("UPDATE $table SET latitude=?, longitude=? WHERE id=?")->execute([$lat,$lng,$id]);
}
echo json_encode(['ok'=>true,'lat'=>$lat,'lng'=>$lng], JSON_UNESCAPED_UNICODE);
