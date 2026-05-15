/* ============================================================
   FLUXBOX — Pile de cartes — JS interaction
   ============================================================ */

(function () {
  'use strict';

  const API = window.FLUXBOX_API_URL || '/api/fluxbox_action.php';
  const CSRF = window.FLUXBOX_CSRF || '';

  const card = document.getElementById('fbx-card');
  const modal = document.getElementById('fbx-modal-adjust');

  if (!card) {
    // Pile vide — pas d'interaction
    setupKeyboardHelp();
    return;
  }

  const carteId = parseInt(card.getAttribute('data-carte-id') || '0', 10);
  if (!carteId) return;

  /* ──────────────────────────────────────────────────────────
     API helper
     ────────────────────────────────────────────────────────── */
  async function callApi(action, extra = {}) {
    const body = Object.assign({ action, carte_id: carteId, csrf: CSRF }, extra);
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF,
          'Accept': 'application/json',
        },
        body: JSON.stringify(body),
        credentials: 'same-origin',
      });
      return await res.json();
    } catch (e) {
      return { ok: false, errors: ['Réseau : ' + e.message] };
    }
  }

  /* ──────────────────────────────────────────────────────────
     Transitions visuelles + rechargement carte suivante
     ────────────────────────────────────────────────────────── */
  function leaveCard(direction) {
    const cls = {
      right: 'fbx-leaving-right',
      left:  'fbx-leaving-left',
      up:    'fbx-leaving-up',
    }[direction] || 'fbx-leaving-right';
    card.classList.add(cls);
    setTimeout(() => { window.location.reload(); }, 280);
  }

  function flash(message, type = 'info') {
    const div = document.createElement('div');
    div.className = 'fbx-flash fbx-flash-' + type;
    div.textContent = message;
    Object.assign(div.style, {
      position: 'fixed', top: '20px', left: '50%', transform: 'translateX(-50%)',
      padding: '12px 24px', borderRadius: '10px',
      background: type === 'error' ? '#fee2e2' : '#dcfce7',
      color: type === 'error' ? '#991b1b' : '#166534',
      fontSize: '14px', fontWeight: '600', boxShadow: '0 6px 20px rgba(0,0,0,0.15)',
      zIndex: '9999',
    });
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 3000);
  }

  /* ──────────────────────────────────────────────────────────
     Actions principales
     ────────────────────────────────────────────────────────── */
  async function actionValidate() {
    const res = await callApi('validate');
    if (res.ok) {
      flash('C\'est fait. Le document est classé.');
      leaveCard('right');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  async function actionLater(until = '+1 day') {
    const res = await callApi('later', { until });
    if (res.ok) {
      flash('Pas de souci, je le remettrai plus tard.');
      leaveCard('left');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  async function actionAdjust(classement) {
    const res = await callApi('adjust', classement);
    if (res.ok) {
      flash('C\'est fait avec vos ajustements.');
      leaveCard('right');
    } else {
      flash('Erreur : ' + (res.errors || ['inconnue']).join(', '), 'error');
    }
  }

  /* ──────────────────────────────────────────────────────────
     Modal Ajuster
     ────────────────────────────────────────────────────────── */
  function openAdjustModal() {
    if (!modal) return;
    if (typeof modal.showModal === 'function') {
      modal.showModal();
    } else {
      modal.setAttribute('open', '');
    }
  }
  function closeAdjustModal() {
    if (!modal) return;
    if (typeof modal.close === 'function') {
      modal.close();
    } else {
      modal.removeAttribute('open');
    }
  }
  function submitAdjustModal() {
    if (!modal) return;
    const form = modal.querySelector('form');
    if (!form) return;
    const data = {};
    form.querySelectorAll('input[name]').forEach((el) => {
      data[el.name] = el.value || '';
    });
    // Validation client minimum N1+N2+N3
    if (!data.n1 || !data.n2 || !data.n3) {
      flash('N1, N2 et N3 sont requis pour valider.', 'error');
      return;
    }
    closeAdjustModal();
    actionAdjust(data);
  }

  /* ──────────────────────────────────────────────────────────
     Branche les 3 boutons + modal
     ────────────────────────────────────────────────────────── */
  card.querySelectorAll('[data-action]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      const action = btn.getAttribute('data-action');
      if (action === 'validate') actionValidate();
      else if (action === 'later') actionLater(btn.getAttribute('data-later') || '+1 day');
      else if (action === 'adjust') openAdjustModal();
    });
  });

  if (modal) {
    modal.querySelector('[data-modal-cancel]')?.addEventListener('click', closeAdjustModal);
    modal.querySelector('[data-modal-confirm]')?.addEventListener('click', submitAdjustModal);
    modal.addEventListener('cancel', (e) => { e.preventDefault(); closeAdjustModal(); });
  }

  /* ──────────────────────────────────────────────────────────
     Raccourcis clavier
     ────────────────────────────────────────────────────────── */
  document.addEventListener('keydown', (e) => {
    // Ne pas intercepter dans les inputs / modal ouverte
    const tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (modal && modal.hasAttribute('open')) return;

    if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      actionValidate();
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      actionLater();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      openAdjustModal();
    } else if (e.key === '?') {
      e.preventDefault();
      showHelp();
    }
  });

  /* ──────────────────────────────────────────────────────────
     Aide raccourcis (popup)
     ────────────────────────────────────────────────────────── */
  function setupKeyboardHelp() {
    document.addEventListener('keydown', (e) => {
      if (e.key === '?' && (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA')) {
        showHelp();
      }
    });
  }

  function showHelp() {
    if (document.querySelector('.fbx-help-popup')) return;
    const pop = document.createElement('div');
    pop.className = 'fbx-help-popup';
    pop.innerHTML = `
      <div style="background:#fff;padding:24px 28px;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-width:380px">
        <h3 style="margin:0 0 14px;color:#243B5C">⌨️ Raccourcis clavier</h3>
        <ul style="list-style:none;margin:0;padding:0;font-size:13px;line-height:2;color:#475569">
          <li><kbd>Espace</kbd> / <kbd>Entrée</kbd> &nbsp; Valider la carte</li>
          <li><kbd>←</kbd> &nbsp; Ouvrir Ajuster</li>
          <li><kbd>→</kbd> &nbsp; Plus tard (+1 jour)</li>
          <li><kbd>Esc</kbd> &nbsp; Fermer cette aide</li>
        </ul>
        <button style="margin-top:14px;width:100%;padding:10px;border:none;background:#243B5C;color:#fff;border-radius:8px;cursor:pointer">Fermer</button>
      </div>
    `;
    Object.assign(pop.style, {
      position: 'fixed', inset: '0', display: 'flex', alignItems: 'center', justifyContent: 'center',
      background: 'rgba(15,23,42,.5)', zIndex: '99999',
    });
    document.body.appendChild(pop);
    const close = () => pop.remove();
    pop.querySelector('button').addEventListener('click', close);
    pop.addEventListener('click', (e) => { if (e.target === pop) close(); });
    document.addEventListener('keydown', function escHandler(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); }
    });
  }

  setupKeyboardHelp();

  /* ──────────────────────────────────────────────────────────
     MOTEUR DE PROGRESSION (validation par chunks, barre live)
     ────────────────────────────────────────────────────────── */
  const CHUNK_SIZE = 25;
  const bulkOverlay = document.getElementById('fbx-bulk-overlay');
  const bulkDialog  = bulkOverlay?.querySelector('.fbx-bulk-dialog');
  const bulkTitle   = document.getElementById('fbx-bulk-title');
  const bulkDone    = document.getElementById('fbx-bulk-done');
  const bulkTotal   = document.getElementById('fbx-bulk-total');
  const bulkPct     = document.getElementById('fbx-bulk-pct');
  const bulkFill    = document.getElementById('fbx-bulk-fill');
  const bulkOk      = document.getElementById('fbx-bulk-ok');
  const bulkErr     = document.getElementById('fbx-bulk-err');
  const bulkChunkInfo = document.getElementById('fbx-bulk-chunk-info');
  const bulkCancel  = document.getElementById('fbx-bulk-cancel');
  const bulkClose   = document.getElementById('fbx-bulk-close');
  let bulkAbort = false;

  function bulkOpen(title, total) {
    if (!bulkOverlay) return;
    bulkAbort = false;
    bulkDialog?.classList.remove('is-done');
    bulkTitle.textContent = title;
    bulkDone.textContent = '0';
    bulkTotal.textContent = String(total);
    bulkPct.textContent = '0';
    bulkFill.style.width = '0%';
    bulkOk.textContent = '0';
    bulkErr.textContent = '0';
    bulkChunkInfo.textContent = `Lot 0 / ${Math.ceil(total / CHUNK_SIZE)}`;
    bulkCancel.style.display = '';
    bulkClose.style.display  = 'none';
    bulkOverlay.classList.add('is-open');
    bulkOverlay.setAttribute('aria-hidden', 'false');
  }
  function bulkUpdate({ done, total, ok, err, chunk, chunks, statusText }) {
    if (!bulkOverlay) return;
    if (typeof done   === 'number') bulkDone.textContent = done;
    if (typeof total  === 'number') bulkTotal.textContent = total;
    if (typeof ok     === 'number') bulkOk.textContent = ok;
    if (typeof err    === 'number') bulkErr.textContent = err;
    if (typeof chunk  === 'number' && typeof chunks === 'number')
      bulkChunkInfo.textContent = `Lot ${chunk} / ${chunks}`;
    const t = parseInt(bulkTotal.textContent, 10) || 1;
    const d = parseInt(bulkDone.textContent, 10) || 0;
    const pct = Math.min(100, Math.round((d / t) * 100));
    bulkPct.textContent = pct;
    bulkFill.style.width = pct + '%';
    if (statusText) bulkTitle.textContent = statusText;
  }
  function bulkFinish(title) {
    if (!bulkOverlay) return;
    bulkTitle.textContent = title;
    bulkDialog?.classList.add('is-done');
    bulkCancel.style.display = 'none';
    bulkClose.style.display  = '';
  }
  function bulkClosePopup() {
    if (!bulkOverlay) return;
    bulkOverlay.classList.remove('is-open');
    bulkOverlay.setAttribute('aria-hidden', 'true');
  }
  bulkCancel?.addEventListener('click', () => {
    bulkAbort = true;
    bulkTitle.textContent = '⏹ Annulation en cours…';
  });
  bulkClose?.addEventListener('click', () => {
    bulkClosePopup();
    window.location.reload();
  });

  /**
   * Traite une liste d'IDs par chunks via bulk_validate carte_ids.
   * @param {number[]} ids
   * @param {string} title titre affiché dans l'overlay
   */
  async function bulkValidateIds(ids, title = '⏳ Validation en masse…') {
    if (!ids || ids.length === 0) {
      flash('Aucune carte à valider.', 'error');
      return;
    }
    const total = ids.length;
    const chunks = Math.ceil(total / CHUNK_SIZE);
    let done = 0, ok = 0, err = 0;
    bulkOpen(title, total);
    for (let i = 0; i < chunks; i++) {
      if (bulkAbort) {
        bulkFinish(`⏹ Annulé — ${ok} validées avant arrêt`);
        return;
      }
      const slice = ids.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE);
      try {
        const r = await callApi('bulk_validate', { carte_ids: slice });
        if (r.ok) {
          const d = r.data || {};
          ok  += (d.validated || 0);
          err += (d.failed    || 0);
        } else {
          err += slice.length;
        }
      } catch (e) {
        err += slice.length;
      }
      done += slice.length;
      bulkUpdate({ done, ok, err, chunk: i + 1, chunks });
    }
    bulkFinish(`✅ Terminé — ${ok} validées · ${err} erreurs`);
  }

  /* ──────────────────────────────────────────────────────────
     SÉLECTION MULTIPLE (batch list)
     ────────────────────────────────────────────────────────── */
  const batch = document.getElementById('fbx-batch');
  if (batch) {
    const list      = document.getElementById('fbx-batch-list');
    const cbAll     = document.getElementById('fbx-batch-all');
    const cbIaReady = document.getElementById('fbx-batch-ia-ready');
    const counter   = document.getElementById('fbx-batch-selected');
    const toolbar   = document.getElementById('fbx-batch-toolbar');
    let lastCheckedIndex = -1;

    function getCheckboxes() {
      return Array.from(list.querySelectorAll('.fbx-batch-cb'));
    }
    const counterTop = document.getElementById('fbx-batch-selected-top');
    function updateUI() {
      const cbs = getCheckboxes();
      const selected = cbs.filter(c => c.checked);
      counter.textContent = selected.length;
      if (counterTop) counterTop.textContent = selected.length;
      toolbar.classList.toggle('is-hidden', selected.length === 0);
      // Marquer visuellement les lignes cochées
      cbs.forEach(cb => {
        const li = cb.closest('.fbx-batch-item');
        if (li) li.classList.toggle('is-checked', cb.checked);
      });
      // Sync de la checkbox "Tout cocher"
      if (cbAll) {
        cbAll.checked = selected.length > 0 && selected.length === cbs.length;
        cbAll.indeterminate = selected.length > 0 && selected.length < cbs.length;
      }
    }

    // Tout cocher / décocher
    cbAll?.addEventListener('change', () => {
      const cbs = getCheckboxes();
      cbs.forEach(c => { c.checked = cbAll.checked; });
      updateUI();
    });

    // Cocher uniquement les IA-prêtes
    cbIaReady?.addEventListener('click', () => {
      const cbs = getCheckboxes();
      cbs.forEach(c => {
        const li = c.closest('.fbx-batch-item');
        const conf = parseFloat(li?.dataset.confiance || '0');
        if (conf >= 85) c.checked = true;
      });
      updateUI();
    });

    // Click sur ligne / checkbox (avec support Shift+click pour plage)
    list.addEventListener('click', (e) => {
      const cb = e.target.closest('.fbx-batch-cb');
      if (!cb) return;
      const cbs = getCheckboxes();
      const idx = cbs.indexOf(cb);
      if (e.shiftKey && lastCheckedIndex >= 0 && idx !== lastCheckedIndex) {
        const [start, end] = idx > lastCheckedIndex ? [lastCheckedIndex, idx] : [idx, lastCheckedIndex];
        const targetState = cb.checked;
        for (let i = start; i <= end; i++) cbs[i].checked = targetState;
      }
      lastCheckedIndex = idx;
      updateUI();
    });

    // Actions toolbar (HAUT + BAS) : valider / plus tard la sélection
    batch.querySelectorAll('[data-batch-action]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const action = btn.getAttribute('data-batch-action');
        const ids = getCheckboxes().filter(c => c.checked).map(c => parseInt(c.value, 10));
        if (ids.length === 0) return;
        const confirmMsg = action === 'validate'
          ? `Valider ${ids.length} carte${ids.length>1?'s':''} ?`
          : `Reporter ${ids.length} carte${ids.length>1?'s':''} à plus tard ?`;
        if (!confirm(confirmMsg)) return;

        if (action === 'validate') {
          await bulkValidateIds(ids, `⏳ Validation de ${ids.length} carte${ids.length>1?'s':''}…`);
        } else if (action === 'later') {
          // "Plus tard" en chunks aussi (boucle séquentielle dans l'overlay)
          await bulkLaterIds(ids);
        }
      });
    });

    // Reporter une liste à demain (avec progression)
    async function bulkLaterIds(ids) {
      const total = ids.length;
      bulkOpen(`⏳ Report de ${total} carte${total>1?'s':''}…`, total);
      let done = 0, ok = 0, err = 0;
      const chunks = Math.ceil(total / CHUNK_SIZE);
      for (let i = 0; i < chunks; i++) {
        if (bulkAbort) {
          bulkFinish(`⏹ Annulé — ${ok} reportées avant arrêt`);
          return;
        }
        const slice = ids.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE);
        for (const id of slice) {
          try {
            const r = await callApi('later', { carte_id: id, until: '+1 day' });
            if (r.ok) ok++; else err++;
          } catch (_) { err++; }
        }
        done += slice.length;
        bulkUpdate({ done, ok, err, chunk: i + 1, chunks });
      }
      bulkFinish(`✅ Terminé — ${ok} reportées · ${err} erreurs`);
    }

    updateUI();
  }

  /* ──────────────────────────────────────────────────────────
     BOUTON "TOUT VALIDER EN MASSE" (hors liste batch)
     ────────────────────────────────────────────────────────── */
  const massBtn = document.getElementById('fbx-mass-validate');
  if (massBtn) {
    massBtn.addEventListener('click', async () => {
      // Détecte le filtre source depuis l'URL
      const urlParams = new URLSearchParams(window.location.search);
      const source = urlParams.get('source') || 'all';
      try {
        // Dry-run pour récupérer la liste exacte des IDs éligibles
        const dryRes = await callApi('bulk_validate', { source, min_confiance: 60, limit: 5000, dry_run: 1 });
        const ids = dryRes.ok ? (dryRes.data?.carte_ids || []) : [];
        if (ids.length === 0) {
          flash('Aucune carte éligible (confiance ≥ 60%) pour validation en masse.', 'error');
          return;
        }
        if (!confirm(`Valider en masse ${ids.length} carte${ids.length>1?'s':''} où l'IA est sûre (confiance ≥ 60%) ?\n\nLes cartes incertaines (< 60%) resteront à valider manuellement.`)) return;
        // Chunk avec barre live
        await bulkValidateIds(ids, `⏳ Validation en masse de ${ids.length} carte${ids.length>1?'s':''}…`);
      } catch (e) {
        flash('Erreur : ' + e.message, 'error');
      }
    });
  }

  /* Scroll smooth pour le lien "📋 Sélection multiple" */
  document.querySelectorAll('a[href="#fbx-batch"]').forEach(a => {
    a.addEventListener('click', (e) => {
      e.preventDefault();
      const target = document.getElementById('fbx-batch');
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
