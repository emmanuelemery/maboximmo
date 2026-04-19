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

    // Bool toggles (balcon, terrasse, ascenseur, etc.) — click = toggle on/off
    document.querySelectorAll('.v2-bool-toggle').forEach(btn => {
      const field = btn.dataset.boolField;
      if (!field) return;
      btn.addEventListener('click', () => {
        const isActive = !btn.classList.contains('is-active');
        btn.classList.toggle('is-active', isActive);
        // Dès modification : retire l'indicateur DPE (valeur devient saisie utilisateur)
        btn.classList.remove('is-from-dpe');
        saveField(field, isActive ? '1' : '0');
      });
    });

    // Retire 'is-from-dpe' sur input/num à la modification utilisateur
    document.querySelectorAll('[data-autosave]').forEach(el => {
      el.addEventListener('input', () => {
        el.classList.remove('is-from-dpe');
        const wrap = el.closest('.v2-num-field');
        if (wrap) wrap.classList.remove('is-from-dpe');
      });
    });

    // Icon radios — 2 modes :
    //  - Par defaut : 1 choix exclusif par groupe (reclic = déselection)
    //  - data-multi="1" : multi-sélection, valeurs concaténées par virgule
    document.querySelectorAll('.v2-icon-radios').forEach(group => {
      const field = group.dataset.field;
      if (!field) return;
      const isMulti = group.dataset.multi === '1';

      group.querySelectorAll('.v2-icon-radio').forEach(btn => {
        btn.addEventListener('click', () => {
          if (isMulti) {
            // Multi : toggle le bouton cliqué uniquement, save valeurs concat
            btn.classList.toggle('is-active');
            const values = Array.from(group.querySelectorAll('.v2-icon-radio.is-active'))
              .map(b => b.dataset.value || '')
              .filter(Boolean);
            saveField(field, values.join(','));
          } else {
            // Radio (exclusif)
            const wasActive = btn.classList.contains('is-active');
            group.querySelectorAll('.v2-icon-radio').forEach(b => b.classList.remove('is-active'));
            if (!wasActive) {
              btn.classList.add('is-active');
              saveField(field, btn.dataset.value || '');
            } else {
              saveField(field, '');
            }
          }
        });
      });
    });

    // Modal liste immeubles (simplifié, déjà rendu en PHP)
    const immBtn = document.getElementById('v2-imm-btn');
    const immModal = document.getElementById('v2-imm-modal');
    const immClose = document.getElementById('v2-imm-modal-close');

    if (immBtn && immModal) {
      immBtn.addEventListener('click', () => { immModal.hidden = false; });
      immClose?.addEventListener('click', () => { immModal.hidden = true; });
      immModal.addEventListener('click', (e) => {
        if (e.target === immModal) immModal.hidden = true;
        const item = e.target.closest('.v2-imm-item');
        if (!item) return;
        const setVal = (id, v) => {
          const el = document.getElementById(id);
          if (el) { el.value = v || ''; el.dispatchEvent(new Event('change')); }
        };
        setVal('v2-f-adresse_1',   item.dataset.adresse || '');
        setVal('v2-f-code_postal', item.dataset.cp || '');
        setVal('v2-f-ville',       item.dataset.ville || '');
        immModal.hidden = true;
      });
    }

  }

  // ── Section ANNONCE : création + autosave annonce + toggles canaux ──
  function bindAnnonceSection(data) {
    const indicator = document.getElementById('v2-annonce-save-indicator');
    const csrf = data.csrfToken;

    function showInd(kind, msg) {
      if (!indicator) return;
      indicator.className = 'v2-save-indicator v2-save-floating ' + (kind || '');
      indicator.textContent = msg || '';
      if (kind === 'ok') {
        setTimeout(() => { if (indicator) { indicator.textContent = ''; indicator.className = 'v2-save-indicator v2-save-floating'; } }, 2000);
      }
    }

    async function saveAnnonce(name, value) {
      const aid = data.annonceId;
      if (!aid) return;
      showInd('', '💾 Enregistrement…');
      const fd = new FormData();
      fd.append('_annonce_id', aid);
      fd.append('csrf_token', csrf);
      fd.append(name, value == null ? '' : value);
      try {
        const r = await fetch(data.annonceAutosaveEndpoint || '/api/annonce_autosave.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (j.ok) showInd('ok', '✅ Enregistré ' + (j.saved_at || ''));
        else showInd('err', '❌ ' + (j.error || 'Erreur'));
      } catch (e) {
        showInd('err', '❌ ' + e.message);
      }
    }

    // Création annonce (bouton dans Card 1)
    const btnCreate = document.getElementById('v2-annonce-create');
    const btnCreateStatus = document.getElementById('v2-annonce-create-status');
    if (btnCreate) {
      btnCreate.addEventListener('click', async () => {
        btnCreate.disabled = true;
        if (btnCreateStatus) { btnCreateStatus.textContent = '⏳ Création…'; btnCreateStatus.className = 'v2-form-status'; }
        const fd = new FormData();
        fd.append('id_bien', data.bienId);
        fd.append('csrf_token', csrf);
        try {
          const r = await fetch(data.annonceCreateEndpoint || '/api/annonce_create.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
          });
          const j = await r.json();
          if (!j.ok) throw new Error(j.error || 'Erreur création');
          if (btnCreateStatus) { btnCreateStatus.textContent = '✅ Annonce créée, rechargement…'; btnCreateStatus.className = 'v2-form-status ok'; }
          setTimeout(() => window.location.reload(), 500);
        } catch (e) {
          btnCreate.disabled = false;
          if (btnCreateStatus) { btnCreateStatus.textContent = '❌ ' + e.message; btnCreateStatus.className = 'v2-form-status err'; }
        }
      });
    }

    // Toggles canaux (data-annonce-bool)
    document.querySelectorAll('[data-annonce-bool]').forEach(btn => {
      const field = btn.dataset.annonceBool;
      if (!field) return;
      btn.addEventListener('click', () => {
        const isActive = !btn.classList.contains('is-active');
        btn.classList.toggle('is-active', isActive);
        saveAnnonce(field, isActive ? '1' : '0');
      });
    });

    // Inputs annonce (futurs champs financiers) — même pattern data-annonce-save
    document.querySelectorAll('[data-annonce-save]').forEach(el => {
      const handler = () => saveAnnonce(el.name, el.value);
      el.addEventListener('change', handler);
      if (el.type === 'text' || el.type === 'number' || el.type === 'date' || el.tagName === 'TEXTAREA') {
        el.addEventListener('blur', handler);
      }
    });
  }

  // ── Init ──
  document.addEventListener('DOMContentLoaded', () => {
    const data = window.__v2DocsData || {};
    const section = data.section || 'documents';

    if (section === 'annonce') {
      bindAnnonceSection(data);
    }

    if (section === 'descriptif') {
      bindDescriptifAutosave(data);

      // Création express du propriétaire détecté dans le DPE
      const btnCreate = document.getElementById('v2-dpe-proprio-create');
      const suggestBox = document.getElementById('v2-dpe-proprio-suggest');
      const statusEl   = document.getElementById('v2-dpe-proprio-status');
      if (btnCreate && suggestBox) {
        btnCreate.addEventListener('click', async () => {
          const pd = JSON.parse(suggestBox.dataset.proprio || '{}');
          btnCreate.disabled = true;
          if (statusEl) { statusEl.textContent = '⏳ Création…'; statusEl.className = 'v2-form-status'; }
          try {
            // 1. Créer le tiers via tiers_create
            const body = {
              type_tiers:     pd.type || 'personne_physique',
              civilite:       pd.civilite || '',
              nom:            pd.nom || '',
              prenom:         pd.prenom || '',
              raison_sociale: pd.societe || '',
              email:          pd.email || '',
              telephone:      pd.telephone || '',
              adresse_1:      pd.adresse || '',
              code_postal:    pd.cp || '',
              ville:          pd.ville || '',
              roles: [{ role_code: 'proprietaire', objet_type: 'bien', id_objet: data.bienId }],
            };
            const r = await fetch(data.tiersCreateEndpoint || '/api/tiers_create.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body: JSON.stringify(body),
            });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Erreur création');
            // 2. Associer au bien via autosave
            await window.__v2SaveField('id_proprietaire', j.id || j.tiers_id || '');
            if (statusEl) { statusEl.textContent = '✅ Associé, rechargement…'; statusEl.className = 'v2-form-status ok'; }
            setTimeout(() => window.location.reload(), 600);
          } catch (e) {
            btnCreate.disabled = false;
            if (statusEl) { statusEl.textContent = '❌ ' + e.message; statusEl.className = 'v2-form-status err'; }
          }
        });
      }
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

      // ── Grille photos Card 5 Documents (lightbox + delete) ──
      const photosGrid = document.querySelector('.v2-photos-doc-grid');
      const lightbox   = document.getElementById('v2-photo-lightbox');
      const lbImg      = document.getElementById('v2-lightbox-img');
      const lbCaption  = document.getElementById('v2-lightbox-caption');
      const lbClose    = lightbox?.querySelector('.v2-lightbox-close');

      function openLightbox(url, name) {
        if (!lightbox || !lbImg) return;
        lbImg.src = url;
        lbImg.alt = name || '';
        if (lbCaption) lbCaption.textContent = name || '';
        lightbox.hidden = false;
      }
      function closeLightbox() { if (lightbox) lightbox.hidden = true; }

      if (lightbox) {
        lbClose?.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', (e) => { if (e.target === lightbox) closeLightbox(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLightbox(); });
      }

      if (photosGrid) {
        photosGrid.addEventListener('click', async (e) => {
          const tile = e.target.closest('.v2-photo-tile');
          if (!tile) return;
          const btn = e.target.closest('.v2-photo-tile-btn');
          const url = tile.dataset.url;
          const name = tile.dataset.name;

          // Si pas de clic sur un bouton : clic sur tile = agrandir
          if (!btn) { openLightbox(url, name); return; }

          const action = btn.dataset.action;
          if (action === 'zoom') { openLightbox(url, name); return; }

          if (action === 'delete') {
            if (!confirm('Supprimer cette photo ?')) return;
            const id = parseInt(tile.dataset.id, 10) || 0;
            if (id <= 0) return;
            const fd = new FormData();
            fd.append('id_photo', id);
            fd.append('csrf_token', data.csrfToken || '');
            try {
              const r = await fetch(data.photoDeleteEndpoint || '/api/bien_photo_delete.php', {
                method: 'POST', body: fd, credentials: 'same-origin'
              });
              const j = await r.json();
              if (!j.ok) throw new Error(j.error || 'Erreur suppression');
              tile.remove();
              const counter = document.getElementById('v2-count-photos');
              if (counter) counter.textContent = photosGrid.querySelectorAll('.v2-photo-tile').length;
            } catch (err) {
              alert('❌ ' + err.message);
            }
          }
        });
      }

      // ── Dropzone Photos (glisser/cliquer, multi-fichiers) ──
      const dz = document.getElementById('v2-photo-drop');
      const dzInput = document.getElementById('v2-photo-input');
      const dzStatus = document.getElementById('v2-photo-drop-status');
      if (dz && dzInput) {
        const setStatus = (kind, msg) => {
          if (!dzStatus) return;
          dzStatus.className = 'v2-photo-drop-status ' + (kind || '');
          dzStatus.textContent = msg || '';
        };

        async function uploadPhoto(file) {
          const fd = new FormData();
          fd.append('fichier', file);
          fd.append('csrf_token', data.csrfToken || '');
          if (data.bienId) fd.append('id_bien', data.bienId);
          const r = await fetch(data.photoUploadEndpoint || '/api/bien_intake_photo_upload.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
          });
          return r.json();
        }

        async function uploadAll(files) {
          const list = Array.from(files).filter(f => /^image\//.test(f.type));
          if (list.length === 0) { setStatus('err', '❌ Aucune image valide'); return; }
          let done = 0, errs = 0;
          setStatus('', `⏳ 0 / ${list.length}…`);
          for (const f of list) {
            try {
              const j = await uploadPhoto(f);
              if (j.ok) done++; else errs++;
            } catch (e) { errs++; }
            setStatus('', `⏳ ${done + errs} / ${list.length}…`);
          }
          if (errs === 0) setStatus('ok', `✅ ${done} photo(s) ajoutée(s)`);
          else setStatus('err', `⚠️ ${done} OK · ${errs} échec(s)`);
        }

        dz.addEventListener('click', () => dzInput.click());
        dzInput.addEventListener('change', (e) => {
          if (e.target.files?.length) uploadAll(e.target.files);
          dzInput.value = '';
        });
        ['dragenter', 'dragover'].forEach(ev =>
          dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('is-dragging'); })
        );
        ['dragleave', 'drop'].forEach(ev =>
          dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.remove('is-dragging'); })
        );
        dz.addEventListener('drop', (e) => {
          if (e.dataTransfer?.files?.length) uploadAll(e.dataTransfer.files);
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
