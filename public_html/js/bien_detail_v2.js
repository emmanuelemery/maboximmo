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
            <button type="button" class="v2-doc-btn v2-doc-btn-danger" data-action="delete-doc" title="Supprimer ce document">🗑️</button>
          </div>
        </div>`;
    }).join('');
    el.innerHTML = '<div class="v2-doc-list">' + rows + '</div>';

    // Handler suppression (délégué sur le container)
    el.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action="delete-doc"]');
      if (!btn) return;
      const item = btn.closest('.v2-doc-item');
      const docId = parseInt(item?.dataset.docId, 10) || 0;
      if (!docId) return;
      if (!confirm('Supprimer ce document ?\n(Le fichier et la ligne sont retirés, les données DPE/mandat analysées restent.)')) return;

      btn.disabled = true; btn.textContent = '⏳';
      const fd = new FormData();
      fd.append('id_doc', docId);
      fd.append('csrf_token', (window.__v2DocsData || {}).csrfToken || '');
      try {
        const r = await fetch('/api/biens_documents_delete.php', {
          method: 'POST', body: fd, credentials: 'same-origin',
        });
        const j = await r.json();
        if (!j.ok) { alert('❌ ' + (j.error || 'Erreur')); btn.disabled = false; btn.textContent = '🗑️'; return; }
        // Retire la ligne du DOM + met à jour le compteur de la Card
        item.style.transition = 'opacity .25s';
        item.style.opacity = '0';
        setTimeout(() => {
          item.remove();
          // Met à jour le compteur v2-count-* associé à ce container
          const countId = containerId.replace('v2-list-', 'v2-count-');
          const countEl = document.getElementById(countId);
          if (countEl) {
            const n = el.querySelectorAll('.v2-doc-item').length;
            countEl.textContent = n;
            // Si plus aucun doc : empty state
            if (n === 0) {
              el.innerHTML = `<div class="v2-doc-empty"><div class="v2-doc-empty-icon">${emptyIcon}</div><div>${emptyMsg}</div></div>`;
            }
          }
        }, 250);
      } catch (err) {
        alert('❌ ' + err.message);
        btn.disabled = false; btn.textContent = '🗑️';
      }
    });
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

  // ══════════════════════════════════════════════════════════════════
  // Card Propriétaire (section descriptif) : picker autocomplete +
  // modal de création express + dissociation
  // ══════════════════════════════════════════════════════════════════
  function bindProprioManager(data) {
    const searchEl  = document.getElementById('v2-proprio-search');
    const resultsEl = document.getElementById('v2-proprio-results');
    const newBtn    = document.getElementById('v2-proprio-new-btn');
    const unlinkBtn = document.getElementById('v2-proprio-unlink');
    if (!searchEl && !newBtn && !unlinkBtn) return;

    // ─── Lier/dissocier un tiers au bien ──
    // NOTE : biens.id_proprietaire a une FK vers `proprietaires.id` (legacy),
    // pas vers `tiers.id`. On passe par /api/bien_proprio_link.php qui crée
    // la ligne proprietaires à la volée si elle n'existe pas.
    // tiersId = 0 ou '' -> dissocie.
    // Retourne : { ok: bool, id_proprio_legacy: number|null, error?: string }
    async function linkTiersToBien(tiersId) {
      const bienId = parseInt(data.bienId, 10) || 0;
      if (!bienId) return { ok: false, error: 'bienId manquant' };
      const body = {
        id_bien:    bienId,
        id_tiers:   parseInt(tiersId, 10) || 0,
        csrf_token: data.csrfToken || '',
      };
      try {
        const r = await fetch((data.bienProprioLinkEndpoint || '/api/bien_proprio_link.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': data.csrfToken || '',
          },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        });
        const j = await r.json();
        if (!j.ok) {
          console.warn('[linkTiersToBien]', j.error);
          alert('❌ ' + (j.error || 'Erreur liaison propriétaire'));
          return { ok: false, error: j.error };
        }
        return { ok: true, id_proprio_legacy: j.id_proprio_legacy || null };
      } catch (e) {
        console.warn('[linkTiersToBien]', e);
        alert('❌ ' + e.message);
        return { ok: false, error: e.message };
      }
    }

    // ─── Autocomplete picker ────────────────────────────────────────
    let searchTimer = null;
    function renderResults(items) {
      if (!resultsEl) return;
      if (!items.length) {
        resultsEl.innerHTML = '<div class="v2-proprio-result-empty">Aucun résultat. Clique « + Créer nouveau » pour l\'ajouter.</div>';
        resultsEl.hidden = false;
        return;
      }
      resultsEl.innerHTML = items.map(t => {
        const name = t.type_tiers === 'personne_morale'
          ? (t.raison_sociale || '—')
          : ((t.civilite || '') + ' ' + (t.prenom || '') + ' ' + (t.nom || '')).trim() || '—';
        const meta = [t.email, t.telephone, t.ville].filter(Boolean).join(' · ');
        return `
          <button type="button" class="v2-proprio-result" data-tiers-id="${t.id}">
            <span class="v2-proprio-result-avatar">${t.type_tiers === 'personne_morale' ? '🏢' : '👤'}</span>
            <span class="v2-proprio-result-body">
              <span class="v2-proprio-result-name">${escapeHtml(name)}</span>
              ${meta ? '<span class="v2-proprio-result-meta">' + escapeHtml(meta) + '</span>' : ''}
            </span>
          </button>
        `;
      }).join('');
      resultsEl.hidden = false;
      resultsEl.querySelectorAll('.v2-proprio-result').forEach(btn => {
        btn.addEventListener('click', async () => {
          const tid = parseInt(btn.dataset.tiersId, 10) || 0;
          if (!tid) return;
          btn.disabled = true;
          btn.innerHTML = '⏳ Liaison en cours…';
          const res = await linkTiersToBien(tid);
          if (res.ok) {
            setTimeout(() => window.location.reload(), 400);
          } else {
            btn.disabled = false;
            btn.innerHTML = '❌ Erreur — réessayer';
          }
        });
      });
    }

    function escapeHtml(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, ch =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[ch]));
    }

    if (searchEl) {
      searchEl.addEventListener('input', () => {
        const q = searchEl.value.trim();
        clearTimeout(searchTimer);
        if (q.length < 2) {
          if (resultsEl) { resultsEl.hidden = true; resultsEl.innerHTML = ''; }
          return;
        }
        searchTimer = setTimeout(async () => {
          try {
            // On NE filtre PAS par role (un tiers sans role proprietaire
            // peut devenir proprietaire via la liaison) et on NE filtre PAS
            // par societe/agence (un proprietaire peut etre partage entre
            // plusieurs societes/agences — confirme par le user).
            const url = (data.tiersLookupEndpoint || '/api/tiers_lookup.php')
              + '?q=' + encodeURIComponent(q) + '&scope=all&limit=15';
            const r = await fetch(url, { credentials: 'same-origin' });
            const j = await r.json();
            renderResults(j.items || []);
          } catch (e) { /* silent */ }
        }, 250);
      });
      searchEl.addEventListener('blur', () => {
        setTimeout(() => { if (resultsEl) resultsEl.hidden = true; }, 200);
      });
      searchEl.addEventListener('focus', () => {
        if (resultsEl && resultsEl.innerHTML) resultsEl.hidden = false;
      });
    }

    // ─── Dissocier ─────────────────────────────────────────────────
    if (unlinkBtn) {
      unlinkBtn.addEventListener('click', async () => {
        if (!confirm('Retirer ce propriétaire du bien ?\n(Le tiers reste en base et peut être relié ailleurs.)')) return;
        unlinkBtn.disabled = true;
        unlinkBtn.textContent = '⏳ Dissociation…';
        const res = await linkTiersToBien('');
        if (res.ok) setTimeout(() => window.location.reload(), 400);
        else { unlinkBtn.disabled = false; unlinkBtn.textContent = '❌ Erreur'; }
      });
    }

    // ─── Modal création express ────────────────────────────────────
    const modal       = document.getElementById('v2-proprio-modal');
    const closeBtn    = document.getElementById('v2-proprio-modal-close');
    const cancelBtn   = document.getElementById('v2-proprio-modal-cancel');
    const saveBtn     = document.getElementById('v2-proprio-modal-save');
    const statusEl    = document.getElementById('v2-proprio-modal-status');
    const typeRadios  = document.getElementById('v2-proprio-type');
    const ppFields    = document.getElementById('v2-proprio-pp-fields');
    const pmFields    = document.getElementById('v2-proprio-pm-fields');

    function setModalStatus(kind, msg) {
      if (!statusEl) return;
      statusEl.className = 'v2-form-status' + (kind ? ' ' + kind : '');
      statusEl.textContent = msg || '';
    }
    // openModal(prefill?) — prefill est un objet optionnel avec les champs
    // pré-remplis (ex: depuis suggestion DPE). Les champs vides laissent
    // l'état actuel, les champs fournis écrasent.
    function openModal(prefill) {
      if (!modal) return;
      modal.hidden = false;
      setModalStatus('', '');
      const set = (id, v) => { const el = document.getElementById(id); if (el && v != null && v !== '') el.value = v; };
      if (prefill && typeof prefill === 'object') {
        // Type : bascule sur le bon tab si fourni
        const wantType = prefill.type_tiers || (prefill.raison_sociale ? 'personne_morale' : 'personne_physique');
        const typeBtn = typeRadios ? typeRadios.querySelector('.v2-icon-radio[data-value="' + wantType + '"]') : null;
        if (typeBtn && !typeBtn.classList.contains('is-active')) typeBtn.click();
        set('v2-proprio-nom',    prefill.nom);
        set('v2-proprio-prenom', prefill.prenom);
        set('v2-proprio-raison', prefill.raison_sociale);
        set('v2-proprio-siret',  prefill.siret);
        set('v2-proprio-email',  prefill.email);
        set('v2-proprio-tel',    prefill.telephone);
        set('v2-proprio-adresse',prefill.adresse || prefill.adresse_1 || prefill.adresse_ligne1);
        set('v2-proprio-cp',     prefill.code_postal || prefill.cp);
        set('v2-proprio-ville',  prefill.ville);
      } else if (searchEl && searchEl.value.trim()) {
        set('v2-proprio-nom', searchEl.value.trim());
      }
    }
    function closeModal() { if (modal) modal.hidden = true; }
    // Expose openModal avec prefill pour la suggestion DPE
    window.__v2OpenProprioModal = openModal;

    if (newBtn && modal) newBtn.addEventListener('click', openModal);
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });

    // Switch type particulier / société
    let typeTiers = 'personne_physique';
    if (typeRadios) {
      typeRadios.querySelectorAll('.v2-icon-radio').forEach(btn => {
        btn.addEventListener('click', () => {
          typeRadios.querySelectorAll('.v2-icon-radio').forEach(b => b.classList.remove('is-active'));
          btn.classList.add('is-active');
          typeTiers = btn.dataset.value || 'personne_physique';
          if (ppFields) ppFields.hidden = (typeTiers !== 'personne_physique');
          if (pmFields) pmFields.hidden = (typeTiers !== 'personne_morale');
        });
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const nom     = (document.getElementById('v2-proprio-nom')    || {}).value || '';
        const prenom  = (document.getElementById('v2-proprio-prenom') || {}).value || '';
        const raison  = (document.getElementById('v2-proprio-raison') || {}).value || '';
        const siret   = (document.getElementById('v2-proprio-siret')  || {}).value || '';
        const email   = (document.getElementById('v2-proprio-email')  || {}).value || '';
        const tel     = (document.getElementById('v2-proprio-tel')    || {}).value || '';
        const adresse = (document.getElementById('v2-proprio-adresse')|| {}).value || '';
        const cp      = (document.getElementById('v2-proprio-cp')     || {}).value || '';
        const ville   = (document.getElementById('v2-proprio-ville')  || {}).value || '';

        if (typeTiers === 'personne_physique' && !nom.trim()) {
          setModalStatus('err', '❌ Le nom est obligatoire.');
          return;
        }
        if (typeTiers === 'personne_morale' && !raison.trim()) {
          setModalStatus('err', '❌ La raison sociale est obligatoire.');
          return;
        }

        saveBtn.disabled = true;
        setModalStatus('', '⏳ Création du tiers…');

        const body = {
          type_tiers:     typeTiers,
          nom:            nom.trim(),
          prenom:         prenom.trim(),
          raison_sociale: raison.trim(),
          siret:          siret.trim(),
          email:          email.trim(),
          telephone:      tel.trim(),
          adresse_ligne1: adresse.trim(),
          code_postal:    cp.trim(),
          ville:          ville.trim(),
          roles: [{ role_code: 'proprietaire', objet_type: 'bien', id_objet: data.bienId }],
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
          const tiersId = j.id_tiers || j.id || j.tiers_id || 0;
          if (!tiersId) throw new Error('Tiers créé mais id manquant');
          const res = await linkTiersToBien(tiersId);
          if (!res.ok) throw new Error(res.error || 'Échec liaison au bien');
          // Redirection vers la fiche du propriétaire (modifiable),
          // avec return URL pour revenir sur ce bien au clic "Retour".
          const idLegacy = res.id_proprio_legacy || 0;
          if (idLegacy > 0) {
            const baseDetail = data.bienDetailUrl || '/bien_detail.php';
            const baseFiche  = data.proprioFicheUrl || '/agency_proprietaire_fiche.php';
            const retUrl = baseDetail + '?edit=' + encodeURIComponent(data.bienId) + '&section=descriptif';
            setModalStatus('ok', '✅ Créé et associé — ouverture de sa fiche…');
            setTimeout(() => {
              window.location.href = baseFiche + '?id=' + idLegacy
                + '&return=' + encodeURIComponent(retUrl);
            }, 400);
          } else {
            setModalStatus('ok', '✅ Créé et associé, rechargement…');
            setTimeout(() => window.location.reload(), 500);
          }
        } catch (e) {
          saveBtn.disabled = false;
          setModalStatus('err', '❌ ' + e.message);
        }
      });
    }
  }

  // ═══════════════════════════════════════════════════════════════
  // Flow 2026-04-22 : Validation bien → création annonce
  // ═══════════════════════════════════════════════════════════════

  // Ouvre le modal "Créer une annonce ?" (section=annonce, bien actif, pas d'annonce)
  function openAnnonceCreateModal(data) {
    const modal = document.getElementById('v2-annonce-create-modal');
    if (!modal) return;
    modal.hidden = false;
    const confirmBtn = document.getElementById('v2-annonce-modal-confirm');
    if (confirmBtn && !confirmBtn.__bound) {
      confirmBtn.__bound = true;
      confirmBtn.addEventListener('click', async () => {
        confirmBtn.disabled = true;
        confirmBtn.textContent = '⏳ Création…';
        try {
          const fd = new FormData();
          fd.append('id_bien', data.bienId);
          fd.append('csrf_token', data.csrfToken);
          const r = await fetch(data.annonceCreateEndpoint || '/api/annonce_create.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
          });
          const j = await r.json();
          if (!j.ok) throw new Error(j.error || 'Erreur création annonce');
          // Reload la page pour charger la Card 1 Conditions financières
          window.location.reload();
        } catch (e) {
          alert('❌ ' + e.message);
          confirmBtn.disabled = false;
          confirmBtn.textContent = '✅ Créer l\'annonce';
        }
      });
    }
    // Binding des boutons fermer
    modal.querySelectorAll('[data-annonce-modal-close]').forEach(el => {
      if (el.__bound) return;
      el.__bound = true;
      el.addEventListener('click', (e) => {
        e.preventDefault();
        modal.hidden = true;
      });
    });
    // Fallback bouton "Ouvrir création" (si user a fermé le modal)
    const fallbackBtn = document.getElementById('v2-annonce-create');
    if (fallbackBtn && !fallbackBtn.__bound) {
      fallbackBtn.__bound = true;
      fallbackBtn.addEventListener('click', () => openAnnonceCreateModal(data));
    }
  }

  // Card Validation : bouton Valider + bouton Dé-valider
  function bindValidationCard(data) {
    const btnValidate   = document.getElementById('v2-bien-validate');
    const btnInvalidate = document.getElementById('v2-bien-invalidate');
    const statusEl      = document.getElementById('v2-bien-validate-status');
    const csrf          = data.csrfToken;
    const endpoint      = data.bienValidateEndpoint || '/api/bien_validate.php';

    async function callApi(action, confirmMsg) {
      if (confirmMsg && !confirm(confirmMsg)) return;
      const targetBtn = action === 'validate' ? btnValidate : btnInvalidate;
      if (targetBtn) targetBtn.disabled = true;
      if (statusEl) { statusEl.textContent = '⏳ En cours…'; statusEl.style.color = '#64748b'; }
      try {
        const fd = new FormData();
        fd.append('id_bien', data.bienId);
        fd.append('action', action);
        fd.append('csrf_token', csrf);
        const r = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) {
          const msg = j.error + (j.missing && j.missing.length ? ' (' + j.missing.join(', ') + ')' : '');
          throw new Error(msg);
        }
        if (statusEl) {
          statusEl.textContent = action === 'validate' ? '✅ Bien validé ! Rechargement…' : '🔓 Bien dé-validé. Rechargement…';
          statusEl.style.color = '#16a34a';
        }
        setTimeout(() => window.location.reload(), 500);
      } catch (e) {
        if (statusEl) { statusEl.textContent = '❌ ' + e.message; statusEl.style.color = '#dc2626'; }
        if (targetBtn) targetBtn.disabled = false;
      }
    }

    if (btnValidate) btnValidate.addEventListener('click', () => callApi('validate', null));
    if (btnInvalidate) btnInvalidate.addEventListener('click', () => callApi('invalidate',
      '⚠️ Dé-valider le bien va le repasser en brouillon et te permettre de modifier adresse + propriétaire.\n\nLe bien ne sera plus diffusable tant qu\'il n\'est pas revalidé.\n\nContinuer ?'));
  }

  // ── Section ANNONCE : création + autosave annonce + toggles canaux ──
  function bindAnnonceSection(data) {
    const indicator = document.getElementById('v2-annonce-save-indicator');
    const csrf = data.csrfToken;

    // Rafraîchit les champs readonly / auto calculés à partir de la réponse JSON
    // des endpoints d'autosave. Les endpoints renvoient maintenant :
    //   { loyer_cc, loyer_hc, loyer_reference_majore, complement_loyer,
    //     depot_garantie, honoraires: { location_bail, edl, total, zone,
    //     location_bail_capped, edl_capped, plafond_location_bail, plafond_edl } }
    function applyCalculated(json) {
      if (!json || typeof json !== 'object') return;
      const fmt = (n) => {
        if (n == null || isNaN(n)) return '';
        const s = Number(n).toFixed(2);
        return s.replace(/\.?0+$/, '');
      };
      // setDisp : ne rien faire si la clé est ABSENTE de la réponse (undefined).
      // Si la clé est présente mais null/0 → on efface. Sinon on met à jour.
      // Corrige le bug "sauver charges efface le complément Card 1".
      const setDisp = (id, val) => {
        if (val === undefined) return;
        const el = document.getElementById(id);
        if (!el) return;
        el.value = (typeof val === 'number' && val > 0) ? fmt(val) : '';
      };
      // Champs readonly (toujours présents quand pertinents)
      setDisp('v2-loyer-cc-display',          json.loyer_cc);
      setDisp('v2-complement-loyer-display',  json.complement_loyer);
      setDisp('v2-loyer-majore-display',      json.loyer_reference_majore);

      // Champs auto-fillable (ne pas écraser si l'utilisateur a saisi un truc plus récent)
      const syncIfEmpty = (selector, val) => {
        const el = document.querySelector(selector);
        if (el && typeof val === 'number' && val > 0 && (!el.value || parseFloat(el.value) === 0)) {
          el.value = fmt(val);
        }
      };
      if (json.loyer_reference_majore != null) syncIfEmpty('[name="loyer_reference_majore"]', json.loyer_reference_majore);
      if (json.enc_zone != null)               syncIfEmpty('[name="enc_zone"]',               json.enc_zone);

      // Dépôt garantie : FORCE l'update car le backend a pu l'écraser (toggle meublé ×2).
      // L'input ne doit pas avoir le focus pour éviter d'écraser une saisie en cours.
      if (typeof json.depot_garantie === 'number') {
        const depEl = document.querySelector('[name="depot_garantie"]');
        if (depEl && document.activeElement !== depEl) {
          depEl.value = json.depot_garantie > 0 ? fmt(json.depot_garantie) : '';
        }
      }

      // Vente : synchronise les 4 champs avec les valeurs finales backend
      // (prix_fai readonly toujours, les autres sauf si focus utilisateur)
      if (json.vente && typeof json.vente === 'object') {
        const v = json.vente;
        const updateIfNotFocus = (id, val) => {
          const el = document.getElementById(id);
          if (!el || document.activeElement === el) return;
          el.value = (typeof val === 'number' && val > 0) ? fmt(val) : '';
        };
        updateIfNotFocus('v2-vente-net',  v.prix_net_vendeur);
        updateIfNotFocus('v2-vente-hono', v.honoraires);
        updateIfNotFocus('v2-vente-pct',  v.pct_alur);
        // Prix FAI readonly : toujours mis à jour
        const faiEl = document.getElementById('v2-vente-fai');
        if (faiEl) faiEl.value = (typeof v.prix_fai === 'number' && v.prix_fai > 0) ? fmt(v.prix_fai) : '';
      }

      // Loyer HC : si le backend a recalculé (= majoré + complément), on FORCE la
      // mise à jour de l'input (contrairement à syncIfEmpty) car c'est un champ
      // calculé dès que loyer_reference_majore > 0.
      if (typeof json.loyer_hc === 'number') {
        const hcEl = document.querySelector('[name="loyer"]');
        if (hcEl && json.loyer_hc > 0) hcEl.value = fmt(json.loyer_hc);
      }

      // Honoraires : le backend a pu écrêter la valeur (cap ALUR) → on force l'input
      // au montant renvoyé + on affiche un warning visible si capped.
      const warnEl = document.getElementById('v2-hono-cap-warning');
      const warnMsgs = [];
      if (json.honoraires && typeof json.honoraires === 'object') {
        const h = json.honoraires;
        const locEl = document.querySelector('[name="honoraires_location_bail"]');
        const edlEl = document.querySelector('[name="honoraires_etat_des_lieux"]');
        const totEl = document.getElementById('v2-hono-total-display');
        // Toujours synchroniser aux valeurs retournées par le serveur (après éventuel cap)
        if (locEl && typeof h.location_bail === 'number') locEl.value = h.location_bail > 0 ? fmt(h.location_bail) : '';
        if (edlEl && typeof h.edl === 'number')            edlEl.value = h.edl > 0           ? fmt(h.edl)           : '';
        if (totEl && typeof h.total === 'number')          totEl.value = h.total > 0         ? fmt(h.total)         : '';
        if (h.location_bail_capped) warnMsgs.push('⚠️ Honoraires location+bail plafonnés à ' + fmt(h.plafond_location_bail) + ' € (ALUR)');
        if (h.edl_capped)           warnMsgs.push('⚠️ Honoraires état des lieux plafonnés à ' + fmt(h.plafond_edl) + ' € (ALUR)');
      }
      if (warnEl) {
        if (warnMsgs.length) {
          warnEl.innerHTML = warnMsgs.join('<br>');
          warnEl.style.display = 'block';
          clearTimeout(warnEl.__hideTimer);
          warnEl.__hideTimer = setTimeout(() => { warnEl.style.display = 'none'; }, 6000);
        }
      }
    }
    // Exposé globalement pour que bindCplSection (hors scope) puisse l'appeler
    window.__v2ApplyCalculated = applyCalculated;

    // Autosave biens (data-autosave) — partagé avec la Card Encadrement
    async function saveBienField(name, value) {
      if (!data.bienId) return;
      const fd = new FormData();
      // bien_autosave.php attend `_edit_id` (pas `id_bien`)
      fd.append('_edit_id', data.bienId);
      fd.append('csrf_token', csrf);
      fd.append(name, value == null ? '' : value);
      try {
        const r = await fetch(data.autosaveEndpoint || '/api/bien_autosave.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (j.ok) { showInd('ok', '✅ Enregistré ' + (j.saved_at || '')); applyCalculated(j); }
        else showInd('err', '❌ ' + (j.error || 'Erreur'));
      } catch (e) {
        showInd('err', '❌ ' + e.message);
      }
    }
    document.querySelectorAll('[data-autosave]').forEach(el => {
      const handler = () => saveBienField(el.name, el.value);
      el.addEventListener('change', handler);
      if (el.type === 'text' || el.type === 'number' || el.type === 'date' || el.tagName === 'TEXTAREA') {
        el.addEventListener('blur', handler);
      }
    });

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
        if (j.ok) { showInd('ok', '✅ Enregistré ' + (j.saved_at || '')); applyCalculated(j); }
        else showInd('err', '❌ ' + (j.error || 'Erreur'));
      } catch (e) {
        showInd('err', '❌ ' + e.message);
      }
    }
    // Expose pour bindAnnonceIAGenerate (persistance directe apres setField,
    // plus robuste que de compter sur dispatchEvent('change'))
    window.__v2SaveAnnonceField = saveAnnonce;

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

    // Icon-radios de la section annonce (data-target="annonce") — route vers saveAnnonce
    document.querySelectorAll('.v2-icon-radios[data-target="annonce"]').forEach(group => {
      const field = group.dataset.field;
      if (!field) return;
      group.querySelectorAll('.v2-icon-radio').forEach(btn => {
        btn.addEventListener('click', () => {
          const wasActive = btn.classList.contains('is-active');
          group.querySelectorAll('.v2-icon-radio').forEach(b => b.classList.remove('is-active'));
          if (!wasActive) {
            btn.classList.add('is-active');
            saveAnnonce(field, btn.dataset.value || '');
          } else {
            saveAnnonce(field, '');
          }
        });
      });
    });

    // Boutons mode loyer HC (minoré / référence / majoré) — reload après save
    // car le changement de mode impacte readonly du champ, verrouillage
    // complément Card Encadrement, et recalcule loyer HC côté serveur.
    document.querySelectorAll('.v2-loyer-mode-btns[data-target="annonce"]').forEach(group => {
      const field = group.dataset.field || 'loyer_mode';
      group.querySelectorAll('.v2-mode-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (btn.classList.contains('is-active')) return;
          group.querySelectorAll('.v2-mode-btn').forEach(b => b.classList.remove('is-active'));
          btn.classList.add('is-active');
          await saveAnnonce(field, btn.dataset.value || 'libre');
          // Reload pour refléter readonly/lock/recalculé
          setTimeout(() => window.location.reload(), 150);
        });
      });
    });

    // Toggles canaux (data-annonce-bool)
    document.querySelectorAll('[data-annonce-bool]').forEach(btn => {
      const field = btn.dataset.annonceBool;
      if (!field) return;
      btn.addEventListener('click', async () => {
        const isActive = !btn.classList.contains('is-active');
        btn.classList.toggle('is-active', isActive);
        await saveAnnonce(field, isActive ? '1' : '0');
        // zone_encadrement_loyer toggle → reload (affiche/masque boutons mode, etc.)
        if (field === 'zone_encadrement_loyer') {
          setTimeout(() => window.location.reload(), 150);
        }
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

    // ══════════════════════════════════════════════════════════════
    // Section VENTE — cascade calcul instant + toggle payeur
    // net + hono (€) ⇄ % ; prix FAI = net + hono (auto, readonly)
    // ══════════════════════════════════════════════════════════════
    (function bindVenteSection() {
      const netEl  = document.getElementById('v2-vente-net');
      const honoEl = document.getElementById('v2-vente-hono');
      const pctEl  = document.getElementById('v2-vente-pct');
      const faiEl  = document.getElementById('v2-vente-fai');
      if (!netEl || !honoEl || !pctEl || !faiEl) return;

      const fmt = (n) => {
        if (n == null || isNaN(n)) return '';
        const s = Number(n).toFixed(2);
        return s.replace(/\.?0+$/, '');
      };
      const num = (el) => {
        const v = parseFloat((el.value || '').replace(',', '.'));
        return isNaN(v) ? 0 : v;
      };
      const refreshFai = () => {
        const fai = num(netEl) + num(honoEl);
        faiEl.value = fai > 0 ? fmt(fai) : '';
      };

      // Saisie des honoraires (€) : recalcule le % si net > 0
      honoEl.addEventListener('input', () => {
        const net = num(netEl);
        if (net > 0) {
          const pct = (num(honoEl) / net) * 100;
          if (pct > 0) pctEl.value = fmt(pct);
        }
        refreshFai();
      });
      // Saisie du % : recalcule les honoraires (€) si net > 0
      pctEl.addEventListener('input', () => {
        const net = num(netEl);
        if (net > 0) {
          const hono = (net * num(pctEl)) / 100;
          if (hono > 0) honoEl.value = fmt(hono);
        }
        refreshFai();
      });
      // Saisie du net : ajuste l'autre (priorité au % s'il est > 0)
      netEl.addEventListener('input', () => {
        const net = num(netEl);
        if (net > 0) {
          if (num(pctEl) > 0) {
            const hono = (net * num(pctEl)) / 100;
            if (hono > 0) honoEl.value = fmt(hono);
          } else if (num(honoEl) > 0) {
            const pct = (num(honoEl) / net) * 100;
            if (pct > 0) pctEl.value = fmt(pct);
          }
        }
        refreshFai();
      });

      // Toggle Acquéreur / Vendeur (exclusif) — sauve les 2 flags
      document.querySelectorAll('.v2-icon-radios[data-target="vente"][data-field="honoraires_payeur"]').forEach(group => {
        group.querySelectorAll('.v2-icon-radio').forEach(btn => {
          btn.addEventListener('click', async () => {
            if (btn.classList.contains('is-active')) return;
            group.querySelectorAll('.v2-icon-radio').forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            const value = btn.dataset.value || 'acquereur';
            // Sauve les 2 flags exclusifs
            await saveAnnonce('honoraires_charge_acquereur', value === 'acquereur' ? '1' : '0');
            await saveAnnonce('honoraires_charge_vendeur',   value === 'vendeur'   ? '1' : '0');
          });
        });
      });

    })();

    // ══════════════════════════════════════════════════════════════
    // Simulation rentabilité (vente) — INDÉPENDANTE de la cascade vente
    // Non persisté en BDD, persistance localStorage par bien.
    // ══════════════════════════════════════════════════════════════
    (function bindRentaSim() {
      const rentaSim  = document.querySelector('.v2-renta-sim');
      const loyerEl   = document.getElementById('v2-renta-loyer');
      const chargesEl = document.getElementById('v2-renta-charges');
      const bruteEl   = document.getElementById('v2-renta-brute');
      const netteEl   = document.getElementById('v2-renta-nette');
      const faiEl     = document.getElementById('v2-vente-fai');
      if (!rentaSim || !loyerEl || !chargesEl || !bruteEl || !netteEl) return;

      const bienId = rentaSim.dataset.bienId || '0';
      const lsKey  = 'renta_sim_bien_' + bienId;
      try {
        const saved = JSON.parse(localStorage.getItem(lsKey) || '{}');
        if (saved.loyer   != null && saved.loyer   !== '') loyerEl.value   = saved.loyer;
        if (saved.charges != null && saved.charges !== '') chargesEl.value = saved.charges;
      } catch (_) {}

      const fmtPct = (p) => (isFinite(p) && p !== 0) ? p.toFixed(2).replace(/\.?0+$/, '') + ' %' : '—';
      const numOf  = (s) => parseFloat(String(s || '').replace(/\s/g, '').replace(',', '.')) || 0;
      const recompute = () => {
        const loyer   = numOf(loyerEl.value);
        const charges = numOf(chargesEl.value);
        const fai     = faiEl ? numOf(faiEl.value) : 0;
        let brute = 0, nette = 0;
        if (fai > 0 && loyer > 0) {
          brute = (loyer * 12 / fai) * 100;
          nette = ((loyer - charges) * 12 / fai) * 100;
        }
        bruteEl.textContent = fmtPct(brute);
        netteEl.textContent = fmtPct(nette);
        try { localStorage.setItem(lsKey, JSON.stringify({ loyer: loyerEl.value, charges: chargesEl.value })); } catch (_) {}
      };
      // Multi-events pour couvrir tous les navigateurs / type=number
      ['input', 'keyup', 'change', 'blur'].forEach(evt => {
        loyerEl.addEventListener(evt,   recompute);
        chargesEl.addEventListener(evt, recompute);
      });
      // Recalcule aussi quand les champs vente changent (impact Prix FAI)
      ['v2-vente-net', 'v2-vente-hono', 'v2-vente-pct'].forEach(id => {
        const el = document.getElementById(id);
        if (el) ['input', 'change', 'blur'].forEach(evt => el.addEventListener(evt, recompute));
      });
      recompute();
    })();

    // ══════════════════════════════════════════════════════════════
    // Card 3 Annonce — Bouton IA « Générer l'annonce complète »
    // + persistance session de l'orientation (ton/cible/mots-clés/notes)
    // ══════════════════════════════════════════════════════════════
    bindAnnonceIAGenerate(data);

    // Card 2 Photos : toggle sélection N:N
    const photosStatus = document.getElementById('v2-annonce-photos-status');
    const photosCount  = document.getElementById('v2-annonce-photos-count');
    function setPhotoStatus(kind, msg) {
      if (!photosStatus) return;
      photosStatus.className = 'v2-annonce-photos-status ' + (kind || '');
      photosStatus.textContent = msg || '';
    }

    // ── Re-render sans reload : réorganise les tiles photos selon l'ordre
    //    fourni (ids sélectionnés dans leur ordre annonce), met à jour les
    //    badges numéros + PRINCIPALE + compteur + disponible[limite atteinte]
    function rerenderAnnoncePhotosGrid(selectedIdsOrdered) {
      const MAX = 7;
      const selectedIds = (selectedIdsOrdered || []).map(Number);
      const selSet = new Set(selectedIds);

      // 1. Index des tiles par photoId (toutes sections confondues)
      const tilesById = new Map();
      document.querySelectorAll('.v2-annonce-photo-tile').forEach(t => {
        const id = parseInt(t.dataset.photoId, 10) || 0;
        if (id) tilesById.set(id, t);
      });
      if (tilesById.size === 0) return;

      // 2. Récupère (ou crée) les containers sections
      const cardBody = document.querySelector('section[aria-label="Photos de l\'annonce"] .v2-card-body');
      if (!cardBody) return;

      function ensureSection(kind, labelHTML) {
        let label = cardBody.querySelector('.v2-annonce-photos-section-label.' + kind);
        let grid  = cardBody.querySelector('#v2-annonce-photos-grid-' + kind);
        if (!grid) {
          label = document.createElement('div');
          label.className = 'v2-annonce-photos-section-label ' + kind;
          label.innerHTML = labelHTML;
          grid = document.createElement('div');
          grid.className = 'v2-annonce-photos-grid';
          grid.id = 'v2-annonce-photos-grid-' + kind;
          // Insère avant la status zone (ou à la fin)
          const status = document.getElementById('v2-annonce-photos-status');
          if (status) {
            cardBody.insertBefore(label, status);
            cardBody.insertBefore(grid, status);
          } else {
            cardBody.appendChild(label);
            cardBody.appendChild(grid);
          }
        } else if (label) {
          label.innerHTML = labelHTML;
        }
        return { label, grid };
      }

      const full = selectedIds.length >= MAX;
      const labelAvail = '📂 Disponibles <small>(cliquez pour ajouter'
        + (full ? ' — limite atteinte' : '') + ')</small>';
      const selSect = selectedIds.length > 0
        ? ensureSection('selected',  '📌 Sélectionnées (glissez pour réordonner · 1ère = principale)')
        : null;
      const availSect = ensureSection('available', labelAvail);

      // 3. Déplace / met à jour chaque tile
      selectedIds.forEach((pid, i) => {
        const t = tilesById.get(pid);
        if (!t || !selSect) return;
        selSect.grid.appendChild(t);
        t.classList.add('is-selected');
        t.setAttribute('draggable', 'true');
        t.dataset.rank = String(i);
        // Badge ordre
        let order = t.querySelector('.v2-annonce-photo-order');
        if (!order) {
          order = document.createElement('span');
          order.className = 'v2-annonce-photo-order';
          t.appendChild(order);
        }
        order.textContent = String(i + 1);
        // Badge PRINCIPALE (sur la 1ère uniquement)
        let main = t.querySelector('.v2-annonce-photo-main');
        if (i === 0) {
          if (!main) {
            main = document.createElement('span');
            main.className = 'v2-annonce-photo-main';
            main.textContent = 'PRINCIPALE';
            t.appendChild(main);
          }
        } else if (main) {
          main.remove();
        }
        // Check ✓
        const chk = t.querySelector('.v2-annonce-photo-check');
        if (chk) chk.textContent = '✓';
      });

      // Tiles non sélectionnées : nettoyage + section Disponibles
      tilesById.forEach((t, id) => {
        if (selSet.has(id)) return;
        availSect.grid.appendChild(t);
        t.classList.remove('is-selected');
        t.removeAttribute('draggable');
        delete t.dataset.rank;
        const order = t.querySelector('.v2-annonce-photo-order');
        if (order) order.remove();
        const main = t.querySelector('.v2-annonce-photo-main');
        if (main) main.remove();
        const chk = t.querySelector('.v2-annonce-photo-check');
        if (chk) chk.textContent = '+';
      });

      // 4. Retirer les sections vides (label orphelin)
      if (selectedIds.length === 0 && selSect) {
        selSect.label.remove();
        selSect.grid.remove();
      }

      // 5. Compteur top
      const counter = document.getElementById('v2-annonce-photos-counter');
      const counterN = document.getElementById('v2-annonce-photos-counter-n');
      if (counterN) counterN.textContent = String(selectedIds.length);
      if (counter) counter.classList.toggle('is-full', full);

      // 6. Compteur card label
      if (photosCount) photosCount.textContent = selectedIds.length;
    }

    // ── Bulk all / none : re-render JS, pas de reload ──
    async function bulkPhotos(action) {
      if (!data.annonceId) return;
      const label = action === 'all' ? 'Récupération depuis le bien' : 'Désélection';
      setPhotoStatus('', '⏳ ' + label + ' en cours…');
      const fd = new FormData();
      fd.append('id_annonce', data.annonceId);
      fd.append('action', action);
      fd.append('csrf_token', csrf);
      try {
        const r = await fetch(data.annoncePhotosBulkEndpoint || '/api/annonce_photos_bulk.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur');
        rerenderAnnoncePhotosGrid(j.selected_ids || []);
        setPhotoStatus('ok', '✅ ' + j.count + ' photo(s) dans l\'annonce');
        setTimeout(() => setPhotoStatus('', ''), 1500);
      } catch (e) {
        setPhotoStatus('err', '❌ ' + e.message);
      }
    }
    document.getElementById('v2-annonce-photos-all')?.addEventListener('click', () => bulkPhotos('all'));
    document.getElementById('v2-annonce-photos-none')?.addEventListener('click', () => bulkPhotos('none'));

    // ── Drag & drop réordonnement des photos sélectionnées ──
    bindAnnoncePhotosReorder(data, csrf, setPhotoStatus);

    // ═══ Card 2 Encadrement des loyers ═══
    bindEncadrementCard(data, csrf);
    bindCplSection(data, csrf);

    // ═══ Card 3 Annonce : compteurs de caractères ═══
    document.querySelectorAll('.v2-char-count').forEach(counter => {
      const targetName = counter.dataset.target;
      const min = parseInt(counter.dataset.min, 10) || 0;
      const optimal = parseInt(counter.dataset.optimal, 10) || 0;
      const target = document.querySelector('[name="' + targetName + '"]');
      if (!target) return;
      const update = () => {
        const n = (target.value || '').length;
        if (optimal) {
          counter.textContent = n + ' / ' + optimal + ' car.';
          counter.classList.toggle('is-ok',   n > 0 && n <= optimal);
          counter.classList.toggle('is-warn', n > optimal);
        } else if (min) {
          counter.textContent = n + ' / ' + min + '+ car.';
          counter.classList.toggle('is-ok',   n >= min);
          counter.classList.toggle('is-warn', n > 0 && n < min);
        } else {
          counter.textContent = n + ' car.';
        }
      };
      target.addEventListener('input', update);
      update();
    });

    // ═══ Card 5 Diffusion : mini-cards (toggle via click, même pattern que bool-toggles) ═══
    document.querySelectorAll('.v2-chan-card[data-annonce-bool]').forEach(btn => {
      const field = btn.dataset.annonceBool;
      btn.addEventListener('click', () => {
        const isOn = !btn.classList.contains('is-selected');
        btn.classList.toggle('is-selected', isOn);
        saveAnnonce(field, isOn ? '1' : '0');
      });
    });

    // ═══ Card 5 Récap Ubiflow : click → scroll vers champ ou redirect section ═══
    document.querySelectorAll('.v2-ubi-item').forEach(item => {
      const go = () => {
        const field = item.dataset.field;
        const targetSec = item.dataset.v2Section;
        const currentSec = data.section || 'annonce';
        if (!field) return;
        if (targetSec && targetSec !== currentSec) {
          window.location.href = '?edit=' + encodeURIComponent(data.bienId) +
                                 '&section=' + encodeURIComponent(targetSec) +
                                 '&focus=' + encodeURIComponent(field);
          return;
        }
        focusField(field);
      };
      item.addEventListener('click', go);
      item.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
      });
    });

    // ═══ Card 5 Bouton « 🚀 Diffuser maintenant » ═══
    // POST /api/annonce_diffuser.php :
    //   - Vérifie complétude Ubiflow (champs bloquants)
    //   - UPDATE etat_publication = 'diffusee' + date_publication
    //   - Si visible_portails coché : génère XML + ubiflow_deploy FTP
    const diffuseBtn = document.getElementById('v2-diffuse-btn');
    const diffuseStatus = document.getElementById('v2-diffuse-status');
    if (diffuseBtn) {
      diffuseBtn.addEventListener('click', async () => {
        if (diffuseBtn.disabled) return;
        if (!data.annonceId) {
          diffuseStatus.textContent = '❌ Annonce introuvable.';
          diffuseStatus.className = 'v2-form-status err';
          return;
        }
        diffuseBtn.disabled = true;
        const originalLabel = diffuseBtn.innerHTML;
        diffuseBtn.innerHTML = '⏳ Diffusion en cours…';
        diffuseStatus.textContent = '⏳ Vérification + dépôt FTP (peut prendre 10-30 s)…';
        diffuseStatus.className = 'v2-form-status';

        const fd = new FormData();
        fd.append('_annonce_id', data.annonceId);
        fd.append('csrf_token', csrf);

        try {
          const r = await fetch(data.annonceDiffuserEndpoint || '/api/annonce_diffuser.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
          });
          const j = await r.json();
          if (!j.ok) {
            diffuseStatus.textContent = '❌ ' + (j.error || 'Échec');
            diffuseStatus.className = 'v2-form-status err';
            diffuseBtn.disabled = false;
            diffuseBtn.innerHTML = originalLabel;
            return;
          }

          // Résumé par canal
          const parts = [];
          const c = j.canaux || {};
          if (c.maboximmo && c.maboximmo.ok)  parts.push('🏢 MaBoxImmo ✓');
          if (c.site_perso && c.site_perso.ok) parts.push('🌐 Site ✓');
          if (c.portails) {
            if (c.portails.ok) {
              parts.push('📰 Portails ✓'
                + (typeof c.portails.annonces_in_flux === 'number' ? ' (' + c.portails.annonces_in_flux + ' annonces)' : ''));
            } else {
              parts.push('📰 Portails ❌ ' + (c.portails.error || ''));
            }
          }
          diffuseStatus.innerHTML = '✅ Annonce diffusée. ' + parts.join(' · ');
          diffuseStatus.className = 'v2-form-status ok';
          diffuseBtn.innerHTML = '✓ Diffusé — rechargement…';
          setTimeout(() => window.location.reload(), 1800);
        } catch (e) {
          diffuseStatus.textContent = '❌ ' + e.message;
          diffuseStatus.className = 'v2-form-status err';
          diffuseBtn.disabled = false;
          diffuseBtn.innerHTML = originalLabel;
        }
      });
    }

    // (focus-on-load est géré au niveau global de l'init — évite la duplication)

    // ── Toggle photo (click) : re-render JS pur, pas de reload ──
    //    On délègue au containeur pour que les tiles re-déplacées par
    //    rerenderAnnoncePhotosGrid restent cliquables.
    const photosCardBody = document.querySelector('section[aria-label="Photos de l\'annonce"] .v2-card-body');
    if (photosCardBody) {
      photosCardBody.addEventListener('click', async (e) => {
        const tile = e.target.closest('.v2-annonce-photo-tile');
        if (!tile) return;
        if (tile.dataset.dragging === '1') return;
        const id = parseInt(tile.dataset.photoId, 10) || 0;
        if (!id || !data.annonceId) return;

        // Calcule la nouvelle liste d'IDs sélectionnés en fonction de l'état
        const currentSelTiles = Array.from(
          document.querySelectorAll('#v2-annonce-photos-grid-selected .v2-annonce-photo-tile[data-photo-id]')
        );
        const currentIds = currentSelTiles.map(t => parseInt(t.dataset.photoId, 10)).filter(Boolean);
        const isCurrentlySelected = currentIds.includes(id);
        // Optimistic newIds pour détecter limite atteinte côté client aussi
        const newIds = isCurrentlySelected
          ? currentIds.filter(x => x !== id)
          : currentIds.concat([id]);
        if (!isCurrentlySelected && newIds.length > 7) {
          setPhotoStatus('err', '⚠️ Maximum 7 photos atteint. Désélectionnez-en une avant.');
          return;
        }

        tile.style.pointerEvents = 'none';
        setPhotoStatus('', '⏳ Enregistrement…');
        const fd = new FormData();
        fd.append('id_annonce', data.annonceId);
        fd.append('id_biens_photo', id);
        fd.append('csrf_token', csrf);
        try {
          const r = await fetch(data.annoncePhotoToggleEndpoint || '/api/annonce_photo_toggle.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
          });
          const j = await r.json();
          if (!j.ok) {
            if (j.limit_reached) {
              setPhotoStatus('err', '⚠️ ' + j.error);
              return;
            }
            throw new Error(j.error || 'Erreur');
          }
          // Re-render instantané : on reste exactement sur la Card Photos,
          // aucun flash, aucune perte de scroll.
          rerenderAnnoncePhotosGrid(newIds);
          const msg = (j.action === 'added' ? '✅ Ajoutée' : '✅ Retirée')
                    + ' · ' + j.count + ' photo(s) dans l\'annonce';
          setPhotoStatus('ok', msg);
          setTimeout(() => setPhotoStatus('', ''), 1500);
        } catch (err) {
          setPhotoStatus('err', '❌ ' + err.message);
        } finally {
          tile.style.pointerEvents = '';
        }
      });
    }
  }

  // ══════════════════════════════════════════════════════════════════
  // Card 4 Annonce — Drag & Drop pour réordonner les photos sélectionnées
  // Appel api/annonce_photo_reorder.php après drop, met à jour les badges
  // numéros sans reload (PRINCIPALE se déplace si la 1ère change).
  // ══════════════════════════════════════════════════════════════════
  function bindAnnoncePhotosReorder(data, csrf, setStatus) {
    const grid = document.getElementById('v2-annonce-photos-grid-selected');
    if (!grid || !data.annonceId) return;

    const tiles = () => Array.from(grid.querySelectorAll('.v2-annonce-photo-tile[draggable="true"]'));
    let dragEl = null;

    function refreshBadges() {
      tiles().forEach((t, i) => {
        const orderBadge = t.querySelector('.v2-annonce-photo-order');
        if (orderBadge) orderBadge.textContent = String(i + 1);
        let mainBadge = t.querySelector('.v2-annonce-photo-main');
        if (i === 0) {
          if (!mainBadge) {
            mainBadge = document.createElement('span');
            mainBadge.className = 'v2-annonce-photo-main';
            mainBadge.textContent = 'PRINCIPALE';
            t.appendChild(mainBadge);
          }
        } else if (mainBadge) {
          mainBadge.remove();
        }
        t.dataset.rank = String(i);
      });
    }

    async function persistOrder() {
      const ids = tiles().map(t => parseInt(t.dataset.photoId, 10)).filter(Boolean);
      if (!ids.length) return;
      setStatus('', '⏳ Réordonnement…');
      const fd = new FormData();
      fd.append('id_annonce', data.annonceId);
      fd.append('csrf_token', csrf);
      ids.forEach(id => fd.append('ordered_ids[]', id));
      try {
        const endpoint = data.annoncePhotoReorderEndpoint || '/api/annonce_photo_reorder.php';
        const r = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur réordonnement');
        setStatus('ok', '✅ Ordre enregistré');
        setTimeout(() => setStatus('', ''), 1500);
      } catch (e) {
        setStatus('err', '❌ ' + e.message);
      }
    }

    grid.addEventListener('dragstart', (e) => {
      const t = e.target.closest('.v2-annonce-photo-tile[draggable="true"]');
      if (!t) return;
      dragEl = t;
      t.dataset.dragging = '1';
      t.classList.add('is-dragging');
      try { e.dataTransfer.effectAllowed = 'move'; } catch (_) {}
      try { e.dataTransfer.setData('text/plain', t.dataset.photoId || ''); } catch (_) {}
    });

    grid.addEventListener('dragover', (e) => {
      if (!dragEl) return;
      e.preventDefault();
      try { e.dataTransfer.dropEffect = 'move'; } catch (_) {}
      const over = e.target.closest('.v2-annonce-photo-tile[draggable="true"]');
      if (!over || over === dragEl) return;
      const rect = over.getBoundingClientRect();
      const after = (e.clientX - rect.left) > rect.width / 2;
      if (after) {
        over.after(dragEl);
      } else {
        over.before(dragEl);
      }
    });

    grid.addEventListener('drop', (e) => {
      if (!dragEl) return;
      e.preventDefault();
    });

    grid.addEventListener('dragend', () => {
      if (!dragEl) return;
      dragEl.classList.remove('is-dragging');
      refreshBadges();
      persistOrder();
      // Nettoie le flag un peu après pour éviter que le click post-drag ne toggle
      const el = dragEl;
      setTimeout(() => { delete el.dataset.dragging; }, 150);
      dragEl = null;
    });
  }

  // ── Helper : focus + scroll vers un champ par son name/id ──
  function focusField(fieldKey) {
    if (!fieldKey) return;
    // 1. Cherche par id "v2-f-<key>" (préféré, stable pour les inputs classiques)
    let el = document.getElementById('v2-f-' + fieldKey);
    // 2. Fallback : par name (textarea, input, select)
    if (!el) el = document.querySelector('[name="' + fieldKey + '"]');
    // 3. Fallback : icon-radios (type_transaction, id_type_bien, chauffage_type…)
    if (!el) el = document.querySelector('.v2-icon-radios[data-field="' + fieldKey + '"]');
    // 4. Fallback : bool-toggles (balcon, terrasse, ascenseur…)
    if (!el) el = document.querySelector('[data-bool-field="' + fieldKey + '"]');
    // 5. Fallback : canaux diffusion annonce (visible_maboximmo, visible_portails…)
    if (!el) el = document.querySelector('[data-annonce-bool="' + fieldKey + '"]');
    if (!el) return;

    // Si la cible est dans une v2-card, activer cette card dans le carousel
    // pour qu'elle soit au premier plan avant le scroll.
    const targetCard = el.closest('.v2-card');
    const stage = document.getElementById('v2-stage');
    if (targetCard && stage && window.__v2Carousel) {
      const allCards = Array.from(stage.querySelectorAll('.v2-card'));
      const idx = allCards.indexOf(targetCard);
      if (idx >= 0 && idx !== window.__v2Carousel.index) {
        window.__v2Carousel.go(idx);
      }
    }

    try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { /* noop */ }
    const wrap = el.closest('.v2-field, .v2-num-field, .v2-icon-radios, .v2-card, section');
    if (wrap) {
      wrap.classList.add('v2-field-focus-flash');
      setTimeout(() => wrap.classList.remove('v2-field-focus-flash'), 2000);
    }
    try { el.focus({ preventScroll: true }); } catch (e) { try { el.focus(); } catch (_) {} }
  }
  // Expose focusField globalement pour pouvoir l'appeler depuis du HTML inline
  // (ex: clic sur la description preview de la Card Diffusion)
  window.__v2FocusField = focusField;

  // ══════════════════════════════════════════════════════════════════
  // Card 3 Annonce — Génération IA (description / titre / SEO / slug)
  // ══════════════════════════════════════════════════════════════════
  function bindAnnonceIAGenerate(data) {
    const btn    = document.getElementById('v2-ia-generate');
    const status = document.getElementById('v2-ia-generate-status');
    if (!btn) return;

    const annonceId = parseInt(data.annonceId, 10) || 0;
    const bienId    = parseInt(data.bienId,    10) || 0;
    if (!annonceId || !bienId) {
      btn.disabled = true;
      return;
    }

    // ── Orientation : persistance session (propre à ce bien) ──
    const SS_KEY = 'v2IaOrientation_bien_' + bienId;
    const tonEl      = document.getElementById('v2-ia-ton');
    const cibleEl    = document.getElementById('v2-ia-cible');
    const keywordsEl = document.getElementById('v2-ia-keywords');
    const notesEl    = document.getElementById('v2-ia-notes');

    // Restauration depuis sessionStorage (ton/cible/keywords seulement).
    // Les "notes" (reprise_descriptif) vivent maintenant en BDD via data-autosave.
    try {
      const saved = JSON.parse(sessionStorage.getItem(SS_KEY) || '{}');
      if (saved.ton      && tonEl)      tonEl.value      = saved.ton;
      if (saved.cible    && cibleEl)    cibleEl.value    = saved.cible;
      if (saved.keywords && keywordsEl) keywordsEl.value = saved.keywords;
    } catch (_) {}

    function persistOrientation() {
      try {
        sessionStorage.setItem(SS_KEY, JSON.stringify({
          ton:      tonEl ? tonEl.value : '',
          cible:    cibleEl ? cibleEl.value : '',
          keywords: keywordsEl ? keywordsEl.value : '',
        }));
      } catch (_) {}
    }
    [tonEl, cibleEl, keywordsEl].forEach(el => {
      if (!el) return;
      el.addEventListener('change', persistOrientation);
      el.addEventListener('blur',   persistOrientation);
    });

    // ── Helpers d'écriture + autosave ──
    function setField(id, value) {
      const el = document.getElementById(id);
      if (!el) return;
      el.value = (value == null) ? '' : String(value);
      // 'input' : met à jour les compteurs de caractères
      el.dispatchEvent(new Event('input', { bubbles: true }));
      // Persistance : appel direct + dispatch change (double ceinture) pour
      // garantir que le champ est sauvé même si le listener change a été
      // attaché avant ou après setField.
      if (el.name && typeof window.__v2SaveAnnonceField === 'function') {
        window.__v2SaveAnnonceField(el.name, el.value);
      }
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setStatus(kind, msg) {
      if (!status) return;
      status.className = 'v2-form-status' + (kind ? ' ' + kind : '');
      status.textContent = msg || '';
    }

    // ── Lecture des champs bien côté bien_detail_v2 (descriptif) ──
    function formVal(name) {
      const el = document.querySelector('[name="' + name + '"]');
      return el ? el.value : '';
    }
    function formChecked(name) {
      const el = document.querySelector('[name="' + name + '"]');
      if (!el) return 0;
      if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? 1 : 0;
      return el.value ? 1 : 0;
    }

    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const oldLabel = btn.innerHTML;
      btn.innerHTML = '⏳ L\'IA rédige…';
      setStatus('', '⏳ Analyse + rédaction en cours (15-30 s)…');

      // Type de transaction (depuis la Card 1 de l'annonce)
      const transEl = document.querySelector('.v2-icon-radios[data-target="annonce"][data-field="type_transaction"] .v2-icon-radio.is-active');
      const typeTransaction = transEl ? (transEl.dataset.value || '') : '';

      const payload = {
        bien_id:     bienId,
        // Orientation session
        orientation_ton:      tonEl ? tonEl.value : '',
        orientation_cible:    cibleEl ? cibleEl.value : '',
        orientation_keywords: keywordsEl ? keywordsEl.value : '',
        description_brute:    (notesEl ? notesEl.value : '') || formVal('description'),
        // Champs factuels du bien (le serveur ré-enrichit depuis la BDD si vides)
        type_bien:   '', // serveur lit biens.id_type_bien
        adresse_1:   formVal('adresse_1'),
        code_postal: formVal('code_postal'),
        ville:       formVal('ville'),
        surface:     parseFloat(formVal('surface_habitable')) || 0,
        nb_pieces:   parseInt(formVal('nb_pieces'),    10) || 0,
        nb_chambres: parseInt(formVal('nb_chambres'),  10) || 0,
        nb_sdb:      parseInt(formVal('nb_salles_bain'),10) || 0,
        etage:       parseInt(formVal('etage'),        10) || 0,
        nb_etages:   parseInt(formVal('nb_niveaux'),   10) || 0,
        loyer_hc:    typeTransaction === 'location' ? (parseFloat(formVal('loyer_de_base')) || 0) : 0,
        charges:     0,
        prix_vente:  typeTransaction === 'vente' ? (parseFloat(formVal('prix')) || 0) : 0,
        dpe_classe:  formVal('dpe_classe'),
        ges_classe:  formVal('ges_classe'),
        meuble:      formChecked('loyer_meuble'),
        ascenseur:   formChecked('ascenseur'),
        parking:     formChecked('garage') || formChecked('parking'),
        balcon:      formChecked('balcon'),
        terrasse:    formChecked('terrasse'),
        cave:        formChecked('cave'),
        digicode:    formChecked('digicode'),
        fibre:       formChecked('fibre'),
      };

      try {
        const endpoint = data.aiGenerateEndpoint || '/api/bien_ai_generate.php';
        const r = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': data.aiCsrfToken || data.csrfToken || '',
          },
          credentials: 'same-origin',
          body: JSON.stringify(payload),
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur IA');

        // ── Remplissage (écrasement volontaire — regénération complète) ──
        if (j.description)      setField('v2-f-description', j.description);
        if (Array.isArray(j.points_forts) && j.points_forts.length) {
          setField('v2-f-points_forts', j.points_forts.join('\n'));
        }
        if (j.accroche)         setField('v2-f-accroche_commerciale', j.accroche);
        if (j.titre)            setField('v2-f-titre', j.titre);
        if (j.meta_title)       setField('v2-f-meta_title', j.meta_title);
        if (j.meta_description) setField('v2-f-meta_description', j.meta_description);
        if (j.mots_cles) {
          const mc = Array.isArray(j.mots_cles) ? j.mots_cles.join(', ') : j.mots_cles;
          setField('v2-f-mots_cles', mc);
        }
        if (j.slug)             setField('v2-f-slug', j.slug);

        // Résumé court : première phrase de la description si champ vide
        const resumeEl = document.getElementById('v2-f-resume_court');
        if (resumeEl && !resumeEl.value.trim() && j.description) {
          const firstSentence = (j.description.match(/^[^.!?]+[.!?]/) || [j.description.slice(0, 140)])[0].trim();
          setField('v2-f-resume_court', firstSentence.slice(0, 255));
        }

        const len = (j.description || '').length;
        setStatus('ok', '✅ Annonce générée — ' + len + ' car. de description');
        setTimeout(() => setStatus('', ''), 4000);
      } catch (e) {
        setStatus('err', '❌ ' + (e.message || 'Erreur'));
      } finally {
        btn.disabled = false;
        btn.innerHTML = '✨ Regénérer l\'annonce complète';
      }
    });
  }

  // ── Helpers Encadrement des loyers ──
  function anneeToEpoque(annee) {
    annee = parseInt(annee, 10) || 0;
    if (annee <= 0) return '';
    if (annee <  1946) return 'avant_1946';
    if (annee <= 1970) return '1946_1970';
    if (annee <= 1990) return '1971_1990';
    if (annee <= 2005) return '1991_2005';
    return 'apres_2005';
  }

  function bindEncadrementCard(data, csrf) {
    const banner = document.getElementById('v2-enc-banner');
    if (!banner) return;

    // Enrichir le contexte à partir des inputs actuels (valeurs à jour)
    function currentContext() {
      const ctx = Object.assign({}, data.bienEncContext || {});
      const cp = document.querySelector('[name="code_postal"]');
      const an = document.querySelector('[name="annee_construction"]');
      const nb = document.querySelector('[name="nb_pieces"]');
      const sf = document.querySelector('[name="surface_habitable"]');
      const mb = document.querySelector('[name="loyer_meuble"]');
      if (cp) ctx.code_postal = cp.value;
      if (an) ctx.annee_construction = parseInt(an.value, 10) || ctx.annee_construction;
      if (nb) ctx.nb_pieces = parseInt(nb.value, 10) || ctx.nb_pieces;
      if (sf) ctx.surface = parseFloat(sf.value) || ctx.surface;
      if (mb && (mb.type === 'hidden' || mb.type === 'checkbox')) ctx.meuble = mb.value === '1' || mb.checked ? 1 : 0;
      return ctx;
    }

    function setField(name, val, { onlyIfEmpty = false } = {}) {
      const el = document.querySelector('[name="' + name + '"]');
      if (!el) return;
      if (onlyIfEmpty && el.value && el.dataset.autoEnc !== '1') return;
      el.value = val;
      el.dataset.autoEnc = '1';
      // Déclenche l'autosave
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async function fetchEncadrement() {
      const ctx = currentContext();
      const cp    = (ctx.code_postal || '').trim();
      const epoque = anneeToEpoque(ctx.annee_construction);
      const pieces = Math.min(parseInt(ctx.nb_pieces, 10) || 0, 4);
      const meuble = ctx.meuble ? 1 : 0;
      if (!cp || !epoque || pieces < 1) { banner.hidden = true; return; }
      // Restreint aux CP Lyon + Villeurbanne
      if (!/^690[0-9]{2}$/.test(cp) && cp !== '69100') { banner.hidden = true; return; }

      try {
        const url = (data.encadrementEndpoint || '/api/encadrement_loyers.php')
                  + '?code_postal=' + encodeURIComponent(cp)
                  + '&nb_pieces='   + encodeURIComponent(pieces)
                  + '&epoque='      + encodeURIComponent(epoque)
                  + '&meuble='      + encodeURIComponent(meuble);
        const r = await fetch(url, { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) { banner.hidden = true; return; }

        // Tarifs (biens) — auto-remplis si champ vide ou déjà auto
        setField('enc_loyer_ref', j.loyer_reference,         { onlyIfEmpty: true });
        setField('enc_loyer_max', j.loyer_reference_majore,  { onlyIfEmpty: true });
        setField('enc_loyer_min', j.loyer_reference_minore,  { onlyIfEmpty: true });

        // Loyers €/mois (annonce) — surface × tarif
        const surf = parseFloat(ctx.surface) || 0;
        if (surf > 0) {
          setField('loyer_de_base',          (surf * j.loyer_reference).toFixed(2),        { onlyIfEmpty: true });
          setField('loyer_reference_majore', (surf * j.loyer_reference_majore).toFixed(2), { onlyIfEmpty: true });
        }

        // Zone label
        setField('enc_zone', j.zone_label || ('Zone ' + (j.zone || '')), { onlyIfEmpty: true });

        // Coche auto "zone encadrée"
        const zeBtn = document.querySelector('[data-annonce-bool="zone_encadrement_loyer"]');
        if (zeBtn && !zeBtn.classList.contains('is-active')) {
          zeBtn.click();
        }

        // Bannière
        banner.hidden = false;
        banner.innerHTML =
          '<strong>📋 Zone encadrée — ' + escapeHtml(j.zone_label || '') + '</strong>'
          + ' · Réf : <strong>' + j.loyer_reference + '</strong> €/m²'
          + ' · Majoré : <strong>' + j.loyer_reference_majore + '</strong> €/m²'
          + ' · Minoré : <strong>' + j.loyer_reference_minore + '</strong> €/m²';
      } catch (e) {
        banner.hidden = true;
      }
    }

    // Re-fetch au bouton manuel + à l'init
    const refreshBtn = document.getElementById('v2-enc-refresh');
    if (refreshBtn) refreshBtn.addEventListener('click', fetchEncadrement);
    fetchEncadrement();
  }

  function bindCplSection(data, csrf) {
    const wrap = document.getElementById('v2-cpl-lignes');
    if (!wrap) return;
    const addBtn = document.getElementById('v2-cpl-add');
    const totalEl = document.getElementById('v2-cpl-total');
    const statusEl = document.getElementById('v2-cpl-status');
    const annonceId = parseInt(wrap.dataset.annonceId, 10) || 0;

    function setStatus(kind, msg) {
      if (!statusEl) return;
      statusEl.className = 'v2-cpl-status ' + (kind || '');
      statusEl.textContent = msg || '';
      if (kind === 'ok') setTimeout(() => { statusEl.textContent = ''; statusEl.className = 'v2-cpl-status'; }, 1800);
    }

    function fmt(n) {
      const v = (typeof n === 'number' ? n : parseFloat(n)) || 0;
      return v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function buildRow(id, libelle = '', montant = '') {
      const row = document.createElement('div');
      row.className = 'v2-cpl-row';
      row.dataset.cplId = id;
      row.innerHTML = ''
        + '<input type="text" class="v2-input v2-cpl-libelle" value="' + escapeHtml(libelle) + '" placeholder="Ex : vue dégagée sur parc">'
        + '<input type="number" step="0.01" min="0" class="v2-num-input v2-cpl-montant" value="' + escapeHtml(String(montant)) + '" placeholder="€">'
        + '<button type="button" class="v2-cpl-del" title="Supprimer">✕</button>';
      bindRow(row);
      return row;
    }

    async function upsertRow(row, field, value) {
      const id = parseInt(row.dataset.cplId, 10) || 0;
      if (!id || !annonceId) return;
      const fd = new FormData();
      fd.append('id', id);
      fd.append(field, value);
      fd.append('csrf_token', csrf);
      setStatus('', '⏳ Enregistrement…');
      try {
        const r = await fetch(data.cplUpdateEndpoint || '/api/annonce_cpl_update.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur');
        if (totalEl) totalEl.textContent = fmt(j.total);
        // Délègue à applyCalculated : met à jour loyer CC, loyer HC, complément display,
        // sub-label "dont complément : X €" en cascade.
        if (typeof window.__v2ApplyCalculated === 'function') window.__v2ApplyCalculated(j);
        setStatus('ok', '✅');
      } catch (e) {
        setStatus('err', '❌ ' + e.message);
      }
    }

    async function deleteRow(row) {
      const id = parseInt(row.dataset.cplId, 10) || 0;
      if (!id) { row.remove(); return; }
      const fd = new FormData();
      fd.append('id', id);
      fd.append('csrf_token', csrf);
      setStatus('', '⏳ Suppression…');
      try {
        const r = await fetch(data.cplDeleteEndpoint || '/api/annonce_cpl_delete.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur');
        if (totalEl) totalEl.textContent = fmt(j.total);
        if (typeof window.__v2ApplyCalculated === 'function') window.__v2ApplyCalculated(j);
        row.remove();
        setStatus('ok', '✅');
      } catch (e) {
        setStatus('err', '❌ ' + e.message);
      }
    }

    function bindRow(row) {
      const lib = row.querySelector('.v2-cpl-libelle');
      const mnt = row.querySelector('.v2-cpl-montant');
      const del = row.querySelector('.v2-cpl-del');
      lib?.addEventListener('blur',  () => upsertRow(row, 'libelle', lib.value));
      mnt?.addEventListener('blur',  () => upsertRow(row, 'montant', mnt.value));
      mnt?.addEventListener('change',() => upsertRow(row, 'montant', mnt.value));
      del?.addEventListener('click', () => deleteRow(row));
    }
    // Bind les lignes déjà rendues côté PHP
    wrap.querySelectorAll('.v2-cpl-row').forEach(bindRow);

    addBtn?.addEventListener('click', async () => {
      if (!annonceId) return;
      const fd = new FormData();
      fd.append('id_annonce', annonceId);
      fd.append('csrf_token', csrf);
      setStatus('', '⏳ Ajout…');
      try {
        const r = await fetch(data.cplAddEndpoint || '/api/annonce_cpl_add.php', {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur');
        const row = buildRow(j.id);
        wrap.appendChild(row);
        row.querySelector('.v2-cpl-libelle')?.focus();
        setStatus('ok', '✅');
      } catch (e) {
        setStatus('err', '❌ ' + e.message);
      }
    });
  }

  // ── Init ──
  document.addEventListener('DOMContentLoaded', () => {
    const data = window.__v2DocsData || {};
    const section = data.section || 'documents';

    // Focus sur champ demandé via ?focus=xxx (actif pour toutes les sections)
    const params = new URLSearchParams(window.location.search);
    const focusKey = params.get('focus');
    if (focusKey) {
      setTimeout(() => focusField(focusKey), 300);
    }

    if (section === 'annonce') {
      bindAnnonceSection(data);
      // Flow 2026-04-22 : si pas d'annonce active, ouvre le modal de création
      if (data.bienEstActif && !data.annonceId) {
        setTimeout(() => openAnnonceCreateModal(data), 250);
      }
    }

    if (section === 'validation') {
      bindValidationCard(data);
    }

    if (section === 'descriptif') {
      bindDescriptifAutosave(data);

      // Création express du propriétaire détecté dans le DPE
      // "Propriétaire détecté dans DPE" → ouvre le modal de création
      // PRÉ-REMPLI avec les données détectées. L'user peut modifier / vérifier
      // avant de valider (évite aussi les doublons par double-clic puisque
      // la création effective passe par le modal qui a sa propre garde).
      const btnCreate  = document.getElementById('v2-dpe-proprio-create');
      const suggestBox = document.getElementById('v2-dpe-proprio-suggest');
      const statusEl   = document.getElementById('v2-dpe-proprio-status');
      if (btnCreate && suggestBox) {
        btnCreate.addEventListener('click', () => {
          if (typeof window.__v2OpenProprioModal !== 'function') {
            if (statusEl) { statusEl.textContent = '❌ Card Propriétaire non initialisée'; statusEl.className = 'v2-form-status err'; }
            return;
          }
          const pd = JSON.parse(suggestBox.dataset.proprio || '{}');
          window.__v2OpenProprioModal({
            type_tiers:     pd.type || 'personne_physique',
            nom:            pd.nom,
            prenom:         pd.prenom,
            raison_sociale: pd.societe,
            email:          pd.email,
            telephone:      pd.telephone,
            adresse:        pd.adresse,
            code_postal:    pd.cp,
            ville:          pd.ville,
          });
          if (statusEl) { statusEl.textContent = '✏️ Vérifie et valide dans le modal'; statusEl.className = 'v2-form-status'; }
        });
      }

      // ─── Gestion Card Propriétaire (picker + modal create + dissocier) ───
      bindProprioManager(data);
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

          if (action === 'analyze') {
            const id = parseInt(tile.dataset.id, 10) || 0;
            if (id <= 0) return;
            const aiBox = tile.querySelector('[data-photo-ai]');
            if (aiBox) aiBox.innerHTML = '<span class="v2-photo-tile-ai-empty">⏳ Analyse en cours…</span>';
            btn.disabled = true; btn.textContent = '⏳';
            try {
              const fd = new FormData();
              fd.append('id_photo', id);
              fd.append('csrf_token', data.csrfToken || '');
              fd.append('force', '1');
              const analyzeUrl = (data.bienDetailUrl ? data.bienDetailUrl.replace('/bien_detail.php', '') : '') + '/api/bien_photo_analyze.php';
              const r = await fetch(analyzeUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
              const j = await r.json();
              if (!j.ok) throw new Error(j.error || 'Erreur analyse');
              const res = (j.results && j.results[0]) || {};
              renderPhotoAi(tile, res);
              tile.dataset.statut = 'ok';
              btn.disabled = false; btn.textContent = '🤖';
              updateAnalyzeAllCount();
            } catch (err) {
              if (aiBox) aiBox.innerHTML = '<span class="v2-photo-tile-ai-empty" style="color:#dc2626;">❌ ' + err.message + '</span>';
              btn.disabled = false; btn.textContent = '🤖';
            }
          }
        });

        // ── Helpers de rendu (commercial + critique) ──
        function escapeHtml(s) {
          return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
        }
        function renderPhotoAi(tile, res) {
          const aiBox = tile.querySelector('[data-photo-ai]');
          const cat = res.categorie || '';
          const desc = res.description || '';
          if (aiBox) {
            let html = '';
            if (cat)  html += '<span class="v2-photo-tile-ai-cat">🏷️ ' + escapeHtml(cat) + '</span>';
            if (desc) html += '<div class="v2-photo-tile-ai-desc">' + escapeHtml(desc) + '</div>';
            if (!html) html = '<span class="v2-photo-tile-ai-empty">📝 Aucun résultat</span>';
            aiBox.innerHTML = html;
          }
          const cri = res.critique || null;
          if (!cri) return;
          const niveau = (cri.niveau || '').toLowerCase();
          const icon  = ({bon:'🟢', moyen:'🟡', mauvais:'🔴'})[niveau] || '⚪';
          const label = ({bon:'Bonne photo', moyen:'À améliorer', mauvais:'À refaire'})[niveau] || 'Non évaluée';
          const pf = Array.isArray(cri.points_forts)   ? cri.points_forts   : [];
          const pw = Array.isArray(cri.points_faibles) ? cri.points_faibles : [];
          const conseil = cri.conseil || '';
          let html = '<div class="critique-header"><span class="critique-icon">' + icon + '</span>'
                   + '<span class="critique-label">📸 Prise de vue : ' + escapeHtml(label) + '</span></div>';
          if (pf.length) {
            html += '<div class="critique-section critique-forts"><div class="critique-section-title">✅ Points forts</div><ul>';
            pf.forEach(x => { html += '<li>' + escapeHtml(x) + '</li>'; });
            html += '</ul></div>';
          }
          if (pw.length) {
            html += '<div class="critique-section critique-faibles"><div class="critique-section-title">⚠️ À améliorer</div><ul>';
            pw.forEach(x => { html += '<li>' + escapeHtml(x) + '</li>'; });
            html += '</ul></div>';
          }
          if (conseil) {
            html += '<div class="critique-conseil">💡 ' + escapeHtml(conseil) + '</div>';
          }
          let cBox = tile.querySelector('[data-photo-critique]');
          if (!cBox) {
            cBox = document.createElement('div');
            cBox.className = 'v2-photo-tile-critique critique-niveau-' + (niveau || 'na');
            cBox.setAttribute('data-photo-critique', tile.dataset.id || '');
            tile.appendChild(cBox);
          } else {
            cBox.className = 'v2-photo-tile-critique critique-niveau-' + (niveau || 'na');
          }
          cBox.innerHTML = html;
        }
        function updateAnalyzeAllCount() {
          const all = document.querySelectorAll('.v2-photo-tile');
          let pending = 0;
          all.forEach(t => { if ((t.dataset.statut || '') !== 'ok') pending++; });
          // Met à jour TOUTES les copies du bouton (le slider/onglets clone le label).
          document.querySelectorAll('[data-analyze-all-photos] .v2-analyze-all-count').forEach(el => {
            el.textContent = String(pending);
          });
          if (pending === 0) {
            document.querySelectorAll('[data-analyze-all-photos]').forEach(b => { b.disabled = true; });
          }
        }
      }

      // ── Bouton global "Analyser toutes les photos" ──
      // Délégation au niveau body : le bouton est dupliqué par le slider de cards
      // (.v2-stage-tab clone l'innerHTML du .v2-card-label). Sans délégation, seul
      // l'exemplaire original capte le listener et le clic sur la copie ne fait rien.
      // stopPropagation pour ne pas déclencher le switch d'onglet du slider.
      document.body.addEventListener('click', async (e) => {
        const btnAll = e.target.closest('[data-analyze-all-photos]');
        if (!btnAll) return;
        e.preventDefault();
        e.stopPropagation();
        if (btnAll.dataset.busy === '1') return;

        const tiles = Array.from(document.querySelectorAll('.v2-photo-tile'));
        const todo = tiles.filter(t => (t.dataset.statut || '') !== 'ok');
        if (!todo.length) {
          alert('Toutes les photos sont déjà analysées.');
          return;
        }

        // Verrouille toutes les copies du bouton pendant le run
        const allBtns = Array.from(document.querySelectorAll('[data-analyze-all-photos]'));
        const original = btnAll.innerHTML;
        allBtns.forEach(b => { b.dataset.busy = '1'; b.disabled = true; });

        const analyzeUrl = (data.bienDetailUrl ? data.bienDetailUrl.replace('/bien_detail.php', '') : '') + '/api/bien_photo_analyze.php';
        let done = 0, ko = 0;
        for (const tile of todo) {
          const id = parseInt(tile.dataset.id, 10) || 0;
          if (id <= 0) continue;
          const progress = '⏳ Analyse… ' + (done + ko + 1) + '/' + todo.length;
          allBtns.forEach(b => { b.innerHTML = progress; });
          const aiBox = tile.querySelector('[data-photo-ai]');
          if (aiBox) aiBox.innerHTML = '<span class="v2-photo-tile-ai-empty">⏳ Analyse en cours…</span>';
          try {
            const fd = new FormData();
            fd.append('id_photo', id);
            fd.append('csrf_token', data.csrfToken || '');
            fd.append('force', '1');
            console.log('[analyze-all] POST', analyzeUrl, 'id_photo=' + id);
            const r = await fetch(analyzeUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            console.log('[analyze-all] HTTP', r.status, r.statusText);
            const txt = await r.text();
            console.log('[analyze-all] body len=', txt.length, 'preview:', txt.slice(0, 300));
            let j;
            try { j = JSON.parse(txt); } catch (_) {
              throw new Error('Réponse non-JSON (HTTP ' + r.status + ') ' + txt.slice(0, 200));
            }
            if (!j.ok) throw new Error(j.error || 'Erreur API : ' + JSON.stringify(j).slice(0, 200));
            // Vérifie le statut individuel de la photo (j.ok=true même si toutes les analyses échouent)
            const indiv = (j.results && j.results[0]) || {};
            if (indiv.ok === false) {
              throw new Error(indiv.error || 'Photo non analysée (raison non précisée)');
            }
            tile.dataset.statut = 'ok';
            done++;
          } catch (err) {
            ko++;
            console.error('[analyze-all] photo #' + id + ' KO:', err);
            if (aiBox) aiBox.innerHTML = '<span class="v2-photo-tile-ai-empty" style="color:#dc2626;">❌ ' + (err.message || 'Erreur') + '</span>';
          }
        }
        allBtns.forEach(b => { b.innerHTML = original; b.disabled = false; b.dataset.busy = ''; });

        console.log('[analyze-all] terminé : done=' + done + ' ko=' + ko);
        if (done === 0 && ko > 0) {
          alert('Analyse en erreur sur toutes les photos.\nOuvre la console (F12) pour voir le détail.');
        } else if (done > 0) {
          // Recharge pour afficher les blocs critique générés côté serveur (PHP)
          setTimeout(() => window.location.reload(), 600);
        }
      });

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
    const v2Carousel = new V2Carousel(stage, {
      dotsEl: dots,
      tabsEl: tabs,
      prevBtn: prevBtn,
      nextBtn: nextBtn,
      // En descriptif : Card 1 = Propriétaire, Card 2 = Caractéristiques.
      // L'user atterrit direct sur Caractéristiques à l'ouverture d'un bien
      // existant (index 1). Les autres sections partent de la Card 1.
      startIndex: (data.section === 'descriptif') ? 1 : 0,
    });
    window.__v2Carousel = v2Carousel;

    // ══════════════════════════════════════════════════════════════
    // Préservation état V2 : card active + scroll au reload / retour
    // ══════════════════════════════════════════════════════════════
    //   - Clé sessionStorage unique par page+section
    //   - Restauration au chargement (si l'URL n'a pas ?focus=X,
    //     auquel cas on laisse le focus-on-load prendre la main)
    //   - Sauvegarde : sur changement de carte, scroll (debounced),
    //     et beforeunload
    bindV2StatePersistence(v2Carousel);
  });

  function bindV2StatePersistence(carousel) {
    const params = new URLSearchParams(location.search);
    const section = params.get('section') || '';
    const edit    = params.get('edit')    || '';
    const focus   = params.get('focus')   || '';
    const SS_KEY = 'v2_state::' + location.pathname + '::' + section + '::' + edit;

    // Restauration (sauf si ?focus=X explicite : focus-on-load gere lui-meme le scroll)
    if (!focus) {
      try {
        const saved = JSON.parse(sessionStorage.getItem(SS_KEY) || '{}');
        if (typeof saved.cardIdx === 'number' && saved.cardIdx >= 0 && saved.cardIdx < carousel.total) {
          carousel.go(saved.cardIdx);
        }
        if (typeof saved.scrollY === 'number' && saved.scrollY > 0) {
          // Deux RAF pour laisser la card active se positionner avant le scroll
          requestAnimationFrame(() => requestAnimationFrame(() => {
            window.scrollTo(0, saved.scrollY);
          }));
        }
      } catch (_) {}
    }

    // Sauvegarde debounced
    let _ssTimer = null;
    function save() {
      clearTimeout(_ssTimer);
      _ssTimer = setTimeout(() => {
        try {
          sessionStorage.setItem(SS_KEY, JSON.stringify({
            cardIdx: carousel.index,
            scrollY: window.scrollY,
            ts:      Date.now(),
          }));
        } catch (_) {}
      }, 120);
    }

    // Change de carte : monkey-patch go() pour notifier
    const _origGo = carousel.go.bind(carousel);
    carousel.go = function (i) { _origGo(i); save(); };

    // Scroll (passif)
    window.addEventListener('scroll', save, { passive: true });

    // Filet de sécurité : snapshot synchrone avant unload
    window.addEventListener('beforeunload', () => {
      try {
        sessionStorage.setItem(SS_KEY, JSON.stringify({
          cardIdx: carousel.index,
          scrollY: window.scrollY,
          ts:      Date.now(),
        }));
      } catch (_) {}
    });
  }
})();
