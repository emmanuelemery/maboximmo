/**
 * components.js
 * Rôle     : Comportements interactifs des composants UI
 * Dépend   : components.css, layout.css
 * Date     : 2026-04-03
 */

'use strict';

/* ══════════════════════════════════════════
   DARK MODE
══════════════════════════════════════════ */
const DarkMode = {
  KEY: 'v2-theme',

  init() {
    const saved = localStorage.getItem(this.KEY);
    if (saved === 'dark') this.enable(false);
    else if (saved === 'light') this.disable(false);
    else if (window.matchMedia('(prefers-color-scheme: dark)').matches) this.enable(false);
  },

  enable(save = true) {
    document.documentElement.setAttribute('data-theme', 'dark');
    document.body.classList.add('dark');
    if (save) localStorage.setItem(this.KEY, 'dark');
    document.querySelectorAll('[data-dark-icon]').forEach(el => {
      el.textContent = el.dataset.darkIcon;
    });
  },

  disable(save = true) {
    document.documentElement.removeAttribute('data-theme');
    document.body.classList.remove('dark');
    if (save) localStorage.setItem(this.KEY, 'light');
    document.querySelectorAll('[data-dark-icon]').forEach(el => {
      el.textContent = el.dataset.lightIcon || '☀️';
    });
  },

  toggle() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    isDark ? this.disable() : this.enable();
  }
};

/* ══════════════════════════════════════════
   SIDEBAR
══════════════════════════════════════════ */
const Sidebar = {
  init() {
    const sidebar    = document.querySelector('.sidebar');
    const toggleBtn  = document.querySelector('[data-sidebar-toggle]');
    const overlay    = document.querySelector('.sidebar-overlay');

    if (!sidebar) return;

    // Persist collapse state
    if (localStorage.getItem('sidebar-collapsed') === '1') {
      sidebar.classList.add('collapsed');
    }

    toggleBtn?.addEventListener('click', () => {
      sidebar.classList.toggle('collapsed');
      localStorage.setItem('sidebar-collapsed',
        sidebar.classList.contains('collapsed') ? '1' : '0');
    });

    // Mobile overlay
    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('mobile-open');
      overlay.classList.remove('active');
    });

    document.querySelector('[data-mobile-menu]')?.addEventListener('click', () => {
      sidebar.classList.add('mobile-open');
      overlay?.classList.add('active');
    });

    // Active nav item — scroll into view
    const active = sidebar.querySelector('.nav-item.active');
    active?.scrollIntoView({ block: 'nearest' });
  }
};

/* ══════════════════════════════════════════
   MODALES
══════════════════════════════════════════ */
const Modal = {
  stack: [],

  open(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.classList.remove('hidden');
    this.stack.push(id);
    document.body.style.overflow = 'hidden';
    overlay.querySelector('[autofocus]')?.focus();
    // Trap focus
    overlay.addEventListener('keydown', this._trapFocus);
    overlay.addEventListener('click', e => {
      if (e.target === overlay) this.close(id);
    });
  },

  close(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.classList.add('hidden');
    this.stack = this.stack.filter(s => s !== id);
    if (this.stack.length === 0) document.body.style.overflow = '';
  },

  closeAll() {
    this.stack.forEach(id => this.close(id));
  },

  _trapFocus(e) {
    if (e.key !== 'Tab') return;
    const modal = e.currentTarget.querySelector('.modal');
    if (!modal) return;
    const focusable = [...modal.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )].filter(el => !el.disabled);
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last  = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault(); last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault(); first.focus();
    }
  },

  init() {
    // [data-modal-open="id"] triggers
    document.addEventListener('click', e => {
      const opener = e.target.closest('[data-modal-open]');
      if (opener) this.open(opener.dataset.modalOpen);
      const closer = e.target.closest('[data-modal-close]');
      if (closer) {
        const overlay = closer.closest('.modal-overlay');
        if (overlay) this.close(overlay.id);
      }
    });
    // Escape key
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && this.stack.length) {
        this.close(this.stack[this.stack.length - 1]);
      }
    });
  }
};

