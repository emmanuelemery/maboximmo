<?php
declare(strict_types=1);
/**
 * p/document_depot_file.php — Servage PUBLIC d'un fichier déposé (par token).
 *
 * Permet au déposant (et au staff) de REVOIR une pièce déjà déposée depuis la
 * page de dépôt, sans authentification GED. Sécurité : token de la demande +
 * la pièce doit appartenir à cette demande et être « reçue ».
 *
 * URL : /p/document_depot_file.php?t=<token>&item=<id>[&dl=1]
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/document_requests.php';

$pdo   = $GLOBALS['pdo'];
$token = (string)($_GET['t'] ?? '');
$itemId = (int)($_GET['item'] ?? 0);
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token) || $itemId <= 0) { http_response_code(400); exit('Requête invalide.'); }

$req = dr_get_by_token($pdo, $token);
if (!$req || !dr_is_valid($req)) { http_response_code(403); exit('Lien invalide ou expiré.'); }

// Gate email : si la demande est verrouillée, exiger la session de dépôt (sauf staff).
$staffView = !empty($_SESSION['user_id']) || !empty($_SESSION['id_user']);
$sessKey   = 'dr_auth_' . substr($token, 0, 16);
if ((int)$req['require_email_gate'] === 1 && !$staffView && empty($_SESSION[$sessKey])) {
    http_response_code(403); exit('Accès non confirmé.');
}

$item = null;
foreach (dr_items($pdo, (int)$req['id']) as $i) { if ((int)$i['id'] === $itemId) { $item = $i; break; } }
if (!$item || ($item['status'] ?? '') !== 'recu') { http_response_code(404); exit('Pièce non disponible.'); }

$path = dr_item_file_path($pdo, $item);
if (!$path || !is_file($path)) { http_response_code(404); exit('Fichier introuvable.'); }

$mimes = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip',
];
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = $mimes[$ext] ?? 'application/octet-stream';
$disp = !empty($_GET['dl']) ? 'attachment' : 'inline';
$dlName = (string)($item['original_name'] ?? ('piece_' . $itemId . '.' . $ext));
if ($dlName === '' || $dlName === '[déjà présent en GED]') $dlName = 'piece_' . $itemId . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . preg_replace('/[^\w.\- ]+/', '_', $dlName) . '"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
