/* global fetch */
/**
 * js/adresse_modal.js — Modal universel de saisie d'adresse
 *
 * Gère l'ouverture/fermeture du modal HTML (#addr-modal), l'injection de la
 * sélection Google/immeuble locale dans les champs cibles de la page hôte,
 * et le POST groupé des 4 champs adresse + coordonnées GPS à l'endpoint
 * d'autosave fourni (ex: api/bien_autosave.php).
 *
 * Déclenché par tout élément avec :
 *   data-addr-modal-open
 *   data-addr-target-street1="id-du-champ-adresse1"
 *   data-addr-target-street2="id-du-champ-adresse2"
 *   data-addr-target-postal="id-du-champ-cp"
 *   data-addr-target-city="id-du-champ-ville"
 *   data-addr-target-lat="id-du-champ-lat"         (optionnel)
 *   data-addr-target-lng="id-du-champ-lng"         (optionnel)
 *   data-addr-target-placeid="id-du-champ-placeid" (optionnel)
 *   data-addr-target-formatted="id-du-champ-formatted" (optionnel)
 *   data-addr-save-endpoint="/api/bien_autosave.php"   (optionnel : POST groupé à la validation)
 *   data-addr-bien-id="12345"                         (requis si save-endpoint fourni)
 *   data-addr-csrf="..."                              (requis si save-endpoint fourni)
 */
