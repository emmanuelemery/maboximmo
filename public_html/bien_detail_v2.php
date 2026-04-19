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
require_once __DIR__ . '/inc/tiers_selector.php';
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
$sectionsAvail = ['documents', 'dpe', 'descriptif'];
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

// ─── Section DESCRIPTIF : charger photos + types + proprietaire ───
$descPhotos = [];
$typeBienLabel = '';
$proprioInfo = null;
$typesBienList = [];
if ($section === 'descriptif') {
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
        // Tentative tiers (nouvelle architecture) puis fallback users legacy
        foreach (['tiers', 'users'] as $tbl) {
            try {
                $st = $pdo->prepare("SELECT nom, prenom, telephone, email FROM `$tbl` WHERE id = ? LIMIT 1");
                $st->execute([(int)$bienLoaded['id_proprietaire']]);
                $proprioInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($proprioInfo) break;
            } catch (Throwable $e) {}
        }
    }
    // Liste des immeubles dispo (meme societe/agence)
    try {
        $st = $pdo->prepare("
            SELECT id, reference_immeuble, adresse, code_postal, ville
            FROM immeubles
            WHERE (id_societe = :s OR :s IS NULL)
              AND (id_agence  = :a OR :a IS NULL)
            ORDER BY ville ASC, adresse ASC
            LIMIT 200
        ");
        $st->execute([':s' => $idSociete, ':a' => isset($_SESSION['id_agence']) ? (int)$_SESSION['id_agence'] : null]);
        $immeublesList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $immeublesList = []; }
}
$immeublesList = $immeublesList ?? [];

