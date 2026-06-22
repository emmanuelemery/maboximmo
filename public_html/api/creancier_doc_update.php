<?php
/**
 * api/creancier_doc_update.php — Enregistre les champs extraits édités (avant validation).
 *
 * Persiste en BDD (creancier_doc_analyse) les corrections de l'utilisateur sur
 * l'extraction IA. N'effectue PAS le commit GED (ça reste la validation).
 *
 * POST : analyse_id (requis) + champs édités + csrf_token (form 'creancier_doc_update').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_doc_update');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$id  = (int)($_POST['analyse_id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'analyse_id requis']); exit; }

$st = $pdo->prepare("SELECT * FROM creancier_doc_analyse WHERE id = ? LIMIT 1");
$st->execute([$id]);
$an = $st->fetch(PDO::FETCH_ASSOC);
if (!$an) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Analyse introuvable']); exit; }
if ($an['statut'] !== 'a_valider') { echo json_encode(['ok'=>false,'error'=>'Analyse déjà traitée']); exit; }

$typeDoc  = trim((string)($_POST['type_doc'] ?? $an['type_doc']));
$crea     = trim((string)($_POST['creancier'] ?? (string)$an['extr_creancier_nom']));
$pro      = trim((string)($_POST['pro'] ?? (string)$an['extr_pro_nom']));
$numDoss  = trim((string)($_POST['numero_dossier'] ?? (string)$an['extr_numero_dossier']));
$objet    = trim((string)($_POST['objet'] ?? (string)$an['extr_objet']));
$mtPrinc  = is_numeric($_POST['montant_principal'] ?? null) ? (float)$_POST['montant_principal'] : ($an['extr_montant_principal'] !== null ? (float)$an['extr_montant_principal'] : null);
$mtTotal  = is_numeric($_POST['montant_total'] ?? null) ? (float)$_POST['montant_total'] : ($an['extr_montant_total'] !== null ? (float)$an['extr_montant_total'] : null);

// Synchronise donnees_json (source de vérité de l'affichage / matching dossier).
$data = json_decode((string)$an['donnees_json'], true) ?: [];
$data['type_doc'] = $typeDoc;
$data['numero_dossier'] = $numDoss ?: null;
$data['objet'] = $objet ?: null;
$data['creancier'] = array_merge($data['creancier'] ?? [], ['nom' => $crea]);
$data['professionnel'] = array_merge($data['professionnel'] ?? [], ['nom' => $pro]);
$data['montants'] = array_merge($data['montants'] ?? [], ['principal' => $mtPrinc, 'total' => $mtTotal]);
if (array_key_exists('debiteur', $_POST)) $data['debiteur_mentionne'] = trim((string)$_POST['debiteur']);

$pdo->prepare("UPDATE creancier_doc_analyse
    SET type_doc=?, extr_creancier_nom=?, extr_pro_nom=?, extr_numero_dossier=?, extr_objet=?,
        extr_montant_principal=?, extr_montant_total=?, donnees_json=?
    WHERE id=?")
   ->execute([
        $typeDoc ?: 'autre', $crea ?: null, $pro ?: null, $numDoss ?: null, $objet ?: null,
        $mtPrinc, $mtTotal, json_encode($data, JSON_UNESCAPED_UNICODE), $id,
   ]);

echo json_encode(['ok'=>true, 'analyse_id'=>$id], JSON_UNESCAPED_UNICODE);
