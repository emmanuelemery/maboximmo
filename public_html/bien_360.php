<?php
// bien_360.php — Vue 360° d'un bien immobilier
// Synthèse complète : bail actif + offres + docs + pièces obligatoires + tiers + immeuble + IA contextuelle
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/ged_document_links.php';   // GED CENTRALE UNIQUE (2026-05-25)
require_once __DIR__ . '/inc/fluxbox_functions.php';    // résolveur société/agence d'entité (contexte modale)
require_once __DIR__ . '/inc/ged_name_pills.php';       // affichage contextuel du nom GED (pills)
require_login();

$bienId = (int)($_GET['id'] ?? 0);
if ($bienId <= 0) {
    header('Location: ' . app_url('/bien_liste.php'));
    exit;
}

// ── Contexte BAILLEUR (ouvert en modal depuis le hub bailleur) : page épurée
// (embed = pas de sidebar/topbar agency) ET masquage des actions agency/transaction
// pour ne pas exposer le bailleur aux options d'un autre module. (Emmanuel 2026-06-11)
$bailleurEmbed = ((($_GET['embed'] ?? '') === '1')
                  && ((($_GET['ctx'] ?? '') === 'bailleur')
                      || in_array((int)(function_exists('current_role_id') ? current_role_id() : 0), [9, 10], true)));

// ─── Charge le bien + immeuble + propriétaire + tiers ────────────────
$sql = "SELECT b.*,
    COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1)     AS bien_adresse,
    COALESCE(NULLIF(b.code_postal, ''), i.code_postal) AS bien_cp,
    COALESCE(NULLIF(b.ville, ''), i.ville)             AS bien_ville,
    i.id AS immeuble_id, i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
    p.id AS proprio_id, p.id_tiers AS proprio_tiers_id,
    COALESCE(NULLIF(p.societe, ''), CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom_legacy,
    COALESCE(NULLIF(tp.nom_affichage, ''), tp.raison_sociale, CONCAT_WS(' ', tp.prenom, tp.nom)) AS proprio_tiers_nom,
    bt.libelle AS type_label
FROM biens b
LEFT JOIN immeubles i      ON i.id = b.id_immeuble
LEFT JOIN proprietaires p  ON p.id = b.id_proprietaire
LEFT JOIN tiers tp         ON tp.id = p.id_tiers
LEFT JOIN bien_types bt    ON bt.id = b.id_bien_type
WHERE b.id = ? LIMIT 1";
$st = $pdo->prepare($sql); $st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit('Bien introuvable.');
}

$proprietaireNom = $bien['proprio_tiers_nom'] ?: $bien['proprio_nom_legacy'] ?: '—';
$adresseComplete = trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? ''));

