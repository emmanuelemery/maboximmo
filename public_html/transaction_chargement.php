<?php
// transaction_chargement.php — Module Chargement par lot (V0)
// ─────────────────────────────────────────────────────────────────
// Upload massif : DPE / Baux / Taxes / Mandats / Photos / Plans / Mixte
// 1. Upload multiple
// 2. Analyse nom de fichier → détecte type doc
// 3. Recherche bien correspondant (référence + tokens adresse/ville)
// 4. Score de confiance + UI de validation
// 5. Validation = insertion ged_documents (source_module='05_TRANSACTION')
// ─────────────────────────────────────────────────────────────────
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSocieteSession = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAgenceSession  = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;

$pageTitle    = 'Chargement par lot — Transaction';
$pageSubtitle = 'Ma Box Agency · Import multi-documents';

// Mode BIEN VERROUILLÉ : ?id_bien=X → tous les fichiers sont rattachés à ce bien,
// sans matching ni proposition d'autres biens.
$lockBien = null;
$lockBienId = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien']) ? (int)$_GET['id_bien'] : 0;
if ($lockBienId > 0) {
    $stL = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation,
                                 COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adr,
                                 COALESCE(NULLIF(b.ville,''), i.ville) AS ville
                            FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble
                           WHERE b.id = ? LIMIT 1");
    $stL->execute([$lockBienId]);
    $lockBien = $stL->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$lockBien) $lockBienId = 0;
}

