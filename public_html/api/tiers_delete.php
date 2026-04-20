<?php
// api/tiers_delete.php — Suppression DÉFINITIVE d'un tiers (super admin uniquement)
// POST JSON : { id_tiers, confirm: 'DELETE' }
// Vérifie l'absence de biens / mandats liés avant de procéder.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo    = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);

// Hard-delete : super admin uniquement (role_id = 1)
if ($roleId !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Réservé à l\'administrateur société. Utilise l\'archivage.']));
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    exit(json_encode(['ok' => false, 'error' => 'JSON invalide']));
}

$idTiers = isset($body['id_tiers']) && ctype_digit((string)$body['id_tiers']) ? (int)$body['id_tiers'] : 0;
$confirm = (string)($body['confirm'] ?? '');
if ($idTiers <= 0 || $confirm !== 'DELETE') {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres invalides (confirm=DELETE requis)']));
}

try {
    // Vérifications anti-cascade : pas de biens actifs / mandats liés
    $checks = [];

    // Via proprietaires legacy
    $stP = $pdo->prepare("SELECT id FROM proprietaires WHERE id_tiers = ?");
    $stP->execute([$idTiers]);
    $idsProprioLegacy = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN) ?: []);

    if (!empty($idsProprioLegacy)) {
        $ph = implode(',', array_fill(0, count($idsProprioLegacy), '?'));
        $stB = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE id_proprietaire IN ($ph)");
        $stB->execute($idsProprioLegacy);
        $nbBiens = (int)$stB->fetchColumn();
        if ($nbBiens > 0) $checks[] = "{$nbBiens} bien(s) encore lié(s)";

        $stM = $pdo->prepare("SELECT COUNT(*) FROM mandats WHERE id_proprietaire IN ($ph)");
        $stM->execute($idsProprioLegacy);
        $nbMandats = (int)$stM->fetchColumn();
        if ($nbMandats > 0) $checks[] = "{$nbMandats} mandat(s) encore lié(s)";
    }

    if (!empty($checks)) {
        exit(json_encode([
            'ok'    => false,
            'error' => 'Impossible de supprimer : ' . implode(' · ', $checks) . '. Dissocie-les d\'abord, ou archive le tiers.',
            'blocked_by' => $checks,
        ]));
    }

    $pdo->beginTransaction();

    // DELETE cascade manuel (les FK ne sont pas toutes en CASCADE)
    $pdo->prepare("DELETE FROM tiers_roles WHERE id_tiers = ?")->execute([$idTiers]);
    if (!empty($idsProprioLegacy)) {
        $phL = implode(',', array_fill(0, count($idsProprioLegacy), '?'));
        $pdo->prepare("DELETE FROM proprietaires WHERE id IN ($phL)")->execute($idsProprioLegacy);
    }
    $pdo->prepare("DELETE FROM tiers WHERE id = ?")->execute([$idTiers]);

    $pdo->commit();

    exit(json_encode(['ok' => true, 'id_tiers' => $idTiers, 'deleted' => true]));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[tiers_delete] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
