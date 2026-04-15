<!DOCTYPE html>
<html lang="fr" data-theme="">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Éditeur de thème — MaBoxImmo</title>
  <link rel="stylesheet" href="css/tokens.css">
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/layout.css">
  <style>
    .theme-editor-layout {
      display: flex;
      height: 100vh;
      overflow: hidden;
    }
    .theme-panel {
      width: 400px;
      flex-shrink: 0;
      overflow-y: auto;
      background: var(--bg-primary);
      border-right: 1px solid var(--border-medium);
      display: flex;
      flex-direction: column;
    }
    .theme-panel-header {
      padding: var(--space-5) var(--space-5) var(--space-4);
      border-bottom: 1px solid var(--border-light);
      flex-shrink: 0;
    }
    .theme-panel-body {
      padding: var(--space-4) var(--space-5);
      flex: 1;
      overflow-y: auto;
    }
    .theme-panel-footer {
      padding: var(--space-4) var(--space-5);
      border-top: 1px solid var(--border-light);
      flex-shrink: 0;
    }
    .theme-preview-pane {
      flex: 1;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      background: var(--bg-secondary);
    }
    .preview-header {
      padding: var(--space-3) var(--space-5);
      background: var(--bg-primary);
      border-bottom: 1px solid var(--border-light);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }
    #theme-preview {
      flex: 1;
      overflow: auto;
      display: flex;
    }
    .preview-shell {
      display: flex;
      width: 100%;
      min-height: 100%;
      font-family: var(--brand-font-sans, var(--font-sans));
    }
    .preview-sidebar {
      width: var(--sidebar-width, 260px);
      background: var(--brand-sidebar-bg, #312E81);
      color: var(--brand-sidebar-text, #E0E7FF);
      padding: var(--space-4);
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
      flex-shrink: 0;
      transition: width 0.3s;
    }
    .preview-sidebar-logo {
      font-size: 16px;
      font-weight: 700;
      padding: var(--space-3) var(--space-2) var(--space-5);
      opacity: 0.9;
    }
    .preview-nav-item {
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-base, 8px);
      font-size: 14px;
      color: rgba(255,255,255,0.65);
      cursor: pointer;
      transition: background 0.15s;
    }
    .preview-nav-item:hover,
    .preview-nav-item.active {
      background: #ffffff;
      color: rgba(255,255,255,0.95);
    }
    .preview-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      background: var(--bg-secondary);
      overflow: auto;
    }
    .preview-topbar {
      height: 52px;
      background: var(--brand-topbar-bg, #ffffff);
      border-bottom: 1px solid var(--border-light);
      display: flex;
      align-items: center;
      padding: 0 var(--space-5);
      gap: var(--space-3);
      flex-shrink: 0;
    }
    .preview-main {
      padding: var(--space-5);
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
    }
    .preview-kpi-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-3);
    }
    .panel-section {
      border-bottom: 1px solid var(--border-light);
      padding: var(--space-4) 0;
    }
    .panel-section:last-child { border-bottom: none; }
    .panel-section-title {
      font-size: var(--text-sm);
      font-weight: var(--font-semibold);
      color: var(--text-primary);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: var(--space-3);
    }
    .color-control { margin-bottom: var(--space-3); }
    .color-control label {
      font-size: var(--text-sm);
      color: var(--text-secondary);
      display: block;
      margin-bottom: var(--space-1);
    }
    .color-input-row {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .color-input-row input[type="color"] {
      width: 36px;
      height: 36px;
      border: 2px solid var(--border-medium);
      border-radius: var(--radius-md);
      cursor: pointer;
      padding: 2px;
      background: none;
    }
    .color-input-row .input { flex: 1; }
    .color-preview {
      width: 36px; height: 36px;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-medium);
      flex-shrink: 0;
    }
    .density-group {
      display: flex;
      gap: var(--space-2);
    }
    .density-group .btn { flex: 1; }
    .preset-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: var(--space-2);
    }
    .preset-btn {
      padding: var(--space-3);
      background: var(--bg-secondary);
      border: 2px solid var(--border-medium);
      border-radius: var(--radius-md);
      cursor: pointer;
      text-align: center;
      font-size: var(--text-sm);
      font-weight: var(--font-medium);
      transition: all 0.2s;
      color: var(--text-primary);
    }
    .preset-btn:hover {
      border-color: var(--brand-primary);
      color: var(--brand-primary);
    }
    #palette-preview { margin-top: var(--space-4); }
    .range-group {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-bottom: var(--space-3);
    }
    .range-group label {
      font-size: var(--text-sm);
      color: var(--text-secondary);
      min-width: 120px;
    }
    .range-group .range { flex: 1; }
    .range-group span {
      font-size: var(--text-sm);
      font-weight: var(--font-medium);
      color: var(--text-primary);
      min-width: 40px;
      text-align: right;
    }
  </style>
