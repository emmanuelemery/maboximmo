<?php
// bien_detail_v2.php
// ─────────────────────────────────────────────────────────────────────────
// Refonte bien_detail en carousel de cards animées (style Apple).
// Sections disponibles (nav d'onglets dans page-head) :
//   ?section=documents → 4 cards (Chargement / Diag / Mandats / Autres)
//   ?section=dpe       → 6 cards (DPE / ERP / Extraits / À compléter / Analyse / Alertes)
//
// Layout : sidebar + topbar (score % à droite) + page-head (onglets) +
// carousel plein-écran (80% × 80% desktop, responsive mobile).
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
if ($editingBienId <= 0) {
    header('Location: ' . app_url('/bien_liste.php?err=bien_introuvable'));
    exit;
}

$idSociete = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$bienLoaded = bien_form_load_record($pdo, $editingBienId, $idSociete);
if ($bienLoaded === null) {
    header('Location: ' . app_url('/bien_liste.php?err=bien_introuvable'));
    exit;
}

// Section courante
$sectionsAvail = ['documents', 'dpe', 'descriptif', 'annonce'];
$section = $_GET['section'] ?? 'documents';
if (!in_array($section, $sectionsAvail, true)) $section = 'documents';

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
        $st = $pdo->prepare("SELECT id, url_photo, nom_original, largeur, hauteur FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC LIMIT 100");
        $st->execute([$editingBienId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $docsPhotos[] = [
                'id'           => (int)$p['id'],
                'url'          => $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '',
                'nom_original' => (string)($p['nom_original'] ?? ''),
                'largeur'      => (int)($p['largeur'] ?? 0),
                'hauteur'      => (int)($p['hauteur'] ?? 0),
            ];
        }
    } catch (Throwable $e) {}
}

