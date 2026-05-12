<?php
/**
 * Script temporaire pour lier les 60 relevés CREDIT MUTUEL à MIONS
 *
 * Usage:
 * 1. Appeler find_mions.php pour voir les immeubles MIONS
 * 2. Appeler ce script avec POST ?immeuble_id=XX
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';

header('Content-Type: application/json; charset=utf-8');

// Vérifier authorization
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Non authentifié']);
    exit;
}

$pdo = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isAdmin = in_array($roleId, [1, 7, 8], true);

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Accès réservé aux administrateurs']);
    exit;
}

// Récupérer immeuble_id
$immeubleId = isset($_GET['immeuble_id']) ? (int)$_GET['immeuble_id'] : 0;
if ($immeubleId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'immeuble_id manquant ou invalide']);
    exit;
}

// Vérifier que l'immeuble existe
$st = $pdo->prepare("SELECT id, reference_immeuble, nom_immeuble, ville FROM immeubles WHERE id = ?");
$st->execute([$immeubleId]);
$immeuble = $st->fetch(PDO::FETCH_ASSOC);

if (!$immeuble) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Immeuble introuvable']);
    exit;
}

// Vérifier combien de relevés CREDIT MUTUEL sont sans immeuble
$st = $pdo->prepare("
    SELECT id, fichier_original, banque_detectee, periode_annee, periode_mois, statut
    FROM ged_import_releves_items
    WHERE statut IN ('extracted', 'analyzed', 'uncertain', 'recognized')
    AND banque_detectee LIKE '%CREDIT MUTUEL%'
    AND id_immeuble IS NULL
    ORDER BY id ASC
");
$st->execute();
$items_to_link = $st->fetchAll(PDO::FETCH_ASSOC);

if (empty($items_to_link)) {
    echo json_encode([
        'ok' => true,
        'message' => 'Aucun relevé CREDIT MUTUEL à lier',
        'linked_count' => 0,
        'immeuble' => $immeuble
    ]);
    exit;
}

// Lier tous les relevés CREDIT MUTUEL à cet immeuble
$count = 0;
$upd = $pdo->prepare("
    UPDATE ged_import_releves_items
    SET id_immeuble = :imm,
        statut = CASE
            WHEN statut IN ('imported','rejected') THEN statut
            ELSE 'recognized'
        END
    WHERE banque_detectee LIKE '%CREDIT MUTUEL%'
    AND id_immeuble IS NULL
    AND statut IN ('extracted', 'analyzed', 'uncertain', 'recognized')
");

$upd->execute([':imm' => $immeubleId]);
$count = $upd->rowCount();

// Retourner les résultats
echo json_encode([
    'ok' => true,
    'message' => $count . ' relevés liés à ' . $immeuble['nom_immeuble'],
    'linked_count' => $count,
    'immeuble' => $immeuble,
    'items_linked' => array_map(function($item) use ($immeubleId) {
        return [
            'id' => $item['id'],
            'fichier' => $item['fichier_original'],
            'banque' => $item['banque_detectee'],
            'periode' => $item['periode_annee'] . '-' . str_pad($item['periode_mois'], 2, '0', STR_PAD_LEFT),
            'ancien_statut' => $item['statut'],
            'nouveau_statut' => 'recognized',
            'id_immeuble_assigné' => $immeubleId
        ];
    }, array_slice($items_to_link, 0, 5)) // Montrer les 5 premiers pour validation
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
