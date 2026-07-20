/*
 * mission-cascade.js
 * Rôle   : Câblage de la CASCADE Phase 1 (Mise en location) sur le backend EXISTANT.
 *          On ne réécrit AUCUNE API — on branche celles de bien_nouveau.php.
 * Étape 1 (immeuble) : recherche Google (places.js → 'places:filled')
 *          → reconnaissance immeuble + infos publiques auto (cadastre/PLU, urbanisme,
 *            risques ERP, copropriété, altitude) + Street View
 *          → confirmation → anti-doublon (immeuble_contenu : lots + propriétaires).
 * Contexte/endpoints : window.MBI_MISSION.location (injecté par la page PHP).
 * Réf    : bien_nouveau.php (logique étape 1 transposée en module centralisé).
 * Date   : 2026-07-20
 */
(function () {
  'use strict';
  var CFG = (window.MBI_MISSION && window.MBI_MISSION.location) || null;
  if (!CFG) return;
  var EP = CFG.endpoints || {};
  var GKEY = CFG.gmapsKey || '';

  function $(id) { return document.getElementById(id); }
  var addr = $('ml-addr');
  if (!addr) return;

  /* Payload cumulé — sera envoyé à api/bien_creer.php en fin de Phase 1 */
  var ML = window.MLcreate = { immeuble: null, enrich: {}, existingBienId: 0, proprietaire: null, bien: null, idAgence: CFG.idAgence || 0 };
  var reco = { immeuble_id: 0, nom: '', lots: 0, immat: '' };

  var geo = {};
  var linesEl = $('ml-imm-lines');
  var pending = 0, confirmShown = false;

  function q(url) {
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    var u = url + sep + 'lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng);
    if (geo.cp) u += '&cp=' + encodeURIComponent(geo.cp);
    if (geo.adresse1) u += '&voie=' + encodeURIComponent(geo.adresse1);
    return u;
  }
  function line(key, label) {
    var d = document.createElement('div'); d.className = 'datarow'; d.id = 'mll-' + key;
    d.innerHTML = '<span class="k">' + label + '</span><span class="v" data-v>…</span>'
      + '<span class="conf ia" data-c><span class="d"></span>en cours</span>';
    linesEl.appendChild(d); return d;
  }
  function doneLine(key, ok, text) {
    var d = $('mll-' + key); if (!d) return;
    d.querySelector('[data-v]').textContent = text || '';
    var c = d.querySelector('[data-c]');
    c.className = 'conf ' + (ok ? 'ok' : 'miss');
    c.innerHTML = '<span class="d"></span>' + (ok ? 'ok' : '—');
    if (--pending === 0) finish();
  }
  function run(key, label, url, handler) {
    pending++; line(key, label);
    fetch(url).then(function (r) { return r.json(); }).then(handler)
      .catch(function () { doneLine(key, false, 'indisponible'); });
  }

  addr.addEventListener('places:filled', function (ev) {
    var d = ev.detail || {};
    geo = {
      lat: d.latitude || '', lng: d.longitude || '', adresse1: d.adresse_1 || '', ville: d.ville || '',
      cp: d.code_postal || '', placeid: d.google_place_id || d.place_id || '',
      label: d.adresse_formatee || ((d.adresse_1 || '') + ' ' + (d.ville || ''))
    };
    reco = { immeuble_id: 0, nom: '', lots: 0, immat: '' };
    ML.enrich = {}; ML.existingBienId = 0;
    confirmShown = false; pending = 0;
    $('ml-imm-result').hidden = false;
    linesEl.innerHTML = ''; $('ml-imm-doublon').innerHTML = '';
    $('ml-imm-confirm').hidden = true;
    $('ml-imm-state').textContent = 'Analyse en cours…';

    /* Reconnaissance immeuble (base MBI) — n'exige PAS les coordonnées */
    var recoUrl = EP.immeuble_reco + '?adresse_1=' + encodeURIComponent(d.adresse_1 || '')
      + '&code_postal=' + encodeURIComponent(d.code_postal || '') + '&ville=' + encodeURIComponent(d.ville || '')
      + '&place_id=' + encodeURIComponent(geo.placeid);
    run('recon', '🏢 Reconnaissance immeuble', recoUrl, function (a) {
      if (a && a.connu) { reco.immeuble_id = a.immeuble_id; reco.nom = a.nom_immeuble || ''; doneLine('recon', true, 'reconnu #' + a.immeuble_id); fetchProprios(a.immeuble_id); }
      else doneLine('recon', true, 'nouvel immeuble');
      revealConfirm();
    });

    /* Street View : seulement si coordonnées géocodées */
    var hasCoords = !!(geo.lat && geo.lng);
    if (!hasCoords) { var sv0 = $('ml-sv'); if (sv0) sv0.style.display = 'none'; }
    else if (GKEY) {
      var sv = $('ml-sv');
      sv.src = 'https://maps.googleapis.com/maps/api/streetview?size=340x200&location='
        + encodeURIComponent(geo.lat + ',' + geo.lng) + '&fov=80&pitch=10&key=' + GKEY;
      sv.style.display = 'block';
    }
    /* Infos publiques : CACHE d'abord (0 appel externe si l'immeuble est déjà connu),
       sinon appels live puis sauvegarde du blob (voir api/immeuble_infos_publiques.php). */
    runPublicInfos(hasCoords);
  });

  /* ── Infos publiques (cadastre/PLU, urbanisme, altitude, risques, copro) ──────
     Ancrées sur l'immeuble via api/immeuble_infos_publiques.php → on ne retape plus
     les APIs externes à chaque sélection. */
  var PUB_SRC = [
    { k: 'cad',   label: '📐 Cadastre &amp; PLU' },
    { k: 'urba',  label: '🏗️ Urbanisme' },
    { k: 'alt',   label: '⛰️ Altitude' },
    { k: 'risk',  label: '⚠️ Risques ERP' },
    { k: 'copro', label: '🏛️ Copropriété (registre)' }
  ];
  var pubLines = {}, pubFromCache = false, pubSaved = false;

  /* Interprète la réponse brute d'une source → {ok,text} + enrichit ML/reco, puis doneLine. */
  function pubHandle(k, a) {
    var ok = false, text = '—';
    if (k === 'cad') {
      var parts = [];
      if (a && a.parcelle && a.parcelle.section) parts.push('parcelle ' + a.parcelle.section + ' ' + a.parcelle.numero);
      if (a && a.plu && a.plu.type) parts.push('PLU ' + a.plu.type);
      if (a && a.parcelle && a.parcelle.reference) ML.enrich.cadastre = { reference: a.parcelle.reference };
      if (a && a.plu && a.plu.type) ML.enrich.plu = { type: a.plu.type, libelle: a.plu.libelle || '' };
      ok = !!(a && a.ok); text = parts.join(' · ') || '—';
    } else if (k === 'urba') {
      ok = !!(a && a.ok); text = (a && a.zone && a.zone.libelle) ? ('zone ' + a.zone.libelle) : '—';
    } else if (k === 'alt') {
      var v = a && a.altitude != null; if (v) ML.enrich.altitude = a.altitude;
      ok = !!(a && a.ok && v); text = v ? (a.altitude + ' m') : '—';
    } else if (k === 'risk') {
      var n = (a && a.risques) ? a.risques.length : 0; ok = !!(a && a.ok); text = n ? (n + ' risque(s)') : 'aucun';
    } else if (k === 'copro') {
      if (a && a.trouve && a.coherent !== false) {
        reco.immat = a.immatriculation; reco.lots = a.nb_lots;
        ML.enrich.registre = { immatriculation: a.immatriculation, construction: a.construction, nb_lots: a.nb_lots };
        ok = true; text = a.immatriculation + ' · ' + a.nb_lots + ' lots';
      } else if (a && a.trouve && a.coherent === false) { ok = false; text = 'à vérifier (CP incohérent)'; }
      else { ok = true; text = 'non copropriété'; }
    }
    pubLines[k] = { ok: ok, text: text };
    doneLine(k, ok, text);
  }

  function runPublicInfos(hasCoords) {
    pubLines = {}; pubFromCache = false; pubSaved = false;
    /* Réserve les 5 lignes + le compteur TOUT DE SUITE (avant le fetch cache async),
       sinon la reconnaissance peut finir avant et déclencher finish() prématurément. */
    PUB_SRC.forEach(function (s) { pending++; line(s.k, s.label); });
    var url = EP.infos_pub + '?place_id=' + encodeURIComponent(geo.placeid)
      + '&lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng)
      + '&cp=' + encodeURIComponent(geo.cp || '') + '&voie=' + encodeURIComponent(geo.adresse1 || '')
      + (reco.immeuble_id ? ('&immeuble_id=' + reco.immeuble_id) : '');
    fetch(url).then(function (r) { return r.json(); }).then(function (c) {
      if (c && c.ok && c.cached && c.data) { pubReplay(c.data); }
      else if (hasCoords) { pubLive(); }
      else { PUB_SRC.forEach(function (s) { doneLine(s.k, false, 'indisponible'); }); }
    }).catch(function () {
      if (hasCoords) { pubLive(); }
      else { PUB_SRC.forEach(function (s) { doneLine(s.k, false, 'indisponible'); }); }
    });
  }

  function pubLive() {
    pubFromCache = false;
    var map = { cad: EP.geo_cadastre, urba: EP.geo_urba, alt: EP.geo_altitude, risk: EP.geo_risques, copro: EP.geo_copro };
    PUB_SRC.forEach(function (s) {
      fetch(q(map[s.k])).then(function (r) { return r.json(); })
        .then(function (a) { pubHandle(s.k, a); })
        .catch(function () { pubHandle(s.k, null); });
    });
  }

  function pubReplay(data) {
    pubFromCache = true;
    if (data.enrich) ML.enrich = Object.assign(ML.enrich || {}, data.enrich);
    if (data.reco) { if (data.reco.lots) reco.lots = data.reco.lots; if (data.reco.immat) reco.immat = data.reco.immat; }
    var lines = data.lines || {};
    PUB_SRC.forEach(function (s) { var ln = lines[s.k] || { ok: true, text: '—' }; doneLine(s.k, !!ln.ok, ln.text || '—'); });
  }

  /* Sauve le blob (une seule fois, jamais si venu du cache) → prochain accès = instantané. */
  function pubSave() {
    if (pubFromCache || pubSaved) return;
    pubSaved = true;
    var body = {
      place_id: geo.placeid, immeuble_id: reco.immeuble_id || 0,
      cp: geo.cp || '', voie: geo.adresse1 || '', lat: geo.lat || '', lng: geo.lng || '',
      adresse_formatee: geo.label || '',
      data: { lines: pubLines, enrich: ML.enrich || {}, reco: { lots: reco.lots || 0, immat: reco.immat || '' } }
    };
    fetch(EP.infos_pub, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).catch(function () {});
  }

  function resume() {
    var r = []; if (geo.label) r.push(geo.label); if (reco.lots) r.push(reco.lots + ' lots');
    return r.join(' · ') || geo.label;
  }
  function revealConfirm() {
    $('ml-imm-resume').textContent = resume();
    $('ml-imm-confirm').hidden = false;
    confirmShown = true;
  }
  function finish() {
    $('ml-imm-state').textContent = 'Analyse terminée — confirmez l\'immeuble.';
    $('ml-imm-resume').textContent = resume();
    if (!confirmShown) revealConfirm();
    pubSave(); /* mémorise les infos publiques (no-op si venu du cache) */
  }

  /* Navigation entre étapes — l'avancement se fait au CLIC (pas de bouton Suivant),
     et l'autosave est implicite (toutes les saisies sont enregistrées au fil de l'eau). */
  var STEPS = { immeuble: { t: "Identifier l'immeuble", n: 1 }, proprio: { t: 'Le propriétaire', n: 2 }, bien: { t: 'Le bien', n: 3 }, docs: { t: 'Documents (DPE, photos…)', n: 4 } };
  function markDone(k) { var s = document.querySelector('.step[data-castep="' + k + '"]'); if (s) { s.classList.remove('todo', 'cur'); s.classList.add('done'); var mk = s.querySelector('.mk'); if (mk) mk.textContent = '✓'; } }
  function saved() { var sh = document.getElementById('sh'); if (sh) sh.dataset.saved = '1'; }
  function gotoStep(k) {
    document.querySelectorAll('.step[data-castep]').forEach(function (s) {
      var kk = s.getAttribute('data-castep'); if (s.classList.contains('done')) return;
      s.classList.toggle('cur', kk === k); s.classList.toggle('todo', kk !== k);
      var mk = s.querySelector('.mk'); if (mk && kk === k && !mk.textContent.trim()) mk.textContent = '›';
    });
    document.querySelectorAll('[data-castep-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-castep-pane') !== k; });
    var h2 = document.querySelector('.worktop h2'); if (h2 && STEPS[k]) h2.textContent = STEPS[k].t;
    var st = document.querySelector('.worktop .st'); if (st && STEPS[k]) st.textContent = 'Étape ' + STEPS[k].n + ' / 4';
    var np = document.querySelector('.nextpill b'); if (np && STEPS[k]) np.textContent = STEPS[k].t;
    saved();
  }
  function commitImmeuble() {
    ML.immeuble = { id: reco.immeuble_id || 0, adresse_1: geo.adresse1, code_postal: geo.cp, ville: geo.ville, lat: geo.lat, lng: geo.lng, place_id: geo.placeid, nom: reco.nom || geo.label };
  }
  /* Immeuble connu → on charge tout de suite ses PROPRIÉTAIRES (page immeuble).
     Les BIENS de l'immeuble sont réservés à l'étape propriétaire suivante. */
  var IMMLOTS = [];
  function fetchProprios(immId) {
    fetch(EP.immeuble_cont + '?immeuble_id=' + encodeURIComponent(immId)).then(function (r) { return r.json(); })
      .then(function (a) { IMMLOTS = (a && a.lots) || []; renderProprios((a && a.proprietaires) || []); })
      .catch(function () {}); /* endpoint réservé admin : silencieux si refus */
  }

  /* Page immeuble : propriétaires en boutons colorés (charte Tiers) */
  function renderProprios(props) {
    var box = $('ml-imm-doublon');
    box.innerHTML = '<div class="attendus" style="margin-top:10px"><div class="ah">👥 Propriétaires de cet immeuble — cliquez pour continuer</div><div class="choices" style="margin-top:6px">'
      + props.map(function (p) {
        return '<button class="ml-propbtn" data-pid="' + p.id + '" data-tid="' + (p.tiers_id || 0) + '" data-nom="' + (p.nom || '').replace(/"/g, '&quot;') + '">👤 ' + (p.nom || ('#' + p.id)) + (p.nb_lots > 1 ? ' <small>(' + p.nb_lots + ' lots)</small>' : '') + '</button>';
      }).join('') + '<button class="ml-propbtn add" data-newprop>＋ Nouveau propriétaire</button></div></div>';
    box.querySelectorAll('[data-pid]').forEach(function (b) {
      b.addEventListener('click', function () {
        commitImmeuble();
        ML.proprietaire = { existingProprio: +b.getAttribute('data-pid'), existingTiers: +b.getAttribute('data-tid'), type: 'reprise', nom: b.getAttribute('data-nom') };
        markDone('immeuble'); gotoStep('proprio'); renderProprioPane();
      });
    });
    var newp = box.querySelector('[data-newprop]'); if (newp) newp.addEventListener('click', function () { commitImmeuble(); markDone('immeuble'); gotoStep('proprio'); renderProprioPane(); });
  }

  /* Page propriétaire : biens de l'immeuble en boutons (3/ligne : lot · étage · locataire) */
  function initials(s) {
    s = (s || '').trim(); if (!s) return '–';
    var p = s.split(/\s+/); var r = ((p[0] || '')[0] || '') + ((p[1] || '')[0] || '');
    return (r || s[0] || '–').toUpperCase();
  }
  /* Construit le contenu de la fiche propriétaire (injecté dans la carte glissante) */
  function buildProprioFiche(who) {
    var id = 'f-proprio-rows', el = document.getElementById(id);
    if (!el) { el = document.createElement('div'); el.id = id; el.hidden = true; var host = document.querySelector('.mbi-mission'); if (host) host.appendChild(el); }
    var lots = IMMLOTS.map(function (l) { return '<span class="lot">' + (l.lot ? ('Lot ' + l.lot) : (l.reference || ('#' + l.id))) + '</span>'; }).join('');
    el.innerHTML =
      '<div class="row"><span class="ic">👤</span><span class="k">Nom</span><span class="v">' + who + '</span></div>' +
      '<div class="row"><span class="ic">🏷️</span><span class="k">Rôle</span><span class="v">Propriétaire · bailleur</span></div>' +
      (lots ? ('<div class="row" style="align-items:flex-start"><span class="ic">🏠</span><span class="k">Biens</span><div class="fiche-list">' + lots + '</div></div>') : '') +
      '<div class="row"><span class="ic">ℹ️</span><span class="k">Coordonnées</span><span class="v">disponibles dans le référentiel Propriétaires</span></div>';
  }
  function renderProprioPane() {
    var pane = document.querySelector('[data-castep-pane="proprio"]'); if (!pane) return;
    var bb = pane.querySelector('.bb'); if (!bb) return;
    var who = (ML.proprietaire && ML.proprietaire.nom) ? ML.proprietaire.nom : 'Nouveau propriétaire';
    buildProprioFiche(who);
    /* Rappel du propriétaire en haut + bouton « Voir la fiche » (carte glissante) */
    var html = '<div class="ml-recap"><span class="ini">' + initials(who) + '</span><span class="rn">' + who + '</span><span class="rsub">Propriétaire · bailleur</span>'
      + '<button class="fiche" data-fiche="Propriétaire" data-fiche-src="f-proprio-rows" data-fiche-mod="var(--c-tiers)" data-fiche-name="' + who.replace(/"/g, '&quot;') + '" data-fiche-sub="Propriétaire · bailleur" data-fiche-ini="' + initials(who) + '" data-fiche-tag="Tiers" data-fiche-ref="Propriétaires">Voir la fiche ›</button></div>';
    if (IMMLOTS.length) {
      html += '<div style="margin:14px 2px 6px;font-family:var(--font-title);font-weight:700;font-size:12.5px;color:var(--ink)">🏠 Biens de ce propriétaire — cliquez pour reprendre</div>';
      html += '<div class="ml-lotgrid">' + IMMLOTS.map(function (l) {
        var lot = l.lot ? ('Lot ' + l.lot) : (l.reference || ('Bien #' + l.id));
        var et = (l.etage != null && l.etage !== '') ? (l.etage + 'ᵉ ét.') : '—';
        var loc = l.locataire || '';
        return '<button class="ml-lotbtn" data-bid="' + l.id + '"><span class="lt">' + lot + '</span><span class="lm">' + et + '</span><span class="lloc">' + (loc ? ('👤 ' + loc) : '🔓 Vacant') + '</span></button>';
      }).join('') + '</div>';
    }
    html += '<button class="ml-bigadd bien" data-newbien style="margin-top:12px"><span class="plus">＋</span><span>Nouveau bien</span></button>';
    bb.innerHTML = html;
    bb.querySelectorAll('[data-bid]').forEach(function (b) {
      b.addEventListener('click', function () { ML.existingBienId = +b.getAttribute('data-bid') || 0; markDone('proprio'); markDone('bien'); gotoStep('docs'); });
    });
    var nb = bb.querySelector('[data-newbien]'); if (nb) nb.addEventListener('click', function () { markDone('proprio'); gotoStep('bien'); });
  }

  /* Nouvel immeuble (aucun propriétaire connu) → gros « + » coloré pour en ajouter un */
  function bigAdd() {
    $('ml-imm-state').textContent = 'Nouvel immeuble — ajoutez le propriétaire.';
    var box = $('ml-imm-doublon');
    box.innerHTML = '<button class="ml-bigadd" id="ml-bigadd"><span class="plus">＋</span><span>Ajouter le propriétaire</span></button>';
    var b = $('ml-bigadd'); if (b) b.addEventListener('click', function () { markDone('immeuble'); gotoStep('proprio'); });
  }

  var oui = $('ml-imm-oui'); if (oui) oui.addEventListener('click', function () {
    commitImmeuble(); markDone('immeuble'); saved();
    $('ml-imm-confirm').hidden = true;
    /* Les propriétaires sont déjà chargés (dès la reconnaissance). S'il n'y en a aucun → gros + */
    if ((($('ml-imm-doublon').innerHTML) || '').trim() === '') bigAdd();
    else $('ml-imm-state').textContent = 'Immeuble validé — choisissez un propriétaire (ou un lot) ci-dessous.';
  });
  var non = $('ml-imm-non'); if (non) non.addEventListener('click', function () {
    $('ml-imm-result').hidden = true; addr.value = ''; addr.focus(); confirmShown = false;
  });

  /* Navigation directe : cliquer une étape ouvre son volet (même vide), sans perdre les ✓ */
  document.querySelectorAll('.step[data-castep]').forEach(function (s) {
    s.addEventListener('click', function () {
      var k = s.getAttribute('data-castep');
      document.querySelectorAll('[data-castep-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-castep-pane') !== k; });
      var h2 = document.querySelector('.worktop h2'); if (h2 && STEPS[k]) h2.textContent = STEPS[k].t;
      var st = document.querySelector('.worktop .st'); if (st && STEPS[k]) st.textContent = 'Étape ' + STEPS[k].n + ' / 4';
      var np = document.querySelector('.nextpill b'); if (np && STEPS[k]) np.textContent = STEPS[k].t;
      document.querySelectorAll('.step[data-castep]').forEach(function (x) { if (!x.classList.contains('done')) x.classList.toggle('cur', x === s); });
    });
  });
})();
