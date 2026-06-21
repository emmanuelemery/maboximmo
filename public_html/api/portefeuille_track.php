<?php
// api/portefeuille_track.php — Beacon de suivi (sans login) : enregistre la
// consultation d'un bien par le destinataire d'un portefeuille, via le jeton.
// Appelé en fond par p.php (fetch) à l'ouverture de la fiche d'un bien.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/portefeuille_track.php';
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

header('Content-Type: application/json; charset=utf-8');

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$idBien = (int)($_GET['bien'] ?? 0);
if (strlen($token) < 20 || $idBien <= 0) { http_response_code(204); exit; }

try {
    $st = $pdo->prepare("SELECT id, id_portefeuille, email_destinataire, snapshot_json, actif, date_expiration
                         FROM portefeuille_envois WHERE token = ? LIMIT 1");
    $st->execute([$token]);
    $envoi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$envoi || (int)$envoi['actif'] !== 1) { http_response_code(204); exit; }
    if (!empty($envoi['date_expiration']) && strtotime((string)$envoi['date_expiration']) < time()) { http_response_code(204); exit; }

    // Le bien doit appartenir au portefeuille de cet envoi (anti-injection d'id).
    $snap = json_decode((string)$envoi['snapshot_json'], true) ?: [];
    $ids  = array_map(fn($l) => (int)($l['id_bien'] ?? 0), $snap['lignes'] ?? []);
    if (!in_array($idBien, $ids, true)) { http_response_code(204); exit; }

    pf_track($pdo, $envoi, 'view_bien', ['id_bien' => $idBien]);
} catch (Throwable) { /* silencieux */ }

http_response_code(204);
