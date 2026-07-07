<?php
/**
 * api/bail_save.php — Met à jour un PROJET de bail (statuts 'projet' ou 'envoye').
 * Un bail 'signe' est figé : toute modification passe par un avenant (étape ultérieure).
 *
 * POST JSON : { bail_id, candidat:{...}, destination, date_prise_effet, duree_mois,
 *   duree_ferme_ans, loyer_annuel_ht, charges_mensuelles, indice_type, indice_trimestre,
 *   indice_valeur, nb_termes_garantie, erp_local, option_achat, option_achat_prix,
 *   option_achat_delai_mois }
 * Auth : user + scope société (bypass admin, exception bailleur).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$userId=(int)($_SESSION['user_id']??0); $userSoc=(int)($_SESSION['id_societe']??0); $isAdmin=((int)($_SESSION['id_role']??0)===1);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$bailId=(int)($body['bail_id']??0);
if ($bailId<=0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));

$st=$pdo->prepare("SELECT bb.id, bb.statut, bb.id_societe, b.id_proprietaire
    FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?");
$st->execute([$bailId]); $bail=$st->fetch(PDO::FETCH_ASSOC);
if (!$bail){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($bail['id_societe']) && (int)$bail['id_societe']!==$userSoc){
    $ok=false;
    if (in_array((int)($_SESSION['id_role']??0),[9,10],true) && (int)($bail['id_proprietaire']??0)>0){
        $c=$pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId,(int)$bail['id_proprietaire']]); $ok=(bool)$c->fetchColumn();
    }
    if(!$ok){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}
if (!in_array($bail['statut'], ['projet','envoye'], true)) {
    exit(json_encode(['ok'=>false,'error'=>'Ce bail est '.$bail['statut'].' : modification possible uniquement par avenant.'], JSON_UNESCAPED_UNICODE));
}

$cand=is_array($body['candidat']??null)?$body['candidat']:[];
$candType=(($cand['type']??'societe')==='physique')?'physique':'societe';
$candNom=trim((string)($cand['nom']??'')); $candRaison=trim((string)($cand['raison_sociale']??''));
$candAdresse=trim((string)($cand['adresse']??''));
$candNaissD=trim((string)($cand['date_naissance']??'')); $candNaissD=preg_match('/^\d{4}-\d{2}-\d{2}$/',$candNaissD)?$candNaissD:null;
$candNaissL=trim((string)($cand['lieu_naissance']??'')); $candNat=trim((string)($cand['nationalite']??''));
$gar=is_array($body['garant']??null)?$body['garant']:[];
$garPresent=!empty($gar['present'])?1:0;
$garType=(($gar['type']??'physique')==='societe')?'societe':'physique';
$garNaissD=trim((string)($gar['date_naissance']??'')); $garNaissD=preg_match('/^\d{4}-\d{2}-\d{2}$/',$garNaissD)?$garNaissD:null;
$garMontant=($gar['montant_max']??null)!==null&&$gar['montant_max']!==''?(float)$gar['montant_max']:null;
$garDuree=($gar['duree_ans']??null)!==null&&$gar['duree_ans']!==''?(int)$gar['duree_ans']:null;
$garSolid=array_key_exists('solidaire',$gar)?(!empty($gar['solidaire'])?1:0):1;
$tvaApp=array_key_exists('tva_applicable',$body)?(!empty($body['tva_applicable'])?1:0):1;
$tvaTaux=$tvaApp?(float)($body['tva_taux']??20):0;
$perio=(($body['periodicite']??'mensuelle')==='trimestrielle')?'trimestrielle':'mensuelle';
$provTf=($body['provision_tf']??null)!==null&&$body['provision_tf']!==''?(float)$body['provision_tf']:null;
$techPct=($body['honoraires_tech_pct']??null)!==null&&$body['honoraires_tech_pct']!==''?(float)$body['honoraires_tech_pct']:null;
$honoBail=($body['honoraires_bailleur']??null)!==null&&$body['honoraires_bailleur']!==''?(float)$body['honoraires_bailleur']:null;
$honoLoc=($body['honoraires_locataire']??null)!==null&&$body['honoraires_locataire']!==''?(float)$body['honoraires_locataire']:null;
$honoChg=($honoBail!==null&&$honoLoc!==null)?'partage':($honoBail!==null?'bailleur':'locataire');
$cpGen=trim((string)($body['conditions_particulieres']??''))?:null;
$cpLoyer=trim((string)($body['conditions_particulieres_loyer']??''))?:null;
$loyerAnnuel=(float)($body['loyer_annuel_ht']??0); $loyerMensuel=$loyerAnnuel>0?round($loyerAnnuel/12,2):null;
$nbDG=($body['nb_termes_garantie']??null)!==null?(int)$body['nb_termes_garantie']:null;
$depotGar=($nbDG&&$loyerMensuel)?round($nbDG*$loyerMensuel,2):null;
$dureeMois=(int)($body['duree_mois']??108)?:108;
$indiceType=in_array(($body['indice_type']??'ILC'),['IRL','ILC','ILAT','ICC','autre'],true)?$body['indice_type']:'ILC';

$sql="UPDATE bien_baux SET
    destination_activite=?, erp_local=?, option_achat=?, option_achat_prix=?, option_achat_delai_mois=?,
    date_prise_effet=?, duree_mois=?, duree_ferme_ans=?, loyer_mensuel_hc=?, charges_mensuelles=?,
    indice_type=?, indice_trimestre=?, indice_valeur=?, depot_garantie=?, nb_termes_garantie=?,
    locataire_type=?, locataire_nom=?, locataire_prenom=?, locataire_raison_sociale=?,
    locataire_siren=?, locataire_email=?, locataire_telephone=?,
    locataire_representant_nom=?, locataire_representant_qualite=?,
    locataire_adresse=?, locataire_date_naissance=?, locataire_lieu_naissance=?, locataire_nationalite=?,
    garant_present=?, garant_type=?, garant_nom=?, garant_prenom=?, garant_raison_sociale=?, garant_siren=?,
    garant_adresse=?, garant_date_naissance=?, garant_lieu_naissance=?, garant_email=?, garant_telephone=?,
    garant_montant_max=?, garant_duree_ans=?, garant_solidaire=?,
    tva_applicable=?, tva_taux=?, periodicite_paiement=?, provision_tf_mensuelle=?, honoraires_gestion_tech_pct=?,
    honoraires_locataire_ttc=?, honoraires_bailleur_ttc=?, honoraires_charge=?,
    conditions_particulieres=?, conditions_particulieres_loyer=?,
    updated_at=NOW()
    WHERE id=?";
try {
    $pdo->prepare($sql)->execute([
        trim((string)($body['destination']??'')) ?: null,
        !empty($body['erp_local'])?1:0, !empty($body['option_achat'])?1:0,
        ($body['option_achat_prix']??null)!==null?(float)$body['option_achat_prix']:null,
        ($body['option_achat_delai_mois']??null)!==null?(int)$body['option_achat_delai_mois']:null,
        (preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($body['date_prise_effet']??''))?$body['date_prise_effet']:null),
        $dureeMois, (($body['duree_ferme_ans']??null)!==null?(int)$body['duree_ferme_ans']:null),
        $loyerMensuel, (($body['charges_mensuelles']??null)!==null?(float)$body['charges_mensuelles']:null),
        $indiceType, trim((string)($body['indice_trimestre']??''))?:null,
        (($body['indice_valeur']??null)!==null?(float)$body['indice_valeur']:null), $depotGar, $nbDG,
        $candType, ($candType==='physique'?$candNom:null), ($candType==='physique'?trim((string)($cand['prenom']??'')):null),
        ($candType==='societe'?$candRaison:null),
        trim((string)($cand['siren']??''))?:null, trim((string)($cand['email']??''))?:null, trim((string)($cand['telephone']??''))?:null,
        trim((string)($cand['representant_nom']??''))?:null, trim((string)($cand['representant_qualite']??''))?:null,
        $candAdresse?:null, $candNaissD, $candNaissL?:null, $candNat?:null,
        $garPresent, ($garPresent?$garType:null),
        ($garPresent&&$garType==='physique'?trim((string)($gar['nom']??'')):null), ($garPresent&&$garType==='physique'?trim((string)($gar['prenom']??'')):null),
        ($garPresent&&$garType==='societe'?trim((string)($gar['raison_sociale']??'')):null), ($garPresent?trim((string)($gar['siren']??''))?:null:null),
        ($garPresent?trim((string)($gar['adresse']??''))?:null:null), ($garPresent?$garNaissD:null), ($garPresent?trim((string)($gar['lieu_naissance']??''))?:null:null),
        ($garPresent?trim((string)($gar['email']??''))?:null:null), ($garPresent?trim((string)($gar['telephone']??''))?:null:null),
        ($garPresent?$garMontant:null), ($garPresent?$garDuree:null), ($garPresent?$garSolid:1),
        $tvaApp, $tvaTaux, $perio, $provTf, $techPct,
        $honoLoc, $honoBail, $honoChg,
        $cpGen, $cpLoyer,
        $bailId,
    ]);
} catch (Throwable $e) { http_response_code(500); exit(json_encode(['ok'=>false,'error'=>'Enregistrement échoué : '.$e->getMessage()], JSON_UNESCAPED_UNICODE)); }

echo json_encode(['ok'=>true,'bail_id'=>$bailId,'message'=>'Projet de bail mis à jour.',
    'redirect'=>(function_exists('app_url')?app_url('/bail_360.php?id='.$bailId):'/bail_360.php?id='.$bailId)], JSON_UNESCAPED_UNICODE);
