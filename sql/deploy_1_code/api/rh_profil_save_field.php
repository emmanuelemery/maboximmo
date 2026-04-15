<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_helpers.php';
require_login();
verify_csrf_any();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$meId   = current_user_id();
$roleId = current_role_id();
$data   = json_decode(file_get_contents('php://input'), true) ?? [];

$targetId = (int)($data['user_id'] ?? 0);
if ($targetId <= 0) $targetId = $meId;

if ($targetId !== $meId && $roleId !== 1) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Accès refusé']); exit;
}

$allowedFields = [
    'telephone','telephone_pro','adresse','adresse2','code_postal','ville','pays',
    'date_naissance','lieu_naissance','nationalite','num_secu','civilite',
    'permis_conduire','vehicule_nom','vehicule_type','vehicule_puissance_fiscale','vehicule_immat',
    'indemnite_km','contact_urgence_nom','contact_urgence_tel','bio_courte','couleur',
];
if ($roleId === 1) {
    $allowedFields = array_merge($allowedFields, [
        'fonction','type_contrat','temps_travail','date_entree','date_sortie',
        'iban','bic','notes_rh','id_agence','id_societe',
    ]);
}
function color_is_too_light(string $hex): bool {
    if (!preg_match('/^#[0-9a-f]{6}$/i', $hex)) return true;
    $hex = strtolower($hex);
    $r = hexdec(substr($hex, 1, 2)) / 255;
    $g = hexdec(substr($hex, 3, 2)) / 255;
    $b = hexdec(substr($hex, 5, 2)) / 255;
    $srgb = [$r, $g, $b];
    $lin = array_map(function ($c) {
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }, $srgb);
    $lum = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    return $lum > 0.6;
}

$incoming = $data['fields'] ?? [];
if (!is_array($incoming) || empty($incoming)) {
    echo json_encode(['success'=>false,'error'=>'Aucun champ']); exit;
}

$bankFields = [];
if ($roleId === 1) {
    foreach (rh_bank_allowed_fields() as $f) {
        if (array_key_exists($f, $incoming)) {
            $bankFields[$f] = $incoming[$f];
        }
    }
    if ($bankFields && rh_table_exists($pdo, rh_bank_table_name())) {
        rh_bank_save($pdo, $targetId, $meId, $bankFields);
        foreach ($bankFields as $f => $_) {
            unset($incoming[$f]);
        }
    }
}

if (empty($incoming)) {
    echo json_encode(['success' => true]); exit;
}

// Ensure vehicle type column exists when needed
if (array_key_exists('vehicule_type', $incoming) && !rh_column_exists($pdo, 'users', 'vehicule_type')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN vehicule_type VARCHAR(120) NULL");
}

$sets = []; $params = [];
foreach ($incoming as $field => $value) {
    if (!in_array($field, $allowedFields, true)) continue;
    $val = is_string($value) ? trim($value) : $value;
    if ($field === 'couleur') {
        $val = strtolower((string)$val);
        if (color_is_too_light($val)) {
            echo json_encode(['success'=>false,'error'=>'Couleur trop claire']); exit;
        }
    }
    $sets[]   = "$field = ?";
    $params[] = ($val !== '' && $val !== null) ? $val : null;
}

if (!$sets) {
    echo json_encode(['success'=>false,'error'=>'Aucun champ valide']); exit;
}

$params[] = $targetId;
$pdo->prepare("UPDATE users SET " . implode(', ', $sets) . ", date_modification=NOW() WHERE id=?")->execute($params);

echo json_encode(['success' => true]);