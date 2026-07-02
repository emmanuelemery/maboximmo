<?php
declare(strict_types=1);

/**
 * Composant FluxBox — Modale de chargement universelle.
 *
 * À inclure dans inc/layout_maboximmo.php (ou tout layout commun) pour rendre
 * la modale + le bouton flottant disponibles sur TOUTES les pages.
 *
 * Toutes les options de chargement :
 *  1. Drag & drop (zone large)
 *  2. Choisir des fichiers (multi)
 *  3. Choisir un dossier complet (récursif)
 *  4. Upload ZIP (détection auto extension)
 *  5. Photo / caméra (capture mobile)
 *  6. Import depuis URL distante
 *  7. Coller depuis presse-papier (image clipboard)
 *
 * Triggers (2 endroits) :
 *  - Bouton flottant 📥 en bas-droite (toujours visible)
 *  - Bouton dans la topbar (à inclure via #fbx-upload-open)
 *
 * Raccourci clavier : Ctrl+U / Cmd+U pour ouvrir.
 *
 * API appelée : /api/fluxbox_action.php avec actions :
 *   - ingest (multi-files)
 *   - ingest_url
 *   - ingest_clipboard
 *
 * Inclusion conditionnelle : ne s'affiche que si user connecté.
 */

if (empty($_SESSION['user_id'])) return;

// Contexte user (société + agence préremplis, read-only pour user simple)
$_fbxUserCtx = ['societe_code'=>'', 'societe_label'=>'—', 'agence_code'=>'', 'agence_label'=>'—'];
try {
    if (function_exists('ged_v3_get_user_context') && isset($GLOBALS['pdo'])) {
        $_fbxUserCtx = ged_v3_get_user_context($GLOBALS['pdo']);
    } elseif (file_exists(__DIR__ . '/ged_classement_v3.php')) {
        require_once __DIR__ . '/ged_classement_v3.php';
        $_fbxUserCtx = ged_v3_get_user_context();
    }
} catch (Throwable) {}
?>

<!-- ═══════════════════════════════════════════════════════════════════
     BOUTON FLOTTANT (toujours visible) + zone toasts
═══════════════════════════════════════════════════════════════════════ -->
<button type="button" id="fbx-upload-fab" class="fbx-upload-fab" title="Charger des documents (Ctrl+U)">
    <span class="fbx-upload-fab-icon">📥</span>
    <span class="fbx-upload-fab-label">Charger</span>
    <span class="fbx-badge-live" id="fbx-fab-badge" style="display:none;">0</span>
</button>
<div class="fbx-toast-wrap" id="fbx-toast-wrap"></div>

<!-- ═══════════════════════════════════════════════════════════════════
     MODALE
