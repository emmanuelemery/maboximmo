<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['ok'=>false,'error'=>'DB indisponible']); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'error'=>'id manquant']); exit; }

$stmt = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, adresse_2, code_postal, ville, pays,
    type_immeuble, statut_immeuble, latitude, longitude,
    nb_lots, nb_niveaux, annee_construction, mode_gestion,
    syndic_actuel, nb_stationnements, presence_ascenseur, commentaire,
    date_creation, date_modification
    FROM immeubles WHERE id = ?");
$stmt->execute([$id]);
$immeuble = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$immeuble) { echo json_encode(['ok'=>false,'error'=>'immeuble introuvable']); exit; }

echo json_encode(['ok'=>true, 'immeuble'=>$immeuble], JSON_UNESCAPED_UNICODE);
