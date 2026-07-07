<?php
/**
 * api/bien_add_bail.php
 *
 * Crée un PROJET de bail commercial depuis un bien (workflow type mandat).
 * Le projet vise un CANDIDAT locataire ; il n'affecte PAS le bail actif ni le
 * locataire courant. À la signature (étape ultérieure), le candidat devient
 * locataire et l'ancien bail se termine.
 *
 * Les infos société/agence gestionnaire NE sont PAS stockées ici : elles sont
 * lues en BDD au moment de la génération PDF.
 *
 * POST JSON : { bien_id, candidat:{...}, destination, date_prise_effet, duree_mois,
 *   duree_ferme_ans, loyer_annuel_ht, charges_mensuelles, indice_type, indice_trimestre,
 *   indice_valeur, nb_termes_garantie, erp_local, option_achat, option_achat_prix,
 *   option_achat_delai_mois, origin }
 *
 * Auth : user authentifié + scope société (bypass admin, exception bailleur).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo       = $GLOBALS['pdo'];
$userId    = (int)($_SESSION['user_id']    ?? 0);
$userSocId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = ((int)($_SESSION['id_role'] ?? 0) === 1);

$body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$bienId = (int)($body['bien_id'] ?? 0);
if ($bienId <= 0) exit(json_encode(['ok'=>false,'error'=>'bien_id requis']));

// ── Bien + scope check (mêmes règles que bien_add_mandat) ──
$st = $pdo->prepare("SELECT id, id_societe, id_agence, id_proprietaire, id_immeuble, reference_bien
                     FROM biens WHERE id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bien introuvable'])); }
if (!$isAdmin && !empty($bien['id_societe']) && (int)$bien['id_societe'] !== $userSocId) {
    $okBailleur = false;
    if (in_array((int)($_SESSION['id_role'] ?? 0), [9,10], true) && (int)($bien['id_proprietaire'] ?? 0) > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $chk->execute([$userId, (int)$bien['id_proprietaire']]);
        $okBailleur = (bool)$chk->fetchColumn();
    }
    if (!$okBailleur) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Bien hors de votre périmètre'])); }
}

$socId = (int)($bien['id_societe'] ?? 0) ?: $userSocId;
$ageId = (int)($bien['id_agence'] ?? 0);

// ── Candidat locataire (avant signature) ──
$cand      = is_array($body['candidat'] ?? null) ? $body['candidat'] : [];
$candType  = (($cand['type'] ?? 'societe') === 'physique') ? 'physique' : 'societe';
$candNom   = trim((string)($cand['nom'] ?? ''));
$candPrenom= trim((string)($cand['prenom'] ?? ''));
$candRaison= trim((string)($cand['raison_sociale'] ?? ''));
$candSiren = trim((string)($cand['siren'] ?? ''));
$candEmail = trim((string)($cand['email'] ?? ''));
$candTel   = trim((string)($cand['telephone'] ?? ''));
$candRepNom= trim((string)($cand['representant_nom'] ?? ''));
$candRepQual=trim((string)($cand['representant_qualite'] ?? ''));
$candAdresse = trim((string)($cand['adresse'] ?? ''));
$candNaissD  = trim((string)($cand['date_naissance'] ?? ''));
$candNaissD  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $candNaissD) ? $candNaissD : null;
$candNaissL  = trim((string)($cand['lieu_naissance'] ?? ''));
$candNat     = trim((string)($cand['nationalite'] ?? ''));
if ($candNom === '' && $candRaison === '') {
    exit(json_encode(['ok'=>false,'error'=>'Nom ou raison sociale du candidat requis']));
}

// ── Garant / caution solidaire (optionnel) ──
$gar        = is_array($body['garant'] ?? null) ? $body['garant'] : [];
$garPresent = !empty($gar['present']) ? 1 : 0;
$garType    = (($gar['type'] ?? 'physique') === 'societe') ? 'societe' : 'physique';
$garNom     = trim((string)($gar['nom'] ?? ''));
$garPrenom  = trim((string)($gar['prenom'] ?? ''));
$garRaison  = trim((string)($gar['raison_sociale'] ?? ''));
$garSiren   = trim((string)($gar['siren'] ?? ''));
$garAdresse = trim((string)($gar['adresse'] ?? ''));
$garNaissD  = trim((string)($gar['date_naissance'] ?? ''));
$garNaissD  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $garNaissD) ? $garNaissD : null;
$garNaissL  = trim((string)($gar['lieu_naissance'] ?? ''));
$garEmail   = trim((string)($gar['email'] ?? ''));
$garTel     = trim((string)($gar['telephone'] ?? ''));
$garMontant = ($gar['montant_max'] ?? null) !== null && $gar['montant_max'] !== '' ? (float)$gar['montant_max'] : null;
$garDuree   = ($gar['duree_ans'] ?? null) !== null && $gar['duree_ans'] !== '' ? (int)$gar['duree_ans'] : null;
$garSolid   = array_key_exists('solidaire',$gar) ? (!empty($gar['solidaire']) ? 1 : 0) : 1;

// ── Conditions ──
$destination = trim((string)($body['destination'] ?? ''));
$dateEffet   = trim((string)($body['date_prise_effet'] ?? '')) ?: null;
if ($dateEffet !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEffet)) $dateEffet = null;
$dureeMois   = (int)($body['duree_mois'] ?? 108) ?: 108;              // 9 ans par défaut
$dureeFerme  = ($body['duree_ferme_ans'] ?? null) !== null ? (int)$body['duree_ferme_ans'] : null; // ex. 6
$loyerAnnuel = (float)($body['loyer_annuel_ht'] ?? 0);
$loyerMensuel= $loyerAnnuel > 0 ? round($loyerAnnuel / 12, 2) : null;
$charges     = ($body['charges_mensuelles'] ?? null) !== null ? (float)$body['charges_mensuelles'] : null;
$indiceType  = in_array(($body['indice_type'] ?? 'ILC'), ['IRL','ILC','ILAT','ICC','autre'], true) ? $body['indice_type'] : 'ILC';
$indiceTrim  = trim((string)($body['indice_trimestre'] ?? '')) ?: null;
$indiceVal   = ($body['indice_valeur'] ?? null) !== null ? (float)$body['indice_valeur'] : null;
$nbTermesDG  = ($body['nb_termes_garantie'] ?? null) !== null ? (int)$body['nb_termes_garantie'] : null;
$depotGar    = ($nbTermesDG && $loyerMensuel) ? round($nbTermesDG * $loyerMensuel, 2) : null;
$erp         = !empty($body['erp_local']) ? 1 : 0;
$optAchat    = !empty($body['option_achat']) ? 1 : 0;
$optPrix     = ($body['option_achat_prix'] ?? null) !== null ? (float)$body['option_achat_prix'] : null;
$optDelai    = ($body['option_achat_delai_mois'] ?? null) !== null ? (int)$body['option_achat_delai_mois'] : null;
$origin      = substr(trim((string)($body['origin'] ?? 'bien_360')), 0, 30);
// ── Argent : TVA, périodicité, provision TF, honoraires gestion technique, honoraires agence ──
$tvaApp   = array_key_exists('tva_applicable',$body) ? (!empty($body['tva_applicable']) ? 1 : 0) : 1;
$tvaTaux  = $tvaApp ? (float)($body['tva_taux'] ?? 20) : 0;
$perio    = (($body['periodicite'] ?? 'mensuelle') === 'trimestrielle') ? 'trimestrielle' : 'mensuelle';
$provTf   = ($body['provision_tf'] ?? null) !== null && $body['provision_tf'] !== '' ? (float)$body['provision_tf'] : null;
$techPct  = ($body['honoraires_tech_pct'] ?? null) !== null && $body['honoraires_tech_pct'] !== '' ? (float)$body['honoraires_tech_pct'] : null;
$honoBail = ($body['honoraires_bailleur'] ?? null) !== null && $body['honoraires_bailleur'] !== '' ? (float)$body['honoraires_bailleur'] : null;
$honoLoc  = ($body['honoraires_locataire'] ?? null) !== null && $body['honoraires_locataire'] !== '' ? (float)$body['honoraires_locataire'] : null;
$honoChg  = ($honoBail !== null && $honoLoc !== null) ? 'partage' : ($honoBail !== null ? 'bailleur' : 'locataire');
$cpGen    = trim((string)($body['conditions_particulieres'] ?? '')) ?: null;
$cpLoyer  = trim((string)($body['conditions_particulieres_loyer'] ?? '')) ?: null;

// ── N° séquentiel par société : BX-<soc>-<AA>-<NNNN> ──
$seq = 1;
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM bien_baux WHERE id_societe = ? AND numero_bail IS NOT NULL");
    $q->execute([$socId]);
    $seq = (int)$q->fetchColumn() + 1;
} catch (Throwable) {}
$numeroBail = 'BX-' . ($socId ?: 0) . '-' . date('y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

$meta = [
    'candidat_representant_nom'     => $candRepNom,
    'candidat_representant_qualite' => $candRepQual,
    'loyer_annuel_ht'               => $loyerAnnuel ?: null,
    'option_achat_prix'             => $optPrix,
    'option_achat_delai_mois'       => $optDelai,
    'template'                      => 'fnaim_commercial_v1',
];

try {
    $pdo->beginTransaction();
    $sql = "INSERT INTO bien_baux
        (id_bien, id_proprietaire, id_agence, id_societe, bail_nature, bail_type, usage_bien,
         destination_activite, erp_local, option_achat, option_achat_prix, option_achat_delai_mois,
         reference_bail, numero_bail, date_prise_effet, duree_mois, duree_ferme_ans,
         loyer_mensuel_hc, charges_mensuelles, charges_type, tva_applicable, tva_taux, periodicite_paiement,
         provision_tf_mensuelle, honoraires_gestion_tech_pct, honoraires_locataire_ttc, honoraires_bailleur_ttc, honoraires_charge,
         conditions_particulieres, conditions_particulieres_loyer,
         indice_type, indice_trimestre, indice_valeur, depot_garantie, nb_termes_garantie,
         clause_resolutoire, locataire_type, locataire_nom, locataire_prenom, locataire_raison_sociale,
         locataire_siren, locataire_email, locataire_telephone,
         locataire_representant_nom, locataire_representant_qualite,
         locataire_adresse, locataire_date_naissance, locataire_lieu_naissance, locataire_nationalite,
         garant_present, garant_type, garant_nom, garant_prenom, garant_raison_sociale, garant_siren,
         garant_adresse, garant_date_naissance, garant_lieu_naissance, garant_email, garant_telephone,
         garant_montant_max, garant_duree_ans, garant_solidaire,
         metadata, statut, origin, id_user_created, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, 'commercial', 'commercial', 'professionnel',
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, 'provisions', ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?,
         ?, ?, ?, ?, ?,
         1, ?, ?, ?, ?,
         ?, ?, ?,
         ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?,
         ?, 'projet', ?, ?, NOW(), NOW())";
    $pdo->prepare($sql)->execute([
        $bienId, $bien['id_proprietaire'] ?: null, $ageId ?: null, $socId ?: null,
        $destination ?: null, $erp, $optAchat, $optPrix, $optDelai,
        $numeroBail, $numeroBail, $dateEffet, $dureeMois, $dureeFerme,
        $loyerMensuel, $charges, $tvaApp, $tvaTaux, $perio,
        $provTf, $techPct, $honoLoc, $honoBail, $honoChg,
        $cpGen, $cpLoyer,
        $indiceType, $indiceTrim, $indiceVal, $depotGar, $nbTermesDG,
        $candType, ($candType === 'physique' ? $candNom : null), ($candType === 'physique' ? $candPrenom : null),
        ($candType === 'societe' ? $candRaison : null),
        $candSiren ?: null, $candEmail ?: null, $candTel ?: null,
        $candRepNom ?: null, $candRepQual ?: null,
        $candAdresse ?: null, $candNaissD, $candNaissL ?: null, $candNat ?: null,
        $garPresent, ($garPresent ? $garType : null),
        ($garPresent && $garType==='physique' ? $garNom : null), ($garPresent && $garType==='physique' ? $garPrenom : null),
        ($garPresent && $garType==='societe' ? $garRaison : null), ($garPresent ? ($garSiren ?: null) : null),
        ($garPresent ? ($garAdresse ?: null) : null), ($garPresent ? $garNaissD : null), ($garPresent ? ($garNaissL ?: null) : null),
        ($garPresent ? ($garEmail ?: null) : null), ($garPresent ? ($garTel ?: null) : null),
        ($garPresent ? $garMontant : null), ($garPresent ? $garDuree : null), ($garPresent ? $garSolid : 1),
        json_encode($meta, JSON_UNESCAPED_UNICODE), $origin, $userId ?: null,
    ]);
    $bailId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>'Création échouée : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

$candLabel = $candType === 'physique' ? trim($candPrenom . ' ' . $candNom) : $candRaison;
echo json_encode([
    'ok'          => true,
    'bail_id'     => $bailId,
    'numero_bail' => $numeroBail,
    'statut'      => 'projet',
    'candidat'    => $candLabel,
    'redirect'    => (function_exists('app_url') ? app_url('/bail_360.php?id=' . $bailId) : '/bail_360.php?id=' . $bailId),
    'message'     => 'Projet de bail ' . $numeroBail . ' créé (candidat : ' . $candLabel . ').',
], JSON_UNESCAPED_UNICODE);