// Cle Google Maps — pattern identique a rh_indemnite_km.php (qui fonctionne)
$googleConfigPaths = [
    __DIR__ . '/../u630423897/google_config.php',
    __DIR__ . '/google_config.php',
    __DIR__ . '/../google_config.php',
];
foreach ($googleConfigPaths as $p) { if (file_exists($p)) { require_once $p; break; } }
$GOOGLE_MAPS_API_KEY = defined('GOOGLE_MAPS_API_KEY')
    ? GOOGLE_MAPS_API_KEY
    : ($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');

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
        <div class="v2-card-label">⬆️ Chargement d'un document</div>
        <div class="v2-card-body"><div id="v2-uploader"></div></div>
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
      <section class="v2-card is-prev" role="tabpanel" aria-label="Autres documents">
        <div class="v2-card-label">📎 Autres documents <span class="v2-count" id="v2-count-autre">0</span></div>
        <div class="v2-card-body" id="v2-list-autre"></div>
      </section>

    <?php elseif ($section === 'descriptif'): ?>

      <?php
        $b = $bienLoaded;
        $v = static fn($k) => isset($b[$k]) && $b[$k] !== '' && $b[$k] !== null ? $b[$k] : null;
        $vb = static fn($k) => (int)($b[$k] ?? 0) === 1 ? '✅ Oui' : '—';
        $vn = static fn($k) => $v($k) !== null ? $v($k) : '—';
        $proprioStr = $proprioInfo
          ? trim((string)($proprioInfo['prenom'] ?? '') . ' ' . (string)($proprioInfo['nom'] ?? ''))
          : '—';
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

          <!-- 1. Propriétaire (composant tiers_selector eprouve) -->
          <div class="v2-group-header">
            <span class="v2-group-header-title">👤 Propriétaire<?php if ($proprioStr): ?> <em class="v2-group-header-current">· <?= h($proprioStr) ?></em><?php endif; ?></span>
            <div id="v2-proprio-picker-wrap" class="v2-ts-wrap">
              <?php
                tiers_selector_render([
                  'id'          => 'v2-proprio-picker',
                  'name'        => 'id_proprietaire_picker',
                  'label'       => '',
                  'role_filter' => 'proprietaire',
                  'placeholder' => 'Rechercher nom, email, téléphone…',
                  'value_id'    => (int)($b['id_proprietaire'] ?? 0),
                  'value_label' => $proprioStr ?: '',
                  'allow_create'=> true,
                  'default_roles' => ['proprietaire'],
                ]);
              ?>
            </div>
          </div>
          <?php if ($proprioInfo): ?>
            <div class="v2-tiers-info">
              <?php if (!empty($proprioInfo['telephone'])): ?>📞 <?= h((string)$proprioInfo['telephone']) ?><?php endif; ?>
              <?php if (!empty($proprioInfo['email'])): ?> · ✉️ <?= h((string)$proprioInfo['email']) ?><?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- 2. Adresse (recherche Google via places.js + recherche immeuble sur la même ligne) -->
          <div class="v2-group-header">
            <span class="v2-group-header-title">📍 Adresse</span>
            <div class="v2-places-picker">
              <span class="v2-picker-label">Recherche Google</span>
              <input type="text" id="v2-google-places" class="v2-input"
                     placeholder="🔍 Ex: 15 place Bellecour Lyon…"
                     autocomplete="off"
                     data-places-endpoint="<?= h(app_url('/api/places_autocomplete.php')) ?>"
                     data-places-details-endpoint="<?= h(app_url('/api/places_details.php')) ?>"
                     data-places-street1="v2-f-adresse_1"
                     data-places-postal="v2-f-code_postal"
                     data-places-city="v2-f-ville"
                     data-places-country-code="fr">
            </div>
            <button type="button" id="v2-imm-btn" class="v2-btn-outline v2-imm-btn" title="Rechercher ou créer un immeuble">
              🏢 Immeubles <span class="v2-badge"><?= count($immeublesList) ?></span>
            </button>
          </div>
          <div class="v2-addr-grid">
            <input type="text" id="v2-f-adresse_1" class="v2-input" name="adresse_1" data-autosave placeholder="Adresse" value="<?= h((string)($b['adresse_1'] ?? '')) ?>">
            <input type="text" id="v2-f-adresse_2" class="v2-input" name="adresse_2" data-autosave placeholder="Complément" value="<?= h((string)($b['adresse_2'] ?? '')) ?>">
            <input type="text" id="v2-f-code_postal" class="v2-input" name="code_postal" data-autosave placeholder="CP" maxlength="10" value="<?= h((string)($b['code_postal'] ?? '')) ?>">
            <input type="text" id="v2-f-ville" class="v2-input" name="ville" data-autosave placeholder="Ville" value="<?= h((string)($b['ville'] ?? '')) ?>">
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

      <?php tiers_selector_assets(); /* injecte CSS + JS + modal du composant eprouve */ ?>

      <!-- Modal recherche immeuble -->
      <div id="v2-imm-modal" class="v2-modal" hidden>
        <div class="v2-modal-card">
          <h3>🏢 Rechercher un immeuble</h3>
          <div class="v2-field">
            <input type="text" id="v2-imm-modal-search" class="v2-input"
                   placeholder="Adresse, ville, code postal, référence…"
                   autocomplete="off">
          </div>
          <div id="v2-imm-modal-results" class="v2-imm-results"></div>
          <div class="v2-modal-actions" style="justify-content:space-between;">
            <a href="<?= h(app_url('/agency_immeuble_form.php')) ?>" target="_blank" class="v2-btn-outline">➕ Nouvel immeuble</a>
            <button type="button" id="v2-imm-modal-close" class="v2-btn-outline">Fermer</button>
          </div>
        </div>
      </div>

      <!-- Card 2 : Pièces, surfaces, extérieur, équipements intérieurs -->
      <section class="v2-card is-next" role="tabpanel" aria-label="Pièces et surfaces">
        <div class="v2-card-label">📐 Pièces &amp; Surfaces</div>
        <div class="v2-card-body">
          <div class="v2-desc-group-title">🚪 Pièces</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Nb pièces</div><div class="v2-kv-v"><?= h((string)$vn('nb_pieces')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Chambres</div><div class="v2-kv-v"><?= h((string)$vn('nb_chambres')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Salles de bain</div><div class="v2-kv-v"><?= h((string)$vn('nb_salles_bain')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Salles d'eau</div><div class="v2-kv-v"><?= h((string)$vn('nb_salles_eau')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">WC</div><div class="v2-kv-v"><?= h((string)$vn('nb_wc')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Niveaux</div><div class="v2-kv-v"><?= h((string)$vn('nb_niveaux')) ?></div></div>
          </div>

          <div class="v2-desc-group-title">📏 Surfaces (m²)</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Habitable</div><div class="v2-kv-v"><?= h((string)$vn('surface_habitable')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Carrez</div><div class="v2-kv-v"><?= h((string)$vn('surface_carrez')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Séjour</div><div class="v2-kv-v"><?= h((string)$vn('surface_sejour')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Totale</div><div class="v2-kv-v"><?= h((string)$vn('surface_totale')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Terrain</div><div class="v2-kv-v"><?= h((string)$vn('surface_terrain')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Hauteur plafond</div><div class="v2-kv-v"><?= h((string)$vn('hauteur_plafond')) ?></div></div>
          </div>

          <div class="v2-desc-group-title">🌳 Extérieur &amp; dépendances</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Balcon</div><div class="v2-kv-v"><?= $vb('balcon') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Terrasse</div><div class="v2-kv-v"><?= $vb('terrasse') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Jardin</div><div class="v2-kv-v"><?= $vb('jardin') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Cour</div><div class="v2-kv-v"><?= $vb('cour') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Cave</div><div class="v2-kv-v"><?= $vb('cave') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Grenier</div><div class="v2-kv-v"><?= $vb('grenier') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Garage</div><div class="v2-kv-v"><?= $vb('garage') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Box</div><div class="v2-kv-v"><?= $vb('box') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Piscine</div><div class="v2-kv-v"><?= $vb('piscine') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Parking</div><div class="v2-kv-v"><?= h((string)$vn('parking_nb')) ?></div></div>
          </div>

          <div class="v2-desc-group-title">🛋️ Équipements intérieurs</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Cuisine équipée</div><div class="v2-kv-v"><?= $vb('cuisine_equipee') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Type cuisine</div><div class="v2-kv-v"><?= h((string)$vn('cuisine_type')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Ascenseur</div><div class="v2-kv-v"><?= $vb('ascenseur') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Interphone</div><div class="v2-kv-v"><?= $vb('interphone') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Digicode</div><div class="v2-kv-v"><?= $vb('digicode') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Alarme</div><div class="v2-kv-v"><?= $vb('alarme') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Fibre</div><div class="v2-kv-v"><?= $vb('fibre') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Cheminée</div><div class="v2-kv-v"><?= $vb('cheminee') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Double vitrage</div><div class="v2-kv-v"><?= $vb('double_vitrage') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Volets roulants</div><div class="v2-kv-v"><?= $vb('volets_roulants') ?></div></div>
          </div>
        </div>
      </section>

      <!-- Card 3 : Chauffage & Énergie -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Chauffage et énergie">
        <div class="v2-card-label">🔥 Chauffage &amp; Énergie</div>
        <div class="v2-card-body">
          <div class="v2-desc-group-title">🔥 Chauffage</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Type</div><div class="v2-kv-v"><?= h((string)$vn('chauffage_type')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Énergie</div><div class="v2-kv-v"><?= h((string)$vn('chauffage_energie')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Plancher chauffant</div><div class="v2-kv-v"><?= $vb('chauffage_plancher') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Thermostat</div><div class="v2-kv-v"><?= $vb('chauffage_thermostat') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Régulateur</div><div class="v2-kv-v"><?= $vb('chauffage_regulateur') ?></div></div>
          </div>

          <div class="v2-desc-group-title">💧 Eau chaude</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Type</div><div class="v2-kv-v"><?= h((string)$vn('eau_chaude_type')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Solaire</div><div class="v2-kv-v"><?= $vb('eau_chaude_solaire') ?></div></div>
          </div>

          <div class="v2-desc-group-title">🌬️ VMC &amp; isolation</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">VMC</div><div class="v2-kv-v"><?= $vb('chauffage_vmc') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">VMC double flux</div><div class="v2-kv-v"><?= $vb('chauffage_vmc_df') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Climatisation</div><div class="v2-kv-v"><?= $vb('climatisation') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Menuiseries</div><div class="v2-kv-v"><?= h((string)$vn('menuiseries')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Isolation</div><div class="v2-kv-v"><?= h((string)$vn('isolation')) ?></div></div>
          </div>
        </div>
      </section>

      <!-- Card 4 : Environnement -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Environnement">
        <div class="v2-card-label">🌳 Environnement</div>
        <div class="v2-card-body">
          <div class="v2-desc-group-title">🧭 Situation</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Étage</div><div class="v2-kv-v"><?= h((string)$vn('etage')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Dernier étage</div><div class="v2-kv-v"><?= $vb('dernier_etage') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Exposition</div><div class="v2-kv-v"><?= h((string)$vn('exposition')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Vue</div><div class="v2-kv-v"><?= h((string)$vn('vue')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Numéro de porte</div><div class="v2-kv-v"><?= h((string)$vn('numero_porte')) ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Année construction</div><div class="v2-kv-v"><?= h((string)$vn('annee_construction')) ?></div></div>
          </div>

          <div class="v2-desc-group-title">🔊 Nuisances &amp; accès</div>
          <div class="v2-kv-grid">
            <div class="v2-kv"><div class="v2-kv-k">Nuisances</div><div class="v2-kv-v"><?= h((string)$vn('nuisances')) ?: '—' ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Accès camion</div><div class="v2-kv-v"><?= $vb('acces_camion') ?></div></div>
            <div class="v2-kv"><div class="v2-kv-k">Adresse visible</div><div class="v2-kv-v"><?= $vb('adresse_visible_public') ?></div></div>
          </div>
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

    <?php else: /* section = dpe */ ?>

      <!-- Card 1 : DPE (visu) -->
      <section class="v2-card is-active" role="tabpanel" aria-label="DPE">
        <div class="v2-card-label">⚡ DPE</div>
        <div class="v2-card-body">
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
    uploadEndpoint:      <?= json_encode(app_url('/api/bien_intake_upload.php'), JSON_UNESCAPED_SLASHES) ?>,
    updateEndpoint:      <?= json_encode(app_url('/api/dpe_diag_update.php'),    JSON_UNESCAPED_SLASHES) ?>,
    autosaveEndpoint:    <?= json_encode(app_url('/api/bien_autosave.php'),      JSON_UNESCAPED_SLASHES) ?>,
    tiersLookupEndpoint: <?= json_encode(app_url('/api/tiers_lookup.php'),       JSON_UNESCAPED_SLASHES) ?>,
    tiersCreateEndpoint: <?= json_encode(app_url('/api/tiers_create.php'),       JSON_UNESCAPED_SLASHES) ?>,
    docsDiag:   <?= json_encode($docsDiag,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsMandat: <?= json_encode($docsMandat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsAutre:  <?= json_encode($docsAutre,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    immeubles:  <?= json_encode($immeublesList ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  };
</script>
<script src="<?= asset_url('/assets/js/document_uploader.js') ?>"></script>
<script src="<?= asset_url('/js/bien_detail_v2.js') ?>?v=<?= @filemtime(__DIR__ . '/js/bien_detail_v2.js') ?: time() ?>"></script>
<!-- Google Places — pattern identique à rh_indemnite_km.php (qui fonctionne) -->
<script>
  // onGoogleReady est appelé par Google Maps quand la librairie est chargée.
  // Il déclenche ensuite places.js (initPlacesAutocomplete) pour binder les inputs
  // qui ont l'attribut data-places-endpoint.
  window.onGoogleReady = function () {
    if (typeof window.initPlacesAutocomplete === 'function') {
      try { window.initPlacesAutocomplete(); } catch(e) { console.warn('[v2] initPlacesAutocomplete:', e); }
    }
  };
</script>
<script src="<?= h(asset_url('/js/places.js')) ?>"></script>
<script>
  // Si places.js s'initialise avant Google Maps (et Google est déjà là), on force
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    if (typeof window.initPlacesAutocomplete === 'function') window.initPlacesAutocomplete();
  }
</script>
<?php if (!empty($GOOGLE_MAPS_API_KEY)): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= h($GOOGLE_MAPS_API_KEY) ?>&libraries=places&loading=async&callback=onGoogleReady" async defer></script>
<?php else: ?>
<script>console.warn('[v2] GOOGLE_MAPS_API_KEY non définie — places Google désactivé');</script>
<?php endif; ?>

</body>
</html>
