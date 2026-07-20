<?php
/**
 * api/immeuble_infos_publiques.php — CACHE des infos publiques d'un immeuble
 * (cadastre/PLU, urbanisme, altitude, risques ERP, registre copro).
 *
 * But : ne PLUS retaper les APIs externes à chaque sélection d'immeuble.
 *       Le navigateur (authentifié) interroge les sources au 1er coup puis POST le
 *       blob ici ; les fois suivantes, GET renvoie le cache → 0 appel externe.
 *
 * Ancrage : l'IMMEUBLE (id_immeuble). Résolveurs : google_place_id, puis addr_hash
 *           (cp|voie normalisés) — marche même si l'immeuble n'est pas encore créé
 *           et même sans Google (saisie manuelle).
 *
 * GET  ?place_id=&immeuble_id=&cp=&voie=&lat=&lng=
 *        → {ok:true, cached:bool, data?:{...}, fetched_at?}
 * POST JSON {place_id,immeuble_id,cp,voie,lat,lng,adresse_formatee,data:{lines,enrich,reco}}
 *        → {ok:true, id}
 * Sécurité : login. Donnée de référence physique (adresse) → non cloisonnée par société.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

// TTL du cache (jours) : au-delà, on considère l'info périmée et on laisse refetch.
const IIP_TTL_DAYS = 90;

/** Hash d'adresse stable : minuscule, sans accents, alphanum + espaces simples. */
function iip_addr_hash(string $cp, string $voie): string {
    $s = mb_strtolower(trim($cp) . '|' . trim($voie), 'UTF-8');
    $tr = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($tr !== false) { $s = $tr; }
    $s = preg_replace('/[^a-z0-9| ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return sha1(trim($s));
}

/** Résout la ligne de cache dans l'ordre : immeuble → place_id → addr_hash. */
function iip_resolve(PDO $pdo, int $immId, string $placeId, ?string $addrHash): ?array {
    if ($immId > 0) {
        $st = $pdo->prepare("SELECT * FROM immeuble_infos_publiques WHERE id_immeuble = ? ORDER BY fetched_at DESC LIMIT 1");
        $st->execute([$immId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    if ($placeId !== '') {
        $st = $pdo->prepare("SELECT * FROM immeuble_infos_publiques WHERE google_place_id = ? LIMIT 1");
        $st->execute([$placeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    if ($addrHash) {
        $st = $pdo->prepare("SELECT * FROM immeuble_infos_publiques WHERE addr_hash = ? ORDER BY fetched_at DESC LIMIT 1");
        $st->execute([$addrHash]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    if (!is_array($in)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad json']); exit; }

    $placeId = trim((string)($in['place_id'] ?? ''));
    $immId   = (int)($in['immeuble_id'] ?? 0);
    $cp      = trim((string)($in['cp'] ?? ''));
    $voie    = trim((string)($in['voie'] ?? ''));
    $lat     = ($in['lat'] ?? '') !== '' ? (float)$in['lat'] : null;
    $lng     = ($in['lng'] ?? '') !== '' ? (float)$in['lng'] : null;
    $adr     = trim((string)($in['adresse_formatee'] ?? ''));
    $data    = $in['data'] ?? null;
    $addrHash = ($cp !== '' && $voie !== '') ? iip_addr_hash($cp, $voie) : null;
    $dataJson = $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

    try {
        $row = iip_resolve($pdo, $immId, $placeId, $addrHash);
        if ($row) {
            // On complète les clés qui étaient inconnues (ex. immeuble créé depuis).
            $sql = "UPDATE immeuble_infos_publiques SET
                        id_immeuble      = COALESCE(NULLIF(?,0), id_immeuble),
                        google_place_id  = COALESCE(NULLIF(?,''), google_place_id),
                        addr_hash        = COALESCE(?, addr_hash),
                        lat = ?, lng = ?, adresse_formatee = COALESCE(NULLIF(?,''), adresse_formatee),
                        data = ?, fetched_at = NOW()
                    WHERE id = ?";
            $pdo->prepare($sql)->execute([$immId, $placeId, $addrHash, $lat, $lng, $adr, $dataJson, (int)$row['id']]);
            echo json_encode(['ok'=>true, 'id'=>(int)$row['id']]); exit;
        }
        $sql = "INSERT INTO immeuble_infos_publiques
                    (id_immeuble, google_place_id, addr_hash, lat, lng, adresse_formatee, data, fetched_at)
                VALUES (NULLIF(?,0), NULLIF(?,''), ?, ?, ?, NULLIF(?,''), ?, NOW())";
        $pdo->prepare($sql)->execute([$immId, $placeId, $addrHash, $lat, $lng, $adr, $dataJson]);
        echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]); exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
    }
}

// ── GET : lecture du cache ────────────────────────────────────────────────
$placeId = trim((string)($_GET['place_id'] ?? ''));
$immId   = (int)($_GET['immeuble_id'] ?? 0);
$cp      = trim((string)($_GET['cp'] ?? ''));
$voie    = trim((string)($_GET['voie'] ?? ''));
$addrHash = ($cp !== '' && $voie !== '') ? iip_addr_hash($cp, $voie) : null;

try {
    $row = iip_resolve($pdo, $immId, $placeId, $addrHash);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
}

if (!$row) { echo json_encode(['ok'=>true, 'cached'=>false]); exit; }

// Périmé ?
$fresh = false;
if (!empty($row['fetched_at'])) {
    $age = time() - strtotime((string)$row['fetched_at']);
    $fresh = $age >= 0 && $age <= IIP_TTL_DAYS * 86400;
}
if (!$fresh) { echo json_encode(['ok'=>true, 'cached'=>false]); exit; }

$data = json_decode((string)$row['data'], true);
echo json_encode([
    'ok'         => true,
    'cached'     => true,
    'fetched_at' => $row['fetched_at'],
    'data'       => is_array($data) ? $data : null,
], JSON_UNESCAPED_UNICODE);
