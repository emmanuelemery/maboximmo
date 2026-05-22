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
        <div class="fbx-upload-head">
            <h2 id="fbx-upload-title">📥 Charger des documents</h2>
            <button type="button" class="fbx-upload-close" data-fbx-close aria-label="Fermer">✕</button>
        </div>
        <div class="fbx-upload-subtitle">
            Glissez vos documents, choisissez-les, prenez en photo, collez une URL ou collez une image.
            <br>Tous les flux passent par FluxBox — vous validez ensuite carte par carte.
        </div>

        <!-- ───── Classement GED — boutons cascade ───── -->
        <div class="fbx-meta-block">

            <!-- Société (boutons codes 4 lettres XX.YY) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">🏢 Société <span class="fbx-required">*</span></div>
                <div class="fbx-btn-row" id="fbx-row-societes">
                    <div class="fbx-loading">Chargement…</div>
                </div>
            </div>

            <!-- Agence (cascade depuis société) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">🏬 Agence <span class="fbx-required">*</span></div>
                <div class="fbx-btn-row" id="fbx-row-agences">
                    <div class="fbx-row-empty">— Choisir une société d'abord —</div>
                </div>
            </div>

            <!-- Métier (boutons groupés par business_group, 8 groupes) -->
            <div class="fbx-row-block">
                <div class="fbx-row-label">💼 Métier <span class="fbx-required">*</span></div>
                <div class="fbx-btn-row fbx-btn-row-groups" id="fbx-row-metiers">
                    <div class="fbx-loading">Chargement…</div>
                </div>
            </div>

            <!-- Domaine + sous-domaine (selects cascade, après métier choisi) -->
            <div class="fbx-meta-cascade">
                <div class="fbx-meta-select">
                    <label for="fbx-meta-n2">Domaine</label>
                    <select id="fbx-meta-n2" data-meta="n2" disabled>
                        <option value="">— Choisir un métier d'abord —</option>
                    </select>
                </div>
                <div class="fbx-meta-select">
                    <label for="fbx-meta-n3">Sous-domaine</label>
                    <select id="fbx-meta-n3" data-meta="n3" disabled>
                        <option value="">— Choisir un domaine d'abord —</option>
                    </select>
                </div>
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
        </div>

    </div>
</div>

<style>
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

/* ═══════════ Bouton topbar (intégration .tb-btn) ═══════════ */
.fbx-topbar-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #D4A047, #b88835);
    color: #1a1816 !important;
    border: none; border-radius: 8px;
    font-family: "Sora", "Inter", sans-serif; font-size: 12px; font-weight: 700;
    cursor: pointer;
    box-shadow: 2px 2px 6px rgba(0,0,0,0.12);
    transition: transform .12s, box-shadow .15s;
}
.fbx-topbar-btn:hover {
    transform: translateY(-1px);
    box-shadow: 3px 4px 10px rgba(0,0,0,0.18);
}
.fbx-topbar-btn-label { letter-spacing: 0.02em; }
@media (max-width: 640px) {
    .fbx-topbar-btn-label { display: none; }
    .fbx-topbar-btn { padding: 6px 8px; }
}

