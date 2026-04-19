/*
 * bien_detail_v2.js
 * Carousel Documents (4 cards) + Diag & DPE (6 cards dont card 4 édition)
 * Dépend : /assets/js/document_uploader.js
 * Date   : 2026-04-19
 */

(function () {
  'use strict';

  const TYPE_LABELS = {
    dpe: 'DPE',
    diag: 'Diagnostic',
    dossier_complet: 'Dossier diagnostics',
    dossier_diagnostics: 'Dossier diagnostics',
    certificat_surface: 'Certificat de surface',
    mesurage_loi_carrez: 'Mesurage Loi Carrez',
    erp: 'ERP',
    amiante: 'Amiante',
    plomb: 'Plomb',
    termites: 'Termites',
    gaz: 'Gaz',
    electricite: 'Électricité',
    mandat: 'Mandat',
    mandat_vente: 'Mandat de vente',
    mandat_gestion: 'Mandat de gestion',
    mandat_location: 'Mandat de location',
    bail: 'Bail',
    acte: 'Acte de propriété',
    titre: 'Titre / Acte',
    fiche: 'Fiche commerciale',
    divers: 'Document divers',
    notification_mutation: 'Notification mutation',
    autre: 'Autre',
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmtSize(b) {
    b = Number(b) || 0;
    if (b < 1024) return b + ' o';
    if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' Ko';
    return (b / 1024 / 1024).toFixed(1) + ' Mo';
  }
  function fmtDate(s) {
    if (!s) return '';
    const d = new Date(s);
    if (isNaN(d)) return '';
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  function renderDocsList(containerId, docs, emptyIcon, emptyMsg) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!docs || docs.length === 0) {
      el.innerHTML = `
        <div class="v2-doc-empty">
          <div class="v2-doc-empty-icon">${emptyIcon}</div>
          <div>${emptyMsg}</div>
        </div>`;
      return;
    }
    const rows = docs.map(d => {
      const typeLabel = TYPE_LABELS[d.type_document] || d.type_document || 'Document';
      const size = d.taille_octets ? ' · ' + fmtSize(d.taille_octets) : '';
      const date = d.date_document || d.date_upload;
      const dateStr = date ? ' · ' + fmtDate(date) : '';
      const url = d.url_fichier || '';
      return `
        <div class="v2-doc-item" data-doc-id="${d.id}">
          <div class="v2-doc-icon">📄</div>
          <div class="v2-doc-meta">
            <div class="v2-doc-name">${escapeHtml(d.nom_original || ('Document #' + d.id))}</div>
            <div class="v2-doc-sub">${escapeHtml(typeLabel)}${size}${dateStr}</div>
          </div>
          <div class="v2-doc-actions">
            ${url ? `<a class="v2-doc-btn" href="${escapeHtml(url)}" target="_blank" rel="noopener">Voir</a>` : ''}
          </div>
        </div>`;
    }).join('');
    el.innerHTML = '<div class="v2-doc-list">' + rows + '</div>';
  }

  // ── Card 4 — champs manquants : submit vers dpe_diag_update.php ──
  function bindMissingForm() {
    const form = document.getElementById('v2-missing-form');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = document.getElementById('v2-missing-status');
      const data = window.__v2DocsData || {};
      const diagId = parseInt(form.getAttribute('data-diag-id'), 10) || 0;
      const bienId = parseInt(form.getAttribute('data-bien-id'), 10) || 0;

      const fields = {};
      Array.from(form.elements).forEach(el => {
        if (!el.name) return;
        const v = (el.value || '').trim();
        if (v !== '') fields[el.name] = v;
      });

      if (Object.keys(fields).length === 0) {
        if (status) { status.textContent = 'Aucun champ saisi.'; status.className = 'v2-form-status err'; }
        return;
      }

      if (status) { status.textContent = '⏳ Enregistrement…'; status.className = 'v2-form-status'; }

      // Si diagId == 0 (pas de dpe_diags existant) → on ne peut pas utiliser dpe_diag_update
      // On POST vers bien_autosave à la place pour les colonnes biens équivalentes
      if (diagId === 0) {
        if (status) { status.textContent = '⚠️ Aucune analyse DPE enregistrée — les champs saisis ici nécessitent une ligne dpe_diags. Uploadez d\'abord un PDF ou utilisez bien_detail.'; status.className = 'v2-form-status err'; }
        return;
      }

      try {
        const r = await fetch(data.updateEndpoint || '/api/dpe_diag_update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: JSON.stringify({
            diag_id: diagId,
            id_bien: bienId,
            diag_fields: fields,
            biens_fields: {}, // Le sync dpe_diags→biens est géré côté serveur par extracted→biens (manuel côté bien_detail)
          }),
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur inconnue');
        if (status) {
          status.textContent = `✅ ${j.diag_updated || Object.keys(fields).length} champ(s) enregistré(s). Rechargement…`;
          status.className = 'v2-form-status ok';
        }
        setTimeout(() => window.location.reload(), 900);
      } catch (err) {
        if (status) { status.textContent = '❌ ' + err.message; status.className = 'v2-form-status err'; }
      }
    });
  }

  // ── Carousel ──
  class V2Carousel {
    constructor(stageEl, opts) {
      this.stage = stageEl;
      this.cards = Array.from(stageEl.querySelectorAll('.v2-card'));
      this.total = this.cards.length;
      this.index = opts.startIndex || 0;
      this.dotsEl = opts.dotsEl || null;
      this.tabsEl = opts.tabsEl || null;
      this.prevBtn = opts.prevBtn || null;
      this.nextBtn = opts.nextBtn || null;

      this._bindEvents();
      this._buildDots();
      this._buildTabs();
      this.update();
    }

    _buildTabs() {
      if (!this.tabsEl) return;
      this.tabsEl.innerHTML = '';
      this.cards.forEach((card, i) => {
        const labelEl = card.querySelector('.v2-card-label');
        const html = labelEl ? labelEl.innerHTML : ('Carte ' + (i + 1));
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'v2-stage-tab';
        b.dataset.idx = i;
        b.innerHTML = html;
        b.setAttribute('role', 'tab');
        b.addEventListener('click', () => this.go(i));
        this.tabsEl.appendChild(b);
      });
    }

    _buildDots() {
      if (!this.dotsEl) return;
      this.dotsEl.innerHTML = '';
      for (let i = 0; i < this.total; i++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'v2-dot';
        b.dataset.idx = i;
        b.setAttribute('aria-label', 'Carte ' + (i + 1));
        b.addEventListener('click', () => this.go(i));
        this.dotsEl.appendChild(b);
      }
    }

    _bindEvents() {
      if (this.prevBtn) this.prevBtn.addEventListener('click', () => this.prev());
      if (this.nextBtn) this.nextBtn.addEventListener('click', () => this.next());

      document.addEventListener('keydown', (e) => {
        const tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.key === 'ArrowLeft') { this.prev(); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { this.next(); e.preventDefault(); }
      });

      // Swipe
      let sx = 0, sy = 0, tracking = false;
      this.stage.addEventListener('touchstart', (e) => {
        const t = e.changedTouches[0];
        sx = t.clientX; sy = t.clientY; tracking = true;
      }, { passive: true });
      this.stage.addEventListener('touchend', (e) => {
        if (!tracking) return;
        tracking = false;
        const t = e.changedTouches[0];
        const dx = t.clientX - sx;
        const dy = t.clientY - sy;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
          if (dx < 0) this.next(); else this.prev();
        }
      }, { passive: true });

      // Clic prev/next card
      this.cards.forEach((c) => {
        c.addEventListener('click', (e) => {
          if (c.classList.contains('is-prev')) { this.prev(); e.preventDefault(); }
          else if (c.classList.contains('is-next')) { this.next(); e.preventDefault(); }
        });
      });
    }

    prev() { this.go((this.index - 1 + this.total) % this.total); }
    next() { this.go((this.index + 1) % this.total); }

    go(i) {
      if (i === this.index) return;
      this.index = i;
      this.update();
    }

    update() {
      const i = this.index;
      const n = this.total;
      const prevI = (i - 1 + n) % n;
      const nextI = (i + 1) % n;

      this.cards.forEach((c, idx) => {
        c.classList.remove('is-active', 'is-prev', 'is-next', 'is-hidden');
        c.setAttribute('aria-hidden', idx === i ? 'false' : 'true');
        if (idx === i) c.classList.add('is-active');
        else if (idx === prevI) c.classList.add('is-prev');
        else if (idx === nextI) c.classList.add('is-next');
        else c.classList.add('is-hidden');
      });

      if (this.dotsEl) {
        Array.from(this.dotsEl.children).forEach((d, idx) => {
          d.classList.toggle('is-active', idx === i);
        });
      }

      if (this.tabsEl) {
        Array.from(this.tabsEl.children).forEach((t, idx) => {
          t.classList.toggle('is-active', idx === i);
          t.setAttribute('aria-selected', idx === i ? 'true' : 'false');
        });
      }
    }
  }

  // ── Section DESCRIPTIF : autosave + icon-radios + tiers picker ──
  function bindDescriptifAutosave(data) {
    const indicator = document.getElementById('v2-save-indicator');
    const bienId = data.bienId;
    const csrf = data.csrfToken;

    function showIndicator(kind, msg) {
      if (!indicator) return;
      indicator.className = 'v2-save-indicator ' + (kind === 'ok' ? 'ok' : kind === 'err' ? 'err' : '');
      indicator.textContent = msg;
      if (kind === 'ok') {
        setTimeout(() => { indicator.textContent = ''; indicator.className = 'v2-save-indicator'; }, 2000);
      }
    }

    async function saveField(name, value) {
      if (!bienId) return;
      showIndicator('', '💾 Enregistrement…');
      const fd = new FormData();
      fd.append('_edit_id', bienId);
      fd.append('csrf_token', csrf);
      fd.append(name, value == null ? '' : value);
      try {
        const r = await fetch(data.autosaveEndpoint || '/api/bien_autosave.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j.ok) showIndicator('ok', '✅ Enregistré ' + (j.saved_at || ''));
        else showIndicator('err', '❌ ' + (j.error || 'Erreur'));
      } catch (e) {
        showIndicator('err', '❌ ' + e.message);
      }
    }
    // Expose pour le tiers picker / modal
    window.__v2SaveField = saveField;

    // Inputs avec autosave
    document.querySelectorAll('[data-autosave]').forEach(el => {
      const handler = () => saveField(el.name, el.value);
      if (el.type === 'checkbox' || el.type === 'radio') el.addEventListener('change', handler);
      else {
        el.addEventListener('change', handler);
        el.addEventListener('blur', handler);
      }
    });

    // Icon radios (1 choix exclusif par groupe, reclic = désélection)
    document.querySelectorAll('.v2-icon-radios').forEach(group => {
      const field = group.dataset.field;
      if (!field) return;
      group.querySelectorAll('.v2-icon-radio').forEach(btn => {
        btn.addEventListener('click', () => {
          const wasActive = btn.classList.contains('is-active');
          group.querySelectorAll('.v2-icon-radio').forEach(b => b.classList.remove('is-active'));
          if (!wasActive) {
            btn.classList.add('is-active');
            saveField(field, btn.dataset.value || '');
          } else {
            saveField(field, '');
          }
        });
      });
    });

    // Tiers picker
    const searchInput = document.getElementById('v2-proprio-search');
    const suggestBox = document.getElementById('v2-proprio-suggest');
    let lastTiersItems = [];
    if (searchInput && suggestBox) {
      let timer = null;
      searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { suggestBox.hidden = true; suggestBox.innerHTML = ''; return; }
        timer = setTimeout(async () => {
          try {
            const ep = data.tiersLookupEndpoint || '/api/tiers_lookup.php';
            const r = await fetch(ep + '?q=' + encodeURIComponent(q) + '&limit=8', { credentials: 'same-origin' });
            const j = await r.json();
            const items = (j.items || []).concat(j.doublons || []);
            lastTiersItems = items;
            if (items.length === 0) {
              suggestBox.innerHTML = '<div class="v2-tiers-suggest-item"><small>Aucun résultat — clique sur ➕ pour créer</small></div>';
            } else {
              suggestBox.innerHTML = items.map(it => {
                const name = it.raison_sociale || ((it.prenom || '') + ' ' + (it.nom || '')).trim();
                const sub = [it.email, it.telephone].filter(Boolean).join(' · ');
                return `<div class="v2-tiers-suggest-item" data-id="${it.id}">
                  <strong>${name || ('Tiers #' + it.id)}</strong>
                  ${sub ? '<small>' + sub + '</small>' : ''}
                  <div class="v2-tiers-suggest-actions">
                    <button type="button" class="v2-tiers-suggest-btn" data-action="view" data-id="${it.id}">👁️ Voir</button>
                    <button type="button" class="v2-tiers-suggest-btn primary" data-action="select" data-id="${it.id}" data-name="${(name || '').replace(/"/g,'&quot;')}">✓ Sélectionner</button>
                  </div>
                </div>`;
              }).join('');
            }
            suggestBox.hidden = false;
          } catch (e) {
            suggestBox.hidden = true;
          }
        }, 250);
      });
      suggestBox.addEventListener('click', (e) => {
        const btn = e.target.closest('.v2-tiers-suggest-btn');
        if (!btn) return;
        const id = btn.dataset.id;
        const action = btn.dataset.action;
        if (action === 'select') {
          const name = btn.dataset.name || '';
          searchInput.value = name;
          suggestBox.hidden = true;
          saveField('id_proprietaire', id).then(() => {
            setTimeout(() => window.location.reload(), 500);
          });
        } else if (action === 'view') {
          const item = lastTiersItems.find(x => String(x.id) === String(id));
          showTiersDetail(item, saveField);
        }
      });
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.v2-tiers-picker')) suggestBox.hidden = true;
      });
    }

    // Recherche immeubles existants (autocomplete sur la liste en memoire)
    const immSearch = document.getElementById('v2-imm-search');
    const immSuggest = document.getElementById('v2-imm-suggest');
    const immList = Array.isArray(data.immeubles) ? data.immeubles : [];
    if (immSearch && immSuggest && immList.length > 0) {
      const renderImm = (matches) => {
        if (matches.length === 0) {
          immSuggest.innerHTML = '<div class="v2-tiers-suggest-item"><small>Aucun immeuble trouvé</small></div>';
        } else {
          immSuggest.innerHTML = matches.slice(0, 10).map(im => {
            const ref = im.reference_immeuble ? '[' + im.reference_immeuble + '] ' : '';
            const adr = im.adresse || '';
            const loc = [im.code_postal, im.ville].filter(Boolean).join(' ');
            return `<div class="v2-tiers-suggest-item"
                         data-id="${im.id}"
                         data-adresse="${String(adr).replace(/"/g,'&quot;')}"
                         data-cp="${im.code_postal || ''}"
                         data-ville="${String(im.ville || '').replace(/"/g,'&quot;')}">
              <strong>${ref}${adr || ('Immeuble #' + im.id)}</strong>
              ${loc ? '<small>' + loc + '</small>' : ''}
            </div>`;
          }).join('');
        }
        immSuggest.hidden = false;
      };
      immSearch.addEventListener('focus', () => renderImm(immList));
      immSearch.addEventListener('input', () => {
        const q = immSearch.value.trim().toLowerCase();
        if (!q) { renderImm(immList); return; }
        const matches = immList.filter(im =>
          (im.adresse || '').toLowerCase().includes(q)
          || (im.ville || '').toLowerCase().includes(q)
          || (im.code_postal || '').toLowerCase().includes(q)
          || (im.reference_immeuble || '').toLowerCase().includes(q)
        );
        renderImm(matches);
      });
      immSuggest.addEventListener('click', (e) => {
        const item = e.target.closest('.v2-tiers-suggest-item');
        if (!item || !item.dataset.id) return;
        const setVal = (id, v) => {
          const el = document.getElementById(id);
          if (el) { el.value = v || ''; el.dispatchEvent(new Event('change')); }
        };
        setVal('v2-f-adresse_1',   item.dataset.adresse || '');
        setVal('v2-f-code_postal', item.dataset.cp || '');
        setVal('v2-f-ville',       item.dataset.ville || '');
        immSearch.value = '';
        immSuggest.hidden = true;
      });
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.v2-imm-picker')) immSuggest.hidden = true;
      });
    }

    // Modal création express
    const modal = document.getElementById('v2-proprio-modal');
    const addBtn = document.getElementById('v2-proprio-add');
    const typeSel = document.getElementById('v2-pm-type');
    if (modal && addBtn) {
      const show = () => { modal.hidden = false; };
      const hide = () => { modal.hidden = true; };
      addBtn.addEventListener('click', show);
      document.getElementById('v2-pm-cancel')?.addEventListener('click', hide);
      typeSel?.addEventListener('change', () => {
        const isPP = typeSel.value === 'personne_physique';
        modal.querySelectorAll('.v2-pm-pp').forEach(el => el.hidden = !isPP);
        modal.querySelectorAll('.v2-pm-pm').forEach(el => el.hidden = isPP);
      });
      document.getElementById('v2-pm-save')?.addEventListener('click', async () => {
        const body = {
          type_tiers: typeSel.value,
          nom: (document.getElementById('v2-pm-nom')?.value || '').trim(),
          prenom: (document.getElementById('v2-pm-prenom')?.value || '').trim(),
          raison_sociale: (document.getElementById('v2-pm-rs')?.value || '').trim(),
          email: (document.getElementById('v2-pm-email')?.value || '').trim(),
          telephone: (document.getElementById('v2-pm-tel')?.value || '').trim(),
          roles: [{ role_code: 'proprietaire', objet_type: 'bien', id_objet: bienId }],
        };
        try {
          const r = await fetch(data.tiersCreateEndpoint || '/api/tiers_create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
          });
          const j = await r.json();
          if (!j.ok) throw new Error(j.error || 'Erreur création');
          await saveField('id_proprietaire', j.id || j.tiers_id || '');
          hide();
          setTimeout(() => window.location.reload(), 500);
        } catch (e) {
          alert('❌ ' + e.message);
        }
      });
    }
  }

  // ── Modal détail tiers (lecture seule) ──
  function showTiersDetail(tiers, saveField) {
    const modal = document.getElementById('v2-tiers-detail-modal');
    const body  = document.getElementById('v2-tiers-detail-body');
    if (!modal || !body || !tiers) return;
    const name = tiers.raison_sociale || ((tiers.prenom || '') + ' ' + (tiers.nom || '')).trim();
    const rows = [
      ['Type',          tiers.type_tiers || '—'],
      ['Civilité',      tiers.civilite  || '—'],
      ['Nom / Raison',  name || '—'],
      ['Email',         tiers.email     || '—'],
      ['Téléphone',     tiers.telephone || '—'],
      ['ID tiers',      '#' + tiers.id],
    ];
    body.innerHTML = rows.map(([k, v]) =>
      `<div class="tk">${k}</div><div class="tv">${String(v).replace(/</g,'&lt;')}</div>`
    ).join('');
    const hide = () => { modal.hidden = true; };
    modal.hidden = false;
    document.getElementById('v2-td-cancel').onclick = hide;
    document.getElementById('v2-td-select').onclick = () => {
      const input = document.getElementById('v2-proprio-search');
      if (input) input.value = name;
      saveField('id_proprietaire', tiers.id).then(() => {
        hide();
        setTimeout(() => window.location.reload(), 500);
      });
    };
  }

  // ── Google Places Autocomplete (adresse) ──
  window.v2InitPlaces = function () {
    const input = document.getElementById('v2-google-places');
    if (!input || !window.google || !google.maps || !google.maps.places) return;
    const ac = new google.maps.places.Autocomplete(input, {
      types: ['address'], componentRestrictions: { country: ['fr','be','lu','ch','mc'] },
      fields: ['address_components','formatted_address']
    });
    ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place || !place.address_components) return;
      const get = (type) => {
        const c = place.address_components.find(a => a.types.includes(type));
        return c ? c.long_name : '';
      };
      const streetNum = get('street_number');
      const route     = get('route');
      const cp        = get('postal_code');
      const ville     = get('locality') || get('postal_town');
      const setVal = (id, v) => {
        const el = document.getElementById(id);
        if (el) { el.value = v || ''; el.dispatchEvent(new Event('change')); }
      };
      setVal('v2-f-adresse_1', (streetNum + ' ' + route).trim());
      setVal('v2-f-code_postal', cp);
      setVal('v2-f-ville', ville);
    });
  };

  // ── Init ──
  document.addEventListener('DOMContentLoaded', () => {
    const data = window.__v2DocsData || {};
    const section = data.section || 'documents';

    if (section === 'descriptif') {
      bindDescriptifAutosave(data);
    }

    if (section === 'documents') {
      const docsDiag   = data.docsDiag   || [];
      const docsMandat = data.docsMandat || [];
      const docsAutre  = data.docsAutre  || [];

      const setCount = (id, n) => {
        const el = document.getElementById(id);
        if (el) el.textContent = n;
      };
      setCount('v2-count-diag',   docsDiag.length);
      setCount('v2-count-mandat', docsMandat.length);
      setCount('v2-count-autre',  docsAutre.length);

      renderDocsList('v2-list-diag',   docsDiag,   '📊', 'Aucun diagnostic enregistré pour ce bien.');
      renderDocsList('v2-list-mandat', docsMandat, '📋', 'Aucun mandat enregistré pour ce bien.');
      renderDocsList('v2-list-autre',  docsAutre,  '📎', 'Aucun document divers enregistré.');

      const uploaderContainer = document.getElementById('v2-uploader');
      if (uploaderContainer && window.DocumentUploader && data.bienId) {
        new window.DocumentUploader('v2-uploader', {
          context: 'bien',
          idContexte: data.bienId,
          endpoint: data.uploadEndpoint || '/api/bien_intake_upload.php',
          csrfToken: data.csrfToken || '',
          availableTypes: ['diag', 'bail', 'mandat', 'titre', 'fiche', 'divers'],
          defaultType: 'diag',
          showValidationTable: false,
          onSuccess: function () {
            setTimeout(() => window.location.reload(), 1200);
          },
        });
      }
    } else if (section === 'dpe') {
      bindMissingForm();
    }

    const stage = document.getElementById('v2-stage');
    if (!stage) return;
    const dots = document.getElementById('v2-dots');
    const tabs = document.getElementById('v2-stage-tabs');
    const prevBtn = document.getElementById('v2-prev');
    const nextBtn = document.getElementById('v2-next');
    new V2Carousel(stage, {
      dotsEl: dots,
      tabsEl: tabs,
      prevBtn: prevBtn,
      nextBtn: nextBtn,
      startIndex: 0,
    });
  });
})();