/* ══════════════════════════════════════════
   DRAWER
══════════════════════════════════════════ */
const Drawer = {
  open(id) {
    const overlay = document.getElementById(id + '-overlay');
    const drawer  = document.getElementById(id);
    overlay?.classList.remove('hidden');
    drawer?.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    overlay?.addEventListener('click', () => this.close(id), { once: true });
  },

  close(id) {
    const overlay = document.getElementById(id + '-overlay');
    const drawer  = document.getElementById(id);
    overlay?.classList.add('hidden');
    drawer?.classList.add('hidden');
    document.body.style.overflow = '';
  },

  init() {
    document.addEventListener('click', e => {
      const opener = e.target.closest('[data-drawer-open]');
      if (opener) this.open(opener.dataset.drawerOpen);
      const closer = e.target.closest('[data-drawer-close]');
      if (closer) {
        const drawer = closer.closest('.drawer');
        if (drawer) this.close(drawer.id);
      }
    });
  }
};

/* ══════════════════════════════════════════
   DROPDOWN
══════════════════════════════════════════ */
const Dropdown = {
  active: null,

  toggle(trigger) {
    const dropdown = trigger.closest('.dropdown');
    const menu = dropdown?.querySelector('.dropdown-menu');
    if (!menu) return;

    const isOpen = !menu.classList.contains('hidden');
    this.closeAll();
    if (!isOpen) {
      menu.classList.remove('hidden');
      this.active = menu;
      // Position check
      const rect = menu.getBoundingClientRect();
      if (rect.right > window.innerWidth) menu.classList.add('align-right');
    }
  },

  closeAll() {
    document.querySelectorAll('.dropdown-menu:not(.hidden)').forEach(m => {
      m.classList.add('hidden');
      m.classList.remove('align-right');
    });
    this.active = null;
  },

  init() {
    document.addEventListener('click', e => {
      const trigger = e.target.closest('[data-dropdown]');
      if (trigger) {
        e.stopPropagation();
        this.toggle(trigger);
      } else {
        this.closeAll();
      }
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') this.closeAll();
    });
  }
};

/* ══════════════════════════════════════════
   TOASTS
══════════════════════════════════════════ */
const Toast = {
  container: null,

  init() {
    this.container = document.querySelector('.toast-container');
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.className = 'toast-container';
      document.body.appendChild(this.container);
    }
  },

  show({ title = '', message = '', type = 'info', duration = 4000 } = {}) {
    const icons = {
      success: '✓',
      warning: '⚠',
      danger:  '✕',
      info:    'ℹ'
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
      <span class="toast-icon">${icons[type] || icons.info}</span>
      <div class="toast-content">
        ${title ? `<div class="toast-title">${title}</div>` : ''}
        ${message ? `<div class="toast-message">${message}</div>` : ''}
      </div>
      <button class="toast-close" aria-label="Fermer">✕</button>
    `;

    toast.querySelector('.toast-close').addEventListener('click', () => this._dismiss(toast));
    this.container.appendChild(toast);

    if (duration > 0) {
      setTimeout(() => this._dismiss(toast), duration);
    }
    return toast;
  },

  _dismiss(toast) {
    toast.classList.add('toast-out');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  },

  success(message, title = 'Succès')   { return this.show({ title, message, type: 'success' }); },
  warning(message, title = 'Attention'){ return this.show({ title, message, type: 'warning' }); },
  danger(message,  title = 'Erreur')   { return this.show({ title, message, type: 'danger'  }); },
  info(message,    title = 'Info')      { return this.show({ title, message, type: 'info'    }); }
};

/* ══════════════════════════════════════════
   TABS
══════════════════════════════════════════ */
const Tabs = {
  init(container) {
    const tabs = container || document;
    tabs.querySelectorAll('.tabs, .tabs-pills, .tabs-vertical').forEach(tabBar => {
      tabBar.querySelectorAll('.tab-item').forEach(item => {
        item.addEventListener('click', () => {
          // Deactivate all
          tabBar.querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
          item.classList.add('active');

          // Switch panels
          const panelId = item.dataset.tab;
          if (panelId) {
            const panelContainer = tabBar.closest('[data-tabs-container]') || document;
            panelContainer.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            panelContainer.querySelector(`#${panelId}`)?.classList.add('active');
          }
        });
      });
    });
  }
};

