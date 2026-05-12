<?php
// agency_analyse_dpe.php — Analyse DPE (upload PDF + extraction + rattachement à un brouillon)
$current_page = 'analyse_dpe';
require_once __DIR__ . '/inc/init.php';
require_login();

$layout_title   = 'Analyse DPE';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$csrf = csrf_token('ajouter_bien');

ob_start();
?>

<style>
  .adpe-wrap{max-width:1100px;margin:0 auto;padding:18px 0 60px}
  .adpe-hero{background:linear-gradient(135deg,#fff 0%,#f7f8fa 100%);border-radius:18px;padding:18px 18px 14px;box-shadow:8px 8px 22px rgba(196,192,186,0.55),-8px -8px 22px #fff;margin-bottom:16px}
  .adpe-title{display:flex;align-items:center;gap:10px;margin:0}
  .adpe-title h1{font-size:18px;margin:0;font-weight:800;color:#1a1816}
  .adpe-sub{font-size:12px;color:#7a786f;margin-top:6px;line-height:1.5}

  .adpe-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width: 980px){.adpe-grid{grid-template-columns:1fr}}

  .card{background:#fff;border-radius:16px;padding:16px 16px 14px;box-shadow:0 3px 12px rgba(0,0,0,.06)}
  .card h3{font-size:13px;margin:0 0 10px;color:#1a1816}
  .muted{color:#8a8680;font-size:11px}

  .drop{border:2px dashed #d4d0ca;background:linear-gradient(180deg,#fff 0%,#faf8f4 100%);border-radius:16px;padding:20px;text-align:center;cursor:pointer;transition:all .18s}
  .drop:hover,.drop.over{border-color:#f59e0b;background:linear-gradient(180deg,#fff7ed 0%,#faf8f4 100%);transform:translateY(-1px)}
  .drop .ico{font-size:44px;line-height:1;margin-bottom:10px}
  .drop .t{font-size:14px;font-weight:800;color:#1a1816}
  .drop .s{font-size:12px;color:#7a786f;margin-top:4px}

  .row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}
  .btn{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:999px;padding:10px 14px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit}
  .btn.primary{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#1a1816}
  .btn.ghost{background:#fff;border:1px solid #e8e6e1;color:#4a4844}
  .btn:disabled{opacity:.55;cursor:not-allowed}

  .log{margin-top:12px;display:flex;flex-direction:column;gap:8px}
  .log-item{background:#fff;border-radius:12px;padding:10px 12px;display:flex;gap:10px;align-items:center;border-left:4px solid #d4d0ca;box-shadow:0 2px 10px rgba(0,0,0,.04)}
  .log-item.ok{border-left-color:#16a34a}
  .log-item.err{border-left-color:#dc2626}
  .log-item.work{border-left-color:#f59e0b}
  .log-name{font-size:12px;font-weight:700;color:#1a1816;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:420px}
  .log-meta{font-size:11px;color:#7a786f}
  .pill{margin-left:auto;font-size:10px;font-weight:900;padding:4px 10px;border-radius:999px;background:#f1f5f9;color:#334155}
  .pill.ok{background:#dcfce7;color:#166534}
  .pill.err{background:#fee2e2;color:#991b1b}
  .pill.work{background:#fef3c7;color:#92400e}

  .kv{display:grid;grid-template-columns:160px 1fr;gap:8px 12px;font-size:12px;margin-top:10px}
  .kv div{padding:6px 8px;border-radius:10px;background:#f8fafc}
  .kv .k{font-weight:800;color:#475569;background:transparent;padding:6px 2px}
  .kv .v{color:#0f172a}
  .warn{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:12px 14px;font-size:12px;color:#7a4b00}
  .warn strong{color:#1a1816}
</style>

<div class="adpe-wrap">
  <div class="adpe-hero">
    <div class="adpe-title">
      <div style="width:34px;height:34px;border-radius:12px;background:#fff7ed;color:#b45309;display:flex;align-items:center;justify-content:center;font-weight:900;">⚡</div>
      <h1>Analyse DPE / Diagnostics</h1>
    </div>
    <div class="adpe-sub">
      Dépose un PDF (texte ou scanné). L’IA extrait les champs et l’enregistre dans <code>dpe_diags</code> + <code>biens_documents</code>.
      Un brouillon de bien est créé automatiquement au 1er upload.
    </div>
  </div>

  <div class="adpe-grid">
    <div class="card">
      <h3>1) Déposer le PDF</h3>
      <div class="drop" id="drop">
        <div class="ico">📄</div>
        <div class="t">Glisser-déposer un ou plusieurs PDF</div>
        <div class="s">Max 20 Mo / PDF — OCR auto si scanné</div>
        <input type="file" id="file" accept="application/pdf,.pdf" multiple style="display:none">
      </div>
      <div class="row">
        <button class="btn primary" id="btnPick" type="button">Choisir des fichiers</button>
        <button class="btn ghost" id="btnOpenBien" type="button" disabled>Ouvrir le bien</button>
        <div class="muted" id="bienInfo">Aucun bien lié pour l’instant.</div>
      </div>
      <div class="log" id="log"></div>
    </div>

    <div class="card">
      <h3>2) Résultat (dernier fichier)</h3>
      <div id="resultEmpty" class="warn">
        <strong>Astuce</strong> : si tu as déjà copié des PDF serveur (ex: <code>uploads/DIAG ET DPE</code>), tu peux aussi utiliser le script bulk
        <code>scripts/diag_bulk_import.php</code> pour traiter des centaines de fichiers.
      </div>
      <div id="result" style="display:none">
        <div class="kv" id="kv"></div>
        <div class="row" style="margin-top:12px">
          <a class="btn primary" id="btnEdit" href="#" style="text-decoration:none">Éditer le bien</a>
          <a class="btn ghost" id="btnDoc" href="#" target="_blank" style="text-decoration:none">Voir le PDF</a>
        </div>
        <div class="muted" style="margin-top:10px" id="meta"></div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const csrfToken = <?= json_encode($csrf) ?>;
  let currentBienId = 0;
  let currentBienUrl = '';

  const drop = document.getElementById('drop');
  const fileInput = document.getElementById('file');
  const btnPick = document.getElementById('btnPick');
  const btnOpenBien = document.getElementById('btnOpenBien');
  const bienInfo = document.getElementById('bienInfo');
  const log = document.getElementById('log');
  const result = document.getElementById('result');
  const resultEmpty = document.getElementById('resultEmpty');
  const kv = document.getElementById('kv');
  const btnEdit = document.getElementById('btnEdit');
  const btnDoc = document.getElementById('btnDoc');
  const meta = document.getElementById('meta');

  function addLog(name, status, message) {
    const row = document.createElement('div');
    row.className = 'log-item ' + status;
    row.innerHTML = `
      <div style="min-width:0">
        <div class="log-name" title="${escapeHtml(name)}">${escapeHtml(name)}</div>
        <div class="log-meta">${escapeHtml(message || '')}</div>
      </div>
      <span class="pill ${status}">${status === 'work' ? 'Analyse…' : (status === 'ok' ? 'OK' : 'Erreur')}</span>
    `;
    log.prepend(row);
    return row;
  }

  function setRow(row, status, message) {
    row.className = 'log-item ' + status;
    const pill = row.querySelector('.pill');
    if (pill) {
      pill.className = 'pill ' + status;
      pill.textContent = status === 'work' ? 'Analyse…' : (status === 'ok' ? 'OK' : 'Erreur');
    }
    const m = row.querySelector('.log-meta');
    if (m) m.textContent = message || '';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  async function ensureBien() {
    if (currentBienId > 0) return currentBienId;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    const resp = await fetch('api/bien_create_draft.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken }});
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Création brouillon impossible');
    currentBienId = Number(data.bien_id || 0);
    currentBienUrl = data.url_edit || '';
    btnOpenBien.disabled = !(currentBienId > 0);
    bienInfo.textContent = currentBienId > 0 ? ('Bien brouillon #' + currentBienId) : 'Aucun bien lié pour l’instant.';
    if (currentBienUrl) {
      btnEdit.href = currentBienUrl;
      btnOpenBien.onclick = () => window.location.href = currentBienUrl;
    }
    return currentBienId;
  }

  function renderResult(data) {
    const f = data.fields || {};
    const pairs = [
      ['Adresse', (f.adresse_1 || '') + (f.code_postal || f.ville ? (' • ' + (f.code_postal || '') + ' ' + (f.ville || '')) : '')],
      ['Lot / situation', f.lot_principal || f.adresse_situation || '—'],
      ['DPE', f.dpe_classe ? (String(f.dpe_classe).toUpperCase()) : '—'],
      ['GES', f.ges_classe ? (String(f.ges_classe).toUpperCase()) : '—'],
      ['Date DPE', f.dpe_date_realisation || '—'],
      ['Conso (kWh)', f.dpe_valeur ?? '—'],
      ['Émissions (kg CO2)', f.ges_valeur ?? '—'],
      ['Version', f.dpe_version || '—'],
      ['Réf. ADEME', f.dpe_reference_certificat || '—'],
      ['Alertes', [
        f.plomb_present ? 'Plomb' : null,
        f.amiante_present ? 'Amiante' : null,
        f.electricite_anomalies ? 'Électricité' : null,
        f.gaz_anomalies ? 'Gaz' : null,
        f.termites ? 'Termites' : null,
        f.zone_georisque ? 'Géorisques' : null
      ].filter(Boolean).join(', ') || '—']
    ];
    kv.innerHTML = pairs.map(([k,v]) => `<div class="k">${escapeHtml(k)}</div><div class="v">${escapeHtml(String(v))}</div>`).join('');
    meta.textContent = `Méthode: ${data.method || '—'}${data.used_ocr ? ' (OCR)' : ''} • Score: ${data.score ?? '—'} • Champs: ${data.detected_count ?? (Object.keys(f).length)}`;
    if (data.fichier) btnDoc.href = data.fichier;
    resultEmpty.style.display = 'none';
    result.style.display = 'block';
  }

  async function uploadOne(file) {
    const row = addLog(file.name, 'work', 'Envoi…');
    try {
      const bienId = await ensureBien();
      const fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('fichier', file);
      fd.append('id_bien', String(bienId));
      const resp = await fetch('api/dpe_import_upload.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-Token': csrfToken }
      });
      const data = await resp.json();
      if (!data.ok) {
        setRow(row, 'err', data.error || 'Erreur');
        return;
      }
      const badge = data.used_ocr ? 'OCR' : (data.method || 'OK');
      setRow(row, 'ok', `${badge} • ${data.detected_count || 0} champs`);
      renderResult(data);
    } catch (e) {
      setRow(row, 'err', e.message || String(e));
    }
  }

  function handleFiles(list) {
    const files = Array.from(list || []).filter(f => f && (f.type === 'application/pdf' || (f.name || '').toLowerCase().endsWith('.pdf')));
    files.forEach(uploadOne);
  }

  drop.addEventListener('click', () => fileInput.click());
  btnPick.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => handleFiles(fileInput.files));

  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
})();
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>

