<?php
/**
 * api/onedrive_classer.php — Classement OneDrive → GED, scope PROPRIÉTAIRE.
 *   action=scan   : dry-run → propositions (n'écrit rien)
 *   action=commit : classe en GED tous les items « certain » (les « pile » sont ignorés)
 * POST : id_proprietaire, action, csrf_token (form 'onedrive_classer').
 * Sécurité : login + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/onedrive_classer.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(600);            // gros propriétaires (capture complète récursive) = plus long
ignore_user_abort(true);        // poursuit le classement même si le navigateur coupe

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('onedrive_classer');
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }
if (!graph_is_configured()) { echo json_encode(['ok'=>false,'error'=>'Microsoft Graph non configuré']); exit; }

/** @var PDO $pdo */
$pdo    = $GLOBALS['pdo'];
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);
$pid    = isset($_POST['id_proprietaire']) && ctype_digit((string)$_POST['id_proprietaire']) ? (int)$_POST['id_proprietaire'] : 0;
$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$bailId = isset($_POST['id_bail']) && ctype_digit((string)$_POST['id_bail']) ? (int)$_POST['id_bail'] : 0;
$action = (string)($_POST['action'] ?? 'scan');
if ($pid <= 0 && $bienId <= 0 && $bailId <= 0) { echo json_encode(['ok'=>false,'error'=>'id_proprietaire, id_bien ou id_bail requis']); exit; }

// Action « ouvrir le dossier OneDrive du pro » — renvoie juste l'URL web (pas de scan).
if ($action === 'folder_url') {
    if ($pid <= 0 && $bailId > 0) { $q=$pdo->prepare("SELECT b.id_proprietaire FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?"); $q->execute([$bailId]); $pid=(int)$q->fetchColumn(); }
    if ($pid <= 0 && $bienId > 0) { $b=$pdo->prepare("SELECT id_proprietaire FROM biens WHERE id=?"); $b->execute([$bienId]); $pid=(int)$b->fetchColumn(); }
    if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'propriétaire introuvable']); exit; }
    echo json_encode(oc_proprio_folder_url($pdo, $pid), JSON_UNESCAPED_UNICODE); exit;
}

// Action « ignore » — marque un document OneDrive comme IGNORÉ (ne plus proposer ni rescanner).
if ($action === 'ignore') {
    $itemId = trim((string)($_POST['item_id'] ?? ''));
    if ($itemId === '') { echo json_encode(['ok'=>false,'error'=>'item_id requis']); exit; }
    try {
        $pdo->prepare("INSERT INTO onedrive_classer_ignore (item_id, id_proprietaire, name, ignored_by, ignored_at)
                       VALUES (?,?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE ignored_at=NOW(), id_proprietaire=VALUES(id_proprietaire)")
            ->execute([$itemId, $pid ?: null, (string)($_POST['name'] ?? ''), $userId ?: null]);
        echo json_encode(['ok'=>true, 'item_id'=>$itemId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'SQL : '.$e->getMessage()]); }
    exit;
}

// Action « set_folder » — mémorise MANUELLEMENT le dossier OneDrive d'un proprio
// (quand la résolution auto échoue). Après ça, plus jamais « introuvable ».
if ($action === 'set_folder') {
    if ($pid <= 0 && $bailId > 0) { $q=$pdo->prepare("SELECT b.id_proprietaire FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?"); $q->execute([$bailId]); $pid=(int)$q->fetchColumn(); }
    if ($pid <= 0 && $bienId > 0) { $b=$pdo->prepare("SELECT id_proprietaire FROM biens WHERE id=?"); $b->execute([$bienId]); $pid=(int)$b->fetchColumn(); }
    if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'propriétaire introuvable']); exit; }
    $folder = trim((string)($_POST['folder_path'] ?? ''));
    // Accepte un chemin OneDrive Windows collé (« C:\Users\…\OneDrive - REGIE EMERY (1)\01_SERVICE_GESTION\… »)
    // → on ne garde que la partie après « OneDrive… \ » et on normalise les séparateurs.
    $folder = str_replace('\\', '/', $folder);
    if (preg_match('#OneDrive[^/]*/(.+)$#i', $folder, $m)) $folder = $m[1];
    $folder = trim($folder, '/');
    if ($folder === '') { echo json_encode(['ok'=>false,'error'=>'chemin vide']); exit; }
    $schemaId = isset($_POST['schema_id']) && ctype_digit((string)$_POST['schema_id']) ? (int)$_POST['schema_id'] : 0;
    $ok = oc_cache_set($pdo, $pid, $folder, 'manuel', $userId, $schemaId);
    echo json_encode(['ok'=>$ok, 'folder_path'=>$folder, 'schema_id'=>$schemaId, 'error'=>$ok?null:'enregistrement échoué'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Action « commit_items » — classe une LISTE d'items déjà scannés (chunké côté client, sans re-scan).
// Évite le timeout des gros propriétaires (200+ docs) en traitant par petits paquets.
if ($action === 'commit_items') {
    if ($pid <= 0 && $bailId > 0) { $q=$pdo->prepare("SELECT b.id_proprietaire FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?"); $q->execute([$bailId]); $pid=(int)$q->fetchColumn(); }
    if ($pid <= 0 && $bienId > 0) { $b=$pdo->prepare("SELECT id_proprietaire FROM biens WHERE id=?"); $b->execute([$bienId]); $pid=(int)$b->fetchColumn(); }
    if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'propriétaire introuvable']); exit; }
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!is_array($items)) { echo json_encode(['ok'=>false,'error'=>'items invalides']); exit; }
    $done = []; $skipped = 0; $errors = [];
    foreach ($items as $it) {
        if (!is_array($it) || ($it['status'] ?? '') !== 'certain') { $skipped++; continue; }
        try {
            $res = oc_commit_proposal($pdo, $it, $pid, $userId);
            if (!empty($res['ok'])) $done[] = ['name'=>$it['name'] ?? '', 'doc_id'=>$res['doc_id'] ?? null, 'dedup'=>!empty($res['deduplicated'])];
            else $errors[] = ($it['name'] ?? '?').' : '.(is_array($res['errors'] ?? null) ? implode(' / ', $res['errors']) : ($res['error'] ?? 'échec'));
        } catch (Throwable $e) { $errors[] = ($it['name'] ?? '?').' : '.$e->getMessage(); }
    }
    $nbDedup = count(array_filter($done, fn($d) => !empty($d['dedup'])));
    echo json_encode(['ok'=>true, 'classes'=>count($done), 'dedup'=>$nbDedup, 'pile'=>$skipped, 'erreurs'=>$errors], JSON_UNESCAPED_UNICODE);
    exit;
}

