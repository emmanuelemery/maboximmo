/*
 * mission.js
 * Rôle   : Comportements CENTRALISÉS de l'UI mission (§4.x du spec). Un seul fichier
 *          partagé pour toutes les missions — jamais de <script> inline par page.
 * Portée : agit dans chaque conteneur .mbi-mission (multi-instances tolérées).
 * Dépend : mission-tokens.css + mission-components.css + mission-bureau.css.
 * Config : ACTIONS[step] et les libellés drawer sont surchargeables via
 *          window.MBI_MISSION = { actions:{...}, titles:{...}, icons:{...} }
 *          (idéalement injecté depuis l'Administration métier, pas codé en dur).
 * Réf    : mbi_gabarit_mission.html (logique transposée en module réutilisable).
 * Date   : 2026-07-18
 */
(function () {
  'use strict';

  var CFG = window.MBI_MISSION || {};

  /* ── Registres drawer (surchargeables) ──────────────────────── */
  var BUREAU = ['documents', 'comments', 'ia', 'activity', 'notifs', 'outils'];
  var TITLES = Object.assign({
    documents: 'Bureau de mission', comments: 'Bureau de mission', ia: 'Bureau de mission',
    activity: 'Bureau de mission', notifs: 'Bureau de mission', outils: "Actions de l'étape",
    loyers: 'Encadrement des loyers', immeuble: 'Infos publiques · immeuble', mail: 'Envoi de mail',
    demande: 'Demander des documents', import: 'Chargement de documents', generate: 'Générer un document',
    sign: 'Envoyer en signature', plan: 'Planifier', share: 'Partager', act: 'Action'
  }, CFG.titles || {});
  var ICOS = Object.assign({
    documents: '📁', comments: '💬', ia: '🤖', activity: '📊', notifs: '🔔', outils: '⚡',
    loyers: '⚖', immeuble: 'ℹ', mail: '📧', demande: '📥', import: '📤', generate: '🖨️',
    sign: '✍️', plan: '📅', share: '🔗', act: '⚡'
  }, CFG.icons || {});
  var DEDIC = { mail: 1, demande: 1, share: 1, loyers: 1, generate: 1, sign: 1 };

  /* ── Config des actions par étape (défaut = mission location) ── */
  var ACTIONS = CFG.actions || {
    annonce: [
      { i: '✉️', t: 'Envoyer un mail au propriétaire', s: 'prérempli · Mme Martin', go: 'mail', fav: 1 },
      { i: '📥', t: 'Demander les documents manquants', s: '2 pièces · liste auto', go: 'demande', fav: 1 },
      { i: '🔗', t: 'Lien sécurisé de dépôt', s: 'prérempli', go: 'share' },
      { i: '⚖', t: 'Calculer le loyer (encadrement)', s: 'zone Avignon', go: 'loyers' },
      { i: '✦', t: "Rédiger le texte de l'annonce (IA)", s: 'à partir du bien', go: null },
      { i: '🗺️', t: 'Ouvrir dans Google Maps', s: '12 rue des Teinturiers', go: null },
      { i: '🏛', t: 'Consulter le cadastre', s: 'parcelle du bien', go: null },
      { i: '🖨️', t: 'Imprimer le dossier', s: 'mission complète', go: null }
    ],
    visites: [
      { i: '📅', t: 'Créer une visite', s: 'candidat + créneau', go: null, fav: 1 },
      { i: '💬', t: 'Envoyer un SMS', s: 'prérempli', go: null, fav: 1 },
      { i: '📲', t: 'Générer un QR code de visite', s: 'lien du bien', go: null },
      { i: '🔗', t: "Partager l'annonce", s: 'lien public', go: 'share' },
      { i: '📊', t: 'Exporter les candidats', s: 'Excel', go: null }
    ],
    bail: [
      { i: '📝', t: 'Préparer le bail', s: 'modèle + données', go: 'generate', fav: 1 },
      { i: '✍️', t: 'Envoyer en signature', s: 'propriétaire + locataire', go: 'sign', fav: 1 },
      { i: '📋', t: "Créer l'état des lieux", s: 'modèle', go: null },
      { i: '💰', t: 'Demander le dépôt de garantie', s: 'prérempli', go: null }
    ]
  };

  /* ── Initialise un conteneur de mission ─────────────────────── */
  function initMission(root) {
    if (root.dataset.mbiInit) return; root.dataset.mbiInit = '1';
    var $ = function (s) { return root.querySelector(s); };
    var $$ = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };

    /* Étapes (colonne gauche) */
    $$('.step').forEach(function (s) {
      s.addEventListener('click', function () {
        $$('.step.cur').forEach(function (x) {
          var done = x.querySelector('.mk').textContent.trim() === '✓';
          x.classList.remove('cur'); x.classList.add(done ? 'done' : 'todo');
        });
        s.classList.remove('done', 'todo'); s.classList.add('cur');
      });
    });

    /* Onglets colonne aides */
    $$('.tab').forEach(function (t) {
      t.addEventListener('click', function () {
        $$('.tab').forEach(function (x) { x.setAttribute('aria-selected', 'false'); });
        t.setAttribute('aria-selected', 'true');
        var k = t.dataset.tab;
        $$('[data-pane]').forEach(function (p) { p.hidden = (p.dataset.pane !== k); });
      });
    });

    /* Chips cascade — multi */
    $$('[data-multi] .chip').forEach(function (c) {
      c.addEventListener('click', function () {
        var on = c.getAttribute('aria-pressed') === 'true';
        c.setAttribute('aria-pressed', String(!on));
        var ck = c.querySelector('.ck'); if (ck) ck.textContent = !on ? '✓' : '';
      });
    });
    /* Chips cascade — solo */
    $$('[data-solo]').forEach(function (g) {
      g.querySelectorAll('.chip').forEach(function (c) {
        c.addEventListener('click', function () {
          g.querySelectorAll('.chip').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
          c.setAttribute('aria-pressed', 'true');
        });
      });
    });

    /* Progression (validations) */
    function bump() {
      var p = $('.pct'); if (p) {
        var v = Math.min(100, parseInt(p.textContent, 10) + 8);
        p.textContent = v + ' %';
        var cur = $('.seg.cur'); if (cur) cur.style.setProperty('--p', v + '%');
      }
      var mp = $('#mpct'); if (mp) {
        var m = Math.min(100, parseInt(mp.textContent, 10) + 3);
        mp.innerHTML = m + '<small>%</small>';
        var f = $('#mfill'); if (f) f.style.width = m + '%';
      }
    }
    $$('[data-validate]').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = b.closest('[data-conf]'); var c = r.querySelector('.conf');
        c.className = 'conf ok'; c.innerHTML = '<span class="d"></span>Validé';
        b.remove(); bump();
      });
    });
    $$('[data-validate-text]').forEach(function (b) {
      b.addEventListener('click', function () {
        var box = b.closest('[data-iabox]');
        box.style.borderStyle = 'solid'; box.style.borderColor = 'var(--ok)'; box.style.background = 'var(--ok-bg)';
        var lab = box.querySelector('.lab'); lab.innerHTML = '✓ Texte validé'; lab.style.color = 'var(--ok-tx)';
        box.querySelector('p').style.color = '#265b40';
        var act = box.querySelector('.act'); if (act) act.remove(); bump();
      });
    });
    var save = $('.save'); if (save) save.addEventListener('click', function () {
      var sh = $('#sh'); if (sh) sh.textContent = "Brouillon · enregistré à l'instant";
    });

    /* Modale fiche */
    var ov = $('#ov');
    function setT(sel, v) { var e = $(sel); if (e != null && v != null) e.textContent = v; }
    function modalEl() { return ov && ov.querySelector('.modal'); }
    /* Fiche-modèle : ouvre la fiche d'une entité (thémable par module, éditable) */
    function openM(btn) {
      var d = btn.dataset, m = modalEl();
      if (m) m.style.setProperty('--mod', d.ficheMod || 'var(--c-tiers)');
      setT('#f-eyebrow', 'Fiche · ' + (d.fiche || ''));
      setT('#f-tag', d.ficheTag || '');
      setT('#f-name', d.ficheName || '');
      setT('#f-sub', d.ficheSub || '');
      setT('#f-ini', d.ficheIni || '');
      setT('#f-ref', 'Ouvrir le référentiel ' + (d.ficheRef || '') + ' →');
      /* compat ancien markup */
      setT('#mtitle', d.fiche || '');
      if (d.ficheSrc) { var src = $('#' + d.ficheSrc), mb = ov && ov.querySelector('.mb'); if (src && mb) mb.innerHTML = src.innerHTML; }
      ov.classList.add('on');
    }
    function closeM() { if (ov) ov.classList.remove('on'); var sd = $('#f-side'); if (sd) sd.classList.remove('on'); }
    /* Petit modal latéral : glisse à droite de la fiche principale */
    root.addEventListener('click', function (e) {
      /* « Ajouter » du petit modal : insère la valeur EN LIGNE dans la fiche */
      var cb = e.target.closest('[data-commit]');
      if (cb) {
        var side0 = $('#f-side'), inp = side0 && side0.querySelector('input.fval'), tgt = $('#' + cb.dataset.commit);
        if (inp && tgt && inp.value.trim()) { var chip = document.createElement('span'); chip.className = 'vchip'; chip.textContent = inp.value.trim(); tgt.appendChild(chip); }
        if (side0) side0.classList.remove('on');
        return;
      }
      var sb = e.target.closest('[data-side]');
      if (sb) { var src = $('#side-' + sb.dataset.side), side = $('#f-side'); if (side) { if (src) side.innerHTML = src.innerHTML; side.classList.add('on'); } return; }
      if (e.target.closest('[data-side-close]')) { var s = $('#f-side'); if (s) s.classList.remove('on'); return; }
      /* Clic EN DEHORS du petit modal → le ferme (sans fermer la fiche) */
      var sd = $('#f-side');
      if (sd && sd.classList.contains('on') && !e.target.closest('#f-side')) { sd.classList.remove('on'); }
    });
    if (ov) {
      /* Délégation : gère aussi les boutons « Voir la fiche » ajoutés dynamiquement */
      root.addEventListener('click', function (e) { var f = e.target.closest('.fiche'); if (f && f.dataset.fiche) openM(f); });
      var mx = $('#mx'), mc = $('#mclose');
      if (mx) mx.addEventListener('click', closeM); if (mc) mc.addEventListener('click', closeM);
      ov.addEventListener('click', function (e) { if (e.target === ov) { var sd = $('#f-side'); if (sd && sd.classList.contains('on')) { sd.classList.remove('on'); return; } closeM(); } });
    }
    /* Barre « Aperçu du thème » : reteinte la fiche en direct */
    $$('.sw').forEach(function (s) {
      s.addEventListener('click', function () {
        $$('.sw').forEach(function (x) { x.classList.remove('act'); });
        s.classList.add('act');
        var m = modalEl(); if (m && s.dataset.mod) m.style.setProperty('--mod', s.dataset.mod);
        setT('#f-tag', s.dataset.tag);
      });
    });

    /* Ruban : remplissage animé au chargement */
    var mfill = $('#mfill'), mpct = $('#mpct');
    if (mfill && mpct) setTimeout(function () { mfill.style.width = (parseInt(mpct.textContent, 10) || 0) + '%'; }, 150);

    /* ── Bureau de mission : drawer coulissant ─────────────────── */
    var drawer = $('#drawer'), dscrim = $('#dscrim'), dtabs = $('#dtabs'), dhealth = $('#dhealth');
    function showPane(k) {
      $$('.dpane').forEach(function (p) { p.classList.toggle('on', p.dataset.dpane === k); });
      $$('.dtab').forEach(function (t) { t.classList.toggle('act', t.dataset.sec === k); });
      $$('.rbtn').forEach(function (r) { r.classList.toggle('act', r.dataset.drawer === k); });
    }
    function openDrawer(k) {
      if (!drawer) return;
      var bureau = BUREAU.indexOf(k) !== -1;
      if (dtabs) dtabs.style.display = bureau ? 'flex' : 'none';
      if (dhealth) dhealth.style.display = bureau ? 'flex' : 'none';
      var dt = $('#dtitle'), di = $('#dico');
      if (dt) dt.textContent = TITLES[k] || k; if (di) di.textContent = ICOS[k] || '⚡';
      showPane(k); drawer.classList.add('on'); if (dscrim) dscrim.classList.add('on');
      drawer.setAttribute('aria-hidden', 'false');
      if (k === 'import') setTimeout(animateImport, 140);
      if (k === 'outils') renderCurrentActions();
    }
    function closeDrawer() {
      if (!drawer) return;
      drawer.classList.remove('on'); if (dscrim) dscrim.classList.remove('on');
      drawer.setAttribute('aria-hidden', 'true');
      $$('.rbtn').forEach(function (r) { r.classList.remove('act'); });
    }
    $$('[data-drawer]').forEach(function (b) { b.addEventListener('click', function () { openDrawer(b.dataset.drawer); }); });
    $$('.dtab').forEach(function (t) { t.addEventListener('click', function () { showPane(t.dataset.sec); }); });
    var dx = $('#dx'); if (dx) dx.addEventListener('click', closeDrawer);
    if (dscrim) dscrim.addEventListener('click', closeDrawer);
    /* Clic EN DEHORS du drawer (Bureau de mission) → le ferme, même quand le voile est traversant (desktop) */
    document.addEventListener('click', function (e) {
      if (!drawer || !drawer.classList.contains('on')) return;
      if (e.target.closest('#drawer') || e.target.closest('[data-drawer]')) return;
      closeDrawer();
    });
    /* Rail ☰ → ouvre la sidebar legacy en off-canvas (glissante depuis la gauche) */
    var mjSidebar = document.querySelector('.agency-sidebar') || document.querySelector('.mbi-sidebar');
    var mjScrim = document.getElementById('mjSidebarScrim');
    function mjCloseSidebar() { if (mjSidebar) mjSidebar.classList.remove('mj-open'); if (mjScrim) mjScrim.classList.remove('on'); }
    $$('[data-mj-sidebar]').forEach(function (b) { b.addEventListener('click', function () { if (mjSidebar) { mjSidebar.classList.add('mj-open'); if (mjScrim) mjScrim.classList.add('on'); } }); });
    if (mjScrim) mjScrim.addEventListener('click', mjCloseSidebar);

    /* Fiche : « + » ajoute une valeur (ex. un 2e téléphone) */
    root.addEventListener('click', function (e) {
      var a = e.target.closest('[data-add]'); if (!a) return;
      var wrap = a.parentNode, inp = document.createElement('input');
      inp.className = 'fval'; inp.placeholder = a.dataset.add || 'Nouvelle valeur';
      wrap.insertBefore(inp, a); inp.focus();
    });

    /* Confirmer / affecter un document */
    root.addEventListener('click', function (e) {
      var b = e.target.closest('[data-confirm],[data-affect]'); if (!b) return;
      var s = document.createElement('span'); s.className = 'conf ok';
      s.innerHTML = '<span class="d"></span>' + (b.hasAttribute('data-confirm') ? 'Confirmé' : 'Affecté');
      b.replaceWith(s);
    });

    /* Import animé */
    function animateImport() {
      $$('#uplist .uprow').forEach(function (r, idx) {
        var bar = r.querySelector('.upb i'), st = r.querySelector('.st');
        bar.style.width = '0'; st.textContent = '0%'; st.style.color = 'var(--muted)';
        var p = 0;
        setTimeout(function () {
          var iv = setInterval(function () {
            p += Math.random() * 13 + 7;
            if (p >= 100) { p = 100; clearInterval(iv); st.textContent = '✓ Classé'; st.style.color = 'var(--ok-tx)'; }
            else { st.textContent = Math.floor(p) + '%'; }
            bar.style.width = p + '%';
          }, 95);
        }, idx * 300);
      });
    }

    /* Actions à effet de bord : confirmation visuelle */
    root.addEventListener('click', function (e) {
      var b = e.target.closest('[data-sent]'); if (!b || b.dataset.done) return;
      b.dataset.done = 1;
      var map = {
        '📧 Envoyer le mail': '✓ Mail envoyé', '✍️ Envoyer en signature': '✓ Envoyé en signature',
        '📅 Planifier': '✓ Planifié', '🔗 Copier le lien': '✓ Lien copié'
      };
      b.textContent = map[b.textContent.trim()] || '✓ Fait';
      b.style.background = 'linear-gradient(135deg,var(--c-immeuble),#2b8f7a)';
    });

    /* ── Panneau Actions piloté par ACTIONS[step] ──────────────── */
    function actItem(a) {
      var at = encodeURIComponent(JSON.stringify(a));
      return '<button class="actrow" data-a="' + at + '"><span class="ai">' + a.i + '</span>' +
        '<span class="an"><span class="t">' + a.t + '</span><span class="s">' + a.s + '</span></span>' +
        (a.fav ? '<span class="fav">★</span>' : '') +
        '<span class="pf">prérempli</span><span class="cv">›</span></button>';
    }
    function renderActions(step) {
      step = step || 'annonce';
      var list = ACTIONS[step] || ACTIONS.annonce || [];
      var fav = list.filter(function (a) { return a.fav; });
      var rest = list.filter(function (a) { return !a.fav; });
      var el = $('#actlist'); if (!el) return;
      el.innerHTML =
        (fav.length ? '<div class="favlab">★ Fréquentes</div>' : '') + fav.map(actItem).join('') +
        (rest.length ? '<div class="favlab">Toutes les actions de l\'étape</div>' : '') + rest.map(actItem).join('');
      el.querySelectorAll('.actrow').forEach(function (r) {
        r.addEventListener('click', function () { handleAction(JSON.parse(decodeURIComponent(r.dataset.a))); });
      });
    }
    function renderCurrentActions() {
      var c = $('#actstep .chip[aria-pressed="true"]');
      renderActions(c ? c.dataset.step : 'annonce');
    }
    function handleAction(a) {
      if (a.go && DEDIC[a.go]) return openDrawer(a.go);
      var big = $('#actbig'); if (big) big.innerHTML = '<span class="e">' + a.i + '</span>' + a.t;
      var desc = $('#actdesc'); if (desc) desc.innerHTML =
        '<div class="sub">Action prête à lancer</div>' + a.t +
        " — le bien, le tiers, la mission et l'étape sont déjà récupérés. Rien à ressaisir.";
      var go = $('#actgo'); if (go) { go.textContent = 'Valider'; delete go.dataset.done; go.style.background = ''; }
      openDrawer('act');
    }
    $$('#actstep .chip').forEach(function (c) {
      c.addEventListener('click', function () { renderActions(c.dataset.step); });
    });
    if ($('#actlist')) renderActions('annonce');

    /* Raccourcis clavier locaux au conteneur */
    root._mbiKey = function (e) {
      if (e.key === 'Escape') { closeM(); closeDrawer(); }
      if (e.key === '/' && document.activeElement && document.activeElement.tagName !== 'INPUT') {
        var inp = $('.search input'); if (inp) { e.preventDefault(); inp.focus(); }
      }
    };
    document.addEventListener('keydown', root._mbiKey);
  }

  function boot() {
    Array.prototype.slice.call(document.querySelectorAll('.mbi-mission')).forEach(initMission);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  window.MBIMission = { init: initMission, boot: boot };
})();
