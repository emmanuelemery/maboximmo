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

$title     = (string)($mbiMeta['title']       ?? 'MaBoxImmo — Annonces immobilières');
$desc      = (string)($mbiMeta['description'] ?? 'Le portail immobilier pensé pour particuliers et professionnels. Acheter, louer, estimer.');
$canonical = (string)($mbiMeta['canonical']   ?? '');
$robots    = (string)($mbiMeta['robots']      ?? 'index, follow');
$image     = (string)($mbiMeta['image']       ?? '');

$navItems = [
    'acheter'    => ['Acheter',                app_url('/mbi_annonces_recherche.php?transaction=vente')],
    'louer'      => ['Louer',                  app_url('/mbi_annonces_recherche.php?transaction=location')],
    'entreprise' => ['Immobilier d\'entreprise', app_url('/mbi_annonces_recherche.php?categorie=entreprise')],
    'estimer'    => ['Estimer',                app_url('/mbi_annonces_recherche.php?tri=recent#estimer')],
    'pro'        => ['Espace Pro',             app_url('/agence_portail.php')],
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
  <link rel="stylesheet" href="<?= h(asset_url('/css/variables.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('/css/mbi_annonces.css')) ?>">
  <?= $mbiJsonLd ?>
</head>
<body class="mbi-body <?= h($mbiBodyClass) ?>">

<header class="mbi-header" role="banner">
  <div class="mbi-container mbi-header-inner">
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

    <nav class="mbi-nav" aria-label="Navigation principale">
      <?php foreach ($navItems as $key => [$label, $url]): ?>
        <a class="mbi-nav-link <?= $mbiNavActive === $key ? 'is-active' : '' ?>" href="<?= h($url) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="mbi-header-actions">
      <a class="mbi-btn mbi-btn-ghost" href="<?= h(app_url('/login.php')) ?>">Se connecter</a>
    </div>
  </div>
</header>

<main class="mbi-main" role="main">
