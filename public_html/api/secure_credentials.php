<?php
declare(strict_types=1);

/**
 * API COFFRE ACCES — Réservé super admin uniquement (V1).
 *
 * Actions :
 *   - list   : GET → liste filtrée (pas de valeur déchiffrée)
 *   - reveal : POST id → renvoie la valeur en clair (LOG OBLIGATOIRE)
 *   - create : POST label/category/plaintext/hint/shared_with[]
 *   - update : POST id + champs
 *   - delete : POST id (soft delete)
 *
 * SÉCURITÉ :
 *   - role_id = 1 obligatoire
 *   - chaque action est journalisée dans secure_credentials_access_log
 *   - reveal renvoie la valeur EN CLAIR uniquement à l'utilisateur autorisé
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/secure_credentials_functions.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé super admin']);
    exit;
}

function coffre_api_respond(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
        case 'list':
            $filters = [
                'category' => (string)($_GET['category'] ?? ''),
                'search'   => (string)($_GET['search']   ?? ''),
            ];
            $items = coffre_list($filters);
            coffre_api_respond(true, ['items' => $items]);
            break;

        case 'reveal':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id requis');
            $plain = coffre_reveal($id);
            coffre_api_respond(true, ['plaintext' => $plain]);
            break;

        case 'create':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $label = trim((string)($_POST['label'] ?? ''));
            $cat   = (string)($_POST['category'] ?? 'autre');
            $val   = (string)($_POST['plaintext'] ?? '');
            $hint  = trim((string)($_POST['hint'] ?? ''));
            $shared = $_POST['shared_with'] ?? [];
            if (!is_array($shared)) $shared = [];
            if ($label === '' || $val === '') throw new RuntimeException('label et plaintext requis');
            $id = coffre_create($label, $cat, $val, $hint !== '' ? $hint : null, $shared);
            coffre_api_respond(true, ['id' => $id]);
            break;

        case 'update':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id requis');
            $fields = [];
            foreach (['label', 'category', 'hint', 'plaintext'] as $k) {
                if (array_key_exists($k, $_POST)) $fields[$k] = (string)$_POST[$k];
            }
            if (isset($_POST['shared_with']) && is_array($_POST['shared_with'])) {
                $fields['shared_with_users'] = $_POST['shared_with'];
            }
            $ok = coffre_update($id, $fields);
            coffre_api_respond($ok);
            break;

        case 'delete':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id requis');
            coffre_delete($id);
            coffre_api_respond(true);
            break;

        case 'logs':
            // Lecture journal (pour le super admin uniquement)
            $st = coffre_pdo()->prepare("
                SELECT l.*, c.label AS credential_label
                FROM secure_credentials_access_log l
                LEFT JOIN secure_credentials c ON c.id = l.credential_id
                ORDER BY l.id DESC LIMIT 200
            ");
            $st->execute();
            coffre_api_respond(true, ['logs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            http_response_code(400);
            coffre_api_respond(false, ['error' => "Action inconnue : {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    coffre_api_respond(false, ['error' => $e->getMessage()]);
}