(function () {
    'use strict';

    var modal, state = null;

    function $(id) { return document.getElementById(id); }

    function setVal(id, val) {
        var el = $(id);
        if (!el) return;
        el.value = val || '';
        // Si c'est un input avec data-autosave, on déclenche change pour le save natif
        try {
            if (el.hasAttribute && el.hasAttribute('data-autosave')) {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } catch (e) {}
    }

    function openModal(trigger) {
        modal = modal || $('addr-modal');
        if (!modal) {
            console.warn('[adresse_modal] #addr-modal introuvable — avez-vous inclus inc/adresse_modal.php ?');
            return;
        }

        // Collecte la config depuis les data-* du trigger
        state = {
            trigger:        trigger,
            street1:        trigger.getAttribute('data-addr-target-street1')   || '',
            street2:        trigger.getAttribute('data-addr-target-street2')   || '',
            postal:         trigger.getAttribute('data-addr-target-postal')    || '',
            city:           trigger.getAttribute('data-addr-target-city')      || '',
            lat:            trigger.getAttribute('data-addr-target-lat')       || '',
            lng:            trigger.getAttribute('data-addr-target-lng')       || '',
            placeid:        trigger.getAttribute('data-addr-target-placeid')   || '',
            formatted:      trigger.getAttribute('data-addr-target-formatted') || '',
            saveEndpoint:   trigger.getAttribute('data-addr-save-endpoint')    || '',
            bienId:         trigger.getAttribute('data-addr-bien-id')          || '',
            csrf:           trigger.getAttribute('data-addr-csrf')             || '',
        };

        // Pré-remplit les champs du modal avec les valeurs actuelles des champs cibles
        setVal('addr-modal-field-adresse1', state.street1 ? ($(state.street1) && $(state.street1).value || '') : '');
        setVal('addr-modal-field-adresse2', state.street2 ? ($(state.street2) && $(state.street2).value || '') : '');
        setVal('addr-modal-field-cp',       state.postal  ? ($(state.postal)  && $(state.postal).value  || '') : '');
        setVal('addr-modal-field-ville',    state.city    ? ($(state.city)    && $(state.city).value    || '') : '');
        setVal('addr-modal-field-lat',      state.lat     ? ($(state.lat)     && $(state.lat).value     || '') : '');
        setVal('addr-modal-field-lng',      state.lng     ? ($(state.lng)     && $(state.lng).value     || '') : '');
        setVal('addr-modal-field-placeid',  state.placeid ? ($(state.placeid) && $(state.placeid).value || '') : '');
        setVal('addr-modal-field-formatted',state.formatted?($(state.formatted)&&$(state.formatted).value||'') : '');

        // Reset search + status
        var searchInput = $('addr-modal-google-search');
        if (searchInput) searchInput.value = '';
        var status = $('addr-modal-status');
        if (status) { status.textContent = ''; status.className = 'addr-modal-status'; }

        modal.hidden = false;

        // Focus sur la recherche
        setTimeout(function () { if (searchInput) searchInput.focus(); }, 80);
    }

    function closeModal() {
        if (modal) modal.hidden = true;
        state = null;
    }

    function getVal(id) { var el = $(id); return el ? el.value : ''; }

    async function validateAndSave() {
        if (!state) return;

        var btn = $('addr-modal-validate');
        var status = $('addr-modal-status');
        var a1 = getVal('addr-modal-field-adresse1');
        var a2 = getVal('addr-modal-field-adresse2');
        var cp = getVal('addr-modal-field-cp');
        var v  = getVal('addr-modal-field-ville');

        if (!a1.trim()) {
            if (status) {
                status.textContent = '❌ Au moins l\'adresse (rue/numéro) est obligatoire.';
                status.className = 'addr-modal-status err';
            }
            return;
        }

        // 1. Copier les valeurs dans les champs cibles de la page
        if (state.street1)   setVal(state.street1,   a1);
        if (state.street2)   setVal(state.street2,   a2);
        if (state.postal)    setVal(state.postal,    cp);
        if (state.city)      setVal(state.city,      v);
        if (state.lat)       setVal(state.lat,       getVal('addr-modal-field-lat'));
        if (state.lng)       setVal(state.lng,       getVal('addr-modal-field-lng'));
        if (state.placeid)   setVal(state.placeid,   getVal('addr-modal-field-placeid'));
        if (state.formatted) setVal(state.formatted, getVal('addr-modal-field-formatted'));

        // 2. POST groupé à l'endpoint d'autosave si fourni (UN seul appel pour les 4 champs)
        if (state.saveEndpoint && state.bienId) {
            if (btn) btn.disabled = true;
            if (status) {
                status.textContent = '💾 Enregistrement…';
                status.className = 'addr-modal-status';
            }
            try {
                var fd = new FormData();
                fd.append('_edit_id', state.bienId);
                if (state.csrf) fd.append('csrf_token', state.csrf);
                fd.append('adresse_1',          a1);
                fd.append('adresse_2',          a2);
                fd.append('code_postal',        cp);
                fd.append('ville',              v);
                fd.append('latitude',           getVal('addr-modal-field-lat'));
                fd.append('longitude',          getVal('addr-modal-field-lng'));
                fd.append('google_place_id',    getVal('addr-modal-field-placeid'));
                fd.append('adresse_formatted',  getVal('addr-modal-field-formatted'));
                // Si un immeuble existant a été sélectionné (source=local dans l'autocomplete),
                // on envoie son id pour que bien_autosave.php lie directement le bien à cet
                // immeuble (plutôt que de créer un doublon ou de matcher par adresse_cle).
                var immId = getVal('addr-modal-field-immeuble-id');
                if (immId && parseInt(immId, 10) > 0) {
                    fd.append('id_immeuble_selected', immId);
                }

                var res = await fetch(state.saveEndpoint, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                var json = await res.json();
                if (json.ok) {
                    if (status) {
                        status.textContent = '✅ Adresse enregistrée';
                        status.className = 'addr-modal-status ok';
                    }
                    setTimeout(closeModal, 500);
                } else {
                    if (status) {
                        status.textContent = '❌ ' + (json.error || 'Erreur serveur');
                        status.className = 'addr-modal-status err';
                    }
                }
            } catch (e) {
                if (status) {
                    status.textContent = '❌ ' + e.message;
                    status.className = 'addr-modal-status err';
                }
            } finally {
                if (btn) btn.disabled = false;
            }
        } else {
            // Pas d'endpoint → on ferme juste (champs déjà remplis côté page)
            closeModal();
        }
    }

    // ── Wiring global au DOMContentLoaded ─────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Délégation : boutons d'ouverture
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('[data-addr-modal-open]') : null;
            if (trigger) {
                e.preventDefault();
                openModal(trigger);
                return;
            }
            var closer = e.target.closest ? e.target.closest('[data-addr-modal-close]') : null;
            if (closer) { e.preventDefault(); closeModal(); return; }
        });

        // Bouton Valider (unique)
        var valBtn = $('addr-modal-validate');
        if (valBtn) valBtn.addEventListener('click', validateAndSave);

        // Échap ferme
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
        });
    });

    // Exposer pour usage avancé
    window.__addrModal = { open: openModal, close: closeModal };
})();
