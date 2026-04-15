/* global fetch */
(function () {
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
    }

    function initForInput(input) {
        if (!input) return;

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

        var hasGoogle = (typeof google !== 'undefined'
            && google.maps
            && google.maps.places
            && google.maps.places.AutocompleteService);

        var autocompleteService = hasGoogle ? new google.maps.places.AutocompleteService() : null;
        var detailsService = hasGoogle ? new google.maps.places.PlacesService(document.createElement('div')) : null;

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
            var rect = input.getBoundingClientRect();
            dropdown.style.width = rect.width + 'px';
            dropdown.style.left = (rect.left + window.scrollX) + 'px';
            dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
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
                releaseSelection();
                return;
            }

            if (hasGoogle && detailsService && placeId) {
                window.setTimeout(releaseSelection, 800);
                detailsService.getDetails({
                    placeId: placeId,
                    fields: ['address_components', 'geometry', 'place_id', 'formatted_address'],
                }, function (place, status) {
                    if (!place || !place.address_components) {
                        input.value = item.textContent || input.value;
                        releaseSelection();
                        return;
                    }

                    var components = place.address_components || [];
                    var getComponent = function (type) {
                        for (var i = 0; i < components.length; i++) {
                            var c = components[i];
                            if (c && Array.isArray(c.types) && c.types.indexOf(type) !== -1) {
                                return c.long_name || '';
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

                    fillFromData(input, {
                        adresse_1: line1,
                        adresse_2: '',
                        code_postal: postalCode,
                        ville: city,
                        quartier: quartier,
                        pays: countryName || 'France',
                        latitude: place.geometry && place.geometry.location ? String(place.geometry.location.lat()) : '',
                        longitude: place.geometry && place.geometry.location ? String(place.geometry.location.lng()) : '',
                        google_place_id: place.place_id || placeId,
                        adresse_formatee: place.formatted_address || '',
                        immeuble_connu: false,
                    });

                    var immeubleIdId = input.getAttribute('data-places-immeuble-id');
                    setValue(immeubleIdId, '');

                    if (place.formatted_address) {
                        input.value = place.formatted_address;
                    }
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
            if (hasGoogle && autocompleteService) {
                googlePromise = new Promise(function (resolve) {
                    autocompleteService.getPlacePredictions({
                        input: value,
                        types: ['address'],
                        componentRestrictions: { country: country },
                    }, function (predictions) {
                        var results = [];
                        if (Array.isArray(predictions)) {
                            predictions.forEach(function (p) {
                                results.push({
                                    source: 'google',
                                    label: p.description || '',
                                    place_id: p.place_id || '',
                                    payload: {},
                                });
                            });
                        }
                        resolve(results);
                    });
                });
            }

            Promise.all([localPromise, googlePromise]).then(function (results) {
                if (value !== lastRequest) return;
                var combined = [].concat(results[0] || [], results[1] || []);
                renderPredictions(combined);
            });
        }

        input.addEventListener('input', function () {
            if (isSelecting) return;
            var value = (input.value || '').trim();
            if (value.length < 3) {
                hideDropdown();
                return;
            }
            requestPredictions(value);
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
