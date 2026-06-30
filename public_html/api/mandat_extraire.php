<?php
/**
 * api/mandat_extraire.php — Extraction IA d'un mandat (PDF GED) vers le registre.
 *   action=one     : extrait 1 document (ged_document_id [+ id_proprietaire/id_tiers])
 *   action=proprio : trouve le mandat GED du propriétaire et l'extrait
 * Sécurité : admin / super admin + CSRF (form 'mandat_extraire').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/mandat_registre.php';
require_admin_or_super_admin();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(180);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('mandat_extraire');

/** @var PDO $pdo */
$pdo    = $GLOBALS['pdo'];
$action = (string)($_POST['action'] ?? 'one');
$docId  = isset($_POST['ged_document_id']) && ctype_digit((string)$_POST['ged_document_id']) ? (int)$_POST['ged_document_id'] : 0;
$pid    = isset($_POST['id_proprietaire'])  && ctype_digit((string)$_POST['id_proprietaire'])  ? (int)$_POST['id_proprietaire']  : 0;
$tid    = isset($_POST['id_tiers'])         && ctype_digit((string)$_POST['id_tiers'])         ? (int)$_POST['id_tiers']         : 0;
$modele = (string)($_POST['modele'] ?? 'haiku');

if ($action === 'proprio') {
    if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'id_proprietaire requis']); exit; }
    // tiers du proprio
    $t = $pdo->prepare("SELECT id_tiers FROM proprietaires WHERE id=?"); $t->execute([$pid]); $tid = (int)$t->fetchColumn();
    // dernier mandat GED rattaché à ce tiers
    $q = $pdo->prepare("SELECT gd.id FROM ged_documents gd
        JOIN ged_document_links gdl ON gdl.document_id=gd.id AND gdl.entity_type='TIERS' AND gdl.entity_id=?
        WHERE gd.status='active' AND gd.document_type='mandat_gestion' ORDER BY gd.id DESC LIMIT 1");
    $q->execute([$tid]); $docId = (int)$q->fetchColumn();
    if ($docId <= 0) { echo json_encode(['ok'=>false,'error'=>'aucun mandat GED pour ce propriétaire']); exit; }
}

if ($docId <= 0) { echo json_encode(['ok'=>false,'error'=>'ged_document_id requis']); exit; }

// Si pid/tid non fournis, on les déduit des liens du doc
if ($tid <= 0) { $l=$pdo->prepare("SELECT entity_id FROM ged_document_links WHERE document_id=? AND entity_type='TIERS' LIMIT 1"); $l->execute([$docId]); $tid=(int)$l->fetchColumn(); }
if ($pid <= 0 && $tid > 0) { $p=$pdo->prepare("SELECT id FROM proprietaires WHERE id_tiers=? LIMIT 1"); $p->execute([$tid]); $pid=(int)$p->fetchColumn(); }

$res = mr_extraire_mandat($pdo, $docId, $pid, $tid, $modele);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
