/*
 * bien_detail_v2.js
 * Rôle : Carousel cards animées pour bien_detail_v2.php (section Documents)
 *        - Navigation flèches + clavier + swipe tactile + dots
 *        - Mount du DocumentUploader sur la card "Chargement"
 *        - Rendu des listes docs (injectées via window.__v2DocsData)
 * Dépend : /assets/js/document_uploader.js (pour la card 1)
 *          /css/bien_detail_v2.css
 * Date   : 2026-04-19
 */

(function () {
  'use strict';

  const TYPE_LABELS = {
    dpe: 'DPE',
    certificat_surface: 'Certificat de surface',
    mesurage_loi_carrez: 'Mesurage Loi Carrez',
    erp: 'ERP',
    amiante: 'Amiante',
    plomb: 'Plomb',
    termites: 'Termites',
    gaz: 'Gaz',
    electricite: 'Électricité',
    mandat_vente: 'Mandat de vente',
    mandat_gestion: 'Mandat de gestion',
    mandat_location: 'Mandat de location',
    bail: 'Bail',
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

  // ── Carousel ──
  class V2Carousel {
    constructor(stageEl, opts) {
      this.stage = stageEl;
      this.cards = Array.from(stageEl.querySelectorAll('.v2-card'));
      this.total = this.cards.length;
      this.index = opts.startIndex || 0;
      this.dotsEl = opts.dotsEl || null;
      this.prevBtn = opts.prevBtn || null;
      this.nextBtn = opts.nextBtn || null;
      this.onChange = opts.onChange || function () {};

      this._bindEvents();
      this._buildDots();
      this.update();
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

      // Clavier global (limité quand le focus est dans un input)
      document.addEventListener('keydown', (e) => {
        const tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.key === 'ArrowLeft') { this.prev(); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { this.next(); e.preventDefault(); }
      });

      // Swipe tactile sur le stage
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

      // Clic direct sur prev/next card
      this.cards.forEach((c, i) => {
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

      this.onChange(i, this.cards[i]);
    }
  }

  // ── Init ──
  document.addEventListener('DOMContentLoaded', () => {
    const data = window.__v2DocsData || {};
    const docsDiag   = data.docsDiag   || [];
    const docsMandat = data.docsMandat || [];
    const docsAutre  = data.docsAutre  || [];

    // Mise à jour des compteurs dans les labels
    const setCount = (id, n) => {
      const el = document.getElementById(id);
      if (el) el.textContent = n;
    };
    setCount('v2-count-diag', docsDiag.length);
    setCount('v2-count-mandat', docsMandat.length);
    setCount('v2-count-autre', docsAutre.length);

    // Listes
    renderDocsList('v2-list-diag',   docsDiag,   '📊', 'Aucun diagnostic enregistré pour ce bien.');
    renderDocsList('v2-list-mandat', docsMandat, '📋', 'Aucun mandat enregistré pour ce bien.');
    renderDocsList('v2-list-autre',  docsAutre,  '📎', 'Aucun document divers enregistré.');

    // DocumentUploader sur la card "Chargement"
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
          // Recharge la page pour rafraîchir les listes (simple et sûr)
          setTimeout(() => window.location.reload(), 1200);
        },
      });
    }

    // Carousel
    const stage = document.getElementById('v2-stage');
    if (!stage) return;
    const dots = document.getElementById('v2-dots');
    const prevBtn = document.getElementById('v2-prev');
    const nextBtn = document.getElementById('v2-next');
    new V2Carousel(stage, {
      dotsEl: dots,
      prevBtn: prevBtn,
      nextBtn: nextBtn,
      startIndex: 0,
    });
  });
})();