═══════════════════════════════════════════════════════════════════════ -->
<div id="fbx-upload-modal" class="fbx-upload-modal" aria-hidden="true">
    <div class="fbx-upload-backdrop" data-fbx-close></div>
    <div class="fbx-upload-dialog" role="dialog" aria-labelledby="fbx-upload-title">
        <!-- Fix Bug 1 (2026-05-26) : vrais hidden inputs pour inspection DOM + audit QA -->
        <!-- Le POST upload utilise FormData JS (fd.append) mais ces hidden donnent la vérité visible -->
        <input type="hidden" name="prefill_bien_id"     id="fbx-prefill-bien-id"     value="0">
        <input type="hidden" name="prefill_creancier_dossier_id" id="fbx-prefill-creancier-dossier-id" value="0">
        <input type="hidden" name="prefill_immeuble_id" id="fbx-prefill-immeuble-id" value="0">
        <input type="hidden" name="prefill_tiers_id"    id="fbx-prefill-tiers-id"    value="0">
        <input type="hidden" name="prefill_bail_id"     id="fbx-prefill-bail-id"     value="0">
        <input type="hidden" name="prefill_soc_id"      id="fbx-prefill-soc-id"      value="0">
        <input type="hidden" name="prefill_age_id"      id="fbx-prefill-age-id"      value="0">
        <input type="hidden" name="prefill_origin"      id="fbx-prefill-origin"      value="">
        <input type="hidden" name="prefill_mode"        id="fbx-prefill-mode"        value="">
        <div class="fbx-upload-head">
            <h2 id="fbx-upload-title">📥 Charger des documents</h2>
            <button type="button" class="fbx-upload-close" data-fbx-close aria-label="Fermer">✕</button>
        </div>
        <div class="fbx-upload-subtitle">
            Glissez vos documents, choisissez-les, prenez en photo, collez une URL ou collez une image.
            <br>Tous les flux passent par FluxBox — vous validez ensuite carte par carte.
        </div>

        <!-- ───── Classement GED — boutons cascade (replié par défaut, après dropzone visuellement) ───── -->
        <details class="fbx-meta-collapse" id="fbx-meta-details">
        <summary class="fbx-meta-summary">
            <span class="fbx-ctx-bar" id="fbx-ctx-bar">
                <span class="fbx-ctx-chip" data-k="soc">🏢 <b>—</b></span>
                <span class="fbx-ctx-sep">·</span>
                <span class="fbx-ctx-chip" data-k="age">🏬 <b>—</b></span>
                <span class="fbx-ctx-sep">·</span>
                <span class="fbx-ctx-chip" data-k="met">💼 <b>—</b></span>
            </span>
            <span class="fbx-meta-summary-hint">— clique pour corriger · sinon l'IA complète depuis le document</span>
        </summary>
        <div class="fbx-meta-block">

            <!-- Société -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">🏢 Société <span class="fbx-required">*</span></div>
                <div class="fbx-btn-grid" id="fbx-row-societes">
                    <div class="fbx-loading">Chargement…</div>
                </div>
            </div>

            <!-- Agence (cascade depuis société — facultative pour docs niveau société) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">🏬 Agence <span class="fbx-meta-optional">(facultative — laisser sur « Société uniquement » pour un doc niveau société)</span></div>
                <div class="fbx-btn-grid" id="fbx-row-agences">
                    <div class="fbx-row-empty">— Choisir une société d'abord —</div>
                </div>
            </div>

            <!-- Métier (1 bouton par N1, grille 5/ligne avec icône) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">💼 Métier <span class="fbx-required">*</span></div>
                <div class="fbx-btn-grid" id="fbx-row-metiers">
                    <div class="fbx-loading">Chargement…</div>
                </div>
            </div>

            <!-- Domaine (N2) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">📂 Domaine</div>
                <div class="fbx-btn-grid-sm" id="fbx-row-n2" data-meta="n2" data-meta-level="2">
                    <div class="fbx-row-empty">— Choisir un métier d'abord —</div>
                </div>
            </div>

            <!-- Sous-domaine (N3) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">📁 Sous-domaine</div>
                <div class="fbx-btn-grid-sm" id="fbx-row-n3" data-meta="n3" data-meta-level="3">
                    <div class="fbx-row-empty">— Choisir un domaine d'abord —</div>
                </div>
            </div>

            <!-- Nom de l'entité — placé juste après le sous-domaine (= entité sélectionnée) -->
            <div class="fbx-meta-label" id="fbx-meta-entity-wrap">
                <label for="fbx-meta-entity-input">
                    👤 Nom de l'entité
                    <span class="fbx-meta-optional" id="fbx-meta-entity-hint">(rempli auto si tu uploades un dossier nommé — sinon saisis ici : Dupont-Pierre, BNP Paribas, Immeuble Foch…)</span>
                </label>
                <input type="text" id="fbx-meta-entity-input" maxlength="120" autocomplete="off"
                       placeholder="🔎 Cherche un propriétaire, un locataire, un immeuble, un bien, un collaborateur…">
                <div id="fbx-entity-results" class="fbx-entity-results"></div>
                <div class="fbx-meta-hint" id="fbx-meta-entity-required-msg" style="display:none; color:#dc2626;">⚠️ Ce sous-domaine attend une entité — sans nom, le doc sera classé sous « COLLABORATEUR » générique.</div>
            </div>

            <!-- Catégorie (N4) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">📄 Catégorie</div>
                <div class="fbx-btn-grid-sm" id="fbx-row-n4" data-meta="n4" data-meta-level="4">
                    <div class="fbx-row-empty">— Choisir un sous-domaine d'abord —</div>
                </div>
            </div>

            <!-- Sous-catégorie (N5) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">📑 Sous-catégorie</div>
                <div class="fbx-btn-grid-sm" id="fbx-row-n5" data-meta="n5" data-meta-level="5">
                    <div class="fbx-row-empty">— Choisir une catégorie d'abord —</div>
                </div>
            </div>

            <!-- Libellé personnalisé du document (optionnel) -->
            <div class="fbx-meta-label">
                <label for="fbx-meta-label-input">🏷️ Libellé du document <span class="fbx-meta-optional">(optionnel)</span></label>
                <input type="text" id="fbx-meta-label-input" maxlength="180"
                       placeholder="Ex : « Relevé CACE octobre 2026 » — si vide, l'IA propose un libellé après import">
                <div class="fbx-meta-hint">💡 Si rempli, ce libellé sera utilisé tel quel. Si vide, l'IA propose un libellé après analyse du document.</div>
            </div>

            <!-- Date du document (override) -->
            <div class="fbx-meta-date">
                <label for="fbx-meta-date-input">📅 Date du document <span class="fbx-meta-optional">(optionnel — auto-détection sinon)</span></label>
                <div class="fbx-meta-date-row">
                    <input type="date" id="fbx-meta-date-input" placeholder="JJ/MM/AAAA">
                    <span class="fbx-meta-hint-inline">
                        💡 Si vide, l'IA cherche dans le nom du fichier (ex : « releve_032026.pdf » → mars 2026).
                        Sinon → date du jour.
                    </span>
                </div>
            </div>

            <!-- Commentaire -->
            <div class="fbx-meta-comment">
                <label for="fbx-meta-comment-input">💬 Commentaire / Action souhaitée <span class="fbx-meta-optional">(optionnel)</span></label>
                <textarea id="fbx-meta-comment-input" rows="2"
                          placeholder="Ex : « facture à valider et envoyer en compta » · « PV à diffuser au conseil syndical » · « bulletin de paie à classer dans le dossier Dupont »"></textarea>
                <div class="fbx-meta-hint">L'IA utilisera ce contexte pour préparer la carte et proposer une action métier.</div>
            </div>
        </div>
        </details>

        <!-- Onglets options -->
        <div class="fbx-upload-tabs" role="tablist">
            <button type="button" class="fbx-tab is-active" data-tab="dragdrop">📂 Fichiers</button>
            <button type="button" class="fbx-tab"            data-tab="folder">🗂️ Dossier</button>
            <button type="button" class="fbx-tab"            data-tab="photo">📷 Photo</button>
            <button type="button" class="fbx-tab"            data-tab="url">🔗 URL</button>
            <button type="button" class="fbx-tab"            data-tab="clipboard">📋 Coller</button>
        </div>

        <!-- ───── PANNEAU 1 : Drag&drop + multi-files + ZIP ───── -->
        <div class="fbx-pane is-active" data-pane="dragdrop">
            <div id="fbx-dropzone" class="fbx-dropzone">
                <div class="fbx-dropzone-icon">⬇️</div>
                <div class="fbx-dropzone-title">Glissez vos fichiers ici</div>
                <div class="fbx-dropzone-sub">ou cliquez pour parcourir — PDF, images, DOCX, ZIP… 50 Mo max/fichier</div>
                <input type="file" id="fbx-input-files" multiple style="display:none">
            </div>
        </div>

        <!-- ───── PANNEAU 2 : Dossier complet récursif ───── -->
        <div class="fbx-pane" data-pane="folder">
            <div class="fbx-folder-zone">
                <div class="fbx-folder-icon">🗂️</div>
                <div class="fbx-folder-title">Importer un dossier complet</div>
                <div class="fbx-folder-sub">Sous-dossiers inclus — ZIP extraits automatiquement côté serveur</div>
                <label class="fbx-btn fbx-btn-primary">
                    <input type="file" id="fbx-input-folder" webkitdirectory directory mozdirectory multiple style="display:none">
                    📁 Choisir un dossier
                </label>
            </div>
        </div>

        <!-- ───── PANNEAU 3 : Photo / caméra mobile ───── -->
        <div class="fbx-pane" data-pane="photo">
            <div class="fbx-photo-zone">
                <div class="fbx-photo-icon">📷</div>
                <div class="fbx-photo-title">Prendre une photo</div>
                <div class="fbx-photo-sub">Scan rapide d'un document, d'un courrier ou d'un reçu</div>
                <label class="fbx-btn fbx-btn-primary">
                    <input type="file" id="fbx-input-photo" accept="image/*" capture="environment" multiple style="display:none">
                    📸 Ouvrir l'appareil photo
                </label>
                <div class="fbx-photo-hint">Sur ordinateur, ouvre la galerie. Sur mobile, ouvre l'appareil photo.</div>
            </div>
        </div>

        <!-- ───── PANNEAU 4 : URL distante ───── -->
        <div class="fbx-pane" data-pane="url">
            <div class="fbx-url-zone">
                <div class="fbx-url-icon">🔗</div>
                <div class="fbx-url-title">Télécharger depuis une URL</div>
                <div class="fbx-url-sub">Lien direct vers un PDF, une image, un document Drive partagé…</div>
                <div class="fbx-url-form">
                    <input type="url" id="fbx-input-url" placeholder="https://exemple.com/document.pdf" autocomplete="off">
                    <button type="button" id="fbx-url-submit" class="fbx-btn fbx-btn-primary">📥 Télécharger</button>
                </div>
                <div class="fbx-url-hint">⚠️ HTTPS uniquement. Taille max : 50 Mo.</div>
            </div>
        </div>

        <!-- ───── PANNEAU 5 : Coller depuis presse-papier ───── -->
        <div class="fbx-pane" data-pane="clipboard">
            <div class="fbx-clipboard-zone" id="fbx-clipboard-zone" tabindex="0">
                <div class="fbx-clipboard-icon">📋</div>
                <div class="fbx-clipboard-title">Coller depuis le presse-papier</div>
                <div class="fbx-clipboard-sub">Cliquez ici puis appuyez sur <kbd>Ctrl</kbd>+<kbd>V</kbd> (capture d'écran, image copiée…)</div>
                <div class="fbx-clipboard-hint" id="fbx-clipboard-status">En attente d'un collage…</div>
            </div>
        </div>

        <!-- ───── FILE PROGRESS + RÉSULTATS ───── -->
        <div id="fbx-upload-queue" class="fbx-upload-queue" hidden>
            <h3>📊 Progression</h3>
            <!-- Barre de progression globale -->
            <div class="fbx-progress-global">
                <div class="fbx-progress-stats">
                    <span><strong id="fbx-progress-done">0</strong> / <span id="fbx-progress-total">0</span> traités</span>
                    <span class="fbx-progress-pct"><span id="fbx-progress-pct">0</span>%</span>
                </div>
                <div class="fbx-progress-bar">
                    <div class="fbx-progress-fill" id="fbx-progress-fill" style="width:0%"></div>
                </div>
                <div class="fbx-progress-details" id="fbx-progress-details">
                    <span>⏳ <strong id="fbx-progress-active">0</strong> en cours</span>
                    <span>✅ <strong id="fbx-progress-ok">0</strong> OK</span>
                    <span>🛡️ <strong id="fbx-progress-dup">0</strong> doublons</span>
                    <span>❌ <strong id="fbx-progress-err">0</strong> erreurs</span>
                </div>
            </div>
            <ul id="fbx-queue-list"></ul>

            <!-- Bilan doublons (affiché en fin d'upload si > 0 doublons détectés) -->
            <div class="fbx-dedup-summary" id="fbx-dedup-summary" hidden>
                <div class="fbx-dedup-head">
                    🛡️ <strong id="fbx-dedup-count">0</strong> doublon(s) détecté(s) — fichier(s) déjà présent(s) en BDD
                    <button type="button" class="fbx-dedup-toggle" id="fbx-dedup-toggle">Voir le détail ▼</button>
                </div>
                <div class="fbx-dedup-list" id="fbx-dedup-list" hidden></div>
            </div>

            <!-- Actions de fin de chargement (affichées quand tout est traité) -->
            <div class="fbx-queue-end-actions" id="fbx-queue-end-actions" hidden>
                <!-- Validation directe en GED (sans passer par la pile) -->
                <button type="button" class="fbx-btn fbx-btn-primary" id="fbx-validate-now" hidden
                        style="background:#15803d;border-color:#15803d;">
                    ✅ Valider et classer maintenant
                </button>
                <!-- Sprint 6 · A1 : bouton de redirection vers pipeline documentaire unifié -->
                <button type="button" class="fbx-btn fbx-btn-primary" id="fbx-go-review" hidden>
                    📋 Réviser maintenant
                </button>
                <button type="button" class="fbx-btn fbx-btn-primary" id="fbx-go-fluxbox">
                    🃏 Voir la pile FluxBox
                </button>
                <button type="button" class="fbx-btn fbx-btn-secondary" id="fbx-upload-more">
                    📥 Charger d'autres documents
                </button>
                <button type="button" class="fbx-btn fbx-btn-secondary" data-fbx-close>
                    ✕ Fermer
                </button>
            </div>
        </div>

    </div>
</div>

<style>
/* ═══════════ Bilan doublons en fin d'upload ═══════════ */
.fbx-dedup-summary {
    margin: 14px 16px 0;
    padding: 14px 16px;
    background: linear-gradient(180deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #d97706;
    border-radius: 12px;
    color: #92400e;
}
.fbx-dedup-head {
    display: flex; align-items: center; gap: 10px;
    font-size: 14px; font-weight: 600;
}
.fbx-dedup-head strong { color: #b45309; font-size: 18px; }
.fbx-dedup-toggle {
    margin-left: auto;
    background: transparent;
    border: 1px solid #b45309;
    color: #92400e;
    padding: 4px 10px; border-radius: 6px;
    cursor: pointer; font-size: 12px; font-weight: 600;
}
.fbx-dedup-toggle:hover { background: rgba(180, 83, 9, 0.1); }
.fbx-dedup-list {
    margin-top: 12px;
    background: #fffbeb;
    border-radius: 8px;
    padding: 6px;
    max-height: 320px;
    overflow-y: auto;
}
.fbx-dedup-item {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px dashed #d97706;
    align-items: center;
    font-size: 12px;
}
.fbx-dedup-item:last-child { border-bottom: none; }
.fbx-dedup-item-new, .fbx-dedup-item-orig {
    display: flex; flex-direction: column; gap: 2px; min-width: 0;
}
.fbx-dedup-item-label {
    font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;
    color: #b45309; font-weight: 700;
}
.fbx-dedup-item-name {
    font-family: 'DM Mono', monospace;
    color: #1f2937;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fbx-dedup-item-meta {
    font-size: 10px; color: #78716c;
}
.fbx-dedup-item-action a {
    display: inline-block;
    padding: 4px 10px;
    background: #fff;
    border: 1px solid #b45309;
    border-radius: 6px;
    color: #92400e;
    text-decoration: none;
    font-size: 11px; font-weight: 600;
    white-space: nowrap;
}
.fbx-dedup-item-action a:hover { background: #fef3c7; }

/* ═══════════ Badges live + Toasts (uploads non-bloquants) ═══════════ */
.fbx-badge-live {
    position: absolute;
    top: -4px; right: -4px;
    background: #dc2626;
    color: #fff;
    min-width: 18px; height: 18px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    animation: fbx-pulse 1.4s ease-in-out infinite;
}
.fbx-badge-live.done { background: #16a34a; animation: none; }
@keyframes fbx-pulse {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.12); }
}
.fbx-topbar-btn { position: relative; }
.fbx-upload-fab { position: fixed; }
#fbx-upload-fab { /* la position fixed est déjà définie plus bas, ici on ajoute juste le relative pour le badge */ }
.fbx-upload-fab .fbx-badge-live { top: -6px; right: -6px; }

/* Toasts (notification fin upload) */
.fbx-toast-wrap {
    position: fixed;
    bottom: 90px;
    right: 24px;
    z-index: 9999;
    display: flex; flex-direction: column-reverse; gap: 8px;
    max-width: 320px;
    pointer-events: none;
}
.fbx-toast {
    background: #fff;
    border-radius: 12px;
    padding: 10px 14px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.18), 0 1px 3px rgba(0,0,0,0.1);
    font-family: "Sora", "Inter", sans-serif;
    font-size: 13px;
    display: flex; align-items: center; gap: 10px;
    border-left: 4px solid #16a34a;
    animation: fbx-toast-in .3s cubic-bezier(0.22,1,0.36,1);
    pointer-events: auto;
}
.fbx-toast.error { border-left-color: #dc2626; }
.fbx-toast.dup   { border-left-color: #ca8a04; }
.fbx-toast-icon { font-size: 18px; flex-shrink: 0; }
.fbx-toast-msg { flex: 1; color: #1e293b; }
.fbx-toast-msg strong { color: #243B5C; }
.fbx-toast-name {
    font-size: 11px; color: #64748b;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    max-width: 200px;
}
@keyframes fbx-toast-in {
    from { transform: translateX(360px); opacity: 0; }
    to   { transform: translateX(0); opacity: 1; }
}
.fbx-toast.is-leaving { animation: fbx-toast-out .25s ease forwards; }
@keyframes fbx-toast-out {
    to { transform: translateX(360px); opacity: 0; }
}

/* ═══════════ Bouton Charger — violet saturé + glow lumineux ═══════════ */
/* Style commun topbar + FAB pour cohérence visuelle parfaite */
.mbi-topbar .tb-btn.fbx-topbar-btn,
.fbx-topbar-btn,
.fbx-upload-fab {
    width: auto !important; height: auto !important;
    display: inline-flex !important; align-items: center; gap: 8px;
    padding: 9px 16px !important;
    background: linear-gradient(180deg, #E5D5F5 0%, #BFA0E0 100%) !important;
    color: #3D1A6E !important;
    border: 1px solid #9F7BCC !important; border-radius: 10px !important;
    font-family: "Sora", "Inter", sans-serif; font-size: 13px; font-weight: 700;
    letter-spacing: 0.01em;
    cursor: pointer;
    /* Relief + glow lumineux (ombre portée réduite de 50% — 2026-05-17) */
    box-shadow:
        -1px -1px 3px rgba(255, 255, 255, 0.42),
         2px 2px 5px rgba(120, 75, 200, 0.22),
         0 0 7px rgba(176, 130, 232, 0.27),
         inset 0 1px 2px rgba(255, 255, 255, 0.9),
         inset 0 -1px 2px rgba(120, 75, 200, 0.18) !important;
    text-shadow: 0 1px 0 rgba(255, 255, 255, 0.6);
    transition: all .15s ease;
}
.mbi-topbar .tb-btn.fbx-topbar-btn:hover,
.fbx-topbar-btn:hover,
.fbx-upload-fab:hover {
    transform: translateY(-2px);
    background: linear-gradient(180deg, #DDC5F0 0%, #B08AD8 100%) !important;
    box-shadow:
        -1.5px -1.5px 4px rgba(255, 255, 255, 0.47),
         2px 3px 7px rgba(120, 75, 200, 0.27),
         0 0 11px rgba(176, 130, 232, 0.37),
         inset 0 1px 2px rgba(255, 255, 255, 1),
         inset 0 -1px 2px rgba(120, 75, 200, 0.25) !important;
    border-color: #6B33B5 !important;
    color: #2D0F58 !important;
}
.mbi-topbar .tb-btn.fbx-topbar-btn:active,
.fbx-topbar-btn:active,
.fbx-upload-fab:active {
    transform: translateY(0);
    box-shadow:
        inset 2px 2px 6px rgba(120, 75, 200, 0.5),
        inset -2px -2px 5px rgba(255, 255, 255, 0.85),
        0 0 10px rgba(176, 130, 232, 0.4) !important;
}
.fbx-topbar-btn-label { letter-spacing: 0.03em; }
/* Topbar uniquement — hauteur réduite de 25% + 50px de décalage à droite (2026-05-17) */
.mbi-topbar .tb-btn.fbx-topbar-btn {
    padding: 4px 16px !important;
    font-size: 12px !important;
    margin-right: 50px !important;
}
@media (max-width: 640px) {
    .fbx-topbar-btn-label { display: none; }
    .mbi-topbar .tb-btn.fbx-topbar-btn,
    .fbx-topbar-btn { padding: 4px 10px !important; margin-right: 50px !important; }
}

/* ═══════════ FAB Charger en bas — toujours visible, style identique au bouton topbar ═══════════ */
/* Note : le style violet+relief vient du bloc .fbx-upload-fab dans la règle commune ci-dessus.
   Ici on ajoute uniquement le positionnement fixed bas-droite + animations. */
.fbx-upload-fab {
    position: fixed !important;
    bottom: 24px !important;
    right: 24px !important;
    z-index: 9990 !important;
    /* Légère animation d'apparition au scroll */
    animation: fbx-fab-pop .35s cubic-bezier(0.22,1,0.36,1);
}
@keyframes fbx-fab-pop {
    from { opacity: 0; transform: translateY(20px) scale(0.85); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.fbx-upload-fab-icon { font-size: 16px; }
@media (max-width: 640px) {
    .fbx-upload-fab-label { display: none; }
    .fbx-upload-fab { padding: 12px 14px !important; }
}

/* ═══════════ MODALE ═══════════ */
.fbx-upload-modal {
    position: fixed; inset: 0; z-index: 9995;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 24px 16px;
    overflow-y: auto;
}
.fbx-upload-modal.is-open { display: flex; }
.fbx-upload-backdrop {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
}
.fbx-upload-dialog {
    position: relative;
    background: #fff;
    border-radius: 20px;
    max-width: 864px;
    width: 100%;
    margin: auto;
    padding: 26px 28px;
    /* Contour violet (couleur signature FluxBox = identique aux boutons actifs / bouton FAB)
       + halo coloré pour signaler visuellement qu'on est dans le module Charger. */
    border: 3px solid #7c3aed;
    box-shadow: 0 0 0 6px rgba(124,58,237,0.18), 0 30px 90px rgba(0,0,0,0.3);
    font-family: "Sora", "Inter", sans-serif;
    animation: fbx-modal-in .25s cubic-bezier(0.22,1,0.36,1);
    /* Flex column pour pouvoir repositionner visuellement avec `order`
       (dropzone d'abord, puis bloc cascade en bas — l'user drop, l'IA propose, il valide). */
    display: flex;
    flex-direction: column;
}
.fbx-upload-head     { order: 1; }
.fbx-upload-subtitle { order: 2; }
.fbx-target-row      { order: 3; }   /* mini-cards "Bien" + "Propriétaire" côte à côte */
.fbx-upload-tabs     { order: 4; }
.fbx-pane            { order: 5; }
.fbx-upload-queue    { order: 6; }
.fbx-meta-collapse   { order: 7; }   /* bloc cascade GED — replié, en bas */

/* Row de 2 mini-cards côte à côte (Bien + Propriétaire). Empilées sur mobile. */
.fbx-target-row {
    display: flex;
    gap: 14px;
    margin-bottom: 18px;
}
@media (max-width: 700px) {
    .fbx-target-row { flex-direction: column; }
}

/* Mini-card commune (style relief MaBoxImmo) — palette métier figée 2026-05-23 */
.fbx-target-card {
    flex: 1; min-width: 0;
    display: flex;
    align-items: center;
    gap: 14px;
    border-radius: 14px;
    padding: 14px 18px;
    text-decoration: none;
    color: inherit;
    transition: transform .12s, box-shadow .12s;
}
.fbx-target-card:hover { transform: translateY(-2px); }

/* BIEN — vert amande classique #84a98c */
.fbx-target-bien {
    background: linear-gradient(135deg, rgba(132,169,140,0.10) 0%, rgba(132,169,140,0.18) 100%);
    border: 1px solid #84a98c;
    border-left: 4px solid #84a98c;
    box-shadow: 4px 4px 12px rgba(132,169,140,0.18);
}
.fbx-target-bien:hover { box-shadow: 6px 6px 16px rgba(132,169,140,0.28); }
.fbx-target-bien .fbx-target-icon {
    background: linear-gradient(135deg, rgba(132,169,140,0.55), rgba(132,169,140,0.95));
}
.fbx-target-bien .fbx-target-label { color: #4a6d52; }
.fbx-target-bien .fbx-target-name  { color: #1a3a2e; }

/* PROPRIÉTAIRE — pétrole cyan #0e7490 */
.fbx-target-proprio {
    background: linear-gradient(135deg, rgba(14,116,144,0.08) 0%, rgba(14,116,144,0.16) 100%);
    border: 1px solid #0e7490;
    border-left: 4px solid #0e7490;
    box-shadow: 4px 4px 12px rgba(14,116,144,0.18);
}
.fbx-target-proprio:hover { box-shadow: 6px 6px 16px rgba(14,116,144,0.28); }
.fbx-target-proprio .fbx-target-icon {
    background: linear-gradient(135deg, rgba(14,116,144,0.55), rgba(14,116,144,0.95));
}
.fbx-target-proprio .fbx-target-label { color: #0e7490; }
.fbx-target-proprio .fbx-target-name  { color: #0e2a3a; }

.fbx-target-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; flex-shrink: 0;
    box-shadow: inset 1px 1px 2px rgba(255,255,255,0.5), 2px 2px 4px rgba(0,0,0,0.15);
}
.fbx-target-body { flex: 1; min-width: 0; }
.fbx-target-label {
    font-family: "DM Mono", monospace;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    margin-bottom: 3px;
}
.fbx-target-name {
    font-size: 15px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.fbx-target-id {
    font-family: "DM Mono", monospace;
    font-size: 11px;
    font-weight: 600;
    background: #ede9fe;
    color: #5b21b6;
    padding: 2px 8px;
    border-radius: 99px;
}
.fbx-target-addr {
    margin-top: 4px;
    font-size: 12.5px;
    color: #5a5650;
}
.fbx-target-from {
    margin-top: 5px;
    font-size: 10.5px;
    color: #7a766f;
    font-style: italic;
}

/* Accordéon classement GED */
.fbx-meta-collapse {
    background: #f8fafc;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    margin-top: 14px;
}
.fbx-meta-collapse > .fbx-meta-block { background: transparent; border: none; margin: 0; }
.fbx-meta-collapse[open] .fbx-meta-summary { border-bottom: 1px solid #e2e8f0; }
.fbx-meta-summary {
    cursor: pointer;
    list-style: none;
    padding: 12px 18px;
    font-size: 13px;
    color: #475569;
    user-select: none;
    display: flex;
    align-items: center;
    gap: 8px;
}
.fbx-meta-summary::-webkit-details-marker { display: none; }
.fbx-meta-summary::before {
    content: "▸";
    transition: transform .15s;
    color: #94a3b8;
}
.fbx-meta-collapse[open] .fbx-meta-summary::before { transform: rotate(90deg); }
.fbx-meta-summary:hover { background: rgba(0,0,0,0.02); }
.fbx-meta-summary strong { color: #243B5C; }
.fbx-meta-summary-hint { font-size: 11.5px; color: #94a3b8; font-weight: 400; }
/* Barre de contexte compacte (société · agence · métier) dans le summary */
.fbx-ctx-bar { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.fbx-ctx-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 12px; color: #475569;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 999px;
    padding: 3px 10px; white-space: nowrap;
}
.fbx-ctx-chip b { color: #243B5C; font-weight: 800; }
.fbx-meta-collapse[open] .fbx-ctx-chip { background: #eef2ff; border-color: #c7d2fe; }
.fbx-ctx-sep { color: #cbd5e1; font-weight: 700; }
/* Résultats de la recherche universelle d'entité (champ « Nom de l'entité ») */
.fbx-entity-results { display: none; flex-direction: column; gap: 4px; margin-top: 6px; max-height: 260px; overflow: auto; }
.fbx-entity-results.is-open { display: flex; }
.fbx-entity-res {
    display: flex; flex-direction: column; align-items: flex-start; gap: 1px;
    border: 1px solid #e2e8f0; background: #fff; border-radius: 9px; padding: 7px 11px;
    cursor: pointer; text-align: left; width: 100%;
}
.fbx-entity-res:hover { border-color: #0e7490; background: #f0fdff; }
.fbx-entity-res .ttl { font-size: 13px; font-weight: 700; color: #243B5C; }
.fbx-entity-res .b {
    font-size: 9.5px; font-weight: 800; color: #0e7490; background: #ecfeff;
    border: 1px solid #a5f3fc; border-radius: 5px; padding: 0 5px; margin-right: 7px; vertical-align: middle;
}
.fbx-entity-res .rp { font-size: 11px; color: #64748b; }
@keyframes fbx-modal-in {
    from { opacity: 0; transform: translateY(20px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.fbx-upload-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 8px;
}
.fbx-upload-head h2 { margin: 0; color: #243B5C; font-size: 22px; font-weight: 700; }
.fbx-upload-close {
    background: #f1f5f9; border: none; color: #64748b;
    width: 32px; height: 32px; border-radius: 50%;
    cursor: pointer; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
}
.fbx-upload-close:hover { background: #e2e8f0; color: #243B5C; }
.fbx-upload-subtitle {
    font-size: 13px; color: #64748b; margin-bottom: 18px; line-height: 1.5;
}

/* ═══════════ Bloc méta (boutons cascade + commentaire) ═══════════ */
.fbx-meta-block {
    background: #f8fafc;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 18px;
    border: 1px solid #e2e8f0;
}

/* ── Rangée de boutons (société / agence / métier) ── */
.fbx-row-block { margin-bottom: 14px; }
.fbx-row-label {
    font-size: 11px; font-weight: 700; color: #475569;
    text-transform: uppercase; letter-spacing: 0.05em;
    margin-bottom: 6px;
}
/* Grid 5 colonnes — boutons carrés (sociétés / agences / métiers) */
.fbx-btn-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    min-height: 38px;
}
@media (max-width: 900px) { .fbx-btn-grid { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 700px) { .fbx-btn-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 480px) { .fbx-btn-grid { grid-template-columns: repeat(2, 1fr); } }

/* Grid 10 colonnes — variante compacte pour N2-N5 (domaines/sous-domaines/catégories) */
.fbx-btn-grid-sm {
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 6px;
    min-height: 32px;
}
@media (max-width: 900px) { .fbx-btn-grid-sm { grid-template-columns: repeat(7, 1fr); } }
@media (max-width: 700px) { .fbx-btn-grid-sm { grid-template-columns: repeat(5, 1fr); } }
@media (max-width: 480px) { .fbx-btn-grid-sm { grid-template-columns: repeat(3, 1fr); } }
.fbx-btn-grid-sm .fbx-choice-btn {
    min-height: 44px;
    padding: 6px 4px;
    font-size: 11px;
    border-radius: 8px;
}
.fbx-btn-grid-sm .fbx-choice-label { font-size: 11px; }

.fbx-loading, .fbx-row-empty {
    font-size: 12px; color: #94a3b8; font-style: italic;
    grid-column: 1 / -1;
    padding: 8px 0;
}
.fbx-choice-btn {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 4px;
    min-height: 64px;
    padding: 10px 8px;
    border-radius: 10px;
    border: 1.5px solid #cbd5e1;
    background: #fff; color: #243B5C;
    font-family: inherit; font-size: 12px; font-weight: 600;
    cursor: pointer;
    transition: all .15s ease;
    line-height: 1.2;
    text-align: center;
    word-break: break-word;
    hyphens: auto;
}
.fbx-choice-btn:hover {
    border-color: #6B33B5;
    background: #fef3c7;
}
.fbx-choice-btn.is-selected {
    background: linear-gradient(180deg, #E5D5F5 0%, #BFA0E0 100%);
    color: #3D1A6E;
    border-color: #9F7BCC;
    box-shadow:
        inset 0 1px 2px rgba(255, 255, 255, 0.9),
        0 0 10px rgba(176, 130, 232, 0.5),
        2px 3px 8px rgba(120, 75, 200, 0.35);
    font-weight: 700;
}
.fbx-choice-code {
    background: #f1f5f9;
    color: #64748b;
    font-family: "JetBrains Mono", monospace;
    font-size: 10px;
    padding: 1px 5px;
    border-radius: 4px;
    letter-spacing: 0.04em;
}
.fbx-choice-btn.is-selected .fbx-choice-code {
    background: rgba(255,255,255,0.18);
    color: #fff;
}
.fbx-choice-label { font-weight: 600; font-size: 12px; line-height: 1.15; }
.fbx-choice-icon { font-size: 22px; line-height: 1; }

/* Bouton spécial "Société uniquement" (option par défaut dans grille Agence) */
.fbx-choice-btn-societe {
    border-style: dashed;
    background: #f8fafc;
}
.fbx-choice-btn-societe:hover {
    border-style: solid;
}
.fbx-choice-btn-societe.is-selected {
    background: linear-gradient(135deg, #6B33B5, #4A1F87);
    border-color: #4A1F87;
}

/* Bouton "+" admin pour ajouter une nouvelle référence (N2-N5) */
.fbx-btn-add-ref {
    border-style: dashed !important;
    border-color: #94a3b8 !important;
    background: #f8fafc;
    color: #475569;
}
.fbx-btn-add-ref:hover {
    border-color: #6B33B5 !important;
    background: #fef9e7;
    color: #92400e;
}
.fbx-btn-add-ref .fbx-choice-icon {
    color: #6B33B5;
    font-weight: 700;
    font-size: 26px;
}

.fbx-meta-label {
    margin-bottom: 14px;
}
.fbx-meta-label label {
    display: block;
    font-size: 11px; color: #475569; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.05em;
    margin-bottom: 4px;
}
.fbx-meta-label input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-family: inherit; font-size: 13px;
    background: #fff; color: #1e293b;
}
.fbx-meta-label input:focus {
    outline: 2px solid #243B5C; outline-offset: 0; border-color: #243B5C;
}
.fbx-meta-hint {
    font-size: 11px; color: #64748b; margin-top: 4px;
}

.fbx-required { color: #dc2626; }
.fbx-meta-optional { color: #94a3b8; font-weight: 400; }

.fbx-meta-date { margin-bottom: 12px; }
.fbx-meta-date label, .fbx-meta-comment label {
    display: block;
    font-size: 12px; color: #475569; font-weight: 600;
    margin-bottom: 4px;
}
.fbx-meta-date-row {
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
}
.fbx-meta-date input[type="date"] {
    padding: 8px 12px;
    border: 1px solid #cbd5e1; border-radius: 8px;
    font-family: inherit; font-size: 13px;
    background: #fff; color: #1e293b;
    min-width: 180px;
}
.fbx-meta-date input[type="date"]:focus {
    outline: 2px solid #243B5C; outline-offset: 0; border-color: #243B5C;
}
.fbx-meta-hint-inline {
    font-size: 11px; color: #94a3b8;
    flex: 1; min-width: 200px;
}
.fbx-meta-comment textarea {
    width: 100%; box-sizing: border-box;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-family: inherit; font-size: 13px;
    resize: vertical; min-height: 56px;
    background: #fff;
}
.fbx-meta-comment textarea:focus {
    outline: 2px solid #243B5C; outline-offset: 0; border-color: #243B5C;
}
.fbx-meta-hint {
    font-size: 11px; color: #94a3b8; margin-top: 4px; font-style: italic;
}

/* ═══════════ Tabs ═══════════ */
.fbx-upload-tabs {
    display: flex; gap: 6px;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 18px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.fbx-tab {
    background: transparent; border: none; cursor: pointer;
    padding: 10px 14px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    color: #64748b;
    border-bottom: 3px solid transparent;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.fbx-tab:hover { color: #243B5C; }
.fbx-tab.is-active {
    color: #243B5C;
    border-bottom-color: #6B33B5;
}

/* ═══════════ Panneaux ═══════════ */
.fbx-pane { display: none; min-height: 220px; }
.fbx-pane.is-active { display: block; }

/* ━━ Drag & drop ━━ */
.fbx-dropzone {
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    padding: 40px 24px;
    text-align: center;
    cursor: pointer;
    background: #f8fafc;
    transition: background .15s, border-color .15s;
}
.fbx-dropzone:hover, .fbx-dropzone.is-dragover {
    background: #fef3c7;
    border-color: #6B33B5;
}
.fbx-dropzone-icon { font-size: 42px; }
.fbx-dropzone-title { font-size: 17px; font-weight: 700; color: #243B5C; margin: 10px 0 4px; }
.fbx-dropzone-sub { font-size: 13px; color: #64748b; }

/* ━━ Folder / Photo / URL / Clipboard zones ━━ */
.fbx-folder-zone, .fbx-photo-zone, .fbx-url-zone, .fbx-clipboard-zone {
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    padding: 30px 24px;
    text-align: center;
    background: #f8fafc;
}
.fbx-clipboard-zone { cursor: text; outline: none; }
.fbx-clipboard-zone:focus { background: #fef3c7; border-color: #6B33B5; }

.fbx-folder-icon, .fbx-photo-icon, .fbx-url-icon, .fbx-clipboard-icon {
    font-size: 38px;
}
.fbx-folder-title, .fbx-photo-title, .fbx-url-title, .fbx-clipboard-title {
    font-size: 16px; font-weight: 700; color: #243B5C; margin: 10px 0 4px;
}
.fbx-folder-sub, .fbx-photo-sub, .fbx-url-sub, .fbx-clipboard-sub {
    font-size: 13px; color: #64748b; margin-bottom: 18px;
}
.fbx-photo-hint, .fbx-url-hint, .fbx-clipboard-hint {
    font-size: 12px; color: #94a3b8; margin-top: 12px; font-style: italic;
}
.fbx-clipboard-hint { font-style: normal; font-weight: 600; }

/* ━━ Boutons ━━ */
.fbx-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px;
    padding: 11px 22px;
    border-radius: 12px;
    font-family: inherit; font-size: 14px; font-weight: 600;
    cursor: pointer; border: none;
    transition: transform .12s, box-shadow .15s;
}
.fbx-btn:hover { transform: translateY(-1px); }
.fbx-btn-primary {
    background: linear-gradient(180deg, #E5D5F5 0%, #BFA0E0 100%);
    color: #3D1A6E;
    border: 1px solid #9F7BCC !important;
    font-weight: 700;
    box-shadow:
        inset 0 1px 2px rgba(255, 255, 255, 0.9),
        0 0 12px rgba(176, 130, 232, 0.5),
        3px 4px 10px rgba(120, 75, 200, 0.35);
}
.fbx-btn-primary:hover {
    background: linear-gradient(180deg, #DDC5F0 0%, #B08AD8 100%);
    box-shadow:
        inset 0 1px 2px rgba(255, 255, 255, 1),
        0 0 18px rgba(176, 130, 232, 0.7),
        4px 6px 14px rgba(120, 75, 200, 0.5);
}

/* ━━ Form URL ━━ */
.fbx-url-form {
    display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;
}
.fbx-url-form input[type="url"] {
    flex: 1; min-width: 240px;
    padding: 11px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-family: inherit; font-size: 14px;
}
.fbx-url-form input[type="url"]:focus {
    outline: 2px solid #243B5C; outline-offset: 0; border-color: #243B5C;
}

/* ═══════════ Queue progression ═══════════ */
.fbx-upload-queue {
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid #e2e8f0;
}
/* Footer fin de chargement */
.fbx-queue-end-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px dashed #cbd5e1;
    animation: fbx-fade-in .35s ease;
}
@keyframes fbx-fade-in {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fbx-queue-end-actions .fbx-btn-primary {
    background: linear-gradient(180deg, #E5D5F5 0%, #BFA0E0 100%);
    color: #3D1A6E;
    border: 1px solid #9F7BCC;
    flex: 1;
    min-width: 220px;
    padding: 12px 18px;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s ease;
    font-family: inherit; font-size: 13px;
}
.fbx-queue-end-actions .fbx-btn-primary:hover {
    box-shadow: 0 4px 12px rgba(36,59,92,0.3);
    transform: translateY(-1px);
}
.fbx-queue-end-actions .fbx-btn-secondary {
    background: #f1f5f9; color: #475569;
    border: 1px solid #cbd5e1;
    padding: 12px 16px;
    border-radius: 10px;
    cursor: pointer;
    transition: all .15s ease;
    font-family: inherit; font-size: 13px; font-weight: 600;
}
.fbx-queue-end-actions .fbx-btn-secondary:hover { background: #e2e8f0; }
.fbx-upload-queue h3 {
    margin: 0 0 12px; font-size: 13px; font-weight: 700;
    color: #475569; text-transform: uppercase; letter-spacing: 0.06em;
}

/* Barre de progression GLOBALE */
.fbx-progress-global {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 14px;
}
.fbx-progress-stats {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 13px; color: #475569;
    margin-bottom: 8px;
}
.fbx-progress-stats strong { color: #243B5C; font-size: 16px; font-weight: 700; }
.fbx-progress-pct {
    font-family: "Sora", sans-serif;
    font-weight: 700; color: #6B33B5; font-size: 15px;
}
.fbx-progress-bar {
    height: 10px;
    background: #f1f5f9;
    border-radius: 6px;
    overflow: hidden;
    position: relative;
}
.fbx-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #16a34a 0%, #6B33B5 100%);
    border-radius: 6px;
    transition: width .3s cubic-bezier(0.22,1,0.36,1);
    position: relative;
    overflow: hidden;
}
.fbx-progress-fill::after {
    content: "";
    position: absolute; inset: 0;
    background: linear-gradient(90deg,
        transparent 0%,
        rgba(255,255,255,0.3) 50%,
        transparent 100%);
    animation: fbx-progress-shine 1.4s ease-in-out infinite;
}
.fbx-progress-global.is-done .fbx-progress-fill::after { animation: none; }
.fbx-progress-global.is-done .fbx-progress-fill {
    background: linear-gradient(90deg, #16a34a, #15803d);
}
@keyframes fbx-progress-shine {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
.fbx-progress-details {
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-top: 8px;
    font-size: 11px; color: #64748b;
}
.fbx-progress-details strong { color: #243B5C; font-weight: 700; }

#fbx-queue-list { list-style: none; padding: 0; margin: 0; max-height: 220px; overflow-y: auto; }
#fbx-queue-list li {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; background: #f8fafc; border-radius: 8px;
    margin-bottom: 6px; font-size: 13px;
}
.fbx-queue-status { font-size: 16px; flex-shrink: 0; }
.fbx-queue-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #2c2a28; }
.fbx-queue-msg  { font-size: 11px; color: #64748b; flex-shrink: 0; }
.fbx-queue-msg.error { color: #dc2626; font-weight: 600; }
.fbx-queue-msg.dup   { color: #ca8a04; }
.fbx-queue-msg.ok    { color: #16a34a; }

kbd {
    background: #f1f5f9; border: 1px solid #cbd5e1; color: #475569;
    padding: 2px 6px; border-radius: 4px; font-family: 'JetBrains Mono', monospace;
    font-size: 11px; margin: 0 2px;
}
</style>

<?php
// Résolution dynamique du chemin API + page FluxBox (compat XAMPP local + Hostinger prod)
$_fbxApiUrl = function_exists('app_url')
    ? app_url('/api/fluxbox_action.php')
    : '/api/fluxbox_action.php';
$_fbxFluxboxUrl = function_exists('app_url')
    ? app_url('/fluxbox_pile.php')
    : '/fluxbox_pile.php';
// Permission d'ajouter des niveaux GED : super admin uniquement (id_role=1)
$_fbxIsAdmin = (int)($_SESSION['id_role'] ?? 0) === 1;
?>
<script>
(function () {
    'use strict';
    const API           = <?= json_encode($_fbxApiUrl, JSON_UNESCAPED_SLASHES) ?>;
    const FLUXBOX_URL   = <?= json_encode($_fbxFluxboxUrl, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF          = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    const IS_ADMIN      = <?= $_fbxIsAdmin ? 'true' : 'false' ?>;

    const modal      = document.getElementById('fbx-upload-modal');
    const fab        = document.getElementById('fbx-upload-fab');
    const rowSoc     = document.getElementById('fbx-row-societes');
    const rowAg      = document.getElementById('fbx-row-agences');
    const rowMet     = document.getElementById('fbx-row-metiers');
    const rowN2      = document.getElementById('fbx-row-n2');
    const rowN3      = document.getElementById('fbx-row-n3');
    const rowN4      = document.getElementById('fbx-row-n4');
    const rowN5      = document.getElementById('fbx-row-n5');
    const labelEl    = document.getElementById('fbx-meta-label-input');
    const entityEl   = document.getElementById('fbx-meta-entity-input');
    const entityReqMsg = document.getElementById('fbx-meta-entity-required-msg');
    const commentEl  = document.getElementById('fbx-meta-comment-input');
    const dateEl     = document.getElementById('fbx-meta-date-input');

    // État sélection courante
    const choice = { societe_id: 0, agence_id: 0, n1: '', n2: '', n3: '', n4: '', n5: '' };

    // État GLOBAL uploads (singleton — survit à la fermeture de la modale)
    if (!window.FluxBoxUploadState) {
        window.FluxBoxUploadState = {
            activeCount: 0,    // uploads en cours
            doneCount:   0,    // succès cumulés (session)
            errCount:    0,    // erreurs cumulées
            dupCount:    0,    // doublons cumulés
            batchTotal:  0,    // total demandé dans la session courante
            duplicates:  [],   // liste des doublons détectés (pour bilan fin upload)
            createdCards: [],  // [Sprint 6 A1] {card_id, doc_id, bien_id} des nouvelles cartes du batch
            _resetTimer: null, // timer reset après inactivité
        };
    }
    const STATE = window.FluxBoxUploadState;

    const fabBadge    = document.getElementById('fbx-fab-badge');
    const topbarBadge = document.getElementById('fbx-topbar-badge');
    const toastWrap   = document.getElementById('fbx-toast-wrap');
    const progressBlock   = document.getElementById('fbx-upload-queue');
    const progressGlobal  = progressBlock?.querySelector('.fbx-progress-global');
    const progressDone    = document.getElementById('fbx-progress-done');
    const progressTotal   = document.getElementById('fbx-progress-total');
    const progressPct     = document.getElementById('fbx-progress-pct');
    const progressFill    = document.getElementById('fbx-progress-fill');
    const progressActive  = document.getElementById('fbx-progress-active');
    const progressOk      = document.getElementById('fbx-progress-ok');
    const progressDup     = document.getElementById('fbx-progress-dup');
    const progressErr     = document.getElementById('fbx-progress-err');
    const endActions      = document.getElementById('fbx-queue-end-actions');

    function updateProgress() {
        if (!progressGlobal) return;
        const total = STATE.batchTotal;
        const processed = STATE.doneCount + STATE.dupCount + STATE.errCount;
        const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
        if (progressDone)   progressDone.textContent   = processed;
        if (progressTotal)  progressTotal.textContent  = total;
        if (progressPct)    progressPct.textContent    = pct;
        if (progressFill)   progressFill.style.width   = pct + '%';
        if (progressActive) progressActive.textContent = STATE.activeCount;
        if (progressOk)     progressOk.textContent     = STATE.doneCount;
        if (progressDup)    progressDup.textContent    = STATE.dupCount;
        if (progressErr)    progressErr.textContent    = STATE.errCount;
        const isDone = STATE.activeCount === 0 && processed > 0 && processed >= total;
        progressGlobal.classList.toggle('is-done', isDone);
        // Affiche le footer "Voir FluxBox / Charger d'autres / Fermer" quand tout est fini
        if (endActions) endActions.hidden = !isDone;
        // Affiche le bilan doublons si fin d'upload + au moins 1 doublon
        if (isDone && STATE.duplicates.length > 0) renderDedupSummary();

        // [Sprint 6 A1] Bouton "📋 Réviser maintenant" — affiché si 1 seule carte créée + bien_id connu
        const reviewBtn = document.getElementById('fbx-go-review');
        if (reviewBtn) {
            const eligible = STATE.createdCards.filter(c => c.bien_id > 0);
            reviewBtn.hidden = !(isDone && eligible.length === 1);
        }
        // Validation directe : dès qu'au moins une carte est créée et le batch terminé
        const valBtn = document.getElementById('fbx-validate-now');
        if (valBtn) valBtn.hidden = !(isDone && STATE.createdCards.length > 0);
    }

    /* Bilan doublons (fin d'upload) — listing batch avec liens vers cartes existantes */
    function renderDedupSummary() {
        const wrap = document.getElementById('fbx-dedup-summary');
        const list = document.getElementById('fbx-dedup-list');
        const countEl = document.getElementById('fbx-dedup-count');
        if (!wrap || !list) return;
        countEl.textContent = STATE.duplicates.length;
        list.innerHTML = '';
        STATE.duplicates.forEach(d => {
            const item = document.createElement('div');
            item.className = 'fbx-dedup-item';
            const orig = d.orig_carte_id
                ? `<a href="<?= function_exists('app_url') ? app_url('/fluxbox_pile.php') : '/fluxbox_pile.php' ?>?carte=${encodeURIComponent(d.orig_carte_id)}" target="_blank">→ Voir carte #${d.orig_carte_id}</a>`
                : (d.orig_ged_doc_id ? `📚 Déjà en GED #${d.orig_ged_doc_id}` : '⚠ supprimée');
            const uploadedAt = d.orig_uploaded_at ? new Date(d.orig_uploaded_at.replace(' ', 'T')).toLocaleString('fr-FR', {dateStyle:'short', timeStyle:'short'}) : '';
            const escape = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            item.innerHTML = `
                <div class="fbx-dedup-item-new">
                    <span class="fbx-dedup-item-label">📥 Tenté maintenant</span>
                    <span class="fbx-dedup-item-name" title="${escape(d.new_filename)}">${escape(d.new_filename)}</span>
                    <span class="fbx-dedup-item-meta">Rejeté (vu ${d.seen_count}× au total)</span>
                </div>
                <div class="fbx-dedup-item-orig">
                    <span class="fbx-dedup-item-label">📂 Déjà présent</span>
                    <span class="fbx-dedup-item-name" title="${escape(d.orig_filename || d.orig_titre)}">${escape(d.orig_filename || d.orig_titre || '(inconnu)')}</span>
                    <span class="fbx-dedup-item-meta">${uploadedAt ? 'Le ' + escape(uploadedAt) : ''} ${d.orig_carte_status ? '· ' + escape(d.orig_carte_status) : ''}</span>
                </div>
                <div class="fbx-dedup-item-action">${orig}</div>
            `;
            list.appendChild(item);
        });
        wrap.hidden = false;
    }

    // Toggle ouverture/fermeture du détail bilan
    document.getElementById('fbx-dedup-toggle')?.addEventListener('click', () => {
        const list = document.getElementById('fbx-dedup-list');
        const btn  = document.getElementById('fbx-dedup-toggle');
        if (!list || !btn) return;
        const willOpen = list.hidden;
        list.hidden = !willOpen;
        btn.textContent = willOpen ? 'Masquer le détail ▲' : 'Voir le détail ▼';
    });

    // Branche les boutons du footer de queue
    document.getElementById('fbx-go-fluxbox')?.addEventListener('click', () => {
        window.location.href = FLUXBOX_URL;
    });
    // Validation directe en GED des cartes du batch (sans passer par la pile)
    document.getElementById('fbx-validate-now')?.addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const cards = STATE.createdCards.filter(c => c.card_id > 0);
        if (!cards.length) return;
        if (!confirm('Classer définitivement en GED ' + cards.length + ' document(s) ?')) return;
        btn.disabled = true; const lbl0 = btn.textContent; btn.textContent = '⏳ Classement…';
        let ok = 0, ko = 0;
        for (const c of cards) {
            try {
                const r = await fetch(API, { method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token':CSRF },
                    body: JSON.stringify({ action:'validate', carte_id: c.card_id, csrf: CSRF }) });
                const j = await r.json();
                if (j && (j.ok || (j.data && j.data.ok))) ok++; else ko++;
            } catch (e) { ko++; }
        }
        btn.textContent = '✅ ' + ok + ' classé(s)' + (ko ? ' · ' + ko + ' échec' : '');
        btn.style.background = ko ? '#b45309' : '#15803d';
        setTimeout(() => { try { (window.parent && window.parent !== window ? window.parent : window).location.reload(); } catch (e) { location.reload(); } }, 1300);
    });
    // [Sprint 6 A1] "Réviser maintenant" → doc_upload_review.php?source=fluxbox
    document.getElementById('fbx-go-review')?.addEventListener('click', () => {
        const eligible = STATE.createdCards.filter(c => c.bien_id > 0);
        if (eligible.length !== 1) return;
        const c = eligible[0];
        const qs = new URLSearchParams({
            source:   'fluxbox',
            ctx_type: 'BIEN',
            bien_id:  String(c.bien_id),
            card_id:  String(c.card_id),
        });
        if (c.doc_id) qs.set('doc_id', String(c.doc_id));
        const base = '<?= function_exists('app_url') ? app_url('/doc_upload_review.php') : '/doc_upload_review.php' ?>';
        window.location.href = base + '?' + qs.toString();
    });
    document.getElementById('fbx-upload-more')?.addEventListener('click', () => {
        // Reset visible de la queue : on cache le footer et on remet la queue à zéro pour un nouvel upload
        if (endActions) endActions.hidden = true;
        const queueList = document.getElementById('fbx-queue-list');
        if (queueList) queueList.innerHTML = '';
        STATE.batchTotal = 0;
        STATE.doneCount  = 0;
        STATE.dupCount   = 0;
        STATE.errCount   = 0;
        STATE.duplicates = [];
        STATE.createdCards = []; // [Sprint 6 A1] reset cartes batch
        // Cache le bilan doublons pour le prochain batch
        const dedupWrap = document.getElementById('fbx-dedup-summary');
        if (dedupWrap) dedupWrap.hidden = true;
        updateProgress();
        // Cache aussi le bloc progression si plus rien
        if (progressBlock) progressBlock.hidden = true;
        // Scroll au top du modal pour le nouveau choix
        modal.querySelector('.fbx-upload-dialog')?.scrollTo({ top: 0, behavior: 'smooth' });
    });

    function updateBadges() {
        const n = STATE.activeCount;
        [fabBadge, topbarBadge].forEach(el => {
            if (!el) return;
            if (n > 0) {
                el.textContent = String(n);
                el.style.display = '';
                el.classList.remove('done');
            } else if (STATE.doneCount > 0 || STATE.dupCount > 0 || STATE.errCount > 0) {
                // Brièvement vert "done" puis cacher après 4s
                el.textContent = '✓';
                el.style.display = '';
                el.classList.add('done');
                setTimeout(() => {
                    if (STATE.activeCount === 0) el.style.display = 'none';
                }, 4000);
            } else {
                el.style.display = 'none';
            }
        });
        updateProgress();

        // Reset auto après 30s d'inactivité (nouvelle "session d'upload" propre)
        if (STATE.activeCount === 0) {
            if (STATE._resetTimer) clearTimeout(STATE._resetTimer);
            STATE._resetTimer = setTimeout(() => {
                STATE.batchTotal = 0;
                STATE.doneCount  = 0;
                STATE.dupCount   = 0;
                STATE.errCount   = 0;
                STATE.createdCards = []; // [Sprint 6 A1]
                updateProgress();
            }, 30000);
        } else if (STATE._resetTimer) {
            clearTimeout(STATE._resetTimer);
            STATE._resetTimer = null;
        }
    }

    function showToast(name, type, msg) {
        if (!toastWrap) return;
        const t = document.createElement('div');
        t.className = 'fbx-toast ' + (type || '');
        const icon = type === 'error' ? '❌' : (type === 'dup' ? '🛡️' : '✅');
        t.innerHTML = `
            <span class="fbx-toast-icon">${icon}</span>
            <div>
                <div class="fbx-toast-msg"><strong></strong></div>
                <div class="fbx-toast-name"></div>
            </div>
        `;
        t.querySelector('.fbx-toast-msg strong').textContent = msg;
        t.querySelector('.fbx-toast-name').textContent = name;
        toastWrap.appendChild(t);
        setTimeout(() => {
            t.classList.add('is-leaving');
            setTimeout(() => t.remove(), 260);
        }, 4000);
        // Limite : max 4 toasts visibles
        while (toastWrap.children.length > 4) {
            toastWrap.firstChild?.remove();
        }
    }

    // Warning si l'user tente de quitter avec uploads en cours
    window.addEventListener('beforeunload', (e) => {
        if (STATE.activeCount > 0) {
            e.preventDefault();
            e.returnValue = `${STATE.activeCount} upload${STATE.activeCount>1?'s':''} en cours — êtes-vous sûr de vouloir quitter ?`;
            return e.returnValue;
        }
    });

    // Au chargement de page : si des uploads étaient persistés en sessionStorage,
    // on pourrait reprendre. Pour V1 : juste init badges (au cas où le compteur a été mis à jour ailleurs)
    updateBadges();
    const dropzone   = document.getElementById('fbx-dropzone');
    const inputFiles = document.getElementById('fbx-input-files');
    const inputFolder= document.getElementById('fbx-input-folder');
    const inputPhoto = document.getElementById('fbx-input-photo');
    const urlInput   = document.getElementById('fbx-input-url');
    const urlSubmit  = document.getElementById('fbx-url-submit');
    const clipZone   = document.getElementById('fbx-clipboard-zone');
    const clipStatus = document.getElementById('fbx-clipboard-status');
    const queueWrap  = document.getElementById('fbx-upload-queue');
    const queueList  = document.getElementById('fbx-queue-list');
    if (!modal) return;

    /* ─── Charge le contexte initial (sociétés + agences + métiers) ─── */
    let contextLoaded = false;
    async function loadContext() {
        if (contextLoaded) return;
        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                // Fix P1-1 (2026-05-26) : on envoie le soc_id du prefill pour que les agences
                // listées correspondent à la société du contexte (pas à la session user qui peut être autre).
                body: JSON.stringify({
                    action: 'fbx_context',
                    csrf: CSRF,
                    societe_id: (window.FBX_PREFILL && window.FBX_PREFILL.soc_id) ? parseInt(window.FBX_PREFILL.soc_id, 10) : null,
                }),
                credentials: 'same-origin',
            });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); }
            catch (_) {
                rowSoc.innerHTML = '<div class="fbx-row-empty">Erreur chargement (HTTP ' + res.status + ')</div>';
                console.error('fbx_context non-JSON:', text.substring(0, 300));
                return;
            }
            if (!data.ok) {
                rowSoc.innerHTML = '<div class="fbx-row-empty">Erreur : ' + (data.errors || []).join(', ') + '</div>';
                return;
            }
            contextLoaded = true;
            renderSocieteButtons(data.data);
            renderMetierButtons(data.data.metiers_grouped || []);

            // ─── Pré-remplissage depuis window.FBX_PREFILL ─────────────────
            // Si la page appelante a injecté un contexte (depuis transaction_index,
            // bien_360, etc.), on l'utilise comme override des défauts BDD.
            // Format : window.FBX_PREFILL = { bien_id, soc_id, age_id, proprio_id, origin }
            const prefill = window.FBX_PREFILL || null;
            const socFinal = (prefill && prefill.soc_id) ? parseInt(prefill.soc_id, 10) : data.data.societe_default;
            const ageFinal = (prefill && prefill.age_id) ? parseInt(prefill.age_id, 10) : data.data.agence_default;
            if (prefill && prefill.bien_id)    choice.bien_id    = parseInt(prefill.bien_id, 10);
            if (prefill && prefill.proprio_id) choice.proprio_id = parseInt(prefill.proprio_id, 10);
            if (prefill && prefill.origin)     choice.origin     = String(prefill.origin);

            if (socFinal) {
                selectSociete(socFinal, data.data.agences || [], ageFinal);
            }

            // Pré-sélection du métier N1 + cascade N2/N3 si fournis dans le prefill
            // (ex: bien en gestion → 03_GESTION_LOCATIVE > BIENS > BIEN)
            // N4 reste vide → l'IA Vision route post-upload (BAUX vs EDL vs DIAGNOSTICS…)
            if (prefill && prefill.n1) {
                setTimeout(() => applyPrefillCascade(prefill.n1, prefill.n2 || '', prefill.n3 || '', prefill.n4 || ''), 0);
            }

            // Correctif UX (2026-06-30) — entité connue (bien/immeuble/tiers…) : le « QUI »
            // (Métier › Domaine › Sous-domaine) est INDUIT. On replie ces 3 lignes en une puce
            // verrouillée et on amène l'utilisateur directement sur la CATÉGORIE (la nature du doc).
            if (prefill && prefill.n1 && prefill.entite_id_bdd) {
                setTimeout(() => collapseQuiRows(prefill), 150);
            }

            // Pré-remplissage NOM DE L'ENTITÉ + verrouillage si le bien est connu
            // (arrivée depuis transaction_index ou bien_360 avec contexte bien validé).
            // L'user n'a plus à saisir → évite les doublons et les saisies fantaisistes.
            if (prefill && prefill.entite_nom) {
                setTimeout(() => {
                    const inp = document.getElementById('fbx-meta-entity-input');
                    if (!inp) return;
                    inp.value = String(prefill.entite_nom);
                    inp.readOnly = true;
                    inp.style.background = '#f0fdf4';
                    inp.style.borderColor = '#86efac';
                    inp.style.cursor = 'not-allowed';
                    inp.title = 'Bien sélectionné depuis ' + (prefill.origin || 'la page appelante') + ' — non modifiable.';
                    const hint = document.getElementById('fbx-meta-entity-hint');
                    if (hint) {
                        const idTxt = prefill.entite_id_bdd ? ' <span style="font-family:DM Mono,monospace;color:#5b21b6;background:#ede9fe;padding:1px 6px;border-radius:4px;">id #' + parseInt(prefill.entite_id_bdd, 10) + '</span>' : '';
                        hint.innerHTML = '<span style="color:#15803d;font-weight:700;">✅ Bien identifié : ' + String(prefill.entite_nom) + idTxt + ' — aucun risque de doublon.</span>';
                    }
                    // Ligne adresse sous le champ
                    let adrLine = document.getElementById('fbx-meta-entity-address');
                    if (prefill.entite_adresse) {
                        if (!adrLine) {
                            adrLine = document.createElement('div');
                            adrLine.id = 'fbx-meta-entity-address';
                            adrLine.style.cssText = 'margin-top:6px;font-size:12px;color:#5a5650;';
                            inp.parentElement.appendChild(adrLine);
                        }
                        adrLine.innerHTML = '📍 ' + String(prefill.entite_adresse);
                    } else if (adrLine) {
                        adrLine.remove();
                    }
                    const req = document.getElementById('fbx-meta-entity-required-msg');
                    if (req) req.style.display = 'none';
                    // (mini-card top "fbx-target-card" affichée par openModal — pas de doublon ici)
                }, 100);
            }
        } catch (e) {
            rowSoc.innerHTML = '<div class="fbx-row-empty">Réseau : ' + e.message + '</div>';
        }
    }

    /* ─── Rendu boutons sociétés ─── */
    function renderSocieteButtons(ctx) {
        const items = ctx.societes || [];
        if (items.length === 0) {
            rowSoc.innerHTML = '<div class="fbx-row-empty">Aucune société accessible.</div>';
            return;
        }
        rowSoc.innerHTML = '';
        items.forEach(s => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fbx-choice-btn';
            btn.dataset.id = s.id;
            btn.dataset.code = s.code;
            btn.innerHTML = `<span class="fbx-choice-code">${s.code}</span><span class="fbx-choice-label">${s.nom}</span>`;
            btn.addEventListener('click', () => {
                if (!ctx.can_change_societe && items.length === 1) return;
                selectSociete(s.id, null, 0);
            });
            rowSoc.appendChild(btn);
        });
    }

    /* ─── Sélection société → recharge agences ─── */
    async function selectSociete(socId, prefetchedAgences = null, defaultAgenceId = 0) {
        choice.societe_id = socId;
        choice.agence_id = 0;
        // Marquage UI
        rowSoc.querySelectorAll('.fbx-choice-btn').forEach(b => {
            b.classList.toggle('is-selected', parseInt(b.dataset.id) === socId);
        });
        fbxUpdateCtxBar();

        // Charge ou utilise les agences pré-fetchées
        let agences = prefetchedAgences;
        if (!agences) {
            rowAg.innerHTML = '<div class="fbx-loading">Chargement…</div>';
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({ action: 'fbx_context', csrf: CSRF, societe_id: socId }),
                    credentials: 'same-origin',
                });
                const data = await res.json();
                agences = data.ok ? (data.data.agences || []) : [];
            } catch (e) { agences = []; }
        }
        renderAgenceButtons(agences);
        if (defaultAgenceId) {
            const def = agences.find(a => a.id === defaultAgenceId);
            if (def) selectAgence(def.id);
        }
    }

    function renderAgenceButtons(agences) {
        rowAg.innerHTML = '';
        // Bouton spécial : doc niveau société (pas d'agence spécifique)
        const btnSoc = document.createElement('button');
        btnSoc.type = 'button';
        btnSoc.className = 'fbx-choice-btn fbx-choice-btn-societe';
        btnSoc.dataset.id = '0';
        btnSoc.title = 'Document au niveau société (pas d\'agence spécifique)';
        btnSoc.innerHTML = `<span class="fbx-choice-icon">🏢</span><span class="fbx-choice-label">Société uniquement</span>`;
        btnSoc.addEventListener('click', () => selectAgence(0));
        rowAg.appendChild(btnSoc);

        if (!agences || agences.length === 0) {
            // Aucune agence — par défaut on sélectionne "Société uniquement"
            selectAgence(0);
            return;
        }
        agences.forEach(a => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fbx-choice-btn';
            btn.dataset.id = a.id;
            btn.innerHTML = `<span class="fbx-choice-code">${a.code}</span><span class="fbx-choice-label">${a.nom}</span>`;
            btn.addEventListener('click', () => selectAgence(a.id));
            rowAg.appendChild(btn);
        });
    }

    function selectAgence(agenceId) {
        choice.agence_id = agenceId;
        rowAg.querySelectorAll('.fbx-choice-btn').forEach(b => {
            b.classList.toggle('is-selected', parseInt(b.dataset.id) === agenceId);
        });
        fbxUpdateCtxBar();
    }

    /* ─── Barre de contexte compacte (résumé société · agence · métier) ───
       Lit le libellé du bouton sélectionné de chaque ligne et l'affiche dans le
       summary, pour que l'utilisateur voie l'essentiel SANS déplier ni scroller. */
    function fbxCtxLabel(rowEl) {
        const sel = rowEl && rowEl.querySelector('.fbx-choice-btn.is-selected .fbx-choice-label');
        return sel ? sel.textContent.trim() : '';
    }
    function fbxUpdateCtxBar() {
        const bar = document.getElementById('fbx-ctx-bar');
        if (!bar) return;
        const vals = { soc: fbxCtxLabel(rowSoc) || '—',
                       age: fbxCtxLabel(rowAg) || '—',
                       met: fbxCtxLabel(rowMet) || '—' };
        Object.keys(vals).forEach(k => {
            const b = bar.querySelector('.fbx-ctx-chip[data-k="' + k + '"] b');
            if (b) b.textContent = vals[k];
        });
    }

    /* ─── Rendu boutons métiers (1 bouton par N1 — grille 5/ligne avec icône) ─── */
    // Ordre d'affichage + icône + label court par N1 (validé EMERY 2026-05-16)
    const METIER_DEF = {
        '01_AGENCE':                 { icon: '🏬', label: 'Agence',       pos:  1 },
        '02_RH':                     { icon: '👥', label: 'RH',           pos:  2 },
        '06_COMPTABILITE':           { icon: '💰', label: 'Compta',       pos:  3 },
        '01_DIRECTION':              { icon: '⚙️', label: 'Direction',    pos:  4 },
        '07_JURIDIQUE_CONTENTIEUX':  { icon: '⚖️', label: 'Juridique',    pos:  5 },
        '08_MARKETING_COMMUNICATION':{ icon: '📣', label: 'Marketing',    pos:  6 },
        '09_MODELES_DOCUMENTS':      { icon: '📄', label: 'Modèles',      pos:  7 },
        '10_REFERENTIEL':            { icon: '📚', label: 'Référentiel',  pos:  8 },
        '12_ARCHIVES':               { icon: '📦', label: 'Archives',     pos:  9 },
        '99_SYSTEME':                { icon: '🛠️', label: 'Système',      pos: 10 },
        '11_MAILS_COMMUNICATIONS':   { icon: '📧', label: 'Mail & Comm',  pos: 11 },
        '03_GESTION_LOCATIVE':       { icon: '🏠', label: 'Gestion',      pos: 12 },
        '04_SYNDIC':                 { icon: '🏢', label: 'Syndic',       pos: 13 },
        '05_TRANSACTION':            { icon: '🤝', label: 'Transaction',  pos: 14 },
        '13_FOURNISSEURS':           { icon: '🚚', label: 'Fournisseurs', pos: 15 },
    };

    function renderMetierButtons(grouped) {
        if (!grouped || grouped.length === 0) {
            rowMet.innerHTML = '<div class="fbx-row-empty">Aucun métier seedé. Applique d\'abord la migration GED.</div>';
            return;
        }
        // Aplatir : grouped est par business_group → on récupère tous les codes N1
        const allCodes = [];
        grouped.forEach(g => (g.codes || []).forEach(c => allCodes.push(c)));
        // Trier selon METIER_DEF.pos (codes inconnus relégués en fin)
        allCodes.sort((a, b) => {
            const pa = METIER_DEF[a.code]?.pos ?? 999;
            const pb = METIER_DEF[b.code]?.pos ?? 999;
            return pa - pb;
        });
        rowMet.innerHTML = '';
        allCodes.forEach(c => {
            const def = METIER_DEF[c.code] || { icon: '📁', label: (c.label || '').replace(/^\d+\s*-\s*/, '') };
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fbx-choice-btn';
            btn.dataset.code = c.code;
            btn.title = c.code;
            btn.innerHTML = `<span class="fbx-choice-icon">${def.icon}</span><span class="fbx-choice-label">${def.label}</span>`;
            btn.addEventListener('click', () => selectMetier(c.code));
            rowMet.appendChild(btn);
        });
    }

    async function selectMetier(n1) {
        choice.n1 = n1;
        choice.n2 = ''; choice.n3 = ''; choice.n4 = ''; choice.n5 = '';
        rowMet.querySelectorAll('.fbx-choice-btn').forEach(b => {
            b.classList.toggle('is-selected', b.dataset.code === n1);
        });
        fbxUpdateCtxBar();
        // Reset cascade aval (N2-N5)
        rowN2.innerHTML = '<div class="fbx-loading">Chargement…</div>';
        rowN3.innerHTML = '<div class="fbx-row-empty">— Choisir un domaine d\'abord —</div>';
        rowN4.innerHTML = '<div class="fbx-row-empty">— Choisir un sous-domaine d\'abord —</div>';
        rowN5.innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie d\'abord —</div>';
        const items = await loadGedChildren(2, { n1 });
        renderBtnGrid(rowN2, items, '— Aucun domaine seedé —', (code) => onPickN2(code));
    }

    async function loadGedChildren(level, parents) {
        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ action: 'ged_cascade', level, ...parents, csrf: CSRF }),
                credentials: 'same-origin',
            });
            const data = await res.json();
            return data.ok ? (data.data.items || []) : [];
        } catch (_) { return []; }
    }

    /* Rend une grille de boutons carrés dans un container, et bind onPick(code) */
    function renderBtnGrid(container, items, emptyMsg, onPick) {
        container.innerHTML = '';
        const hasItems = items && items.length > 0;
        if (!hasItems) {
            const e = document.createElement('div');
            e.className = 'fbx-row-empty';
            e.textContent = emptyMsg;
            container.appendChild(e);
        } else {
            items.forEach(it => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'fbx-choice-btn';
                btn.dataset.code = it.code;
                const lab = document.createElement('span');
                lab.className = 'fbx-choice-label';
                lab.textContent = it.label || it.code;
                btn.appendChild(lab);
                btn.addEventListener('click', () => {
                    container.querySelectorAll('.fbx-choice-btn').forEach(b =>
                        b.classList.toggle('is-selected', b === btn));
                    onPick(it.code);
                });
                container.appendChild(btn);
            });
        }
        // Bouton + admin pour créer une nouvelle référence à ce niveau
        appendAddRefButton(container);
    }

    /* Ajoute un bouton "+" à la fin d'une grille — visible uniquement pour admin */
    function appendAddRefButton(container) {
        if (!IS_ADMIN) return;
        const level = parseInt(container.dataset.metaLevel || '0', 10);
        if (![2, 3, 4, 5].includes(level)) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fbx-choice-btn fbx-btn-add-ref';
        btn.title = 'Ajouter une nouvelle référence à ce niveau (admin)';
        btn.innerHTML = '<span class="fbx-choice-icon">+</span><span class="fbx-choice-label">Ajouter</span>';
        btn.addEventListener('click', () => promptAddLevelCode(level, container));
        container.appendChild(btn);
    }

    /* Workflow d'ajout : prompt code + label, POST API, reload grille, auto-sélection du nouveau code */
    async function promptAddLevelCode(level, container) {
        const niveauLabel = {2: 'Domaine', 3: 'Sous-domaine', 4: 'Catégorie', 5: 'Sous-catégorie'}[level] || 'Niveau';
        // Pré-checks parents
        const parents = {
            n1: choice.n1,
            n2: level >= 3 ? choice.n2 : '',
            n3: level >= 4 ? choice.n3 : '',
            n4: level >= 5 ? choice.n4 : '',
        };
        if (level >= 2 && !parents.n1) { alert('Choisis d\'abord un Métier.'); return; }
        if (level >= 3 && !parents.n2) { alert('Choisis d\'abord un Domaine.'); return; }
        if (level >= 4 && !parents.n3) { alert('Choisis d\'abord un Sous-domaine.'); return; }
        if (level >= 5 && !parents.n4) { alert('Choisis d\'abord une Catégorie.'); return; }

        const label = (prompt(`Nouveau ${niveauLabel.toLowerCase()} — Libellé affiché\n\n(ex pour un collab : « Dupont Pierre », pour une banque : « Crédit Agricole CE ») :`) || '').trim();
        if (!label) return;
        const codeSuggested = label.toUpperCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '') // strip accents (combining marks Unicode)
            .replace(/[^A-Z0-9]+/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
        const code = (prompt(`Code court (A-Z 0-9 _)\n\nPar défaut : « ${codeSuggested} »\n(modifie si tu veux un code plus court)`, codeSuggested) || '').trim().toUpperCase();
        if (!code) return;

        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({
                    action: 'add_level_code',
                    csrf: CSRF,
                    level, code, label,
                    parent_n1: parents.n1,
                    parent_n2: parents.n2,
                    parent_n3: parents.n3,
                    parent_n4: parents.n4,
                }),
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!data.ok) {
                alert('Erreur : ' + ((data.errors || []).join(', ') || 'inconnue'));
                return;
            }
            const createdCode  = (data.data && data.data.code) || code;
            const copied       = (data.data && data.data.children_copied) || 0;
            const siblingUsed  = (data.data && data.data.sibling_used) || null;
            if (copied > 0 && siblingUsed) {
                showToast(createdCode, 'success',
                    `✅ Créé · ${copied} sous-niveau${copied>1?'x':''} clonés depuis « ${siblingUsed} »`);
            }
            // Recharge la grille du niveau concerné puis auto-sélectionne le nouveau code
            const items = await loadGedChildren(level, parents);
            const onPickCb = {
                2: (c) => onPickN2(c),
                3: (c) => onPickN3(c),
                4: (c) => onPickN4(c),
                5: (c) => { choice.n5 = c; },
            }[level];
            const emptyMsg = {
                2: '— Aucun domaine seedé —',
                3: '— Aucun sous-domaine seedé —',
                4: '— Aucune catégorie seedée —',
                5: '— Aucune sous-catégorie seedée —',
            }[level];
            const rowEl = {2: rowN2, 3: rowN3, 4: rowN4, 5: rowN5}[level];
            renderBtnGrid(rowEl, items, emptyMsg, onPickCb);
            // Auto-clique le nouveau bouton pour qu'il devienne sélectionné
            const newBtn = rowEl.querySelector(`.fbx-choice-btn[data-code="${CSS.escape(createdCode)}"]`);
            if (newBtn) {
                newBtn.click();
                newBtn.scrollIntoView({ block: 'center', behavior: 'smooth' });
                // Petit flash visuel
                newBtn.style.transition = 'box-shadow .4s';
                newBtn.style.boxShadow = '0 0 0 3px #6B33B5';
                setTimeout(() => { newBtn.style.boxShadow = ''; }, 800);
            } else {
                alert('Code créé en BDD mais introuvable dans la liste — vérifie en BDD ou recharge la page.\nCode : ' + createdCode);
            }
        } catch (e) {
            alert('Erreur réseau : ' + e.message);
        }
    }

    /* Cascade automatique depuis un prefill (N1 → N2 → N3 → N4) ─────────
       Utilisé par window.FBX_PREFILL pour pré-remplir toute la hiérarchie
       quand on arrive depuis un contexte connu (transaction_index, bien_360).
       Chaque étape attend que la précédente ait fini son async (boutons render)
       avant d'enchainer + marquer visuellement le bouton sélectionné.
       Helper waitFrame : assure que le DOM est paint avant de querySelector. */
    function waitFrame() { return new Promise(r => requestAnimationFrame(r)); }
    function markSelected(container, code) {
        if (!container || !code) return;
        container.querySelectorAll('.fbx-choice-btn').forEach(b =>
            b.classList.toggle('is-selected', b.dataset.code === code));
    }
    /* ─── Replie les 3 niveaux « QUI » induits en une puce verrouillée (entité connue) ───
       Le rangement (Métier › Domaine › Sous-domaine › entité) est déterminé par la page
       d'origine (bien_360, immeuble_360, transaction_dossier…). On ne le redemande pas :
       on l'affiche en lecture seule + bouton « Modifier le rangement », et le curseur
       arrive directement sur CATÉGORIE. Cascade déjà jouée par applyPrefillCascade(). */
    function fbxHumanizeSlug(s) {
        if (!s) return '';
        var map = {'03_GESTION_LOCATIVE':'Gestion locative','04_SYNDIC':'Syndic','05_TRANSACTION':'Transaction',
                   '06_TRANSACTION':'Transaction','BIENS':'Biens','BIEN':'Bien','IMMEUBLES':'Immeubles','IMMEUBLE':'Immeuble',
                   'PROPRIETAIRES':'Propriétaires','LOCATAIRES':'Locataires'};
        if (map[s]) return map[s];
        var t = String(s).replace(/^\d+[_-]/, '').replace(/[_-]+/g, ' ').trim();
        return t ? t.charAt(0).toUpperCase() + t.slice(1).toLowerCase() : '';
    }
    function collapseQuiRows(prefill) {
        var rowMetEl = document.getElementById('fbx-row-metiers');
        var rowN2El  = document.getElementById('fbx-row-n2');
        var rowN3El  = document.getElementById('fbx-row-n3');
        if (!rowMetEl || !rowN2El || !rowN3El) return;
        var blocks = [rowMetEl, rowN2El, rowN3El].map(function(e){ return e.closest('.fbx-row-block'); }).filter(Boolean);
        if (!blocks.length || document.getElementById('fbx-qui-chip')) return;

        var parts = [fbxHumanizeSlug(prefill.n1), fbxHumanizeSlug(prefill.n2), fbxHumanizeSlug(prefill.n3)].filter(Boolean);
        var ent = prefill.entite_nom ? (' · ' + prefill.entite_nom) : '';
        var chip = document.createElement('div');
        chip.id = 'fbx-qui-chip';
        chip.className = 'fbx-row-block';
        chip.style.cssText = 'background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:10px 14px;margin-bottom:10px;';
        chip.innerHTML =
            '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
          + '<span style="font-size:16px;">📂</span>'
          + '<span style="font-size:12.5px;color:#166534;"><strong>Rangé dans :</strong> '
          + parts.join(' › ') + '<span style="color:#15803d;">' + ent + '</span></span>'
          + '<button type="button" id="fbx-qui-edit" style="margin-left:auto;font-size:11px;font-weight:600;'
          + 'border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:7px;padding:3px 10px;cursor:pointer;">✏️ Modifier le rangement</button>'
          + '</div>'
          + '<div style="margin-top:4px;font-size:11px;color:#15803d;">👉 Choisis seulement la <strong>catégorie</strong> du document ci-dessous.</div>';

        blocks[0].parentNode.insertBefore(chip, blocks[0]);
        blocks.forEach(function(b){ b.style.display = 'none'; });

        document.getElementById('fbx-qui-edit').addEventListener('click', function(){
            blocks.forEach(function(b){ b.style.display = ''; });
            chip.remove();
        });
    }

    async function applyPrefillCascade(n1, n2, n3, n4) {
        if (!n1) return;
        await selectMetier(n1);            // render N2 + marquage auto N1
        await waitFrame();
        if (!n2) return;
        markSelected(rowN2, n2);
        await onPickN2(n2);                // render N3
        await waitFrame();
        if (!n3) return;
        markSelected(rowN3, n3);
        await onPickN3(n3);                // render N4
        await waitFrame();
        if (!n4) return;
        markSelected(rowN4, n4);
        await onPickN4(n4);                // render N5
        await waitFrame();
    }

    async function onPickN2(code) {
        choice.n2 = code;
        choice.n3 = ''; choice.n4 = ''; choice.n5 = '';
        rowN3.innerHTML = '<div class="fbx-loading">Chargement…</div>';
        rowN4.innerHTML = '<div class="fbx-row-empty">— Choisir un sous-domaine d\'abord —</div>';
        rowN5.innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie d\'abord —</div>';
        const items = await loadGedChildren(3, { n1: choice.n1, n2: choice.n2 });
        renderBtnGrid(rowN3, items, '— Aucun sous-domaine seedé —', (c) => onPickN3(c));
    }

    async function onPickN3(code) {
        choice.n3 = code;
        choice.n4 = ''; choice.n5 = '';
        rowN4.innerHTML = '<div class="fbx-loading">Chargement…</div>';
        rowN5.innerHTML = '<div class="fbx-row-empty">— Choisir une catégorie d\'abord —</div>';
        const items = await loadGedChildren(4, { n1: choice.n1, n2: choice.n2, n3: choice.n3 });
        renderBtnGrid(rowN4, items, '— Aucune catégorie seedée —', (c) => onPickN4(c));
    }

    async function onPickN4(code) {
        choice.n4 = code;
        choice.n5 = '';
        rowN5.innerHTML = '<div class="fbx-loading">Chargement…</div>';
        const items = await loadGedChildren(5, { n1: choice.n1, n2: choice.n2, n3: choice.n3, n4: choice.n4 });
        renderBtnGrid(rowN5, items, '— Aucune sous-catégorie seedée —', (c) => { choice.n5 = c; });
    }

    /* ─── Récup métadonnées user (commentaire + classement + date + libellé + entité) ─── */
    function getMetadata() {
        const pf = window.FBX_PREFILL || {};
        return {
            user_comment:    (commentEl?.value || '').trim(),
            user_label:      (labelEl?.value   || '').trim(),
            entity_instance: (entityEl?.value  || '').trim(),
            ged_n1: choice.n1 || '',
            ged_n2: choice.n2 || '',
            ged_n3: choice.n3 || '',
            ged_n4: choice.n4 || '',
            ged_n5: choice.n5 || '',
            target_societe_id: choice.societe_id || '',
            target_agence_id:  choice.agence_id  || '',
            target_date: (dateEl?.value || '').trim(), // YYYY-MM-DD
            // [V3.1 — 2026-05-25] Prefill métier propagé pour naming entité polymorphe
            prefill_bien_id:     pf.bien_id     ? parseInt(pf.bien_id, 10)     : 0,
            prefill_immeuble_id: pf.immeuble_id ? parseInt(pf.immeuble_id, 10) : 0,
            prefill_tiers_id:    pf.proprio_tiers_id ? parseInt(pf.proprio_tiers_id, 10)
                               : (pf.tiers_id ? parseInt(pf.tiers_id, 10) : 0),
            prefill_bail_id:     pf.bail_id ? parseInt(pf.bail_id, 10) : 0,
            prefill_creancier_dossier_id: pf.creancier_dossier_id ? parseInt(pf.creancier_dossier_id, 10) : 0,
            prefill_origin:      pf.origin || '',
        };
    }

    /* ─── Ouverture / fermeture ─── */
    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        // Si un prefill est posé par la page appelante, force le reload du contexte
        // pour appliquer le nouveau bien/société/agence (sinon la 2ème ouverture ignore).
        // Et auto-ouvre l'accordéon classement pour que l'user voie la pré-sélection.
        const metaDetails = document.getElementById('fbx-meta-details');
        // UX 2026-06-30 : section TOUJOURS repliée par défaut (zéro scroll). La barre de
        // contexte compacte (société · agence · métier) résume l'état ; clic = déplie pour corriger.
        if (metaDetails) metaDetails.open = false;
        if (window.FBX_PREFILL) {
            contextLoaded = false;
            renderTargetCard(window.FBX_PREFILL);   // mini-card "Bien ciblé" visible immédiatement
        } else {
            removeTargetCard();
        }
        loadContext(); // charge sociétés + agences + métiers au premier open
    }

    /* Mini-cards "Bien ciblé" + "Propriétaire" côte à côte tout en haut de la
       modal. Affichage immédiat (synchrone) à l'ouverture — on en est certain
       car le prefill contient toutes les infos (ref, ID BDD, adresse, proprio).
       Clic = ouvre bien_360 / tiers_360 dans nouvel onglet. */
    function renderTargetCard(prefill) {
        if (!prefill || !prefill.entite_nom) { removeTargetCard(); return; }
        // Fix Bug 1 (2026-05-26) : mettre à jour les hidden inputs HTML visibles pour QA + audit
        const setHidden = (id, val) => { const el = document.getElementById(id); if (el) el.value = String(val ?? ''); };
        setHidden('fbx-prefill-bien-id',     prefill.bien_id          || 0);
        setHidden('fbx-prefill-creancier-dossier-id', prefill.creancier_dossier_id || 0);
        setHidden('fbx-prefill-immeuble-id', prefill.immeuble_id      || 0);
        setHidden('fbx-prefill-tiers-id',    prefill.proprio_tiers_id || prefill.tiers_id || 0);
        setHidden('fbx-prefill-bail-id',     prefill.bail_id          || 0);
        setHidden('fbx-prefill-soc-id',      prefill.soc_id           || 0);
        setHidden('fbx-prefill-age-id',      prefill.age_id           || 0);
        setHidden('fbx-prefill-origin',      prefill.origin           || '');
        setHidden('fbx-prefill-mode',        prefill.mode_dossier_proprio ? 'dossier_proprio' : (prefill.bien_id ? 'bien' : 'libre'));

        // Fix P0-2 (2026-05-26) : en mode dossier propriétaire (tiers sans bien),
        // on masque la mini-card BIEN car elle est trompeuse — l'IA matchera bien individuel.
        const isProprietaireOnly = (
            prefill.mode_dossier_proprio === true
            || ((!prefill.bien_id || parseInt(prefill.bien_id, 10) === 0)
                && (prefill.proprio_tiers_id || prefill.proprio_id))
        );
        let row = document.getElementById('fbx-target-row');
        if (!row) {
            row = document.createElement('div');
            row.id = 'fbx-target-row';
            row.className = 'fbx-target-row';
            const subtitle = modal.querySelector('.fbx-upload-subtitle');
            if (subtitle && subtitle.parentElement) {
                subtitle.insertAdjacentElement('afterend', row);
            } else {
                modal.querySelector('.fbx-upload-dialog')?.prepend(row);
            }
        }
        const base       = (typeof window.APP_BASE === 'string' && window.APP_BASE) ? window.APP_BASE : '';
        const originLbl  = prefill.origin === 'bien_360' ? 'fiche 360°'
                         : prefill.origin === 'transaction_index' ? 'tableau Transaction'
                         : (prefill.origin || 'page appelante');

        // ── Mini-card BIEN (gauche) — vert amande #84a98c ──
        const ref      = String(prefill.entite_nom);
        const idBdd    = prefill.entite_id_bdd ? parseInt(prefill.entite_id_bdd, 10) : 0;
        const adresse  = prefill.entite_adresse ? String(prefill.entite_adresse) : '';
        const idBadge  = idBdd > 0 ? '<span class="fbx-target-id">id #' + idBdd + '</span>' : '';
        const adrLine  = adresse ? '<div class="fbx-target-addr">📍 ' + adresse + '</div>' : '';
        const urlBien  = idBdd > 0 ? base + '/bien_360.php?id=' + idBdd : '#';
        const bienHtml =
            '<a class="fbx-target-card fbx-target-bien" href="' + urlBien + '" target="_blank" rel="noopener" title="Ouvrir la fiche 360° du bien (nouvel onglet)">'
          +   '<div class="fbx-target-icon">🏠</div>'
          +   '<div class="fbx-target-body">'
          +     '<div class="fbx-target-label">DOCUMENT POUR LE BIEN</div>'
          +     '<div class="fbx-target-name">' + ref + idBadge + '</div>'
          +     adrLine
          +     '<div class="fbx-target-from">→ depuis ' + originLbl + ' · 🔗 360°</div>'
          +   '</div>'
          + '</a>';

        // ── Mini-card PROPRIÉTAIRE (droite) — pétrole cyan #0e7490 ──
        let proprioHtml = '';
        if (prefill.proprio_nom || prefill.proprio_id) {
            const pNom      = prefill.proprio_nom ? String(prefill.proprio_nom) : 'Propriétaire #' + parseInt(prefill.proprio_id || 0, 10);
            const pTiersId  = prefill.proprio_tiers_id ? parseInt(prefill.proprio_tiers_id, 10) : 0;
            const pId       = prefill.proprio_id ? parseInt(prefill.proprio_id, 10) : 0;
            const pIdBadge  = pTiersId > 0 ? '<span class="fbx-target-id">tiers #' + pTiersId + '</span>'
                            : (pId > 0 ? '<span class="fbx-target-id">proprio #' + pId + '</span>' : '');
            const pRepLine  = prefill.proprio_representant ? '<div class="fbx-target-addr">👥 ' + String(prefill.proprio_representant) + '</div>' : '';
            const pUrl      = pTiersId > 0 ? base + '/tiers_360.php?id=' + pTiersId : '#';
            proprioHtml =
                '<a class="fbx-target-card fbx-target-proprio" href="' + pUrl + '" target="_blank" rel="noopener" title="Ouvrir la fiche 360° du propriétaire (nouvel onglet)">'
              +   '<div class="fbx-target-icon">👤</div>'
              +   '<div class="fbx-target-body">'
              +     '<div class="fbx-target-label">PROPRIÉTAIRE</div>'
              +     '<div class="fbx-target-name">' + pNom + pIdBadge + '</div>'
              +     pRepLine
              +     '<div class="fbx-target-from">🔗 360°</div>'
              +   '</div>'
              + '</a>';
        }
        // Fix P0-2 : en mode propriétaire seul, on masque la card BIEN
        row.innerHTML = isProprietaireOnly ? proprioHtml : (bienHtml + proprioHtml);
    }
    function removeTargetCard() {
        const c = document.getElementById('fbx-target-row');
        if (c) c.remove();
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    fab?.addEventListener('click', openModal);
    // Topbar trigger (présent ailleurs dans le layout)
    document.querySelectorAll('#fbx-upload-open, [data-fbx-open]').forEach(el => {
        el.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
    });
    // ─── API globale pour ouvrir la modal depuis n'importe où ────────
    // Usage : window.fbxOpenUploadModal({ bien_id:897, soc_id:3, age_id:12, proprio_id:52, origin:'transaction' })
    // Le prefill est posé sur window.FBX_PREFILL et appliqué au load_context.
    window.fbxOpenUploadModal = function(prefill) {
        if (prefill && typeof prefill === 'object') {
            window.FBX_PREFILL = prefill;
        }
        openModal();
    };
    modal.querySelectorAll('[data-fbx-close]').forEach(el => el.addEventListener('click', closeModal));

    /* ═══════════════════════════════════════════════════════════════════════
       RECHERCHE UNIVERSELLE D'ENTITÉ (champ « Nom de l'entité »)
       Tape un nom → propriétaire / locataire / immeuble / bien / collaborateur.
       Choisir un résultat renseigne d'un coup : société · agence · métier ·
       domaine · sous-domaine (branche GED de la fiche), et ouvre la CATÉGORIE.
       Zéro <select>, cascade de boutons. Si l'user ne cherche pas → l'IA décide.
       ═══════════════════════════════════════════════════════════════════════ */
    (function initEntitySearch() {
        const input = document.getElementById('fbx-meta-entity-input');
        const out   = document.getElementById('fbx-entity-results');
        if (!input || !out) return;
        const base = () => (typeof window.APP_BASE === 'string' && window.APP_BASE) ? window.APP_BASE : '';
        let timer = null;

        function esc(s){ return String(s == null ? '' : s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }

        async function applyEntity(it) {
            // 1. Prefill global (attache le doc à la bonne entité côté upload)
            const pf = { origin: 'fluxbox_search', n1: it.n1 || '', n2: it.n2 || '', n3: it.n3 || '',
                         soc_id: it.societe_id || 0, age_id: it.agence_id || 0,
                         entite_nom: it.label, entite_id_bdd: it.id };
            if (it.entity_type === 'bien')          pf.bien_id = it.id;
            else if (it.entity_type === 'immeuble') pf.immeuble_id = it.id;
            else if (it.entity_type === 'tiers')    pf.proprio_tiers_id = it.id;
            window.FBX_PREFILL = pf;

            // 2. Société + agence de la fiche (source=fiche/vert)
            if (pf.soc_id) { try { await selectSociete(parseInt(pf.soc_id,10), null, parseInt(pf.age_id,10)||0); } catch(e){} }
            // 3. Métier › Domaine › Sous-domaine (branche entité) → ouvre la Catégorie
            if (pf.n1) { try { await applyPrefillCascade(pf.n1, pf.n2, pf.n3, ''); } catch(e){} }
            // 4. Mini-card + hidden inputs + verrouillage du champ
            try { renderTargetCard(pf); } catch(e){}
            input.value = it.label;
            input.readOnly = true;
            input.style.background = '#f0fdf4'; input.style.borderColor = '#86efac'; input.style.cursor = 'not-allowed';
            const hint = document.getElementById('fbx-meta-entity-hint');
            if (hint) hint.innerHTML = '<span style="color:#15803d;font-weight:700;">✅ ' + esc(it.badge) + ' : ' + esc(it.label) + ' — classement rempli. Choisis la catégorie du document.</span>';
            // 5. Déplie pour montrer la Catégorie + rafraîchit la barre compacte
            const md = document.getElementById('fbx-meta-details'); if (md) md.open = true;
            fbxUpdateCtxBar();
            out.classList.remove('is-open'); out.innerHTML = '';
        }

        function render(items) {
            out.innerHTML = '';
            if (!items || !items.length) { out.classList.remove('is-open'); return; }
            items.forEach(it => {
                const el = document.createElement('button');
                el.type = 'button'; el.className = 'fbx-entity-res';
                el.innerHTML = '<div class="ttl"><span class="b">' + esc(it.badge) + '</span>' + esc(it.label) + '</div>'
                             + (it.repere1 ? '<div class="rp">' + esc(it.repere1) + '</div>' : '')
                             + (it.repere2 ? '<div class="rp">' + esc(it.repere2) + '</div>' : '');
                el.addEventListener('click', () => applyEntity(it));
                out.appendChild(el);
            });
            out.classList.add('is-open');
        }

        input.addEventListener('input', () => {
            if (input.readOnly) return;
            const q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { out.classList.remove('is-open'); out.innerHTML = ''; return; }
            timer = setTimeout(async () => {
                try {
                    const res = await fetch(base() + '/api/fluxbox_entity_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
                    const data = await res.json();
                    render(data.ok ? (data.results || []) : []);
                } catch (e) { out.classList.remove('is-open'); }
            }, 220);
        });
        // Clic hors résultats → ferme
        document.addEventListener('click', (e) => {
            if (!out.contains(e.target) && e.target !== input) out.classList.remove('is-open');
        });
    })();

    // Raccourci Ctrl+U / Cmd+U
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault(); openModal();
        }
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });

    /* ─── Tabs ─── */
    modal.querySelectorAll('.fbx-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-tab');
            modal.querySelectorAll('.fbx-tab').forEach(t => t.classList.toggle('is-active', t === tab));
            modal.querySelectorAll('.fbx-pane').forEach(p =>
                p.classList.toggle('is-active', p.getAttribute('data-pane') === target));
            if (target === 'clipboard') setTimeout(() => clipZone?.focus(), 50);
        });
    });

    /* ─── Drag & drop ─── */
    dropzone?.addEventListener('click', () => inputFiles?.click());
    ['dragenter','dragover'].forEach(ev => dropzone?.addEventListener(ev, (e) => {
        e.preventDefault(); e.stopPropagation();
        dropzone.classList.add('is-dragover');
    }));
    ['dragleave','drop'].forEach(ev => dropzone?.addEventListener(ev, (e) => {
        e.preventDefault(); e.stopPropagation();
        dropzone.classList.remove('is-dragover');
    }));
    dropzone?.addEventListener('drop', (e) => {
        const files = e.dataTransfer?.files;
        if (files && files.length > 0) uploadFiles(files);
    });
    inputFiles?.addEventListener('change', () => {
        if (inputFiles.files && inputFiles.files.length > 0) uploadFiles(inputFiles.files);
    });
    inputFolder?.addEventListener('change', () => {
        if (inputFolder.files && inputFolder.files.length > 0) uploadFiles(inputFolder.files);
    });
    inputPhoto?.addEventListener('change', () => {
        if (inputPhoto.files && inputPhoto.files.length > 0) uploadFiles(inputPhoto.files);
    });

    /* ─── URL distante ─── */
    urlSubmit?.addEventListener('click', async () => {
        const url = (urlInput?.value || '').trim();
        if (!url) { alert('Entrez une URL.'); return; }
        if (!/^https:\/\//i.test(url)) { alert('HTTPS uniquement.'); return; }
        const li = addQueueItem(url, '⏳', 'Téléchargement…');
        const meta = getMetadata();
        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ action: 'ingest_url', url, csrf: CSRF, ...meta }),
                credentials: 'same-origin',
            });
            const data = await res.json();
            renderResult(li, data);
            if (data.ok) urlInput.value = '';
        } catch (e) {
            updateQueueItem(li, '❌', 'Erreur réseau : ' + e.message, 'error');
        }
    });

    /* ─── Clipboard (paste) ─── */
    clipZone?.addEventListener('paste', async (e) => {
        const items = (e.clipboardData || window.clipboardData)?.items;
        if (!items) { clipStatus.textContent = 'Rien à coller.'; return; }
        let found = false;
        for (const item of items) {
            if (item.type.indexOf('image') === 0) {
                const file = item.getAsFile();
                if (file) {
                    found = true;
                    clipStatus.textContent = 'Image collée — envoi en cours…';
                    uploadFiles([file]);
                }
            }
        }
        if (!found) clipStatus.textContent = 'Pas d\'image détectée dans le presse-papier.';
    });
    // Aussi : paste global quand modale ouverte sur cet onglet
    document.addEventListener('paste', (e) => {
        if (!modal.classList.contains('is-open')) return;
        const activePane = modal.querySelector('.fbx-pane.is-active');
        if (!activePane || activePane.getAttribute('data-pane') !== 'clipboard') return;
        // Reproduit la logique sur clipZone
        const items = (e.clipboardData || window.clipboardData)?.items;
        if (!items) return;
        for (const item of items) {
            if (item.type.indexOf('image') === 0) {
                const file = item.getAsFile();
                if (file) { clipStatus.textContent = 'Image collée — envoi…'; uploadFiles([file]); }
            }
        }
    });

    /* ─── Helper : POST + parse JSON robuste (affiche la réponse brute si non-JSON) ─── */
    async function postAndParse(fd, isJsonBody = false) {
        const res = await fetch(API, {
            method: 'POST',
            headers: isJsonBody
                ? { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }
                : { 'X-CSRF-Token': CSRF },
            body: fd,
            credentials: 'same-origin',
        });
        const text = await res.text();
        // Tente JSON
        try {
            return { ok: true, data: JSON.parse(text), status: res.status };
        } catch (_) {
            // Réponse HTML / autre — extrait un indice utile
            let preview = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            if (preview.length > 200) preview = preview.substring(0, 200) + '…';
            return {
                ok: false,
                parseError: true,
                status: res.status,
                preview: preview || `HTTP ${res.status} (réponse vide)`,
            };
        }
    }

    /* ─── Upload multi-fichiers (FormData parallèle, max 3 simultanés, non-bloquant) ─── */
    const UPLOAD_CONCURRENCY = 3;

    /* Nettoie un nom de fichier pour en faire un libellé lisible */
    function cleanFilenameToLabel(filename) {
        if (!filename) return '';
        // Enlève l'extension
        let s = filename.replace(/\.[^.]+$/, '');
        // Remplace underscores et points isolés par des espaces
        s = s.replace(/[_]+/g, ' ').replace(/\.(?=\D)/g, ' ');
        // Compacte les espaces multiples
        s = s.replace(/\s+/g, ' ').trim();
        // Capitalise la première lettre
        if (s.length > 0) s = s.charAt(0).toUpperCase() + s.slice(1);
        // Tronque si trop long (maxlength 180 sur le champ)
        if (s.length > 180) s = s.substring(0, 177) + '…';
        return s;
    }

    /* NOTE: prefill du libellé supprimé (2026-05-17) — bug : le nom du 1er fichier
       était appliqué à TOUS les fichiers du batch via snapshotMeta. Maintenant, si user_label
       est vide, le backend dérive le titre depuis le nom de fichier de chaque doc. */

    async function uploadFiles(fileList) {
        queueWrap.hidden = false;
        const files = Array.from(fileList);
        const meta = getMetadata();

        // Snapshot des métadonnées au moment du drop (si user change après, on garde la valeur d'origine)
        const snapshotMeta = { ...meta };

        // Augmente le total pour la barre de progression globale
        STATE.batchTotal += files.length;
        updateProgress();

        // Pool de promesses limitées
        const pool = [];
        for (const file of files) {
            STATE.activeCount++;
            updateBadges();
            const promise = uploadOne(file, snapshotMeta).finally(() => {
                STATE.activeCount = Math.max(0, STATE.activeCount - 1);
                updateBadges();
            });
            pool.push(promise);
            // Limite la concurrence
            if (pool.length >= UPLOAD_CONCURRENCY) {
                await Promise.race(pool).catch(() => {});
                // Nettoie les promesses settled
                for (let i = pool.length - 1; i >= 0; i--) {
                    // On vide après allSettled cumulatif — simplification : on attend juste qu'au moins 1 termine
                    break;
                }
            }
        }
        // Pas besoin d'await final : les uploads continuent en arrière-plan
        // (le user peut fermer la modale, naviguer, le state.activeCount reste juste pour le badge)
    }

    async function uploadOne(file, meta) {
        const isZip = /\.zip$/i.test(file.name) || file.type === 'application/zip';
        const initMsg = isZip ? 'Envoi + extraction ZIP…' : 'Envoi…';
        const li = addQueueItem(file.name, '⏳', initMsg);
        try {
            const fd = new FormData();
            fd.append('action', 'ingest');
            fd.append('csrf', CSRF);
            fd.append('file', file, file.name);
            fd.append('user_comment',    meta.user_comment);
            fd.append('user_label',      meta.user_label);
            fd.append('entity_instance', meta.entity_instance);
            fd.append('ged_n1', meta.ged_n1);
            fd.append('ged_n2', meta.ged_n2);
            fd.append('ged_n3', meta.ged_n3);
            fd.append('ged_n4', meta.ged_n4);
            fd.append('ged_n5', meta.ged_n5);
            fd.append('target_societe_id', String(meta.target_societe_id));
            fd.append('target_agence_id',  String(meta.target_agence_id));
            fd.append('target_date',       meta.target_date || '');
            // [V3.1] Prefill métier (pour naming entité polymorphe + détection N1 contextuelle)
            fd.append('prefill_bien_id',     String(meta.prefill_bien_id || 0));
            fd.append('prefill_immeuble_id', String(meta.prefill_immeuble_id || 0));
            fd.append('prefill_tiers_id',    String(meta.prefill_tiers_id || 0));
            fd.append('prefill_bail_id',     String(meta.prefill_bail_id || 0));
            fd.append('prefill_creancier_dossier_id', String(meta.prefill_creancier_dossier_id || 0));
            fd.append('prefill_origin',      meta.prefill_origin || '');
            // Path relatif si le file vient d'un panneau "Dossier" (<input webkitdirectory>)
            // Permet au serveur d'extraire le nom du dossier parent comme instance entité
            if (file.webkitRelativePath) {
                fd.append('relative_path', file.webkitRelativePath);
            }
            const r = await postAndParse(fd);
            if (r.parseError) {
                STATE.errCount++;
                updateQueueItem(li, '❌',
                    `Serveur : HTTP ${r.status} (pas JSON). ${r.preview}`, 'error');
                showToast(file.name, 'error', `Erreur HTTP ${r.status}`);
                console.error('FluxBox upload — réponse non-JSON :', r.preview);
            } else {
                renderResult(li, r.data);
                // Toast récap
                const d = r.data?.data || {};
                if (!r.data.ok) {
                    STATE.errCount++;
                    showToast(file.name, 'error', (r.data.errors || []).join(', ') || 'Erreur');
                } else if (d.is_duplicate) {
                    STATE.dupCount++;
                    // Push dans la liste des doublons pour bilan fin upload
                    STATE.duplicates.push({
                        new_filename:    d.new_filename || file.name,
                        orig_filename:   d.orig_filename || '',
                        orig_carte_id:   d.orig_carte_id || null,
                        orig_carte_status: d.orig_carte_status || '',
                        orig_ged_doc_id: d.orig_ged_doc_id || null,
                        orig_uploaded_at: d.orig_uploaded_at || '',
                        orig_titre:      d.orig_titre || '',
                        seen_count:      d.seen_count || 1,
                    });
                    let dupMsg = `Déjà reçu (${d.seen_count}× vu)`;
                    if (d.orig_carte_id && d.orig_carte_status === 'pending') {
                        dupMsg += ` — voir carte #${d.orig_carte_id}`;
                    } else if (d.orig_ged_doc_id) {
                        dupMsg += ` — déjà classé (doc GED #${d.orig_ged_doc_id})`;
                    } else if (d.orig_carte_status === 'dismissed') {
                        dupMsg += ` — précédemment supprimé`;
                    }
                    showToast(file.name, 'dup', dupMsg);
                } else if (d.is_zip) {
                    STATE.doneCount++;
                    showToast(file.name, 'success',
                        `ZIP : ${d.files_ingested} fichiers · ${d.cards_created} cartes`);
                } else {
                    STATE.doneCount++;
                    // [Sprint 6 A1] Mémoriser carte créée pour redirection review unifiée
                    if (d.carte_id) {
                        const pf = window.FBX_PREFILL || {};
                        STATE.createdCards.push({
                            card_id: parseInt(d.carte_id, 10),
                            doc_id:  d.document_id ? parseInt(d.document_id, 10) : (d.doc_id ? parseInt(d.doc_id, 10) : 0),
                            bien_id: pf.bien_id ? parseInt(pf.bien_id, 10) : 0,
                        });
                    }
                    showToast(file.name, 'success', 'Carte créée');
                }
            }
        } catch (e) {
            STATE.errCount++;
            updateQueueItem(li, '❌', 'Erreur réseau : ' + e.message, 'error');
            showToast(file.name, 'error', 'Réseau : ' + e.message);
        }
    }

    /* ─── Queue helpers ─── */
    function addQueueItem(name, icon, msg) {
        const li = document.createElement('li');
        li.innerHTML = `
            <span class="fbx-queue-status">${icon}</span>
            <span class="fbx-queue-name"></span>
            <span class="fbx-queue-msg">${msg}</span>
        `;
        li.querySelector('.fbx-queue-name').textContent = name;
        queueList.prepend(li);
        return li;
    }
    function updateQueueItem(li, icon, msg, cls) {
        li.querySelector('.fbx-queue-status').textContent = icon;
        const m = li.querySelector('.fbx-queue-msg');
        m.textContent = msg;
        m.className = 'fbx-queue-msg' + (cls ? ' ' + cls : '');
    }
    function renderResult(li, data) {
        if (data.ok) {
            const d = data.data || {};
            if (d.is_zip) {
                // Résultat ZIP : afficher stats globales
                const parts = [];
                parts.push(`📦 ${d.files_ingested} fichier${d.files_ingested>1?'s':''} extrait${d.files_ingested>1?'s':''}`);
                if (d.files_dup > 0) parts.push(`🛡️ ${d.files_dup} doublon${d.files_dup>1?'s':''}`);
                if (d.nested_zips > 0) parts.push(`🔁 ${d.nested_zips} ZIP imbriqué${d.nested_zips>1?'s':''}`);
                if (d.cards_created > 0) parts.push(`✅ ${d.cards_created} carte${d.cards_created>1?'s':''}`);
                if ((d.errors || []).length > 0) parts.push(`⚠️ ${d.errors.length} erreur${d.errors.length>1?'s':''}`);
                updateQueueItem(li, '📦', parts.join(' · '), d.files_ingested > 0 ? 'ok' : 'error');
            } else if (d.is_duplicate) {
                let dupMsg = `Déjà reçu (${d.seen_count}× vu)`;
                if (d.orig_carte_id && d.orig_carte_status === 'pending') {
                    dupMsg += ` — carte pending #${d.orig_carte_id}`;
                } else if (d.orig_ged_doc_id) {
                    dupMsg += ` — déjà classé GED`;
                } else if (d.orig_carte_status === 'dismissed') {
                    dupMsg += ` — précédemment supprimé`;
                }
                updateQueueItem(li, '🛡️', dupMsg, 'dup');
            } else {
                updateQueueItem(li, '✅', `Carte créée${d.carte_id ? ' #' + d.carte_id : ''}`, 'ok');
            }
        } else {
            const errs = (data.errors || ['erreur inconnue']).join(', ');
            updateQueueItem(li, '❌', errs, 'error');
        }
    }
})();
</script>