// ─── Bail actif + locataire ──────────────────────────────────────────
$bailActif = null; $locataireNom = null; $locataireTiersId = null;
try {
    $stB = $pdo->prepare("SELECT bb.*, t.nom_affichage AS loc_tiers_nom, t.raison_sociale AS loc_raison
        FROM bien_baux bb
        LEFT JOIN tiers t ON t.id = bb.id_tiers_locataire
        WHERE bb.id_bien = ? AND bb.statut = 'actif'
        ORDER BY bb.date_prise_effet DESC LIMIT 1");
    $stB->execute([$bienId]);
    $bailActif = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($bailActif) {
        $locataireNom = $bailActif['loc_tiers_nom'] ?: $bailActif['loc_raison']
            ?: ($bailActif['locataire_raison_sociale'] ?: trim((string)$bailActif['locataire_prenom'] . ' ' . $bailActif['locataire_nom']));
        $locataireTiersId = (int)($bailActif['id_tiers_locataire'] ?? 0);
    }
} catch (Throwable $e) {}

// ─── Archives baux ──
$archivesBaux = [];
try {
    $stA = $pdo->prepare("SELECT id, bail_nature, statut, date_prise_effet, date_fin,
        locataire_raison_sociale, locataire_nom, locataire_prenom, loyer_mensuel_hc
        FROM bien_baux WHERE id_bien = ? AND statut <> 'actif'
        ORDER BY date_prise_effet DESC LIMIT 10");
    $stA->execute([$bienId]);
    $archivesBaux = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Offres en cours ──
$offres = [];
try {
    $stO = $pdo->prepare("SELECT id, nom, prenom, prix_propose, financement_type, statut_offre, date_creation
        FROM leads_annonces WHERE id_bien = ? AND type_contact = 'offre'
        ORDER BY date_creation DESC LIMIT 20");
    $stO->execute([$bienId]);
    $offres = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$nbOffresActives = 0;
foreach ($offres as $o) if (!in_array($o['statut_offre'] ?? '', ['refusee','expiree'], true)) $nbOffresActives++;

// ─── Documents GED (centrale unique via ged_document_links) ──
// Source unique : tout doc rattaché au BIEN, quelle que soit l'origine d'upload.
// Plus de filtre source_module restrictif (qui excluait les uploads bien_intake).
$docs = [];
try {
    $docs = gdl_documents_for_entity($pdo, 'BIEN', $bienId, [
        'status'   => 'active',
        'limit'    => 30,
        'order_by' => 'd.created_at DESC',
    ]);
} catch (Throwable $e) {}
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

// ─── Photos du bien (biens_photos), regroupées en sous-dossiers (groupe_no / groupe_label) ──
$photoGroups = []; $photosTotal = 0;
try {
    $stP = $pdo->prepare("SELECT id, COALESCE(groupe_no,0) gno, COALESCE(NULLIF(groupe_label,''),'Sans groupe') glabel,
                                 url_photo, url_lbc, nom_original, ordre
                          FROM biens_photos WHERE id_bien = ? ORDER BY groupe_no ASC, ordre ASC, id ASC");
    $stP->execute([$bienId]);
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $g = (int)$p['gno'];
        if (!isset($photoGroups[$g])) $photoGroups[$g] = ['no'=>$g, 'label'=>(string)$p['glabel'], 'photos'=>[]];
        $photoGroups[$g]['photos'][] = $p;
        $photosTotal++;
    }
    ksort($photoGroups);
} catch (Throwable $e) {}

// ─── Checklist pièces obligatoires ──
// Référentiel « Documents de base » du BIEN/LOT (impératif → important).
$pieces = [
    ['code'=>'DIAG_DPE',      'label'=>'DPE',                     'sublabel'=>'Performance énergétique · 10 ans'],
    ['code'=>'DIAG_ERP',      'label'=>'ERP',                     'sublabel'=>'État des risques · 6 mois'],
    ['code'=>'DIAG_PLOMB',    'label'=>'CREP plomb',              'sublabel'=>'Logements < 1949'],
    ['code'=>'DIAG_AMIANTE',  'label'=>'Amiante',                 'sublabel'=>'Permis < 07/1997'],
    ['code'=>'DIAG_GAZ',      'label'=>'État gaz',                'sublabel'=>'Installation > 15 ans'],
    ['code'=>'DIAG_ELEC',     'label'=>'État électricité',        'sublabel'=>'Installation > 15 ans'],
    ['code'=>'DIAG_TERMITES', 'label'=>'Termites',                'sublabel'=>'Si zone préfectorale'],
    ['code'=>'DIAG_ANC',      'label'=>'ANC (assainissement)',    'sublabel'=>'Si non raccordé'],
    ['code'=>'SURFACE_CARREZ','label'=>'Surface Carrez / Boutin', 'sublabel'=>'Carrez=vente · Boutin=loc'],
    ['code'=>'TITRE_PROP',    'label'=>'Titre de propriété',      'sublabel'=>'Du lot'],
    ['code'=>'TAXE_FONCIERE', 'label'=>'Taxe foncière',           'sublabel'=>'Dernier millésime'],
];
// Le bail signé + EDL sont attendus dès qu'un bail est actif sur le bien.
if ($bailActif) {
    $pieces[] = ['code'=>'BAIL',       'label'=>'Bail signé',           'sublabel'=>'Contrat de location', 'alt_codes'=>['bail_signe','BAIL_SIGNE','BAIL_LOCATION']];
    $pieces[] = ['code'=>'ETAT_LIEUX', 'label'=>'État des lieux d\'entrée', 'sublabel'=>'Si bail actif',   'alt_codes'=>['edl_entree','EDL_ENTREE']];
}
// Mapping code pièce → type FluxBox (forced_type_doc) = code GLOSSAIRE canonique
// (ged_level_codes) pour que le modal pré-sélectionne la bonne pastille.
$fbxTypeByCode = [
    'DIAG_DPE'=>'DPE', 'DIAG_ERP'=>'ERP_ERNMT', 'DIAG_PLOMB'=>'PLOMB',
    'DIAG_AMIANTE'=>'AMIANTE', 'DIAG_GAZ'=>'GAZ', 'DIAG_ELEC'=>'ELECTRICITE',
    'DIAG_TERMITES'=>'TERMITES', 'DIAG_ANC'=>'ASSAINISSEMENT',
    'SURFACE_CARREZ'=>'SURFACE', 'TITRE_PROP'=>'TITRE_PROPRIETE', 'TAXE_FONCIERE'=>'TAXES_FONCIERES',
    'BAIL'=>'BAIL', 'ETAT_LIEUX'=>'EDL_ENTREE',
];
$piecesItems = [];
// Types FluxBox « de base » présents → pour filtrer la card « Documents divers » (mode A).
$baseTypes = [];
foreach ($pieces as $p) {
    $ft = $fbxTypeByCode[$p['code']] ?? null;
    // Détection : par code legacy, alt_codes, ET par le type FluxBox réel (ex. document_type='dpe').
    // Comparaison INSENSIBLE à la casse et au séparateur (-/_) : BAIL, bail, bail_signe,
    // BAIL-SIGNE… doivent tous cocher la même pièce (fin des « types jumeaux » non reconnus).
    $normType = static fn($s) => strtoupper(str_replace('-', '_', trim((string)$s)));
    $okCodes  = array_merge([$p['code']], $p['alt_codes'] ?? [], $ft ? [$ft] : []);
    $haveTypes = array_map($normType, array_keys($docsByType));
    $ok = false;
    foreach ($okCodes as $c) { if (in_array($normType($c), $haveTypes, true)) { $ok = true; break; } }
    if ($ft) $baseTypes[$ft] = true;
    foreach ($p['alt_codes'] ?? [] as $ac) $baseTypes[$ac] = true;
    $piecesItems[] = [
        'label'    => $p['label'],
        'sublabel' => $p['sublabel'],
        'ok'       => $ok,
        'add_url'  => app_url('/transaction_chargement.php'),
        // Recherche assistée OneDrive — v1 limitée au DPE (cf. décision 2026-06-06)
        'search_code' => ($p['code'] === 'DIAG_DPE') ? $p['code'] : null,
        'fbx_type'    => $fbxTypeByCode[$p['code']] ?? null,
    ];
}
$nbPieces   = count($piecesItems);
$nbPiecesOk = array_sum(array_map(fn($p)=>$p['ok']?1:0, $piecesItems));

// ─── Statut visuel intelligent ──
$statusColor = 'gray';
$statusIcon  = '⚪';
$statusMsg   = 'Bien créé.';
$statusAlertes = '';
if (($bien['statut_bien'] ?? '') === 'vendu' || $bien['prix_final_vente']) {
    $statusColor = 'gray'; $statusIcon = '🏁';
    $statusMsg = '<strong>Bien vendu</strong> — clôture en cours.';
} elseif (in_array(strtolower((string)($bien['type_commercialisation'] ?? '')), ['vente','location'], true)) {
    // Mission active (mirror) → prime sur un date_retrait résiduel d'un cycle précédent.
    $statusColor = 'orange'; $statusIcon = '🏷️';
    $statusMsg = '<strong>' . (strtolower($bien['type_commercialisation'])==='vente'?'En vente':'À louer') . '</strong> — commercialisation active.';
} elseif ($bien['date_retrait_commercialisation']) {
    // Retiré de la commercialisation ≠ vendu (mandat sans_suite). Bien intact.
    $statusColor = 'gray'; $statusIcon = '🚫';
    $statusMsg = '<strong>Retiré de la commercialisation</strong> — hors marché.';
} elseif ($nbOffresActives > 0) {
    $statusColor = 'orange'; $statusIcon = '💰';
    $statusMsg = "<strong>{$nbOffresActives} offre(s) active(s)</strong> — décision attendue.";
} elseif ($bailActif) {
    $statusColor = 'green'; $statusIcon = '✅';
    $statusMsg = "<strong>Bien loué</strong> à <strong>" . h($locataireNom) . "</strong> jusqu'au " . h($bailActif['date_fin']) . ".";
} elseif (($bien['statut_occupation'] ?? '') === 'vacant') {
    $statusColor = 'orange'; $statusIcon = '🔓';
    $statusMsg = "<strong>" . h(ucfirst($bien['type_commercialisation'] ?: 'Bien')) . " · Vacant</strong> — aucun bail actif.";
}
$piecesManquantes = $nbPieces - $nbPiecesOk;
if ($piecesManquantes > 0) {
    $statusAlertes = $piecesManquantes . ' pièce(s) à charger';
}

// ─── Mentions : docs où ce bien apparaît en relation 'annexe'/'reference' ──
// (le pivot ged_document_links permet de distinguer le rattachement principal des mentions secondaires)
$mentions = [];
try {
    $stM = $pdo->prepare("SELECT d.id, d.name_display, d.document_type, d.created_at
        FROM ged_document_links dl
        INNER JOIN ged_documents d ON d.id = dl.document_id
        WHERE dl.entity_type = 'BIEN' AND dl.entity_id = ?
          AND dl.relation_type IN ('annexe','reference','piece_jointe')
          AND d.status = 'active'
        ORDER BY d.created_at DESC LIMIT 10");
    $stM->execute([$bienId]);
    $mentions = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// ─── Représentants du propriétaire (via tiers_contacts) ──
$representants = [];
if (!empty($bien['proprio_tiers_id'])) {
    try {
        $stR = $pdo->prepare("SELECT tc.qualite, t.id, t.nom, t.prenom, t.email, t.telephone
            FROM tiers_contacts tc
            INNER JOIN tiers t ON t.id = tc.id_tiers_contact
            WHERE tc.id_tiers_entite = ? AND tc.actif = 1
            ORDER BY tc.priorite ASC LIMIT 10");
        $stR->execute([(int)$bien['proprio_tiers_id']]);
        $representants = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

// Topbar : UNIQUEMENT la référence du bien (le reste est dans la chaîne + la card)
$pageTitle    = 'Bien · réf. ' . ($bien['reference_bien'] ?: '#' . $bienId);
$pageSubtitle = 'Vue 360° · ' . ($bien['ville'] ?? '');

// Type(s) de mandat actif(s) → affiché à DROITE sur la ligne du titre (slot tb-center)
$mandatTypes = [];
try {
    $stMt = $pdo->prepare("SELECT DISTINCT type_mandat FROM mandats WHERE id_bien = ?
        AND statut NOT IN ('resilie','expire','annule','archive','termine','perdu','refuse','clos','vendu','sans_suite')");
    $stMt->execute([$bienId]);
    $mandatTypes = array_filter($stMt->fetchAll(PDO::FETCH_COLUMN) ?: []);
} catch (Throwable) {}
// (Le mandat n'est PLUS affiché dans la topbar : il est déjà visible dans « Mandats actifs ».)
// $mandatTypes reste utilisé plus bas pour l'état des boutons « À vendre » / « À louer ».

$extraCss     = fiche360_css() . '<style>
/* Palette modules MaBoxImmo (rappel couleur par card sur bien_360) */
:root{
  --c-bien:#84A763; --c-immeuble:#3D7465; --c-tiers:#316887; --c-bail:#84A7AB;
  --c-creancier:#DD4735; --c-document:#A2658C; --c-fluxbox:#BF527A; --c-bailleur:#BF8837;
  --c-action:#3D4762;
}
.f360-card{ border-left:4px solid var(--acc,#e8e4da); }
details.f360-card > summary{ margin-left:-2px; }
/* Carte Actions → Twilight Indigo */
.f360-actions{ background:var(--c-action); }
.f360-actions h4{ color:#e9c877; }
</style>';
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>
<style>
/* Fond de page en dégradé (identique à la page Biens / .mbi-main) + topbar collée en haut */
.agency-content{
  background:linear-gradient(135deg, rgba(132,169,140,0.18) 0%, rgba(255,255,255,0) 35%, rgba(72,120,166,0.14) 60%, rgba(255,255,255,0) 85%, rgba(201,123,46,0.16) 100%), #fafbfc;
  background-attachment:fixed;
  padding-top:0 !important;
}
/* Topbar = barre pleine largeur (déborde le padding 28px du conteneur), collée en haut, plate */
.agency-topbar{
  background:#ffffff;
  border:none;
  border-bottom:1px solid #e8e4da;
  border-radius:0;
  box-shadow:0 2px 8px rgba(0,0,0,.05);
  padding:10px 28px;
  min-height:56px;
  margin:0 -28px 18px -28px;
}
.agency-topbar .tb-title{ font-size:1.5rem; font-weight:800; color:#1f2937; line-height:1.12; }
.agency-topbar .tb-center{ flex:1; display:flex; justify-content:flex-end; }
/* La chaîne Propriétaire → Immeuble → Bien : sans cadre (transparente) */
.f360-chain{ background:transparent !important; border:none !important; box-shadow:none !important; padding:4px 0 0 !important; }
</style>

<?php
// ─── BREADCRUMB hiérarchique ──
$chaine = [];
// Propriétaire : nom + ids (proprio + tiers) ; lien tiers si dispo, sinon fiche proprio
if ($proprietaireNom && $proprietaireNom !== '—') {
    $pid = (int)($bien['proprio_id'] ?? 0); $ptid = (int)($bien['proprio_tiers_id'] ?? 0);
    $purl = $ptid ? app_url('/tiers_360.php?id=' . $ptid) : ($pid ? app_url('/agency_proprietaire_fiche.php?id=' . $pid) : null);
    $pidLbl = ($pid ? ' · #' . $pid : '') . ($ptid ? ' · tiers #' . $ptid : '');
    $chaine[] = ['icon'=>'👤','label'=>$proprietaireNom . $pidLbl,'url'=>$purl];
}
if (!empty($bien['immeuble_id'])) {
    $chaine[] = ['icon'=>'🏢','label'=>($bien['nom_immeuble'] ?: $bien['imm_adresse'] ?: 'Immeuble') . ' · #' . (int)$bien['immeuble_id'],
                 'url'=>app_url('/immeuble_360.php?id=' . $bien['immeuble_id'])];
}
// Bien : nom (désignation) + son id
$chaine[] = ['icon'=>'🏠','label'=>($bien['designation'] ?: $bien['reference_bien'] ?: 'Ce bien') . ' · #' . $bienId,'url'=>null];

// ─── HEADER bien ──
$badgeBail = null;
$tcMir = strtolower((string)($bien['type_commercialisation'] ?? ''));
if (($bien['statut_bien'] ?? '') === 'vendu' || $bien['prix_final_vente'])  $badgeBail = ['label'=>'Vendu','class'=>'vendu'];
elseif ($tcMir === 'vente')                                               $badgeBail = ['label'=>'En vente','class'=>'vacant'];
elseif ($tcMir === 'location')                                            $badgeBail = ['label'=>'À louer','class'=>'vacant'];
elseif ($bailActif)                                                       $badgeBail = ['label'=>'Loué','class'=>'loue'];
elseif ($bien['date_retrait_commercialisation'])                          $badgeBail = ['label'=>'Retiré','class'=>'vacant'];
elseif (($bien['statut_occupation'] ?? '') === 'vacant')                  $badgeBail = ['label'=>'Vacant','class'=>'vacant'];

$metas = [];
if (!empty($bien['type_label']))        $metas[] = ['icon'=>'🏷️','text'=>$bien['type_label']];
if (!empty($bien['surface_habitable'])) $metas[] = ['icon'=>'📐','text'=>number_format((float)$bien['surface_habitable'], 0) . ' m²'];
if (!empty($bien['nb_pieces']))         $metas[] = ['icon'=>'🚪','text'=>$bien['nb_pieces'] . ' pièces'];
if (!empty($bien['nb_chambres']))       $metas[] = ['icon'=>'🛏️','text'=>$bien['nb_chambres'] . ' ch.'];
if (!empty($bien['etage']) || $bien['etage']==='0') $metas[] = ['icon'=>'🏢','text'=>'Ét. ' . $bien['etage']];
if (!empty($bien['dpe_classe']))        $metas[] = ['icon'=>'⚡','text'=>'DPE ' . $bien['dpe_classe']];
if (!empty($bien['numero_lot']))        $metas[] = ['icon'=>'🔢','text'=>'Lot ' . $bien['numero_lot']];

// Helper : action FluxBox avec contexte pré-rempli depuis ce bien
$idSocBien    = (int)($bien['id_societe'] ?? 0);
$idAgeBien    = (int)($bien['id_agence'] ?? 0);
$idProprioBien= (int)($bien['id_proprietaire'] ?? 0);
// Société/agence non attribuées au bien (fréquent) → on REPREND la résolution éprouvée
// de MaBoxOffice (bien → immeuble/propriétaire → agence → société). Sans ça la modale
// « Charger des documents » retombe sur la société du USER connecté (fausse).
if ($idAgeBien <= 0) {
    $idAgeBien = fluxbox_resolve_agence_of_entity($pdo, 'BIEN', $bienId);
}
if ($idSocBien <= 0 && $idAgeBien > 0) {
    try { $q=$pdo->prepare("SELECT id_societe FROM agences WHERE id=?"); $q->execute([$idAgeBien]); $idSocBien=(int)$q->fetchColumn(); } catch (Throwable) {}
}

// N1 suggéré : priorité au mandat actif, fallback bien.type_commercialisation.
// Voir transaction_index.php pour la doctrine complète.
// Priorité : gerance > syndic > transaction > location (cf. transaction_index.php pour la doctrine).
$mandatTypeBien = '';
$mandatsActifs  = []; // liste complète pour la card "Mandats actifs" plus bas
try {
    $stM = $pdo->prepare("SELECT id, type_mandat, statut, date_debut, date_fin,
        numero_mandat, commentaire
        FROM mandats
        WHERE id_bien = ? AND statut NOT IN
            ('resilie','expire','annule','archive','termine','perdu','refuse','clos','vendu','sans_suite')
        ORDER BY CASE type_mandat
            WHEN 'gerance'     THEN 1
            WHEN 'syndic'      THEN 2
            WHEN 'transaction' THEN 3
            WHEN 'location'    THEN 4
            ELSE 9 END,
            id DESC");
    $stM->execute([$bienId]);
    $mandatsActifs = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!empty($mandatsActifs)) {
        $mandatTypeBien = strtolower(trim((string)$mandatsActifs[0]['type_mandat']));
    }
} catch (Throwable $e) {}
$typeComBien  = strtolower(trim((string)($bien['type_commercialisation'] ?? '')));
// Métier (N1) induit par le bien. Doctrine : depuis un bien, le DÉFAUT est GESTION
// (jamais transaction « par défaut »). Seul un signal de VENTE explicite bascule en transaction.
// Un mandat 'location' = gestion locative (PAS transaction). Empty interdit : sinon la cascade
// du modal ne se déclenche pas (categorie reste verrouillée) — cf. applyPrefillCascade().
$n1Bien       = match (true) {
    $mandatTypeBien === 'transaction'                       => '05_TRANSACTION',
    $typeComBien   === 'vente'                              => '05_TRANSACTION',
    $mandatTypeBien === 'syndic'                            => '04_SYNDIC',
    default                                                 => '03_GESTION_LOCATIVE', // gerance, location, gestion, ou aucun signal → GESTION
};
// Référence du bien pour pré-remplir le champ NOM DE L'ENTITÉ + verrouiller
// Cascade GED imposée : BIENS > BIEN (sous-domaine "Bien entité")
// L'IA Vision décidera N4 post-upload (BAUX / ETATS_DES_LIEUX / DIAGNOSTICS…)
$refBienJs   = addslashes((string)($bien['reference_bien'] ?: 'Bien #' . $bienId));
$adrBienJs   = addslashes(trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? '')));
// Propriétaire (nom + tiers id + représentant si présent) pour la mini-card PROPRIÉTAIRE
$proprioNomJs    = addslashes($proprietaireNom);
$proprioTiersId  = (int)($bien['proprio_tiers_id'] ?? 0);
$proprioRepJs    = '';
if (!empty($representants[0])) {
    $r0 = $representants[0];
    $repNom    = trim((string)($r0['prenom'] ?? '') . ' ' . ($r0['nom'] ?? ''));
    $proprioRepJs = addslashes($repNom . ($r0['qualite'] ? ' (' . $r0['qualite'] . ')' : ''));
}
// Immeuble de rattachement du bien → pour renseigner la ligne « Immeuble » du modal.
$immIdBien = (int)($bien['immeuble_id'] ?? 0);
$immNomJs  = addslashes((string)($bien['nom_immeuble'] ?: $bien['imm_adresse'] ?: ''));
$fbxOnClickBien = "window.fbxOpenUploadModal({bien_id:{$bienId}, soc_id:{$idSocBien}, age_id:{$idAgeBien}, immeuble_id:{$immIdBien}, immeuble_nom:'{$immNomJs}', proprio_id:{$idProprioBien}, proprio_nom:'{$proprioNomJs}', proprio_tiers_id:{$proprioTiersId}, proprio_representant:'{$proprioRepJs}', n1:'{$n1Bien}', n2:'BIENS', n3:'BIEN', entite_nom:'{$refBienJs}', entite_id_bdd:{$bienId}, entite_adresse:'{$adrBienJs}', origin:'bien_360'});return false;";
// Prefill FluxBox de la fiche bien (réutilisé par la checklist des pièces).
$fbxPrefillBien = [
    'origin'           => 'bien_360',
    'bien_id'          => (int)$bienId,
    'soc_id'           => (int)$idSocBien,
    'age_id'           => (int)$idAgeBien,
    'immeuble_id'      => (int)$immIdBien,
    'immeuble_nom'     => (string)($bien['nom_immeuble'] ?: $bien['imm_adresse'] ?: ''),
    'proprio_id'       => (int)$idProprioBien,
    'proprio_nom'      => (string)$proprietaireNom,
    'proprio_tiers_id' => (int)$proprioTiersId,
    'n1'               => (string)$n1Bien, 'n2' => 'BIENS', 'n3' => 'BIEN',
    'entite_nom'       => (string)($bien['reference_bien'] ?: 'Bien #' . $bienId),
    'entite_id_bdd'    => (int)$bienId,
    'entite_adresse'   => trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? '')),
];

// Bail signé + EDL = docs du BAIL (= locataire), pas du bien nu. Si un bail est actif,
// ces pièces ouvrent le modal avec le CONTEXTE BAIL (comme depuis bail_360) : bail +
// locataire remplis, bien/immeuble en annexe, classement en gestion locative.
if ($bailActif) {
    $fbxPrefillBailFromBien = [
        'origin'           => 'bien_360',
        'bail_id'          => (int)$bailActif['id'],
        'bail_locataire'   => (string)$locataireNom,
        'bien_id'          => (int)$bienId,
        'immeuble_id'      => (int)$immIdBien,
        'immeuble_nom'     => (string)($bien['nom_immeuble'] ?: $bien['imm_adresse'] ?: ''),
        'soc_id'           => (int)$idSocBien,
        'age_id'           => (int)$idAgeBien,
        'proprio_id'       => (int)$idProprioBien,
        'proprio_nom'      => (string)$proprietaireNom,
        'proprio_tiers_id' => (int)$proprioTiersId,
        'entite_id_bdd'    => (int)$bienId,
        'entite_nom'       => (string)($bien['reference_bien'] ?: 'Bien #' . $bienId),
        'entite_adresse'   => trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? '')),
        'card_label'       => 'DOCUMENT POUR LE BAIL',
        'n1'               => '03_GESTION_LOCATIVE',
    ];
    foreach ($piecesItems as &$pit) {
        if (in_array($pit['fbx_type'] ?? '', ['BAIL', 'EDL_ENTREE'], true)) $pit['fbx_prefill'] = $fbxPrefillBailFromBien;
    }
    unset($pit);
}

// Checklist « Documents de base » : rendu capturé ici pour l'afficher en tête de la colonne 2.
$nbOkP = 0; foreach ($piecesItems as $i) if (!empty($i['ok'])) $nbOkP++;
$totP  = count($piecesItems);
ob_start();
fiche360_checklist('Documents de base', $piecesItems, $fbxPrefillBien);
$piecesHtml = ob_get_clean();
// « Documents divers » = docs du bien dont le type n'est PAS une pièce de base (mode A).
$docsDivers = array_values(array_filter($docs, fn($d) => empty($baseTypes[$d['document_type']])));

// Le bouton « Dossier de vente » est contextuel : "Voir" si un dossier existe
// déjà pour ce bien, "Créer" sinon (la cible transaction_dossier.php est idempotente).
$hasDossierVente = false;
try {
    $stDV = $pdo->prepare("SELECT 1 FROM dossier_vente WHERE id_bien = ? LIMIT 1");
    $stDV->execute([$bienId]);
    $hasDossierVente = (bool)$stDV->fetchColumn();
} catch (Throwable $e) {}
$dossierVenteBtn = [
    'label' => $hasDossierVente ? '🗂️ Voir le dossier de vente' : '🗂️ Créer le dossier de vente',
    'url'   => app_url('/transaction_dossier.php?id_bien=' . $bienId),
    'class' => $hasDossierVente ? 'tr-btn' : 'tr-btn tr-btn-primary',
];

// Annonce active : la dernière annonce du bien est diffusée (en ligne sur les portails).
$annonceActive = false;
try {
    $stAA = $pdo->prepare("SELECT etat_publication FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $stAA->execute([$bienId]);
    $annonceActive = ((string)$stAA->fetchColumn() === 'diffusee');
} catch (Throwable $e) {}
$annonceActiveBtn = $annonceActive ? [[
    'label' => '📡 Annonce active',
    'url'   => app_url('/bien_detail.php?edit=' . $bienId . '&section=annonce'),
    'class' => 'tr-btn tr-btn-annonce-active',
]] : [];

// Chaîne hiérarchique : noms réels Propriétaire → Immeuble → Bien (juste sous la topbar)
// + 2 gros boutons « À vendre » / « À louer » alignés à droite (au-dessus de la colonne Actions)
$mandatLower = array_map('strtolower', $mandatTypes);
$aVendre = in_array('vente', $mandatLower, true);
$aLouer  = in_array('location', $mandatLower, true);
$btnB360 = function(string $type, string $labelOff, string $labelOn, bool $on, string $cOn, string $cOnD, string $shadow) use ($bienId): string {
    // Boutons TOUJOURS pleins de couleur (le bouton est coloré, pas seulement l'écriture)
    $bg    = "linear-gradient(135deg,$cOn,$cOnD)";
    $icon  = $on ? '✓' : $labelOff;       // $labelOff = l'emoji (💼 / 🔑)
    $txt   = $labelOn;
    $sh    = "box-shadow:0 3px 0 $shadow,0 4px 10px rgba(0,0,0,.18);";
    return '<button type="button" onclick="b360SetMandat(' . $bienId . ',\'' . $type . '\',this)" '
         . 'style="flex:1 1 0;min-width:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;letter-spacing:.02em;'
         . 'display:flex;align-items:center;justify-content:center;gap:8px;text-align:center;white-space:nowrap;'
         . 'padding:15px 14px;border-radius:12px;border:none;background:' . $bg . ';color:#fff;' . $sh . '">'
         . '<span style="font-size:28px;line-height:1">' . $icon . '</span>' . $txt . '</button>';
};
$rightBtns  = '<div style="width:291px;display:flex;gap:12px;justify-content:center">';
$rightBtns .= $btnB360('vente',    '💼', 'À vendre', $aVendre, '#3b6fb0', '#274d80', '#1d3a61');
$rightBtns .= $btnB360('location', '🔑', 'À louer', $aLouer, '#3f9d5a', '#2d6a35', '#1f4d26');
$rightBtns .= '</div>';
fiche360_breadcrumb($chaine, '', $rightBtns);
?>
<script>
function b360SetMandat(bienId, type, btn){
  if (btn.dataset.busy) return; btn.dataset.busy = '1';
  var old = btn.innerHTML; btn.innerHTML = '⏳…';
  fetch(<?= json_encode(app_url('/api/bien_add_mandat.php')) ?>, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ bien_id: bienId, type_mandat: type })
  }).then(function(r){ return r.json(); }).then(function(res){
    if (res && res.ok) {
      // Mandat posé → on dirige vers la fiche d'édition pour compléter le bien
      window.location.href = <?= json_encode(app_url('/bien_detail.php?edit=' . $bienId)) ?>;
    }
    else { btn.innerHTML = old; delete btn.dataset.busy; alert((res && res.error) || 'Échec de la mise en marché.'); }
  }).catch(function(){ btn.innerHTML = old; delete btn.dataset.busy; alert('Erreur réseau.'); });
}
</script>
<?php

// ── Card ANNONCE : affichée UNIQUEMENT si une annonce active existe sur le bien ──
$annonce = null;
try {
    $stAn = $pdo->prepare("SELECT id, titre, description, titre_ia, texte_ia
                           FROM annonces WHERE id_bien = ?
                             AND (statut IS NULL OR statut NOT IN ('archive','archivee','supprime','supprimee'))
                           ORDER BY id DESC LIMIT 1");
    $stAn->execute([$bienId]);
    $annonce = $stAn->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

if ($annonce) {
    $cut = static function(string $s, int $n){ return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s; };
    $anTitre = trim((string)($annonce['titre'] ?: $annonce['titre_ia'] ?: 'Annonce sans titre'));
    $anTexteFull = trim((string)($annonce['description'] ?: $annonce['texte_ia'] ?: ''));
    // Icône = crayon (cliquable → modal) à la place de la maison
    $roleNow = function_exists('current_role_id') ? (int)current_role_id() : 0;
    $canEditAnnonce = in_array($roleNow, [1,2,7], true) || (function_exists('is_super_admin') && is_super_admin());
    fiche360_header($canEditAnnonce ? '✏️' : '🏠', $anTitre, $badgeBail, $cut($anTexteFull, 220), [], []);

    if ($canEditAnnonce) {
        $csrfAn = csrf_token('annonce_texte');
        ?>
        <div id="an-modal" style="display:none;position:fixed;inset:0;z-index:9000;align-items:center;justify-content:center;padding:20px">
          <div style="position:absolute;inset:0;background:rgba(15,23,42,.55)" id="an-modal-ov"></div>
          <div style="position:relative;background:#fff;border-radius:14px;width:min(640px,100%);max-height:90vh;overflow:auto;box-shadow:0 24px 64px rgba(0,0,0,.3);padding:20px">
            <h3 style="margin:0 0 14px;font-size:16px;color:#143A41">✏️ Titre &amp; texte de l'annonce</h3>
            <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px">Titre</label>
            <input type="text" id="an-titre" value="<?= h((string)($annonce['titre'] ?: $annonce['titre_ia'] ?: '')) ?>"
                   style="width:100%;padding:11px 13px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box;margin-bottom:14px">
            <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px">Texte de l'annonce</label>
            <textarea id="an-texte" rows="9" style="width:100%;padding:11px 13px;border:1px solid #cbd5e1;border-radius:9px;font-size:13.5px;box-sizing:border-box;font-family:inherit;resize:vertical"><?= h((string)($annonce['description'] ?: $annonce['texte_ia'] ?: '')) ?></textarea>
            <div id="an-status" style="font-size:12px;color:#64748b;margin-top:8px;min-height:16px"></div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
              <button type="button" id="an-cancel" style="background:#fff;border:1px solid #cbd5e1;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer">Annuler</button>
              <button type="button" id="an-save" style="background:#1B4A52;color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer">Enregistrer</button>
            </div>
          </div>
        </div>
        <script>
        (function(){
          var SAVE=<?= json_encode(app_url('/api/annonce_texte_save.php')) ?>, AID=<?= (int)$annonce['id'] ?>, CSRF=<?= json_encode($csrfAn) ?>;
          var m=document.getElementById('an-modal'), st=document.getElementById('an-status');
          function open(){ m.style.display='flex'; } function close(){ m.style.display='none'; }
          // Le crayon (icône de la card annonce) ouvre la modal
          var ic = document.querySelector('.f360-header-icon');
          if (ic) { ic.style.cursor='pointer'; ic.title='Modifier le titre & le texte'; ic.addEventListener('click', open); }
          document.getElementById('an-cancel').addEventListener('click', close);
          document.getElementById('an-modal-ov').addEventListener('click', close);
          document.getElementById('an-save').addEventListener('click', function(){
            st.textContent='⏳ Enregistrement…';
            fetch(SAVE,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
              annonce_id:AID, csrf:CSRF,
              titre:document.getElementById('an-titre').value,
              description:document.getElementById('an-texte').value
            })}).then(function(r){return r.json();}).then(function(res){
              if(res&&res.ok){ st.textContent='✓ Enregistré. Rechargement…'; setTimeout(function(){location.reload();},600); }
              else { st.textContent='⚠️ '+((res&&res.error)||'échec'); }
            }).catch(function(){ st.textContent='⚠️ échec réseau'; });
          });
        })();
        </script>
        <?php
    }
}

// ── Barre de KPI du bien (format agency_biens) ──
$kpis = [];
if (!empty($bien['type_label']))        $kpis[] = [$bien['type_label'], 'Type'];
if (!empty($bien['surface_habitable'])) $kpis[] = [number_format((float)$bien['surface_habitable'], 0, ',', ' '), 'm²'];
if (!empty($bien['nb_pieces']))         $kpis[] = [$bien['nb_pieces'], 'Pièces'];
if (!empty($bien['nb_chambres']))       $kpis[] = [$bien['nb_chambres'], 'Chambres'];
if ($bien['etage'] !== null && $bien['etage'] !== '') $kpis[] = [(($bien['etage']==='0'||(int)$bien['etage']===0)?'RDC':$bien['etage']), 'Étage'];
if (!empty($bien['dpe_classe'])) {
    $dpeVal = strtoupper((string)$bien['dpe_classe']);
    if (!empty($bien['ges_classe'])) { $kpis[] = [$dpeVal . ' / ' . strtoupper((string)$bien['ges_classe']), 'DPE / GES']; }
    else { $kpis[] = [$dpeVal, 'DPE']; }
}
if (!empty($bien['numero_lot']))        $kpis[] = [$bien['numero_lot'], 'Lot'];
if (!empty($bien['etat_bien']))         $kpis[] = [$bien['etat_bien'], 'État'];  // tout à droite
if ($kpis) {
    echo '<div class="b360-kpibar">';
    foreach ($kpis as $k) {
        echo '<div class="b360-kpi"><div class="b360-kpi-v">' . h((string)$k[0]) . '</div><div class="b360-kpi-l">' . h((string)$k[1]) . '</div></div>';
    }
    echo '</div>';
}
?>
<style>
  .b360-kpibar{display:flex;flex-wrap:nowrap;gap:8px;margin:12px 0;width:100%}
  .b360-kpi{flex:1 1 auto;min-width:0;background:#fff;border:1px solid #e8e4da;border-radius:12px;padding:10px 10px;text-align:center}
  .b360-kpi-v{font-size:18px;font-weight:800;color:#1B4A52;line-height:1.12;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .b360-kpi-l{font-size:10.5px;color:#8A8472;text-transform:uppercase;letter-spacing:.03em;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
<?php

?>

<style>
.b360-grid3 { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
@media (max-width:900px){ .b360-grid3 { grid-template-columns:1fr; } }
.b360-grid3 > div { min-width:0; }
.b360-inner { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); gap:14px; align-items:start; }
@media (max-width:1100px){ .b360-inner { grid-template-columns:1fr; } }
.b360-inner > div { min-width:0; }

/* Card Actions — fond bleu pétrole (charte) */
.b360-grid3 .f360-actions { background:linear-gradient(155deg,#34586b,#243f4d); }
.b360-grid3 .f360-actions a:hover { background:rgba(255,255,255,.10); }

/* Boutons d'action du header 360° — style doux (cartes blanches) */
.f360-header-actions { gap:10px; flex-wrap:wrap; }
.f360-header-actions .tr-btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 15px; border-radius:13px;
    border:1px solid #f0ede7; background:#fff; color:#4a5568;
    font-size:13px; font-weight:600; line-height:1; white-space:nowrap;
    text-decoration:none; cursor:pointer;
    box-shadow:0 2px 6px rgba(36,59,92,.08), 0 1px 2px rgba(36,59,92,.04);
    transition:transform .14s ease, box-shadow .14s ease, background .14s ease, color .14s ease;
}
.f360-header-actions .tr-btn:hover {
    color:#243B5C; background:#fbfaf7;
    transform:translateY(-1px);
    box-shadow:0 5px 14px rgba(36,59,92,.12), 0 2px 4px rgba(36,59,92,.06);
}
.f360-header-actions .tr-btn-primary { background:#f3f6fb; color:#2d4a72; border-color:#e3ebf5; }
.f360-header-actions .tr-btn-primary:hover { background:#eaf1fa; color:#243B5C; }
</style>

<div class="b360-grid3">

  <!-- ═══════ ZONE GAUCHE (2 colonnes) : Barre IA + Core + Documents ═══════ -->
  <div style="min-width:0;">

    <?php fiche360_ia_bar('bien', $bienId, "Dites ce que vous voulez saisir (surface, chambres, prix…) ou posez une question"); ?>
    <script>
    /* IA = routeur d'intentions : « saisir la surface », « ajouter le nb de chambres »… → ouvre la bonne page au bon champ */
    (function(){
      var BID = <?= (int)$bienId ?>, DET = <?= json_encode(app_url('/bien_detail.php')) ?>;
      var ROUTER = <?= json_encode(app_url('/api/ia_action_router.php')) ?>;
      // Liens internes = relatifs ; liens externes (https://maps/google…) → nouvel onglet
      function goUrl(u, ext){ if(ext || /^https?:\/\//i.test(u)) window.open(u,'_blank'); else window.location.href=u; }
      var VILLE = <?= json_encode(trim((string)($bien['ville'] ?: $bien['imm_ville'] ?: ''))) ?>;
      var ADR = <?= json_encode(trim((string)$adresseComplete)) ?>;
      function maps(terms){ return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(terms + ' ' + (ADR||VILLE)); }
      function route(qRaw){
        var q = (qRaw||'').toLowerCase();
        var D = DET + '?edit=' + BID;
        // 1) Intention de SAISIE interne (verbe de saisie + champ)
        if(/(saisir|saisie|ajout|modif|remplir|mettre|met\b|chang|[ée]dit|corrig|renseign|compl[ée]t|indiqu|à jour)/.test(q)){
          if(/surface|superficie|\bm2\b|m²|m[èe]tre|carrez|boutin/.test(q)) return D+'&section=descriptif&focus=surface_habitable';
          if(/chambre/.test(q))               return D+'&section=descriptif&focus=nb_chambres';
          if(/pi[èe]ce/.test(q))               return D+'&section=descriptif&focus=nb_pieces';
          if(/[ée]tage/.test(q))               return D+'&section=descriptif&focus=etage';
          if(/construction|ann[ée]e/.test(q))  return D+'&section=descriptif&focus=annee_construction';
          if(/type de bien|nature/.test(q))    return D+'&section=descriptif&focus=type_bien';
          if(/loyer|location|louer/.test(q))   return D+'&section=annonce&focus=loyer';
          if(/prix|vente|vendre|fai/.test(q))  return D+'&section=annonce&focus=prix';
          if(/honoraire/.test(q))              return D+'&section=annonce&focus=honoraires';
          if(/mandat|transaction/.test(q))     return D+'&section=annonce&focus=type_transaction';
          if(/dpe|diagnostic|[ée]nerg|\bges\b/.test(q)) return D+'&section=dpe';
          if(/descriptif|description|texte|annonce|photo/.test(q)) return D+'&section=annonce';
          if(/document|charger/.test(q))       return <?= json_encode(app_url('/bien_documents_list.php?id=')) ?> + BID;
        }
        // 2) Services EXTERNES → Google Maps près du bien
        if(/cuisin/.test(q))                    return maps('cuisiniste magasin de cuisine');
        if(/plomb/.test(q))                     return maps('plombier');
        if(/[ée]lectric/.test(q))               return maps('électricien');
        if(/chauffag|chaudi[èe]re/.test(q))     return maps('chauffagiste');
        if(/peintre|peinture/.test(q))          return maps('peintre bâtiment');
        if(/serrur/.test(q))                    return maps('serrurier');
        if(/ma[çc]on|ma[çc]onnerie/.test(q))    return maps('maçon');
        if(/menuis|fen[êe]tre|porte/.test(q))   return maps('menuisier');
        if(/notaire/.test(q))                   return maps('notaire');
        if(/diagnostiqueur/.test(q))            return maps('diagnostiqueur immobilier');
        if(/d[ée]m[ée]nag/.test(q))             return maps('déménageur');
        if(/jardin|paysag/.test(q))             return maps('paysagiste jardinier');
        if(/artisan|travaux|devis|r[ée]nov|entreprise du b[âa]timent/.test(q)) return maps('artisan travaux rénovation');
        if((/magasin|boutique|fournisseur|acheter|o[ùu] trouver/.test(q))) return maps(q.replace(/.*?(magasin|boutique|fournisseur)\s*(de|d')?\s*/,'').slice(0,40) || q.slice(0,40));
        // 3) Naviguer vers une autre entité (propriétaire / tiers) : « voir Locavente », « ouvrir la fiche de … »
        var nav = q.match(/(?:voir|ouvrir|consulter|afficher|fiche\s+(?:de\s+)?|aller (?:à|vers|sur))\s+(?:le |la |l'|les |du |de la |de l'|d'|un |une |propri[ée]taire |tiers |client |soci[ée]t[ée] |sci |le bien |la fiche )*(.{2,60})/);
        if (nav && nav[1] && !/surface|chambre|pi[èe]ce|[ée]tage|construction|loyer|prix|honoraire|mandat|dpe|diagnostic|descriptif|photo|document/.test(nav[1])) {
          return <?= json_encode(app_url('/agency_proprietaires.php?q=')) ?> + encodeURIComponent(nav[1].trim());
        }
        return null;
      }
      function install(){
        if (typeof window.fiche360IaAsk !== 'function') { return setTimeout(install, 120); }
        if (window.__iaRouterInstalled) return; window.__iaRouterInstalled = true;
        var orig = window.fiche360IaAsk;
        window.fiche360IaAsk = function(rid){
          var inp = document.getElementById(rid + '-q');
          var q = inp ? inp.value.trim() : '';
          if (!q) return;
          var url = route(q);
          if (url) { goUrl(url); return; }        // route mots-clés (instantané, gratuit)
          // Sinon : routeur IA illimité (interprète n'importe quelle demande → action)
          var resp = document.getElementById(rid + '-resp');
          if (resp) { resp.classList.add('show'); resp.innerHTML = '<div class="ia-q">❓ ' + q + '</div><div class="ia-loading">⏳ Je cherche la bonne action…</div>'; }
          fetch(ROUTER, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ bien_id:BID, question:q }) })
            .then(function(r){ return r.json(); })
            .then(function(a){
              if (a && a.type === 'open' && a.url) { goUrl(a.url, a.external); if(resp) resp.classList.remove('show'); return; }
              if (resp) resp.innerHTML = '<div class="ia-q">❓ ' + q + '</div>' + ((a && a.text) || '—');
            })
            .catch(function(){ if (orig) orig(rid); });
        };
      }
      install();
    })();
    </script>

    <?php
    // ─────────────── COMMENTAIRE INTERNE DU BIEN (persisté biens.commentaire) ───────────────
    $roleCmt  = function_exists('current_role_id') ? (int)current_role_id() : 0;
    $canEditCmt = in_array($roleCmt, [1,2,7], true) || (function_exists('is_super_admin') && is_super_admin());
    $cmtValue = (string)($bien['commentaire'] ?? '');
    ?>
    <div class="f360-card">
      <h3>📝 Commentaire interne</h3>
      <?php if ($canEditCmt): ?>
        <textarea id="bien-cmt" rows="4" placeholder="Note interne sur ce bien (visible par l'équipe, jamais publiée)…"
                  style="width:100%;padding:11px 13px;border:1px solid #cbd5e1;border-radius:9px;font-size:13.5px;box-sizing:border-box;font-family:inherit;resize:vertical;"><?= h($cmtValue) ?></textarea>
        <div id="bien-cmt-status" style="font-size:12px;color:#64748b;margin-top:6px;min-height:16px;"></div>
        <script>
        (function(){
          var SAVE = <?= json_encode(app_url('/api/bien_commentaire_save.php')) ?>;
          var BID  = <?= (int)$bienId ?>, CSRF = <?= json_encode(csrf_token('bien_commentaire')) ?>;
          var ta = document.getElementById('bien-cmt'), st = document.getElementById('bien-cmt-status');
          var t = null, last = ta.value;
          function save(){
            if (ta.value === last) return;
            last = ta.value;
            st.textContent = '⏳ Enregistrement…';
            fetch(SAVE, { method:'POST', headers:{'Content-Type':'application/json'},
              body: JSON.stringify({ bien_id:BID, csrf:CSRF, commentaire:ta.value }) })
              .then(function(r){ return r.json(); })
              .then(function(d){ st.textContent = d && d.ok ? '✓ Enregistré' : ('⚠️ ' + ((d&&d.error)||'Erreur')); })
              .catch(function(){ st.textContent = '⚠️ Erreur réseau'; });
          }
          ta.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(save, 900); });
          ta.addEventListener('blur', function(){ clearTimeout(t); save(); });
        })();
        </script>
      <?php elseif (trim($cmtValue) !== ''): ?>
        <div style="white-space:pre-wrap;font-size:13.5px;color:#334155;"><?= h($cmtValue) ?></div>
      <?php else: ?>
        <div style="font-size:13px;color:#94a3b8;font-style:italic;">Aucun commentaire.</div>
      <?php endif; ?>
    </div>

    <div class="b360-inner">

      <!-- ─────────────── COLONNE 1 — MANDATS / BAIL ─────────────── -->
      <div style="min-width:0;">

    <!-- Mandats actifs sur ce bien -->
    <?php if (!empty($mandatsActifs)): ?>
    <?php
    // Détecte les concurrents : si on a vente ET location simultanés sur un bien VIDE,
    // c'est le cas "premier arrivé l'emporte" — badge orange explicite.
    $typesActifs = array_column($mandatsActifs, 'type_mandat');
    $hasVente    = in_array('transaction', $typesActifs, true);
    $hasLocation = in_array('location', $typesActifs, true);
    $hasGerance  = in_array('gerance', $typesActifs, true);
    $isConcurrents = $hasVente && $hasLocation;
    $isCompleting  = $hasGerance && ($hasVente || $hasLocation);
    ?>
    <div class="f360-card">
        <h3>
            📜 Mandats actifs
            <span class="count"><?= count($mandatsActifs) ?></span>
        </h3>
        <?php if ($isConcurrents): ?>
            <div style="background:#fff7ed; border-left:3px solid #f59e0b; padding:8px 12px; border-radius:6px; font-size:12px; margin-bottom:10px; color:#78350f;">
                ⚠️ <strong>Mandats concurrents</strong> — vente + location simultanées. Règle « premier arrivé l'emporte » : si bail signé → annule la vente, si vente signée → annule la location.
            </div>
        <?php elseif ($isCompleting): ?>
            <div style="background:#eff6ff; border-left:3px solid #3b82f6; padding:8px 12px; border-radius:6px; font-size:12px; margin-bottom:10px; color:#1e40af;">
                ℹ️ <strong>Gestion + Vente</strong> — on continue à gérer en attendant la vente.
            </div>
        <?php endif; ?>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($mandatsActifs as $m):
                $tm = strtolower((string)$m['type_mandat']);
                [$badgeBg, $badgeFg, $icon] = match ($tm) {
                    'gerance', 'gestion' => ['#d9f0db', '#14532d', '🏠'],
                    'transaction', 'vente' => ['#fef3c7', '#92400e', '💰'],
                    'location'    => ['#dbeafe', '#1e40af', '🔑'],
                    'syndic'      => ['#e9d5ff', '#5b21b6', '🏢'],
                    default       => ['#f4f1ec', '#5a5650', '📜'],
                };
            ?>
                <div style="display:flex; align-items:center; gap:12px; padding:9px 12px; background:#fafaf6; border-radius:8px; font-size:12.5px;">
                    <span style="background:<?= $badgeBg ?>; color:<?= $badgeFg ?>; padding:3px 10px; border-radius:99px; font-weight:700; font-size:11px; min-width:90px; text-align:center;">
                        <?= $icon ?> <?= h(ucfirst($tm)) ?>
                    </span>
                    <span style="flex:1;">
                        <strong>Mandat #<?= (int)$m['id'] ?></strong>
                        <?php if ($m['numero_mandat']): ?> · n° <?= h($m['numero_mandat']) ?><?php endif; ?>
                        <span style="color:#9a9690; font-size:11px;">
                            · <?= h($m['date_debut'] ?: '—') ?>
                            <?php if ($m['date_fin']): ?>→ <?= h($m['date_fin']) ?><?php endif; ?>
                        </span>
                    </span>
                    <span style="color:#7a766f; font-size:10.5px;">
                        <?= h(ucfirst((string)$m['statut'] ?: 'actif')) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Boutons ajout mandat (Sprint MANDATS 2026-05-25) — cumulables avec GESTION -->
        <?php
            $hasGestion = $hasGerance || in_array('gestion', $typesActifs, true);
            $hasVenteAny = $hasVente || in_array('vente', $typesActifs, true);
            $hasLocation = in_array('location', $typesActifs, true);
            $missingTypes = [];
            if (!$hasGestion)  $missingTypes[] = ['type' => 'gestion',  'label' => '🏠 Gestion',  'color' => '#14532d', 'bg' => '#d9f0db'];
            if (!$hasLocation) $missingTypes[] = ['type' => 'location', 'label' => '🔑 Location', 'color' => '#1e40af', 'bg' => '#dbeafe'];
            if (!$hasVenteAny) $missingTypes[] = ['type' => 'vente',    'label' => '💰 Vente',    'color' => '#92400e', 'bg' => '#fef3c7'];
        ?>
        <?php if (!empty($missingTypes)): ?>
        <div style="display:flex; gap:8px; margin-top:12px; padding-top:10px; border-top:1px solid #f0ece6; flex-wrap:wrap;">
            <span style="font-size:11px; color:#7a766f; font-weight:700; align-self:center;">➕ Cumuler :</span>
            <?php foreach ($missingTypes as $mt): ?>
                <button type="button"
                        onclick="addMandat(<?= $bienId ?>, '<?= h($mt['type']) ?>')"
                        style="background:<?= $mt['bg'] ?>; color:<?= $mt['color'] ?>; padding:6px 14px; border:1px dashed <?= $mt['color'] ?>; border-radius:6px; font-weight:700; font-size:11.5px; cursor:pointer;">
                    + <?= h($mt['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Sprint MANDATS 2026-05-25 : si bien SANS aucun mandat actif → alerte + bouton créer GESTION socle -->
    <?php if (empty($mandatsActifs) && !$bailleurEmbed): ?>
    <div class="f360-card" style="background:#fef2f2; border-left:4px solid #dc2626;">
        <h3 style="color:#991b1b;">⚠️ Aucun mandat actif</h3>
        <p style="font-size:12px; color:#7f1d1d;">
            Ce bien n'a pas de mandat actif. Tout bien doit avoir au minimum un mandat <strong>GESTION</strong> socle.
        </p>
        <button type="button" onclick="addMandat(<?= $bienId ?>, 'gestion')"
                style="background:#dc2626; color:#fff; padding:8px 18px; border:0; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; margin-top:8px;">
            🏠 Créer le mandat GESTION socle
        </button>
    </div>
    <?php endif; ?>

    <script>
    window.addMandat = async function(bienId, type) {
        if (!confirm('Ajouter un mandat ' + type.toUpperCase() + ' au bien #' + bienId + ' ?')) return;
        try {
            const res = await fetch('<?= h(app_url("/api/bien_add_mandat.php")) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bien_id: bienId, type_mandat: type }),
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (data.ok) {
                alert('✅ Mandat ' + type + ' créé (n° ' + data.numero_mandat + ')');
                window.location.reload();
            } else {
                alert('❌ ' + (data.error || 'Erreur'));
            }
        } catch (e) { alert('❌ Réseau : ' + e.message); }
    };
    </script>

    <?php
    // ── Prefill projet de bail (gestionnaire lu en BDD, jamais en dur) + projets en cours ──
    $socRow = [];
    try { if ($idSocBien) { $q=$pdo->prepare("SELECT raison_sociale,nom,forme_juridique,capital_social,siren,siret,adresse_1,code_postal,ville,carte_pro_numero,numero_carte_t,carte_pro_cci,cci_carte_t,assurance_rcp,garantie_financiere,rib_emetteur_iban,rib_emetteur_bic,rib_emetteur_nom FROM societes WHERE id=?"); $q->execute([$idSocBien]); $socRow=$q->fetch(PDO::FETCH_ASSOC) ?: []; } } catch (Throwable) {}
    $ageRow = [];
    try { if ($idAgeBien) { $q=$pdo->prepare("SELECT nom_agence,adresse_1,code_postal,ville,rcs,iban,bic,banque_nom FROM agences WHERE id=?"); $q->execute([$idAgeBien]); $ageRow=$q->fetch(PDO::FETCH_ASSOC) ?: []; } } catch (Throwable) {}
    // RIB de GESTION de l'agence (identique au PDF : cb_resolve …,'gestion'), jamais le compte société.
    require_once __DIR__ . '/inc/comptes_bancaires.php';
    $ribGBien = cb_resolve($pdo, $idSocBien, $idAgeBien ?: null, 'gestion');
    $belPrefill = [
        'bien_id'          => (int)$bienId,
        'proprio_nom'      => (string)$proprietaireNom,
        'immeuble_nom'     => (string)($bien['nom_immeuble'] ?: $bien['imm_adresse'] ?: ''),
        'bien_ref'         => (string)($bien['reference_bien'] ?: ('Bien #' . $bienId)),
        'bien_adresse'     => trim((string)($bien['bien_adresse'] ?? '') . ' ' . ($bien['bien_cp'] ?? '') . ' ' . ($bien['bien_ville'] ?? '')),
        'bien_surface'     => (float)($bien['surface_habitable'] ?? 0),
        'bien_lot'         => (string)($bien['numero_lot'] ?? ''),
        'bien_etage'       => (($bien['etage'] ?? null) !== null && $bien['etage'] !== '' ? ((int)$bien['etage'] === 0 ? 'rez-de-chaussée' : (int)$bien['etage'] . 'ᵉ étage') : ''),
        'bien_copro'       => (!empty($bien['bien_en_copropriete']) ? 'bien en copropriété' . (!empty($bien['lot_tantiemes']) ? ' (' . (int)$bien['lot_tantiemes'] . ' / ' . (int)($bien['copro_nb_lots'] ?: 0) . ' tantièmes)' : '') : ''),
        'bien_description' => (string)($bien['description'] ?? ''),
        'bien_designation'    => (string)($bien['designation'] ?? ''),
        'bien_en_copropriete' => !empty($bien['bien_en_copropriete']) ? 1 : 0,
        'bien_numero_lot'     => (string)($bien['numero_lot'] ?? ''),
        'bien_tantiemes'      => (string)($bien['lot_tantiemes'] ?? ''),
        'gestionnaire'     => [
            'raison'    => (string)(($socRow['raison_sociale'] ?? '') ?: ($socRow['nom'] ?? '')),
            'forme'     => (string)($socRow['forme_juridique'] ?? ''),
            'capital'   => $socRow['capital_social'] ?? null,
            'siren'     => (string)(($socRow['siren'] ?? '') ?: ($socRow['siret'] ?? '')),
            'adresse'   => trim((string)($socRow['adresse_1'] ?? '') . ' ' . ($socRow['code_postal'] ?? '') . ' ' . ($socRow['ville'] ?? '')),
            'carte'     => (string)(($socRow['carte_pro_numero'] ?? '') ?: ($socRow['numero_carte_t'] ?? '')),
            'carte_cci' => (string)(($socRow['carte_pro_cci'] ?? '') ?: ($socRow['cci_carte_t'] ?? '')),
            'rcp'       => (string)($socRow['assurance_rcp'] ?? ''),
            'garantie'  => (string)($socRow['garantie_financiere'] ?? ''),
            'age_nom'   => (string)($ageRow['nom_agence'] ?? ''),
            'age_adresse'=> trim((string)($ageRow['adresse_1'] ?? '') . ' ' . ($ageRow['code_postal'] ?? '') . ' ' . ($ageRow['ville'] ?? '')),
            'rib_iban'  => (string)$ribGBien['iban'],
            'rib_bic'   => (string)$ribGBien['bic'],
            'rib_nom'   => (string)($ribGBien['banque'] ?: $ribGBien['titulaire']),
        ],
        'origin'           => 'bien_360',
    ];
    echo '<script>window.BEL_PREFILL_CREATE = ' . json_encode($belPrefill, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) . ';</script>';
    $belOnClick = 'bailOpenCreateModal(window.BEL_PREFILL_CREATE);return false;';
    // Émission du modal (idempotente) — garantit sa présence même si le panneau Actions ne s'exécute pas.
    require_once __DIR__ . '/inc/bail_edit_modal.php';
    bail_edit_modal();

    $bailProjets = [];
    try { $qp=$pdo->prepare("SELECT id,numero_bail,statut,locataire_raison_sociale,locataire_nom,locataire_prenom,loyer_mensuel_hc,date_prise_effet FROM bien_baux WHERE id_bien=? AND statut IN ('projet','envoye','signe','avenant') ORDER BY id DESC"); $qp->execute([$bienId]); $bailProjets=$qp->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
    $belStatutLbl = ['projet'=>['🟡','Projet'],'envoye'=>['📨','Envoyé à signer'],'signe'=>['✅','Signé'],'avenant'=>['📝','Avenant']];
    ?>
    <!-- Bail actif / Archives -->
    <div class="f360-card" style="--acc:var(--c-bail);">
        <div class="f360-tabs">
            <button type="button" class="f360-tab active" onclick="f360tab(this, 'tab-bail')">📋 Bail actif <span class="count"><?= $bailActif ? 1 : 0 ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tab(this, 'tab-arch')">🗂 Archives <span class="count"><?= count($archivesBaux) ?></span></button>
            <button type="button" class="f360-tab"        onclick="f360tab(this, 'tab-offres')">💰 Offres <span class="count"><?= count($offres) ?></span></button>
        </div>

        <div id="tab-bail">
            <?php if ($bailActif): ?>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px;">
                    <div><div style="font-size:10px; color:#9a9690;">LOCATAIRE</div><strong><a href="<?= h(app_url('/bail_360.php?id=' . (int)$bailActif['id'])) ?>" style="color:#5b21b6;text-decoration:none;border-bottom:1px dotted #b39ddb;" title="Ouvrir la fiche bail 360° (infos + documents)"><?= h($locataireNom) ?> ↗</a></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">NATURE</div><strong><?= h($bailActif['bail_nature']) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">PRISE D'EFFET</div><strong><?= h($bailActif['date_prise_effet']) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">FIN</div><strong><?= h($bailActif['date_fin']) ?></strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">LOYER DE BASE</div><strong><?= number_format((float)$bailActif['loyer_mensuel_hc'], 0, ',', ' ') ?> €/mois</strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">CHARGES</div><strong><?= number_format((float)$bailActif['charges_mensuelles'], 0, ',', ' ') ?> €/mois</strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">DG</div><strong><?= number_format((float)$bailActif['depot_garantie'], 0, ',', ' ') ?> €</strong></div>
                    <div><div style="font-size:10px; color:#9a9690;">INDICE</div><strong><?= h($bailActif['indice_type']) ?> <?= h($bailActif['indice_trimestre']) ?></strong></div>
                </div>
                <?php if ($bailActif['conditions_particulieres'] ?? ''): ?>
                    <div style="margin-top:12px; padding:10px 12px; background:#f9f7ff; border-left:3px solid #7c3aed; border-radius:6px; font-size:12px;">
                        <strong>📋 Conditions particulières :</strong> <?= h(mb_substr((string)$bailActif['conditions_particulieres'], 0, 400)) ?><?= mb_strlen($bailActif['conditions_particulieres']) > 400 ? '…' : '' ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="f360-empty"><div class="em-ico">🔓</div>Aucun bail actif sur ce bien.</div>
            <?php endif; ?>

            <!-- ── Projet(s) de nouveau bail (workflow type mandat) ── -->
            <div style="margin-top:14px;border-top:1px dashed #d9d2e6;padding-top:12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">
                    <div style="font-size:12px;font-weight:800;color:#5f8f93;text-transform:uppercase;letter-spacing:.04em;">🔑 Projet de nouveau bail <span style="color:#9a9690;font-weight:600;">(<?= count($bailProjets) ?>)</span></div>
                    <button type="button" onclick="<?= h($belOnClick) ?>" style="border:1.5px solid #84A7AB;background:#eef5f5;color:#3a5a5c;border-radius:999px;padding:5px 13px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;">＋ Nouveau projet</button>
                </div>
                <?php if (empty($bailProjets)): ?>
                    <div style="font-size:12px;color:#8a97a0;">Aucun projet en cours. « Nouveau projet » ouvre le générateur de bail commercial (candidat + conditions).</div>
                <?php else: foreach ($bailProjets as $bp):
                    $bpCand = $bp['locataire_raison_sociale'] ?: trim((string)$bp['locataire_prenom'] . ' ' . $bp['locataire_nom']) ?: 'Candidat à définir';
                    $bpSt = $belStatutLbl[$bp['statut']] ?? ['•', $bp['statut']];
                ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:9px 10px;border:1px solid #e5e0ee;border-radius:9px;color:#2c2a28;margin-bottom:6px;background:#fbfaff;">
                        <span style="font-size:15px;"><?= $bpSt[0] ?></span>
                        <span style="flex:1;min-width:0;">
                            <strong style="font-size:13px;">🔑 <?= h($bpCand) ?></strong>
                            <span style="font-size:11px;font-weight:700;color:#5f8f93;"> · <?= h($bpSt[1]) ?></span>
                            <div style="font-size:11px;color:#8a8694;margin-top:1px;">
                                <?= h($bp['numero_bail'] ?: ('#' . $bp['id'])) ?>
                                <?= $bp['date_prise_effet'] ? ' · effet ' . h(date('d/m/Y', strtotime((string)$bp['date_prise_effet']))) : '' ?>
                                <?= $bp['loyer_mensuel_hc'] ? ' · ' . number_format((float)$bp['loyer_mensuel_hc']*12, 0, ',', ' ') . ' €/an' : '' ?>
                            </div>
                        </span>
                        <a href="<?= h(app_url('/bail_360.php?id=' . (int)$bp['id'])) ?>" style="border:1.5px solid #5f8f93;background:#fff;color:#3a5a5c;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap;">📂 Reprendre le dossier</a>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div id="tab-arch" style="display:none;">
            <?php if (empty($archivesBaux)): ?>
                <div class="f360-empty"><div class="em-ico">🗂</div>Pas d'historique de baux.</div>
            <?php else: foreach ($archivesBaux as $a):
                $aLoc = $a['locataire_raison_sociale'] ?: trim((string)$a['locataire_prenom'] . ' ' . $a['locataire_nom']);
            ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px;">
                    <strong>Bail #<?= (int)$a['id'] ?></strong> · <?= h($a['bail_nature']) ?> · <em><?= h($a['statut']) ?></em>
                    — <?= h($aLoc) ?> · <?= h($a['date_prise_effet']) ?> → <?= h($a['date_fin']) ?>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div id="tab-offres" style="display:none;">
            <?php if (empty($offres)): ?>
                <div class="f360-empty"><div class="em-ico">💰</div>Aucune offre reçue.</div>
            <?php else: foreach ($offres as $o):
                $sCol = match($o['statut_offre']){'acceptee'=>'#2d6a35','refusee'=>'#a8323b','expiree'=>'#7a766f',default=>'#8a4c12'};
            ?>
                <div style="padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px; display:flex; gap:10px; align-items:center;">
                    <strong style="color:<?= $sCol ?>; font-size:14px;"><?= number_format((float)$o['prix_propose'], 0, ',', ' ') ?> €</strong>
                    <span><?= h(trim((string)$o['prenom'] . ' ' . $o['nom'])) ?></span>
                    <span style="color:#9a9690; font-size:10px;"><?= h($o['financement_type'] ?? '') ?> · <?= h($o['statut_offre'] ?? '?') ?></span>
                    <span style="margin-left:auto; color:#9a9690; font-size:10px;"><?= h(date('d/m/y', strtotime((string)$o['date_creation']))) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

      </div>
      <!-- fin COLONNE 1 -->

      <!-- ─────────────── COLONNE 2 — DOCUMENTS ─────────────── -->
      <div style="min-width:0;">

    <!-- CARD 1 — Documents de base (checklist pliable, complétude en titre) -->
    <?php $pctP = $totP>0 ? round($nbOkP/$totP*100) : 0;
          $barCol = $pctP>=100 ? '#166534' : ($pctP>=50 ? '#b45309' : '#b91c1c'); ?>
    <details class="f360-card" style="margin-bottom:14px; --acc:var(--c-document);">
      <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;">
        <span style="font-weight:700;color:#2c2a28;font-size:13.5px;">📋 Documents de base</span>
        <span style="background:#f4f1ec;color:#565434;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;"><?= (int)$nbOkP ?>/<?= (int)$totP ?></span>
        <span style="flex:1;height:6px;background:#eef2f7;border-radius:4px;overflow:hidden;"><span style="display:block;height:100%;width:<?= $pctP ?>%;background:<?= $barCol ?>;"></span></span>
        <span style="color:#94a3b8;font-size:12px;">déplier ▾</span>
      </summary>
      <div style="margin-top:10px;"><?= preg_replace('#<h3>.*?</h3>#s', '', $piecesHtml) ?></div>
    </details>

    <!-- CARD 2 — Documents divers (hors pièces de base) -->
    <div class="f360-card" style="--acc:var(--c-document);">
        <h3>📂 Documents divers <span class="count"><?= count($docsDivers) ?></span></h3>
        <?php if (empty($docsDivers)): ?>
            <div class="f360-empty"><div class="em-ico">📄</div>Aucun document divers. <a href="<?= h(app_url('/bien_documents_list.php?id=' . $bienId)) ?>">→ Gérer les documents</a></div>
        <?php else: foreach ($docsDivers as $d): ?>
            <div onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>)"
                 style="padding:8px 0; border-bottom:1px solid #f0ece6; cursor:pointer;"
                 onmouseover="this.style.background='#faf8ff'" onmouseout="this.style.background='transparent'">
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:2px;" title="<?= h($d['name_display']) ?>"><?= ged_name_pills((string)$d['name_display'], 'BIEN') ?></div>
                <div style="display:flex; align-items:center; gap:8px; margin-top:4px; font-size:10px; color:#9a9690;">
                    <span style="font-family:'DM Mono',monospace; color:#5b21b6; font-weight:700;">[<?= h($d['document_type']) ?>]</span>
                    <span><?= h(date('d/m/y', strtotime((string)$d['created_at']))) ?></span>
                    <button type="button" onclick="event.stopPropagation();gedDeleteDoc(<?= (int)$d['id'] ?>,<?= htmlspecialchars(json_encode((string)$d['name_display']), ENT_QUOTES) ?>,this)" title="Supprimer" style="margin-left:auto;border:none;background:transparent;color:#c0392b;cursor:pointer;font-size:13px;padding:0 2px;">🗑️</button>
                    <span style="color:#5b21b6; font-weight:700;">Ouvrir ›</span>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; /* modale standard mvptModalView */ ?>
    <?php require_once __DIR__ . '/inc/ged_delete_modal.php'; ?>

    <!-- Dossiers sources (archives OneDrive liées, non importées) — inclusion défensive -->
    <?php
    $gsfCardFile = __DIR__ . '/inc/ged_source_folders_card.php';
    if (is_file($gsfCardFile)) { require_once $gsfCardFile;
        if (function_exists('ged_source_folders_card')) { try {
            ged_source_folders_card($pdo, 'BIEN', $bienId, ['id_societe'=>$idSocBien, 'id_agence'=>$idAgeBien, 'metier'=>'gestion']);
        } catch (Throwable $e) {} } }
    ?>

    <!-- Photos du bien (sous-dossiers par groupe) -->
    <?php $photosUrl = app_url('/bien_detail.php?edit=' . $bienId . '&section=documents&focus=photos'); ?>
    <div class="f360-card" style="--acc:var(--c-bien);">
        <h3>📸 Photos <span class="count"><?= (int)$photosTotal ?></span></h3>
        <?php if (empty($photoGroups)): ?>
            <div class="f360-empty"><div class="em-ico">📷</div>Aucune photo. Déverse-les depuis MaBoxOffice (bouton « 📸 Enregistrer en photos »).</div>
        <?php else: foreach ($photoGroups as $g): ?>
            <details class="ph-grp">
                <summary style="cursor:pointer; padding:8px 0; font-size:13px; font-weight:700; color:#243B5C; list-style:none; display:flex; align-items:center; gap:8px;">
                    <span style="font-family:'DM Mono',monospace; color:#84a98c;"><?= str_pad((string)$g['no'], 2, '0', STR_PAD_LEFT) ?></span>
                    📁 <?= h($g['label']) ?>
                    <span style="font-size:11px; color:#9a9690; font-weight:600;"><?= count($g['photos']) ?> photo<?= count($g['photos'])>1?'s':'' ?></span>
                    <button type="button" onclick="event.preventDefault();openPhotosFrame('<?= h($photosUrl) ?>');"
                            style="margin-left:auto; border:1px solid #cbd5e1; background:#fff; color:#5b21b6; border-radius:7px; padding:3px 10px; font-size:11px; font-weight:700; cursor:pointer;">Détails ↗</button>
                </summary>
                <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(72px,1fr)); gap:6px; padding:6px 0 12px;">
                    <?php foreach ($g['photos'] as $p): $thumb = app_url('/' . ltrim((string)($p['url_lbc'] ?: $p['url_photo']), '/')); ?>
                        <a href="<?= h($photosUrl) ?>" onclick="event.preventDefault();openPhotosFrame(this.getAttribute('href'));" title="<?= h($p['nom_original'] ?: '') ?>" style="display:block; aspect-ratio:1; border-radius:8px; overflow:hidden; border:1px solid #f0ece6; cursor:zoom-in;">
                            <img src="<?= h($thumb) ?>" alt="<?= h($p['nom_original'] ?: '') ?>" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; endif; ?>
    </div>

    <!-- Overlay iframe : gestion des photos (onglet Photos de bien_details, réutilisé tel quel) -->
    <div id="photosFrameOverlay" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(20,26,40,.55); backdrop-filter:blur(2px);">
        <div style="position:absolute; inset:24px; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 30px 80px rgba(20,26,40,.4); display:flex; flex-direction:column;">
            <div style="display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid #eef1f6; background:#f8fafc;">
                <b style="font-size:14px; color:#243B5C;">📸 Photos du bien</b>
                <a href="<?= h($photosUrl) ?>" target="_blank" style="font-size:12px; color:#5b21b6; font-weight:600; text-decoration:none;">Ouvrir en plein écran ↗</a>
                <button type="button" onclick="closePhotosFrame()" style="margin-left:auto; border:none; background:#eef1f6; width:32px; height:32px; border-radius:50%; font-size:15px; cursor:pointer; color:#556;">✕</button>
            </div>
            <iframe id="photosFrame" src="about:blank" style="flex:1; width:100%; border:0;"></iframe>
        </div>
    </div>
    <script>
    function openPhotosFrame(url){ var o=document.getElementById('photosFrameOverlay'), f=document.getElementById('photosFrame');
        if(f.getAttribute('data-src')!==url){ f.src=url; f.setAttribute('data-src',url); } o.style.display='block'; document.body.style.overflow='hidden'; }
    function closePhotosFrame(){ document.getElementById('photosFrameOverlay').style.display='none'; document.body.style.overflow=''; }
    document.getElementById('photosFrameOverlay').addEventListener('click', function(e){ if(e.target===this) closePhotosFrame(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePhotosFrame(); });
    </script>

    <!-- Repli par défaut de TOUTES les cards de la page (titre = toggle) -->
    <style>
      .f360-collapsible > h3 { cursor:pointer; display:flex; align-items:center; gap:8px; user-select:none; }
      .f360-collapsible.collapsed > .f360-cardbody { display:none; }
      .f360-chev { margin-left:auto; font-size:12px; color:#9a9690; transition:transform .15s ease; }
      .f360-collapsible:not(.collapsed) > h3 .f360-chev { transform:rotate(90deg); }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
      document.querySelectorAll('.f360-card').forEach(function(card){
        var h = card.querySelector(':scope > h3');
        if(!h) return; // pas de titre → on laisse la card telle quelle
        var body = document.createElement('div'); body.className = 'f360-cardbody';
        while(h.nextSibling){ body.appendChild(h.nextSibling); }
        card.appendChild(body);
        card.classList.add('f360-collapsible','collapsed');
        var chev = document.createElement('span'); chev.className='f360-chev'; chev.textContent='▸';
        h.appendChild(chev);
        h.addEventListener('click', function(e){
          if(e.target.closest('a,button')) return; // ne pas toggler sur un lien/bouton du titre
          card.classList.toggle('collapsed');
        });
      });
    });
    </script>

    <!-- Mentionné dans -->
    <?php
    $mentionsForLayout = array_map(fn($m) => [
        'icon'  => '📄',
        'title' => $m['name_display'],
        'ref'   => $m['document_type'] . ' · ' . date('d/m/y', strtotime((string)$m['created_at'])),
        'url'   => app_url('/api/ged_document_view.php?id=' . (int)$m['id'] . '&mode=inline'),
    ], $mentions);
    fiche360_mention_dans($mentionsForLayout);
    ?>

      </div>
      <!-- fin COLONNE 2 -->

    </div><!-- fin .b360-inner -->
  </div><!-- fin ZONE GAUCHE -->

  <!-- ═══════════════════ COLONNE DROITE — ACTIONS + CONTACTS ═══════════════════ -->
  <div style="min-width:0;">

    <?php
    // Panneau Actions — remonté EN HAUT de la colonne pour visibilité immédiate.
    // En contexte BAILLEUR (modal) : panneau réduit en lecture seule (aucune action agency/transaction).
    if ($bailleurEmbed) {
        fiche360_actions_panel('Consultation', [
            ['icon'=>'📁','label'=>'Documents du bien','url'=>app_url('/bien_documents_list.php?id=' . $bienId)],
        ]);
    } else {
        fiche360_actions_panel('Actions bien', [
            ['icon'=>'📝','label'=>'Descriptif du bien','url'=>app_url('/bien_detail.php?edit=' . $bienId)],
            ['icon'=>'🗂️','label'=>($hasDossierVente ? 'Voir le dossier de vente' : 'Créer le dossier de vente'),'url'=>app_url('/transaction_dossier.php?id_bien=' . $bienId)],
            ['icon'=>'🔑','label'=>'Créer un projet de bail','url'=>'#','onclick'=>$belOnClick],
            ['icon'=>'📤','label'=>'Charger des documents','url'=>'#','onclick'=>$fbxOnClickBien],
            ['icon'=>'📨','label'=>'Demander un document','url'=>app_url('/document_request_new.php?ctx=BIEN&id=' . $bienId . '&back=' . urlencode('bien_360.php?id=' . $bienId))],
            ['icon'=>'📥','label'=>'Importer docs OneDrive (bien + locataires)','url'=>'javascript:odClasserOpen()'],
            ['icon'=>'📂','label'=>'Ouvrir le dossier OneDrive','url'=>'javascript:odOpenFolder()'],
            ['icon'=>'📡','label'=>'Créer une annonce',        'url'=>app_url('/bien_detail.php?edit=' . $bienId . '&section=annonce')],
            ['icon'=>'📁','label'=>'Documents du bien',        'url'=>app_url('/bien_documents_list.php?id=' . $bienId)],
        ]);
        // Modal « Créer un projet de bail commercial » (émis une seule fois).
        require_once __DIR__ . '/inc/bail_edit_modal.php';
        bail_edit_modal();
    }

    // (La checklist « Documents de base » est désormais en CARD 1 de la colonne 2.)


    // ── CONTACTS : propriétaire + locataire (+ représentants) surfacés systématiquement ──
    $contactLinks = [];
    if (!empty($bien['proprio_id'])) {
        $proprioUrl = !empty($bien['proprio_tiers_id'])
            ? app_url('/tiers_360.php?id=' . (int)$bien['proprio_tiers_id'])
            : app_url('/agency_proprietaires.php?q=' . urlencode($proprietaireNom));
        $contactLinks[] = ['icon'=>'🏠','name'=>$proprietaireNom . ' — Propriétaire','ref'=>'#' . $bien['proprio_id'] . (!empty($bien['proprio_tiers_id']) ? ' · tiers ' . $bien['proprio_tiers_id'] : ''),'url'=>$proprioUrl];
    }
    if ($bailActif) {
        $locUrl = $locataireTiersId
            ? app_url('/tiers_360.php?id=' . (int)$locataireTiersId)
            : app_url('/bail_360.php?id=' . (int)$bailActif['id']);
        $contactLinks[] = ['icon'=>'🔑','name'=>$locataireNom . ' — Locataire','ref'=>'Bail #' . $bailActif['id'] . ' · jusqu\'au ' . $bailActif['date_fin'],'url'=>$locUrl];
    }
    foreach ($representants as $r) {
        $rNom = trim((string)$r['prenom'] . ' ' . $r['nom']);
        $rUrl = !empty($r['id']) ? app_url('/tiers_360.php?id=' . (int)$r['id']) : '#';
        $contactLinks[] = ['icon'=>'👥','name'=>$rNom . ' (' . $r['qualite'] . ')','ref'=>$r['email'] ?: '','url'=>$rUrl];
    }
    if (!empty($contactLinks)) fiche360_attach('CONTACTS (' . count($contactLinks) . ')', $contactLinks);

    // (card IMMEUBLE retirée : l'immeuble est déjà dans la chaîne en haut + la carte « Données publiques »)
    // Bouton « changer l'immeuble » (relink simple) — staff manager
    $roleIdBien = function_exists('current_role_id') ? (int)current_role_id() : 0;
    $canRelinkImm = in_array($roleIdBien, [1,2,7], true) || (function_exists('is_super_admin') && is_super_admin());
    // (bouton « Changer l'immeuble » retiré à la demande)

    // Données publiques de l'immeuble (enrichissement persisté + bouton de relance pour admin)
    if (!empty($bien['immeuble_id'])) {
        require_once __DIR__ . '/inc/immeuble_public_card.php';
        // Admin/super admin + bailleurs (9/10) : peuvent lancer les recherches publiques
        // sur leur immeuble (les API immeuble_/geo_ d'enrichissement sont whitelistées).
        $canEnrich = (function_exists('current_role_id') && in_array((int)current_role_id(), [1,7,9,10], true))
                  || (function_exists('is_super_admin') && is_super_admin());
        $gvLabel = trim((string)(($bien['nom_immeuble'] ?? '') ?: ($bien['imm_adresse'] ?? '')));
        if (($bien['imm_ville'] ?? '') !== '') $gvLabel = trim($gvLabel . ' · ' . $bien['imm_ville']);
        immeuble_public_card($pdo, (int)$bien['immeuble_id'], $canEnrich,
            ['lat' => $bien['latitude'] ?? '', 'lng' => $bien['longitude'] ?? '', 'label' => $gvLabel]);
    }

    // ── Marquer vendu (sort des « à vendre », garde l'historique) ──
    // « Déjà vendu » = vraiment vendu (statut_bien) ou prix final posé — PAS un simple retrait.
    $dejaVendu = (($bien['statut_bien'] ?? '') === 'vendu') || !empty($bien['prix_final_vente']);
    ?>
    <div style="margin-top:14px;">
      <?php if (!empty($_GET['vendu'])): ?>
        <div style="background:#e3f3e8;color:#2d8a4e;border:1px solid #9fd3b0;border-radius:10px;padding:10px 14px;font-weight:700;margin-bottom:10px;">✅ Bien marqué vendu — sorti des « à vendre », historique conservé.</div>
      <?php endif; ?>
      <?php if ($dejaVendu): ?>
        <div style="background:#efe7f7;color:#6b4aa0;border:1px solid #c9b8e6;border-radius:10px;padding:10px 14px;font-weight:700;">🏷️ Ce bien est déjà marqué vendu / retiré de la commercialisation.</div>
      <?php elseif (!$bailleurEmbed): ?>
        <form method="post" action="<?= h(app_url('/api/bien_marquer_vendu.php')) ?>" onsubmit="return confirm('Marquer ce bien comme VENDU ?\nIl sortira des « à vendre » (l\'historique est conservé).');">
          <?= csrf_field('bien_vendu') ?>
          <input type="hidden" name="id_bien" value="<?= (int)$bienId ?>">
          <input type="hidden" name="retour" value="<?= h(app_url('/bien_360.php?id=' . $bienId)) ?>">
          <button type="submit" style="width:100%;cursor:pointer;background:linear-gradient(135deg,#2d8a4e,#23703f);color:#fff;border:none;border-radius:10px;padding:11px 14px;font-size:14px;font-weight:800;">🏷️ Marquer ce bien vendu</button>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<?= fiche360_js() ?>
<script>
function f360tab(btn, targetId) {
    btn.parentElement.querySelectorAll('.f360-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const container = btn.closest('.f360-card');
    ['tab-bail','tab-arch','tab-offres'].forEach(id => {
        const el = container.querySelector('#' + id);
        if (el) el.style.display = (id === targetId) ? 'block' : 'none';
    });
}
</script>

<!-- ── Recherche assistée OneDrive → GED (v1 DPE) ─────────────────────── -->
<style>
/* Bouton Descriptif : doré charte MBI, texte blanc, relief 3D, séparé à gauche + plus haut */
.tr-btn-gold{ order:-1; margin-right:auto !important;
  background:linear-gradient(180deg,#e7c364,#d4a047) !important; color:#fff !important;
  border:none !important; border-radius:10px !important; font-weight:800 !important; font-size:14.5px !important;
  padding:14px 24px !important; line-height:1 !important;
  text-shadow:0 1px 2px rgba(0,0,0,.28);
  box-shadow:0 5px 0 #a87d2c, 0 9px 16px rgba(0,0,0,.24) !important;
  transition:transform .08s ease, box-shadow .08s ease, filter .12s !important; }
.tr-btn-gold:hover{ filter:brightness(1.06); }
.tr-btn-gold:active{ transform:translateY(3px) !important; box-shadow:0 1px 0 #a87d2c, 0 2px 6px rgba(0,0,0,.2) !important; }
/* Bouton "Annonce active" : vert plein, indique que le bien est diffusé en ligne */
.tr-btn-annonce-active{ background:#16a34a !important; color:#fff !important; border:none !important;
  border-radius:9px !important; font-weight:800 !important; padding:9px 16px !important;
  box-shadow:0 2px 0 #128a3e, 0 4px 10px rgba(22,163,74,.25) !important; }
.tr-btn-annonce-active:hover{ filter:brightness(1.05); }
.tr-btn-annonce-active:active{ transform:translateY(2px) !important; box-shadow:0 1px 0 #128a3e !important; }
.f360-ged-search-btn{margin-left:8px;cursor:pointer;background:#0f6cbd;color:#fff;border:none;
  border-radius:8px;padding:5px 10px;font-size:12px;font-weight:700;white-space:nowrap;}
.f360-ged-search-btn:hover{background:#0c5aa0;}
.gedov-overlay{position:fixed;inset:0;background:rgba(20,22,28,.55);z-index:9998;display:none;}
.gedov-modal{position:fixed;z-index:9999;top:50%;left:50%;transform:translate(-50%,-50%);
  width:min(720px,94vw);max-height:86vh;overflow:auto;background:#fff;border-radius:14px;
  box-shadow:0 24px 60px rgba(0,0,0,.35);padding:22px;display:none;}
.gedov-modal h3{margin:0 0 4px;font-size:18px;}
.gedov-sub{color:#6b7280;font-size:13px;margin-bottom:14px;}
.gedov-cand{display:flex;align-items:flex-start;gap:10px;border:1px solid #e5e7eb;border-radius:10px;
  padding:10px 12px;margin-bottom:8px;cursor:pointer;}
.gedov-cand:hover{border-color:#0f6cbd;background:#f5faff;}
.gedov-cand.sel{border-color:#0f6cbd;background:#eef6ff;box-shadow:0 0 0 2px #cfe4fb inset;}
.gedov-cand .nm{font-weight:700;font-size:14px;word-break:break-word;}
.gedov-cand .pt{color:#6b7280;font-size:12px;word-break:break-word;}
.gedov-cand .badge{font-size:11px;font-weight:700;color:#0f6cbd;background:#e3f0ff;border-radius:6px;padding:2px 7px;}
.gedov-cand .best{background:#e3f3e8;color:#2d8a4e;}
.gedov-prev{font-size:12px;font-weight:700;color:#0f6cbd;text-decoration:none;border:1px solid #cfe4fb;border-radius:7px;padding:3px 9px;display:inline-block;}
.gedov-prev:hover{background:#eef6ff;}
.gedov-prevclose{cursor:pointer;background:#fde8e8;color:#b42318;border:1px solid #f5b5b5;font-weight:800;}
.gedov-prevclose:hover{background:#f9d2d2;}
.gedov-rowact{margin-top:9px;display:flex;gap:8px;flex-wrap:wrap;}
.gedov-rowbtn{cursor:pointer;border:none;border-radius:8px;padding:7px 12px;font-size:12px;font-weight:800;}
.gedov-rowbtn.prev{background:#eef2f6;color:#0f6cbd;border:1px solid #cfe4fb;}
.gedov-rowbtn.prev:hover{background:#e0ecf7;}
.gedov-rowbtn.valid{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
.gedov-rowbtn.valid:hover{filter:brightness(1.06);}
.gedov-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:14px;}
.gedov-btn{cursor:pointer;border:none;border-radius:9px;padding:10px 16px;font-weight:800;font-size:14px;}
.gedov-btn.cancel{background:#eceef1;color:#374151;}
.gedov-btn.valider{background:linear-gradient(135deg,#0f6cbd,#0c5aa0);color:#fff;}
.gedov-btn:disabled{opacity:.5;cursor:not-allowed;}
.gedov-state{padding:24px 6px;text-align:center;color:#6b7280;font-size:14px;}
.gedov-ctx{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;padding:10px 12px;background:#f5f7fa;border:1px solid #e5e7eb;border-radius:10px;}
.gedov-ctx .chip{font-size:12px;font-weight:700;color:#374151;background:#fff;border:1px solid #e0e4e8;border-radius:7px;padding:4px 9px;}
.gedov-ctx .chip.lot{background:#fff7e6;border-color:#f0d28a;color:#92600a;}
.gedov-ctx .chip.loc{background:#eef6ff;border-color:#cfe4fb;color:#0f5a9e;}
.gedov-spin{width:38px;height:38px;border:4px solid #d6e6f7;border-top-color:#0f6cbd;border-radius:50%;
  margin:6px auto 14px;animation:gedovspin .8s linear infinite;}
@keyframes gedovspin{to{transform:rotate(360deg);}}
.gedov-dots::after{content:'';animation:gedovdots 1.4s steps(4,end) infinite;}
@keyframes gedovdots{0%{content:'';}25%{content:'.';}50%{content:'..';}75%{content:'...';}}
</style>
<div class="gedov-overlay" id="gedovOverlay"></div>
<div class="gedov-modal" id="gedovModal">
  <h3 id="gedovTitle">Recherche OneDrive</h3>
  <div class="gedov-sub" id="gedovSub"></div>
  <div id="gedovCtx" class="gedov-ctx" style="display:none;"></div>
  <div id="gedovBody"><div class="gedov-state">…</div></div>
  <div class="gedov-foot">
    <button type="button" class="gedov-btn cancel" onclick="gedovClose()">Annuler</button>
    <button type="button" class="gedov-btn valider" id="gedovValider" disabled onclick="gedovCommit()">✓ Valider &amp; classer</button>
  </div>
</div>
<script>
(function(){
  const BIEN_ID = <?= (int)$bienId ?>;
  const CSRF    = <?= json_encode(csrf_token('ged_graph')) ?>;
  const SEARCH_URL = <?= json_encode(app_url('/api/graph_doc_search.php')) ?>;
  const COMMIT_URL = <?= json_encode(app_url('/api/graph_doc_commit.php')) ?>;
  const PREVIEW_URL= <?= json_encode(app_url('/api/graph_doc_preview.php')) ?>;
  let curType = null, curLabel = '', selectedItem = null, busy = false;

  function fmtSize(b){ if(!b) return ''; const u=['o','Ko','Mo','Go']; let i=0,v=b;
    while(v>=1024&&i<u.length-1){v/=1024;i++;} return (i?v.toFixed(1):v)+' '+u[i]; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

  window.gedovClose = function(){
    document.getElementById('gedovOverlay').style.display='none';
    document.getElementById('gedovModal').style.display='none';
    selectedItem=null;
  };

  function openModal(){
    document.getElementById('gedovOverlay').style.display='block';
    document.getElementById('gedovModal').style.display='block';
    document.getElementById('gedovValider').disabled=true;
  }

  function renderCandidates(cands){
    const body=document.getElementById('gedovBody');
    if(!cands.length){ body.innerHTML='<div class="gedov-state">Aucun document trouvé dans le OneDrive pour ce bien.<br>Essayez le chargement manuel.</div>'; return; }
    body.innerHTML = cands.map((c,i)=>{
      const badges=[];
      if(c.best) badges.push('<span class="badge best">Meilleur candidat</span>');
      if(c.in_folder) badges.push('<span class="badge best">📁 bon dossier</span>');
      badges.push('<span class="badge">score '+c.score+'</span>');
      (c.reasons||[]).forEach(r=>badges.push('<span class="badge">'+esc(r)+'</span>'));
      const prev=PREVIEW_URL+'?item_id='+encodeURIComponent(c.item_id);
      return '<div class="gedov-cand" data-i="'+i+'">'+
        '<div style="flex:1;min-width:0;">'+
          '<div class="nm">'+esc(c.name)+'</div>'+
          '<div class="pt" title="Chemin OneDrive">📁 '+esc(c.path||'(racine)')+(c.size?(' · '+fmtSize(c.size)):'')+'</div>'+
          '<div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap;">'+badges.join('')+'</div>'+
          '<div class="gedov-rowact">'+
            '<button type="button" class="gedov-rowbtn prev" data-i="'+i+'">👁 Aperçu</button>'+
            '<button type="button" class="gedov-rowbtn valid" data-i="'+i+'">✓ Valider &amp; classer ce document</button>'+
          '</div>'+
          '<div class="gedov-prevbox" data-prev="'+prev+'"></div>'+
        '</div></div>';
    }).join('');
    function closePreview(box){ if(box){ box.innerHTML=''; box.dataset.open='0'; } }
    function openPreview(box){
      if(!box) return;
      box.innerHTML='<div style="display:flex;align-items:center;justify-content:space-between;margin:8px 0 5px;">'+
          '<span style="font-weight:700;font-size:12px;color:#374151;">👁 Aperçu</span>'+
          '<button type="button" class="gedov-prev gedov-prevclose">✕ Fermer l\'aperçu</button></div>'+
        '<iframe src="'+box.dataset.prev+'" style="width:100%;height:48vh;border:1px solid #e5e7eb;border-radius:10px;background:#fff;"></iframe>'+
        '<div style="margin-top:5px;"><a class="gedov-prev" href="'+box.dataset.prev+'" target="_blank" rel="noopener">↗ Ouvrir en plein écran</a></div>';
      box.dataset.open='1';
      box.querySelector('.gedov-prevclose').addEventListener('click',(ev)=>{ ev.stopPropagation(); closePreview(box); });
      box.scrollIntoView({block:'nearest',behavior:'smooth'});
    }
    function selectRow(el){
      body.querySelectorAll('.gedov-cand').forEach(x=>x.classList.remove('sel'));
      el.classList.add('sel');
      selectedItem=cands[+el.dataset.i];
      document.getElementById('gedovValider').disabled=false;
    }
    function togglePreviewFor(el){
      const box=el.querySelector('.gedov-prevbox');
      const wasOpen=box && box.dataset.open==='1';
      body.querySelectorAll('.gedov-prevbox').forEach(pb=>closePreview(pb));
      if(box && !wasOpen) openPreview(box);
    }
    body.querySelectorAll('.gedov-cand').forEach(el=>{
      el.addEventListener('click',(ev)=>{
        if(ev.target.closest('.gedov-prevbox')||ev.target.closest('.gedov-rowbtn')) return;
        selectRow(el); togglePreviewFor(el);
      });
    });
    // Bouton Aperçu de la ligne
    body.querySelectorAll('.gedov-rowbtn.prev').forEach(b=>{
      b.addEventListener('click',(ev)=>{ ev.stopPropagation();
        const el=b.closest('.gedov-cand'); selectRow(el); togglePreviewFor(el); });
    });
    // Bouton Valider DANS la ligne → classe directement ce document
    body.querySelectorAll('.gedov-rowbtn.valid').forEach(b=>{
      b.addEventListener('click',(ev)=>{ ev.stopPropagation();
        selectedItem=cands[+b.dataset.i]; gedovCommit(); });
    });
    // Pré-sélection du meilleur candidat (sans ouvrir l'aperçu : clic utilisateur requis)
    const bestIdx=cands.findIndex(c=>c.best);
    if(bestIdx>=0){
      const el=body.querySelector('.gedov-cand[data-i="'+bestIdx+'"]');
      if(el){ el.classList.add('sel'); selectedItem=cands[bestIdx];
        document.getElementById('gedovValider').disabled=false; }
    }
  }

  async function runSearch(typeCode,label){
    curType=typeCode; curLabel=label; selectedItem=null;
    document.getElementById('gedovTitle').textContent='🔎 '+label;
    document.getElementById('gedovSub').textContent='Recherche dans le OneDrive général…';
    document.getElementById('gedovBody').innerHTML='<div class="gedov-state">Recherche en cours…</div>';
    document.getElementById('gedovValider').style.display='';
    document.getElementById('gedovCtx').style.display='none';
    openModal();
    try{
      const fd=new FormData(); fd.append('bien_id',BIEN_ID); fd.append('type_code',typeCode); fd.append('csrf_token',CSRF);
      const r=await fetch(SEARCH_URL,{method:'POST',body:fd,headers:{'X-CSRF-Token':CSRF}});
      const j=await r.json();
      if(!j.ok){ document.getElementById('gedovBody').innerHTML='<div class="gedov-state">⚠ '+esc(j.error||'Erreur')+'</div>'; return; }
      document.getElementById('gedovSub').textContent=j.count+' document(s) proposé(s) — choisissez puis validez.';
      // Barre de contexte du bien (aide à pointer le bon lot)
      const bi=j.bien_info||{}; const chips=[];
      if(bi.reference) chips.push('<span class="chip">🏠 '+esc(bi.reference)+'</span>');
      const adr=[bi.adresse,bi.ville].filter(Boolean).join(' ');
      if(adr) chips.push('<span class="chip">📍 '+esc(adr)+'</span>');
      const lot=[bi.lot_principal,bi.lot_secondaire].filter(Boolean).join(' / ');
      if(lot) chips.push('<span class="chip lot">🔖 Lot '+esc(lot)+'</span>');
      else chips.push('<span class="chip lot" title="Aucun n° de lot saisi sur la fiche bien">🔖 Lot non renseigné</span>');
      (bi.locataires||[]).slice(0,3).forEach(l=>chips.push('<span class="chip loc">👤 '+esc(l)+'</span>'));
      const ctx=document.getElementById('gedovCtx');
      ctx.innerHTML=chips.join(''); ctx.style.display=chips.length?'flex':'none';
      renderCandidates(j.candidates||[]);
    }catch(e){ document.getElementById('gedovBody').innerHTML='<div class="gedov-state">⚠ '+esc(e.message)+'</div>'; }
  }

  window.gedovCommit = async function(){
    if(!selectedItem||busy) return;
    busy=true;
    const docName=(selectedItem&&selectedItem.name)?selectedItem.name:'document';
    const vb=document.getElementById('gedovValider'); vb.disabled=true; vb.textContent='Classement…';
    // Animation d'attente (le téléchargement + lecture IA du diagnostic prend du temps)
    document.getElementById('gedovSub').textContent='Traitement en cours, merci de patienter…';
    document.getElementById('gedovBody').innerHTML=
      '<div class="gedov-state"><div class="gedov-spin"></div>'+
      '<div style="font-weight:700;color:#0f6cbd;">Classement de «&nbsp;'+esc(docName)+'&nbsp;»</div>'+
      '<div class="gedov-dots" style="margin-top:6px;">Téléchargement, lecture du diagnostic et mise à jour de la fiche bien</div>'+
      '<div style="margin-top:8px;font-size:12px;color:#9ca3af;">Cela peut prendre 15 à 30 secondes</div></div>';
    try{
      const fd=new FormData();
      fd.append('bien_id',BIEN_ID); fd.append('type_code',curType);
      fd.append('item_id',selectedItem.item_id); fd.append('csrf_token',CSRF);
      const r=await fetch(COMMIT_URL,{method:'POST',body:fd,headers:{'X-CSRF-Token':CSRF}});
      const j=await r.json();
      if(!j.ok){
        document.getElementById('gedovBody').innerHTML='<div class="gedov-state">⚠ Échec : '+esc(j.error||'inconnu')+'</div>';
        return;
      }
      let extra='';
      if(j.diag_analyzed){
        const parts=[];
        if(j.dpe_classe) parts.push('DPE '+esc(j.dpe_classe));
        if(j.ges_classe) parts.push('GES '+esc(j.ges_classe));
        if(j.diag_date)  parts.push('du '+esc(j.diag_date));
        extra='<br><span style="color:#0b8043;font-weight:700;">🔍 Analysé : '+(parts.length?parts.join(' · '):'données extraites')+' → fiche bien complétée</span>';
      }
      document.getElementById('gedovBody').innerHTML='<div class="gedov-state">✅ Classé dans la GED :<br><b>'+esc(j.name_display||'')+'</b>'+(j.deduplicated?'<br><i>(document déjà présent — lien ajouté)</i>':'')+extra+'</div>';
      document.getElementById('gedovValider').style.display='none';
      setTimeout(()=>location.reload(), j.diag_analyzed ? 2400 : 1200);
    }catch(e){ alert('Erreur : '+e.message); }
    finally{ busy=false; vb.textContent='✓ Valider & classer'; }
  };

  document.addEventListener('click',function(ev){
    const btn=ev.target.closest('.f360-ged-search-btn');
    if(!btn) return;
    ev.preventDefault();
    runSearch(btn.dataset.typeCode, btn.dataset.typeLabel||'Document');
  });
  document.getElementById('gedovOverlay').addEventListener('click',gedovClose);
})();
</script>

<?php
$roleIdBien2 = function_exists('current_role_id') ? (int)current_role_id() : 0;
if (in_array($roleIdBien2, [1,2,7], true) || (function_exists('is_super_admin') && is_super_admin())): ?>
<style>
.bien-relink-imm{margin-top:8px;cursor:pointer;background:#eef4fb;color:#0f5a9e;border:1px solid #cfe4fb;border-radius:9px;padding:8px 12px;font-weight:700;font-size:13px;}
.bien-relink-imm:hover{background:#e0ecf7;}
.rli-overlay{position:fixed;inset:0;background:rgba(20,22,28,.55);z-index:9300;display:none;}
.rli-modal{position:fixed;z-index:9301;top:50%;left:50%;transform:translate(-50%,-50%);width:min(560px,94vw);max-height:84vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.35);padding:22px;display:none;}
.rli-modal h3{margin:0 0 10px;}
.rli-modal input[type=text]{width:100%;padding:10px 12px;border:1px solid #d6dbe1;border-radius:9px;font-size:14px;box-sizing:border-box;}
.rli-res{margin-top:10px;max-height:46vh;overflow:auto;}
.rli-item{padding:9px 11px;border:1px solid #e5e7eb;border-radius:9px;margin-bottom:6px;cursor:pointer;}
.rli-item:hover{border-color:#0f6cbd;background:#f5faff;}
.rli-item .nm{font-weight:700;font-size:14px;}
.rli-item .ad{color:#6b7280;font-size:12px;}
.rli-foot{display:flex;justify-content:flex-end;margin-top:12px;}
.rli-foot button{cursor:pointer;border:none;border-radius:9px;padding:9px 14px;font-weight:800;background:#eceef1;color:#374151;}
</style>
<div class="rli-overlay" id="rli-overlay" onclick="closeRelinkImm()"></div>
<div class="rli-modal" id="rli-modal">
  <h3>🏢 Rattacher le bien à un autre immeuble</h3>
  <input type="text" id="rli-q" placeholder="Rechercher un immeuble (nom, adresse, ville…)" autocomplete="off">
  <div class="rli-res" id="rli-res"><div style="color:#9ca3af;font-size:13px;padding:10px;">Tape au moins 2 caractères…</div></div>
  <div class="rli-foot"><button type="button" onclick="closeRelinkImm()">Fermer</button></div>
</div>
<script>
(function(){
  const BID=<?= (int)$bienId ?>;
  const CSRF=<?= json_encode(csrf_token('bien_immeuble')) ?>;
  const SEARCH=<?= json_encode(app_url('/api/ik_search_immeubles.php')) ?>;
  const SETURL=<?= json_encode(app_url('/api/bien_set_immeuble.php')) ?>;
  let tmr=null;
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  window.openRelinkImm=function(){document.getElementById('rli-overlay').style.display='block';document.getElementById('rli-modal').style.display='block';document.getElementById('rli-q').focus();};
  window.closeRelinkImm=function(){document.getElementById('rli-overlay').style.display='none';document.getElementById('rli-modal').style.display='none';};
  async function doSearch(q){
    const box=document.getElementById('rli-res');
    if(q.length<2){box.innerHTML='<div style="color:#9ca3af;font-size:13px;padding:10px;">Tape au moins 2 caractères…</div>';return;}
    box.innerHTML='<div style="color:#9ca3af;font-size:13px;padding:10px;">Recherche…</div>';
    try{
      const r=await fetch(SEARCH+'?q='+encodeURIComponent(q));
      const list=await r.json();
      if(!Array.isArray(list)||!list.length){box.innerHTML='<div style="color:#9ca3af;font-size:13px;padding:10px;">Aucun immeuble trouvé.</div>';return;}
      box.innerHTML=list.map(im=>'<div class="rli-item" data-id="'+im.id+'" data-label="'+esc((im.nom_immeuble||'')+' '+(im.adresse||''))+'">'+
        '<div class="nm">🏢 '+esc(im.nom_immeuble||im.adresse||('#'+im.id))+'</div>'+
        '<div class="ad">'+esc(im.adresse||'')+' '+esc(im.code_postal||'')+' '+esc(im.ville||'')+'</div></div>').join('');
      box.querySelectorAll('.rli-item').forEach(el=>el.addEventListener('click',()=>relink(+el.dataset.id, el.dataset.label)));
    }catch(e){box.innerHTML='<div style="color:#c62828;padding:10px;">⚠ '+esc(e.message)+'</div>';}
  }
  async function relink(idImm,label){
    if(!confirm('Rattacher ce bien à :\n'+label+' ?'))return;
    try{
      const fd=new FormData();fd.append('id_bien',BID);fd.append('id_immeuble',idImm);fd.append('csrf_token',CSRF);
      const r=await fetch(SETURL,{method:'POST',body:fd,headers:{'X-CSRF-Token':CSRF}});
      const j=await r.json();
      if(!j.ok){alert('Échec : '+(j.error||'?'));return;}
      alert('✅ Bien rattaché à : '+(j.immeuble_nom||label));
      location.reload();
    }catch(e){alert('Erreur : '+e.message);}
  }
  document.getElementById('rli-q').addEventListener('input',function(){clearTimeout(tmr);const q=this.value.trim();tmr=setTimeout(()=>doSearch(q),250);});
})();
</script>
<?php endif; ?>

<!-- ── Modal classement OneDrive → GED (scope BIEN : nouveaux + loupés) ── -->
<div id="odModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 style="margin:0;font-size:16px;">📥 Documents OneDrive — bien <?= h($bien['reference_bien'] ?: '#'.$bienId) ?></h3>
      <button type="button" onclick="document.getElementById('odModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <div id="odBody" style="flex:1;overflow:auto;padding:16px 18px;font-size:13px;"><div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div></div>
    <div style="padding:12px 18px;border-top:1px solid #eef0f2;display:flex;gap:10px;align-items:center;">
      <button type="button" id="odCommitBtn" onclick="odClasserCommit()" disabled
              style="background:#2d8a4e;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;opacity:.5;">✓ Valider et classer</button>
      <span id="odMsg" style="font-size:12.5px;font-weight:700;"></span>
    </div>
  </div>
</div>
<script>
(function(){
  var BID=<?= (int)$bienId ?>, CSRF=<?= json_encode(function_exists('csrf_token')?csrf_token('onedrive_classer'):'', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode(app_url('/api/onedrive_classer.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function post(action){var fd=new FormData();fd.append('csrf_token',CSRF);fd.append('id_bien',BID);fd.append('action',action);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  window.odOpenFolder=function(){
    var w=window.open('','_blank'); if(w)w.document.write('Ouverture du dossier OneDrive…');
    post('folder_url').then(function(j){
      if(j&&j.ok&&j.url){ if(w){w.location.href=j.url;}else{window.location.href=j.url;} }
      else { if(w)w.close(); alert('❌ '+((j&&j.error)||'Dossier OneDrive introuvable')); }
    }).catch(function(e){ if(w)w.close(); alert('❌ Réseau : '+e); });
  };
  window.odClasserOpen=function(){
    document.getElementById('odModal').style.display='flex';
    document.getElementById('odCommitBtn').disabled=true; document.getElementById('odCommitBtn').style.opacity=.5;
    document.getElementById('odMsg').textContent='';
    document.getElementById('odBody').innerHTML='<div style="color:#6b7280;padding:30px;text-align:center;">⏳ Analyse du dossier OneDrive…</div>';
    post('scan').then(function(j){
      if(!j||!j.ok){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ '+esc((j&&j.error)||'Erreur')+(j&&j.base?'<br><small>base: '+esc(j.base)+'</small>':'')+'</div>';return;}
      var rows=(j.items||[]).map(function(it){
        var col=it.status==='certain'?'#2d8a4e':(it.status==='pile'?'#8a6d1b':'#c62828');
        var cible=it.target==='BAIL'?('→ bail #'+it.bail_id):(it.target==='BIEN'?'→ ce bien':'→ pile');
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;margin-top:2px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">'+esc(cible)+'</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason)+'</td></tr>';
      }).join('');
      var nbCertain=(j.items||[]).filter(function(x){return x.status==='certain';}).length;
      document.getElementById('odBody').innerHTML=
        '<div style="margin-bottom:8px;color:#6b7280;">Dossier <b>'+esc(j.folder)+'</b> · baux du bien '+(j.nb_baux||0)+' · <b>'+nbCertain+'</b> doc(s) à classer sur ce bien (nouveaux + loupés).</div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(rows||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun document bail/EDL/DPE rattachable à ce bien.</td></tr>')+'</tbody></table>';
      var b=document.getElementById('odCommitBtn'); if(nbCertain>0){b.disabled=false;b.style.opacity=1;}
    }).catch(function(e){document.getElementById('odBody').innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>';});
  };
  window.odClasserCommit=function(){
    var b=document.getElementById('odCommitBtn'),m=document.getElementById('odMsg');
    b.disabled=true;b.style.opacity=.5;m.style.color='#6b7280';m.textContent='⏳ Classement en cours…';
    post('commit').then(function(j){
      if(!j||!j.ok){m.style.color='#c62828';m.textContent='❌ '+esc((j&&j.error)||'Erreur');return;}
      m.style.color='#2d8a4e';m.textContent='✓ '+j.classes+' document(s) classé(s) en GED'+(j.pile?(' · '+j.pile+' en pile'):'')+(j.erreurs&&j.erreurs.length?(' · '+j.erreurs.length+' erreur(s)'):'')+'. Recharge la page pour voir les docs.';
    }).catch(function(e){m.style.color='#c62828';m.textContent='❌ Réseau : '+esc(e);});
  };
})();
</script>

<?php require_once __DIR__ . '/inc/geo_views_modal.php'; ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
