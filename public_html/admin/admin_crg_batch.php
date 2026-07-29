<?php
declare(strict_types=1);
/**
 * admin/admin_crg_batch.php — Chargement de MASSE des CRG par dossier.
 *
 * Layout agency standard (agency_layout_top/bottom → sidebar + topbar MBI).
 *
 * 2 modes :
 *   • PROD : on SÉLECTIONNE un dossier dans le navigateur → les PDF montent sur
 *     le serveur et sont traités par GPT-4o.
 *   • LOCAL : chemin d'un dossier déjà sur le serveur (parser Python gratuit).
 *
 * Propriétaire déduit du NOM DE FICHIER (partie avant l'année), sinon du dossier.
 * Case à cocher par ligne, Pause/Stop, détection de doublons. Écrit via le cœur
 * partagé (inc/crg_import_core.php) → bien_baux + GED. Réservé aux admins.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_admin_or_super_admin();

$csrf = csrf_token('default');

// Agences (pour rattacher le lot CRG à son agence gestionnaire — LYON par défaut).
$agences = $pdo->query("SELECT id, nom_agence FROM agences WHERE actif=1 ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
$agenceDefaut = 0;
foreach ($agences as $a) { if (stripos($a['nom_agence'], 'LYON') !== false) { $agenceDefaut = (int)$a['id']; break; } }

$pageTitle    = '📦 CRG en masse';
$pageSubtitle = 'Ma Box Agency · Import CRG par dossier';
$extraCss = <<<'CSS'
<style>
  .crgb { --navy:#243B5C; --gold:#D4A047; --line:#e8edf3; }
  .crgb .card { background:#fff; border-radius:14px; box-shadow:0 2px 14px rgba(36,59,92,.07); padding:22px; margin-bottom:20px; border:1px solid var(--line); }
  .crgb .muted { color:#6b7a90; font-size:13.5px; line-height:1.5; }
  .crgb code { background:#eef2f7; padding:1px 6px; border-radius:5px; font-size:12.5px; color:var(--navy); }
  .crgb input[type=text] { width:60%; padding:11px 13px; border:1px solid #cbd5e1; border-radius:9px; font-size:14px; }
  .crgb input[type=file] { padding:10px; border:1.5px dashed #c3cfdd; border-radius:9px; background:#fafcff; width:60%; }
  .crgb .btn { border:0; padding:11px 18px; border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; color:#fff; background:var(--navy); transition:filter .15s; }
  .crgb .btn:hover { filter:brightness(1.08); }
  .crgb .btn.gold { background:var(--gold); color:var(--navy); }
  .crgb .btn:disabled { opacity:.45; cursor:default; filter:none; }
  .crgb .tabs { display:flex; gap:8px; margin-bottom:16px; }
  .crgb .tabs button { background:#eef2f7; color:var(--navy); border:1px solid var(--line); border-radius:9px; padding:9px 15px; font-weight:600; cursor:pointer; }
  .crgb .tabs button.active { background:var(--navy); color:#fff; border-color:var(--navy); }
  .crgb table { width:100%; border-collapse:collapse; font-size:13.5px; }
  .crgb th, .crgb td { text-align:left; padding:9px 10px; border-bottom:1px solid var(--line); }
  .crgb th { color:#6b7a90; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px; }
  .crgb tbody tr:hover { background:#f8fafc; }
  .crgb td.chk, .crgb th.chk { width:40px; text-align:center; }
  .crgb input[type=checkbox] { width:17px; height:17px; accent-color:var(--navy); cursor:pointer; }
  .crgb .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
  .crgb .b-new { background:#e0ecff; color:#1e40af; }
  .crgb .b-dup { background:#fdecc0; color:#92600e; }
  .crgb .b-ok  { background:#cdf5d8; color:#166534; }
  .crgb .b-err { background:#fdd5d5; color:#991b1b; }
  .crgb .b-skip{ background:#e6ebf1; color:#52627a; }
  .crgb .bar { height:12px; background:#e6ebf1; border-radius:7px; overflow:hidden; margin:14px 0; }
  .crgb .bar > div { height:100%; width:0; background:linear-gradient(90deg,var(--gold),#e6b968); transition:width .25s; }
  .crgb #log { max-height:340px; overflow:auto; font-family:ui-monospace,'Cascadia Code',monospace; font-size:12.5px; line-height:1.55; }
  .crgb .toolbar { display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:6px; }
  .crgb .lbl { font-size:13px; color:var(--navy); font-weight:600; display:inline-flex; align-items:center; gap:6px; }
  .crgb h2 { font-size:15px; margin:0 0 12px; color:var(--navy); }
</style>
CSS;
require_once __DIR__ . '/../inc/agency_layout_top.php';
?>

<div class="crgb">
  <div class="card">
    <div class="tabs">
      <button id="tabProd" class="active" onclick="setMode('prod')">🌐 Prod — choisir un dossier</button>
      <button id="tabLocal" onclick="setMode('local')">💻 Local — chemin serveur (Python)</button>
    </div>

    <div id="modeProd">
      <p class="muted">Sélectionne un <b>dossier de base</b>. Le propriétaire est déduit du <b>nom de fichier</b> (partie avant l'année, ex. <code>Monsieur_QU_XINLIANG_YANG_2026_T1…</code>) ; à défaut, du <b>nom du sous-dossier</b> (ex. <code>EMERY IMMO</code>). Les PDF montent sur le serveur et sont traités par l'IA. Trimestre déduit du nom de fichier. Doublons détectés et pré-décochés.</p>
      <div class="toolbar">
        <input type="file" id="folderPick" webkitdirectory directory multiple>
        <button class="btn" id="btnPick" onclick="pickScan()">🔍 Analyser la sélection</button>
      </div>
    </div>

    <div id="modeLocal" style="display:none">
      <p class="muted">Chemin d'un dossier <b>déjà présent sur le serveur</b> (mode local, parser Python gratuit).</p>
      <div class="toolbar">
        <input type="text" id="basePath" placeholder="C:\xampp\htdocs\...\CRG 2026">
        <button class="btn" id="btnScan" onclick="scan()">🔍 Scanner</button>
      </div>
    </div>
  </div>

  <div class="card" id="scanCard" style="display:none">
    <div class="toolbar">
      <span id="summary" class="muted" style="flex:1"></span>
      <label class="lbl" title="Détectée automatiquement dans l'entête du CRG (code postal). Ce choix ne sert que si la détection échoue.">Agence (secours)&nbsp;:
        <select id="agenceSel" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13.5px">
          <?php foreach ($agences as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === $agenceDefaut ? 'selected' : '') ?>><?= htmlspecialchars($a['nom_agence']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="lbl" title="Force la période de TOUT le lot (utile quand des PDF portent une date d'édition en juillet alors que le CRG est arrêté au 30/06 = T2). Laisse « auto » pour lire la date de chaque PDF.">Forcer&nbsp;:
        <select id="forcePeriode" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13.5px">
          <option value="" selected>auto (date PDF)</option>
          <option value="2026-1">2026 · T1</option>
          <option value="2026-2">2026 · T2 (30/06)</option>
          <option value="2026-3">2026 · T3</option>
          <option value="2026-4">2026 · T4</option>
        </select>
      </label>
      <label class="lbl"><input type="checkbox" id="allChk" checked onchange="toggleAll(this.checked)"> tout cocher</label>
      <button class="btn gold" id="btnRun" onclick="run()" disabled>▶️ Importer la sélection</button>
      <button class="btn" id="btnPause" onclick="togglePause()" style="display:none;background:#6b7a90">⏸ Pause</button>
      <button class="btn" id="btnStop" onclick="stopRun()" style="display:none;background:#991b1b">⏹ Stop</button>
    </div>
    <div class="bar"><div></div></div>
    <span id="progress" class="muted"></span>
    <div style="margin-top:12px; overflow:auto; max-height:340px">
      <table id="tbl"><thead><tr><th class="chk">✔</th><th>Propriétaire</th><th>Fichier</th><th>Période</th><th>Statut</th></tr></thead><tbody></tbody></table>
    </div>
  </div>

  <div class="card" id="logCard" style="display:none">
    <h2>Journal</h2>
    <div id="log"></div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
// URL ABSOLUE de l'API : la balise <base href> du layout casse les URLs relatives
// (« ../api/… » se résout vers /MaBoxImmo2026/api sans public_html → 404 → HTML).
const API_URL = <?= json_encode(app_url('/api/import_crg_batch.php')) ?>;
let ITEMS = [];
let MODE = 'prod';
let PAUSED=false, STOP=false;
const sleep = ms => new Promise(r=>setTimeout(r,ms));

function setMode(m){
  MODE = m;
  document.getElementById('tabProd').classList.toggle('active', m==='prod');
  document.getElementById('tabLocal').classList.toggle('active', m==='local');
  document.getElementById('modeProd').style.display  = m==='prod'  ? '' : 'none';
  document.getElementById('modeLocal').style.display = m==='local' ? '' : 'none';
}

function trimFromName(name){
  const m = name.match(/(\d{4})(\d{2})(\d{2})/);
  if(m){ const mois=parseInt(m[2],10); const map={3:1,6:2,9:3,12:4}; return {annee:parseInt(m[1],10), trim:map[mois]||Math.ceil(mois/3)}; }
  const m2 = name.match(/(20\d\d)[_\-\s]+T([1-4])/i);
  if(m2) return {annee: parseInt(m2[1],10), trim: parseInt(m2[2],10)};
  return {annee:0, trim:0};
}

function proprioFromName(name, folder){
  const base = name.replace(/\.[^.]+$/,'');
  const m = base.match(/^(.*?)[_\-\s]+(20\d\d)(?=[_\-\s.]|$)/);
  if(m){
    let n = m[1].replace(/[_\-]+/g,' ').trim();
    n = n.replace(/^(monsieur et madame|mr et mme|m\.? et mme|madame|monsieur|mademoiselle|melle|mme|mlle|mr|m\.)\s+/i,'').trim();
    if(n.length>=3 && !/^\d+$/.test(n)) return n;
  }
  return folder||'';
}

function AGENCE_ID(){ const s=document.getElementById('agenceSel'); return s ? s.value : ''; }
// Période forcée pour tout le lot (select « Forcer »). Renvoie {annee,trim} ou null (=auto).
function FORCE_PER(){ const s=document.getElementById('forcePeriode'); if(!s||!s.value) return null; const m=/^(\d{4})-(\d)$/.exec(s.value); return m ? {annee:parseInt(m[1],10), trim:parseInt(m[2],10)} : null; }

function post(action, params){
  const fd = new FormData();
  fd.append('action', action);
  for(const k in params) fd.append(k, params[k]);
  return fetch(API_URL, {method:'POST', headers:{'X-CSRF-Token':CSRF}, body:fd, credentials:'same-origin'}).then(r=>r.json());
}

function renderTable(){
  const tb = document.querySelector('#tbl tbody'); tb.innerHTML='';
  ITEMS.forEach((it,i)=>{
    const tr = document.createElement('tr'); tr.id='row'+i;
    const per = it.period.annee ? ('T'+it.period.trim+' '+it.period.annee) : '—';
    const st = it.doublon ? '<span class="badge b-dup">doublon</span>' : '<span class="badge b-new">à importer</span>';
    const checked = it.doublon ? '' : 'checked';
    tr.innerHTML = `<td class="chk"><input type="checkbox" id="chk${i}" ${checked} onchange="syncSummary()"></td>`
      + `<td>${it.proprio||'<i>?</i>'}</td><td>${it.file}</td><td>${per}</td><td id="stt${i}">${st}</td>`;
    tb.appendChild(tr);
  });
  document.getElementById('scanCard').style.display='';
  document.getElementById('btnRun').disabled = ITEMS.length===0;
  syncSummary();
}

function toggleAll(v){ ITEMS.forEach((_,i)=>{ const c=document.getElementById('chk'+i); if(c) c.checked=v; }); syncSummary(); }
function countChecked(){ return ITEMS.reduce((n,_,i)=>{ const c=document.getElementById('chk'+i); return n+(c&&c.checked?1:0); },0); }
function syncSummary(){
  const sel = countChecked();
  const dup = ITEMS.filter(i=>i.doublon).length;
  document.getElementById('summary').innerHTML = `<b>${ITEMS.length}</b> fichiers · <b>${sel}</b> coché(s) · ${dup} doublon(s)`;
}

function pickScan(){
  const files = Array.from(document.getElementById('folderPick').files || []);
  const pdfs = files.filter(f => f.name.toLowerCase().endsWith('.pdf'));
  if(!pdfs.length){ alert('Aucun PDF dans la sélection.'); return; }
  ITEMS = pdfs.map(f => {
    const rel = f.webkitRelativePath || f.name;
    const parts = rel.split('/');
    const folder = parts.length > 1 ? parts[parts.length-2] : '';
    const proprio = proprioFromName(f.name, folder);
    return { proprio, file: f.name, period: trimFromName(f.name), fileObj: f, doublon:false };
  });
  renderTable();
}

async function scan(){
  const bp = document.getElementById('basePath').value.trim();
  if(!bp){ alert('Indiquez le chemin du dossier.'); return; }
  document.getElementById('btnScan').disabled=true;
  const d = await post('scan', {base_path: bp});
  document.getElementById('btnScan').disabled=false;
  if(!d.ok){ alert(d.error||'Erreur scan'); return; }
  ITEMS = d.items.map(it => ({ proprio:it.proprio, file:it.file, path:it.path,
      period:{annee:it.annee, trim:it.trimestre}, doublon:it.doublon }));
  renderTable();
}

function togglePause(){
  PAUSED=!PAUSED;
  document.getElementById('btnPause').innerHTML = PAUSED ? '▶️ Reprendre' : '⏸ Pause';
  document.getElementById('btnPause').style.background = PAUSED ? '#166534' : '#6b7a90';
}
function stopRun(){ STOP=true; PAUSED=false; }

async function run(){
  if(countChecked()===0){ alert('Aucun document coché.'); return; }
  document.getElementById('btnRun').disabled=true;
  document.getElementById('btnPause').style.display='';
  document.getElementById('btnStop').style.display='';
  document.getElementById('logCard').style.display='';
  PAUSED=false; STOP=false;
  const log = document.getElementById('log');
  const bar = document.querySelector('.bar > div');
  let done=0, ok=0, dup=0, err=0;
  const toDo = ITEMS.map((it,i)=>({it,i})).filter(x=>{ const c=document.getElementById('chk'+x.i); return c&&c.checked; });
  const total = toDo.length;

  for(let k=0;k<total;k++){
    while(PAUSED && !STOP){ await sleep(300); }
    if(STOP){ const d=document.createElement('div'); d.innerHTML='<b>⏹ Arrêté par l\'utilisateur.</b>'; d.style.color='#991b1b'; log.appendChild(d); break; }
    const {it,i} = toDo[k];
    document.getElementById('stt'+i).innerHTML='<span class="badge b-new">…</span>';
    let d;
    try {
      const fp = FORCE_PER();   // {annee,trim} ou null
      if(MODE==='prod'){
        const fd = new FormData();
        fd.append('action','process_one');
        fd.append('proprio', it.proprio);
        fd.append('filename', it.file);
        fd.append('force', '1');
        fd.append('id_agence', AGENCE_ID());
        if(fp){ fd.append('force_annee', fp.annee); fd.append('force_trimestre', fp.trim); }
        fd.append('fichier', it.fileObj, it.file);
        d = await fetch(API_URL,{method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd,credentials:'same-origin'}).then(r=>r.json());
      } else {
        const p = {path: it.path, proprio: it.proprio, filename: it.file, force:'1', id_agence: AGENCE_ID()};
        if(fp){ p.force_annee = fp.annee; p.force_trimestre = fp.trim; }
        d = await post('process_one', p);
      }
    } catch(e){ d = {ok:false, status:'erreur', error:String(e)}; }

    let cls='b-err', label='erreur';
    if(d.status==='ok'){ cls='b-ok'; label='importé'; ok++; }
    else if(d.status==='doublon'){ cls='b-skip'; label='ignoré'; dup++; }
    else { err++; }
    document.getElementById('stt'+i).innerHTML=`<span class="badge ${cls}">${label}</span>`;

    const s = d.stats||{};
    const line = `[${k+1}/${total}] ${it.proprio} / ${it.file} → ${label}`
      + (d.status==='ok' ? ` (${s.immeubles||0} imm, ${s.lots||0} lots, GED:${s.ged||'-'}, ${d.moteur||''})` : '')
      + (s.ged==='err' && s.ged_error ? ` ⚠️ GED: ${s.ged_error}` : '')
      + (d.error ? ' — '+d.error : '');
    const div = document.createElement('div'); div.textContent=line;
    if(cls==='b-err') div.style.color='#991b1b';
    log.appendChild(div); log.scrollTop=log.scrollHeight;

    done++; bar.style.width=(done/total*100)+'%';
    document.getElementById('progress').textContent=`${done}/${total} — ✅ ${ok}  ⏭️ ${dup}  ❌ ${err}`;
  }
  const fin = document.createElement('div');
  fin.innerHTML=`<b>${STOP?'Interrompu':'Terminé'} : ✅ ${ok} importés · ⏭️ ${dup} ignorés · ❌ ${err} erreurs</b>`;
  log.appendChild(fin);
  document.getElementById('btnPause').style.display='none';
  document.getElementById('btnStop').style.display='none';
  document.getElementById('btnRun').disabled=false;
}
</script>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