/* ══════════════════════════════════════════
   ALERTS (dismiss)
══════════════════════════════════════════ */
const Alerts = {
  init() {
    document.addEventListener('click', e => {
      const close = e.target.closest('.alert-close');
      if (close) {
        const alert = close.closest('.alert');
        alert?.remove();
      }
    });
  }
};

/* ══════════════════════════════════════════
   TABLES — tri & sélection
══════════════════════════════════════════ */
const Table = {
  init(tableEl) {
    if (!tableEl) return;
    this._initSort(tableEl);
    this._initSelection(tableEl);
    this._initPagination(tableEl);
  },

  _initSort(table) {
    table.querySelectorAll('th.sortable').forEach(th => {
      th.addEventListener('click', () => {
        const col  = th.cellIndex;
        const asc  = !th.classList.contains('sort-asc');
        // Reset others
        table.querySelectorAll('th').forEach(t => t.classList.remove('sort-asc', 'sort-desc'));
        th.classList.add(asc ? 'sort-asc' : 'sort-desc');

        const tbody = table.querySelector('tbody');
        const rows  = [...tbody.querySelectorAll('tr')];
        rows.sort((a, b) => {
          const av = a.cells[col]?.textContent.trim() ?? '';
          const bv = b.cells[col]?.textContent.trim() ?? '';
          const an = parseFloat(av.replace(/[^0-9.-]/g, ''));
          const bn = parseFloat(bv.replace(/[^0-9.-]/g, ''));
          const cmp = isNaN(an) || isNaN(bn)
            ? av.localeCompare(bv, 'fr', { sensitivity: 'base' })
            : an - bn;
          return asc ? cmp : -cmp;
        });
        rows.forEach(r => tbody.appendChild(r));
      });
    });
  },

  _initSelection(table) {
    const selectAll = table.querySelector('th input[type="checkbox"]');
    const rows      = table.querySelectorAll('tbody tr');
    if (!selectAll) return;

    selectAll.addEventListener('change', () => {
      rows.forEach(row => {
        const cb = row.querySelector('td input[type="checkbox"]');
        if (cb) {
          cb.checked = selectAll.checked;
          row.classList.toggle('selected', selectAll.checked);
        }
      });
    });

    rows.forEach(row => {
      const cb = row.querySelector('td input[type="checkbox"]');
      cb?.addEventListener('change', () => {
        row.classList.toggle('selected', cb.checked);
        const allChecked = [...rows].every(r => r.querySelector('td input[type="checkbox"]')?.checked);
        const someChecked = [...rows].some(r => r.querySelector('td input[type="checkbox"]')?.checked);
        selectAll.checked = allChecked;
        selectAll.indeterminate = someChecked && !allChecked;
      });
    });
  },

  _initPagination(table) {
    const wrapper = table.closest('[data-paginate]');
    if (!wrapper) return;
    const perPage = parseInt(wrapper.dataset.paginate) || 10;
    const tbody   = table.querySelector('tbody');
    const rows    = [...tbody.querySelectorAll('tr')];
    const pages   = Math.ceil(rows.length / perPage);
    let current   = 1;

    const paginationEl = wrapper.querySelector('.table-pagination');
    if (!paginationEl) return;

    const render = () => {
      rows.forEach((r, i) => {
        r.style.display = (i >= (current-1)*perPage && i < current*perPage) ? '' : 'none';
      });
      paginationEl.querySelector('.table-pagination-info').textContent =
        `${(current-1)*perPage+1}–${Math.min(current*perPage, rows.length)} sur ${rows.length}`;
      paginationEl.querySelector('[data-prev]').disabled = current === 1;
      paginationEl.querySelector('[data-next]').disabled = current === pages;
    };

    paginationEl.querySelector('[data-prev]')?.addEventListener('click', () => { if (current>1) { current--; render(); } });
    paginationEl.querySelector('[data-next]')?.addEventListener('click', () => { if (current<pages) { current++; render(); } });
    render();
  }
};

