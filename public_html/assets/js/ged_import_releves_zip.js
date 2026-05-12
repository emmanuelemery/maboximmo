/* GED — Import ZIP relevés bancaires (front)
 * Fichier : assets/js/ged_import_releves_zip.js
 */

(function () {
    'use strict';

    var UPLOAD_URL = '/api/ged_import_releves_zip_upload.php';
    var STATUS_URL = '/api/ged_import_releves_zip_status.php';
    var ACTION_URL = '/api/ged_import_releves_zip_action.php';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function h(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function badge(status) {
        var st = String(status || '');
        var cls = 'badge';
        return '<span class="' + cls + '">' + h(st || '—') + '</span>';
    }

    function buildImmeubleSelect(currentId) {
        var list = Array.isArray(window.GED_IMMEUBLES) ? window.GED_IMMEUBLES : [];
        var html = '<select class="imm-select">';
        html += '<option value="">—</option>';
        for (var i = 0; i < list.length; i++) {
            var it = list[i] || {};
            var id = parseInt(it.id, 10) || 0;
            if (!id) continue;
            var label = (it.ref ? it.ref + ' · ' : '') + (it.nom || '') + (it.ville ? ' · ' + it.ville : '');
            var sel = (currentId && id === currentId) ? ' selected' : '';
            html += '<option value="' + id + '"' + sel + '>' + h(label) + '</option>';
        }
        html += '</select>';
        return html;
    }

    function ymValue(yy, mm) {
        if (!yy || !mm) return '';
        var m = String(mm).padStart(2, '0');
        return String(yy) + '-' + m;
    }

    function renderItems(items) {
        var table = document.getElementById('items-table');
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        if (!items || !items.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="empty">Aucun PDF extrait pour le moment…</td></tr>';
            return;
        }

        var out = '';
        for (var i = 0; i < items.length; i++) {
            var it = items[i] || {};
            var id = parseInt(it.id, 10) || 0;
            var immId = it.id_immeuble ? parseInt(it.id_immeuble, 10) : 0;
            var period = ymValue(it.periode_annee, it.periode_mois);
            var conf = (it.confiance_immeuble != null) ? (Math.round(parseFloat(it.confiance_immeuble)) + '%') : '—';
            var drive = '';
            if (it.drive_url_immeuble) drive += '<a href="' + h(it.drive_url_immeuble) + '" target="_blank">Immeuble</a> ';
            if (it.drive_url_compta) drive += '<a href="' + h(it.drive_url_compta) + '" target="_blank">Compta</a>';

            out += '<tr data-item-id="' + id + '">';
            out += '<td>' + h(it.zip_original || '—') + '</td>';
            out += '<td title="' + h(it.fichier_original || '') + '">' + h(it.fichier_original || '—') + '</td>';
            out += '<td>' + buildImmeubleSelect(immId) + '</td>';
            out += '<td><select class="log-select">'
                + '<option value="">—</option>'
                + '<option value="SEPTEO"' + (it.logiciel_comptable === 'SEPTEO' ? ' selected' : '') + '>SEPTEO</option>'
                + '<option value="LOJJI"' + (it.logiciel_comptable === 'LOJJI' ? ' selected' : '') + '>LOJJI</option>'
                + '<option value="ICS"' + (it.logiciel_comptable === 'ICS' ? ' selected' : '') + '>ICS</option>'
                + '<option value="MABOXIMMO"' + (it.logiciel_comptable === 'MABOXIMMO' ? ' selected' : '') + '>MaBoxImmo</option>'
                + '<option value="AUTRE"' + (it.logiciel_comptable === 'AUTRE' ? ' selected' : '') + '>AUTRE</option>'
              + '</select></td>';
            out += '<td><input class="period-input" type="month" value="' + h(period) + '"></td>';
            out += '<td><input class="bank-input" type="text" value="' + h(it.banque_detectee || '') + '" placeholder="Banque"></td>';
            out += '<td>' + h(conf) + '</td>';
            out += '<td>' + badge(it.statut) + '</td>';
            out += '<td>' + (drive || '<span style="color:#94a3b8">—</span>') + '</td>';
            out += '<td><div class="cell-actions">'
                + '<button type="button" class="aged-btn btn-save">Enregistrer</button>'
                + '</div></td>';
            out += '</tr>';
        }
        tbody.innerHTML = out;
    }

    function setStatus(el, msg, kind) {
        if (!el) return;
        el.textContent = msg || '';
        el.style.color = (kind === 'error') ? '#b91c1c' : (kind === 'success' ? '#14532d' : '#475569');
    }

    function postAction(payload) {
        var fd = new FormData();
        Object.keys(payload || {}).forEach(function (k) {
            if (payload[k] != null) fd.append(k, String(payload[k]));
        });
        fd.append('csrf_token', csrfToken());
        return fetch(ACTION_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    // ── Upload ZIP ─────────────────────────────────────────────────────────
    var form = document.getElementById('releves-upload-form');
    var inputZips = document.getElementById('releves-zips');
    var inputPeriod = document.getElementById('releves-default-period');
    var uploadStatus = document.getElementById('releves-upload-status');

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!inputZips || !inputZips.files || !inputZips.files.length) return;

            var fd = new FormData();
            fd.append('csrf_token', csrfToken());
            if (inputPeriod && inputPeriod.value) fd.append('mois_annee_defaut', inputPeriod.value);
            for (var i = 0; i < inputZips.files.length; i++) {
                fd.append('zips[]', inputZips.files[i], inputZips.files[i].name);
            }

            setStatus(uploadStatus, 'Envoi des ZIP…', 'info');
            fetch(UPLOAD_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) throw new Error((data && data.message) ? data.message : 'Upload échoué');
                    setStatus(uploadStatus, 'Batch #' + data.batch_id + ' créé. Analyse en cours…', 'success');
                    setTimeout(function () {
                        var url = new URL(window.location.href);
                        url.searchParams.set('batch_id', String(data.batch_id));
                        window.location.href = url.toString();
                    }, 500);
                })
                .catch(function (err) {
                    setStatus(uploadStatus, 'Erreur : ' + err.message, 'error');
                });
        });
    }

    // ── Batch view (poll status) ───────────────────────────────────────────
    var batchId = parseInt(window.GED_RELEVES_BATCH_ID || 0, 10) || 0;
    var batchStatus = document.getElementById('batch-status');
    var btnValRec = document.getElementById('btn-validate-rec');
    var btnValAll = document.getElementById('btn-validate-all');
    var btnReanalyze = document.getElementById('btn-reanalyze');
    var btnReject = document.getElementById('btn-reject');

    function runValidate(which) {
        if (!batchId) return;
        var label = which === 'validate_all' ? 'Validation globale…' : 'Validation reconnus…';
        setStatus(batchStatus, label, 'info');
        postAction({ action: which, batch_id: batchId })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'Erreur');
                setStatus(batchStatus, data.message || 'OK', 'success');
            })
            .catch(function (err) { setStatus(batchStatus, 'Erreur : ' + err.message, 'error'); });
    }

    if (btnValRec) {
        btnValRec.addEventListener('click', function () {
            if (!confirm('Importer sur Drive tous les items reconnus (confiance forte) ?')) return;
            runValidate('validate_recognized');
        });
    }
    if (btnValAll) {
        btnValAll.addEventListener('click', function () {
            if (!confirm('Importer sur Drive tous les items (inclut douteux) ?')) return;
            runValidate('validate_all');
        });
    }

    if (btnReanalyze) {
        btnReanalyze.addEventListener('click', function () {
            if (!batchId) return;
            setStatus(batchStatus, 'Relance analyse…', 'info');
            postAction({ action: 'reanalyze', batch_id: batchId })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'Erreur');
                    setStatus(batchStatus, data.message || 'OK', 'success');
                })
                .catch(function (err) { setStatus(batchStatus, 'Erreur : ' + err.message, 'error'); });
        });
    }
    if (btnReject) {
        btnReject.addEventListener('click', function () {
            if (!batchId) return;
            if (!confirm('Rejeter tous les items non reconnus / douteux non importés ?')) return;
            setStatus(batchStatus, 'Rejet…', 'info');
            postAction({ action: 'reject_unrecognized', batch_id: batchId })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'Erreur');
                    setStatus(batchStatus, data.message || 'OK', 'success');
                })
                .catch(function (err) { setStatus(batchStatus, 'Erreur : ' + err.message, 'error'); });
        });
    }

    function poll() {
        if (!batchId) return;
        fetch(STATUS_URL + '?batch_id=' + encodeURIComponent(String(batchId)), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) throw new Error((data && data.message) ? data.message : 'Status KO');
                var b = data.batch || {};
                var msg = 'Batch #' + b.id + ' · ' + (b.statut || '?')
                    + ' · ZIP=' + (b.nb_zip || 0) + ' PDF=' + (b.nb_pdf || 0)
                    + ' · reconnus=' + (b.nb_reconnus || 0) + ' à_valider=' + (b.nb_a_valider || 0)
                    + ' · erreurs=' + (b.nb_erreurs || 0);
                if (b.statut === 'error' && b.commentaire) {
                    msg += ' — ' + String(b.commentaire);
                }
                setStatus(batchStatus, msg, (b.statut === 'error') ? 'error' : 'info');
                renderItems(data.items || []);
            })
            .catch(function (err) {
                setStatus(batchStatus, 'Erreur status : ' + err.message, 'error');
            });
    }

    if (batchId) {
        poll();
        setInterval(poll, 3500);
    }

    // Save per row
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.classList ? (e.target.classList.contains('btn-save') ? e.target : null) : null;
        if (!btn) return;
        var tr = btn.closest('tr');
        if (!tr) return;
        var itemId = parseInt(tr.getAttribute('data-item-id') || '0', 10) || 0;
        if (!itemId) return;

        var selImm = tr.querySelector('.imm-select');
        var selLog = tr.querySelector('.log-select');
        var inpPer = tr.querySelector('.period-input');
        var inpBank = tr.querySelector('.bank-input');

        btn.disabled = true;
        postAction({
            action: 'update_item',
            batch_id: batchId,
            item_id: itemId,
            id_immeuble: selImm && selImm.value ? selImm.value : '',
            logiciel_comptable: selLog && selLog.value ? selLog.value : '',
            periode: inpPer && inpPer.value ? inpPer.value : '',
            banque_detectee: inpBank && inpBank.value ? inpBank.value : ''
        })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'Erreur');
                if (window.gedToast) window.gedToast('Item #' + itemId + ' mis à jour', 'success');
            })
            .catch(function (err) {
                if (window.gedToast) window.gedToast('Erreur: ' + err.message, 'error');
            })
            .finally(function () { btn.disabled = false; });
    });
})();
