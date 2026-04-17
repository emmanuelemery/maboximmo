'use strict';

function arbSvgClear(svg) {
  while (svg.firstChild) svg.removeChild(svg.firstChild);
}

function arbSvgEl(tag, attrs = {}, children = []) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, String(v)));
  children.forEach((c) => el.appendChild(c));
  return el;
}

function arbClamp01(x) {
  const v = Number(x || 0);
  return Math.max(0, Math.min(1, v));
}

function arbDrawBars(svg, items, opts = {}) {
  const w = 360, h = Number(svg.getAttribute('viewBox')?.split(' ')[2] || 120);
  const pad = 14;
  const max = Math.max(1, ...items.map(i => Number(i.value || 0)));

  arbSvgClear(svg);

  const barW = (w - pad * 2) / Math.max(1, items.length);
  items.forEach((it, idx) => {
    const v = Number(it.value || 0);
    const bh = Math.max(2, (h - 44) * (v / max));
    const x = pad + idx * barW + 6;
    const y = h - 22 - bh;
    const color = it.color || '#36577d';

    svg.appendChild(arbSvgEl('rect', {
      x, y, width: Math.max(8, barW - 12), height: bh, rx: 8,
      fill: color,
      opacity: 0.9,
    }));
    svg.appendChild(arbSvgEl('text', {
      x: x + (barW - 12) / 2,
      y: h - 8,
      'text-anchor': 'middle',
      'font-size': 10,
      'font-weight': 800,
      fill: '#6b7280',
    }, [document.createTextNode(it.label || '')]));

    if (opts.showValues) {
      svg.appendChild(arbSvgEl('text', {
        x: x + (barW - 12) / 2,
        y: y - 6,
        'text-anchor': 'middle',
        'font-size': 10,
        'font-weight': 900,
        fill: '#111827',
      }, [document.createTextNode(it.valueLabel || '')]));
    }
  });
}

function arbDrawScenario(svg, scenario) {
  const conserver = Number(scenario?.conserver?.resultat_net || 0);
  const vendre = Number(scenario?.vendre?.impact_tresorerie || 0);
  const reinv = Number(scenario?.vendre_reinvestir?.impact_tresorerie || 0);

  arbDrawBars(svg, [
    { label: 'Conserver', value: Math.max(0, conserver), color: '#4a6038', valueLabel: arbFmtMoney(conserver) },
    { label: 'Vendre', value: Math.max(0, vendre), color: '#36577d', valueLabel: arbFmtMoney(vendre) },
    { label: 'Vendre+R', value: Math.max(0, reinv), color: '#b8922a', valueLabel: arbFmtMoney(reinv) },
  ], { showValues: true });
}

function arbDrawRadar(svg, axes) {
  const w = 360;
  const h = Number(svg.getAttribute('viewBox')?.split(' ')[3] || 160);
  const cx = w / 2;
  const cy = h / 2 + 8;
  const r = Math.min(w, h) * 0.34;

  arbSvgClear(svg);

  const labels = [
    { key: 'rentabilite', label: 'Rent.' },
    { key: 'liquidite', label: 'Liq.' },
    { key: 'risque', label: 'Risque' },
    { key: 'travaux', label: 'Trav.' },
  ];
  const n = labels.length;

  // Grid
  [0.33, 0.66, 1.0].forEach((k) => {
    const pts = labels.map((_, i) => {
      const a = (Math.PI * 2 * i) / n - Math.PI / 2;
      return [cx + Math.cos(a) * r * k, cy + Math.sin(a) * r * k];
    });
    svg.appendChild(arbSvgEl('path', {
      d: 'M ' + pts.map(p => p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' L ') + ' Z',
      fill: 'none',
      stroke: 'rgba(107,114,128,0.28)',
      'stroke-width': 1,
    }));
  });

  // Axes + labels
  labels.forEach((l, i) => {
    const a = (Math.PI * 2 * i) / n - Math.PI / 2;
    const x2 = cx + Math.cos(a) * r;
    const y2 = cy + Math.sin(a) * r;
    svg.appendChild(arbSvgEl('line', { x1: cx, y1: cy, x2, y2, stroke: 'rgba(107,114,128,0.32)' }));
    svg.appendChild(arbSvgEl('text', {
      x: cx + Math.cos(a) * (r + 16),
      y: cy + Math.sin(a) * (r + 16),
      'text-anchor': 'middle',
      'font-size': 10,
      'font-weight': 900,
      fill: '#6b7280',
    }, [document.createTextNode(l.label)]));
  });

  const valPts = labels.map((l, i) => {
    const v = arbClamp01(axes?.[l.key] ?? 0);
    const a = (Math.PI * 2 * i) / n - Math.PI / 2;
    return [cx + Math.cos(a) * r * v, cy + Math.sin(a) * r * v];
  });

  svg.appendChild(arbSvgEl('path', {
    d: 'M ' + valPts.map(p => p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' L ') + ' Z',
    fill: 'rgba(54,87,125,0.18)',
    stroke: 'rgba(54,87,125,0.7)',
    'stroke-width': 2,
  }));
}

