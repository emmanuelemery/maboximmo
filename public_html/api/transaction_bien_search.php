<?php
// api/transaction_bien_search.php — Recherche bien pour modal "Ajouter au tableau" (V0)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q           = trim((string)($_GET['q'] ?? ''));
$proprioId   = (int)($_GET['proprietaire_id'] ?? 0);

// Si pas de propriétaire ET recherche < 3 chars → rien
if ($proprioId <= 0 && mb_strlen($q) < 3) {
    echo json_encode(['items' => []]); exit;
}

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSociete    = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

// On exclut les biens déjà au tableau (type_commercialisation déjà renseigné)
$where  = [
    '(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))',
    '(b.type_commercialisation IS NULL OR b.type_commercialisation = "")',
];
$params = [];

if ($proprioId > 0) {
    $where[] = 'b.id_proprietaire = :pid';
    $params[':pid'] = $proprioId;
}

if (mb_strlen($q) >= 3) {
    $where[] = '(b.reference_bien LIKE :q OR b.designation LIKE :q OR b.ville LIKE :q OR b.adresse_1 LIKE :q OR b.code_postal LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if (!$isSuperAdmin && $idSociete !== null) {
    $where[] = '(b.id_societe = :s OR b.id_societe IS NULL)';
    $params[':s'] = $idSociete;
}

// Adresse : un lot hérite de l'adresse de son immeuble si b.adresse_1 est vide
$sql = 'SELECT b.id, b.reference_bien, b.designation, b.type_commercialisation,
               COALESCE(NULLIF(b.adresse_1, ""), i.adresse_1) AS adresse_1,
               COALESCE(NULLIF(b.code_postal, ""), i.code_postal) AS code_postal,
               COALESCE(NULLIF(b.ville, ""), i.ville) AS ville,
               b.numero_lot, b.etage,
               (SELECT COUNT(*) FROM annonces a
                 WHERE a.id_bien = b.id AND (a.statut IS NULL OR a.statut <> "archivee")) AS nb_annonces
        FROM biens b
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.date_modification DESC LIMIT 30';

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $items = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'id'                     => (int)$r['id'],
            'reference_bien'         => $r['reference_bien'],
            'designation'            => $r['designation'],
            'ville'                  => $r['ville'],
            'adresse_1'              => $r['adresse_1'],
            'code_postal'            => $r['code_postal'],
            'numero_lot'             => $r['numero_lot'],
            'etage'                  => $r['etage'],
            'type_commercialisation' => $r['type_commercialisation'],
            'has_active_annonce'     => (int)$r['nb_annonces'] > 0,
        ];
    }
    echo json_encode(['items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['items' => [], 'error' => $e->getMessage()]);
}
