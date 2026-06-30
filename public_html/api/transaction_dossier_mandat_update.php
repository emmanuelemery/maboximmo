<?php
// api/transaction_dossier_mandat_update.php — Met à jour les termes du mandat du dossier.
// POST : id_dossier, numero_mandat, honoraires, honoraires_charge, exclusif, duree_mois, date_debut
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }
if (empty($dossier['id_mandat'])) { echo json_encode(['ok'=>false,'error'=>'aucun mandat à modifier']); exit; }

// Scope société (super admin / manager bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

$idMandat = (int)$dossier['id_mandat'];
$num   = static fn($v) => ($v === null || $v === '') ? null : (float)str_replace([' ',','], ['','.'], (string)$v);

$numero    = trim((string)(post('numero_mandat') ?? '')) ?: null;
$honos     = $num(post('honoraires'));
$charge    = trim((string)(post('honoraires_charge') ?? '')) ?: null;
if ($charge !== null && !in_array($charge, ['vendeur','acquereur','partage'], true)) $charge = null;
$exclusif  = (int)((post('exclusif') ?? '0') === '1' || post('exclusif') === 'on' ? 1 : 0);
$dureeMois = (int)(post('duree_mois') ?? 0);
$dateDebut = trim((string)(post('date_debut') ?? '')) ?: null;
$dateSign  = trim((string)(post('date_signature') ?? '')) ?: null; // signature des 2 parties

// date_fin = date_debut + durée
$dateFin = null;
if ($dateDebut && $dureeMois > 0) {
    try { $d = new DateTime($dateDebut); $d->modify('+' . $dureeMois . ' months'); $dateFin = $d->format('Y-m-d'); }
    catch (Throwable $e) {}
}

try {
    $pdo->prepare("UPDATE mandats
                      SET numero_mandat = COALESCE(?, numero_mandat),
                          honoraires = ?, honoraires_charge = ?, exclusif = ?,
                          date_debut = COALESCE(?, date_debut),
                          date_fin = COALESCE(?, date_fin),
                          date_signature = ?,
                          date_modification = NOW()
                    WHERE id = ?")
        ->execute([$numero, $honos, $charge, $exclusif, $dateDebut, $dateFin, $dateSign, $idMandat]);
    // Honoraires modifiés → recalcul du FAI et propagation à l'annonce.
    dv_sync_prix_annonce($pdo, $idDossier);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[mandat_update] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