function arbDrawDuelRadar(svg, aAxes, bAxes) {
  const w = 360;
  const h = Number(svg.getAttribute('viewBox')?.split(' ')[3] || 180);
  const cx = w / 2;
  const cy = h / 2 + 8;
  const r = Math.min(w, h) * 0.34;

  arbSvgClear(svg);

  const labels = [
    { key: 'rentabilite', label: 'Rent.' },
    { key: 'liquidite', label: 'Liq.' },
    { key: 'risque', label: 'Risque' },
    { key: 'travaux', label: 'Trav.' },
  ];
  const n = labels.length;

  [0.33, 0.66, 1.0].forEach((k) => {
    const pts = labels.map((_, i) => {
      const a = (Math.PI * 2 * i) / n - Math.PI / 2;
      return [cx + Math.cos(a) * r * k, cy + Math.sin(a) * r * k];
    });
    svg.appendChild(arbSvgEl('path', {
      d: 'M ' + pts.map(p => p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' L ') + ' Z',
      fill: 'none',
      stroke: 'rgba(107,114,128,0.28)',
      'stroke-width': 1,
    }));
  });

  labels.forEach((l, i) => {
    const a = (Math.PI * 2 * i) / n - Math.PI / 2;
    const x2 = cx + Math.cos(a) * r;
    const y2 = cy + Math.sin(a) * r;
    svg.appendChild(arbSvgEl('line', { x1: cx, y1: cy, x2, y2, stroke: 'rgba(107,114,128,0.32)' }));
    svg.appendChild(arbSvgEl('text', {
      x: cx + Math.cos(a) * (r + 16),
      y: cy + Math.sin(a) * (r + 16),
      'text-anchor': 'middle',
      'font-size': 10,
      'font-weight': 900,
      fill: '#6b7280',
    }, [document.createTextNode(l.label)]));
  });

  function poly(axes, fill, stroke) {
    const pts = labels.map((l, i) => {
      const v = arbClamp01(axes?.[l.key] ?? 0);
      const a = (Math.PI * 2 * i) / n - Math.PI / 2;
      return [cx + Math.cos(a) * r * v, cy + Math.sin(a) * r * v];
    });
    svg.appendChild(arbSvgEl('path', {
      d: 'M ' + pts.map(p => p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' L ') + ' Z',
      fill,
      stroke,
      'stroke-width': 2,
    }));
  }

  poly(aAxes, 'rgba(54,87,125,0.16)', 'rgba(54,87,125,0.75)');
  poly(bAxes, 'rgba(74,96,56,0.14)', 'rgba(74,96,56,0.75)');
}

function arbDrawDuelBars(svg, left, right) {
  const items = [
    { label: 'Cash', a: Number(left.cash || 0), b: Number(right.cash || 0) },
    { label: 'Rdt', a: Number(left.rendement || 0), b: Number(right.rendement || 0) },
    { label: 'Coût', a: Number(left.cout || 0), b: Number(right.cout || 0) },
    { label: 'Score', a: Number(left.score || 0), b: Number(right.score || 0) },
  ];

  const w = 360, h = 140, pad = 14;
  arbSvgClear(svg);

  const max = Math.max(1, ...items.flatMap(i => [i.a, i.b]));
  const rowH = (h - 20) / items.length;

  items.forEach((it, idx) => {
    const y = 10 + idx * rowH;
    const x0 = 86;
    const barW = w - x0 - pad;

    svg.appendChild(arbSvgEl('text', {
      x: 8, y: y + 14,
      'font-size': 10,
      'font-weight': 900,
      fill: '#6b7280',
    }, [document.createTextNode(it.label)]));

    const aw = barW * (it.a / max);
    const bw = barW * (it.b / max);
    svg.appendChild(arbSvgEl('rect', { x: x0, y: y + 4, width: Math.max(2, aw), height: 10, rx: 6, fill: '#36577d', opacity: .85 }));
    svg.appendChild(arbSvgEl('rect', { x: x0, y: y + 18, width: Math.max(2, bw), height: 10, rx: 6, fill: '#4a6038', opacity: .85 }));
  });
}

function arbDrawTresoTimeline(svg, points) {
  const w = 720, h = 160, padX = 18, padY = 18;
  arbSvgClear(svg);
  const max = Math.max(1, ...points.map(p => Number(p.value || 0)));

  svg.appendChild(arbSvgEl('rect', { x: 0, y: 0, width: w, height: h, fill: '#fff' }));
  const barW = (w - padX * 2) / Math.max(1, points.length);
  points.forEach((p, i) => {
    const v = Number(p.value || 0);
    const bh = (h - 44) * (v / max);
    const x = padX + i * barW + 3;
    const y = h - 22 - bh;
    svg.appendChild(arbSvgEl('rect', { x, y, width: Math.max(6, barW - 6), height: Math.max(2, bh), rx: 6, fill: '#4a6038', opacity: .85 }));
    if (i % 3 === 0) {
      svg.appendChild(arbSvgEl('text', { x: x + (barW - 6) / 2, y: h - 8, 'text-anchor': 'middle', 'font-size': 9, 'font-weight': 800, fill: '#9ca3af' }, [document.createTextNode(String(p.label || ''))]));
    }
  });
}

window.ArbCharts = {
  drawBars: arbDrawBars,
  drawScenario: arbDrawScenario,
  drawRadar: arbDrawRadar,
  drawDuelBars: arbDrawDuelBars,
  drawDuelRadar: arbDrawDuelRadar,
  drawTresoTimeline: arbDrawTresoTimeline,
};
