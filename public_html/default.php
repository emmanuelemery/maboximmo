<?php
// Gestion des redirections 301/302/410 (doit être en PREMIER!)
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/redirect_handler.php';

$canonicalPath = parse_url($_SERVER['REQUEST_URI'] ?? '/default.php', PHP_URL_PATH);
if (!is_string($canonicalPath) || $canonicalPath === '') {
  $canonicalPath = '/default.php';
}
$siteUrl = rtrim(str_replace('/default.php', '', $canonicalPath), '/');
if ($siteUrl === '') {
  $siteUrl = '/';
}
$sitePrefix = $siteUrl === '/' ? '' : $siteUrl;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MaBoxImmo - Portail immobilier</title>
  <meta name="description" content="MaBoxImmo, portail immobilier professionnel : gestion des biens, mandats, baux, visites et services pour bailleurs." />
  <meta name="robots" content="index, follow" />
  <meta name="theme-color" content="#0a0f14" />
  <link rel="canonical" href="<?= htmlspecialchars($canonicalPath) ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="MaBoxImmo" />
  <meta property="og:title" content="MaBoxImmo - Portail immobilier" />
  <meta property="og:description" content="MaBoxImmo, portail immobilier professionnel : gestion des biens, mandats, baux, visites et services pour bailleurs." />
  <meta property="og:url" content="<?= htmlspecialchars($canonicalPath) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "MaBoxImmo",
      "url": "<?= htmlspecialchars($siteUrl) ?>"
    }
  </script>
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "MaBoxImmo",
      "url": "<?= htmlspecialchars($siteUrl) ?>",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "<?= htmlspecialchars($sitePrefix) ?>/bien_recherche.php?q={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
  </script>
  <style>
    :root {
      --bg: var(--bg-secondary);
      --bg-soft: #ffffff;
      --sidebar: #ffffff;
      --ink: #d0e4ff;
      --muted: #7a91a8;
      --accent: #4878a6;
      --accent-2: #ffd479;
      --accent-3: #4a6038;
      --card: #ffffff;
      --stroke: rgba(255, 255, 255, 0.15);
      --glow: 0 20px 60px rgba(102, 217, 255, 0.22);
      --radius-lg: 28px;
      --radius-md: 18px;
      --radius-sm: 12px;
      --max: 1200px;
    }

    * {
      box-sizing: border-box;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      margin: 0;
      font-family: "Manrope", system-ui, -apple-system, sans-serif;
      background: radial-gradient(1200px 600px at 80% -10%, rgba(102, 217, 255, 0.1), transparent 60%),
                  radial-gradient(900px 500px at 10% 20%, rgba(124, 245, 214, 0.08), transparent 55%),
                  var(--bg);
      color: var(--ink);
      overflow-x: hidden;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    img {
      max-width: 100%;
      display: block;
      border-radius: 20px;
    }

    .page {
      position: relative;
      isolation: isolate;
    }

    .aurora {
      position: absolute;
      inset: -40vh -20vw auto -20vw;
      height: 60vh;
      background: conic-gradient(from 20deg, rgba(102, 217, 255, 0.25), rgba(255, 212, 121, 0.16), rgba(124, 245, 214, 0.2), rgba(102, 217, 255, 0.25));
      filter: blur(80px);
      opacity: 0.6;
      z-index: -2;
      animation: floatGlow 14s ease-in-out infinite;
    }

    .noise {
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
      pointer-events: none;
      mix-blend-mode: soft-light;
      z-index: -1;
    }

    .container {
      width: min(100% - 32px, var(--max));
      margin: 0 auto;
    }

    .nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 24px 0 12px;
      position: sticky;
      top: 0;
      backdrop-filter: blur(18px);
      z-index: 20;
    }

    .page::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100px;
      background: linear-gradient(to bottom, rgba(7, 18, 27, 0.8), rgba(7, 18, 27, 0.6));
      z-index: 19;
      pointer-events: none;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 700;
      letter-spacing: 0.5px;
    }

    .logo-mark {
      width: 40px;
      height: 40px;
      border-radius: 14px;
      background: linear-gradient(135deg, #4878a6, #1c4dff);
      box-shadow: var(--glow);
      display: grid;
      place-items: center;
      font-size: 18px;
    }

    .nav-links {
      display: flex;
      gap: 2 px;
      align-items: center;
      font-size: 14px;
      color: var(--muted);
    }

    .nav-links a {
      padding: 6px 8px;
      border-radius: 999px;
      transition: 0.2s ease;
    }

    .nav-links a:hover {
      background: rgba(255, 255, 255, 0.08);
      color: var(--ink);
    }

    .cta {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 18px;
      border-radius: 999px;
      background: linear-gradient(135deg, rgba(102, 217, 255, 0.9), rgba(28, 77, 255, 0.8));
      color: #07121b;
      font-weight: 700;
      box-shadow: var(--glow);
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .nav-actions .btn {
      padding: 10px 16px;
      border-radius: 999px;
      border: 1px solid var(--stroke);
      color: var(--ink);
      background: rgba(255, 255, 255, 0.04);
      font-size: 14px;
      font-weight: 600;
      transition: 0.2s ease;
    }

    .nav-actions .btn:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .hero {
      padding: 60px 0 80px;
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 40px;
      align-items: center;
      position: relative;
    }

    .hero h1 {
      font-family: "Playfair Display", serif;
      font-size: clamp(40px, 5vw, 72px);
      line-height: 1.05;
      margin: 0 0 20px;
    }

    .hero h1 span {
      background: linear-gradient(120deg, #4878a6, #ffd479, #4a6038);
      -webkit-background-clip: text;
      color: transparent;
    }

    .hero p {
      font-size: 18px;
      color: var(--muted);
      margin-bottom: 28px;
    }

    .hero-actions {
      position: absolute;
      top: 20px;
      right: -20px;
      display: flex;
      flex-wrap: nowrap;
      gap: 10px;
      justify-content: flex-end;
      width: auto;
      z-index: 10;
    }

    .btn {
      padding: 8px 14px;
      border-radius: 10px;
      border: 1px solid var(--stroke);
      color: var(--ink);
      background: rgba(255, 255, 255, 0.04);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      font-size: 13px;
      white-space: nowrap;
    }

    .btn.primary {
      background: linear-gradient(135deg, #5a6b7a 0%, #4a5a6a 100%);
      color: #e0e0e0;
      font-weight: 700;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      border: 1px solid #6a7b8a !important;
      transition: all 0.3s ease;
    }

    .btn.primary:hover {
      background: linear-gradient(135deg, #6a7b8a 0%, #5a6b7a 100%);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
      transform: translateY(-2px);
    }

    .btn:hover {
      transform: translateY(-2px);
    }

    .btn-icon-square {
      width: 180px;
      height: 120px;
      padding: 3px;
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(218, 165, 32, 0.95), rgba(184, 134, 11, 0.85));
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      transition: all 0.3s ease;
      box-shadow: 0 10px 30px rgba(184, 134, 11, 0.4);
    }

    .btn-icon-square img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 12px;
    }

    .btn-icon-square:hover {
      transform: scale(1.05) translateY(-4px);
      box-shadow: 0 15px 40px rgba(184, 134, 11, 0.5);
      background: linear-gradient(135deg, rgba(238, 180, 34, 0.95), rgba(154, 105, 10, 0.9));
    }

    .btn-icon-square.green {
      background: linear-gradient(135deg, rgba(128, 128, 64, 0.95), rgba(85, 85, 34, 0.85));
      box-shadow: 0 10px 30px rgba(100, 100, 50, 0.35);
    }

    .btn-icon-square.green:hover {
      box-shadow: 0 15px 40px rgba(100, 100, 50, 0.5);
      background: linear-gradient(135deg, rgba(148, 148, 84, 0.95), rgba(75, 75, 24, 0.9));
    }

    .btn-icon-square.teal {
      background: linear-gradient(135deg, rgba(45, 135, 165, 0.95), rgba(20, 75, 110, 0.85));
      box-shadow: 0 10px 30px rgba(30, 100, 130, 0.35);
    }

    .btn-icon-square.teal:hover {
      box-shadow: 0 15px 40px rgba(30, 100, 130, 0.5);
      background: linear-gradient(135deg, rgba(65, 155, 185, 0.95), rgba(15, 60, 100, 0.9));
    }

    .hero-card {
      background: linear-gradient(180deg, #ffffff, rgba(255,255,255,0.02));
      border: 1.5px solid rgba(102, 217, 255, 0.3);
      border-radius: var(--radius-lg);
      padding: 20px;
      box-shadow: 0 30px 80px rgba(9, 13, 20, 0.55),
                  0 0 30px rgba(102, 217, 255, 0.15);
      position: relative;
      overflow: hidden;
      margin-left: 75px;
    }

    .hero-card::after {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 20% 0%, rgba(102, 217, 255, 0.2), transparent 45%);
      opacity: 0.8;
      pointer-events: none;
    }

    .hero-card img {
      border-radius: 22px;
      height: 420px;
      object-fit: cover;
    }

    .hero-tiles {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-top: 18px;
    }

    .tile {
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid var(--stroke);
      border-radius: 14px;
      padding: 12px;
      font-size: 12px;
      color: var(--muted);
    }

    .tile strong {
      display: block;
      color: var(--ink);
      font-size: 16px;
      margin-bottom: 4px;
    }

    .search-shell {
      margin-top: 32px;
      background: linear-gradient(135deg, rgba(12, 18, 28, 0.95) 0%, rgba(20, 35, 55, 0.9) 100%);
      border: 2px solid #4a6038;
      border-radius: 20px;
      padding: 28px;
      display: grid;
      gap: 18px;
      box-shadow: 0 0 40px rgba(124, 245, 214, 0.4),
                  0 0 80px rgba(124, 245, 214, 0.15),
                  inset 0 0 20px rgba(124, 245, 214, 0.1);
      position: relative;
      backdrop-filter: blur(12px);
      animation: glowPulse 3s ease-in-out infinite;
    }

    @keyframes glowPulse {
      0%, 100% { box-shadow: 0 0 40px rgba(124, 245, 214, 0.4), 0 0 80px rgba(124, 245, 214, 0.15), inset 0 0 20px rgba(124, 245, 214, 0.1); }
      50% { box-shadow: 0 0 60px rgba(124, 245, 214, 0.6), 0 0 120px rgba(124, 245, 214, 0.25), inset 0 0 30px rgba(124, 245, 214, 0.15); }
    }

    .search-top {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      font-size: 14px;
      font-weight: 600;
      color: #4a6038;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .search-title {
      font-size: 28px;
      font-weight: 800;
      color: #ffffff;
      text-transform: none;
      letter-spacing: 0;
      line-height: 1.3;
    }

    .search-filters {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .pill {
      padding: 10px 18px;
      border-radius: 999px;
      border: 1.5px solid rgba(124, 245, 214, 0.5);
      color: #4a6038;
      background: rgba(124, 245, 214, 0.08);
      cursor: pointer;
      transition: all 0.3s ease;
      font-weight: 600;
      font-size: 13px;
    }

    .pill:hover {
      background: rgba(124, 245, 214, 0.15);
      border-color: #4a6038;
      box-shadow: 0 0 15px rgba(124, 245, 214, 0.4);
    }

    .search-input {
      display: grid;
      grid-template-columns: 1.6fr 1fr 1fr auto;
      gap: 12px;
      align-items: center;
    }

    .search-input input,
    .search-input select {
      background: rgba(124, 245, 214, 0.05);
      border: 1.5px solid rgba(124, 245, 214, 0.3);
      color: var(--ink);
      padding: 14px 16px;
      border-radius: 12px;
      font-size: 14px;
      transition: all 0.3s ease;
    }

    .search-input input::placeholder {
      color: rgba(255, 255, 255, 0.4);
    }

    .search-input input:focus,
    .search-input select:focus {
      outline: none;
      background: rgba(124, 245, 214, 0.1);
      border-color: #4a6038;
      box-shadow: 0 0 20px rgba(124, 245, 214, 0.3);
    }

    .search-input button {
      border-radius: 12px;
      border: none;
      padding: 14px 32px;
      font-weight: 700;
      font-size: 14px;
      background: linear-gradient(135deg, #4a6038 0%, rgba(124, 245, 214, 0.8) 100%);
      color: #041017;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 0 30px rgba(124, 245, 214, 0.5);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 700;
    }

    .search-input button:hover {
      transform: translateY(-2px);
      box-shadow: 0 0 50px rgba(124, 245, 214, 0.7), 0 10px 30px rgba(124, 245, 214, 0.4);
    }

    .search-input button:active {
      transform: translateY(0);
    }

    section {
      padding: 80px 0;
    }

    .section-title {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 16px;
      margin-bottom: 32px;
    }

    .section-title h2 {
      margin: 0;
      font-size: clamp(28px, 3vw, 42px);
      font-family: "Playfair Display", serif;
    }

    .section-title p {
      margin: 0;
      color: var(--muted);
      max-width: 460px;
    }

    .grid-3 {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .glass-card {
      background: var(--card);
      border: 1px solid var(--stroke);
      border-radius: var(--radius-md);
      padding: 22px;
      backdrop-filter: blur(12px);
      min-height: 200px;
      display: grid;
      gap: 16px;
      position: relative;
      overflow: hidden;
    }

    .glass-card::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 100% 0%, rgba(255, 212, 121, 0.15), transparent 45%);
      opacity: 0.6;
      pointer-events: none;
    }

    .glass-card h3 {
      margin: 0;
      font-size: 18px;
    }

    .glass-card p {
      color: var(--muted);
      margin: 0;
    }

    .listing-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .listing {
      background: rgba(12, 18, 28, 0.92);
      border-radius: 20px;
      overflow: hidden;
      border: 1px solid var(--stroke);
      display: grid;
    }

    .listing-body {
      padding: 18px;
      display: grid;
      gap: 8px;
    }

    .listing .price {
      font-size: 20px;
      font-weight: 700;
    }

    .listing .meta {
      color: var(--muted);
      font-size: 14px;
    }

    .cta-panel {
      background: linear-gradient(135deg, rgba(102, 217, 255, 0.16), rgba(255, 212, 121, 0.16));
      border-radius: 24px;
      padding: 32px;
      display: grid;
      grid-template-columns: 1.2fr 0.8fr;
      gap: 20px;
      border: 1px solid var(--stroke);
    }

    .cta-panel h3 {
      margin: 0 0 10px;
      font-size: 26px;
    }

    .cta-panel p {
      color: var(--muted);
      margin: 0 0 18px;
    }

    .footer {
      padding: 40px 0 60px;
      border-top: 1px solid var(--stroke);
      color: var(--muted);
      font-size: 14px;
    }

    .footer-grid {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 18px;
    }

    .reveal {
      opacity: 0;
      transform: translateY(20px);
      transition: 0.8s ease;
    }

    .reveal.is-visible {
      opacity: 1;
      transform: translateY(0);
    }

    @media (max-width: 980px) {
      .hero {
        grid-template-columns: 1fr;
      }
      .search-input {
        grid-template-columns: 1fr;
      }
      .grid-3,
      .listing-grid {
        grid-template-columns: 1fr;
      }
      .cta-panel {
        grid-template-columns: 1fr;
      }
      .nav-links {
        display: none;
      }
    }

    @keyframes floatGlow {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(12px); }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="aurora"></div>
    <div class="noise"></div>

    <div class="container nav">
      <div class="logo">
        <div class="logo-mark">MI</div>
        <div>MaBoxImmo</div>
      </div>
      <div class="nav-links">
        <a href="#">Acheter</a>
        <a href="#">Louer</a>
        <a href="#">Estimer</a>
        <a href="annonce_nouvelle.php">Déposer annonce</a>
        <a href="#">Espace Membre MBi</a>
        <a href="#">Contact</a>
      </div>
      <div class="nav-actions">
        <a class="btn" href="agence_inscription.php">Créer son Compte</a>
        <a class="cta" href="login.php">Connexion</a>
      </div>
    </div>

    <div class="container hero" style="padding-top: 40px;">
      <div class="hero-copy reveal" data-reveal style="transition-delay: .05s;">
        <div style="font-size: 75px !important; display: flex; align-items: center; overflow: visible; line-height: 1; margin-bottom: 30px;">Ma B<svg width="65" height="75" viewBox="0 0 55 55" style="display: inline-block; flex-shrink: 0; margin-bottom: -15px; margin-top: -3px; margin-left: -10px; margin-right: -10px;">
  <defs>
    <radialGradient id="pinGradient" cx="35%" cy="35%">
      <stop offset="0%" style="stop-color:#FFB366;stop-opacity:1" />
      <stop offset="70%" style="stop-color:#FF8C42;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#E67E22;stop-opacity:1" />
    </radialGradient>
    <filter id="shadow" x="-30%" y="-30%" width="160%" height="160%">
      <feDropShadow dx="1" dy="2" stdDeviation="2" flood-opacity="0.2"/>
    </filter>
  </defs>

  <!-- Pin de localisation 3D -->
  <g filter="url(#shadow)">
    <!-- Corps du pin -->
    <path d="M 27.5 8 C 19 8 12 15 12 23 C 12 33 27.5 50 27.5 50 C 27.5 50 43 33 43 23 C 43 15 36 8 27.5 8 Z"
          fill="url(#pinGradient)"/>

    <!-- Highlight 3D -->
    <ellipse cx="23" cy="17" rx="5" ry="6" fill="white" opacity="0.35"/>

    <!-- Cercle central blanc -->
    <circle cx="27.5" cy="23" r="7" fill="white" opacity="0.95"/>
    <circle cx="27.5" cy="23" r="6.5" fill="#FF8C42" opacity="0.15"/>
  </g>
</svg>x Immo</div>

      <div style="font-size: 35px !important;">Le 1er portail immobilier pensé pour les particuliers et les professionnels.</div>

        <div style="margin-top: 40px;">
         
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
              <p style="font-size: 16px; margin-bottom: 15px; color: var(--ink); font-weight: 600;">Devenir Membre Mbi</p>
              <a class="btn-icon-square" href="#membre1" title="Devenir Membre">
                <img src="images/icons/boutons/membre.png" alt="Membre MBi">
              </a>
            </div>
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
              <p style="font-size: 16px; margin-bottom: 15px; color: var(--ink); font-weight: 600;">Estimer mon bien</p>
              <a class="btn-icon-square green" href="#estimation" title="Estimation de mon bien">
                <img src="images/icons/boutons/estimation.png" alt="Estimation">
              </a>
            </div>
            <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
              <p style="font-size: 16px; margin-bottom: 4px; color: var(--ink); font-weight: 600;">Diffuser mon annonce</p>
              <p style="font-size: 11px; margin-bottom: 11px; color: var(--muted); font-weight: 500;">Particulier · Vendeur · Bailleur</p>
              <a class="btn-icon-square teal" href="annonce_nouvelle.php" title="Diffuser mon annonce sur MaBoxImmo">
                <img src="images/icons/boutons/a vendre.png" alt="Diffuser mon annonce">
              </a>
            </div>
          </div>
        </div>

        <div class="search-shell reveal" data-reveal style="transition-delay: .1s; position: relative;">
          <div class="search-top">
            <div class="search-title">Trouver votre<br>logement</div>
            <div class="search-filters">
              <span class="pill">Transaction</span>
              <span class="pill">Location</span>
              
            </div>
          </div>
          <div class="search-input">
            <input type="text" placeholder="Ville, quartier, adresse...">
            <select>
              <option>Budget max</option>
              <option>250 000 €</option>
              <option>500 000 €</option>
              <option>1 000 000 €</option>
            </select>
            <select>
              <option>Surface min</option>
              <option>40 m²</option>
              <option>80 m²</option>
              <option>120 m²</option>
            </select>
            <button>Trouver</button>
          </div>
        </div>
      </div>

      <div class="hero-card reveal" data-reveal style="transition-delay: .15s;">
        <img src="https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=1200&q=80" alt="Bien immobilier premium">
        <div class="hero-tiles">
          <div class="tile"><strong>248</strong>Biens suivis</div>
          <div class="tile"><strong>92%</strong>Taux de conversion</div>
          <div class="tile"><strong>+35%</strong>Temps gagné</div>
        </div>
      </div>
    </div>

    <section id="services">
      <div class="container">
        <div class="section-title reveal" data-reveal>
          <h1 style="font-size:inherit;margin:inherit">Une suite complète, pensée pour les agences exigeantes.</h1>
          <p>Modulaire, évolutive, sécurisée. Chaque brique s’aligne sur vos flux métiers réels.</p>
        </div>

        <div class="grid-3">
          <article class="glass-card reveal" data-reveal style="transition-delay: .05s;">
            <h3>Gestion des biens & mandats</h3>
            <p>Des fiches ultra rapides, un suivi clair des étapes, et une diffusion multi‑portails maîtrisée.</p>
          </article>
          <article class="glass-card reveal" data-reveal style="transition-delay: .05s;">
            <h3>Gestion des biens & mandats</h3>
            <p>Des fiches ultra rapides, un suivi clair des étapes, et une diffusion multi‑portails maîtrisée.</p>
          </article>
          <article class="glass-card reveal" data-reveal style="transition-delay: .1s;">
            <h3>Portail copro & bailleurs</h3>
            <p>Documents, sinistres, demandes, suivi: tout est centralisé avec une traçabilité propre.</p>
          </article>
          <article class="glass-card reveal" data-reveal style="transition-delay: .15s;">
            <h3>Expérience client premium</h3>
            <p>Alertes, visites, signatures, relationnel. Le parcours client reste fluide et pro.</p>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-title reveal" data-reveal>
          <h2>Biens mis en avant</h2>
          <p>Une vitrine immersive pour valoriser chaque adresse et maximiser la conversion.</p>
        </div>
        <div class="listing-grid">
          <article class="listing reveal" data-reveal style="transition-delay: .05s;">
            <img src="https://images.unsplash.com/photo-1505691938895-1758d7feb511?auto=format&fit=crop&w=1200&q=80" alt="Appartement">
            <div class="listing-body">
              <div class="price">465 000 €</div>
              <div>Appartement signature · 92 m²</div>
              <div class="meta">Lyon 6e · Vue dégagée</div>
            </div>
          </article>
          <article class="listing reveal" data-reveal style="transition-delay: .1s;">
            <img src="https://images.unsplash.com/photo-1502005097973-6a7082348e28?auto=format&fit=crop&w=1200&q=80" alt="Maison">
            <div class="listing-body">
              <div class="price">795 000 €</div>
              <div>Maison contemporaine · 180 m²</div>
              <div class="meta">Nantes · Jardin paysager</div>
            </div>
          </article>
          <article class="listing reveal" data-reveal style="transition-delay: .15s;">
            <img src="https://images.unsplash.com/photo-1507089947368-19c1da9775ae?auto=format&fit=crop&w=1200&q=80" alt="Villa">
            <div class="listing-body">
              <div class="price">1 450 000 €</div>
              <div>Villa panorama · 260 m²</div>
              <div class="meta">Cannes · Terrasse + piscine</div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="cta-panel reveal" data-reveal>
          <div>
            <h3>Une base propre pour scaler, sans refontes inutiles.</h3>
            <p>On commence par les fondations: données normalisées, sécurité, modules progressifs. Puis on déploie page par page.</p>
            <div class="hero-actions">
              <a class="btn primary" href="dev_organisation.php">Piloter le développement</a>
              <a class="btn" href="contact.php">Demander une démo</a>
            </div>
          </div>
          <div class="glass-card">
            <h3>Indicateurs clés</h3>
            <p>Analysez vos flux et anticipez vos prochaines décisions.</p>
            <div class="tile"><strong>+28%</strong>Biens diffusés en 1 clic</div>
            <div class="tile"><strong>2 min</strong>Création d’un mandat</div>
          </div>
        </div>
      </div>
    </section>

    <footer class="footer">
      <div class="container footer-grid">
        <div>
          <strong>MaBoxImmo</strong>
          <div>Portail immobilier nouvelle génération.</div>
        </div>
        <div>
          <a href="#">Acheter</a> ·
          <a href="#">Louer</a> ·
          <a href="#">Estimer</a> ·
          <a href="#">Syndic bénévole</a>
        </div>
        <div>© 2026 MaBoxImmo</div>
      </div>
    </footer>
  </div>

  <script>
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });

    document.querySelectorAll('[data-reveal]').forEach((el) => {
      el.classList.add('reveal');
      observer.observe(el);
    });
  </script>
</body>
</html>
