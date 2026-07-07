<?php
/**
 * inc/mvpt_modal_doc_viewer.php
 *
 * Modal UNIVERSEL de visualisation d'un document GED — réutilisable sur toutes les pages.
 * Disposition 2 colonnes : champs extraits À GAUCHE + aperçu du document À DROITE.
 *
 * Usage :
 *   mvptModalView(docId, name)            → aperçu seul (rétro-compatible)
 *   mvptModalView(docId, name, fields)    → + panneau de champs à gauche
 *      fields = [ {section:'Finances'}, {label:'Loyer HC', value:'1 000 €'}, ... ]
 *      (les entrées {section:'…'} créent un sous-titre ; value vide → ligne ignorée)
 *
 *   <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
 */
?>
<div id="mvptModalBackdrop" class="mvpt-modal-backdrop" onclick="if (event.target === this) mvptModalClose()">
    <div class="mvpt-modal" id="mvptModal">
        <div class="mvpt-modal-header">
            <h3 id="mvptModalTitle">📄 Document</h3>
            <div class="mvpt-modal-header-actions">
                <button class="mvpt-modal-reclass" onclick="mvptModalReclass()" title="Re-classer ce document via FluxBox">✏️ Re-classer</button>
                <a id="mvptModalOpen" class="mvpt-modal-open" href="#" target="_blank" rel="noopener" title="Ouvrir dans un nouvel onglet">↗ Ouvrir</a>
                <button class="mvpt-modal-close" onclick="mvptModalClose()">✕ Fermer</button>
            </div>
        </div>
        <div class="mvpt-modal-cols">
            <aside class="mvpt-fields" id="mvptModalFields" style="display:none;"></aside>
            <div class="mvpt-modal-body" id="mvptModalBody">
                <div class="mvpt-modal-loading">⏳ Chargement…</div>
            </div>
        </div>
        <div class="mvpt-modal-footer" id="mvptModalFooter">—</div>
    </div>
</div>
<style>
.mvpt-modal-backdrop { position: fixed; inset: 0; z-index: 9000; background: rgba(15,18,24,.55);
    align-items: center; justify-content: center; }
