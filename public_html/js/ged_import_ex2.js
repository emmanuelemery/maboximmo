/* ─────────────────────────────────────────────────────────────────
   Ma GED Box V1.1 — JS Page Import (super_admin_ged_import.php)
   ───────────────────────────────────────────────────────────────── */

(function () {
  'use strict';

  const API = '/api/ged_import_admin.php';
  const $   = (sel, root) => (root || document).querySelector(sel);
  const $$  = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  // batch_id : 1) attribut data-batch-id sur .gimp-wrap, 2) param URL ?batch_id=, 3) 0
  function detectBatchId() {
    const wrap = document.querySelector('.gimp-wrap');
    let id = wrap ? parseInt(wrap.dataset.batchId || '0', 10) : 0;
    if (!id) {
      const m = window.location.search.match(/[?&]batch_id=(\d+)/);
      if (m) id = parseInt(m[1], 10) || 0;
    }
    return id;
  }
  let currentBatchId = detectBatchId();
  let currentItem = null;
  let currentItems = [];

  // ── Utilitaires ────────────────────────────────────────────────
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g,
      c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }
  function scoreClass(score) {
    if (score >= 100) return 'gimp-score-full';
    if (score >= 80)  return 'gimp-score-high';
    if (score >= 50)  return 'gimp-score-medium';
    return 'gimp-score-low';
  }
  async function api(action, opts) {
    opts = opts || {};
    const url = API + (opts.method === 'POST' ? '' : '?action=' + encodeURIComponent(action) + (opts.qs || ''));
    const init = {
      method: opts.method || 'GET',
      credentials: 'same-origin',
    };
    if (opts.method === 'POST') {
      const fd = opts.formData || new FormData();
      if (!fd.has('action')) fd.append('action', action);
      init.body = fd;
    }
    const r = await fetch(url, init);
    const txt = await r.text();
    let j;
    try { j = JSON.parse(txt); } catch (_) {
      throw new Error('Réponse non-JSON (HTTP ' + r.status + ') : ' + txt.slice(0, 200));
    }
    if (!j.ok) throw new Error(j.error || 'Erreur API');
    return j;
  }

  // ── Upload ─────────────────────────────────────────────────────
  async function handleUpload(evt) {
    evt.preventDefault();
    const form = evt.target;
    const filesInput = $('#gimp-upload-files');
    const folderInput = $('#gimp-upload-folder');
    const batchName = $('#gimp-batch-name').value.trim() || ('Import ' + new Date().toLocaleString('fr-FR'));

    const files = (filesInput && filesInput.files.length > 0)
      ? Array.from(filesInput.files)
      : (folderInput ? Array.from(folderInput.files) : []);
    if (!files.length) { alert('Sélectionne au moins un fichier ou un dossier.'); return; }

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '⏳ Upload…'; }

    const fd = new FormData();
    fd.append('batch_name', batchName);
    fd.append('source_type', folderInput && folderInput.files.length > 0 ? 'upload_folder' : 'upload_files');
    files.forEach((f, i) => {
      fd.append('files[]', f, f.name);
      // webkitRelativePath = chemin si upload dossier
      const rel = f.webkitRelativePath || f.name;
      fd.append('rel_paths[]', rel);
    });

    try {
      const j = await api('upload', { method: 'POST', formData: fd });
      // Recharge la page sur le batch nouvellement créé
      window.location.href = window.location.pathname + '?batch_id=' + j.batch_id;
    } catch (err) {
      alert('❌ Upload : ' + err.message);
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '📤 Importer'; }
    }
  }

  // ── Liste des items ────────────────────────────────────────────
  async function reloadItems() {
    if (!currentBatchId) return;
    const filters = {
      status:    $('#gimp-filter-status')?.value || '',
      n1:        $('#gimp-filter-n1')?.value || '',
      min_score: $('#gimp-filter-score')?.value || '',
      ext:       $('#gimp-filter-ext')?.value || '',
      search:    $('#gimp-filter-search')?.value || '',
    };
    const qs = '&batch_id=' + currentBatchId + '&' + new URLSearchParams(filters).toString();
    const j = await api('list_items', { qs: qs });
    currentItems = j.items;
    renderTable(j.items);
    if (j.batch) renderBatchSummary(j.batch);
  }

  function renderBatchSummary(batch) {
    const el = $('#gimp-batch-summary');
    if (!el) return;
    el.innerHTML = `<strong>${escapeHtml(batch.batch_name)}</strong>
      · ${batch.nb_items} items
      · <span style="color:#16a34a">${batch.nb_validated} validés</span>
      · <span style="color:#dc2626">${batch.nb_ignored} ignorés</span>
      <span style="color:#94a3b8;font-family:monospace;font-size:10px">  · uuid=${escapeHtml(batch.uuid)}</span>`;
  }

  function renderTable(items) {
    const tbody = $('#gimp-tbody');
    if (!tbody) return;
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;color:#94a3b8;padding:30px;font-style:italic">Aucun item — uploade des fichiers pour commencer.</td></tr>';
      return;
    }
    const rows = items.map(it => {
      const sc = parseInt(it.confidence_score || 0, 10);
      return `
        <tr class="gimp-row gimp-row-${escapeHtml(it.status)}" data-id="${it.id}">
          <td><input type="checkbox" class="gimp-row-check" data-id="${it.id}"></td>
          <td class="gimp-cell-name">
            <strong>${escapeHtml(it.title_user || it.old_filename)}</strong>
            <code>${escapeHtml(it.old_folder_path || '')}/${escapeHtml(it.old_filename)}</code>
          </td>
          <td>${badgeLvl(it.selected_n1, 1)}</td>
          <td>${badgeLvl(it.selected_n2, 2)}</td>
          <td>${badgeLvl(it.selected_n3, 3)}</td>
          <td>${badgeLvl(it.selected_n4, 4)}</td>
          <td>${badgeLvl(it.selected_n5, 5)}</td>
          <td>${badgeLvl(it.selected_n6, 6)}</td>
          <td class="gimp-cell-canon">${escapeHtml(it.name_canonical || '—')}</td>
          <td><span class="gimp-score ${scoreClass(sc)}">${sc}</span></td>
          <td><span class="gimp-status gimp-status-${escapeHtml(it.status)}">${escapeHtml(it.status)}</span></td>
          <td class="gimp-row-actions">
            <a href="#" data-action="open"     data-id="${it.id}">✏️ Éditer</a>
            <a href="#" data-action="validate" data-id="${it.id}" style="color:#16a34a">✓ Valider</a>
            <a href="#" data-action="ignore"   data-id="${it.id}" style="color:#dc2626">✗ Ignorer</a>
          </td>
        </tr>`;
    });
    tbody.innerHTML = rows.join('');
  }

  function badgeLvl(val, level) {
    if (!val) return '<span class="gimp-lvl gimp-lvl-empty">—</span>';
    return '<span class="gimp-lvl gimp-lvl-' + level + '">' + escapeHtml(val) + '</span>';
  }

  // ── Modal édition ─────────────────────────────────────────────
  async function openModal(itemId) {
    const item = currentItems.find(i => parseInt(i.id, 10) === parseInt(itemId, 10));
    if (!item) return;
    currentItem = Object.assign({}, item);

    $('#gimp-modal-title').textContent = '✏️ ' + (item.title_user || item.old_filename);
    $('#gimp-meta-old-path').textContent     = item.old_folder_path || '—';
    $('#gimp-meta-old-name').textContent     = item.old_filename;
    $('#gimp-meta-ext').textContent          = item.file_extension || '—';
    $('#gimp-meta-mime').textContent         = item.mime_type || '—';
    $('#gimp-meta-size').textContent         = formatBytes(parseInt(item.size_bytes || 0, 10));
    $('#gimp-meta-hash').textContent         = (item.hash_sha256 || '—').slice(0, 16) + '…';

    $('#gimp-input-title').value     = item.title_user || '';
    $('#gimp-input-societe').value   = '';
    $('#gimp-input-agence').value    = '';
    $('#gimp-input-ref-entite').value= '';
    $('#gimp-input-nom-entite').value= '';
    $('#gimp-input-date').value      = '';
    $('#gimp-input-n6').value        = item.selected_n6 || '';

    // Cascade : charge N1 (toujours), puis N2 si N1 défini, etc.
    await renderCascade();

    $('#gimp-modal-bg').classList.add('is-open');
  }
  function closeModal() {
    $('#gimp-modal-bg').classList.remove('is-open');
    currentItem = null;
  }

  async function renderCascade() {
    if (!currentItem) return;
    for (let lvl = 1; lvl <= 5; lvl++) {
      await renderLevelButtons(lvl);
    }
    updateCanonicalPreview();
  }

  async function renderLevelButtons(level) {
    const container = $('#gimp-cascade-l' + level);
    if (!container) return;
    const parents = {
      n1: currentItem.selected_n1 || '',
      n2: currentItem.selected_n2 || '',
      n3: currentItem.selected_n3 || '',
      n4: currentItem.selected_n4 || '',
    };
    // Gating : pour level N, il faut N-1 parent défini (sauf level 1)
    const required = ['n1','n2','n3','n4'][level - 2];
    if (level > 1 && required && !parents[required]) {
      container.innerHTML = '<div class="gimp-cascade-empty">— Choisis d\'abord N' + (level-1) + ' —</div>';
      return;
    }
    container.innerHTML = '<div class="gimp-cascade-empty">Chargement…</div>';
    const qs = '&level=' + level + '&n1=' + encodeURIComponent(parents.n1)
      + '&n2=' + encodeURIComponent(parents.n2)
      + '&n3=' + encodeURIComponent(parents.n3)
      + '&n4=' + encodeURIComponent(parents.n4);
    try {
      const j = await api('get_levels', { qs: qs });
      if (!j.options.length) {
        container.innerHTML = '<div class="gimp-cascade-empty">Aucune option à ce niveau (à ajouter dans la page Niveaux).</div>';
        return;
      }
      const selectedKey = 'selected_n' + level;
      const sel = currentItem[selectedKey];
      container.innerHTML = j.options.map(opt => {
        const isSel = (opt.code === sel) ? ' is-selected' : '';
        return '<button type="button" class="gimp-cascade-btn' + isSel + '" data-level="' + level + '" data-code="' + escapeHtml(opt.code) + '">' + escapeHtml(opt.label) + '</button>';
      }).join('');
    } catch (err) {
      container.innerHTML = '<div class="gimp-cascade-empty" style="color:#dc2626">Erreur : ' + escapeHtml(err.message) + '</div>';
    }
  }

  async function pickLevel(level, code) {
    if (!currentItem) return;
    const key = 'selected_n' + level;
    currentItem[key] = code;
    // Reset les niveaux inférieurs
    for (let i = level + 1; i <= 6; i++) currentItem['selected_n' + i] = '';
    // Re-render à partir du niveau courant
    await renderLevelButtons(level);
    for (let i = level + 1; i <= 5; i++) await renderLevelButtons(i);
    updateCanonicalPreview();
  }

  async function pickN6(value) {
    if (!currentItem) return;
    currentItem.selected_n6 = value;
    updateCanonicalPreview();
  }

  function updateCanonicalPreview() {
    if (!currentItem) return;
    // Calcul local rapide (pas d'appel API à chaque keystroke)
    const seg = (s, max) => {
      if (!s) return '';
      let v = String(s).normalize('NFKD').replace(/[̀-ͯ]/g, '');
      v = v.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').toUpperCase();
      if (max && v.length > max) v = v.substring(0, max).replace(/_+$/, '');
      return v;
    };
    const parts = [];
    const n1 = currentItem.selected_n1 || '';
    if (n1) parts.push(seg(n1.replace(/^\d+_/, '')));
    const soc = $('#gimp-input-societe').value;
    const ag  = $('#gimp-input-agence').value;
    const ref = $('#gimp-input-ref-entite').value;
    const nom = $('#gimp-input-nom-entite').value;
    if (soc) parts.push(seg(soc));
    if (ag)  parts.push(seg(ag));
    if (ref) parts.push(seg(ref));
    if (nom) parts.push(seg(nom, 15));
    ['selected_n2','selected_n3','selected_n4','selected_n5'].forEach(k => {
      if (currentItem[k]) parts.push(seg(currentItem[k]));
    });
    if (currentItem.selected_n6) parts.push(seg(currentItem.selected_n6));
    const title = $('#gimp-input-title').value;
    if (title) parts.push(seg(title));
    const date = $('#gimp-input-date').value;
    if (date) {
      const ts = Date.parse(date);
      if (!isNaN(ts)) {
        const d = new Date(ts);
        parts.push(d.toISOString().slice(2, 10).replace(/-/g, ''));
      }
    }
    const ext = currentItem.file_extension ? '.' + currentItem.file_extension : '';
    $('#gimp-canonical-preview').textContent = parts.join('_') + ext;
    $('#gimp-destination-preview').textContent = ['n1','n2','n3','n4','n5','n6'].map(n =>
      currentItem['selected_' + n] ? seg(currentItem['selected_' + n]).toLowerCase() : null
    ).filter(Boolean).join('/');
  }

  async function saveItem(closeAfter) {
    if (!currentItem) return;
    const fd = new FormData();
    fd.append('action', 'update_item');
    fd.append('item_id', currentItem.id);
    ['selected_n1','selected_n2','selected_n3','selected_n4','selected_n5','selected_n6'].forEach(k => {
      fd.append(k, currentItem[k] || '');
    });
    fd.append('title_user', $('#gimp-input-title').value);
    fd.append('societe_code', $('#gimp-input-societe').value);
    fd.append('agence_code',  $('#gimp-input-agence').value);
    fd.append('ref_entite',   $('#gimp-input-ref-entite').value);
    fd.append('nom_entite',   $('#gimp-input-nom-entite').value);
    fd.append('date',         $('#gimp-input-date').value);
    try {
      const j = await api('update_item', { method: 'POST', formData: fd });
      Object.assign(currentItem, j.item);
      await reloadItems();
      if (closeAfter) closeModal();
    } catch (err) {
      alert('❌ ' + err.message);
    }
  }

  async function validateItem(itemId) {
    if (!confirm('Valider cet item et créer le document GED ?')) return;
    const fd = new FormData(); fd.append('action', 'validate'); fd.append('item_id', itemId);
    try {
      await api('validate', { method: 'POST', formData: fd });
      await reloadItems();
    } catch (err) { alert('❌ ' + err.message); }
  }
  async function ignoreItem(itemId) {
    const fd = new FormData(); fd.append('action', 'ignore'); fd.append('item_id', itemId);
    await api('ignore', { method: 'POST', formData: fd });
    await reloadItems();
  }

  async function validateAllAuto() {
    if (!currentBatchId) return;
    const minScore = parseInt(prompt('Valider toutes les lignes avec score >= ?', '90') || '0', 10);
    if (!minScore) return;
    const fd = new FormData();
    fd.append('action', 'validate_bulk');
    fd.append('batch_id', currentBatchId);
    fd.append('min_score', minScore);
    try {
      const j = await api('validate_bulk', { method: 'POST', formData: fd });
      const ok = Object.keys(j.validated).length;
      const ko = Object.keys(j.errors).length;
      alert('Validés : ' + ok + ' · Erreurs : ' + ko);
      await reloadItems();
    } catch (err) { alert('❌ ' + err.message); }
  }

  function formatBytes(b) {
    if (!b) return '0 B';
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return Math.round(b/102.4)/10 + ' Ko';
    return Math.round(b/(1024*102.4))/10 + ' Mo';
  }

  // ── Bindings ───────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const form = $('#gimp-upload-form');
    if (form) form.addEventListener('submit', handleUpload);

    if (currentBatchId) reloadItems();

    // Délégation événements tableau
    document.body.addEventListener('click', async (e) => {
      const a = e.target.closest('a[data-action]');
      if (a) {
        e.preventDefault();
        const id = parseInt(a.dataset.id, 10) || 0;
        const action = a.dataset.action;
        if (action === 'open')     openModal(id);
        else if (action === 'validate') validateItem(id);
        else if (action === 'ignore')   ignoreItem(id);
        return;
      }
      const cb = e.target.closest('.gimp-cascade-btn');
      if (cb) {
        await pickLevel(parseInt(cb.dataset.level, 10), cb.dataset.code);
        return;
      }
    });

    const closeBtn = $('#gimp-modal-close');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    $('#gimp-modal-bg')?.addEventListener('click', (e) => {
      if (e.target.id === 'gimp-modal-bg') closeModal();
    });

    $('#gimp-save-btn')?.addEventListener('click',     () => saveItem(false));
    $('#gimp-save-next-btn')?.addEventListener('click',() => saveItem(true));
    $('#gimp-validate-btn')?.addEventListener('click', () => {
      if (!currentItem) return;
      validateItem(currentItem.id);
      closeModal();
    });
    $('#gimp-ignore-btn')?.addEventListener('click',   () => {
      if (!currentItem) return;
      ignoreItem(currentItem.id);
      closeModal();
    });
    $('#gimp-validate-all-btn')?.addEventListener('click', validateAllAuto);

    // Filtres tableau
    ['gimp-filter-status', 'gimp-filter-n1', 'gimp-filter-score', 'gimp-filter-ext'].forEach(id => {
      $('#' + id)?.addEventListener('change', reloadItems);
    });
    $('#gimp-filter-search')?.addEventListener('input', () => {
      clearTimeout(window._gimpSearchTimer);
      window._gimpSearchTimer = setTimeout(reloadItems, 300);
    });

    // N6 input + autres inputs (recalcul canonique live)
    ['gimp-input-title', 'gimp-input-societe', 'gimp-input-agence',
     'gimp-input-ref-entite', 'gimp-input-nom-entite', 'gimp-input-date'].forEach(id => {
      $('#' + id)?.addEventListener('input', updateCanonicalPreview);
    });
    $('#gimp-input-n6')?.addEventListener('input', (e) => pickN6(e.target.value));
  });
})();
