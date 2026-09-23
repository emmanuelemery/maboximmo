<?php
declare(strict_types=1);

// Variables d'entrée attendues (toutes optionnelles) :
//   $mbiMeta = ['title','description','canonical','image','robots']
//   $mbiJsonLd = string (HTML <script type="application/ld+json">...)
//   $mbiNavActive = 'home' | 'acheter' | 'louer' | 'estimer' | 'pro'
//   $mbiBodyClass = string

$mbiMeta      = $mbiMeta ?? [];
$mbiJsonLd    = $mbiJsonLd ?? '';
$mbiNavActive = (string)($mbiNavActive ?? 'home');
$mbiBodyClass = (string)($mbiBodyClass ?? '');

// ── Ma Box Net : contexte de vitrine par agence (sous-domaine) ───────
// Si présent → on skinne la marque (logo + nom agence). Sinon portail générique.
require_once __DIR__ . '/net_context.php';
$mbiNet = net_context($GLOBALS['pdo'] ?? null);
if ($mbiNet) {
    $mbiBodyClass = trim($mbiBodyClass . ' mbi-net');
    // Différenciation SEO : suffixe le titre avec le nom de l'agence locale,
    // sauf si la page a déjà posé un titre contenant ce nom.
    $__t = (string)($mbiMeta['title'] ?? '');
    if ($__t === '' || !str_contains($__t, $mbiNet['nom'])) {
        $base = $__t !== '' ? preg_replace('/\s*—\s*MaBoxImmo.*$/u', '', $__t) : 'Annonces immobilières';
        $mbiMeta['title'] = $base . ' — ' . $mbiNet['nom'];
    }

    // ── SEO canonical ────────────────────────────────────────────────
    // Les FICHES annonces sont identiques sous tous les sous-dossiers agence
    // → on canonicalise vers l'URL RACINE (sans préfixe agence) pour consolider
    // le référencement sur une seule URL. Les pages éditoriales/listing restent
    // self-canonical (contenu local différencié), avec leur préfixe.
    $__pfx = (string)($mbiNet['prefix'] ?? '');
    if ($__pfx !== '' && str_contains((string)$mbiBodyClass, 'mbi-page-detail') && !empty($mbiMeta['canonical'])) {
        $mbiMeta['canonical'] = preg_replace('#/' . preg_quote($__pfx, '#') . '(/|$)#', '$1', (string)$mbiMeta['canonical'], 1);
    }
}

$title     = (string)($mbiMeta['title']       ?? 'MaBoxImmo — Annonces immobilières');
$desc      = (string)($mbiMeta['description'] ?? 'Le portail immobilier pensé pour particuliers et professionnels. Acheter, louer, estimer.');
$canonical = (string)($mbiMeta['canonical']   ?? '');
$robots    = (string)($mbiMeta['robots']      ?? 'index, follow');
$image     = (string)($mbiMeta['image']       ?? '');

