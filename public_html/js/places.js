/* global fetch */
(function () {
    // CSS de base injecté par le script lui-même : garantit que le menu déroulant
    // s'affiche bien positionné et AU-DESSUS de tout (modals inclus), même sur une
    // page qui n'embarque pas le CSS .places-dropdown. position:fixed + z-index max.
    (function injectPlacesCss() {
        if (document.getElementById('places-base-css')) return;
        var st = document.createElement('style');
        st.id = 'places-base-css';
        st.textContent =
            '.places-dropdown{position:fixed !important;z-index:2147483600 !important;background:#fff;'
          + 'border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.18);'
          + 'max-height:300px;overflow:auto;}'
          + '.places-item{padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;color:#0f172a;}'
          + '.places-item:last-child{border-bottom:0;}'
          + '.places-item.active,.places-item:hover{background:#eef5fc;}';
        (document.head || document.documentElement).appendChild(st);
    })();

    function byId(id) {
        if (!id) return null;
        return document.getElementById(id);
    }

    function setValue(id, value) {
        var el = byId(id);
        if (!el) return;
        el.value = value || '';
    }

    function fillFromData(input, data) {
        var addr1Id      = input.getAttribute('data-places-street1');
        var addr2Id      = input.getAttribute('data-places-street2');
        var postalId     = input.getAttribute('data-places-postal');
        var cityId       = input.getAttribute('data-places-city');
        var quartierIdEl = input.getAttribute('data-places-quartier');
        var countryId    = input.getAttribute('data-places-country');
        var placeIdId    = input.getAttribute('data-places-place-id');
        var formattedId  = input.getAttribute('data-places-formatted');
        var latId        = input.getAttribute('data-places-lat');
        var lngId        = input.getAttribute('data-places-lng');
        var immeubleIdId = input.getAttribute('data-places-immeuble-id');
        var connuBadgeId = input.getAttribute('data-places-immeuble-badge');

        setValue(addr1Id, data.adresse_1 || '');
        setValue(addr2Id, data.adresse_2 || '');
        setValue(postalId, data.code_postal || '');
        setValue(cityId, data.ville || '');
        if (quartierIdEl) setValue(quartierIdEl, data.quartier || '');
        setValue(countryId, data.pays || '');
        setValue(placeIdId, data.google_place_id || '');
        setValue(formattedId, data.adresse_formatee || '');
        setValue(latId, data.latitude || '');
        setValue(lngId, data.longitude || '');
        setValue(immeubleIdId, data.immeuble_id || '');

        // Badge immeuble connu / nouveau
        var badge = connuBadgeId ? document.getElementById(connuBadgeId) : null;
        if (badge) {
            if (data.immeuble_connu) {
                badge.textContent = '✅ Immeuble déjà dans la base';
                badge.className = 'places-immeuble-badge known';
            } else if (data.adresse_1 || data.google_place_id) {
                badge.textContent = '🆕 Nouvel immeuble — sera créé à l\'enregistrement';
                badge.className = 'places-immeuble-badge new';
            } else {
                badge.textContent = '';
                badge.className = 'places-immeuble-badge';
            }
        }

        try {
            input.dispatchEvent(new CustomEvent('places:filled', { detail: data }));
        } catch (e) {}
    }

    function initForInput(input) {
        if (!input) return;
        // Anti double-init : le SDK Google (async) rappelle initPlacesAutocomplete
        // une fois chargé → sans ce garde, l'input est ré-initialisé (déroulants +
        // écouteurs en double = sélection qui part dans le mauvais clone).
        if (input.__placesInited) return;
        input.__placesInited = true;

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('autocorrect', 'off');
        input.setAttribute('spellcheck', 'false');

        var country = input.getAttribute('data-places-country-code') || 'fr';
        var endpoint = input.getAttribute('data-places-endpoint') || '';
        var detailsEndpoint = input.getAttribute('data-places-details-endpoint') || '';
        var geocodeEndpoint = input.getAttribute('data-places-geocode-endpoint') || '';

        var dropdown = document.createElement('div');
        dropdown.className = 'places-dropdown';
        dropdown.style.display = 'none';
        document.body.appendChild(dropdown);

        // Google évalué À LA DEMANDE (le SDK peut finir de charger APRÈS ce 1er init) :
        // ainsi un seul init gère local + Google dès que le SDK est prêt.
        // Nouvelle API Places (mars 2025+) : AutocompleteSuggestion + Place.
        // (l'ancienne AutocompleteService/PlacesService est dépréciée → coupures aléatoires)
        function googleReady() {
            return (typeof google !== 'undefined' && google.maps && google.maps.places
                && google.maps.places.AutocompleteSuggestion
                && google.maps.places.Place);
        }

        var active = -1;
        var items = [];
        var lastRequest = null;
        var isSelecting = false;

        function focusNextField() {
            var addr1Id = input.getAttribute('data-places-street1');
            var postalId = input.getAttribute('data-places-postal');
            var cityId = input.getAttribute('data-places-city');
            var target = byId(addr1Id) || byId(postalId) || byId(cityId);
            if (target && typeof target.focus === 'function') {
                target.focus();
            }
        }

        function positionDropdown() {
            // position:fixed → coordonnées viewport (getBoundingClientRect), SANS scrollX/Y.
            var rect = input.getBoundingClientRect();
            dropdown.style.width = rect.width + 'px';
            dropdown.style.left = rect.left + 'px';
            dropdown.style.top = rect.bottom + 'px';
        }

        function hideDropdown() {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            items = [];
            active = -1;
        }

        function showDropdown() {
            if (!items.length) {
                hideDropdown();
                return;
            }
            positionDropdown();
            dropdown.style.display = 'block';
        }

        function renderPredictions(predictions) {
            dropdown.innerHTML = '';
            items = [];
            active = -1;

            if (!predictions || !predictions.length) {
                hideDropdown();
                return;
            }

            predictions.forEach(function (p) {
                var item = document.createElement('div');
                item.className = 'places-item';
                item.textContent = p.label || '';
                item.setAttribute('data-place-id', p.place_id || '');
                item.setAttribute('data-source', p.source || 'local');
                item.setAttribute('data-payload', JSON.stringify(p.payload || {}));
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectPlace(item);
                });
                dropdown.appendChild(item);
                items.push(item);
            });

            showDropdown();
        }

        function selectPlace(item) {
            if (!item) return;
            var source = item.getAttribute('data-source') || 'local';
            var placeId = item.getAttribute('data-place-id') || '';
            var payload = {};
            var released = false;

            function releaseSelection() {
                if (released) return;
                released = true;
                isSelecting = false;
            }

            try {
                payload = JSON.parse(item.getAttribute('data-payload') || '{}');
            } catch (e) {
                payload = {};
            }

            isSelecting = true;
            hideDropdown();
            window.setTimeout(function () {
                focusNextField();
            }, 0);

            if (source === 'local') {
                fillFromData(input, payload);
                input.value = payload.label || item.textContent || input.value;
                // GPS : si l'immeuble enregistré n'a pas de coordonnées en base,
                // on les récupère par géocodage de l'adresse (la ligne 📍 s'affiche).
                var hasGps = payload.latitude && payload.longitude;
                if (!hasGps && geocodeEndpoint) {
                    var q = [payload.adresse_1, payload.code_postal, payload.ville]
                        .filter(function (v) { return v; }).join(' ').trim();
                    if (q.length >= 4) {
                        var latId2 = input.getAttribute('data-places-lat');
                        var lngId2 = input.getAttribute('data-places-lng');
                        fetch(geocodeEndpoint + '?q=' + encodeURIComponent(q))
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                if (data && data.ok && data.latitude && data.longitude) {
                                    setValue(latId2, data.latitude);
                                    setValue(lngId2, data.longitude);
                                }
                            })
                            .catch(function () {});
                    }
                }
                releaseSelection();
                return;
            }

            if (googleReady() && placeId) {
                window.setTimeout(releaseSelection, 1500);
                var place = new google.maps.places.Place({ id: placeId });
                place.fetchFields({
                    fields: ['addressComponents', 'location', 'formattedAddress', 'id'],
                }).then(function () {
                    var components = place.addressComponents || [];
                    var getComponent = function (type) {
                        for (var i = 0; i < components.length; i++) {
                            var c = components[i];
                            if (c && Array.isArray(c.types) && c.types.indexOf(type) !== -1) {
                                return c.longText || c.shortText || '';
                            }
                        }
                        return '';
                    };

                    var streetNumber = getComponent('street_number');
                    var route = getComponent('route');
                    var line1 = (streetNumber + ' ' + route).trim();
                    var postalCode = getComponent('postal_code');
                    var city = getComponent('locality')
                        || getComponent('postal_town')
                        || getComponent('administrative_area_level_2');
                    var countryName = getComponent('country');
                    var quartier = getComponent('neighborhood')
                        || getComponent('sublocality_level_1')
                        || getComponent('sublocality');
                    var loc = place.location;

                    fillFromData(input, {
                        adresse_1: line1,
                        adresse_2: '',
                        code_postal: postalCode,
                        ville: city,
                        quartier: quartier,
                        pays: countryName || 'France',
                        latitude: loc ? String(loc.lat()) : '',
                        longitude: loc ? String(loc.lng()) : '',
                        google_place_id: place.id || placeId,
                        adresse_formatee: place.formattedAddress || '',
                        immeuble_connu: false,
                    });

                    var immeubleIdId = input.getAttribute('data-places-immeuble-id');
                    setValue(immeubleIdId, '');

                    if (place.formattedAddress) {
                        input.value = place.formattedAddress;
                    }
                    releaseSelection();
                }).catch(function () {
                    input.value = item.textContent || input.value;
                    releaseSelection();
                });
                return;
            }

            if (!detailsEndpoint || !placeId) {
                input.value = item.textContent || input.value;
                releaseSelection();
                return;
            }

            window.setTimeout(releaseSelection, 800);
            fetch(detailsEndpoint + '?place_id=' + encodeURIComponent(placeId))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        input.value = item.textContent || input.value;
                        return;
                    }
                    fillFromData(input, data);
                    var immeubleIdId = input.getAttribute('data-places-immeuble-id');
                    setValue(immeubleIdId, '');
                    if (data.adresse_formatee) {
                        input.value = data.adresse_formatee;
                    }
                })
                .catch(function () {
                    input.value = item.textContent || input.value;
                })
                .then(function () {
                    releaseSelection();
                });
        }

        function requestPredictions(value) {
            lastRequest = value;

            var localPromise = Promise.resolve([]);
            if (endpoint) {
                localPromise = fetch(endpoint + '?q=' + encodeURIComponent(value) + '&country=' + encodeURIComponent(country))
                    .then(function (res) { return res.json(); })
                    .then(function (data) { return (data && data.items) ? data.items : []; })
                    .catch(function () { return []; });
            }

            var googlePromise = Promise.resolve([]);
            if (googleReady()) {
                googlePromise = google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: value,
                    includedRegionCodes: [country],
                    language: 'fr',
                }).then(function (res) {
                    var results = [];
                    var suggestions = (res && res.suggestions) || [];
                    suggestions.forEach(function (s) {
                        var pred = s.placePrediction;
                        if (!pred) return;
                        var label = (pred.text && pred.text.text)
                            ? pred.text.text
                            : (pred.mainText && pred.mainText.text ? pred.mainText.text : '');
                        results.push({
                            source: 'google',
                            label: label,
                            place_id: pred.placeId || '',
                            payload: {},
                        });
                    });
                    return results;
                }).catch(function () { return []; });
            }

            Promise.all([localPromise, googlePromise]).then(function (results) {
                if (value !== lastRequest) return;
                var combined = [].concat(results[0] || [], results[1] || []);
                // Dédup : local + Google renvoient souvent la MÊME adresse → on ne garde
                // qu'une ligne par libellé (le local, listé en premier, prime = reprise base).
                var seen = {};
                combined = combined.filter(function (p) {
                    var key = ((p && p.label) || '').toLowerCase().replace(/\s+/g, ' ').trim();
                    if (!key || seen[key]) return false;
                    seen[key] = true;
                    return true;
                });
                renderPredictions(combined);
            });
        }

        // Debounce 250ms : évite de redessiner le dropdown pendant que l'user vise un item
        // (sinon race condition = click atterrit sur un autre item fraîchement remplacé).
        // Réduit aussi la charge sur l'API Google Places.
        var inputDebounceTimer = null;
        input.addEventListener('input', function () {
            if (isSelecting) return;
            var value = (input.value || '').trim();
            if (value.length < 3) {
                if (inputDebounceTimer) { clearTimeout(inputDebounceTimer); inputDebounceTimer = null; }
                hideDropdown();
                return;
            }
            if (inputDebounceTimer) clearTimeout(inputDebounceTimer);
            inputDebounceTimer = setTimeout(function () {
                if (isSelecting) return;
                requestPredictions(value);
            }, 250);
        });

        input.addEventListener('keydown', function (e) {
            if (!items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                active = Math.min(active + 1, items.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                active = Math.max(active - 1, 0);
            } else if (e.key === 'Enter') {
                if (active >= 0 && items[active]) {
                    e.preventDefault();
                    selectPlace(items[active]);
                }
            } else if (e.key === 'Escape') {
                hideDropdown();
            }

            items.forEach(function (it, idx) {
                if (idx === active) {
                    it.classList.add('active');
                } else {
                    it.classList.remove('active');
                }
            });
        });

        input.addEventListener('focus', function () {
            if (items.length) {
                showDropdown();
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(hideDropdown, 150);

            if (!geocodeEndpoint) return;
            var value = (input.value || '').trim();
            if (value.length < 6) return;

            var addr1Id = input.getAttribute('data-places-street1');
            var postalId = input.getAttribute('data-places-postal');
            var cityId = input.getAttribute('data-places-city');

            if (byId(addr1Id) && byId(addr1Id).value) return;
            if (byId(postalId) && byId(postalId).value) return;
            if (byId(cityId) && byId(cityId).value) return;

            fetch(geocodeEndpoint + '?q=' + encodeURIComponent(value))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.ok) return;
                    fillFromData(input, data);
                    if (data.adresse_formatee) {
                        input.value = data.adresse_formatee;
                    }
                })
                .catch(function () {});
        });

        window.addEventListener('resize', positionDropdown);
        window.addEventListener('scroll', positionDropdown, true);
    }

    window.initPlacesAutocomplete = function () {
        var inputs = document.querySelectorAll('[data-places-input]');
        for (var i = 0; i < inputs.length; i++) {
            initForInput(inputs[i]);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.initPlacesAutocomplete();
    });
})();