$extraCss = <<<'CSS'
<style>
.chg-wrap { max-width: 1300px; }
.chg-hero {
    background: linear-gradient(135deg, #fff, #f9f7f3);
    border-radius: 14px; padding: 22px; margin-bottom: 18px;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
}
.chg-hero h2 { margin: 0 0 6px; font-size: 18px; color: #2c2a28; }
.chg-hero p { margin: 0; font-size: 13px; color: #7a766f; }

.chg-uploader {
    background: #fff; border-radius: 14px; padding: 24px;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
    margin-bottom: 18px;
}
.chg-row { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.chg-row label { font-size: 12px; font-weight: 700; color: #5a5650; }
.chg-row select { border: 1px solid #e3dfd8; border-radius: 8px; padding: 8px 12px; font-size: 13px; background: #fafafa; }

.chg-drop {
    border: 2px dashed #c8c4be; border-radius: 12px;
    padding: 40px; text-align: center; cursor: pointer;
    background: #fafafa; transition: all .2s;
}
.chg-drop:hover, .chg-drop.dragover { background: #f0ece6; border-color: #4878a6; }
.chg-drop .icon { font-size: 42px; margin-bottom: 8px; }
.chg-drop .title { font-size: 15px; font-weight: 700; color: #2c2a28; }
.chg-drop .sub { font-size: 12px; color: #7a766f; margin-top: 6px; }

.chg-results {
    background: #fff; border-radius: 14px;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
    overflow: hidden;
}
.chg-results-head {
    padding: 14px 18px; background: #f4f1ec; display: flex; align-items: center; gap: 12px;
}
.chg-results-head h3 { margin: 0; font-size: 15px; }
.chg-results-head .stats { font-size: 12px; color: #7a766f; }
.chg-results-head .actions { margin-left: auto; display: flex; gap: 8px; }

.chg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.chg-table thead th {
    background: #fafaf6; color: #5a5650; font-weight: 700; font-size: 11px;
    padding: 10px; text-align: left; text-transform: uppercase; letter-spacing: .03em;
    border-bottom: 1px solid #e3dfd8;
}
.chg-table tbody td { padding: 10px; border-bottom: 1px solid #f0ece6; vertical-align: middle; }
.chg-table tbody tr:hover { background: #fafaf6; }

.chg-conf { padding: 3px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.chg-conf.high   { background: #d9f0db; color: #2d6a35; }   /* > 85 */
.chg-conf.medium { background: #ffe5c2; color: #8a4c12; }   /* 50-85 */
.chg-conf.low    { background: #fbe9e9; color: #a8323b; }   /* < 50 */

/* Cards candidats (Sprint 2C-Phase2 — UI riche) */
.chg-candidates { display: flex; flex-direction: column; gap: 5px; max-width: 480px; }
.chg-cand { display: flex; gap: 8px; align-items: flex-start; padding: 6px 9px; border-radius: 7px;
    border: 1px solid #e3dfd8; background: #fff; cursor: pointer; transition: all .12s;
    font-size: 11.5px; line-height: 1.4; }
.chg-cand:hover { background: #fafaf6; border-color: #c8c4be; }
.chg-cand.selected { background: #f0fdf4; border-color: #84a98c; box-shadow: 0 0 0 1px #84a98c inset; }
.chg-cand input[type=radio] { margin: 2px 0 0; flex-shrink: 0; accent-color: #84a98c; }
.chg-cand .cand-body { flex: 1; min-width: 0; }
.chg-cand .cand-title { font-weight: 700; color: #2c2a28; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.chg-cand .cand-meta { color: #7a766f; font-size: 10.5px; margin-top: 2px; }
.chg-cand .cand-reasons { margin-top: 3px; display: flex; flex-wrap: wrap; gap: 3px; }
.chg-cand .cand-reason { background: #f4f1ec; color: #5a5650; padding: 1px 6px; border-radius: 4px;
    font-size: 9.5px; font-family: "DM Mono", monospace; }
.chg-cand .cand-score { padding: 1px 7px; border-radius: 99px; font-size: 10px; font-weight: 700;
    font-family: "DM Mono", monospace; }
.chg-cand .cand-score.high   { background: #d9f0db; color: #2d6a35; }
.chg-cand .cand-score.medium { background: #ffe5c2; color: #8a4c12; }
.chg-cand .cand-score.low    { background: #ededed; color: #5a5650; }
.chg-cand .cand-link { color: #4878a6; text-decoration: none; font-size: 10px; margin-left: 6px; }
.chg-cand .cand-link:hover { text-decoration: underline; }
.chg-cand .cand-badge-best { background: #84a98c; color: #fff; padding: 1px 6px; border-radius: 4px;
    font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
.chg-cand-actions { display: flex; gap: 6px; margin-top: 4px; }
.chg-cand-actions .chg-btn { font-size: 10.5px; padding: 4px 9px; }

.chg-bien-select { width: 100%; border: 1px solid #e3dfd8; border-radius: 6px; padding: 5px 8px; font-size: 12px; background: #fafafa; }
.chg-type-select { width: auto; border: 1px solid #e3dfd8; border-radius: 6px; padding: 5px 8px; font-size: 12px; background: #fafafa; }

.chg-btn {
    border: none; border-radius: 8px; padding: 7px 14px; cursor: pointer;
    font-size: 12.5px; font-weight: 600; font-family: inherit;
    background: #fff; color: #4878a6;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
}
.chg-btn:hover { box-shadow: 2px 2px 5px #c8c4be, -2px -2px 5px #fff; }
.chg-btn-primary { background: #4878a6; color: #fff; }
.chg-btn-primary:hover { background: #3a6890; }
.chg-btn-validate { background: #2d6a35; color: #fff; }
.chg-btn-validate:hover { background: #225528; }
.chg-btn-sm { padding: 4px 10px; font-size: 11.5px; }

.chg-status { font-size: 11px; padding: 2px 8px; border-radius: 6px; font-weight: 700; }
.chg-status.pending  { background: #e9e6e0; color: #5a5650; }
.chg-status.ok       { background: #d9f0db; color: #2d6a35; }
.chg-status.error    { background: #fbe9e9; color: #a8323b; }
.chg-status.analyse  { background: #ede9fe; color: #5b21b6; }

.chg-filename { font-family: 'DM Mono', monospace; font-size: 12px; word-break: break-all; }
.chg-empty { padding: 60px; text-align: center; color: #7a766f; }

/* Badges IA extraits */
.chg-ia-block { margin-top: 6px; padding: 8px 10px; background: #f9f7ff; border-left: 3px solid #7c3aed; border-radius: 6px; font-size: 11.5px; }
.chg-ia-block .chg-ia-row { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 2px; }
.chg-ia-block .chg-ia-item { font-family: 'DM Mono', monospace; }
.chg-ia-block .chg-ia-item strong { color: #2c2a28; font-weight: 700; }
.chg-ia-block .chg-ia-item .lbl { color: #7a766f; }
.chg-ia-signe { display:inline-block; padding: 1px 7px; border-radius: 99px; font-size: 10px; font-weight: 700; }
.chg-ia-signe.signe     { background: #d9f0db; color: #2d6a35; }
.chg-ia-signe.non_signe { background: #fef3c7; color: #92400e; }
.chg-ia-signe.inconnu   { background: #e9e6e0; color: #5a5650; }

/* ── Modale d'édition bail (2 colonnes : form gauche, PDF droite) ── */
.bail-modal-backdrop { position:fixed; inset:0; background:rgba(40,38,36,.7); display:none; align-items:center; justify-content:center; z-index:9500; }
.bail-modal-backdrop.show { display:flex; }
.bail-modal { background:#fff; border-radius:14px; width:96vw; max-width:1400px; height:92vh; display:flex; overflow:hidden; box-shadow:0 30px 80px rgba(0,0,0,.4); }
.bail-modal-left  { flex: 0 0 55%; overflow-y:auto; padding:22px 26px; background:#fafaf6; }
.bail-modal-right { flex: 1; background:#2c2a28; display:flex; flex-direction:column; }
.bail-modal-right .bail-doc-toolbar { padding:8px 12px; background:#1a1a1a; color:#fff; display:flex; align-items:center; gap:10px; font-size:12px; }
.bail-modal-right iframe { flex: 1; border: none; background: #fff; }
.bail-modal h3 { margin:0 0 4px; font-size:18px; }
.bail-modal h3 + p { color:#7a766f; font-size:11px; margin:0 0 18px; }

.bail-modal-close { position:absolute; top:14px; right:18px; background:transparent; border:none; cursor:pointer; font-size:24px; color:#7a766f; }

.bail-section { background:#fff; border-radius:10px; padding:14px 16px; margin-bottom:12px; box-shadow:0 2px 6px rgba(0,0,0,.04); }
.bail-section h4 { margin:0 0 10px; font-size:13px; color:#2c2a28; display:flex; align-items:center; gap:8px; }
.bail-section h4 small { color:#a8741d; font-weight:400; font-size:10.5px; }
.bail-section-locked { background:#fffaf0; border-left:3px solid #d97706; }
.bail-section .bail-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
.bail-section label { display:block; font-size:11px; font-weight:600; color:#5a5650; margin:6px 0 3px; }
.bail-section input, .bail-section select, .bail-section textarea {
    width:100%; border:1px solid #e3dfd8; border-radius:6px; padding:6px 8px; font-size:12.5px;
    box-sizing:border-box; background:#fafafa; font-family:inherit;
}
.bail-section input[type=checkbox] { width:auto; }
.bail-section textarea { min-height:50px; resize:vertical; }
.bail-section .bail-hint { font-size:10.5px; color:#7a766f; margin-top:4px; font-style:italic; }

.bail-form-actions { position:sticky; bottom:-22px; background:linear-gradient(180deg, transparent 0%, #fafaf6 30%); padding:18px 0 0; margin-top:14px; display:flex; gap:10px; justify-content:flex-end; }
.bail-btn { border:none; border-radius:8px; padding:10px 18px; cursor:pointer; font-size:13px; font-weight:600; font-family:inherit; background:#fff; color:#4878a6; box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff; }
.bail-btn-primary { background:#2d6a35; color:#fff; }
.bail-btn-primary:hover { background:#225528; }
.bail-link-bien { display:inline-block; font-size:11px; color:#4878a6; text-decoration:underline; margin-top:4px; }

/* Liste de cards "Bien rattaché" — priorité affichée via couleur de bordure gauche */
.bail-biens-list { list-style:none; padding:0; margin:0; max-height:280px; overflow-y:auto;
    border:1px solid #e3dfd8; border-radius:8px; background:#fff; }
.bail-bien-card { padding:8px 12px; border-bottom:1px solid #f0ece6; cursor:pointer;
    display:flex; align-items:center; gap:12px; border-left:4px solid #e3dfd8; transition:background .12s; }
.bail-bien-card:hover { background:#fafaf6; }
.bail-bien-card.selected { background:#eef4fb; border-left-width:6px; }
.bail-bien-card.prio-haute    { border-left-color:#fbbf24; }
.bail-bien-card.prio-normale  { border-left-color:#fb923c; }
.bail-bien-card.prio-differee { border-left-color:#ef4444; }
.bbc-ref { font-family:'DM Mono', monospace; font-weight:700; color:#4878a6; font-size:12.5px; }
.bbc-addr { font-size:13px; color:#2c2a28; font-weight:600; line-height:1.2; }
.bbc-ville { font-size:11px; color:#7a766f; font-family:'DM Mono', monospace; margin-top:1px; }
.bbc-score { margin-left:auto; font-weight:700; padding:3px 9px; border-radius:99px; font-size:11px; white-space:nowrap; }
.bbc-score.high { background:#d9f0db; color:#2d6a35; }
.bbc-score.med  { background:#ffe5c2; color:#8a4c12; }
.bbc-score.low  { background:#fbe9e9; color:#a8323b; }
.bbc-empty { padding:18px; text-align:center; color:#9a9690; font-size:12px; font-style:italic; }

/* Mini-modale récap création bien */
.chg-bcreated-backdrop { position:fixed; inset:0; background:rgba(40,38,36,.6); display:none;
    align-items:center; justify-content:center; z-index:9100; }
.chg-bcreated-backdrop.show { display:flex; }
.chg-bcreated-modal { background:#fff; border-radius:14px; padding:24px 26px; width:520px; max-width:92vw;
    box-shadow:0 20px 50px rgba(0,0,0,.3); }
.chg-bcreated-modal h3 { margin:0 0 6px; color:#2d6a35; font-size:18px; }
.chg-bcreated-line { display:flex; gap:8px; padding:7px 0; border-bottom:1px solid #f0ece6; font-size:13px; align-items:baseline; }
.chg-bcreated-line:last-child { border-bottom:none; }
.chg-bcreated-line .lbl { color:#7a766f; font-size:11px; min-width:130px; }
.chg-bcreated-line .val { color:#2c2a28; font-weight:600; }
.chg-bcreated-line .val .ref { font-family:'DM Mono',monospace; color:#4878a6; }
.chg-bcreated-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
.chg-bcreated-tip { background:#eef4fb; border-left:3px solid #4878a6; padding:10px 14px; border-radius:6px;
    font-size:11.5px; color:#2c5687; margin-top:14px; }
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
?>

<div class="chg-wrap">

<div class="chg-hero">
    <h2>📦 Chargement par lot</h2>
    <?php if ($lockBien): ?>
      <p style="background:#e9f7ef;border:1px solid #9ad3ab;border-radius:8px;padding:8px 12px;color:#0b6b35;font-weight:600;">
        🔒 Rattachement <strong>verrouillé</strong> sur le bien
        <strong><?= h($lockBien['reference_bien'] ?: ('#'.$lockBien['id'])) ?></strong>
        <?= $lockBien['adr'] ? ' — ' . h(trim($lockBien['adr'].' '.($lockBien['ville']??''))) : '' ?>.
        Tous les fichiers déposés iront directement sur ce bien (pas de proposition).
      </p>
    <?php else: ?>
      <p>Glisse tous les documents d'un coup (diagnostics, baux, mandats, taxes…). Le système analyse le nom de chaque fichier, propose un rattachement automatique au bon bien, puis tu valides en 1 clic.<br>
      <strong>Aucun document n'est rattaché définitivement sans ta validation.</strong></p>
    <?php endif; ?>
</div>

<div class="chg-uploader">
    <div class="chg-row">
        <label>Type de lot</label>
        <select id="chg-lot-type">
            <option value="mixte">Mixte (auto-détection)</option>
            <option value="DIAG_DPE">Diagnostics</option>
            <option value="BAIL">Baux</option>
            <option value="TAXE_FONCIERE">Taxes foncières</option>
            <option value="MANDAT_VENTE">Mandats</option>
            <option value="PHOTO">Photos</option>
            <option value="PLAN">Plans</option>
        </select>
        <span style="font-size:11px; color:#7a766f;">Si "Mixte" : un type est détecté par fichier via le nom.</span>
    </div>

    <div class="chg-drop" id="chg-drop">
        <div class="icon">⬆️</div>
        <div class="title">Glisse tes fichiers ici, ou clique pour sélectionner</div>
        <div class="sub">PDF, JPG, PNG, WebP — multi-fichiers acceptés</div>
        <input type="file" id="chg-file-input" multiple hidden
               accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.tiff,.doc,.docx">
    </div>
</div>

<div class="chg-results" id="chg-results" style="display:none;">
    <div class="chg-results-head">
        <h3>Propositions de rattachement</h3>
        <span class="stats" id="chg-stats"></span>
        <div class="actions">
            <button class="chg-btn chg-btn-sm" onclick="chgClearAll()">Vider</button>
            <button class="chg-btn chg-btn-sm" onclick="chgAnalyseAll()" title="Analyse IA Claude Vision sur les fichiers PAS encore analysés">🔍 Analyser nouveaux (IA)</button>
            <button class="chg-btn chg-btn-sm" onclick="chgReanalyseAll()" title="Réanalyser TOUS les fichiers (même déjà analysés). Coût ~5ct/doc. Utile après une mise à jour du prompt IA." style="background:#ede9fe;color:#5b21b6;">🔄 Tout réanalyser</button>
            <button class="chg-btn chg-btn-sm" onclick="chgRematchAll()" title="Re-matcher tous les biens à partir des extractions IA déjà faites (gratuit, instantané)">🔁 Re-matcher</button>
            <button class="chg-btn chg-btn-validate" onclick="chgValidateAll()">✅ Tout valider (confiance élevée)</button>
        </div>
    </div>
    <table class="chg-table">
        <thead>
            <tr>
                <th style="width:30%;">Fichier</th>
                <th style="width:15%;">Type détecté</th>
                <th style="width:35%;">Bien proposé</th>
                <th style="width:10%;">Confiance</th>
                <th style="width:10%;">Action</th>
            </tr>
        </thead>
        <tbody id="chg-tbody"></tbody>
    </table>
</div>

</div><!-- /chg-wrap -->

<!-- Mini-modale récap bien créé -->
<div class="chg-bcreated-backdrop" id="chg-bcreated-modal">
    <div class="chg-bcreated-modal" id="chg-bcreated-body"></div>
</div>

<!-- ═════════════════════════════════════════════════════════════
     MODALE ÉDITION BAIL (2 colonnes : form gauche, PDF droite)
═════════════════════════════════════════════════════════════ -->
<div class="bail-modal-backdrop" id="bail-modal">
    <div class="bail-modal">
        <div class="bail-modal-left">
            <button class="bail-modal-close" onclick="chgCloseBail()">×</button>
            <h3>✏️ Enregistrer le bail</h3>
            <p>Valeurs pré-remplies depuis l'analyse IA. Corrige / complète puis enregistre.</p>

            <form id="bail-form">
                <input type="hidden" name="staging_id" id="bail-staging-id">
                <input type="hidden" name="metadata"   id="bail-metadata">

                <!-- Bien + Doc -->
                <div class="bail-section">
                    <h4>🏢 Bien rattaché</h4>
                    <input type="hidden" name="id_bien" id="bail-id-bien" required>
                    <ul id="bail-biens-list" class="bail-biens-list"></ul>
                    <a id="bail-link-bien" target="_blank" class="bail-link-bien">→ Ouvrir la fiche complète du bien</a>
                    <div class="bail-row">
                        <div>
                            <label>Type document</label>
                            <select name="type_document" id="bail-type-doc">
                                <option value="BAIL">Bail</option>
                                <option value="AVENANT">Avenant</option>
                                <option value="MANDAT_LOCATION">Mandat location</option>
                                <option value="COMPROMIS">Compromis</option>
                                <option value="AUTRE">Autre</option>
                            </select>
                        </div>
                        <div>
                            <label>Nature</label>
                            <select name="bail_nature">
                                <option value="commercial">Commercial</option>
                                <option value="habitation">Habitation</option>
                                <option value="professionnel">Professionnel</option>
                                <option value="civil">Civil</option>
                                <option value="terrain">Terrain</option>
                                <option value="parking">Parking</option>
                                <option value="meuble_touristique">Meublé touristique</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Bailleur (entité juridique) -->
                <div class="bail-section">
                    <h4>🏠 Bailleur / Propriétaire</h4>
                    <div class="bail-hint">Extrait du bail. Pré-rempli depuis l'IA. À la sauvegarde, ces champs enrichissent le tiers propriétaire du bien (sans écraser ce qui est déjà rempli).</div>
                    <div class="bail-row">
                        <label style="font-size:12px;"><input type="radio" name="bailleur_type" value="societe" checked> Société</label>
                        <label style="font-size:12px;"><input type="radio" name="bailleur_type" value="physique"> Personne physique</label>
                        <label style="font-size:12px;"><input type="radio" name="bailleur_type" value="indivision"> Indivision</label>
                    </div>
                    <div id="bail-bail-societe">
                        <label>Raison sociale / Nom indivision</label>
                        <input type="text" name="bailleur_raison_sociale" placeholder="GROUPE SIR, SCI…">
                        <div class="bail-row">
                            <div><label>SIREN</label><input type="text" name="bailleur_siren" placeholder="9 chiffres"></div>
                            <div><label>Email</label><input type="email" name="bailleur_email"></div>
                            <div><label>Téléphone</label><input type="text" name="bailleur_telephone"></div>
                        </div>
                    </div>
                    <div id="bail-bail-physique" style="display:none;">
                        <div class="bail-row">
                            <div><label>Nom</label><input type="text" name="bailleur_nom"></div>
                            <div><label>Prénom</label><input type="text" name="bailleur_prenom"></div>
                        </div>
                        <div class="bail-row">
                            <div><label>Email</label><input type="email" name="bailleur_email_phys"></div>
                            <div><label>Téléphone</label><input type="text" name="bailleur_telephone_phys"></div>
                        </div>
                    </div>
                </div>

                <!-- Bailleur (représentant légal) -->
                <div class="bail-section">
                    <h4>👤 Bailleur — représentant légal</h4>
                    <div class="bail-hint">Si la partie bailleur est une société ou indivision : nom du gérant/président/mandataire qui signe.</div>
                    <div class="bail-row">
                        <div><label>Nom du représentant</label><input type="text" name="bailleur_representant_nom" placeholder="ex: Jean DUPONT"></div>
                        <div><label>Qualité</label><input type="text" name="bailleur_representant_qualite" placeholder="gérant, président, mandataire…"></div>
                    </div>
                    <div class="bail-row">
                        <div><label>Email</label><input type="email" name="bailleur_representant_email"></div>
                        <div><label>Téléphone</label><input type="text" name="bailleur_representant_telephone"></div>
                    </div>
                </div>

                <!-- Locataire -->
                <div class="bail-section">
                    <h4>👤 Locataire</h4>
                    <div class="bail-row">
                        <label style="font-size:12px;"><input type="radio" name="locataire_type" value="societe" checked> Société</label>
                        <label style="font-size:12px;"><input type="radio" name="locataire_type" value="physique"> Personne physique</label>
                    </div>
                    <div id="bail-loc-societe">
                        <label>Raison sociale</label>
                        <input type="text" name="locataire_raison_sociale">
                        <div class="bail-row">
                            <div><label>SIREN</label><input type="text" name="locataire_siren"></div>
                            <div><label>Email</label><input type="email" name="locataire_email"></div>
                            <div><label>Téléphone</label><input type="text" name="locataire_telephone"></div>
                        </div>
                    </div>
                    <div id="bail-loc-physique" style="display:none;">
                        <div class="bail-row">
                            <div><label>Nom</label><input type="text" name="locataire_nom"></div>
                            <div><label>Prénom</label><input type="text" name="locataire_prenom"></div>
                        </div>
                    </div>
                </div>

                <!-- Locataire (représentant) -->
                <div class="bail-section">
                    <h4>👥 Locataire — représentant légal</h4>
                    <div class="bail-hint">Si le locataire est une société : nom du gérant/président qui signe.</div>
                    <div class="bail-row">
                        <div><label>Nom du représentant</label><input type="text" name="locataire_representant_nom"></div>
                        <div><label>Qualité</label><input type="text" name="locataire_representant_qualite"></div>
                    </div>
                    <div class="bail-row">
                        <div><label>Email</label><input type="email" name="locataire_representant_email"></div>
                        <div><label>Téléphone</label><input type="text" name="locataire_representant_telephone"></div>
                    </div>
                </div>

                <!-- Dates -->
                <div class="bail-section">
                    <h4>📅 Dates</h4>
                    <div class="bail-row">
                        <div><label>Signature</label><input type="date" name="date_signature"></div>
                        <div><label>Prise d'effet</label><input type="date" name="date_prise_effet"></div>
                    </div>
                    <div class="bail-row">
                        <div><label>Durée (mois)</label><input type="number" name="duree_mois"></div>
                        <div><label>Date fin</label><input type="date" name="date_fin"></div>
                        <div><label>Reconduction</label><input type="text" name="reconduction" placeholder="tacite, etc."></div>
                    </div>
                </div>

                <!-- Loyer DE BASE -->
                <div class="bail-section bail-section-locked">
                    <h4>💶 Loyer DE BASE & charges <small>— référence à la signature, immuable</small></h4>
                    <div class="bail-hint">Les loyers réels mensuels seront tracés par les CRG (module futur).</div>
                    <div class="bail-row">
                        <div><label>Loyer mensuel HC (€)</label><input type="number" step="0.01" name="loyer_mensuel_hc"></div>
                        <div><label>Complément loyer (€)</label><input type="number" step="0.01" name="complement_loyer"></div>
                    </div>
                    <div class="bail-row">
                        <div><label>Charges mensuelles (€)</label><input type="number" step="0.01" name="charges_mensuelles"></div>
                        <div>
                            <label>Type charges</label>
                            <select name="charges_type">
                                <option value="provisions">Provisions</option>
                                <option value="forfait">Forfait</option>
                            </select>
                        </div>
                    </div>
                    <div class="bail-row">
                        <div><label><input type="checkbox" name="tva_applicable" value="1"> TVA applicable</label></div>
                        <div><label>Taux TVA (%)</label><input type="number" step="0.01" name="tva_taux"></div>
                    </div>
                </div>

                <!-- Indice -->
                <div class="bail-section">
                    <h4>📊 Indice de révision</h4>
                    <div class="bail-row">
                        <div>
                            <label>Type</label>
                            <select name="indice_type">
                                <option value="">—</option>
                                <option value="ILC">ILC</option>
                                <option value="ILAT">ILAT</option>
                                <option value="IRL">IRL</option>
                                <option value="ICC">ICC</option>
                            </select>
                        </div>
                        <div><label>Trimestre</label><input type="text" name="indice_trimestre" placeholder="ex: 2T2024"></div>
                        <div><label>Valeur</label><input type="number" step="0.001" name="indice_valeur"></div>
                    </div>
                </div>

                <!-- Garanties -->
                <div class="bail-section">
                    <h4>🛡️ Garanties</h4>
                    <div class="bail-row">
                        <div><label>Dépôt garantie (€)</label><input type="number" step="0.01" name="depot_garantie"></div>
                        <div><label>Nb termes</label><input type="number" name="nb_termes_garantie"></div>
                        <div>
                            <label>Caution</label>
                            <select name="caution_type">
                                <option value="aucune">Aucune</option>
                                <option value="physique">Personne physique</option>
                                <option value="visale">Visale</option>
                                <option value="assurance">Assurance</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Honoraires -->
                <div class="bail-section">
                    <h4>💼 Honoraires</h4>
                    <div class="bail-row">
                        <div><label>Bailleur TTC (€)</label><input type="number" step="0.01" name="honoraires_bailleur_ttc"></div>
                        <div><label>Locataire TTC (€)</label><input type="number" step="0.01" name="honoraires_locataire_ttc"></div>
                        <div>
                            <label>À charge</label>
                            <select name="honoraires_charge">
                                <option value="">—</option>
                                <option value="bailleur">Bailleur</option>
                                <option value="locataire">Locataire</option>
                                <option value="partage">Partagé</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Caractéristiques du bien extraites (propagées vers `biens`) -->
                <div class="bail-section" style="background:#eff6ff; border-left:3px solid #4878a6;">
                    <h4>📐 Caractéristiques du bien <small style="color:#4878a6; font-weight:400;">— extraites du bail, complètent la fiche `biens`</small></h4>
                    <div class="bail-hint">Ces champs viennent du bail mais décrivent le BIEN. À la sauvegarde, ils enrichissent la fiche bien (sans écraser ce qui est déjà rempli).</div>
                    <div class="bail-row">
                        <div><label>N° lot copropriété</label><input type="text" name="bien_numero_lot_copro" placeholder="ex: 170"></div>
                        <div><label>Quote-part (%)</label><input type="number" step="0.01" name="bien_quote_part_copro_pct" placeholder="ex: 5.89"></div>
                        <div><label>Étage</label><input type="text" name="bien_etage" placeholder="RDC, 1, 2…"></div>
                    </div>
                    <div class="bail-row">
                        <div><label>Surface totale (m²)</label><input type="number" step="0.01" name="bien_surface_totale_m2"></div>
                        <div><label>Nb parkings</label><input type="number" name="bien_nb_parkings"></div>
                        <div>
                            <label>Usage</label>
                            <select name="bien_destination_usage">
                                <option value="">—</option>
                                <option value="commercial">Commercial</option>
                                <option value="professionnel">Professionnel</option>
                                <option value="habitation">Habitation</option>
                                <option value="mixte">Mixte</option>
                            </select>
                        </div>
                    </div>
                    <label>Détail surfaces</label>
                    <input type="text" name="bien_surfaces_detail" placeholder="ex: 495 m² RDC + 70 m² 1er étage = 565 m²">
                    <label>Description du bien (extrait du bail)</label>
                    <textarea name="bien_description" rows="2" placeholder="Composition, agencement, équipements…"></textarea>
                </div>

                <!-- Assurance refondue : 2 sens distincts -->
                <div class="bail-section">
                    <h4>🛡️ Assurance — clauses de renonciation</h4>
                    <div class="bail-hint">Distinguer la renonciation UNILATÉRALE (un seul sens) de la RÉCIPROQUE (les 2 sens). Souvent le bail dit "le LOCATAIRE renonce à recours" sans clause symétrique.</div>

                    <div style="margin-top:8px;">
                        <strong style="font-size:11.5px; color:#5a5650;">👥 Le LOCATAIRE renonce à se retourner contre le BAILLEUR :</strong>
                        <div class="bail-row" style="margin-top:4px;">
                            <label style="font-size:12px;"><input type="radio" name="renonciation_recours_locataire" value="1"> ✓ OUI</label>
                            <label style="font-size:12px;"><input type="radio" name="renonciation_recours_locataire" value="0"> ✗ NON</label>
                            <label style="font-size:12px;"><input type="radio" name="renonciation_recours_locataire" value="" checked> ? n/a</label>
                        </div>
                    </div>

                    <div style="margin-top:10px;">
                        <strong style="font-size:11.5px; color:#5a5650;">🏠 Le BAILLEUR renonce à se retourner contre le LOCATAIRE :</strong>
                        <div class="bail-row" style="margin-top:4px;">
                            <label style="font-size:12px;"><input type="radio" name="renonciation_recours_bailleur" value="1"> ✓ OUI</label>
                            <label style="font-size:12px;"><input type="radio" name="renonciation_recours_bailleur" value="0"> ✗ NON</label>
                            <label style="font-size:12px;"><input type="radio" name="renonciation_recours_bailleur" value="" checked> ? n/a</label>
                        </div>
                    </div>

                    <div class="bail-row" style="margin-top:12px;">
                        <div>
                            <label>Surprimes (activité) à charge de</label>
                            <select name="assurance_surprimes_a_charge">
                                <option value="">—</option>
                                <option value="locataire">Locataire</option>
                                <option value="bailleur">Bailleur</option>
                                <option value="partage">Partagé</option>
                            </select>
                        </div>
                        <div>
                            <label>Justification annuelle obligatoire ?</label>
                            <select name="assurance_justification_annuelle">
                                <option value="">—</option>
                                <option value="1">✓ Oui</option>
                                <option value="0">✗ Non</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Conditions particulières -->
                <div class="bail-section">
                    <h4>📋 Conditions particulières</h4>
                    <div class="bail-hint">Clauses spécifiques (autoconsommation, sous-location, exclusivité, droits de préemption, garants spécifiques, etc.)</div>
                    <textarea name="conditions_particulieres" rows="4" placeholder="Texte intégral des clauses particulières du bail…"></textarea>
                </div>

                <!-- Notes -->
                <div class="bail-section">
                    <h4>📝 Statut & commentaire</h4>
                    <div class="bail-row">
                        <div>
                            <label>Statut</label>
                            <select name="statut">
                                <option value="actif">Actif</option>
                                <option value="brouillon">Brouillon</option>
                                <option value="resilie">Résilié</option>
                                <option value="expire">Expiré</option>
                            </select>
                        </div>
                    </div>
                    <label>Commentaire</label>
                    <textarea name="commentaire" rows="2"></textarea>
                </div>

                <div class="bail-form-actions">
                    <button type="button" class="bail-btn" onclick="chgCloseBail()">Annuler</button>
                    <button type="submit" class="bail-btn bail-btn-primary">💾 Enregistrer le bail + classer le doc</button>
                </div>
            </form>
        </div>
        <div class="bail-modal-right">
            <div class="bail-doc-toolbar">
                <span id="bail-doc-name">document</span>
                <span style="margin-left:auto;">
                    <a id="bail-doc-download" class="bail-link-bien" style="color:#fff;" target="_blank">↓ Ouvrir dans un onglet</a>
                </span>
            </div>
            <iframe id="bail-doc-iframe" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
// ── Ouverture / fermeture modale bail ──────────────────────────
function chgCloseBail() { document.getElementById('bail-modal').classList.remove('show'); }

async function chgOpenBailModal(item) {
    if (!item.ia_data) { alert('Lance d\'abord l\'analyse IA sur ce document.'); return; }

    // 1. Aperçu PDF/image à droite
    const viewUrl = APP_BASE + '/api/transaction_chg_staging_view.php?id=' + item.staging_id;
    document.getElementById('bail-doc-iframe').src = viewUrl;
    document.getElementById('bail-doc-download').href = viewUrl;
    document.getElementById('bail-doc-name').textContent = item.filename || 'document';

    // 2. Charge la liste de cards (id en réf, adresse ligne 1, ville ligne 2, couleur priorité)
    const idBienInput = document.getElementById('bail-id-bien');
    const list = document.getElementById('bail-biens-list');
    list.innerHTML = '';
    const biens = item.biens || [];
    if (biens.length === 0) {
        list.innerHTML = '<li class="bbc-empty">Aucun bien trouvé — clique "➕ Créer bien" depuis le tableau.</li>';
        idBienInput.value = '';
    } else {
        biens.forEach((b, idx) => {
            const li = document.createElement('li');
            const prio = b.priorite_vente || '';
            li.className = 'bail-bien-card' + (prio ? ' prio-' + prio : '');
            const isSel = (b.id === item.selectedBienId) || (idx === 0 && !item.selectedBienId);
            if (isSel) li.classList.add('selected');
            const score = b.score || 0;
            const scoreClass = score >= 70 ? 'high' : (score >= 40 ? 'med' : 'low');
            const villeStr = [b.code_postal, b.ville].filter(Boolean).join(' ');
            const refIsAuto = /^(TMP|AUTO)-/i.test(b.reference_bien || '');
            const metaParts = [];
            if (b.reference_bien) metaParts.push('<span style="color:#4878a6; font-family:DM Mono,monospace;">' + escapeHtml(b.reference_bien) + (refIsAuto ? ' <em style="color:#a8741d;">(brouillon récent)</em>' : '') + '</span>');
            if (b.proprio_nom)        metaParts.push('🏠 ' + escapeHtml(b.proprio_nom));
            if (b.surface_habitable)  metaParts.push('📐 ' + escapeHtml(String(b.surface_habitable)) + ' m²');
            if (b.numero_lot)         metaParts.push('🏢 Lot ' + escapeHtml(String(b.numero_lot)));

            li.innerHTML = '<div style="flex:1; min-width:0;">'
                         + '<div class="bbc-ref">#' + b.id + (refIsAuto ? ' <span style="background:#fef3c7; color:#92400e; font-size:9px; padding:1px 5px; border-radius:3px; margin-left:4px;">RÉCENT</span>' : '') + '</div>'
                         + '<div class="bbc-addr">' + escapeHtml(b.adresse_1 || '—') + '</div>'
                         + '<div class="bbc-ville">' + escapeHtml(villeStr || '—') + '</div>'
                         + (metaParts.length ? '<div style="font-size:10.5px; color:#5a5650; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap;">' + metaParts.join('') + '</div>' : '')
                         + '</div>'
                         + '<div class="bbc-score ' + scoreClass + '">' + score + '%</div>';
            li.onclick = () => {
                idBienInput.value = b.id;
                item.selectedBienId = b.id;
                list.querySelectorAll('.bail-bien-card').forEach(c => c.classList.remove('selected'));
                li.classList.add('selected');
                document.getElementById('bail-link-bien').href = APP_BASE + '/bien_detail.php?edit=' + b.id;
            };
            list.appendChild(li);
            if (isSel) {
                idBienInput.value = b.id;
                item.selectedBienId = b.id;
            }
        });
    }
    // Lien fiche bien selon sélection courante
    document.getElementById('bail-link-bien').href = idBienInput.value ? (APP_BASE + '/bien_detail.php?edit=' + idBienInput.value) : '#';

    // 3. Préremplit le form depuis ia_data
    const d = item.ia_data || {};
    const f = document.getElementById('bail-form');
    document.getElementById('bail-staging-id').value = item.staging_id;
    document.getElementById('bail-metadata').value = JSON.stringify(d);

    const setVal = (name, val) => {
        const el = f.querySelector('[name="' + name + '"]');
        if (!el) return;
        if (el.type === 'checkbox') el.checked = !!val;
        else el.value = (val === null || val === undefined) ? '' : val;
    };
    const setRadio = (name, val) => {
        f.querySelectorAll('[name="' + name + '"]').forEach(r => r.checked = (r.value === val));
    };

    // Type doc mapping
    const tMap = {
        'bail_commercial': 'BAIL', 'bail_habitation': 'BAIL', 'avenant': 'AVENANT',
        'mandat_vente': 'MANDAT_VENTE', 'mandat_location': 'MANDAT_LOCATION',
        'compromis': 'COMPROMIS',
    };
    setVal('type_document', tMap[d.type_doc] || item.type || 'BAIL');

    // Nature mapping
    if (d.type_doc === 'bail_commercial')     setVal('bail_nature', 'commercial');
    else if (d.type_doc === 'bail_habitation')setVal('bail_nature', 'habitation');

    // Locataire
    setRadio('locataire_type', d.locataire_forme === 'personne_physique' ? 'physique' : 'societe');
    chgBailToggleLocType();
    if (d.locataire_forme === 'personne_physique') {
        const parts = (d.locataire || '').split(' ');
        if (parts.length >= 2) { setVal('locataire_prenom', parts[0]); setVal('locataire_nom', parts.slice(1).join(' ')); }
        else setVal('locataire_nom', d.locataire || '');
    } else {
        setVal('locataire_raison_sociale', d.locataire || '');
    }
    setVal('locataire_siren', d.locataire_siren || '');

    // Bailleur (entité)
    let bailType = 'societe';
    if (d.proprietaire_forme === 'personne_physique') bailType = 'physique';
    if ((d.proprietaire || '').toLowerCase().includes('indivision')) bailType = 'indivision';
    setRadio('bailleur_type', bailType);
    chgBailToggleBailType();
    if (bailType === 'physique') {
        const parts = (d.proprietaire || '').split(' ');
        if (parts.length >= 2) { setVal('bailleur_prenom', parts[0]); setVal('bailleur_nom', parts.slice(1).join(' ')); }
        else setVal('bailleur_nom', d.proprietaire || '');
        setVal('bailleur_email_phys', '');
        setVal('bailleur_telephone_phys', '');
    } else {
        setVal('bailleur_raison_sociale', d.proprietaire || '');
        setVal('bailleur_siren', d.proprietaire_siren || '');
    }

    // Dates
    setVal('date_signature',  d.date_signature || '');
    setVal('date_prise_effet',d.date_debut_bail || '');
    setVal('duree_mois',      d.duree_mois || '');
    setVal('date_fin',        d.date_fin_bail || '');

    // Loyer (annuel → mensuel si mensuel absent)
    let loyerMensuel = d.loyer_mensuel_ht;
    if (!loyerMensuel && d.loyer_annuel_ht) loyerMensuel = Math.round((parseFloat(d.loyer_annuel_ht) / 12) * 100) / 100;
    setVal('loyer_mensuel_hc',   loyerMensuel || '');
    setVal('complement_loyer',   d.complement_loyer || '');
    let chargesMens = d.charges_mensuelles;
    if (!chargesMens && d.charges_annuelles) chargesMens = Math.round((parseFloat(d.charges_annuelles) / 12) * 100) / 100;
    setVal('charges_mensuelles', chargesMens || '');
    setVal('tva_applicable',     d.tva_applicable === true || d.tva_applicable === 'true');
    setVal('tva_taux',           d.tva_applicable ? '20' : '');

    // Indice
    setVal('indice_type',     d.indice_revision || '');
    setVal('indice_trimestre',d.indice_reference_trimestre || '');
    setVal('indice_valeur',   d.indice_reference_valeur || '');

    // Garanties
    setVal('depot_garantie',    d.depot_garantie || '');
    // Calcul nb termes : DG / loyer mensuel arrondi
    if (d.depot_garantie && loyerMensuel) {
        setVal('nb_termes_garantie', Math.round(parseFloat(d.depot_garantie) / parseFloat(loyerMensuel)));
    }

    // Représentants (préremplis depuis IA)
    setVal('bailleur_representant_nom',        d.bailleur_representant_nom || '');
    setVal('bailleur_representant_qualite',    d.bailleur_representant_qualite || '');
    setVal('bailleur_representant_email',      d.bailleur_representant_email || '');
    setVal('bailleur_representant_telephone',  d.bailleur_representant_telephone || '');
    setVal('locataire_representant_nom',       d.locataire_representant_nom || '');
    setVal('locataire_representant_qualite',   d.locataire_representant_qualite || '');
    setVal('locataire_representant_email',     d.locataire_representant_email || '');
    setVal('locataire_representant_telephone', d.locataire_representant_telephone || '');

    // Assurance refondue (3 champs distincts + 2 metadata)
    const triBool = v => (v === true || v === 'true' || v === 1) ? '1'
                       : (v === false || v === 'false' || v === 0) ? '0' : '';
    setRadio('renonciation_recours_locataire', triBool(d.renonciation_recours_locataire));
    setRadio('renonciation_recours_bailleur',  triBool(d.renonciation_recours_bailleur));
    setVal('assurance_surprimes_a_charge', d.assurance_surprimes_a_charge || '');
    setVal('assurance_justification_annuelle', triBool(d.assurance_justification_annuelle));

    // Caractéristiques du bien (propagées vers `biens` à la sauvegarde)
    setVal('bien_numero_lot_copro',    d.bien_numero_lot_copro     || '');
    setVal('bien_quote_part_copro_pct',d.bien_quote_part_copro_pct || '');
    setVal('bien_etage',               d.bien_etage                || '');
    setVal('bien_surface_totale_m2',   d.bien_surface_totale_m2    || '');
    setVal('bien_nb_parkings',         d.bien_nb_parkings          || '');
    setVal('bien_destination_usage',   d.bien_destination_usage    || '');
    setVal('bien_surfaces_detail',     d.bien_surfaces_detail      || '');
    setVal('bien_description',         d.bien_description          || '');

    // Conditions particulières
    setVal('conditions_particulieres', d.conditions_particulieres || '');

    // Notes
    setVal('statut', d.signature_status === 'signe' ? 'actif' : 'brouillon');
    setVal('commentaire', d.notes || '');

    document.getElementById('bail-modal').classList.add('show');
}

function chgBailToggleLocType() {
    const f = document.getElementById('bail-form');
    const type = f.querySelector('[name="locataire_type"]:checked').value;
    document.getElementById('bail-loc-societe').style.display  = type === 'societe'  ? 'block' : 'none';
    document.getElementById('bail-loc-physique').style.display = type === 'physique' ? 'block' : 'none';
}
document.querySelectorAll('[name="locataire_type"]').forEach(r => r.addEventListener('change', chgBailToggleLocType));

function chgBailToggleBailType() {
    const f = document.getElementById('bail-form');
    const type = f.querySelector('[name="bailleur_type"]:checked').value;
    document.getElementById('bail-bail-societe').style.display  = type === 'physique' ? 'none' : 'block';
    document.getElementById('bail-bail-physique').style.display = type === 'physique' ? 'block' : 'none';
}
document.querySelectorAll('[name="bailleur_type"]').forEach(r => r.addEventListener('change', chgBailToggleBailType));

document.getElementById('bail-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    try {
        const res = await fetch(APP_BASE + '/api/transaction_bail_save.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            // Le bail est créé, le doc est classé, la ligne staging supprimée → on retire l'item du tableau
            const sid = parseInt(document.getElementById('bail-staging-id').value, 10);
            chgItems = chgItems.filter(x => x.staging_id !== sid);
            chgCloseBail();
            renderResults();
            const links = (data.links_created || []).join('\n  ');
            alert('✅ Bail #' + data.bail_id + ' enregistré'
                + (data.doc_id ? '\n📎 Doc #' + data.doc_id + ' classé en GED' : '')
                + (links ? '\n\nLiens créés :\n  ' + links : ''));
        } else {
            alert('Erreur : ' + (data.error || 'inconnue'));
        }
    } catch (err) {
        alert('Erreur réseau : ' + (err.message || err));
    }
});
</script>

<script>
const APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;
const CHG_LOCK_BIEN = <?= $lockBien ? json_encode(['id'=>(int)$lockBien['id'],'reference_bien'=>($lockBien['reference_bien'] ?: ('#'.$lockBien['id'])),'score'=>100]) : 'null' ?>;

// ── Heuristiques V0 ────────────────────────────────────────
// Type détecté via mots-clés du nom de fichier
const TYPE_PATTERNS = [
    { re: /\b(dpe|diagnostic[_\- ]?dpe)\b/i,             type: 'DIAG_DPE',         label: 'Diagnostic DPE' },
    { re: /\b(amiante)\b/i,                               type: 'DIAG_AMIANTE',     label: 'Diagnostic amiante' },
    { re: /\b(plomb|crep)\b/i,                            type: 'DIAG_PLOMB',       label: 'Diagnostic plomb' },
    { re: /\b(termites?)\b/i,                             type: 'DIAG_TERMITES',    label: 'Diagnostic termites' },
    { re: /\b(electric|elec)\b/i,                         type: 'DIAG_ELEC',        label: 'Diagnostic électricité' },
    { re: /\b(gaz)\b/i,                                   type: 'DIAG_GAZ',         label: 'Diagnostic gaz' },
    { re: /\b(erp|risque|pollution|etat[_\- ]?risque)\b/i, type: 'DIAG_ERP',         label: 'État des risques' },
    { re: /\b(diag(nostic)?s?)\b/i,                       type: 'DIAG_DPE',         label: 'Diagnostic' },
    { re: /\b(bail|location[_\- ]?contrat)\b/i,           type: 'BAIL',             label: 'Bail' },
    { re: /\b(mandat[_\- ]?vente|mandat[_\- ]?v\b)/i,     type: 'MANDAT_VENTE',     label: 'Mandat de vente' },
    { re: /\b(mandat[_\- ]?location|mandat[_\- ]?loc)/i,  type: 'MANDAT_LOCATION',  label: 'Mandat de location' },
    { re: /\b(mandat)\b/i,                                type: 'MANDAT_VENTE',     label: 'Mandat' },
    { re: /\b(taxe[_\- ]?fonci|tf\b)/i,                   type: 'TAXE_FONCIERE',    label: 'Taxe foncière' },
    { re: /\b(plan)\b/i,                                  type: 'PLAN',             label: 'Plan' },
    { re: /\b(compromis)\b/i,                             type: 'COMPROMIS',        label: 'Compromis' },
    { re: /\b(offre[_\- ]?achat)\b/i,                     type: 'OFFRE_ACHAT',      label: 'Offre d\'achat' },
    { re: /\b(acte[_\- ]?authentique|acte[_\- ]?vente)\b/i, type: 'ACTE_AUTHENTIQUE', label: 'Acte authentique' },
    { re: /\.(jpe?g|png|webp|heic|tiff)$/i,               type: 'PHOTO',            label: 'Photo' },
];

function detectType(filename, lotType) {
    if (lotType && lotType !== 'mixte') {
        const found = TYPE_PATTERNS.find(p => p.type === lotType);
        return found || { type: lotType, label: lotType };
    }
    for (const p of TYPE_PATTERNS) {
        if (p.re.test(filename)) return p;
    }
    return { type: 'AUTRE', label: 'Autre' };
}

// ── État local : reload-safe (staging serveur) ─────────────────
// Chaque item contient un staging_id côté BDD. Pas de Blob côté JS (le fichier
// vit sur le serveur dès le drop), donc un F5 ne perd plus la pile.
let chgItems = [];  // [{ staging_id, filename, type, biens, selectedBienId, confidence, status, ia_data, ... }]

// ── Restauration au page load ──────────────────────────────────
window.addEventListener('DOMContentLoaded', chgRestoreFromServer);

async function chgRestoreFromServer() {
    try {
        const res = await fetch(APP_BASE + '/api/transaction_chg_staging_list.php');
        const data = await res.json();
        if (!data.ok || !Array.isArray(data.items)) return;
        chgItems = data.items.map(it => {
            const meta = it.metadata || {};
            return {
                staging_id: it.staging_id,
                filename:   it.filename,
                mime_type:  it.mime_type,
                size_bytes: it.size_bytes,
                type:       meta.type || meta.detected_type || 'AUTRE',
                detected_type: meta.detected_type || 'AUTRE',
                biens:      meta.match_biens || [],
                selectedBienId: meta.selected_bien_id || null,
                confidence: meta.confidence || 0,
                status:     meta.status || 'pending',
                ia_data:    meta.ia_data || null,
                error:      meta.error || null,
            };
        });
        renderResults();
    } catch (e) { console.error('[restore]', e); }
}

// ── Persistance metadata côté serveur après chaque modif ───────
async function chgPersist(item, patch) {
    try {
        const fd = new FormData();
        fd.append('staging_id', item.staging_id);
        fd.append('metadata', JSON.stringify(patch));
        await fetch(APP_BASE + '/api/transaction_chg_staging_update.php', { method:'POST', body: fd });
    } catch (e) { /* silencieux */ }
}

// ── Drag & drop ────────────────────────────────────────────
const drop = document.getElementById('chg-drop');
const fileInput = document.getElementById('chg-file-input');
drop.addEventListener('click', () => fileInput.click());
drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragover'); });
drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
drop.addEventListener('drop', e => {
    e.preventDefault(); drop.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', e => handleFiles(e.target.files));

async function handleFiles(fileList) {
    const lotType = document.getElementById('chg-lot-type').value;
    for (const f of fileList) {
        const detected = detectType(f.name, lotType);
        // Upload immédiat en staging serveur
        const fd = new FormData();
        fd.append('fichier', f);
        fd.append('detected_type', detected.type);
        try {
            const res  = await fetch(APP_BASE + '/api/transaction_chg_staging_add.php', { method:'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { alert('Upload échoué pour ' + f.name + ' : ' + (data.error || '?')); continue; }
            const item = {
                staging_id: data.staging_id,
                filename:   data.filename,
                mime_type:  data.mime_type,
                size_bytes: data.size_bytes,
                type:       detected.type,
                detected_type: detected.type,
                typeLabel:  detected.label,
                biens:      [], selectedBienId: null,
                confidence: 0,
                status:     'pending',
                ia_data:    null,
                error:      null,
            };
            chgItems.push(item);
            renderResults();
            // Auto-recherche bien par nom (rapide, pas d'IA)
            await chgAnalyseFilename(item);
            renderResults();
        } catch (e) { alert('Erreur upload : ' + (e.message || e)); }
    }
}

async function chgAnalyseFilename(item) {
    // Bien verrouillé : on force le rattachement, pas de matching ni proposition.
    if (CHG_LOCK_BIEN) {
        item.biens = [CHG_LOCK_BIEN];
        item.selectedBienId = CHG_LOCK_BIEN.id;
        item.confidence = 100;
        chgPersist(item, { match_biens: item.biens, selected_bien_id: item.selectedBienId, confidence: 100 });
        return;
    }
    const base = item.filename.replace(/\.[^.]+$/, '');
    const tokens = base.split(/[_\-\s\.]+/).filter(t => t.length >= 3).slice(0, 6);
    if (tokens.length === 0) return;
    const q = tokens.join(' ');
    try {
        const res = await fetch(APP_BASE + '/api/transaction_chargement_match.php?q=' + encodeURIComponent(q));
        const data = await res.json();
        item.biens = data.items || [];
        if (item.biens.length > 0) {
            item.selectedBienId = item.biens[0].id;
            item.confidence = item.biens[0].score || 0;
        } else {
            item.confidence = 0;
        }
        chgPersist(item, {
            match_biens: item.biens, selected_bien_id: item.selectedBienId,
            confidence: item.confidence,
        });
    } catch (e) { item.confidence = 0; }
}

// ── Rendu UI ───────────────────────────────────────────────
function renderResults() {
    const wrap = document.getElementById('chg-results');
    const tbody = document.getElementById('chg-tbody');
    if (chgItems.length === 0) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';

    const nbHigh   = chgItems.filter(i => i.confidence > 85 && i.status === 'pending').length;
    const nbMed    = chgItems.filter(i => i.confidence >= 50 && i.confidence <= 85 && i.status === 'pending').length;
    const nbLow    = chgItems.filter(i => i.confidence < 50 && i.status === 'pending').length;
    const nbOk     = chgItems.filter(i => i.status === 'ok').length;
    document.getElementById('chg-stats').textContent =
        chgItems.length + ' fichier(s) · ' + nbHigh + ' confiance élevée · ' + nbMed + ' moyenne · ' + nbLow + ' à associer · ' + nbOk + ' validés';

    tbody.innerHTML = '';
    chgItems.forEach((item, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.id = item.id;

        const confClass = item.confidence > 85 ? 'high' : (item.confidence >= 50 ? 'medium' : 'low');

        // Cellule fichier
        let html = '<td><span class="chg-filename">' + escapeHtml(item.filename) + '</span>';
        if (item.status === 'ok')      html += ' <span class="chg-status ok">✅ Validé</span>';
        if (item.status === 'error')   html += ' <span class="chg-status error">❌ ' + escapeHtml(item.error || 'erreur') + '</span>';
        if (item.status === 'analyse') html += ' <span class="chg-status analyse">🔍 Analyse IA…</span>';

        // Bloc IA si analyse faite
        if (item.ia_data) {
            const d = item.ia_data;
            const fmtDate = s => s ? s : '—';
            const fmtEur  = n => (n && !isNaN(n)) ? new Intl.NumberFormat('fr-FR').format(n) + ' €' : '—';
            const sign = d.signature_status || 'inconnu';
            const signLabel = sign === 'signe' ? '✓ Signé' : (sign === 'non_signe' ? '✗ Non signé' : '? Inconnu');
            html += '<div class="chg-ia-block">'
                  + '<div><strong>' + escapeHtml(d.type_doc_label || d.type_doc || 'document') + '</strong> '
                  + '<span class="chg-ia-signe ' + sign + '">' + signLabel + '</span></div>'
                  + '<div class="chg-ia-row">'
                  + (d.proprietaire    ? '<span class="chg-ia-item"><span class="lbl">Bailleur:</span> <strong>'+escapeHtml(d.proprietaire)+'</strong></span>' : '')
                  + (d.locataire       ? '<span class="chg-ia-item"><span class="lbl">Locataire:</span> <strong>'+escapeHtml(d.locataire)+'</strong></span>' : '')
                  + (d.adresse_bien    ? '<span class="chg-ia-item"><span class="lbl">📍</span> '+escapeHtml(d.adresse_bien)+(d.code_postal?' '+escapeHtml(d.code_postal):'')+(d.ville?' '+escapeHtml(d.ville):'')+'</span>' : '')
                  + (d.date_debut_bail ? '<span class="chg-ia-item"><span class="lbl">Début bail:</span> <strong>'+fmtDate(d.date_debut_bail)+'</strong></span>' : '')
                  + (d.date_fin_bail   ? '<span class="chg-ia-item"><span class="lbl">Fin bail:</span> <strong>'+fmtDate(d.date_fin_bail)+'</strong></span>' : '')
                  + (d.loyer_annuel_ht ? '<span class="chg-ia-item"><span class="lbl">Loyer/an HT:</span> <strong>'+fmtEur(d.loyer_annuel_ht)+'</strong></span>' : '')
                  + (d.charges_annuelles ? '<span class="chg-ia-item"><span class="lbl">Charges/an:</span> <strong>'+fmtEur(d.charges_annuelles)+'</strong></span>' : '')
                  + (d.depot_garantie  ? '<span class="chg-ia-item"><span class="lbl">DG:</span> <strong>'+fmtEur(d.depot_garantie)+'</strong></span>' : '')
                  + (d.surface_m2      ? '<span class="chg-ia-item"><span class="lbl">Surface:</span> <strong>'+escapeHtml(String(d.surface_m2))+' m²</strong></span>' : '')
                  + (d.indice_revision ? '<span class="chg-ia-item"><span class="lbl">Indice:</span> <strong>'+escapeHtml(d.indice_revision)+'</strong></span>' : '')
                  + (d.numero_mandat   ? '<span class="chg-ia-item"><span class="lbl">N° mandat:</span> <strong>'+escapeHtml(d.numero_mandat)+'</strong></span>' : '')
                  + (d.exclusif === true  ? '<span class="chg-ia-item"><strong>EXCLUSIF</strong></span>' : '')
                  + (d.bailleur_representant_nom ? '<span class="chg-ia-item"><span class="lbl">👤 Repr. bailleur:</span> <strong>'+escapeHtml(d.bailleur_representant_nom)+'</strong>'+(d.bailleur_representant_qualite ? ' ('+escapeHtml(d.bailleur_representant_qualite)+')' : '')+'</span>' : '')
                  + (d.locataire_representant_nom ? '<span class="chg-ia-item"><span class="lbl">👥 Repr. locataire:</span> <strong>'+escapeHtml(d.locataire_representant_nom)+'</strong>'+(d.locataire_representant_qualite ? ' ('+escapeHtml(d.locataire_representant_qualite)+')' : '')+'</span>' : '')
                  // Caractéristiques du bien
                  + (d.bien_numero_lot_copro ? '<span class="chg-ia-item" style="background:#eff6ff; padding:1px 6px; border-radius:6px;"><span class="lbl">🏢 Lot:</span> <strong>'+escapeHtml(d.bien_numero_lot_copro)+'</strong>'+(d.bien_quote_part_copro_pct ? ' ('+d.bien_quote_part_copro_pct+'%)' : '')+'</span>' : '')
                  + (d.bien_nb_parkings ? '<span class="chg-ia-item" style="background:#eff6ff; padding:1px 6px; border-radius:6px;"><span class="lbl">🅿️ Parkings:</span> <strong>'+escapeHtml(String(d.bien_nb_parkings))+'</strong></span>' : '')
                  + (d.bien_etage ? '<span class="chg-ia-item" style="background:#eff6ff; padding:1px 6px; border-radius:6px;"><span class="lbl">📶 Étage:</span> <strong>'+escapeHtml(String(d.bien_etage))+'</strong></span>' : '')
                  // Assurance : renonciations distinctes
                  + (d.renonciation_recours_locataire === true  ? '<span class="chg-ia-item" style="background:#d9f0db; padding:1px 6px; border-radius:6px;">🛡️ Locat→Bail: <strong>RENONCE</strong></span>' : '')
                  + (d.renonciation_recours_locataire === false ? '<span class="chg-ia-item" style="background:#fef3c7; padding:1px 6px; border-radius:6px;">🛡️ Locat→Bail: <strong>recours OK</strong></span>' : '')
                  + (d.renonciation_recours_bailleur  === true  ? '<span class="chg-ia-item" style="background:#d9f0db; padding:1px 6px; border-radius:6px;">🛡️ Bail→Locat: <strong>RENONCE</strong></span>' : '')
                  + (d.renonciation_recours_bailleur  === false ? '<span class="chg-ia-item" style="background:#fef3c7; padding:1px 6px; border-radius:6px;">🛡️ Bail→Locat: <strong>recours OK</strong></span>' : '')
                  + (d.renonciation_recours_locataire === true && d.renonciation_recours_bailleur === true
                       ? '<span class="chg-ia-item" style="background:#bbf7d0; padding:1px 6px; border-radius:6px; font-weight:700;">= RÉCIPROQUE</span>' : '')
                  + '</div>'
                  + (d.bien_surfaces_detail ? '<div style="color:#5a5650; margin-top:6px; font-size:11.5px; padding:6px 8px; background:#fff; border-left:2px solid #4878a6; border-radius:4px;"><strong>📐 Surfaces :</strong> '+escapeHtml(d.bien_surfaces_detail)+'</div>' : '')
                  + (d.bien_description ? '<div style="color:#5a5650; margin-top:4px; font-size:11.5px; padding:6px 8px; background:#fff; border-left:2px solid #4878a6; border-radius:4px;"><strong>🏠 Bien :</strong> '+escapeHtml(d.bien_description.substring(0, 200))+(d.bien_description.length > 200 ? '…' : '')+'</div>' : '')
                  + (d.conditions_particulieres ? '<div style="color:#5a5650; margin-top:4px; font-size:11.5px; padding:6px 8px; background:#fff; border-left:2px solid #7c3aed; border-radius:4px;"><strong>📋 Conditions particulières :</strong> '+escapeHtml(d.conditions_particulieres.substring(0, 300))+(d.conditions_particulieres.length > 300 ? '…' : '')+'</div>' : '')
                  + (d.notes ? '<div style="color:#7a766f; margin-top:4px;">📝 '+escapeHtml(d.notes)+'</div>' : '')
                  + '</div>';
        }

        html += '</td>';

        // Cellule type (éditable)
        html += '<td><select class="chg-type-select" data-action="set-type">'
              + ['DIAG_DPE','DIAG_AMIANTE','DIAG_PLOMB','DIAG_ELEC','DIAG_GAZ','DIAG_TERMITES','DIAG_ERP',
                 'BAIL','MANDAT_VENTE','MANDAT_LOCATION','TAXE_FONCIERE','PLAN','PHOTO',
                 'COMPROMIS','OFFRE_ACHAT','ACTE_AUTHENTIQUE','AUTRE']
                .map(t => '<option value="'+t+'"'+ (t===item.type?' selected':'') +'>'+t+'</option>').join('')
              + '</select></td>';

        // Cellule bien (cards candidats — Sprint 2C-Phase2)
        html += '<td>';
        if (item.biens.length === 0) {
            html += '<input type="text" class="chg-bien-select" placeholder="🔎 Tape référence/ville/adresse"'
                  + ' data-action="search-bien" autocomplete="off">'
                  + '<div class="chg-bien-suggestions" style="position:relative;"></div>';
        } else {
            // Sélection par défaut = le 1er candidat (best) si rien n'est encore sélectionné
            if (!item.selectedBienId && item.biens[0]) {
                item.selectedBienId = item.biens[0].id;
            }
            html += '<div class="chg-candidates">';
            item.biens.forEach((b, idx) => {
                const isBest      = (idx === 0);
                const isSelected  = (b.id === item.selectedBienId);
                const score       = Math.round(parseFloat(b.score || 0));
                const scoreClass  = score >= 85 ? 'high' : (score >= 60 ? 'medium' : 'low');
                const reasons     = Array.isArray(b.reasons) ? b.reasons : [];
                const url360      = APP_BASE + '/bien_360.php?id=' + b.id;
                html += '<label class="chg-cand'+ (isSelected ? ' selected' : '') +'" data-bien-id="'+ b.id +'">'
                      + '<input type="radio" name="cand-'+ item.id +'" value="'+ b.id +'" data-action="set-bien-radio"'+ (isSelected ? ' checked' : '') +'>'
                      + '<div class="cand-body">'
                      +   '<div class="cand-title">'
                      +     escapeHtml(b.reference_bien || ('#' + b.id))
                      +     (isBest ? ' <span class="cand-badge-best">Best</span>' : '')
                      +     ' <span class="cand-score '+ scoreClass +'">' + score + '%</span>'
                      +     ' <a href="'+ url360 +'" target="_blank" rel="noopener" class="cand-link" title="Ouvrir la fiche 360°">👁 voir</a>'
                      +   '</div>'
                      +   '<div class="cand-meta">'
                      +     escapeHtml(b.designation || '') + (b.designation && b.ville ? ' · ' : '') + escapeHtml(b.ville || '')
                      +     (b.proprio_nom ? ' · 👤 ' + escapeHtml(b.proprio_nom) : '')
                      +   '</div>';
                if (reasons.length > 0) {
                    html += '<div class="cand-reasons">';
                    reasons.slice(0, 4).forEach(r => {
                        html += '<span class="cand-reason">' + escapeHtml(r) + '</span>';
                    });
                    if (reasons.length > 4) html += '<span class="cand-reason">+' + (reasons.length - 4) + '</span>';
                    html += '</div>';
                }
                html += '</div></label>';
            });
            html += '</div>';
            html += '<div class="chg-cand-actions">'
                  + '<button class="chg-btn chg-btn-sm" data-action="search-other">🔍 Autre bien</button> '
                  + '<button class="chg-btn chg-btn-sm" data-action="create-anyway" style="background:#fef3c7;color:#92400e;" title="Créer un nouveau bien malgré les candidats trouvés">➕ Créer malgré tout</button>'
                  + '</div>';
        }
        html += '</td>';

        // Confiance
        html += '<td><span class="chg-conf '+ confClass +'">' + Math.round(item.confidence) + '%</span></td>';

        // Action
        html += '<td>';
        if (item.status === 'pending') {
            if (!item.ia_data) {
                html += '<button class="chg-btn chg-btn-sm" data-action="analyse" title="Analyse IA Vision Claude">🔍 IA</button> ';
            } else {
                // Bouton de RÉANALYSE (utile après un enrichissement du prompt IA)
                html += '<button class="chg-btn chg-btn-sm" data-action="reanalyse" title="Refaire l\'analyse IA (coût ~5ct, écrase l\'ancienne extraction)" style="background:#ede9fe;color:#5b21b6;">🔄</button> ';
                if (item.creation_needed) {
                    html += '<button class="chg-btn chg-btn-sm" data-action="create-bien" title="Créer le bien depuis l\'extraction IA" style="background:#fef3c7;color:#92400e;">➕ Créer bien</button> ';
                } else {
                    html += '<button class="chg-btn chg-btn-sm" data-action="open-bail" title="Corriger & enregistrer le bail">✏️ Bail</button> ';
                }
            }
            html += '<button class="chg-btn chg-btn-validate chg-btn-sm" data-action="validate">Valider</button> '
                  + '<button class="chg-btn chg-btn-sm" data-action="remove" title="Retirer">✕</button>';
        } else if (item.status === 'analyse') {
            html += '<span style="font-size:11px;color:#7a766f;">…</span>';
        } else {
            html += '<button class="chg-btn chg-btn-sm" data-action="remove">✕</button>';
        }
        html += '</td>';

        tr.innerHTML = html;
        tbody.appendChild(tr);

        // bind les events
        tr.querySelectorAll('[data-action]').forEach(el => {
            const action = el.dataset.action;
            if (action === 'set-type') el.addEventListener('change', e => {
                item.type = e.target.value;
                chgPersist(item, { type: item.type });
            });
            if (action === 'set-bien') el.addEventListener('change', e => {
                item.selectedBienId = parseInt(e.target.value, 10) || null;
                chgPersist(item, { selected_bien_id: item.selectedBienId });
            });
            // Sprint 2C-Phase2 : sélection via radio dans cards candidats
            if (action === 'set-bien-radio') el.addEventListener('change', e => {
                item.selectedBienId = parseInt(e.target.value, 10) || null;
                // Mise à jour visuelle : highlight la card sélectionnée
                tr.querySelectorAll('.chg-cand').forEach(card => {
                    card.classList.toggle('selected',
                        parseInt(card.dataset.bienId, 10) === item.selectedBienId);
                });
                chgPersist(item, { selected_bien_id: item.selectedBienId });
            });
            // Sprint 2C-Phase2 : "Créer malgré tout" = créer un nouveau bien depuis l'extraction IA
            // même si des candidats existent (l'user a vu les matches et choisit de créer)
            if (action === 'create-anyway') el.addEventListener('click', () => {
                if (!item.ia_data) {
                    alert('Lance l\'analyse IA d\'abord (bouton 🔍 IA).');
                    return;
                }
                if (!confirm('Créer un nouveau bien malgré les ' + item.biens.length + ' candidat(s) trouvé(s) ?\nLes candidats resteront en BDD inchangés.')) return;
                item.selectedBienId = null;
                chgCreateBienFromIA(item);
            });
            if (action === 'validate')   el.addEventListener('click', () => chgValidateOne(item));
            if (action === 'analyse')    el.addEventListener('click', () => chgAnalyseOne(item));
            if (action === 'reanalyse')  el.addEventListener('click', () => chgReanalyseOne(item));
            if (action === 'open-bail')  el.addEventListener('click', () => chgOpenBailModal(item));
            if (action === 'create-bien')el.addEventListener('click', () => chgCreateBienFromIA(item));
            if (action === 'remove')     el.addEventListener('click', () => chgRemoveOne(item));
            if (action === 'search-bien' || action === 'search-other') {
                el.addEventListener('input', async e => {
                    const v = (e.target.value || '').trim();
                    if (v.length < 3) return;
                    const res = await fetch(APP_BASE + '/api/transaction_chargement_match.php?q=' + encodeURIComponent(v));
                    const data = await res.json();
                    if (data.items && data.items.length) {
                        item.biens = data.items;
                        item.selectedBienId = data.items[0].id;
                        item.confidence = data.items[0].score || 50;
                        renderResults();
                    }
                });
                if (action === 'search-other') {
                    el.addEventListener('click', () => {
                        item.biens = []; item.selectedBienId = null; item.confidence = 0;
                        renderResults();
                    });
                }
            }
        });
    });
}

async function chgValidateOne(item) {
    if (!item.selectedBienId) {
        alert('Sélectionne un bien avant de valider.');
        return;
    }
    const fd = new FormData();
    fd.append('id_bien', item.selectedBienId);
    fd.append('type_document', item.type);
    fd.append('visibilite', 'interne');
    fd.append('commentaire', '[chargement-lot] ' + item.filename);
    fd.append('staging_id', item.staging_id);
    try {
        const res = await fetch(APP_BASE + '/api/transaction_doc_upload.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            // Le doc est passé en GED, on retire la ligne du tableau (la BDD staging a déjà été nettoyée par l'API)
            chgItems = chgItems.filter(x => x.staging_id !== item.staging_id);
        } else {
            item.status = 'error';
            item.error = data.error || 'erreur';
        }
    } catch (e) { item.status = 'error'; item.error = 'réseau'; }
    renderResults();
}

async function chgRemoveOne(item) {
    try {
        const fd = new FormData();
        fd.append('staging_id', item.staging_id);
        await fetch(APP_BASE + '/api/transaction_chg_staging_delete.php', { method:'POST', body: fd });
    } catch (e) {}
    chgItems = chgItems.filter(x => x.staging_id !== item.staging_id);
    renderResults();
}

async function chgValidateAll() {
    const pending = chgItems.filter(i => i.status === 'pending' && i.confidence > 85 && i.selectedBienId);
    if (pending.length === 0) { alert('Aucun fichier en confiance élevée à valider.'); return; }
    if (!confirm('Valider ' + pending.length + ' rattachement(s) (confiance > 85%) ?')) return;
    for (const it of pending) { await chgValidateOne(it); }
}

// ── Analyse IA Vision (extrait locataire, dates, loyer, signature…) ────
async function chgAnalyseOne(item) {
    if (item.status !== 'pending') return;
    item.status = 'analyse'; item.error = null; renderResults();
    // Timeout côté browser : on n'attend pas plus de 150s (Vision PDF prend rarement plus)
    const ctrl = new AbortController();
    const killer = setTimeout(() => ctrl.abort(), 150000);
    try {
        const fd = new FormData();
        fd.append('staging_id', item.staging_id);   // Mode staging : pas de réupload
        const res = await fetch(APP_BASE + '/api/transaction_doc_preview_ia.php', { method:'POST', body: fd, signal: ctrl.signal });
        clearTimeout(killer);
        const raw = await res.text();
        let data;
        try { data = JSON.parse(raw); }
        catch (e) {
            console.error('[IA] Réponse non-JSON:', raw.substring(0, 500));
            item.status = 'error';
            item.error = 'Réponse serveur invalide (voir console)';
            renderResults();
            return;
        }
        if (!data.ok) {
            item.status = 'error';
            item.error = data.error || 'analyse_echec';
            renderResults();
            return;
        }
        item.ia_data         = data.data;
        item.ia_cout         = data.cout_centimes;
        item.ia_match        = data.match_biens || [];
        item.creation_needed = !!data.creation_needed;

        // Si on a un match → préremplit le bien sélectionné avec le score réel
        if (item.ia_match.length > 0 && !item.creation_needed) {
            item.biens = item.ia_match.map(b => ({
                id: b.id, reference_bien: b.reference_bien, designation: b.designation,
                ville: b.ville, code_postal: b.code_postal, adresse_1: b.adresse_1,
                priorite_vente: b.priorite_vente || null,
                proprio_nom: b.proprio_nom || null,
                surface_habitable: b.surface_habitable || null,
                numero_lot: b.numero_lot || null,
                score: b.score || 0,
            }));
            item.selectedBienId = item.biens[0].id;
            item.confidence = item.biens[0].score || 0;
        } else {
            // Pas de match adresse → confiance 0, bien à créer
            item.biens = [];
            item.selectedBienId = null;
            item.confidence = 0;
        }

        // Mappe le type_doc IA vers nos codes
        const tMap = {
            'bail_commercial': 'BAIL', 'bail_habitation': 'BAIL',
            'mandat_vente': 'MANDAT_VENTE', 'mandat_location': 'MANDAT_LOCATION',
            'avenant': 'BAIL', 'compromis': 'COMPROMIS', 'promesse_vente': 'PROMESSE_VENTE',
            'acte_vente': 'ACTE_AUTHENTIQUE', 'dpe': 'DIAG_DPE', 'diagnostic': 'DIAG_DPE',
            'taxe_fonciere': 'TAXE_FONCIERE',
        };
        if (data.data && data.data.type_doc && tMap[data.data.type_doc]) {
            item.type = tMap[data.data.type_doc];
        }

        item.status = 'pending';
        // Persiste tout ce qu'on a appris côté serveur (reload-safe)
        chgPersist(item, {
            ia_data: item.ia_data, match_biens: item.biens,
            selected_bien_id: item.selectedBienId, confidence: item.confidence,
            type: item.type, status: 'pending', error: null,
        });
        renderResults();
    } catch (e) {
        clearTimeout(killer);
        console.error('[IA] erreur:', e);
        item.status = 'error';
        item.error = (e.name === 'AbortError') ? 'Timeout 150s (Claude trop lent)' : ('Erreur réseau : ' + (e.message || e));
        renderResults();
    }
}

// ── Re-match : refait la recherche bien depuis ia_data existant (coût zéro IA) ─
async function chgRematchOne(item) {
    if (!item.ia_data) { return; }
    try {
        const fd = new FormData();
        fd.append('staging_id', item.staging_id);
        const res = await fetch(APP_BASE + '/api/transaction_doc_rematch.php', { method:'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { item.error = data.error || 'rematch_echec'; return; }
        item.biens = (data.match_biens || []).map(b => ({
            id: b.id, reference_bien: b.reference_bien, designation: b.designation,
            ville: b.ville, code_postal: b.code_postal, adresse_1: b.adresse_1,
            priorite_vente: b.priorite_vente || null,
            proprio_nom: b.proprio_nom || null,
            surface_habitable: b.surface_habitable || null,
            numero_lot: b.numero_lot || null,
            score: b.score || 0,
        }));
        item.selectedBienId = data.selected_bien_id;
        item.confidence = data.confidence || 0;
        item.creation_needed = !!data.creation_needed;
    } catch (e) { console.error('[rematch]', e); }
}

async function chgRematchAll() {
    const todo = chgItems.filter(i => i.ia_data && i.status === 'pending');
    if (todo.length === 0) { alert('Aucun fichier à re-matcher (nécessite une analyse IA préalable).'); return; }
    for (const it of todo) { await chgRematchOne(it); }
    renderResults();
    alert('🔄 ' + todo.length + ' fichier(s) re-matché(s).');
}

async function chgCreateBienFromIA(item) {
    if (!item.ia_data) { alert('Lance d\'abord l\'analyse IA.'); return; }
    const d = item.ia_data;
    const msg = 'Créer le bien suivant ?\n\n'
              + '📍 ' + (d.adresse_bien || '?') + '\n'
              + '   ' + (d.code_postal || '') + ' ' + (d.ville || '') + '\n'
              + '👤 Propriétaire : ' + (d.proprietaire || 'inconnu') + '\n\n'
              + 'L\'adresse sera géocodée Google pour détecter les doublons et créer l\'immeuble.';
    if (!confirm(msg)) return;

    try {
        const fd = new FormData();
        fd.append('adresse',     d.adresse_bien || '');
        fd.append('code_postal', d.code_postal || '');
        fd.append('ville',       d.ville || '');
        fd.append('proprietaire',d.proprietaire || '');
        fd.append('designation', (d.type_doc_label || '') + (d.locataire ? ' — ' + d.locataire : ''));
        fd.append('usage_bien',  d.type_doc === 'bail_habitation' ? 'habitation' : 'professionnel');
        fd.append('type_commercialisation', 'location');
        fd.append('surface_habitable', d.surface_m2 || '');
        // loyer mensuel à partir de annuel si nécessaire
        let loyerMens = d.loyer_mensuel_ht;
        if (!loyerMens && d.loyer_annuel_ht) loyerMens = Math.round((parseFloat(d.loyer_annuel_ht) / 12) * 100) / 100;
        fd.append('loyer_hc', loyerMens || '');

        const res = await fetch(APP_BASE + '/api/transaction_bien_create_from_ia.php', { method:'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { alert('Erreur : ' + (data.error || '?')); return; }

        // Met à jour l'item en local : on a maintenant un bien rattachable
        item.selectedBienId = data.bien_id;
        item.biens = [{
            id: data.bien_id,
            reference_bien: data.reference_bien,
            adresse_1: data.adresse_formatee,
            ville: d.ville,
            designation: d.locataire || d.type_doc_label,
            score: 100,
        }];
        item.confidence = 100;
        item.creation_needed = false;
        // Persiste
        await chgPersist(item, {
            match_biens: item.biens, selected_bien_id: item.selectedBienId,
            confidence: 100,
        });

        // Affiche la mini-modale récap avec bouton "Ouvrir fiche bien"
        chgShowBienCreatedModal(data);
        renderResults();
    } catch (e) {
        alert('Erreur réseau : ' + (e.message || e));
    }
}

function chgShowBienCreatedModal(data) {
    const body = document.getElementById('chg-bcreated-body');
    const isExisting = !data.created;
    body.innerHTML =
        '<h3>' + (isExisting ? 'ℹ️ Bien existant retrouvé' : '✅ Bien créé') + '</h3>'
      + '<div style="color:#7a766f; font-size:12px; margin-bottom:10px;">'
      + (isExisting ? 'Anti-doublon : un bien correspondant existait déjà (' + (data.reason || '') + ').' : 'Le bien a été créé en base avec tous ses liens.')
      + '</div>'
      + '<div>'
      +   '<div class="chg-bcreated-line"><span class="lbl">ID en base</span><span class="val ref">#' + data.bien_id + '</span></div>'
      +   (data.reference_bien ? '<div class="chg-bcreated-line"><span class="lbl">Référence</span><span class="val ref">' + escapeHtml(data.reference_bien) + '</span></div>' : '')
      +   (data.adresse_formatee ? '<div class="chg-bcreated-line"><span class="lbl">📍 Adresse</span><span class="val">' + escapeHtml(data.adresse_formatee) + '</span></div>' : '')
      +   '<div class="chg-bcreated-line"><span class="lbl">🌍 Géocodage Google</span><span class="val" style="color:' + (data.geocoded ? '#2d6a35' : '#a8741d') + ';">' + (data.geocoded ? '✓ ' + (data.google_place_id ? 'place_id ' + data.google_place_id.substring(0, 12) + '…' : 'lat/lon OK') : '✗ Pas géocodé') + '</span></div>'
      +   (data.immeuble_id ? '<div class="chg-bcreated-line"><span class="lbl">🏢 Immeuble lié</span><span class="val ref">#' + data.immeuble_id + '</span></div>' : '')
      +   (data.proprietaire_id ? '<div class="chg-bcreated-line"><span class="lbl">🏠 Propriétaire</span><span class="val ref">#' + data.proprietaire_id + '</span></div>' : '')
      + '</div>'
      + '<div class="chg-bcreated-tip">💡 Prochaine étape : clique <strong>✏️ Bail</strong> sur la ligne pour enregistrer les conditions du bail (locataire, dates, loyer, conditions particulières…) dans <code>bien_baux</code> avec tous les liens automatiques.</div>'
      + '<div class="chg-bcreated-actions">'
      +   '<button type="button" class="chg-btn" onclick="chgCloseBienCreatedModal()">Continuer ici</button>'
      +   '<a href="' + APP_BASE + '/bien_detail.php?edit=' + data.bien_id + '" target="_blank" class="chg-btn chg-btn-primary">🔗 Ouvrir la fiche bien (nouvel onglet)</a>'
      + '</div>';
    document.getElementById('chg-bcreated-modal').classList.add('show');
}
function chgCloseBienCreatedModal() { document.getElementById('chg-bcreated-modal').classList.remove('show'); }

async function chgAnalyseAll() {
    const todo = chgItems.filter(i => i.status === 'pending' && !i.ia_data);
    if (todo.length === 0) { alert('Tous les fichiers ont déjà été analysés.\n\nPour relancer une analyse sur les fichiers existants (ex: après une mise à jour du prompt IA), utilise 🔄 Tout réanalyser.'); return; }
    const estimateCost = (todo.length * 5).toFixed(0);
    if (!confirm('Analyser ' + todo.length + ' fichier(s) via IA Claude Vision (Sonnet) ?\n\nCoût estimé : ~' + estimateCost + ' centimes total.\nDurée : ~' + todo.length * 30 + ' s.')) return;
    for (const it of todo) { await chgAnalyseOne(it); }
}

// ── Réanalyse forcée (écrase l'ancienne extraction) ────────────
async function chgReanalyseOne(item) {
    // Reset ia_data pour que chgAnalyseOne ne skip pas
    item.ia_data = null;
    item.creation_needed = false;
    item.status = 'pending';
    item.error = null;
    await chgPersist(item, { ia_data: null, creation_needed: false, status: 'pending', error: null });
    await chgAnalyseOne(item);
}

async function chgReanalyseAll() {
    const todo = chgItems.filter(i => i.status === 'pending' || i.status === 'error');
    if (todo.length === 0) { alert('Aucun fichier à réanalyser.'); return; }
    const cost = (todo.length * 5).toFixed(0);
    const duration = Math.round(todo.length * 30 / 60);
    if (!confirm('🔄 RÉANALYSER ' + todo.length + ' fichier(s) ?\n\n'
        + 'Cela ÉCRASE les anciennes extractions IA — utile après une mise à jour du prompt (nouveaux champs assurance, descriptif bien, etc.).\n\n'
        + '💰 Coût estimé : ~' + cost + ' centimes (Sonnet PDF)\n'
        + '⏱️ Durée estimée : ~' + duration + ' minute(s)\n\n'
        + 'Continuer ?')) return;
    for (const it of todo) { await chgReanalyseOne(it); }
}

async function chgClearAll() {
    if (!confirm('Vider la liste ? (les fichiers en staging seront supprimés du serveur)')) return;
    try {
        const fd = new FormData();
        fd.append('wipe_all', '1');
        await fetch(APP_BASE + '/api/transaction_chg_staging_delete.php', { method:'POST', body: fd });
    } catch (e) {}
    chgItems = []; renderResults();
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
}
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
