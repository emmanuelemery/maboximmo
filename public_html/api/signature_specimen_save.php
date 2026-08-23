<?php
/**
 * api/signature_specimen_save.php — enregistre un spécimen (signature, paraphe, cachet).
 *
 * POST JSON : { type, image, portee?:'user'|'societe' } → { ok, id, message }
 *   `image` = data URL PNG (tracé au doigt/souris, ou image détourée déposée).
 *
 * ── ON N'ÉCRASE JAMAIS ─────────────────────────────────────────────────────────────
 * Chaque enregistrement crée une LIGNE ; la précédente passe à `actif = 0` et RESTE.
 * Si une signature apposée en mars est un jour contestée, il faut pouvoir montrer le
 * spécimen tel qu'il était en mars — un UPDATE rendrait cette démonstration impossible,
 * et c'est le genre de manque qui ne se rattrape pas après coup.
 *
 * ── À QUI APPARTIENT QUOI ──────────────────────────────────────────────────────────
 * signature et paraphe → à la PERSONNE connectée. On ne permet pas d'enregistrer la
 *   signature de quelqu'un d'autre : ce serait fabriquer sa signature à sa place.
 * cachet → à la SOCIÉTÉ. Il ne suit pas la personne, et changer de société ne doit pas
 *   obliger à refaire son tracé.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
if (function_exists('verify_csrf_any')) verify_csrf_any('signature_specimen');

$pdo  = $GLOBALS['pdo'];
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

$type = (string)($body['type'] ?? 'signature');
if (!in_array($type, ['signature', 'paraphe', 'cachet'], true)) {
    exit(json_encode(['ok'=>false,'error'=>'Type inconnu'], JSON_UNESCAPED_UNICODE));
}

$img = (string)($body['image'] ?? '');
/* ⚠️ On n'accepte QUE du PNG en data URL, et on vérifie que le contenu décodé EST une
   image — pas seulement que la chaîne y ressemble. Ce contenu finira dans un acte : une
   donnée arbitraire glissée ici serait recopiée dans un document opposable. */
if (!preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~', trim($img), $m)) {
    exit(json_encode(['ok'=>false,'error'=>'Image attendue au format PNG'], JSON_UNESCAPED_UNICODE));
}
$brut = base64_decode($m[1], true);
if ($brut === false || strlen($brut) < 100) {
    exit(json_encode(['ok'=>false,'error'=>'Image illisible'], JSON_UNESCAPED_UNICODE));
}
/* 2 Mo : au-delà, c'est une photo, pas une signature — et elle alourdirait chaque acte. */
if (strlen($brut) > 2 * 1024 * 1024) {
    exit(json_encode(['ok'=>false,'error'=>'Image trop lourde (2 Mo maximum)'], JSON_UNESCAPED_UNICODE));
}
$info = @getimagesizefromstring($brut);
if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG) {
    exit(json_encode(['ok'=>false,'error'=>'Ce fichier n\'est pas une image PNG'], JSON_UNESCAPED_UNICODE));
}
[$larg, $haut] = $info;

$userId = function_exists('current_user_id') ? (int)current_user_id() : 0;
$socId  = (int)($_SESSION['id_societe'] ?? 0);
if ($type === 'cachet') {
    if ($socId <= 0) exit(json_encode(['ok'=>false,'error'=>'Aucune société rattachée à votre compte'], JSON_UNESCAPED_UNICODE));
    $propType = 'societe'; $propId = $socId;
} else {
    if ($userId <= 0) exit(json_encode(['ok'=>false,'error'=>'Utilisateur inconnu'], JSON_UNESCAPED_UNICODE));
    $propType = 'user';    $propId = $userId;
}

$ip = function_exists('client_ip') ? (string)client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');

try {
    $pdo->beginTransaction();
    // L'ancienne version est DÉSACTIVÉE, jamais supprimée.
    $pdo->prepare("UPDATE signature_specimens SET actif = 0
                    WHERE proprietaire_type = ? AND proprietaire_id = ? AND type = ? AND actif = 1")
        ->execute([$propType, $propId, $type]);
    $pdo->prepare("INSERT INTO signature_specimens
        (proprietaire_type, proprietaire_id, type, image_data, largeur_px, hauteur_px, actif, cree_par, ip)
        VALUES (?,?,?,?,?,?,1,?,?)")
        ->execute([$propType, $propId, $type, trim($img), (int)$larg, (int)$haut, $userId ?: null, $ip ?: null]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[signature_specimen_save] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>'Enregistrement impossible : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

$LBL = ['signature'=>'Signature', 'paraphe'=>'Paraphe', 'cachet'=>'Cachet de la société'];
echo json_encode([
    'ok'      => true,
    'id'      => $id,
    'message' => $LBL[$type] . ' enregistré' . ($type === 'cachet' ? '' : 'e') . ' (' . $larg . '×' . $haut . ' px).',
], JSON_UNESCAPED_UNICODE);
