'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const root = arbQs('#arb-plan');
  if (!root) return;

  arbInitModeToggle(root);
  arbInitPrint(root);

  const saveUrl = root.dataset.settingsSaveUrl || '';
  const saveStateEl = arbQs('[data-save-state]', root);

  const objectifEl = arbQs('[data-setting="objectif_tresorerie"]', root);
  const horizonEl = arbQs('[data-setting="horizon_mois"]', root);
  const rendementEl = arbQs('[data-setting="rendement_reinvest_cible_pct"]', root);

  const fill = arbQs('[data-progress-fill]', root);
  const kpiCash = arbQs('[data-kpi="cash_sell"]', root);
  const kpiObj = arbQs('[data-kpi="objectif"]', root);
  const kpiTaux = arbQs('[data-kpi="taux"]', root);
  const progressBar = arbQs('.arb-progress-bar', root);
  const chartSvg = arbQs('[data-chart="treso"] svg', root);

  function getSelectedRows() {
    return arbQsa('tr.arb-row', root).filter((tr) => tr.querySelector('[data-select]')?.checked);
  }

  function computeSelectedCash() {
    return getSelectedRows().reduce((sum, tr) => sum + Number(tr.dataset.cash || 0), 0);
  }

  function updateProgress() {
    const objectif = Number(objectifEl?.value || 0) || 0;
    const cash = computeSelectedCash();
    const pct = objectif > 0 ? Math.min(1, cash / objectif) : 0;
    if (fill) fill.style.width = (pct * 100).toFixed(1) + '%';
    if (kpiCash) kpiCash.textContent = arbFmtMoney(cash);
    if (kpiObj) kpiObj.textContent = arbFmtMoney(objectif);
    if (kpiTaux) kpiTaux.textContent = (pct * 100).toFixed(1).replace('.', ',') + ' %';
    if (progressBar) progressBar.setAttribute('aria-valuenow', String(cash));

    // Timeline (simple) : accumulation linéaire sur horizon
    const horizon = Math.max(6, Math.min(60, Number(horizonEl?.value || 18) || 18));
    if (chartSvg && window.ArbCharts) {
      const points = [];
      for (let m = 1; m <= horizon; m++) {
        points.push({ label: 'M' + m, value: (cash / horizon) * m });
      }
      window.ArbCharts.drawTresoTimeline(chartSvg, points);
    }
  }

  root.addEventListener('change', (e) => {
    if (e.target.closest('[data-select]')) updateProgress();
  });

  // Save settings
  arbQs('[data-save-settings]', root)?.addEventListener('click', async () => {
    if (!saveUrl) return;
    try {
      arbSetSaveState(saveStateEl, 'Sauvegarde…');
      const json = await arbPostJson(saveUrl, {
        objectif_tresorerie: Number(objectifEl?.value || 0),
        horizon_mois: Number(horizonEl?.value || 18),
        rendement_reinvest_cible_pct: Number(rendementEl?.value || 6),
      });
      arbSetSaveState(saveStateEl, 'Sauvegardé');
      if (json?.settings) {
        objectifEl.value = json.settings.objectif_tresorerie;
        horizonEl.value = json.settings.horizon_mois;
        rendementEl.value = json.settings.rendement_reinvest_cible_pct;
      }
      updateProgress();
    } catch (e) {
      arbSetSaveState(saveStateEl, (e?.message || 'Erreur'), true);
    }
  });

  updateProgress();
});

