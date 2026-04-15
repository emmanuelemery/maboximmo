/**
 * minicard.js — Composant mini-cards réutilisable
 * MaBoxImmo / TruBox / Registres — v1.0.0
 *
 * Initialisation automatique au chargement DOM.
 * API disponible via window.MiniCard.
 *
 * Modes :
 *   data-mc-mode="select"  — sélection unique ou multiple (formulaires)
 *   data-mc-mode="admin"   — gestion (admin) : toggle actif, edit, delete
 *
 * Attributs des grilles (.mc-grid) :
 *   data-mc-mode="select|admin"
 *   data-mc-multiple="true"    — multi-sélection (mode select)
 *   data-mc-field="nom_champ"  — met à jour un <input hidden> (mode select)
 *   data-mc-min="0"            — min sélections (mode select)
 *   data-mc-max="99"           — max sélections (mode select)
 *
 * Attributs des cartes (.mc-card) :
 *   data-mc-value="valeur"     — valeur à retourner
 *   data-mc-label="libellé"    — libellé affiché
 *   data-mc-desc="description" — contenu tooltip
 *   data-mc-id="123"           — ID en base (utile mode admin)
 */

(function () {
  'use strict';

  // ── Ferme tous les tooltips ouverts ─────────────────────────
  function closeAllTooltips() {
    document.querySelectorAll('.mc-tooltip.is-visible').forEach(t => {
      t.classList.remove('is-visible');
    });
  }

  // ── Crée/récupère le tooltip d'une carte ────────────────────
  function getOrCreateTooltip(card) {
    let tip = card.querySelector('.mc-tooltip');
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'mc-tooltip';
      const label = card.dataset.mcLabel || '';
      const desc  = card.dataset.mcDesc  || 'Aucune description.';
      tip.innerHTML = `<strong>${escHtml(label)}</strong>${escHtml(desc)}`;
      card.appendChild(tip);
    }
    return tip;
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  // ── Met à jour le champ caché lié à la grille ───────────────
  function syncHiddenField(grid) {
    const fieldName = grid.dataset.mcField;
    if (!fieldName) return;

    const selected = [...grid.querySelectorAll('.mc-card.is-selected')]
      .map(c => c.dataset.mcValue)
      .filter(Boolean);

    const multiple = grid.dataset.mcMultiple === 'true';

    // Nettoie les anciens inputs cachés créés par ce composant
    grid.parentElement.querySelectorAll(`input[data-mc-field="${fieldName}"]`).forEach(el => el.remove());

    if (multiple) {
      selected.forEach(val => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = fieldName + '[]';
        inp.value = val;
        inp.setAttribute('data-mc-field', fieldName);
        grid.parentElement.insertBefore(inp, grid.nextSibling);
      });
    } else {
      const inp = document.createElement('input');
      inp.type  = 'hidden';
      inp.name  = fieldName;
      inp.value = selected[0] ?? '';
      inp.setAttribute('data-mc-field', fieldName);
      grid.parentElement.insertBefore(inp, grid.nextSibling);
    }
  }

  // ── Gestion du clic sur une carte (mode select) ─────────────
  function handleSelectClick(card, grid) {
    const multiple = grid.dataset.mcMultiple === 'true';
    const maxSel   = parseInt(grid.dataset.mcMax ?? '99', 10);
    const isActive = card.classList.contains('is-inactive');
    if (isActive) return;

    if (multiple) {
      const alreadySelected = card.classList.contains('is-selected');
      const currentCount    = grid.querySelectorAll('.mc-card.is-selected').length;

      if (!alreadySelected && currentCount >= maxSel) return; // max atteint
      card.classList.toggle('is-selected');
    } else {
      const alreadySelected = card.classList.contains('is-selected');
      grid.querySelectorAll('.mc-card.is-selected').forEach(c => c.classList.remove('is-selected'));
      if (!alreadySelected) card.classList.add('is-selected');
    }

    syncHiddenField(grid);
    grid.dispatchEvent(new CustomEvent('mc:change', {
      bubbles: true,
      detail: {
        grid,
        selected: [...grid.querySelectorAll('.mc-card.is-selected')].map(c => c.dataset.mcValue),
      }
    }));
  }

  // ── Initialise une grille ────────────────────────────────────
  function initGrid(grid) {
    if (grid.dataset.mcInit === '1') return;
    grid.dataset.mcInit = '1';

    const mode = grid.dataset.mcMode ?? 'select';

    grid.querySelectorAll('.mc-card').forEach(card => {
      // ── Bouton ? ──
      let helpBtn = card.querySelector('.mc-help');
      if (!helpBtn) {
        helpBtn = document.createElement('button');
        helpBtn.type      = 'button';
        helpBtn.className = 'mc-help';
        helpBtn.textContent = '?';
        helpBtn.title     = 'Voir la description';
        card.appendChild(helpBtn);
      }

      helpBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        const tip      = getOrCreateTooltip(card);
        const isOpen   = tip.classList.contains('is-visible');
        closeAllTooltips();
        if (!isOpen) tip.classList.add('is-visible');
      });

      // ── Clic carte (mode select) ──
      if (mode === 'select') {
        card.addEventListener('click', function (e) {
          if (e.target.closest('.mc-help') || e.target.closest('.mc-tooltip')) return;
          handleSelectClick(card, grid);
        });
      }
    });

    // Sync initiale si des cartes sont déjà sélectionnées
    if (mode === 'select') syncHiddenField(grid);
  }

  // ── Init globale ─────────────────────────────────────────────
  function init() {
    document.querySelectorAll('.mc-grid').forEach(initGrid);

    // Ferme les tooltips au clic en dehors
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.mc-help') && !e.target.closest('.mc-tooltip')) {
        closeAllTooltips();
      }
    });
  }

  // ── API publique ─────────────────────────────────────────────
  window.MiniCard = {
    init,
    initGrid,
    closeAllTooltips,

    /**
     * Retourne les valeurs sélectionnées d'une grille.
     * @param {HTMLElement|string} gridOrSelector
     * @returns {string[]}
     */
    getSelected(gridOrSelector) {
      const grid = typeof gridOrSelector === 'string'
        ? document.querySelector(gridOrSelector)
        : gridOrSelector;
      if (!grid) return [];
      return [...grid.querySelectorAll('.mc-card.is-selected')].map(c => c.dataset.mcValue ?? '');
    },

    /**
     * Définit la sélection d'une grille par valeurs.
     * @param {HTMLElement|string} gridOrSelector
     * @param {string|string[]} values
     */
    setSelected(gridOrSelector, values) {
      const grid = typeof gridOrSelector === 'string'
        ? document.querySelector(gridOrSelector)
        : gridOrSelector;
      if (!grid) return;
      const vals = Array.isArray(values) ? values : [values];
      grid.querySelectorAll('.mc-card').forEach(c => {
        c.classList.toggle('is-selected', vals.includes(c.dataset.mcValue ?? ''));
      });
      syncHiddenField(grid);
    },

    /**
     * Filtre les cartes visibles selon un prédicat.
     * Utilisé pour les filtres type_bien → dépendances.
     * @param {HTMLElement|string} gridOrSelector
     * @param {function(HTMLElement):boolean} predicate
     */
    filterCards(gridOrSelector, predicate) {
      const grid = typeof gridOrSelector === 'string'
        ? document.querySelector(gridOrSelector)
        : gridOrSelector;
      if (!grid) return;
      grid.querySelectorAll('.mc-card').forEach(c => {
        const visible = predicate(c);
        c.style.display = visible ? '' : 'none';
      });
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
