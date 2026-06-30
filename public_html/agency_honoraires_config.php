<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Mes honoraires';
$bodyClass = '';
$robots = 'noindex, nofollow';

$pdo = db();
$idSociete = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : 0;

// ── Accès : super admin = TOUTES les sociétés ; managers (1,2,3,7) = LEUR société ──
$_roleHono = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSAhono  = (function_exists('is_super_admin') && is_super_admin()) || $_roleHono === 1;
// Managers autorisés à éditer le MODÈLE société + choisir une agence de leur société
$isMgrHono = $isSAhono || in_array($_roleHono, [1, 2, 3, 7], true);

$societesHono = [];
$isBaseHono = false;  // true = on édite le BARÈME DE BASE (id_societe=0, toutes sociétés)
if ($isSAhono) {
    // Super admin : sélecteur de société (toutes) + barème de base
    try { $societesHono = $pdo->query("SELECT id, nom FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    if (isset($_POST['societe_id_ctx']) || isset($_GET['societe_id'])) {
        $idSociete = (int)($_POST['societe_id_ctx'] ?? $_GET['societe_id']);  // 0 = barème de base
    }
    if ($idSociete === 0) $isBaseHono = true;
}
// (manager non-SA : $idSociete reste celle de sa session — pas de choix d'autres sociétés)

// ── Agence éditée (barème PAR AGENCE, option A) : 0 = MODÈLE société ──
// Managers : peuvent choisir « modèle société » OU une agence de leur société.
if ($isMgrHono) {
    $idAgence = (int)($_POST['agence_id_ctx'] ?? $_GET['agence_id'] ?? (isset($_SESSION['id_agence']) ? (int)$_SESSION['id_agence'] : 0));
} else {
    $idAgence = isset($_SESSION['id_agence']) ? (int)$_SESSION['id_agence'] : 0;
}
if ($isBaseHono) $idAgence = 0;  // barème de base : pas d'agence

// ── Brouillon en cours (provisoire) → ré-injecté dans le formulaire (sur GET) ──
$hasDraft = false; $draftMeta = null;
if (!is_post()) {
    try {
        $qD = $pdo->prepare("SELECT id, label, created_at FROM honoraires_versions
            WHERE id_societe = ? AND id_agence = ? AND statut = 'brouillon' ORDER BY id DESC LIMIT 1");
        $qD->execute([$idSociete, $idAgence]);
        $d = $qD->fetch(PDO::FETCH_ASSOC);
        if ($d) {
            $qP = $pdo->prepare("SELECT payload_json FROM honoraires_versions WHERE id = ?");
            $qP->execute([(int)$d['id']]);
            $payload = json_decode((string)$qP->fetchColumn(), true);
            if (is_array($payload)) {
                foreach ($payload as $k => $val) { if (!isset($_POST[$k])) $_POST[$k] = $val; }
                $hasDraft = true; $draftMeta = $d;
            }
        }
    } catch (Throwable $e) { /* table versions pas encore migrée → ignoré */ }
}
// Liste des agences de la société (sélecteur managers + SA)
$agencesHono = [];
if ($isMgrHono && $idSociete > 0) {
    try {
        $stA = $pdo->prepare("SELECT id, COALESCE(NULLIF(ville,''), nom_agence) AS lib FROM agences WHERE id_societe = ? ORDER BY lib");
        $stA->execute([$idSociete]);
        $agencesHono = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    // Sécurité : l'agence choisie doit appartenir à la société courante (sinon → modèle société)
    if ($idAgence > 0 && !in_array($idAgence, array_map(fn($a) => (int)$a['id'], $agencesHono), true)) {
        $idAgence = 0;
    }
}

// Plafonds honoraires location depuis la source unique (helper central + table societe_tarifs_honoraires).
// → garantit que l'auto-remplissage suit les plafonds 2026 (8,07 / 10,09 / 12,10 · EDL 3,03) où qu'ils soient mis à jour.
require_once __DIR__ . '/inc/honoraires_helper.php';
$plafondsZone = [];
$plafondEdl = '3.03';
if (function_exists('tarifs_honoraires_get')) {
    foreach (['non_tendue','tendue','tres_tendue'] as $_z) {
        $_t = tarifs_honoraires_get($pdo, $idSociete, $_z);
        $plafondsZone[$_z] = number_format((float)($_t['location_bail'] ?? 0), 2, '.', '');
        $plafondEdl        = number_format((float)($_t['edl'] ?? 3.03), 2, '.', '');
    }
} else {
    $plafondsZone = ['non_tendue'=>'8.07','tendue'=>'10.09','tres_tendue'=>'12.10'];
}

// Tarifs par ZONE (toutes les zones — une agence peut rayonner sur plusieurs zones).
// Source : table societe_tarifs_honoraires (via helper), fallback plafonds 2026.
$zonesDef = ['non_tendue'=>'Non tendue', 'tendue'=>'Tendue', 'tres_tendue'=>'Très tendue'];
$zoneTarifs = [];
foreach ($zonesDef as $zk => $zl) {
    $t = function_exists('tarifs_honoraires_get') ? tarifs_honoraires_get($pdo, $idSociete, $zk, $idAgence) : ['location_bail'=>null,'edl'=>3.03];
    // Brouillon (POST) prioritaire sur la valeur live
    $pLoc = $_POST['zt_loc'][$zk] ?? null;
    $pEdl = $_POST['zt_edl'][$zk] ?? null;
    $zoneTarifs[$zk] = [
        'loc' => ($pLoc !== null && $pLoc !== '') ? (string)$pLoc : ($t['location_bail'] !== null ? number_format((float)$t['location_bail'], 2, '.', '') : ''),
        'edl' => ($pEdl !== null && $pEdl !== '') ? (string)$pEdl : ($t['edl'] !== null ? number_format((float)$t['edl'], 2, '.', '') : '3.03'),
    ];
}

$errors = [];
$success = '';

// ─── Chargement de la config existante (par société + agence) ──────
$row = null; $rowOwn = false;  // $rowOwn : un barème PROPRE à l'agence existe déjà
if ($idSociete > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? AND id_agence = ? LIMIT 1");
        $stmt->execute([$idSociete, $idAgence]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $rowOwn = (bool)$row;
        if (!$row && $idAgence > 0) {
            // pas encore d'override agence → afficher le MODÈLE société (id_agence=0) comme défaut
            $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? AND id_agence = 0 LIMIT 1");
            $stmt->execute([$idSociete]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row && $idSociete > 0) {
            // ni agence ni modèle société → on affiche le BARÈME DE BASE (id_societe=0) comme défaut
            $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = 0 AND id_agence = 0 LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        // colonne id_agence pas encore migrée → ancien comportement société
        $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? LIMIT 1");
        $stmt->execute([$idSociete]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $rowOwn = (bool)$row;
    }
}

// ─── Pré-remplissage AUTO des mentions légales depuis les docs RH ───
// Source : colonnes officielles de l'agence éditée (prioritaire) puis de la société.
$_soc = $_age = [];
try { if ($idSociete > 0) { $s = $pdo->prepare("SELECT * FROM societes WHERE id=? LIMIT 1"); $s->execute([$idSociete]); $_soc = $s->fetch(PDO::FETCH_ASSOC) ?: []; } } catch (Throwable $e) {}
try { if ($idAgence  > 0) { $s = $pdo->prepare("SELECT * FROM agences  WHERE id=? LIMIT 1"); $s->execute([$idAgence]);  $_age = $s->fetch(PDO::FETCH_ASSOC) ?: []; } } catch (Throwable $e) {}
$_pick = function(array $cands) use ($_age, $_soc): string {
    foreach ($cands as [$src, $k]) { $arr = $src === 'a' ? $_age : $_soc; if (!empty($arr[$k])) return (string)$arr[$k]; }
    return '';
};
$legalAuto = [
    'carte_pro_numero'         => $_pick([['a','carte_pro_numero'],['s','carte_pro_numero'],['s','numero_carte_t']]),
    'carte_pro_cci'            => $_pick([['a','carte_pro_cci'],['s','carte_pro_cci'],['s','cci_carte_t']]),
    'garant_financier'         => $_pick([['a','garant_financier'],['s','garant_financier'],['s','garantie_financiere']]),
    'garant_financier_montant' => $_pick([['a','garant_montant'],['s','garant_montant']]),
    'assurance_rcp'            => $_pick([['s','assurance_rcp']]),
    'siret'                    => $_pick([['a','siret'],['s','siret']]),
    'rcs'                      => $_pick([['a','rcs']]),
    'tva_intra'                => $_pick([['a','tva_intracom'],['s','tva_intracom']]),
    // Médiateur de la consommation (niveau société)
    'mediation_organisme'      => $_pick([['s','mediateur_nom']]),
    'mediateur_adresse'        => $_pick([['s','mediateur_adresse']]),
    'mediateur_coordonnees'    => $_pick([['s','mediateur_coordonnees']]),
    'mediation_url'            => $_pick([['s','mediateur_url']]),
];
// Montant de garantie : si pas en colonne dédiée, on l'EXTRAIT de la chaîne RH
// (ex. « GALIAN-SMABTP - 1 100 000 € » → 1100000).
if (($legalAuto['garant_financier_montant'] ?? '') === '') {
    $_gfStr = (string)($_age['garant_financier'] ?? '') . ' ' . (string)($_soc['garantie_financiere'] ?? '') . ' ' . (string)($_soc['garant_financier'] ?? '');
    if (preg_match('/(\d[\d \x{00a0}.,]*\d)\s*(?:€|euros?)?/u', $_gfStr, $_mm)) {
        $_num = preg_replace('/\D/', '', $_mm[1]);
        if ($_num !== '' && (int)$_num >= 1000) $legalAuto['garant_financier_montant'] = $_num;
    }
}

// ─── Traitement POST ───────────────────────────────────────
if (is_post()) {
    verify_csrf('honoraires_config');

    $action = (string)($_POST['action'] ?? 'deployer');   // brouillon | deployer | reprendre
    $userIdH = function_exists('current_user_id') ? (int)current_user_id() : 0;

    // Snapshot du formulaire courant (toutes sections) — sert aux brouillons/versions.
    $hono_payload = function () : array {
        $skip = ['csrf_token','action','version_id','societe_id_ctx','agence_id_ctx'];
        $p = [];
        foreach ($_POST as $k => $v) { if (!in_array($k, $skip, true)) $p[$k] = $v; }
        return $p;
    };

    // ── REPRENDRE une version : la recharge comme brouillon (sans déployer) ──
    if ($action === 'reprendre') {
        $vid = (int)($_POST['version_id'] ?? 0);
        try {
            $q = $pdo->prepare("SELECT payload_json FROM honoraires_versions WHERE id = ? AND id_societe = ? AND id_agence = ?");
            $q->execute([$vid, $idSociete, $idAgence]);
            $pl = json_decode((string)$q->fetchColumn(), true);
            if (is_array($pl)) {
                $pdo->prepare("DELETE FROM honoraires_versions WHERE id_societe=? AND id_agence=? AND statut='brouillon'")->execute([$idSociete, $idAgence]);
                $pdo->prepare("INSERT INTO honoraires_versions (id_societe,id_agence,statut,label,payload_json,created_by,created_at) VALUES (?,?,'brouillon',?,?,?,NOW())")
                    ->execute([$idSociete, $idAgence, 'Repris de la version #'.$vid, json_encode($pl, JSON_UNESCAPED_UNICODE), $userIdH]);
                foreach ($pl as $k => $v) { $_POST[$k] = $v; }   // ré-injecte dans le formulaire
                $hasDraft = true;
                $success = 'Version reprise comme brouillon — ajustez puis cliquez « Déployer ».';
            } else { $errors[] = 'Version introuvable.'; }
        } catch (Throwable $e) { $errors[] = 'Reprise impossible : ' . $e->getMessage(); }
    } else {

    $str = static fn(string $k) => trim((string)post($k, ''));
    $flt = static fn(string $k) => post($k, '') !== '' ? (float)post($k) : null;

    // ─── VENTE ──
    $venteMethode      = $str('vente_methode') ?: 'tranches';
    $venteTauxUnique   = $flt('vente_taux_unique');
    $venteForfait      = $flt('vente_forfait');
    $venteCharge       = $str('vente_charge_par_defaut') ?: 'acquereur';
    $venteMin          = $flt('vente_montant_minimum');

    // Tranches : tableaux parallèles
    $trMin = $_POST['tr_min'] ?? [];
    $trMax = $_POST['tr_max'] ?? [];
    $trPct = $_POST['tr_pct'] ?? [];
    $tranches = [];
    if (is_array($trMin)) {
        foreach ($trMin as $i => $min) {
            $min = $min !== '' ? (float)$min : null;
            $max = isset($trMax[$i]) && $trMax[$i] !== '' ? (float)$trMax[$i] : null;
            $pct = isset($trPct[$i]) && $trPct[$i] !== '' ? (float)$trPct[$i] : null;
            if ($min === null && $max === null && $pct === null) continue;
            $tranches[] = ['min' => $min, 'max' => $max, 'pct' => $pct];
        }
    }
    $venteTranchesJson = $tranches ? json_encode($tranches, JSON_UNESCAPED_UNICODE) : null;

    // ─── LOCATION ──
    // Tarifs par zone (3 lignes) → societe_tarifs_honoraires
    // Normalise « 8,07 » / « 1 100 000 » → float (virgule décimale + espaces/nbsp)
    $numFr = static function ($s): ?float {
        $s = trim((string)$s);
        if ($s === '') return null;
        $s = str_replace(["\xc2\xa0", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    };
    $ztLocIn = is_array($_POST['zt_loc'] ?? null) ? array_map($numFr, $_POST['zt_loc']) : [];
    $ztEdlIn = is_array($_POST['zt_edl'] ?? null) ? array_map($numFr, $_POST['zt_edl']) : [];
    // societe_honoraires (legacy) : on garde la valeur "non tendue" comme référence
    $locZone           = 'non_tendue';
    $locLocataireM2    = $ztLocIn['non_tendue'] ?? null;
    $locEdlM2          = $ztEdlIn['non_tendue'] ?? null;
    $locBailleurPct    = $flt('location_honoraires_bailleur_pct');
    $locBailleurForfait = $flt('location_honoraires_bailleur_forfait');

    // ─── GESTION LOCATIVE ──
    $gestionMethode    = $str('gestion_methode') ?: 'pct';
    $gtrMin = $_POST['gtr_min'] ?? []; $gtrMax = $_POST['gtr_max'] ?? []; $gtrPct = $_POST['gtr_pct'] ?? [];
    $gTr = [];
    if (is_array($gtrMin)) {
        foreach ($gtrMin as $i => $min) {
            $mn = $min !== '' ? (float)$min : null;
            $mx = isset($gtrMax[$i]) && $gtrMax[$i] !== '' ? (float)$gtrMax[$i] : null;
            $pc = isset($gtrPct[$i]) && $gtrPct[$i] !== '' ? (float)$gtrPct[$i] : null;
            if ($mn === null && $mx === null && $pc === null) continue;
            $gTr[] = ['min' => $mn, 'max' => $mx, 'pct' => $pc];
        }
    }
    $gestionTranchesJson = $gTr ? json_encode($gTr, JSON_UNESCAPED_UNICODE) : null;
    $gestionPctLoyer   = $flt('gestion_pct_loyer');
    // Sécurité : tranches remplies mais méthode laissée sur « Taux unique » sans % → on bascule en tranches
    if ($gestionMethode === 'pct' && $gestionPctLoyer === null && !empty($gTr)) {
        $gestionMethode = 'tranches';
    }
    $gestionEntree     = $flt('gestion_frais_entree_locataire');
    $gestionSortie     = $flt('gestion_frais_sortie_locataire');
    $gestionRenouv     = $flt('gestion_renouvellement_bail');
    $gestionAvenant    = $flt('gestion_avenant_bail');
    $gestionTravauxPct = $flt('gestion_suivi_travaux_pct');
    $gestionQuittance  = $flt('gestion_quittance_supplementaire');
    $gestionGli        = $flt('gestion_assurance_loyers_impayes_pct');
    $gestionCarence    = $flt('gestion_carence_locative_pct');
    $gestionHtml       = $str('gestion_prestations_html');

    // ─── SYNDIC ──
    $syndicForfaitLot  = $flt('syndic_forfait_annuel_lot');
    $syndicForfaitMin  = $flt('syndic_forfait_min');
    $syndicRemBase     = $flt('syndic_remuneration_base');
    $syndicVisite      = $flt('syndic_visite_immeuble');
    $syndicAg          = $flt('syndic_assemblee_supplementaire');
    $syndicEtatDate    = $flt('syndic_etat_date_pre');
    $syndicMec         = $flt('syndic_mise_en_concurrence');
    $syndicRecouvSimp  = $flt('syndic_recouvrement_simple');
    $syndicRecouvCont  = $flt('syndic_recouvrement_contentieux_pct');
    $syndicArchivPct   = $flt('syndic_archivage_pct');
    $syndicHtml        = $str('syndic_prestations_html');

    // ─── MANDAT RECHERCHE ──
    $mandatRPct        = $flt('mandat_recherche_pct');
    $mandatRForfait    = $flt('mandat_recherche_forfait');

    // ─── INFOS LÉGALES ──
    $cartePro          = $str('carte_pro_numero');
    $carteProCci       = $str('carte_pro_cci');
    $garant            = $str('garant_financier');
    $garantMontant     = $flt('garant_financier_montant');
    $rcp               = $str('assurance_rcp');
    $siret             = $str('siret');
    $rcs               = $str('rcs');
    $tva               = $str('tva_intra');
    $mediation         = $str('mediation_organisme');
    $mediationUrl      = $str('mediation_url');
    $mediationAdresse  = $str('mediateur_adresse');
    $mediationCoord    = $str('mediateur_coordonnees');
    $contenuHtml       = $str('contenu_html');

    // ── BROUILLON : on enregistre uniquement un snapshot (pas d'écriture live) ──
    if ($action === 'brouillon') {
        try {
            $lbl = $str('brouillon_label') ?: ('Brouillon ' . date('d/m/Y H:i'));
            $pdo->prepare("DELETE FROM honoraires_versions WHERE id_societe=? AND id_agence=? AND statut='brouillon'")->execute([$idSociete, $idAgence]);
            $pdo->prepare("INSERT INTO honoraires_versions (id_societe,id_agence,statut,label,payload_json,created_by,created_at) VALUES (?,?,'brouillon',?,?,?,NOW())")
                ->execute([$idSociete, $idAgence, $lbl, json_encode($hono_payload(), JSON_UNESCAPED_UNICODE), $userIdH]);
            $hasDraft = true;
            $success = 'Brouillon enregistré (non déployé) — il sera rechargé à votre prochaine visite. Cliquez « Déployer » pour l\'appliquer.';
        } catch (Throwable $e) { $errors[] = 'Brouillon non enregistré : ' . $e->getMessage(); }
    } else {
    try {
        // Vérification fraîche de l'existence de la ligne (société, agence) juste avant l'écriture
        try {
            $qEx = $pdo->prepare("SELECT 1 FROM societe_honoraires WHERE id_societe = ? AND id_agence = ? LIMIT 1");
            $qEx->execute([$idSociete, $idAgence]);
            $rowOwn = (bool)$qEx->fetchColumn();
        } catch (Throwable $e) { /* garde $rowOwn courant */ }
        if ($rowOwn) {
            // UPDATE du barème PROPRE à (société, agence)
            $sql = "
                UPDATE societe_honoraires SET
                    vente_methode=:vm, vente_taux_unique=:vtu, vente_forfait=:vf,
                    vente_tranches_json=:vtj, vente_charge_par_defaut=:vcd, vente_montant_minimum=:vmm,
                    location_zone=:lz, location_honoraires_locataire_m2=:llm,
                    location_honoraires_etat_des_lieux_m2=:lem, location_honoraires_bailleur_pct=:lbp,
                    location_honoraires_bailleur_forfait=:lbf,
                    gestion_pct_loyer=:gpl, gestion_frais_entree_locataire=:gel,
                    gestion_frais_sortie_locataire=:gsl, gestion_renouvellement_bail=:grb,
                    gestion_avenant_bail=:gab, gestion_suivi_travaux_pct=:gstp,
                    gestion_quittance_supplementaire=:gqs, gestion_assurance_loyers_impayes_pct=:ggli,
                    gestion_carence_locative_pct=:gcl, gestion_prestations_html=:gph,
                    syndic_forfait_annuel_lot=:sfl, syndic_forfait_min=:sfm,
                    syndic_remuneration_base=:srb, syndic_visite_immeuble=:svi,
                    syndic_assemblee_supplementaire=:sas, syndic_etat_date_pre=:sed,
                    syndic_mise_en_concurrence=:smec, syndic_recouvrement_simple=:srs,
                    syndic_recouvrement_contentieux_pct=:src, syndic_archivage_pct=:sap,
                    syndic_prestations_html=:sph,
                    mandat_recherche_pct=:mrp, mandat_recherche_forfait=:mrf,
                    carte_pro_numero=:cp, carte_pro_cci=:cc, garant_financier=:gf,
                    garant_financier_montant=:gfm, assurance_rcp=:rcp, siret=:siret,
                    rcs=:rcs, tva_intra=:tva, mediation_organisme=:med, mediation_url=:medu,
                    contenu_html=:html
                WHERE id_societe=:soc AND id_agence=:age
            ";
        } else {
            $sql = "
                INSERT INTO societe_honoraires
                    (id_societe, id_agence, vente_methode, vente_taux_unique, vente_forfait, vente_tranches_json,
                     vente_charge_par_defaut, vente_montant_minimum,
                     location_zone, location_honoraires_locataire_m2, location_honoraires_etat_des_lieux_m2,
                     location_honoraires_bailleur_pct, location_honoraires_bailleur_forfait,
                     gestion_pct_loyer, gestion_frais_entree_locataire, gestion_frais_sortie_locataire,
                     gestion_renouvellement_bail, gestion_avenant_bail, gestion_suivi_travaux_pct,
                     gestion_quittance_supplementaire, gestion_assurance_loyers_impayes_pct,
                     gestion_carence_locative_pct, gestion_prestations_html,
                     syndic_forfait_annuel_lot, syndic_forfait_min, syndic_remuneration_base,
                     syndic_visite_immeuble, syndic_assemblee_supplementaire, syndic_etat_date_pre,
                     syndic_mise_en_concurrence, syndic_recouvrement_simple,
                     syndic_recouvrement_contentieux_pct, syndic_archivage_pct, syndic_prestations_html,
                     mandat_recherche_pct, mandat_recherche_forfait,
                     carte_pro_numero, carte_pro_cci, garant_financier, garant_financier_montant,
                     assurance_rcp, siret, rcs, tva_intra, mediation_organisme, mediation_url,
                     contenu_html)
                VALUES
                    (:soc, :age, :vm, :vtu, :vf, :vtj, :vcd, :vmm,
                     :lz, :llm, :lem, :lbp, :lbf,
                     :gpl, :gel, :gsl, :grb, :gab, :gstp, :gqs, :ggli, :gcl, :gph,
                     :sfl, :sfm, :srb, :svi, :sas, :sed, :smec, :srs, :src, :sap, :sph,
                     :mrp, :mrf,
                     :cp, :cc, :gf, :gfm, :rcp, :siret, :rcs, :tva, :med, :medu, :html)
            ";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':soc'   => $idSociete,
            ':age'   => $idAgence,
            ':vm'    => $venteMethode,
            ':vtu'   => $venteTauxUnique,
            ':vf'    => $venteForfait,
            ':vtj'   => $venteTranchesJson,
            ':vcd'   => $venteCharge,
            ':vmm'   => $venteMin,
            ':lz'    => $locZone,
            ':llm'   => $locLocataireM2,
            ':lem'   => $locEdlM2,
            ':lbp'   => $locBailleurPct,
            ':lbf'   => $locBailleurForfait,
            ':gpl'   => $gestionPctLoyer,
            ':gel'   => $gestionEntree,
            ':gsl'   => $gestionSortie,
            ':grb'   => $gestionRenouv,
            ':gab'   => $gestionAvenant,
            ':gstp'  => $gestionTravauxPct,
            ':gqs'   => $gestionQuittance,
            ':ggli'  => $gestionGli,
            ':gcl'   => $gestionCarence,
            ':gph'   => $gestionHtml ?: null,
            ':sfl'   => $syndicForfaitLot,
            ':sfm'   => $syndicForfaitMin,
            ':srb'   => $syndicRemBase,
            ':svi'   => $syndicVisite,
            ':sas'   => $syndicAg,
            ':sed'   => $syndicEtatDate,
            ':smec'  => $syndicMec,
            ':srs'   => $syndicRecouvSimp,
            ':src'   => $syndicRecouvCont,
            ':sap'   => $syndicArchivPct,
            ':sph'   => $syndicHtml ?: null,
            ':mrp'   => $mandatRPct,
            ':mrf'   => $mandatRForfait,
            ':cp'    => $cartePro ?: null,
            ':cc'    => $carteProCci ?: null,
            ':gf'    => $garant ?: null,
            ':gfm'   => $garantMontant,
            ':rcp'   => $rcp ?: null,
            ':siret' => $siret ?: null,
            ':rcs'   => $rcs ?: null,
            ':tva'   => $tva ?: null,
            ':med'   => $mediation ?: null,
            ':medu'  => $mediationUrl ?: null,
            ':html'  => $contenuHtml ?: null,
        ]);
        // Médiateur de la consommation = niveau SOCIÉTÉ (source unique, partagée par toutes les agences)
        try {
            $pdo->prepare("UPDATE societes SET mediateur_nom=:n, mediateur_adresse=:a, mediateur_coordonnees=:c, mediateur_url=:u WHERE id=:id")
                ->execute([':n'=>$mediation ?: null, ':a'=>$mediationAdresse ?: null, ':c'=>$mediationCoord ?: null, ':u'=>$mediationUrl ?: null, ':id'=>$idSociete]);
        } catch (Throwable $e) { /* colonnes médiateur pas encore migrées → ignoré */ }
        // Tarifs par ZONE (toutes zones) → societe_tarifs_honoraires (upsert sur clé id_societe+zone)
        if ($idSociete > 0) {
            try {
                $upZ = $pdo->prepare("INSERT INTO societe_tarifs_honoraires
                        (id_societe, id_agence, zone_tendue, honoraires_location_bail_m2, honoraires_edl_m2, actif, date_creation, date_modification)
                    VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        honoraires_location_bail_m2 = VALUES(honoraires_location_bail_m2),
                        honoraires_edl_m2 = VALUES(honoraires_edl_m2),
                        actif = 1, date_modification = NOW()");
                foreach (['non_tendue','tendue','tres_tendue'] as $zk) {
                    $loc = $ztLocIn[$zk] ?? null;
                    $edl = $ztEdlIn[$zk] ?? 3.03;
                    if ($loc === null) continue;
                    $upZ->execute([$idSociete, $idAgence, $zk, $loc, $edl]);
                }
            } catch (Throwable $e) { error_log('[honoraires zones] ' . $e->getMessage()); }
        }
        // Gestion par tranches (colonnes ajoutées par migration 20260627_honoraires_gestion_tranches)
        try {
            $pdo->prepare("UPDATE societe_honoraires SET gestion_methode = :gm, gestion_tranches_json = :gtj WHERE id_societe = :soc AND id_agence = :age")
                ->execute([':gm' => $gestionMethode, ':gtj' => $gestionTranchesJson, ':soc' => $idSociete, ':age' => $idAgence]);
        } catch (Throwable $e) { /* colonnes gestion tranches pas encore migrées → ignoré */ }

        // ── CASCADE : enregistrer le MODÈLE société (id_agence=0) propage à toutes ses agences ──
        // (Le barème de BASE id_societe=0 ne cascade pas : il sert de repli global par héritage.)
        if ($idAgence === 0 && $idSociete > 0) {
            try {
                $stAg = $pdo->prepare("SELECT id FROM agences WHERE id_societe = ?");
                $stAg->execute([$idSociete]);
                $allAg = $stAg->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($allAg as $aid) {
                    $aid = (int)$aid;
                    // copie societe_honoraires (modèle → agence) — colonnes explicites
                    $copyCols = "vente_methode, vente_taux_unique, vente_forfait, vente_tranches_json, vente_charge_par_defaut, vente_montant_minimum,
                            location_zone, location_honoraires_locataire_m2, location_honoraires_etat_des_lieux_m2, location_honoraires_bailleur_pct, location_honoraires_bailleur_forfait,
                            gestion_pct_loyer, gestion_frais_entree_locataire, gestion_frais_sortie_locataire, gestion_renouvellement_bail, gestion_avenant_bail, gestion_suivi_travaux_pct, gestion_quittance_supplementaire, gestion_assurance_loyers_impayes_pct, gestion_carence_locative_pct, gestion_prestations_html,
                            syndic_forfait_annuel_lot, syndic_forfait_min, syndic_remuneration_base, syndic_visite_immeuble, syndic_assemblee_supplementaire, syndic_etat_date_pre, syndic_mise_en_concurrence, syndic_recouvrement_simple, syndic_recouvrement_contentieux_pct, syndic_archivage_pct, syndic_prestations_html,
                            mandat_recherche_pct, mandat_recherche_forfait,
                            carte_pro_numero, carte_pro_cci, garant_financier, garant_financier_montant, assurance_rcp, siret, rcs, tva_intra, mediation_organisme, mediation_url, contenu_html,
                            gestion_methode, gestion_tranches_json";
                    $pdo->prepare("DELETE FROM societe_honoraires WHERE id_societe=? AND id_agence=?")->execute([$idSociete, $aid]);
                    $pdo->prepare("INSERT INTO societe_honoraires (id_societe, id_agence, $copyCols)
                        SELECT id_societe, ?, $copyCols FROM societe_honoraires WHERE id_societe=? AND id_agence=0")
                        ->execute([$aid, $idSociete]);
                    // copie tarifs par zone (modèle → agence)
                    $pdo->prepare("DELETE FROM societe_tarifs_honoraires WHERE id_societe=? AND id_agence=?")->execute([$idSociete, $aid]);
                    $pdo->prepare("INSERT INTO societe_tarifs_honoraires (id_societe, id_agence, zone_tendue, honoraires_location_bail_m2, honoraires_edl_m2, actif, date_creation, date_modification)
                        SELECT id_societe, ? AS id_agence, zone_tendue, honoraires_location_bail_m2, honoraires_edl_m2, 1, NOW(), NOW()
                        FROM societe_tarifs_honoraires WHERE id_societe=? AND id_agence=0")
                        ->execute([$aid, $idSociete]);
                }
            } catch (Throwable $e) { error_log('[honoraires cascade] ' . $e->getMessage()); }
        }

        $success = $idSociete === 0
            ? '★★ Barème de BASE enregistré : il s\'applique par défaut à toutes les sociétés/agences sans barème propre (actuelles et futures).'
            : ($idAgence === 0
                ? 'Barème MODÈLE société enregistré et propagé à toutes les agences (elles peuvent le re-modifier).'
                : 'Barème de l\'agence enregistré.');

        // ── Snapshot de DÉPLOIEMENT (historique reprenable) + purge du brouillon ──
        try {
            $lbl = $str('brouillon_label') ?: ('Déployé le ' . date('d/m/Y H:i'));
            $pdo->prepare("INSERT INTO honoraires_versions (id_societe,id_agence,statut,label,payload_json,created_by,created_at,deployed_at) VALUES (?,?,'deploye',?,?,?,NOW(),NOW())")
                ->execute([$idSociete, $idAgence, $lbl, json_encode($hono_payload(), JSON_UNESCAPED_UNICODE), $userIdH]);
            $pdo->prepare("DELETE FROM honoraires_versions WHERE id_societe=? AND id_agence=? AND statut='brouillon'")->execute([$idSociete, $idAgence]);
            $hasDraft = false;
        } catch (Throwable $e) { error_log('[honoraires version] ' . $e->getMessage()); }

        // Recharge (par société + agence)
        try {
            $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? AND id_agence = ? LIMIT 1");
            $stmt->execute([$idSociete, $idAgence]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null; $rowOwn = (bool)$row;
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("SELECT * FROM societe_honoraires WHERE id_societe = ? LIMIT 1");
            $stmt->execute([$idSociete]); $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $errors[] = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
    }
    } // fin else (brouillon | deployer)
    } // fin else (action !== 'reprendre')
}

// Helper pour pré-remplir
$v = static function (string $k, string $default = '') use ($row): string {
    if (post($k, null) !== null) return (string)post($k, $default);
    return (string)($row[$k] ?? $default);
};

// Valeur des mentions légales = honoraires saisi en priorité, sinon valeur auto (docs RH).
// (défini APRÈS $v — sinon le closure capturerait un $v encore indéfini)
$vl = static function (string $field) use ($v, $legalAuto): string {
    $cur = (string)$v($field);
    return $cur !== '' ? $cur : (string)($legalAuto[$field] ?? '');
};

$tranches = [];
if ($row && !empty($row['vente_tranches_json'])) {
    $tranches = json_decode($row['vente_tranches_json'], true) ?: [];
}
if (post('tr_min', null) !== null && is_array($_POST['tr_min'])) {
    // re-saisie après erreur
    $tranches = [];
    foreach ($_POST['tr_min'] as $i => $min) {
        $tranches[] = [
            'min' => $min,
            'max' => $_POST['tr_max'][$i] ?? '',
            'pct' => $_POST['tr_pct'][$i] ?? '',
        ];
    }
}
if (empty($tranches)) {
    $tranches = [
        ['min' => 0, 'max' => 50000, 'pct' => 8.0],
        ['min' => 50000, 'max' => 100000, 'pct' => 6.0],
        ['min' => 100000, 'max' => null, 'pct' => 5.0],
    ];
}

// Tranches GESTION (par loyer mensuel) — même logique que la vente
$gestionTranches = [];
if ($row && !empty($row['gestion_tranches_json'])) {
    $gestionTranches = json_decode($row['gestion_tranches_json'], true) ?: [];
}
if (post('gtr_min', null) !== null && is_array($_POST['gtr_min'])) {
    $gestionTranches = [];
    foreach ($_POST['gtr_min'] as $i => $min) {
        $gestionTranches[] = ['min' => $min, 'max' => $_POST['gtr_max'][$i] ?? '', 'pct' => $_POST['gtr_pct'][$i] ?? ''];
    }
}
if (empty($gestionTranches)) {
    $gestionTranches = [
        ['min' => 0,    'max' => 600, 'pct' => 8.0],
        ['min' => 600,  'max' => 900, 'pct' => 7.0],
        ['min' => 900,  'max' => null, 'pct' => 6.0],
    ];
}

ob_start();
?>

<style>
.hc-wrap { padding: 24px 28px 80px; max-width: 1100px; }
.hc-card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 22px; }
.hc-title { font-size: 16px; font-weight: 700; color: #1a1816; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.hc-sub   { font-size: 12px; color: #888; margin-bottom: 18px; }
.hc-grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.hc-grid-3 { display: grid; grid-template-columns: 1fr 1fr 120px 36px; gap: 8px; align-items: center; margin-bottom: 6px; }
.hc-grid-3 > :nth-child(3) { text-align: right; }
.hc-grid-3 input:nth-child(3) { text-align: right; }
.hc-field label { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
.hc-field input, .hc-field select, .hc-field textarea {
  width: 100%; padding: 9px 12px; border: 1px solid #d4d0ca; border-radius: 8px; font-size: 13px; font-family: inherit;
}
.hc-field textarea { resize: vertical; min-height: 80px; }
.hc-section-head { display: flex; align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 2px solid; margin-bottom: 16px; }
.hc-icon { font-size: 28px; }
.hc-card.transaction { border-left: 4px solid #f97316; }
.hc-card.gestion     { border-left: 4px solid #1f6f7a; }
.hc-card.syndic      { border-left: 4px solid #6a4ca8; }
.hc-card.legal       { border-left: 4px solid #2d8659; }
.hc-card.transaction .hc-section-head { border-color: #f97316; }
.hc-card.gestion .hc-section-head { border-color: #1f6f7a; }
.hc-card.syndic .hc-section-head { border-color: #6a4ca8; }
.hc-card.legal .hc-section-head { border-color: #2d8659; }
.hc-add-row { background: none; border: 1px dashed #d4d0ca; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 12px; color: #555; }
.hc-del-row { background: none; border: none; color: #c0392b; font-size: 18px; cursor: pointer; }
.hc-actions { display: flex; gap: 12px; justify-content: space-between; align-items: center; padding: 18px 0; position: sticky; bottom: 0; background: linear-gradient(180deg, transparent, #f5f3ee 30%); }
.hc-save-btn { padding: 12px 24px; background: linear-gradient(135deg, #f97316, #ea580c); color: #fff; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 4px 12px rgba(249,115,22,0.35); }
.hc-public-link { font-size: 12px; color: #1f6f7a; }
.hc-alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; }
.hc-alert.error { background: #fee2e2; color: #991b1b; border-left: 4px solid #c0392b; }
.hc-alert.success { background: #dcfce7; color: #14532d; border-left: 4px solid #2d8659; }
</style>

<div class="hc-page">

  <div class="hc-wrap">
    <div style="background:#1f6f7a;color:#fff;font-weight:700;text-align:center;padding:10px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;">
      💶 Tous les tarifs affichés et saisis sur cette page sont exprimés en <strong>prix TTC</strong>.
    </div>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;">
      <div>
        <div style="font-family:'DM Mono',monospace;font-size:11px;letter-spacing:1px;color:#7a9060;text-transform:uppercase;">Administration</div>
        <h1 style="font-size:24px;font-weight:700;margin:4px 0 6px;">Barème des honoraires</h1>
        <div style="font-size:13px;color:#888;">Configuration des tarifs publics — obligation arrêté du 10/01/2017 (modifié 26/01/2022) — affichage en tarifs maximums</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= h(app_url('/agency_honoraires_comparatif.php')) ?>" class="hc-public-link" style="padding:8px 14px;background:#fff;border:1px solid #6a4ca8;color:#6a4ca8;border-radius:8px;text-decoration:none;font-weight:600;">
          📊 Comparer les barèmes (agences)
        </a>
        <a href="<?= h(app_url('/tarifs_societe.php')) ?>" target="_blank" class="hc-public-link" style="padding:8px 14px;background:#fff;border:1px solid #1f6f7a;border-radius:8px;text-decoration:none;font-weight:600;">
          🔗 Voir la page publique (barème détaillé société)
        </a>
      </div>
    </div>

    <?php if ($isMgrHono): ?>
    <form method="get" style="background:#eef2f8;border:1px solid #c7d2e0;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <strong style="color:#243b5c;"><?= $isSAhono ? '🛠️ Super admin — éditer le barème de :' : '🛠️ Éditer le barème de :' ?></strong>
      <?php if ($isSAhono && $societesHono): ?>
      <select name="societe_id" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid #c7d2e0;border-radius:8px;font-size:14px;font-weight:600;">
        <option value="0" <?= $idSociete === 0 ? 'selected' : '' ?>>★★ Barème de base (toutes sociétés)</option>
        <?php foreach ($societesHono as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $idSociete === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['nom'] ?: '#'.$s['id']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php if (!$isBaseHono): ?>
      <select name="agence_id" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid #c7d2e0;border-radius:8px;font-size:14px;font-weight:600;">
        <option value="0" <?= $idAgence === 0 ? 'selected' : '' ?>>★ Modèle société (toutes agences)</option>
        <?php foreach ($agencesHono as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $idAgence === (int)$a['id'] ? 'selected' : '' ?>><?= h(ucwords(mb_strtolower((string)$a['lib']))) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; /* !isBaseHono */ ?>
      <span style="font-size:12px;color:#5a6b82;"><?= $isBaseHono ? '★★ Barème de BASE : repli par défaut pour TOUTES les sociétés/agences sans barème propre.' : ($idAgence === 0 ? 'Modèle = défaut hérité par les agences (enregistrer propage à toutes).' : 'Barème propre à cette agence (override).') ?></span>
    </form>
    <?php endif; ?>

    <?php if ($errors): ?><div class="hc-alert error"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="hc-alert success">✅ <?= h($success) ?></div><?php endif; ?>

    <?php
      // ── Historique des versions (scopé société + agence courantes) ──
      $versions = [];
      try {
          $qV = $pdo->prepare("SELECT id, statut, label, created_at, deployed_at, payload_json FROM honoraires_versions WHERE id_societe = ? AND id_agence = ? ORDER BY (statut='brouillon') DESC, id DESC LIMIT 12");
          $qV->execute([$idSociete, $idAgence]);
          $versions = $qV->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) { $versions = []; }

      $scopeLabel = $idSociete === 0 ? '★★ Barème de BASE (toutes sociétés)'
                  : ($idAgence === 0 ? '★ Modèle société' : 'Agence');

      // Extrait quelques chiffres-clés d'un payload pour le comparatif
      $vKey = function (array $p) : array {
          $g = fn($k) => isset($p[$k]) && $p[$k] !== '' ? $p[$k] : '—';
          $loc = [];
          foreach (['normale'=>'Z. normale','tendue'=>'Z. tendue','tres_tendue'=>'Très tendue'] as $z=>$lbl) {
              $loc[$lbl] = isset($p['zt_loc'][$z]) && $p['zt_loc'][$z] !== '' ? $p['zt_loc'][$z].' €/m²' : '—';
          }
          return [
              'Vente'   => $g('vente_methode') . ($p['vente_taux_unique'] ?? '' ? ' · '.$p['vente_taux_unique'].' %' : ''),
              'Location (locataire €/m²)' => implode(' / ', $loc),
              'Gestion' => ($g('gestion_methode')==='tranches' ? 'par tranches' : (($p['gestion_pct_loyer'] ?? '') !== '' ? $p['gestion_pct_loyer'].' % loyer' : '—')),
              'Syndic €/lot' => $g('syndic_forfait_annuel_lot'),
          ];
      };
    ?>

    <?php if ($hasDraft && !empty($draftMeta)): ?>
      <div style="background:#fff7e6;border:1px solid #f0c36d;border-radius:10px;padding:12px 16px;margin-bottom:14px;color:#7a5b00;">
        ✏️ <strong>Brouillon en cours</strong> (<?= htmlspecialchars($draftMeta['label'] ?? 'sans nom') ?> — <?= htmlspecialchars(substr((string)($draftMeta['created_at'] ?? ''),0,16)) ?>) :
        ces valeurs sont <strong>provisoires</strong> et ne sont PAS encore appliquées au site. Cliquez <strong>« 🚀 Déployer »</strong> pour les publier.
      </div>
    <?php endif; ?>

    <?php if (count($versions) > 0): ?>
      <details style="background:#f5f8fc;border:1px solid #c7d2e0;border-radius:10px;padding:10px 16px;margin-bottom:18px;">
        <summary style="cursor:pointer;font-weight:600;color:#243f4d;">📜 Historique &amp; comparatif des versions — <?= htmlspecialchars($scopeLabel) ?> (<?= count($versions) ?>)</summary>
        <div style="overflow-x:auto;margin-top:12px;">
          <table style="border-collapse:collapse;width:100%;font-size:13px;">
            <thead>
              <tr style="background:#243f4d;color:#fff;">
                <th style="padding:6px 8px;text-align:left;">Version</th>
                <?php foreach (['Vente','Location (locataire €/m²)','Gestion','Syndic €/lot'] as $h): ?>
                  <th style="padding:6px 8px;text-align:left;"><?= $h ?></th>
                <?php endforeach; ?>
                <th style="padding:6px 8px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($versions as $vrow):
                  $p = json_decode((string)$vrow['payload_json'], true) ?: [];
                  $k = $vKey($p);
                  $isDraft = $vrow['statut'] === 'brouillon';
                  $when = $isDraft ? ($vrow['created_at'] ?? '') : ($vrow['deployed_at'] ?: $vrow['created_at']);
              ?>
                <tr style="border-bottom:1px solid #e2e8f0;<?= $isDraft ? 'background:#fff7e6;' : '' ?>">
                  <td style="padding:6px 8px;">
                    <?= $isDraft ? '✏️ <strong>Brouillon</strong>' : '✅ Déployé' ?><br>
                    <span style="color:#64748b;font-size:11px;"><?= htmlspecialchars($vrow['label'] ?? '') ?> · <?= htmlspecialchars(substr((string)$when,0,16)) ?></span>
                  </td>
                  <?php foreach (['Vente','Location (locataire €/m²)','Gestion','Syndic €/lot'] as $col): ?>
                    <td style="padding:6px 8px;"><?= htmlspecialchars((string)$k[$col]) ?></td>
                  <?php endforeach; ?>
                  <td style="padding:6px 8px;text-align:right;">
                    <?php if (!$isDraft): ?>
                      <button type="submit" form="hono-versions-form" name="version_id" value="<?= (int)$vrow['id'] ?>"
                              style="background:#34586b;color:#fff;border:0;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:12px;"
                              onclick="return confirm('Reprendre cette version comme brouillon ? Vous pourrez l\'ajuster puis la déployer.');">↩︎ Reprendre</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <form method="post" id="hono-versions-form" style="display:none;">
          <?= csrf_field('honoraires_config') ?>
          <input type="hidden" name="action" value="reprendre">
          <?php if ($isSAhono): ?><input type="hidden" name="societe_id_ctx" value="<?= (int)$idSociete ?>"><?php endif; ?>
          <?php if ($isMgrHono): ?><input type="hidden" name="agence_id_ctx" value="<?= (int)$idAgence ?>"><?php endif; ?>
        </form>
      </details>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field('honoraires_config') ?>
      <?php if ($isSAhono): ?><input type="hidden" name="societe_id_ctx" value="<?= (int)$idSociete ?>"><?php endif; ?>
      <?php if ($isMgrHono): ?><input type="hidden" name="agence_id_ctx" value="<?= (int)$idAgence ?>"><?php endif; ?>

      <!-- ════════════════════════════════════════════ -->
      <!-- TRANSACTION (vente + location)               -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card transaction">
        <div class="hc-section-head">
          <span class="hc-icon">🤝</span>
          <div>
            <div class="hc-title">Transaction (Vente & Location)</div>
            <div class="hc-sub">Honoraires de négociation immobilière</div>
          </div>
        </div>

        <h3 style="font-size:14px;margin-bottom:10px;color:#f97316;">— Vente —</h3>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Méthode de calcul</label>
            <select name="vente_methode">
              <option value="tranches"   <?= $v('vente_methode','tranches') === 'tranches'   ? 'selected' : '' ?>>Tranches dégressives</option>
              <option value="pct_unique" <?= $v('vente_methode') === 'pct_unique' ? 'selected' : '' ?>>Pourcentage unique</option>
              <option value="forfait"    <?= $v('vente_methode') === 'forfait'    ? 'selected' : '' ?>>Forfait fixe</option>
              <option value="sur_demande" <?= $v('vente_methode') === 'sur_demande' ? 'selected' : '' ?>>Sur demande</option>
            </select>
          </div>
          <div class="hc-field">
            <label>Charge par défaut</label>
            <select name="vente_charge_par_defaut">
              <option value="acquereur" <?= $v('vente_charge_par_defaut','acquereur') === 'acquereur' ? 'selected' : '' ?>>Acquéreur</option>
              <option value="vendeur"   <?= $v('vente_charge_par_defaut') === 'vendeur'   ? 'selected' : '' ?>>Vendeur</option>
              <option value="partage"   <?= $v('vente_charge_par_defaut') === 'partage'   ? 'selected' : '' ?>>Partagé</option>
            </select>
          </div>
          <div class="hc-field">
            <label>Taux unique (%)</label>
            <input type="number" step="0.01" name="vente_taux_unique" value="<?= h($v('vente_taux_unique')) ?>" placeholder="Ex: 5.00">
          </div>
          <div class="hc-field">
            <label>Forfait fixe (€)</label>
            <input type="number" step="0.01" name="vente_forfait" value="<?= h($v('vente_forfait')) ?>" placeholder="Ex: 8000">
          </div>
          <div class="hc-field">
            <label>Plancher (€)</label>
            <input type="number" step="0.01" name="vente_montant_minimum" value="<?= h($v('vente_montant_minimum')) ?>" placeholder="Ex: 5000">
          </div>
        </div>

        <h4 style="font-size:12px;margin:18px 0 8px;color:#555;">Tranches dégressives (si méthode = Tranches)</h4>
        <div id="tranches-wrap">
          <div class="hc-grid-3" style="font-size:11px;font-weight:600;color:#888;">
            <div>Prix de</div>
            <div>Prix à</div>
            <div>Taux %</div>
            <div></div>
          </div>
          <?php foreach ($tranches as $i => $t): ?>
          <div class="hc-grid-3 tr-row">
            <input type="number" step="0.01" name="tr_min[]" value="<?= h((string)($t['min'] ?? '')) ?>" placeholder="0">
            <input type="number" step="0.01" name="tr_max[]" value="<?= h((string)($t['max'] ?? '')) ?>" placeholder="(illimité)">
            <input type="number" step="0.01" name="tr_pct[]" value="<?= h((string)($t['pct'] ?? '')) ?>" placeholder="ex: 6.00">
            <button type="button" class="hc-del-row" onclick="this.closest('.tr-row').remove()">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="hc-add-row" onclick="addTranche()">＋ Ajouter une tranche</button>

        <h3 style="font-size:14px;margin:24px 0 10px;color:#f97316;">— Location : honoraires locataire €/m² par zone —</h3>
        <p style="font-size:12px;color:#888;margin:0 0 10px;">Une agence peut rayonner sur plusieurs zones : renseignez les 3. Plafonds 2026 pré-remplis (modifiables à la baisse).</p>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead><tr style="text-align:left;color:#888;border-bottom:1px solid #eee;">
            <th style="padding:6px 8px;">Zone</th>
            <th style="padding:6px 8px;">Hon. locataire €/m² (visite+bail+dossier)</th>
            <th style="padding:6px 8px;">État des lieux €/m²</th>
            <th style="padding:6px 8px;">Plafond 2026</th>
          </tr></thead>
          <tbody>
          <?php foreach ($zonesDef as $zk => $zl): ?>
            <tr>
              <td style="padding:6px 8px;font-weight:600;"><?= h($zl) ?></td>
              <td style="padding:6px 8px;"><input type="text" inputmode="decimal" name="zt_loc[<?= h($zk) ?>]" value="<?= h(number_format((float)$zoneTarifs[$zk]['loc'], 2, ',', ' ')) ?>" style="width:140px;padding:8px 10px;border:1px solid #d4d0ca;border-radius:8px;"> €/m²</td>
              <td style="padding:6px 8px;"><input type="text" inputmode="decimal" name="zt_edl[<?= h($zk) ?>]" value="<?= h(number_format((float)$zoneTarifs[$zk]['edl'], 2, ',', ' ')) ?>" style="width:120px;padding:8px 10px;border:1px solid #d4d0ca;border-radius:8px;"> €/m²</td>
              <td style="padding:6px 8px;color:#15803d;"><?= h($plafondsZone[$zk] ?? '—') ?> € · EDL 3,03 €</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <div class="hc-grid" style="margin-top:14px;">
          <div class="hc-field">
            <label>Hon. bailleur (% loyer annuel)</label>
            <input type="number" step="0.01" name="location_honoraires_bailleur_pct" value="<?= h($v('location_honoraires_bailleur_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Hon. bailleur — forfait (€)</label>
            <input type="number" step="0.01" name="location_honoraires_bailleur_forfait" value="<?= h($v('location_honoraires_bailleur_forfait')) ?>">
          </div>
        </div>
      </div>

      <!-- ════════════════════════════════════════════ -->
      <!-- GESTION LOCATIVE                              -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card gestion">
        <div class="hc-section-head">
          <span class="hc-icon">🔑</span>
          <div>
            <div class="hc-title">Gestion locative</div>
            <div class="hc-sub">Honoraires de gestion d'un bien donné en location</div>
          </div>
        </div>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Méthode de calcul des honoraires de gestion</label>
            <select name="gestion_methode" id="gestion_methode">
              <option value="pct"      <?= $v('gestion_methode','pct') === 'pct'      ? 'selected' : '' ?>>Taux unique (% du loyer)</option>
              <option value="tranches" <?= $v('gestion_methode') === 'tranches' ? 'selected' : '' ?>>Tranches selon le loyer</option>
            </select>
          </div>
          <div class="hc-field">
            <label>Honoraires de gestion (% du loyer encaissé) — si taux unique</label>
            <input type="number" step="0.01" name="gestion_pct_loyer" value="<?= h($v('gestion_pct_loyer')) ?>" placeholder="Ex: 7.00">
          </div>
        </div>

        <!-- Tranches gestion par loyer mensuel (si méthode = Tranches) -->
        <h4 style="font-size:12px;margin:14px 0 8px;color:#555;">Tranches selon le loyer mensuel (€) — honoraires de gestion (%)</h4>
        <div id="gestion-tranches-wrap">
          <div class="hc-grid-3" style="font-size:11px;font-weight:600;color:#888;">
            <div>Loyer de (€)</div><div>Loyer à (€)</div><div>Taux %</div><div></div>
          </div>
          <?php foreach ($gestionTranches as $t): ?>
          <div class="hc-grid-3 gtr-row">
            <input type="number" step="0.01" name="gtr_min[]" value="<?= h((string)($t['min'] ?? '')) ?>" placeholder="0">
            <input type="number" step="0.01" name="gtr_max[]" value="<?= h((string)($t['max'] ?? '')) ?>" placeholder="(illimité)">
            <input type="number" step="0.01" name="gtr_pct[]" value="<?= h((string)($t['pct'] ?? '')) ?>" placeholder="ex: 7.00">
            <button type="button" class="hc-del-row" onclick="this.closest('.gtr-row').remove()">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="hc-add-row" onclick="addGestionTranche()">＋ Ajouter une tranche</button>

        <div class="hc-grid" style="margin-top:16px;">
          <div class="hc-field">
            <label>Frais d'entrée locataire (€)</label>
            <input type="number" step="0.01" name="gestion_frais_entree_locataire" value="<?= h($v('gestion_frais_entree_locataire')) ?>">
          </div>
          <div class="hc-field">
            <label>Frais sortie / état des lieux (€)</label>
            <input type="number" step="0.01" name="gestion_frais_sortie_locataire" value="<?= h($v('gestion_frais_sortie_locataire')) ?>">
          </div>
          <div class="hc-field">
            <label>Renouvellement bail (€)</label>
            <input type="number" step="0.01" name="gestion_renouvellement_bail" value="<?= h($v('gestion_renouvellement_bail')) ?>">
          </div>
          <div class="hc-field">
            <label>Avenant au bail (€)</label>
            <input type="number" step="0.01" name="gestion_avenant_bail" value="<?= h($v('gestion_avenant_bail')) ?>">
          </div>
          <div class="hc-field">
            <label>Suivi travaux (% montant)</label>
            <input type="number" step="0.01" name="gestion_suivi_travaux_pct" value="<?= h($v('gestion_suivi_travaux_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Quittance supplémentaire (€)</label>
            <input type="number" step="0.01" name="gestion_quittance_supplementaire" value="<?= h($v('gestion_quittance_supplementaire')) ?>">
          </div>
          <div class="hc-field">
            <label>GLI — assurance loyers impayés (%)</label>
            <input type="number" step="0.01" name="gestion_assurance_loyers_impayes_pct" value="<?= h($v('gestion_assurance_loyers_impayes_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Carence locative (%)</label>
            <input type="number" step="0.01" name="gestion_carence_locative_pct" value="<?= h($v('gestion_carence_locative_pct')) ?>">
          </div>
        </div>
        <div class="hc-field" style="margin-top:14px;">
          <label>Détail des prestations incluses (HTML libre)</label>
          <textarea name="gestion_prestations_html" placeholder="Liste des prestations couvertes par les honoraires de gestion…"><?= h($v('gestion_prestations_html')) ?></textarea>
        </div>
      </div>

      <!-- ════════════════════════════════════════════ -->
      <!-- SYNDIC DE COPROPRIÉTÉ                         -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card syndic">
        <div class="hc-section-head">
          <span class="hc-icon">🏢</span>
          <div>
            <div class="hc-title">Syndic de copropriété</div>
            <div class="hc-sub">Honoraires du contrat type de syndic — décret du 26/03/2015</div>
          </div>
        </div>
        <div class="hc-grid">
          <div class="hc-field">
            <label>Forfait annuel par lot (€/lot/an)</label>
            <input type="number" step="0.01" name="syndic_forfait_annuel_lot" value="<?= h($v('syndic_forfait_annuel_lot')) ?>" placeholder="Ex: 180">
          </div>
          <div class="hc-field">
            <label>Plancher annuel total (€)</label>
            <input type="number" step="0.01" name="syndic_forfait_min" value="<?= h($v('syndic_forfait_min')) ?>" placeholder="Ex: 1500">
          </div>
          <div class="hc-field">
            <label>Rémunération de base (€/an)</label>
            <input type="number" step="0.01" name="syndic_remuneration_base" value="<?= h($v('syndic_remuneration_base')) ?>">
          </div>
          <div class="hc-field">
            <label>Visite immeuble supplémentaire (€)</label>
            <input type="number" step="0.01" name="syndic_visite_immeuble" value="<?= h($v('syndic_visite_immeuble')) ?>">
          </div>
          <div class="hc-field">
            <label>AG supplémentaire (€)</label>
            <input type="number" step="0.01" name="syndic_assemblee_supplementaire" value="<?= h($v('syndic_assemblee_supplementaire')) ?>">
          </div>
          <div class="hc-field">
            <label>État daté pré-vente (€)</label>
            <input type="number" step="0.01" name="syndic_etat_date_pre" value="<?= h($v('syndic_etat_date_pre')) ?>" placeholder="Plafond légal: 380€">
          </div>
          <div class="hc-field">
            <label>Mise en concurrence travaux (€)</label>
            <input type="number" step="0.01" name="syndic_mise_en_concurrence" value="<?= h($v('syndic_mise_en_concurrence')) ?>">
          </div>
          <div class="hc-field">
            <label>Recouvrement simple (€)</label>
            <input type="number" step="0.01" name="syndic_recouvrement_simple" value="<?= h($v('syndic_recouvrement_simple')) ?>">
          </div>
          <div class="hc-field">
            <label>Recouvrement contentieux (% somme)</label>
            <input type="number" step="0.01" name="syndic_recouvrement_contentieux_pct" value="<?= h($v('syndic_recouvrement_contentieux_pct')) ?>">
          </div>
          <div class="hc-field">
            <label>Frais d'archivage (% honoraires)</label>
            <input type="number" step="0.01" name="syndic_archivage_pct" value="<?= h($v('syndic_archivage_pct')) ?>">
          </div>
        </div>
        <div class="hc-field" style="margin-top:14px;">
          <label>Tableau libre des prestations particulières (HTML)</label>
          <textarea name="syndic_prestations_html" placeholder="Détails complémentaires…"><?= h($v('syndic_prestations_html')) ?></textarea>
        </div>
      </div>

      <!-- ════════════════════════════════════════════ -->
      <!-- INFORMATIONS LÉGALES                          -->
      <!-- ════════════════════════════════════════════ -->
      <div class="hc-card legal">
        <div class="hc-section-head">
          <span class="hc-icon">⚖️</span>
          <div>
            <div class="hc-title">Informations légales du professionnel</div>
            <div class="hc-sub">Mentions obligatoires Loi Hoguet<?php if (array_filter($legalAuto)): ?> · <span style="color:#15803d;">pré-rempli automatiquement depuis les documents RH (agence / société)</span><?php endif; ?></div>
          </div>
        </div>
        <div class="hc-grid">
          <div class="hc-field">
            <label>N° carte professionnelle</label>
            <input type="text" name="carte_pro_numero" value="<?= h($vl('carte_pro_numero')) ?>">
          </div>
          <div class="hc-field">
            <label>CCI émettrice</label>
            <input type="text" name="carte_pro_cci" value="<?= h($vl('carte_pro_cci')) ?>">
          </div>
          <div class="hc-field">
            <label>Garant financier</label>
            <input type="text" name="garant_financier" value="<?= h($vl('garant_financier')) ?>">
          </div>
          <div class="hc-field">
            <label>Montant garantie (€)</label>
            <input type="number" step="0.01" name="garant_financier_montant" value="<?= h($vl('garant_financier_montant')) ?>">
          </div>
          <div class="hc-field">
            <label>Assurance RCP</label>
            <input type="text" name="assurance_rcp" value="<?= h($vl('assurance_rcp')) ?>">
          </div>
          <div class="hc-field">
            <label>SIRET</label>
            <input type="text" name="siret" value="<?= h($vl('siret')) ?>">
          </div>
          <div class="hc-field">
            <label>RCS</label>
            <input type="text" name="rcs" value="<?= h($vl('rcs')) ?>">
          </div>
          <div class="hc-field">
            <label>TVA intracommunautaire</label>
            <input type="text" name="tva_intra" value="<?= h($vl('tva_intra')) ?>">
          </div>
          <div class="hc-field">
            <label>Médiateur consommation — Nom</label>
            <input type="text" name="mediation_organisme" value="<?= h($vl('mediation_organisme')) ?>" placeholder="Ex : Médiateur du notariat / CNPM…">
          </div>
          <div class="hc-field">
            <label>Médiateur — Adresse</label>
            <input type="text" name="mediateur_adresse" value="<?= h($vl('mediateur_adresse')) ?>" placeholder="Adresse postale du médiateur">
          </div>
          <div class="hc-field">
            <label>Médiateur — Coordonnées</label>
            <input type="text" name="mediateur_coordonnees" value="<?= h($vl('mediateur_coordonnees')) ?>" placeholder="Tél / email">
          </div>
          <div class="hc-field">
            <label>URL du médiateur</label>
            <input type="url" name="mediation_url" value="<?= h($vl('mediation_url')) ?>" placeholder="https://…">
          </div>
        </div>
        <div class="hc-field" style="margin-top:14px;">
          <label>Mentions complémentaires (HTML libre)</label>
          <textarea name="contenu_html" placeholder="Texte additionnel à afficher en bas de la page publique…"><?= h($v('contenu_html')) ?></textarea>
        </div>
      </div>

      <div class="hc-actions" style="flex-wrap:wrap;gap:10px;">
        <div style="font-size:11px;color:#888;flex:1 1 100%;">Dernière mise à jour : <?= h($row['date_mise_a_jour'] ?? '—') ?></div>
        <input type="text" name="brouillon_label" maxlength="180" placeholder="Nom de la version (optionnel)"
               style="flex:1 1 220px;min-width:180px;padding:9px 12px;border:1px solid #c7d2e0;border-radius:8px;font-size:13px;">
        <button type="submit" name="action" value="brouillon"
                style="background:#fff;color:#7a5b00;border:1px solid #f0c36d;border-radius:10px;padding:11px 18px;font-weight:700;font-size:13px;cursor:pointer;">💾 Enregistrer (brouillon)</button>
        <button type="submit" name="action" value="deployer" class="hc-save-btn"
                onclick="return confirm('Déployer ce barème ? Il sera immédiatement appliqué partout (site, annonces, calculs)<?= $idAgence===0 && $idSociete>0 ? ' et propagé à toutes les agences de la société' : '' ?>.');">🚀 Déployer</button>
      </div>
    </form>

    <div style="margin-top:18px;text-align:center;">
      <a href="<?= h(app_url('/agency_honoraires_comparatif.php')) ?>" style="display:inline-block;padding:11px 20px;background:#6a4ca8;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px;">
        📊 Comparer toutes les agences (barèmes en direct)
      </a>
    </div>
  </div>
</div><!-- /hc-page -->

<script>
function addTranche() {
  const wrap = document.getElementById('tranches-wrap');
  const row = document.createElement('div');
  row.className = 'hc-grid-3 tr-row';
  row.innerHTML = `
    <input type="number" step="0.01" name="tr_min[]" placeholder="0">
    <input type="number" step="0.01" name="tr_max[]" placeholder="(illimité)">
    <input type="number" step="0.01" name="tr_pct[]" placeholder="ex: 6.00">
    <button type="button" class="hc-del-row" onclick="this.closest('.tr-row').remove()">✕</button>`;
  wrap.appendChild(row);
}
function addGestionTranche() {
  const wrap = document.getElementById('gestion-tranches-wrap');
  const row = document.createElement('div');
  row.className = 'hc-grid-3 gtr-row';
  row.innerHTML = `
    <input type="number" step="0.01" name="gtr_min[]" placeholder="0">
    <input type="number" step="0.01" name="gtr_max[]" placeholder="(illimité)">
    <input type="number" step="0.01" name="gtr_pct[]" placeholder="ex: 7.00">
    <button type="button" class="hc-del-row" onclick="this.closest('.gtr-row').remove()">✕</button>`;
  wrap.appendChild(row);
}
</script>

<?php
$layout_content = ob_get_clean();
$layout_sidebar = 'sidebar_net';
$layout_title   = 'Mes honoraires';
$layout_hide_page_head = true;
require __DIR__ . '/inc/layout_maboximmo.php';
