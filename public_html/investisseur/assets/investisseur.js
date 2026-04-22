/* ═══════════════════════════════════════════════════════════════════════════
   Module Analyse Investisseur — JS
   - Mini-charts Chart.js avec zoom-au-clic (modal grand format)
   - Onglets argumentaires (prudent / équilibré / offensif)
   - Étoiles de notation 1-5 (radio stylisé)
   ═══════════════════════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    const CHART_PALETTE = [
        '#24324a', // navy
        '#4f7a3a', // vert foncé
        '#7ba056', // vert tendre
        '#d9b13a', // jaune
        '#d97a3a', // orange
        '#b4443a', // rouge
        '#4878a6', // bleu
        '#7a6898', // violet
        '#2d5f6b', // teal
        '#9a9690', // gris
    ];

    // ── Chart.js est-il dispo ? ───────────────────────────────────────
    function ensureChartJs(cb) {
        if (window.Chart) return cb();
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    // ── Factory d'un mini-chart à partir d'un <canvas data-inv-chart="..."> ─
    function buildChart(canvas) {
        const kind   = canvas.dataset.invChart;           // 'doughnut' | 'bar' | 'radar'
        const labels = safeJson(canvas.dataset.labels);
        const values = safeJson(canvas.dataset.values);
        const title  = canvas.dataset.title || '';

        if (!labels || !values) return null;

        const base = {
            type: kind,
            data: {
                labels,
                datasets: [{
                    label: title,
                    data: values,
                    backgroundColor: kind === 'doughnut'
                        ? labels.map((_, i) => CHART_PALETTE[i % CHART_PALETTE.length])
                        : 'rgba(36,50,74,0.75)',
                    borderColor: '#fff',
                    borderWidth: kind === 'doughnut' ? 2 : 0,
                    borderRadius: kind === 'bar' ? 6 : 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: kind !== 'doughnut',
                        labels: { font: { size: 10 }, color: '#5a5a55' }
                    },
                    tooltip: { enabled: true }
                },
                scales: kind === 'radar'
                    ? { r: { angleLines: { color: '#e6e1d7' }, grid: { color: '#e6e1d7' },
                             pointLabels: { font: { size: 10 }, color: '#5a5a55' },
                             ticks: { display: false }, suggestedMin: 0, suggestedMax: 100 } }
                    : (kind === 'bar'
                        ? { x: { ticks: { font: { size: 9 }, color: '#5a5a55' }, grid: { display: false } },
                            y: { ticks: { font: { size: 9 }, color: '#9a9690' }, grid: { color: '#f0ede6' }, beginAtZero: true } }
                        : {})
            }
        };

        try {
            return new window.Chart(canvas.getContext('2d'), base);
        } catch (e) {
            console.error('inv chart error', e);
            return null;
        }
    }

    function safeJson(raw) {
        if (!raw) return null;
        try { return JSON.parse(raw); } catch (e) { return null; }
    }

    // ── Modal : clic sur un mini-chart → ouvre en grand ───────────────
    let modalChart = null;
    function openChartModal(sourceCanvas) {
        const modal = document.getElementById('inv-chart-modal');
        if (!modal) return;
        const title = sourceCanvas.dataset.title || 'Graphique';
        modal.querySelector('.inv-chart-modal-title').textContent = title;

        const bigCanvas = modal.querySelector('canvas');
        if (modalChart) { modalChart.destroy(); modalChart = null; }

        const clone = sourceCanvas.cloneNode(false);
        clone.removeAttribute('style');
        modalChart = buildChart(Object.assign(clone, {
            getContext: () => bigCanvas.getContext('2d')
        }));
        // Alternative simple : reconstruire depuis les datasets
        if (!modalChart) {
            const kind   = sourceCanvas.dataset.invChart;
            const labels = safeJson(sourceCanvas.dataset.labels);
            const values = safeJson(sourceCanvas.dataset.values);
            modalChart = new window.Chart(bigCanvas.getContext('2d'), {
                type: kind,
                data: {
                    labels,
                    datasets: [{
                        label: title,
                        data: values,
                        backgroundColor: kind === 'doughnut'
                            ? labels.map((_, i) => CHART_PALETTE[i % CHART_PALETTE.length])
                            : 'rgba(36,50,74,0.75)',
                        borderColor: '#fff',
                        borderWidth: kind === 'doughnut' ? 2 : 0,
                        borderRadius: kind === 'bar' ? 6 : 0,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { font: { size: 13 } } },
                        tooltip: { enabled: true }
                    },
                    scales: kind === 'radar'
                        ? { r: { suggestedMin: 0, suggestedMax: 100 } }
                        : (kind === 'bar' ? { y: { beginAtZero: true } } : {})
                }
            });
        }

        modal.classList.add('open');
    }

    function closeChartModal() {
        const modal = document.getElementById('inv-chart-modal');
        if (!modal) return;
        modal.classList.remove('open');
        if (modalChart) { modalChart.destroy(); modalChart = null; }
    }

    // ── Onglets argumentaires ─────────────────────────────────────────
    function initTabs() {
        document.querySelectorAll('[data-inv-tabs]').forEach(group => {
            const tabs = group.querySelectorAll('.inv-tab');
            const panes = document.querySelectorAll('[data-inv-tab-pane]');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    const key = tab.dataset.tab;
                    panes.forEach(p => p.classList.toggle('active', p.dataset.invTabPane === key));
                });
            });
        });
    }

    // ── Étoiles de notation ───────────────────────────────────────────
    function initRatings() {
        document.querySelectorAll('.inv-rating').forEach(rating => {
            const radios = rating.querySelectorAll('input[type="radio"]');
            const stars  = rating.querySelectorAll('label.star');
            function paint() {
                let selected = -1;
                radios.forEach((r, i) => { if (r.checked) selected = i; });
                stars.forEach((s, i) => s.classList.toggle('active', i <= selected));
            }
            radios.forEach(r => r.addEventListener('change', paint));
            paint();
        });
    }

    // ── Injection modal ───────────────────────────────────────────────
    function injectModal() {
        if (document.getElementById('inv-chart-modal')) return;
        const div = document.createElement('div');
        div.id = 'inv-chart-modal';
        div.className = 'inv-chart-modal';
        div.innerHTML = `
            <div class="inv-chart-modal-inner">
                <button type="button" class="close" aria-label="Fermer">&times;</button>
                <h3 class="inv-chart-modal-title">Graphique</h3>
                <div class="inv-chart-modal-canvas"><canvas></canvas></div>
            </div>`;
        document.body.appendChild(div);
        div.querySelector('.close').addEventListener('click', closeChartModal);
        div.addEventListener('click', e => { if (e.target === div) closeChartModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeChartModal(); });
    }

    // ── Boot ──────────────────────────────────────────────────────────
    function boot() {
        injectModal();
        initTabs();
        initRatings();

        const canvases = document.querySelectorAll('canvas[data-inv-chart]');
        if (!canvases.length) return;

        ensureChartJs(() => {
            canvases.forEach(c => {
                buildChart(c);
                const wrap = c.closest('.inv-chart-wrap');
                if (wrap) wrap.addEventListener('click', () => openChartModal(c));
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
