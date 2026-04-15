<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout = true;
$pageTitle = 'Nouveau bien — Import intelligent';
$bodyClass = '';
$robots = 'noindex, nofollow';

require __DIR__ . '/inc/header.php';
?>

<style>
  .intake-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 32px 28px 100px;
  }
  .intake-hero {
    text-align: center;
    margin-bottom: 36px;
  }
  .intake-label {
    display: inline-block;
    font-family: 'DM Mono', monospace;
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: #7a9060;
    padding: 5px 14px;
    background: rgba(122,144,96,.1);
    border-radius: 99px;
    margin-bottom: 14px;
  }
  .intake-hero h1 {
    font-size: 32px;
    font-weight: 800;
    color: #1a1816;
    margin: 0 0 10px;
    line-height: 1.2;
  }
  .intake-hero p {
    font-size: 14px;
    color: #888;
    max-width: 620px;
    margin: 0 auto;
    line-height: 1.6;
  }

  /* ─── DROP ZONE ─── */
  .intake-drop {
    border: 2px dashed #d4d0ca;
    background: linear-gradient(180deg, #fff 0%, #faf8f4 100%);
    border-radius: 22px;
    padding: 56px 32px;
    text-align: center;
    transition: all .25s ease;
    cursor: pointer;
    margin-bottom: 24px;
  }
  .intake-drop:hover, .intake-drop.over {
    border-color: #1f6f7a;
    background: linear-gradient(180deg, #f0f9fa 0%, #faf8f4 100%);
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(31,111,122,0.12);
  }
  .intake-drop-icon {
    font-size: 56px;
    line-height: 1;
    margin-bottom: 18px;
    display: block;
  }
  .intake-drop-title {
    font-size: 18px;
    font-weight: 700;
    color: #1a1816;
    margin-bottom: 8px;
  }
  .intake-drop-sub {
    font-size: 13px;
    color: #888;
  }
  .intake-drop-types {
    display: flex;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 18px;
  }
  .intake-drop-types span {
    padding: 5px 12px;
    background: #fff;
    border: 1px solid #e8e6e1;
    border-radius: 99px;
    font-size: 11px;
    color: #555;
    font-weight: 600;
  }

  /* ─── LISTE FICHIERS ─── */
  .intake-files {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 28px;
  }
  .intake-file {
    background: #fff;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    border-left: 4px solid #d4d0ca;
    animation: slideIn .3s ease;
  }
  @keyframes slideIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .intake-file.analysing { border-left-color: #f59e0b; }
  .intake-file.success   { border-left-color: #16a34a; }
  .intake-file.error     { border-left-color: #dc2626; }
  .intake-file-icon {
    font-size: 28px;
    flex-shrink: 0;
  }
  .intake-file-info { flex: 1; min-width: 0; }
  .intake-file-name {
    font-weight: 700;
    font-size: 14px;
    color: #1a1816;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .intake-file-meta {
    font-size: 11px;
    color: #888;
    margin-top: 2px;
  }
  .intake-file-status {
    flex-shrink: 0;
    padding: 6px 12px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 700;
  }
  .intake-file.analysing .intake-file-status { background: #fef3c7; color: #92400e; }
  .intake-file.success   .intake-file-status { background: #dcfce7; color: #15803d; }
  .intake-file.error     .intake-file-status { background: #fee2e2; color: #991b1b; }
  .intake-spinner {
    display: inline-block;
    width: 14px; height: 14px;
    border: 2px solid #f59e0b;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin .8s linear infinite;
    vertical-align: middle;
    margin-right: 6px;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* ─── ACTIONS BOUTONS DANS LIGNE FICHIER ─── */
  .intake-file-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
  }
  .intake-file-btn {
    background: #fff;
    border: 1px solid #d4d0ca;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px;
    cursor: pointer;
    color: #555;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all .15s;
    font-family: inherit;
  }
  .intake-file-btn:hover { background: #f5f1ee; border-color: #1f6f7a; color: #1f6f7a; }
  .intake-file-btn.danger:hover { border-color: #dc2626; color: #dc2626; }

  /* ─── CARTES CANDIDATS (propriétaire / immeuble) ─── */
  .intake-candidates {
    background: #fff;
    border-radius: 16px;
    padding: 20px 24px;
    box-shadow: 0 4px 14px rgba(0,0,0,.05);
    margin-bottom: 18px;
    border-left: 4px solid #1f6f7a;
  }
  .intake-candidates-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
  }
  .intake-candidates-title {
    font-size: 14px; font-weight: 700; color: #1a1816;
  }
  .intake-candidates-sub { font-size: 11px; color: #888; }
  .intake-candidates-list {
    display: flex; flex-direction: column; gap: 8px;
  }
  .intake-candidate-card {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 12px 14px; background: #f5f1ee;
    border-radius: 10px; border: 2px solid transparent; cursor: pointer;
    transition: all .15s;
  }
  .intake-candidate-card:hover { border-color: #1f6f7a; background: #fff; transform: translateX(2px); }
  .intake-candidate-card.linked {
    background: #dcfce7; border-color: #16a34a;
  }
  .intake-candidate-info { flex: 1; min-width: 0; }
  .intake-candidate-name { font-weight: 700; font-size: 13px; color: #1a1816; }
  .intake-candidate-meta { font-size: 11px; color: #888; margin-top: 2px; }
  .intake-candidate-score {
    padding: 4px 10px; border-radius: 99px; font-size: 10px; font-weight: 700;
    background: #e0e7ff; color: #4338ca; flex-shrink: 0;
  }
  .intake-candidate-score.high { background: #dcfce7; color: #15803d; }
  .intake-create-new {
    margin-top: 10px; padding: 12px 14px;
    background: #fff7e6; border: 2px dashed #f59e0b; border-radius: 10px;
    cursor: pointer; text-align: center; font-size: 12px; color: #92400e;
    font-weight: 700; transition: all .15s;
  }
  .intake-create-new:hover { background: #fef3c7; transform: translateY(-1px); }

  /* ─── CHAMPS OBLIGATOIRES MANQUANTS ─── */
  .intake-missing {
    background: linear-gradient(180deg, #fff7e6, #fff);
    border-radius: 18px;
    padding: 22px 26px;
    box-shadow: 0 4px 18px rgba(245,158,11,0.12);
    margin-bottom: 22px;
    border-left: 5px solid #f59e0b;
    display: none;
  }
  .intake-missing.visible { display: block; }
  .intake-missing.complete {
    background: linear-gradient(180deg, #dcfce7, #fff);
    border-left-color: #16a34a;
  }
  .intake-missing-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
  }
  .intake-missing-title {
    font-size: 16px; font-weight: 700; color: #92400e;
  }
  .intake-missing.complete .intake-missing-title { color: #15803d; }
  .intake-missing-count {
    padding: 5px 12px; background: #f59e0b; color: #fff;
    border-radius: 99px; font-size: 11px; font-weight: 700;
  }
  .intake-missing.complete .intake-missing-count { background: #16a34a; }
  .intake-missing-grid {
    display: grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr));
    gap: 10px;
  }
  .intake-missing-field {
    background: #fff; padding: 10px 14px; border-radius: 10px;
    border: 1px solid #fde68a; display: flex; flex-direction: column; gap: 4px;
  }
  .intake-missing-field label {
    font-size: 11px; font-weight: 700; color: #92400e;
  }
  .intake-missing-field input,
  .intake-missing-field select,
  .intake-missing-field textarea {
    width: 100%; padding: 7px 10px; border: 1px solid #fde68a;
    border-radius: 6px; font-size: 12px; font-family: inherit;
  }
  .intake-missing-field input:focus,
  .intake-missing-field select:focus,
  .intake-missing-field textarea:focus {
    outline: none; border-color: #f59e0b;
  }
  .intake-missing-field.saved {
    border-color: #16a34a; background: #dcfce7;
  }
  .intake-missing-field.saved label { color: #15803d; }

  /* ─── CHIPS DE CHAMPS DÉTECTÉS ─── */
  .intake-chips-card {
    background: #fff;
    border-radius: 18px;
    padding: 24px 28px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
    margin-bottom: 24px;
    display: none;
  }
  .intake-chips-card.visible { display: block; }
  .intake-chips-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }
  .intake-chips-title {
    font-size: 16px;
    font-weight: 700;
    color: #1a1816;
  }
  .intake-chips-count {
    padding: 6px 14px;
    background: linear-gradient(135deg, #1f6f7a, #36577d);
    color: #fff;
    border-radius: 99px;
    font-size: 12px;
    font-weight: 700;
  }
  .intake-chips-section {
    margin-top: 18px;
  }
  .intake-chips-section:first-child { margin-top: 0; }
  .intake-section-label {
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #888;
    margin-bottom: 10px;
  }
  .intake-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .intake-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #f5f1ee;
    border: 1px solid #e8e6e1;
    border-radius: 10px;
    font-size: 12px;
    color: #1a1816;
    transition: all .2s;
    animation: chipPop .35s ease;
  }
  @keyframes chipPop {
    from { transform: scale(.85); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
  }
  .intake-chip:hover {
    background: #fff;
    border-color: #1f6f7a;
    transform: translateY(-1px);
  }
  .intake-chip strong { color: #1f6f7a; }
  .intake-chip.alert {
    background: #fef3c7;
    border-color: #f59e0b;
    color: #92400e;
  }
  .intake-chip.alert strong { color: #92400e; }

  /* ─── BOUTON FINAL ─── */
  .intake-cta {
    display: flex;
    justify-content: center;
    margin-top: 32px;
  }
  .intake-cta-btn {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    padding: 18px 38px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    border: none;
    border-radius: 14px;
    font-size: 16px;
    font-weight: 800;
    font-family: inherit;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(249,115,22,0.35);
    transition: all .2s;
    text-decoration: none;
  }
  .intake-cta-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(249,115,22,0.45); }
  .intake-cta-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
  .intake-cta-skip {
    margin-top: 14px;
    text-align: center;
  }
  .intake-cta-skip a {
    font-size: 12px;
    color: #888;
    text-decoration: underline;
  }

  /* ─── PHOTOS DROP ZONE ─── */
  .intake-photos-zone {
    border: 2px dashed #d4d0ca;
    background: linear-gradient(180deg, #fff 0%, #fdf6f0 100%);
    border-radius: 18px;
    padding: 32px 24px;
    text-align: center;
    margin-bottom: 24px;
    cursor: pointer;
    transition: all .2s ease;
  }
  .intake-photos-zone:hover, .intake-photos-zone.over {
    border-color: #ea580c;
    background: linear-gradient(180deg, #fff7ed 0%, #fdf6f0 100%);
  }
  .intake-photos-zone .icon { font-size: 38px; line-height: 1; margin-bottom: 10px; display: block; }
  .intake-photos-zone .title { font-size: 15px; font-weight: 700; color: #1a1816; margin-bottom: 4px; }
  .intake-photos-zone .sub   { font-size: 12px; color: #888; }
  .intake-photos-grid {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 14px;
    text-align: left;
  }
  .intake-photo-row {
    display: flex;
    align-items: stretch;
    gap: 10px;
    background: #fff;
    border: 1px solid #e8e6e1;
    border-radius: 10px;
    padding: 6px;
    overflow: hidden;
  }
  .intake-photo-thumb {
    position: relative;
    width: 90px;
    height: 70px;
    flex-shrink: 0;
    background: #f3f0ea;
    border-radius: 6px;
    overflow: hidden;
  }
  .intake-photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .intake-photo-thumb .badge {
    position: absolute; top: 4px; left: 4px;
    background: rgba(0,0,0,.65); color: #fff;
    font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 99px;
  }
  .intake-photo-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 2px 4px;
  }
  .intake-photo-cat {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #1f6f7a;
    margin-bottom: 2px;
  }
  .intake-photo-desc {
    font-size: 10.5px;
    line-height: 1.35;
    color: #555;
  }
  .intake-photo-loading {
    font-size: 10px;
    color: #aaa;
    font-style: italic;
  }

  /* ─── INFO DPE PANEL ─── */
  .intake-info-dpe {
    background: linear-gradient(180deg, #f0f9fa 0%, #fff 100%);
    border: 1px solid #b6dde2;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 24px;
  }
  .intake-info-dpe h3 {
    margin: 0 0 10px;
    font-size: 14px;
    color: #1f6f7a;
    font-weight: 800;
    display: flex; align-items: center; gap: 8px;
  }
  .intake-info-dpe ul {
    margin: 6px 0 0 18px;
    padding: 0;
    font-size: 12px;
    color: #2a3a40;
    line-height: 1.7;
  }
  .intake-info-dpe ul li strong { color: #1a1816; }
  .intake-dpe-alert {
    margin-top: 12px;
    padding: 10px 14px;
    background: #fee2e2;
    border-left: 3px solid #dc2626;
    border-radius: 8px;
    color: #7f1d1d;
    font-size: 12px;
    font-weight: 600;
    display: none;
  }
  .intake-dpe-alert.visible { display: block; }
  .intake-dpe-alert.warn   { background: #fef3c7; border-left-color: #d97706; color: #78350f; }
</style>

<main class="mbi-main">
  <header class="topbar">
    <div class="topbar-crumb">
      <a href="<?= h(app_url('/bien_liste.php')) ?>" style="color:#888;text-decoration:none;">← Retour à la liste</a>
    </div>
  </header>

  <div class="intake-wrap">
    <div class="intake-hero">
      <span class="intake-label">📥 Création par import intelligent</span>
      <h1>Glissez vos documents, l'IA fait le reste.</h1>
      <p>
        Téléchargez un mandat de gestion, un DPE, un dossier de diagnostics, une fiche commerciale,
        une attestation de surface… L'IA extrait automatiquement toutes les données et pré-remplit
        votre fiche bien. Vous validerez ensuite dans le formulaire complet.
      </p>
    </div>

    <!-- ── DROP ZONE ── -->
    <div class="intake-drop" id="intake-drop">
      <span class="intake-drop-icon">📥</span>
      <div class="intake-drop-title">Glissez vos PDF ici, ou cliquez pour les sélectionner</div>
      <div class="intake-drop-sub">PDF uniquement — max 20 Mo par fichier — extraction automatique par GPT-4o</div>
      <div class="intake-drop-types">
        <span>📋 Mandat</span>
        <span>⚡ DPE</span>
        <span>🔬 Diagnostics</span>
        <span>📐 Loi Boutin / Carrez</span>
        <span>📃 Fiche commerciale</span>
        <span>🌍 État des risques</span>
      </div>
      <input type="file" id="intake-input" accept="application/pdf,.pdf" multiple style="display:none">
    </div>

    <!-- ── LISTE FICHIERS UPLOADÉS ── -->
    <div class="intake-files" id="intake-files"></div>

    <!-- ── ZONE PHOTOS ── -->
    <div class="intake-photos-zone" id="intake-photos-zone">
      <span class="icon">📸</span>
      <div class="title">Glissez vos photos du bien ici</div>
      <div class="sub">JPG / PNG / WebP — multi-sélection — au moins 1 photo obligatoire pour la diffusion</div>
      <div class="intake-photos-grid" id="intake-photos-grid"></div>
      <input type="file" id="intake-photos-input" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple style="display:none">
    </div>

    <!-- ── PANNEAU INFOS DPE ── -->
    <div class="intake-info-dpe" id="intake-info-dpe">
      <h3>ℹ️ Obligations DPE & validité des diagnostics</h3>
      <ul>
        <li><strong>DPE 2021</strong> (réalisé après le 01/07/2021) : valide <strong>10 ans</strong>.</li>
        <li><strong>DPE 2011-2017</strong> : valide <strong>jusqu'au 31/12/2022</strong> (expirés).</li>
        <li><strong>DPE 2018-juin 2021</strong> : valide <strong>jusqu'au 31/12/2024</strong> (expirés).</li>
        <li><strong>DPE vierge</strong> : autorisé uniquement pour les biens sans historique de consommation (chauffage collectif sans compteur individuel, etc.).</li>
        <li><strong>Amiante / Plomb (CREP)</strong> : validité illimitée si négatif, 1 an si positif (vente) / 6 ans (location).</li>
        <li><strong>Gaz / Électricité</strong> : 6 ans (location 6 ans, vente 3 ans).</li>
        <li><strong>Termites</strong> : 6 mois.</li>
        <li><strong>État des risques (ERP)</strong> : 6 mois.</li>
        <li><strong>Mesurage Carrez / Boutin</strong> : illimité tant qu'aucun travaux.</li>
      </ul>
      <div class="intake-dpe-alert" id="intake-dpe-alert"></div>
    </div>

    <!-- ── CARTES CANDIDATS PROPRIÉTAIRE ── -->
    <div class="intake-candidates" id="intake-proprio-card" style="display:none;">
      <div class="intake-candidates-head">
        <div>
          <div class="intake-candidates-title">👤 Propriétaire détecté</div>
          <div class="intake-candidates-sub">Sélectionnez un propriétaire existant ou créez-en un nouveau</div>
        </div>
      </div>
      <div class="intake-candidates-list" id="intake-proprio-list"></div>
    </div>

    <!-- ── CARTES CANDIDATS IMMEUBLE ── -->
    <div class="intake-candidates" id="intake-immeuble-card" style="display:none;border-left-color:#36577d;">
      <div class="intake-candidates-head">
        <div>
          <div class="intake-candidates-title">🏢 Immeuble détecté</div>
          <div class="intake-candidates-sub">Sélectionnez un immeuble existant à cette adresse, ou créez-en un nouveau</div>
        </div>
      </div>
      <div class="intake-candidates-list" id="intake-immeuble-list"></div>
    </div>

    <!-- ── CHAMPS OBLIGATOIRES MANQUANTS ── -->
    <div class="intake-missing" id="intake-missing-card">
      <div class="intake-missing-head">
        <div>
          <div class="intake-missing-title">⚠️ Champs obligatoires à compléter</div>
          <div style="font-size:11px;color:#888;margin-top:2px;">Remplissez ces champs pour rendre le bien diffusable. Saisie sauvegardée automatiquement.</div>
        </div>
        <div class="intake-missing-count" id="intake-missing-count">0</div>
      </div>
      <div class="intake-missing-grid" id="intake-missing-grid"></div>
    </div>

    <!-- ── CHIPS DES CHAMPS DÉTECTÉS ── -->
    <div class="intake-chips-card" id="intake-chips-card">
      <div class="intake-chips-head">
        <div class="intake-chips-title">🎯 Champs détectés automatiquement</div>
        <div class="intake-chips-count" id="intake-chips-count">0 champ</div>
      </div>
      <div id="intake-chips-body"></div>
    </div>

    <!-- ── BOUTON FINAL ── -->
    <div class="intake-cta">
      <button type="button" id="intake-continue" class="intake-cta-btn" disabled>
        ✅ Continuer la création du bien →
      </button>
    </div>
    <div class="intake-cta-skip">
      <a href="#" id="intake-skip">Passer cette étape et créer un bien vide</a>
    </div>
  </div>
</main>

<script>
(function () {
  const dropZone = document.getElementById('intake-drop');
  const fileInput = document.getElementById('intake-input');
  const filesList = document.getElementById('intake-files');
  const chipsCard = document.getElementById('intake-chips-card');
  const chipsBody = document.getElementById('intake-chips-body');
  const chipsCount = document.getElementById('intake-chips-count');
  const continueBtn = document.getElementById('intake-continue');
  const skipBtn = document.getElementById('intake-skip');

  const csrfToken = '<?= h(csrf_token('ajouter_bien')) ?>';
  let currentBienId = 0;
  const aggregatedFields = {}; // tous les champs détectés cumulés
  const aggregatedAlerts = new Set();
  const docTypeIcons = {
    dpe: '⚡', dossier_diagnostics: '🔬',
    mandat_gestion: '📋', mandat_vente: '📋', mandat_location: '📋',
    fiche_commerciale: '📃', titre_propriete: '📜',
    mesurage_boutin: '📐', mesurage_carrez: '📐',
    etat_risques: '🌍', attestation: '📄', autre: '📄',
  };

  // Sections logiques pour grouper les chips
  const SECTIONS = {
    '🏠 Identification': ['type_bien','designation','reference_bien','annee_construction','etage','lot_principal'],
    '📍 Adresse': ['adresse_1','adresse_2','code_postal','ville','pays'],
    '📐 Surfaces': ['surface_habitable','surface_carrez','surface_sejour','surface_terrain','surface_balcon','surface_terrasse','surface_jardin','surface_cave','surface_garage'],
    '🚪 Pièces': ['nb_pieces','nb_chambres','nb_salles_bain','nb_salles_eau','nb_wc'],
    '⚡ DPE & énergie': ['dpe_classe','ges_classe','dpe_valeur','ges_valeur','dpe_date_realisation','dpe_version','dpe_vierge','dpe_reference_certificat','montant_estime_depenses_min','montant_estime_depenses_max','chauffage_type','chauffage_energie','eau_chaude_type','double_vitrage','volets_roulants','menuiseries'],
    '👤 Propriétaire (identité)': ['proprio_nom','proprio_prenom','proprio_civilite','proprio_societe','proprio_type_personne','proprio_email','proprio_telephone'],
    '🏠 Adresse propriétaire': ['proprio_adresse_1','proprio_code_postal','proprio_ville'],
    '📋 Mandat': ['numero_mandat','type_mandat','nature_mandat','date_signature','date_debut','date_fin','honoraires','honoraires_charge'],
    '💰 Prix': ['prix_vente','loyer_hc','charges_locatives','depot_garantie'],
  };

  const LABELS = {
    type_bien: 'Type', designation: 'Désignation', reference_bien: 'Référence',
    annee_construction: 'Année', etage: 'Étage', lot_principal: 'Lot',
    adresse_1: 'Adresse', code_postal: 'CP', ville: 'Ville', pays: 'Pays',
    surface_habitable: 'Surface hab.', surface_carrez: 'Carrez', surface_sejour: 'Séjour',
    surface_terrain: 'Terrain', surface_balcon: 'Balcon', surface_terrasse: 'Terrasse',
    surface_jardin: 'Jardin', surface_cave: 'Cave', surface_garage: 'Garage',
    nb_pieces: 'Pièces', nb_chambres: 'Chambres', nb_salles_bain: 'SdB', nb_salles_eau: 'SdE', nb_wc: 'WC',
    dpe_classe: 'DPE', ges_classe: 'GES', dpe_valeur: 'kWh/m²/an', ges_valeur: 'CO₂/m²/an',
    dpe_date_realisation: 'Date DPE', dpe_version: 'Version', dpe_vierge: 'DPE vierge',
    dpe_reference_certificat: 'N° ADEME',
    montant_estime_depenses_min: 'Dépense min', montant_estime_depenses_max: 'Dépense max',
    chauffage_type: 'Chauffage', chauffage_energie: 'Énergie', eau_chaude_type: 'ECS',
    double_vitrage: 'Double vitrage', volets_roulants: 'Volets roulants', menuiseries: 'Menuiseries',
    nom: 'Nom', prenom: 'Prénom', civilite: 'Civilité', societe: 'Société', type_personne: 'Type',
    proprio_nom: 'Nom', proprio_prenom: 'Prénom', proprio_civilite: 'Civilité',
    proprio_societe: 'Société', proprio_type_personne: 'Type',
    proprio_email: 'Email', proprio_telephone: 'Téléphone',
    proprio_adresse_1: 'Adresse', proprio_code_postal: 'CP', proprio_ville: 'Ville',
    numero_mandat: 'N° mandat', type_mandat: 'Type', nature_mandat: 'Nature',
    date_signature: 'Signé le', date_debut: 'Début', date_fin: 'Fin',
    honoraires: 'Honoraires', honoraires_charge: 'Charge',
    prix_vente: 'Prix vente', loyer_hc: 'Loyer HC', charges_locatives: 'Charges', depot_garantie: 'Dépôt',
  };

  // ── PHOTOS : drag & drop + multi upload ──
  const photosZone = document.getElementById('intake-photos-zone');
  const photosInput = document.getElementById('intake-photos-input');
  const photosGrid = document.getElementById('intake-photos-grid');
  let photosCount = 0;

  ['dragenter', 'dragover'].forEach(e => photosZone.addEventListener(e, ev => {
    ev.preventDefault(); photosZone.classList.add('over');
  }));
  ['dragleave', 'drop'].forEach(e => photosZone.addEventListener(e, ev => {
    ev.preventDefault(); photosZone.classList.remove('over');
  }));
  photosZone.addEventListener('click', (ev) => {
    if (ev.target.closest('.intake-photo-thumb')) return;
    photosInput.click();
  });
  photosZone.addEventListener('drop', ev => {
    ev.preventDefault();
    handlePhotos(ev.dataTransfer.files);
  });
  photosInput.addEventListener('change', () => handlePhotos(photosInput.files));

  function handlePhotos(fileListObj) {
    const files = Array.from(fileListObj || []);
    files.forEach(file => {
      if (!/\.(jpe?g|png|webp)$/i.test(file.name)) return;
      if (file.size > 15 * 1024 * 1024) return;
      uploadPhoto(file);
    });
  }

  // Cumule les descriptions IA pour amorcer le descriptif global du bien
  const photoDescriptions = [];

  async function uploadPhoto(file) {
    // Row placeholder (thumb + info)
    const row = document.createElement('div');
    row.className = 'intake-photo-row';
    row.innerHTML = `
      <div class="intake-photo-thumb">
        <div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:11px;color:#888;">⏳</div>
      </div>
      <div class="intake-photo-info">
        <div class="intake-photo-loading">📤 Envoi + analyse IA en cours…</div>
      </div>
    `;
    photosGrid.appendChild(row);

    const fd = new FormData();
    fd.append('fichier', file);
    if (currentBienId > 0) fd.append('id_bien', String(currentBienId));
    fd.append('csrf_token', csrfToken);

    try {
      const resp = await fetch('api/bien_intake_photo_upload.php', { method: 'POST', body: fd });
      const data = await resp.json();
      if (!data.ok) {
        row.querySelector('.intake-photo-info').innerHTML =
          `<div style="font-size:10px;color:#c00;">${escapeHtml(data.error || 'Erreur')}</div>`;
        return;
      }
      if (data.bien_id && !currentBienId) currentBienId = data.bien_id;
      photosCount++;
      const cat = data.categorie ? data.categorie.replace(/_/g, ' ') : '';
      const desc = data.description || '';
      if (desc) photoDescriptions.push((cat ? '[' + cat + '] ' : '') + desc);

      row.querySelector('.intake-photo-thumb').innerHTML =
        `<img src="${escapeHtml(data.url)}" alt=""><span class="badge">${photosCount}</span>`;
      row.querySelector('.intake-photo-info').innerHTML = `
        ${cat ? `<div class="intake-photo-cat">📸 ${escapeHtml(cat)}</div>` : ''}
        <div class="intake-photo-desc">${desc ? escapeHtml(desc) : '<em style="color:#aaa;">Description non générée</em>'}</div>
      `;
      refreshState();
    } catch (err) {
      row.querySelector('.intake-photo-info').innerHTML =
        `<div style="font-size:10px;color:#c00;">${escapeHtml(err.message)}</div>`;
    }
  }

  // ── Vérification validité DPE / diagnostics ──
  function checkDpeValidity() {
    const alertEl = document.getElementById('intake-dpe-alert');
    if (!alertEl) return;
    const messages = [];
    const dateStr = aggregatedFields.dpe_date_realisation;
    const version = aggregatedFields.dpe_version;
    if (dateStr && /^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
      const dpeDate = new Date(dateStr);
      const today = new Date();
      const ageYears = (today - dpeDate) / (1000 * 60 * 60 * 24 * 365.25);
      // Règles d'expiration
      if (dpeDate < new Date('2018-01-01')) {
        messages.push(`⚠️ DPE du ${dateStr} : expiré depuis le 31/12/2022 (DPE 2011-2017). À refaire impérativement.`);
      } else if (dpeDate < new Date('2021-07-01')) {
        messages.push(`⚠️ DPE du ${dateStr} : expiré depuis le 31/12/2024 (DPE 2018 — juin 2021). À refaire impérativement.`);
      } else if (ageYears > 10) {
        messages.push(`⚠️ DPE du ${dateStr} : expiré (DPE 2021, validité 10 ans). À refaire.`);
      } else if (ageYears > 9) {
        messages.push(`⏰ DPE du ${dateStr} : expire dans moins d'un an. Penser à le renouveler.`);
      }
    }
    if (messages.length === 0) {
      alertEl.classList.remove('visible');
      alertEl.innerHTML = '';
      return;
    }
    alertEl.innerHTML = messages.join('<br>');
    alertEl.classList.add('visible');
    alertEl.classList.toggle('warn', messages.every(m => m.startsWith('⏰')));
  }

  // ── Drag & drop ──
  ['dragenter', 'dragover'].forEach(e => dropZone.addEventListener(e, ev => {
    ev.preventDefault(); dropZone.classList.add('over');
  }));
  ['dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, ev => {
    ev.preventDefault(); dropZone.classList.remove('over');
  }));
  dropZone.addEventListener('drop', ev => {
    ev.preventDefault();
    handleFiles(ev.dataTransfer.files);
  });
  dropZone.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => handleFiles(fileInput.files));

  function handleFiles(fileListObj) {
    const files = Array.from(fileListObj || []);
    files.forEach(file => {
      if (!/\.pdf$/i.test(file.name)) return;
      if (file.size > 20 * 1024 * 1024) {
        addFileRow(file, 'error', 'Trop volumineux (>20 Mo)');
        return;
      }
      uploadFile(file);
    });
  }

  function addFileRow(file, statut, message) {
    const row = document.createElement('div');
    row.className = 'intake-file ' + statut;
    row.innerHTML = `
      <div class="intake-file-icon">📄</div>
      <div class="intake-file-info">
        <div class="intake-file-name">${escapeHtml(file.name)}</div>
        <div class="intake-file-meta">${(file.size/1024).toFixed(0)} Ko${message ? ' • ' + escapeHtml(message) : ''}</div>
      </div>
      <div class="intake-file-actions" style="display:none;"></div>
      <div class="intake-file-status">${statusLabel(statut)}</div>
    `;
    filesList.appendChild(row);
    return row;
  }

  function addFileActions(row, fileUrl, diagId) {
    const actions = row.querySelector('.intake-file-actions');
    if (!actions) return;
    actions.innerHTML = `
      <a href="${escapeHtml(fileUrl)}" target="_blank" class="intake-file-btn" title="Ouvrir le PDF">👁 Voir</a>
      <button type="button" class="intake-file-btn danger" data-diag-id="${diagId}" title="Supprimer">🗑</button>
    `;
    actions.style.display = 'flex';
    const delBtn = actions.querySelector('.intake-file-btn.danger');
    delBtn.addEventListener('click', () => deleteDiag(diagId, row));
  }

  async function deleteDiag(diagId, row) {
    if (!confirm('Supprimer ce document ? Les champs auto-remplis seront conservés mais vous pouvez les modifier.')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'delete_diag');
    fd.append('bien_id', String(currentBienId));
    fd.append('diag_id', String(diagId));
    try {
      const resp = await fetch('api/bien_intake_action.php', { method: 'POST', body: fd });
      const data = await resp.json();
      if (data.ok) {
        row.remove();
        refreshState();
      } else {
        alert('Suppression échouée : ' + (data.error || 'inconnue'));
      }
    } catch (err) {
      alert('Erreur réseau : ' + err.message);
    }
  }

  function statusLabel(s) {
    if (s === 'analysing') return '<span class="intake-spinner"></span>Analyse IA…';
    if (s === 'ocr')       return '<span class="intake-spinner"></span>OCR Vision…';
    if (s === 'success')   return '✓ Analysé';
    if (s === 'error')     return '✗ Erreur';
    return s;
  }

  function updateRow(row, statut, message, badge) {
    row.className = 'intake-file ' + statut;
    const meta = row.querySelector('.intake-file-meta');
    const status = row.querySelector('.intake-file-status');
    const fileName = row.querySelector('.intake-file-name');
    if (badge) {
      const exists = fileName.querySelector('.intake-doc-badge');
      if (!exists) {
        const b = document.createElement('span');
        b.className = 'intake-doc-badge';
        b.style.cssText = 'display:inline-block;margin-left:8px;padding:2px 8px;background:#e0e7ff;color:#4338ca;border-radius:99px;font-size:10px;font-weight:700;';
        b.textContent = badge;
        fileName.appendChild(b);
      }
    }
    if (message) meta.textContent = meta.textContent.split(' • ')[0] + ' • ' + message;
    status.innerHTML = statusLabel(statut);
  }

  async function uploadFile(file) {
    const row = addFileRow(file, 'analysing');
    const fd = new FormData();
    fd.append('fichier', file);
    if (currentBienId > 0) fd.append('id_bien', String(currentBienId));
    fd.append('csrf_token', csrfToken);

    try {
      const resp = await fetch('api/bien_intake_upload.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-Token': csrfToken },
      });
      const data = await resp.json();
      if (!data.ok) {
        updateRow(row, 'error', data.error || 'Erreur');
        return;
      }
      // Récupère / mémorise l'id du bien (créé au 1er upload)
      if (data.bien_id && !currentBienId) currentBienId = data.bien_id;

      const docIcon = docTypeIcons[data.doc_type] || '📄';
      const docLabel = (data.doc_type || 'document').replace(/_/g, ' ');
      const ocrBadge = data.used_ocr ? ' 👁 OCR' : '';
      updateRow(row, 'success', `${data.count} champ(s) extraits${ocrBadge}`, `${docIcon} ${docLabel}`);
      // Boutons voir / supprimer
      addFileActions(row, data.fichier, data.diag_id);

      // Affiche les candidats propriétaires (même si liste vide tant qu'on a des données extraites)
      if ((data.proprietaires_candidats && data.proprietaires_candidats.length > 0) || data.proprietaire_suggested) {
        renderProprioCandidates(
          data.proprietaires_candidats || [],
          data.proprietaire_auto_link,
          data.proprietaire_suggested
        );
      }
      if ((data.immeubles_candidats && data.immeubles_candidats.length > 0) || data.immeuble_suggested) {
        renderImmeubleCandidates(
          data.immeubles_candidats || [],
          data.immeuble_auto_link,
          data.immeuble_suggested
        );
      }

      // Aggrégation des champs
      Object.entries(data.fields || {}).forEach(([k, v]) => {
        if (!(k in aggregatedFields) || aggregatedFields[k] === null || aggregatedFields[k] === '') {
          aggregatedFields[k] = v;
        }
      });
      // Détection alertes
      if (data.fields) {
        if (data.fields.plomb_present) aggregatedAlerts.add('🔴 Plomb détecté (CREP)');
        if (data.fields.amiante_present) aggregatedAlerts.add('🟠 Amiante repéré');
        if (data.fields.electricite_anomalies) aggregatedAlerts.add('🟡 Anomalies électriques');
        if (data.fields.gaz_anomalies) aggregatedAlerts.add('🔵 Anomalies gaz');
        if (data.fields.termites) aggregatedAlerts.add('🟤 Termites');
        if (data.fields.zone_georisque) aggregatedAlerts.add('🌊 Zone géorisque');
      }
      renderChips();
      checkDpeValidity();
      // Refresh état (champs manquants, statut, etc.)
      refreshState();
      continueBtn.disabled = false;
    } catch (err) {
      updateRow(row, 'error', err.message);
    }
  }

  // ─── Cartes candidats propriétaire ───
  function renderProprioCandidates(candidates, autoLinkId, extractedData) {
    const card = document.getElementById('intake-proprio-card');
    const list = document.getElementById('intake-proprio-list');
    if (!card || !list) return;
    card.style.display = 'block';
    let html = '';

    // Aperçu des données extraites (toujours visible)
    if (extractedData && (extractedData.nom || extractedData.societe)) {
      const label = extractedData.societe
        ? extractedData.societe + (extractedData.nom ? ' (' + (extractedData.prenom || '') + ' ' + extractedData.nom + ')' : '')
        : (extractedData.civilite || '') + ' ' + (extractedData.prenom || '') + ' ' + (extractedData.nom || '');
      const proprioAdr = [
        extractedData.adresse_1,
        [extractedData.code_postal, extractedData.ville].filter(Boolean).join(' '),
      ].filter(Boolean).join(' — ');
      html += `
        <div style="background:#fff7e6;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;margin-bottom:10px;">
          <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:6px;">📋 Données extraites du document</div>
          <div style="font-size:13px;color:#1a1816;font-weight:600;">${escapeHtml(label.trim())}</div>
          ${proprioAdr ? `<div style="font-size:12px;color:#555;margin-top:4px;">📍 ${escapeHtml(proprioAdr)}</div>` : ''}
          <div style="font-size:11px;color:#888;margin-top:3px;">
            ${extractedData.email ? '✉️ ' + escapeHtml(extractedData.email) : ''}
            ${extractedData.telephone ? ' • 📞 ' + escapeHtml(extractedData.telephone) : ''}
          </div>
        </div>
      `;
    }

    // Liste des candidats existants
    if (candidates.length > 0) {
      html += `<div style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.5px;margin:14px 0 6px;">Propriétaires similaires en base (${candidates.length}) — vérifiez l'adresse pour confirmer</div>`;
      candidates.forEach(c => {
        const isLinked = (c.id == autoLinkId);
        const scoreClass = c.score >= 80 ? 'high' : '';
        // Adresse complète
        const adrParts = [];
        if (c.adresse_1) adrParts.push(escapeHtml(c.adresse_1));
        const cpVille = [c.code_postal, c.ville].filter(Boolean).map(escapeHtml).join(' ');
        if (cpVille) adrParts.push(cpVille);
        const adresseHtml = adrParts.length
          ? `<div class="intake-candidate-meta" style="margin-top:4px;">📍 ${adrParts.join(' — ')}</div>`
          : '<div class="intake-candidate-meta" style="margin-top:4px;color:#c08;">📍 (pas d\'adresse en base)</div>';
        // Contacts
        const contacts = [];
        if (c.email)     contacts.push('✉️ ' + escapeHtml(c.email));
        if (c.telephone) contacts.push('📞 ' + escapeHtml(c.telephone));
        const contactsHtml = contacts.length
          ? `<div class="intake-candidate-meta" style="margin-top:2px;">${contacts.join(' • ')}</div>`
          : '';
        html += `
          <div class="intake-candidate-card ${isLinked ? 'linked' : ''}" data-id="${c.id}" data-kind="proprietaire">
            <div class="intake-candidate-info">
              <div class="intake-candidate-name">${escapeHtml(c._label || c.nom || c.societe || '?')}</div>
              ${adresseHtml}
              ${contactsHtml}
            </div>
            <span class="intake-candidate-score ${scoreClass}">${isLinked ? '✓ Lié' : c.score + '%'}</span>
          </div>
        `;
      });
    } else {
      html += `<div style="padding:10px;font-size:12px;color:#888;font-style:italic;">Aucun propriétaire similaire trouvé en base.</div>`;
    }

    // Bouton créer (mis en avant si pas de match fort)
    const noMatchHigh = candidates.length === 0 || (candidates[0]?.score || 0) < 80;
    if (noMatchHigh) {
      html += `
        <button type="button" class="intake-create-new" data-kind="proprietaire" style="margin-top:12px;width:100%;background:#dcfce7;border-color:#16a34a;color:#15803d;font-weight:700;padding:14px;font-size:13px;">
          ✨ Créer ce propriétaire avec les données extraites
        </button>
      `;
    } else {
      html += `<button type="button" class="intake-create-new" data-kind="proprietaire" style="margin-top:8px;font-size:11px;">＋ Créer quand même un nouveau (les candidats ne correspondent pas)</button>`;
    }

    list.innerHTML = html;
    list.querySelectorAll('.intake-candidate-card').forEach(el => {
      el.addEventListener('click', () => {
        // Toggle : si déjà lié → on délie ; sinon on lie
        if (el.classList.contains('linked')) {
          linkEntity('proprietaire', 'unlink', null, null, null);
        } else {
          linkEntity('proprietaire', 'select', el.dataset.id, null, null);
        }
      });
    });
    list.querySelectorAll('.intake-create-new').forEach(el => {
      el.addEventListener('click', () => linkEntity('proprietaire', 'create', null, extractedData, null));
    });
  }

  // ─── Cartes candidats immeuble ───
  function renderImmeubleCandidates(candidates, autoLinkId, extractedData) {
    const card = document.getElementById('intake-immeuble-card');
    const list = document.getElementById('intake-immeuble-list');
    if (!card || !list) return;
    card.style.display = 'block';
    let html = '';

    // Aperçu de l'adresse extraite (toujours visible)
    if (extractedData && (extractedData.adresse_1 || extractedData.code_postal || extractedData.ville)) {
      html += `
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;margin-bottom:10px;">
          <div style="font-size:11px;font-weight:700;color:#1e40af;margin-bottom:6px;">📋 Adresse du bien extraite du document</div>
          <div style="font-size:13px;color:#1a1816;font-weight:600;">
            ${escapeHtml(extractedData.adresse_1 || '(adresse manquante)')}
            ${extractedData.adresse_2 ? '<br>' + escapeHtml(extractedData.adresse_2) : ''}
          </div>
          <div style="font-size:12px;color:#555;margin-top:3px;">
            ${escapeHtml((extractedData.code_postal || '') + ' ' + (extractedData.ville || ''))}
          </div>
        </div>
      `;
    }

    // Liste des candidats existants
    if (candidates.length > 0) {
      html += `<div style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.5px;margin:14px 0 6px;">Immeubles trouvés en base (${candidates.length})</div>`;
      candidates.forEach(c => {
        const isLinked = (c.id == autoLinkId && c.source === 'immeubles');
        const scoreClass = c.score >= 80 ? 'high' : '';
        const sourceBadge = c.source === 'reg'
          ? '<span style="display:inline-block;padding:2px 8px;background:#e0e7ff;color:#4338ca;border-radius:99px;font-size:9px;font-weight:700;margin-left:6px;">📚 REGISTRE NATIONAL</span>'
          : '';
        const meta = [];
        if (c.nb_biens > 0) meta.push('🏠 ' + c.nb_biens + ' bien(s)');
        if (c.nb_lots > 0)  meta.push('📋 ' + c.nb_lots + ' lots');
        if (c.immatriculation) meta.push('🆔 ' + escapeHtml(String(c.immatriculation).substr(0, 25)));
        if (c.code_postal) meta.push('📍 ' + escapeHtml(c.code_postal));
        html += `
          <div class="intake-candidate-card ${isLinked ? 'linked' : ''}" data-id="${c.id}" data-source="${c.source || 'immeubles'}" data-kind="immeuble">
            <div class="intake-candidate-info">
              <div class="intake-candidate-name">${escapeHtml(c._label || c.adresse_1 || '?')} ${sourceBadge}</div>
              <div class="intake-candidate-meta">${meta.join(' • ')}</div>
            </div>
            <span class="intake-candidate-score ${scoreClass}">${isLinked ? '✓ Lié' : c.score + '%'}</span>
          </div>
        `;
      });
    } else {
      html += `<div style="padding:10px;font-size:12px;color:#888;font-style:italic;">Aucun immeuble similaire trouvé en base.</div>`;
    }

    // Bouton créer (mis en avant si pas de match fort)
    const noMatchHigh = candidates.length === 0 || (candidates[0]?.score || 0) < 80;
    if (noMatchHigh) {
      html += `
        <button type="button" class="intake-create-new" data-kind="immeuble" style="margin-top:12px;width:100%;background:#dcfce7;border-color:#16a34a;color:#15803d;font-weight:700;padding:14px;font-size:13px;">
          ✨ Créer ce nouvel immeuble avec l'adresse extraite
        </button>
      `;
    } else {
      html += `<button type="button" class="intake-create-new" data-kind="immeuble" style="margin-top:8px;font-size:11px;">＋ Créer quand même un nouveau (les candidats ne correspondent pas)</button>`;
    }

    list.innerHTML = html;
    list.querySelectorAll('.intake-candidate-card').forEach(el => {
      el.addEventListener('click', () => {
        if (el.classList.contains('linked')) {
          linkEntity('immeuble', 'unlink', null, null, null);
        } else {
          linkEntity('immeuble', 'select', el.dataset.id, null, el.dataset.source);
        }
      });
    });
    list.querySelectorAll('.intake-create-new').forEach(el => {
      el.addEventListener('click', () => linkEntity('immeuble', 'create', null, extractedData, 'immeubles'));
    });
  }

  async function linkEntity(kind, action, targetId, data, source) {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('bien_id', String(currentBienId));
    fd.append('kind', kind);
    fd.append('action', action);
    if (targetId) fd.append('target_id', targetId);
    if (source)   fd.append('source', source);
    if (data) fd.append('data', JSON.stringify(data));
    try {
      const resp = await fetch('api/bien_intake_link.php', { method: 'POST', body: fd });
      const r = await resp.json();
      if (!r.ok) { alert('Liaison échouée : ' + (r.error || 'inconnue')); return; }
      const listId = kind === 'proprietaire' ? 'intake-proprio-list' : 'intake-immeuble-list';
      const list = document.getElementById(listId);
      // On retire systématiquement les marqueurs "lié"
      list.querySelectorAll('.intake-candidate-card').forEach(c => {
        c.classList.remove('linked');
        const b = c.querySelector('.intake-candidate-score');
        if (b && b.textContent === '✓ Lié') {
          b.textContent = (c.dataset.score || '—') + (c.dataset.score ? '%' : '');
          b.classList.remove('high');
        }
      });
      // Si c'était une déliaison, on s'arrête là
      if (r.unlinked) { refreshState(); return; }
      const newLinked = list.querySelector(`[data-id="${r.id}"]`);
      if (newLinked) {
        newLinked.classList.add('linked');
        const badge = newLinked.querySelector('.intake-candidate-score');
        if (badge) { badge.textContent = '✓ Lié'; badge.classList.add('high'); }
      } else {
        // Nouveau créé : on rafraîchit
        list.insertAdjacentHTML('afterbegin',
          `<div class="intake-candidate-card linked" data-id="${r.id}" data-kind="${kind}">
            <div class="intake-candidate-info">
              <div class="intake-candidate-name">${escapeHtml(r.label || 'Nouveau')}</div>
              <div class="intake-candidate-meta">✨ Créé à l'instant</div>
            </div>
            <span class="intake-candidate-score high">✓ Lié</span>
          </div>`);
      }
      refreshState();
    } catch (err) {
      alert('Erreur réseau : ' + err.message);
    }
  }

  // ─── Refresh état complet du bien (champs manquants) ───
  let refreshTimer = null;
  function refreshState(immediate) {
    if (!currentBienId) return;
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(async () => {
      try {
        const resp = await fetch('api/bien_intake_action.php?action=state&bien_id=' + currentBienId);
        const data = await resp.json();
        if (data.ok) renderMissing(data);
      } catch (err) { /* silent */ }
    }, immediate ? 0 : 400);
  }

  function renderMissing(state) {
    const card = document.getElementById('intake-missing-card');
    const grid = document.getElementById('intake-missing-grid');
    const countEl = document.getElementById('intake-missing-count');
    if (!card || !grid) return;
    card.classList.add('visible');
    const missing = state.missing || [];
    countEl.textContent = missing.length;
    if (missing.length === 0) {
      card.classList.add('complete');
      const title = card.querySelector('.intake-missing-title');
      if (title) title.textContent = '✅ Tous les champs obligatoires sont remplis !';
      grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:14px;color:#15803d;font-size:13px;">🎉 Le bien est prêt à être diffusé. Cliquez sur "Continuer" pour finaliser.</div>';
      return;
    }
    card.classList.remove('complete');
    let html = '';
    missing.forEach(m => {
      html += `
        <div class="intake-missing-field" data-field="${escapeHtml(m.key)}">
          <label>${escapeHtml(m.label)}</label>
          ${renderFieldInput(m)}
        </div>
      `;
    });
    grid.innerHTML = html;
    // Bind autosave on blur/change
    grid.querySelectorAll('input, select, textarea').forEach(el => {
      el.addEventListener('change', () => saveField(el));
      if (el.tagName === 'TEXTAREA' || el.type === 'text' || el.type === 'url') {
        el.addEventListener('blur', () => saveField(el));
      }
    });
    // Auto-save des valeurs par défaut (ex : type_transaction = location)
    grid.querySelectorAll('select[data-default]').forEach(el => {
      if (el.value === el.dataset.default) saveField(el);
    });
  }

  function renderFieldInput(m) {
    const key = m.key;
    const t = m.input_type || 'text';
    if (t === 'select') {
      if (key === 'dpe_classe' || key === 'ges_classe') {
        return `<select name="${key}"><option value="">—</option>${['A','B','C','D','E','F','G'].map(l => `<option value="${l}">${l}</option>`).join('')}</select>`;
      }
      if (key === 'type_transaction') {
        return `<select name="${key}" data-default="location">
                  <option value="location" selected>📍 Location</option>
                  <option value="vente">💰 Vente</option>
                </select>`;
      }
    }
    if (t === 'date')     return `<input type="date" name="${key}">`;
    if (t === 'number')   return `<input type="number" name="${key}" min="0">`;
    if (t === 'decimal')  return `<input type="number" step="0.01" name="${key}" min="0">`;
    if (t === 'url')      return `<input type="url" name="${key}" placeholder="https://...">`;
    if (t === 'textarea') return `<textarea name="${key}" rows="3" placeholder="Min 100 caractères pour SEO"></textarea>`;
    if (t === 'checkbox') return `<label style="display:flex;align-items:center;gap:6px;font-size:11px;"><input type="checkbox" name="${key}" value="1"> Cocher si applicable</label>`;
    return `<input type="text" name="${key}">`;
  }

  async function saveField(el) {
    const wrap = el.closest('.intake-missing-field');
    const key = wrap?.dataset.field;
    if (!key) return;
    let value = el.value;
    if (el.type === 'checkbox') value = el.checked ? 1 : 0;
    if (value === '' && el.type !== 'checkbox') return;

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'save_field');
    fd.append('bien_id', String(currentBienId));
    fd.append('field', key);
    fd.append('value', value);
    try {
      const resp = await fetch('api/bien_intake_action.php', { method: 'POST', body: fd });
      const r = await resp.json();
      if (r.ok) {
        wrap.classList.add('saved');
        setTimeout(() => refreshState(), 600);
      }
    } catch (err) { /* silent */ }
  }

  function renderChips() {
    const total = Object.keys(aggregatedFields).length;
    if (total === 0) return;
    chipsCard.classList.add('visible');
    chipsCount.textContent = total + ' champ' + (total > 1 ? 's' : '');

    let html = '';
    // Alertes en premier (bandeau)
    if (aggregatedAlerts.size > 0) {
      html += '<div class="intake-chips-section">';
      html += '<div class="intake-section-label">⚠️ Alertes détectées</div>';
      html += '<div class="intake-chips">';
      aggregatedAlerts.forEach(a => {
        html += `<span class="intake-chip alert"><strong>${escapeHtml(a)}</strong></span>`;
      });
      html += '</div></div>';
    }

    // Sections groupées
    Object.entries(SECTIONS).forEach(([sectionName, keys]) => {
      const present = keys.filter(k => k in aggregatedFields && aggregatedFields[k] !== null && aggregatedFields[k] !== '');
      if (present.length === 0) return;
      html += '<div class="intake-chips-section">';
      html += '<div class="intake-section-label">' + escapeHtml(sectionName) + '</div>';
      html += '<div class="intake-chips">';
      present.forEach(k => {
        const label = LABELS[k] || k;
        let val = aggregatedFields[k];
        if (val === 1 || val === '1' || val === true) val = '✓';
        if (val === 0 || val === false) return;
        html += `<span class="intake-chip">${escapeHtml(label)} : <strong>${escapeHtml(String(val))}</strong></span>`;
      });
      html += '</div></div>';
    });
    chipsBody.innerHTML = html;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // ── Bouton Continuer ──
  continueBtn.addEventListener('click', () => {
    if (currentBienId > 0) {
      window.location.href = '<?= h(app_url('/bien_ajouter.php')) ?>?edit=' + currentBienId + '&from=intake';
    }
  });

  // ── Skip / créer vide ──
  skipBtn.addEventListener('click', e => {
    e.preventDefault();
    // Crée un brouillon vide via le pattern existant
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = '<?= h(app_url('/bien_ajouter.php')) ?>';
    f.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="_action" value="new_draft">
    `;
    document.body.appendChild(f);
    f.submit();
  });
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
