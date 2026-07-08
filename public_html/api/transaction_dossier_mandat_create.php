<?php
// api/transaction_dossier_mandat_create.php — Crée le mandat de VENTE du dossier (avec termes).
// POST : id_dossier, honoraires, honoraires_charge, exclusif, duree_mois, date_debut
//   →  { ok, mandat_id, numero_mandat, etape }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
if ($idDossier <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier manquant']); exit; }

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }
$idBien = (int)$dossier['id_bien'];

// Scope société.
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

// Termes (tous optionnels sauf le principe vente).
$num = static fn($k) => post($k) !== null && post($k) !== '' ? (float)str_replace([' ', ','], ['', '.'], (string)post($k)) : null;
$honoraires      = $num('honoraires');
$honorairesCharge = trim((string)(post('honoraires_charge') ?? '')) ?: null; // vendeur|acquereur|partage
$exclusif        = (int)((post('exclusif') ?? '0') === '1' || post('exclusif') === 'on' ? 1 : 0);
$dureeMois       = (int)($num('duree_mois') ?? 0);
$dateDebut       = trim((string)(post('date_debut') ?? '')) ?: date('Y-m-d');
// Co-mandat : agence mandataire choisie (commercialisation) + agence collaboratrice (REGIE EMERY) + répartition.
$idAgeMand       = (int)(post('id_agence_mandataire') ?? 0);
$idAgeCollab     = (int)(post('id_agence_collaborateur') ?? 0) ?: null;
$partMand        = $num('part_honoraires_mandataire');
$partCollab      = $num('part_honoraires_collaborateur');

if ($honorairesCharge !== null && !in_array($honorairesCharge, ['vendeur','acquereur','partage'], true)) {
    $honorairesCharge = null;
}

try {
    $stB = $pdo->prepare("SELECT id_societe, id_agence, id_proprietaire FROM biens WHERE id = ? LIMIT 1");
    $stB->execute([$idBien]);
    $bien = $stB->fetch(PDO::FETCH_ASSOC);
    if (!$bien) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }

    // Agence mandataire = celle choisie si accessible (admin = toutes ; sinon même société), sinon celle du bien.
    $ageMandataire = (int)($bien['id_agence'] ?? 0);
    if ($idAgeMand > 0) {
        $ca = $pdo->prepare("SELECT id_societe FROM agences WHERE id=? AND actif=1 LIMIT 1");
        $ca->execute([$idAgeMand]); $ags = $ca->fetch(PDO::FETCH_ASSOC);
        if ($ags && ($isManager || (int)$ags['id_societe'] === (int)$idSoc)) { $ageMandataire = $idAgeMand; }
    }

    // Anti-doublon : un seul mandat vente actif par bien.
    $stDup = $pdo->prepare("SELECT id FROM mandats
                             WHERE id_bien = ? AND statut = 'actif' AND type_mandat IN ('vente','transaction') LIMIT 1");
    $stDup->execute([$idBien]);
    $dupId = (int)$stDup->fetchColumn();
    if ($dupId > 0) {
        // On relie simplement le dossier au mandat existant.
        $pdo->prepare("UPDATE dossier_vente SET id_mandat = ?, date_mandat = COALESCE(date_mandat, ?), updated_at = NOW() WHERE id = ?")
            ->execute([$dupId, $dateDebut, $idDossier]);
        dv_sync_etape($pdo, $idDossier);
        $d = dv_get($pdo, $idDossier);
        echo json_encode(['ok'=>true, 'mandat_id'=>$dupId, 'existed'=>true, 'etape'=>$d['etape'] ?? null,
                          'message'=>'Un mandat vente actif existait déjà — relié au dossier.']);
        exit;
    }

    $numero = 'AUTO-V-' . date('Y') . '-' . str_pad((string)$idBien, 5, '0', STR_PAD_LEFT);
    $dateFin = $dureeMois > 0 ? date('Y-m-d', strtotime($dateDebut . ' +' . $dureeMois . ' months')) : null;

    $pdo->beginTransaction();
    // Renouvellement par tacite reconduction : par défaut OUI, durée = terme initial, plafond 3 ans.
    $renouv = (post('renouvellement_tacite') === '0' || post('renouvellement_tacite') === 'off') ? 0 : 1;
    $dureeMax = 36; // 3 ans max cumulés
    $ins = $pdo->prepare("INSERT INTO mandats
        (id_bien, id_proprietaire, id_agence, id_agence_collaborateur, numero_mandat, type_mandat, nature_mandat,
         exclusif, date_signature, date_debut, date_fin, honoraires, honoraires_charge,
         part_honoraires_mandataire, part_honoraires_collaborateur,
         renouvellement_tacite, duree_initiale_mois, duree_max_mois,
         statut, id_user, date_creation)
        VALUES (?, ?, ?, ?, ?, 'vente', NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', ?, NOW())");
    $ins->execute([
        $idBien, $bien['id_proprietaire'] ?: null, $ageMandataire ?: null, $idAgeCollab, $numero,
        $exclusif, $dateDebut, $dateFin, $honoraires, $honorairesCharge,
        $partMand, $partCollab,
        $renouv, ($dureeMois > 0 ? $dureeMois : null), $dureeMax,
        (int)current_user_id() ?: null,
    ]);
    $mandatId = (int)$pdo->lastInsertId();

    // Upsert annonce vente brouillon (cohérent avec bien_add_mandat).
    $stAnn = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? AND type_transaction = 'vente' LIMIT 1");
    $stAnn->execute([$idBien]);
    if (!(int)$stAnn->fetchColumn()) {
        $pdo->prepare("INSERT INTO annonces (id_bien, id_societe, id_agence, id_user, type_transaction,
                        etat_publication, date_creation, date_modification)
                        VALUES (?, ?, ?, ?, 'vente', 'brouillon', NOW(), NOW())")
            ->execute([$idBien, $bien['id_societe'] ?: null, $bien['id_agence'] ?: null, (int)current_user_id() ?: null]);
    }

    // Relie le dossier + avance l'étape.
    $pdo->prepare("UPDATE dossier_vente SET id_mandat = ?, date_mandat = COALESCE(date_mandat, ?), updated_at = NOW() WHERE id = ?")
        ->execute([$mandatId, $dateDebut, $idDossier]);
    $pdo->commit();

    dv_sync_etape($pdo, $idDossier);
    if (function_exists('dv_sync_prix_annonce')) dv_sync_prix_annonce($pdo, $idDossier);
    $d = dv_get($pdo, $idDossier);

    echo json_encode([
        'ok'            => true,
        'mandat_id'     => $mandatId,
        'numero_mandat' => $numero,
        'etape'         => $d['etape'] ?? null,
        'message'       => 'Mandat de vente créé.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[transaction_dossier_mandat_create] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