/* ══════════════════════════════════════════
   TEXTAREA COUNTER
══════════════════════════════════════════ */
const TextareaCounter = {
  init() {
    document.querySelectorAll('.textarea[maxlength]').forEach(ta => {
      const wrapper = ta.closest('.textarea-wrapper');
      if (!wrapper) return;
      let counter = wrapper.querySelector('.textarea-counter');
      if (!counter) {
        counter = document.createElement('span');
        counter.className = 'textarea-counter';
        wrapper.appendChild(counter);
      }
      const max = parseInt(ta.getAttribute('maxlength'));
      const update = () => {
        const len = ta.value.length;
        counter.textContent = `${len} / ${max}`;
        counter.classList.toggle('near-limit', len > max * 0.8);
        counter.classList.toggle('at-limit',   len >= max);
      };
      ta.addEventListener('input', update);
      update();
    });
  }
};

/* ══════════════════════════════════════════
   FILE UPLOAD (drag & drop)
══════════════════════════════════════════ */
const FileUpload = {
  init() {
    document.querySelectorAll('.file-upload-zone').forEach(zone => {
      const input = zone.querySelector('.file-input') || zone.nextElementSibling;

      zone.addEventListener('click', () => input?.click());
      zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
      zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
      zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        this._handleFiles(zone, e.dataTransfer.files);
      });
      input?.addEventListener('change', () => this._handleFiles(zone, input.files));
    });
  },

  _handleFiles(zone, files) {
    const list = zone.closest('.file-upload-wrapper')?.querySelector('.file-list');
    if (!list) return;
    [...files].forEach(file => {
      const item = document.createElement('div');
      item.className = 'file-item';
      item.innerHTML = `
        <span class="file-item-name">${file.name}</span>
        <span class="file-item-size">${this._fmt(file.size)}</span>
        <button class="file-item-remove" aria-label="Retirer">✕</button>
      `;
      item.querySelector('.file-item-remove').addEventListener('click', () => item.remove());
      list.appendChild(item);
    });
  },

  _fmt(bytes) {
    if (bytes < 1024) return `${bytes} o`;
    if (bytes < 1048576) return `${(bytes/1024).toFixed(1)} Ko`;
    return `${(bytes/1048576).toFixed(1)} Mo`;
  }
};

/* ══════════════════════════════════════════
   SWATCH COPY (palette de couleurs)
══════════════════════════════════════════ */
const ColorSwatches = {
  init() {
    document.querySelectorAll('[data-copy-color]').forEach(el => {
      el.addEventListener('click', () => {
        const color = el.dataset.copyColor || getComputedStyle(el).backgroundColor;
        navigator.clipboard.writeText(color).then(() => {
          Toast.success(color, 'Couleur copiée');
        });
      });
    });
  }
};

/* ══════════════════════════════════════════
   STEPPER
══════════════════════════════════════════ */
const Stepper = {
  init() {
    document.querySelectorAll('[data-stepper]').forEach(container => {
      let current = 0;
      const steps  = container.querySelectorAll('.step');
      const panels = document.querySelectorAll('[data-step-panel]');
      const prevBtn = container.querySelector('[data-step-prev]');
      const nextBtn = container.querySelector('[data-step-next]');

      const render = () => {
        steps.forEach((s, i) => {
          s.classList.remove('current', 'completed');
          if (i < current) s.classList.add('completed');
          if (i === current) s.classList.add('current');
        });
        panels.forEach((p, i) => {
          p.style.display = i === current ? '' : 'none';
        });
        if (prevBtn) prevBtn.disabled = current === 0;
        if (nextBtn) nextBtn.disabled = current === steps.length - 1;
      };

      prevBtn?.addEventListener('click', () => { if (current > 0) { current--; render(); } });
      nextBtn?.addEventListener('click', () => { if (current < steps.length-1) { current++; render(); } });
      render();
    });
  }
};

/* ══════════════════════════════════════════
   INIT GLOBAL
══════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  DarkMode.init();
  Sidebar.init();
  Modal.init();
  Drawer.init();
  Dropdown.init();
  Toast.init();
  Tabs.init();
  Alerts.init();
  TextareaCounter.init();
  FileUpload.init();
  ColorSwatches.init();
  Stepper.init();

  // Tables avec data-table
  document.querySelectorAll('[data-table]').forEach(t => Table.init(t));

  // Dark mode toggle
  document.querySelectorAll('[data-toggle-dark]').forEach(btn => {
    btn.addEventListener('click', () => DarkMode.toggle());
  });
});

// Exports pour usage inline
window.V2 = { DarkMode, Sidebar, Modal, Drawer, Dropdown, Toast, Tabs, Table, Stepper };