</head>
<body>

<div class="theme-editor-layout">

  <!-- ═══════════════════════════════════════════
       PANNEAU DE CONTRÔLE GAUCHE
  ════════════════════════════════════════════════ -->
  <aside class="theme-panel">

    <!-- Header -->
    <div class="theme-panel-header">
      <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-1);">
        <h1 style="font-size:var(--text-lg);font-weight:var(--font-bold);color:var(--text-primary);margin:0;">Éditeur de thème</h1>
        <span class="badge badge-warning">Simulation</span>
      </div>
      <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0;">Les modifications sont appliquées en temps réel</p>
    </div>

    <!-- Body -->
    <div class="theme-panel-body">

      <!-- ── Section 1 : Identité de la société ── -->
      <div class="panel-section">
        <div class="panel-section-title">Identité de la société</div>

        <div style="margin-bottom:var(--space-3);">
          <label class="form-label" for="company-name">Nom de la société</label>
          <input
            type="text"
            id="company-name"
            name="company-name"
            class="input input-md"
            placeholder="Nom de la société"
            value="Société Exemple"
            style="width:100%;"
          >
        </div>

        <!-- Logo upload simulé -->
        <div style="margin-bottom:var(--space-3);">
          <label class="form-label">Logo</label>
          <input type="file" id="logo-file-input" accept="image/*" style="display:none;">
          <div
            id="logo-upload-zone"
            onclick="document.getElementById('logo-file-input').click()"
            style="
              border: 2px dashed var(--border-medium);
              border-radius: var(--radius-md);
              padding: var(--space-4);
              text-align: center;
              cursor: pointer;
              background: var(--bg-secondary);
              transition: border-color 0.2s;
            "
            onmouseenter="this.style.borderColor='var(--brand-primary)'"
            onmouseleave="this.style.borderColor='var(--border-medium)'"
          >
            <div id="logo-placeholder-text" style="font-size:var(--text-sm);color:var(--text-muted);">
              🖼 (logo simulé) — cliquer pour changer
            </div>
          </div>
        </div>

        <!-- Favicon upload simulé -->
        <div>
          <label class="form-label">Favicon</label>
          <input type="file" id="favicon-file-input" accept="image/x-icon,image/png" style="display:none;">
          <div
            id="favicon-upload-zone"
            onclick="document.getElementById('favicon-file-input').click()"
            style="
              border: 2px dashed var(--border-medium);
              border-radius: var(--radius-md);
              padding: var(--space-3);
              text-align: center;
              cursor: pointer;
              background: var(--bg-secondary);
              transition: border-color 0.2s;
            "
            onmouseenter="this.style.borderColor='var(--brand-primary)'"
            onmouseleave="this.style.borderColor='var(--border-medium)'"
          >
            <div id="favicon-placeholder-text" style="font-size:var(--text-sm);color:var(--text-muted);">
              🔲 (favicon simulé) — cliquer pour changer
            </div>
          </div>
        </div>
      </div>

      <!-- ── Section 2 : Couleurs de marque ── -->
      <div class="panel-section">
        <div class="panel-section-title">Couleurs de marque</div>

        <!-- Couleur primaire -->
        <div class="color-control">
          <label for="color-primary">Couleur primaire</label>
          <div class="color-input-row">
            <input type="color" id="color-primary" value="#36577d">
            <input type="text" id="color-primary-hex" class="input input-sm" value="#36577d" placeholder="#000000">
            <div class="color-preview" id="primary-preview" style="background:#36577d;"></div>
          </div>
        </div>

        <!-- Couleur secondaire -->
        <div class="color-control">
          <label for="color-secondary">Couleur secondaire (accent)</label>
          <div class="color-input-row">
            <input type="color" id="color-secondary" value="#7a9060">
            <input type="text" id="color-secondary-hex" class="input input-sm" value="#7a9060" placeholder="#000000">
            <div class="color-preview" id="secondary-preview" style="background:#7a9060;"></div>
          </div>
        </div>

        <!-- Sidebar -->
        <div class="color-control">
          <label for="color-sidebar">Fond de la sidebar</label>
          <div class="color-input-row">
            <input type="color" id="color-sidebar" value="#f0f1f3">
            <input type="text" id="color-sidebar-hex" class="input input-sm" value="#f0f1f3" placeholder="#000000">
            <div class="color-preview" id="sidebar-preview" style="background:#f0f1f3;"></div>
          </div>
        </div>

        <!-- Topbar -->
        <div class="color-control">
          <label for="color-topbar">Fond de la topbar</label>
          <div class="color-input-row">
            <input type="color" id="color-topbar" value="var(--bg-primary)">
            <input type="text" id="color-topbar-hex" class="input input-sm" value="var(--bg-primary)" placeholder="#000000">
            <div class="color-preview" id="topbar-preview" style="background:var(--bg-primary);border:1px solid var(--border-medium);"></div>
          </div>
        </div>

        <!-- Liens -->
        <div class="color-control">
          <label for="color-links">Couleur des liens</label>
          <div class="color-input-row">
            <input type="color" id="color-links" value="#36577d">
            <input type="text" id="color-links-hex" class="input input-sm" value="#36577d" placeholder="#000000">
            <div class="color-preview" id="links-preview" style="background:#36577d;"></div>
          </div>
        </div>
      </div>

      <!-- ── Section 3 : Typographie ── -->
      <div class="panel-section">
        <div class="panel-section-title">Typographie</div>

        <div style="margin-bottom:var(--space-3);">
          <label class="form-label" for="font-select">Police d'interface</label>
          <select id="font-select" class="input input-md" style="width:100%;">
            <option value="Inter" selected>Inter</option>
            <option value="Roboto">Roboto</option>
            <option value="Poppins">Poppins</option>
            <option value="Lato">Lato</option>
            <option value="Open Sans">Open Sans</option>
            <option value="Nunito">Nunito</option>
            <option value="DM Sans">DM Sans</option>
          </select>
        </div>

        <div class="range-group">
          <label for="font-size">Taille de base</label>
          <input type="range" id="font-size" class="range" min="12" max="18" value="14" step="1">
          <span id="font-size-val">14px</span>
        </div>
      </div>

      <!-- ── Section 4 : Géométrie ── -->
      <div class="panel-section">
        <div class="panel-section-title">Géométrie</div>

        <div class="range-group">
          <label for="border-radius">Rayon de bordure</label>
          <input type="range" id="border-radius" class="range" min="0" max="24" value="8" step="2">
          <span id="radius-val">8px</span>
        </div>

        <div class="range-group">
          <label for="sidebar-width">Largeur sidebar</label>
          <input type="range" id="sidebar-width" class="range" min="200" max="320" value="260" step="10">
          <span id="sidebar-width-val">260px</span>
        </div>

        <div style="margin-top:var(--space-2);">
          <label class="form-label" style="margin-bottom:var(--space-2);">Densité d'affichage</label>
          <div class="density-group">
            <button class="btn btn-outline btn-sm" data-density="comfortable">Ample</button>
            <button class="btn btn-primary btn-sm" data-density="normal">Normal</button>
            <button class="btn btn-outline btn-sm" data-density="compact">Compact</button>
          </div>
        </div>
      </div>

      <!-- ── Section 5 : Préréglages ── -->
      <div class="panel-section">
        <div class="panel-section-title">Préréglages</div>
        <div class="preset-grid">
          <button class="preset-btn" data-preset="classique">🏛 Classique</button>
          <button class="preset-btn" data-preset="moderne">⚡ Moderne</button>
          <button class="preset-btn" data-preset="naturel">🌿 Naturel</button>
          <button class="preset-btn" data-preset="chaud">🔥 Chaud</button>
          <button class="preset-btn" data-preset="sombre" style="grid-column:span 2;">🌑 Sombre Pro</button>
        </div>
      </div>

      <!-- Palette preview (mis à jour par theme-editor.js) -->
      <div id="palette-preview"></div>

    </div><!-- /theme-panel-body -->

    <!-- Footer -->
    <div class="theme-panel-footer">
      <button class="btn btn-primary btn-md" style="width:100%;" id="btn-generate-css">Générer le CSS</button>
    </div>

  </aside><!-- /theme-panel -->


  <!-- ═══════════════════════════════════════════
       ZONE DE PRÉVISUALISATION DROITE
  ════════════════════════════════════════════════ -->
  <div class="theme-preview-pane">

    <!-- Header de la preview -->
    <div class="preview-header">
      <div>
        <span style="font-size:var(--text-sm);font-weight:var(--font-semibold);color:var(--text-primary);">
          Prévisualisation —
          <span data-preview-name>Société Exemple</span>
        </span>
      </div>
      <span class="badge badge-info" style="font-size:var(--text-xs);">Toutes les modifications s'appliquent en direct</span>
    </div>

    <!-- Preview iframe-like -->
    <div id="theme-preview">
      <div class="preview-shell">

        <!-- Sidebar simulée -->
        <div class="preview-sidebar" style="background:#f0f1f3;color:#36577d;">
          <div class="preview-sidebar-logo" style="color:#36577d;">
            🏢 <span data-preview-logo-name>Société Exemple</span>
          </div>
          <div class="preview-nav-item active" style="color:#4a6038;font-weight:700;">▶ Dashboard</div>
          <div class="preview-nav-item" style="color:#6a6660;">Biens</div>
          <div class="preview-nav-item" style="color:#6a6660;">RH</div>
          <div class="preview-nav-item" style="color:#6a6660;">Paramètres</div>
        </div>

        <!-- Contenu simulé -->
        <div class="preview-content">

          <!-- Topbar simulée -->
          <div class="preview-topbar">
            <div style="flex:1;font-size:14px;font-weight:600;color:var(--text-primary);">Dashboard</div>
            <span class="badge badge-success">En ligne</span>
            <div style="width:32px;height:32px;border-radius:50%;background:var(--brand-primary,#36577d);display:flex;align-items:center;justify-content:center;color:white;font-size:13px;font-weight:700;">A</div>
          </div>

          <!-- Main content simulé -->
          <div class="preview-main">

            <!-- KPI cards -->
            <div class="preview-kpi-row">
              <div class="card">
                <div class="card-body" style="padding:var(--space-4);">
                  <div style="font-size:var(--text-xs);color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-1);">Biens actifs</div>
                  <div style="font-size:24px;font-weight:700;color:var(--brand-primary,#36577d);">142</div>
                  <div style="font-size:var(--text-xs);color:var(--text-success,#16a34a);margin-top:var(--space-1);">↑ +12 ce mois</div>
                </div>
              </div>
              <div class="card">
                <div class="card-body" style="padding:var(--space-4);">
                  <div style="font-size:var(--text-xs);color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-1);">Mandats</div>
                  <div style="font-size:24px;font-weight:700;color:var(--brand-secondary,#7a9060);">38</div>
                  <div style="font-size:var(--text-xs);color:var(--text-muted);margin-top:var(--space-1);">= stable</div>
                </div>
              </div>
              <div class="card">
                <div class="card-body" style="padding:var(--space-4);">
                  <div style="font-size:var(--text-xs);color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-1);">Collaborateurs</div>
                  <div style="font-size:24px;font-weight:700;color:var(--text-primary);">9</div>
                  <div style="font-size:var(--text-xs);color:var(--text-danger,#dc2626);margin-top:var(--space-1);">↓ -1 départ</div>
                </div>
              </div>
            </div>

            <!-- Mini table simulée -->
            <div class="card">
              <div class="card-body" style="padding:0;">
                <div style="padding:var(--space-3) var(--space-4);border-bottom:1px solid var(--border-light);font-size:var(--text-sm);font-weight:var(--font-semibold);color:var(--text-primary);">Derniers biens</div>
                <table style="width:100%;border-collapse:collapse;font-size:var(--text-sm);">
                  <thead>
                    <tr style="background:var(--bg-secondary);">
                      <th style="padding:var(--space-2) var(--space-4);text-align:left;color:var(--text-muted);font-weight:var(--font-medium);font-size:var(--text-xs);text-transform:uppercase;">Référence</th>
                      <th style="padding:var(--space-2) var(--space-4);text-align:left;color:var(--text-muted);font-weight:var(--font-medium);font-size:var(--text-xs);text-transform:uppercase;">Type</th>
                      <th style="padding:var(--space-2) var(--space-4);text-align:left;color:var(--text-muted);font-weight:var(--font-medium);font-size:var(--text-xs);text-transform:uppercase;">Statut</th>
                      <th style="padding:var(--space-2) var(--space-4);text-align:right;color:var(--text-muted);font-weight:var(--font-medium);font-size:var(--text-xs);text-transform:uppercase;">Prix</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr style="border-top:1px solid var(--border-light);">
                      <td style="padding:var(--space-2) var(--space-4);color:var(--brand-primary,#36577d);font-weight:var(--font-medium);">B-2024-001</td>
                      <td style="padding:var(--space-2) var(--space-4);color:var(--text-primary);">Appartement T3</td>
                      <td style="padding:var(--space-2) var(--space-4);"><span class="badge badge-success">Disponible</span></td>
                      <td style="padding:var(--space-2) var(--space-4);text-align:right;font-weight:var(--font-semibold);color:var(--text-primary);">285 000 €</td>
                    </tr>
                    <tr style="border-top:1px solid var(--border-light);">
                      <td style="padding:var(--space-2) var(--space-4);color:var(--brand-primary,#36577d);font-weight:var(--font-medium);">B-2024-002</td>
                      <td style="padding:var(--space-2) var(--space-4);color:var(--text-primary);">Maison T5</td>
                      <td style="padding:var(--space-2) var(--space-4);"><span class="badge badge-warning">Sous offre</span></td>
                      <td style="padding:var(--space-2) var(--space-4);text-align:right;font-weight:var(--font-semibold);color:var(--text-primary);">420 000 €</td>
                    </tr>
                    <tr style="border-top:1px solid var(--border-light);">
                      <td style="padding:var(--space-2) var(--space-4);color:var(--brand-primary,#36577d);font-weight:var(--font-medium);">B-2024-003</td>
                      <td style="padding:var(--space-2) var(--space-4);color:var(--text-primary);">Studio</td>
                      <td style="padding:var(--space-2) var(--space-4);"><span class="badge badge-neutral">Vendu</span></td>
                      <td style="padding:var(--space-2) var(--space-4);text-align:right;font-weight:var(--font-semibold);color:var(--text-primary);">98 000 €</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Mini formulaire simulé -->
            <div class="card">
              <div class="card-body" style="padding:var(--space-4);">
                <div style="font-size:var(--text-sm);font-weight:var(--font-semibold);color:var(--text-primary);margin-bottom:var(--space-3);">Recherche rapide</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);margin-bottom:var(--space-3);">
                  <div>
                    <label style="font-size:var(--text-xs);color:var(--text-muted);display:block;margin-bottom:4px;">Ville</label>
                    <input type="text" class="input input-sm" placeholder="Ex : Lyon" style="width:100%;" readonly>
                  </div>
                  <div>
                    <label style="font-size:var(--text-xs);color:var(--text-muted);display:block;margin-bottom:4px;">Type de bien</label>
                    <select class="input input-sm" style="width:100%;">
                      <option>Appartement</option>
                      <option>Maison</option>
                    </select>
                  </div>
                </div>
                <div style="display:flex;gap:var(--space-2);">
                  <button class="btn btn-primary btn-sm">Rechercher</button>
                  <button class="btn btn-outline btn-sm">Réinitialiser</button>
                </div>
              </div>
            </div>

            <!-- Badges de démonstration -->
            <div style="display:flex;flex-wrap:wrap;gap:var(--space-2);align-items:center;">
              <span style="font-size:var(--text-xs);color:var(--text-muted);">Badges :</span>
              <span class="badge badge-primary">Primaire</span>
              <span class="badge badge-success">Succès</span>
              <span class="badge badge-warning">Attention</span>
              <span class="badge badge-danger">Erreur</span>
              <span class="badge badge-info">Info</span>
              <span class="badge badge-neutral">Neutre</span>
            </div>

          </div><!-- /preview-main -->
        </div><!-- /preview-content -->
      </div><!-- /preview-shell -->
    </div><!-- /theme-preview -->

  </div><!-- /theme-preview-pane -->

</div><!-- /theme-editor-layout -->


<!-- ═══════════════════════════════════════════
     MODALE EXPORT CSS
════════════════════════════════════════════════ -->
<div class="modal-overlay hidden" id="export-modal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 class="modal-title">CSS généré</h2>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <div class="modal-body">
      <textarea
        id="export-css-output"
        class="textarea"
        style="font-family:monospace;height:300px;"
        readonly
      ></textarea>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-md" data-modal-close>Fermer</button>
      <button class="btn btn-outline btn-md" id="btn-download-css">⬇ Télécharger</button>
      <button class="btn btn-primary btn-md" id="btn-copy-css">Copier</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container"></div>

<script src="js/components.js"></script>
<script src="js/theme-editor.js"></script>

</body>
</html>
