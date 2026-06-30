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
    var nomTouched = false;

    function $(id) { return document.getElementById(id); }

    // Convention nom immeuble (figée) : "{numéro} {voie SANS type}_{VILLE}",
    // MAJUSCULES, sans accent. Ex : "12 Place Saint Jean" + "Riom" -> "12 SAINT JEAN_RIOM".
    function deriveNom(adr, ville) {
        if (!adr) return '';
        var types = /\b(rue|avenue|av|bd|boulevard|impasse|imp|chemin|chem|allee|all[ée]e|place|pl|route|rte|quai|cours|passage|pass|square|sq|sentier|villa|voie|montee|mont[ée]e|esplanade|faubourg|fbg|traverse)\b/gi;
        var stripAccent = function (s) {
            return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
        };
        var voie = stripAccent(adr).replace(types, ' ').replace(/\s+/g, ' ').trim().toUpperCase();
        var vl = ville ? stripAccent(ville).replace(/\s+/g, ' ').trim().toUpperCase() : '';
        return vl ? voie + '_' + vl : voie;
    }

    // Met à jour la ligne GPS + propose le nom (si l'utilisateur n'y a pas touché).
    function refreshGpsAndNom() {
        var gpsEl = $('addr-modal-gps');
        if (gpsEl) {
            var la = getVal('addr-modal-field-lat').trim();
            var lo = getVal('addr-modal-field-lng').trim();
            gpsEl.textContent = (la && lo)
                ? '📍 GPS : ' + parseFloat(la).toFixed(6) + ', ' + parseFloat(lo).toFixed(6)
                : '';
        }
        if (!nomTouched) {
            var nomEl = $('addr-modal-field-nom');
            if (nomEl) nomEl.value = deriveNom(getVal('addr-modal-field-adresse1'), getVal('addr-modal-field-ville'));
        }
    }

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
            nom:            trigger.getAttribute('data-addr-target-nom')       || '',
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

        // Nom immeuble : pré-rempli depuis la cible si fournie, sinon proposé depuis l'adresse.
        var nomPrefill = state.nom ? ($(state.nom) && $(state.nom).value || '') : '';
        setVal('addr-modal-field-nom', nomPrefill);
        nomTouched = nomPrefill.trim() !== '';
        refreshGpsAndNom();

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
        if (state.nom)       setVal(state.nom,       getVal('addr-modal-field-nom'));

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
                var nomImm = getVal('addr-modal-field-nom').trim();
                if (nomImm) fd.append('nom_immeuble', nomImm);

                var res = await fetch(state.saveEndpoint, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                // Lecture en texte d'abord : si le serveur renvoie un corps vide
                // ou non-JSON (fatale PHP masquée, redirection login, 404…), on
                // surface le vrai code HTTP + un extrait au lieu du cryptique
                // "Unexpected end of JSON input".
                var raw = await res.text();
                var json;
                try {
                    json = raw ? JSON.parse(raw) : {};
                } catch (parseErr) {
                    var snippet = (raw || '').replace(/\s+/g, ' ').trim().slice(0, 160);
                    throw new Error(
                        'Réponse serveur invalide (HTTP ' + res.status + ')'
                        + (snippet ? ' : ' + snippet : ' : corps vide')
                    );
                }
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

        // Nom immeuble : marque "touché" dès que l'utilisateur édite manuellement.
        var nomInput = $('addr-modal-field-nom');
        if (nomInput) nomInput.addEventListener('input', function () {
            nomTouched = nomInput.value.trim() !== '';
        });

        // L'autocomplete (places.js) remplit les champs par programme + émet 'places:filled'.
        // → on rafraîchit GPS + proposition de nom à ce moment-là.
        var searchInput = $('addr-modal-google-search');
        if (searchInput) searchInput.addEventListener('places:filled', refreshGpsAndNom);

        // Édition manuelle de l'adresse → reproposer le nom (si non touché).
        var adr1 = $('addr-modal-field-adresse1');
        if (adr1) adr1.addEventListener('input', refreshGpsAndNom);

        // Échap ferme
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
        });
    });

    // Exposer pour usage avancé
    window.__addrModal = { open: openModal, close: closeModal };
})();
