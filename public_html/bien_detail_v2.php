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
$sectionsAvail = ['documents', 'dpe'];
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
$diagTypes   = ['dpe','certificat_surface','mesurage_loi_carrez','erp','amiante','plomb','termites','gaz','electricite'];
$mandatTypes = ['mandat_vente','mandat_gestion','mandat_location','bail'];

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
            if ($lastDpePdf === null && $t === 'dpe' && $row['url_fichier']) $lastDpePdf = $row;
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
  <link rel="stylesheet" href="<?= asset_url('/css/bien_detail_v2.css') ?>">
  <style>
    :root {
      --bg:        var(--bg-secondary);
      --card:      var(--bg-primary);
      --ink:       #1a1816;
      --muted:     #8a8680;
      --accent:    #36577d;
      --stroke:    #d4d0ca;
      --sidebar-w: 220px;
      --topbar-h:  56px;
      --neu-out:   6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      --neu-in:    inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Sora', system-ui, sans-serif; background: var(--bg); color: var(--ink); display: flex; min-height: 100vh; }
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
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--bg); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out); color: var(--muted);
      transition: box-shadow .18s; flex-shrink: 0;
    }
    .topbar-nav-btn:hover, .topbar-icon-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
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

    /* Page head */
    .page-head {
      padding: 12px 24px 8px;
      display: flex; align-items: center; justify-content: space-between;
      gap: 18px; flex-shrink: 0;
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

  <!-- PAGE HEAD avec nav onglets -->
  <div class="page-head">
    <div class="page-head-info">
      <div class="page-head-label">Gestion des biens · <?= $section === 'dpe' ? 'Diag &amp; DPE' : 'Documents' ?></div>
      <h1 class="page-head-title"><?= h($pageTitle) ?></h1>
    </div>
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
    </nav>
  </div>

  <!-- CAROUSEL STAGE -->
  <div class="v2-stage-wrap">
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
              <iframe src="<?= h($lastDpePdf['url_fichier']) ?>#toolbar=0" title="Document DPE"></iframe>
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

      <!-- Card 5 : Analyse du rapport -->
      <section class="v2-card is-hidden" role="tabpanel" aria-label="Analyse du rapport">
        <div class="v2-card-label">🧠 Analyse du rapport</div>
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
          <?php else: ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">🧠</div>
              <div>Aucune analyse disponible.<br><small>L'analyse IA est générée automatiquement lors de l'upload d'un PDF DPE.</small></div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Card 6 : Alertes -->
      <section class="v2-card is-prev" role="tabpanel" aria-label="Alertes sur diagnostics">
        <div class="v2-card-label">⚠️ Alertes s/Diag</div>
        <div class="v2-card-body">
          <?php if ($dpeDiag): ?>
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
          <?php else: ?>
            <div class="v2-doc-empty">
              <div class="v2-doc-empty-icon">⚠️</div>
              <div>Aucune alerte — pas d'analyse DPE disponible.</div>
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
    uploadEndpoint: <?= json_encode(app_url('/api/bien_intake_upload.php'), JSON_UNESCAPED_SLASHES) ?>,
    updateEndpoint: <?= json_encode(app_url('/api/dpe_diag_update.php'), JSON_UNESCAPED_SLASHES) ?>,
    docsDiag:   <?= json_encode($docsDiag,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsMandat: <?= json_encode($docsMandat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsAutre:  <?= json_encode($docsAutre,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  };
</script>
<script src="<?= asset_url('/assets/js/document_uploader.js') ?>"></script>
<script src="<?= asset_url('/js/bien_detail_v2.js') ?>"></script>

</body>
</html>
