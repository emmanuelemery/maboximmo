<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaBoxImmo — Votre partenaire immobilier</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>

/* ============================================================
   TOKENS
============================================================ */
:root {
  --bg:          #eef1f6;
  --surface:     #e4e8f0;
  --card:        #e4e8f0;
  --card2:       #eef3f8;
  --bg-tertiary: #f0f1f3;

  --brand-900: #0e1825;
  --brand-700: #213450;
  --brand-600: #2b4466;
  --brand-500: #36577d;
  --brand-400: #528eb0;
  --brand-300: #7aadc8;
  --brand-200: #b0cce2;
  --brand-100: #d6e4f0;
  --brand-50:  #eef3f8;

  --accent:            var(--brand-500);
  --accent-grad:       linear-gradient(135deg, var(--brand-500), var(--brand-400));
  --accent-grad-hover: linear-gradient(135deg, var(--brand-600), var(--brand-500));

  --green:  #17a257;
  --amber:  #d48a0f;
  --red:    #d44040;
  --purple: #7640d4;

  --ink:   var(--brand-900);
  --muted: var(--brand-500);
  --faint: var(--brand-200);

  --border:        rgba(54,87,125,.12);
  --border-strong: rgba(54,87,125,.22);

  --shadow-dark:  #c8cdd8;
  --shadow-light: #ffffff;
  --neu-sm:  4px 4px 8px var(--shadow-dark),  -4px -4px 8px var(--shadow-light);
  --neu-out: 6px 6px 12px var(--shadow-dark), -6px -6px 12px var(--shadow-light);
  --neu-lg:  8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
  --neu-in:  inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light);
  --neu-in-sm: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
  --shadow-btn:  var(--neu-sm);
  --shadow-card: var(--neu-out);
  --shadow-card-hover: var(--neu-lg);

  --r-btn:  20px;
  --r-card: 14px;
  --r-xl:   20px;
}

/* ============================================================
   BASE
============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Sora', sans-serif;
  background: var(--bg);
  color: var(--ink);
  overflow-x: hidden;
}

a { color: inherit; text-decoration: none; }

/* ============================================================
   NAV — glassmorphisme sur fond foncé
============================================================ */
.nav {
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 200;
  height: 62px;
  display: flex;
  align-items: center;
  padding: 0 48px;
  gap: 32px;
  background: rgba(14,24,37,.75);
  border-bottom: 1px solid rgba(255,255,255,.08);
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
  box-shadow: none;
}

.nav-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-right: auto;
}

.nav-logo {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  background: var(--accent-grad);
  display: grid;
  place-items: center;
  font-weight: 800;
  font-size: 13px;
  color: #fff;
  flex-shrink: 0;
}

.logo-name {
  font-size: 16px;
  font-weight: 800;
  color: #fff;
  letter-spacing: -.3px;
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 4px;
  list-style: none;
}

.nav-link {
  padding: 7px 14px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  color: var(--brand-200);
  transition: all .18s;
  cursor: pointer;
}

.nav-link:hover {
  color: #fff;
  background: rgba(255,255,255,.1);
}

.nav-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-login {
  padding: 8px 18px;
  border-radius: var(--r-btn);
  border: 1px solid rgba(255,255,255,.2);
  background: rgba(255,255,255,.08);
  color: #fff;
  font-family: 'Sora', sans-serif;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  backdrop-filter: blur(6px);
  box-shadow: none;
  transition: all .18s;
}

.btn-login:hover {
  background: rgba(255,255,255,.18);
}

.btn-signup-nav {
  padding: 8px 18px;
  border-radius: var(--r-btn);
  border: none;
  background: var(--accent-grad);
  color: #fff;
  font-family: 'Sora', sans-serif;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(54,87,125,.35);
  transition: all .18s;
}

.btn-signup-nav:hover {
  background: var(--accent-grad-hover);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(54,87,125,.45);
}

.btn-theme {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,.2);
  background: rgba(255,255,255,.08);
  box-shadow: none;
  cursor: pointer;
  display: grid;
  place-items: center;
  font-size: 15px;
  transition: transform .18s;
}

.btn-theme:hover { transform: rotate(22deg) scale(1.12); }

