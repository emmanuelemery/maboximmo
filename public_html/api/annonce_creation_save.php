<?php
/**
 * POST /api/annonce_creation_save.php
 *
 * Endpoint autosave mono-champ pour annonce_creation.php.
 * Même principe que bien_creation_save.php : blacklist + SHOW COLUMNS + cast.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'POST requis']));
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = $GLOBALS['pdo'];
    $societeId = (int)($_SESSION['id_societe'] ?? 0);

    $annonceId = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
    $field     = trim((string)($_POST['field'] ?? ''));
    $value     = $_POST['value'] ?? '';

    if ($annonceId <= 0) exit(json_encode(['ok' => false, 'error' => 'id manquant']));
    if ($field === '')   exit(json_encode(['ok' => false, 'error' => 'field manquant']));

    $blacklist = [
        'id',
        'date_creation', 'date_modification', 'date_suppression',
        'id_bien', 'id_societe', 'id_agence',
        'deleted_at', 'created_at', 'updated_at',
    ];
    if (in_array($field, $blacklist, true)) {
        exit(json_encode(['ok' => false, 'error' => 'champ non modifiable']));
    }

    // Scope check
    $stmt = $pdo->prepare("SELECT id_societe FROM annonces WHERE id = ? LIMIT 1");
    $stmt->execute([$annonceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'annonce introuvable']));
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'hors de votre société']));
    }

    // Column existence + type
    $stmtC = $pdo->prepare("SHOW COLUMNS FROM annonces LIKE ?");
    $stmtC->execute([$field]);
    $col = $stmtC->fetch(PDO::FETCH_ASSOC);
    if (!$col) exit(json_encode(['ok' => false, 'error' => 'colonne inconnue']));

    $type     = strtolower((string)$col['Type']);
    $nullable = ($col['Null'] === 'YES');
    $str      = is_string($value) ? trim($value) : (string)$value;

    $casted = null;
    if ($str === '') {
        $casted = $nullable ? null : '';
    } elseif (str_starts_with($type, 'tinyint(1)')) {
        $casted = (int)((int)$str > 0 || $str === '1' || strtolower($str) === 'true' || strtolower($str) === 'oui');
    } elseif (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $type)) {
        $casted = (int)$str;
    } elseif (preg_match('/^(decimal|float|double|numeric)/', $type)) {
        $casted = (float)str_replace(',', '.', $str);
    } elseif ($type === 'date') {
        $casted = preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) ? $str : ($nullable ? null : '0000-00-00');
    } elseif (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
        $s = str_replace('T', ' ', $str);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $s)) {
            $casted = substr($s, 0, 19);
            if (strlen($casted) === 16) $casted .= ':00';
        } else {
            $casted = null;
        }
    } elseif (preg_match('/^enum\((.*)\)$/', $type, $m)) {
        $opts = array_map(fn($o) => trim($o, "' "), explode(',', $m[1]));
        $casted = in_array($str, $opts, true) ? $str : ($nullable ? null : $opts[0]);
    } else {
        $casted = $str;
    }

    $sql = "UPDATE annonces SET `{$field}` = :v, date_modification = NOW() WHERE id = :id";
    $up = $pdo->prepare($sql);
    $up->bindValue(':v', $casted, $casted === null ? PDO::PARAM_NULL : (is_int($casted) ? PDO::PARAM_INT : PDO::PARAM_STR));
    $up->bindValue(':id', $annonceId, PDO::PARAM_INT);
    $up->execute();

    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s'), 'value' => $casted], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[annonce_creation_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
