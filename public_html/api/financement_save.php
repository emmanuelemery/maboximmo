<?php
declare(strict_types=1);
/**
 * api/financement_save.php — Créer / mettre à jour un dossier financier (T1).
 * Body JSON : { id?, type, libelle, id_tiers?, id_societe_concernee?, pilote_user_id?, statut?, confidentialite?, synthese? }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/financement.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
function fo(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fo(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('financement');
$roleId = (int)current_role_id();
if (!(function_exists('is_super_admin') && is_super_admin()) && !in_array($roleId, [1, 7], true)) fo(['ok' => false, 'error' => 'Réservé administrateurs.'], 403);

$pdo = $GLOBALS['pdo'];
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) fo(['ok' => false, 'error' => 'JSON invalide'], 400);

$type = (string)($body['type'] ?? 'financement_bancaire');
if (!isset(fin_type_labels()[$type])) fo(['ok' => false, 'error' => 'Type invalide'], 400);
$libelle = trim((string)($body['libelle'] ?? ''));
if ($libelle === '') fo(['ok' => false, 'error' => 'Libellé requis'], 400);

try {
    $id = (int)($body['id'] ?? 0);
    if ($id > 0) {
        $d = fin_scope_ok($pdo, $id);
        if (!$d) fo(['ok' => false, 'error' => 'Dossier hors périmètre'], 403);
        fin_update($pdo, $id, [
            'type' => $type, 'libelle' => $libelle,
            'id_tiers' => $body['id_tiers'] ?? null, 'id_societe_concernee' => $body['id_societe_concernee'] ?? null,
            'pilote_user_id' => $body['pilote_user_id'] ?? null, 'statut' => $body['statut'] ?? 'ouvert',
            'confidentialite' => $body['confidentialite'] ?? 'normal', 'synthese' => $body['synthese'] ?? null,
        ]);
        fo(['ok' => true, 'id' => $id]);
    }
    $newId = fin_create($pdo, [
        'type' => $type, 'libelle' => $libelle,
        'id_tiers' => $body['id_tiers'] ?? null, 'id_societe_concernee' => $body['id_societe_concernee'] ?? null,
        'pilote_user_id' => $body['pilote_user_id'] ?? null, 'statut' => $body['statut'] ?? 'ouvert',
        'confidentialite' => $body['confidentialite'] ?? 'normal', 'synthese' => $body['synthese'] ?? null,
    ]);
    // liens optionnels à la création (biens/immeubles/creancier)
    foreach ((array)($body['liens'] ?? []) as $lk) {
        if (!empty($lk['type']) && !empty($lk['id'])) fin_link_add($pdo, $newId, (string)$lk['type'], (int)$lk['id'], $lk['role'] ?? null);
    }
    fo(['ok' => true, 'id' => $newId]);
} catch (Throwable $e) {
    fo(['ok' => false, 'error' => $e->getMessage()], 500);
}
