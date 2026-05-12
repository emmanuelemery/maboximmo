<?php
declare(strict_types=1);

/**
 * Ma Box Immo — COFFRE ACCES sécurisé
 * ====================================
 *
 * Helpers chiffrement AES-256-GCM + accès contrôlé + journalisation.
 *
 * Sécurité :
 *   - Clé maître dans config/coffre.php (gitignored, créée manuellement)
 *     define('COFFRE_MASTER_KEY', '<chaîne aléatoire 64 hex bytes>');
 *   - IV unique par enregistrement (12 bytes pour GCM)
 *   - tag d'authentification stocké, vérifié au déchiffrement
 *   - super admin (id_role=1) toujours autorisé
 *   - propriétaire toujours autorisé
 *   - shared_with_users (JSON array) pour partage explicite
 *
 * INTERDICTION : ne jamais stocker un mot de passe en GED (table ged_documents).
 */

if (!function_exists('coffre_pdo')) {
    function coffre_pdo(): PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Coffre : PDO indisponible');
        }
        return $pdo;
    }
}

/**
 * Charge la clé maître. Cherche d'abord config/coffre.php (hors webroot idéalement
 * mais public_html/config/ acceptable si .htaccess deny + permissions strictes).
 *
 * @return string Clé maître (32 bytes binaire)
 * @throws RuntimeException si manquante / invalide
 */
function coffre_master_key(): string
{
    if (!defined('COFFRE_MASTER_KEY')) {
        $candidates = [
            __DIR__ . '/../config/coffre.php',
            dirname(__DIR__, 2) . '/u630423897/coffre.php',
            '/home/u630423897/coffre.php',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) { require_once $c; break; }
        }
    }
    if (!defined('COFFRE_MASTER_KEY')) {
        throw new RuntimeException('COFFRE_MASTER_KEY non définie. Crée config/coffre.php avec define("COFFRE_MASTER_KEY", "<64 hex chars>").');
    }
    $hex = (string)constant('COFFRE_MASTER_KEY');
    if (preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
        return hex2bin($hex);
    }
    // Sinon : on dérive via SHA-256 d'une passphrase
    return hash('sha256', $hex, true);
}

/**
 * Chiffre une valeur en clair via AES-256-GCM.
 *
 * @return array ['cipher'=>string, 'iv'=>binary, 'tag'=>binary, 'data'=>binary]
 */
function coffre_encrypt(string $plaintext): array
{
    $key = coffre_master_key();
    $iv = random_bytes(12); // 96 bits standard pour GCM
    $tag = '';
    $data = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($data === false) {
        throw new RuntimeException('Échec chiffrement : ' . openssl_error_string());
    }
    return ['cipher' => 'aes-256-gcm', 'iv' => $iv, 'tag' => $tag, 'data' => $data];
}

/**
 * Déchiffre une valeur. Vérifie l'intégrité via le tag GCM.
 *
 * @throws RuntimeException si tag invalide (tentative de tampering)
 */
function coffre_decrypt(string $data, string $iv, string $tag, string $cipher = 'aes-256-gcm'): string
{
    $key = coffre_master_key();
    $plain = openssl_decrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Déchiffrement échoué (tag GCM invalide ou clé erronée).');
    }
    return $plain;
}

/**
 * Vérifie qu'un user a le droit de voir/modifier un credential.
 * - Super admin (id_role=1) : toujours OK
 * - Owner : toujours OK
 * - Listé dans shared_with_users : OK
 */
function coffre_user_can_access(array $credential, ?int $userId = null, ?int $roleId = null): bool
{
    $userId = $userId ?? (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? 0);
    $roleId = $roleId ?? (int)($_SESSION['id_role'] ?? 0);
    if ($roleId === 1) return true;
    if ((int)$credential['owner_user_id'] === $userId) return true;
    $shared = $credential['shared_with_users'] ?? null;
    if (is_string($shared)) $shared = json_decode($shared, true);
    if (is_array($shared) && in_array($userId, array_map('intval', $shared), true)) return true;
    return false;
}

/**
 * Logue un accès dans secure_credentials_access_log.
 */
