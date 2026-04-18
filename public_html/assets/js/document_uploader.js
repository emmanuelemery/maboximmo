/**
 * ═══════════════════════════════════════════════════════════════════════
 * DocumentUploader — composant d'upload documents avec choix de type
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Règle site (memory/feedback_upload_documents_types.md) :
 * toute page d'upload doit passer par ce composant pour garantir le bon
 * module d'extraction IA (force_type → bail | mandat | titre | diag | fiche | divers).
 *
 * Usage :
 *   const uploader = new DocumentUploader('my-container', {
 *     context: 'bien',          // 'bien' | 'immeuble'
 *     idContexte: 123,
 *     endpoint: '/api/bien_intake_upload.php',
 *     csrfToken: '...',
 *     availableTypes: ['diag','bail','mandat','titre','fiche','divers'],
 *     defaultType: 'diag',
 *     onSuccess: (data) => { },
 *     onError: (err) => { }
 *   });
 *
 * Dépendance : document_uploader.css (à inclure dans la page).
 */

(function () {
  'use strict';

  const TYPE_DEFS = {
    diag:   { icon: '📊', label: 'Diagnostic',        desc: 'DPE, plomb, amiante, électricité, gaz, termites, ERP, mesurage' },
    bail:   { icon: '📝', label: 'Bail',              desc: 'Habitation, commercial, pro, civil, terrain, parking, meublé' },
    mandat: { icon: '📋', label: 'Mandat',            desc: 'Vente, gestion, location, recherche' },
    titre:  { icon: '🏛️', label: 'Acte / Mutation',  desc: 'Acte de propriété, notification mutation notaire' },
    fiche:  { icon: '🏢', label: 'Fiche commerciale', desc: 'Hektor, Périclès, Apimo, Poliris, Netty, ICI' },
    divers: { icon: '📎', label: 'Divers',            desc: 'Autre document — résumé IA + chat interactif' },
  };

  class DocumentUploader {
    constructor(containerId, options) {
      this.container = typeof containerId === 'string'
        ? document.getElementById(containerId)
        : containerId;
      if (!this.container) {
        console.error('[DocumentUploader] container introuvable :', containerId);
        return;
      }

      this.opts = Object.assign({
        context: 'bien',
        idContexte: null,
        endpoint: '/api/bien_intake_upload.php',
        csrfToken: '',
        availableTypes: ['diag', 'bail', 'mandat', 'titre', 'fiche', 'divers'],
        defaultType: 'diag',
        onSuccess: function () {},
        onError: function () {},
        showValidationTable: true,  // si false : affiche seulement le status, pas le tableau
        applyValueFn: null,         // fonction appelée au Valider pour appliquer chaque champ au form parent
      }, options || {});

      this.selectedType = this.opts.availableTypes.includes(this.opts.defaultType)
        ? this.opts.defaultType
        : this.opts.availableTypes[0];

      this.lastResult = null;
      this.render();
    }

    // ─── Rendu initial ──────────────────────────────────
    render() {
      const root = document.createElement('div');
      root.className = 'doc-uploader';

      // Onglets
      const tabs = document.createElement('div');
      tabs.className = 'doc-uploader-tabs';
      this.opts.availableTypes.forEach(type => {
        const def = TYPE_DEFS[type] || { icon: '📄', label: type };
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'doc-uploader-tab' + (type === this.selectedType ? ' active' : '');
        btn.dataset.type = type;
        btn.innerHTML = `<span>${def.icon}</span><span>${def.label}</span>`;
        btn.title = def.desc;
        btn.addEventListener('click', () => this.selectType(type));
        tabs.appendChild(btn);
      });

      // Hint
      const hint = document.createElement('div');
      hint.className = 'doc-uploader-hint';
      hint.id = this._id('hint');
      hint.innerHTML = this._hintForType(this.selectedType);

      // Dropzone
      const dz = document.createElement('div');
      dz.className = 'doc-uploader-dropzone';
      dz.id = this._id('dropzone');
      dz.innerHTML = this._dropzoneHtml(this.selectedType);

      const fileInput = dz.querySelector('input[type="file"]');
      fileInput.addEventListener('change', e => {
        if (e.target.files.length) this.upload(e.target.files[0]);
      });
      dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragging'); });
      dz.addEventListener('dragleave', () => dz.classList.remove('dragging'));
      dz.addEventListener('drop', e => {
        e.preventDefault();
        dz.classList.remove('dragging');
        if (e.dataTransfer.files.length) {
          fileInput.files = e.dataTransfer.files;
          this.upload(e.dataTransfer.files[0]);
        }
      });

      // Status + results
      const status = document.createElement('div');
      status.className = 'doc-uploader-status';
      status.id = this._id('status');

      const results = document.createElement('div');
      results.className = 'doc-uploader-results';
      results.id = this._id('results');

      // Assemble
      root.appendChild(tabs);
      root.appendChild(hint);
      root.appendChild(dz);
      root.appendChild(status);
      root.appendChild(results);

      // Clear + mount
      this.container.innerHTML = '';
      this.container.appendChild(root);

      this.rootEl    = root;
      this.tabsEl    = tabs;
      this.hintEl    = hint;
      this.dzEl      = dz;
      this.statusEl  = status;
      this.resultsEl = results;
    }

    _id(suffix) {
      return 'du-' + Math.random().toString(36).slice(2, 8) + '-' + suffix;
    }

    _hintForType(type) {
      const def = TYPE_DEFS[type] || { icon: '📄', desc: '' };
      return `<span class="icon">ℹ️</span> Type sélectionné : <strong>${def.icon} ${def.label || type}</strong>`
        + (def.desc ? ` — <em>${def.desc}</em>` : '')
        + `. Choisissez un autre type avant d'uploader si nécessaire.`;
    }

    _dropzoneHtml(type) {
      const def = TYPE_DEFS[type] || { icon: '📄', label: type };
      return `
        <input type="file" accept=".pdf,application/pdf">
        <div class="dz-icon">${def.icon}</div>
        <div class="dz-title">Glissez votre PDF ${def.label} ici ou cliquez</div>
        <div class="dz-subtitle">PDF uniquement — max 20 Mo — analyse IA automatique</div>
      `;
    }

    // ─── Sélection de type ──────────────────────────────
    selectType(type) {
      if (!this.opts.availableTypes.includes(type)) return;
      this.selectedType = type;
      this.tabsEl.querySelectorAll('.doc-uploader-tab').forEach(b => {
        b.classList.toggle('active', b.dataset.type === type);
      });
      this.hintEl.innerHTML = this._hintForType(type);
      this.dzEl.innerHTML = this._dropzoneHtml(type);
      const fileInput = this.dzEl.querySelector('input[type="file"]');
      fileInput.addEventListener('change', e => {
        if (e.target.files.length) this.upload(e.target.files[0]);
      });
    }

    // ─── Upload ────────────────────────────────────────
    async upload(file) {
      if (!file) return;
      this._setStatus('loading', `⏳ Analyse du document en cours (10-30s)…`);
      this.resultsEl.innerHTML = '';

      const fd = new FormData();
      fd.append('fichier', file);
      fd.append('csrf_token', this.opts.csrfToken);
      fd.append('force_type', this.selectedType);
      if (this.opts.context === 'immeuble') {
        fd.append('id_immeuble', this.opts.idContexte || 0);
      } else {
        if (this.opts.idContexte) fd.append('id_bien', this.opts.idContexte);
      }

      try {
        const r = await fetch(this.opts.endpoint, {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Erreur inconnue');
        this.lastResult = j;
        this._renderResult(j);
        if (typeof this.opts.onSuccess === 'function') {
          this.opts.onSuccess(j);
        }
      } catch (e) {
        this._setStatus('error', '❌ ' + e.message);
        if (typeof this.opts.onError === 'function') {
          this.opts.onError(e);
        }
      }
    }

    _setStatus(kind, html) {
      this.statusEl.className = 'doc-uploader-status ' + kind;
      this.statusEl.style.display = 'block';
      this.statusEl.innerHTML = html;
    }

    // ─── Rendu du résultat après upload ─────────────────
    _renderResult(j) {
      const fields = j.fields || {};
      const pdfUrl = j.fichier || j.fichier_relatif || '';
      const pdfName = j.nom || 'Document';
      const docType = j.doc_type || this.selectedType;
      const resume  = j.resume || j.resume_bailleur || '';

      // Badge mode
      let methodBadge = '';
      if (j.method === 'ocr_vision' || j.used_ocr === true) {
        methodBadge = `<span class="doc-uploader-badge ocr" title="PDF scanné — analysé par GPT-4o Vision">📸 OCR Vision</span>`;
      } else if (j.method === 'regex+ia' || j.method === 'text_ia') {
        methodBadge = `<span class="doc-uploader-badge text-ia" title="Texte natif + IA">🧠 Texte + IA</span>`;
      } else if (j.method === 'regex') {
        methodBadge = `<span class="doc-uploader-badge regex" title="Regex locale">⚡ Regex</span>`;
      }

      const pdfBtn = pdfUrl
        ? `<a class="doc-uploader-pdf-link" href="${pdfUrl}" target="_blank" rel="noopener">🔍 Voir le PDF</a>`
        : '';

      const nbFields = Object.keys(fields).length;
      const headMsg = `<strong>✅ ${TYPE_DEFS[this.selectedType]?.label || docType} analysé</strong>`
        + methodBadge
        + ` <span style="color:#64748b;font-size:11px;">· ${this._escape(pdfName)}</span>`;

      // Pour mode "divers" : pas de tableau validation, juste résumé
      if (this.selectedType === 'divers') {
        this._setStatus('success', headMsg);
        this.resultsEl.innerHTML = `
          <div style="margin-top:10px;padding:14px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
              <strong style="color:#14532d;">📎 Document divers enregistré</strong>
              ${pdfBtn}
            </div>
            ${resume ? `<div style="font-size:12px;color:#334155;line-height:1.5;margin-top:6px;">${this._escape(resume).replace(/\n/g,'<br>')}</div>` : ''}
            <div style="font-size:11px;color:#64748b;margin-top:8px;font-style:italic;">💬 Le chat interactif sur ce document arrive à l'Étape 3.</div>
          </div>`;
        return;
      }

      // Tableau validation IA pour les autres types
      this._setStatus('success',
        `<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          ${headMsg}
          <div style="margin-left:auto;">${pdfBtn}</div>
        </div>`
      );

      if (!this.opts.showValidationTable) return;

      if (nbFields === 0) {
        this.resultsEl.innerHTML = `
          <div style="margin-top:10px;padding:12px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:6px;font-size:12px;color:#991b1b;">
            ⚠️ Aucun champ exploitable extrait. Le PDF est peut-être illisible ou le type choisi ne correspond pas au contenu.
          </div>`;
        return;
      }

      // Tableau simple : liste de tous les champs extraits
      const rows = Object.entries(fields).map(([key, value]) => {
        const valStr = typeof value === 'object' ? JSON.stringify(value) : String(value);
        return `<tr>
          <td class="col-champ">${this._escape(key)}</td>
          <td class="col-extracted" style="color:#0369a1;">${this._escape(valStr)}</td>
        </tr>`;
      }).join('');

      this.resultsEl.innerHTML = `
        <div class="doc-uploader-table">
          <div class="doc-uploader-table-head">
            <span>📋 ${nbFields} champ(s) extrait(s) par l'IA</span>
          </div>
          <table>
            <thead><tr><th>Champ</th><th>Valeur extraite</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${typeof this.opts.applyValueFn === 'function' ? `
        <div class="doc-uploader-actions">
          <button type="button" class="doc-uploader-btn btn-cancel" data-action="cancel">Annuler</button>
          <button type="button" class="doc-uploader-btn btn-primary" data-action="apply">
            ✓ Valider &amp; appliquer (${nbFields})
          </button>
        </div>` : ''}
      `;

      // Bind boutons si applyValueFn fourni
      if (typeof this.opts.applyValueFn === 'function') {
        const applyBtn = this.resultsEl.querySelector('[data-action="apply"]');
        const cancelBtn = this.resultsEl.querySelector('[data-action="cancel"]');
        if (applyBtn) applyBtn.addEventListener('click', () => {
          let applied = 0;
          Object.entries(fields).forEach(([k, v]) => {
            if (this.opts.applyValueFn(k, v)) applied++;
          });
          this._setStatus('success', `✅ ${applied} champ(s) appliqué(s) au formulaire.` + (pdfUrl ? ` ${pdfBtn}` : ''));
          this.resultsEl.innerHTML = '';
        });
        if (cancelBtn) cancelBtn.addEventListener('click', () => {
          this.resultsEl.innerHTML = '';
          this.statusEl.style.display = 'none';
        });
      }
    }

    _escape(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ─── API publique ───────────────────────────────────
    setIdContexte(id) { this.opts.idContexte = id; }
    setEndpoint(url)  { this.opts.endpoint = url; }
    destroy()         { this.container.innerHTML = ''; }
    getLastResult()   { return this.lastResult; }
  }

  // Export global
  window.DocumentUploader = DocumentUploader;
})();
