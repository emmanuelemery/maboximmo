<?php
declare(strict_types=1);

// Entrées attendues :
// - $vMeta (title, description, canonical, image, robots)
// - $vJsonLd (string HTML)
// - $agence (array)
// - $navActive (string)

$vMeta = $vMeta ?? [];
$vJsonLd = $vJsonLd ?? '';
$agence = $agence ?? [];
$navActive = (string)($navActive ?? '');

$title = (string)($vMeta['title'] ?? 'Vitrine agence');
$desc = (string)($vMeta['description'] ?? '');
$canonical = (string)($vMeta['canonical'] ?? '');
$robots = (string)($vMeta['robots'] ?? 'index, follow');
$image = (string)($vMeta['image'] ?? '');

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <?php if ($desc !== ''): ?><meta name="description" content="<?= h($desc) ?>"><?php endif; ?>
  <meta name="robots" content="<?= h($robots) ?>">
  <meta name="theme-color" content="#2d5f6b">
  <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= h($canonical) ?>"><?php endif; ?>
  <?= og_tags(['title' => $title, 'description' => $desc, 'url' => $canonical !== '' ? $canonical : null, 'image' => $image !== '' ? $image : null]) ?>
  <link rel="stylesheet" href="<?= h(asset_url('/css/variables.css')) ?>">
  <link rel="stylesheet" href="<?= h(asset_url('/css/vitrine.css')) ?>">
  <?= $vJsonLd ?>
</head>
<body>

<header class="v-header">
  <div class="v-container v-header-inner">
    <a class="v-brand" href="<?= h(vitrine_path((string)($agence['slug'] ?? ''), '/')) ?>">
      <?php if (!empty($agence['logo_url'])): ?>
        <img class="v-brand-logo" src="<?= h(app_url('/' . ltrim((string)$agence['logo_url'], '/'))) ?>" alt="<?= h((string)($agence['nom_agence'] ?? 'Agence')) ?>" width="44" height="44" loading="eager" decoding="async">
      <?php else: ?>
        <div class="v-brand-mark">🏠</div>
      <?php endif; ?>
      <div class="v-brand-text">
        <div class="v-brand-title"><?= h((string)($agence['nom_agence'] ?? 'Agence')) ?></div>
        <div class="v-brand-sub"><?= h((string)($agence['ville'] ?? '')) ?></div>
      </div>
    </a>

    <nav class="v-nav">
      <?php
        $slug = (string)($agence['slug'] ?? '');
        $links = [
          ['k' => 'home', 'label' => 'Accueil', 'href' => vitrine_path($slug, '/')],
          ['k' => 'annonces', 'label' => 'Annonces', 'href' => vitrine_path($slug, '/annonces')],
          ['k' => 'transaction-immobiliere', 'label' => 'Vente & location', 'href' => vitrine_path($slug, '/transaction-immobiliere')],
          ['k' => 'gestion-locative', 'label' => 'Gestion', 'href' => vitrine_path($slug, '/gestion-locative')],
          ['k' => 'syndic-copropriete', 'label' => 'Syndic', 'href' => vitrine_path($slug, '/syndic-copropriete')],
          ['k' => 'estimation-gratuite', 'label' => 'Estimation gratuite', 'href' => vitrine_path($slug, '/estimation-gratuite')],
        ];
      ?>
      <?php foreach ($links as $ln): ?>
        <a class="v-nav-link <?= $navActive === $ln['k'] ? 'is-active' : '' ?>" href="<?= h($ln['href']) ?>"><?= h($ln['label']) ?></a>
      <?php endforeach; ?>
      <?php if (!empty($agence['id'])): ?>
        <a class="v-nav-link" href="<?= h(app_url('/mbi_annonces_index.php?net_agence=' . (int)$agence['id'])) ?>" title="Accéder au portail d'annonces de l'agence">Portail annonces ↗</a>
      <?php endif; ?>
      <a class="v-btn v-btn-primary" href="<?= h(vitrine_path($slug, '/estimation-gratuite')) ?>">Demander un rappel</a>
    </nav>
  </div>
</header>

<main class="v-main">
  <div class="v-container">

