<?php
// bien_detail.php  (ex bien_detail_v2.php — promue page principale le 2026-04-20)
// ─────────────────────────────────────────────────────────────────────────
// Page unifiée création / édition d'un bien immobilier, organisée en
// carousel de cards animées (style Apple), découpée en 4 sections via
// paramètre `?section=` :
//   documents  → Chargement / Diag / Mandats / Autres / Photos
//   dpe        → DPE / Extraits / À compléter / Analyse / Alertes
//   descriptif → Caractéristiques / Pièces / Chauffage / Environnement / Photos
//   annonce    → Conditions / Encadrement / Annonce+IA / Photos / Diffusion
//
// L'ancienne page (V1 "pro" de ~9326 lignes) reste accessible sous
// bien_detail_ex.php pour consultation de référence / rollback éventuel.
//
// Layout : sidebar + topbar (score Ubiflow % à droite) + page-head
// (onglets) + carousel plein-écran (80% × 80% desktop, responsive mobile).
// PAS DE SCROLL vertical.
// ─────────────────────────────────────────────────────────────────────────
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/bien_form_loader.php';
require_once __DIR__ . '/inc/ubiflow_validator.php';
require_login();

$appLayout = true;
$bodyClass = 'v2-no-scroll';
$robots    = 'noindex, nofollow';

