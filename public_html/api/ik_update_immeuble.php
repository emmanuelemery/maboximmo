<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['ok'=>false,'error'=>'DB indisponible']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id   = (int)($data['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'error'=>'id manquant']); exit; }

$lat = isset($data['latitude'])  && $data['latitude']  !== '' ? (float)$data['latitude']  : null;
$lng = isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null;

$stmt = $pdo->prepare("UPDATE immeubles SET
    nom_immeuble       = ?,
    adresse_1          = ?,
    code_postal        = ?,
    ville              = ?,
    type_immeuble      = ?,
    statut_immeuble    = ?,
    latitude           = ?,
    longitude          = ?,
    nb_lots            = ?,
    nb_niveaux         = ?,
    annee_construction = ?,
    mode_gestion       = ?,
    syndic_actuel      = ?,
    nb_stationnements  = ?,
    commentaire        = ?,
    date_modification  = NOW()
    WHERE id = ?");

$stmt->execute([
    trim($data['nom_immeuble']   ?? ''),
    trim($data['adresse_1']      ?? ''),
    trim($data['code_postal']    ?? ''),
    trim($data['ville']          ?? ''),
    $data['type_immeuble']       ?: null,
    $data['statut_immeuble']     ?: 'actif',
    $lat,
    $lng,
    isset($data['nb_lots'])           && $data['nb_lots']           !== null ? (int)$data['nb_lots']           : null,
    isset($data['nb_niveaux'])        && $data['nb_niveaux']        !== null ? (int)$data['nb_niveaux']        : null,
    isset($data['annee_construction'])&& $data['annee_construction']!== null ? (int)$data['annee_construction']: null,
    $data['mode_gestion']        ?: null,
    trim($data['syndic_actuel']  ?? '') ?: null,
    isset($data['nb_stationnements']) && $data['nb_stationnements'] !== null ? (int)$data['nb_stationnements'] : null,
    trim($data['commentaire']    ?? '') ?: null,
    $id,
]);

echo json_encode(['ok'=>true]);
