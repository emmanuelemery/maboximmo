<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$appLayout         = true;
$pageTitle         = 'Créer un bien — Express';
$robots            = 'noindex, nofollow';
$includeGooglePlaces = true;

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$roleId    = (int)($_SESSION['id_role']    ?? 0);

// ─── Pattern de référence (preview) ─────────────────────────
$refPattern = '{TYPE3}-{VILLE3}-{YY}-{SEQ:04}-{USER3}';
try {
    $stmtR = $pdo->prepare("
        SELECT COALESCE(NULLIF(a.ref_pattern_bien, ''), s.ref_pattern_bien) AS pat
        FROM agences a
        LEFT JOIN societes s ON s.id = a.id_societe
        WHERE a.id = ? LIMIT 1
    ");
    $stmtR->execute([$agenceId]);
    $p = $stmtR->fetchColumn();
    if ($p) $refPattern = (string)$p;
} catch (Throwable) { /* migration pas encore appliquée → garder fallback */ }

// ─── Liste des propriétaires pour le sélecteur live ─────────
// proprietaires a id_agence (pas id_societe)
$proprietairesList = [];
try {
    $sqlP = "SELECT id, type_personne, civilite, nom, prenom, societe, email, telephone, ville
             FROM proprietaires WHERE actif = 1";
    $paramsP = [];
    if ($agenceId > 0 && $roleId !== 7) {
        $sqlP .= " AND (id_agence = ? OR id_agence IS NULL)";
        $paramsP[] = $agenceId;
    }
    $sqlP .= " ORDER BY nom, prenom LIMIT 500";
    $stmtP = $pdo->prepare($sqlP);
    $stmtP->execute($paramsP);
    $proprietairesList = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

// ─── Types de bien (robuste sur schémas différents) ─────────
$typesList = [];
$tryQueries = [
    "SELECT code, libelle FROM types_bien WHERE actif = 1 ORDER BY ordre_affichage, libelle",
    "SELECT code, libelle FROM types_bien WHERE actif = 1 ORDER BY ordre, libelle",
    "SELECT code, libelle FROM types_bien WHERE actif = 1 ORDER BY libelle",
    "SELECT code, libelle FROM types_bien ORDER BY libelle",
];
foreach ($tryQueries as $q) {
    try {
        $stmtTb = $pdo->query($q);
        $rows = $stmtTb->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) { $typesList = $rows; break; }
    } catch (Throwable) { continue; }
}

$csrf = csrf_token('ajouter_bien');

require_once __DIR__ . '/inc/header.php';
?>
<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

<style>
  :root { --sidebar-w: 220px; }
  body.app-layout { margin: 0; }
  .exp-main { margin-left: var(--sidebar-w); min-height: 100vh; background: #f8fafc; }
  @media (max-width: 900px) { .exp-main { margin-left: 0; } }
  .exp-wrap { max-width: 980px; margin: 0 auto; padding: 24px 20px 120px; }
  .exp-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
  .exp-head h1 { margin: 0; font-size: 22px; color: #0f172a; }
  .exp-head .sub { color: #64748b; font-size: 12px; margin-top: 2px; }
  .exp-head a { font-size: 12px; color: #0369a1; }

  .exp-step { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 18px 22px; margin-bottom: 14px;
              transition: opacity .3s, background .3s; position: relative; }
  .exp-step.locked { opacity: .45; pointer-events: none; }
  .exp-step .num { position: absolute; left: -14px; top: 18px; width: 30px; height: 30px; border-radius: 50%;
                   background: #0ea5e9; color: #fff; display: flex; align-items: center; justify-content: center;
                   font-weight: 700; font-size: 13px; box-shadow: 0 2px 6px rgba(14,165,233,.35); }
  .exp-step .num.done { background: #16a34a; }
  .exp-step h2 { font-size: 15px; font-weight: 700; color: #0369a1; margin: 0 0 4px; }
  .exp-step .hint { font-size: 11px; color: #64748b; margin-bottom: 10px; }

  .exp-field { margin-bottom: 10px; }
  .exp-field label { display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .04em; }
  .exp-field input, .exp-field select, .exp-field textarea {
    width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #e5e7eb; font-size: 13px;
    font-family: inherit; box-sizing: border-box; transition: border-color .2s, background .2s;
  }
  .exp-field input:focus, .exp-field select:focus, .exp-field textarea:focus {
    border-color: #0ea5e9; outline: none; box-shadow: 0 0 0 3px rgba(14,165,233,.1);
  }
  /* Colors on fields */
  .exp-field.ia-filled input, .exp-field.ia-filled select, .exp-field.ia-filled textarea {
    border-color: #0ea5e9; background: #f0f9ff;
  }
  .exp-field.required-empty input, .exp-field.required-empty select {
    border-color: #f59e0b; background: #fffbeb;
  }
  .exp-field .badge { display: inline-block; font-size: 9px; padding: 2px 6px; border-radius: 4px; background: #dbeafe; color: #1e40af; margin-left: 6px; font-weight: 700; }
  .exp-field .req { color: #f59e0b; }

  .exp-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }

  .exp-dpe-drop { border: 2px dashed #0ea5e9; border-radius: 14px; padding: 30px; text-align: center; background: #f0f9ff;
                  cursor: pointer; transition: background .2s; }
  .exp-dpe-drop:hover { background: #e0f2fe; }
  .exp-dpe-drop .icon { font-size: 32px; }
  .exp-dpe-drop .title { font-weight: 700; color: #0369a1; margin: 6px 0 2px; }
  .exp-dpe-drop .sub { font-size: 11px; color: #475569; }
  .exp-dpe-status { display: none; padding: 12px 14px; border-radius: 8px; margin-top: 10px; font-size: 12px; }

  .exp-nodpe { text-align: center; margin-top: 8px; font-size: 11px; color: #94a3b8; }
  .exp-nodpe a { color: #64748b; text-decoration: underline; cursor: pointer; }

  .exp-chips { display: flex; flex-wrap: wrap; gap: 8px; }
  .exp-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 999px;
              background: #fff; border: 1px solid #e5e7eb; cursor: pointer; user-select: none;
              font-size: 12px; font-weight: 600; color: #334155; transition: all .15s; }
  .exp-chip:hover { border-color: #0ea5e9; background: #f0f9ff; }
  .exp-chip.active { background: #0ea5e9; color: #fff; border-color: #0ea5e9; }

  .exp-proprio-suggest { position: absolute; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,.08);
                        max-height: 240px; overflow-y: auto; z-index: 100; width: 100%; margin-top: 2px; }
  .exp-proprio-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
  .exp-proprio-item:hover { background: #f1f5f9; }
  .exp-proprio-item .match { font-size: 10px; color: #0369a1; background: #dbeafe; padding: 2px 6px; border-radius: 4px; margin-left: 6px; }

  .exp-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-top: 10px; }
  .exp-photo-thumb { position: relative; width: 100%; aspect-ratio: 4/3; border-radius: 8px; overflow: hidden; background: #f1f5f9; }
  .exp-photo-thumb img { width: 100%; height: 100%; object-fit: cover; }
  .exp-photo-thumb .cat { position: absolute; top: 4px; left: 4px; background: rgba(14,165,233,.9); color: #fff; font-size: 9px; padding: 2px 5px; border-radius: 4px; font-weight: 700; }
  .exp-photo-thumb .status { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,.6); color: #fff; font-size: 9px; padding: 4px; text-align: center; }

  .exp-ia-preview { background: #fff; border: 1px solid #0ea5e9; border-radius: 10px; padding: 14px; margin-top: 10px; }
  .exp-ia-preview label { font-size: 10px; font-weight: 700; color: #0369a1; text-transform: uppercase; letter-spacing: .04em; }
  .exp-ia-preview .generated { font-size: 12px; color: #0f172a; margin: 4px 0 12px; line-height: 1.5; }
  .exp-ia-preview textarea { width: 100%; min-height: 120px; }

  .exp-footer { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #e5e7eb;
                padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; z-index: 50;
                box-shadow: 0 -4px 12px rgba(0,0,0,.04); }
  .exp-conf { display: flex; gap: 10px; align-items: center; font-size: 12px; color: #475569; }
  .exp-conf .score { font-size: 18px; font-weight: 700; color: #16a34a; }
  .exp-conf .score.warn { color: #f59e0b; }
  .exp-conf .score.bad { color: #dc2626; }
  .exp-btn { padding: 10px 22px; border-radius: 8px; background: #0ea5e9; color: #fff; border: none; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .exp-btn.warn { background: #f59e0b; }
  .exp-btn:disabled { background: #cbd5e1; cursor: not-allowed; }
  .exp-btn-ghost { padding: 10px 18px; border-radius: 8px; background: #fff; color: #475569; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; }

  .exp-btn-sm { padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit; }

  .exp-modal-back { position: fixed; inset: 0; background: rgba(15,23,42,.5); z-index: 200; display: flex; align-items: center; justify-content: center; }
  .exp-modal { background: #fff; border-radius: 14px; max-width: 560px; width: calc(100% - 40px); padding: 24px; }
  .exp-modal h3 { margin: 0 0 10px; }
</style>

<main class="exp-main">
<div class="exp-wrap">
  <div class="exp-head">
    <div>
      <h1>⚡ Créer un bien — Express</h1>
      <div class="sub">Flow rapide avec IA et pré-remplissage automatique.</div>
    </div>
    <div>
      <a href="bien_ajouter.php">Mode détaillé →</a>
    </div>
  </div>

  <form id="exp-form">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id_bien" id="exp-id-bien" value="">
    <input type="hidden" name="id_proprietaire" id="exp-id-proprietaire" value="">
    <input type="hidden" name="dpe_applied" id="exp-dpe-applied" value="0">

    <!-- STEP 1 : Référence preview -->
    <div class="exp-step" id="step-ref">
      <div class="num">1</div>
      <h2>Référence interne</h2>
      <div class="hint">Format configuré pour votre agence : <code><?= h($refPattern) ?></code>. La référence sera générée automatiquement à la création.</div>
      <div class="exp-field">
        <label>Référence qui sera assignée</label>
        <input type="text" id="exp-ref-preview" value="(sera générée automatiquement)" readonly style="color:#64748b;background:#f1f5f9;font-family:monospace;">
      </div>
    </div>

    <!-- STEP 2 : DPE import -->
    <div class="exp-step" id="step-dpe">
      <div class="num">2</div>
      <h2>Importer le DPE</h2>
      <div class="hint">Pré-remplit automatiquement <strong>~80% des informations</strong> (surface, pièces, DPE/GES, adresse, année).</div>

      <div class="exp-dpe-drop" id="exp-dpe-drop">
        <div class="icon">📄</div>
        <div class="title">Glissez le DPE ici ou cliquez pour parcourir</div>
        <div class="sub">PDF uniquement — max 20 Mo</div>
        <input type="file" id="exp-dpe-input" accept="application/pdf" style="display:none;">
      </div>
      <div class="exp-dpe-status" id="exp-dpe-status"></div>
      <div class="exp-nodpe">
        <a id="exp-nodpe-btn">Pas de DPE disponible — continuer quand même</a>
      </div>
    </div>

    <!-- STEP 3 : Bien -->
    <div class="exp-step locked" id="step-bien">
      <div class="num">3</div>
      <h2>Informations du bien</h2>
      <div class="hint">Les champs <span class="req">*</span> sont nécessaires à la création.</div>

      <div class="exp-row">
        <div class="exp-field required-empty" id="wrap-type">
          <label>Type <span class="req">*</span></label>
          <select name="type_bien_code" id="exp-type-bien" required>
            <option value="">— Choisir —</option>
            <?php foreach ($typesList as $t): ?>
              <option value="<?= h((string)$t['code']) ?>"><?= h((string)$t['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="exp-field required-empty" id="wrap-transaction">
          <label>Transaction <span class="req">*</span></label>
          <select name="transaction" id="exp-transaction" required>
            <option value="">—</option>
            <option value="mandat">📜 Mandat (simple)</option>
            <option value="mandat_gestion">🔑 Mandat de gestion</option>
            <option value="location">🏠 Location (diffusion)</option>
            <option value="vente">🤝 Vente (diffusion)</option>
            <option value="estimation">📊 Estimation seulement</option>
          </select>
        </div>
      </div>

      <div class="exp-field required-empty" id="wrap-adresse">
        <label>Adresse postale <span class="req">*</span> <span style="font-weight:400;color:#64748b;text-transform:none;">— auto-complétée par Google</span></label>
        <input type="text"
               id="exp-adresse"
               name="adresse_1"
               autocomplete="off"
               placeholder="Commencez à taper (ex: 15 rue Voltaire Paris)…"
               data-places-input
               data-places-endpoint="api/places_autocomplete.php"
               data-places-details-endpoint="api/places_details.php"
               data-places-geocode-endpoint="api/geocode_address.php"
               data-places-street1="exp-adresse"
               data-places-postal="exp-cp"
               data-places-city="exp-ville"
               data-places-country="exp-pays"
               data-places-lat="exp-lat"
               data-places-lng="exp-lng"
               data-places-place-id="exp-place-id"
               data-places-formatted="exp-adresse-formatee"
               data-places-country-code="fr">
        <input type="hidden" name="pays" id="exp-pays" value="France">
        <input type="hidden" name="latitude" id="exp-lat">
        <input type="hidden" name="longitude" id="exp-lng">
        <input type="hidden" name="google_place_id" id="exp-place-id">
        <input type="hidden" name="adresse_formatee" id="exp-adresse-formatee">
        <div id="exp-adresse-status" style="font-size:10px;color:#16a34a;margin-top:4px;min-height:12px;"></div>
      </div>

      <div class="exp-field" id="wrap-adresse2">
        <label>Situation dans l'immeuble <span style="font-weight:400;color:#64748b;text-transform:none;">— porte, allée, cage, étage complément (optionnel)</span></label>
        <input type="text" name="adresse_2" id="exp-adresse2" placeholder="ex: Porte A, Allée B, Cage 2, bâtiment Nord">
      </div>

      <div class="exp-row">
        <div class="exp-field required-empty" id="wrap-cp">
          <label>Code postal <span class="req">*</span></label>
          <input type="text" name="code_postal" id="exp-cp">
        </div>
        <div class="exp-field required-empty" id="wrap-ville">
          <label>Ville <span class="req">*</span></label>
          <input type="text" name="ville" id="exp-ville">
        </div>
        <div class="exp-field">
          <label>Étage</label>
          <input type="text" name="etage" id="exp-etage">
        </div>
        <div class="exp-field">
          <label>Lot / porte</label>
          <input type="text" name="lot_principal" id="exp-lot">
        </div>
      </div>
      <div class="exp-row">
        <div class="exp-field"><label>Surface m²</label><input type="number" step="0.01" name="surface_habitable" id="exp-surface"></div>
        <div class="exp-field"><label>Pièces</label><input type="number" name="nb_pieces" id="exp-nbp"></div>
        <div class="exp-field"><label>Chambres</label><input type="number" name="nb_chambres" id="exp-nbc"></div>
        <div class="exp-field">
          <label>Année const.</label>
          <input type="number" name="annee_construction" id="exp-annee" min="1800" max="2099" placeholder="ex: 1975">
          <div id="exp-annee-hint" style="font-size:10px;color:#64748b;margin-top:3px;min-height:12px;line-height:1.3;"></div>
        </div>
      </div>
      <div class="exp-row">
        <div class="exp-field"><label>DPE classe</label><input type="text" name="dpe_classe" id="exp-dpe-cl" maxlength="1"></div>
        <div class="exp-field"><label>GES classe</label><input type="text" name="ges_classe" id="exp-ges-cl" maxlength="1"></div>
        <div class="exp-field"><label id="exp-prix-label">Prix / Loyer HC</label><input type="number" step="0.01" name="prix_vente_estime" id="exp-prix"></div>
      </div>

      <div id="exp-dup-bien" style="display:none;margin-top:10px;padding:12px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:8px;font-size:12px;"></div>
    </div>

    <!-- STEP 4 : Bailleur -->
    <div class="exp-step locked" id="step-bailleur">
      <div class="num">4</div>
      <h2>Bailleur <span style="font-size:12px;color:#dc2626;font-weight:400;">(obligatoire)</span></h2>
      <div class="hint">Recherche live par nom, email ou téléphone. Sélectionnez un existant ou créez un nouveau.</div>

      <div class="exp-row">
        <div class="exp-field required-empty" id="wrap-pro-nom">
          <label>Nom <span class="req">*</span></label>
          <div style="position:relative;">
            <input type="text" name="proprio_nom" id="exp-pro-nom" autocomplete="off">
            <div class="exp-proprio-suggest" id="exp-pro-suggest" style="display:none;"></div>
          </div>
        </div>
        <div class="exp-field"><label>Prénom</label><input type="text" name="proprio_prenom" id="exp-pro-prenom"></div>
        <div class="exp-field"><label>Société (si personne morale)</label><input type="text" name="proprio_societe" id="exp-pro-soc"></div>
      </div>
      <div class="exp-row">
        <div class="exp-field"><label>Email</label><input type="email" name="proprio_email" id="exp-pro-email"></div>
        <div class="exp-field"><label>Téléphone</label><input type="tel" name="proprio_telephone" id="exp-pro-tel"></div>
      </div>
      <div class="exp-field"><label>Adresse</label><input type="text" name="proprio_adresse" id="exp-pro-adr"></div>
      <div id="exp-pro-status" style="font-size:11px;color:#64748b;margin-top:4px;"></div>
    </div>

    <!-- STEP 5 : Photos -->
    <div class="exp-step locked" id="step-photos">
      <div class="num">5</div>
      <h2>Photos <span style="font-size:11px;color:#64748b;font-weight:400;">— <span id="exp-photo-count">0</span> chargée(s)</span></h2>
      <div class="hint">Analyse IA automatique en arrière-plan (catégorie + description pour le SEO). Cliquez ✕ pour supprimer.</div>

      <!-- Grille des photos déjà chargées (au-dessus du dropzone) -->
      <div class="exp-photo-grid" id="exp-photo-grid" style="margin-bottom:14px;"></div>

      <!-- Dropzone en dessous -->
      <div class="exp-dpe-drop" id="exp-photos-drop" style="border-color:#6a4ca8;background:#faf5ff;">
        <div class="icon">📷</div>
        <div class="title" style="color:#6a4ca8;">Ajouter des photos</div>
        <div class="sub">JPG · PNG · WEBP — multi-fichiers OK (maintenez Ctrl/Shift pour sélectionner plusieurs)</div>
        <input type="file" id="exp-photos-input" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
      </div>
    </div>

    <!-- STEP 6 : Environnement -->
    <div class="exp-step locked" id="step-env">
      <div class="num">6</div>
      <h2>Environnement</h2>
      <div class="hint">Sélection rapide — pas de saisie libre.</div>

      <label style="font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;">Exposition</label>
      <div class="exp-chips" data-env="exposition" data-multi="0" style="margin-bottom:12px;">
        <div class="exp-chip" data-val="sud">☀️ Sud</div>
        <div class="exp-chip" data-val="est">🌅 Est</div>
        <div class="exp-chip" data-val="ouest">🌆 Ouest</div>
        <div class="exp-chip" data-val="nord">🌙 Nord</div>
        <div class="exp-chip" data-val="traversant">↔️ Traversant</div>
      </div>

      <label style="font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;">Vue</label>
      <div class="exp-chips" data-env="vue" data-multi="1" style="margin-bottom:12px;">
        <div class="exp-chip" data-val="vegetale">🌳 Végétale</div>
        <div class="exp-chip" data-val="degagee">🏞️ Dégagée</div>
        <div class="exp-chip" data-val="urbaine">🏘️ Urbaine</div>
        <div class="exp-chip" data-val="rue">🛣️ Rue</div>
        <div class="exp-chip" data-val="mer">🌊 Mer / lac</div>
      </div>

      <label style="font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;">Ambiance</label>
      <div class="exp-chips" data-env="ambiance" data-multi="1" style="margin-bottom:12px;">
        <div class="exp-chip" data-val="calme">🤫 Calme</div>
        <div class="exp-chip" data-val="centre">🏙️ Centre-ville</div>
        <div class="exp-chip" data-val="transports">🚇 Proche transports</div>
        <div class="exp-chip" data-val="commerces">🛒 Proche commerces</div>
        <div class="exp-chip" data-val="residentiel">🌳 Résidentiel</div>
      </div>

      <label style="font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;">Nuisances</label>
      <div class="exp-chips" data-env="nuisances" data-multi="1" style="margin-bottom:12px;">
        <div class="exp-chip" data-val="route">🔊 Route</div>
        <div class="exp-chip" data-val="aerien">✈️ Aérien</div>
        <div class="exp-chip" data-val="rail">🚂 Ferroviaire</div>
        <div class="exp-chip" data-val="vis_a_vis">🏢 Vis-à-vis</div>
      </div>

      <label style="font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;">Distance transports</label>
      <div class="exp-chips" data-env="dist_transports" data-multi="0" style="margin-bottom:12px;">
        <div class="exp-chip" data-val="immediat">🚇 Immédiat (&lt;2min)</div>
        <div class="exp-chip" data-val="proche">🚶 Proche (2-5min)</div>
        <div class="exp-chip" data-val="moyen">🚶‍♂️ Moyen (5-10min)</div>
        <div class="exp-chip" data-val="eloigne">🚗 Éloigné (&gt;10min)</div>
        <div class="exp-chip" data-val="voiture">🚙 Voiture requise</div>
      </div>

      <label style="font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;">Distance commerces</label>
      <div class="exp-chips" data-env="dist_commerces" data-multi="0" style="margin-bottom:12px;">
        <div class="exp-chip" data-val="immediat">🛒 Immédiat (&lt;2min)</div>
        <div class="exp-chip" data-val="proche">🛍️ Proche (2-5min)</div>
        <div class="exp-chip" data-val="moyen">🏪 Moyen (5-10min)</div>
        <div class="exp-chip" data-val="eloigne">🛣️ Éloigné (&gt;10min)</div>
      </div>

      <div class="exp-field" style="position:relative;">
        <label>Quartier (important SEO local)</label>
        <input type="text" name="quartier" id="exp-quartier" autocomplete="off" placeholder="ex: Antigone, Part-Dieu, Le Marais…">
        <div id="exp-quartier-suggest" style="display:none;position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.08);max-height:200px;overflow-y:auto;z-index:100;width:100%;margin-top:2px;"></div>
      </div>
      <div class="exp-field">
        <label>Points d'intérêt supplémentaires (optionnel)</label>
        <input type="text" name="points_interet" id="exp-poi" placeholder="ex: école Jules Ferry à 200m, parc proche">
      </div>
      <div class="exp-field">
        <label>Argument phare (1 phrase)</label>
        <input type="text" name="argument_phare" id="exp-arg" placeholder="ex: terrasse plein sud, vue mer">
      </div>
    </div>

    <!-- STEP 7 : Note IA + Générer annonce -->
    <div class="exp-step locked" id="step-ia">
      <div class="num">7</div>
      <h2>Générer l'annonce</h2>
      <div class="hint">Note optionnelle pour guider l'IA (ton, focus, mentions spécifiques).</div>
      <div class="exp-field">
        <label>💡 Note pour l'IA (éphémère)</label>
        <textarea id="exp-note-ia" rows="3" placeholder="ex: insister sur la vue, ton chaleureux, mentionner l'école à 5min"></textarea>
      </div>
      <button type="button" id="exp-generate-btn" class="exp-btn" disabled>✨ Générer l'annonce (SEO + LBC)</button>
    </div>

    <!-- STEP 8 : Preview annonce -->
    <div class="exp-step locked" id="step-preview">
      <div class="num">8</div>
      <h2>Preview & édition de l'annonce</h2>
      <div class="hint">Tout est éditable. Clic ↻ pour régénérer avec ou sans modifier la note.</div>
      <div id="exp-ia-output"></div>
      <div style="margin-top:10px;"><button type="button" id="exp-regen-btn" class="exp-btn-ghost">↻ Régénérer</button></div>
    </div>

    <!-- STEP 9 : Valider -->
    <div class="exp-step locked" id="step-valider">
      <div class="num">9</div>
      <h2>Validation finale</h2>
      <div class="hint">Un brouillon a été créé automatiquement. La validation publie l'annonce.</div>
      <div id="exp-conformity-panel" style="font-size:12px;"></div>
      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" id="exp-publish-btn" class="exp-btn" disabled>💾 Valider & publier</button>
        <a href="bien_ajouter.php" id="exp-edit-detailed" class="exp-btn-ghost" style="display:none;text-decoration:none;">Passer en mode détaillé</a>
      </div>
    </div>
  </form>
</div>

<!-- Footer conformité permanent -->
<div class="exp-footer">
  <div class="exp-conf">
    <div class="score" id="exp-score">0%</div>
    <div><strong>Conformité portails</strong><br><span id="exp-score-detail" style="font-size:11px;">Commencez par importer un DPE ou remplir les champs</span></div>
  </div>
</div>

<!-- Modal warning pas de DPE -->
<div class="exp-modal-back" id="exp-modal-nodpe" style="display:none;">
  <div class="exp-modal">
    <h3>⚠️ Attention</h3>
    <p style="font-size:13px;line-height:1.5;color:#475569;">
      Sans DPE, votre annonce <strong>ne sera pas diffusée sur les portails</strong> (Le Bon Coin, SeLoger…).<br>
      Sur votre site, elle sera marquée <strong>« Provisoire »</strong>.<br><br>
      <em>Ajouter le DPE permet de pré-remplir près de <strong>80% des champs</strong> en quelques secondes.</em>
    </p>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
      <button type="button" class="exp-btn-ghost" id="exp-modal-cancel">Ajouter le DPE</button>
      <button type="button" class="exp-btn warn" id="exp-modal-continue">Continuer sans DPE</button>
    </div>
  </div>
</div>

<script>
(function() {
  const CSRF = <?= json_encode($csrf) ?>;
  const PROPRIOS = <?= json_encode($proprietairesList, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?: '[]' ?>;

  const $ = (id) => document.getElementById(id);
  const show = (id) => { const e = $(id); if (e) e.classList.remove('locked'); };
  const markDone = (stepId) => { const e = $(stepId); if (e) { const n = e.querySelector('.num'); if (n) n.classList.add('done'); } };

  let state = {
    id_bien: 0,
    id_proprietaire: 0,
    ref: '',
    env: { exposition: '', vue: [], ambiance: [], nuisances: [], dist_transports: '', dist_commerces: '' },
    generated: null,
    photos: [],
  };

  // Quartiers connus des grandes villes françaises (liste courte, à étendre dans une table plus tard)
  const QUARTIERS = {
    'lyon': ['1er arrondissement','2e arrondissement','3e arrondissement (Part-Dieu)','4e arrondissement (Croix-Rousse)','5e arrondissement (Vieux Lyon)','6e arrondissement (Brotteaux)','7e arrondissement (Guillotière/Gerland)','8e arrondissement (Monplaisir)','9e arrondissement (Vaise)','Confluence','Presqu\'île','Terreaux','Perrache','Montchat','Tête d\'Or'],
    'paris': ['1er arr. (Louvre)','2e arr. (Bourse)','3e arr. (Marais)','4e arr. (Île Saint-Louis)','5e arr. (Quartier latin)','6e arr. (Saint-Germain)','7e arr. (Invalides)','8e arr. (Champs-Élysées)','9e arr. (Opéra)','10e arr. (République)','11e arr. (Bastille)','12e arr. (Bercy)','13e arr. (Place d\'Italie)','14e arr. (Montparnasse)','15e arr.','16e arr. (Passy)','17e arr. (Batignolles)','18e arr. (Montmartre)','19e arr. (Buttes-Chaumont)','20e arr. (Belleville)'],
    'marseille': ['1er arr. (Belsunce)','2e arr. (Joliette)','3e arr.','4e arr. (La Blancarde)','5e arr. (Baille)','6e arr. (Préfecture)','7e arr. (Vieux-Port)','8e arr. (Prado/Corniche)','9e arr. (Mazargues)','10e arr. (Saint-Loup)','11e arr. (Saint-Marcel)','12e arr. (Les Caillols)','13e arr. (Château-Gombert)','14e arr. (Saint-Antoine)','15e arr.','16e arr. (L\'Estaque)'],
    'montpellier': ['Centre historique (Écusson)','Antigone','Port Marianne','Beaux-Arts','Les Aubes','Les Arceaux','Boutonnet','Figuerolles','La Paillade','Mosson','Malbosc','Celleneuve','Hôpitaux-Facultés','Les Cévennes','Prés d\'Arènes','Estanove','Ovalie','Parc Marianne'],
    'toulouse': ['Capitole','Carmes','Saint-Étienne','Saint-Aubin','Saint-Cyprien','Compans-Caffarelli','Minimes','Côte Pavée','Rangueil','Jolimont','Basso Cambo','Purpan','Blagnac (voisine)','Lardenne','Les Chalets'],
    'nice': ['Vieux Nice','Carré d\'Or','Cimiez','Port','Musiciens','Libération','Riquier','Saint-Roch','Magnan','Fabron','L\'Ariane','Saint-Isidore'],
    'bordeaux': ['Chartrons','Saint-Pierre','Saint-Michel','Victoire','Bacalan','Caudéran','Grand Parc','Les Aubiers','La Bastide','Saint-Jean','Nansouty'],
    'nantes': ['Centre-ville','Île de Nantes','Bouffay','Graslin','Dervallières','Zola','Chantenay','Doulon','Erdre','Malakoff','Bellevue'],
    'strasbourg': ['Centre','Neustadt','Krutenau','Petite France','Robertsau','Neuhof','Hautepierre','Cronenbourg','Koenigshoffen','Wacken'],
    'lille': ['Vieux-Lille','Centre','Wazemmes','Moulins','Saint-Maurice Pellevoisin','Vauban-Esquermes','Fives','Bois-Blancs','Lille-Sud','Faubourg de Béthune'],
  };

  // ─── Conformité (simple) ──
  function confUpdate() {
    const reqs = [
      { id: 'exp-type-bien',  name: 'Type' },
      { id: 'exp-transaction', name: 'Transaction' },
      { id: 'exp-adresse',    name: 'Adresse' },
      { id: 'exp-cp',         name: 'Code postal' },
      { id: 'exp-ville',      name: 'Ville' },
      { id: 'exp-surface',    name: 'Surface' },
      { id: 'exp-nbp',        name: 'Pièces' },
      { id: 'exp-prix',       name: 'Prix/Loyer' },
      { id: 'exp-pro-nom',    name: 'Bailleur (nom)', or: 'exp-id-proprietaire' },
      { id: 'exp-dpe-cl',     name: 'DPE classe' },
    ];
    let ok = 0;
    const missing = [];
    reqs.forEach(r => {
      const el = $(r.id);
      const orEl = r.or ? $(r.or) : null;
      const v = (el && el.value.trim() !== '') || (orEl && orEl.value !== '');
      if (v) ok++; else missing.push(r.name);
      if (el) {
        const wrap = el.closest('.exp-field');
        if (wrap) wrap.classList.toggle('required-empty', !v);
      }
    });
    const score = Math.round(ok / reqs.length * 100);
    $('exp-score').textContent = score + '%';
    $('exp-score').className = 'score ' + (score >= 100 ? '' : score >= 70 ? 'warn' : 'bad');
    $('exp-score-detail').textContent = missing.length ? 'Manque : ' + missing.slice(0, 3).join(', ') + (missing.length > 3 ? '…' : '') : '✅ Prêt à publier';
    $('exp-publish-btn').disabled = missing.length > 0 || !state.id_bien;
    // Si pas DPE → label alt
    if (missing.includes('DPE classe')) {
      $('exp-publish-btn').textContent = '💾 Valider (annonce provisoire)';
      $('exp-publish-btn').classList.add('warn');
    } else {
      $('exp-publish-btn').textContent = '💾 Valider & publier';
      $('exp-publish-btn').classList.remove('warn');
    }
  }

  // ─── Écoute changements → màj conformité + colors ──
  document.addEventListener('input', confUpdate);
  document.addEventListener('change', confUpdate);

  // Transaction → label prix
  $('exp-transaction').addEventListener('change', () => {
    const t = $('exp-transaction').value;
    $('exp-prix-label').textContent = t === 'vente' ? 'Prix de vente (€)' : t === 'location' ? 'Loyer HC (€/mois)' : 'Prix / Loyer HC';
  });

  // ─── STEP 2 : DPE drag-drop / click ──
  const dpeDrop = $('exp-dpe-drop');
  const dpeInput = $('exp-dpe-input');
  dpeDrop.addEventListener('click', () => dpeInput.click());
  dpeDrop.addEventListener('dragover', e => { e.preventDefault(); dpeDrop.style.background = '#bae6fd'; });
  dpeDrop.addEventListener('dragleave', () => dpeDrop.style.background = '#f0f9ff');
  dpeDrop.addEventListener('drop', e => {
    e.preventDefault(); dpeDrop.style.background = '#f0f9ff';
    if (e.dataTransfer.files.length) { dpeInput.files = e.dataTransfer.files; dpeInput.dispatchEvent(new Event('change')); }
  });
  dpeInput.addEventListener('change', uploadDpe);

  async function uploadDpe() {
    const file = dpeInput.files[0];
    if (!file) return;
    const status = $('exp-dpe-status');
    status.style.display = 'block';
    status.style.background = '#f0f9ff'; status.style.color = '#0369a1';
    status.innerHTML = '⏳ Analyse du DPE en cours (15-30s)…';

    const fd = new FormData();
    fd.append('fichier', file);
    fd.append('csrf_token', CSRF);
    try {
      const r = await fetch('<?= h(app_url('/api/dpe_import_upload.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Échec analyse');
      // Remplit les champs avec les valeurs extraites
      const f = j.fields || {};
      const map = {
        type_bien: 'exp-type-bien', adresse_1: 'exp-adresse', code_postal: 'exp-cp', ville: 'exp-ville',
        surface_habitable: 'exp-surface', nb_pieces: 'exp-nbp', nb_chambres: 'exp-nbc',
        annee_construction: 'exp-annee', etage: 'exp-etage',
        dpe_classe: 'exp-dpe-cl', ges_classe: 'exp-ges-cl',
      };
      let filled = 0;
      Object.entries(map).forEach(([k, id]) => {
        if (f[k] != null && f[k] !== '') {
          const el = $(id);
          if (el) { el.value = f[k]; el.closest('.exp-field')?.classList.add('ia-filled'); filled++; }
        }
      });
      $('exp-dpe-applied').value = '1';
      status.style.background = '#f0fdf4'; status.style.color = '#14532d';
      status.innerHTML = '✅ DPE analysé — <strong>' + filled + '</strong> champ(s) pré-remplis. Vérifiez et complétez les champs critiques.';
      markDone('step-dpe');
      show('step-bien'); show('step-bailleur'); show('step-photos'); show('step-env'); show('step-ia'); show('step-preview'); show('step-valider');
      confUpdate();
      await ensureDraftCreated();
    } catch (e) {
      status.style.background = '#fef2f2'; status.style.color = '#991b1b';
      status.innerHTML = '❌ ' + e.message;
    }
  }

  // Pas de DPE → modal
  $('exp-nodpe-btn').addEventListener('click', () => $('exp-modal-nodpe').style.display = 'flex');
  $('exp-modal-cancel').addEventListener('click', () => $('exp-modal-nodpe').style.display = 'none');
  $('exp-modal-continue').addEventListener('click', () => {
    $('exp-modal-nodpe').style.display = 'none';
    markDone('step-dpe');
    show('step-bien'); show('step-bailleur'); show('step-photos'); show('step-env'); show('step-ia'); show('step-preview'); show('step-valider');
    confUpdate();
  });

  // ─── STEP 3 : check doublon bien (au blur adresse) ──
  $('exp-adresse').addEventListener('blur', async () => {
    if (!$('exp-adresse').value.trim()) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('adresse_1', $('exp-adresse').value);
    fd.append('code_postal', $('exp-cp').value);
    fd.append('ville', $('exp-ville').value);
    fd.append('etage', $('exp-etage').value);
    fd.append('lot_principal', $('exp-lot').value);
    fd.append('surface_habitable', $('exp-surface').value);
    fd.append('nb_pieces', $('exp-nbp').value);
    try {
      const r = await fetch('<?= h(app_url('/api/bien_check_duplicate.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await r.json();
      const dup = $('exp-dup-bien');
      if (j.ok && j.count > 0) {
        dup.style.display = 'block';
        dup.innerHTML = '<strong>⚠️ ' + j.count + ' bien(s) similaire(s) trouvé(s) :</strong><br>' +
          j.matches.slice(0, 3).map(m =>
            `<div style="margin-top:6px;">📍 <strong>${m.reference_bien}</strong> — ${m.adresse_1||''} ${m.code_postal||''} ${m.ville||''}` +
            (m.etage ? ' · Ét. ' + m.etage : '') + ` <em style="color:#64748b;">(score ${m.score})</em> ` +
            `<a href="${m.edit_url}" style="color:#0369a1;margin-left:8px;">Ouvrir →</a></div>`
          ).join('');
      } else {
        dup.style.display = 'none';
      }
    } catch (e) {}
  });

  // ─── STEP 4 : check doublon bailleur live ──
  let proLastQuery = '';
  async function proSearch() {
    const nom = $('exp-pro-nom').value.trim();
    const email = $('exp-pro-email').value.trim();
    const tel = $('exp-pro-tel').value.trim();
    const soc = $('exp-pro-soc').value.trim();
    const q = nom + '|' + email + '|' + tel + '|' + soc;
    if (q === proLastQuery) return;
    proLastQuery = q;
    if (!nom && !email && !tel && !soc) { $('exp-pro-suggest').style.display = 'none'; return; }
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('nom', nom); fd.append('email', email); fd.append('telephone', tel); fd.append('societe', soc);
    try {
      const r = await fetch('<?= h(app_url('/api/bailleur_check_duplicate.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await r.json();
      const s = $('exp-pro-suggest');
      if (j.ok && j.count > 0) {
        s.innerHTML = j.matches.map(m =>
          `<div class="exp-proprio-item" data-id="${m.id}" data-nom="${m.nom}" data-prenom="${m.prenom||''}" data-societe="${m.societe||''}" data-email="${m.email||''}" data-tel="${m.telephone||''}">
            <strong>${m.societe || (m.prenom + ' ' + m.nom)}</strong>
            <span class="match">score ${m.score}</span>
            <div style="font-size:10px;color:#64748b;">${[m.email, m.telephone, m.ville].filter(Boolean).join(' • ')}</div>
          </div>`).join('');
        s.style.display = 'block';
        s.querySelectorAll('.exp-proprio-item').forEach(it => {
          it.addEventListener('click', () => {
            $('exp-id-proprietaire').value = it.dataset.id;
            $('exp-pro-nom').value = it.dataset.nom;
            $('exp-pro-prenom').value = it.dataset.prenom;
            $('exp-pro-soc').value = it.dataset.societe;
            $('exp-pro-email').value = it.dataset.email;
            $('exp-pro-tel').value = it.dataset.tel;
            s.style.display = 'none';
            $('exp-pro-status').innerHTML = '✅ Bailleur existant sélectionné (ID ' + it.dataset.id + ')';
            markDone('step-bailleur');
            confUpdate();
          });
        });
      } else {
        s.style.display = 'none';
      }
    } catch (e) {}
  }
  ['exp-pro-nom', 'exp-pro-email', 'exp-pro-tel', 'exp-pro-soc'].forEach(id => {
    let t; $(id).addEventListener('input', () => {
      clearTimeout(t); t = setTimeout(proSearch, 400);
      $('exp-id-proprietaire').value = ''; // toute nouvelle saisie = déselection éventuelle
    });
  });

  // ─── STEP 6 : chips environnement ──
  document.querySelectorAll('.exp-chips').forEach(group => {
    const env = group.dataset.env;
    const multi = group.dataset.multi === '1';
    if (multi) state.env[env] = [];
    group.querySelectorAll('.exp-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        if (multi) {
          chip.classList.toggle('active');
          const vals = [...group.querySelectorAll('.exp-chip.active')].map(c => c.dataset.val);
          state.env[env] = vals;
        } else {
          group.querySelectorAll('.exp-chip').forEach(c => c.classList.remove('active'));
          chip.classList.add('active');
          state.env[env] = chip.dataset.val;
        }
      });
    });
  });

  // ─── STEP 5 : photos upload + delete ──
  const photoDrop = $('exp-photos-drop');
  const photoInput = $('exp-photos-input');
  photoDrop.addEventListener('click', () => photoInput.click());
  photoDrop.addEventListener('dragover', e => { e.preventDefault(); photoDrop.style.background = '#ede3ff'; });
  photoDrop.addEventListener('dragleave', () => photoDrop.style.background = '#faf5ff');
  photoDrop.addEventListener('drop', e => {
    e.preventDefault(); photoDrop.style.background = '#faf5ff';
    if (e.dataTransfer.files.length) { photoInput.files = e.dataTransfer.files; photoInput.dispatchEvent(new Event('change')); }
  });
  photoInput.addEventListener('change', async () => {
    const files = Array.from(photoInput.files);
    photoInput.value = ''; // reset immédiat pour accepter nouveaux uploads même avant fin du batch
    if (!files.length) return;
    // S'assurer qu'on a un brouillon AVANT de démarrer les uploads
    await ensureDraftCreated();
    if (!state.id_bien) { alert('Impossible de créer le brouillon. Vérifiez les champs critiques.'); return; }
    // Upload séquentiel pour éviter de surcharger le serveur
    for (const f of files) await uploadOnePhoto(f);
  });
  function updatePhotoCount() {
    $('exp-photo-count').textContent = state.photos.length;
  }
  function renderPhotoThumb(p) {
    const thumb = document.createElement('div');
    thumb.className = 'exp-photo-thumb';
    thumb.dataset.photoId = p.id;
    thumb.innerHTML = `
      <img src="${p.url || ''}" alt="">
      ${p.categorie ? `<div class="cat">${p.categorie.replace(/_/g,' ')}</div>` : ''}
      ${p.description ? `<div class="status">${p.description.substring(0,60)}…</div>` : ''}
      <button type="button" class="exp-photo-del" title="Supprimer"
              style="position:absolute;top:4px;right:4px;width:24px;height:24px;border-radius:50%;background:rgba(220,38,38,.95);color:#fff;border:none;font-weight:700;cursor:pointer;font-size:14px;line-height:1;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.25);">✕</button>
    `;
    thumb.querySelector('.exp-photo-del').addEventListener('click', async (ev) => {
      ev.stopPropagation();
      if (!confirm('Supprimer cette photo ?')) return;
      const btn = ev.currentTarget; btn.disabled = true; btn.textContent = '⏳';
      try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('id_photo', String(p.id));
        const r = await fetch('<?= h(app_url('/api/bien_photo_delete.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
        const j = await r.json();
        if (j.ok) {
          state.photos = state.photos.filter(x => x.id !== p.id);
          thumb.remove(); updatePhotoCount();
        } else {
          alert('Erreur : ' + (j.error || 'inconnue')); btn.disabled = false; btn.textContent = '✕';
        }
      } catch (e) { alert('Réseau : ' + e.message); btn.disabled = false; btn.textContent = '✕'; }
    });
    return thumb;
  }
  async function uploadOnePhoto(file) {
    if (!state.id_bien) return;
    const grid = $('exp-photo-grid');
    const placeholder = document.createElement('div');
    placeholder.className = 'exp-photo-thumb';
    placeholder.innerHTML = '<div class="status">⏳ ' + (file.name || 'upload') + '…</div>';
    grid.appendChild(placeholder);
    try {
      const fd = new FormData();
      fd.append('fichier', file);
      fd.append('id_bien', String(state.id_bien));
      fd.append('csrf_token', CSRF);
      const r = await fetch('<?= h(app_url('/api/bien_intake_photo_upload.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await r.json();
      if (j.ok) {
        const photo = { id: j.id, url: j.url, categorie: j.categorie, description: j.description };
        state.photos.push(photo);
        placeholder.replaceWith(renderPhotoThumb(photo));
        updatePhotoCount();
      } else {
        placeholder.innerHTML = '<div class="status" style="background:#dc2626;">❌ ' + (j.error || 'err') + '</div>';
        setTimeout(() => placeholder.remove(), 3500);
      }
    } catch (e) {
      placeholder.innerHTML = '<div class="status" style="background:#dc2626;">❌ réseau</div>';
      setTimeout(() => placeholder.remove(), 3500);
    }
  }

  // ─── Année construction : affiche la période (encadrement loyers + RT) ──
  $('exp-annee').addEventListener('input', () => {
    const y = parseInt($('exp-annee').value, 10);
    const hint = $('exp-annee-hint');
    if (!y || y < 1800 || y > 2099) { hint.textContent = ''; return; }
    // Catégorie encadrement loyers
    let enc = '';
    if (y < 1946)        enc = 'Avant 1946';
    else if (y <= 1970)  enc = '1946-1970';
    else if (y <= 1990)  enc = '1971-1990';
    else                 enc = 'Après 1990';
    // Réglementation thermique
    let rt = '';
    if (y < 1974)        rt = 'Avant RT';
    else if (y < 1989)   rt = 'RT 1974';
    else if (y < 2001)   rt = 'RT 1989';
    else if (y < 2006)   rt = 'RT 2000';
    else if (y < 2013)   rt = 'RT 2005';
    else if (y < 2022)   rt = 'RT 2012';
    else                 rt = 'RE 2020';
    hint.innerHTML = `<span style="color:#6a4ca8;">📊 Encadrement : <strong>${enc}</strong></span> • <span style="color:#0369a1;">⚡ ${rt}</span>`;
  });

  // ─── Quartier autocomplete ──
  $('exp-quartier').addEventListener('input', () => {
    const v = $('exp-quartier').value.trim().toLowerCase();
    const ville = ($('exp-ville').value || '').trim().toLowerCase();
    const sug = $('exp-quartier-suggest');
    sug.innerHTML = '';
    if (!ville || !QUARTIERS[ville]) { sug.style.display = 'none'; return; }
    const matches = QUARTIERS[ville].filter(q => q.toLowerCase().includes(v));
    if (!matches.length || v === '') { sug.style.display = 'none'; return; }
    matches.slice(0, 8).forEach(q => {
      const d = document.createElement('div');
      d.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid #f1f5f9;';
      d.textContent = q;
      d.addEventListener('mouseenter', () => d.style.background = '#f1f5f9');
      d.addEventListener('mouseleave', () => d.style.background = '');
      d.addEventListener('click', () => {
        $('exp-quartier').value = q;
        sug.style.display = 'none';
      });
      sug.appendChild(d);
    });
    sug.style.display = 'block';
  });
  $('exp-quartier').addEventListener('blur', () => setTimeout(() => { $('exp-quartier-suggest').style.display = 'none'; }, 150));

  // ─── Création brouillon dès qu'on a assez d'infos ──
  let draftCreating = false;
  async function ensureDraftCreated() {
    if (state.id_bien > 0 || draftCreating) return;
    draftCreating = true;
    const fd = new FormData($('exp-form'));
    fd.append('csrf_token', CSRF);
    try {
      const r = await fetch('<?= h(app_url('/api/bien_express_create.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await r.json();
      if (j.ok) {
        state.id_bien = j.id_bien;
        state.ref = j.reference_bien;
        $('exp-id-bien').value = j.id_bien;
        $('exp-ref-preview').value = j.reference_bien;
        $('exp-edit-detailed').href = 'bien_ajouter.php?edit=' + j.id_bien;
        $('exp-edit-detailed').style.display = 'inline-block';
        if (j.id_proprietaire) { state.id_proprietaire = j.id_proprietaire; $('exp-id-proprietaire').value = j.id_proprietaire; }
        markDone('step-ref'); markDone('step-bailleur');
        $('exp-generate-btn').disabled = false;
      } else {
        alert('Erreur création brouillon : ' + (j.error || 'inconnue'));
      }
    } catch (e) {
      alert('Réseau : ' + e.message);
    } finally {
      draftCreating = false;
    }
  }

  // Trigger création brouillon quand champs critiques remplis
  let draftTimer;
  function scheduleDraft() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(() => {
      const hasAll = $('exp-adresse').value && $('exp-ville').value && $('exp-cp').value && $('exp-type-bien').value;
      const hasPro = $('exp-pro-nom').value || $('exp-id-proprietaire').value;
      if (hasAll && hasPro) ensureDraftCreated();
    }, 800);
  }
  ['exp-adresse','exp-cp','exp-ville','exp-type-bien','exp-pro-nom'].forEach(id => $(id).addEventListener('blur', scheduleDraft));

  // ─── STEP 7 : Générer annonce ──
  $('exp-generate-btn').addEventListener('click', generateAnnonce);
  $('exp-regen-btn').addEventListener('click', generateAnnonce);

  async function generateAnnonce() {
    if (!state.id_bien) { alert('Remplissez d\'abord les champs critiques pour créer le brouillon.'); return; }
    if (!$('exp-transaction').value) { alert('Choisissez d\'abord la transaction (location/vente).'); return; }
    const btn = $('exp-generate-btn'); btn.disabled = true; btn.textContent = '⏳ Génération…';
    const btn2 = $('exp-regen-btn'); btn2.disabled = true;
    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id_bien', state.id_bien);
      fd.append('transaction', $('exp-transaction').value);
      fd.append('note_ia', $('exp-note-ia').value);
      fd.append('quartier', $('exp-quartier').value);
      fd.append('points_interet', $('exp-poi').value);
      fd.append('argument_phare', $('exp-arg').value);
      Object.entries(state.env).forEach(([k, v]) => {
        if (Array.isArray(v)) v.forEach(x => fd.append('environnement[' + k + '][]', x));
        else if (v) fd.append('environnement[' + k + ']', v);
      });
      const r = await fetch('<?= h(app_url('/api/bien_generate_annonce.php')) ?>', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'échec');
      state.generated = j.generated;
      renderGenerated(j.generated);
      markDone('step-ia');
    } catch (e) {
      alert('❌ ' + e.message);
    } finally {
      btn.disabled = false; btn.textContent = '✨ Générer l\'annonce (SEO + LBC)';
      btn2.disabled = false;
    }
  }
  function renderGenerated(g) {
    const out = $('exp-ia-output');
    out.innerHTML = `
      <div class="exp-ia-preview"><label>Titre SEO (Google)</label><input type="text" id="ia-titre-seo" value="${(g.titre_seo||'').replace(/"/g,'&quot;')}"></div>
      <div class="exp-ia-preview"><label>Titre Le Bon Coin</label><input type="text" id="ia-titre-lbc" value="${(g.titre_lbc||'').replace(/"/g,'&quot;')}"></div>
      <div class="exp-ia-preview"><label>H1 page publique</label><input type="text" id="ia-h1" value="${(g.h1||'').replace(/"/g,'&quot;')}"></div>
      <div class="exp-ia-preview"><label>Meta description</label><input type="text" id="ia-meta" value="${(g.meta_description||'').replace(/"/g,'&quot;')}"></div>
      <div class="exp-ia-preview"><label>Slug URL</label><input type="text" id="ia-slug" value="${(g.slug||'').replace(/"/g,'&quot;')}"></div>
      <div class="exp-ia-preview"><label>Description</label><textarea id="ia-desc">${g.description||''}</textarea></div>
      <div class="exp-ia-preview"><label>Mots-clés</label><input type="text" id="ia-kw" value="${(g.mots_cles||[]).join(', ')}"></div>
    `;
  }

  // ─── STEP 9 : Publier ──
  $('exp-publish-btn').addEventListener('click', async () => {
    if (!state.id_bien) { alert('Brouillon pas encore créé'); return; }
    if (!confirm('Valider et publier cette annonce ?')) return;
    // Pour MVP : redirige vers la fiche détaillée, le user finalise là-bas si besoin
    // (un endpoint de publish pourra être ajouté plus tard)
    alert('✅ Brouillon enregistré sous référence ' + state.ref + '. Redirection vers la fiche détaillée pour finaliser la publication.');
    window.location.href = 'bien_ajouter.php?edit=' + state.id_bien;
  });

  // Init
  confUpdate();
})();
</script>

</main>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