/* ============================================================
   HERO — fond brand foncé
============================================================ */
.hero {
  min-height: 100vh;
  background: linear-gradient(160deg, var(--brand-900) 0%, var(--brand-700) 60%, #1a3a52 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 100px 24px 60px;
  position: relative;
  overflow: hidden;
}

/* Cercles décoratifs */
.hero::before {
  content: '';
  position: absolute;
  width: 600px; height: 600px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(83,142,176,.18) 0%, transparent 70%);
  top: -150px; right: -100px;
  pointer-events: none;
}

.hero::after {
  content: '';
  position: absolute;
  width: 400px; height: 400px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(122,173,200,.12) 0%, transparent 70%);
  bottom: 60px; left: -80px;
  pointer-events: none;
}

.hero-inner {
  max-width: 860px;
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28px;
  position: relative;
  z-index: 1;
}

.hero-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 16px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .4px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.18);
  color: var(--brand-100);
  box-shadow: none;
  backdrop-filter: blur(8px);
}

.eyebrow-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--brand-300);
  box-shadow: 0 0 0 3px rgba(122,173,200,.3);
  animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { box-shadow: 0 0 0 3px rgba(122,173,200,.3); }
  50%       { box-shadow: 0 0 0 6px rgba(122,173,200,.12); }
}

.hero-title {
  font-size: clamp(36px, 5vw, 60px);
  font-weight: 800;
  color: #fff;
  text-align: center;
  line-height: 1.15;
  letter-spacing: -.5px;
}

.hero-title span {
  background: linear-gradient(135deg, var(--brand-300), var(--brand-200));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.hero-sub {
  font-size: 17px;
  font-weight: 400;
  color: var(--brand-200);
  text-align: center;
  line-height: 1.7;
  max-width: 560px;
}

/* Type tabs */
.type-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: center;
}

.type-tab {
  padding: 8px 20px;
  border-radius: var(--r-btn);
  border: 1px solid rgba(255,255,255,.15);
  background: rgba(255,255,255,.08);
  color: var(--brand-200);
  font-family: 'Sora', sans-serif;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: none;
  backdrop-filter: blur(6px);
  transition: all .18s;
}

.type-tab:hover {
  color: #fff;
  background: rgba(255,255,255,.15);
}

.type-tab.active {
  background: #fff;
  color: var(--brand-700);
  border-color: transparent;
  box-shadow: 0 3px 12px rgba(0,0,0,.2);
  font-weight: 800;
}

/* Search card — neumorphique sur fond foncé */
.search-card {
  width: 100%;
  background: var(--card);
  border-radius: var(--r-xl);
  padding: 28px 28px 24px;
  box-shadow: 0 20px 50px rgba(0,0,0,.35);
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.search-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.search-field {
  flex: 1;
  min-width: 180px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.search-field label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .8px;
  color: var(--muted);
  font-family: 'DM Mono', monospace;
}

.search-field input,
.search-field select {
  padding: 10px 14px;
  border-radius: 10px;
  border: none;
  background: var(--surface);
  box-shadow: var(--neu-in);
  font-family: 'Sora', sans-serif;
  font-size: 13px;
  color: var(--ink);
  outline: none;
  transition: box-shadow .18s;
}

.search-field input:focus,
.search-field select:focus {
  box-shadow: var(--neu-in), 0 0 0 3px rgba(54,87,125,.2);
}

.btn-search {
  align-self: flex-end;
  padding: 11px 28px;
  border-radius: var(--r-btn);
  border: none;
  background: var(--accent-grad);
  color: #fff;
  font-family: 'Sora', sans-serif;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(54,87,125,.35);
  transition: all .18s;
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}

.btn-search:hover {
  background: var(--accent-grad-hover);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(54,87,125,.45);
}

/* Pills suggestions */
.hpills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.hpill {
  padding: 5px 14px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.18);
  color: var(--brand-100);
  box-shadow: none;
  backdrop-filter: blur(4px);
  cursor: pointer;
  transition: all .15s;
}

.hpill:hover {
  color: #fff;
  background: rgba(255,255,255,.18);
}

/* Stats bar */
.hero-stats {
  display: flex;
  align-items: center;
  gap: 0;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 14px;
  backdrop-filter: blur(10px);
  box-shadow: none;
  overflow: hidden;
}

.stat-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 18px 24px;
  border-right: 1px solid rgba(255,255,255,.12);
  gap: 4px;
}

.stat-item:last-child { border-right: none; }

.stat-num {
  font-size: 26px;
  font-weight: 800;
  color: #fff;
  line-height: 1;
}

