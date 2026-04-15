<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Design System v2 — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/tokens.css">
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/layout.css">
</head>
<body>

<div class="app">

  <!-- ===== SIDEBAR ===== -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9 22 9 12 15 12 15 22"/>
      </svg>
      <span class="sidebar-logo-text">MaBox v2</span>
    </div>

    <nav class="sidebar-nav">
      <div class="sidebar-section-label">Fondations</div>
      <a class="sidebar-link" href="#s-typo">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
        Typographie
      </a>
      <a class="sidebar-link" href="#s-colors">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20"/></svg>
        Couleurs
      </a>

      <div class="sidebar-section-label">Composants</div>
      <a class="sidebar-link" href="#s-buttons">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="7" width="20" height="10" rx="2"/></svg>
        Boutons
      </a>
      <a class="sidebar-link" href="#s-forms">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="15" x2="13" y2="15"/></svg>
        Formulaires
      </a>
      <a class="sidebar-link" href="#s-cards">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="9" x2="22" y2="9"/></svg>
        Cards
      </a>
      <a class="sidebar-link" href="#s-tables">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="1"/><line x1="2" y1="8" x2="22" y2="8"/><line x1="2" y1="14" x2="22" y2="14"/><line x1="8" y1="2" x2="8" y2="22"/></svg>
        Tables
      </a>
      <a class="sidebar-link" href="#s-badges">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
        Badges
      </a>
      <a class="sidebar-link" href="#s-nav">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        Navigation
      </a>
      <a class="sidebar-link" href="#s-feedback">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><dot cx="12" cy="16" r="1"/><circle cx="12" cy="16" r="1" fill="currentColor"/></svg>
        Feedback
      </a>
      <a class="sidebar-link" href="#s-modals">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
        Modales
      </a>

      <div class="sidebar-section-label">Métier</div>
      <a class="sidebar-link" href="#s-metier">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        Métier
      </a>
      <a class="sidebar-link" href="#s-darkmode">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        Dark Mode
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="avatar avatar-sm">AD</div>
      <div class="sidebar-footer-info">
        <span class="sidebar-footer-name">Admin Demo</span>
        <span class="badge badge-primary badge-sm">v2.0</span>
      </div>
    </div>
  </aside>
  <!-- /SIDEBAR -->

  <div class="main-wrapper">

    <!-- ===== TOPBAR ===== -->
    <header class="topbar">
      <button class="btn btn-ghost btn-icon" data-sidebar-toggle aria-label="Ouvrir le menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span class="topbar-title">Design System</span>
      <div class="topbar-actions">
        <button class="btn btn-ghost btn-icon" data-toggle-dark data-dark-icon="🌙" data-light-icon="☀️" aria-label="Basculer le mode sombre">
          🌙
        </button>
        <div style="position:relative;display:inline-flex;">
          <button class="btn btn-ghost btn-icon" aria-label="Notifications">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          </button>
          <span class="badge badge-danger badge-sm" style="position:absolute;top:-4px;right:-4px;">3</span>
        </div>
        <div class="avatar avatar-sm">AD</div>
      </div>
    </header>
    <!-- /TOPBAR -->

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

      <!-- ============================================================
           SECTION 1 — TYPOGRAPHIE
           ============================================================ -->
      <section class="section" id="s-typo">
        <h2 class="section-title">Typographie</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Titres</h3>
          <h1>h1 — Titre principal <span class="badge badge-neutral badge-sm">.h1</span></h1>
          <h2>h2 — Titre secondaire <span class="badge badge-neutral badge-sm">.h2</span></h2>
          <h3>h3 — Titre tertiaire <span class="badge badge-neutral badge-sm">.h3</span></h3>
          <h4>h4 — Sous-titre <span class="badge badge-neutral badge-sm">.h4</span></h4>
          <h5>h5 — Petit titre <span class="badge badge-neutral badge-sm">.h5</span></h5>
          <h6>h6 — Micro-titre <span class="badge badge-neutral badge-sm">.h6</span></h6>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Échelle de tailles</h3>
          <div class="type-scale-grid">
            <div class="type-swatch"><span class="text-xs">text-xs — 0.75rem</span><code class="type-label">--font-size-xs</code></div>
            <div class="type-swatch"><span class="text-sm">text-sm — 0.875rem</span><code class="type-label">--font-size-sm</code></div>
            <div class="type-swatch"><span class="text-base">text-base — 1rem</span><code class="type-label">--font-size-base</code></div>
            <div class="type-swatch"><span class="text-lg">text-lg — 1.125rem</span><code class="type-label">--font-size-lg</code></div>
            <div class="type-swatch"><span class="text-xl">text-xl — 1.25rem</span><code class="type-label">--font-size-xl</code></div>
            <div class="type-swatch"><span class="text-2xl">text-2xl — 1.5rem</span><code class="type-label">--font-size-2xl</code></div>
            <div class="type-swatch"><span class="text-3xl">text-3xl — 1.875rem</span><code class="type-label">--font-size-3xl</code></div>
            <div class="type-swatch"><span class="text-4xl">text-4xl — 2.25rem</span><code class="type-label">--font-size-4xl</code></div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Texte courant</h3>
          <p>Voici un paragraphe de texte courant. MaBoxImmo est une solution RH et immobilière complète pour gérer vos collaborateurs, vos biens et vos contrats. <a href="#">Un lien dans le texte</a> apparaît ainsi.</p>
          <ul>
            <li>Élément de liste non ordonnée</li>
            <li>Second élément avec du <strong>texte en gras</strong></li>
            <li>Troisième élément avec de <em>l'italique</em></li>
          </ul>
          <ol>
            <li>Première étape ordonnée</li>
            <li>Deuxième étape</li>
            <li>Troisième étape</li>
          </ol>
          <p>Du code inline : <code>const maVar = 'MaBoxImmo';</code> dans une phrase.</p>
          <blockquote>
            "La gestion immobilière et RH réunies dans une seule interface fluide et moderne."
            <cite>— Équipe MaBoxImmo</cite>
          </blockquote>
        </div>
      </section>

      <!-- ============================================================
           SECTION 2 — COULEURS
           ============================================================ -->
      <section class="section" id="s-colors">
        <h2 class="section-title">Couleurs</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Palette primaire (brand)</h3>
          <div class="color-grid color-grid-9">
            <div class="color-swatch" style="background:var(--brand-50);" data-copy-color="var(--brand-50)"><span class="color-label">50</span><span class="color-value">brand-50</span></div>
            <div class="color-swatch" style="background:var(--brand-100);" data-copy-color="var(--brand-100)"><span class="color-label">100</span><span class="color-value">brand-100</span></div>
            <div class="color-swatch" style="background:var(--brand-200);" data-copy-color="var(--brand-200)"><span class="color-label">200</span><span class="color-value">brand-200</span></div>
            <div class="color-swatch" style="background:var(--brand-300);" data-copy-color="var(--brand-300)"><span class="color-label">300</span><span class="color-value">brand-300</span></div>
            <div class="color-swatch" style="background:var(--brand-400);" data-copy-color="var(--brand-400)"><span class="color-label">400</span><span class="color-value">brand-400</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--brand-500);" data-copy-color="var(--brand-500)"><span class="color-label">500</span><span class="color-value">brand-500</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--brand-600);" data-copy-color="var(--brand-600)"><span class="color-label">600</span><span class="color-value">brand-600</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--brand-700);" data-copy-color="var(--brand-700)"><span class="color-label">700</span><span class="color-value">brand-700</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--brand-800);" data-copy-color="var(--brand-800)"><span class="color-label">800</span><span class="color-value">brand-800</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--brand-900);" data-copy-color="var(--brand-900)"><span class="color-label">900</span><span class="color-value">brand-900</span></div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Neutres (gray)</h3>
          <div class="color-grid color-grid-9">
            <div class="color-swatch" style="background:var(--gray-50);" data-copy-color="var(--gray-50)"><span class="color-label">50</span><span class="color-value">gray-50</span></div>
            <div class="color-swatch" style="background:var(--gray-100);" data-copy-color="var(--gray-100)"><span class="color-label">100</span><span class="color-value">gray-100</span></div>
            <div class="color-swatch" style="background:var(--gray-200);" data-copy-color="var(--gray-200)"><span class="color-label">200</span><span class="color-value">gray-200</span></div>
            <div class="color-swatch" style="background:var(--gray-300);" data-copy-color="var(--gray-300)"><span class="color-label">300</span><span class="color-value">gray-300</span></div>
            <div class="color-swatch" style="background:var(--gray-400);" data-copy-color="var(--gray-400)"><span class="color-label">400</span><span class="color-value">gray-400</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--gray-500);" data-copy-color="var(--gray-500)"><span class="color-label">500</span><span class="color-value">gray-500</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--gray-600);" data-copy-color="var(--gray-600)"><span class="color-label">600</span><span class="color-value">gray-600</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--gray-700);" data-copy-color="var(--gray-700)"><span class="color-label">700</span><span class="color-value">gray-700</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--gray-800);" data-copy-color="var(--gray-800)"><span class="color-label">800</span><span class="color-value">gray-800</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--gray-900);" data-copy-color="var(--gray-900)"><span class="color-label">900</span><span class="color-value">gray-900</span></div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Couleurs sémantiques</h3>
          <div class="color-grid color-grid-4">
            <div class="color-swatch color-swatch--dark" style="background:var(--color-success);" data-copy-color="var(--color-success)"><span class="color-label">Success</span><span class="color-value">--color-success</span></div>
            <div class="color-swatch" style="background:var(--color-warning);" data-copy-color="var(--color-warning)"><span class="color-label">Warning</span><span class="color-value">--color-warning</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--color-danger);" data-copy-color="var(--color-danger)"><span class="color-label">Danger</span><span class="color-value">--color-danger</span></div>
            <div class="color-swatch color-swatch--dark" style="background:var(--color-info);" data-copy-color="var(--color-info)"><span class="color-label">Info</span><span class="color-value">--color-info</span></div>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 3 — BOUTONS
           ============================================================ -->
      <section class="section" id="s-buttons">
        <h2 class="section-title">Boutons</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Tailles × Variantes (primary)</h3>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-primary btn-xs">Extra Small</button>
            <button class="btn btn-primary btn-sm">Small</button>
            <button class="btn btn-primary">Default</button>
            <button class="btn btn-primary btn-lg">Large</button>
            <button class="btn btn-primary btn-xl">Extra Large</button>
          </div>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-outline btn-xs">Extra Small</button>
            <button class="btn btn-outline btn-sm">Small</button>
            <button class="btn btn-outline">Default</button>
            <button class="btn btn-outline btn-lg">Large</button>
            <button class="btn btn-outline btn-xl">Extra Large</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Variantes</h3>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-primary">Primary</button>
            <button class="btn btn-secondary">Secondary</button>
            <button class="btn btn-outline">Outline</button>
            <button class="btn btn-ghost">Ghost</button>
            <button class="btn btn-link">Link</button>
            <button class="btn btn-danger">Danger</button>
            <button class="btn btn-success">Success</button>
            <button class="btn btn-warning">Warning</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">États</h3>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-primary" disabled>Disabled</button>
            <button class="btn btn-secondary" disabled>Disabled</button>
            <button class="btn btn-outline" disabled>Disabled</button>
            <button class="btn btn-primary btn-loading">Chargement</button>
            <button class="btn btn-secondary btn-loading">Chargement</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Avec icônes</h3>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-primary">
              <span aria-hidden="true">←</span> Icône gauche
            </button>
            <button class="btn btn-primary">
              Icône droite <span aria-hidden="true">→</span>
            </button>
            <button class="btn btn-primary btn-icon" aria-label="Précédent">←</button>
            <button class="btn btn-primary btn-icon" aria-label="Suivant">→</button>
            <button class="btn btn-outline btn-icon" aria-label="Paramètres">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93A10 10 0 0 0 4.93 19.07M4.93 4.93a10 10 0 0 0 14.14 14.14"/></svg>
            </button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Groupe de boutons</h3>
          <div class="btn-group">
            <button class="btn btn-outline">Semaine</button>
            <button class="btn btn-outline active">Mois</button>
            <button class="btn btn-outline">Année</button>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 4 — FORMULAIRES
           ============================================================ -->
      <section class="section" id="s-forms">
        <h2 class="section-title">Formulaires</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Champs texte</h3>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="demo-text">Nom complet</label>
              <input class="form-input" type="text" id="demo-text" placeholder="Jean Dupont">
            </div>
            <div class="form-group">
              <label class="form-label" for="demo-email">Adresse email</label>
              <input class="form-input" type="email" id="demo-email" placeholder="jean@maboximmо.fr">
            </div>
            <div class="form-group">
              <label class="form-label" for="demo-password">Mot de passe</label>
              <input class="form-input" type="password" id="demo-password" placeholder="••••••••">
            </div>
            <div class="form-group">
              <label class="form-label" for="demo-number">Loyer mensuel (€)</label>
              <input class="form-input" type="number" id="demo-number" placeholder="1250">
            </div>
            <div class="form-group">
              <label class="form-label" for="demo-search">Recherche</label>
              <div class="input-with-icon">
                <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="form-input input-has-icon" type="search" id="demo-search" placeholder="Rechercher un bien...">
              </div>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">États des champs</h3>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label" for="demo-error">Champ en erreur</label>
              <input class="form-input input-error" type="text" id="demo-error" value="valeur incorrecte">
              <span class="form-message form-message--error">Ce champ est obligatoire.</span>
            </div>
            <div class="form-group">
              <label class="form-label" for="demo-success">Champ validé</label>
              <input class="form-input input-success" type="text" id="demo-success" value="marie.lambert@maboximmo.fr">
              <span class="form-message form-message--success">Email valide.</span>
            </div>
            <div class="form-group">
              <label class="form-label" for="demo-disabled">Champ désactivé</label>
              <input class="form-input" type="text" id="demo-disabled" value="Non modifiable" disabled>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Textarea</h3>
          <div class="form-group">
            <label class="form-label" for="demo-textarea">Description du bien</label>
            <textarea class="form-input form-textarea" id="demo-textarea" maxlength="200" placeholder="Décrivez le bien immobilier..."></textarea>
            <span class="form-message">0 / 200 caractères</span>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Select</h3>
          <div class="form-group" style="max-width:320px;">
            <label class="form-label" for="demo-select">Type de bien</label>
            <select class="form-input form-select" id="demo-select">
              <option value="">Choisir...</option>
              <option value="appart">Appartement</option>
              <option value="maison">Maison</option>
              <option value="bureau">Bureau</option>
              <option value="commerce">Commerce</option>
              <option value="terrain">Terrain</option>
            </select>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Checkboxes</h3>
          <div class="checkbox-group">
            <label class="checkbox-label">
              <input type="checkbox" class="checkbox-input" checked>
              <span class="checkbox-custom"></span>
              Appartement
            </label>
            <label class="checkbox-label">
              <input type="checkbox" class="checkbox-input">
              <span class="checkbox-custom"></span>
              Maison
            </label>
            <label class="checkbox-label">
              <input type="checkbox" class="checkbox-input" disabled>
              <span class="checkbox-custom"></span>
              Terrain (désactivé)
            </label>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Radio buttons</h3>
          <div class="radio-group">
            <label class="radio-label">
              <input type="radio" class="radio-input" name="statut" value="dispo" checked>
              <span class="radio-custom"></span>
              Disponible
            </label>
            <label class="radio-label">
              <input type="radio" class="radio-input" name="statut" value="loue">
              <span class="radio-custom"></span>
              Loué
            </label>
            <label class="radio-label">
              <input type="radio" class="radio-input" name="statut" value="vendu" disabled>
              <span class="radio-custom"></span>
              Vendu (désactivé)
            </label>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Toggles</h3>
          <div class="toggle-group">
            <label class="toggle-label">
              <input type="checkbox" class="toggle-input" checked>
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
              Notifications email
            </label>
            <label class="toggle-label">
              <input type="checkbox" class="toggle-input">
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
              Notifications SMS
            </label>
            <label class="toggle-label">
              <input type="checkbox" class="toggle-input" disabled>
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
              Mode maintenance (désactivé)
            </label>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Range slider</h3>
          <div class="form-group" style="max-width:400px;">
            <label class="form-label" for="demo-range">Budget maximum : <strong>800 €</strong></label>
            <input type="range" class="form-range" id="demo-range" min="0" max="3000" step="50" value="800">
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Zone de téléversement</h3>
          <div class="file-upload-zone" data-file-drop>
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <p class="file-upload-text">Glissez vos fichiers ici ou <span class="file-upload-link">parcourez</span></p>
            <p class="file-upload-hint">PDF, JPG, PNG — Max 10 Mo</p>
            <input type="file" class="file-upload-input" multiple accept=".pdf,.jpg,.jpeg,.png">
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 5 — CARDS
           ============================================================ -->
      <section class="section" id="s-cards">
        <h2 class="section-title">Cards</h2>

        <div class="grid-3">

          <!-- Card simple -->
          <div class="card">
            <div class="card-header">
              <h4 class="card-title">Carte standard</h4>
              <span class="badge badge-info">Nouveau</span>
            </div>
            <div class="card-body">
              <p>Ceci est une carte standard avec header, body et footer. Utilisable pour n'importe quel type de contenu structuré.</p>
            </div>
            <div class="card-footer">
              <button class="btn btn-ghost btn-sm">Annuler</button>
              <button class="btn btn-primary btn-sm">Confirmer</button>
            </div>
          </div>

          <!-- Card avec image -->
          <div class="card card--media">
            <div class="card-media">
              <svg width="100%" height="180" viewBox="0 0 400 180" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect width="400" height="180" fill="#e2e8f0"/>
                <rect x="160" y="60" width="80" height="60" rx="4" fill="#94a3b8"/>
                <path d="M200 55 L240 90 H160 Z" fill="#64748b"/>
                <rect x="185" y="95" width="30" height="25" fill="#475569"/>
              </svg>
            </div>
            <div class="card-body">
              <h4 class="card-title">Appartement moderne</h4>
              <p>Card avec image de couverture. Idéale pour les fiches biens ou les articles.</p>
            </div>
          </div>

          <!-- Card KPI -->
          <div class="card card-kpi-group">
            <div class="card-header">
              <h4 class="card-title">Indicateurs clés</h4>
            </div>
            <div class="card-body">
              <div class="kpi-list">
                <div class="kpi-item">
                  <span class="kpi-label">Biens gérés</span>
                  <span class="kpi-value">24</span>
                  <span class="kpi-trend kpi-trend--up">↑ 12%</span>
                </div>
                <div class="kpi-item">
                  <span class="kpi-label">Disponibles</span>
                  <span class="kpi-value">8</span>
                  <span class="kpi-trend kpi-trend--down">↓ 2%</span>
                </div>
                <div class="kpi-item">
                  <span class="kpi-label">CA mensuel</span>
                  <span class="kpi-value">156k€</span>
                  <span class="kpi-trend kpi-trend--up">↑ 34%</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Card bien immobilier -->
          <div class="card card-bien">
            <div class="card-media" style="position:relative;">
              <svg width="100%" height="160" viewBox="0 0 400 160" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect width="400" height="160" fill="#dbeafe"/>
                <rect x="150" y="50" width="100" height="75" rx="3" fill="#93c5fd"/>
                <path d="M200 45 L255 90 H145 Z" fill="#3b82f6"/>
                <rect x="180" y="95" width="40" height="30" fill="#1d4ed8"/>
                <rect x="240" y="70" width="25" height="35" rx="2" fill="#60a5fa"/>
              </svg>
              <span class="bien-badge bien-badge-disponible" style="position:absolute;top:8px;left:8px;">Disponible</span>
            </div>
            <div class="card-body">
              <div class="bien-prix">1 250 €<span class="bien-prix-unit">/mois</span></div>
              <h4 class="card-title">Appartement T3</h4>
              <p class="bien-adresse">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Lyon 6e arrondissement
              </p>
              <div class="bien-meta">
                <span class="bien-meta-item">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                  72 m²
                </span>
                <span class="bien-meta-item">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 9V4a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v5"/><path d="M2 9h20v11a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V9z"/></svg>
                  3 chambres
                </span>
                <span class="bien-meta-item bien-dpe bien-dpe--b">DPE B</span>
              </div>
            </div>
          </div>

          <!-- Card collaborateur -->
          <div class="card card-collab">
            <div class="card-body card-body--center">
              <div class="avatar avatar-lg">ML</div>
              <h4 class="card-title" style="margin-top:var(--spacing-3);">Marie Lambert</h4>
              <p class="text-muted">Responsable RH</p>
              <span class="badge badge-warning" style="margin-top:var(--spacing-2);">En congé</span>
              <div class="card-collab-actions">
                <button class="btn btn-outline btn-sm">Voir le profil</button>
                <button class="btn btn-ghost btn-sm btn-icon" aria-label="Envoyer un message">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </button>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- ============================================================
           SECTION 6 — TABLES
           ============================================================ -->
      <section class="section" id="s-tables">
        <h2 class="section-title">Tables</h2>

        <div class="table-container">
          <table class="table" data-table data-paginate="3">
            <thead>
              <tr>
                <th class="table-col-check"><input type="checkbox" class="checkbox-input" data-check-all aria-label="Tout sélectionner"></th>
                <th class="sortable" data-sort="nom">Nom <span class="sort-icon">↕</span></th>
                <th class="sortable" data-sort="poste">Poste <span class="sort-icon">↕</span></th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><input type="checkbox" class="checkbox-input" aria-label="Sélectionner"></td>
                <td>
                  <div class="table-user">
                    <div class="avatar avatar-xs">ML</div>
                    <span>Marie Lambert</span>
                  </div>
                </td>
                <td>Responsable RH</td>
                <td><span class="badge badge-warning">En congé</span></td>
                <td class="table-actions">
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Éditer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Supprimer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                  </button>
                </td>
              </tr>
              <tr>
                <td><input type="checkbox" class="checkbox-input" aria-label="Sélectionner"></td>
                <td>
                  <div class="table-user">
                    <div class="avatar avatar-xs">TP</div>
                    <span>Thomas Petit</span>
                  </div>
                </td>
                <td>Gestionnaire locatif</td>
                <td><span class="badge badge-success">Actif</span></td>
                <td class="table-actions">
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Éditer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Supprimer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                  </button>
                </td>
              </tr>
              <tr>
                <td><input type="checkbox" class="checkbox-input" aria-label="Sélectionner"></td>
                <td>
                  <div class="table-user">
                    <div class="avatar avatar-xs">SB</div>
                    <span>Sophie Bernard</span>
                  </div>
                </td>
                <td>Comptable</td>
                <td><span class="badge badge-success">Actif</span></td>
                <td class="table-actions">
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Éditer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Supprimer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                  </button>
                </td>
              </tr>
              <tr>
                <td><input type="checkbox" class="checkbox-input" aria-label="Sélectionner"></td>
                <td>
                  <div class="table-user">
                    <div class="avatar avatar-xs">JM</div>
                    <span>Jean Martin</span>
                  </div>
                </td>
                <td>Commercial</td>
                <td><span class="badge badge-danger">Inactif</span></td>
                <td class="table-actions">
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Éditer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Supprimer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                  </button>
                </td>
              </tr>
              <tr>
                <td><input type="checkbox" class="checkbox-input" aria-label="Sélectionner"></td>
                <td>
                  <div class="table-user">
                    <div class="avatar avatar-xs">CR</div>
                    <span>Chloé Renard</span>
                  </div>
                </td>
                <td>Juriste</td>
                <td><span class="badge badge-info">Télétravail</span></td>
                <td class="table-actions">
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Éditer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn btn-ghost btn-xs btn-icon" aria-label="Supprimer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ============================================================
           SECTION 7 — BADGES & TAGS
           ============================================================ -->
      <section class="section" id="s-badges">
        <h2 class="section-title">Badges &amp; Tags</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Tailles &times; Couleurs</h3>
          <div class="badge-demo-grid">
            <div class="badge-demo-row">
              <span class="badge badge-primary badge-sm">Primary SM</span>
              <span class="badge badge-primary">Primary</span>
              <span class="badge badge-primary badge-lg">Primary LG</span>
            </div>
            <div class="badge-demo-row">
              <span class="badge badge-success badge-sm">Success SM</span>
              <span class="badge badge-success">Success</span>
              <span class="badge badge-success badge-lg">Success LG</span>
            </div>
            <div class="badge-demo-row">
              <span class="badge badge-warning badge-sm">Warning SM</span>
              <span class="badge badge-warning">Warning</span>
              <span class="badge badge-warning badge-lg">Warning LG</span>
            </div>
            <div class="badge-demo-row">
              <span class="badge badge-danger badge-sm">Danger SM</span>
              <span class="badge badge-danger">Danger</span>
              <span class="badge badge-danger badge-lg">Danger LG</span>
            </div>
            <div class="badge-demo-row">
              <span class="badge badge-info badge-sm">Info SM</span>
              <span class="badge badge-info">Info</span>
              <span class="badge badge-info badge-lg">Info LG</span>
            </div>
            <div class="badge-demo-row">
              <span class="badge badge-neutral badge-sm">Neutral SM</span>
              <span class="badge badge-neutral">Neutral</span>
              <span class="badge badge-neutral badge-lg">Neutral LG</span>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Variantes outline</h3>
          <div class="demo-row demo-row--wrap">
            <span class="badge badge-outline-primary">Primary</span>
            <span class="badge badge-outline-success">Success</span>
            <span class="badge badge-outline-warning">Warning</span>
            <span class="badge badge-outline-danger">Danger</span>
            <span class="badge badge-outline-info">Info</span>
            <span class="badge badge-outline-neutral">Neutral</span>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Badge avec point (dot)</h3>
          <div class="demo-row demo-row--wrap">
            <span class="badge badge-dot badge-dot-primary">En ligne</span>
            <span class="badge badge-dot badge-dot-success">Disponible</span>
            <span class="badge badge-dot badge-dot-warning">Absent</span>
            <span class="badge badge-dot badge-dot-danger">Hors ligne</span>
            <span class="badge badge-dot badge-dot-info">Télétravail</span>
            <span class="badge badge-dot badge-dot-neutral">Inconnu</span>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 8 — NAVIGATION
           ============================================================ -->
      <section class="section" id="s-nav">
        <h2 class="section-title">Navigation</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Tabs horizontaux</h3>
          <div class="tabs" data-tabs>
            <div class="tabs-list" role="tablist">
              <button class="tab-btn active" role="tab" data-tab="apercu" aria-selected="true">Aperçu</button>
              <button class="tab-btn" role="tab" data-tab="details" aria-selected="false">Détails</button>
              <button class="tab-btn" role="tab" data-tab="documents" aria-selected="false">Documents</button>
              <button class="tab-btn" role="tab" data-tab="historique" aria-selected="false">Historique</button>
            </div>
            <div class="tabs-content">
              <div class="tab-panel active" id="tab-apercu" role="tabpanel">
                <p>Contenu du panel <strong>Aperçu</strong>. Résumé général du bien ou du collaborateur.</p>
              </div>
              <div class="tab-panel" id="tab-details" role="tabpanel">
                <p>Contenu du panel <strong>Détails</strong>. Informations complètes et caractéristiques.</p>
              </div>
              <div class="tab-panel" id="tab-documents" role="tabpanel">
                <p>Contenu du panel <strong>Documents</strong>. Bail, diagnostics, pièces jointes.</p>
              </div>
              <div class="tab-panel" id="tab-historique" role="tabpanel">
                <p>Contenu du panel <strong>Historique</strong>. Journal des modifications et événements.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Tabs pills</h3>
          <div class="tabs tabs--pills" data-tabs>
            <div class="tabs-list" role="tablist">
              <button class="tab-btn active" role="tab" data-tab="all" aria-selected="true">Tous</button>
              <button class="tab-btn" role="tab" data-tab="actifs" aria-selected="false">Actifs</button>
              <button class="tab-btn" role="tab" data-tab="archives" aria-selected="false">Archivés</button>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Breadcrumb</h3>
          <nav class="breadcrumb" aria-label="Fil d'Ariane">
            <ol class="breadcrumb-list">
              <li class="breadcrumb-item"><a href="#">Accueil</a></li>
              <li class="breadcrumb-separator" aria-hidden="true">/</li>
              <li class="breadcrumb-item"><a href="#">RH</a></li>
              <li class="breadcrumb-separator" aria-hidden="true">/</li>
              <li class="breadcrumb-item"><a href="#">Profil</a></li>
              <li class="breadcrumb-separator" aria-hidden="true">/</li>
              <li class="breadcrumb-item breadcrumb-item--current" aria-current="page">Modifier</li>
            </ol>
          </nav>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Pagination</h3>
          <nav class="pagination" aria-label="Pagination">
            <button class="pagination-btn" aria-label="Page précédente">← Préc.</button>
            <button class="pagination-btn">1</button>
            <button class="pagination-btn">2</button>
            <button class="pagination-btn pagination-btn--active" aria-current="page">3</button>
            <button class="pagination-btn">4</button>
            <span class="pagination-ellipsis">…</span>
            <button class="pagination-btn">8</button>
            <button class="pagination-btn">9</button>
            <button class="pagination-btn" aria-label="Page suivante">Suiv. →</button>
          </nav>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Stepper</h3>
          <ol class="stepper">
            <li class="stepper-step stepper-step--completed">
              <div class="stepper-indicator">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <div class="stepper-content">
                <span class="stepper-label">Informations</span>
              </div>
            </li>
            <li class="stepper-step stepper-step--current" aria-current="step">
              <div class="stepper-indicator">2</div>
              <div class="stepper-content">
                <span class="stepper-label">Contrat</span>
              </div>
            </li>
            <li class="stepper-step">
              <div class="stepper-indicator">3</div>
              <div class="stepper-content">
                <span class="stepper-label">Documents</span>
              </div>
            </li>
            <li class="stepper-step">
              <div class="stepper-indicator">4</div>
              <div class="stepper-content">
                <span class="stepper-label">Validation</span>
              </div>
            </li>
          </ol>
        </div>
      </section>

      <!-- ============================================================
           SECTION 9 — FEEDBACK & ÉTATS
           ============================================================ -->
      <section class="section" id="s-feedback">
        <h2 class="section-title">Feedback &amp; États</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Alertes</h3>
          <div class="alert alert-success" data-alert>
            <svg class="alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><strong>Succès !</strong> Le bien a été enregistré avec succès.</span>
            <button class="alert-close" data-alert-close aria-label="Fermer">&times;</button>
          </div>
          <div class="alert alert-warning" data-alert>
            <svg class="alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><strong>Attention !</strong> Ce contrat expire dans 30 jours.</span>
            <button class="alert-close" data-alert-close aria-label="Fermer">&times;</button>
          </div>
          <div class="alert alert-danger" data-alert>
            <svg class="alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <span><strong>Erreur !</strong> Impossible de supprimer ce bien, il est lié à un contrat actif.</span>
            <button class="alert-close" data-alert-close aria-label="Fermer">&times;</button>
          </div>
          <div class="alert alert-info" data-alert>
            <svg class="alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><strong>Info :</strong> Mise à jour du système prévue le 5 avril 2026 à 22h.</span>
            <button class="alert-close" data-alert-close aria-label="Fermer">&times;</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Toasts</h3>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-success btn-sm" data-toast="success" data-toast-message="Opération réussie avec succès !">Toast Success</button>
            <button class="btn btn-warning btn-sm" data-toast="warning" data-toast-message="Vérifiez les informations saisies.">Toast Warning</button>
            <button class="btn btn-danger btn-sm" data-toast="error" data-toast-message="Une erreur est survenue.">Toast Error</button>
            <button class="btn btn-secondary btn-sm" data-toast="info" data-toast-message="Mise à jour disponible.">Toast Info</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Barres de progression</h3>
          <div class="progress-list">
            <div class="progress-item">
              <div class="progress-header">
                <span>Occupation du parc</span>
                <span>45%</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill" style="width:45%;"></div>
              </div>
            </div>
            <div class="progress-item">
              <div class="progress-header">
                <span>Budget consommé (animé)</span>
                <span>70%</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill progress-fill--animated" style="width:70%;"></div>
              </div>
            </div>
            <div class="progress-item">
              <div class="progress-header">
                <span>Biens conformes DPE</span>
                <span>30%</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill progress-fill--success" style="width:30%;"></div>
              </div>
            </div>
            <div class="progress-item">
              <div class="progress-header">
                <span>Taux de sinistralité</span>
                <span>85%</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill progress-fill--danger" style="width:85%;"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Skeleton loaders</h3>
          <div class="skeleton-card">
            <div class="skeleton-avatar"></div>
            <div class="skeleton-content">
              <div class="skeleton skeleton-title"></div>
              <div class="skeleton skeleton-text"></div>
              <div class="skeleton skeleton-text skeleton-text--short"></div>
              <div class="skeleton skeleton-text"></div>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">État vide</h3>
          <div class="empty-state">
            <svg class="empty-state-icon" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
              <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
              <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
              <line x1="12" y1="12" x2="12" y2="16"/>
              <line x1="10" y1="14" x2="14" y2="14"/>
            </svg>
            <h3 class="empty-state-title">Aucun résultat</h3>
            <p class="empty-state-text">Aucun bien ne correspond à vos critères de recherche.</p>
            <button class="btn btn-outline">Réinitialiser les filtres</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Spinners</h3>
          <div class="demo-row demo-row--center">
            <div class="spinner spinner-sm" role="status" aria-label="Chargement"></div>
            <div class="spinner" role="status" aria-label="Chargement"></div>
            <div class="spinner spinner-lg" role="status" aria-label="Chargement"></div>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 10 — MODALES & OVERLAYS
           ============================================================ -->
      <section class="section" id="s-modals">
        <h2 class="section-title">Modales &amp; Overlays</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Modales</h3>
          <div class="demo-row demo-row--wrap">
            <button class="btn btn-primary" data-modal-open="modal-profil">Modale Profil</button>
            <button class="btn btn-danger" data-modal-open="modal-confirm-delete">Modale Suppression</button>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Drawer</h3>
          <button class="btn btn-secondary" data-drawer-open="drawer-collab">Ouvrir le drawer</button>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Dropdown menu</h3>
          <div class="dropdown" data-dropdown>
            <button class="btn btn-outline" data-dropdown-toggle>
              Actions
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="margin-left:6px;"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="dropdown-menu" data-dropdown-menu>
              <button class="dropdown-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Éditer
              </button>
              <button class="dropdown-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Dupliquer
              </button>
              <div class="dropdown-divider"></div>
              <button class="dropdown-item dropdown-item--danger">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                Supprimer
              </button>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Tooltips</h3>
          <div class="demo-row demo-row--wrap demo-row--spaced">
            <button class="btn btn-outline" data-tooltip="Tooltip en haut" data-tooltip-pos="top">Haut</button>
            <button class="btn btn-outline" data-tooltip="Tooltip en bas" data-tooltip-pos="bottom">Bas</button>
            <button class="btn btn-outline" data-tooltip="Tooltip à gauche" data-tooltip-pos="left">Gauche</button>
            <button class="btn btn-outline" data-tooltip="Tooltip à droite" data-tooltip-pos="right">Droite</button>
          </div>
        </div>
      </section>

      <!-- ===== MODALE : Modifier le profil ===== -->
      <div class="modal-overlay" id="modal-profil" data-modal hidden>
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-profil-title">
          <div class="modal-header">
            <h3 class="modal-title" id="modal-profil-title">Modifier le profil</h3>
            <button class="modal-close" data-modal-close aria-label="Fermer">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label" for="modal-nom">Nom complet</label>
              <input class="form-input" type="text" id="modal-nom" value="Marie Lambert">
            </div>
            <div class="form-group">
              <label class="form-label" for="modal-role">Rôle</label>
              <select class="form-input form-select" id="modal-role">
                <option value="admin">Administrateur</option>
                <option value="rh" selected>Responsable RH</option>
                <option value="gestionnaire">Gestionnaire</option>
                <option value="comptable">Comptable</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" data-modal-close>Annuler</button>
            <button class="btn btn-primary">Sauvegarder</button>
          </div>
        </div>
      </div>

      <!-- ===== MODALE : Confirmation suppression ===== -->
      <div class="modal-overlay" id="modal-confirm-delete" data-modal hidden>
        <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="modal-delete-title">
          <div class="modal-header modal-header--danger">
            <div class="modal-danger-icon" aria-hidden="true">⚠</div>
            <h3 class="modal-title" id="modal-delete-title">Supprimer le bien ?</h3>
            <button class="modal-close" data-modal-close aria-label="Fermer">&times;</button>
          </div>
          <div class="modal-body">
            <p>Cette action est <strong>irréversible</strong>. Toutes les données associées à ce bien (contrats, documents, historique) seront définitivement supprimées.</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" data-modal-close>Annuler</button>
            <button class="btn btn-danger">Supprimer définitivement</button>
          </div>
        </div>
      </div>

      <!-- ===== DRAWER : Détails collaborateur ===== -->
      <div class="drawer-overlay" id="drawer-collab" data-drawer hidden>
        <div class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-collab-title">
          <div class="drawer-header">
            <h3 class="drawer-title" id="drawer-collab-title">Détails du collaborateur</h3>
            <button class="drawer-close" data-drawer-close aria-label="Fermer">&times;</button>
          </div>
          <div class="drawer-body">
            <div class="drawer-profile">
              <div class="avatar avatar-xl">ML</div>
              <h4>Marie Lambert</h4>
              <p class="text-muted">Responsable RH</p>
              <span class="badge badge-warning">En congé</span>
            </div>
            <div class="drawer-info-list">
              <div class="drawer-info-item">
                <span class="drawer-info-label">Email</span>
                <span class="drawer-info-value">marie.lambert@maboximmo.fr</span>
              </div>
              <div class="drawer-info-item">
                <span class="drawer-info-label">Téléphone</span>
                <span class="drawer-info-value">+33 6 12 34 56 78</span>
              </div>
              <div class="drawer-info-item">
                <span class="drawer-info-label">Département</span>
                <span class="drawer-info-value">Ressources Humaines</span>
              </div>
              <div class="drawer-info-item">
                <span class="drawer-info-label">Date d'entrée</span>
                <span class="drawer-info-value">12 mars 2021</span>
              </div>
              <div class="drawer-info-item">
                <span class="drawer-info-label">Contrat</span>
                <span class="drawer-info-value">CDI temps plein</span>
              </div>
            </div>
          </div>
          <div class="drawer-footer">
            <button class="btn btn-outline btn-sm" data-drawer-close>Fermer</button>
            <button class="btn btn-primary btn-sm">Modifier le profil</button>
          </div>
        </div>
      </div>

      <!-- ============================================================
           SECTION 11 — COMPOSANTS MÉTIER MABOXIMMO
           ============================================================ -->
      <section class="section" id="s-metier">
        <h2 class="section-title">Composants métier MaBoxImmo</h2>

        <div class="section-block">
          <h3 class="section-subtitle">Badges statut bien</h3>
          <div class="demo-row demo-row--wrap">
            <span class="bien-badge bien-badge-disponible">Disponible</span>
            <span class="bien-badge bien-badge-loue">Loué</span>
            <span class="bien-badge bien-badge-vendu">Vendu</span>
            <span class="bien-badge bien-badge-en-cours">En cours</span>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Timeline historique</h3>
          <ol class="syndic-timeline">
            <li class="timeline-item timeline-item--success">
              <div class="timeline-marker"></div>
              <div class="timeline-content">
                <span class="timeline-date">2 avr. 2026</span>
                <p class="timeline-text"><strong>Contrat signé</strong> — Bail 3 ans avec M. Dupont pour l'appartement T3 Lyon 6e.</p>
              </div>
            </li>
            <li class="timeline-item timeline-item--info">
              <div class="timeline-marker"></div>
              <div class="timeline-content">
                <span class="timeline-date">28 mar. 2026</span>
                <p class="timeline-text"><strong>Visite effectuée</strong> — État des lieux d'entrée réalisé par Thomas Petit.</p>
              </div>
            </li>
            <li class="timeline-item timeline-item--warning">
              <div class="timeline-marker"></div>
              <div class="timeline-content">
                <span class="timeline-date">20 mar. 2026</span>
                <p class="timeline-text"><strong>Alerte loyer</strong> — Loyer du bien Rue de la Paix en attente de règlement depuis 5 jours.</p>
              </div>
            </li>
            <li class="timeline-item">
              <div class="timeline-marker"></div>
              <div class="timeline-content">
                <span class="timeline-date">15 mar. 2026</span>
                <p class="timeline-text"><strong>Document ajouté</strong> — Diagnostic DPE mis à jour (catégorie B).</p>
              </div>
            </li>
            <li class="timeline-item timeline-item--danger">
              <div class="timeline-marker"></div>
              <div class="timeline-content">
                <span class="timeline-date">10 mar. 2026</span>
                <p class="timeline-text"><strong>Sinistre déclaré</strong> — Dégât des eaux signalé au 3e étage. Assurance contactée.</p>
              </div>
            </li>
          </ol>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">KPI RH</h3>
          <div class="grid-3">
            <div class="card card-kpi">
              <div class="card-kpi-icon card-kpi-icon--warning">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              </div>
              <div class="card-kpi-body">
                <span class="card-kpi-label">Congés pris</span>
                <span class="card-kpi-value">18 j</span>
                <span class="kpi-trend kpi-trend--up">↑ 3 vs N-1</span>
              </div>
            </div>
            <div class="card card-kpi">
              <div class="card-kpi-icon card-kpi-icon--info">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </div>
              <div class="card-kpi-body">
                <span class="card-kpi-label">Entretiens</span>
                <span class="card-kpi-value">7</span>
                <span class="kpi-trend kpi-trend--up">↑ 2 ce mois</span>
              </div>
            </div>
            <div class="card card-kpi">
              <div class="card-kpi-icon card-kpi-icon--success">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              </div>
              <div class="card-kpi-body">
                <span class="card-kpi-label">Indemnités</span>
                <span class="card-kpi-value">3 420 €</span>
                <span class="kpi-trend kpi-trend--down">↓ 8% vs N-1</span>
              </div>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Mini biens</h3>
          <div class="mini-bien-list">
            <div class="mini-bien">
              <div class="mini-bien-photo">
                <svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <rect width="60" height="60" fill="#dbeafe"/>
                  <path d="M30 12 L48 28 H12 Z" fill="#3b82f6"/>
                  <rect x="20" y="28" width="20" height="20" fill="#60a5fa"/>
                  <rect x="26" y="35" width="8" height="13" fill="#1d4ed8"/>
                </svg>
              </div>
              <div class="mini-bien-info">
                <span class="mini-bien-ref">REF-2024-001</span>
                <span class="mini-bien-adresse">12 Rue Molière, Lyon 6e</span>
                <span class="mini-bien-prix">1 250 €/mois</span>
              </div>
              <span class="bien-badge bien-badge-disponible">Disponible</span>
            </div>
            <div class="mini-bien">
              <div class="mini-bien-photo">
                <svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <rect width="60" height="60" fill="#dcfce7"/>
                  <path d="M30 10 L50 30 H10 Z" fill="#22c55e"/>
                  <rect x="18" y="30" width="24" height="18" fill="#86efac"/>
                  <rect x="25" y="37" width="10" height="11" fill="#16a34a"/>
                </svg>
              </div>
              <div class="mini-bien-info">
                <span class="mini-bien-ref">REF-2024-008</span>
                <span class="mini-bien-adresse">5 Avenue Foch, Paris 16e</span>
                <span class="mini-bien-prix">3 800 €/mois</span>
              </div>
              <span class="bien-badge bien-badge-loue">Loué</span>
            </div>
            <div class="mini-bien">
              <div class="mini-bien-photo">
                <svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <rect width="60" height="60" fill="#fef9c3"/>
                  <rect x="10" y="20" width="40" height="28" rx="3" fill="#fde047"/>
                  <rect x="5" y="17" width="50" height="6" rx="2" fill="#ca8a04"/>
                  <rect x="22" y="32" width="16" height="16" fill="#a16207"/>
                </svg>
              </div>
              <div class="mini-bien-info">
                <span class="mini-bien-ref">REF-2023-045</span>
                <span class="mini-bien-adresse">8 Rue du Commerce, Bordeaux</span>
                <span class="mini-bien-prix">890 €/mois</span>
              </div>
              <span class="bien-badge bien-badge-en-cours">En cours</span>
            </div>
          </div>
        </div>

        <div class="section-block">
          <h3 class="section-subtitle">Calendrier congés (statique)</h3>
          <div class="conges-calendar">
            <div class="conges-calendar-header">
              <span class="conges-month">Avril 2026</span>
            </div>
            <table class="conges-table">
              <thead>
                <tr>
                  <th>Lun</th><th>Mar</th><th>Mer</th><th>Jeu</th><th>Ven</th><th>Sam</th><th>Dim</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="conges-empty"></td>
                  <td>1</td>
                  <td class="conges-day--off">2</td>
                  <td class="conges-day--off">3</td>
                  <td class="conges-day--off">4</td>
                  <td class="conges-weekend">5</td>
                  <td class="conges-weekend">6</td>
                </tr>
                <tr>
                  <td class="conges-day--off">7</td>
                  <td class="conges-day--off">8</td>
                  <td>9</td>
                  <td>10</td>
                  <td>11</td>
                  <td class="conges-weekend">12</td>
                  <td class="conges-weekend">13</td>
                </tr>
                <tr>
                  <td>14</td>
                  <td class="conges-day--ferie">15</td>
                  <td>16</td>
                  <td>17</td>
                  <td class="conges-day--today">18</td>
                  <td class="conges-weekend">19</td>
                  <td class="conges-weekend">20</td>
                </tr>
                <tr>
                  <td>21</td>
                  <td>22</td>
                  <td class="conges-day--rtt">23</td>
                  <td class="conges-day--rtt">24</td>
                  <td>25</td>
                  <td class="conges-weekend">26</td>
                  <td class="conges-weekend">27</td>
                </tr>
                <tr>
                  <td>28</td>
                  <td>29</td>
                  <td>30</td>
                  <td class="conges-empty"></td>
                  <td class="conges-empty"></td>
                  <td class="conges-empty"></td>
                  <td class="conges-empty"></td>
                </tr>
              </tbody>
            </table>
            <div class="conges-legend">
              <span class="conges-legend-item"><span class="conges-legend-dot conges-day--off"></span> Congés payés</span>
              <span class="conges-legend-item"><span class="conges-legend-dot conges-day--rtt"></span> RTT</span>
              <span class="conges-legend-item"><span class="conges-legend-dot conges-day--ferie"></span> Férié</span>
              <span class="conges-legend-item"><span class="conges-legend-dot conges-day--today"></span> Aujourd'hui</span>
            </div>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 12 — DARK MODE
           ============================================================ -->
      <section class="section" id="s-darkmode">
        <h2 class="section-title">Dark Mode</h2>

        <div class="section-block">
          <div class="card" style="max-width:480px;">
            <div class="card-body">
              <h3 class="card-title">Thème sombre</h3>
              <p>Le dark mode est géré via la classe <code>.dark</code> appliquée sur l'élément <code>&lt;html&gt;</code>. La préférence est persistée dans <code>localStorage</code> sous la clé <code>mabox-theme</code>.</p>
              <p style="margin-top:var(--spacing-3);">Toutes les couleurs utilisent des variables CSS définies dans <code>tokens.css</code>, avec une section <code>[data-theme="dark"]</code> ou <code>.dark</code> qui remplace automatiquement les valeurs.</p>
              <div style="margin-top:var(--spacing-4);">
                <button class="btn btn-primary btn-lg" data-toggle-dark data-dark-icon="🌙" data-light-icon="☀️">
                  Basculer Dark / Light
                </button>
              </div>
              <p class="text-muted" style="margin-top:var(--spacing-3);font-size:var(--font-size-sm);">
                Le bouton dans la topbar et ce bouton partagent le même <code>data-toggle-dark</code> — components.js synchronise les deux.
              </p>
            </div>
          </div>
        </div>
      </section>

    </main>
    <!-- /MAIN CONTENT -->

  </div>
  <!-- /MAIN WRAPPER -->

</div>
<!-- /APP -->

<!-- Zone des toasts -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<script src="js/components.js"></script>
</body>
</html>
