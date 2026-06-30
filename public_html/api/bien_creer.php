<?php
declare(strict_types=1);
/**
 * api/bien_creer.php — Création RÉELLE d'un bien depuis l'écran bien_nouveau.php (Phase 6).
 *
 * Crée/attache, dans une transaction et de façon idempotente :
 *   1. l'IMMEUBLE (immeuble_resolve — anti-doublon place_id/adresse, ou id reconnu)
 *      + nom convention + persistance de l'enrichissement public
 *   2. le PROPRIÉTAIRE (repris si existant, sinon créé : tiers + proprietaires)
 *   3. le BIEN (réf via ref_generator, rattaché immeuble + proprio + type)
 *   4. le MANDAT (ensure_mandat) + dossier_vente si vente (dv_ensure_for_bien)
 *
 * Réutilise les writers existants (rien de réinventé). Contrat constant.
 *
 * POST JSON :
 *  { immeuble:{id,adresse_1,adresse_2,code_postal,ville,lat,lng,place_id,nom},
 *    enrichissement:{cadastre:{reference},plu:{type},altitude,registre:{immatriculation,construction,date_maj,nb_lots}},
 *    proprietaire:{existingTiers,type,nom,contact,societe,contact_choice},
 *    bien:{type,pieces,etage,mandat} }
 *
 * Réponse : { ok, data:{bien_id,reference,immeuble_id}, source, confidence, error }
 *
 * Accès : admin / super admin (pilote).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/immeuble_link.php';
require_once __DIR__ . '/../inc/bien_missions.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/ref_generator.php';
require_once __DIR__ . '/../inc/bien_type_helper.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
function reply($ok,$data,$conf,$err=null){ echo json_encode(['ok'=>$ok,'data'=>$data,'source'=>'bien_creer','confidence'=>$conf,'error'=>$err], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); reply(false,null,'manquant','POST requis'); }
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) reply(false,null,'manquant','JSON invalide');

$pdo = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0) ?: null;
$agenceId  = (int)($_SESSION['id_agence']  ?? 0) ?: null;
$userId    = (int)($_SESSION['user_id']    ?? 0) ?: null;

// Agence d'attribution choisie dans l'écran (boutons étape 3).
// Sécurité multi-tenant : on n'accepte que si elle appartient à la société de l'user.
$agenceChoisie = (int)($body['id_agence'] ?? 0);
if ($agenceChoisie > 0) {
    try {
        $chkAg = $pdo->prepare("SELECT id FROM agences WHERE id = ? AND id_societe = ? LIMIT 1");
        $chkAg->execute([$agenceChoisie, $societeId]);
        if ((int)$chkAg->fetchColumn() === $agenceChoisie) { $agenceId = $agenceChoisie; }
    } catch (Throwable) { /* ignore : on garde l'agence de session */ }
}

$imm = is_array($body['immeuble'] ?? null) ? $body['immeuble'] : [];
$enr = is_array($body['enrichissement'] ?? null) ? $body['enrichissement'] : [];
$pro = is_array($body['proprietaire'] ?? null) ? $body['proprietaire'] : [];
$bi  = is_array($body['bien'] ?? null) ? $body['bien'] : [];

$adresse1 = trim((string)($imm['adresse_1'] ?? ''));
if ($adresse1 === '' && empty($imm['id'])) reply(false,null,'manquant','Adresse de l\'immeuble requise');

