'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const root = arbQs('#arb-compare');
  if (!root) return;

  arbInitModeToggle(root);
  arbInitPrint(root);

  const isDuel = root.dataset.duel === '1';
  if (!isDuel || !window.ArbCharts) return;

  const rows = arbQsa('tr.arb-row', root);
  if (rows.length < 2) return;

  const a = rows[0].dataset;
  const b = rows[1].dataset;

  const left = {
    cash: Number(a.cash || 0),
    rendement: Number(a.rendement || 0),
    cout: Number(a.cout || 0),
    score: Number(a.score || 0),
  };
  const right = {
    cash: Number(b.cash || 0),
    rendement: Number(b.rendement || 0),
    cout: Number(b.cout || 0),
    score: Number(b.score || 0),
  };

  const barsSvg = arbQs('[data-chart="duel-bars"] svg', root);
  const radarSvg = arbQs('[data-chart="duel-radar"] svg', root);

  if (barsSvg) window.ArbCharts.drawDuelBars(barsSvg, left, right);

  if (radarSvg) {
    const axesA = {
      rentabilite: arbClamp01(Number(a.rendement || 0) / 10),
      liquidite: arbClamp01(Number(a.liq || 0) / 5),
      risque: arbClamp01(1 - (Number(a.risk || 3) / 5)),
      travaux: (() => {
        const prix = Number(a.prix || 0);
        const travaux = Number(a.travaux || 0);
        const ratio = prix > 0 ? Math.min(1, travaux / (prix * 0.08)) : 0;
        return arbClamp01(1 - ratio);
      })(),
    };
    const axesB = {
      rentabilite: arbClamp01(Number(b.rendement || 0) / 10),
      liquidite: arbClamp01(Number(b.liq || 0) / 5),
      risque: arbClamp01(1 - (Number(b.risk || 3) / 5)),
      travaux: (() => {
        const prix = Number(b.prix || 0);
        const travaux = Number(b.travaux || 0);
        const ratio = prix > 0 ? Math.min(1, travaux / (prix * 0.08)) : 0;
        return arbClamp01(1 - ratio);
      })(),
    };
    window.ArbCharts.drawDuelRadar(radarSvg, axesA, axesB);
  }
});