.mvpt-modal-backdrop:not(.open) { display: none !important; }
.mvpt-modal-backdrop.open { display: flex; }
.mvpt-modal { background: #fff; border-radius: 14px; width: min(960px, 94vw); max-height: 92vh;
    display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
.mvpt-modal.with-fields { width: min(1240px, 96vw); }
.mvpt-modal-header { display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-bottom: 1px solid #eef0f2; }
.mvpt-modal-header h3 { margin: 0; font-size: 16px; }
.mvpt-modal-cols { flex: 1; display: flex; min-height: 0; overflow: hidden; }
.mvpt-fields { width: 340px; flex: none; overflow: auto; background: #faf8ff; border-right: 1px solid #ece7f5;
    padding: 14px 16px; }
.mvpt-fields h4 { margin: 0 0 10px; font-size: 13px; color: #5b21b6; text-transform: uppercase; letter-spacing: .03em; }
.mvpt-f-sec { font-size: 11px; font-weight: 800; color: #7a766f; text-transform: uppercase; letter-spacing: .03em;
    margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ece7f5; }
.mvpt-f-sec:first-of-type { margin-top: 0; }
.mvpt-f-row { display: flex; flex-direction: column; gap: 1px; padding: 5px 0; border-bottom: 1px dashed #f0ecf6; }
.mvpt-f-lbl { font-size: 10.5px; color: #9a9690; text-transform: uppercase; letter-spacing: .02em; }
.mvpt-f-val { font-size: 13px; color: #2c2a28; font-weight: 600; word-break: break-word; }
.mvpt-modal-body { flex: 1; overflow: auto; min-height: 260px; background: #f8fafc; }
.mvpt-modal-body iframe { width: 100%; height: 78vh; border: 0; display: block; }
.mvpt-modal-body img { max-width: 100%; display: block; margin: 0 auto; }
.mvpt-modal-footer { padding: 10px 18px; border-top: 1px solid #eef0f2; font-size: 12px; color: #6b7280; }
.mvpt-modal-loading { padding: 34px; text-align: center; color: #6b7280; font-size: 14px; }
.mvpt-modal-close, .mvpt-modal-open { padding: 6px 12px; background: #eceef1; border: 1px solid #d6dade; color: #374151;
    border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; text-decoration: none; }
.mvpt-modal-close:hover, .mvpt-modal-open:hover { background: #dfe3e7; }
.mvpt-modal-header-actions { display: flex; gap: 8px; align-items: center; }
.mvpt-modal-reclass { padding: 6px 14px; background: #fef9e7; border: 1px solid #d4a047; color: #92400e;
    border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; transition: all .15s; }
.mvpt-modal-reclass:hover { background: #d4a047; color: #fff; }
@media (max-width: 780px){ .mvpt-modal-cols{ flex-direction: column; } .mvpt-fields{ width: auto; max-height: 30vh; border-right: 0; border-bottom: 1px solid #ece7f5; } }
</style>

<script>
(function() {
    let mvptCurrentDocId = 0;
    // URLs API préfixées par la base de l'app (sinon 404 en local sous sous-dossier).
    const MVPT_SERVE = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_doc_serve.php') : '/api/ged_doc_serve.php') ?>;
    const MVPT_INFO  = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_doc_info.php') : '/api/ged_doc_info.php') ?>;
    const esc = function(s){ const d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; };

    function renderFields(fields){
        const panel = document.getElementById('mvptModalFields');
        const modal = document.getElementById('mvptModal');
        if (!fields || !fields.length){ panel.style.display='none'; panel.innerHTML=''; modal.classList.remove('with-fields'); return; }
        let html = '<h4>🧾 Données extraites</h4>';
        fields.forEach(function(f){
            if (f && f.section){ html += '<div class="mvpt-f-sec">'+esc(f.section)+'</div>'; return; }
            if (!f || f.value === null || f.value === undefined || String(f.value).trim()===''){ return; }
            html += '<div class="mvpt-f-row"><div class="mvpt-f-lbl">'+esc(f.label||'')+'</div><div class="mvpt-f-val">'+esc(f.value)+'</div></div>';
        });
        panel.innerHTML = html;
        panel.style.display = 'block';
        modal.classList.add('with-fields');
    }

    window.mvptModalReclass = async function() {
        if (mvptCurrentDocId <= 0) return;
        if (!confirm('Re-classer ce document via la pile FluxBox ?')) return;
        try {
            const res = await fetch(<?= json_encode(function_exists('app_url') ? app_url('/api/ged_doc_send_to_reclass.php') : '/api/ged_doc_send_to_reclass.php') ?>, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ doc_id: mvptCurrentDocId }), credentials: 'same-origin',
            });
            const data = await res.json();
            if (data.ok && data.redirect_url) { window.location.href = data.redirect_url; }
            else { alert('❌ ' + (data.error || 'Erreur')); }
        } catch (e) { alert('❌ Réseau : ' + e.message); }
    };

    // 3e argument optionnel `fields` = panneau gauche (champs extraits).
    window.mvptModalView = async function(docId, name, fields) {
        mvptCurrentDocId = docId;
        const backdrop = document.getElementById('mvptModalBackdrop');
        const title    = document.getElementById('mvptModalTitle');
        const body     = document.getElementById('mvptModalBody');
        const footer   = document.getElementById('mvptModalFooter');
        const openLink = document.getElementById('mvptModalOpen');

        title.textContent = '📄 ' + (name || 'Document #' + docId);
        body.innerHTML = '<div class="mvpt-modal-loading">⏳ Chargement…</div>';
        footer.textContent = 'doc #' + docId + ' · récupération métadonnées…';
        openLink.href = MVPT_SERVE + '?id=' + docId;
        renderFields(fields);
        backdrop.classList.add('open');

        try {
            const r = await fetch(MVPT_INFO + '?id=' + docId, {credentials:'same-origin'});
            if (!r.ok) {
                // Remonte le motif exact du serveur (ex. « Hors société », « chemin hors zone »)
                let motif = '';
                try { const t = await r.text(); try { motif = (JSON.parse(t).error || '').toString(); } catch(_) { motif = t; } } catch(_) {}
                throw new Error('HTTP ' + r.status + (motif ? ' — ' + motif.slice(0, 160) : ''));
            }
            const j = await r.json();
            if (j.error) throw new Error(j.error);
            if (!j.ged_document) throw new Error('Document introuvable');

            const doc = j.ged_document;
            const mime = (doc.mime_type || '').toLowerCase();
            const viewerUrl = MVPT_SERVE + '?id=' + docId;

            if (mime.includes('pdf')) {
                body.innerHTML = '<iframe src="' + viewerUrl + '" title="' + esc(doc.name_file || '') + '"></iframe>';
            } else if (mime.startsWith('image/')) {
                body.innerHTML = '<img src="' + viewerUrl + '" alt="' + esc(doc.name_file || '') + '">';
            } else {
                body.innerHTML = '<div class="mvpt-modal-loading">📎 Aperçu non disponible pour ce type (' + esc(mime) + ')<br><br>' +
                    'Type : ' + esc(doc.document_type || '—') + '<br>Module : ' + esc(doc.source_module || '—') + '<br>' +
                    'Taille : ' + Math.round((doc.size_bytes || 0)/1024) + ' Ko</div>';
            }

            const linkCount = (j.links || []).length;
            const folderName = j.folder ? j.folder.name_display : '—';
            footer.innerHTML = 'doc #<code>' + doc.id + '</code> · type <code>' + esc(doc.document_type || '—') + '</code> · ' +
                'mod <code>' + esc(doc.source_module || '—') + '</code> · dossier <code>' + esc(folderName) + '</code> · ' +
                '<b>' + linkCount + '</b> lien(s)';
        } catch (e) {
            body.innerHTML = '<div class="mvpt-modal-loading">❌ Erreur : ' + esc(e.message) + '</div>';
            footer.textContent = 'doc #' + docId + ' · échec récupération';
        }
    };

    window.mvptModalClose = function() {
        document.getElementById('mvptModalBackdrop').classList.remove('open');
        document.getElementById('mvptModalBody').innerHTML = '';
    };
    document.addEventListener('keydown', e => { if (e.key === 'Escape') mvptModalClose(); });
})();
</script>