.stat-lbl {
  font-size: 11px;
  font-weight: 500;
  color: var(--brand-200);
  letter-spacing: .3px;
}

/* ============================================================
   SECTION ANNONCES
============================================================ */
.annonces-section {
  background: var(--bg);
  padding: 80px 48px;
}

.sec-header {
  text-align: center;
  max-width: 600px;
  margin: 0 auto 48px;
}

.sec-eyebrow {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: var(--brand-400);
  font-family: 'DM Mono', monospace;
  margin-bottom: 10px;
}

.sec-title {
  font-size: clamp(26px, 3vw, 38px);
  font-weight: 800;
  color: var(--brand-900);
  line-height: 1.2;
  margin-bottom: 12px;
}

.sec-desc {
  font-size: 15px;
  color: var(--muted);
  line-height: 1.7;
}

.annonces-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 24px;
  max-width: 1200px;
  margin: 0 auto;
}

/* Card listing */
.lcard {
  background: var(--bg);
  border: none;
  border-radius: var(--r-card);
  box-shadow: var(--neu-out);
  overflow: hidden;
  transition: transform .25s, box-shadow .25s;
  cursor: pointer;
}

.lcard:hover {
  transform: translateY(-5px);
  box-shadow: var(--neu-lg);
}

.lcard-img {
  width: 100%;
  height: 180px;
  object-fit: cover;
  display: block;
  background: linear-gradient(135deg, var(--brand-100), var(--brand-200));
}

.lcard-img-placeholder {
  width: 100%;
  height: 180px;
  background: linear-gradient(135deg, var(--brand-100), var(--brand-200));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  color: var(--brand-300);
}

.lcard-body {
  padding: 18px 18px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lcard-type {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-family: 'DM Mono', monospace;
  color: var(--muted);
}

.lcard-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--brand-900);
  line-height: 1.3;
}

.lcard-loc {
  font-size: 12px;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 4px;
}

.lcard-features {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: var(--muted);
  font-family: 'DM Mono', monospace;
}

.lcard-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 18px 16px;
  border-top: 1px solid var(--border);
}

.lcard-price {
  font-size: 20px;
  font-weight: 800;
  color: var(--brand-700);
}

.lcard-badge {
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
}

.badge-vente    { background: rgba(54,87,125,.1);  color: var(--brand-600); }
.badge-location { background: rgba(83,142,176,.1); color: var(--brand-400); }

.annonces-cta {
  text-align: center;
  margin-top: 40px;
}

.btn-more {
  padding: 12px 32px;
  border-radius: var(--r-btn);
  border: 2px solid var(--brand-300);
  background: var(--surface);
  color: var(--brand-500);
  font-family: 'Sora', sans-serif;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: var(--neu-sm);
  transition: all .18s;
}

.btn-more:hover {
  background: var(--brand-500);
  color: #fff;
  border-color: var(--brand-500);
  transform: translateY(-1px);
  box-shadow: var(--neu-lg);
}

/* ============================================================
   SECTION CARTE GPS
============================================================ */
.map-section {
  background: var(--bg);
  padding: 0 48px 80px;
}

.map-wrap {
  max-width: 1200px;
  margin: 0 auto;
  border-radius: var(--r-xl);
  overflow: hidden;
  box-shadow: var(--neu-lg);
  height: 440px;
  background: linear-gradient(135deg, var(--brand-100), var(--brand-200));
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.map-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  color: var(--brand-500);
}

.map-placeholder-icon { font-size: 56px; }
.map-placeholder-text { font-size: 15px; font-weight: 600; }

.map-overlay {
  position: absolute;
  top: 20px; left: 20px;
  background: var(--surface);
  border-radius: 12px;
  padding: 14px 18px;
  box-shadow: var(--neu-out);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.map-overlay-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .8px;
  color: var(--muted);
  font-family: 'DM Mono', monospace;
}

.map-stat {
  font-size: 22px;
  font-weight: 800;
  color: var(--brand-700);
}

.map-stat-lbl {
  font-size: 12px;
  color: var(--muted);
}

/* ============================================================
   SECTION SERVICES — fond brand foncé
============================================================ */
.services-section {
  background: linear-gradient(160deg, var(--brand-700), var(--brand-900));
  padding: 80px 48px;
}