// Sous-menu « Nos métiers » : les 5 activités + l'estimation + le barème d'honoraires.
$mbiMetiers = [
    ['Location',                app_url('/mbi_annonces_location.php'),       '🔑'],
    ['Achat & vente',           app_url('/mbi_annonces_transaction.php'),    '🏠'],
    ['Gestion locative',        app_url('/mbi_annonces_gestion.php'),        '📂'],
    ['Syndic de copropriété',   app_url('/mbi_annonces_syndic.php'),         '🏢'],
    ['Investissement',          app_url('/mbi_annonces_investissement.php'), '📈'],
    ['Estimation gratuite',     app_url('/mbi_annonces_recherche.php?tri=recent#estimer'), '📊'],
    ['Tarifs & honoraires',     app_url('/tarifs.php'),                                '💶'],
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($desc) ?>">
  <meta name="robots" content="<?= h($robots) ?>">
  <meta name="theme-color" content="#ef4444">
  <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= h($canonical) ?>"><?php endif; ?>
  <?= og_tags(['title' => $title, 'description' => $desc, 'url' => $canonical !== '' ? $canonical : null, 'image' => $image !== '' ? $image : null]) ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <!-- Preload des 2 images hero pour first paint sans strobe (Lyon en hero, puzzle dans le panel filtres) -->
  <link rel="preload" as="image" href="<?= h(asset_url('/images/mbi_annonces_hero_69.png')) ?>" fetchpriority="high">
  <link rel="preload" as="image" href="<?= h(asset_url('/images/maboximmo_puzzle_fond_seul.png')) ?>" fetchpriority="high">
  <?php
    // Cache buster basé sur mtime — invalide auto à chaque modif fichier CSS
    $__mbiCssRoot = dirname(__DIR__) . '/css';
    $__vVar = @filemtime($__mbiCssRoot . '/variables.css')   ?: time();
    $__vCss = @filemtime($__mbiCssRoot . '/mbi_annonces.css') ?: time();
  ?>
  <link rel="stylesheet" href="<?= h(asset_url('/css/variables.css')) ?>?v=<?= $__vVar ?>">
  <link rel="stylesheet" href="<?= h(asset_url('/css/mbi_annonces.css')) ?>?v=<?= $__vCss ?>">
  <?php if (!empty($mbiNet)): $__vNet = @filemtime($__mbiCssRoot . '/mbi_net.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= h(asset_url('/css/mbi_net.css')) ?>?v=<?= $__vNet ?>">
  <?php endif; ?>
  <?= $mbiJsonLd ?>
</head>
<body class="mbi-body <?= h($mbiBodyClass) ?>">

<header class="mbi-header" role="banner">
  <div class="mbi-container mbi-header-inner">
    <?php if ($mbiNet): ?>
      <a class="mbi-brand mbi-brand-net" href="<?= h(app_url('/mbi_annonces_index.php')) ?>" aria-label="<?= h($mbiNet['nom']) ?> — Accueil">
        <?php if (!empty($mbiNet['logo_url'])): ?>
          <img class="mbi-brand-logo" src="<?= h(asset_url('/' . ltrim((string)$mbiNet['logo_url'], '/'))) ?>" alt="<?= h($mbiNet['nom']) ?>" height="48">
        <?php else: ?>
          <span class="mbi-brand-mark" aria-hidden="true">📍</span>
        <?php endif; ?>
        <span class="mbi-brand-text">
          <span class="mbi-brand-name"><?= h($mbiNet['nom']) ?></span>
          <span class="mbi-brand-sub">Immobilier</span>
        </span>
      </a>
    <?php else: ?>
      <a class="mbi-brand" href="<?= h(app_url('/mbi_annonces_index.php')) ?>" aria-label="Ma Box Immo — Accueil">
        <?php
          // Logo officiel : si /images/logos/mbi_logo.png existe, on l'utilise.
          // Sinon fallback en composé (pin doré + texte navy).
          $logoFile = __DIR__ . '/../images/logos/mbi_logo.png';
          if (is_file($logoFile)):
        ?>
          <img class="mbi-brand-logo" src="<?= h(asset_url('/images/logos/mbi_logo.png')) ?>" alt="Ma Box Immo" width="160" height="48">
        <?php else: ?>
          <span class="mbi-brand-mark" aria-hidden="true">📍</span>
          <span class="mbi-brand-text">
            <span class="mbi-brand-name">MA BOX IMMO</span>
            <span class="mbi-brand-sub">Immobilier</span>
          </span>
        <?php endif; ?>
      </a>
    <?php endif; ?>

    <nav class="mbi-nav" aria-label="Navigation principale">
      <a class="mbi-nav-link <?= $mbiNavActive === 'acheter' ? 'is-active' : '' ?>" href="<?= h(app_url('/mbi_annonces_index.php?transaction=vente')) ?>">Acheter</a>
      <a class="mbi-nav-link <?= $mbiNavActive === 'louer' ? 'is-active' : '' ?>" href="<?= h(app_url('/mbi_annonces_index.php?transaction=location')) ?>">Louer</a>

      <div class="mbi-nav-dd">
        <button type="button" class="mbi-nav-link mbi-nav-ddbtn <?= $mbiNavActive === 'metiers' ? 'is-active' : '' ?>" aria-haspopup="true" aria-expanded="false">
          Nos métiers <span class="mbi-nav-caret" aria-hidden="true">▾</span>
        </button>
        <div class="mbi-nav-ddmenu" role="menu">
          <?php foreach ($mbiMetiers as [$mLabel, $mUrl, $mIcon]): ?>
            <a class="mbi-nav-dditem" role="menuitem" href="<?= h($mUrl) ?>"><span class="mbi-nav-ddicon" aria-hidden="true"><?= $mIcon ?></span><?= h($mLabel) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <a class="mbi-nav-link <?= $mbiNavActive === 'agences' ? 'is-active' : '' ?>" href="<?= h(app_url('/mbi_annonces_agences.php')) ?>">Nos agences</a>
      <a class="mbi-nav-link <?= $mbiNavActive === 'contact' ? 'is-active' : '' ?>" href="<?= h(app_url('/mbi_annonces_contact_general.php')) ?>">Contact</a>
    </nav>

    <div class="mbi-header-actions">
      <button class="mbi-client-gestion" type="button" data-mbi-agency-open aria-haspopup="dialog" aria-controls="mbi-agency-modal">Accès Client EMERY</button>
      <a class="mbi-services-fab" href="<?= h(app_url('/accueil.php')) ?>" title="Nos services pro &amp; particuliers" aria-label="Nos services pro et particuliers">
        <img src="<?= h(asset_url('/images/icons/boutons/fluxbox.png')) ?>" alt="" width="26" height="26">
      </a>
      <a class="mbi-btn mbi-btn-ghost mbi-user-login" href="<?= h(app_url('/login.php')) ?>" aria-label="Connexion utilisateurs Ma Box Immo"><span class="mbi-user-login-long">Connexion utilisateurs MBI</span><span class="mbi-user-login-short">Connexion MBI</span></a>
    </div>
  </div>
</header>

<div class="mbi-agency-modal" id="mbi-agency-modal" hidden>
  <div class="mbi-agency-modal-backdrop" data-mbi-agency-close></div>
  <section class="mbi-agency-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mbi-agency-modal-title">
    <button class="mbi-agency-modal-close" type="button" data-mbi-agency-close aria-label="Fermer la fenêtre">×</button>
    <h2 class="mbi-fcard-title mbi-agency-modal-title" id="mbi-agency-modal-title">📍 Choisissez votre agence...</h2>
    <div class="mbi-fcard-grid mbi-fcard-grid-3col mbi-agency-grid">
      <a class="mbi-fcardbtn mbi-agency-card" href="https://extranet2.ics.fr/V5/connexion-locaimmo_acig.html">
        <span class="mbi-fcardbtn-txt">LYON GESTION</span><span class="mbi-fcardbtn-cp" aria-hidden="true">→</span>
      </a>
      <a class="mbi-fcardbtn mbi-agency-card" href="https://extranet2.ics.fr/V5/connexion-servajean.html">
        <span class="mbi-fcardbtn-txt">CHAMALIÈRES GESTION</span><span class="mbi-fcardbtn-cp" aria-hidden="true">→</span>
      </a>
      <a class="mbi-fcardbtn mbi-agency-card" href="https://extranet2.ics.fr/V5/connexion-servajean.html">
        <span class="mbi-fcardbtn-txt">RIOM GESTION</span><span class="mbi-fcardbtn-cp" aria-hidden="true">→</span>
      </a>
      <?php foreach (['CHAPONOST GESTION', 'VIENNE GESTION ET SYNDIC', 'MIONS SYNDIC', 'LYON CHAPONOST SYNDIC'] as $__agencyPending): ?>
        <div class="mbi-fcardbtn mbi-agency-card is-pending" aria-disabled="true">
          <span class="mbi-fcardbtn-txt"><?= h($__agencyPending) ?></span><small>Lien à venir</small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<script>
(() => {
  const modal = document.getElementById('mbi-agency-modal');
  const opener = document.querySelector('[data-mbi-agency-open]');
  if (!modal || !opener) return;

  const close = () => {
    modal.hidden = true;
    document.body.classList.remove('mbi-modal-open');
    opener.focus();
  };
  const open = () => {
    modal.hidden = false;
    document.body.classList.add('mbi-modal-open');
    modal.querySelector('[data-mbi-agency-close]').focus();
  };

  opener.addEventListener('click', open);
  modal.querySelectorAll('[data-mbi-agency-close]').forEach((element) => element.addEventListener('click', close));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
})();
</script>

<main class="mbi-main" role="main">
