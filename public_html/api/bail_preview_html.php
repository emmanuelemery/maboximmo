<?php
/**
 * api/bail_preview_html.php — Rend l'APERÇU du bail avec le MÊME générateur que le PDF
 * (bail_commercial_articles_html), à partir des valeurs EN COURS D'ÉDITION (non enregistrées).
 * Garantit un aperçu strictement identique au PDF (textes, tableaux, RIB, champs).
 *
 * POST JSON : le payload belBuildPayload() (bien_id, bail_id?, candidat, garant, loyer, dates…)
 *   → { ok, html }
 * Auth : user + scope société (bypass admin).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bail_commercial_pdf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$isAdmin=((int)($_SESSION['id_role']??0)===1); $userSoc=(int)($_SESSION['id_societe']??0);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$bienId=(int)($body['bien_id']??0);
if ($bienId<=0) exit(json_encode(['ok'=>false,'error'=>'bien_id requis']));

// Bien + immeuble + propriétaire (mêmes alias que la requête du contexte PDF).
$sql = "SELECT b.reference_bien, b.designation, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
        b.code_postal AS bien_cp, b.surface_habitable, b.numero_lot AS bien_numero_lot_src, b.id_immeuble,
        b.description AS bien_description, b.etage AS bien_etage,
        b.bien_en_copropriete, b.lot_tantiemes AS bien_tantiemes_src, b.copro_nb_lots,
        b.id_societe AS bien_soc, b.id_agence AS bien_age, b.id AS id_bien, b.id_proprietaire,
        i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
        p.id AS proprio_id, p.id_tiers AS proprio_tiers_id, tp.infos_juridiques_json AS proprio_juridique_json,
        COALESCE(NULLIF(p.societe,''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
        COALESCE(NULLIF(tp.nom_affichage,''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom
        FROM biens b
        LEFT JOIN immeubles i     ON i.id = b.id_immeuble
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        LEFT JOIN tiers tp        ON tp.id = p.id_tiers
        WHERE b.id = ? LIMIT 1";
$st=$pdo->prepare($sql); $st->execute([$bienId]); $b=$st->fetch(PDO::FETCH_ASSOC);
if (!$b){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bien introuvable'])); }
if (!$isAdmin && !empty($b['bien_soc']) && (int)$b['bien_soc']!==$userSoc){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }

// Base de GESTION (société/agence) + fiche du CANDIDAT, reprises du bail ENREGISTRÉ pour que
// l'aperçu live corresponde au PDF final (le bien peut ne pas porter la société/agence ; elles
// sont sur le bail — et la fiche du candidat porte les infos juridiques du preneur).
$belBase = [];
$bidPrev = (int)($body['bail_id'] ?? 0);
if ($bidPrev > 0) {
    try {
        $qb = $pdo->prepare("SELECT bb.id_societe, bb.id_agence,
                                    tc.infos_juridiques_json AS preneur_juridique_json,
                                    tc.raison_sociale AS preneur_tiers_raison,
                                    COALESCE(NULLIF(tc.nom_affichage,''), tc.raison_sociale, CONCAT_WS(' ', tc.prenom, tc.nom)) AS preneur_tiers_nom
                               FROM bien_baux bb
                               LEFT JOIN tiers tc ON tc.id = bb.candidat_tiers_id
                              WHERE bb.id = ? LIMIT 1");
        $qb->execute([$bidPrev]);
        $belBase = $qb->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $belBase = []; }
}

// Helpers de mapping payload → colonnes bien_baux.
$cand = is_array($body['candidat']??null) ? $body['candidat'] : [];
$gar  = is_array($body['garant']??null)   ? $body['garant']   : [];
$candType = (($cand['type']??'societe')==='physique') ? 'physique' : 'societe';
$garType  = (($gar['type']??'physique')==='societe') ? 'societe' : 'physique';
$garPresent = !empty($gar['present']) ? 1 : 0;
$dateOk = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : null;
$loyerAnnuel = (float)($body['loyer_annuel_ht'] ?? 0);
$loyerMensuel = $loyerAnnuel > 0 ? round($loyerAnnuel/12, 2) : null;
$nbDG = ($body['nb_termes_garantie'] ?? null) !== null ? (int)$body['nb_termes_garantie'] : null;
$enCopro = !empty($body['en_copropriete']) ? 1 : 0;
$honoBail = ($body['honoraires_bailleur'] ?? null) !== null && $body['honoraires_bailleur'] !== '' ? (float)$body['honoraires_bailleur'] : null;
$honoLoc  = ($body['honoraires_locataire'] ?? null) !== null && $body['honoraires_locataire'] !== '' ? (float)$body['honoraires_locataire'] : null;

// Ligne bien_baux synthétique (valeurs en cours d'édition) + jointures du bien.
$bail = array_merge($b, [
    'statut'                 => 'projet',
    'numero_bail'            => (string)($body['numero_bail'] ?? ''),
    'bailleur_representant_nom'     => (string)($body['bailleur_representant_nom'] ?? ''),
    'bailleur_representant_qualite' => (string)($body['bailleur_representant_qualite'] ?? ''),
    'id_societe'             => (int)($b['bien_soc'] ?? 0) ?: (int)($belBase['id_societe'] ?? 0),
    'id_agence'              => (int)($b['bien_age'] ?? 0) ?: (int)($belBase['id_agence'] ?? 0),
    'preneur_juridique_json' => (string)($belBase['preneur_juridique_json'] ?? ''),
    'preneur_tiers_raison'   => (string)($belBase['preneur_tiers_raison'] ?? ''),
    'preneur_tiers_nom'      => (string)($belBase['preneur_tiers_nom'] ?? ''),
    'destination_activite'   => (string)($body['destination'] ?? ''),
    'date_prise_effet'       => $dateOk($body['date_prise_effet'] ?? ''),
    'prorata_date_debut'     => $dateOk($body['prorata_date_debut'] ?? ''),
    'duree_mois'             => (int)($body['duree_mois'] ?? 108) ?: 108,
    'duree_ferme_ans'        => ($body['duree_ferme_ans'] ?? null) !== null ? (int)$body['duree_ferme_ans'] : null,
    'loyer_mensuel_hc'       => $loyerMensuel,
    'charges_mensuelles'     => ($body['charges_mensuelles'] ?? null) !== null && $body['charges_mensuelles'] !== '' ? (float)$body['charges_mensuelles'] : null,
    'indice_type'            => (string)($body['indice_type'] ?? 'ILC'),
    'indice_trimestre'       => (string)($body['indice_trimestre'] ?? ''),
    'indice_valeur'          => ($body['indice_valeur'] ?? null) !== null && $body['indice_valeur'] !== '' ? (float)$body['indice_valeur'] : null,
    'nb_termes_garantie'     => $nbDG,
    'depot_garantie'         => ($nbDG && $loyerMensuel) ? round($nbDG*$loyerMensuel, 2) : null,
    'erp_local'              => !empty($body['erp_local']) ? 1 : 0,
    'option_achat'           => !empty($body['option_achat']) ? 1 : 0,
    'option_achat_prix'      => ($body['option_achat_prix'] ?? null) !== null && $body['option_achat_prix'] !== '' ? (float)$body['option_achat_prix'] : null,
    'option_achat_delai_mois'=> ($body['option_achat_delai_mois'] ?? null) !== null && $body['option_achat_delai_mois'] !== '' ? (int)$body['option_achat_delai_mois'] : null,
    'tva_applicable'         => array_key_exists('tva_applicable',$body) ? (!empty($body['tva_applicable'])?1:0) : 1,
    'tva_taux'               => !empty($body['tva_applicable']) ? (float)($body['tva_taux'] ?? 20) : 0,
    'periodicite_paiement'   => (($body['periodicite'] ?? 'mensuelle')==='trimestrielle') ? 'trimestrielle' : 'mensuelle',
    'provision_tf_mensuelle' => ($body['provision_tf'] ?? null) !== null && $body['provision_tf'] !== '' ? (float)$body['provision_tf'] : null,
    'honoraires_gestion_tech_pct' => ($body['honoraires_tech_pct'] ?? null) !== null && $body['honoraires_tech_pct'] !== '' ? (float)$body['honoraires_tech_pct'] : null,
    'honoraires_locataire_ttc' => $honoLoc,
    'honoraires_bailleur_ttc'  => $honoBail,
    'honoraires_charge'      => ($honoBail!==null && $honoLoc!==null) ? 'partage' : ($honoBail!==null ? 'bailleur' : 'locataire'),
    // Champs modèle FNAIM (édition en cours)
    'taux_penalite'          => ($body['taux_penalite'] ?? null) !== null && $body['taux_penalite'] !== '' ? (float)$body['taux_penalite'] : 10.0,
    'droit_entree'           => ($body['droit_entree'] ?? null) !== null && $body['droit_entree'] !== '' ? (float)$body['droit_entree'] : null,
    'honoraires_pct_preneur' => ($body['honoraires_pct_preneur'] ?? null) !== null && $body['honoraires_pct_preneur'] !== '' ? (float)$body['honoraires_pct_preneur'] : null,
    'honoraires_pct_bailleur'=> ($body['honoraires_pct_bailleur'] ?? null) !== null && $body['honoraires_pct_bailleur'] !== '' ? (float)$body['honoraires_pct_bailleur'] : null,
    'conditions_particulieres'       => (string)($body['conditions_particulieres'] ?? ''),
    'conditions_particulieres_loyer' => (string)($body['conditions_particulieres_loyer'] ?? ''),
    'bien_designation'       => (string)($body['bien_designation'] ?? ''),
    'en_copropriete'         => $enCopro,
    'lot_copropriete'        => $enCopro ? (string)($body['lot_copropriete'] ?? '') : '',
    'lot_tantiemes'          => $enCopro ? (string)($body['lot_tantiemes'] ?? '') : '',
    'travaux_realises_3ans'  => (string)($body['travaux_realises'] ?? ''),
    'travaux_prevus_3ans'    => (string)($body['travaux_prevus'] ?? ''),
    // Preneur
    'locataire_type'          => $candType,
    'locataire_nom'           => (string)($cand['nom'] ?? ''),
    'locataire_prenom'        => (string)($cand['prenom'] ?? ''),
    'locataire_raison_sociale'=> (string)($cand['raison_sociale'] ?? ''),
    'locataire_siren'         => (string)($cand['siren'] ?? ''),
    'locataire_email'         => (string)($cand['email'] ?? ''),
    'locataire_telephone'     => (string)($cand['telephone'] ?? ''),
    'locataire_representant_nom'     => (string)($cand['representant_nom'] ?? ''),
    'locataire_representant_qualite' => (string)($cand['representant_qualite'] ?? ''),
    'locataire_adresse'       => (string)($cand['adresse'] ?? ''),
    'locataire_date_naissance'=> $dateOk($cand['date_naissance'] ?? ''),
    'locataire_lieu_naissance'=> (string)($cand['lieu_naissance'] ?? ''),
    'locataire_nationalite'   => (string)($cand['nationalite'] ?? ''),
    // Garant
    'garant_present'     => $garPresent,
    'garant_type'        => $garType,
    'garant_nom'         => (string)($gar['nom'] ?? ''),
    'garant_prenom'      => (string)($gar['prenom'] ?? ''),
    'garant_raison_sociale' => (string)($gar['raison_sociale'] ?? ''),
    'garant_siren'       => (string)($gar['siren'] ?? ''),
    'garant_adresse'     => (string)($gar['adresse'] ?? ''),
    'garant_date_naissance' => $dateOk($gar['date_naissance'] ?? ''),
    'garant_lieu_naissance' => (string)($gar['lieu_naissance'] ?? ''),
    'garant_montant_max' => ($gar['montant_max'] ?? null) !== null && $gar['montant_max'] !== '' ? (float)$gar['montant_max'] : null,
    'garant_duree_ans'   => ($gar['duree_ans'] ?? null) !== null && $gar['duree_ans'] !== '' ? (int)$gar['duree_ans'] : null,
    'garant_solidaire'   => array_key_exists('solidaire',$gar) ? (!empty($gar['solidaire'])?1:0) : 1,
]);

try {
    $ctx = bail_commercial_ctx_build($pdo, $bail);
    if (!$ctx) exit(json_encode(['ok'=>false,'error'=>'Contexte vide']));
    $ctx['signatures'] = []; // aperçu = pas de signatures
    $html = bail_commercial_corps_fnaim($ctx);   // modèle FNAIM exact + annexe (un seul document)
    echo json_encode(['ok'=>true, 'html'=>$html], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); exit(json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE));
}
