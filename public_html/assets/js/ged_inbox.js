/* ─────────────────────────────────────────────────────────────────────────────
 * GED MaBoxImmo — Inbox front (optimistic UI)
 * Fichier : assets/js/ged_inbox.js
 * ───────────────────────────────────────────────────────────────────────────── */

(function () {
    'use strict';

    var ACTION_URL = './ged_inbox_action.php';
    // Endpoint upload séparé dans /api/ — Hostinger WAF ne bloque pas /api/
    // (pareil que api/bien_import_upload.php qui marche en prod en multipart)
    var UPLOAD_URL = '/api/ged_inbox_upload.php';
    var dz       = document.getElementById('ged-dropzone');
    var dzInput  = document.getElementById('ged-file-input');
    var dzProg   = document.getElementById('dz-progress');
    var dzFill   = document.getElementById('dz-fill');
    var dzText   = document.getElementById('dz-text');
    var card     = document.getElementById('ged-card');

    // ── Dropzone (drag/drop + click) ────────────────────────────────────────
    if (dz) {
        ['dragenter','dragover'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('drag-over'); });
        });
        ['dragleave','drop'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('drag-over'); });
        });
        dz.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) uploadFile(f);
        });
        dz.addEventListener('click', function (e) {
            if (e.target === dzInput || (e.target.closest && e.target.closest('label'))) return;
            dzInput && dzInput.click();
        });
        if (dzInput) {
            dzInput.addEventListener('change', function () {
                if (dzInput.files && dzInput.files[0]) uploadFile(dzInput.files[0]);
            });
        }
    }

    var MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 20 Mo

    function uploadFile(file) {
        if (file.size > MAX_UPLOAD_BYTES) {
            alert('Fichier trop volumineux (' + (Math.round(file.size / 1024 / 1024 * 10) / 10) + ' Mo). Max 20 Mo.');
            resetDropzone();
            return;
        }

        if (dz)    dz.classList.add('uploading');
        if (dzProg) dzProg.style.display = 'block';
        if (dzFill) { dzFill.classList.add('indeterminate'); dzFill.style.width = ''; }
        if (dzText) dzText.textContent = 'Analyse IA en cours… (~5-10s)';

        // Multipart classique vers /api/ — même pattern que api/bien_import_upload.php
        // qui upload des PDFs en prod sans pb. mod_security est plus permissif sur /api/.
        var fd = new FormData();
        fd.append('file', file);

        fetch(UPLOAD_URL, {
            method:      'POST',
            credentials: 'same-origin',
            body:        fd
        })
        .then(function (r) {
            return r.text().then(function (text) {
                return { status: r.status, text: text, headers: r.headers.get('content-type') };
            });
        })
        .then(function (resp) {
            var data = null;
            try { data = JSON.parse(resp.text); }
            catch (parseErr) {
                console.group('[GED upload] Réponse non-JSON');
                console.log('Status:', resp.status);
                console.log('Content-Type:', resp.headers);
                console.log('Body (1000 premiers chars):', resp.text.substring(0, 1000));
                console.log('Body complet ↓');
                console.log(resp.text);
                console.groupEnd();
                var preview = resp.text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 200);
                alert('Réponse serveur invalide (status ' + resp.status + ').\n\nDébut : "' + preview + '..."\n\nDétails console (F12).');
                resetDropzone();
                return;
            }
            if (data.ok) {
                if (window.gedToast) window.gedToast('Document analysé : ' + (data.engine || '') + ' / ' + (data.model || ''), 'success');
                setTimeout(function () { location.href = '?id=' + data.analysis_id; }, 400);
            } else {
                alert('Erreur upload : ' + (data.message || 'inconnue'));
                resetDropzone();
            }
        })
        .catch(function (err) {
            console.error('[GED upload] Erreur fetch:', err);
            alert('Erreur réseau : ' + err.message);
            resetDropzone();
        });
    }

    function resetDropzone() {
        if (dz)    dz.classList.remove('uploading');
        if (dzProg) dzProg.style.display = 'none';
        if (dzInput) dzInput.value = '';
    }

    // ── Boutons Valider / Skip / Rejeter ────────────────────────────────────
    var btnV = document.getElementById('btn-validate');
    var btnS = document.getElementById('btn-skip');
    var btnR = document.getElementById('btn-reject');

    if (btnV) btnV.addEventListener('click', function () { doAction('validate'); });
    if (btnS) btnS.addEventListener('click', function () { doAction('skip'); });
    if (btnR) btnR.addEventListener('click', function () {
        if (confirm('Rejeter ce document ? L\'analyse passera en status=rejected.')) doAction('reject');
    });

    // Raccourcis clavier
    document.addEventListener('keydown', function (e) {
        if (!card) return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            // Permet Enter pour valider depuis n'importe quel champ
            if (e.key === 'Enter' && !e.shiftKey && e.target.type !== 'textarea') {
                e.preventDefault(); doAction('validate');
            }
            return;
        }
        if (e.key === 'v' || e.key === 'V') doAction('validate');
        if (e.key === 's' || e.key === 'S') doAction('skip');
        if (e.key === 'r' || e.key === 'R') doAction('reject');
    });

    function doAction(action) {
        if (!card) return;
        var aid = parseInt(card.getAttribute('data-analysis-id'), 10);
        if (!aid) return;

        // Optimistic UI : on bascule visuel immédiatement
        card.classList.add('transitioning');

        var fd = new FormData();
        fd.append('action', action);
        fd.append('analysis_id', String(aid));

        if (action === 'validate') {
            // Récupère tous les inputs/selects du form
            var form = card.querySelectorAll('input[name], select[name]');
            form.forEach(function (el) { fd.append(el.name, el.value); });
        }

        fetch(ACTION_URL, { method:'POST', body:fd, credentials:'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    if (window.gedToast) window.gedToast('Erreur : ' + (data.message || ''), 'error');
                    card.classList.remove('transitioning');
                    return;
                }
                if (window.gedToast) {
                    var msg = data.message || 'OK';
                    if (data.nom_renomme) msg += ' → ' + data.nom_renomme;
                    window.gedToast(msg, 'success');
                }
                if (data.promote_error) {
                    console.warn('[ged_inbox] Drive upload échoué :', data.promote_error);
                }
                // Charge le suivant
                if (data.next_id) {
                    setTimeout(function () { location.href = '?id=' + data.next_id; }, 300);
                } else {
                    // Plus de doc à valider
                    setTimeout(function () { location.href = location.pathname; }, 300);
                }
            })
            .catch(function (err) {
                if (window.gedToast) window.gedToast('Erreur réseau : ' + err.message, 'error');
                card.classList.remove('transitioning');
            });
    }
})();
