<?php
/**
 * api/fluxbox_resolve.php — Correctif 5/8 : (re)calcule la résolution d'une carte en direct.
 *
 * Appelé à chaque champ confirmé / entité choisie pour mettre à jour l'aperçu
 * (chemin complet + nom prévu) AVANT validation. Persiste resolution_json + type_document.
 *
 * POST (JSON ou form) :
 *   carte_id        (int, requis)
 *   entite_id       (int)    — entité choisie via la recherche universelle
 *   entite_type     (string) — bien|immeuble|societe|tiers|user
 *   entite_nom      (string)
 *   confirme_societe(0|1)    — tap de confirmation
 *   confirme_agence (0|1)
 *   type_document   (string) — override explicite éventuel
 *
 * →  { ok, resolution:{…contrat figé…} }
 * Sécurité : login + carte du tenant courant.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/fluxbox_va_orchestrator.php';
require_once __DIR__ . '/../inc/fluxbox_resolution.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

// Entrée : JSON ou POST classique
$raw = file_get_contents('php://input');
$in  = [];
if ($raw !== '' && ($j = json_decode($raw, true)) !== null && is_array($j)) $in = $j;
$in = array_merge($_POST, $in);

$carteId = (int)($in['carte_id'] ?? 0);
if ($carteId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'carte_id requis']); exit; }

// Vérifie l'appartenance au tenant (multi-tenant)
try {
    $tid = (int)(function_exists('ged_current_tenant_id') ? (ged_current_tenant_id() ?? 0) : 0);
    $st = $pdo->prepare("SELECT id FROM fluxbox_cartes WHERE id=? " . ($tid>0 ? "AND tenant_id=?" : "") . " LIMIT 1");
    $st->execute($tid>0 ? [$carteId,$tid] : [$carteId]);
    if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'carte introuvable']); exit; }
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

// Contexte explicite issu des taps utilisateur
$explicite = [
    'confirme' => [
        'societe' => !empty($in['confirme_societe']),
        'agence'  => !empty($in['confirme_agence']),
    ],
];
if (!empty($in['entite_id'])) {
    $explicite['entite_id']   = (int)$in['entite_id'];
    $explicite['entite_type'] = (string)($in['entite_type'] ?? 'tiers');
    $explicite['entite_nom']  = (string)($in['entite_nom'] ?? '');
}
if (!empty($in['type_document'])) $explicite['type_document'] = (string)$in['type_document'];

try {
    $resolution = fluxbox_resoudre_carte($carteId, $pdo, $explicite);
    if (empty($resolution)) { echo json_encode(['ok'=>false,'error'=>'résolution vide']); exit; }
    fluxbox_persister_resolution($pdo, $carteId, $resolution);
    if ($tid > 0 && !empty($resolution['_ancres'])) {
        fluxbox_persister_ancres($pdo, $tid, $carteId, $resolution['_ancres']);
    }
    echo json_encode(['ok'=>true,'resolution'=>$resolution], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