try {
    $pdo->beginTransaction();

    // ── 1. IMMEUBLE (idempotent) ──
    $immId = immeuble_resolve($pdo, [
        'id_immeuble_selected' => (int)($imm['id'] ?? 0),
        'adresse_1'   => $adresse1,
        'adresse_2'   => (string)($imm['adresse_2'] ?? ''),
        'code_postal' => (string)($imm['code_postal'] ?? ''),
        'ville'       => (string)($imm['ville'] ?? ''),
        'latitude'    => $imm['lat'] ?? null,
        'longitude'   => $imm['lng'] ?? null,
        'google_place_id' => (string)($imm['place_id'] ?? ''),
        'id_societe'  => $societeId,
        'id_agence'   => $agenceId,
    ]);
    if ($immId <= 0) { $pdo->rollBack(); reply(false,null,'manquant','Immeuble non résolu'); }

    // Nom convention (si vide) + agence de l'immeuble en repli
    $nom = trim((string)($imm['nom'] ?? ''));
    $immRow = $pdo->query("SELECT id_agence, nom_immeuble FROM immeubles WHERE id=".(int)$immId)->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($nom !== '' && empty($immRow['nom_immeuble'])) {
        try { $pdo->prepare("UPDATE immeubles SET nom_immeuble=? WHERE id=? AND (nom_immeuble IS NULL OR nom_immeuble='')")->execute([$nom,$immId]); } catch (Throwable) {}
    }
    if (!$agenceId && !empty($immRow['id_agence'])) $agenceId = (int)$immRow['id_agence'];

    // Persistance enrichissement public (best-effort — n'échoue pas la création)
    try {
        $cad = is_array($enr['cadastre'] ?? null) ? $enr['cadastre'] : [];
        $plu = is_array($enr['plu'] ?? null) ? $enr['plu'] : [];
        $reg = is_array($enr['registre'] ?? null) ? $enr['registre'] : [];
        $set=[]; $par=[]; $now=date('Y-m-d H:i:s');
        if (!empty($cad['reference'])) { $set[]='parcelle_reference=?'; $par[]=substr((string)$cad['reference'],0,20); }
        if (!empty($plu['type']))      { $set[]='zone_plu=?'; $par[]=substr((string)$plu['type'],0,20); }
        if (isset($enr['altitude']) && $enr['altitude']!=='') { $set[]='altitude=?'; $par[]=(int)$enr['altitude']; }
        if (!empty($reg['immatriculation'])) { $set[]='registre_copro_immatriculation=?'; $par[]=substr((string)$reg['immatriculation'],0,20); }
        if (!empty($reg['construction']))    { $set[]='registre_copro_periode=?'; $par[]=substr((string)$reg['construction'],0,40); }
        if (!empty($reg['date_maj']))        { $set[]='registre_copro_maj=?'; $par[]=substr((string)$reg['date_maj'],0,10); }
        if (!empty($reg['nb_lots']) && (int)$reg['nb_lots']>0) { $set[]='copro_nb_lots=?'; $par[]=(int)$reg['nb_lots']; }
        if ($set) {
            $set[]='enrichissement_public_json=?'; $par[]=json_encode($enr, JSON_UNESCAPED_UNICODE);
            $set[]='enrichi_cadastre_le=?'; $par[]=$now; $set[]='enrichi_registre_le=?'; $par[]=$now; $set[]='enrichi_risques_le=?'; $par[]=$now;
            $par[]=$immId;
            $pdo->prepare("UPDATE immeubles SET ".implode(',',$set)." WHERE id=?")->execute($par);
        }
    } catch (Throwable) { /* colonnes d'enrichissement non migrées : on ignore */ }

    // ── 2. PROPRIÉTAIRE ──
    $proprioId = 0;
    $existingProprio = (int)($pro['existingProprio'] ?? 0);
    $existingTiers = (int)($pro['existingTiers'] ?? 0);
    $contact = trim((string)($pro['contact'] ?? ''));
    $isEmail = strpos($contact,'@') !== false;

    // Repris directement par son id (sélection dans le modal « propriétaires de l'immeuble »)
    if ($existingProprio > 0) {
        $chk=$pdo->prepare("SELECT id FROM proprietaires WHERE id=?"); $chk->execute([$existingProprio]);
        $proprioId=(int)$chk->fetchColumn();
    }

    if (!$proprioId && $existingTiers > 0) {
        // Repris : retrouve (ou crée) le proprietaires lié à ce tiers
        $st=$pdo->prepare("SELECT id FROM proprietaires WHERE id_tiers=? LIMIT 1"); $st->execute([$existingTiers]);
        $proprioId=(int)$st->fetchColumn();
        if (!$proprioId) {
            $t=$pdo->prepare("SELECT type_tiers,nom,prenom,raison_sociale,email,telephone FROM tiers WHERE id=?"); $t->execute([$existingTiers]); $tr=$t->fetch(PDO::FETCH_ASSOC)?:[];
            $tp = ($tr['type_tiers']??'')==='personne_morale' ? 'morale' : 'physique';
            $pdo->prepare("INSERT INTO proprietaires (nom,prenom,societe,email,telephone,type_personne,id_agence,id_tiers,actif,date_creation,date_modification)
                           VALUES (?,?,?,?,?,?,?,?,1,NOW(),NOW())")
                ->execute([(string)($tr['nom']??''),(string)($tr['prenom']??''),(string)($tr['raison_sociale']??''),(string)($tr['email']??''),(string)($tr['telephone']??''),$tp,$agenceId,$existingTiers]);
            $proprioId=(int)$pdo->lastInsertId();
        }
    } elseif (!$proprioId) {
        // Nouveau : crée un tiers (best-effort) + proprietaires
        $soc = is_array($pro['societe'] ?? null) ? $pro['societe'] : null;
        $idTiers = null;
        try {
            if ($soc) {
                $aff = (string)($soc['raison_sociale'] ?? '');
                // Snapshot juridique Pappers complet (capital, dirigeants, NAF…) persisté sur le tiers
                $jurJson = json_encode($soc, JSON_UNESCAPED_UNICODE);
                try {
                    $pdo->prepare("INSERT INTO tiers (id_societe,id_agence,type_tiers,raison_sociale,forme_juridique,siren,siret,nom_affichage,id_user_createur,actif,infos_juridiques_json,infos_juridiques_maj)
                                   VALUES (?,?, 'personne_morale', ?,?,?,?,?,?,1,?,NOW())")
                        ->execute([$societeId,$agenceId,$aff,(string)($soc['forme_juridique']??''),(string)($soc['siren']??''),(string)(($soc['siege']['siret']??'')),$aff,$userId,$jurJson]);
                } catch (Throwable) {
                    // colonnes infos_juridiques non migrées : insert sans le snapshot
                    $pdo->prepare("INSERT INTO tiers (id_societe,id_agence,type_tiers,raison_sociale,forme_juridique,siren,siret,nom_affichage,id_user_createur,actif)
                                   VALUES (?,?, 'personne_morale', ?,?,?,?,?,?,1)")
                        ->execute([$societeId,$agenceId,$aff,(string)($soc['forme_juridique']??''),(string)($soc['siren']??''),(string)(($soc['siege']['siret']??'')),$aff,$userId]);
                }
            } else {
                $aff = trim((string)($pro['nom'] ?? ''));
                $pdo->prepare("INSERT INTO tiers (id_societe,id_agence,type_tiers,nom,email,telephone,nom_affichage,id_user_createur,actif)
                               VALUES (?,?, 'personne_physique', ?,?,?,?,?,1)")
                    ->execute([$societeId,$agenceId,$aff,($isEmail?$contact:''),($isEmail?'':$contact),$aff,$userId]);
            }
            $idTiers=(int)$pdo->lastInsertId();
        } catch (Throwable) { $idTiers=null; }

        if ($soc) {
            $pdo->prepare("INSERT INTO proprietaires (nom,prenom,societe,email,telephone,type_personne,id_agence,id_tiers,actif,date_creation,date_modification)
                           VALUES ('','',?,?,?, 'morale', ?,?,1,NOW(),NOW())")
                ->execute([(string)($soc['raison_sociale']??''),($isEmail?$contact:''),($isEmail?'':$contact),$agenceId,$idTiers]);
        } else {
            $pdo->prepare("INSERT INTO proprietaires (nom,prenom,societe,email,telephone,type_personne,id_agence,id_tiers,actif,date_creation,date_modification)
                           VALUES (?,'','',?,?, 'physique', ?,?,1,NOW(),NOW())")
                ->execute([trim((string)($pro['nom']??'')),($isEmail?$contact:''),($isEmail?'':$contact),$agenceId,$idTiers]);
        }
        $proprioId=(int)$pdo->lastInsertId();
    }

    // ── 3. BIEN ──
    $typeCode = strtolower(trim((string)($bi['type'] ?? '')));
    $tt = function_exists('bien_type_resolve') ? bien_type_resolve($pdo,$typeCode) : ['id_bien_type'=>null,'id_type_bien'=>null];
    $idTypeBien = (int)($tt['id_type_bien'] ?? 0);
    if ($idTypeBien <= 0) { try { $idTypeBien=(int)$pdo->query("SELECT id FROM types_bien_legacy ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable) {} }
    if ($idTypeBien <= 0) { try { $idTypeBien=(int)$pdo->query("SELECT id FROM types_bien ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable) {} }
    $idBienType = (int)($tt['id_bien_type'] ?? 0) ?: null;

    // Référence : générateur agence/CRG ; repli TMP si pas d'agence.
    // On TRANSMET le contexte (type, ville, user) au générateur — sinon le pattern
    // {TYPE3}-{VILLE3}-{YYMM}-{SEQ:04}-{USER3} sort vide/XXX (réf « --2606-0037-XXX »).
    $userRef = [];
    if ($userId) { try { $u=$pdo->prepare("SELECT prenom,nom FROM users WHERE id=?"); $u->execute([$userId]); $userRef=$u->fetch(PDO::FETCH_ASSOC)?:[]; } catch (Throwable) {} }
    $ref='';
    try {
        if ($agenceId || $immId) $ref = ref_generate_bien($pdo, [
            'id_immeuble'    => $immId,
            'id_agence'      => $agenceId,
            'type_bien_code' => $typeCode,
            'ville'          => (string)($imm['ville'] ?? ''),
            'user'           => $userRef,
        ]);
    } catch (Throwable) {}
    if ($ref==='') $ref = 'TMP-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,5));

    $nbPieces = (int)preg_replace('/\D+/','',(string)($bi['pieces'] ?? '')) ?: null;
    $designation = $nom !== '' ? $nom : $adresse1;

    $pdo->prepare("INSERT INTO biens
        (reference_bien, designation, statut_bien, id_societe, id_agence, id_user_actuel, id_proprietaire, id_immeuble,
         id_type_bien, id_bien_type, adresse_1, code_postal, ville, latitude, longitude, etage, nb_pieces,
         date_creation, date_modification)
        VALUES (?,?, 'actif', ?,?,?,?,?, ?,?, ?,?,?,?,?,?,?, NOW(),NOW())")
      ->execute([
        $ref, $designation, $societeId, $agenceId, $userId, ($proprioId?:null), $immId,
        $idTypeBien, $idBienType,
        $adresse1, (string)($imm['code_postal']??'') ?: null, (string)($imm['ville']??'') ?: null,
        ($imm['lat']??null)?:null, ($imm['lng']??null)?:null,
        (string)($bi['etage']??'') ?: null, $nbPieces,
      ]);
    $bienId=(int)$pdo->lastInsertId();

    // ── 4. MANDAT + dossier vente ──
    $mandatType = strtolower(trim((string)($bi['mandat'] ?? '')));
    $opts = ['id_agence'=>$agenceId,'id_proprietaire'=>($proprioId?:null),'id_user'=>$userId];
    if ($mandatType === 'vente') {
        ensure_mandat_vente($pdo,$bienId,$opts);
        try { dv_ensure_for_bien($pdo,$bienId,['id_user'=>$userId]); } catch (Throwable) {}
    } elseif ($mandatType === 'location') {
        ensure_mandat($pdo,$bienId,'location',$opts);
    }
    try { derive_type_commercialisation($pdo,$bienId); } catch (Throwable) {}

    $pdo->commit();

    // ── Ingestion AUTO des documents publics (hors transaction : appels réseau) ──
    // ERP (Géorisques) + photos publiques (Google Places) → GED, liés immeuble + bien.
    $docsAuto = ['erp'=>null,'photos'=>0];
    $lat = (float)($imm['lat'] ?? 0); $lng = (float)($imm['lng'] ?? 0);
    try {
        require_once __DIR__ . '/../inc/erp_ingest.php';
        $erp = erp_ingest_to_ged($pdo, $immId, $lat, $lng, ['bien_id'=>$bienId,'user_id'=>$userId,'societe_id'=>$societeId,'agence_id'=>$agenceId]);
        $docsAuto['erp'] = !empty($erp['ok']) ? ($erp['doc_id'] ?? true) : null;
    } catch (Throwable) {}
    try {
        $placeId = (string)($imm['place_id'] ?? '');
        if ($placeId !== '') {
            require_once __DIR__ . '/../inc/photos_ingest.php';
            $ph = photos_ingest_to_ged($pdo, $immId, $placeId, ['bien_id'=>$bienId,'user_id'=>$userId,'societe_id'=>$societeId,'agence_id'=>$agenceId]);
            $docsAuto['photos'] = (int)($ph['count'] ?? 0);
        }
    } catch (Throwable) {}

    // ── VALIDATION de l'immeuble (après chargement des données publiques) ──
    try {
        $pdo->prepare("UPDATE immeubles SET valide_le = NOW(), valide_par = ? WHERE id = ? AND valide_le IS NULL")
            ->execute([$userId, $immId]);
    } catch (Throwable) {}

    reply(true, ['bien_id'=>$bienId,'reference'=>$ref,'immeuble_id'=>$immId,'proprietaire_id'=>$proprioId,'docs_auto'=>$docsAuto], 'certain');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    reply(false,null,'manquant','Création échouée : '.$e->getMessage());
}
