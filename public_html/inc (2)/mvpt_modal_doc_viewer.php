<?php
/**
 * inc/mvpt_modal_doc_viewer.php
 *
 * Composant inclus dans les pages docs_list (bien/immeuble/tiers/bail).
 * Modal universel pour visualiser un document GED via iframe.
 *
 * Usage côté template :
 *   <a href="javascript:void(0)" onclick="mvptModalView(<doc_id>, '<name>')">...</a>
 *   <?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
 *
 * Comportement :
 *   - Fetch /api/ged_doc_info.php?id=X pour récup les méta
 *   - Construit URL fichier physique (heuristique chemin disque → URL Apache)
 *   - Affiche dans iframe (PDF) ou img (image) selon mime_type
 */
?>
<div id="mvptModalBackdrop" class="mvpt-modal-backdrop" onclick="if (event.target === this) mvptModalClose()">
    <div class="mvpt-modal">
        <div class="mvpt-modal-header">
            <h3 id="mvptModalTitle">📄 Document</h3>
            <div class="mvpt-modal-header-actions">
                <button class="mvpt-modal-reclass" onclick="mvptModalReclass()" title="Re-classer ce document via FluxBox">✏️ Re-classer</button>
                <button class="mvpt-modal-close" onclick="mvptModalClose()">✕ Fermer</button>
            </div>
        </div>
        <div class="mvpt-modal-body" id="mvptModalBody">
            <div class="mvpt-modal-loading">⏳ Chargement…</div>
        </div>
        <div class="mvpt-modal-footer" id="mvptModalFooter">—</div>
    </div>
</div>
<style>
.mvpt-modal-header-actions { display: flex; gap: 8px; align-items: center; }
.mvpt-modal-reclass {
    padding: 6px 14px;
    background: #fef9e7;
    border: 1px solid #d4a047;
    color: #92400e;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    transition: all 0.15s;
}
.mvpt-modal-reclass:hover { background: #d4a047; color: #fff; }
</style>

<script>
(function() {
    let mvptCurrentDocId = 0;

    window.mvptModalReclass = async function() {
        if (mvptCurrentDocId <= 0) return;
        if (!confirm('Re-classer ce document via la pile FluxBox ?')) return;
        try {
            const res = await fetch('/api/ged_doc_send_to_reclass.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ doc_id: mvptCurrentDocId }),
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (data.ok && data.redirect_url) {
                window.location.href = data.redirect_url;
            } else {
                alert('❌ ' + (data.error || 'Erreur'));
            }
        } catch (e) {
            alert('❌ Réseau : ' + e.message);
        }
    };

    window.mvptModalView = async function(docId, name) {
        mvptCurrentDocId = docId;
        const backdrop = document.getElementById('mvptModalBackdrop');
        const title    = document.getElementById('mvptModalTitle');
        const body     = document.getElementById('mvptModalBody');
        const footer   = document.getElementById('mvptModalFooter');

        title.textContent = '📄 ' + (name || 'Document #' + docId);
        body.innerHTML = '<div class="mvpt-modal-loading">⏳ Chargement…</div>';
        footer.textContent = 'doc #' + docId + ' · récupération métadonnées…';
        backdrop.classList.add('open');

        try {
            const r = await fetch('/api/ged_doc_info.php?id=' + docId, {credentials:'same-origin'});
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const j = await r.json();
            if (j.error) throw new Error(j.error);
            if (!j.ged_document) throw new Error('Document introuvable');

            const doc = j.ged_document;
            const mime = (doc.mime_type || '').toLowerCase();

            // Récupérer le chemin physique via fluxbox_documents (heuristique)
            // L'endpoint /api/ged_doc_info.php n'expose pas le chemin disque, on tente
            // une URL conventionnelle via le hash.
            // Best-effort : si le chemin physique n'est pas accessible, on affiche un message.
            let viewerUrl = null;

            // Si on a le name_file, on tente un chemin sous /storage_fluxbox/{soc}/...
            // Mais on ne connaît pas le chemin exact côté client. On utilise un endpoint
            // de servage si dispo, sinon on dit "non accessible".
            // Pour MVP : message infos + bouton "Ouvrir dans nouvel onglet" en best-effort

            if (mime.includes('pdf')) {
                viewerUrl = '/api/ged_doc_serve.php?id=' + docId;
                body.innerHTML = '<iframe src="' + viewerUrl + '" title="' + (doc.name_file || '') + '"></iframe>';
            } else if (mime.startsWith('image/')) {
                viewerUrl = '/api/ged_doc_serve.php?id=' + docId;
                body.innerHTML = '<img src="' + viewerUrl + '" alt="' + (doc.name_file || '') + '">';
            } else {
                body.innerHTML = '<div class="mvpt-modal-loading">' +
                    '📎 Aperçu non disponible pour ce type (' + mime + ')<br><br>' +
                    'Type : ' + (doc.document_type || '—') + '<br>' +
                    'Module : ' + (doc.source_module || '—') + '<br>' +
                    'Taille : ' + Math.round((doc.size_bytes || 0)/1024) + ' Ko<br>' +
                    '</div>';
            }

            // Footer infos
            const linkCount = (j.links || []).length;
            const folderName = j.folder ? j.folder.name_display : '—';
            footer.innerHTML = 'doc #<code>' + doc.id + '</code> · ' +
                'type <code>' + (doc.document_type || '—') + '</code> · ' +
                'mod <code>' + (doc.source_module || '—') + '</code> · ' +
                'dossier <code>' + folderName + '</code> · ' +
                '<b>' + linkCount + '</b> lien(s) · ' +
                'nom <code style="font-size:10px;">' + (doc.name_file || '') + '</code>';
        } catch (e) {
            body.innerHTML = '<div class="mvpt-modal-loading">❌ Erreur : ' + e.message + '</div>';
            footer.textContent = 'doc #' + docId + ' · échec récupération';
        }
    };

    window.mvptModalClose = function() {
        document.getElementById('mvptModalBackdrop').classList.remove('open');
        document.getElementById('mvptModalBody').innerHTML = '';
    };

    // Esc pour fermer
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') mvptModalClose();
    });
})();
</script>
