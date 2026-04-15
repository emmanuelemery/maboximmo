<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);

$nom       = trim((string)($_GET['nom']       ?? ''));
$prenom    = trim((string)($_GET['prenom']    ?? ''));
$email     = trim((string)($_GET['email']     ?? ''));
$telephone = preg_replace('/[\s.\-]/', '', trim((string)($_GET['telephone'] ?? '')));

if ($nom === '' && $email === '' && $telephone === '') {
    exit(json_encode(['ok' => true, 'resultats' => []]));
}

try {
    $where  = [];
    $params = [];

    if ($email !== '') {
        $where[] = 'email = ?';
        $params[] = $email;
    }
    if ($telephone !== '') {
        $tel = $telephone;
        $where[] = '(REPLACE(REPLACE(REPLACE(telephone,\' \',\'\'),\'.\',\'\'),\'-\',\'\') = ? OR REPLACE(REPLACE(REPLACE(telephone_2,\' \',\'\'),\'.\',\'\'),\'-\',\'\') = ?)';
        $params[] = $tel;
        $params[] = $tel;
    }
    if ($nom !== '') {
        $where[] = 'nom LIKE ?';
        $params[] = '%' . $nom . '%';
    }

    // Filtre société/agence
    $scopeClause = '';
    if ($agenceId > 0) {
        $scopeClause = ' AND id_agence = ' . $agenceId;
    } elseif ($societeId > 0) {
        // Récupère les agences de la société
        $agStmt = $pdo->prepare('SELECT id FROM agences WHERE id_societe = ?');
        $agStmt->execute([$societeId]);
        $agIds  = $agStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($agIds)) {
            $scopeClause = ' AND id_agence IN (' . implode(',', array_map('intval', $agIds)) . ')';
        }
    }

    $sql = 'SELECT id, civilite, nom, prenom, societe, email, telephone, telephone_2, adresse_1, ville, code_postal
            FROM proprietaires
            WHERE (' . implode(' OR ', $where) . ')' . $scopeClause . '
            ORDER BY nom, prenom LIMIT 10';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    exit(json_encode(['ok' => true, 'resultats' => $resultats], JSON_UNESCAPED_UNICODE));

} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
