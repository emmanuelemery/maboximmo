'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const root = arbQs('#arb-biens');
  if (!root) return;

  arbInitModeToggle(root);

  const activeFilters = new Set();
  const chips = arbQsa('[data-filter]', root);
  const btnClear = arbQs('[data-filter-clear]', root);
  const rows = arbQsa('tr.arb-row', root);

  function applyFilters() {
    const need = Array.from(activeFilters);
    rows.forEach((tr) => {
      if (need.length === 0) {
        tr.style.display = '';
        return;
      }
      const tags = (tr.dataset.filters || '').split(/\s+/).filter(Boolean);
      const ok = need.every((f) => tags.includes(f));
      tr.style.display = ok ? '' : 'none';
    });
  }

  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      const f = chip.dataset.filter;
      if (!f) return;
      if (activeFilters.has(f)) {
        activeFilters.delete(f);
        chip.classList.remove('is-on');
      } else {
        activeFilters.add(f);
        chip.classList.add('is-on');
      }
      applyFilters();
    });
  });

  btnClear?.addEventListener('click', () => {
    activeFilters.clear();
    chips.forEach((c) => c.classList.remove('is-on'));
    applyFilters();
  });

  const compareBtn = arbQs('[data-compare-selected]', root);
  const kpiCash = arbQs('[data-kpi-cash]', root);

  function getSelectedIds() {
    const ids = [];
    rows.forEach((tr) => {
      const cb = tr.querySelector('[data-select]');
      if (cb && cb.checked && tr.style.display !== 'none') {
        const id = Number(tr.dataset.bienId || 0);
        if (id > 0) ids.push(id);
      }
    });
    return ids;
  }

  function updateSelectionUi() {
    const ids = getSelectedIds();
    if (compareBtn) compareBtn.disabled = ids.length < 1;

    let cash = 0;
    ids.forEach((id) => {
      const tr = rows.find(r => Number(r.dataset.bienId || 0) === id);
      if (tr) cash += Number(tr.dataset.cash || 0);
    });
    if (kpiCash) kpiCash.textContent = arbFmtMoney(cash);
  }

  root.addEventListener('change', (e) => {
    const cb = e.target.closest('[data-select]');
    if (!cb) return;
    updateSelectionUi();
  });

  compareBtn?.addEventListener('click', () => {
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    window.location.href = 'arbitrage_comparaison.php?ids=' + encodeURIComponent(ids.join(','));
  });

  updateSelectionUi();
});

