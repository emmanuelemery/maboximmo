<?php
declare(strict_types=1);

/**
 * pilotage_declencheurs.php — Référentiel PARTAGÉ des déclencheurs métier.
 * Modèle TodoBox (card titre + master/détail). Tous les collaborateurs de la
 * société voient la même liste (pas de doublon) et la complètent ensemble :
 * chacun ajoute des déclencheurs et détaille les ACTIONS qu'ils enclenchent.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$appLayout = true;
$pageTitle = 'Déclencheurs';
$robots    = 'noindex, nofollow';
$pdo = $GLOBALS['pdo'];

// Services (couleurs charte modules MBI)
$SERVICES = [
    'gestion'     => ['Gestion',     '#316887', '🏠'],
    'location'    => ['Location',    '#84A7AB', '🔑'],
    'syndic'      => ['Syndic',      '#3D7465', '🏛️'],
    'transaction' => ['Transaction', '#84A763', '🤝'],
    'compta'      => ['Compta',      '#7a6830', '💶'],
    'rh'          => ['RH',          '#BF8837', '👤'],
];

include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<style>
.dc-wrap{margin-left:220px;padding:18px 22px;max-width:1500px}
@media(max-width:900px){.dc-wrap{margin-left:0}}
.dc-title{display:flex;align-items:center;gap:16px;padding:16px 22px;border-radius:18px;margin-bottom:18px;
  background:linear-gradient(135deg,#F59E0B 0%,#DD4735 100%);
  box-shadow:7px 7px 18px rgba(221,71,53,.28),-5px -5px 14px rgba(255,255,255,.55)}
.dc-title .ico{font-size:40px}
.dc-title h1{color:#fff;font-size:24px;font-weight:800;margin:0;text-shadow:0 1px 2px rgba(0,0,0,.15)}
.dc-title .sub{color:#fff;font-size:14px;font-weight:600;opacity:.95;margin-top:3px;max-width:640px}
.dc-title .kpi{margin-left:auto;background:#fff;color:#243B5C;border-radius:13px;padding:8px 16px;font-weight:700;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.13)}
.dc-title .kpi b{font-size:21px;color:#DD4735}
.dc-layout{display:grid;grid-template-columns:1fr 2px 1.2fr;gap:18px;align-items:start}
@media(max-width:900px){.dc-layout{grid-template-columns:1fr}}
.dc-filters{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.dc-fbtn{height:30px;border:none;cursor:pointer;padding:0 13px;border-radius:999px;font-size:12.5px;font-weight:700;
  background:#fff;color:#5a5750;box-shadow:2px 2px 6px #d4d7de,-2px -2px 6px #fff}
.dc-fbtn.on{color:#fff;background:#243B5C}
.dc-quick{display:flex;align-items:center;gap:12px;padding:11px 16px;border-radius:12px;background:#fff;
  box-shadow:inset 2px 2px 6px #e0e2e8,inset -2px -2px 6px #fff;border-left:5px solid #DD4735;margin-bottom:10px}
.dc-quick .p{color:#DD4735;font-size:20px;font-weight:700}
.dc-quick input{flex:1;border:none;outline:none;background:transparent;font-size:14px;font-family:inherit;color:#2a2a2a}
.dc-quick .hint{font-size:10px;color:#bdbab4}
#dcRows{display:flex;flex-direction:column;gap:8px}
.dc-row{display:flex;align-items:center;gap:12px;padding:11px 15px;border-radius:12px;cursor:pointer;background:#fff;
  box-shadow:2px 2px 6px #d4d7de,-2px -2px 6px #fff;border-left:5px solid #ccc;transition:transform .1s}
.dc-row:hover{transform:translateX(2px)}
.dc-row.sel{outline:2px solid #243B5C}
.dc-row .ri{font-size:22px;flex-shrink:0}
.dc-row .rb{flex:1;min-width:0}
.dc-row .rt{font-size:14px;color:#2a2a2a;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-row .rm{display:flex;gap:8px;align-items:center;margin-top:3px;font-size:11px;color:#8a8680}
.dc-svc{padding:2px 9px;border-radius:999px;color:#fff;font-weight:700;font-size:10px}
.dc-cnt{background:#eef3f5;color:#48606a;border-radius:999px;padding:1px 9px;font-weight:700}
.dc-empty{text-align:center;color:#9a968f;padding:26px;font-size:13px}
.dc-split{align-self:stretch;background:#e3e7ec;border-radius:3px}
@media(max-width:900px){.dc-split{display:none}}
/* Aperçu */
.dc-pv{position:sticky;top:12px;background:linear-gradient(160deg,#FFF3E9 0%,#FDE7DA 100%);border-radius:16px;padding:18px;
  border:1px solid #F3D3C0;box-shadow:6px 6px 16px rgba(221,71,53,.08),-6px -6px 16px rgba(255,255,255,.8);min-height:220px}
.dc-pv .empty{color:#c0a99b;text-align:center;padding:56px 10px;font-size:13px}
.dc-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#a5766a;font-weight:700;margin:14px 0 6px}
.dc-lbl:first-child{margin-top:0}
.dc-field{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #eadfd8;border-radius:9px;font-size:13.5px;font-family:inherit;background:rgba(255,255,255,.9)}
textarea.dc-field{resize:vertical;min-height:52px}
.dc-head{display:flex;align-items:center;gap:12px}
.dc-head .em{font-size:30px}
.dc-head input{font-size:17px;font-weight:800;color:#243B5C;border:none;background:transparent;flex:1;outline:none}
.dc-act{display:flex;align-items:flex-start;gap:9px;background:rgba(255,255,255,.92);border-radius:10px;padding:9px 11px;margin-bottom:7px}
.dc-act .n{width:22px;height:22px;border-radius:50%;background:#DD4735;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;cursor:grab}
.dc-act .n:active{cursor:grabbing}
.dc-act.dragging{opacity:.45}
.dc-act.drop{outline:2px dashed #DD4735;outline-offset:-2px}
.dc-act .ab{flex:1;min-width:0}
.dc-act .al{font-size:13.5px;font-weight:600;color:#2a2a2a;border:none;background:transparent;width:100%;outline:none}
.dc-act .ad{font-size:12px;color:#7a746e;border:none;background:transparent;width:100%;outline:none;margin-top:3px;font-family:inherit;line-height:1.4;resize:none;overflow:hidden;min-height:36px;box-sizing:border-box}
.dc-act .ad:focus{background:#fff;border:1px solid #eadfd8;border-radius:7px;padding:5px 7px}
.dc-act .ad::placeholder{color:#c3bcb5}
.dc-act .au{font-size:10px;color:#a89f98;margin-top:2px}
.dc-act .x{border:none;background:rgba(0,0,0,.05);cursor:pointer;width:22px;height:22px;border-radius:50%;color:#c0392b;font-weight:800;flex-shrink:0}
.dc-act .x:hover{background:#f6d7d2}
.dc-addact{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;border:1px dashed #e0b9a8;background:rgba(255,255,255,.6);margin-top:4px}
.dc-addact .p{color:#DD4735;font-weight:800}
.dc-addact input{flex:1;border:none;outline:none;background:transparent;font-size:13px;font-family:inherit}
.dc-svc-pick{display:flex;flex-wrap:wrap;gap:5px}
.dc-svc-sq{border:2px solid var(--cc);background:#fff;color:var(--cc);border-radius:8px;cursor:pointer;font-size:11px;font-weight:800;padding:5px 10px}
.dc-svc-sq.on{background:var(--cc);color:#fff}
.dc-del{border:none;background:#f0e5e0;color:#c0392b;border-radius:9px;padding:7px 13px;font-size:12.5px;font-weight:700;cursor:pointer;margin-top:14px}
.dc-saved{font-size:10px;color:#2f9e5b;opacity:0;transition:opacity .2s}.dc-saved.on{opacity:1}
</style>

<div class="dc-wrap">
  <div class="dc-title">
    <span class="ico">⚡</span>
    <div>
      <h1>Déclencheurs</h1>
      <div class="sub">Un déclencheur est un événement qui arrive dans l'agence et qui enclenche une série d'actions. Liste partagée : complétez celle des autres, n'en recréez pas.</div>
    </div>
    <div class="kpi"><b id="dcKpi">0</b> déclencheurs</div>
  </div>

  <div class="dc-filters" id="dcFilters">
    <button class="dc-fbtn on" data-svc="">Tous</button>
    <?php foreach ($SERVICES as $k => $s): ?>
      <button class="dc-fbtn" data-svc="<?= h($k) ?>"><?= $s[2] ?> <?= h($s[0]) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="dc-layout">
    <div>
      <div class="dc-quick">
        <span class="p">+</span>
        <input type="text" id="dcQuick" placeholder="Ajouter un déclencheur (ex. Réception d'un préavis)… puis Entrée" autocomplete="off">
        <span class="hint">Entrée ↵</span>
      </div>
      <div id="dcRows"><div class="dc-empty">Chargement…</div></div>
    </div>
    <div class="dc-split"></div>
    <div class="dc-pv" id="dcPv"><div class="empty">Cliquez un déclencheur pour le détailler ici, ou ajoutez-en un ↑</div></div>
  </div>
</div>

<script>
const DC_CSRF='<?= h(csrf_token('pilotage')) ?>';
const DC_URL='<?= h(app_url('/api/pilotage_declencheur.php')) ?>';
const DC_SVC=<?= json_encode($SERVICES, JSON_UNESCAPED_UNICODE) ?>;
let dcSvc='', dcList=[], dcCur=null;

function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function jget(u){return fetch(u,{credentials:'same-origin'}).then(r=>r.json());}
function jpost(data){const fd=new FormData();Object.entries(data).forEach(([k,v])=>{ if(Array.isArray(v)){ v.forEach(x=>fd.append(k+'[]',x)); } else { fd.append(k,v); } });
  return fetch(DC_URL,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':DC_CSRF},body:fd}).then(r=>r.json());}

function load(keepSel){
  return jget(DC_URL+'?action=list'+(dcSvc?('&service='+encodeURIComponent(dcSvc)):'')).then(d=>{
    dcList=d.ok?d.triggers:[]; renderRows();
    document.getElementById('dcKpi').textContent=dcList.length;
    if(keepSel&&dcCur){const t=dcList.find(x=>x.id===dcCur.id); if(t){dcCur=t;renderPv();} else {dcCur=null;emptyPv();}}
  });
}
function emptyPv(){document.getElementById('dcPv').innerHTML='<div class="empty">Cliquez un déclencheur pour le détailler ici.</div>';}
function renderRows(){
  const box=document.getElementById('dcRows');
  if(!dcList.length){box.innerHTML='<div class="dc-empty">Aucun déclencheur pour ce filtre. Ajoutez le premier ↑</div>';return;}
  box.innerHTML=dcList.map(t=>{
    const s=DC_SVC[t.service_slug]||['?','#999','•'];
    return `<div class="dc-row${dcCur&&dcCur.id===t.id?' sel':''}" style="border-left-color:${s[1]}" onclick="pick(${t.id})">
      <span class="ri">${t.icon?esc(t.icon):s[2]}</span>
      <div class="rb"><div class="rt">${esc(t.label)}</div>
        <div class="rm"><span class="dc-svc" style="background:${s[1]}">${esc(s[0])}</span>
          <span class="dc-cnt">${t.nb_actions} action${t.nb_actions>1?'s':''}</span>
          ${t.author?`<span>· ${esc(t.author)}</span>`:''}</div>
      </div></div>`;
  }).join('');
}

// Ajout rapide déclencheur
document.getElementById('dcQuick').addEventListener('keydown',function(e){
  if(e.key!=='Enter')return; const label=this.value.trim(); if(!label)return; this.value='';
  jpost({action:'add_trigger',label,service:(dcSvc||'gestion')}).then(d=>{ if(!d.ok){alert(d.error||'Erreur');return;} load().then(()=>pick(d.id)); });
});

// Filtres service
document.getElementById('dcFilters').addEventListener('click',function(e){
  const b=e.target.closest('.dc-fbtn'); if(!b)return;
  document.querySelectorAll('#dcFilters .dc-fbtn').forEach(x=>x.classList.remove('on')); b.classList.add('on');
  dcSvc=b.dataset.svc||''; load();
});

function pick(id){ dcCur=dcList.find(t=>t.id===id); if(!dcCur)return; renderRows(); renderPv(); }
function renderPv(){
  const t=dcCur, pv=document.getElementById('dcPv');
  const svcPick=Object.entries(DC_SVC).map(([k,s])=>`<button class="dc-svc-sq${k===t.service_slug?' on':''}" style="--cc:${s[1]}" onclick="setSvc('${k}')">${s[2]} ${esc(s[0])}</button>`).join('');
  const acts=(t.actions||[]).map((a,i)=>`
    <div class="dc-act" data-id="${a.id}" ondragover="dcActOver(event,this)" ondragleave="this.classList.remove('drop')" ondrop="dcActDrop(event,${a.id})">
      <span class="n" draggable="true" title="Glisser pour réordonner" ondragstart="dcActStart(event,${a.id})" ondragend="dcActEnd()">${i+1}</span>
      <div class="ab">
        <input class="al" value="${esc(a.label_libre||'')}" onchange="updAct(${a.id},'label_libre',this.value)">
        <textarea class="ad" rows="2" placeholder="Détail de l'action (facultatif)…" oninput="dcAutoGrow(this)" onchange="updAct(${a.id},'detail',this.value)">${esc(a.detail||'')}</textarea>
        ${a.author?`<div class="au">proposé par ${esc(a.author)}</div>`:''}
      </div>
      <button class="x" title="Retirer cette action" onclick="delAct(${a.id})">✕</button>
    </div>`).join('');
  pv.innerHTML=`
    <div class="dc-head"><span class="em">${t.icon?esc(t.icon):'⚡'}</span>
      <input value="${esc(t.label)}" onchange="updTrig('label',this.value)"><span class="dc-saved" id="dcSaved">enregistré ✓</span></div>
    <div class="dc-lbl">Service</div><div class="dc-svc-pick">${svcPick}</div>
    <div class="dc-lbl">Description</div>
    <textarea class="dc-field" onchange="updTrig('description',this.value)" placeholder="Que se passe-t-il exactement ? Comment arrive l'événement ?">${esc(t.description||'')}</textarea>
    <div class="dc-lbl">Exemple concret</div>
    <textarea class="dc-field" onchange="updTrig('example_text',this.value)" placeholder="Ex. Préavis de Mme Martin reçu par courrier le 12/07 pour le 15 rue X.">${esc(t.example_text||'')}</textarea>
    <div class="dc-lbl">Actions déclenchées <span style="text-transform:none;letter-spacing:0;color:#b79a8d;font-weight:600">— tout le monde peut compléter</span></div>
    <div id="dcActs">${acts||'<div style="color:#c0a99b;font-size:12.5px;padding:4px 0">Aucune action listée. Ajoutez la première ↓</div>'}</div>
    <div class="dc-addact"><span class="p">+</span><input id="dcNewAct" placeholder="Ajouter une action (ex. Accuser réception du préavis)… puis Entrée" autocomplete="off"></div>`;
  const na=document.getElementById('dcNewAct');
  na.addEventListener('keydown',function(e){ if(e.key!=='Enter')return; const label=this.value.trim(); if(!label)return; this.value='';
    jpost({action:'add_action',declencheur_id:t.id,label}).then(d=>{ if(!d.ok){alert(d.error||'Erreur');return;} load(true); }); });
  pv.querySelectorAll('.ad').forEach(dcAutoGrow); // dimensionne les détails existants
}
function dcAutoGrow(el){ el.style.height='auto'; el.style.height=(el.scrollHeight)+'px'; }
function flash(){const s=document.getElementById('dcSaved'); if(s){s.classList.add('on');setTimeout(()=>s.classList.remove('on'),1200);}}
function updTrig(field,value){ jpost({action:'update_trigger',id:dcCur.id,field,value}).then(d=>{ if(d.ok){dcCur[field]=value;flash();load(true);} else alert(d.error||'Erreur'); }); }
function setSvc(k){ updTrig('service_slug',k); }
function updAct(id,field,value){ jpost({action:'update_action',id,field,value}).then(d=>{ if(!d.ok)alert(d.error||'Erreur'); else load(true); }); }
function delAct(id){ if(!confirm('Retirer cette action ?'))return; jpost({action:'delete_action',id}).then(d=>{ if(d.ok)load(true); else alert(d.error||'Erreur'); }); }

// ---- Glisser-déposer : réordonner les actions ----
let dcDragActId=null;
function dcActStart(e,id){ dcDragActId=id; e.dataTransfer.effectAllowed='move'; try{e.dataTransfer.setData('text/plain',String(id));}catch(_){} const card=e.target.closest('.dc-act'); if(card) card.classList.add('dragging'); }
function dcActEnd(){ dcDragActId=null; document.querySelectorAll('.dc-act.dragging,.dc-act.drop').forEach(n=>n.classList.remove('dragging','drop')); }
function dcActOver(e,el){ if(dcDragActId==null) return; e.preventDefault(); el.classList.add('drop'); }
function dcActDrop(e,targetId){
  e.preventDefault();
  document.querySelectorAll('.dc-act.drop').forEach(n=>n.classList.remove('drop'));
  if(dcDragActId==null || dcDragActId===targetId) return;
  const arr=dcCur.actions||[];
  const from=arr.findIndex(a=>a.id===dcDragActId), to=arr.findIndex(a=>a.id===targetId);
  if(from<0||to<0) return;
  const [moved]=arr.splice(from,1); arr.splice(to,0,moved);
  dcDragActId=null;
  renderPv();
  jpost({action:'reorder_actions',declencheur_id:dcCur.id,ids:arr.map(a=>a.id)}).then(d=>{ if(!d.ok) alert(d.error||'Erreur'); });
}

load();
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
