<?php
/**
 * super_admin_dpe_lyon_batch.php — Import des DIAGNOSTICS (DPE) LYON depuis OneDrive.
 *
 * Périmètre : REGIE EMERY LYON (agence 69-2). Dossier OneDrive organisé PAR IMMEUBLE.
 * Démarche :
 *   1) On liste les dossiers OneDrive et on les apparie aux immeubles EXISTANTS en base.
 *   2) Pour chaque immeuble, bouton « Scanner les DPE » (AJAX, lecture seule) → liste les
 *      DPE du dossier + le LOT (bien) suggéré.
 *   3) « Traiter » ouvre le modal (aperçu PDF OneDrive à gauche, champs à droite) :
 *      choix immeuble/lot, saisie/regex gratuit, enregistrement sur le bien.
 *
 * Sécurité : admin / super admin. Lecture seule jusqu'à l'enregistrement via le modal.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_admin_or_super_admin();
require_once __DIR__ . '/inc/microsoft_graph.php';
require_once __DIR__ . '/inc/dpe_lyon_lib.php';
require_once __DIR__ . '/inc/csrf.php';

$pdo = $GLOBALS['pdo'];
$dlyCsrf = function_exists('csrf_token') ? csrf_token('ged_graph') : '';

[$imMeta, $immBiens] = dly_load_scope($pdo);

$graphOk = graph_is_configured();
$err = '';
$matched = [];     // [ ['immeuble'=>meta, 'folder'=>name, 'folder_id'=>id] ]
$nbFoldersTotal = 0; $nbOutScope = 0;

if (!$graphOk) {
    $err = 'Microsoft Graph n’est pas configuré sur cet environnement.';
} else {
    try {
        graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');
        $children = graph_list_all_children_by_path(DPE_LYON_ROOT);
        foreach ($children as $it) {
            if (!isset($it['folder'])) continue; // on ne veut que les dossiers (1 = immeuble)
            $name = (string)($it['name'] ?? '');
            if (preg_match('/^00\b|petites surfaces|^001 ernt$/i', $name)) continue;
            $nbFoldersTotal++;
            $imId = dly_match_folder($name, $imMeta);
            if ($imId === null) { $nbOutScope++; continue; }
            $matched[] = ['immeuble' => $imMeta[$imId], 'folder' => $name, 'folder_id' => (string)$it['id']];
        }
        usort($matched, fn($a, $z) => strcmp($a['immeuble']['adr'], $z['immeuble']['adr']));
    } catch (Throwable $e) {
        $err = 'Erreur Graph : ' . $e->getMessage();
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$layout_title  = 'Import DPE LYON';
$layout_module = 'Super Admin · Diagnostics';

ob_start();
?>
<div class="dly-wrap">
  <h1 class="dly-h1">🌡️ Import des DPE — REGIE EMERY LYON</h1>
  <p class="dly-sub">Dossier OneDrive <code><?= $h(DPE_LYON_ROOT) ?></code> · périmètre agence <b>69-2</b>.
     On n'affiche que les dossiers appariés à un <b>immeuble existant</b> en gestion LYON.</p>

  <?php if ($err): ?>
    <div class="dly-err">⚠️ <?= $h($err) ?></div>
  <?php else: ?>
    <div class="dly-kpis">
      <span><b><?= count($matched) ?></b> immeubles à traiter</span>
      <span><b><?= $nbFoldersTotal ?></b> dossiers OneDrive</span>
      <span><b><?= $nbOutScope ?></b> hors périmètre (immeuble non géré 69-2)</span>
    </div>
    <div class="dly-actions">
      <button class="dly-run-all" onclick="dlyRunAll(this)">▶ Tout scanner (affiche les DPE à valider)</button>
      <span id="dly-run-msg" class="dly-mut"></span>
      <div class="dly-help">« OK » = classe le PDF en GED + <b>analyse gratuite</b> (regex) + enregistre sur le bien, sans IA.
        Le lanceur global traite automatiquement <b>CERTAINS et PROBABLES</b> (bien rattachable). Seuls les
        <b>AMBIGU</b> (aucun lot identifié) restent à ouvrir au modal. Un bien déjà pourvu d'un DPE est ignoré.</div>
    </div>

    <table class="dly-tbl">
      <thead><tr><th>Immeuble (base)</th><th>Dossier OneDrive</th><th>Lots</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($matched as $i => $m): $im = $m['immeuble']; ?>
        <tr id="row-<?= $i ?>">
          <td><b><?= $h($im['adr']) ?></b><div class="dly-mut">immeuble #<?= (int)$im['id'] ?></div></td>
          <td><span class="dly-folder">📁 <?= $h($m['folder']) ?></span></td>
          <td><?= (int)$im['nb'] ?></td>
          <td><button class="dly-scan" onclick="dlyScan(<?= $i ?>,'<?= $h($m['folder_id']) ?>',<?= (int)$im['id'] ?>,this)">🔍 Scanner les DPE</button></td>
        </tr>
        <tr id="files-<?= $i ?>" class="dly-files" style="display:none;"><td colspan="4"></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<style>
.dly-wrap{max-width:1100px;margin:0 auto;padding:8px 4px;}
.dly-h1{font-size:22px;margin:0 0 4px;}
.dly-sub{color:#6b7280;font-size:13px;margin:0 0 14px;} .dly-sub code{background:#f1f5f9;padding:1px 5px;border-radius:4px;}
.dly-err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 12px;border-radius:8px;}
.dly-kpis{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:12px;font-size:13px;color:#374151;}
.dly-kpis b{color:#1f7a46;font-size:16px;}
.dly-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.dly-tbl th,.dly-tbl td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef0f2;vertical-align:top;}
.dly-tbl th{font-size:11px;text-transform:uppercase;color:#6b7280;letter-spacing:.03em;}
.dly-mut{color:#9ca3af;font-size:11px;}
.dly-folder{font-family:monospace;font-size:12px;color:#0a66c2;}
.dly-scan{background:#1f7a46;color:#fff;border:none;border-radius:7px;padding:7px 12px;font-weight:700;cursor:pointer;font-size:12px;}
.dly-scan:hover{background:#176038;} .dly-scan:disabled{opacity:.5;}
.dly-files td{background:#f8fafc;}
.dly-file{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px dashed #e5e7eb;}
.dly-file:last-child{border-bottom:none;}
.dly-file .nm{flex:1;font-family:monospace;font-size:12px;}
.dly-badge{font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;}
.dly-b-certain{background:#dcfce7;color:#166534;} .dly-b-probable{background:#fef9c3;color:#854d0e;} .dly-b-ambigu{background:#fee2e2;color:#991b1b;}
.dly-traiter{background:#fff;border:1px solid #1f7a46;color:#1f7a46;border-radius:6px;padding:5px 11px;font-weight:700;cursor:pointer;font-size:12px;}
.dly-traiter:hover{background:#eaf6ef;}
.dly-ok{background:#1f7a46;border:none;color:#fff;border-radius:6px;padding:5px 13px;font-weight:800;cursor:pointer;font-size:12px;}
.dly-ok:hover{background:#176038;} .dly-ok:disabled{opacity:.5;}
.dly-ok-prob{background:#b45309;} .dly-ok-prob:hover{background:#92400e;}
.dly-done{color:#166534;font-weight:800;font-size:12px;}
.dly-skip{color:#6b7280;font-weight:700;font-size:12px;}
.dly-row-done{opacity:.75;background:#f0fdf4;border-radius:6px;padding:2px 6px;}
.dly-actions{margin:6px 0 16px;padding:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;}
.dly-run-all{background:#0f766e;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:800;cursor:pointer;font-size:13px;}
.dly-run-all:hover{background:#0b5d57;} .dly-run-all:disabled{opacity:.5;}
.dly-help{font-size:12px;color:#6b7280;margin-top:8px;}
</style>

<script>
var DLY_CSRF=<?= json_encode($dlyCsrf, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

// Scan d'un immeuble → rend les fichiers. Renvoie une Promise(nb_certains_traitables).
function dlyScan(i, folderId, idImmeuble, btn){
  var row=document.getElementById('files-'+i), cell=row.firstElementChild;
  if(btn && row.style.display==='table-row'){ row.style.display='none'; return Promise.resolve(0); }
  if(btn) btn.disabled=true;
  cell.innerHTML='<span class="dly-mut">⏳ Lecture OneDrive…</span>'; row.style.display='table-row';
  return fetch('/api/dpe_lyon_scan_immeuble.php?folder_id='+encodeURIComponent(folderId)+'&id_immeuble='+idImmeuble,{credentials:'same-origin'})
   .then(r=>r.json()).then(function(j){
     if(btn) btn.disabled=false;
     if(!j||!j.ok){ cell.innerHTML='<span class="dly-mut">❌ '+((j&&j.error)||'erreur')+'</span>'; return 0; }
     if(!j.files.length){ cell.innerHTML='<span class="dly-mut">Aucun DPE détecté dans ce dossier.</span>'; return 0; }
     cell.innerHTML='';
     j.files.forEach(function(f,k){
       var cls = f.niveau==='certain'?'dly-b-certain':(f.niveau==='probable'?'dly-b-probable':'dly-b-ambigu');
       var lbl = f.niveau==='certain'?'CERTAIN':(f.niveau==='probable'?'PROBABLE':'AMBIGU');
       var sug = f.bien_ref ? (' → '+f.bien_ref) : ' → lot à choisir';
       var div=document.createElement('div'); div.className='dly-file'; div.id='f-'+i+'-'+k;
       var act;
       // OK auto = dès qu'un bien est rattachable (CERTAIN ou PROBABLE). L'analyse gratuite
       // (regex) tourne dans le OK. Seuls les AMBIGU (aucun bien) restent au modal.
       if(f.bien_id){
         var okLbl = f.niveau==='certain' ? '✅ OK' : '✅ OK (probable)';
         act='<button class="dly-ok'+(f.niveau!=='certain'?' dly-ok-prob':'')+'" onclick="dlyOk('+i+','+k+','+f.bien_id+',\''+(f.item_id||'')+'\',this)">'+okLbl+'</button>';
       }
       var data = encodeURIComponent(JSON.stringify({title:'🌡️ DPE — '+f.name, pdf_url:f.download_url, onedrive_item_id:f.item_id||'', id_immeuble:idImmeuble, id_bien:f.bien_id||0}));
       div.innerHTML='<span class="dly-badge '+cls+'">'+lbl+'</span>'
         +'<span class="nm">'+f.rel.replace(/</g,'&lt;')+'<span class="dly-mut">'+sug+'</span></span>'
         +(act||'')+'<button class="dly-traiter" onclick="dlyTraiter(\''+data+'\')">Traiter →</button>';
       cell.appendChild(div);
     });
     return j.files.filter(function(f){ return f.bien_id; }).length; // certains + probables (= rattachables)
   }).catch(function(){ if(btn) btn.disabled=false; cell.innerHTML='<span class="dly-mut">❌ réseau</span>'; return 0; });
}
function dlyTraiter(data){ try{ dpeDiagOpen(JSON.parse(decodeURIComponent(data))); }catch(e){ alert('Erreur ouverture modal'); } }

// OK (certain) : classe en GED (sans IA) → extraction gratuite (regex) → enregistre. Tout gratuit.
function dlyOk(i,k,bienId,itemId,btn){
  var div=document.getElementById('f-'+i+'-'+k); if(btn) btn.disabled=true;
  setMsg(div,'⏳ Classement GED…');
  var fd=new FormData(); fd.append('bien_id',bienId); fd.append('type_code','DIAG_DPE');
  fd.append('item_id',itemId); fd.append('no_analyse','1'); fd.append('csrf_token',DLY_CSRF);
  return fetch('/api/graph_doc_commit.php',{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(function(j){
    // Bien qui a déjà un DPE en GED → skip propre (pas une erreur).
    if(j && j.duplicate){ if(div){ div.classList.add('dly-row-done'); div.querySelectorAll("button").forEach(b=>b.remove());
        var sp=div.querySelector(".dly-msg")||div; sp.innerHTML='<span class="dly-skip">⏭️ déjà existant</span>'; }
      return 'skip'; }
    if(!j||!j.ok) throw new Error((j&&j.error)||'GED');
    var gedId=+j.ged_doc_id||0; setMsg(div,'⏳ Extraction gratuite…');
    return fetch('/api/dpe_analyse_doc.php?mode=regex&ged_document_id='+gedId,{credentials:'same-origin'}).then(r=>r.json()).then(function(a){
      var fields=(a&&a.ok&&a.fields)?a.fields:{};
      setMsg(div,'⏳ Enregistrement…');
      return fetch('/api/dpe_diag_save.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({id_bien:bienId,ged_document_id:gedId,fields:fields})}).then(r=>r.json()).then(function(s){
          if(!s||!s.ok) throw new Error((s&&s.error)||'enregistrement');
          var nb=Object.keys(fields).length;
          if(div){ div.classList.add('dly-row-done'); div.querySelectorAll('button').forEach(b=>b.remove());
            var sp=div.querySelector('.dly-msg')||div; sp.innerHTML='<span class="dly-done">✅ Chargé en GED'+(nb?(' ('+nb+' champ'+(nb>1?'s':'')+')'):'')+'</span>'; }
          return true;
        });
    });
  }).catch(function(e){ if(btn) btn.disabled=false; setMsg(div,'❌ '+(e.message||'échec')); return false; });
}
function setMsg(div,txt){ if(!div) return; var s=div.querySelector('.dly-msg'); if(!s){ s=document.createElement('span'); s.className='dly-msg dly-mut'; div.appendChild(s); } s.textContent=' '+txt; }

// Lanceur global : SCANNE chaque immeuble en série et affiche tous les DPE avec leur
// bouton OK prêt. Ne valide RIEN automatiquement : tu cliques « OK » sur chaque DPE
// (sans modal), et tu n'ouvres le modal qu'en cas de doute.
function dlyRunAll(btn){
  var rows=[].slice.call(document.querySelectorAll('tr[id^="row-"]'));
  btn.disabled=true; var msg=document.getElementById('dly-run-msg');
  var done=0, filesTotal=0;
  (function next(){
    if(!rows.length){ btn.disabled=false; msg.textContent='Scan terminé : '+filesTotal+' DPE prêts à valider sur '+done+' immeubles. Clique « OK » sur chacun.'; return; }
    var tr=rows.shift(); var b=tr.querySelector('.dly-scan'); if(!b){ return next(); }
    var m=b.getAttribute('onclick').match(/dlyScan\((\d+),'([^']*)',(\d+)/); if(!m){ return next(); }
    var i=+m[1], fid=m[2], iid=+m[3]; done++;
    msg.textContent='Scan immeuble '+done+'… ('+filesTotal+' DPE trouvés)';
    dlyScan(i,fid,iid,null).then(function(nb){ filesTotal+=(nb||0); next(); });
  })();
}
</script>

<?php include __DIR__ . '/inc/dpe_edit_modal.php'; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