// Scope bail (fiche bail : bail + EDL entrée) > scope bien (nouveaux + loupés) > scope propriétaire.
$scope = 'proprio';
if ($bailId > 0) {
    $scope = 'bail';
    $scan = oc_scan_bail($pdo, $bailId);
    if (!empty($scan['ok'])) $pid = (int)($scan['bail']['id_proprietaire'] ?? $pid);
} elseif ($bienId > 0) {
    $scope = 'bien';
    $scan = oc_scan_bien($pdo, $bienId);
    if (!empty($scan['ok'])) $pid = (int)($scan['bien']['id_proprietaire'] ?? $pid);
} else {
    $scan = oc_scan_proprio($pdo, $pid);
}
if (empty($scan['ok'])) { echo json_encode(['ok'=>false,'error'=>$scan['error'] ?? 'scan impossible', 'base'=>$scan['base'] ?? null, 'needs_manual'=>!empty($scan['needs_manual']), 'missing'=>$scan['missing'] ?? []]); exit; }

if ($action === 'scan') {
    echo json_encode(['ok'=>true, 'mode'=>'dry', 'scope'=>$scope,
        'proprio'=>$scan['proprio'], 'folder'=>$scan['folder'], 'bien'=>$scan['bien'] ?? null, 'bail'=>$scan['bail'] ?? null,
        'nb_biens'=>$scan['nb_biens'] ?? null, 'nb_baux'=>$scan['nb_baux'] ?? ($scan['nb_baux_bien'] ?? null),
        'items'=>$scan['items']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'commit') {
    // Filtre optionnel par TYPE (boutons « Mandat », « Bail », « DPE », « TF »). Vide = tout.
    $typesRaw = trim((string)($_POST['types'] ?? ''));
    $typeFilter = $typesRaw !== '' ? array_filter(array_map('trim', explode(',', $typesRaw))) : [];
    $done = []; $skipped = 0; $errors = [];
    foreach ($scan['items'] as $it) {
        if (($it['status'] ?? '') !== 'certain') { $skipped++; continue; }
        if ($typeFilter && !in_array($it['doc_type'] ?? '', $typeFilter, true)) { continue; }   // hors du type demandé
        try {
            $res = oc_commit_proposal($pdo, $it, $pid, $userId);
            if (!empty($res['ok'])) $done[] = ['name'=>$it['name'], 'type'=>$it['type'], 'doc_id'=>$res['doc_id'] ?? null, 'dedup'=>!empty($res['deduplicated'])];
            else $errors[] = $it['name'].' : '.(is_array($res['errors'] ?? null) ? implode(' / ', $res['errors']) : ($res['error'] ?? 'échec'));
        } catch (Throwable $e) { $errors[] = $it['name'].' : '.$e->getMessage(); }
    }
    $nbDedup = count(array_filter($done, fn($d) => !empty($d['dedup'])));
    echo json_encode(['ok'=>true, 'mode'=>'commit', 'scope'=>$scope, 'folder'=>$scan['folder'] ?? null,
        'scanned'=>count($scan['items']), 'classes'=>count($done), 'dedup'=>$nbDedup,
        'pile'=>$skipped, 'erreurs'=>$errors, 'docs'=>$done], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['ok'=>false, 'error'=>'action inconnue']);
