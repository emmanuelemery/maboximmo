<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['error' => 'DB indisponible']); exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$search = '%' . $q . '%';

// Filtrer par société/agence selon le rôle
$roleId  = current_role_id();
$agenceId = current_agence_id();
$societeId = current_societe_id();

$sql = "SELECT i.id,
               i.nom_immeuble,
               COALESCE(i.reference_immeuble,'') as reference_immeuble,
               COALESCE(i.adresse_1,'') as adresse,
               COALESCE(i.code_postal,'') as code_postal,
               COALESCE(i.ville,'') as ville,
               i.latitude,
               i.longitude
        FROM immeubles i
        WHERE i.statut_immeuble = 'actif'
          AND (i.nom_immeuble LIKE ? OR i.adresse_1 LIKE ? OR i.ville LIKE ? OR i.code_postal LIKE ? OR i.reference_immeuble LIKE ?)";

$params = [$search, $search, $search, $search, $search];

if ($roleId === 3) {
    // User : restreindre à son agence
    $sql .= " AND i.id_agence = ?";
    $params[] = $agenceId;
} elseif ($roleId === 2) {
    // Manager : restreindre à sa société
    $sql .= " AND i.id_societe = ?";
    $params[] = $societeId;
}

$sql .= " ORDER BY i.nom_immeuble LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
