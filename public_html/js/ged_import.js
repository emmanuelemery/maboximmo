/* ─────────────────────────────────────────────────────────────────
   Ma GED Box V1.1 — JS Page Import (super_admin_ged_import.php)
   ───────────────────────────────────────────────────────────────── */

(function () {
  'use strict';

  const API = '/api/ged_import_admin.php';
  const API_V2 = '/api/ged_naming.php'; // Phase 2 : moteur intelligent V2
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
    const baseUrl = opts.baseUrl || API;
    const url = baseUrl + (opts.method === 'POST' ? '' : '?action=' + encodeURIComponent(action) + (opts.qs || ''));
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
  function apiV2(action, opts) { return api(action, Object.assign({}, opts, { baseUrl: API_V2 })); }

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
    // Phase 3 : mode quick si toggle coché
    const modeQuick = $('#gimp-mode-quick');
    fd.append('default_mode', (modeQuick && modeQuick.checked) ? 'quick' : 'normalized');
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
      mode:      $('#gimp-filter-mode')?.value || '',
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
      const isQuick = (it.mode === 'quick' || it.status === 'classified_quick');
      const quickBadge = isQuick ? '<span style="background:#f59e0b;color:#fff;padding:1px 7px;border-radius:99px;font-size:9px;font-weight:700;margin-left:4px" title="Mode rapide">⚡ QUICK</span>' : '';
      return `
        <tr class="gimp-row gimp-row-${escapeHtml(it.status)}" data-id="${it.id}">
          <td><input type="checkbox" class="gimp-row-check" data-id="${it.id}"></td>
          <td class="gimp-cell-name">
            <strong>${escapeHtml(it.title_user || it.old_filename)}${quickBadge}</strong>
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

    // Reset des checkboxes de validation par champ
    resetValidateChecks();

    // Cascade : charge N1 (toujours), puis N2 si N1 défini, etc.
    await renderCascade();

    // Phase 2 V2 : check doublons en parallèle (non bloquant)
    checkDuplicates();

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

    // Phase 2 V2 : appel async pour scores granulaires + raisons
    refreshScoresAndReasons();
  }

  // ─── Phase 2 V2 : scores granulaires + raisons + doublons ──────────
  let _scoresTimer = null;
  function refreshScoresAndReasons() {
    if (!currentItem) return;
    clearTimeout(_scoresTimer);
    _scoresTimer = setTimeout(async () => {
      try {
        // Save d'abord pour persister + recompute côté serveur (autorité)
        const fd = new FormData();
        fd.append('action', 'recompute_item_scores');
        fd.append('item_id', currentItem.id);
        const j = await apiV2('recompute_item_scores', { method: 'POST', formData: fd });
        renderScoresGrid(j.scores || {});
        renderReviewReasons(j.needs_review_reason || []);
      } catch (err) {
        console.error('refreshScores KO:', err.message);
      }
    }, 350);
  }

  function renderScoresGrid(scores) {
    const wrap = $('#gimp-scores-grid');
    if (!wrap) return;
    wrap.style.display = '';
    const map = {
      type:        scores.score_type || 0,
      entity:      scores.score_entity || 0,
      date:        scores.score_date || 0,
      structure:   scores.score_structure || 0,
      destination: scores.score_destination || 0,
    };
    Object.entries(map).forEach(([k, v]) => {
      const cell = wrap.querySelector('[data-score-key="' + k + '"]');
      if (!cell) return;
      const fill = cell.querySelector('[data-bar-fill]');
      const valEl = cell.querySelector('[data-score-val]');
      const cls = scoreFillClass(v);
      if (fill) {
        fill.className = 'gimp-score-cell-bar-fill ' + cls;
        fill.style.width = Math.min(100, Math.max(0, v)) + '%';
      }
      if (valEl) valEl.textContent = v;
    });

    // Score global
    const sg = scores.score_global || 0;
    const wrapGlobal = $('#gimp-score-global-wrap');
    const circle = $('#gimp-score-global-circle');
    const help = $('#gimp-score-global-help');
    if (wrapGlobal) {
      wrapGlobal.style.display = '';
      const cls = sg >= 100 ? 'full' : sg >= 80 ? 'high' : sg >= 50 ? 'medium' : 'low';
      circle.className = 'gimp-score-global-circle ' + cls;
      circle.textContent = sg;
      help.textContent = sg >= 90
        ? '🟢 Validation auto possible (score ≥ 90)'
        : sg >= 70
          ? '🟡 Validation recommandée (score 70-90)'
          : '🔴 À REVOIR — vérifie les champs en orange';
    }
  }
  function scoreFillClass(v) {
    if (v >= 100) return 'gimp-score-fill-full';
    if (v >= 80)  return 'gimp-score-fill-high';
    if (v >= 50)  return 'gimp-score-fill-medium';
    return 'gimp-score-fill-low';
  }

  function renderReviewReasons(reasons) {
    const block = $('#gimp-review-block');
    const list = $('#gimp-review-reasons');
    if (!block || !list) return;
    if (!reasons || reasons.length === 0) {
      block.style.display = 'none';
      return;
    }
    block.style.display = '';
    const labels = {
      low_confidence:  '🔴 Confiance faible',
      missing_entity:  '⚠️ Entité manquante',
      unknown_date:    '📅 Date inconnue/estimée',
      ambiguous_type:  '❓ Type ambigu (N1/N2)',
      duplicate:       '🔁 Doublon potentiel',
      manual_flag:     '🚩 Marqué à revoir manuellement',
    };
    list.innerHTML = reasons.map(r => {
      const label = labels[r] || r;
      const isBad = (r === 'low_confidence' || r === 'duplicate');
      return '<span class="gimp-review-reason' + (isBad ? ' bad' : '') + '">' + escapeHtml(label) + '</span>';
    }).join('');
  }

  async function checkDuplicates() {
    if (!currentItem) return;
    const block = $('#gimp-dupes-block');
    const title = $('#gimp-dupes-title');
    const content = $('#gimp-dupes-content');
    if (!block) return;
    block.style.display = '';
    block.className = 'gimp-dupes-block risk-none';
    title.textContent = '🔍 Vérification doublons en cours…';
    content.innerHTML = '';
    try {
      const j = await apiV2('find_duplicates_smart', { qs: '&item_id=' + currentItem.id });
      block.className = 'gimp-dupes-block risk-' + (j.risk || 'none');
      const counts = {
        hash:    (j.hash_matches || []).length,
        similar: (j.similar || []).length,
      };
      if (j.risk === 'high') {
        title.textContent = '🚨 ' + counts.hash + ' DOUBLON(S) EXACT(S) (hash identique)';
        content.innerHTML = '<ul class="gimp-dupes-list">' + (j.hash_matches || []).map(d =>
          '<li><strong>' + escapeHtml(d.name_display || '#' + d.id) + '</strong> · <code>' + escapeHtml(d.name_canonical || '') + '</code></li>'
        ).join('') + '</ul>';
      } else if (j.risk === 'medium') {
        title.textContent = '⚠️ ' + counts.similar + ' document(s) similaire(s) (même taille + MIME + entité ± date)';
        content.innerHTML = '<ul class="gimp-dupes-list">' + (j.similar || []).map(d =>
          '<li>' + escapeHtml(d.name_display || '#' + d.id) + ' <small>(' + escapeHtml(d.entity_type || '') + ' #' + d.entity_id + ')</small></li>'
        ).join('') + '</ul>';
      } else if (j.risk === 'low') {
        title.textContent = '✓ Aucun doublon détecté (vérification basique : taille uniquement)';
      } else {
        title.textContent = '✓ Aucun doublon détecté';
      }
    } catch (err) {
      block.className = 'gimp-dupes-block risk-medium';
      title.textContent = '⚠️ Vérification doublons impossible : ' + err.message;
    }
  }

  // ─── Autocomplete N6 ───────────────────────────────────────────
  let _n6Timer = null;
  function bindN6Autocomplete() {
    const input = $('#gimp-input-n6');
    const sugg  = $('#gimp-n6-suggestions');
    if (!input || !sugg) return;

    input.addEventListener('input', () => {
      const q = input.value.trim();
      clearTimeout(_n6Timer);
      if (q.length < 2) { sugg.style.display = 'none'; return; }
      _n6Timer = setTimeout(async () => {
        try {
          const n1 = currentItem && currentItem.selected_n1 ? currentItem.selected_n1 : '';
          const j = await apiV2('n6_autocomplete', {
            qs: '&q=' + encodeURIComponent(q) + '&n1=' + encodeURIComponent(n1) + '&limit=10'
          });
          const list = j.suggestions || [];
          if (!list.length) { sugg.style.display = 'none'; return; }
          sugg.innerHTML = list.map(s =>
            '<div class="gimp-n6-suggestion" data-code="' + escapeHtml(s.code) + '">'
              + '<span>' + escapeHtml(s.label || s.code) + '</span>'
              + '<span class="gimp-n6-source gimp-n6-source-' + escapeHtml(s.source || 'history') + '">'
              + escapeHtml(s.source || '') + '</span>'
            + '</div>'
          ).join('');
          sugg.style.display = '';
        } catch (err) { sugg.style.display = 'none'; }
      }, 220);
    });

    sugg.addEventListener('click', (e) => {
      const item = e.target.closest('.gimp-n6-suggestion');
      if (!item) return;
      input.value = item.dataset.code || '';
      sugg.style.display = 'none';
      // Déclenche le pickN6 (recalcul canonical + scores)
      pickN6(input.value);
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.gimp-n6-wrap')) sugg.style.display = 'none';
    });
  }

  // ─── Validation par champ + bouton "Tout valider" ──────────────
  function bindValidateField() {
    const checks = $$('.gimp-validate-checkbox input[data-validate-input]');
    const toggle = $('#gimp-validate-toggle-all');

    checks.forEach(cb => {
      cb.addEventListener('change', () => {
        const lbl = cb.closest('.gimp-validate-checkbox');
        if (lbl) lbl.classList.toggle('is-checked', cb.checked);
        updateToggleAllState();
      });
    });

    if (toggle) {
      toggle.addEventListener('click', () => {
        const allChecked = checks.every(cb => cb.checked);
        const newState = !allChecked;
        checks.forEach(cb => {
          cb.checked = newState;
          const lbl = cb.closest('.gimp-validate-checkbox');
          if (lbl) lbl.classList.toggle('is-checked', newState);
        });
        updateToggleAllState();
      });
    }
  }
  function updateToggleAllState() {
    const checks = $$('.gimp-validate-checkbox input[data-validate-input]');
    const toggle = $('#gimp-validate-toggle-all');
    if (!toggle) return;
    const allChecked = checks.length > 0 && checks.every(cb => cb.checked);
    toggle.classList.toggle('is-all-checked', allChecked);
    toggle.textContent = allChecked ? '✓ Tout validé' : '✓ Tout valider';
    // Gate du bouton "⚡ Valider rapidement" : visible si N1 + N2 + entité validés
    updateValidateQuickGate();
  }
  function updateValidateQuickGate() {
    const btn = $('#gimp-validate-quick-btn');
    if (!btn) return;
    const need = ['n1', 'n2', 'entity'];
    const ok = need.every(field => {
      const cb = document.querySelector('.gimp-validate-checkbox input[data-validate-input="' + field + '"]');
      return cb && cb.checked;
    });
    btn.style.display = ok ? '' : 'none';
  }
  function resetValidateChecks() {
    $$('.gimp-validate-checkbox input[data-validate-input]').forEach(cb => {
      cb.checked = false;
      cb.closest('.gimp-validate-checkbox')?.classList.remove('is-checked');
    });
    updateToggleAllState();
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
    $('#gimp-validate-quick-btn')?.addEventListener('click', async () => {
      if (!currentItem) return;
      if (!confirm('Validation rapide : créer le document avec mode=quick ?\n\n• name_file = uuid.ext (pas renommage physique)\n• name_canonical généré en arrière-plan\n• Statut = classified_quick (différent de validated)\n\nNécessite N1 + N2 + entité validés (gate UI déjà OK).')) return;
      try {
        const fd = new FormData();
        fd.append('action', 'validate_quick');
        fd.append('item_id', currentItem.id);
        const j = await api('validate_quick', { method: 'POST', formData: fd });
        flashOkLight('⚡ Validé rapide → doc #' + j.document_id);
        closeModal();
        await reloadItems();
      } catch (err) { alert('❌ ' + err.message); }
    });
    function flashOkLight(msg) {
      const t = document.createElement('div');
      t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#f59e0b;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,.2);z-index:9999';
      t.textContent = msg;
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 2200);
    }
    $('#gimp-ignore-btn')?.addEventListener('click',   () => {
      if (!currentItem) return;
      ignoreItem(currentItem.id);
      closeModal();
    });
    $('#gimp-validate-all-btn')?.addEventListener('click', validateAllAuto);

    // Filtres tableau
    ['gimp-filter-status', 'gimp-filter-n1', 'gimp-filter-score', 'gimp-filter-ext', 'gimp-filter-mode'].forEach(id => {
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

    // Phase 2 V2 : autocomplete N6 + validation par champ
    bindN6Autocomplete();
    bindValidateField();
  });
})();
