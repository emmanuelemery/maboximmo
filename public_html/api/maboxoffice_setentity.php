<?php
declare(strict_types=1);
/**
 * api/maboxoffice_setentity.php — Lie MANUELLEMENT une entité à un document MaBoxOffice
 * (via la recherche universelle). Superadmin. Renvoie la chaîne de liens + le nom GED
 * recalculés pour rafraîchir l'écran sans recharger.
 *
 * GET ?doc=<id>&type=<bien|immeuble|tiers|user>&id=<entityId>&label=<txt>
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/maboxoffice_match.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
if (!is_super_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit; }

$pdo = $GLOBALS['pdo'];
$doc = (int)($_REQUEST['doc'] ?? 0);
$rawType = strtolower(trim((string)($_REQUEST['type'] ?? '')));
$eid = (int)($_REQUEST['id'] ?? 0);
$label = trim((string)($_REQUEST['label'] ?? '')) ?: null;

$map = ['bien'=>'BIEN', 'immeuble'=>'IMB', 'tiers'=>'TIERS', 'proprio'=>'TIERS', 'bail'=>'BAIL', 'user'=>'EMP', 'trs'=>'TRS',
        'creancier'=>'CREANCIER_DOSSIER', 'creancier_dossier'=>'CREANCIER_DOSSIER'];
// RH « TOUS les collaborateurs » = sentinel id -1 (autorisé uniquement pour user/EMP).
$isAllRH = ($rawType === 'user' && $eid === -1);
if ($doc <= 0 || !isset($map[$rawType]) || ($eid <= 0 && !$isAllRH)) { echo json_encode(['ok'=>false,'error'=>'paramètres invalides']); exit; }
$mboType = $map[$rawType];

$st = $pdo->prepare("SELECT * FROM fluxbox_documents WHERE id=? LIMIT 1");
$st->execute([$doc]); $d = $st->fetch(PDO::FETCH_ASSOC);
if (!$d) { echo json_encode(['ok'=>false,'error'=>'Document introuvable']); exit; }

// Un collaborateur (user) → document RH : on force le métier RH (déclenche mois + lien SAL).
$metier = (string)($d['mbo_metier'] ?? '');
if ($mboType === 'EMP') $metier = 'rh';
if ($mboType === 'CREANCIER_DOSSIER') $metier = 'contentieux';

$pdo->prepare("UPDATE fluxbox_documents SET mbo_entity_type=?, mbo_entity_id=?, mbo_entity_label=?, mbo_entity_score=999, mbo_metier=? WHERE id=?")
    ->execute([$mboType, $eid, $label, $metier ?: null, $doc]);

// Recalcule la chaîne + le nom GED avec la nouvelle entité.
$d['mbo_entity_type'] = $mboType; $d['mbo_entity_id'] = $eid; $d['mbo_entity_label'] = $label; $d['mbo_metier'] = $metier;

$parts = [];
foreach (mbo_entity_chain($pdo, $mboType, $eid) as $lk)
    $parts[] = '<a href="' . htmlspecialchars($lk['url']) . '" target="_blank">' . $lk['icon'] . ' ' . htmlspecialchars($lk['label']) . '</a>';
$chain = implode(' <span style="color:#9aa6b8">›</span> ', $parts);

$gedname = mbo_build_ged_name($pdo, $d);
$refsal  = !empty($d['mbo_ref_salaire']) ? date('Y-m', strtotime((string)$d['mbo_ref_salaire'])) : '';

echo json_encode(['ok'=>true, 'chain'=>$chain, 'gedname'=>$gedname, 'metier'=>$metier, 'refsalaire'=>$refsal, 'label'=>$label], JSON_UNESCAPED_UNICODE);