.services-section .sec-eyebrow { color: var(--brand-300); }
.services-section .sec-title   { color: #fff; }
.services-section .sec-desc    { color: var(--brand-200); }

.svc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 20px;
  max-width: 1200px;
  margin: 0 auto;
}

.svc-card {
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: var(--r-card);
  padding: 28px 24px;
  box-shadow: none;
  backdrop-filter: blur(8px);
  transition: all .22s;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.svc-card:hover {
  background: rgba(255,255,255,.13);
  border-color: rgba(255,255,255,.25);
  transform: translateY(-3px);
}

.svc-icon {
  width: 50px;
  height: 50px;
  border-radius: 14px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.2);
  box-shadow: none;
  display: grid;
  place-items: center;
}

.svc-icon svg {
  width: 22px;
  height: 22px;
  stroke-width: 1.8;
  fill: none;
}

.si-blue svg   { stroke: var(--brand-200); }
.si-gold svg   { stroke: #f5c842; }
.si-green svg  { stroke: #4cd98a; }
.si-purple svg { stroke: #b07dff; }
.si-red svg    { stroke: #ff7c7c; }

.svc-title {
  font-size: 16px;
  font-weight: 700;
  color: #fff;
}

.svc-desc {
  font-size: 13px;
  line-height: 1.65;
  color: var(--brand-200);
  flex: 1;
}

.svc-tag {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  align-self: flex-start;
}

.tag-pro  { background: rgba(245,200,50,.15);  color: #f5c842;          border: 1px solid rgba(245,200,50,.25); }
.tag-both { background: rgba(76,217,138,.12);  color: #4cd98a;          border: 1px solid rgba(76,217,138,.22); }
.tag-part { background: rgba(122,173,200,.15); color: var(--brand-200); border: 1px solid rgba(122,173,200,.25); }

/* ============================================================
   SECTION AUDIENCE
============================================================ */
.audience-section {
  background: var(--bg);
  padding: 80px 48px;
}

.aud-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  max-width: 1200px;
  margin: 0 auto 48px;
}

.aud-part {
  background: var(--surface);
  border-radius: var(--r-xl);
  padding: 40px;
  box-shadow: var(--neu-out);
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.aud-icon  { font-size: 40px; }
.aud-title { font-size: 22px; font-weight: 800; color: var(--brand-900); }
.aud-desc  { font-size: 14px; color: var(--muted); line-height: 1.7; flex: 1; }

.aud-features {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 8px;
}

.aud-feature {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--brand-600);
  font-weight: 500;
}

.aud-check {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--surface);
  box-shadow: var(--neu-sm);
  display: grid;
  place-items: center;
  font-size: 11px;
  flex-shrink: 0;
  color: var(--green);
}

.aud-btn {
  padding: 12px 28px;
  border-radius: var(--r-btn);
  border: none;
  background: var(--accent-grad);
  color: #fff;
  font-family: 'Sora', sans-serif;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(54,87,125,.35);
  transition: all .18s;
  align-self: flex-start;
}

.aud-btn:hover {
  background: var(--accent-grad-hover);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(54,87,125,.45);
}

/* ============================================================
   FOOTER CTA
============================================================ */
.footer-cta {
  background: linear-gradient(160deg, var(--brand-900), var(--brand-700), var(--brand-900));
  padding: 80px 48px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
  position: relative;
  overflow: hidden;
}

.footer-cta::before {
  content: '';
  position: absolute;
  width: 500px; height: 500px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(83,142,176,.15) 0%, transparent 70%);
  top: -150px; right: -100px;
  pointer-events: none;
}

.fcta-title {
  font-size: clamp(28px, 3.5vw, 44px);
  font-weight: 800;
  color: var(--brand-50);
  line-height: 1.2;
  position: relative;
  z-index: 1;
}

.fcta-sub {
  font-size: 16px;
  color: var(--brand-200);
  opacity: .7;
  max-width: 480px;
  line-height: 1.6;
  position: relative;
  z-index: 1;
}

.fcta-actions {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  justify-content: center;
  position: relative;
  z-index: 1;
}

.fcta-main {
  padding: 14px 32px;
  border-radius: var(--r-btn);
  border: none;
  background: var(--accent-grad);
  color: #fff;
  font-family: 'Sora', sans-serif;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(54,87,125,.35);
  transition: all .18s;
}

.fcta-main:hover {
  background: var(--accent-grad-hover);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(54,87,125,.45);
}

.fcta-ghost {
  padding: 14px 32px;
  border-radius: var(--r-btn);
  border: 1px solid rgba(255,255,255,.25);
  background: rgba(255,255,255,.08);
  color: #fff;
  font-family: 'Sora', sans-serif;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  backdrop-filter: blur(6px);
  transition: all .18s;
}

.fcta-ghost:hover { background: rgba(255,255,255,.18); }

/* ============================================================
   FOOTER
============================================================ */
.footer {
  background: var(--brand-900);
  padding: 40px 48px 28px;
  color: var(--brand-300);
}

.footer-grid {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px;
  max-width: 1200px;
  margin: 0 auto 32px;
}

.footer-brand-name {
  font-size: 17px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.footer-desc {
  font-size: 13px;
  color: var(--brand-300);
  line-height: 1.7;
  max-width: 260px;
}

.footer-col-title {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .8px;
  color: var(--brand-200);
  margin-bottom: 16px;
  font-family: 'DM Mono', monospace;
}

.footer-links {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.footer-links li a {
  font-size: 13px;
  color: var(--brand-300);
  transition: color .15s;
}

.footer-links li a:hover { color: #fff; }

.footer-bottom {
  border-top: 1px solid rgba(255,255,255,.08);
  padding-top: 20px;
  text-align: center;
  font-size: 12px;
  color: var(--brand-400);
  max-width: 1200px;
  margin: 0 auto;
}

/* ============================================================
   RESPONSIVE
============================================================ */
@media (max-width: 900px) {
  .nav { padding: 0 24px; }
  .nav-links { display: none; }
  .annonces-section, .map-section, .services-section,
  .audience-section, .footer-cta, .footer { padding-left: 24px; padding-right: 24px; }
  .aud-grid { grid-template-columns: 1fr; }
  .footer-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 560px) {
  .hero { padding: 80px 16px 40px; }
  .search-row { flex-direction: column; }
  .hero-stats { flex-direction: column; }
  .stat-item { border-right: none; border-bottom: 1px solid rgba(255,255,255,.12); }
  .stat-item:last-child { border-bottom: none; }
  .footer-grid { grid-template-columns: 1fr; }
}

  </style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav class="nav">
  <div class="nav-brand">
    <div class="nav-logo">MI</div>
    <span class="logo-name">MaBoxImmo</span>
  </div>
  <ul class="nav-links">
    <li><a class="nav-link" href="#">Acheter</a></li>
    <li><a class="nav-link" href="#">Louer</a></li>
    <li><a class="nav-link" href="#">Estimer</a></li>
    <li><a class="nav-link" href="#">Agences</a></li>
    <li><a class="nav-link" href="#">Actualités</a></li>
  </ul>
  <div class="nav-actions">
    <button class="btn-theme" title="Thème">🌙</button>
    <button class="btn-login" onclick="location.href='login.php'">Se connecter</button>
    <button class="btn-signup-nav" onclick="location.href='login.php'">Publier une annonce</button>
  </div>
</nav>

<!-- ─── HERO ─── -->
<section class="hero">
  <div class="hero-inner">

    <div class="hero-eyebrow">
      <span class="eyebrow-dot"></span>
      N°1 de l'immobilier professionnel en France
    </div>

    <h1 class="hero-title">
      Trouvez votre bien<br>
      <span>idéal en quelques clics</span>
    </h1>

    <p class="hero-sub">
      Des milliers d'annonces vérifiées, des outils professionnels et une équipe à votre écoute pour concrétiser votre projet immobilier.
    </p>

    <!-- Type tabs -->
    <div class="type-tabs">
      <button class="type-tab active">🏠 Acheter</button>
      <button class="type-tab">🔑 Louer</button>
      <button class="type-tab">💼 Investir</button>
      <button class="type-tab">📊 Estimer</button>
    </div>

    <!-- Search card -->
    <div class="search-card">
      <div class="search-row">
        <div class="search-field" style="flex:2">
          <label>Ville ou code postal</label>
          <input type="text" placeholder="Paris, Lyon, Bordeaux…">
        </div>
        <div class="search-field">
          <label>Type de bien</label>
          <select>
            <option>Tous types</option>
            <option>Appartement</option>
            <option>Maison</option>
            <option>Bureau</option>
            <option>Commerce</option>
          </select>
        </div>
        <div class="search-field">
          <label>Budget max</label>
          <input type="text" placeholder="Illimité">
        </div>
        <button class="btn-search">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Rechercher
        </button>
      </div>

      <div class="hpills">
        <span>Suggestions :</span>
        <button class="hpill">Paris 75001</button>
        <button class="hpill">Lyon 2 pièces</button>
        <button class="hpill">Bordeaux < 300k€</button>
        <button class="hpill">Appartements Nantes</button>
        <button class="hpill">Maison avec jardin</button>
      </div>
    </div>

    <!-- Stats -->
    <div class="hero-stats">
      <div class="stat-item">
        <span class="stat-num">42 800</span>
        <span class="stat-lbl">Annonces actives</span>
      </div>
      <div class="stat-item">
        <span class="stat-num">1 240</span>
        <span class="stat-lbl">Agences partenaires</span>
      </div>
      <div class="stat-item">
        <span class="stat-num">98 %</span>
        <span class="stat-lbl">Clients satisfaits</span>
      </div>
      <div class="stat-item">
        <span class="stat-num">12 ans</span>
        <span class="stat-lbl">D'expérience</span>
      </div>
    </div>

  </div>
</section>

<!-- ─── ANNONCES ─── -->
<section class="annonces-section">
  <div class="sec-header">
    <div class="sec-eyebrow">Annonces récentes</div>
    <h2 class="sec-title">Les meilleures offres du moment</h2>
    <p class="sec-desc">Sélectionnées par nos experts, vérifiées et mises à jour quotidiennement.</p>
  </div>

  <div class="annonces-grid">

    <!-- Card Appartement — bande bleue brand-500 -->
    <div class="lcard" style="border-top: 3px solid #36577d">
      <div class="lcard-img-placeholder">🏢</div>
      <div class="lcard-body">
        <div class="lcard-type">Appartement · Vente</div>
        <div class="lcard-title">Appartement T3 lumineux — Paris 11e</div>
        <div class="lcard-loc">📍 Paris, 75011</div>
        <div class="lcard-features">
          <span>🛏 3 pièces</span>
          <span>📐 68 m²</span>
          <span>🏢 3e étage</span>
        </div>
      </div>
      <div class="lcard-footer">
        <span class="lcard-price">485 000 €</span>
        <span class="lcard-badge badge-vente">Vente</span>
      </div>
    </div>

    <!-- Card Maison — bande verte -->
    <div class="lcard" style="border-top: 3px solid #17a257">
      <div class="lcard-img-placeholder">🏡</div>
      <div class="lcard-body">
        <div class="lcard-type">Maison · Vente</div>
        <div class="lcard-title">Maison 5 pièces avec jardin — Lyon</div>
        <div class="lcard-loc">📍 Lyon, 69003</div>
        <div class="lcard-features">
          <span>🛏 5 pièces</span>
          <span>📐 142 m²</span>
          <span>🌿 320 m² terrain</span>
        </div>
      </div>
      <div class="lcard-footer">
        <span class="lcard-price">620 000 €</span>
        <span class="lcard-badge badge-vente">Vente</span>
      </div>
    </div>

    <!-- Card Bureau — bande violette -->
    <div class="lcard" style="border-top: 3px solid #7640d4">
      <div class="lcard-img-placeholder">🏗️</div>
      <div class="lcard-body">
        <div class="lcard-type">Bureau · Location</div>
        <div class="lcard-title">Plateau de bureaux open-space — Bordeaux</div>
        <div class="lcard-loc">📍 Bordeaux, 33000</div>
        <div class="lcard-features">
          <span>💼 Open-space</span>
          <span>📐 210 m²</span>
          <span>🚗 Parking</span>
        </div>
      </div>
      <div class="lcard-footer">
        <span class="lcard-price">2 800 €/mois</span>
        <span class="lcard-badge badge-location">Location</span>
      </div>
    </div>

    <!-- Card Appartement location — bande brand-300 -->
    <div class="lcard" style="border-top: 3px solid #7aadc8">
      <div class="lcard-img-placeholder">🏠</div>
      <div class="lcard-body">
        <div class="lcard-type">Appartement · Location</div>
        <div class="lcard-title">Studio moderne rénové — Nantes Centre</div>
        <div class="lcard-loc">📍 Nantes, 44000</div>
        <div class="lcard-features">
          <span>🛏 1 pièce</span>
          <span>📐 32 m²</span>
          <span>🏢 2e étage</span>
        </div>
      </div>
      <div class="lcard-footer">
        <span class="lcard-price">680 €/mois</span>
        <span class="lcard-badge badge-location">Location</span>
      </div>
    </div>

    <!-- Card Maison location — bande verte -->
    <div class="lcard" style="border-top: 3px solid #17a257">
      <div class="lcard-img-placeholder">🏡</div>
      <div class="lcard-body">
        <div class="lcard-type">Maison · Location</div>
        <div class="lcard-title">Villa T4 avec piscine — Montpellier</div>
        <div class="lcard-loc">📍 Montpellier, 34000</div>
        <div class="lcard-features">
          <span>🛏 4 pièces</span>
          <span>📐 115 m²</span>
          <span>🏊 Piscine</span>
        </div>
      </div>
      <div class="lcard-footer">
        <span class="lcard-price">1 850 €/mois</span>
        <span class="lcard-badge badge-location">Location</span>
      </div>
    </div>

    <!-- Card Appartement vente — bande bleue -->
    <div class="lcard" style="border-top: 3px solid #36577d">
      <div class="lcard-img-placeholder">🏢</div>
      <div class="lcard-body">
        <div class="lcard-type">Appartement · Vente</div>
        <div class="lcard-title">T2 avec terrasse vue mer — Nice</div>
        <div class="lcard-loc">📍 Nice, 06000</div>
        <div class="lcard-features">
          <span>🛏 2 pièces</span>
          <span>📐 54 m²</span>
          <span>🌅 Vue mer</span>
        </div>
      </div>
      <div class="lcard-footer">
        <span class="lcard-price">395 000 €</span>
        <span class="lcard-badge badge-vente">Vente</span>
      </div>
    </div>

  </div>

  <div class="annonces-cta">
    <button class="btn-more">Voir toutes les annonces →</button>
  </div>
</section>

<!-- ─── CARTE GPS ─── -->
<section class="map-section">
  <div class="map-wrap">
    <div class="map-placeholder">
      <div class="map-placeholder-icon">🗺️</div>
      <div class="map-placeholder-text">Carte interactive des annonces</div>
    </div>
    <div class="map-overlay">
      <div class="map-overlay-title">Dans votre zone</div>
      <div class="map-stat">234</div>
      <div class="map-stat-lbl">biens disponibles</div>
    </div>
  </div>
</section>

<!-- ─── SERVICES ─── -->
<section class="services-section">
  <div class="sec-header">
    <div class="sec-eyebrow">Nos services</div>
    <h2 class="sec-title">Tout ce qu'il vous faut</h2>
    <p class="sec-desc">Des outils professionnels pensés pour simplifier votre vie immobilière.</p>
  </div>

  <div class="svc-grid">

    <div class="svc-card">
      <div class="svc-icon si-blue">
        <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      </div>
      <div class="svc-title">Gestion locative</div>
      <div class="svc-desc">Pilotez vos locations, quittances, états des lieux et suivi des loyers depuis un seul tableau de bord.</div>
      <span class="svc-tag tag-pro">Professionnel</span>
    </div>

    <div class="svc-card">
      <div class="svc-icon si-gold">
        <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      </div>
      <div class="svc-title">Estimation en ligne</div>
      <div class="svc-desc">Obtenez une estimation fiable de votre bien en moins de 2 minutes grâce à notre algorithme IA.</div>
      <span class="svc-tag tag-both">Gratuit</span>
    </div>

    <div class="svc-card">
      <div class="svc-icon si-green">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      </div>
      <div class="svc-title">Gestion RH</div>
      <div class="svc-desc">Suivez les salaires, congés, documents et entretiens de votre équipe dans un espace dédié.</div>
      <span class="svc-tag tag-pro">Professionnel</span>
    </div>

    <div class="svc-card">
      <div class="svc-icon si-purple">
        <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      </div>
      <div class="svc-title">Syndic de copropriété</div>
      <div class="svc-desc">Gérez les assemblées, charges et travaux de copropriété en toute transparence.</div>
      <span class="svc-tag tag-both">Pro & Particuliers</span>
    </div>

    <div class="svc-card">
      <div class="svc-icon si-red">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div class="svc-title">Alertes & notifications</div>
      <div class="svc-desc">Recevez des alertes instantanées sur les nouveaux biens correspondant à vos critères.</div>
      <span class="svc-tag tag-part">Particuliers</span>
    </div>

    <div class="svc-card">
      <div class="svc-icon si-blue">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div class="svc-title">Réseau d'agences</div>
      <div class="svc-desc">Rejoignez notre réseau de 1 240 agences partenaires et accédez à un flux d'affaires qualifiées.</div>
      <span class="svc-tag tag-pro">Agences</span>
    </div>

  </div>
</section>

<!-- ─── AUDIENCE ─── -->
<section class="audience-section">
  <div class="sec-header">
    <div class="sec-eyebrow">Pour qui ?</div>
    <h2 class="sec-title">Une plateforme pensée pour tous</h2>
  </div>

  <div class="aud-grid">

    <div class="aud-part">
      <div class="aud-icon">👤</div>
      <div class="aud-title">Vous êtes particulier</div>
      <div class="aud-desc">Achetez, louez ou vendez votre bien en toute sérénité avec nos outils simples et nos conseils d'experts.</div>
      <div class="aud-features">
        <div class="aud-feature"><span class="aud-check">✓</span> Recherche avancée multi-critères</div>
        <div class="aud-feature"><span class="aud-check">✓</span> Alertes email instantanées</div>
        <div class="aud-feature"><span class="aud-check">✓</span> Estimation gratuite de votre bien</div>
        <div class="aud-feature"><span class="aud-check">✓</span> Gestion de vos favoris</div>
      </div>
      <button class="aud-btn">Créer un compte gratuit →</button>
    </div>

    <div class="aud-part">
      <div class="aud-icon">🏢</div>
      <div class="aud-title">Vous êtes professionnel</div>
      <div class="aud-desc">Gérez votre portefeuille, vos équipes, vos mandats et votre comptabilité depuis une plateforme unifiée.</div>
      <div class="aud-features">
        <div class="aud-feature"><span class="aud-check">✓</span> CRM & gestion de mandats</div>
        <div class="aud-feature"><span class="aud-check">✓</span> Module RH complet</div>
        <div class="aud-feature"><span class="aud-check">✓</span> Gestion multi-agences</div>
        <div class="aud-feature"><span class="aud-check">✓</span> Rapports & statistiques</div>
      </div>
      <button class="aud-btn">Demander une démo →</button>
    </div>

  </div>
</section>

<!-- ─── FOOTER CTA ─── -->
<section class="footer-cta">
  <h2 class="fcta-title">Prêt à trouver votre prochain bien ?</h2>
  <p class="fcta-sub">Rejoignez plus de 120 000 utilisateurs qui font confiance à MaBoxImmo chaque jour.</p>
  <div class="fcta-actions">
    <button class="fcta-main">Commencer gratuitement →</button>
    <button class="fcta-ghost">Voir les annonces</button>
  </div>
</section>

<!-- ─── FOOTER ─── -->
<footer class="footer">
  <div class="footer-grid">
    <div>
      <div class="footer-brand-name">
        <div class="nav-logo" style="width:28px;height:28px;font-size:11px">MI</div>
        MaBoxImmo
      </div>
      <p class="footer-desc">La plateforme immobilière professionnelle française. Achat, location, gestion — tout en un.</p>
    </div>
    <div>
      <div class="footer-col-title">Acheter</div>
      <ul class="footer-links">
        <li><a href="#">Appartements</a></li>
        <li><a href="#">Maisons</a></li>
        <li><a href="#">Bureaux</a></li>
        <li><a href="#">Terrains</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Louer</div>
      <ul class="footer-links">
        <li><a href="#">Appartements</a></li>
        <li><a href="#">Maisons</a></li>
        <li><a href="#">Bureaux</a></li>
        <li><a href="#">Garages</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Société</div>
      <ul class="footer-links">
        <li><a href="#">À propos</a></li>
        <li><a href="#">Agences partenaires</a></li>
        <li><a href="#">Actualités</a></li>
        <li><a href="#">Contact</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    © 2026 MaBoxImmo — Tous droits réservés · <a href="#" style="color:var(--brand-300)">Mentions légales</a> · <a href="#" style="color:var(--brand-300)">CGU</a>
  </div>
</footer>

<script>
// Type tabs
document.querySelectorAll('.type-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
  });
});
</script>

</body>
</html>
