<?php
// bien_detail_v2.php
// ─────────────────────────────────────────────────────────────────────────
// Refonte bien_detail en carousel de cards animées (style Apple).
// SCOPE ÉTAPE 1 : section DOCUMENTS uniquement, 4 cards :
//   1. Chargement (DocumentUploader)
//   2. Diagnostics (dpe, certificats, erp, plomb, amiante…)
//   3. Mandats & baux
//   4. Autres documents
//
// Layout préservé : sidebar_agency, topbar MaBoxImmo, page-head, viz card
// (champs impératifs + barre de complétude). PAS DE SCROLL vertical :
// tout tient dans le viewport, carousel élargi horizontalement.
// ─────────────────────────────────────────────────────────────────────────
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/bien_form_loader.php';
require_once __DIR__ . '/inc/ubiflow_validator.php';
require_login();

$appLayout = true;
$pageTitle = 'Détail du bien (v2)';
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

$pageTitle = 'Détail du bien — #' . $editingBienId;

// ── Documents catégorisés ──
$diagTypes   = ['dpe','certificat_surface','mesurage_loi_carrez','erp','amiante','plomb','termites','gaz','electricite'];
$mandatTypes = ['mandat_vente','mandat_gestion','mandat_location','bail'];

$docsDiag = [];
$docsMandat = [];
$docsAutre = [];
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
        if (in_array($t, $diagTypes, true))        $docsDiag[]   = $row;
        elseif (in_array($t, $mandatTypes, true))  $docsMandat[] = $row;
        else                                        $docsAutre[]  = $row;
    }
} catch (Throwable $e) {
    error_log('[bien_detail_v2] biens_documents: ' . $e->getMessage());
}

$username = $_SESSION['username'] ?? '?';
$csrfTokenVal = csrf_token('ajouter_bien');
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
    /* Bootstrap commun (mêmes variables que bien_detail.php) */
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
    body {
      font-family: 'Sora', system-ui, sans-serif;
      background: var(--bg);
      color: var(--ink);
      display: flex;
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }
    /* Topbar identique bien_detail.php */
    .mbi-topbar {
      position: sticky; top: 0; height: var(--topbar-h);
      background: var(--card);
      box-shadow: 0 2px 8px var(--shadow-dark);
      border-bottom: 1px solid var(--stroke);
      display: flex; align-items: center; gap: 10px;
      padding: 0 24px; z-index: 50;
      flex-shrink: 0;
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
    .topbar-breadcrumb {
      display: flex; align-items: center; gap: 6px;
      font-size: 13px; font-weight: 500; color: var(--muted);
    }
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
    /* Page head compact */
    .page-head {
      padding: 14px 24px 10px;
      display: flex; align-items: flex-start; gap: 18px;
      flex-shrink: 0;
    }
    .page-head-info { flex: 1; min-width: 0; }
    .page-head-label {
      font-size: 11px; font-weight: 600; letter-spacing: 1px;
      color: var(--muted); text-transform: uppercase;
    }
    .page-head-title {
      font-size: 20px; font-weight: 700; color: var(--ink);
      margin-top: 2px;
    }
    .page-head-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .page-head-ref {
      font-family: monospace; font-size: 11px; color: #64748b;
      background: rgba(255,255,255,.6); padding: 4px 10px;
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
      <span class="active">Documents (v2)</span>
    </nav>
    <span class="page-head-ref" style="margin-left:20px;">
      #<?= (int)$editingBienId ?>
      <?php if (!empty($bienLoaded['reference_bien'])): ?>
        · <strong style="color:#0f172a;"><?= h((string)$bienLoaded['reference_bien']) ?></strong>
      <?php endif; ?>
    </span>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= h(strtoupper(substr($username, 0, 1))) ?></div>
  </header>

  <!-- PAGE HEAD -->
  <div class="page-head">
    <div class="page-head-info">
      <div class="page-head-label">Gestion des biens · Documents</div>
      <h1 class="page-head-title"><?= h($pageTitle) ?></h1>
      <div class="page-head-sub">
        <?php
          $addrParts = array_filter([
            $bienLoaded['adresse_1'] ?? '',
            $bienLoaded['code_postal'] ?? '',
            $bienLoaded['ville'] ?? '',
          ]);
          echo h(implode(' · ', $addrParts) ?: 'Adresse non renseignée');
        ?>
      </div>
    </div>
  </div>

  <!-- VIZ CARD : champs impératifs + complétude -->
  <?php
    $score = (int)($ubiCheck['score'] ?? 0);
    $missing = is_array($ubiCheck['missing'] ?? null) ? $ubiCheck['missing'] : [];
    $missingImp = array_slice(array_filter($missing, static fn($m) => ($m['source'] ?? '') === 'ubiflow'), 0, 8);
  ?>
  <div class="v2-viz" role="status" aria-label="Complétude du bien">
    <div class="v2-viz-title">
      Champs impératifs
      <small><?= count($missing) ?> manquant(s) · <?= count($missingImp) ?> bloquant(s)</small>
    </div>
    <div class="v2-viz-chips">
      <?php if (empty($missingImp)): ?>
        <span class="v2-chip ok">✅ Tous les champs impératifs sont remplis</span>
      <?php else: ?>
        <?php foreach ($missingImp as $m): ?>
          <span class="v2-chip" title="<?= h($m['aide'] ?? '') ?>"><?= h($m['label'] ?? '') ?></span>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="v2-viz-score">
      <div class="v2-viz-score-pct"><?= $score ?>%</div>
      <div class="v2-viz-score-bar"><span style="width: <?= $score ?>%;"></span></div>
    </div>
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

      <!-- Card 1 : CHARGEMENT -->
      <section class="v2-card is-active" role="tabpanel" aria-label="Chargement de documents">
        <div class="v2-card-label">⬆️ Chargement d'un document</div>
        <div class="v2-card-body">
          <div id="v2-uploader"></div>
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
      <section class="v2-card is-prev" role="tabpanel" aria-label="Autres documents">
        <div class="v2-card-label">📎 Autres documents <span class="v2-count" id="v2-count-autre">0</span></div>
        <div class="v2-card-body" id="v2-list-autre"></div>
      </section>

    </div>

    <div id="v2-dots" class="v2-dots" role="tablist" aria-label="Sélection de carte"></div>
  </div>

</main>

<!-- Données injectées vers JS -->
<script>
  window.__v2DocsData = {
    bienId: <?= (int)$editingBienId ?>,
    csrfToken: <?= json_encode($csrfTokenVal, JSON_UNESCAPED_SLASHES) ?>,
    uploadEndpoint: <?= json_encode(app_url('/api/bien_intake_upload.php'), JSON_UNESCAPED_SLASHES) ?>,
    docsDiag:   <?= json_encode($docsDiag,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsMandat: <?= json_encode($docsMandat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    docsAutre:  <?= json_encode($docsAutre,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  };
</script>
<script src="<?= asset_url('/assets/js/document_uploader.js') ?>"></script>
<script src="<?= asset_url('/js/bien_detail_v2.js') ?>"></script>

</body>
</html>