$editingBienId = isset($_GET['edit']) && ctype_digit((string)$_GET['edit']) ? (int)$_GET['edit'] : 0;
// Compte en lecture seule : interdiction de créer un bien (pas de brouillon à la volée).
if ($editingBienId <= 0 && function_exists('is_readonly_user') && is_readonly_user()) {
    header('Location: ' . app_url('/transaction_portefeuilles_hub.php#patrimoine'));
    exit;
}
if ($editingBienId <= 0) {
    // Création d'un nouveau brouillon à la volée : l'user arrive sur
    // /bien_detail.php (sans ?edit) depuis bien_liste "➕ Nouveau bien"
    // ou depuis un lien legacy (bien_ajouter.php redirect).
    //
    // Anti-doublon (2026-05-26) : si l'user a déjà un brouillon VIERGE récent
    // (designation auto + statut='brouillon' + créé il y a < 10 min), on le
    // réutilise au lieu d'en créer un nouveau. Évite le spam de biens "Sans titre"
    // lors d'un browser back ou d'un refresh accidentel.
    try {
        $idSocieteSession = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
        $idAgenceSession  = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;
        $idUserSession    = function_exists('current_user_id') ? (int)current_user_id() : null;

        if ($idUserSession) {
            $stExist = $pdo->prepare("
                SELECT id FROM biens
                WHERE id_user_actuel = ?
                  AND statut_bien = 'brouillon'
                  AND (designation IS NULL OR designation LIKE 'Brouillon créé le %')
                  AND date_creation > (NOW() - INTERVAL 10 MINUTE)
                ORDER BY id DESC LIMIT 1
            ");
            $stExist->execute([$idUserSession]);
            $editingBienId = (int)$stExist->fetchColumn();
        }

        if ($editingBienId <= 0) {
            $editingBienId = bien_form_create_draft($pdo, $idSocieteSession ?: null, $idAgenceSession ?: null, null, $idUserSession ?: null);
        }
        // Création depuis le patrimoine bailleur : propriétaire pré-rattaché + ouverture
        // directe du modal immeuble (rattachement obligatoire → réf {reference_immeuble}-9001+).
        $createProprio  = isset($_GET['id_proprietaire']) && ctype_digit((string)$_GET['id_proprietaire']) ? (int)$_GET['id_proprietaire'] : 0;
        $createImmeuble = isset($_GET['id_immeuble']) && ctype_digit((string)$_GET['id_immeuble']) ? (int)$_GET['id_immeuble'] : 0;
        $extra = '&section=identite';
        if ($createProprio > 0 && $editingBienId > 0) {
            try {
                $okP = $pdo->prepare("SELECT 1 FROM proprietaires WHERE id = ? LIMIT 1");
                $okP->execute([$createProprio]);
                if ($okP->fetchColumn()) {
                    $pdo->prepare("UPDATE biens SET id_proprietaire = ? WHERE id = ?")->execute([$createProprio, $editingBienId]);
                }
            } catch (Throwable $e) { error_log('[bien_detail create proprio] ' . $e->getMessage()); }
            $extra = '&section=identite&open_immeuble=1';
        }
        // Immeuble connu (création depuis un bloc immeuble) : on rattache directement
        // l'immeuble + recopie l'adresse + génère la réf {reference_immeuble}-9001+.
        if ($createImmeuble > 0 && $editingBienId > 0) {
            try {
                $im = $pdo->prepare("SELECT id, adresse_1, adresse_2, code_postal, ville, latitude, longitude FROM immeubles WHERE id = ? LIMIT 1");
                $im->execute([$createImmeuble]);
                if ($imm = $im->fetch(PDO::FETCH_ASSOC)) {
                    require_once __DIR__ . '/inc/ref_generator.php';
                    $newRef = ref_generate_bien_immeuble($pdo, $createImmeuble);
                    $sets = "id_immeuble = :imm, adresse_1 = :a1, adresse_2 = :a2, code_postal = :cp, ville = :v, latitude = :lat, longitude = :lng";
                    $prm = [':imm'=>$createImmeuble, ':a1'=>$imm['adresse_1'], ':a2'=>$imm['adresse_2'], ':cp'=>$imm['code_postal'],
                            ':v'=>$imm['ville'], ':lat'=>$imm['latitude'], ':lng'=>$imm['longitude'], ':id'=>$editingBienId];
                    if ($newRef !== null) { $sets .= ", reference_bien = :ref"; $prm[':ref'] = $newRef; }
                    $pdo->prepare("UPDATE biens SET {$sets} WHERE id = :id")->execute($prm);
                    $extra = '&section=identite';   // immeuble déjà rattaché → pas besoin d'ouvrir le modal
                }
            } catch (Throwable $e) { error_log('[bien_detail create immeuble] ' . $e->getMessage()); }
        }
        header('Location: ' . app_url('/bien_detail.php?edit=' . $editingBienId . $extra));
        exit;
    } catch (Throwable $e) {
        error_log('[bien_detail create_draft] ' . $e->getMessage());
        header('Location: ' . app_url('/bien_liste.php?err=creation_bien'));
        exit;
    }
}

// Super-admin (role=1) bypass le filtre société pour pouvoir voir n'importe quel bien.
// Rôles propriétaires externes (9/10) : bypass du filtre société également — le scope
// est assuré par user_proprietaires (contrôle ci-dessous).
$_idRole = (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($_idRole === 1);
$isProprioExterne = in_array($_idRole, [9, 10], true);
$idSociete = ($isSuperAdmin || $isProprioExterne) ? null : (isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null);
$bienLoaded = bien_form_load_record($pdo, $editingBienId, $idSociete);
if ($bienLoaded === null) {
    header('Location: ' . app_url('/bien_liste.php?err=bien_introuvable'));
    exit;
}
// Contrôle d'accès supplémentaire pour les propriétaires : vérifier que
// le bien appartient bien à une des SCI rattachées à leur user.
if ($isProprioExterne) {
    $_idUserSess = (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
    $stAcc = $pdo->prepare("SELECT 1 FROM user_proprietaires
        WHERE id_user = :u AND id_proprietaire = :p LIMIT 1");
    $stAcc->bindValue(':u', $_idUserSess, PDO::PARAM_INT);
    $stAcc->bindValue(':p', (int)($bienLoaded['id_proprietaire'] ?? 0), PDO::PARAM_INT);
    $stAcc->execute();
    if (!$stAcc->fetchColumn()) {
        header('Location: ' . app_url('/bien_liste.php?err=acces_refuse'));
        exit;
    }
}

// ─── Auto-génération reference_bien si vide ────────────────
// Ubiflow exige une reference_bien ; si elle est vide (cas d'un bien créé
// par intake avant la mise en place de ref_generator, ou brouillon sans
// commercial), on la génère depuis le pattern de l'agence/société.
if (empty($bienLoaded['reference_bien'])) {
    try {
        require_once __DIR__ . '/inc/ref_generator.php';
        $refCtx = [
            'id_agence'       => (int)($bienLoaded['id_agence'] ?? $_SESSION['id_agence'] ?? 0),
            'type_bien_code'  => (string)($bienLoaded['_type_code'] ?? ''),
            'ville'           => (string)($bienLoaded['_imm_ville'] ?? $bienLoaded['ville'] ?? ''),
            'user'            => [
                'nom'    => (string)($_SESSION['nom']    ?? ''),
                'prenom' => (string)($_SESSION['prenom'] ?? ''),
            ],
        ];
        if ($refCtx['id_agence'] > 0) {
            $newRef = ref_generate_bien($pdo, $refCtx);
            if ($newRef !== '') {
                $pdo->prepare("UPDATE biens SET reference_bien = ?, date_modification = NOW() WHERE id = ? AND (reference_bien IS NULL OR reference_bien = '')")
                    ->execute([$newRef, $editingBienId]);
                $bienLoaded['reference_bien'] = $newRef;
            }
        }
    } catch (Throwable $e) {
        error_log('[bien_detail_v2 ref_generate_bien] ' . $e->getMessage());
    }
}

// Section courante
$sectionsAvail = ['documents', 'dpe', 'descriptif', 'validation', 'annonce'];
// Défaut = 'descriptif' (ouverture d'un bien existant depuis bien_liste).
// Pour un NOUVEAU brouillon, le redirect ci-dessus force explicitement
// 'section=documents' pour atterrir sur la Card Chargement (DPE, mandat…).
$section = $_GET['section'] ?? 'descriptif';
if (!in_array($section, $sectionsAvail, true)) $section = 'descriptif';

// ── Flow 2026-04-22 : validation bien obligatoire avant annonce ──
// Statut du bien pour gérer les gates UI (bloquer annonce si !actif)
$statutBien = (string)($bienLoaded['statut_bien'] ?? 'brouillon');
$bienEstActif = ($statutBien === 'actif');
// Pré-charge la checklist pour la section Validation (et pour afficher le nb manquant sur le tab)
require_once __DIR__ . '/inc/bien_validator.php';
$validationResult = bien_validator_check($pdo, $editingBienId);
$nbManquants = count($validationResult['missing_required']);

// Complétude Ubiflow (score pill topbar)
$annonceIdLoaded = (int)($bienLoaded['_annonce_id'] ?? 0);
$annonceForCheck = [];
if ($annonceIdLoaded > 0) {
    $stmtAnn = $pdo->prepare("SELECT * FROM annonces WHERE id = ? LIMIT 1");
    $stmtAnn->execute([$annonceIdLoaded]);
    $annonceForCheck = $stmtAnn->fetch(PDO::FETCH_ASSOC) ?: [];
}
$photosCount = function_exists('ubiflow_count_photos_bien')
    ? ubiflow_count_photos_bien($pdo, $editingBienId) : 0;
$ubiCheck = ubiflow_check_completude($bienLoaded, $annonceForCheck, $photosCount, $pdo, $idSociete);
$scorePct = (int)($ubiCheck['score'] ?? 0);
$scoreStatus = (string)($ubiCheck['status'] ?? UBIFLOW_CHECK_INCOMPLET);
$scoreClass = match ($scoreStatus) {
    UBIFLOW_CHECK_OK         => 'ok',
    UBIFLOW_CHECK_WARNING    => 'warn',
    default                   => 'bad',
};

$pageTitle = 'Détail du bien — #' . $editingBienId;

// ─── Section DOCUMENTS ────────────────────────────────────────
// Types reels en base (cf. api/bien_intake_upload.php docTypeMap) :
// 'dpe', 'diag' = diagnostics · 'mandat', 'bail' = mandats · 'acte', 'titre', 'fiche', 'divers', 'autre' = autres
$diagTypes   = ['dpe', 'diag', 'dossier_complet', 'dossier_diagnostics',
                'certificat_surface', 'mesurage_loi_carrez',
                'erp', 'amiante', 'plomb', 'termites', 'gaz', 'electricite'];
$mandatTypes = ['mandat', 'mandat_vente', 'mandat_gestion', 'mandat_location', 'bail'];

// Photos du bien (pour la Card 5 Documents)
$docsPhotos = [];
if ($section === 'documents') {
    try {
        // SELECT tolérant : on tente d'inclure les colonnes critique (migration 2026-05-02)
        // et on retombe sur l'ancien schéma si la migration n'a pas encore été appliquée.
        try {
            $st = $pdo->prepare("SELECT id, url_photo, nom_original, largeur, hauteur, categorie, description_ia,
                        critique_niveau, critique_points_forts, critique_points_faibles, critique_conseil, analyse_statut
                FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC LIMIT 100");
            $st->execute([$editingBienId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $st = $pdo->prepare("SELECT id, url_photo, nom_original, largeur, hauteur, categorie, description_ia
                FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC LIMIT 100");
            $st->execute([$editingBienId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as $p) {
            $pf = !empty($p['critique_points_forts'])   ? (json_decode((string)$p['critique_points_forts'],   true) ?: []) : [];
            $pw = !empty($p['critique_points_faibles']) ? (json_decode((string)$p['critique_points_faibles'], true) ?: []) : [];
            $docsPhotos[] = [
                'id'                       => (int)$p['id'],
                'url'                      => $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '',
                'nom_original'             => (string)($p['nom_original'] ?? ''),
                'largeur'                  => (int)($p['largeur'] ?? 0),
                'hauteur'                  => (int)($p['hauteur'] ?? 0),
                'categorie'                => (string)($p['categorie'] ?? ''),
                'description_ia'           => (string)($p['description_ia'] ?? ''),
                'critique_niveau'          => (string)($p['critique_niveau'] ?? ''),
                'critique_points_forts'    => is_array($pf) ? $pf : [],
                'critique_points_faibles'  => is_array($pw) ? $pw : [],
                'critique_conseil'         => (string)($p['critique_conseil'] ?? ''),
                'analyse_statut'           => (string)($p['analyse_statut'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
}

$docsDiag = [];
$docsMandat = [];
$docsAutre = [];
$lastDpePdf = null;

// ─── GED CENTRALE UNIQUE (2026-05-25) ──
// Source de vérité unique = ged_documents + ged_document_links (pivot polymorphe).
// Tout doc rattaché au BIEN apparaît ici, quelle que soit la page qui l'a uploadé.
require_once __DIR__ . '/inc/ged_document_links.php';
try {
    $docsGed = gdl_documents_for_entity($pdo, 'BIEN', $editingBienId, [
        'status'   => 'active',
        'limit'    => 200,
        'order_by' => 'd.created_at DESC',
    ]);
    // Mapping type_document GED canonique → conventions internes bien_detail
    $typeMapGed = [
        'DIAG_DPE' => 'dpe', 'DIAG_AMIANTE' => 'amiante', 'DIAG_PLOMB' => 'plomb',
        'DIAG_GAZ' => 'gaz', 'DIAG_ELEC' => 'electricite', 'DIAG_TERMITES' => 'termites',
        'DIAG_ERP' => 'erp', 'SURFACE_CARREZ' => 'mesurage_loi_carrez',
        'MANDAT_VENTE' => 'mandat', 'MANDAT_LOCATION' => 'mandat',
        'MANDAT_RECHERCHE' => 'mandat', 'MANDAT_GESTION' => 'mandat',
        'BAIL' => 'bail', 'COMPROMIS' => 'compromis', 'PROMESSE_VENTE' => 'promesse_vente',
        'ACTE_AUTHENTIQUE' => 'acte', 'OFFRE_ACHAT' => 'offre_achat',
        'TAXE_FONCIERE' => 'taxe_fonciere', 'PLAN' => 'plan', 'PHOTO' => 'photo', 'AUTRE' => 'autre',
    ];
    foreach ($docsGed as $d) {
        $rawType = (string)($d['document_type'] ?? 'AUTRE');
        $t = $typeMapGed[$rawType] ?? mb_strtolower($rawType);

        // URL : metadata.public_url ou fallback servable via api/ged_doc_serve.php
        $meta = json_decode((string)($d['metadata'] ?? '{}'), true) ?: [];
        $url  = (string)($meta['public_url'] ?? '');
        if ($url !== '') $url = app_url('/' . ltrim($url, '/'));

        $row = [
            'id'             => 'ged_' . (int)$d['id'],
            'type_document'  => $t,
            'nom_original'   => (string)($d['name_display'] ?? $d['name_file']),
            'url_fichier'    => $url,
            'taille_octets'  => (int)($d['size_bytes'] ?? 0),
            'date_document'  => $meta['extra']['classement']['date_doc'] ?? null,
            'date_upload'    => $d['created_at'] ?? null,
            'source'         => 'ged_documents',
        ];
        if (in_array($t, $diagTypes, true)) {
            $docsDiag[] = $row;
            if ($lastDpePdf === null && $row['url_fichier']
                && in_array($t, ['dpe','diag','dossier_complet','dossier_diagnostics'], true)) {
                $lastDpePdf = $row;
            }
        } elseif (in_array($t, $mandatTypes, true)) {
            $docsMandat[] = $row;
        } else {
            $docsAutre[] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('[bien_detail_v2] gdl_documents_for_entity failed: ' . $e->getMessage());
}

// ─── Section ANNONCE : charger l'annonce existante du bien + photos ──
$annonce = null;
$annoncePhotoIds = [];
$annonceBienPhotos = [];
$cplLignes = [];
if ($section === 'annonce') {
    try {
        $st = $pdo->prepare("SELECT * FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$editingBienId]);
        $annonce = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($annonce) {
            // Photos sélectionnées de l'annonce, DANS LEUR ORDRE
            $st2 = $pdo->prepare("SELECT id_biens_photo FROM annonces_photos WHERE id_annonce = ? ORDER BY ordre ASC, id ASC");
            $st2->execute([(int)$annonce['id']]);
            $annoncePhotoIds = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN) ?: []);

            // Lignes complément de loyer
            $stCpl = $pdo->prepare("SELECT id, libelle, montant, ordre FROM annonces_complement_loyer_lignes WHERE id_annonce = ? ORDER BY ordre ASC, id ASC");
            $stCpl->execute([(int)$annonce['id']]);
            $cplLignes = $stCpl->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // ── Historique complet : toutes les annonces du bien (y compris active) ──
        // Utilisé par la Card 6 "Historique" pour retracer vente 2024 / location 2026 etc.
        $annoncesHistorique = [];
        $stH = $pdo->prepare("
            SELECT a.id, a.type_transaction, a.etat_publication,
                   a.date_creation, a.date_mise_en_ligne AS date_publication, a.date_modification,
                   a.prix, a.loyer, a.loyer_cc, a.titre,
                   (SELECT COUNT(*) FROM annonces_photos ap WHERE ap.id_annonce = a.id) AS nb_photos
            FROM annonces a
            WHERE a.id_bien = ?
            ORDER BY a.id DESC
        ");
        $stH->execute([$editingBienId]);
        $annoncesHistorique = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Toutes les photos du bien (indexées par id, ordre par défaut = ordre biens_photos)
        $st3 = $pdo->prepare("SELECT id, url_photo, nom_original FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
        $st3->execute([$editingBienId]);
        $photosById = [];
        $photosOrdreBien = [];
        foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $id = (int)$p['id'];
            $photosById[$id] = [
                'id'  => $id,
                'url' => $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '',
                'nom' => (string)($p['nom_original'] ?? ''),
            ];
            $photosOrdreBien[] = $id;
        }
        // Ordre final : sélectionnées (dans l'ordre annonce) puis disponibles (dans l'ordre bien)
        $selSet = array_flip($annoncePhotoIds);
        foreach ($annoncePhotoIds as $pid) {
            if (isset($photosById[$pid])) $annonceBienPhotos[] = $photosById[$pid];
        }
        foreach ($photosOrdreBien as $pid) {
            if (!isset($selSet[$pid]) && isset($photosById[$pid])) {
                $annonceBienPhotos[] = $photosById[$pid];
            }
        }
        // Garde-fou inconditionnel : si pour une raison quelconque les loops
        // ci-dessus n'ont rien produit (annonces_photos orphelins, IDs ne
        // matchant pas, etc.), on prend toutes les photos du bien — l'user
        // pourra ensuite resélectionner via la Card Photos.
        if (empty($annonceBienPhotos) && !empty($photosOrdreBien)) {
            foreach ($photosOrdreBien as $pid) {
                if (isset($photosById[$pid])) {
                    $annonceBienPhotos[] = $photosById[$pid];
                }
            }
        }
    } catch (Throwable $e) { error_log('[bien_detail_v2 annonce] ' . $e->getMessage()); }
}

// ─── Section DPE : charger dpe_diags + whitelist champs ────────
$dpeDiag = null;
if ($section === 'dpe') {
    try {
        $st = $pdo->prepare("SELECT * FROM dpe_diags WHERE id_bien = ? ORDER BY date_creation DESC, id DESC LIMIT 1");
        $st->execute([$editingBienId]);
        $dpeDiag = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[bien_detail_v2] dpe_diags: ' . $e->getMessage());
    }
}

// ─── Section DESCRIPTIF : charger photos + types + proprietaire + sync dpe_diags→biens ───
$descPhotos = [];
$typeBienLabel = '';
$proprioInfo = null;
$typesBienList = [];
$descDpeDiag = null;
$descSyncedFields = [];
$descProprioFromDpe = null; // Proprio extrait du DPE (non encore associé)
if ($section === 'descriptif') {
    // 1. Charger le dernier dpe_diags
    try {
        $st = $pdo->prepare("SELECT * FROM dpe_diags WHERE id_bien = ? ORDER BY date_creation DESC, id DESC LIMIT 1");
        $st->execute([$editingBienId]);
        $descDpeDiag = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    // 1.bis. Extraire le proprio depuis champs_extraits_json du DPE
    if ($descDpeDiag && !empty($descDpeDiag['champs_extraits_json'])) {
        $extracted = json_decode((string)$descDpeDiag['champs_extraits_json'], true);
        if (is_array($extracted)) {
            $pNom = trim((string)($extracted['proprio_nom'] ?? ''));
            $pPrenom = trim((string)($extracted['proprio_prenom'] ?? ''));
            $pSoc = trim((string)($extracted['proprio_societe'] ?? ''));
            if ($pNom !== '' || $pSoc !== '') {
                $descProprioFromDpe = [
                    'nom'       => $pNom,
                    'prenom'    => $pPrenom,
                    'societe'   => $pSoc,
                    'email'     => trim((string)($extracted['proprio_email']     ?? '')),
                    'telephone' => trim((string)($extracted['proprio_telephone'] ?? '')),
                    'adresse'   => trim((string)($extracted['proprio_adresse_1'] ?? $extracted['proprio_adresse'] ?? '')),
                    'cp'        => trim((string)($extracted['proprio_code_postal']?? '')),
                    'ville'     => trim((string)($extracted['proprio_ville']     ?? '')),
                    'civilite'  => trim((string)($extracted['proprio_civilite']  ?? '')),
                    'type'      => trim((string)($extracted['proprio_type_personne'] ?? '')) === 'morale' ? 'personne_morale' : 'personne_physique',
                ];
                $descProprioFromDpe['display'] = $pSoc !== '' ? $pSoc : trim(
                    ($descProprioFromDpe['civilite'] ? $descProprioFromDpe['civilite'] . ' ' : '')
                    . $pPrenom . ' ' . $pNom
                );
            }
        }
    }

    // 2. Sync AUTOMATIQUE dpe_diags → biens (COALESCE : ne touche pas les valeurs saisies)
    if ($descDpeDiag) {
        $syncMap = [
            // dpe_diags_col          => biens_col
            'adresse_detectee'            => 'adresse_1',
            'code_postal_detecte'         => 'code_postal',
            'ville_detectee'              => 'ville',
            'etage_detecte'               => 'etage',
            'annee_construction_detectee' => 'annee_construction',
            'surface_habitable_detectee'  => 'surface_habitable',
            'surface_carrez_detectee'     => 'surface_carrez',
            'surface_sejour_detectee'     => 'surface_sejour',
            'nb_pieces_detecte'           => 'nb_pieces',
            'nb_chambres_detecte'         => 'nb_chambres',
            'nb_salles_bain_detecte'      => 'nb_salles_bain',
            'nb_salles_eau_detecte'       => 'nb_salles_eau',
            'nb_wc_detecte'               => 'nb_wc',
            'chauffage_type_detecte'      => 'chauffage_type',
            'chauffage_energie_detecte'   => 'chauffage_energie',
            'eau_chaude_type_detecte'     => 'eau_chaude_type',
            'menuiseries_detectees'       => 'menuiseries',
            'altitude_detectee'           => 'altitude',
            'dpe_classe'                  => 'dpe_classe',
            'ges_classe'                  => 'ges_classe',
            'consommation_energie'        => 'dpe_valeur',
            'emission_ges'                => 'ges_valeur',
            'conso_energie_primaire'      => 'dpe_valeur_conso_primaire',
            'conso_energie_finale'        => 'dpe_valeur_conso_finale',
            'montant_depenses_min'        => 'montant_estime_depenses_min',
            'montant_depenses_max'        => 'montant_estime_depenses_max',
            'date_diagnostic'             => 'dpe_date_realisation',
            'numero_ademe'                => 'dpe_reference_certificat',
            'dpe_version'                 => 'dpe_version',
            'date_indice_prix'            => 'date_indice_prix_energies',
        ];
        $boolMap = [
            'double_vitrage_detecte' => 'double_vitrage',
            'volets_roulants_detecte'=> 'volets_roulants',
            'dpe_vierge'             => 'dpe_vierge',
            'alerte_zone_georisque'  => 'zone_georisque',
        ];

        $setParts = [];
        $setParams = [':_id' => $editingBienId];
        foreach ($syncMap as $src => $dst) {
            $v = $descDpeDiag[$src] ?? null;
            if ($v === null || $v === '') continue;
            $bienVal = $bienLoaded[$dst] ?? null;
            if ($bienVal !== null && $bienVal !== '') continue; // deja rempli → skip
            $setParts[] = "`$dst` = COALESCE(NULLIF(`$dst`, ''), :v_$dst)";
            $setParams[":v_$dst"] = $v;
            $descSyncedFields[] = $dst;
        }
        foreach ($boolMap as $src => $dst) {
            $v = $descDpeDiag[$src] ?? null;
            if ($v === null || $v === '') continue;
            $bienVal = (int)($bienLoaded[$dst] ?? 0);
            if ($bienVal === 1) continue;
            $setParts[] = "`$dst` = CASE WHEN `$dst` = 0 OR `$dst` IS NULL THEN :v_$dst ELSE `$dst` END";
            $setParams[":v_$dst"] = (int)(!empty($v));
            $descSyncedFields[] = $dst;
        }
        if (!empty($setParts)) {
            try {
                $pdo->prepare("UPDATE biens SET " . implode(', ', $setParts) . " WHERE id = :_id")->execute($setParams);
                // Recharger $bienLoaded avec les nouvelles valeurs pour l'affichage
                $bienLoaded = bien_form_load_record($pdo, $editingBienId, $idSociete) ?: $bienLoaded;
            } catch (Throwable $e) {
                error_log('[bien_detail_v2 sync] ' . $e->getMessage());
            }
        }
    }
    // Migration 20260430_bien_types : dropdown des types alimenté depuis
    // la nouvelle table `bien_types` (référentiel unifié LBC/SeLoger/FNAIM).
    // On conserve les clés (id, code, label) attendues par le template Twig,
    // donc bien_types.libelle est aliasé en `label`.
    try {
        $st = $pdo->query("SELECT id, code, libelle AS label FROM bien_types WHERE actif = 1 ORDER BY ordre_affichage ASC, libelle ASC");
        $typesBienList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Renommer 'fonds_commerce' en 'Commerce' (présentation UI)
        foreach ($typesBienList as &$_t) {
            if (($_t['code'] ?? '') === 'fonds_commerce') $_t['label'] = 'Commerce';
        }
        unset($_t);
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT id, url_photo, nom_original FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC LIMIT 60");
        $st->execute([$editingBienId]);
        $descPhotos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    // Label du type pour affichage : priorité bien_types via id_bien_type,
    // fallback sur base_types_bien via id_type_bien legacy.
    if (!empty($bienLoaded['id_bien_type'])) {
        try {
            $st = $pdo->prepare("SELECT libelle FROM bien_types WHERE id = ? LIMIT 1");
            $st->execute([(int)$bienLoaded['id_bien_type']]);
            $typeBienLabel = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    if (empty($typeBienLabel) && !empty($bienLoaded['id_type_bien'])) {
        try {
            $st = $pdo->prepare("SELECT label FROM base_types_bien WHERE id = ? LIMIT 1");
            $st->execute([(int)$bienLoaded['id_type_bien']]);
            $typeBienLabel = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    if (!empty($bienLoaded['id_proprietaire'])) {
        // biens.id_proprietaire pointe sur proprietaires.id (legacy). On
        // joint vers tiers via proprietaires.id_tiers pour récupérer les
        // infos enrichies (type, civilité, email, tél, adresse).
        try {
            $st = $pdo->prepare("
                SELECT p.id              AS id_proprio_legacy,
                       p.id_tiers        AS id_tiers,
                       COALESCE(t.type_tiers, 'personne_physique') AS type_tiers,
                       COALESCE(NULLIF(t.civilite, ''),       p.civilite)    AS civilite,
                       COALESCE(NULLIF(t.nom, ''),            p.nom)         AS nom,
                       COALESCE(NULLIF(t.prenom, ''),         p.prenom)      AS prenom,
                       COALESCE(NULLIF(t.raison_sociale, ''), p.societe)     AS raison_sociale,
                       COALESCE(NULLIF(t.email, ''),          p.email)       AS email,
                       COALESCE(NULLIF(t.telephone, ''),      p.telephone)   AS telephone,
                       COALESCE(NULLIF(t.adresse_ligne1, ''), p.adresse_1)   AS adresse,
                       COALESCE(NULLIF(t.code_postal, ''),    p.code_postal) AS code_postal,
                       COALESCE(NULLIF(t.ville, ''),          p.ville)       AS ville
                FROM proprietaires p
                LEFT JOIN tiers t ON t.id = p.id_tiers
                WHERE p.id = ?
                LIMIT 1
            ");
            $st->execute([(int)$bienLoaded['id_proprietaire']]);
            $proprioInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('[proprio load] ' . $e->getMessage());
        }

        // Patrimoine du proprio : nb de biens + répartition par agence
        if ($proprioInfo && !empty($proprioInfo['id_proprio_legacy'])) {
            try {
                $st = $pdo->prepare("
                    SELECT ag.id, ag.nom_agence AS nom, COUNT(*) AS nb_biens
                    FROM biens b
                    LEFT JOIN agences ag ON ag.id = b.id_agence
                    WHERE b.id_proprietaire = ?
                    GROUP BY ag.id, ag.nom_agence
                    ORDER BY nb_biens DESC
                ");
                $st->execute([(int)$proprioInfo['id_proprio_legacy']]);
                $proprioInfo['_patrimoine_agences'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $proprioInfo['_patrimoine_total']   = (int)array_sum(array_column($proprioInfo['_patrimoine_agences'], 'nb_biens'));
            } catch (Throwable $e) {
                $proprioInfo['_patrimoine_agences'] = [];
                $proprioInfo['_patrimoine_total']   = 0;
            }
        }
    }
    // Liste alphabetique des immeubles
    // - Super admin (role_id = 1) : voit TOUS les immeubles
    // - Autres : filtre par id_societe / id_agence (multi-tenant)
    try {
        $roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
        if ($roleId === 1) {
            $st = $pdo->query("
                SELECT id, reference_immeuble, adresse_1, code_postal, ville
                FROM immeubles
                ORDER BY adresse_1 ASC, ville ASC
                LIMIT 500
            ");
            $immeublesList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $st = $pdo->prepare("
                SELECT id, reference_immeuble, adresse_1, code_postal, ville
                FROM immeubles
                WHERE (id_societe = :s OR :s IS NULL)
                  AND (id_agence  = :a OR :a IS NULL)
                ORDER BY adresse_1 ASC, ville ASC
                LIMIT 500
            ");
            $st->execute([
                ':s' => $idSociete,
                ':a' => isset($_SESSION['id_agence']) ? (int)$_SESSION['id_agence'] : null,
            ]);
            $immeublesList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) { $immeublesList = []; }
}
$immeublesList = $immeublesList ?? [];

// Labels FR + type de champ pour la card 4 (whitelist alignée sur api/dpe_diag_update.php)
$dpeFieldDefs = [
    'date_diagnostic'            => ['label' => 'Date du diagnostic',        'type' => 'date'],
    'date_validite'              => ['label' => 'Date de validité',          'type' => 'date'],
    'dpe_version'                => ['label' => 'Version DPE',               'type' => 'dpe_version'],
    'dpe_vierge'                 => ['label' => 'DPE vierge',                'type' => 'bool'],
    'dpe_classe'                 => ['label' => 'Classe énergétique (DPE)',  'type' => 'class'],
    'ges_classe'                 => ['label' => 'Classe GES',                'type' => 'class'],
    'consommation_energie'       => ['label' => 'Consommation énergie (kWh/m²/an)', 'type' => 'number'],
    'conso_energie_primaire'     => ['label' => 'Conso énergie primaire',    'type' => 'number'],
    'conso_energie_finale'       => ['label' => 'Conso énergie finale',      'type' => 'number'],
    'emission_ges'               => ['label' => 'Émission GES (kgCO₂/m²/an)','type' => 'number'],
    'montant_depenses_min'       => ['label' => 'Dépenses annuelles min (€)','type' => 'number'],
    'montant_depenses_max'       => ['label' => 'Dépenses annuelles max (€)','type' => 'number'],
    'date_indice_prix'           => ['label' => 'Date indice prix énergie',  'type' => 'date'],
    'diagnostiqueur_nom'         => ['label' => 'Nom du diagnostiqueur',     'type' => 'text'],
    'diagnostiqueur_societe'     => ['label' => 'Société diagnostiqueur',    'type' => 'text'],
    'numero_rapport'             => ['label' => 'Numéro de rapport',         'type' => 'text'],
    'numero_ademe'               => ['label' => 'Numéro ADEME',              'type' => 'text'],
    'type_bien_detecte'          => ['label' => 'Type de bien',              'type' => 'text'],
    'adresse_detectee'           => ['label' => 'Adresse',                   'type' => 'text'],
    'code_postal_detecte'        => ['label' => 'Code postal',               'type' => 'text'],
    'ville_detectee'             => ['label' => 'Ville',                     'type' => 'text'],
    'etage_detecte'              => ['label' => 'Étage',                     'type' => 'text'],
    'lot_detecte'                => ['label' => 'Lot',                       'type' => 'text'],
    'annee_construction_detectee'=> ['label' => 'Année de construction',     'type' => 'number'],
    'surface_habitable_detectee' => ['label' => 'Surface habitable (m²)',    'type' => 'number'],
    'surface_carrez_detectee'    => ['label' => 'Surface Carrez (m²)',       'type' => 'number'],
    'surface_sejour_detectee'    => ['label' => 'Surface séjour (m²)',       'type' => 'number'],
    'nb_pieces_detecte'          => ['label' => 'Nombre de pièces',          'type' => 'number'],
    'nb_chambres_detecte'        => ['label' => 'Nombre de chambres',        'type' => 'number'],
    'nb_salles_bain_detecte'     => ['label' => 'Nombre de salles de bain',  'type' => 'number'],
    'nb_salles_eau_detecte'      => ['label' => "Nombre de salles d'eau",    'type' => 'number'],
    'nb_wc_detecte'              => ['label' => 'Nombre de WC',              'type' => 'number'],
    'chauffage_type_detecte'     => ['label' => 'Type de chauffage',         'type' => 'text'],
    'chauffage_energie_detecte'  => ['label' => 'Énergie de chauffage',      'type' => 'text'],
    'eau_chaude_type_detecte'    => ['label' => 'Type eau chaude',           'type' => 'text'],
    'double_vitrage_detecte'     => ['label' => 'Double vitrage',            'type' => 'bool'],
    'volets_roulants_detecte'    => ['label' => 'Volets roulants',           'type' => 'bool'],
    'menuiseries_detectees'      => ['label' => 'Menuiseries',               'type' => 'text'],
    'altitude_detectee'          => ['label' => 'Altitude (m)',              'type' => 'number'],
    'alerte_plomb_present'       => ['label' => 'Plomb présent',             'type' => 'bool'],
    'alerte_plomb_classe_max'    => ['label' => 'Classe plomb max',          'type' => 'text'],
    'alerte_amiante_present'     => ['label' => 'Amiante présent',           'type' => 'bool'],
    'alerte_electricite_anomalies' => ['label' => 'Anomalies électricité',   'type' => 'text'],
    'alerte_gaz_anomalies'       => ['label' => 'Anomalies gaz',             'type' => 'text'],
    'alerte_termites'            => ['label' => 'Termites',                  'type' => 'bool'],
    'alerte_zone_georisque'      => ['label' => 'Zone géorisque',            'type' => 'bool'],
    'alerte_inondation'          => ['label' => 'Zone inondation',           'type' => 'bool'],
    'sismicite_zone'             => ['label' => 'Zone de sismicité',         'type' => 'text'],
    'resume_bailleur'            => ['label' => 'Résumé bailleur',           'type' => 'textarea'],
    'commentaire'                => ['label' => 'Commentaire',               'type' => 'textarea'],
];

// Champs NON renseignés dans dpe_diags (card 4)
$dpeMissingFields = [];
foreach ($dpeFieldDefs as $col => $def) {
    $current = $dpeDiag[$col] ?? null;
    $isEmpty = ($current === null || $current === '' || (is_string($current) && trim($current) === ''));
    if ($isEmpty) $dpeMissingFields[$col] = $def;
}

$username = $_SESSION['username'] ?? '?';
$csrfTokenVal = csrf_token('ajouter_bien');

// Helper pour cases DPE classe (A-G)
$dpeColors = ['A'=>'#319834','B'=>'#33a357','C'=>'#51b755','D'=>'#f2e500','E'=>'#f0b200','F'=>'#eb8235','G'=>'#d7221f'];
$gesColors = ['A'=>'#f2e6ff','B'=>'#d9b3ff','C'=>'#bf80ff','D'=>'#a64dff','E'=>'#8c1aff','F'=>'#7300e6','G'=>'#5900b3'];
?>
<!doctype html>
<html lang="fr" class="v2-no-scroll">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="robots" content="<?= h($robots) ?>"/>
  <title><?= h($pageTitle) ?> — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/assets/css/document_uploader.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/bien_detail_v2.css') ?>?v=<?= @filemtime(__DIR__ . '/css/bien_detail_v2.css') ?: time() ?>">
  <style>
    :root {
      --bg:        var(--bg-secondary);
      --card:      var(--bg-primary);
      --ink:       #1a1816;
      --muted:     #8a8680;
      --accent:    #36577d;
      --stroke:    #d4d0ca;
      --sidebar-w: 220px;
      --topbar-h:  56px; /* aligné sur les autres pages */
      --neu-out:   6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      --neu-in:    inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Sora', system-ui, sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; }
    a { color: inherit; text-decoration: none; }

    /* Topbar */
    .mbi-topbar {
      position: sticky; top: 0; height: var(--topbar-h);
      background: var(--card);
      box-shadow: 0 2px 8px var(--shadow-dark);
      border-bottom: 1px solid var(--stroke);
      display: flex; align-items: center; gap: 10px;
      padding: 0 24px; z-index: 50; flex-shrink: 0;
    }
    .topbar-nav-btn, .topbar-icon-btn {
      width: 32px; height: 32px; border-radius: 8px;
      background: var(--bg); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
      color: var(--muted);
      transition: box-shadow .18s; flex-shrink: 0;
    }
    .topbar-nav-btn:hover, .topbar-icon-btn:hover {
      box-shadow: inset 1px 1px 3px var(--shadow-dark), inset -1px -1px 3px var(--shadow-light);
      color: var(--ink);
    }
    .topbar-gap { width: 50px; flex-shrink: 0; }
    .topbar-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; color: var(--muted); }
    .topbar-breadcrumb a { color: var(--muted); transition: color .15s; }
    .topbar-breadcrumb a:hover { color: var(--ink); }
    .topbar-breadcrumb .sep { color: var(--stroke); }
    .topbar-breadcrumb .active { color: var(--accent); font-weight: 600; }
    .topbar-spacer { flex: 1; }
    .topbar-avatar {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--accent); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 700;
      box-shadow: var(--neu-out); flex-shrink: 0;
    }

    /* Page head — onglets principaux sur fond vert amande clair */
    .page-head {
      padding: 4px 24px;
      display: flex; align-items: center; justify-content: center;
      gap: 18px; flex-shrink: 0;
      background: linear-gradient(180deg, #e3edd0 0%, #d4e2b8 100%);
      border-bottom: 1px solid rgba(117, 158, 120, 0.25);
    }
    .page-head-info { flex: 1; min-width: 0; }
    .page-head-label {
      font-size: 11px; font-weight: 600; letter-spacing: 1px;
      color: var(--muted); text-transform: uppercase;
    }
    .page-head-title { font-size: 18px; font-weight: 700; color: var(--ink); margin-top: 2px; }
    .page-head-ref {
      font-family: monospace; font-size: 11px; color: #64748b;
      background: rgba(255,255,255,.6); padding: 3px 9px;
      border-radius: 6px; border: 1px solid var(--stroke);
    }

    /* Google Places autocomplete dropdown (mêmes styles que agency_immeuble_form.php) */
    .places-dropdown {
      position: absolute; z-index: 2000;
      background: #fff; border: 1px solid rgba(196,192,186,0.5); border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12); max-height: 300px; overflow-y: auto;
    }
    .places-item { padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid rgba(196,192,186,0.2); }
    .places-item:last-child { border-bottom: none; }
    .places-item:hover, .places-item.active { background: rgba(72,120,166,0.08); }
  </style>
</head>
<body class="<?= h($bodyClass) ?>">

<?php
$_sbFile = ($_SESSION['nav_ctx'] ?? '') === 'bailleur'
    ? __DIR__ . '/inc/sidebar_bailleur.php'
    : __DIR__ . '/inc/sidebar_agency.php';
require_once $_sbFile;
?>

<main class="mbi-main v2-main">

  <!-- TOPBAR -->
  <header class="mbi-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <a href="<?= h(app_url('/bien_liste.php')) ?>">Biens</a>
      <span class="sep">›</span>
      <a href="<?= h(app_url('/bien_detail.php?edit=' . $editingBienId)) ?>">Détail</a>
      <span class="sep">›</span>
      <span class="active">v2</span>
    </nav>
    <span class="page-head-ref" style="margin-left:16px;">
      #<?= (int)$editingBienId ?>
      <?php if (!empty($bienLoaded['reference_bien'])): ?>
        · <strong style="color:#0f172a;"><?= h((string)$bienLoaded['reference_bien']) ?></strong>
      <?php endif; ?>
      <?php if ($section === 'annonce' && !empty($annonce)): ?>
        · <span style="color:#0ea5e9; font-weight:600;" title="ID interne de l'annonce courante">📰 Annonce #<?= (int)$annonce['id'] ?></span>
      <?php endif; ?>
    </span>
    <div class="topbar-spacer"></div>

    <!-- Score complétude Ubiflow (pill) -->
    <div class="v2-topbar-score <?= h($scoreClass) ?>" title="Complétude Ubiflow — <?= (int)count($ubiCheck['missing'] ?? []) ?> champ(s) manquant(s)">
      <span class="v2-score-dot"></span>
      <span class="v2-score-pct"><?= $scorePct ?>%</span>
    </div>

    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= h(strtoupper(substr($username, 0, 1))) ?></div>
  </header>

  <!-- PAGE HEAD — onglets seuls -->
  <div class="page-head">
    <nav class="v2-section-tabs" role="tablist" aria-label="Sections du bien">
      <a href="?edit=<?= (int)$editingBienId ?>&section=documents"
         class="v2-section-tab<?= $section === 'documents' ? ' is-active' : '' ?>"
         role="tab" aria-selected="<?= $section === 'documents' ? 'true' : 'false' ?>">
        <span>📎</span> Documents
      </a>
      <a href="?edit=<?= (int)$editingBienId ?>&section=dpe"
         class="v2-section-tab<?= $section === 'dpe' ? ' is-active' : '' ?>"
         role="tab" aria-selected="<?= $section === 'dpe' ? 'true' : 'false' ?>">
        <span>⚡</span> Diag &amp; DPE
      </a>
      <a href="?edit=<?= (int)$editingBienId ?>&section=descriptif"
         class="v2-section-tab<?= $section === 'descriptif' ? ' is-active' : '' ?>"
         role="tab" aria-selected="<?= $section === 'descriptif' ? 'true' : 'false' ?>">
        <span>🏠</span> Descriptif
      </a>
      <a href="?edit=<?= (int)$editingBienId ?>&section=validation"
         class="v2-section-tab<?= $section === 'validation' ? ' is-active' : '' ?>"
         role="tab" aria-selected="<?= $section === 'validation' ? 'true' : 'false' ?>"
         title="<?= $bienEstActif ? 'Bien validé' : ($nbManquants . ' champ(s) manquant(s)') ?>">
        <span><?= $bienEstActif ? '✅' : '⚠️' ?></span> Validation<?php if (!$bienEstActif && $nbManquants > 0): ?> <small style="background:#fef3c7;color:#78350f;padding:1px 6px;border-radius:99px;font-size:10px;font-weight:700;"><?= $nbManquants ?></small><?php endif; ?>
      </a>
      <a href="?edit=<?= (int)$editingBienId ?>&section=annonce"
         class="v2-section-tab<?= $section === 'annonce' ? ' is-active' : '' ?><?= !$bienEstActif ? ' is-locked' : '' ?>"
         role="tab" aria-selected="<?= $section === 'annonce' ? 'true' : 'false' ?>"
         title="<?= $bienEstActif ? 'Diffusion sur portails' : '🔒 Valide d\'abord le bien pour accéder à l\'annonce' ?>"
         <?php if (!$bienEstActif): ?>data-locked="1" onclick="alert('⚠️ Tu dois d\'abord valider le bien (onglet Validation) avant de pouvoir créer une annonce.'); return false;"<?php endif; ?>>
        <span><?= $bienEstActif ? '📡' : '🔒' ?></span> Annonce
      </a>
    </nav>
  </div>

  <!-- CAROUSEL STAGE -->
  <div class="v2-stage-wrap">
    <!-- Barre d'onglets (titres des cards, active en grand) -->
    <div id="v2-stage-tabs" class="v2-stage-tabs" role="tablist" aria-label="Sélection de carte"></div>

    <button type="button" id="v2-prev" class="v2-nav prev" aria-label="Carte précédente">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" id="v2-next" class="v2-nav next" aria-label="Carte suivante">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </button>

    <div id="v2-stage" class="v2-stage" role="tablist">

    <?php if ($section === 'documents'): ?>

      <!-- Card 1 : CHARGEMENT -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Chargement de documents">
        <div class="v2-card-label">⬆️ Chargement</div>
        <div class="v2-card-body">
          <div class="v2-chargement-tagline">
            ✨ Le meilleur moyen de créer un bien est de scanner son DPE !
          </div>
          <div id="v2-uploader"></div>

          <!-- Dropzone Photos (en dessous du DocumentUploader) -->
          <div class="v2-photo-drop-wrap">
            <div class="v2-desc-group-title">📸 Photos du bien</div>
            <div id="v2-photo-drop" class="v2-photo-drop">
              <input type="file" id="v2-photo-input" accept="image/jpeg,image/png,image/webp" multiple hidden>
              <div class="v2-photo-drop-icon">📸</div>
              <div class="v2-photo-drop-title">Glissez vos photos ici ou cliquez</div>
              <div class="v2-photo-drop-sub">JPG / PNG / WebP · max 15 Mo par photo · multiples acceptés</div>
            </div>
            <div id="v2-photo-drop-status" class="v2-photo-drop-status"></div>
          </div>
        </div>
      </section>

      <!-- Card 2 : DIAGNOSTICS -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Diagnostics">
        <div class="v2-card-label">📊 Diagnostics <span class="v2-count" id="v2-count-diag">0</span></div>
        <div class="v2-card-body" id="v2-list-diag"></div>
      </section>

      <!-- Card 3 : MANDATS -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Mandats et baux">
        <div class="v2-card-label">📋 Mandats &amp; baux <span class="v2-count" id="v2-count-mandat">0</span></div>
        <div class="v2-card-body" id="v2-list-mandat"></div>
      </section>

      <!-- Card 4 : AUTRES -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Autres documents">
        <div class="v2-card-label">📎 Autres documents <span class="v2-count" id="v2-count-autre">0</span></div>
        <div class="v2-card-body" id="v2-list-autre"></div>
      </section>

      <!-- Card 5 : PHOTOS -->
      <section class="v2-card is-prev" role="tabpanel" aria-label="Photos du bien">
        <?php
          // Comptage des photos qui ont besoin d'une analyse :
          // - jamais analysées (analyse_statut != 'ok')
          // - OU analysées en commercial mais sans critique IA (legacy avant migration critique)
          $nbAnalyser = 0;
          foreach ($docsPhotos as $pp) {
              $statutOk = (($pp['analyse_statut'] ?? '') === 'ok');
              $aCritique = (($pp['critique_niveau'] ?? '') !== '');
              if (!$statutOk || !$aCritique) $nbAnalyser++;
          }
        ?>
        <div class="v2-card-label">
          📸 Photos <span class="v2-count" id="v2-count-photos"><?= count($docsPhotos) ?></span>
          <?php if (!empty($docsPhotos)): ?>
            <button type="button"
                    class="v2-btn-analyze-all"
                    data-analyze-all-photos="1"
                    data-bien-id="<?= (int)$editingBienId ?>"
                    title="Analyser toutes les photos qui n'ont pas encore de critique de prise de vue">
              🤖 Analyser toutes <span class="v2-analyze-all-count"><?= $nbAnalyser ?></span>
            </button>
          <?php endif; ?>
        </div>
        <div class="v2-card-body" id="v2-photos-container">
          <?php if (empty($docsPhotos)): ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">📸</div>
              <div>Aucune photo — glisse des photos dans la card Chargement.</div>
            </div>
          <?php else: ?>
            <div class="v2-photos-doc-grid">
              <?php foreach ($docsPhotos as $p):
                $needsAnalyse = (($p['analyse_statut'] ?? '') !== 'ok') || (($p['critique_niveau'] ?? '') === '');
              ?>
                <div class="v2-photo-tile"
                     data-id="<?= (int)$p['id'] ?>"
                     data-url="<?= h($p['url']) ?>"
                     data-name="<?= h($p['nom_original']) ?>"
                     data-statut="<?= $needsAnalyse ? '' : 'ok' ?>">
                  <div class="v2-photo-tile-img-wrap">
                    <img src="<?= h($p['url']) ?>" alt="<?= h($p['nom_original']) ?>" loading="lazy">
                    <div class="v2-photo-tile-actions">
                      <button type="button" class="v2-photo-tile-btn" data-action="zoom" title="Agrandir">🔍</button>
                      <button type="button" class="v2-photo-tile-btn" data-action="analyze" title="Analyser à l'IA (commercial + critique de prise de vue)">🤖</button>
                      <button type="button" class="v2-photo-tile-btn danger" data-action="delete" title="Supprimer">🗑️</button>
                    </div>
                  </div>
                  <div class="v2-photo-tile-ai" data-photo-ai="<?= (int)$p['id'] ?>">
                    <?php if ($p['categorie'] !== '' || $p['description_ia'] !== ''): ?>
                      <?php if ($p['categorie'] !== ''): ?>
                        <span class="v2-photo-tile-ai-cat">🏷️ <?= h($p['categorie']) ?></span>
                      <?php endif; ?>
                      <?php if ($p['description_ia'] !== ''): ?>
                        <div class="v2-photo-tile-ai-desc"><?= h((string)$p['description_ia']) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="v2-photo-tile-ai-empty">📝 Pas encore analysée — clique 🤖</span>
                    <?php endif; ?>
                  </div>

                  <?php
                    $hasCritique = ($p['critique_niveau'] ?? '') !== ''
                        || !empty($p['critique_points_forts'])
                        || !empty($p['critique_points_faibles'])
                        || ($p['critique_conseil'] ?? '') !== '';
                  ?>
                  <?php if ($hasCritique):
                    $niv = (string)($p['critique_niveau'] ?? '');
                    $nivIcon  = ['bon' => '🟢', 'moyen' => '🟡', 'mauvais' => '🔴'][$niv] ?? '⚪';
                    $nivLabel = ['bon' => 'Bonne photo', 'moyen' => 'À améliorer', 'mauvais' => 'À refaire'][$niv] ?? 'Non évaluée';
                  ?>
                    <div class="v2-photo-tile-critique critique-niveau-<?= h($niv ?: 'na') ?>" data-photo-critique="<?= (int)$p['id'] ?>">
                      <div class="critique-header">
                        <span class="critique-icon"><?= $nivIcon ?></span>
                        <span class="critique-label">📸 Prise de vue : <?= h($nivLabel) ?></span>
                      </div>
                      <?php if (!empty($p['critique_points_forts'])): ?>
                        <div class="critique-section critique-forts">
                          <div class="critique-section-title">✅ Points forts</div>
                          <ul>
                            <?php foreach ($p['critique_points_forts'] as $pf): ?>
                              <li><?= h((string)$pf) ?></li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($p['critique_points_faibles'])): ?>
                        <div class="critique-section critique-faibles">
                          <div class="critique-section-title">⚠️ À améliorer</div>
                          <ul>
                            <?php foreach ($p['critique_points_faibles'] as $pw): ?>
                              <li><?= h((string)$pw) ?></li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>
                      <?php if (($p['critique_conseil'] ?? '') !== ''): ?>
                        <div class="critique-conseil">
                          💡 <?= h((string)$p['critique_conseil']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Lightbox pour agrandir les photos -->
      <div id="v2-photo-lightbox" class="v2-lightbox" hidden>
        <button type="button" class="v2-lightbox-close" aria-label="Fermer">✕</button>
        <button type="button" class="v2-lightbox-nav v2-lightbox-prev" aria-label="Précédente">‹</button>
        <button type="button" class="v2-lightbox-nav v2-lightbox-next" aria-label="Suivante">›</button>
        <img id="v2-lightbox-img" src="" alt="">
        <div class="v2-lightbox-counter" id="v2-lightbox-counter"></div>
        <div id="v2-lightbox-caption" class="v2-lightbox-caption"></div>
      </div>

    <?php elseif ($section === 'descriptif'): ?>

      <?php
        $b = $bienLoaded;
        $v = static fn($k) => isset($b[$k]) && $b[$k] !== '' && $b[$k] !== null ? $b[$k] : null;
        $vb = static fn($k) => (int)($b[$k] ?? 0) === 1 ? '✅ Oui' : '—';
        $vn = static fn($k) => $v($k) !== null ? $v($k) : '—';
        $proprioStr = '—';
        if ($proprioInfo) {
            if (!empty($proprioInfo['raison_sociale'])) {
                $proprioStr = (string)$proprioInfo['raison_sociale'];
            } else {
                $civ = !empty($proprioInfo['civilite']) ? $proprioInfo['civilite'] . ' ' : '';
                $proprioStr = trim($civ . (string)($proprioInfo['prenom'] ?? '') . ' ' . (string)($proprioInfo['nom'] ?? '')) ?: '—';
            }
        }
      ?>

      <?php
        // Icones (emojis natifs) par champ
        $typeIcons = [
          'appartement'=>'🏢','maison'=>'🏠','villa'=>'🏡','terrain'=>'🗺️',
          'local_commercial'=>'🏪','bureau'=>'💼','immeuble'=>'🏬',
          'parking'=>'🅿️','garage'=>'🚗','entrepot'=>'📦','boutique'=>'🛍️',
          'loft'=>'🎨','atelier'=>'🔧','fonds_commerce'=>'✍️','programme_neuf'=>'🏗️',
        ];
        $sousTypes = [
          'studio'=>['🏚️','Studio'], 't1'=>['1️⃣','T1'], 't2'=>['2️⃣','T2'],
          't3'=>['3️⃣','T3'], 't4'=>['4️⃣','T4'], 't5'=>['5️⃣','T5+'],
          'duplex'=>['🏗️','Duplex'], 'triplex'=>['🏙️','Triplex'],
          'plain_pied'=>['➖','Plain-pied'], 'a_etage'=>['⬆️','À étage'],
        ];
        $usages = [
          'habitation'=>['🏠','Habitation'], 'commercial'=>['🏪','Commercial'],
          'professionnel'=>['💼','Pro'], 'mixte'=>['🔀','Mixte'],
        ];
        $etats = [
          'neuf'=>['✨','Neuf'], 'recent'=>['🆕','Récent'],
          'bon_etat'=>['✅','Bon état'], 'rafraichir'=>['🎨','À rafraîchir'],
          'travaux'=>['🔨','Travaux'], 'mauvais'=>['⚠️','Mauvais'],
        ];
        $standings = [
          'economique'=>['💰','Économique'], 'standard'=>['⭐','Standard'],
          'standing'=>['✨','Standing'], 'luxe'=>['💎','Luxe'],
        ];
        $statuts = [
          'actif'=>['✅','Actif'], 'brouillon'=>['📝','Brouillon'], 'archive'=>['📦','Archivé'],
        ];
        $mandats = [
          'vente'=>['💶','Vente'], 'location'=>['🔑','Location'], 'gestion'=>['🏢','Gestion'],
        ];

        // Code stable du type courant : on lit directement _type_bien_code
        // calculé par bien_form_loader via COALESCE(bien_types.code, base_types_bien.code).
        // Source de vérité : bien_types (via biens.id_bien_type), fallback legacy.
        // Les IDs auto-increment ne sont pas portables entre dev/prod (et types_bien_legacy
        // a des ids différents de bien_types) — on travaille en code stable uniquement.
        $curTypeCode = strtolower(trim((string)($b['_type_bien_code'] ?? '')));
        $curType     = 0;
        foreach ($typesBienList as $_t) {
            if ((string)$_t['code'] === $curTypeCode) { $curType = (int)$_t['id']; break; }
        }
        $curSType   = (string)($b['sous_type_bien'] ?? '');
        $curUsage   = (string)($b['usage_bien'] ?? '');
        $curEtat    = (string)($b['etat_bien'] ?? '');
        $curStand   = (string)($b['standing'] ?? '');
        $curStatut  = (string)($b['statut_bien'] ?? '');
        $curMandat  = (string)($b['type_commercialisation'] ?? '');
        $curProprio = (int)($b['id_proprietaire'] ?? 0);
      ?>

      <!-- Card 1 : Propriétaire (tiers lié, recherche, création express, infos extraites) -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Propriétaire">
        <div class="v2-card-label">👤 Propriétaire</div>
        <div id="v2-save-indicator" class="v2-save-indicator v2-save-floating" aria-live="polite"></div>
        <div class="v2-card-body">
          <?php if ($proprioInfo): ?>
            <!-- ─── État : propriétaire lié au bien ──────────────────── -->
            <?php
              $idProprioLegacy = (int)($proprioInfo['id_proprio_legacy'] ?? 0);
              $idTiersProprio  = (int)($proprioInfo['id_tiers'] ?? 0);
              $patTotal        = (int)($proprioInfo['_patrimoine_total'] ?? 0);
              $patAgences      = $proprioInfo['_patrimoine_agences'] ?? [];
              // URL de retour pour revenir sur ce bien après édition
              $returnUrl = '/bien_detail.php?edit=' . (int)$editingBienId . '&section=descriptif';
              // « Ouvrir fiche » → fiche propriétaire 360° (tiers_360, qui fonctionne).
              // Repli sur l'ancienne fiche legacy si le tiers n'est pas renseigné.
              $ficheUrl  = $idTiersProprio > 0
                         ? '/tiers_360.php?id=' . $idTiersProprio
                         : '/agency_proprietaire_fiche.php?id=' . $idProprioLegacy
                           . '&return=' . urlencode($returnUrl);
            ?>
            <div class="v2-proprio-card is-linked">
              <div class="v2-proprio-head">
                <div class="v2-proprio-avatar">
                  <?= ($proprioInfo['type_tiers'] ?? '') === 'personne_morale' ? '🏢' : '👤' ?>
                </div>
                <div class="v2-proprio-main">
                  <div class="v2-proprio-name"><?= h((string)($proprioStr ?: '—')) ?></div>
                  <div class="v2-proprio-meta">
                    <?php if (!empty($proprioInfo['telephone'])): ?>📞 <?= h((string)$proprioInfo['telephone']) ?><?php endif; ?>
                    <?php if (!empty($proprioInfo['email'])): ?> · ✉️ <?= h((string)$proprioInfo['email']) ?><?php endif; ?>
                    <?php
                      $vilP = trim((string)($proprioInfo['ville'] ?? ''));
                      $cpP  = trim((string)($proprioInfo['code_postal'] ?? ''));
                      if ($cpP || $vilP): ?>
                      · 📍 <?= h(trim($cpP . ' ' . $vilP)) ?>
                    <?php endif; ?>
                    <?php if (!empty($proprioInfo['adresse'])): ?>
                      <div style="margin-top:2px;"><?= h((string)$proprioInfo['adresse']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="v2-proprio-actions">
                  <?php if ($idTiersProprio > 0 || $idProprioLegacy > 0): ?>
                    <a class="v2-btn-primary" href="<?= h(app_url($ficheUrl)) ?>"
                       title="Ouvrir la fiche propriétaire 360°">
                      👤 Ouvrir fiche
                    </a>
                  <?php endif; ?>
                  <?php if ($bienEstActif): ?>
                    <button type="button" class="v2-btn-secondary" disabled
                            style="background:#f1f5f9; color:#64748b; cursor:not-allowed;"
                            title="🔒 Verrouillé — dé-valide le bien pour changer de propriétaire">
                      🔒 Dissocier (verrouillé)
                    </button>
                  <?php else: ?>
                    <button type="button" class="v2-btn-secondary" id="v2-proprio-unlink"
                            title="Retirer ce propriétaire du bien (le tiers reste en base)">🔗 Dissocier</button>
                  <?php endif; ?>
                </div>
              </div>

              <!-- ─── Patrimoine chez nous ─── -->
              <?php if ($patTotal > 0): ?>
                <div class="v2-proprio-patrimoine">
                  <div class="v2-proprio-patrimoine-title">
                    📊 Patrimoine chez nous
                    <strong><?= $patTotal ?> bien<?= $patTotal > 1 ? 's' : '' ?></strong>
                    <?php if (count($patAgences) > 1): ?>
                      · <?= count($patAgences) ?> agences
                    <?php endif; ?>
                  </div>
                  <div class="v2-proprio-patrimoine-list">
                    <?php foreach ($patAgences as $ag):
                      $agNom = (string)($ag['nom'] ?? '— Sans agence —');
                      $nb    = (int)$ag['nb_biens'];
                    ?>
                      <span class="v2-proprio-patrimoine-chip">
                        🏢 <?= h($agNom) ?> · <strong><?= $nb ?></strong>
                      </span>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <!-- ─── État : pas de propriétaire lié ───────────────────── -->
            <div class="v2-proprio-card is-empty">
              <div class="v2-proprio-empty-msg">
                Aucun propriétaire n'est lié à ce bien.
                Recherche-le dans la base ou crée-en un nouveau.
              </div>
            </div>
          <?php endif; ?>

          <!-- ─── Recherche + création — désactivé si bien actif (proprio verrouillé) ──────────── -->
          <?php if ($bienEstActif): ?>
            <div style="margin-top:14px; padding:12px 16px; background:#fef3c7; border-left:4px solid #f59e0b; border-radius:6px; font-size:13px; color:#78350f;">
              🔒 <strong>Propriétaire verrouillé.</strong> Dé-valide le bien (onglet Validation) pour changer de propriétaire.
            </div>
          <?php else: ?>
            <div class="v2-group-header" style="margin-top:14px;">
              <span class="v2-group-header-title">🔍 Lier un propriétaire</span>
            </div>
            <div class="v2-proprio-picker">
              <input type="text" class="v2-input" id="v2-proprio-search"
                     placeholder="Tape un nom, email, société…" autocomplete="off">
              <button type="button" class="v2-btn-primary" id="v2-proprio-new-btn">+ Créer nouveau propriétaire</button>
            </div>
            <div class="v2-proprio-results" id="v2-proprio-results" hidden></div>
          <?php endif; ?>

          <?php if ($descProprioFromDpe && empty($bienLoaded['id_proprietaire'])): ?>
            <!-- ─── Propriétaire détecté dans le DPE (prefill rapide) ─ -->
            <div class="v2-dpe-proprio-suggest" id="v2-dpe-proprio-suggest"
                 data-proprio='<?= h(json_encode($descProprioFromDpe, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'
                 style="margin-top:14px;">
              <div class="v2-dpe-proprio-header">
                📄 <strong>Propriétaire détecté dans le DPE</strong>
              </div>
              <div class="v2-dpe-proprio-body">
                <span class="v2-dpe-proprio-name"><?= h($descProprioFromDpe['display']) ?></span>
                <?php if (!empty($descProprioFromDpe['telephone'])): ?>
                  <span>· 📞 <?= h($descProprioFromDpe['telephone']) ?></span>
                <?php endif; ?>
                <?php if (!empty($descProprioFromDpe['email'])): ?>
                  <span>· ✉️ <?= h($descProprioFromDpe['email']) ?></span>
                <?php endif; ?>
              </div>
              <div class="v2-dpe-proprio-actions">
                <button type="button" class="v2-btn-primary" id="v2-dpe-proprio-create">✓ Créer &amp; associer</button>
                <span id="v2-dpe-proprio-status" class="v2-form-status"></span>
              </div>
            </div>
          <?php endif; ?>

          <!-- ─── Modal création express propriétaire ─────────────── -->
          <div class="v2-modal-overlay" id="v2-proprio-modal" hidden>
            <div class="v2-modal">
              <div class="v2-modal-head">
                <h3>➕ Nouveau propriétaire</h3>
                <button type="button" class="v2-modal-close" id="v2-proprio-modal-close">✕</button>
              </div>
              <div class="v2-modal-body">
                <div class="v2-icon-row">
                  <span class="v2-icon-row-label">Type</span>
                  <div class="v2-icon-radios" id="v2-proprio-type">
                    <button type="button" class="v2-icon-radio is-active" data-value="personne_physique">
                      <span class="v2-icon-emoji">👤</span><span class="v2-icon-lbl">Particulier</span>
                    </button>
                    <button type="button" class="v2-icon-radio" data-value="personne_morale">
                      <span class="v2-icon-emoji">🏢</span><span class="v2-icon-lbl">Société</span>
                    </button>
                  </div>
                </div>

                <div id="v2-proprio-pp-fields">
                  <div class="v2-addr-grid" style="grid-template-columns:1fr 1fr; margin-top:10px;">
                    <div class="v2-field">
                      <label class="v2-field-label">Prénom</label>
                      <input type="text" class="v2-input" id="v2-proprio-prenom" maxlength="80">
                    </div>
                    <div class="v2-field">
                      <label class="v2-field-label">Nom <span style="color:#dc2626;">*</span></label>
                      <input type="text" class="v2-input" id="v2-proprio-nom" maxlength="80" required>
                    </div>
                  </div>
                </div>

                <div id="v2-proprio-pm-fields" hidden>
                  <div class="v2-field" style="margin-top:10px;">
                    <label class="v2-field-label">Raison sociale <span style="color:#dc2626;">*</span></label>
                    <input type="text" class="v2-input" id="v2-proprio-raison" maxlength="150">
                  </div>
                  <div class="v2-field">
                    <label class="v2-field-label">SIRET</label>
                    <input type="text" class="v2-input" id="v2-proprio-siret" maxlength="20" placeholder="14 chiffres">
                  </div>
                </div>

                <div class="v2-addr-grid" style="grid-template-columns:1fr 1fr; margin-top:10px;">
                  <div class="v2-field">
                    <label class="v2-field-label">Email</label>
                    <input type="email" class="v2-input" id="v2-proprio-email">
                  </div>
                  <div class="v2-field">
                    <label class="v2-field-label">Téléphone</label>
                    <input type="tel" class="v2-input" id="v2-proprio-tel">
                  </div>
                </div>
                <div class="v2-field" style="margin-top:10px;">
                  <label class="v2-field-label">Adresse</label>
                  <input type="text" class="v2-input" id="v2-proprio-adresse">
                </div>
                <div class="v2-addr-grid" style="grid-template-columns:0.4fr 1fr; margin-top:8px;">
                  <input type="text" class="v2-input" id="v2-proprio-cp" placeholder="CP" maxlength="10">
                  <input type="text" class="v2-input" id="v2-proprio-ville" placeholder="Ville">
                </div>

                <div id="v2-proprio-modal-status" class="v2-form-status" style="margin-top:10px;"></div>
              </div>
              <div class="v2-modal-foot">
                <button type="button" class="v2-btn-secondary" id="v2-proprio-modal-cancel">Annuler</button>
                <button type="button" class="v2-btn-primary" id="v2-proprio-modal-save">✓ Créer et associer</button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Card 2 : Adresse / Caractéristiques (EDITION AVEC AUTOSAVE) -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Caractéristiques">
        <div class="v2-card-label">📋 Caractéristiques</div>
        <div class="v2-card-body">
          <!-- 1bis. Identification (référence + désignation + statut + mandat sur une ligne) -->
          <div class="v2-group-header">
            <span class="v2-group-header-title">🏷️ Identification</span>
            <span class="v2-hint">Référence interne + désignation commerciale — repris dans l'annonce et le flux Ubiflow</span>
          </div>
          <div class="v2-id-row" style="display:grid; grid-template-columns: minmax(140px, 0.5fr) minmax(220px, 1fr) auto auto; gap:10px; align-items:center; margin-bottom:14px;">
            <input type="text" id="v2-f-reference_bien" class="v2-input"
                   name="reference_bien" data-autosave maxlength="60"
                   placeholder="Référence bien (ex : 2026-001)"
                   value="<?= h((string)($b['reference_bien'] ?? '')) ?>">
            <input type="text" id="v2-f-designation" class="v2-input"
                   name="designation" data-autosave maxlength="255"
                   placeholder="Désignation commerciale (ex : T3 lumineux vue dégagée, 65m²)"
                   value="<?= h((string)($b['designation'] ?? '')) ?>">
            <div class="v2-icon-row v2-icon-row-inline">
              <span class="v2-icon-row-label">Statut</span>
              <div class="v2-icon-radios" data-field="statut_bien">
                <?php foreach ($statuts as $code => [$ic, $lbl]): $act = ($curStatut === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>" title="<?= h($lbl) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="v2-icon-row v2-icon-row-inline" title="🏢 Gestion = défaut systématique du bien. Ne change en Vente ou Location que si un propriétaire externe nous confie un mandat spécifique. Le type de transaction publique (vente/location) se choisit dans l'onglet Annonce.">
              <span class="v2-icon-row-label">Mandat <span class="v2-info-pill" aria-label="Aide">ⓘ</span></span>
              <div class="v2-icon-radios" data-field="type_commercialisation">
                <?php foreach ($mandats as $code => [$ic, $lbl]): $act = ($curMandat === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>" title="<?= h($lbl) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- 2. Adresse (inputs autosave — valeurs déjà syncées depuis dpe_diags si vides) -->
          <?php
            $etageVal = isset($b['etage']) && $b['etage'] !== '' ? h((string)$b['etage']) : '';
            $anneeVal = isset($b['annee_construction']) && $b['annee_construction'] !== '' ? h((string)$b['annee_construction']) : '';
            $etageFromDpe = isset($syncFlags['etage']) ? ' is-from-dpe' : '';
            $anneeFromDpe = isset($syncFlags['annee_construction']) ? ' is-from-dpe' : '';
          ?>
          <div class="v2-group-header">
            <span class="v2-group-header-title">📍 Adresse</span>
            <?php if (!empty($descSyncedFields)): ?>
              <span class="v2-hint">📄 <?= count($descSyncedFields) ?> champ(s) repris du DPE</span>
            <?php endif; ?>
            <div class="v2-num-field<?= $etageFromDpe ?>" title="Étage">
              <span class="v2-num-icon">🪜</span>
              <input type="number" step="any" min="0" class="v2-num-input" name="etage" data-autosave value="<?= $etageVal ?>" placeholder="0">
              <span class="v2-num-label">Étage</span>
            </div>
            <div class="v2-num-field<?= $anneeFromDpe ?>" title="Année construction">
              <span class="v2-num-icon">📅</span>
              <input type="number" step="1" min="0" max="2100" class="v2-num-input" style="width:70px;" name="annee_construction" data-autosave value="<?= $anneeVal ?>" placeholder="Année">
              <span class="v2-num-label">An. construction</span>
            </div>
            <?php $curBienEnCopro = (int)($b['bien_en_copropriete'] ?? 0) === 1; ?>
            <button type="button" class="v2-bool-toggle<?= $curBienEnCopro ? ' is-active' : '' ?>" data-bool-field="bien_en_copropriete"
                    title="Coche si le bien est en copropriété. Rend obligatoires : nombre de lots principaux et charges annuelles du lot (obligation ALUR pour diffusion).">
              <span class="v2-icon-emoji">🏢</span>
              <span class="v2-icon-lbl">Copropriété</span>
            </button>
          </div>
          <?php
            $syncFlags = array_flip($descSyncedFields);
            $addrCls = static fn($f) => isset($syncFlags[$f]) ? ' is-from-dpe' : '';
          ?>
          <!-- Bouton d'ouverture du modal d'adresse (recherche Google + immeubles existants) -->
          <!-- ⚠️ Bloqué si bien actif : l'adresse fait partie des données critiques verrouillées.
               L'utilisateur doit dé-valider le bien (onglet Validation) pour pouvoir la modifier. -->
          <div style="margin-bottom:10px;">
            <?php if ($bienEstActif): ?>
              <button type="button" class="v2-btn-secondary"
                      style="width:100%; padding:12px; font-size:13px; background:#f1f5f9; color:#64748b; cursor:not-allowed;"
                      disabled title="🔒 Adresse verrouillée — dé-valide le bien (onglet Validation) pour la modifier">
                🔒 Adresse verrouillée (bien validé)
              </button>
            <?php else: ?>
              <button type="button"
                      class="v2-btn-primary"
                      style="width:100%; padding:12px; font-size:13px;"
                      onclick="bdOpenImmeubleModal(this)"
                      data-bien-id="<?= (int)$editingBienId ?>"
                      data-autosave-url="<?= h(app_url('/api/bien_autosave.php')) ?>"
                      data-csrf="<?= h(csrf_token('ajouter_bien')) ?>">
                📍 Rechercher / saisir l'adresse (Google + immeubles existants)
              </button>
            <?php endif; ?>
          </div>
          <div class="v2-addr-grid">
            <input type="text" id="v2-f-adresse_1"   class="v2-input<?= $addrCls('adresse_1') ?>"   name="adresse_1"   data-autosave placeholder="Adresse"    value="<?= h((string)($b['_imm_adresse_1']   ?? $b['adresse_1']   ?? '')) ?>">
            <input type="text" id="v2-f-adresse_2"   class="v2-input<?= $addrCls('adresse_2') ?>"   name="adresse_2"   data-autosave placeholder="Complément" value="<?= h((string)($b['_imm_adresse_2']   ?? $b['adresse_2']   ?? '')) ?>">
            <input type="text" id="v2-f-code_postal" class="v2-input<?= $addrCls('code_postal') ?>" name="code_postal" data-autosave placeholder="CP" maxlength="10" value="<?= h((string)($b['_imm_code_postal'] ?? $b['code_postal'] ?? '')) ?>">
            <input type="text" id="v2-f-ville"       class="v2-input<?= $addrCls('ville') ?>"       name="ville"       data-autosave placeholder="Ville"     value="<?= h((string)($b['_imm_ville']       ?? $b['ville']       ?? '')) ?>">
          </div>
          <!-- Champs hidden alimentés par le modal (coordonnées GPS + Google Place ID) -->
          <input type="hidden" id="v2-f-latitude"  name="latitude"          value="">
          <input type="hidden" id="v2-f-longitude" name="longitude"         value="">
          <input type="hidden" id="v2-f-place_id"  name="google_place_id"   value="">
          <input type="hidden" id="v2-f-formatted" name="adresse_formatted" value="">

          <!-- 3. Caractéristiques -->
          <div class="v2-desc-group-title">🏷️ Caractéristiques</div>

          <!-- Type (pleine largeur) -->
          <div class="v2-icon-row">
            <span class="v2-icon-row-label">Type</span>
            <div class="v2-icon-radios" data-field="type_bien">
              <?php foreach ($typesBienList as $t):
                $icon = $typeIcons[$t['code']] ?? '📦';
                $act = ((string)$t['code'] === $curTypeCode) ? ' is-active' : '';
              ?>
                <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h((string)$t['code']) ?>" title="<?= h((string)$t['label']) ?>">
                  <span class="v2-icon-emoji"><?= $icon ?></span>
                  <span class="v2-icon-lbl"><?= h((string)$t['label']) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Sous-type + Usage -->
          <div class="v2-row-split">
            <div class="v2-icon-row">
              <span class="v2-icon-row-label">Sous-type</span>
              <div class="v2-icon-radios" data-field="sous_type_bien">
                <?php foreach ($sousTypes as $code => [$ic, $lbl]): $act = ($curSType === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="v2-icon-row">
              <span class="v2-icon-row-label">Usage</span>
              <div class="v2-icon-radios" data-field="usage_bien">
                <?php foreach ($usages as $code => [$ic, $lbl]): $act = ($curUsage === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <?php
              // TVA : visible uniquement si usage commercial/pro/mixte. Persiste sur annonces.tva_assujetti.
              // Au changement → reload (adapte les champs de la Card Conditions financières : HT vs HC).
              $usageNeedsTva = in_array($curUsage, ['commercial','pro','mixte'], true);
              $curTvaAssujetti = (int)($annonce['tva_assujetti'] ?? 0) === 1;
            ?>
            <?php if ($usageNeedsTva && $annonce): ?>
            <div class="v2-icon-row" title="Bail commercial : préciser si la location est assujettie à la TVA 20%. Impacte les champs de loyer (HT vs HC) sur la Card Conditions financières.">
              <span class="v2-icon-row-label">💼 TVA</span>
              <div class="v2-icon-radios" data-field="tva_assujetti" data-target="annonce" data-reload-after-save="1">
                <button type="button" class="v2-icon-radio<?= !$curTvaAssujetti ? ' is-active' : '' ?>" data-value="0">
                  <span class="v2-icon-emoji">❌</span><span class="v2-icon-lbl">Non assujetti</span>
                </button>
                <button type="button" class="v2-icon-radio<?= $curTvaAssujetti ? ' is-active' : '' ?>" data-value="1">
                  <span class="v2-icon-emoji">✅</span><span class="v2-icon-lbl">Assujetti TVA 20%</span>
                </button>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <!-- État + Standing -->
          <div class="v2-row-split">
            <div class="v2-icon-row">
              <span class="v2-icon-row-label">État</span>
              <div class="v2-icon-radios" data-field="etat_bien">
                <?php foreach ($etats as $code => [$ic, $lbl]): $act = ($curEtat === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="v2-icon-row">
              <span class="v2-icon-row-label">Standing</span>
              <div class="v2-icon-radios" data-field="standing">
                <?php foreach ($standings as $code => [$ic, $lbl]): $act = ($curStand === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- Modal liste immeubles (alpha) — plus de recherche -->
      <?php
        $immReturnUrl = '/bien_detail.php?edit=' . (int)$editingBienId . '&section=descriptif';
        $immCreateUrl = app_url('/agency_immeuble_form.php?return=' . urlencode($immReturnUrl));
      ?>
      <div id="v2-imm-modal" class="v2-modal" hidden>
        <div class="v2-modal-card">
          <h3>🏢 Sélectionner un immeuble</h3>
          <?php if (!empty($immeublesList)): ?>
            <div class="v2-imm-results">
              <?php foreach ($immeublesList as $imm):
                $adr = (string)($imm['adresse_1'] ?? '');
                $loc = trim(((string)($imm['code_postal'] ?? '')) . ' ' . ((string)($imm['ville'] ?? '')));
                $ref = !empty($imm['reference_immeuble']) ? '[' . $imm['reference_immeuble'] . '] ' : '';
              ?>
                <div class="v2-imm-item"
                     data-adresse="<?= h($adr) ?>"
                     data-cp="<?= h((string)($imm['code_postal'] ?? '')) ?>"
                     data-ville="<?= h((string)($imm['ville'] ?? '')) ?>">
                  <strong><?= h($ref . ($adr ?: 'Immeuble #' . (int)$imm['id'])) ?></strong>
                  <?php if ($loc): ?><small><?= h($loc) ?></small><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="v2-imm-empty">
              Aucun immeuble dans votre agence pour le moment.<br>
              <small>Vous pouvez en créer un ou saisir l'adresse directement dans les champs ci-dessous (l'immeuble sera créé automatiquement).</small>
            </div>
          <?php endif; ?>
          <div class="v2-modal-actions">
            <a href="<?= h($immCreateUrl) ?>" class="v2-btn-primary">➕ Créer un nouvel immeuble</a>
            <button type="button" id="v2-imm-modal-close" class="v2-btn-outline">Fermer</button>
          </div>
        </div>
      </div>

      <?php
        $syncedFlags = array_flip($descSyncedFields);
        $numField = static function(string $icon, string $name, string $label, string $suffix = '') use ($b, $syncedFlags) {
          $val = isset($b[$name]) && $b[$name] !== null && $b[$name] !== '' ? $b[$name] : '';
          $fromDpe = isset($syncedFlags[$name]) ? ' is-from-dpe' : '';
          return '<div class="v2-num-field' . $fromDpe . '" title="' . ($fromDpe ? 'Repris du DPE' : '') . '">'
               . '<span class="v2-num-icon">' . $icon . '</span>'
               . '<input type="number" step="any" min="0" class="v2-num-input"'
               . ' name="' . h($name) . '" data-autosave value="' . h((string)$val) . '"'
               . ' placeholder="' . h($label) . '">'
               . '<span class="v2-num-label">' . h($label) . ($suffix ? ' <small>' . $suffix . '</small>' : '') . '</span>'
               . '</div>';
        };
        $boolToggle = static function(string $icon, string $name, string $label) use ($b, $syncedFlags) {
          $active = (int)($b[$name] ?? 0) === 1 ? ' is-active' : '';
          $fromDpe = isset($syncedFlags[$name]) ? ' is-from-dpe' : '';
          return '<button type="button" class="v2-bool-toggle' . $active . $fromDpe . '" data-bool-field="' . h($name) . '"'
               . ($fromDpe ? ' title="Repris du DPE"' : '') . '>'
               . '<span class="v2-icon-emoji">' . $icon . '</span>'
               . '<span class="v2-icon-lbl">' . h($label) . '</span>'
               . '</button>';
        };
      ?>

      <!-- Card 2 : Pièces, surfaces, extérieur, équipements (ÉDITION AUTOSAVE) -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Pièces et surfaces">
        <div class="v2-card-label">📐 Pièces &amp; Surfaces</div>
        <div class="v2-card-body">

          <div class="v2-desc-group-title">🚪 Pièces (nombre)</div>
          <div class="v2-num-grid">
            <?= $numField('🛋️', 'nb_pieces',      'Pièces') ?>
            <?= $numField('🛏️', 'nb_chambres',    'Chambres') ?>
            <?= $numField('🛁',  'nb_salles_bain', 'SDB') ?>
            <?= $numField('🚿',  'nb_salles_eau',  "S. d'eau") ?>
            <?= $numField('🚽',  'nb_wc',          'WC') ?>
            <?= $numField('🏢', 'nb_niveaux',     'Niveaux') ?>
          </div>

          <div class="v2-desc-group-title">📏 Surfaces (m²)</div>
          <div class="v2-num-grid">
            <?= $numField('🏠', 'surface_habitable', 'Habitable', 'm²') ?>
            <?= $numField('📐', 'surface_carrez',    'Carrez',    'm²') ?>
            <?= $numField('🛋️', 'surface_sejour',    'Séjour',    'm²') ?>
            <?= $numField('🏞️', 'surface_totale',    'Totale',    'm²') ?>
            <?= $numField('🌳', 'surface_terrain',   'Terrain',   'm²') ?>
            <?= $numField('↕️', 'hauteur_plafond',   'Plafond',   'm') ?>
          </div>

          <div class="v2-desc-group-title">🌳 Extérieur &amp; dépendances <small>(cliquer pour activer)</small></div>
          <div class="v2-bool-toggles">
            <?= $boolToggle('🏞️', 'balcon',   'Balcon') ?>
            <?= $boolToggle('🌅',  'terrasse', 'Terrasse') ?>
            <?= $boolToggle('🌳',  'jardin',   'Jardin') ?>
            <?= $boolToggle('🏡',  'cour',     'Cour') ?>
            <?= $boolToggle('📦',  'cave',     'Cave') ?>
            <?= $boolToggle('🏚️', 'grenier',  'Grenier') ?>
            <?= $boolToggle('🚗',  'garage',   'Garage') ?>
            <?= $boolToggle('🅿️', 'box',      'Box') ?>
            <?= $boolToggle('🏊',  'piscine',  'Piscine') ?>
          </div>
          <div class="v2-num-grid" style="margin-top:10px;">
            <?= $numField('🅿️', 'parking_nb', 'Parkings') ?>
          </div>

          <div class="v2-desc-group-title">🛋️ Équipements intérieurs <small>(cliquer pour activer)</small></div>
          <div class="v2-bool-toggles">
            <?= $boolToggle('🍳', 'cuisine_equipee', 'Cuisine équ.') ?>
            <?= $boolToggle('🛗', 'ascenseur',       'Ascenseur') ?>
            <?= $boolToggle('📞', 'interphone',      'Interphone') ?>
            <?= $boolToggle('🔐', 'digicode',        'Digicode') ?>
            <?= $boolToggle('🚨', 'alarme',          'Alarme') ?>
            <?= $boolToggle('🌐', 'fibre',           'Fibre') ?>
            <?= $boolToggle('🔥', 'cheminee',        'Cheminée') ?>
            <?= $boolToggle('🪟', 'double_vitrage',  'Double vitrage') ?>
            <?= $boolToggle('🎚️', 'volets_roulants', 'Volets roul.') ?>
          </div>
        </div>
      </section>

      <?php
        // Dictionnaires icon-radios Card 3
        $chauffageTypes = [
          'individuel'    => ['🏠', 'Individuel'],
          'collectif'     => ['🏢', 'Collectif'],
          'electrique'    => ['⚡',  'Électrique'],
          'pompe_chaleur' => ['♻️', 'PAC'],
        ];
        $chauffageEnergies = [
          'gaz'         => ['🔥', 'Gaz'],
          'fioul'       => ['⛽', 'Fioul'],
          'electricite' => ['⚡',  'Électricité'],
          'bois'        => ['🪵', 'Bois'],
          'solaire'     => ['☀️', 'Solaire'],
        ];
        $eauChaudeTypes = [
          'individuelle' => ['🏠', 'Individuelle'],
          'collective'   => ['🏢', 'Collective'],
          'chauffe_eau'  => ['⚡',  'Chauffe-eau'],
          'solaire'      => ['☀️', 'Solaire'],
        ];
        $menuiseriesTypes = [
          'pvc'       => ['🪟', 'PVC'],
          'bois'      => ['🪵', 'Bois'],
          'aluminium' => ['🔩', 'Alu'],
          'mixte'     => ['🔀', 'Mixte'],
        ];
        $isolationTypes = [
          'thermique'           => ['🌡️', 'Thermique'],
          'thermique_phonique'  => ['🔇', 'Therm+Phon'],
          'faible'              => ['⚠️', 'Faible'],
        ];

        $curChType    = (string)($b['chauffage_type']    ?? '');
        $curChEnergy  = (string)($b['chauffage_energie'] ?? '');
        $curEauType   = (string)($b['eau_chaude_type']   ?? '');
        $curMenui     = (string)($b['menuiseries']       ?? '');
        $curIsol      = (string)($b['isolation']         ?? '');

        // Helper : génère un groupe icon-radios avec fond DPE si applicable
        $iconRadios = static function(string $field, array $dict, string $current) use ($syncFlags) {
          $html = '<div class="v2-icon-radios" data-field="' . h($field) . '">';
          foreach ($dict as $code => [$ic, $lbl]) {
            $act = ($current === $code) ? ' is-active' : '';
            $dpe = (isset($syncFlags[$field]) && $current === $code) ? ' is-from-dpe' : '';
            $html .= '<button type="button" class="v2-icon-radio' . $act . $dpe . '" data-value="' . h($code) . '">'
                   . '<span class="v2-icon-emoji">' . $ic . '</span>'
                   . '<span class="v2-icon-lbl">' . h($lbl) . '</span>'
                   . '</button>';
          }
          return $html . '</div>';
        };

        // Helper multi-sélection (vue, nuisances) : valeurs separees par virgule
        $iconMulti = static function(string $field, array $dict, string $currentStr) {
          $selected = array_filter(array_map('trim', explode(',', $currentStr)));
          $html = '<div class="v2-icon-radios" data-field="' . h($field) . '" data-multi="1">';
          foreach ($dict as $code => [$ic, $lbl]) {
            $act = in_array($code, $selected, true) ? ' is-active' : '';
            $html .= '<button type="button" class="v2-icon-radio' . $act . '" data-value="' . h($code) . '">'
                   . '<span class="v2-icon-emoji">' . $ic . '</span>'
                   . '<span class="v2-icon-lbl">' . h($lbl) . '</span>'
                   . '</button>';
          }
          return $html . '</div>';
        };
      ?>

      <!-- Card 3 : Chauffage & Énergie (ÉDITION AUTOSAVE avec icônes) -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Chauffage et énergie">
        <div class="v2-card-label">🔥 Chauffage &amp; Énergie</div>
        <div class="v2-card-body">

          <div class="v2-desc-group-title">🔥 Chauffage</div>
          <div class="v2-icon-row">
            <span class="v2-icon-row-label">Type</span>
            <?= $iconRadios('chauffage_type', $chauffageTypes, $curChType) ?>
          </div>
          <div class="v2-icon-row">
            <span class="v2-icon-row-label">Énergie</span>
            <?= $iconRadios('chauffage_energie', $chauffageEnergies, $curChEnergy) ?>
          </div>
          <div class="v2-bool-toggles" style="margin-top:10px;">
            <?= $boolToggle('🔥', 'chauffage_plancher',    'Plancher chauffant') ?>
            <?= $boolToggle('🌡️', 'chauffage_thermostat',  'Thermostat') ?>
            <?= $boolToggle('⚙️', 'chauffage_regulateur',  'Régulateur') ?>
          </div>

          <div class="v2-desc-group-title">💧 Eau chaude</div>
          <div class="v2-icon-row">
            <span class="v2-icon-row-label">Type</span>
            <?= $iconRadios('eau_chaude_type', $eauChaudeTypes, $curEauType) ?>
          </div>
          <div class="v2-bool-toggles" style="margin-top:10px;">
            <?= $boolToggle('☀️', 'eau_chaude_solaire', 'Solaire') ?>
          </div>

          <div class="v2-desc-group-title">🌬️ VMC, climatisation &amp; isolation</div>
          <div class="v2-bool-toggles">
            <?= $boolToggle('🌬️', 'chauffage_vmc',    'VMC') ?>
            <?= $boolToggle('🔁',  'chauffage_vmc_df', 'VMC double flux') ?>
            <?= $boolToggle('❄️',  'climatisation',    'Climatisation') ?>
          </div>
          <div class="v2-icon-row" style="margin-top:14px;">
            <span class="v2-icon-row-label">Menuiseries</span>
            <?= $iconRadios('menuiseries', $menuiseriesTypes, $curMenui) ?>
          </div>
          <div class="v2-icon-row">
            <span class="v2-icon-row-label">Isolation</span>
            <?= $iconRadios('isolation', $isolationTypes, $curIsol) ?>
          </div>
        </div>
      </section>

      <?php
        // Dictionnaires Card 4
        $expositions = [
          'nord'       => ['⬆️', 'Nord'],
          'est'        => ['➡️', 'Est'],
          'sud'        => ['⬇️', 'Sud'],
          'ouest'      => ['⬅️', 'Ouest'],
          'nord_sud'   => ['↕️', 'N/S'],
          'est_ouest'  => ['↔️', 'E/O'],
          'plein_sud'  => ['☀️', 'Plein Sud'],
          'traversant' => ['🔄', 'Traversant'],
        ];
        $vues = [
          'degagee'   => ['🌅', 'Dégagée'],
          'jardin'    => ['🌳', 'Jardin'],
          'mer'       => ['🌊', 'Mer'],
          'montagne'  => ['⛰️', 'Montagne'],
          'parc'      => ['🌲', 'Parc'],
          'cour'      => ['🏡', 'Cour'],
          'rue'       => ['🛣️', 'Rue'],
          'immeuble'  => ['🏢', 'Immeuble'],
        ];
        $nuisancesOpts = [
          'aucune'     => ['✅', 'Aucune'],
          'route'      => ['🚗', 'Route'],
          'voie_ferree'=> ['🚂', 'Voie ferrée'],
          'aeroport'   => ['✈️', 'Aéroport'],
          'industrie'  => ['🏭', 'Industrie'],
          'nocturne'   => ['🌙', 'Nocturne'],
        ];
        $accesTransports = [
          'moins_5'  => ['⚡', '< 5 min'],
          'moins_10' => ['🚶', '< 10 min'],
          'moins_15' => ['🚶', '< 15 min'],
          'plus_20'  => ['🐌', '> 20 min'],
        ];
        $distanceCommerces = [
          'moins_200'  => ['🏃', '< 200 m'],
          'moins_400'  => ['🚶', '< 400 m'],
          'moins_600'  => ['🚶', '< 600 m'],
          'moins_800'  => ['🚶', '< 800 m'],
          'plus_1200'  => ['🚗', '> 1,2 km'],
        ];

        $curExpo    = (string)($b['exposition']         ?? '');
        $curVue     = (string)($b['vue']                ?? '');
        $curNuis    = (string)($b['nuisances']          ?? '');
        $curTrans   = (string)($b['acces_transports']   ?? '');
        $curCom     = (string)($b['distance_commerces'] ?? '');
      ?>

      <!-- Card 4 : Environnement (ÉDITION AUTOSAVE avec icônes) -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Environnement">
        <div class="v2-card-label">🌳 Environnement</div>
        <div class="v2-card-body">

          <div class="v2-desc-group-title">🧭 Situation</div>
          <div class="v2-bool-toggles">
            <?= $boolToggle('🔝', 'dernier_etage',          'Dernier étage') ?>
            <?= $boolToggle('🚪', 'adresse_visible_public', 'Adresse visible public') ?>
            <?= $boolToggle('🚚', 'acces_camion',           'Accès camion') ?>
          </div>

          <?php if ((int)($b['bien_en_copropriete'] ?? 0) === 1): ?>
          <div class="v2-desc-group-title">🏢 Copropriété <small>(obligation ALUR pour diffusion)</small></div>
          <div class="v2-num-grid">
            <?php // _imm_* : saisis ici pour l'UX (tout au même endroit), mais persistés
                  // dans la table immeubles côté bien_autosave (infos communes à l'immeuble entier). ?>
            <?= $numField('🔢', '_imm_nb_lots',            'Nb lots principaux') ?>
            <?= $numField('💸', 'copro_quote_part_charges', 'Charges annuelles du lot', '€') ?>
          </div>
          <div class="v2-bool-toggles" style="margin-top:8px;">
            <?= $boolToggle('⚠️', '_imm_copro_procedure',                  'Syndic en procédure') ?>
            <?= $boolToggle('🛡️', '_imm_alur_copropriete_plan_sauvegarde', 'Plan de sauvegarde') ?>
            <?= $boolToggle('🚨', '_imm_alur_copropriete_etat_carence',    'État de carence') ?>
          </div>
          <?php endif; ?>

          <div class="v2-desc-group-title">☀️ Exposition</div>
          <?= $iconRadios('exposition', $expositions, $curExpo) ?>

          <div class="v2-desc-group-title">👀 Vue <small>(plusieurs choix possibles)</small></div>
          <?= $iconMulti('vue', $vues, $curVue) ?>

          <div class="v2-desc-group-title">🔊 Nuisances <small>(plusieurs choix possibles)</small></div>
          <?= $iconMulti('nuisances', $nuisancesOpts, $curNuis) ?>

          <div class="v2-desc-group-title">🚉 Accès transports</div>
          <?= $iconRadios('acces_transports', $accesTransports, $curTrans) ?>

          <div class="v2-desc-group-title">🏪 Distance commerces</div>
          <?= $iconRadios('distance_commerces', $distanceCommerces, $curCom) ?>
        </div>
      </section>

      <!-- Card 5 : Photos -->
      <section class="v2-card is-prev" role="tabpanel" aria-label="Photos">
        <div class="v2-card-label">📸 Photos <span class="v2-count" id="v2-photos-count"><?= count($descPhotos) ?></span></div>
        <div class="v2-card-body">
          <?php if (!empty($descPhotos)): ?>
            <div class="v2-photo-grid" id="v2-photo-grid">
              <?php foreach ($descPhotos as $p): ?>
                <?php
                  $photoUrl = $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '';
                  if (!$photoUrl) continue;
                  $photoId   = (int)($p['id'] ?? 0);
                  $photoName = (string)($p['nom_original'] ?? '');
                ?>
                <div class="v2-photo-item" data-photo-id="<?= $photoId ?>" data-photo-url="<?= h($photoUrl) ?>" title="<?= h($photoName) ?>">
                  <img src="<?= h($photoUrl) ?>" alt="<?= h($photoName ?: 'Photo') ?>" loading="lazy">
                  <button type="button" class="v2-photo-delete" aria-label="Supprimer cette photo" title="Supprimer cette photo">✕</button>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">📸</div>
              <div>Aucune photo enregistrée.</div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Lightbox modal (clic image agrandie + ESC/clic backdrop pour fermer) -->
      <div id="v2-photo-lightbox" class="v2-photo-lightbox" hidden role="dialog" aria-modal="true" aria-label="Aperçu photo">
        <button type="button" class="v2-photo-lightbox-close" aria-label="Fermer" title="Fermer (ESC)">✕</button>
        <img id="v2-photo-lightbox-img" src="" alt="">
      </div>
      <style>
        .v2-photo-grid { display:flex; flex-wrap:wrap; gap:8px; }
        .v2-photo-item { position:relative; display:block; width:140px; height:140px; border-radius:8px; overflow:hidden; cursor:zoom-in; background:#f3f4f6; }
        .v2-photo-item img { width:100%; height:100%; object-fit:cover; display:block; }
        .v2-photo-delete {
          position:absolute; top:4px; right:4px; width:32px; height:32px;
          border:none; border-radius:50%; background:rgba(220,38,38,.92); color:#fff;
          font-size:16px; font-weight:700; line-height:1; cursor:pointer;
          display:flex; align-items:center; justify-content:center;
          opacity:0; transition:opacity .15s, transform .15s, background .15s;
          z-index:5; /* au-dessus de l'image pour capter tous les clics dans la zone */
        }
        .v2-photo-item:hover .v2-photo-delete { opacity:1; }
        .v2-photo-delete:hover { background:#b91c1c; transform:scale(1.1); }
        .v2-photo-lightbox {
          position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.85);
          display:flex; align-items:center; justify-content:center; padding:32px;
        }
        .v2-photo-lightbox[hidden] { display:none; }
        .v2-photo-lightbox img { max-width:100%; max-height:100%; border-radius:6px; box-shadow:0 8px 32px rgba(0,0,0,.5); }
        .v2-photo-lightbox-close {
          position:absolute; top:16px; right:16px; width:40px; height:40px;
          border:none; border-radius:50%; background:rgba(255,255,255,.15); color:#fff;
          font-size:20px; cursor:pointer; transition:background .15s;
        }
        .v2-photo-lightbox-close:hover { background:rgba(255,255,255,.25); }
      </style>

    <?php elseif ($section === 'validation'): ?>

      <!-- Card Validation — checklist Ubiflow + gros bouton Valider -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Validation du bien">
        <div class="v2-card-label">✅ Validation du bien</div>
        <div class="v2-card-body">
          <?php if ($bienEstActif): ?>
            <div style="padding:20px; background:#ecfdf5; border:2px solid #10b981; border-radius:12px; text-align:center; margin-bottom:20px;">
              <div style="font-size:36px; margin-bottom:8px;">✅</div>
              <div style="font-size:18px; font-weight:700; color:#065f46;">Bien validé et actif</div>
              <small style="color:#047857; display:block; margin-top:4px;">Tu peux maintenant créer une annonce depuis l'onglet 📡 Annonce.</small>
            </div>
            <div style="background:#fef3c7; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:13px; color:#78350f;">
              ⚠️ <strong>Adresse et propriétaire verrouillés.</strong> Pour modifier ces données critiques, tu dois d'abord dé-valider le bien.
            </div>
            <button type="button" id="v2-bien-invalidate" class="v2-btn-secondary" style="background:#fff; border:1px solid #dc2626; color:#dc2626;">
              🔓 Dé-valider le bien (modifier adresse / propriétaire)
            </button>
            <div id="v2-bien-validate-status" style="margin-top:12px; font-size:13px;"></div>
          <?php else: ?>
            <div style="padding:16px; background:#fefce8; border:1px solid #f59e0b; border-radius:10px; margin-bottom:20px;">
              <strong style="color:#78350f;">📋 Checklist Ubiflow</strong>
              <small style="display:block; color:#92400e; margin-top:4px;">
                Remplis les champs obligatoires pour pouvoir créer une annonce diffusable sur LeBonCoin, SeLoger, etc.
                <?php if ($validationResult['exempt_dpe_surface']): ?>
                  <br>→ Surface et DPE non requis pour ce type de bien (<?= h($validationResult['type_code']) ?>).
                <?php endif; ?>
              </small>
            </div>

            <?php
              // Mapping section logique (validator) → section URL bien_detail.php
              // Permet de transformer chaque ligne en lien cliquable qui navigue vers
              // l'onglet contenant le champ + focus auto via JS (focusField).
              $sectionUrlMap = [
                'identification'   => 'descriptif',
                'localisation'     => 'descriptif',
                'caracteristiques' => 'descriptif',
                'transaction'      => 'annonce',
                'dpe'              => 'diag',
                'alur'             => 'annonce',
                'honoraires'       => 'annonce',
                'photos'           => 'descriptif',
              ];
              // Clés spéciales qui ne sont pas des champs simples (modals/autres) :
              // on garde la navigation vers la bonne section, le focus ne ciblera juste rien.
            ?>
            <div class="v2-validation-checklist" style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
              <?php foreach ($validationResult['checks'] as $c):
                $bg = $c['ok'] ? '#ecfdf5' : ($c['required'] ? '#fef2f2' : '#f8fafc');
                $bd = $c['ok'] ? '#10b981' : ($c['required'] ? '#ef4444' : '#cbd5e1');
                $ic = $c['ok'] ? '✅' : ($c['required'] ? '❌' : '➖');
                $targetSection = $sectionUrlMap[$c['section'] ?? ''] ?? 'descriptif';
                $targetKey     = (string)($c['key'] ?? '');
                $targetUrl     = app_url('/bien_detail.php?edit=' . (int)$editingBienId
                                       . '&section=' . urlencode($targetSection)
                                       . ($targetKey !== '' ? '&focus=' . urlencode($targetKey) : ''));
              ?>
                <a href="<?= h($targetUrl) ?>"
                   style="display:flex; align-items:center; gap:12px; padding:10px 14px; background:<?= $bg ?>; border-left:4px solid <?= $bd ?>; border-radius:6px; text-decoration:none; color:inherit; cursor:pointer; transition:transform .12s, box-shadow .12s;"
                   onmouseover="this.style.transform='translateX(3px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,.08)';"
                   onmouseout="this.style.transform=''; this.style.boxShadow='';"
                   title="Aller au champ <?= h($c['label']) ?>">
                  <span style="font-size:20px;"><?= $ic ?></span>
                  <div style="flex:1;">
                    <div style="font-weight:600; color:#0f172a; font-size:13px;">
                      <?= h($c['label']) ?>
                      <?php if (!$c['required']): ?><small style="color:#64748b; font-weight:400;"> (optionnel)</small><?php endif; ?>
                    </div>
                    <small style="color:#64748b; font-size:11px;"><?= h($c['detail']) ?></small>
                  </div>
                  <?php if (!$c['ok']): ?>
                    <span style="font-size:11px; color:#64748b; font-weight:600;">→</span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:16px; align-items:center;">
              <button type="button" id="v2-bien-validate" class="v2-btn-primary"
                      style="flex:1; padding:18px; font-size:16px; font-weight:700; background:<?= $validationResult['ok'] ? '#16a34a' : '#94a3b8' ?>; border-color:<?= $validationResult['ok'] ? '#16a34a' : '#94a3b8' ?>; cursor:<?= $validationResult['ok'] ? 'pointer' : 'not-allowed' ?>;"
                      <?= $validationResult['ok'] ? '' : 'disabled' ?>>
                ✅ VALIDER LE BIEN &amp; ACTIVER
              </button>
            </div>
            <div id="v2-bien-validate-status" style="margin-top:12px; font-size:13px;"></div>
            <?php if (!$validationResult['ok']): ?>
              <small style="display:block; margin-top:10px; color:#dc2626; font-size:12px;">
                ❌ <?= count($validationResult['missing_required']) ?> champ(s) obligatoire(s) manquant(s).
                Complète-les dans les onglets Documents / DPE / Descriptif.
              </small>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>

    <?php elseif ($section === 'annonce' && !$bienEstActif): ?>

      <!-- Gate : bien non validé → impossible de créer une annonce -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Annonce bloquée">
        <div class="v2-card-label">🔒 Annonce bloquée</div>
        <div class="v2-card-body" style="text-align:center; padding:40px 20px;">
          <div style="font-size:48px; margin-bottom:16px;">🔒</div>
          <h2 style="font-size:20px; color:#0f172a; margin-bottom:12px;">Bien non validé</h2>
          <p style="color:#64748b; max-width:500px; margin:0 auto 24px;">
            Tu dois d'abord valider le bien (remplir les champs obligatoires Ubiflow) avant de pouvoir créer une annonce.
            Ça garantit qu'une fois diffusée sur LeBonCoin / SeLoger, elle ne sera pas rejetée pour données manquantes.
          </p>
          <a href="?edit=<?= (int)$editingBienId ?>&section=validation" class="v2-btn-primary" style="display:inline-block; padding:14px 28px; text-decoration:none;">
            ⚠️ Aller à la validation (<?= $nbManquants ?> champ<?= $nbManquants > 1 ? 's' : '' ?> manquant<?= $nbManquants > 1 ? 's' : '' ?>)
          </a>
        </div>
      </section>

    <?php elseif ($section === 'annonce'): ?>

      <?php
        $a = $annonce ?: [];
        $aid = (int)($annonce['id'] ?? 0);
        $af = static fn($k, $d='') => isset($a[$k]) && $a[$k] !== null && $a[$k] !== '' ? (string)$a[$k] : (string)$d;
        $ab = static fn($k) => (int)($a[$k] ?? 0) === 1;
        // Section annonce a aussi besoin de $b (champs bien) pour la Card Diffusion
        $b = $bienLoaded;
        // Agence de diffusion sélectionnée pour l'annonce (peut différer de l'agence
        // d'origine du bien : permet à un user multi-agences de choisir laquelle diffuse).
        $idAgenceBien = (int)($b['id_agence'] ?? 0);
        $idAgenceAnnonce = (int)($a['id_agence'] ?? 0);
        if ($idAgenceAnnonce <= 0) $idAgenceAnnonce = $idAgenceBien;

        // Compteur "annonces actives diffusées" par flux Ubiflow (limite 15 par flux/agence)
        // Compte les annonces de l'agence sélectionnée actuellement visibles sur portails.
        $UBIFLOW_LIMIT_PER_FLUX = 15;
        $diffuseesAgence = 0;
        if ($idAgenceAnnonce > 0) {
            try {
                $stCnt = $pdo->prepare("
                    SELECT COUNT(*) FROM annonces
                    WHERE id_agence = ?
                      AND visible_portails = 1
                      AND (etat_publication IS NULL OR etat_publication NOT IN ('archive','archivee','supprime'))
                ");
                $stCnt->execute([$idAgenceAnnonce]);
                $diffuseesAgence = (int)$stCnt->fetchColumn();
            } catch (Throwable) { $diffuseesAgence = 0; }
        }
        // Niveau de criticité : OK (< 13), warning (13-14), bloquant (>= 15)
        if ($diffuseesAgence >= $UBIFLOW_LIMIT_PER_FLUX) {
            $diffuseesLvl = 'bad';
        } elseif ($diffuseesAgence >= ($UBIFLOW_LIMIT_PER_FLUX - 2)) {
            $diffuseesLvl = 'warn';
        } else {
            $diffuseesLvl = 'ok';
        }

        // Liste des agences de la société (pour le sélecteur "Agence de diffusion")
        $agencesDiffusion = [];
        $idSocieteBien = (int)($b['id_societe'] ?? ($_SESSION['id_societe'] ?? 0));
        if ($idSocieteBien > 0) {
            try {
                $stAg = $pdo->prepare("SELECT id, COALESCE(NULLIF(nom_agence,''), CONCAT('Agence #', id)) AS label
                    FROM agences WHERE id_societe = ? ORDER BY label ASC");
                $stAg->execute([$idSocieteBien]);
                $agencesDiffusion = $stAg->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) { $agencesDiffusion = []; }
        }

        // Liste des commerciaux : ceux de l'agence de diffusion choisie (et non plus celle du bien)
        $commerciaux = [];
        if ($idAgenceAnnonce > 0) {
            try {
                // Liste TOUS les commerciaux de la société (pas seulement de l'agence sélectionnée).
                // Un user peut être attribué à une annonce d'une agence différente de la sienne
                // (multi-agences au sein d'une même société).
                $stCom = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE id_societe = ? AND (actif = 1 OR actif IS NULL) ORDER BY nom, prenom");
                $stCom->execute([$idSocieteBien]);
                $commerciaux = $stCom->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) { $commerciaux = []; }
        }
        $currentCommercialId = (int)($a['id_user'] ?? 0);
      ?>

      <!-- Card 1 : Conditions financières -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Conditions financières">
        <div class="v2-card-label">💶 Conditions financières</div>
        <div id="v2-annonce-save-indicator" class="v2-save-indicator v2-save-floating" aria-live="polite"></div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <!-- Placeholder : le JS détecte l'absence d'annonce et déclenche
                 le modal "Créer une annonce pour ce bien ?" au chargement
                 de la section. Bouton manuel en fallback si modal fermé. -->
            <div class="v2-doc-empty" style="padding:24px;" id="v2-annonce-empty">
              <div class="v2-doc-empty-icon">📡</div>
              <div style="margin-bottom:14px;">
                Aucune annonce pour ce bien.<br>
                <small>La fenêtre de création va s'ouvrir automatiquement.</small>
              </div>
              <button type="button" id="v2-annonce-create" class="v2-btn-primary">➕ Ouvrir la création d'annonce</button>
              <span id="v2-annonce-create-status" class="v2-form-status" style="margin-left:10px;"></span>
            </div>
          <?php else: ?>
            <div class="v2-annonce-header">
              <span class="v2-badge"><?= h((string)($annonce['etat_publication'] ?? 'brouillon')) ?></span>
              <small>Annonce #<?= $aid ?> · créée le <?= h((string)($annonce['date_creation'] ?? '')) ?></small>
            </div>

            <?php
              // Zone tendue (utilisée pour le pill en haut + section Location plus bas)
              $zoneCur = (string)($b['zone_tendue'] ?? 'non_tendue');
              // Helpers Card 1 Annonce (data-annonce-save au lieu de data-autosave)
              $aNum = static function(string $icon, string $name, string $label, string $suffix = '') use ($a) {
                $val = isset($a[$name]) && $a[$name] !== null && $a[$name] !== '' ? (string)$a[$name] : '';
                return '<div class="v2-num-field">'
                     . '<span class="v2-num-icon">' . $icon . '</span>'
                     . '<input type="number" step="any" min="0" class="v2-num-input" style="width:90px;"'
                     . ' name="' . h($name) . '" data-annonce-save value="' . h($val) . '"'
                     . ' placeholder="' . h($label) . '">'
                     . '<span class="v2-num-label">' . h($label) . ($suffix ? ' <small>' . $suffix . '</small>' : '') . '</span>'
                     . '</div>';
              };
              $aDate = static function(string $icon, string $name, string $label) use ($a) {
                $val = isset($a[$name]) && $a[$name] !== null && $a[$name] !== '' ? (string)$a[$name] : '';
                // Format MySQL DATE → YYYY-MM-DD pour l'input
                if ($val && preg_match('/^(\d{4}-\d{2}-\d{2})/', $val, $m)) $val = $m[1];
                return '<div class="v2-num-field">'
                     . '<span class="v2-num-icon">' . $icon . '</span>'
                     . '<input type="date" class="v2-num-input" style="width:140px;"'
                     . ' name="' . h($name) . '" data-annonce-save value="' . h($val) . '">'
                     . '<span class="v2-num-label">' . h($label) . '</span>'
                     . '</div>';
              };
              $aText = static function(string $name, string $label, string $placeholder = '') use ($a) {
                $val = isset($a[$name]) && $a[$name] !== null ? (string)$a[$name] : '';
                return '<div class="v2-field" style="margin-bottom:8px;">'
                     . '<label style="font-size:11px;font-weight:600;color:var(--v2-muted);">' . h($label) . '</label>'
                     . '<input type="text" class="v2-input" style="margin-top:3px;"'
                     . ' name="' . h($name) . '" data-annonce-save value="' . h($val) . '"'
                     . ($placeholder ? ' placeholder="' . h($placeholder) . '"' : '') . '>'
                     . '</div>';
              };
            ?>

            <!-- Type de transaction (icon-radios avec data-target annonce) -->
            <?php
              $typeTransactions = [
                'vente'      => ['💶', 'Vente'],
                'location'   => ['🔑', 'Location'],
                'saisonnier' => ['🌴', 'Saisonnier'],
                'viager'     => ['⌛', 'Viager'],
              ];
              $curTT = (string)($a['type_transaction'] ?? '');
              // Affichage de la zone honoraires pour vérification visuelle
              $zonePillMap = [
                'non_tendue'  => ['🟢', 'Non tendue',  '8,07 €/m²',  '#ecfdf5', '#065f46'],
                'tendue'      => ['🟠', 'Tendue',      '10,09 €/m²', '#fef3c7', '#78350f'],
                'tres_tendue' => ['🔴', 'Très tendue', '12,10 €/m²', '#fee2e2', '#991b1b'],
              ];
              $zonePill = $zonePillMap[$zoneCur] ?? $zonePillMap['non_tendue'];
            ?>
            <?php $curMeubleTop = (int)($a['meuble'] ?? 0) === 1; ?>
            <div class="v2-desc-group-title">💼 Type de transaction</div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;">
              <div class="v2-icon-radios" data-field="type_transaction" data-target="annonce" style="margin:0;">
                <?php foreach ($typeTransactions as $code => [$ic, $lbl]):
                  $act = ($curTT === $code) ? ' is-active' : '';
                ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span>
                    <span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <!-- Type location : Libre / Meublé (défaut libre, impacte dépôt garantie : 1 mois libre / 2 mois meublé) -->
              <div class="v2-icon-radios" data-field="meuble" data-target="annonce" style="margin:0;" title="Dépôt garantie : 1 mois HC libre / 2 mois HC meublé">
                <button type="button" class="v2-icon-radio<?= $curMeubleTop ? '' : ' is-active' ?>" data-value="0">
                  <span class="v2-icon-emoji">🪑</span>
                  <span class="v2-icon-lbl">Libre</span>
                </button>
                <button type="button" class="v2-icon-radio<?= $curMeubleTop ? ' is-active' : '' ?>" data-value="1">
                  <span class="v2-icon-emoji">🛋️</span>
                  <span class="v2-icon-lbl">Meublé</span>
                </button>
              </div>
              <!-- Pill zone honoraires (vérification visuelle) -->
              <div style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:99px; background:<?= $zonePill[3] ?>; color:<?= $zonePill[4] ?>; font-size:12px; font-weight:600; white-space:nowrap;"
                   title="Plafond honoraires location+bail applicable à ce bien">
                <?= $zonePill[0] ?>
                <span>Zone <?= h($zonePill[1]) ?></span>
                <small style="font-weight:400; opacity:.75;">· plafond <?= h($zonePill[2]) ?></small>
              </div>
            </div>

            <!-- VENTE -->
            <?php
              // Modèle 2026-04-24 : simplification commissions vente
              //   prix_net_vendeur + honoraires (€) ⇄ % (saisie inverse)
              //   prix (FAI) = prix_net_vendeur + honoraires  (calculé, readonly)
              //   toggle Acquéreur / Vendeur (exclusif) → flag honoraires_charge_*
              $curPrixNet    = (float)($a['prix_net_vendeur'] ?? 0);
              $curHono       = (float)($a['honoraires'] ?? 0);
              $curPctAlur    = (float)($a['alur_pourcentage_honoraires_ttc'] ?? 0);
              $curPrixFAI    = (float)($a['prix'] ?? 0);
              if ($curPrixFAI <= 0 && ($curPrixNet > 0 || $curHono > 0)) $curPrixFAI = $curPrixNet + $curHono;
              if ($curPctAlur <= 0 && $curPrixNet > 0 && $curHono > 0) $curPctAlur = round(($curHono / $curPrixNet) * 100, 2);
              $curChargeAcq  = (int)($a['honoraires_charge_acquereur'] ?? 0) === 1;
              $curChargeVen  = (int)($a['honoraires_charge_vendeur'] ?? 0) === 1;
              if (!$curChargeAcq && !$curChargeVen) $curChargeAcq = true; // défaut
              $fmtV = static fn($v) => $v > 0 ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : '';
            ?>
            <div class="v2-desc-group-title">💰 Vente</div>
            <!-- Toggle qui paye les honoraires (exclusif) -->
            <div style="display:flex; justify-content:flex-start; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:10px;">
              <span style="font-size:11px; font-weight:600; color:var(--v2-muted);">Honoraires à la charge :</span>
              <div class="v2-icon-radios" data-field="honoraires_payeur" data-target="vente" style="margin:0;" title="Défini qui supporte juridiquement les honoraires (impacte l'affichage annonce + flux portails)">
                <button type="button" class="v2-icon-radio<?= $curChargeAcq ? ' is-active' : '' ?>" data-value="acquereur">
                  <span class="v2-icon-emoji">🛒</span>
                  <span class="v2-icon-lbl">Acquéreur</span>
                </button>
                <button type="button" class="v2-icon-radio<?= $curChargeVen ? ' is-active' : '' ?>" data-value="vendeur">
                  <span class="v2-icon-emoji">🏷️</span>
                  <span class="v2-icon-lbl">Vendeur</span>
                </button>
              </div>
            </div>
            <div class="v2-loc-grid" id="v2-vente-grid">
              <!-- PRIX NET VENDEUR -->
              <div class="v2-loc-field" title="Montant revenant au vendeur (hors honoraires)">
                <label class="v2-loc-lbl"><span>💰</span> Prix net vendeur <small>€</small></label>
                <input type="number" step="0.01" min="0" class="v2-loc-input"
                       name="prix_net_vendeur" data-annonce-save id="v2-vente-net"
                       value="<?= h($fmtV($curPrixNet)) ?>" placeholder="Net vendeur">
              </div>
              <!-- HONORAIRES € -->
              <div class="v2-loc-field" title="Montant des honoraires en euros. La saisie met à jour automatiquement le %.">
                <label class="v2-loc-lbl"><span>🤝</span> Honoraires <small>€</small></label>
                <input type="number" step="0.01" min="0" class="v2-loc-input"
                       name="honoraires" data-annonce-save id="v2-vente-hono"
                       value="<?= h($fmtV($curHono)) ?>" placeholder="Honoraires">
              </div>
              <!-- HONORAIRES % -->
              <div class="v2-loc-field" title="% honoraires TTC. La saisie met à jour automatiquement les honoraires €.">
                <label class="v2-loc-lbl"><span>⚖️</span> % honoraires <small>%</small></label>
                <input type="number" step="0.01" min="0" max="20" class="v2-loc-input"
                       name="alur_pourcentage_honoraires_ttc" data-annonce-save id="v2-vente-pct"
                       value="<?= h($fmtV($curPctAlur)) ?>" placeholder="%">
              </div>
              <!-- PRIX FAI = net + hono (readonly) -->
              <div class="v2-loc-field is-accent" title="Prix FAI (Frais Agence Inclus) = net vendeur + honoraires. Calculé automatiquement.">
                <label class="v2-loc-lbl"><span>🏷️</span> <strong>Prix FAI</strong> <small>€</small></label>
                <input type="number" step="0.01" class="v2-loc-input is-accent"
                       id="v2-vente-fai" value="<?= h($fmtV($curPrixFAI)) ?>" readonly tabindex="-1">
              </div>
              <!-- SIMULATION RENTABILITÉ (affichage local, pas persisté en BDD)
                   Occupe 2 colonnes à droite du Prix FAI, les 2 inputs empilés
                   avec libellé inline à gauche. Valeurs persistées en localStorage. -->
              <div class="v2-renta-sim" style="grid-column: span 2;" data-bien-id="<?= (int)($b['id'] ?? 0) ?>">
                <div class="v2-renta-head">💡 Simulation rentabilité (non enregistrée)</div>
                <div class="v2-renta-body">
                  <div class="v2-renta-inputs">
                    <div class="v2-renta-row">
                      <label>Loyer <small>€</small></label>
                      <input type="number" step="0.01" min="0" id="v2-renta-loyer" placeholder="0">
                    </div>
                    <div class="v2-renta-row">
                      <label>Charges <small>€</small></label>
                      <input type="number" step="0.01" min="0" id="v2-renta-charges" placeholder="0">
                    </div>
                  </div>
                  <div class="v2-renta-results">
                    <div class="v2-renta-kpi">
                      <div class="v2-renta-kpi-lbl">Brute</div>
                      <div class="v2-renta-kpi-val" id="v2-renta-brute">—</div>
                    </div>
                    <div class="v2-renta-kpi">
                      <div class="v2-renta-kpi-lbl">Nette</div>
                      <div class="v2-renta-kpi-val" id="v2-renta-nette">—</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?= $aText('url_tarifs_publics', 'URL tarifs publics (barème honoraires — obligation arrêté 10/01/2017)', 'https://...') ?>

            <!-- LOCATION -->
            <?php
              // Modèle 2026-04-24 :
              //   loyer_mode ∈ {libre, majore, reference, minore}
              //     - libre     : loyer_HC = saisie manuelle
              //     - majore    : loyer_HC = loyer_reference_majore + SUM(lignes complément)
              //     - reference : loyer_HC = surface × enc_loyer_ref (complément IGNORÉ)
              //     - minore    : loyer_HC = surface × enc_loyer_min (complément IGNORÉ)
              //   loyer_CC = loyer_HC + charges
              //   dépôt    = loyer_HC × (meuble ? 2 : 1) (auto si vide ou si toggle meuble)
              $curLoyerMode    = (string)($a['loyer_mode']      ?? 'libre');
              $curLoyerHC      = (float)($a['loyer']            ?? 0);
              $curMajore       = (float)($a['loyer_reference_majore'] ?? 0);
              $curCharges      = (float)($b['charges_locatives'] ?? 0);
              $curComplement   = (float)($a['complement_loyer'] ?? 0);
              $curDepot        = (float)($a['depot_garantie']   ?? 0);
              $isEncadre       = (int)($a['zone_encadrement_loyer'] ?? 0) === 1;
              $hcReadonly      = $isEncadre && $curLoyerMode !== 'libre';
              $cplIgnored      = $isEncadre && in_array($curLoyerMode, ['reference','minore'], true);
              // Loyer HC de référence (pour affichage & calculs dérivés)
              $loyerHcRef      = $curMajore > 0 ? ($curMajore + $curComplement) : $curLoyerHC;
              // Loyer CC (depuis DB si maintenu, sinon calculé)
              $curLoyerCC      = (float)($a['loyer_cc']         ?? 0);
              if ($curLoyerCC <= 0) $curLoyerCC = $loyerHcRef + $curCharges;
              if ($curDepot <= 0)   $curDepot   = $loyerHcRef;
              $fmt             = static fn($v) => $v > 0 ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : '';
              $curLoyerCCDisp  = $fmt($curLoyerCC);
              $curComplDisp    = $fmt($curComplement);
              $curDepotDisp    = $fmt($curDepot);
              // Honoraires
              $curHonoLoc      = (float)($a['honoraires_location_bail'] ?? 0);
              $curHonoEdl      = (float)($a['honoraires_etat_des_lieux'] ?? 0);
              $curHonoTotal    = $curHonoLoc + $curHonoEdl;
              $curHonoTotalDisp= $fmt($curHonoTotal);
              $zoneCur         = (string)($b['zone_tendue'] ?? 'non_tendue');
              $zoneLblMap      = ['non_tendue'=>'Non tendue','tendue'=>'Tendue','tres_tendue'=>'Très tendue'];
              $zoneLbl         = $zoneLblMap[$zoneCur] ?? 'Non tendue';
              $hasComplement   = $curComplement > 0;
            ?>
            <?php
              // Si l'annonce est assujettie TVA (bail commercial), bascule sur le mode TVA :
              // - Saisies HT (loyer + charges), TVA et CC TTC en readonly
              // - Pas de complément, pas d'encadrement, DG par défaut 3 mois loyer HC HT
              // - Périodicité paiement : mensuel (défaut) ou trimestriel d'avance
              $tvaOn = (int)($a['tva_assujetti'] ?? 0) === 1;
              $curLoyerHcHt   = (float)($a['loyer_hc_ht']  ?? 0);
              $curChargesHt   = (float)($a['charges_ht']   ?? 0);
              $curTvaMontant  = (float)($a['tva_montant']  ?? 0);
              $curPeriodicite = (string)($a['periodicite_loyer'] ?? 'mensuel');
              if ($tvaOn && $curTvaMontant <= 0 && ($curLoyerHcHt > 0 || $curChargesHt > 0)) {
                $curTvaMontant = ($curLoyerHcHt + $curChargesHt) * 0.20;
              }
              $curCcTtc = $tvaOn ? ($curLoyerHcHt + $curChargesHt + $curTvaMontant) : 0;
              if ($tvaOn && $curDepot <= 0 && $curLoyerHcHt > 0) $curDepot = $curLoyerHcHt * 3;
              $curLoyerHcHtDisp = $fmt($curLoyerHcHt);
              $curChargesHtDisp = $fmt($curChargesHt);
              $curTvaMontantDisp = $fmt($curTvaMontant);
              $curCcTtcDisp     = $fmt($curCcTtc);
              $curDepotDisp     = $fmt($curDepot);
            ?>

            <?php if ($tvaOn): ?>
            <!-- ═══ MODE TVA — Bail commercial assujetti 20% ═══ -->
            <div class="v2-desc-group-title">🔑 Location <small style="color:#92400e; font-weight:600;">— Bail commercial assujetti TVA 20%</small></div>
            <div class="v2-loc-grid">
              <!-- LOYER CC TTC (calculé, accent) -->
              <div class="v2-loc-field is-accent" title="Calculé : (Loyer HC HT + Charges HT) × 1.20">
                <label class="v2-loc-lbl"><span>💧</span> <strong>Loyer CC <span style="color:#78350f;">TTC</span></strong> <small>€/mois</small></label>
                <input type="number" step="any" class="v2-loc-input is-accent"
                       id="v2-loyer-cc-display" value="<?= h($curCcTtcDisp) ?>" readonly tabindex="-1">
              </div>
              <!-- LOYER HC HT (saisie principale) -->
              <div class="v2-loc-field" title="Loyer hors charges HT — base de calcul de la TVA">
                <label class="v2-loc-lbl"><span>🔑</span> Loyer HC <strong style="color:#92400e;">HT</strong> <small>€</small></label>
                <input type="number" step="any" min="0" class="v2-loc-input"
                       name="loyer_hc_ht" data-annonce-save id="v2-loyer-hc-ht-input"
                       value="<?= h($curLoyerHcHtDisp) ?>" placeholder="HC HT">
              </div>
              <!-- CHARGES HT -->
              <div class="v2-loc-field" title="Charges HT (ajoutées à la base TVA)">
                <label class="v2-loc-lbl"><span>💡</span> Charges <strong style="color:#92400e;">HT</strong> <small>€</small></label>
                <input type="number" step="any" min="0" class="v2-loc-input"
                       name="charges_ht" data-annonce-save id="v2-charges-ht-input"
                       value="<?= h($curChargesHtDisp) ?>" placeholder="Charges HT">
              </div>
              <!-- TVA GLOBALE (readonly) -->
              <div class="v2-loc-field is-ro" title="Calculée : (HC HT + Charges HT) × 20%">
                <label class="v2-loc-lbl"><span>🧾</span> TVA <small>€ (20%)</small></label>
                <input type="number" step="any" class="v2-loc-input is-ro"
                       id="v2-tva-montant-display" value="<?= h($curTvaMontantDisp) ?>" readonly tabindex="-1">
              </div>
              <!-- DÉPÔT GARANTIE — défaut 3 mois HC HT -->
              <div class="v2-loc-field" title="Par défaut 3 mois de loyer HC HT (norme bail commercial), modifiable">
                <label class="v2-loc-lbl"><span>🔒</span> Dépôt <small>€ (3 mois HC HT)</small></label>
                <input type="number" step="any" min="0" class="v2-loc-input"
                       name="depot_garantie" data-annonce-save id="v2-depot-garantie-input"
                       value="<?= h($curDepotDisp) ?>" placeholder="Dépôt">
              </div>
            </div>
            <!-- PÉRIODICITÉ paiement (mensuel par défaut, trimestriel d'avance courant en commercial) -->
            <div class="v2-icon-row" style="margin-top:10px;" title="Périodicité de règlement du loyer (impacte les modalités contractuelles)">
              <span class="v2-icon-row-label">📅 Règlement</span>
              <div class="v2-icon-radios" data-field="periodicite_loyer" data-target="annonce">
                <button type="button" class="v2-icon-radio<?= $curPeriodicite === 'mensuel' ? ' is-active' : '' ?>" data-value="mensuel">
                  <span class="v2-icon-emoji">📆</span><span class="v2-icon-lbl">Mensuel</span>
                </button>
                <button type="button" class="v2-icon-radio<?= $curPeriodicite === 'trimestriel' ? ' is-active' : '' ?>" data-value="trimestriel">
                  <span class="v2-icon-emoji">🗓️</span><span class="v2-icon-lbl">Trimestre d'avance</span>
                </button>
              </div>
            </div>

            <?php else: ?>
            <!-- ═══ MODE STANDARD — Habitation / Non-TVA ═══ -->
            <div class="v2-desc-group-title">🔑 Location</div>
            <?php $curMeuble = (int)($a['meuble'] ?? 0) === 1; $depMois = $curMeuble ? 2 : 1; ?>
            <div class="v2-loc-grid">
              <!-- LOYER CC — champ calculé readonly (gros) -->
              <div class="v2-loc-field is-accent" title="Calculé : Loyer HC + charges">
                <label class="v2-loc-lbl"><span>💧</span> <strong>Loyer CC</strong> <small>€/mois</small></label>
                <input type="number" step="any" class="v2-loc-input is-accent"
                       id="v2-loyer-cc-display" value="<?= h($curLoyerCCDisp) ?>" readonly tabindex="-1">
              </div>
              <!-- LOYER HC — readonly si zone encadrée (mode majoré/réf/minoré). Mode libre = saisie manuelle. -->
              <div class="v2-loc-field<?= $hcReadonly ? ' is-ro' : '' ?>" title="<?= $hcReadonly ? 'Loyer HC calculé selon mode ' . h($curLoyerMode) . '. Pour modifier : change le mode ou ajuste les compléments (mode majoré uniquement).' : 'Loyer HC hors charges — saisie manuelle libre.' ?>">
                <label class="v2-loc-lbl"><span>🔑</span> Loyer HC <small>€</small></label>
                <input type="number" step="any" min="0" class="v2-loc-input<?= $hcReadonly ? ' is-ro' : '' ?>"
                       name="loyer" data-annonce-save value="<?= h((string)($a['loyer'] ?? '')) ?>"
                       placeholder="Loyer HC"<?= $hcReadonly ? ' readonly tabindex="-1"' : '' ?>>
                <?php if ($isEncadre): ?>
                  <div class="v2-loyer-mode-btns" data-field="loyer_mode" data-target="annonce" role="group" aria-label="Mode de loyer HC">
                    <button type="button" class="v2-mode-btn<?= $curLoyerMode === 'minore'    ? ' is-active' : '' ?>" data-value="minore"    title="Loyer minoré : surface × tarif minoré. Complément INTERDIT.">📉 Min</button>
                    <button type="button" class="v2-mode-btn<?= $curLoyerMode === 'reference' ? ' is-active' : '' ?>" data-value="reference" title="Loyer de référence : surface × tarif de référence. Complément INTERDIT.">📐 Réf</button>
                    <button type="button" class="v2-mode-btn<?= $curLoyerMode === 'majore'    ? ' is-active' : '' ?>" data-value="majore"    title="Loyer majoré + compléments justifiés (défaut encadrement).">📈 Maj</button>
                  </div>
                <?php endif; ?>
              </div>
              <!-- CHARGES — stockées sur biens.charges_locatives -->
              <div class="v2-loc-field">
                <label class="v2-loc-lbl"><span>💡</span> Charges <small>€</small></label>
                <input type="number" step="any" min="0" class="v2-loc-input"
                       name="charges_locatives" data-autosave value="<?= h((string)($b['charges_locatives'] ?? '')) ?>" placeholder="Charges">
              </div>
              <!-- COMPLÉMENT LOYER — readonly, maintenu par les lignes Card 2 -->
              <div class="v2-loc-field is-ro" title="Total des justifications (éditable dans Card Encadrement)">
                <label class="v2-loc-lbl"><span>💳</span> Complément <small>€</small></label>
                <input type="number" step="any" class="v2-loc-input is-ro"
                       id="v2-complement-loyer-display" value="<?= h($curComplDisp) ?>" readonly tabindex="-1">
              </div>
              <!-- DÉPÔT DE GARANTIE — auto = X mois loyer HC (1 libre / 2 meublé), modifiable -->
              <div class="v2-loc-field" title="Par défaut <?= $depMois ?> mois de loyer HC (<?= $curMeuble ? 'meublé' : 'libre' ?>), modifiable">
                <label class="v2-loc-lbl"><span>🔒</span> Dépôt <small>€ (<?= $depMois ?> mois HC)</small></label>
                <input type="number" step="any" min="0" class="v2-loc-input"
                       name="depot_garantie" data-annonce-save id="v2-depot-garantie-input"
                       value="<?= h($curDepotDisp) ?>" placeholder="Dépôt">
              </div>
            </div>
            <?php endif; ?>

            <!-- HONORAIRES LOCATAIRE (ALUR) -->
            <div class="v2-desc-group-title" style="margin-top:14px;">
              📋 Honoraires locataire (ALUR)
              <small style="font-weight:400; color:var(--v2-muted); margin-left:8px;">
                Zone : <strong><?= h($zoneLbl) ?></strong> · plafonds ALUR non dépassables (écrêtage auto si saisie > plafond)
              </small>
            </div>
            <div id="v2-hono-cap-warning" class="v2-form-status" style="display:none; margin-bottom:8px; padding:6px 10px; background:#fef3c7; border:1px solid #f59e0b; border-radius:6px; color:#78350f; font-size:12px;"></div>
            <div class="v2-num-grid">
              <?= $aNum('📝', 'honoraires_location_bail', 'Location + bail', '€') ?>
              <?= $aNum('📑', 'honoraires_etat_des_lieux', 'État des lieux', '€') ?>
              <!-- TOTAL LOCATAIRE — readonly -->
              <div class="v2-num-field" style="background:#fef3c7; border:1px solid #f59e0b;" title="Total honoraires locataire envoyé au flux Ubiflow">
                <span class="v2-num-icon">💰</span>
                <input type="number" step="any" class="v2-num-input" style="width:110px; font-weight:700; color:#78350f;"
                       id="v2-hono-total-display" value="<?= h($curHonoTotalDisp) ?>" readonly tabindex="-1">
                <span class="v2-num-label"><strong>Total locataire</strong> <small>€</small></span>
              </div>
            </div>

            <!-- ANCIEN LOYER (ALUR) -->
            <div class="v2-desc-group-title">📜 Ancien loyer (obligation ALUR)</div>
            <div class="v2-num-grid">
              <?= $aNum('💰', 'ancien_loyer_montant', 'Ancien loyer', '€') ?>
              <?= $aNum('💧', 'ancien_loyer_charges', 'Anc. charges', '€') ?>
            </div>
            <div class="v2-num-grid">
              <?= $aDate('📅', 'ancien_loyer_date_revision',     'Date dernière révision') ?>
              <?= $aDate('🚪', 'ancien_locataire_date_sortie',   'Date sortie locataire') ?>
            </div>

            <!-- TAXES -->
            <div class="v2-desc-group-title">🏛️ Taxes annuelles</div>
            <div class="v2-num-grid">
              <?= $aNum('🏛️', 'taxe_fonciere',          'Taxe foncière (TF)',     '€') ?>
              <?= $aNum('🏡', 'taxe_habitation',        'Taxe habitation',        '€') ?>
              <?= $aNum('🗑️', 'taxe_ordures_menageres', 'TOM (ordures ménagères)', '€') ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 2 : Encadrement des loyers -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Encadrement des loyers">
        <div class="v2-card-label">📋 Encadrement des loyers</div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty"><div class="v2-doc-empty-icon">📋</div><div>Créez d'abord l'annonce dans la Card 1.</div></div>
          <?php else:
            $aZoneEnc  = (int)($a['zone_encadrement_loyer'] ?? 0) === 1;
            $aLoyerCc  = (int)($a['loyer_est_cc'] ?? 0) === 1;
            $encZone   = (string)($bienLoaded['enc_zone']        ?? '');
            $encLRef   = $bienLoaded['enc_loyer_ref']   ?? '';
            $encLMax   = $bienLoaded['enc_loyer_max']   ?? '';
            $encLMin   = $bienLoaded['enc_loyer_min']   ?? '';
            $lBase     = $a['loyer_de_base']          ?? '';
            $lRefMaj   = $a['loyer_reference_majore'] ?? '';
            $modalite  = (string)($a['modalite_recuperation_charges_locatives'] ?? '');
          ?>
            <!-- Bannière auto-remplie par JS -->
            <div id="v2-enc-banner" class="v2-enc-banner" hidden></div>

            <!-- Liens externes -->
            <div class="v2-enc-links">
              <a href="https://data.grandlyon.com/portail/fr/jeux-de-donnees/encadrement-des-loyers-de-la-metropole-de-lyon-2025-2026/info"
                 target="_blank" rel="noopener" class="v2-btn-secondary">🗺️ Carte des zones</a>
              <a href="https://demarches.toodego.com/logement/encadrement-des-loyers-v2/"
                 target="_blank" rel="noopener" class="v2-btn-secondary">🔍 Simulateur Grand Lyon</a>
            </div>

            <!-- Toggles Zone encadrée + Loyer CC -->
            <div class="v2-bool-toggles" style="margin-top:12px;">
              <button type="button" class="v2-bool-toggle<?= $aZoneEnc ? ' is-active' : '' ?>" data-annonce-bool="zone_encadrement_loyer">
                <span class="v2-icon-emoji">📋</span><span class="v2-icon-lbl">Zone encadrée</span>
              </button>
              <button type="button" class="v2-bool-toggle<?= $aLoyerCc ? ' is-active' : '' ?>" data-annonce-bool="loyer_est_cc">
                <span class="v2-icon-emoji">💧</span><span class="v2-icon-lbl">Loyer affiché CC</span>
              </button>
            </div>

            <!-- Zone label (stocké sur biens) -->
            <div class="v2-field" style="margin-top:12px;">
              <label style="font-size:11px;font-weight:600;color:var(--v2-muted);">Zone d'encadrement (label)</label>
              <input type="text" class="v2-input" name="enc_zone" data-autosave
                     value="<?= h($encZone) ?>" placeholder="ex : Lyon 3e, 7e, 8e, 9e, Villeurbanne">
            </div>

            <!-- Tarifs officiels €/m² (biens) -->
            <div class="v2-desc-group-title" style="margin-top:14px;">📐 Tarifs officiels (€/m²)</div>
            <div class="v2-num-grid">
              <div class="v2-num-field">
                <span class="v2-num-icon">📏</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:90px;"
                       name="enc_loyer_ref" data-autosave value="<?= h((string)$encLRef) ?>" placeholder="Référence">
                <span class="v2-num-label">Loyer référence <small>€/m²</small></span>
              </div>
              <div class="v2-num-field">
                <span class="v2-num-icon">📈</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:90px;"
                       name="enc_loyer_max" data-autosave value="<?= h((string)$encLMax) ?>" placeholder="Majoré">
                <span class="v2-num-label">Loyer majoré <small>€/m²</small></span>
              </div>
              <div class="v2-num-field">
                <span class="v2-num-icon">📉</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:90px;"
                       name="enc_loyer_min" data-autosave value="<?= h((string)$encLMin) ?>" placeholder="Minoré">
                <span class="v2-num-label">Loyer minoré <small>€/m²</small></span>
              </div>
            </div>

            <!-- Loyers calculés €/mois (annonce) -->
            <div class="v2-desc-group-title" style="margin-top:14px;">💰 Loyers calculés (€/mois)</div>
            <?php
              $surfBien = (float)($b['surface_habitable'] ?? $b['surface_totale'] ?? 0);
              $encMaxVal = (float)($encLMax ?? 0);
              $majoreAuto = ($surfBien > 0 && $encMaxVal > 0) ? round($surfBien * $encMaxVal, 2) : 0;
            ?>
            <div class="v2-num-grid">
              <div class="v2-num-field">
                <span class="v2-num-icon">📏</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:100px;"
                       name="loyer_de_base" data-annonce-save value="<?= h((string)$lBase) ?>" placeholder="Loyer de base">
                <span class="v2-num-label">Loyer de base <small>€</small></span>
              </div>
              <div class="v2-num-field" title="Auto-calculé : surface × loyer majoré €/m². Modifiable pour forcer une valeur.">
                <span class="v2-num-icon">📈</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:100px;"
                       name="loyer_reference_majore" data-annonce-save id="v2-loyer-majore-input"
                       value="<?= h((string)$lRefMaj) ?>" placeholder="<?= $majoreAuto > 0 ? number_format($majoreAuto, 2, '.', '') : 'Réf. majoré' ?>">
                <span class="v2-num-label">
                  Loyer réf. majoré <small>€</small>
                  <?php if ($majoreAuto > 0): ?>
                    <br><small style="color:#059669;">auto : <?= number_format($majoreAuto, 2, ',', ' ') ?> € (surface × €/m²)</small>
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <!-- Modalité récupération des charges -->
            <div class="v2-field" style="margin-top:12px;">
              <label style="font-size:11px;font-weight:600;color:var(--v2-muted);">Modalité récupération des charges</label>
              <select class="v2-input" name="modalite_recuperation_charges_locatives" data-annonce-save>
                <option value=""                            <?= $modalite === ''                            ? 'selected' : '' ?>>—</option>
                <option value="forfait"                     <?= $modalite === 'forfait'                     ? 'selected' : '' ?>>Forfait</option>
                <option value="provision annuelle"          <?= $modalite === 'provision annuelle'          ? 'selected' : '' ?>>Provision annuelle</option>
                <option value="remboursement sur justificatifs" <?= $modalite === 'remboursement sur justificatifs' ? 'selected' : '' ?>>Remboursement sur justificatifs</option>
              </select>
            </div>

            <!-- Complément de loyer -->
            <?php
              $encMode      = (string)($a['loyer_mode'] ?? 'libre');
              $encCplLocked = $aZoneEnc && in_array($encMode, ['reference','minore'], true);
              $encTotalLignes = array_sum(array_map(static fn($l) => (float)$l['montant'], $cplLignes));
            ?>
            <div class="v2-desc-group-title" style="margin-top:18px;border-top:1px solid var(--v2-stroke,#eee);padding-top:12px;">
              ➕ Complément de loyer (justifications)
            </div>
            <?php if ($encCplLocked): ?>
              <div class="v2-enc-cpl-locked">
                <strong>⚠ Compléments interdits en mode <?= h($encMode) ?></strong> — art. 18 loi 89-462.<br>
                Les lignes ci-dessous sont conservées pour information mais <u>ne sont pas sommées</u> et n'impactent pas le loyer HC.
                Repasse en mode <strong>Majoré</strong> (Card Cond. financières) pour réautoriser.
              </div>
            <?php else: ?>
              <div class="v2-enc-cpl-hint" style="font-size:11px;color:var(--v2-muted);margin-bottom:8px;">
                Total appliqué à <code>complement_loyer</code>. Le loyer total HC = majoré + somme des compléments.
              </div>
            <?php endif; ?>
            <datalist id="v2-cpl-suggestions">
              <option value="Terrasse">
              <option value="Vue dégagée">
              <option value="Stationnement privatif">
              <option value="Balcon">
              <option value="Cave">
              <option value="Double exposition">
              <option value="Dernier étage">
              <option value="Équipements haut de gamme">
            </datalist>
            <div id="v2-cpl-lignes" data-annonce-id="<?= (int)$annonce['id'] ?>" data-locked="<?= $encCplLocked ? '1' : '0' ?>">
              <?php foreach ($cplLignes as $ln): ?>
                <div class="v2-cpl-row<?= $encCplLocked ? ' is-disabled' : '' ?>" data-cpl-id="<?= (int)$ln['id'] ?>">
                  <input type="text" class="v2-input v2-cpl-libelle" list="v2-cpl-suggestions" value="<?= h((string)$ln['libelle']) ?>" placeholder="Ex : vue dégagée sur parc">
                  <input type="number" step="0.01" min="0" class="v2-num-input v2-cpl-montant" value="<?= h((string)$ln['montant']) ?>" placeholder="€">
                  <button type="button" class="v2-cpl-del" title="Supprimer">✕</button>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="v2-cpl-footer">
              <button type="button" id="v2-cpl-add" class="v2-btn-secondary"<?= $encCplLocked ? ' disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>>＋ Ajouter une justification</button>
              <span class="v2-cpl-total">
                <?php if ($encCplLocked): ?>
                  <span style="color:#92400e;">Total ignoré</span> (info : <strong><?= number_format($encTotalLignes, 2, ',', ' ') ?></strong> €)
                <?php else: ?>
                  Total : <strong id="v2-cpl-total"><?= number_format($encTotalLignes, 2, ',', ' ') ?></strong> €
                <?php endif; ?>
              </span>
            </div>
            <div id="v2-cpl-status" class="v2-cpl-status" aria-live="polite"></div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 3 : Annonce (description / détails / titre / SEO) -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Annonce">
        <div class="v2-card-label">📝 Annonce</div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty"><div class="v2-doc-empty-icon">📝</div><div>Créez d'abord l'annonce dans la Card 1.</div></div>
          <?php else: ?>

            <!-- ═══ Bloc Orientation IA (session uniquement, non persisté) ═══ -->
            <details class="v2-ia-orientation" id="v2-ia-orientation">
              <summary>
                <span>✨ Orientation IA</span>
                <small>ton, cible &amp; mots-clés à privilégier — session uniquement</small>
              </summary>
              <div class="v2-ia-orientation-body">
                <div class="v2-field">
                  <label class="v2-field-label">Ton de l'annonce</label>
                  <select class="v2-input" id="v2-ia-ton">
                    <option value="">— Par défaut (professionnel) —</option>
                    <option value="Professionnel et factuel">Professionnel &amp; factuel</option>
                    <option value="Chaleureux et accueillant">Chaleureux &amp; accueillant</option>
                    <option value="Premium / haut de gamme">Premium / haut de gamme</option>
                    <option value="Familial et rassurant">Familial &amp; rassurant</option>
                    <option value="Court et percutant">Court &amp; percutant</option>
                    <option value="Investissement / ROI">Investissement / ROI</option>
                  </select>
                </div>
                <div class="v2-field">
                  <label class="v2-field-label">Cible à adresser</label>
                  <select class="v2-input" id="v2-ia-cible">
                    <option value="">— Aucune cible particulière —</option>
                    <option value="Primo-accédant">Primo-accédant</option>
                    <option value="Investisseur locatif">Investisseur locatif</option>
                    <option value="Famille avec enfants">Famille avec enfants</option>
                    <option value="Jeune couple">Jeune couple</option>
                    <option value="Étudiant">Étudiant</option>
                    <option value="Sénior / retraité">Sénior / retraité</option>
                    <option value="Résidence secondaire">Résidence secondaire</option>
                    <option value="Professionnel / bureaux">Professionnel / bureaux</option>
                  </select>
                </div>
                <div class="v2-field">
                  <label class="v2-field-label">Mots-clés à intégrer <small>(séparés par virgules)</small></label>
                  <input type="text" class="v2-input" id="v2-ia-keywords"
                         placeholder="ex : proche métro, vue dégagée, lumineux, calme">
                </div>
                <div class="v2-field">
                  <label class="v2-field-label">
                    Reprise descriptif <small>(notes brutes à intégrer — <strong>persistées sur le bien</strong>, réutilisées par l'IA)</small>
                  </label>
                  <textarea class="v2-input v2-textarea" id="v2-ia-notes" name="reprise_descriptif" data-autosave rows="3"
                            placeholder="Ex : travaux récents, chaudière neuve 2024, copropriété bien gérée, école primaire à 200 m…"><?= h((string)($bienLoaded['reprise_descriptif'] ?? '')) ?></textarea>
                </div>
              </div>
            </details>

            <!-- ═══ Bouton unique de génération ═══ -->
            <div class="v2-ia-generate-bar">
              <button type="button" class="v2-btn-primary v2-ia-generate-btn" id="v2-ia-generate"
                      title="Rédige description, points forts, titre, accroche, meta + mots-clés + slug — à partir du bien, photos analysées et orientation ci-dessus">
                ✨ Générer l'annonce complète
              </button>
              <span class="v2-form-status" id="v2-ia-generate-status"></span>
            </div>
            <div class="v2-hint" style="margin-bottom:14px;">
              Le bouton régénère <strong>tous les champs ci-dessous</strong> à partir des données du bien, des photos analysées et de l'orientation. Cliquez à nouveau après chaque modification des caractéristiques du bien ou de l'orientation.
            </div>

            <!-- Description -->
            <div class="v2-desc-group-title">📝 Description</div>
            <div class="v2-field">
              <textarea class="v2-input v2-textarea" name="description" id="v2-f-description"
                        data-annonce-save data-min-chars="100" rows="6"
                        placeholder="Rédigez le texte de diffusion (min. 100 caractères requis par Ubiflow)…"><?= h((string)($a['description'] ?? '')) ?></textarea>
              <small class="v2-char-count" data-target="description" data-min="100">0 / 100+ car.</small>
            </div>

            <!-- Détails -->
            <div class="v2-desc-group-title" style="margin-top:14px;">📋 Détails</div>
            <div class="v2-field">
              <label class="v2-field-label">Résumé court</label>
              <textarea class="v2-input v2-textarea" name="resume_court" id="v2-f-resume_court"
                        data-annonce-save rows="2"
                        placeholder="Court résumé pour listings / aperçus…"><?= h((string)($a['resume_court'] ?? '')) ?></textarea>
            </div>
            <div class="v2-field">
              <label class="v2-field-label">Points forts</label>
              <textarea class="v2-input v2-textarea" name="points_forts" id="v2-f-points_forts"
                        data-annonce-save rows="3"
                        placeholder="Un atout par ligne (ex : vue panoramique, parking, …)"><?= h((string)($a['points_forts'] ?? '')) ?></textarea>
            </div>
            <div class="v2-field">
              <label class="v2-field-label">Accroche commerciale</label>
              <input type="text" class="v2-input" name="accroche_commerciale" id="v2-f-accroche_commerciale"
                     data-annonce-save maxlength="255"
                     value="<?= h((string)($a['accroche_commerciale'] ?? '')) ?>"
                     placeholder="Phrase courte d'accroche commerciale…">
            </div>

            <!-- Annonce (titre) -->
            <div class="v2-desc-group-title" style="margin-top:14px;">📣 Annonce</div>
            <div class="v2-field">
              <label class="v2-field-label">Titre de l'annonce <small>(diffusé sur portails)</small></label>
              <input type="text" class="v2-input" name="titre" id="v2-f-titre"
                     data-annonce-save maxlength="255"
                     value="<?= h((string)($a['titre'] ?? '')) ?>"
                     placeholder="Ex : Appartement 3 pièces plein centre avec balcon…">
            </div>

            <!-- SEO -->
            <div class="v2-desc-group-title" style="margin-top:14px;">🔍 SEO</div>
            <div class="v2-field">
              <label class="v2-field-label">Meta title <small>(≈60 car. optimal)</small></label>
              <input type="text" class="v2-input" name="meta_title" id="v2-f-meta_title"
                     data-annonce-save maxlength="255"
                     value="<?= h((string)($a['meta_title'] ?? '')) ?>"
                     placeholder="Titre pour moteurs de recherche…">
              <small class="v2-char-count" data-target="meta_title" data-min="0" data-optimal="60">0 / 60</small>
            </div>
            <div class="v2-field">
              <label class="v2-field-label">Meta description <small>(≈160 car. optimal)</small></label>
              <textarea class="v2-input v2-textarea" name="meta_description" id="v2-f-meta_description"
                        data-annonce-save maxlength="320" rows="2"
                        placeholder="Description pour moteurs…"><?= h((string)($a['meta_description'] ?? '')) ?></textarea>
              <small class="v2-char-count" data-target="meta_description" data-min="0" data-optimal="160">0 / 160</small>
            </div>
            <div class="v2-field">
              <label class="v2-field-label">Mots-clés <small>(séparés par virgules)</small></label>
              <input type="text" class="v2-input" name="mots_cles" id="v2-f-mots_cles"
                     data-annonce-save maxlength="500"
                     value="<?= h((string)($a['mots_cles'] ?? '')) ?>"
                     placeholder="ex : appartement 3 pièces, lyon 6ème, à louer, proche métro">
            </div>
            <div class="v2-field">
              <label class="v2-field-label">URL slug</label>
              <input type="text" class="v2-input" name="slug" id="v2-f-slug"
                     data-annonce-save maxlength="190"
                     value="<?= h((string)($a['slug'] ?? '')) ?>"
                     placeholder="ex : appartement-lyon-3-pieces-balcon">
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 4 : Photos de l'annonce → page dédiée annonce_photos.php -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Photos de l'annonce">
        <div class="v2-card-label">📸 Photos <span class="v2-count" id="v2-annonce-photos-count"><?= count($annoncePhotoIds) ?></span></div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty"><div class="v2-doc-empty-icon">📸</div><div>Créez d'abord l'annonce dans la Card 1.</div></div>
          <?php else:
            $returnQS = '/bien_detail.php?edit=' . (int)$editingBienId . '&section=annonce';
            $apUrl = '/annonce_photos.php?id_annonce=' . (int)$annonce['id'] . '&return=' . urlencode($returnQS);
          ?>
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 20px; gap:18px;">
              <div style="font-size:48px;">📸</div>
              <div style="text-align:center; color:#475569; font-size:14px; max-width:520px;">
                <strong style="color:#0f172a; font-size:16px;"><?= count($annoncePhotoIds) ?> photo(s) actuellement sélectionnée(s)</strong>
                <div style="margin-top:8px;">
                  Ouvre la page de sélection pour ajouter, retirer ou réordonner les photos qui apparaîtront sur l'annonce diffusée.
                </div>
              </div>
              <a href="<?= h(app_url($apUrl)) ?>" class="v2-btn-primary" style="font-size:14px; padding:12px 22px;">
                📸 Sélectionner les photos de l'annonce →
              </a>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 5 : Diffusion (canaux + récap Ubiflow + diffuser) -->
      <section class="v2-card is-prev" role="tabpanel" aria-label="Diffusion">
        <div class="v2-card-label">📡 Diffusion</div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty"><div class="v2-doc-empty-icon">📡</div><div>Créez d'abord l'annonce dans la Card 1.</div></div>
          <?php else:
            // Fallback : ces variables ne sont définies qu'en section=descriptif
            if (!isset($mandats)) {
                $mandats = ['vente'=>['💶','Vente'], 'location'=>['🔑','Location'], 'gestion'=>['🏢','Gestion']];
            }
            if (!isset($curMandat)) {
                $curMandat = (string)($b['type_commercialisation'] ?? '');
            }
            // ── Préparation aperçu : 1ère photo sélectionnée ──
            $previewPhotoUrl = '';
            if (!empty($annoncePhotoIds)) {
                $firstPid = (int)$annoncePhotoIds[0];
                $stPv = $pdo->prepare("SELECT url_photo FROM biens_photos WHERE id = ? LIMIT 1");
                $stPv->execute([$firstPid]);
                $up = (string)$stPv->fetchColumn();
                if ($up) $previewPhotoUrl = app_url('/' . ltrim($up, '/'));
            }
            // Champs annonce
            $aTitre = (string)($annonce['titre'] ?? '');
            $aDesc  = (string)($annonce['description'] ?? '');
            $aPrix  = (float)($annonce['prix'] ?? 0);
            $aLoyer = (float)($annonce['loyer'] ?? 0);
            // Loyer CC (calculé backend) = loyer HC + charges + complément — affiché dans l'aperçu
            $aLoyerCC = (float)($annonce['loyer_cc'] ?? 0);
            if ($aLoyerCC <= 0) $aLoyerCC = $aLoyer + (float)($b['charges_locatives'] ?? 0) + (float)($annonce['complement_loyer'] ?? 0);
            $aTrans = (string)($annonce['type_transaction'] ?? '');
            // Champs bien
            $bSurfH = (float)($b['surface_habitable'] ?? 0);
            $bPieces= (int)($b['nb_pieces'] ?? 0);
            $bChamb = (int)($b['nb_chambres'] ?? 0);
            $bDpe   = (string)($b['dpe_classe'] ?? '');
            $bGes   = (string)($b['ges_classe'] ?? '');
            $bAdrVis= (int)($b['adresse_visible_public'] ?? 0) === 1;
            $bAdr   = trim((string)($b['_imm_adresse_1']   ?? $b['adresse_1']   ?? ''));
            $bCp    = (string)($b['_imm_code_postal'] ?? $b['code_postal'] ?? '');
            $bVille = (string)($b['_imm_ville']       ?? $b['ville']       ?? '');
          ?>

            <!-- Layout 2 colonnes : actions à gauche, aperçu à droite -->
            <div style="display:grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start;">
              <div>
                <!-- 📄 Récap de la diffusion — affiche UNIQUEMENT les valeurs sélectionnées,
                     style v2-icon-radio (boutons), vérification avant diffusion.
                     Le Type de mandat reste éditable via la Card Descriptif. -->
                <?php
                  // Nom de l'immeuble pour le badge cliquable
                  $immeubleNom = '';
                  $immeubleId  = (int)($b['id_immeuble'] ?? 0);
                  if ($immeubleId > 0) {
                      try {
                          $stI = $pdo->prepare("SELECT nom_immeuble FROM immeubles WHERE id = ? LIMIT 1");
                          $stI->execute([$immeubleId]);
                          $immeubleNom = trim((string)$stI->fetchColumn());
                      } catch (Throwable) {}
                      if ($immeubleNom === '') {
                          $immeubleNom = 'Immeuble #' . $immeubleId;
                      }
                  }

                  // Label du type de bien : priorité bien_types (id_bien_type),
                  // fallback base_types_bien (id_type_bien legacy).
                  $typeBienLibelle = '';
                  $typeBienEmoji   = '🏷️';
                  if (!empty($b['id_bien_type'])) {
                      try {
                          $stTb = $pdo->prepare("SELECT libelle FROM bien_types WHERE id = ? LIMIT 1");
                          $stTb->execute([(int)$b['id_bien_type']]);
                          $typeBienLibelle = (string)$stTb->fetchColumn();
                      } catch (Throwable) {}
                  }
                  if ($typeBienLibelle === '' && !empty($b['id_type_bien'])) {
                      try {
                          $stTb = $pdo->prepare("SELECT label FROM base_types_bien WHERE id = ? LIMIT 1");
                          $stTb->execute([(int)$b['id_type_bien']]);
                          $typeBienLibelle = (string)$stTb->fetchColumn();
                      } catch (Throwable) {}
                  }
                  if ($typeBienLibelle === '' && !empty($b['_type_bien_code'])) {
                      $typeBienLibelle = ucfirst(str_replace('_', ' ', (string)$b['_type_bien_code']));
                  }
                  // Emoji par type (aligné avec $typeIcons du descriptif)
                  $typeEmojiMap = [
                      'appartement'=>'🏢','maison'=>'🏠','villa'=>'🏡','terrain'=>'🗺️',
                      'local_commercial'=>'🪟','bureau'=>'💼','immeuble'=>'🏬',
                      'parking'=>'🚗','garage'=>'🚙','entrepot'=>'📦','boutique'=>'🏪',
                      'atelier'=>'🔧','fonds_commerce'=>'☕','programme_neuf'=>'🏗️',
                  ];
                  if (!empty($b['_type_bien_code']) && isset($typeEmojiMap[(string)$b['_type_bien_code']])) {
                      $typeBienEmoji = $typeEmojiMap[(string)$b['_type_bien_code']];
                  }

                  // Prix / Loyer CC selon type de mandat
                  $recapPrixValue = '';
                  $recapPrixEmoji = '💶';
                  if ($curMandat === 'vente') {
                      $prixVal = (float)($a['prix'] ?? 0);
                      if ($prixVal > 0) $recapPrixValue = number_format($prixVal, 0, ',', ' ') . ' €';
                  } elseif (in_array($curMandat, ['location', 'gestion'], true)) {
                      $loyerCc = (float)($a['loyer_cc'] ?? $a['loyer'] ?? 0);
                      if ($loyerCc > 0) $recapPrixValue = number_format($loyerCc, 0, ',', ' ') . ' € CC/mois';
                  }

                  // Surface totale (fallback sur surface_habitable si totale vide)
                  $recapSurface = (float)($b['surface_totale'] ?? 0);
                  if ($recapSurface <= 0) $recapSurface = (float)($b['surface_habitable'] ?? 0);
                  $recapSurfaceStr = $recapSurface > 0 ? rtrim(rtrim(number_format($recapSurface, 2, ',', ' '), '0'), ',') . ' m²' : '';

                  // Honoraires total : cumulés si vente, sinon somme honoraires location + bail + EDL (ALUR)
                  $recapHonorairesStr = '';
                  if ($curMandat === 'vente') {
                      $honoCumul = (float)($a['honoraires_negociation_cumules'] ?? 0);
                      if ($honoCumul <= 0) {
                          $honoCumul = (float)($a['honoraires_charge_acquereur'] ?? 0) + (float)($a['honoraires_charge_vendeur'] ?? 0);
                      }
                      if ($honoCumul > 0) $recapHonorairesStr = number_format($honoCumul, 0, ',', ' ') . ' €';
                  } else {
                      $honoLoc = (float)($a['honoraires_location_bail']  ?? 0)
                               + (float)($a['honoraires_etat_des_lieux'] ?? 0);
                      if ($honoLoc <= 0) $honoLoc = (float)($b['honoraires_locataire'] ?? 0); // fallback legacy
                      if ($honoLoc > 0) $recapHonorairesStr = number_format($honoLoc, 0, ',', ' ') . ' €';
                  }

                  // URL de la fiche immeuble (avec return pour revenir ici)
                  $immeubleReturn = '/bien_detail.php?edit=' . (int)$editingBienId . '&section=annonce';
                  $immeubleUrl    = app_url('/agency_immeuble_fiche.php?id=' . $immeubleId . '&return=' . urlencode($immeubleReturn));
                ?>
                <!-- 🎯 Bloc principal Diffusion : Statut + Agence + Commercial (top de la card, sans séparateurs) -->
                <?php
                  $curStatutBien = (string)($b['statut_bien'] ?? 'brouillon');
                  // Style local pour les mini-labels (pas de border-top contrairement à .v2-desc-group-title)
                  $miniLabelStyle = 'font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#64748b; margin-bottom:6px;';
                  $selectStyle    = 'width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; background:#fff;';
                ?>
                <div style="display:grid; grid-template-columns: 1fr 1.3fr 1.3fr; gap:14px; margin:0 0 16px;">
                  <div>
                    <div style="<?= $miniLabelStyle ?>">📊 Statut du bien</div>
                    <select id="v2-f-statut_bien_top" name="statut_bien" data-autosave
                            style="<?= $selectStyle ?>"
                            title="Modifiable à tout moment — se met à jour automatiquement">
                      <option value="actif"     <?= $curStatutBien === 'actif'     ? 'selected' : '' ?>>✅ Actif</option>
                      <option value="brouillon" <?= $curStatutBien === 'brouillon' ? 'selected' : '' ?>>📝 Brouillon</option>
                      <option value="archive"   <?= $curStatutBien === 'archive'   ? 'selected' : '' ?>>📦 Archivé</option>
                    </select>
                  </div>
                  <div>
                    <div style="<?= $miniLabelStyle ?>">🏬 Agence de diffusion</div>
                    <?php if (empty($agencesDiffusion)): ?>
                      <div style="color:#94a3b8; font-size:12px; font-style:italic; padding:8px 0;">
                        Aucune agence trouvée
                      </div>
                    <?php else: ?>
                      <select id="v2-f-annonce_agence_id" name="annonce_agence_id" data-annonce-save data-reload-after-save="1"
                              style="<?= $selectStyle ?>"
                              title="Agence responsable de la diffusion (peut différer de l'agence du bien)">
                        <option value="">— Aucune (à attribuer) —</option>
                        <?php foreach ($agencesDiffusion as $ag):
                          $aid = (int)$ag['id'];
                          $sel = ($aid === $idAgenceAnnonce) ? ' selected' : '';
                        ?>
                          <option value="<?= $aid ?>"<?= $sel ?>><?= h($ag['label']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if ($idAgenceAnnonce > 0):
                        $diffBadgeColors = [
                          'ok'   => ['#dcfce7', '#166534', '#bbf7d0'],
                          'warn' => ['#fef3c7', '#92400e', '#fde68a'],
                          'bad'  => ['#fee2e2', '#991b1b', '#fecaca'],
                        ];
                        [$bgC, $fgC, $brdC] = $diffBadgeColors[$diffuseesLvl];
                      ?>
                        <div style="margin-top:6px; padding:5px 9px; background:<?= $bgC ?>; color:<?= $fgC ?>; border:1px solid <?= $brdC ?>; border-radius:6px; font-size:11px; font-weight:700; text-align:center;"
                             title="Limite Ubiflow : 15 annonces actives par flux. Au-delà, les nouvelles annonces ne seront pas diffusées.">
                          📡 <?= (int)$diffuseesAgence ?> / <?= $UBIFLOW_LIMIT_PER_FLUX ?> diffusées
                          <?php if ($diffuseesLvl === 'bad'): ?>
                            <span style="font-weight:400;">— quota atteint</span>
                          <?php elseif ($diffuseesLvl === 'warn'): ?>
                            <span style="font-weight:400;">— bientôt plein</span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div style="<?= $miniLabelStyle ?>">👤 Commercial attribué</div>
                    <?php if (empty($commerciaux)): ?>
                      <div style="color:#94a3b8; font-size:12px; font-style:italic; padding:8px 0;">
                        Aucun commercial pour cette agence
                      </div>
                    <?php else: ?>
                      <select id="v2-f-annonce_commercial_id" name="annonce_commercial_id" data-annonce-save
                              style="<?= $selectStyle ?>">
                        <option value="">— Aucun (à attribuer) —</option>
                        <?php foreach ($commerciaux as $com):
                          $cid = (int)$com['id'];
                          $sel = ($cid === $currentCommercialId) ? ' selected' : '';
                          $lbl = trim((string)$com['prenom'] . ' ' . (string)$com['nom']);
                        ?>
                          <option value="<?= $cid ?>"<?= $sel ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </div>
                </div>

                <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#64748b; margin:0 0 8px;">📋 Récap de la diffusion <small style="font-weight:400; text-transform:none; letter-spacing:0;">— cliquez sur un badge pour modifier le champ</small></div>
                <?php
                  // URLs de navigation vers le champ éditable
                  // → Type de transaction (Vente/Location) : c'est l'ANNONCE qui porte ça, pas le bien.
                  //   Le bien reste en "gestion" par défaut, c'est l'annonce qui définit Vente vs Location.
                  //   Card visée : "Conditions financières" (section=annonce) qui contient le sélecteur type_transaction.
                  $urlMandat   = app_url('/bien_detail.php?edit=' . (int)$editingBienId . '&section=annonce&focus=type_transaction');
                  // → Type de bien : section descriptif, focus type_bien (champ bien)
                  $urlTypeBien = app_url('/bien_detail.php?edit=' . (int)$editingBienId . '&section=descriptif&focus=type_bien');
                  // → Prix / Loyer : annonce, focus prix ou loyer
                  $prixFieldKey = ($curMandat === 'vente') ? 'prix' : 'loyer';
                  $urlPrix     = app_url('/bien_detail.php?edit=' . (int)$editingBienId . '&section=annonce&focus=' . $prixFieldKey);
                  // → Surface : descriptif, focus surface_habitable (champ bien)
                  $urlSurface  = app_url('/bien_detail.php?edit=' . (int)$editingBienId . '&section=descriptif&focus=surface_habitable');
                  // → Honoraires : annonce, focus honoraires
                  $urlHonos    = app_url('/bien_detail.php?edit=' . (int)$editingBienId . '&section=annonce&focus=honoraires');
                ?>
                <div class="v2-icon-radios"
                     style="display:flex; flex-wrap:wrap; gap:8px; justify-content:space-between; align-items:center; margin-bottom:18px;">
                  <?php if (!empty($curMandat) && isset($mandats[$curMandat])): ?>
                    <a href="<?= h($urlMandat) ?>" class="v2-icon-radio is-active" style="text-decoration:none; color:#fff; cursor:pointer;" title="Modifier le mandat — Caractéristiques">
                      <span class="v2-icon-emoji"><?= $mandats[$curMandat][0] ?></span>
                      <span class="v2-icon-lbl"><?= h($mandats[$curMandat][1]) ?></span>
                    </a>
                  <?php endif; ?>
                  <?php if ($typeBienLibelle !== ''): ?>
                    <a href="<?= h($urlTypeBien) ?>" class="v2-icon-radio is-active" style="text-decoration:none; color:#fff; cursor:pointer;" title="Modifier le type de bien — Caractéristiques">
                      <span class="v2-icon-emoji"><?= $typeBienEmoji ?></span>
                      <span class="v2-icon-lbl"><?= h($typeBienLibelle) ?></span>
                    </a>
                  <?php endif; ?>
                  <?php if ($recapPrixValue !== ''): ?>
                    <a href="<?= h($urlPrix) ?>" class="v2-icon-radio is-active" style="text-decoration:none; color:#fff; cursor:pointer;" title="<?= $curMandat === 'vente' ? 'Modifier le prix — Conditions financières' : 'Modifier le loyer — Conditions financières' ?>">
                      <span class="v2-icon-emoji"><?= $recapPrixEmoji ?></span>
                      <span class="v2-icon-lbl"><?= h($recapPrixValue) ?></span>
                    </a>
                  <?php endif; ?>
                  <?php if ($recapSurfaceStr !== ''): ?>
                    <a href="<?= h($urlSurface) ?>" class="v2-icon-radio is-active" style="text-decoration:none; color:#fff; cursor:pointer;" title="Modifier la surface — Pièces &amp; Surfaces">
                      <span class="v2-icon-emoji">📐</span>
                      <span class="v2-icon-lbl"><?= h($recapSurfaceStr) ?></span>
                    </a>
                  <?php endif; ?>
                  <?php if ($recapHonorairesStr !== ''): ?>
                    <a href="<?= h($urlHonos) ?>" class="v2-icon-radio is-active" style="text-decoration:none; color:#fff; cursor:pointer;" title="Modifier les honoraires — Conditions financières">
                      <span class="v2-icon-emoji">💰</span>
                      <span class="v2-icon-lbl"><?= h($recapHonorairesStr) ?></span>
                    </a>
                  <?php endif; ?>
                </div>

              <!-- Suite colonne gauche : canaux + complétude + diffuser -->
              <div class="v2-desc-group-title">📢 Canaux de diffusion</div>
            <div class="v2-chan-grid">
              <button type="button" class="v2-chan-card v2-chan-mbi<?= $ab('visible_maboximmo')  ? ' is-selected' : '' ?>"
                      data-annonce-bool="visible_maboximmo">
                <span class="v2-chan-icon">🏢</span>
                <span class="v2-chan-title">MaBoxImmo</span>
                <span class="v2-chan-sub">Annuaire interne</span>
              </button>
              <button type="button" class="v2-chan-card v2-chan-web<?= $ab('visible_site_perso') ? ' is-selected' : '' ?>"
                      data-annonce-bool="visible_site_perso">
                <span class="v2-chan-icon">🌐</span>
                <span class="v2-chan-title">Site perso</span>
                <span class="v2-chan-sub">Site de l'agence</span>
              </button>
              <button type="button" class="v2-chan-card v2-chan-lbc<?= $ab('visible_portails')   ? ' is-selected' : '' ?>"
                      data-annonce-bool="visible_portails">
                <span class="v2-chan-icon">📰</span>
                <span class="v2-chan-title">LeBonCoin</span>
                <span class="v2-chan-sub">Via passerelle</span>
              </button>
            </div>

            <!-- Récapitulatif de complétude Ubiflow -->
            <?php
              // Mapping key → section v2
              $FIELD_TO_V2 = [
                'reference_bien' => 'descriptif', 'designation' => 'descriptif',
                'description' => 'annonce', 'meta_title' => 'annonce', 'meta_description' => 'annonce',
                'titre' => 'annonce', 'accroche_commerciale' => 'annonce',
                'code_postal' => 'descriptif', 'ville' => 'descriptif',
                'adresse_1' => 'descriptif', 'id_type_bien' => 'descriptif',
                'surface_habitable' => 'descriptif', 'nb_pieces' => 'descriptif',
                'annee_construction' => 'descriptif', 'nb_wc' => 'descriptif',
                'copro_nb_lots' => 'descriptif', 'copro_quote_part_charges' => 'descriptif',
                'type_transaction' => 'annonce', 'prix' => 'annonce', 'loyer' => 'annonce',
                'alur_pourcentage_honoraires_ttc' => 'annonce', 'url_tarifs_publics' => 'annonce',
                'dpe_classe' => 'dpe', 'ges_classe' => 'dpe', 'dpe_valeur' => 'dpe',
                'ges_valeur' => 'dpe', 'dpe_date_realisation' => 'dpe',
                '_photos_count' => 'documents',
              ];
              $missing = $ubiCheck['missing'] ?? [];
              $scorePctLocal = (int)($ubiCheck['score'] ?? 0);
              $scoreCls = $scorePctLocal >= 100 ? 'ok' : ($scorePctLocal >= 70 ? 'warn' : 'bad');
              $canDiffuse = empty(array_filter($missing, static fn($m) => !empty($m['blocking'])));
            ?>
            <div class="v2-desc-group-title" style="margin-top:16px;">✅ Complétude Ubiflow</div>
            <div class="v2-ubi-gauge <?= $scoreCls ?>">
              <div class="v2-ubi-gauge-bar" style="--pct:<?= $scorePctLocal ?>%"></div>
              <span class="v2-ubi-gauge-label"><?= $scorePctLocal ?>%
                · <?= count($missing) ?> champ<?= count($missing) > 1 ? 's' : '' ?> manquant<?= count($missing) > 1 ? 's' : '' ?></span>
            </div>

            <div class="v2-ubi-recap">
              <?php if (empty($missing)): ?>
                <div class="v2-ubi-ok">✅ Tous les champs Ubiflow sont complets — prêt à diffuser.</div>
              <?php else: ?>
                <?php foreach ($missing as $m):
                  $key     = (string)($m['key'] ?? '');
                  $label   = (string)($m['label'] ?? $key);
                  $secVal  = (string)($m['section'] ?? '');
                  $block   = !empty($m['blocking']);
                  $v2Sec   = $FIELD_TO_V2[$key] ?? 'descriptif';
                ?>
                  <div class="v2-ubi-item <?= $block ? 'is-blocking' : 'is-warning' ?>"
                       data-field="<?= h($key) ?>"
                       data-v2-section="<?= h($v2Sec) ?>"
                       tabindex="0" role="button">
                    <span class="v2-ubi-icon"><?= $block ? '❌' : '⚠️' ?></span>
                    <div class="v2-ubi-main">
                      <div class="v2-ubi-label"><?= h($label) ?></div>
                      <div class="v2-ubi-sec"><?= h($secVal) ?> · <em><?= h($v2Sec) ?></em></div>
                    </div>
                    <span class="v2-ubi-arrow">→</span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <!-- Bouton Diffuser -->
            <div class="v2-diffuse-actions">
              <button type="button" id="v2-diffuse-btn" class="v2-btn-primary v2-btn-diffuse"
                      <?= $canDiffuse ? '' : 'disabled' ?>
                      title="<?= $canDiffuse ? 'Lancer la diffusion sur les canaux activés' : 'Résoudre d\'abord les champs bloquants' ?>">
                🚀 Diffuser maintenant
              </button>
              <span id="v2-diffuse-status" class="v2-form-status" aria-live="polite"></span>
            </div>
              </div><!-- /col gauche -->

              <!-- 👁️ Aperçu de l'annonce diffusée (colonne droite, sticky) -->
              <div>
                <div style="position: sticky; top: 90px;">
                  <div class="v2-desc-group-title" style="margin-top:0;">👁️ Aperçu</div>
                  <div style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; background:#fff; box-shadow:0 1px 3px rgba(15,23,42,0.06);">
                    <div style="position:relative; aspect-ratio: 4/3; background:#f1f5f9;">
                      <?php if ($previewPhotoUrl): ?>
                        <img src="<?= h($previewPhotoUrl) ?>" alt="Photo principale" style="width:100%; height:100%; object-fit:cover; display:block;">
                      <?php else: ?>
                        <div style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; font-size:32px;">
                          📷<small style="font-size:11px; margin-top:4px;">Aucune photo</small>
                        </div>
                      <?php endif; ?>
                      <?php if ($aTrans): ?>
                        <span style="position:absolute; top:8px; left:8px; background:#0ea5e9; color:#fff; font-size:10px; font-weight:700; padding:3px 9px; border-radius:99px; text-transform:uppercase; letter-spacing:.04em;"><?= h($aTrans) ?></span>
                      <?php endif; ?>
                    </div>
                    <div style="padding:12px 14px;">
                      <div style="font-size:18px; font-weight:700; color:#0f172a; margin-bottom:4px;">
                        <?php if ($aTrans === 'vente' && $aPrix > 0): ?>
                          <?= number_format($aPrix, 0, ',', ' ') ?> €
                        <?php elseif ($aTrans === 'location' && $aLoyerCC > 0): ?>
                          <?= number_format($aLoyerCC, 0, ',', ' ') ?> €<small style="font-weight:400; color:#64748b;"> CC/mois</small>
                        <?php elseif ($aTrans === 'location' && $aLoyer > 0): ?>
                          <?= number_format($aLoyer, 0, ',', ' ') ?> €<small style="font-weight:400; color:#64748b;"> HC/mois</small>
                        <?php else: ?>
                          <span style="color:#94a3b8; font-size:14px;">Prix à définir</span>
                        <?php endif; ?>
                      </div>
                      <div style="font-size:13px; font-weight:600; color:#0f172a; margin-bottom:6px; line-height:1.3;"><?= h($aTitre ?: '(Titre à compléter)') ?></div>
                      <div style="font-size:11px; color:#64748b; margin-bottom:8px; display:flex; gap:8px; flex-wrap:wrap;">
                        <?php if ($bSurfH > 0): ?><span>📐 <?= number_format($bSurfH, 1, ',', ' ') ?> m²</span><?php endif; ?>
                        <?php if ($bPieces > 0): ?><span>🚪 <?= $bPieces ?>p</span><?php endif; ?>
                        <?php if ($bChamb > 0): ?><span>🛏️ <?= $bChamb ?>ch</span><?php endif; ?>
                        <?php if ($bDpe): ?><span style="background:#f1f5f9; padding:1px 5px; border-radius:3px; font-weight:600;">DPE <?= h($bDpe) ?></span><?php endif; ?>
                        <?php if ($bGes): ?><span style="background:#f1f5f9; padding:1px 5px; border-radius:3px; font-weight:600;">GES <?= h($bGes) ?></span><?php endif; ?>
                      </div>
                      <div style="font-size:11px; color:#475569; margin-bottom:8px;">
                        📍
                        <?php if ($bAdrVis && $bAdr): ?>
                          <?= h($bAdr) ?>, <?= h(trim($bCp . ' ' . $bVille)) ?>
                        <?php else: ?>
                          <em><?= h(trim($bCp . ' ' . $bVille) ?: 'Adresse à compléter') ?></em>
                        <?php endif; ?>
                      </div>
                      <div onclick="if(window.__v2FocusField){window.__v2FocusField('description');}"
                           style="font-size:11px; color:#334155; line-height:1.4; cursor:pointer; padding:4px; border-radius:4px; transition:background .15s;"
                           onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"
                           title="Cliquer pour éditer la description">
                        <?php if ($aDesc !== ''): ?>
                          <?= nl2br(h((string)$aDesc)) ?>
                        <?php else: ?>
                          <em style="color:#94a3b8;">📝 Description à compléter — clique pour la rédiger</em>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div><!-- /col droite -->
            </div><!-- /grid 2 cols -->
          <?php endif; ?>
        </div>
      </section>

      <!-- Ma Box Communication (Lot 6 — include isolé) -->
      <?php @include __DIR__ . '/inc/mbi_supports_card_bien.php'; ?>

      <!-- Card 6 : Historique des annonces du bien -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Historique des annonces">
        <div class="v2-card-label">📜 Historique <span class="v2-count"><?= count($annoncesHistorique) ?></span></div>
        <div class="v2-card-body">
          <?php if (empty($annoncesHistorique)): ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">📜</div>
              <div>Aucune annonce pour ce bien.</div>
            </div>
          <?php else: ?>
            <div class="v2-hint" style="padding:6px 10px;margin-bottom:10px;">
              Toutes les annonces successives du bien. La plus récente (◉) est celle éditée dans les autres cards.
              Les annonces <strong>archivées</strong> restent consultables pour conserver l'historique (vente 2024 aboutie, location 2026, etc.).
            </div>
            <div class="v2-histo-wrap">
              <table class="v2-histo-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Créée</th>
                    <th>Transaction</th>
                    <th>État</th>
                    <th>Montant</th>
                    <th style="text-align:center;">Photos</th>
                    <th>Titre</th>
                    <th>Publiée</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $currentAnnonceId = (int)($annonce['id'] ?? 0);
                    $labelTrans = [
                      'vente' => '💶 Vente', 'location' => '🔑 Location',
                      'saisonnier' => '🌴 Saison.', 'viager' => '⌛ Viager',
                    ];
                    $labelEtat = [
                      'brouillon' => ['📝', 'Brouillon', 'is-draft'],
                      'diffusee'  => ['🚀', 'Diffusée',  'is-live'],
                      'archivee'  => ['📦', 'Archivée',  'is-archived'],
                    ];
                    foreach ($annoncesHistorique as $ah):
                      $aid    = (int)$ah['id'];
                      $isCurr = $aid === $currentAnnonceId;
                      $trans  = (string)($ah['type_transaction'] ?? '');
                      $etat   = (string)($ah['etat_publication'] ?? 'brouillon');
                      [$eIc, $eLbl, $eCls] = $labelEtat[$etat] ?? ['❓', $etat, ''];
                      $montant = '';
                      if ($trans === 'vente' && !empty($ah['prix'])) {
                        $montant = number_format((float)$ah['prix'], 0, ',', ' ') . ' €';
                      } elseif (!empty($ah['loyer_cc'])) {
                        $montant = number_format((float)$ah['loyer_cc'], 0, ',', ' ') . ' € CC/mois';
                      } elseif (!empty($ah['loyer'])) {
                        $montant = number_format((float)$ah['loyer'], 0, ',', ' ') . ' € HC/mois';
                      }
                      $dc = $ah['date_creation']    ? date('d/m/Y', strtotime((string)$ah['date_creation'])) : '—';
                      $dp = $ah['date_publication'] ? date('d/m/Y', strtotime((string)$ah['date_publication'])) : '—';
                  ?>
                    <tr class="v2-histo-row <?= $eCls ?><?= $isCurr ? ' is-current' : '' ?>">
                      <td class="v2-histo-id">
                        <?php if ($isCurr): ?><span class="v2-histo-current-dot" title="Annonce active">◉</span><?php endif; ?>
                        #<?= $aid ?>
                      </td>
                      <td><?= h($dc) ?></td>
                      <td><?= $trans !== '' ? h($labelTrans[$trans] ?? $trans) : '<span style="color:var(--v2-muted);">—</span>' ?></td>
                      <td><span class="v2-histo-badge <?= $eCls ?>"><?= $eIc ?> <?= h($eLbl) ?></span></td>
                      <td><?= $montant !== '' ? h($montant) : '<span style="color:var(--v2-muted);">—</span>' ?></td>
                      <td style="text-align:center;">
                        <span class="v2-histo-photos<?= (int)$ah['nb_photos'] > 0 ? '' : ' is-zero' ?>">
                          📸 <?= (int)$ah['nb_photos'] ?>
                        </span>
                      </td>
                      <td class="v2-histo-titre" title="<?= h((string)($ah['titre'] ?? '')) ?>">
                        <?= h((string)($ah['titre'] ?? '')) ?: '<span style="color:var(--v2-muted);">—</span>' ?>
                      </td>
                      <td><?= h($dp) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

    <?php else: /* section = dpe */ ?>

      <!-- Card 1 : DPE (visu) -->
      <section class="v2-card is-active" role="tabpanel" aria-label="DPE">
        <div class="v2-card-label">⚡ DPE</div>
        <div class="v2-card-body">

          <?php if ($dpeDiag && (!empty($dpeDiag['diagnostiqueur_nom']) || !empty($dpeDiag['diagnostiqueur_societe']) || !empty($dpeDiag['numero_rapport']))): ?>
            <div class="v2-dpe-diag-header">
              <div class="v2-dpe-diag-label">📄 Diagnostiqueur</div>
              <div class="v2-dpe-diag-info">
                <?php if (!empty($dpeDiag['diagnostiqueur_nom'])): ?>
                  <strong><?= h((string)$dpeDiag['diagnostiqueur_nom']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($dpeDiag['diagnostiqueur_societe'])): ?>
                  <span class="v2-dpe-diag-societe">· <?= h((string)$dpeDiag['diagnostiqueur_societe']) ?></span>
                <?php endif; ?>
                <?php if (!empty($dpeDiag['numero_rapport'])): ?>
                  <span class="v2-dpe-diag-meta">· N° rapport : <?= h((string)$dpeDiag['numero_rapport']) ?></span>
                <?php endif; ?>
                <?php if (!empty($dpeDiag['numero_ademe'])): ?>
                  <span class="v2-dpe-diag-meta">· ADEME : <?= h((string)$dpeDiag['numero_ademe']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="v2-dpe-visu">
            <div class="v2-dpe-col">
              <div class="v2-dpe-col-title">⚡ Énergie (kWh/m²/an)</div>
              <?php
                $currentDpe = strtoupper((string)($bienLoaded['dpe_classe'] ?? ''));
                foreach ($dpeColors as $l => $color):
                  $active = ($currentDpe === $l);
              ?>
                <div class="v2-dpe-bar <?= $active ? 'is-active' : '' ?>">
                  <div class="v2-dpe-letter" style="--w:<?= 40 + (ord($l) - 65) * 22 ?>px;background:<?= $color ?>;opacity:<?= $active ? 1 : 0.35 ?>;"><?= $l ?></div>
                  <?php if ($active): ?><span class="v2-dpe-arrow" style="color:<?= $color ?>;">◀</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <div class="v2-dpe-value">
                <?php if (!empty($bienLoaded['dpe_valeur'])): ?>
                  <strong><?= h((string)$bienLoaded['dpe_valeur']) ?></strong> kWh/m²/an
                <?php else: ?><em>Valeur non renseignée</em><?php endif; ?>
              </div>
            </div>

            <div class="v2-dpe-col">
              <div class="v2-dpe-col-title">🌍 GES (kgCO₂/m²/an)</div>
              <?php
                $currentGes = strtoupper((string)($bienLoaded['ges_classe'] ?? ''));
                foreach ($gesColors as $l => $color):
                  $active = ($currentGes === $l);
                  $txt = in_array($l, ['A','B']) ? '#7c3aed' : '#fff';
              ?>
                <div class="v2-dpe-bar <?= $active ? 'is-active' : '' ?>">
                  <div class="v2-dpe-letter" style="--w:<?= 40 + (ord($l) - 65) * 22 ?>px;background:<?= $color ?>;color:<?= $txt ?>;opacity:<?= $active ? 1 : 0.35 ?>;"><?= $l ?></div>
                  <?php if ($active): ?><span class="v2-dpe-arrow" style="color:<?= $color ?>;">◀</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <div class="v2-dpe-value">
                <?php if (!empty($bienLoaded['ges_valeur'])): ?>
                  <strong><?= h((string)$bienLoaded['ges_valeur']) ?></strong> kgCO₂/m²/an
                <?php else: ?><em>Valeur non renseignée</em><?php endif; ?>
              </div>
            </div>

            <div class="v2-dpe-meta">
              <div><span class="v2-meta-k">Date réalisation</span><span class="v2-meta-v"><?= h((string)($bienLoaded['dpe_date_realisation'] ?? '—')) ?></span></div>
              <div><span class="v2-meta-k">Version DPE</span><span class="v2-meta-v"><?= h((string)($bienLoaded['dpe_version'] ?? '—')) ?></span></div>
              <div><span class="v2-meta-k">N° certificat</span><span class="v2-meta-v"><?= h((string)($bienLoaded['dpe_reference_certificat'] ?? '—')) ?></span></div>
              <div><span class="v2-meta-k">DPE vierge</span><span class="v2-meta-v"><?= (int)($bienLoaded['dpe_vierge'] ?? 0) === 1 ? 'Oui' : 'Non' ?></span></div>
              <div><span class="v2-meta-k">Dépenses min</span><span class="v2-meta-v"><?= !empty($bienLoaded['montant_estime_depenses_min']) ? number_format((float)$bienLoaded['montant_estime_depenses_min'], 0, ',', ' ') . ' €' : '—' ?></span></div>
              <div><span class="v2-meta-k">Dépenses max</span><span class="v2-meta-v"><?= !empty($bienLoaded['montant_estime_depenses_max']) ? number_format((float)$bienLoaded['montant_estime_depenses_max'], 0, ',', ' ') . ' €' : '—' ?></span></div>
            </div>
          </div>
        </div>
      </section>

      <!-- Card 2 : ERP / Géorisques (visu) -->
      <section class="v2-card is-next" role="tabpanel" aria-label="ERP Géorisques">
        <div class="v2-card-label">🌍 ERP / Géorisques</div>
        <div class="v2-card-body">
          <div class="v2-kv-grid">
            <div class="v2-kv">
              <div class="v2-kv-k">Mention Géorisques</div>
              <div class="v2-kv-v <?= (int)($bienLoaded['zone_georisque'] ?? 0) === 1 ? 'bad' : 'ok' ?>">
                <?= (int)($bienLoaded['zone_georisque'] ?? 0) === 1 ? '⚠️ Zone à risques' : '✅ Hors zone' ?>
              </div>
            </div>
            <div class="v2-kv">
              <div class="v2-kv-k">Obligation débroussaillement</div>
              <div class="v2-kv-v"><?= (int)($bienLoaded['obligation_debroussaillement'] ?? 0) === 1 ? '🔥 Oui' : 'Non' ?></div>
            </div>
            <div class="v2-kv">
              <div class="v2-kv-k">Date ERP</div>
              <div class="v2-kv-v"><?= h((string)($bienLoaded['erp_date_realisation'] ?? '—')) ?></div>
            </div>
            <div class="v2-kv">
              <div class="v2-kv-k">Zone sismicité</div>
              <div class="v2-kv-v"><?= h((string)($dpeDiag['sismicite_zone'] ?? '—')) ?></div>
            </div>
            <div class="v2-kv">
              <div class="v2-kv-k">Zone inondation</div>
              <div class="v2-kv-v"><?= (int)($dpeDiag['alerte_inondation'] ?? 0) === 1 ? '💧 Oui' : 'Non' ?></div>
            </div>
          </div>
          <p class="v2-card-note">📌 Obligations légales : Mention Géorisques depuis 01/01/2023 · Débroussaillement renforcé 2025.</p>
        </div>
      </section>

      <!-- Card 3 : Données extraites (visu tableau) -->
      <?php
        // Score d'extraction recalculé en LIVE = % de colonnes whitelistées remplies
        // (au lieu de dpe_diags.extraction_score figé à l'upload, qui restait
        //  désaligné du compteur "Champs à compléter" → 100% / 17 à compléter)
        $dpeFieldsTotal  = count($dpeFieldDefs);
        $dpeFieldsFilled = $dpeFieldsTotal - count($dpeMissingFields);
        $dpeScoreLive    = $dpeFieldsTotal > 0
            ? (int)round(100 * $dpeFieldsFilled / $dpeFieldsTotal)
            : 0;
      ?>
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Données extraites du DPE">
        <div class="v2-card-label">📊 Données extraites
          <?php if ($dpeDiag): ?>
            <span class="v2-count"><?= $dpeScoreLive ?>%</span>
          <?php endif; ?>
        </div>
        <div class="v2-card-body">
          <?php if ($dpeDiag): ?>
            <table class="v2-extract-table">
              <thead>
                <tr><th>Champ</th><th>Valeur extraite</th></tr>
              </thead>
              <tbody>
                <?php foreach ($dpeFieldDefs as $col => $def):
                  $v = $dpeDiag[$col] ?? null;
                  if ($v === null || $v === '') continue;
                ?>
                  <tr>
                    <td><?= h($def['label']) ?></td>
                    <td><?= h((string)$v) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">📊</div>
              <div>Aucune analyse DPE disponible.<br><small>Uploadez un PDF DPE dans la section Documents pour déclencher l'extraction.</small></div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 4 : Champs à compléter (édition + split PDF) -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Champs à compléter">
        <div class="v2-card-label">✍️ Champs à compléter
          <span class="v2-count" id="v2-count-missing"><?= count($dpeMissingFields) ?></span>
        </div>
        <div class="v2-card-body v2-split">
          <div class="v2-split-left">
            <?php if ($lastDpePdf): ?>
              <iframe src="<?= h($lastDpePdf['url_fichier']) ?>#navpanes=0&toolbar=1&view=FitH" title="<?= h((string)$lastDpePdf['nom_original']) ?>" loading="lazy"></iframe>
              <a href="<?= h($lastDpePdf['url_fichier']) ?>" target="_blank" rel="noopener" class="v2-pdf-open-btn" title="Ouvrir dans un nouvel onglet">↗</a>
            <?php else: ?>
              <div class="v2-doc-empty">
                <div class="v2-doc-empty-icon">📎</div>
                <div>Aucun PDF DPE chargé<br>
                  <a href="?edit=<?= (int)$editingBienId ?>&section=documents" class="v2-btn-outline" style="margin-top:12px;display:inline-block;">📎 Charger un PDF</a>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <div class="v2-split-right">
            <form id="v2-missing-form" class="v2-form" data-diag-id="<?= (int)($dpeDiag['id'] ?? 0) ?>" data-bien-id="<?= (int)$editingBienId ?>">
              <?php if (empty($dpeMissingFields)): ?>
                <div class="v2-doc-empty">
                  <div class="v2-doc-empty-icon">🎉</div>
                  <div>Tous les champs sont renseignés !</div>
                </div>
              <?php else: ?>
                <?php foreach ($dpeMissingFields as $col => $def): ?>
                  <div class="v2-field">
                    <label for="v2-f-<?= h($col) ?>"><?= h($def['label']) ?></label>
                    <?php if ($def['type'] === 'bool'): ?>
                      <select id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input">
                        <option value="">—</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                      </select>
                    <?php elseif ($def['type'] === 'class'): ?>
                      <select id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input">
                        <option value="">—</option>
                        <?php foreach (['A','B','C','D','E','F','G'] as $l): ?>
                          <option value="<?= $l ?>"><?= $l ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($def['type'] === 'dpe_version'): ?>
                      <select id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input">
                        <option value="">—</option>
                        <option value="2011">2011 (avant réforme du 1ᵉʳ juillet 2021)</option>
                        <option value="2021">2021 (méthode actuelle)</option>
                      </select>
                    <?php elseif ($def['type'] === 'date'): ?>
                      <input type="date" id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input">
                    <?php elseif ($def['type'] === 'number'): ?>
                      <input type="number" step="0.01" id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input">
                    <?php elseif ($def['type'] === 'textarea'): ?>
                      <textarea id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input" rows="3"></textarea>
                    <?php else: ?>
                      <input type="text" id="v2-f-<?= h($col) ?>" name="<?= h($col) ?>" class="v2-input">
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <div class="v2-form-actions">
                  <button type="submit" class="v2-btn-primary">💾 Enregistrer</button>
                  <span id="v2-missing-status" class="v2-form-status"></span>
                </div>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </section>

      <!-- Card 5 : Analyse du rapport + Alertes -->
      <section class="v2-card is-prev" role="tabpanel" aria-label="Analyse du rapport et alertes">
        <div class="v2-card-label">🧠 Analyse &amp; ⚠️ Alertes</div>
        <div class="v2-card-body">
          <?php if (!empty($dpeDiag['resume_bailleur']) || !empty($dpeDiag['commentaire'])): ?>
            <?php if (!empty($dpeDiag['resume_bailleur'])): ?>
              <div class="v2-analysis-block">
                <div class="v2-analysis-title">📝 Résumé pour le bailleur</div>
                <div class="v2-analysis-body"><?= nl2br(h((string)$dpeDiag['resume_bailleur'])) ?></div>
              </div>
            <?php endif; ?>
            <?php if (!empty($dpeDiag['commentaire'])): ?>
              <div class="v2-analysis-block">
                <div class="v2-analysis-title">💬 Commentaire</div>
                <div class="v2-analysis-body"><?= nl2br(h((string)$dpeDiag['commentaire'])) ?></div>
              </div>
            <?php endif; ?>
          <?php elseif (!$dpeDiag): ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">🧠</div>
              <div>Aucune analyse disponible.<br><small>L'analyse IA est générée automatiquement lors de l'upload d'un PDF DPE.</small></div>
            </div>
          <?php endif; ?>

          <?php if ($dpeDiag): ?>
            <div class="v2-alerts-section">
              <div class="v2-alerts-title">⚠️ Alertes sur diagnostics</div>
              <div class="v2-alerts-grid">
                <?php
                  $alerts = [
                    ['k' => 'alerte_plomb_present',     'label' => 'Plomb',         'icon' => '🧪', 'detail' => $dpeDiag['alerte_plomb_classe_max'] ?? null],
                    ['k' => 'alerte_amiante_present',   'label' => 'Amiante',       'icon' => '🧱'],
                    ['k' => 'alerte_electricite_anomalies', 'label' => 'Électricité','icon' => '⚡', 'freeText' => true],
                    ['k' => 'alerte_gaz_anomalies',     'label' => 'Gaz',           'icon' => '🔥', 'freeText' => true],
                    ['k' => 'alerte_termites',          'label' => 'Termites',      'icon' => '🐜'],
                    ['k' => 'alerte_zone_georisque',    'label' => 'Géorisque',     'icon' => '🌍'],
                    ['k' => 'alerte_inondation',        'label' => 'Inondation',    'icon' => '💧'],
                  ];
                  foreach ($alerts as $a):
                    $val = $dpeDiag[$a['k']] ?? null;
                    $freeText = !empty($a['freeText']);
                    if ($freeText) {
                        $state = (!empty($val) && $val !== '0' && $val !== 'aucune' && $val !== 'Aucune') ? 'bad' : 'ok';
                        $txt = $state === 'bad' ? h((string)$val) : 'Aucune anomalie';
                    } else {
                        $state = (int)$val === 1 ? 'bad' : 'ok';
                        $txt = $state === 'bad' ? '⚠️ Signalé' : '✅ Aucun';
                        if ($state === 'bad' && !empty($a['detail'])) $txt .= ' (' . h((string)$a['detail']) . ')';
                    }
                ?>
                  <div class="v2-alert-card <?= $state ?>">
                    <div class="v2-alert-icon"><?= $a['icon'] ?></div>
                    <div class="v2-alert-label"><?= h($a['label']) ?></div>
                    <div class="v2-alert-state"><?= $txt ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (!empty($dpeDiag['sismicite_zone'])): ?>
                <div class="v2-alert-sismic">🌋 Zone de sismicité : <strong><?= h((string)$dpeDiag['sismicite_zone']) ?></strong></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

    <?php endif; ?>

    </div>

    <div id="v2-dots" class="v2-dots" role="tablist" aria-label="Sélection de carte"></div>
  </div>

</main>

<!-- Données JS -->
<script>
  window.__v2DocsData = {
    bienId: <?= (int)$editingBienId ?>,
    section: <?= json_encode($section) ?>,
    csrfToken: <?= json_encode($csrfTokenVal, JSON_UNESCAPED_SLASHES) ?>,
    proprioFicheUrl: <?= json_encode(app_url('/agency_proprietaire_fiche.php'), JSON_UNESCAPED_SLASHES) ?>,
    bienDetailUrl:   <?= json_encode(app_url('/bien_detail.php'),                JSON_UNESCAPED_SLASHES) ?>,
    uploadEndpoint:      <?= json_encode(app_url('/api/bien_intake_upload.php'),       JSON_UNESCAPED_SLASHES) ?>,
    photoUploadEndpoint: <?= json_encode(app_url('/api/bien_intake_photo_upload.php'), JSON_UNESCAPED_SLASHES) ?>,
    updateEndpoint:      <?= json_encode(app_url('/api/dpe_diag_update.php'),          JSON_UNESCAPED_SLASHES) ?>,
    autosaveEndpoint:    <?= json_encode(app_url('/api/bien_autosave.php'),            JSON_UNESCAPED_SLASHES) ?>,
    tiersLookupEndpoint: <?= json_encode(app_url('/api/tiers_lookup.php'),             JSON_UNESCAPED_SLASHES) ?>,
    tiersCreateEndpoint: <?= json_encode(app_url('/api/tiers_create.php'),             JSON_UNESCAPED_SLASHES) ?>,
    bienProprioLinkEndpoint: <?= json_encode(app_url('/api/bien_proprio_link.php'),    JSON_UNESCAPED_SLASHES) ?>,
    photoDeleteEndpoint: <?= json_encode(app_url('/api/bien_photo_delete.php'),        JSON_UNESCAPED_SLASHES) ?>,
    docDeleteEndpoint:    <?= json_encode(app_url('/api/biens_documents_delete.php'),    JSON_UNESCAPED_SLASHES) ?>,
    docReanalyzeEndpoint: <?= json_encode(app_url('/api/biens_documents_reanalyze.php'), JSON_UNESCAPED_SLASHES) ?>,
    docExtractViewEndpoint: <?= json_encode(app_url('/api/biens_documents_extract_view.php'), JSON_UNESCAPED_SLASHES) ?>,
    annonceCreateEndpoint:       <?= json_encode(app_url('/api/annonce_create.php'),        JSON_UNESCAPED_SLASHES) ?>,
    annonceAutosaveEndpoint:     <?= json_encode(app_url('/api/annonce_autosave.php'),      JSON_UNESCAPED_SLASHES) ?>,
    annoncePhotoToggleEndpoint:  <?= json_encode(app_url('/api/annonce_photo_toggle.php'),  JSON_UNESCAPED_SLASHES) ?>,
    annoncePhotosBulkEndpoint:   <?= json_encode(app_url('/api/annonce_photos_bulk.php'),   JSON_UNESCAPED_SLASHES) ?>,
    annoncePhotoReorderEndpoint: <?= json_encode(app_url('/api/annonce_photo_reorder.php'), JSON_UNESCAPED_SLASHES) ?>,
    annonceDiffuserEndpoint:     <?= json_encode(app_url('/api/annonce_diffuser.php'),     JSON_UNESCAPED_SLASHES) ?>,
    encadrementEndpoint:         <?= json_encode(app_url('/api/encadrement_loyers.php'),    JSON_UNESCAPED_SLASHES) ?>,
    cplAddEndpoint:              <?= json_encode(app_url('/api/annonce_cpl_add.php'),       JSON_UNESCAPED_SLASHES) ?>,
    cplUpdateEndpoint:           <?= json_encode(app_url('/api/annonce_cpl_update.php'),    JSON_UNESCAPED_SLASHES) ?>,
    cplDeleteEndpoint:           <?= json_encode(app_url('/api/annonce_cpl_delete.php'),    JSON_UNESCAPED_SLASHES) ?>,
    aiGenerateEndpoint:          <?= json_encode(app_url('/api/bien_ai_generate.php'),      JSON_UNESCAPED_SLASHES) ?>,
    aiCsrfToken:                 <?= json_encode(csrf_token('ajouter_bien'),                JSON_UNESCAPED_SLASHES) ?>,
    bienEncContext: {
      code_postal: <?= json_encode((string)($bienLoaded['_imm_code_postal'] ?? $bienLoaded['code_postal'] ?? ''), JSON_UNESCAPED_SLASHES) ?>,
      ville:       <?= json_encode((string)($bienLoaded['_imm_ville']       ?? $bienLoaded['ville']       ?? ''), JSON_UNESCAPED_SLASHES) ?>,
      latitude:    <?= json_encode((string)($bienLoaded['_imm_latitude']    ?? ''), JSON_UNESCAPED_SLASHES) ?>,
      longitude:   <?= json_encode((string)($bienLoaded['_imm_longitude']   ?? ''), JSON_UNESCAPED_SLASHES) ?>,
      annee_construction: <?= (int)($bienLoaded['annee_construction'] ?? 0) ?>,
      nb_pieces:  <?= (int)($bienLoaded['nb_pieces']          ?? 0) ?>,
      surface:    <?= json_encode((float)($bienLoaded['surface_habitable']  ?? 0)) ?>,
      meuble:     <?= (int)(!empty($bienLoaded['loyer_meuble']) ? 1 : 0) ?>,
    },
    annonceId: <?= (int)($annonce['id'] ?? 0) ?>,
    // Flow 2026-04-22 : statut bien + endpoint de validation
    statutBien:         <?= json_encode($statutBien, JSON_UNESCAPED_SLASHES) ?>,
    bienEstActif:       <?= $bienEstActif ? 'true' : 'false' ?>,
    nbManquants:        <?= (int)$nbManquants ?>,
    bienValidateEndpoint: <?= json_encode(app_url('/api/bien_validate.php'), JSON_UNESCAPED_SLASHES) ?>,
    docsDiag:   <?= json_encode($docsDiag,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsMandat: <?= json_encode($docsMandat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsAutre:  <?= json_encode($docsAutre,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    immeubles:  <?= json_encode($immeublesList, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  };
</script>
<script src="<?= asset_url('/assets/js/document_uploader.js') ?>"></script>
<script src="<?= asset_url('/js/bien_detail_v2.js') ?>?v=<?= @filemtime(__DIR__ . '/js/bien_detail_v2.js') ?: time() ?>"></script>

<!-- Modal universel d'adresse (Google Places + immeubles existants) -->
<?php require_once __DIR__ . '/inc/adresse_modal.php'; ?>
<?php if (!empty($_GET['open_immeuble'])): ?>
<script>
// Création depuis le patrimoine bailleur : on ouvre directement le modal immeuble/adresse
// (rattachement obligatoire). L'utilisateur recherche un immeuble existant ou en crée un.
(function(){
  function openImm(){
    var btn = document.querySelector('[data-addr-modal-open]');
    if (btn) {
      if (window.__addrModal && typeof window.__addrModal.open === 'function') window.__addrModal.open(btn);
      else btn.click();
    } else { setTimeout(openImm, 200); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ setTimeout(openImm, 250); });
  else setTimeout(openImm, 250);
})();
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL "Créer une annonce ?" — déclenché à l'entrée section=annonce
     si aucune annonce active (flow 2026-04-22)
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="v2-annonce-create-modal" class="addr-modal" hidden>
  <div class="addr-modal-overlay" data-annonce-modal-close></div>
  <div class="addr-modal-card" style="max-width:520px;">
    <div class="addr-modal-head">
      <h3>📡 Créer une annonce pour ce bien ?</h3>
      <button type="button" class="addr-modal-x" data-annonce-modal-close aria-label="Fermer">×</button>
    </div>
    <div class="addr-modal-body">
      <div style="text-align:center; padding:14px 0;">
        <div style="font-size:40px; margin-bottom:12px;">📡</div>
        <p style="color:#334155; font-size:14px; line-height:1.5;">
          Une nouvelle annonce va être créée pour ce bien, en héritant automatiquement :
        </p>
        <ul style="text-align:left; max-width:380px; margin:12px auto; color:#475569; font-size:13px; line-height:1.8;">
          <li>🏢 Adresse &amp; immeuble</li>
          <li>🏛️ Société, agence, commercial</li>
          <li>👤 Propriétaire rattaché</li>
          <li>📸 Toutes les photos actuelles du bien</li>
        </ul>
        <p style="color:#64748b; font-size:12px;">Tu pourras ensuite renseigner le type de mandat (vente / location / gestion), les montants, honoraires, etc.</p>
      </div>
    </div>
    <div class="addr-modal-foot">
      <button type="button" class="addr-modal-btn-secondary" data-annonce-modal-close>Annuler</button>
      <button type="button" id="v2-annonce-modal-confirm" class="addr-modal-btn-primary">✅ Créer l'annonce</button>
    </div>
  </div>
</div>
<script src="<?= asset_url('/js/places.js') ?>"></script>
<script src="<?= asset_url('/js/adresse_modal.js') ?>?v=<?= @filemtime(__DIR__ . '/js/adresse_modal.js') ?: time() ?>"></script>
<?php if (!empty($GOOGLE_MAPS_API_KEY)): ?>
<script async
        src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>

<?php
// STANDARD MBI : modal iframe de recherche/création d'immeuble (toute adresse = immeuble).
require_once __DIR__ . '/inc/immeuble_recherche_mbi.php';
immeuble_mbi_render();
immeuble_mbi_assets();
?>
<script>
// Retour direct au dossier de vente (quand le bien a été créé depuis un dossier).
(function(){
  var p = new URLSearchParams(location.search);
  var dossier = p.get('return_dossier');
  if(!dossier || !/^\d+$/.test(dossier)) return;
  var a = document.createElement('a');
  a.href = <?= json_encode(app_url('/transaction_dossier.php?id=')) ?> + dossier;
  a.textContent = '← Retour au dossier de vente';
  a.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:9999;'
    + 'background:linear-gradient(135deg,#0f6cbd,#0c5aa0);color:#fff;font-weight:800;font-size:14px;'
    + 'padding:12px 22px;border-radius:30px;text-decoration:none;box-shadow:0 8px 24px rgba(15,108,189,.4);';
  document.addEventListener('DOMContentLoaded', function(){ document.body.appendChild(a); });
})();
function bdOpenImmeubleModal(btn){
  if(!window.ImmeubleRechercheMBI){ alert('Composant immeuble non chargé.'); return; }
  var bienId = btn.getAttribute('data-bien-id');
  var url    = btn.getAttribute('data-autosave-url');
  var csrf   = btn.getAttribute('data-csrf');
  window.ImmeubleRechercheMBI.open(function(imm){
    var s = function(id,v){ var e=document.getElementById(id); if(e){ e.value = v||''; e.dispatchEvent(new Event('input',{bubbles:true})); } };
    s('v2-f-adresse_1', imm.adresse_1);
    s('v2-f-code_postal', imm.code_postal);
    s('v2-f-ville', imm.ville);
    // Rattache l'immeuble + recopie l'adresse sur le bien via l'autosave existant.
    if(bienId && url){
      var fd = new FormData();
      fd.append('_edit_id', bienId);
      if(csrf) fd.append('csrf_token', csrf);
      fd.append('id_immeuble_selected', imm.id);
      fd.append('adresse_1', imm.adresse_1||'');
      fd.append('code_postal', imm.code_postal||'');
      fd.append('ville', imm.ville||'');
      fetch(url, {method:'POST', credentials:'same-origin', body:fd}).catch(function(){});
    }
  });
}
</script>
</body>
</html>
