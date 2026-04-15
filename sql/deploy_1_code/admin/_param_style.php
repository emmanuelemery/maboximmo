<style>
  /* ── Styles partagés pages de paramétrage ── */
  .pa-back { font-size: 12px; color: var(--muted); text-decoration: none; }
  .pa-back:hover { color: var(--ink); }

  .pa-intro { font-size: 13px; color: var(--muted); margin: 0 0 20px; line-height: 1.6; }

  .pa-alert { padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
  .pa-alert-ok  { background: rgba(80,200,120,0.12); border: 1px solid rgba(80,200,120,0.3); color: #6ddc97; }
  .pa-alert-err { background: rgba(255,80,80,0.1);   border: 1px solid rgba(255,80,80,0.3);  color: #ff8f8f; }

  /* ── Bloc ajout custom ── */
  .pa-add-block {
    margin-top: 32px;
    padding: 20px 22px;
    border-radius: 14px;
    border: 1px dashed #ffffff;
    background: rgba(255,255,255,0.02);
  }
  .pa-add-title { font-size: 13px; font-weight: 700; color: var(--muted); margin-bottom: 14px; }

  .pa-add-form { display: flex; flex-direction: column; gap: 10px; }
  .pa-form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
  .pa-field     { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 140px; }
  .pa-field-wide{ flex: 2; min-width: 240px; }
  .pa-field-small{ flex: 0 0 80px; min-width: 80px; }
  .pa-field label { font-size: 11px; font-weight: 600; color: var(--muted); }
  .pa-field input,
  .pa-field textarea,
  .pa-field select {
    padding: 8px 10px; border-radius: 8px;
    border: 1px solid #ffffff;
    background: #ffffff;
    color: var(--ink); font-size: 12px; font-family: inherit;
    outline: none; resize: vertical;
  }
  .pa-field input:focus,
  .pa-field textarea:focus,
  .pa-field select:focus {
    border-color: rgba(72,120,166,0.25);
    background: rgba(102,217,255,0.06);
  }

  .pa-btn-add    { padding: 9px 20px; border-radius: 8px; border: none; background: rgba(72,120,166,0.12); color: rgba(102,217,255,1); font-size: 12px; font-weight: 700; cursor: pointer; align-self: flex-start; }
  .pa-btn-add:hover { background: rgba(102,217,255,0.32); }
  .pa-btn-cancel { padding: 9px 20px; border-radius: 8px; border: 1px solid #ffffff; background: transparent; color: var(--muted); font-size: 12px; font-weight: 600; cursor: pointer; }
  .pa-btn-cancel:hover { color: var(--ink); }

  /* ── Modal édition ── */
  .pa-modal {
    position: fixed; inset: 0; z-index: 999;
    background: rgba(0,0,0,0.6);
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
  }
  .pa-modal-box {
    background: #1a1f2e; border-radius: 16px;
    border: 1px solid #ffffff;
    padding: 28px 28px 24px; width: 540px; max-width: 95vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  }
  .pa-modal-title { font-size: 16px; font-weight: 700; margin-bottom: 18px; }

  /* ── Onglets (chauffage page) ── */
  .pa-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1px solid #ffffff; padding-bottom: 0; }
  .pa-tab {
    padding: 9px 18px; border-radius: 8px 8px 0 0;
    border: 1px solid transparent;
    background: transparent; color: var(--muted);
    font-size: 13px; font-weight: 600; cursor: pointer;
    margin-bottom: -1px; position: relative;
  }
  .pa-tab.active {
    background: rgba(72,120,166,0.08);
    border-color: #ffffff; border-bottom-color: #1a1f2e;
    color: var(--ink);
  }
  .pa-panel { display: none; }
  .pa-panel.active { display: block; }

  /* ── Table liaisons ── */
  .pa-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 12px; }
  .pa-table th { text-align: left; padding: 8px 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); border-bottom: 1px solid #ffffff; }
  .pa-table td { padding: 8px 12px; border-bottom: 1px solid #ffffff; vertical-align: top; }
  .pa-table tr:last-child td { border-bottom: none; }
  .pa-table tr:hover td { background: rgba(255,255,255,0.025); }

  /* ── Chips de liaison ── */
  .pa-chips { display: flex; flex-wrap: wrap; gap: 5px; }
  .pa-chip {
    padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 600;
    border: 1px solid #ffffff; background: #ffffff; color: var(--muted);
    cursor: pointer; transition: .15s;
  }
  .pa-chip.on  { background: rgba(72,120,166,0.1); border-color: rgba(72,120,166,0.25); color: rgba(102,217,255,1); }
  .pa-chip:hover { border-color: rgba(72,120,166,0.2); color: rgba(102,217,255,0.8); }
</style>