function coffre_log(int $credentialId, string $action, bool $success = true, ?string $errMsg = null): void
{
    try {
        coffre_pdo()->prepare("
            INSERT INTO secure_credentials_access_log
                (credential_id, actor_user_id, actor_role_code, action, ip_address, user_agent, success, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $credentialId > 0 ? $credentialId : null,
            (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? 0),
            (string)($_SESSION['role_code'] ?? $_SESSION['id_role'] ?? ''),
            $action,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $success ? 1 : 0,
            $errMsg !== null ? substr($errMsg, 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[coffre_log] ' . $e->getMessage());
    }
}

/**
 * Génère un UUID v4 (utilise la même implémentation que ged_functions si dispo).
 */
function coffre_uuid(): string
{
    if (function_exists('ged_generate_uuid')) return ged_generate_uuid();
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ─── CRUD ──────────────────────────────────────────────────────────────

function coffre_create(string $label, string $category, string $plaintext, ?string $hint = null, array $sharedWith = []): int
{
    $enc = coffre_encrypt($plaintext);
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? 0);
    $tenantId = (int)($_SESSION['id_societe'] ?? 0) ?: null;
    $uuid = coffre_uuid();
    $sharedJson = !empty($sharedWith) ? json_encode(array_map('intval', $sharedWith)) : null;

    $st = coffre_pdo()->prepare("
        INSERT INTO secure_credentials
            (uuid, tenant_id, label, category, hint, encrypted_value, iv, auth_tag, cipher,
             owner_user_id, shared_with_users)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->bindValue(1, $uuid);
    $st->bindValue(2, $tenantId);
    $st->bindValue(3, $label);
    $st->bindValue(4, $category);
    $st->bindValue(5, $hint);
    $st->bindValue(6, $enc['data'], PDO::PARAM_LOB);
    $st->bindValue(7, $enc['iv'],   PDO::PARAM_LOB);
    $st->bindValue(8, $enc['tag'],  PDO::PARAM_LOB);
    $st->bindValue(9, $enc['cipher']);
    $st->bindValue(10, $userId);
    $st->bindValue(11, $sharedJson);
    $st->execute();
    $id = (int)coffre_pdo()->lastInsertId();
    coffre_log($id, 'create', true);
    return $id;
}

function coffre_get(int $id): ?array
{
    $st = coffre_pdo()->prepare("SELECT * FROM secure_credentials WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function coffre_reveal(int $id): string
{
    $row = coffre_get($id);
    if (!$row) {
        coffre_log($id, 'reveal', false, 'Credential introuvable');
        throw new RuntimeException('Credential introuvable');
    }
    if (!coffre_user_can_access($row)) {
        coffre_log($id, 'reveal', false, 'Accès refusé');
        throw new RuntimeException('Accès refusé');
    }
    try {
        $plain = coffre_decrypt($row['encrypted_value'], $row['iv'], $row['auth_tag'], $row['cipher']);
        coffre_pdo()->prepare("UPDATE secure_credentials SET last_accessed_at = NOW() WHERE id = ?")->execute([$id]);
        coffre_log($id, 'reveal', true);
        return $plain;
    } catch (Throwable $e) {
        coffre_log($id, 'reveal', false, $e->getMessage());
        throw $e;
    }
}

function coffre_list(array $filters = []): array
{
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? 0);
    $roleId = (int)($_SESSION['id_role'] ?? 0);

    $where = "deleted_at IS NULL";
    $params = [];

    if ($roleId !== 1) {
        // Limite aux credentials owned ou shared
        $where .= " AND (owner_user_id = ? OR JSON_CONTAINS(shared_with_users, ?, '$'))";
        $params[] = $userId;
        $params[] = (string)$userId;
    }
    if (!empty($filters['category'])) {
        $where .= " AND category = ?";
        $params[] = (string)$filters['category'];
    }
    if (!empty($filters['search'])) {
        $where .= " AND (label LIKE ? OR hint LIKE ?)";
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$filters['search']) . '%';
        $params[] = $like; $params[] = $like;
    }
    $sql = "SELECT id, uuid, label, category, hint, owner_user_id, shared_with_users,
                   created_at, last_accessed_at
            FROM secure_credentials
            WHERE {$where}
            ORDER BY label ASC LIMIT 500";
    $st = coffre_pdo()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    coffre_log(0, 'list', true);
    return $rows;
}

function coffre_update(int $id, array $fields): bool
{
    $row = coffre_get($id);
    if (!$row) throw new RuntimeException('Credential introuvable');
    if (!coffre_user_can_access($row)) {
        coffre_log($id, 'update', false, 'Accès refusé');
        throw new RuntimeException('Accès refusé');
    }
    $sets = [];
    $params = [];
    if (array_key_exists('label', $fields))    { $sets[] = '`label` = ?';    $params[] = (string)$fields['label']; }
    if (array_key_exists('category', $fields)) { $sets[] = '`category` = ?'; $params[] = (string)$fields['category']; }
    if (array_key_exists('hint', $fields))     { $sets[] = '`hint` = ?';     $params[] = (string)$fields['hint']; }
    if (array_key_exists('shared_with_users', $fields)) {
        $sets[] = '`shared_with_users` = ?';
        $params[] = !empty($fields['shared_with_users']) ? json_encode(array_map('intval', $fields['shared_with_users'])) : null;
    }
    // Re-encryption si valeur fournie
    if (array_key_exists('plaintext', $fields) && $fields['plaintext'] !== null && $fields['plaintext'] !== '') {
        $enc = coffre_encrypt((string)$fields['plaintext']);
        $sets[] = '`encrypted_value` = ?'; $params[] = $enc['data'];
        $sets[] = '`iv` = ?';              $params[] = $enc['iv'];
        $sets[] = '`auth_tag` = ?';        $params[] = $enc['tag'];
        $sets[] = '`cipher` = ?';          $params[] = $enc['cipher'];
    }
    if (!$sets) return false;
    $params[] = $id;
    $sql = "UPDATE secure_credentials SET " . implode(', ', $sets) . " WHERE id = ?";
    $st = coffre_pdo()->prepare($sql);
    // Bind avec PDO::PARAM_LOB pour les blobs
    $idx = 1;
    foreach ($sets as $s) {
        $val = array_shift($params);
        if (in_array(trim(explode('=', $s)[0]), ['`encrypted_value`', '`iv`', '`auth_tag`'], true)) {
            $st->bindValue($idx, $val, PDO::PARAM_LOB);
        } else {
            $st->bindValue($idx, $val);
        }
        $idx++;
    }
    $st->bindValue($idx, $id);
    $st->execute();
    coffre_log($id, 'update', true);
    return true;
}

function coffre_delete(int $id): bool
{
    $row = coffre_get($id);
    if (!$row) throw new RuntimeException('Credential introuvable');
    if (!coffre_user_can_access($row)) {
        coffre_log($id, 'delete', false, 'Accès refusé');
        throw new RuntimeException('Accès refusé');
    }
    coffre_pdo()->prepare("UPDATE secure_credentials SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    coffre_log($id, 'delete', true);
    return true;
}
