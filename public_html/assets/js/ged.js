/* ─────────────────────────────────────────────────────────────────────────────
 * GED MaBoxImmo — Front JS dashboard (rename de agent_ged.js)
 * Fichier : assets/js/ged.js
 *
 * Expose en global :
 *   - gedAction(btn, action, analysisId, extra?)
 *   - gedView(documentId, documentTable)
 *   - gedToast(msg, kind)
 *
 * Compat : alias agedAction / agedView / agedToast pour les anciennes pages.
 * ───────────────────────────────────────────────────────────────────────────── */

(function () {
    'use strict';

    var ACTION_URL = './ged_dashboard_action.php';

    function gedToast(msg, kind) {
        var t = document.createElement('div');
        t.className = 'aged-toast' + (kind ? ' t-' + kind : '');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3500);
    }
    window.gedToast  = gedToast;
    window.agedToast = gedToast;

    function gedView(documentId, documentTable) {
        if (!documentId) { gedToast('Document non lié', 'error'); return; }
        var url;
        switch (documentTable) {
            case 'admin_documents':  url = '/admin_documents.php?id='  + encodeURIComponent(documentId); break;
            case 'agence_documents': url = '/agency_documents.php?id=' + encodeURIComponent(documentId); break;
            default:                 url = '/admin_documents.php?id='  + encodeURIComponent(documentId);
        }
        window.open(url, '_blank');
    }
    window.gedView  = gedView;
    window.agedView = gedView;

    function gedAction(btn, action, analysisId, extra) {
        if (btn) { btn.classList.add('b-loading'); btn.disabled = true; }

        var formData = new FormData();
        formData.append('action', action);
        formData.append('analysis_id', String(analysisId));
        if (extra) {
            Object.keys(extra).forEach(function (k) { formData.append(k, String(extra[k])); });
        }

        fetch(ACTION_URL, {
            method: 'POST', body: formData, credentials: 'same-origin', cache: 'no-store',
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    gedToast(data.message || 'Action effectuée', 'success');
                    handleActionSuccess(action, analysisId, data, btn);
                } else {
                    gedToast('Erreur : ' + (data.message || 'Inconnue'), 'error');
                    if (btn) { btn.classList.remove('b-loading'); btn.disabled = false; }
                }
            })
            .catch(function (err) {
                console.error('[gedAction]', err);
                gedToast('Erreur réseau : ' + err.message, 'error');
                if (btn) { btn.classList.remove('b-loading'); btn.disabled = false; }
            });
    }
    window.gedAction  = gedAction;
    window.agedAction = gedAction;

    function handleActionSuccess(action, analysisId, data, btn) {
        switch (action) {
            case 'validate':
            case 'reject':
                var tr = btn && btn.closest('tr');
                if (tr) {
                    tr.style.transition = 'opacity .3s';
                    tr.style.opacity = '0';
                    setTimeout(function () { tr.remove(); }, 300);
                }
                break;
            case 'reanalyze':
            case 'ocr_free':
            case 'ocr_premium':
                setTimeout(function () { location.reload(); }, 800);
                break;
            default:
                if (btn) { btn.classList.remove('b-loading'); btn.disabled = false; }
        }
    }
})();
