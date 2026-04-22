<?php
// api/bien_validate.php — Valide ou dé-valide un bien (passage actif/brouillon)
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_validator.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);

$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$action = (string)($_POST['action'] ?? 'validate'); // 'validate' | 'invalidate' | 'check'

if ($bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_bien manquant']));
}

// Scope société
try {
    $st = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ?");
    $st->execute([$bienId]);
    $bienSoc = (int)($st->fetchColumn() ?: 0);
    if (!$isSuperAdmin && $societeId > 0 && $bienSoc !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Bien hors de votre société']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérification scope']));
}

if ($action === 'check') {
    // Retourne juste la checklist sans modifier le statut
    $r = bien_validator_check($pdo, $bienId);
    exit(json_encode([
        'ok' => true,
        'checks' => $r['checks'],
        'missing_required' => $r['missing_required'],
        'validation_ok' => $r['ok'],
        'exempt_dpe_surface' => $r['exempt_dpe_surface'],
        'statut_bien' => (string)($r['bien']['statut_bien'] ?? ''),
    ]));
}

if ($action === 'invalidate') {
    $r = bien_validator_invalidate($pdo, $bienId);
    exit(json_encode($r + ['statut_bien' => 'brouillon']));
}

// Défaut : validate
$r = bien_validator_validate($pdo, $bienId);
if ($r['ok']) {
    exit(json_encode(['ok' => true, 'statut_bien' => 'actif']));
}
exit(json_encode([
    'ok' => false,
    'error' => $r['error'] ?? 'Validation refusée',
    'missing' => $r['missing'] ?? [],
]));
