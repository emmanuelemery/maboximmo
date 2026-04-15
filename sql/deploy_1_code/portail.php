<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaBoxImmo — Le portail immobilier pensé pour tous</title>
  <meta name="description" content="MaBoxImmo, le portail immobilier pensé pour les particuliers et les professionnels. Annonces, gestion locative, syndic, et bien plus.">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    /* ============================================================
       RESET & BASE
    ============================================================ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --green:        #6b8e6f;
      --green-dark:   #5a7d5e;
      --green-light:  #e8f2e9;
      --petrol:       #2d5f6b;
      --petrol-dark:  #1d4f5b;
      --petrol-light: #d6e8ec;
      --white:        #fffbf8;
      --beige:        #f4e8d8;
      --beige-light:  #faf6f0;
      --gray-dark:    #2d2d2d;
      --gray:         #6b6b6b;
      --gray-mid:     #9a9a9a;
      --gray-light:   #e5e5e5;
      --shadow-sm:    0 2px 8px rgba(0,0,0,0.07);
      --shadow-md:    0 4px 24px rgba(0,0,0,0.09);
      --shadow-lg:    0 12px 40px rgba(0,0,0,0.13);
      --radius:       16px;
      --radius-sm:    10px;
      --radius-pill:  999px;
    }

    html { scroll-behavior: smooth; }

    body {
      font-family: 'Manrope', -apple-system, sans-serif;
      background: var(--white);
      color: var(--gray-dark);
      line-height: 1.5;
      overflow-x: hidden;
    }

    a { text-decoration: none; color: inherit; }
    img { display: block; max-width: 100%; }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 0 24px;
    }

    /* ============================================================
       NAV
    ============================================================ */
    .nav {
      position: sticky;
      top: 0;
      z-index: 200;
      background: rgba(255,251,248,0.96);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--gray-light);
      height: 72px;
      display: flex;
      align-items: center;
    }

    .nav-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
    }

    .nav-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 800;
      font-size: 20px;
      color: var(--petrol);
      letter-spacing: -0.3px;
    }

    .nav-logo-icon {
      width: 38px;
      height: 38px;
      background: var(--petrol);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 18px;
      font-weight: 900;
      letter-spacing: -1px;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .nav-link {
      padding: 8px 16px;
      border-radius: var(--radius-pill);
      font-size: 14px;
      font-weight: 600;
      color: var(--gray);
      transition: background 0.18s, color 0.18s;
    }

    .nav-link:hover {
      background: var(--beige-light);
      color: var(--petrol);
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .btn-outline {
      padding: 9px 20px;
      border-radius: var(--radius-pill);
      border: 1.5px solid var(--gray-light);
      font-size: 14px;
      font-weight: 600;
      color: var(--gray-dark);
      background: transparent;
      cursor: pointer;
      transition: border-color 0.18s, background 0.18s;
    }

    .btn-outline:hover {
      border-color: var(--gray);
      background: var(--beige-light);
    }

    .btn-primary {
      padding: 9px 22px;
      border-radius: var(--radius-pill);
      border: none;
      font-size: 14px;
      font-weight: 700;
      color: #fff;
      background: var(--petrol);
      cursor: pointer;
      transition: background 0.18s, transform 0.12s;
    }

    .btn-primary:hover {
      background: var(--petrol-dark);
      transform: translateY(-1px);
    }

    /* ============================================================
       HERO
    ============================================================ */
    .hero {
      background: var(--white);
      padding: 72px 0 56px;
      text-align: center;
    }

    .hero-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--green-light);
      color: var(--green-dark);
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 6px 16px;
      border-radius: var(--radius-pill);
      margin-bottom: 24px;
    }

    .hero-eyebrow-dot {
      width: 6px;
      height: 6px;
      background: var(--green);
      border-radius: 50%;
    }

    .hero-title {
      font-size: clamp(34px, 5vw, 58px);
      font-weight: 900;
      color: var(--petrol);
      line-height: 1.1;
      letter-spacing: -1.5px;
      max-width: 800px;
      margin: 0 auto 20px;
    }

    .hero-title span {
      color: var(--green);
    }

    .hero-sub {
      font-size: 18px;
      color: var(--gray);
      max-width: 560px;
      margin: 0 auto 40px;
      font-weight: 400;
      line-height: 1.6;
    }

    /* ============================================================
       SEARCHBAR (style Airbnb)
    ============================================================ */
    .searchbar-wrap {
      max-width: 860px;
      margin: 0 auto 20px;
    }

    .searchbar {
      display: flex;
      align-items: stretch;
      background: #fff;
      border: 1.5px solid var(--gray-light);
      border-radius: var(--radius-pill);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
    }

    .search-field {
      flex: 1;
      display: flex;
      flex-direction: column;
      padding: 14px 22px;
      border-right: 1px solid var(--gray-light);
      cursor: pointer;
      transition: background 0.15s;
    }

    .search-field:last-of-type { border-right: none; }

    .search-field:hover { background: var(--beige-light); }

    .search-field label {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--gray-dark);
      margin-bottom: 4px;
    }

    .search-field input,
    .search-field select {
      border: none;
      background: transparent;
      font-family: 'Manrope', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: var(--gray);
      outline: none;
      width: 100%;
      cursor: pointer;
    }

    .search-field select option { background: #fff; }

    .search-btn {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--green);
      color: #fff;
      border: none;
      padding: 0 28px;
      font-family: 'Manrope', sans-serif;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      border-radius: 0 var(--radius-pill) var(--radius-pill) 0;
      transition: background 0.18s;
      white-space: nowrap;
    }

    .search-btn:hover { background: var(--green-dark); }

    .search-btn svg { flex-shrink: 0; }

    .hero-tags {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 16px;
    }

    .hero-tag {
      padding: 6px 16px;
      background: var(--beige-light);
      border: 1px solid var(--beige);
      border-radius: var(--radius-pill);
      font-size: 13px;
      font-weight: 600;
      color: var(--petrol);
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }

    .hero-tag:hover {
      background: var(--beige);
      border-color: var(--green);
    }

    /* ============================================================
       STATS BAND
    ============================================================ */
    .stats-band {
      background: var(--petrol);
      padding: 32px 0;
    }

    .stats-inner {
      display: flex;
      justify-content: space-around;
      align-items: center;
      flex-wrap: wrap;
      gap: 20px;
    }

    .stat-item {
      text-align: center;
      color: #fff;
    }

    .stat-value {
      font-size: 32px;
      font-weight: 900;
      letter-spacing: -1px;
      line-height: 1;
    }

    .stat-label {
      font-size: 13px;
      opacity: 0.7;
      margin-top: 4px;
      font-weight: 500;
    }

    .stat-sep {
      width: 1px;
      height: 40px;
      background: #f0f1f3;
    }

    /* ============================================================
       SECTION COMMUNE
    ============================================================ */
    .section {
      padding: 80px 0;
    }

    .section-alt {
      background: var(--beige-light);
    }

    .section-header {
      text-align: center;
      margin-bottom: 52px;
    }

    .section-eyebrow {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: var(--green);
      margin-bottom: 12px;
    }

    .section-title {
      font-size: clamp(26px, 3.5vw, 38px);
      font-weight: 900;
      color: var(--petrol);
      letter-spacing: -0.8px;
      line-height: 1.15;
      margin-bottom: 14px;
    }

    .section-desc {
      font-size: 16px;
      color: var(--gray);
      max-width: 540px;
      margin: 0 auto;
      line-height: 1.65;
    }

    /* ============================================================
       SERVICES CARDS (4 services)
    ============================================================ */
    .services-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
    }

    .service-card {
      background: #fff;
      border: 1.5px solid var(--gray-light);
      border-radius: 20px;
      padding: 36px 32px;
      position: relative;
      overflow: hidden;
      transition: box-shadow 0.22s, transform 0.22s, border-color 0.22s;
      cursor: pointer;
    }

    .service-card:hover {
      box-shadow: var(--shadow-lg);
      transform: translateY(-4px);
      border-color: var(--green);
    }

    .service-card.featured {
      grid-column: span 1;
      background: var(--petrol);
      border-color: var(--petrol);
      color: #fff;
    }

    .service-card.featured:hover {
      border-color: var(--green);
      box-shadow: 0 16px 48px rgba(45,95,107,0.3);
    }

    .service-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      margin-bottom: 20px;
    }

    .service-card:not(.featured) .service-icon {
      background: var(--beige-light);
    }

    .service-card.featured .service-icon {
      background: #ffffff;
    }

    .service-tag {
      display: inline-block;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 4px 10px;
      border-radius: var(--radius-pill);
      margin-bottom: 10px;
    }

    .service-card:not(.featured) .service-tag {
      background: var(--green-light);
      color: var(--green-dark);
    }

    .service-card.featured .service-tag {
      background: #f0f1f3;
      color: rgba(255,255,255,0.9);
    }

    .service-name {
      font-size: 22px;
      font-weight: 800;
      letter-spacing: -0.4px;
      margin-bottom: 10px;
      line-height: 1.2;
    }

    .service-card:not(.featured) .service-name { color: var(--petrol); }
    .service-card.featured .service-name { color: #fff; }

    .service-desc {
      font-size: 14px;
      line-height: 1.65;
      margin-bottom: 24px;
    }

    .service-card:not(.featured) .service-desc { color: var(--gray); }
    .service-card.featured .service-desc { color: rgba(255,255,255,0.75); }

    .service-features {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 28px;
    }

    .service-features li {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      font-weight: 600;
    }

    .service-card:not(.featured) .service-features li { color: var(--gray-dark); }
    .service-card.featured .service-features li { color: rgba(255,255,255,0.85); }

    .service-features li::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .service-card:not(.featured) .service-features li::before { background: var(--green); }
    .service-card.featured .service-features li::before { background: rgba(255,255,255,0.5); }

    .service-cta {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
      font-weight: 700;
      border-radius: var(--radius-pill);
      padding: 10px 20px;
      transition: background 0.18s, transform 0.12s;
    }

    .service-card:not(.featured) .service-cta {
      background: var(--petrol);
      color: #fff;
    }

    .service-card:not(.featured) .service-cta:hover {
      background: var(--petrol-dark);
      transform: translateX(3px);
    }

    .service-card.featured .service-cta {
      background: #fff;
      color: var(--petrol);
    }

    .service-card.featured .service-cta:hover {
      background: var(--beige-light);
      transform: translateX(3px);
    }

    .service-badge {
      position: absolute;
      top: 20px;
      right: 20px;
      background: var(--green);
      color: #fff;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 4px 12px;
      border-radius: var(--radius-pill);
    }

    /* ============================================================
       ANNONCES CARDS (aperçu Airbnb)
    ============================================================ */
    .listings-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 28px;
    }

    .listings-title {
      font-size: 24px;
      font-weight: 800;
      color: var(--petrol);
      letter-spacing: -0.4px;
    }

    .listings-see-all {
      font-size: 14px;
      font-weight: 700;
      color: var(--petrol);
      text-decoration: underline;
      text-underline-offset: 3px;
      transition: color 0.15s;
    }

    .listings-see-all:hover { color: var(--green); }

    .listings-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
    }

    .listing-card {
      border-radius: var(--radius);
      overflow: hidden;
      cursor: pointer;
      transition: transform 0.2s;
    }

    .listing-card:hover { transform: translateY(-3px); }

    .listing-card:hover .listing-img { transform: scale(1.04); }

    .listing-img-wrap {
      aspect-ratio: 4/3;
      overflow: hidden;
      border-radius: var(--radius);
      background: var(--beige);
      position: relative;
    }

    .listing-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.35s ease;
    }

    .listing-img-placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 48px;
      opacity: 0.3;
    }

    .listing-badge {
      position: absolute;
      top: 12px;
      left: 12px;
      background: #fff;
      font-size: 11px;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: var(--radius-pill);
      color: var(--petrol);
      box-shadow: var(--shadow-sm);
    }

    .listing-fav {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 32px;
      height: 32px;
      background: rgba(255,255,255,0.85);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      cursor: pointer;
      transition: background 0.15s, transform 0.15s;
    }

    .listing-fav:hover {
      background: #fff;
      transform: scale(1.1);
    }

    .listing-info {
      padding: 12px 4px 0;
    }

    .listing-location {
      font-size: 12px;
      font-weight: 600;
      color: var(--gray-mid);
      margin-bottom: 4px;
    }

    .listing-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--gray-dark);
      margin-bottom: 4px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .listing-details {
      font-size: 13px;
      color: var(--gray-mid);
      margin-bottom: 8px;
    }

    .listing-price {
      font-size: 15px;
      font-weight: 800;
      color: var(--petrol);
    }

    .listing-price span {
      font-weight: 400;
      font-size: 13px;
      color: var(--gray-mid);
    }

    /* ============================================================
       SECTION PARTENAIRES
    ============================================================ */
    .partners-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      align-items: center;
    }

    .partners-content {}

    .partners-content .section-eyebrow { text-align: left; }
    .partners-content .section-title { text-align: left; }
    .partners-content .section-desc { text-align: left; margin: 0 0 28px; }

    .partners-feature-list {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-bottom: 32px;
    }

    .partners-feature-list li {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      font-size: 14px;
      color: var(--gray);
      line-height: 1.5;
    }

    .partners-feature-icon {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: var(--petrol-light);
      color: var(--petrol);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      flex-shrink: 0;
      margin-top: 1px;
    }

    .partners-feature-text strong {
      display: block;
      color: var(--gray-dark);
      font-weight: 700;
      font-size: 14px;
      margin-bottom: 2px;
    }

    .partners-visual {
      background: var(--petrol);
      border-radius: 24px;
      padding: 36px;
      color: #fff;
    }

    .partners-visual-title {
      font-size: 18px;
      font-weight: 800;
      margin-bottom: 20px;
      opacity: 0.9;
    }

    .partner-logo-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-bottom: 24px;
    }

    .partner-logo-item {
      background: #ffffff;
      border: 1px solid #f0f1f3;
      border-radius: 12px;
      padding: 14px 10px;
      text-align: center;
      font-size: 12px;
      font-weight: 700;
      opacity: 0.8;
      transition: opacity 0.15s, background 0.15s;
      cursor: pointer;
    }

    .partner-logo-item:hover {
      opacity: 1;
      background: rgba(255,255,255,0.18);
    }

    .partner-logo-item-icon { font-size: 22px; margin-bottom: 6px; }

    .partner-cta-btn {
      display: block;
      width: 100%;
      text-align: center;
      background: #ffffff;
      border: 1px solid rgba(255,255,255,0.2);
      color: #fff;
      padding: 12px;
      border-radius: var(--radius-pill);
      font-size: 14px;
      font-weight: 700;
      transition: background 0.18s;
    }

    .partner-cta-btn:hover { background: rgba(255,255,255,0.2); }

    /* ============================================================
       COMMENT ÇA MARCHE
    ============================================================ */
    .how-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
    }

    .how-card {
      background: #fff;
      border: 1px solid var(--gray-light);
      border-radius: 20px;
      padding: 32px 28px;
      text-align: center;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .how-card:hover {
      box-shadow: var(--shadow-md);
      transform: translateY(-3px);
    }

    .how-step {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--petrol);
      color: #fff;
      font-size: 18px;
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
    }

    .how-icon {
      font-size: 36px;
      margin-bottom: 16px;
    }

    .how-title {
      font-size: 17px;
      font-weight: 800;
      color: var(--petrol);
      margin-bottom: 10px;
    }

    .how-desc {
      font-size: 14px;
      color: var(--gray);
      line-height: 1.6;
    }

    /* ============================================================
       CTA BAND
    ============================================================ */
    .cta-band {
      background: linear-gradient(135deg, var(--petrol) 0%, #1a4a55 100%);
      padding: 80px 0;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .cta-band::before {
      content: '';
      position: absolute;
      top: -60px;
      right: -60px;
      width: 300px;
      height: 300px;
      border-radius: 50%;
      background: rgba(107,142,111,0.15);
    }

    .cta-band::after {
      content: '';
      position: absolute;
      bottom: -80px;
      left: -40px;
      width: 250px;
      height: 250px;
      border-radius: 50%;
      background: #ffffff;
    }

    .cta-band-content { position: relative; z-index: 1; }

    .cta-band-eyebrow {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: rgba(255,255,255,0.6);
      margin-bottom: 16px;
    }

    .cta-band-title {
      font-size: clamp(28px, 4vw, 44px);
      font-weight: 900;
      color: #fff;
      letter-spacing: -1px;
      line-height: 1.15;
      margin-bottom: 16px;
    }

    .cta-band-desc {
      font-size: 17px;
      color: rgba(255,255,255,0.72);
      max-width: 500px;
      margin: 0 auto 36px;
      line-height: 1.6;
    }

    .cta-band-buttons {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .btn-white {
      background: #fff;
      color: var(--petrol);
      border: none;
      padding: 14px 30px;
      border-radius: var(--radius-pill);
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.15s, box-shadow 0.15s;
    }

    .btn-white:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    }

    .btn-ghost-white {
      background: transparent;
      color: #fff;
      border: 1.5px solid rgba(255,255,255,0.4);
      padding: 14px 30px;
      border-radius: var(--radius-pill);
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: background 0.18s, border-color 0.18s;
    }

    .btn-ghost-white:hover {
      background: #ffffff;
      border-color: rgba(255,255,255,0.7);
    }

    /* ============================================================
       FOOTER
    ============================================================ */
    .footer {
      background: var(--petrol);
      color: rgba(255,255,255,0.8);
      padding: 56px 0 32px;
    }

    .footer-grid {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr 1fr;
      gap: 40px;
      margin-bottom: 48px;
    }

    .footer-brand-name {
      font-size: 20px;
      font-weight: 900;
      color: #fff;
      margin-bottom: 10px;
      letter-spacing: -0.3px;
    }

    .footer-brand-desc {
      font-size: 13px;
      line-height: 1.7;
      opacity: 0.65;
      margin-bottom: 20px;
    }

    .footer-socials {
      display: flex;
      gap: 10px;
    }

    .footer-social {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      transition: background 0.15s;
      cursor: pointer;
    }

    .footer-social:hover { background: rgba(255,255,255,0.2); }

    .footer-col-title {
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #fff;
      margin-bottom: 16px;
    }

    .footer-links {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .footer-links li a {
      font-size: 13px;
      opacity: 0.65;
      transition: opacity 0.15s;
    }

    .footer-links li a:hover { opacity: 1; }

    .footer-bottom {
      border-top: 1px solid #ffffff;
      padding-top: 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .footer-bottom-text {
      font-size: 12px;
      opacity: 0.5;
    }

    .footer-bottom-links {
      display: flex;
      gap: 20px;
    }

    .footer-bottom-links a {
      font-size: 12px;
      opacity: 0.5;
      transition: opacity 0.15s;
    }

    .footer-bottom-links a:hover { opacity: 0.9; }

    /* ============================================================
       RESPONSIVE
    ============================================================ */
    @media (max-width: 1024px) {
      .listings-grid { grid-template-columns: repeat(3, 1fr); }
      .footer-grid { grid-template-columns: 1fr 1fr; }
    }

    @media (max-width: 768px) {
      .nav-links { display: none; }
      .services-grid { grid-template-columns: 1fr; }
      .listings-grid { grid-template-columns: repeat(2, 1fr); }
      .how-grid { grid-template-columns: 1fr; }
      .partners-grid { grid-template-columns: 1fr; }
      .footer-grid { grid-template-columns: 1fr; }
      .searchbar { flex-direction: column; border-radius: var(--radius); }
      .search-field { border-right: none; border-bottom: 1px solid var(--gray-light); }
      .search-btn { border-radius: 0 0 var(--radius) var(--radius); padding: 16px; justify-content: center; }
      .stat-sep { display: none; }
    }

    @media (max-width: 480px) {
      .listings-grid { grid-template-columns: 1fr; }
      .partner-logo-grid { grid-template-columns: repeat(2, 1fr); }
    }
  </style>
</head>
<body>

<!-- ============================================================
     NAV
============================================================ -->
<nav class="nav">
  <div class="container">
    <div class="nav-inner">
      <a href="portail.php" class="nav-logo">
        <div class="nav-logo-icon">MB</div>
        MaBoxImmo
      </a>
      <div class="nav-links">
        <a href="#annonces" class="nav-link">Annonces</a>
        <a href="#services" class="nav-link">Nos services</a>
        <a href="#partenaires" class="nav-link">Partenaires</a>
        <a href="#comment" class="nav-link">Comment ça marche</a>
      </div>
      <div class="nav-actions">
        <a href="login.php"><button class="btn-outline">Connexion</button></a>
        <a href="agence_inscription.php"><button class="btn-primary">S'inscrire</button></a>
      </div>
    </div>
  </div>
</nav>

<!-- ============================================================
     HERO
============================================================ -->
<section class="hero">
  <div class="container">
    <div class="hero-eyebrow">
      <span class="hero-eyebrow-dot"></span>
      Le portail immobilier nouvelle génération
    </div>
    <h1 class="hero-title">
      Pensé pour les <span>particuliers</span><br>
      et les <span>professionnels</span>
    </h1>
    <p class="hero-sub">
      Achetez, vendez, gérez vos biens, pilotez votre copropriété —
      tout sur une seule plateforme conçue pour simplifier l'immobilier.
    </p>

    <!-- Searchbar Airbnb style -->
    <div class="searchbar-wrap">
      <div class="searchbar">
        <div class="search-field">
          <label for="s-transaction">Je recherche</label>
          <select id="s-transaction">
            <option>Acheter</option>
            <option>Louer</option>
            <option>Louer en saisonnier</option>
            <option>Viager</option>
          </select>
        </div>
        <div class="search-field">
          <label for="s-type">Type de bien</label>
          <select id="s-type">
            <option>Tous types</option>
            <option>Appartement</option>
            <option>Maison</option>
            <option>Terrain</option>
            <option>Local commercial</option>
            <option>Bureau</option>
          </select>
        </div>
        <div class="search-field">
          <label for="s-localisation">Où ?</label>
          <input id="s-localisation" type="text" placeholder="Ville, code postal...">
        </div>
        <div class="search-field">
          <label for="s-budget">Budget max</label>
          <input id="s-budget" type="text" placeholder="Ex : 350 000 €">
        </div>
        <button class="search-btn" onclick="alert('Moteur de recherche en construction')">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/>
          </svg>
          Rechercher
        </button>
      </div>
    </div>

    <div class="hero-tags">
      <span class="hero-tag">🏙️ Paris</span>
      <span class="hero-tag">🌊 Bordeaux</span>
      <span class="hero-tag">☀️ Nice</span>
      <span class="hero-tag">🏔️ Grenoble</span>
      <span class="hero-tag">🌿 Lyon</span>
      <span class="hero-tag">🏖️ Montpellier</span>
      <span class="hero-tag">🦁 Lille</span>
    </div>
  </div>
</section>

<!-- ============================================================
     STATS
============================================================ -->
<div class="stats-band">
  <div class="container">
    <div class="stats-inner">
      <div class="stat-item">
        <div class="stat-value">12 000+</div>
        <div class="stat-label">Annonces actives</div>
      </div>
      <div class="stat-sep"></div>
      <div class="stat-item">
        <div class="stat-value">850+</div>
        <div class="stat-label">Agences partenaires</div>
      </div>
      <div class="stat-sep"></div>
      <div class="stat-item">
        <div class="stat-value">98 villes</div>
        <div class="stat-label">En France</div>
      </div>
      <div class="stat-sep"></div>
      <div class="stat-item">
        <div class="stat-value">4 services</div>
        <div class="stat-label">Pour tous les besoins</div>
      </div>
      <div class="stat-sep"></div>
      <div class="stat-item">
        <div class="stat-value">100%</div>
        <div class="stat-label">Sécurisé & RGPD</div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     ANNONCES (aperçu)
============================================================ -->
<section class="section" id="annonces">
  <div class="container">
    <div class="listings-header">
      <h2 class="listings-title">Annonces récentes</h2>
      <a href="bien_recherche.php" class="listings-see-all">Voir toutes les annonces →</a>
    </div>
    <div class="listings-grid">

      <?php
      // Annonces fictives de démonstration (à remplacer par requête BDD)
      $demo_listings = [
        ['type'=>'Appartement','titre'=>'T3 lumineux vue dégagée','ville'=>'Lyon 6e','surface'=>68,'pieces'=>3,'prix'=>289000,'badge'=>'Exclusivité','emoji'=>'🏙️','tx'=>'Vente'],
        ['type'=>'Maison','titre'=>'Maison avec jardin 800m²','ville'=>'Villeurbanne','surface'=>124,'pieces'=>5,'prix'=>485000,'badge'=>'Coup de cœur','emoji'=>'🏡','tx'=>'Vente'],
        ['type'=>'Appartement','titre'=>'Studio rénové centre-ville','ville'=>'Grenoble','surface'=>32,'pieces'=>1,'prix'=>720,'badge'=>'Location','emoji'=>'🏢','tx'=>'Location'],
        ['type'=>'Local com.','titre'=>'Local commercial 90m²','ville'=>'Paris 11e','surface'=>90,'pieces'=>null,'prix'=>3200,'badge'=>'Pro','emoji'=>'🏪','tx'=>'Location'],
      ];
      foreach ($demo_listings as $l):
      ?>
      <div class="listing-card">
        <div class="listing-img-wrap">
          <div class="listing-img-placeholder"><?= $l['emoji'] ?></div>
          <span class="listing-badge"><?= htmlspecialchars($l['badge']) ?></span>
          <div class="listing-fav">♡</div>
        </div>
        <div class="listing-info">
          <div class="listing-location"><?= htmlspecialchars($l['ville']) ?> · <?= htmlspecialchars($l['type']) ?></div>
          <div class="listing-title"><?= htmlspecialchars($l['titre']) ?></div>
          <div class="listing-details">
            <?= $l['surface'] ?>m²
            <?= $l['pieces'] ? ' · ' . $l['pieces'] . ' pièces' : '' ?>
          </div>
          <div class="listing-price">
            <?= number_format($l['prix'], 0, ',', ' ') ?> €
            <span><?= $l['tx'] === 'Location' ? '/mois' : ' FAI' ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

    </div>
  </div>
</section>

<!-- ============================================================
     NOS 4 SERVICES
============================================================ -->
<section class="section section-alt" id="services">
  <div class="container">
    <div class="section-header">
      <div class="section-eyebrow">Nos services</div>
      <h2 class="section-title">Une plateforme, quatre univers</h2>
      <p class="section-desc">
        Du particulier qui recherche son logement à l'agence qui gère des centaines de mandats —
        MaBoxImmo s'adapte à chaque profil.
      </p>
    </div>
    <div class="services-grid">

      <!-- SERVICE 1 : Ma Box Immo (portail public) -->
      <div class="service-card featured">
        <span class="service-badge">Portail public</span>
        <div class="service-icon">🏠</div>
        <div class="service-tag">Particuliers & Pros</div>
        <h3 class="service-name">Ma Box Immo</h3>
        <p class="service-desc">
          Le moteur de recherche immobilier pensé pour tous. Achetez, vendez ou louez
          avec des annonces vérifiées, des alertes personnalisées et des outils de simulation.
        </p>
        <ul class="service-features">
          <li>Annonces vente, location & neuf</li>
          <li>Alertes email & push en temps réel</li>
          <li>Simulation de prêt intégrée</li>
          <li>Carte interactive & secteurs</li>
          <li>DPE, diagnostics, documents</li>
        </ul>
        <a href="bien_recherche.php" class="service-cta">
          Chercher une annonce
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
        </a>
      </div>

      <!-- SERVICE 2 : Ma Box Agency -->
      <div class="service-card">
        <div class="service-icon">🏢</div>
        <div class="service-tag">Professionnels</div>
        <h3 class="service-name">Ma Box Agency</h3>
        <p class="service-desc">
          La suite complète pour les agences immobilières : gestion des mandats, diffusion multi-portails,
          CRM clients, statistiques de performance et gestion d'équipe.
        </p>
        <ul class="service-features">
          <li>Gestion mandats & négociateurs</li>
          <li>Diffusion automatique multi-portails</li>
          <li>CRM & suivi des prospects</li>
          <li>Statistiques & rapports</li>
          <li>Import flux agences partenaires</li>
        </ul>
        <a href="login_agency.php" class="service-cta">
          Accès agence
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
        </a>
      </div>

      <!-- SERVICE 3 : Ma Box Syndic -->
      <div class="service-card">
        <div class="service-icon">🏗️</div>
        <div class="service-tag">Syndics</div>
        <h3 class="service-name">Ma Box Syndic</h3>
        <p class="service-desc">
          La solution dédiée aux syndics de copropriété et gestionnaires d'immeubles : suivi des charges,
          convocations AG, gestion des travaux et espace copropriétaires.
        </p>
        <ul class="service-features">
          <li>Gestion des immeubles & lots</li>
          <li>Appels de charges automatisés</li>
          <li>Convocations & votes AG en ligne</li>
          <li>Suivi travaux & prestataires</li>
          <li>Espace privé copropriétaires</li>
        </ul>
        <a href="dashboard_syndic.php" class="service-cta">
          Accès syndic
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
        </a>
      </div>

      <!-- SERVICE 4 : Ma Box Pro (bailleurs) -->
      <div class="service-card">
        <div class="service-icon">🔑</div>
        <div class="service-tag">Bailleurs & Investisseurs</div>
        <h3 class="service-name">Ma Box Pro</h3>
        <p class="service-desc">
          L'outil de gestion locative pour les bailleurs privés et investisseurs : baux, quittances,
          états des lieux, comptabilité locative et tableau de bord patrimoine.
        </p>
        <ul class="service-features">
          <li>Gestion des baux & renouvellements</li>
          <li>Quittances & encaissements automatiques</li>
          <li>États des lieux numériques</li>
          <li>Comptabilité locative simplifiée</li>
          <li>Tableau de bord patrimoine</li>
        </ul>
        <a href="dashboard_proprietaire.php" class="service-cta">
          Accès bailleur
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
        </a>
      </div>

    </div>
  </div>
</section>

<!-- ============================================================
     COMMENT ÇA MARCHE
============================================================ -->
<section class="section" id="comment">
  <div class="container">
    <div class="section-header">
      <div class="section-eyebrow">Simple & rapide</div>
      <h2 class="section-title">Comment ça marche ?</h2>
      <p class="section-desc">
        Que vous soyez particulier ou professionnel, démarrer sur MaBoxImmo prend moins de 5 minutes.
      </p>
    </div>
    <div class="how-grid">
      <div class="how-card">
        <div class="how-step">1</div>
        <div class="how-icon">📋</div>
        <h3 class="how-title">Créez votre compte</h3>
        <p class="how-desc">
          Inscription gratuite en 2 minutes. Choisissez votre profil :
          particulier, agence, bailleur ou syndic. Vérification par email.
        </p>
      </div>
      <div class="how-card">
        <div class="how-step">2</div>
        <div class="how-icon">🔍</div>
        <h3 class="how-title">Accédez à votre univers</h3>
        <p class="how-desc">
          Publiez vos annonces, gérez vos biens ou pilotez votre copropriété
          depuis votre tableau de bord personnalisé.
        </p>
      </div>
      <div class="how-card">
        <div class="how-step">3</div>
        <div class="how-icon">🤝</div>
        <h3 class="how-title">Connectez-vous & agissez</h3>
        <p class="how-desc">
          Échangez avec acheteurs, locataires, copropriétaires ou agences partenaires.
          Signez, gérez, diffusez — tout en ligne.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================
     PARTENAIRES (flux entrant agences)
============================================================ -->
<section class="section section-alt" id="partenaires">
  <div class="container">
    <div class="partners-grid">
      <div class="partners-content">
        <div class="section-eyebrow">Réseau partenaires</div>
        <h2 class="section-title">Votre agence intégrée en quelques heures</h2>
        <p class="section-desc">
          MaBoxImmo reçoit les flux d'annonces de vos agences partenaires automatiquement.
          Diffusion unifiée, audiences croisées, visibilité maximale.
        </p>
        <ul class="partners-feature-list">
          <li>
            <div class="partners-feature-icon">📡</div>
            <div class="partners-feature-text">
              <strong>Import de flux XML automatique</strong>
              Récupération horaire de vos annonces via votre flux XML standard.
            </div>
          </li>
          <li>
            <div class="partners-feature-icon">🔄</div>
            <div class="partners-feature-text">
              <strong>Mise à jour en temps réel</strong>
              Prix, disponibilité, photos — synchronisés sans intervention manuelle.
            </div>
          </li>
          <li>
            <div class="partners-feature-icon">📊</div>
            <div class="partners-feature-text">
              <strong>Statistiques de diffusion</strong>
              Suivez les vues, clics et leads générés pour chaque annonce.
            </div>
          </li>
          <li>
            <div class="partners-feature-icon">🛡️</div>
            <div class="partners-feature-text">
              <strong>Conformité & qualité</strong>
              Validation automatique DPE, loi Alur, photos conformes aux standards.
            </div>
          </li>
        </ul>
        <a href="agence_inscription.php">
          <button class="btn-primary" style="padding:13px 28px;font-size:15px;">
            Devenir agence partenaire →
          </button>
        </a>
      </div>

      <div class="partners-visual">
        <div class="partners-visual-title">Nos agences partenaires</div>
        <div class="partner-logo-grid">
          <div class="partner-logo-item">
            <div class="partner-logo-item-icon">🏠</div>
            <div>Agence A</div>
          </div>
          <div class="partner-logo-item">
            <div class="partner-logo-item-icon">🏡</div>
            <div>Agence B</div>
          </div>
          <div class="partner-logo-item">
            <div class="partner-logo-item-icon">🏢</div>
            <div>Agence C</div>
          </div>
          <div class="partner-logo-item">
            <div class="partner-logo-item-icon">🔑</div>
            <div>Agence D</div>
          </div>
          <div class="partner-logo-item">
            <div class="partner-logo-item-icon">📐</div>
            <div>Agence E</div>
          </div>
          <div class="partner-logo-item" style="opacity:0.4;border-style:dashed;">
            <div class="partner-logo-item-icon">＋</div>
            <div>Rejoignez-nous</div>
          </div>
        </div>
        <a href="agence_inscription.php" class="partner-cta-btn">
          Intégrer mon flux d'annonces
        </a>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================
     CTA BAND
============================================================ -->
<section class="cta-band">
  <div class="container">
    <div class="cta-band-content">
      <div class="cta-band-eyebrow">Rejoignez MaBoxImmo</div>
      <h2 class="cta-band-title">
        L'immobilier, simplifié.<br>
        Pour tout le monde.
      </h2>
      <p class="cta-band-desc">
        Particulier, agence, bailleur ou syndic — créez votre compte gratuitement
        et accédez à votre espace en moins de 5 minutes.
      </p>
      <div class="cta-band-buttons">
        <a href="agence_inscription.php"><button class="btn-white">Créer un compte gratuit</button></a>
        <a href="bien_recherche.php"><button class="btn-ghost-white">Parcourir les annonces</button></a>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================
     FOOTER
============================================================ -->
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="footer-brand-name">MaBoxImmo</div>
        <p class="footer-brand-desc">
          Le portail immobilier pensé pour les particuliers et les professionnels.
          Achetez, vendez, gérez — tout en un.
        </p>
        <div class="footer-socials">
          <div class="footer-social">𝕏</div>
          <div class="footer-social">in</div>
          <div class="footer-social">f</div>
          <div class="footer-social">▶</div>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Particuliers</div>
        <ul class="footer-links">
          <li><a href="#">Acheter</a></li>
          <li><a href="#">Louer</a></li>
          <li><a href="#">Estimer mon bien</a></li>
          <li><a href="#">Simulateur crédit</a></li>
          <li><a href="#">Guides & conseils</a></li>
        </ul>
      </div>
      <div>
        <div class="footer-col-title">Professionnels</div>
        <ul class="footer-links">
          <li><a href="login_agency.php">Ma Box Agency</a></li>
          <li><a href="dashboard_proprietaire.php">Ma Box Pro</a></li>
          <li><a href="dashboard_syndic.php">Ma Box Syndic</a></li>
          <li><a href="agence_inscription.php">Devenir partenaire</a></li>
          <li><a href="api/flux/export_annonces.xml">API & Flux XML</a></li>
        </ul>
      </div>
      <div>
        <div class="footer-col-title">MaBoxImmo</div>
        <ul class="footer-links">
          <li><a href="#">À propos</a></li>
          <li><a href="#">Contact</a></li>
          <li><a href="#">Presse</a></li>
          <li><a href="#">Recrutement</a></li>
          <li><a href="#">Blog</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span class="footer-bottom-text">© 2026 MaBoxImmo · Développé par TRUFOX Conseils · contact@trufox.fr</span>
      <div class="footer-bottom-links">
        <a href="#">Mentions légales</a>
        <a href="#">CGU</a>
        <a href="#">Confidentialité</a>
        <a href="#">Cookies</a>
      </div>
    </div>
  </div>
</footer>

</body>
</html>
