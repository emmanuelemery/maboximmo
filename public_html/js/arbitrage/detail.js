'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const root = arbQs('#arb-detail');
  if (!root) return;

  arbInitModeToggle(root);
  arbInitPrint(root);

  const bienId = Number(root.dataset.bienId || 0);
  const autosaveUrl = root.dataset.autosaveUrl || '';
  const saveStateEl = arbQs('[data-save-state]', root);

  function setChoice(name, value) {
    arbQsa(`[data-choice="${name}"]`, root).forEach((b) => {
      b.classList.toggle('is-on', b.dataset.value === value);
    });
  }

  function getChoiceValue(name) {
    const on = arbQs(`[data-choice="${name}"].is-on`, root);
    return on?.dataset.value || '';
  }

  function getTravauxTags() {
    return arbQsa('[data-tags] .arb-tag.is-on', root).map(b => b.dataset.tag).filter(Boolean);
  }

  function collectPayload(extra = {}) {
    const payload = { id_bien: bienId, ...extra };
    // choices
    ['decision', 'posture', 'statut_locatif', 'travaux_niveau'].forEach((k) => {
      const v = getChoiceValue(k);
      if (v) payload[k] = v;
      else payload[k] = null;
    });
    // tags
    payload.travaux_tags = getTravauxTags();
    // fields
    arbQsa('[data-field]', root).forEach((el) => {
      const k = el.dataset.field;
      if (!k) return;
      payload[k] = el.value;
    });
    return payload;
  }

  function updateKpis(metrics) {
    if (!metrics) return;
    const set = (k, txt) => {
      const el = arbQs(`[data-kpi="${k}"]`, root);
      if (el) el.textContent = txt;
    };
    set('prix', arbFmtMoney(metrics.prix_vente_realiste || 0));
    set('cout_annuel', arbFmtMoney(metrics.cout_annuel || 0));
    set('rendement', arbFmtPct(metrics.rendement_reel_pct || 0));
    set('score', String(metrics.score_strategique || 0) + '/100');
  }

  function updateTexts(texts) {
    if (!texts) return;
    const syn = arbQs('[data-text="synthese"]', root);
    const arg = arbQs('[data-text="argumentaire"]', root);
    if (syn && typeof texts.synthese_auto === 'string') syn.textContent = texts.synthese_auto;
    if (arg && typeof texts.argumentaire_final === 'string') arg.textContent = texts.argumentaire_final;
  }

  function updateCrg(signals) {
    const wrap = arbQs('[data-crg-cards]', root);
    if (!wrap) return;
    wrap.innerHTML = '';
    (signals || []).slice(0, 10).forEach((s) => {
      const sev = s.severity || 'info';
      const card = document.createElement('div');
      card.className = 'arb-crg-card arb-sev-' + sev;
      const t = document.createElement('div');
      t.className = 'arb-crg-title';
      t.textContent = s.title || '';
      const v = document.createElement('div');
      v.className = 'arb-crg-value';
      v.textContent = s.value || '';
      card.appendChild(t);
      card.appendChild(v);
      wrap.appendChild(card);
    });
  }

  function updateCharts(metrics) {
    if (!metrics || !window.ArbCharts) return;

    const coutsSvg = arbQs('[data-chart="couts"] svg', root);
    const scSvg = arbQs('[data-chart="scenarios"] svg', root);
    const radarSvg = arbQs('[data-chart="radar"] svg', root);

    if (coutsSvg) {
      const manqueAn = (Number(metrics.manque_a_gagner_mensuel || 0) * 12);
      window.ArbCharts.drawBars(coutsSvg, [
        { label: 'Coût/an', value: Number(metrics.cout_annuel || 0), color: '#cc5c58', valueLabel: arbFmtMoney(metrics.cout_annuel || 0) },
        { label: 'Vacance', value: Number(metrics.cout_cumule_vacance || 0), color: '#f59e0b', valueLabel: arbFmtMoney(metrics.cout_cumule_vacance || 0) },
        { label: 'Travaux', value: Number(metrics.travaux_total || 0), color: '#36577d', valueLabel: arbFmtMoney(metrics.travaux_total || 0) },
        { label: 'Manque', value: manqueAn, color: '#b8922a', valueLabel: arbFmtMoney(manqueAn) },
      ], { showValues: false });
    }

    if (scSvg) {
      window.ArbCharts.drawScenario(scSvg, metrics.projection_18m || {});
    }

    if (radarSvg) {
      const prix = Number(metrics.prix_vente_realiste || 0);
      const travaux = Number(metrics.travaux_total || 0);
      const travauxPenalty = prix > 0 ? Math.min(1, travaux / (prix * 0.08)) : 0;
      window.ArbCharts.drawRadar(radarSvg, {
        rentabilite: arbClamp01(Number(metrics.rendement_reel_pct || 0) / 10),
        liquidite: arbClamp01(Number(metrics.liquidite_niveau || 0) / 5),
        risque: arbClamp01(1 - (Number(metrics.risque_niveau || 3) / 5)),
        travaux: arbClamp01(1 - travauxPenalty),
      });
    }
  }

  const autosave = arbDebounce(async (extra = {}) => {
    if (!autosaveUrl || bienId <= 0) return;
    try {
      arbSetSaveState(saveStateEl, 'Sauvegarde…');
      const json = await arbPostJson(autosaveUrl, collectPayload(extra));
      arbSetSaveState(saveStateEl, 'Sauvegardé ' + (json.saved_at || ''));
      updateKpis(json.metrics);
      updateCharts(json.metrics);
      updateCrg(json.crg?.signals || []);
      updateTexts(json.texts);
    } catch (e) {
      arbSetSaveState(saveStateEl, (e?.message || 'Erreur sauvegarde'), true);
    }
  }, 350);

  // Choices
  root.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-choice]');
    if (btn) {
      const name = btn.dataset.choice;
      const val = btn.dataset.value;
      setChoice(name, val);
      autosave();
    }

    const tag = e.target.closest('[data-tag]');
    if (tag) {
      tag.classList.toggle('is-on');
      autosave();
    }

    const copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      const key = copyBtn.dataset.copy;
      const el = arbQs(`[data-text="${key}"]`, root);
      const text = el ? el.textContent : '';
      arbCopyText(text).then((ok) => {
        if (!ok) return;
        const old = copyBtn.textContent;
        copyBtn.textContent = 'Copié';
        setTimeout(() => { copyBtn.textContent = old; }, 900);
      });
    }

    const regen = e.target.closest('[data-regen-ai]');
    if (regen) {
      autosave({ regen_ai: 1 });
    }
  });

  // Fields
  root.addEventListener('input', (e) => {
    const el = e.target.closest('[data-field]');
    if (!el) return;
    autosave();
  });
});

