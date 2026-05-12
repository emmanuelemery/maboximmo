/* ─────────────────────────────────────────────────────────────────
   Ma GED Box V1.1 — JS niveaux GED super_admin (drag & drop natif)
   ───────────────────────────────────────────────────────────────── */

(function () {
  'use strict';

  const API = '/api/ged_folders_admin.php';
  const $   = (s, r) => (r || document).querySelector(s);
  const $$  = (s, r) => Array.from((r || document).querySelectorAll(s));

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g,
      c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }

  async function api(action, opts) {
    opts = opts || {};
    const isPost = (opts.method === 'POST');
    const url = API + (isPost ? '' : '?action=' + encodeURIComponent(action) + (opts.qs || ''));
    const init = { method: opts.method || 'GET', credentials: 'same-origin' };
    if (isPost) {
      const fd = opts.formData || new FormData();
      if (!fd.has('action')) fd.append('action', action);
      init.body = fd;
    }
    const r = await fetch(url, init);
    const txt = await r.text();
    let j;
    try { j = JSON.parse(txt); } catch (_) {
      throw new Error('Réponse non-JSON : ' + txt.slice(0, 160));
    }
    if (!j.ok) throw new Error(j.error || 'Erreur API');
    return j;
  }

  // ── Drag & drop ──────────────────────────────────────────────
  let dragSrc = null;

  function bindDragHandlers() {
    $$('.gnvx-item').forEach(item => {
      if (item.dataset.dndBound === '1') return;
      item.dataset.dndBound = '1';
      item.setAttribute('draggable', 'true');

      item.addEventListener('dragstart', (e) => {
        dragSrc = item;
        item.classList.add('gnvx-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.dataset.id || '');
      });
      item.addEventListener('dragend', () => {
        item.classList.remove('gnvx-dragging');
        dragSrc = null;
        $$('.gnvx-list.gnvx-drag-over').forEach(l => l.classList.remove('gnvx-drag-over'));
      });
    });

    $$('.gnvx-list').forEach(list => {
      if (list.dataset.dndBound === '1') return;
      list.dataset.dndBound = '1';

      list.addEventListener('dragover', (e) => {
        if (!dragSrc) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        list.classList.add('gnvx-drag-over');
      });
      list.addEventListener('dragleave', (e) => {
        if (e.target === list) list.classList.remove('gnvx-drag-over');
      });
      list.addEventListener('drop', async (e) => {
        e.preventDefault();
        list.classList.remove('gnvx-drag-over');
        if (!dragSrc) return;

        const targetItem = e.target.closest('.gnvx-item');
        const sameList = (dragSrc.parentElement === list);

        // Reorder dans la même liste
        if (sameList) {
          if (targetItem && targetItem !== dragSrc) {
            const rect = targetItem.getBoundingClientRect();
            const after = (e.clientY - rect.top) > rect.height / 2;
            list.insertBefore(dragSrc, after ? targetItem.nextSibling : targetItem);
          }
          await persistReorder(list);
          return;
        }

        // Move vers une autre liste (changement de parent)
        const newLevel = parseInt(list.dataset.level, 10);
        const srcLevel = parseInt(dragSrc.dataset.level, 10);
        if (newLevel !== srcLevel) {
          alert('Impossible de déplacer un N' + srcLevel + ' vers un emplacement N' + newLevel + '. Le drop n\'a de sens qu\'à un même niveau (changement de parent).');
          return;
        }
        // Récupère le nouveau parent depuis data-* de la liste
        const newParents = {
          new_parent_n1: list.dataset.parentN1 || '',
          new_parent_n2: list.dataset.parentN2 || '',
          new_parent_n3: list.dataset.parentN3 || '',
          new_parent_n4: list.dataset.parentN4 || '',
        };
        if (!confirm('Déplacer "' + (dragSrc.dataset.label || '') + '" sous le nouveau parent ?')) return;
        try {
          const fd = new FormData();
          fd.append('action', 'move');
          fd.append('level_id', dragSrc.dataset.id);
          Object.entries(newParents).forEach(([k, v]) => fd.append(k, v));
          await api('move', { method: 'POST', formData: fd });
          window.location.reload();
        } catch (err) {
          alert('❌ Move : ' + err.message);
        }
      });
    });
  }

  async function persistReorder(list) {
    const positions = $$('.gnvx-item', list).map((it, idx) => ({
      id: parseInt(it.dataset.id, 10),
      position: (idx + 1) * 10,
    })).filter(p => p.id > 0);
    if (!positions.length) return;
    try {
      const fd = new FormData();
      fd.append('action', 'reorder_batch');
      positions.forEach((p, i) => {
        fd.append('positions[' + i + '][id]', p.id);
        fd.append('positions[' + i + '][position]', p.position);
      });
      await api('reorder_batch', { method: 'POST', formData: fd });
      // Pas de reload, on affiche juste un toast léger
      flashOk(positions.length + ' niveaux réordonnés');
    } catch (err) { alert('❌ Reorder : ' + err.message); }
  }

  // ── Actions item (archive / delete / unarchive) ──────────────
  document.body.addEventListener('click', async (e) => {
    const btn = e.target.closest('.gnvx-action-btn');
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();
    const action = btn.dataset.btnAction;
    const id = parseInt(btn.dataset.id, 10);
    if (!id) return;

    if (action === 'archive') {
      if (!confirm('Archiver ce niveau ? (réversible — bouton ✓ pour désarchiver)')) return;
      try { await api('archive', { method: 'POST', formData: fdOf({ action: 'archive', level_id: id }) });
        window.location.reload();
      } catch (err) { alert('❌ ' + err.message); }
    }
    else if (action === 'unarchive') {
      try { await api('unarchive', { method: 'POST', formData: fdOf({ action: 'unarchive', level_id: id }) });
        window.location.reload();
      } catch (err) { alert('❌ ' + err.message); }
    }
    else if (action === 'delete') {
      try {
        // Pré-check
        const j = await api('count_usages', { qs: '&level_id=' + id });
        if (!j.can_delete) {
          alert('❌ Impossible de supprimer.\n• ' + j.children_count + ' niveaux enfants\n• ' + j.usage_docs + ' documents GED\n• ' + j.usage_items + ' items d\'import\n\nUtilise "Archiver" à la place.');
          return;
        }
        if (!confirm('Supprimer définitivement ce niveau ? (vide, action irréversible)')) return;
        await api('delete_if_empty', { method: 'POST', formData: fdOf({ action: 'delete_if_empty', level_id: id }) });
        window.location.reload();
      } catch (err) { alert('❌ ' + err.message); }
    }
  });

  function fdOf(obj) {
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
    return fd;
  }

  // ── Bouton "Recalculer toute l'arbo" ─────────────────────────
  function bindRecalc() {
    const btn = $('#gnvx-btn-recalc');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      if (!confirm('Recalculer tous les path_cache + depth de l\'arborescence ged_folders ?\n(Peut prendre quelques secondes pour de gros volumes.)')) return;
      btn.disabled = true; const orig = btn.textContent;
      btn.textContent = '⏳ Recalcul…';
      try {
        const j = await api('recalculate_tree', { method: 'POST', formData: fdOf({ action: 'recalculate_tree' }) });
        flashOk('✓ ' + j.recalculated + ' dossiers recalculés.');
      } catch (err) { alert('❌ ' + err.message); }
      finally { btn.disabled = false; btn.textContent = orig; }
    });
  }

  // ── Toast léger ───────────────────────────────────────────────
  function flashOk(msg) {
    let t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#16a34a;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,.2);z-index:9999;opacity:0;transition:opacity .2s';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.style.opacity = '1', 50);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 2200);
  }

  // ── Init ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    bindDragHandlers();
    bindRecalc();
  });

  // Re-bind après un éventuel rendu dynamique
  window.gnvxRebind = () => bindDragHandlers();
})();
