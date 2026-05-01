/* ─────────────────────────────────────────────────────────────────────────────
 * Agent GED MaBoxImmo — Front JS
 * Fichier : assets/js/agent_ged.js
 *
 * Fonctions :
 *   - agedAction(btn, action, analysisId)  → POST vers agent_ged_action.php
 *   - agedView(documentId, documentTable)  → ouvre la page de visualisation doc
 *   - agedToast(message, kind)             → notification flash
 *
 * Toutes les actions :
 *   - désactivent le bouton pendant la requête (UX feedback)
 *   - affichent un toast de succès/erreur
 *   - en cas de succès sur validate/reject : retire la ligne du tableau
 *   - en cas de succès sur reanalyze : recharge la page pour afficher la nouvelle analyse
 * ───────────────────────────────────────────────────────────────────────────── */

(function () {
    'use strict';

    /**
     * URL absolue du handler. Utilise le même path que la page (relative).
     */
    var ACTION_URL = './agent_ged_action.php';

    /**
     * Affiche un toast en bas à droite, auto-disparaît après 3.5s.
     * @param {string} msg
     * @param {string} kind  '' | 'success' | 'error'
     */
    function agedToast(msg, kind) {
        var t = document.createElement('div');
        t.className = 'aged-toast' + (kind ? ' t-' + kind : '');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3500);
    }
    window.agedToast = agedToast;

    /**
     * Ouvre la page de visualisation d'un document.
     * Dans MaBoxImmo legacy, on ouvre la page admin_documents.php?id=X.
     */
    function agedView(documentId, documentTable) {
        if (!documentId) {
            agedToast('Document non lié', 'error');
            return;
        }
        // Tentative de routage selon la table source du doc
        var url;
        switch (documentTable) {
            case 'admin_documents':
                url = '/admin_documents.php?id=' + encodeURIComponent(documentId);
                break;
            case 'agence_documents':
                url = '/agency_documents.php?id=' + encodeURIComponent(documentId);
                break;
            default:
                url = '/admin_documents.php?id=' + encodeURIComponent(documentId);
        }
        window.open(url, '_blank');
    }
    window.agedView = agedView;

    /**
     * Appelle le handler avec une action.
     * @param {HTMLButtonElement} btn         bouton cliqué (pour gérer l'état loading)
     * @param {string} action                  validate | reject | reanalyze | ocr_free | ocr_premium
     * @param {number} analysisId              id de la ligne agent_ged_analyses
     * @param {Object} extra                   params optionnels (service, etc.)
     */
    function agedAction(btn, action, analysisId, extra) {
        if (btn) btn.classList.add('b-loading');
        if (btn) btn.disabled = true;

        var formData = new FormData();
        formData.append('action', action);
        formData.append('analysis_id', String(analysisId));
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                formData.append(k, String(extra[k]));
            });
        }

        fetch(ACTION_URL, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    agedToast(data.message || 'Action effectuée', 'success');
                    handleActionSuccess(action, analysisId, data, btn);
                } else {
                    agedToast('Erreur : ' + (data.message || 'Inconnue'), 'error');
                    if (btn) {
                        btn.classList.remove('b-loading');
                        btn.disabled = false;
                    }
                }
            })
            .catch(function (err) {
                console.error('[agedAction]', err);
                agedToast('Erreur réseau : ' + err.message, 'error');
                if (btn) {
                    btn.classList.remove('b-loading');
                    btn.disabled = false;
                }
            });
    }
    window.agedAction = agedAction;

    /**
     * Comportement post-succès selon l'action.
     */
    function handleActionSuccess(action, analysisId, data, btn) {
        switch (action) {
            case 'validate':
            case 'reject':
                // Retire la ligne du tableau (animation simple : fade out + collapse)
                var tr = btn.closest('tr');
                if (tr) {
                    tr.style.transition = 'opacity .3s';
                    tr.style.opacity = '0';
                    setTimeout(function () { tr.remove(); }, 300);
                }
                break;
            case 'reanalyze':
                // Recharge la page pour afficher la nouvelle analyse
                setTimeout(function () { location.reload(); }, 800);
                break;
            case 'ocr_free':
            case 'ocr_premium':
                // Idem reload pour récupérer le nouveau ocr_text
                setTimeout(function () { location.reload(); }, 800);
                break;
            default:
                if (btn) {
                    btn.classList.remove('b-loading');
                    btn.disabled = false;
                }
        }
    }

})();
