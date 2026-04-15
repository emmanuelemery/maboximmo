<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expirée, veuillez vous reconnecter.']);
    exit;
}
if (!is_super_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès réservé au Super Admin.']);
    exit;
}

// CSRF pour les opérations d'écriture
if (in_array($_GET['action'] ?? '', ['update', 'delete', 'insert'], true)) {
    verify_csrf_any();
}

// Whitelist des tables accessibles
$allowedTables = [
    // RH
    'users', 'roles', 'societes', 'agences',
    'conges', 'conges_soldes', 'mois_clos',
    'salaires', 'salaires_documents', 'mail_templates',
    // Syndic
    'immeubles', 'biens', 'types_bien',
    // Agency
    'registres_acces', 'agence_documents',
    'subscription_plans', 'user_subscriptions',
    // Système
    'cache_listings', 'url_redirects', 'dev_organisation', 'email_verifications',
    'sso_tokens', 'agence_documents'
];

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';
    $table = $_GET['table'] ?? '';

    // Valider le nom de la table
    if (!in_array($table, $allowedTables, true)) {
        throw new Exception('Table non autorisée');
    }

    switch ($action) {
        case 'rows':
            handleGetRows($pdo, $table);
            break;
        case 'columns':
            handleGetColumns($pdo, $table);
            break;
        case 'update':
            handleUpdate($pdo, $table);
            break;
        case 'delete':
            handleDelete($pdo, $table);
            break;
        case 'insert':
            handleInsert($pdo, $table);
            break;
        default:
            throw new Exception('Action non reconnue');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGetRows(PDO $pdo, string $table): void {
    // Assertion défensive (table déjà validée par whitelist en amont)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new \Exception('Nom de table invalide');
    }

    $offset = (int)($_GET['offset'] ?? 0);
    $limit = 50;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
    $totalRows = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $sql = "SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'rows' => $rows,
        'total' => $totalRows,
        'offset' => $offset,
        'limit' => $limit,
        'hasMore' => ($offset + $limit) < $totalRows
    ]);
}

function handleGetColumns(PDO $pdo, string $table): void {
    $sql = "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['columns' => $columns]);
}

function handleUpdate(PDO $pdo, string $table): void {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['id'])) {
        throw new Exception('ID requis pour la mise à jour');
    }

    $id = $data['id'];
    unset($data['id']);

    if (empty($data)) {
        throw new Exception('Aucune donnée à mettre à jour');
    }

    // Construire la requête UPDATE
    $setParts = [];
    $params = [];

    foreach ($data as $column => $value) {
        // Valider le nom de la colonne (simple alphanumérique + underscore)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new Exception("Nom de colonne invalide: $column");
        }
        $setParts[] = "`$column` = ?";
        $params[] = $value;
    }

    $params[] = $id;

    $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Enregistrement mis à jour',
        'affected' => $stmt->rowCount()
    ]);
}

function handleDelete(PDO $pdo, string $table): void {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['id'])) {
        throw new Exception('ID requis pour la suppression');
    }

    $sql = "DELETE FROM `$table` WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Enregistrement supprimé',
        'affected' => $stmt->rowCount()
    ]);
}

function handleInsert(PDO $pdo, string $table): void {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data)) {
        throw new Exception('Données requises pour l\'insertion');
    }

    $columns = [];
    $values = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new Exception("Nom de colonne invalide: $column");
        }
        $columns[] = "`$column`";
        $values[] = "?";
        $params[] = $value;
    }

    $sql = "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Enregistrement créé',
        'lastInsertId' => $pdo->lastInsertId()
    ]);
}
?>