$docsDiag = [];
$docsMandat = [];
$docsAutre = [];
$lastDpePdf = null;
try {
    $st = $pdo->prepare("
        SELECT id, type_document, nom_original, url_fichier, taille_octets,
               date_document, date_upload
        FROM biens_documents
        WHERE id_bien = ?
        ORDER BY id DESC
    ");
    $st->execute([$editingBienId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $t = (string)($d['type_document'] ?? 'autre');
        $row = [
            'id'             => (int)$d['id'],
            'type_document'  => $t,
            'nom_original'   => (string)($d['nom_original'] ?? ''),
            'url_fichier'    => $d['url_fichier'] ? app_url('/' . ltrim((string)$d['url_fichier'], '/')) : '',
            'taille_octets'  => (int)($d['taille_octets'] ?? 0),
            'date_document'  => $d['date_document'] ?? null,
            'date_upload'    => $d['date_upload'] ?? null,
        ];
        if (in_array($t, $diagTypes, true)) {
            $docsDiag[] = $row;
            // Recherche du dernier PDF DPE/diag pour la card 4 (split view)
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
    error_log('[bien_detail_v2] biens_documents: ' . $e->getMessage());
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
            $st2 = $pdo->prepare("SELECT id_biens_photo FROM annonces_photos WHERE id_annonce = ?");
            $st2->execute([(int)$annonce['id']]);
            $annoncePhotoIds = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN) ?: []);

            // Lignes complément de loyer
            $stCpl = $pdo->prepare("SELECT id, libelle, montant, ordre FROM annonces_complement_loyer_lignes WHERE id_annonce = ? ORDER BY ordre ASC, id ASC");
            $stCpl->execute([(int)$annonce['id']]);
            $cplLignes = $stCpl->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        // Toutes les photos du bien (pour la grille de sélection)
        $st3 = $pdo->prepare("SELECT id, url_photo, nom_original FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
        $st3->execute([$editingBienId]);
        foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $annonceBienPhotos[] = [
                'id'  => (int)$p['id'],
                'url' => $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '',
                'nom' => (string)($p['nom_original'] ?? ''),
            ];
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
    try {
        $st = $pdo->query("SELECT id, code, label FROM base_types_bien ORDER BY ordre_defaut ASC, label ASC");
        $typesBienList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Filtrer 'loft' + renommer 'fonds_commerce' en 'Commerce'
        $typesBienList = array_values(array_filter($typesBienList, static fn($t) => ($t['code'] ?? '') !== 'loft'));
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
    if (!empty($bienLoaded['id_type_bien'])) {
        try {
            $st = $pdo->prepare("SELECT label FROM base_types_bien WHERE id = ? LIMIT 1");
            $st->execute([(int)$bienLoaded['id_type_bien']]);
            $typeBienLabel = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    if (!empty($bienLoaded['id_proprietaire'])) {
        // Tentative tiers (personne physique OU morale via raison_sociale) puis fallback users legacy
        try {
            $st = $pdo->prepare("SELECT id, type_tiers, civilite, nom, prenom, raison_sociale, telephone, email FROM tiers WHERE id = ? LIMIT 1");
            $st->execute([(int)$bienLoaded['id_proprietaire']]);
            $proprioInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
        if (!$proprioInfo) {
            try {
                $st = $pdo->prepare("SELECT id, nom, prenom, telephone, email FROM users WHERE id = ? LIMIT 1");
                $st->execute([(int)$bienLoaded['id_proprietaire']]);
                $proprioInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }
    }
    // Liste alphabetique des immeubles
    // - Super admin (role_id = 1) : voit TOUS les immeubles
    // - Autres : filtre par id_societe / id_agence (multi-tenant)
    try {
        $roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
        if ($roleId === 1) {
            $st = $pdo->query("
                SELECT id, reference_immeuble, adresse, code_postal, ville
                FROM immeubles
                ORDER BY adresse ASC, ville ASC
                LIMIT 500
            ");
            $immeublesList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $st = $pdo->prepare("
                SELECT id, reference_immeuble, adresse, code_postal, ville
                FROM immeubles
                WHERE (id_societe = :s OR :s IS NULL)
                  AND (id_agence  = :a OR :a IS NULL)
                ORDER BY adresse ASC, ville ASC
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
    'dpe_version'                => ['label' => 'Version DPE',               'type' => 'text'],
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
      --topbar-h:  72px; /* +30% vs 56px */
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
  </style>
</head>
<body class="<?= h($bodyClass) ?>">

<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

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
      <a href="?edit=<?= (int)$editingBienId ?>&section=annonce"
         class="v2-section-tab<?= $section === 'annonce' ? ' is-active' : '' ?>"
         role="tab" aria-selected="<?= $section === 'annonce' ? 'true' : 'false' ?>">
        <span>📡</span> Annonce
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
        <div class="v2-card-label">📸 Photos <span class="v2-count" id="v2-count-photos"><?= count($docsPhotos) ?></span></div>
        <div class="v2-card-body" id="v2-photos-container">
          <?php if (empty($docsPhotos)): ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">📸</div>
              <div>Aucune photo — glisse des photos dans la card Chargement.</div>
            </div>
          <?php else: ?>
            <div class="v2-photos-doc-grid">
              <?php foreach ($docsPhotos as $p): ?>
                <div class="v2-photo-tile" data-id="<?= (int)$p['id'] ?>" data-url="<?= h($p['url']) ?>" data-name="<?= h($p['nom_original']) ?>">
                  <img src="<?= h($p['url']) ?>" alt="<?= h($p['nom_original']) ?>" loading="lazy">
                  <div class="v2-photo-tile-actions">
                    <button type="button" class="v2-photo-tile-btn" data-action="zoom" title="Agrandir">🔍</button>
                    <button type="button" class="v2-photo-tile-btn danger" data-action="delete" title="Supprimer">🗑️</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Lightbox pour agrandir les photos -->
      <div id="v2-photo-lightbox" class="v2-lightbox" hidden>
        <button type="button" class="v2-lightbox-close" aria-label="Fermer">✕</button>
        <img id="v2-lightbox-img" src="" alt="">
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

        $curType    = (int)($b['id_type_bien'] ?? 0);
        $curSType   = (string)($b['sous_type_bien'] ?? '');
        $curUsage   = (string)($b['usage_bien'] ?? '');
        $curEtat    = (string)($b['etat_bien'] ?? '');
        $curStand   = (string)($b['standing'] ?? '');
        $curStatut  = (string)($b['statut_bien'] ?? '');
        $curMandat  = (string)($b['type_commercialisation'] ?? '');
        $curProprio = (int)($b['id_proprietaire'] ?? 0);
      ?>

      <!-- Card 1 : Propriétaire / Adresse / Caractéristiques (EDITION AVEC AUTOSAVE) -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Caractéristiques">
        <div class="v2-card-label">📋 Caractéristiques</div>
        <div id="v2-save-indicator" class="v2-save-indicator v2-save-floating" aria-live="polite"></div>
        <div class="v2-card-body">

          <!-- 1. Propriétaire (lecture seule, édition via bien_detail.php) -->
          <div class="v2-group-header">
            <span class="v2-group-header-title">👤 Propriétaire</span>
            <span class="v2-proprio-display"><?= h($proprioStr ?: '— Non renseigné —') ?></span>
            <a href="<?= h(app_url('/bien_detail.php?edit=' . $editingBienId)) ?>"
               class="v2-btn-outline v2-header-btn" title="Modifier dans bien_detail">✏️</a>
          </div>
          <?php if ($proprioInfo): ?>
            <div class="v2-tiers-info">
              <?php if (!empty($proprioInfo['telephone'])): ?>📞 <?= h((string)$proprioInfo['telephone']) ?><?php endif; ?>
              <?php if (!empty($proprioInfo['email'])): ?> · ✉️ <?= h((string)$proprioInfo['email']) ?><?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($descProprioFromDpe && empty($bienLoaded['id_proprietaire'])): ?>
            <div class="v2-dpe-proprio-suggest" id="v2-dpe-proprio-suggest"
                 data-proprio='<?= h(json_encode($descProprioFromDpe, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
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
            <button type="button" id="v2-imm-btn" class="v2-btn-outline v2-header-btn">
              🔍 Trouver immeuble <?php if (!empty($immeublesList)): ?><span class="v2-badge"><?= count($immeublesList) ?></span><?php endif; ?>
            </button>
          </div>
          <?php
            $syncFlags = array_flip($descSyncedFields);
            $addrCls = static fn($f) => isset($syncFlags[$f]) ? ' is-from-dpe' : '';
          ?>
          <div class="v2-addr-grid">
            <input type="text" id="v2-f-adresse_1"   class="v2-input<?= $addrCls('adresse_1') ?>"   name="adresse_1"   data-autosave placeholder="Adresse"    value="<?= h((string)($b['adresse_1']   ?? '')) ?>">
            <input type="text" id="v2-f-adresse_2"   class="v2-input<?= $addrCls('adresse_2') ?>"   name="adresse_2"   data-autosave placeholder="Complément" value="<?= h((string)($b['adresse_2']   ?? '')) ?>">
            <input type="text" id="v2-f-code_postal" class="v2-input<?= $addrCls('code_postal') ?>" name="code_postal" data-autosave placeholder="CP" maxlength="10" value="<?= h((string)($b['code_postal'] ?? '')) ?>">
            <input type="text" id="v2-f-ville"       class="v2-input<?= $addrCls('ville') ?>"       name="ville"       data-autosave placeholder="Ville"     value="<?= h((string)($b['ville']       ?? '')) ?>">
          </div>

          <!-- 3. Caractéristiques -->
          <div class="v2-desc-group-title">🏷️ Caractéristiques</div>

          <!-- Type (pleine largeur) -->
          <div class="v2-icon-row">
            <span class="v2-icon-row-label">Type</span>
            <div class="v2-icon-radios" data-field="id_type_bien">
              <?php foreach ($typesBienList as $t):
                $icon = $typeIcons[$t['code']] ?? '📦';
                $act = ((int)$t['id'] === $curType) ? ' is-active' : '';
              ?>
                <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= (int)$t['id'] ?>" title="<?= h((string)$t['label']) ?>">
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

          <!-- Statut + Mandat -->
          <div class="v2-row-split">
            <div class="v2-icon-row">
              <span class="v2-icon-row-label">Statut</span>
              <div class="v2-icon-radios" data-field="statut_bien">
                <?php foreach ($statuts as $code => [$ic, $lbl]): $act = ($curStatut === $code) ? ' is-active' : ''; ?>
                  <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                    <span class="v2-icon-emoji"><?= $ic ?></span><span class="v2-icon-lbl"><?= h($lbl) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="v2-icon-row">
              <span class="v2-icon-row-label">Mandat</span>
              <div class="v2-icon-radios" data-field="type_commercialisation">
                <?php foreach ($mandats as $code => [$ic, $lbl]): $act = ($curMandat === $code) ? ' is-active' : ''; ?>
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
      <div id="v2-imm-modal" class="v2-modal" hidden>
        <div class="v2-modal-card">
          <h3>🏢 Sélectionner un immeuble</h3>
          <?php if (!empty($immeublesList)): ?>
            <div class="v2-imm-results">
              <?php foreach ($immeublesList as $imm):
                $adr = (string)($imm['adresse'] ?? '');
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
            <div class="v2-imm-empty">Aucun immeuble dans votre agence pour le moment.</div>
          <?php endif; ?>
          <div class="v2-modal-actions">
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
        <div class="v2-card-label">📸 Photos <span class="v2-count"><?= count($descPhotos) ?></span></div>
        <div class="v2-card-body">
          <?php if (!empty($descPhotos)): ?>
            <div class="v2-photo-grid">
              <?php foreach ($descPhotos as $p): ?>
                <?php
                  $photoUrl = $p['url_photo'] ? app_url('/' . ltrim((string)$p['url_photo'], '/')) : '';
                  if (!$photoUrl) continue;
                ?>
                <a class="v2-photo-item" href="<?= h($photoUrl) ?>" target="_blank" rel="noopener" title="<?= h((string)($p['nom_original'] ?? '')) ?>">
                  <img src="<?= h($photoUrl) ?>" alt="<?= h((string)($p['nom_original'] ?? 'Photo')) ?>" loading="lazy">
                </a>
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

    <?php elseif ($section === 'annonce'): ?>

      <?php
        $a = $annonce ?: [];
        $aid = (int)($annonce['id'] ?? 0);
        $af = static fn($k, $d='') => isset($a[$k]) && $a[$k] !== null && $a[$k] !== '' ? (string)$a[$k] : (string)$d;
        $ab = static fn($k) => (int)($a[$k] ?? 0) === 1;
      ?>

      <!-- Card 1 : Conditions financières -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Conditions financières">
        <div class="v2-card-label">💶 Conditions financières</div>
        <div id="v2-annonce-save-indicator" class="v2-save-indicator v2-save-floating" aria-live="polite"></div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty" style="padding:24px;">
              <div class="v2-doc-empty-icon">📡</div>
              <div style="margin-bottom:14px;">
                Aucune annonce enregistrée pour ce bien.<br>
                <small>Remplissez les conditions financières puis créez l'annonce.</small>
              </div>
              <button type="button" id="v2-annonce-create" class="v2-btn-primary">➕ Créer l'annonce (brouillon)</button>
              <span id="v2-annonce-create-status" class="v2-form-status" style="margin-left:10px;"></span>
            </div>
          <?php else: ?>
            <div class="v2-annonce-header">
              <span class="v2-badge"><?= h((string)($annonce['etat_publication'] ?? 'brouillon')) ?></span>
              <small>Annonce #<?= $aid ?> · créée le <?= h((string)($annonce['date_creation'] ?? '')) ?></small>
            </div>

            <?php
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
            <div class="v2-desc-group-title">💼 Type de transaction</div>
            <?php
              $typeTransactions = [
                'vente'      => ['💶', 'Vente'],
                'location'   => ['🔑', 'Location'],
                'saisonnier' => ['🌴', 'Saisonnier'],
                'viager'     => ['⌛', 'Viager'],
              ];
              $curTT = (string)($a['type_transaction'] ?? '');
            ?>
            <div class="v2-icon-radios" data-field="type_transaction" data-target="annonce">
              <?php foreach ($typeTransactions as $code => [$ic, $lbl]):
                $act = ($curTT === $code) ? ' is-active' : '';
              ?>
                <button type="button" class="v2-icon-radio<?= $act ?>" data-value="<?= h($code) ?>">
                  <span class="v2-icon-emoji"><?= $ic ?></span>
                  <span class="v2-icon-lbl"><?= h($lbl) ?></span>
                </button>
              <?php endforeach; ?>
            </div>

            <!-- VENTE -->
            <div class="v2-desc-group-title">💰 Vente</div>
            <div class="v2-num-grid">
              <?= $aNum('💰', 'prix',                           'Prix de vente',       '€') ?>
              <?= $aNum('🤝', 'honoraires_charge_acquereur',    'Honoraires acquéreur','€') ?>
              <?= $aNum('🏷️', 'honoraires_charge_vendeur',      'Honoraires vendeur',  '€') ?>
              <?= $aNum('%', 'pourcentage_honoraires_vendeur', '% vendeur',           '%') ?>
              <?= $aNum('⚖️', 'alur_pourcentage_honoraires_ttc','% ALUR TTC',          '%') ?>
              <?= $aNum('💼', 'honoraires_negociation_cumules', 'Hon. cumulés',        '€') ?>
            </div>
            <?= $aText('url_tarifs_publics', 'URL tarifs publics', 'https://...') ?>

            <!-- LOCATION -->
            <div class="v2-desc-group-title">🔑 Location</div>
            <div class="v2-num-grid">
              <?= $aNum('🔑', 'loyer',            'Loyer HC',         '€') ?>
              <?= $aNum('💧', 'loyer_cc',         'Loyer CC',         '€') ?>
              <?= $aNum('💳', 'complement_loyer', 'Complément loyer', '€') ?>
              <?= $aNum('📑', 'honoraires_etat_des_lieux', 'Hon. EDL', '€') ?>
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
              <?= $aNum('🏛️', 'taxe_fonciere',   'Taxe foncière',    '€') ?>
              <?= $aNum('🏡', 'taxe_habitation', 'Taxe habitation', '€') ?>
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
            <div class="v2-num-grid">
              <div class="v2-num-field">
                <span class="v2-num-icon">📏</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:100px;"
                       name="loyer_de_base" data-annonce-save value="<?= h((string)$lBase) ?>" placeholder="Loyer de base">
                <span class="v2-num-label">Loyer de base <small>€</small></span>
              </div>
              <div class="v2-num-field">
                <span class="v2-num-icon">📈</span>
                <input type="number" step="0.01" min="0" class="v2-num-input" style="width:100px;"
                       name="loyer_reference_majore" data-annonce-save value="<?= h((string)$lRefMaj) ?>" placeholder="Réf. majoré">
                <span class="v2-num-label">Loyer réf. majoré <small>€</small></span>
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
            <div class="v2-desc-group-title" style="margin-top:18px;border-top:1px solid var(--v2-stroke,#eee);padding-top:12px;">
              ➕ Complément de loyer (justifications)
            </div>
            <div class="v2-enc-cpl-hint" style="font-size:11px;color:var(--v2-muted);margin-bottom:8px;">
              Total appliqué à <code>complement_loyer</code>. Le loyer total HC = majoré + somme des compléments.
            </div>
            <div id="v2-cpl-lignes" data-annonce-id="<?= (int)$annonce['id'] ?>">
              <?php foreach ($cplLignes as $ln): ?>
                <div class="v2-cpl-row" data-cpl-id="<?= (int)$ln['id'] ?>">
                  <input type="text" class="v2-input v2-cpl-libelle" value="<?= h((string)$ln['libelle']) ?>" placeholder="Ex : vue dégagée sur parc">
                  <input type="number" step="0.01" min="0" class="v2-num-input v2-cpl-montant" value="<?= h((string)$ln['montant']) ?>" placeholder="€">
                  <button type="button" class="v2-cpl-del" title="Supprimer">✕</button>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="v2-cpl-footer">
              <button type="button" id="v2-cpl-add" class="v2-btn-secondary">＋ Ajouter une justification</button>
              <span class="v2-cpl-total">Total : <strong id="v2-cpl-total"><?= number_format(array_sum(array_map(static fn($l) => (float)$l['montant'], $cplLignes)), 2, ',', ' ') ?></strong> €</span>
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
                  <label class="v2-field-label">Notes à mentionner <small>(éléments internes à intégrer au texte)</small></label>
                  <textarea class="v2-input v2-textarea" id="v2-ia-notes" rows="2"
                            placeholder="Ex : travaux récents, chaudière neuve 2024, copropriété bien gérée, école primaire à 200 m…"></textarea>
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

      <!-- Card 4 : Photos de l'annonce (N:N toggle biens_photos ↔ annonces_photos) -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Photos de l'annonce">
        <div class="v2-card-label">📸 Photos <span class="v2-count" id="v2-annonce-photos-count"><?= count($annoncePhotoIds) ?></span></div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty"><div class="v2-doc-empty-icon">📸</div><div>Créez d'abord l'annonce dans la Card 1.</div></div>
          <?php elseif (empty($annonceBienPhotos)): ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">📸</div>
              <div>Aucune photo disponible pour ce bien.<br>
                <small>Charge des photos dans <a href="?edit=<?= (int)$editingBienId ?>&section=documents">📎 Documents → Chargement</a>.</small>
              </div>
            </div>
          <?php else: ?>
            <div class="v2-hint" style="padding:6px 10px;margin-bottom:10px;">
              Clique sur une photo pour l'inclure ou l'exclure de l'annonce diffusée.
              Les photos sélectionnées sont celles qui partent sur les portails.
            </div>
            <div class="v2-annonce-photos-bulk">
              <button type="button" id="v2-annonce-photos-all" class="v2-btn-secondary">✓ Tout sélectionner</button>
              <button type="button" id="v2-annonce-photos-none" class="v2-btn-secondary">✕ Tout désélectionner</button>
            </div>
            <div class="v2-annonce-photos-grid">
              <?php foreach ($annonceBienPhotos as $p): ?>
                <?php $isSel = in_array($p['id'], $annoncePhotoIds, true); ?>
                <div class="v2-annonce-photo-tile<?= $isSel ? ' is-selected' : '' ?>" data-photo-id="<?= $p['id'] ?>">
                  <img src="<?= h($p['url']) ?>" alt="<?= h($p['nom']) ?>" loading="lazy">
                  <div class="v2-annonce-photo-check">
                    <?= $isSel ? '✓' : '+' ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="v2-annonce-photos-status" id="v2-annonce-photos-status"></div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 5 : Diffusion (canaux + récap Ubiflow + diffuser) -->
      <section class="v2-card is-prev" role="tabpanel" aria-label="Diffusion">
        <div class="v2-card-label">📡 Diffusion</div>
        <div class="v2-card-body">
          <?php if (!$annonce): ?>
            <div class="v2-doc-empty"><div class="v2-doc-empty-icon">📡</div><div>Créez d'abord l'annonce dans la Card 1.</div></div>
          <?php else: ?>
            <!-- Canaux de diffusion : mini-cards colorées -->
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
          <div class="v2-card-footer">
            <a class="v2-btn-outline" href="<?= h(app_url('/bien_detail.php?edit=' . $editingBienId . '#tab-dpe')) ?>">✏️ Éditer dans bien_detail</a>
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
          <div class="v2-card-footer">
            <a class="v2-btn-outline" href="<?= h(app_url('/bien_detail.php?edit=' . $editingBienId . '#tab-dpe')) ?>">✏️ Éditer dans bien_detail</a>
          </div>
        </div>
      </section>

      <!-- Card 3 : Données extraites (visu tableau) -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Données extraites du DPE">
        <div class="v2-card-label">📊 Données extraites
          <?php if ($dpeDiag && isset($dpeDiag['extraction_score'])): ?>
            <span class="v2-count"><?= (int)$dpeDiag['extraction_score'] ?>%</span>
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
          <div class="v2-card-footer">
            <a class="v2-btn-outline" href="<?= h(app_url('/bien_detail.php?edit=' . $editingBienId . '#tab-dpe')) ?>">✏️ Éditer dans bien_detail</a>
          </div>
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
              <iframe src="<?= h($lastDpePdf['url_fichier']) ?>" title="<?= h((string)$lastDpePdf['nom_original']) ?>" loading="lazy"></iframe>
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
    uploadEndpoint:      <?= json_encode(app_url('/api/bien_intake_upload.php'),       JSON_UNESCAPED_SLASHES) ?>,
    photoUploadEndpoint: <?= json_encode(app_url('/api/bien_intake_photo_upload.php'), JSON_UNESCAPED_SLASHES) ?>,
    updateEndpoint:      <?= json_encode(app_url('/api/dpe_diag_update.php'),          JSON_UNESCAPED_SLASHES) ?>,
    autosaveEndpoint:    <?= json_encode(app_url('/api/bien_autosave.php'),            JSON_UNESCAPED_SLASHES) ?>,
    tiersLookupEndpoint: <?= json_encode(app_url('/api/tiers_lookup.php'),             JSON_UNESCAPED_SLASHES) ?>,
    tiersCreateEndpoint: <?= json_encode(app_url('/api/tiers_create.php'),             JSON_UNESCAPED_SLASHES) ?>,
    photoDeleteEndpoint: <?= json_encode(app_url('/api/bien_photo_delete.php'),        JSON_UNESCAPED_SLASHES) ?>,
    annonceCreateEndpoint:       <?= json_encode(app_url('/api/annonce_create.php'),        JSON_UNESCAPED_SLASHES) ?>,
    annonceAutosaveEndpoint:     <?= json_encode(app_url('/api/annonce_autosave.php'),      JSON_UNESCAPED_SLASHES) ?>,
    annoncePhotoToggleEndpoint:  <?= json_encode(app_url('/api/annonce_photo_toggle.php'),  JSON_UNESCAPED_SLASHES) ?>,
    annoncePhotosBulkEndpoint:   <?= json_encode(app_url('/api/annonce_photos_bulk.php'),   JSON_UNESCAPED_SLASHES) ?>,
    encadrementEndpoint:         <?= json_encode(app_url('/api/encadrement_loyers.php'),    JSON_UNESCAPED_SLASHES) ?>,
    cplAddEndpoint:              <?= json_encode(app_url('/api/annonce_cpl_add.php'),       JSON_UNESCAPED_SLASHES) ?>,
    cplUpdateEndpoint:           <?= json_encode(app_url('/api/annonce_cpl_update.php'),    JSON_UNESCAPED_SLASHES) ?>,
    cplDeleteEndpoint:           <?= json_encode(app_url('/api/annonce_cpl_delete.php'),    JSON_UNESCAPED_SLASHES) ?>,
    aiGenerateEndpoint:          <?= json_encode(app_url('/api/bien_ai_generate.php'),      JSON_UNESCAPED_SLASHES) ?>,
    aiCsrfToken:                 <?= json_encode(csrf_token('ajouter_bien'),                JSON_UNESCAPED_SLASHES) ?>,
    bienEncContext: {
      code_postal: <?= json_encode((string)($bienLoaded['code_postal']       ?? ''), JSON_UNESCAPED_SLASHES) ?>,
      annee_construction: <?= (int)($bienLoaded['annee_construction'] ?? 0) ?>,
      nb_pieces:  <?= (int)($bienLoaded['nb_pieces']          ?? 0) ?>,
      surface:    <?= json_encode((float)($bienLoaded['surface_habitable']  ?? 0)) ?>,
      meuble:     <?= (int)(!empty($bienLoaded['loyer_meuble']) ? 1 : 0) ?>,
    },
    annonceId: <?= (int)($annonce['id'] ?? 0) ?>,
    docsDiag:   <?= json_encode($docsDiag,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsMandat: <?= json_encode($docsMandat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsAutre:  <?= json_encode($docsAutre,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    immeubles:  <?= json_encode($immeublesList, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  };
</script>
<script src="<?= asset_url('/assets/js/document_uploader.js') ?>"></script>
<script src="<?= asset_url('/js/bien_detail_v2.js') ?>?v=<?= @filemtime(__DIR__ . '/js/bien_detail_v2.js') ?: time() ?>"></script>

</body>
</html>
