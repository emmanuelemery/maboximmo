<?php
/**
 * api/immeuble_recherche_mbi.php — STANDARD MBI de résolution/création d'immeuble.
 *
 * Règle (Emmanuel 2026-06-14) : recherche d'adresse Google → nom d'immeuble APRÈS,
 * SANS attribution (l'immeuble est accessible à TOUT LE MONDE : id_societe / id_agence
 * NULL). Anti-doublon via immeuble_resolve (réutilise l'existant, jamais de 2e).
 *
 * POST : nom, adresse_1, code_postal, ville, latitude, longitude,
 *        google_place_id, id_immeuble_selected
 *   →  { ok, immeuble:{ id, nom, adresse_1, code_postal, ville }, created:bool }
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/immeuble_link.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$nom      = trim((string)(post('nom') ?? ''));
$adr1     = trim((string)(post('adresse_1') ?? ''));
$cp       = trim((string)(post('code_postal') ?? ''));
$ville    = trim((string)(post('ville') ?? ''));
$lat      = post('latitude')  !== '' && post('latitude')  !== null ? (float)post('latitude')  : null;
$lng      = post('longitude') !== '' && post('longitude') !== null ? (float)post('longitude') : null;
$placeId  = trim((string)(post('google_place_id') ?? '')) ?: null;
$selected = (int)(post('id_immeuble_selected') ?? 0);

if ($adr1 === '' && $selected <= 0) { echo json_encode(['ok'=>false,'error'=>'adresse requise']); exit; }

try {
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM immeubles")->fetchColumn();

    // Anti-doublon + création SANS attribution (accessible à tous).
    $idImmeuble = immeuble_resolve($pdo, [
        'id_immeuble_selected' => $selected,
        'adresse_1' => $adr1, 'code_postal' => $cp, 'ville' => $ville,
        'latitude' => $lat, 'longitude' => $lng, 'google_place_id' => $placeId,
        'id_societe' => null, 'id_agence' => null,
    ]);
    if ($idImmeuble <= 0) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'immeuble non résolu']); exit; }

    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM immeubles")->fetchColumn();
    $created = $countAfter > $countBefore;

    // Nom d'immeuble : renseigné APRÈS la recherche. On le pose seulement s'il est
    // fourni ET que l'immeuble n'en a pas déjà un (on n'écrase pas un immeuble partagé).
    if ($nom !== '') {
        $pdo->prepare("UPDATE immeubles SET nom_immeuble = ?
                        WHERE id = ? AND (nom_immeuble IS NULL OR nom_immeuble = '')")
            ->execute([$nom, $idImmeuble]);
    }

    $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville FROM immeubles WHERE id = ?");
    $st->execute([$idImmeuble]);
    $imm = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'       => true,
        'created'  => $created,
        'immeuble' => [
            'id'          => (int)$imm['id'],
            'nom'         => $imm['nom_immeuble'] ?: '',
            'adresse_1'   => $imm['adresse_1'] ?: '',
            'code_postal' => $imm['code_postal'] ?: '',
            'ville'       => $imm['ville'] ?: '',
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[immeuble_recherche_mbi] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
