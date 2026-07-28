<?php
declare(strict_types=1);
/**
 * api/dossier_vente_partage_action.php — Partage acquéreur/notaire d'un dossier de vente.
 *
 * Modèle : UNE liste de docs, 2 cases par doc (acquéreur / notaire). On maintient UN lien
 * stable par rôle (acquereur, notaire) ; enregistrer la sélection met à jour le jeu de docs
 * de chaque lien (token conservé). Photos : en attente (non gérées ici).
 *
 * POST (csrf 'dossier_vente_partage') :
 *   action=save   : id_dossier, docs_acquereur (JSON array ged_documents ids),
 *                   docs_notaire (JSON array), expires_days
 *   action=revoke : id_dossier, partage_id
 * Manager (1,2,3,7)/super-admin + scope société du dossier.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('dossier_vente_partage');
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : 0;
$action = (string)($_POST['action'] ?? '');
$idDossier = isset($_POST['id_dossier']) && ctype_digit((string)$_POST['id_dossier']) ? (int)$_POST['id_dossier'] : 0;
if ($idDossier <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier requis']); exit; }

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Dossier introuvable']); exit; }
if (!(function_exists('is_super_admin') && is_super_admin())) {
    $mySoc = (int)($_SESSION['id_societe'] ?? 0);
    if ($mySoc > 0 && (int)($dossier['id_societe'] ?? 0) !== $mySoc) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Dossier hors de votre société']); exit;
    }
}

/** Normalise une entrée en liste d'IDs entiers positifs uniques. */
function dvp_ids($raw): array {
    $arr = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $out = [];
    foreach ((array)$arr as $x) { $x = (int)$x; if ($x > 0) $out[$x] = true; }
    return array_values(array_map('intval', array_keys($out)));
}
$base = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/';

try {
    if ($action === 'save') {
        $days = isset($_POST['expires_days']) ? (int)$_POST['expires_days'] : 30;
        $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days*86400) : null;
        $sel = [
            'acquereur'         => dvp_ids($_POST['docs_acquereur'] ?? '[]'),
            'notaire'           => dvp_ids($_POST['docs_notaire']   ?? '[]'),
            'commercialisateur' => dvp_ids($_POST['docs_commercialisateur'] ?? '[]'),
        ];
        $res = [];
        foreach ($sel as $role => $ids) {
            $docsJson = json_encode($ids);
            // Lien STABLE : on met à jour le partage le PLUS ANCIEN du rôle (= le lien déjà envoyé),
            // on garde son token, et on RÉVOQUE les doublons actifs → un seul lien qui perdure.
            $st = $pdo->prepare("SELECT id, token FROM dossier_vente_partage WHERE id_dossier=? AND role_destinataire=? AND revoked_at IS NULL ORDER BY id ASC LIMIT 1");
            $st->execute([$idDossier, $role]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare("UPDATE dossier_vente_partage SET docs_json=?, inclure_photos=0, expires_at=? WHERE id=?")
                    ->execute([$docsJson, $expires, (int)$row['id']]);
                $token = (string)$row['token']; $pid = (int)$row['id'];
            } else {
                $token = bin2hex(random_bytes(24));
                $pdo->prepare("INSERT INTO dossier_vente_partage (id_dossier, role_destinataire, token, docs_json, inclure_photos, expires_at, created_by) VALUES (?,?,?,?,0,?,?)")
                    ->execute([$idDossier, $role, $token, $docsJson, $expires, $userId ?: null]);
                $pid = (int)$pdo->lastInsertId();
            }
            // Consolidation : révoque tout autre partage actif du même rôle (fini les doublons de liens).
            $pdo->prepare("UPDATE dossier_vente_partage SET revoked_at=NOW() WHERE id_dossier=? AND role_destinataire=? AND revoked_at IS NULL AND id<>?")
                ->execute([$idDossier, $role, $pid]);
            $res[$role] = ['id'=>$pid, 'token'=>$token, 'url'=>$base.'dossier_vente_partage.php?t='.$token, 'count'=>count($ids)];
        }
        echo json_encode(['ok'=>true, 'liens'=>$res]);
        exit;
    }

    if ($action === 'revoke') {
        $pid = isset($_POST['partage_id']) && ctype_digit((string)$_POST['partage_id']) ? (int)$_POST['partage_id'] : 0;
        if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'partage_id requis']); exit; }
        $pdo->prepare("UPDATE dossier_vente_partage SET revoked_at = NOW() WHERE id = ? AND id_dossier = ?")->execute([$pid, $idDossier]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'action inconnue']);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