/* ═══════════ Bouton flottant FAB ═══════════ */
.fbx-upload-fab {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9990;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #D4A047, #b88835);
    color: #1a1816;
    border: none;
    padding: 14px 22px;
    border-radius: 30px;
    font-family: "Sora", "Inter", sans-serif;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 4px 6px 18px rgba(0,0,0,0.22), 2px 2px 6px rgba(0,0,0,0.1);
    transition: transform .15s ease, box-shadow .2s;
}
.fbx-upload-fab:hover {
    transform: translateY(-3px);
    box-shadow: 6px 10px 26px rgba(0,0,0,0.28);
}
.fbx-upload-fab-icon { font-size: 22px; }
@media (max-width: 640px) {
    .fbx-upload-fab-label { display: none; }
    .fbx-upload-fab { padding: 14px; }
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
    max-width: 720px;
    width: 100%;
    margin: auto;
    padding: 26px 28px;
    box-shadow: 0 30px 90px rgba(0,0,0,0.3);
    font-family: "Sora", "Inter", sans-serif;
    animation: fbx-modal-in .25s cubic-bezier(0.22,1,0.36,1);
}
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
.fbx-btn-row {
    display: flex; flex-wrap: wrap; gap: 6px;
    min-height: 38px;
    align-items: center;
}
.fbx-loading, .fbx-row-empty {
    font-size: 12px; color: #94a3b8; font-style: italic;
}
.fbx-choice-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    border-radius: 10px;
    border: 1.5px solid #cbd5e1;
    background: #fff; color: #243B5C;
    font-family: inherit; font-size: 12px; font-weight: 600;
    cursor: pointer;
    transition: all .15s ease;
    line-height: 1.2;
}
.fbx-choice-btn:hover {
    border-color: #D4A047;
    background: #fef3c7;
}
.fbx-choice-btn.is-selected {
    background: linear-gradient(135deg, #243B5C, #1e3050);
    color: #fff;
    border-color: #243B5C;
    box-shadow: 2px 2px 6px rgba(36,59,92,0.25);
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
.fbx-choice-label { font-weight: 600; }

/* Grouped buttons (métiers par business_group) */
.fbx-btn-row-groups { flex-direction: column; align-items: stretch; gap: 8px; }
.fbx-group-block {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
    padding: 4px 8px;
    background: #fff;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
.fbx-group-icon { font-size: 16px; }
.fbx-group-label {
    font-size: 10px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.06em;
    min-width: 90px;
}
.fbx-group-items { display: flex; flex-wrap: wrap; gap: 4px; flex: 1; }

.fbx-meta-cascade {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}
@media (max-width: 640px) { .fbx-meta-cascade { grid-template-columns: 1fr; } }

.fbx-meta-select label {
    display: block;
    font-size: 11px; color: #475569; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.05em;
    margin-bottom: 4px;
}
.fbx-meta-select select {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-family: inherit; font-size: 13px;
    background: #fff; color: #1e293b;
    cursor: pointer;
}
.fbx-meta-select select:disabled {
    background: #f1f5f9; color: #94a3b8; cursor: not-allowed;
}
.fbx-meta-select select:focus {
    outline: 2px solid #243B5C; outline-offset: 0; border-color: #243B5C;
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
    border-bottom-color: #D4A047;
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
    border-color: #D4A047;
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
.fbx-clipboard-zone:focus { background: #fef3c7; border-color: #D4A047; }

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
    background: linear-gradient(135deg, #243B5C, #1e3050);
    color: #fff;
    box-shadow: 3px 3px 10px rgba(36,59,92,0.2);
}
.fbx-btn-primary:hover { box-shadow: 4px 5px 14px rgba(36,59,92,0.3); }

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
    font-weight: 700; color: #D4A047; font-size: 15px;
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
    background: linear-gradient(90deg, #16a34a 0%, #D4A047 100%);
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
// Résolution dynamique du chemin API (compat XAMPP local + Hostinger prod)
$_fbxApiUrl = function_exists('app_url')
    ? app_url('/api/fluxbox_action.php')
    : '/api/fluxbox_action.php';
?>
<script>
(function () {
    'use strict';
    const API  = <?= json_encode($_fbxApiUrl, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;

    const modal      = document.getElementById('fbx-upload-modal');
    const fab        = document.getElementById('fbx-upload-fab');
    const rowSoc     = document.getElementById('fbx-row-societes');
    const rowAg      = document.getElementById('fbx-row-agences');
    const rowMet     = document.getElementById('fbx-row-metiers');
    const selN2      = document.getElementById('fbx-meta-n2');
    const selN3      = document.getElementById('fbx-meta-n3');
    const commentEl  = document.getElementById('fbx-meta-comment-input');
    const dateEl     = document.getElementById('fbx-meta-date-input');

    // État sélection courante
    const choice = { societe_id: 0, agence_id: 0, n1: '', n2: '', n3: '' };

    // État GLOBAL uploads (singleton — survit à la fermeture de la modale)
    if (!window.FluxBoxUploadState) {
        window.FluxBoxUploadState = {
            activeCount: 0,    // uploads en cours
            doneCount:   0,    // succès cumulés (session)
            errCount:    0,    // erreurs cumulées
            dupCount:    0,    // doublons cumulés
            batchTotal:  0,    // total demandé dans la session courante
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
        progressGlobal.classList.toggle('is-done', STATE.activeCount === 0 && processed > 0 && processed >= total);
    }

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
                body: JSON.stringify({ action: 'fbx_context', csrf: CSRF }),
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
            // Préselection auto société par défaut
            if (data.data.societe_default) {
                selectSociete(data.data.societe_default, data.data.agences || [], data.data.agence_default);
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
        if (!agences || agences.length === 0) {
            rowAg.innerHTML = '<div class="fbx-row-empty">Aucune agence sur cette société.</div>';
            return;
        }
        rowAg.innerHTML = '';
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
    }

    /* ─── Rendu boutons métiers (groupés par business_group) ─── */
    function renderMetierButtons(grouped) {
        if (!grouped || grouped.length === 0) {
            rowMet.innerHTML = '<div class="fbx-row-empty">Aucun métier seedé. Applique d\'abord la migration GED.</div>';
            return;
        }
        rowMet.innerHTML = '';
        grouped.forEach(g => {
            const wrap = document.createElement('div');
            wrap.className = 'fbx-group-block';
            wrap.innerHTML = `
                <span class="fbx-group-icon">${g.icon || ''}</span>
                <span class="fbx-group-label">${g.group_label}</span>
                <div class="fbx-group-items"></div>
            `;
            const items = wrap.querySelector('.fbx-group-items');
            (g.codes || []).forEach(c => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'fbx-choice-btn';
                btn.dataset.code = c.code;
                // Label court : on enlève le préfixe "NN_" si présent
                const lbl = (c.label || '').replace(/^\d+\s*-\s*/, '');
                btn.textContent = lbl;
                btn.title = c.code;
                btn.addEventListener('click', () => selectMetier(c.code));
                items.appendChild(btn);
            });
            rowMet.appendChild(wrap);
        });
    }

    async function selectMetier(n1) {
        choice.n1 = n1;
        choice.n2 = '';
        choice.n3 = '';
        rowMet.querySelectorAll('.fbx-choice-btn').forEach(b => {
            b.classList.toggle('is-selected', b.dataset.code === n1);
        });
        // Charge N2 dans le select
        selN2.disabled = true; selN3.disabled = true;
        selN2.innerHTML = '<option value="">— Chargement… —</option>';
        selN3.innerHTML = '<option value="">— Choisir un domaine d\'abord —</option>';
        const items = await loadGedChildren(2, { n1 });
        populateSelect(selN2, items, '— Choisir un domaine —');
        selN2.disabled = items.length === 0;
        if (items.length === 0) selN2.innerHTML = '<option value="">— Aucun domaine seedé —</option>';
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

    function populateSelect(sel, items, placeholder) {
        sel.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = ''; opt0.textContent = placeholder;
        sel.appendChild(opt0);
        items.forEach(it => {
            const o = document.createElement('option');
            o.value = it.code; o.textContent = it.label;
            sel.appendChild(o);
        });
    }

    selN2?.addEventListener('change', async () => {
        choice.n2 = selN2.value;
        choice.n3 = '';
        selN3.disabled = true;
        selN3.innerHTML = '<option value="">— Chargement… —</option>';
        if (!choice.n2) {
            selN3.innerHTML = '<option value="">— Choisir un domaine d\'abord —</option>';
            return;
        }
        const items = await loadGedChildren(3, { n1: choice.n1, n2: choice.n2 });
        populateSelect(selN3, items, '— Choisir un sous-domaine —');
        selN3.disabled = items.length === 0;
        if (items.length === 0) selN3.innerHTML = '<option value="">— Aucun sous-domaine seedé —</option>';
    });
    selN3?.addEventListener('change', () => { choice.n3 = selN3.value; });

    /* ─── Récup métadonnées user (commentaire + classement + date) ─── */
    function getMetadata() {
        return {
            user_comment: (commentEl?.value || '').trim(),
            ged_n1: choice.n1 || '',
            ged_n2: choice.n2 || '',
            ged_n3: choice.n3 || '',
            target_societe_id: choice.societe_id || '',
            target_agence_id:  choice.agence_id  || '',
            target_date: (dateEl?.value || '').trim(), // YYYY-MM-DD
        };
    }

    /* ─── Ouverture / fermeture ─── */
    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        loadContext(); // charge sociétés + agences + métiers au premier open
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
    modal.querySelectorAll('[data-fbx-close]').forEach(el => el.addEventListener('click', closeModal));

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
            fd.append('user_comment', meta.user_comment);
            fd.append('ged_n1', meta.ged_n1);
            fd.append('ged_n2', meta.ged_n2);
            fd.append('ged_n3', meta.ged_n3);
            fd.append('target_societe_id', String(meta.target_societe_id));
            fd.append('target_agence_id',  String(meta.target_agence_id));
            fd.append('target_date',       meta.target_date || '');
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
                    showToast(file.name, 'dup', `Déjà reçu (${d.seen_count}× vu)`);
                } else if (d.is_zip) {
                    STATE.doneCount++;
                    showToast(file.name, 'success',
                        `ZIP : ${d.files_ingested} fichiers · ${d.cards_created} cartes`);
                } else {
                    STATE.doneCount++;
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
                updateQueueItem(li, '🛡️', `Déjà reçu (${d.seen_count}× vu)`, 'dup');
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
