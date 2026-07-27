<?php
/**
 * bien_photos_groupes.php?id=<bien> — Organisation des photos d'un bien par GROUPES.
 * Renommer / créer un groupe, ajouter des photos, déplacer (drag & drop + multi-sélection),
 * réordonner. Rien ne se perd : supprimer un groupe renvoie ses photos en « Sans groupe ».
 *
 * Endpoints : bien_photo_group_rename.php · bien_photo_move.php · bien_intake_photo_upload.php
 *             · bien_photo_delete.php   (form CSRF = 'bien_photo_group')
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$bienId = (int)($_GET['id'] ?? 0);
if ($bienId <= 0) { http_response_code(400); exit('id requis'); }
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Bien + scope société.
$st = $pdo->prepare("SELECT b.id, b.reference_bien, b.designation, b.adresse_1, b.ville, b.id_societe
                       FROM biens b WHERE b.id = ? LIMIT 1");
$st->execute([$bienId]); $bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit('Bien introuvable'); }
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
$userSoc = (int)($_SESSION['id_societe'] ?? 0);
if (!$isAdmin && !empty($bien['id_societe']) && (int)$bien['id_societe'] !== $userSoc) { http_response_code(403); exit('Hors périmètre'); }

// Photos groupées.
$sp = $pdo->prepare("SELECT id, COALESCE(groupe_no,0) AS gno, groupe_label, url_photo, ordre, nom_original
                       FROM biens_photos WHERE entity_type='BIEN' AND entity_id=?
                      ORDER BY COALESCE(groupe_no,0) ASC, ordre ASC, id ASC");
$sp->execute([$bienId]);
$groups = [];   // gno => ['label'=>, 'photos'=>[]]
foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $g = (int)$p['gno'];
    if (!isset($groups[$g])) $groups[$g] = ['label' => trim((string)($p['groupe_label'] ?? '')), 'photos' => []];
    if ($groups[$g]['label'] === '' && trim((string)($p['groupe_label'] ?? '')) !== '') $groups[$g]['label'] = trim((string)$p['groupe_label']);
    $groups[$g]['photos'][] = $p;
}
ksort($groups);
$csrf     = function_exists('csrf_token') ? csrf_token('bien_photo_group') : '';
$csrfAjout = function_exists('csrf_token') ? csrf_token('ajouter_bien') : '';   // delete + upload
$maxGno = $groups ? max(array_keys($groups)) : 0;
$title = trim((string)($bien['reference_bien'] ?: $bien['designation'] ?: ('Bien #' . $bienId)));
?><!doctype html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Photos — <?= $h($title) ?></title>
<style>
  :root{--navy:#243B5C;--line:#e6e2da;--bg:#f4f6f9;}
  *{box-sizing:border-box;} body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:#2c2a28;}
  .pgtop{position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid var(--line);padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
  .pgtop h1{font-size:16px;margin:0;color:var(--navy);} .pgtop .sub{font-size:12px;color:#8a8680;}
  .btn{border:1px solid #d6dade;background:#eceef1;color:#374151;border-radius:8px;padding:8px 14px;font-weight:800;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block;}
  .btn-p{border:none;background:var(--navy);color:#fff;} .btn-g{border:none;background:#84A763;color:#fff;}
  .wrap{max-width:1200px;margin:0 auto;padding:16px 18px 90px;}
  .hint{font-size:12px;color:#8a8680;margin:2px 0 14px;}
  .grp{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;overflow:hidden;transition:box-shadow .15s;}
  .grp.drop-hi{box-shadow:0 0 0 3px #84A763;}
  .grp-h{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f7f5f1;border-bottom:1px solid var(--line);}
  .grp-h .name{font-weight:800;color:var(--navy);font-size:14px;cursor:text;border:1px dashed transparent;border-radius:6px;padding:2px 6px;}
  .grp-h .name:hover{border-color:#cbd5e1;background:#fff;}
  .grp-h .cnt{font-size:11px;color:#8a8680;font-weight:700;background:#ece7dd;border-radius:99px;padding:2px 8px;}
  .grp-h .sp{flex:1;}
  .grp-b{display:flex;flex-wrap:wrap;gap:10px;padding:14px;min-height:70px;}
  .ph{position:relative;width:150px;height:110px;border-radius:8px;overflow:hidden;border:1px solid #e0dccf;background:#000;cursor:grab;flex:none;}
  .ph.sel{outline:3px solid #84A763;outline-offset:1px;}
  .ph img{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;}
  .ph .chk{position:absolute;top:5px;left:5px;transform:scale(1.25);z-index:2;cursor:pointer;}
  .ph .tools{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:flex-end;gap:4px;padding:4px;background:linear-gradient(transparent,rgba(0,0,0,.55));opacity:0;transition:.15s;}
  .ph:hover .tools{opacity:1;}
  .ph .tb{border:none;background:rgba(255,255,255,.9);border-radius:6px;width:26px;height:24px;cursor:pointer;font-size:13px;}
  .ph .cover-b{position:absolute;top:4px;right:5px;font-size:11px;background:#D4A047;color:#fff;border-radius:5px;padding:1px 5px;z-index:2;}
  .grp-empty{color:#b0aca2;font-size:13px;font-style:italic;padding:16px;}
  .bulk{position:fixed;bottom:0;left:0;right:0;background:#243B5C;color:#fff;padding:12px 18px;display:none;align-items:center;gap:12px;z-index:20;box-shadow:0 -2px 12px rgba(0,0,0,.2);}
  .bulk.on{display:flex;} .bulk select{padding:7px 10px;border-radius:7px;border:none;font-size:13px;}
</style>
</head><body>
<div class="pgtop">
  <div><h1>🖼️ Photos — <?= $h($title) ?></h1><div class="sub"><?= $h(trim(($bien['adresse_1'] ?? '') . ' ' . ($bien['ville'] ?? ''))) ?></div></div>
  <div style="display:flex;gap:8px;">
    <button type="button" class="btn btn-g" onclick="pgNewGroup()">＋ Nouveau groupe</button>
    <a class="btn btn-p" href="<?= $h(function_exists('app_url') ? app_url('/bien_360.php?id=' . $bienId) : ('/bien_360.php?id=' . $bienId)) ?>">✓ Terminé</a>
  </div>
</div>
<div class="wrap">
  <div class="hint">Glisse une photo d'un groupe à l'autre, ou coche plusieurs photos puis « Déplacer vers ». Clique le nom d'un groupe pour le renommer.</div>
  <div id="pgGroups">
    <?php foreach ($groups as $gno => $g): ?>
    <div class="grp" data-gno="<?= (int)$gno ?>">
      <div class="grp-h">
        <span>⠿</span>
        <?php if ($gno === 0): ?>
          <span class="name" style="cursor:default;color:#8a8680;">📥 Sans groupe</span>
        <?php else: ?>
          <span class="name" contenteditable="true" data-gno="<?= (int)$gno ?>" onblur="pgRename(this)" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}"><?= $h($g['label'] !== '' ? $g['label'] : 'Groupe ' . $gno) ?></span>
        <?php endif; ?>
        <span class="cnt"><?= count($g['photos']) ?></span>
        <span class="sp"></span>
        <button type="button" class="btn" onclick="pgAddPhotos(<?= (int)$gno ?>)">＋ Photos</button>
        <?php if ($gno !== 0): ?><button type="button" class="btn" onclick="pgDeleteGroup(<?= (int)$gno ?>)" title="Supprimer le groupe (les photos repassent en « Sans groupe »)">🗑️</button><?php endif; ?>
      </div>
      <div class="grp-b" data-gno="<?= (int)$gno ?>">
        <?php foreach ($g['photos'] as $ph): ?>
        <div class="ph" draggable="true" data-id="<?= (int)$ph['id'] ?>" data-gno="<?= (int)$gno ?>">
          <input type="checkbox" class="chk" onclick="pgToggle(event,this)">
          <img src="<?= $h(function_exists('app_url') ? app_url('/' . ltrim((string)$ph['url_photo'], '/')) : ('/' . ltrim((string)$ph['url_photo'], '/'))) ?>" alt="" loading="lazy">
          <div class="tools">
            <button type="button" class="tb" title="Mettre en couverture" onclick="pgCover(<?= (int)$ph['id'] ?>)">★</button>
            <button type="button" class="tb" title="Supprimer" onclick="pgDelete(<?= (int)$ph['id'] ?>)">🗑️</button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$g['photos']): ?><div class="grp-empty">Groupe vide — glisse des photos ici ou clique « ＋ Photos ».</div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$groups): ?><div class="grp"><div class="grp-empty" style="padding:24px;">Aucune photo. Clique « ＋ Nouveau groupe » puis « ＋ Photos ».</div></div><?php endif; ?>
  </div>
</div>

<div class="bulk" id="pgBulk">
  <span id="pgBulkN">0</span> sélectionnée(s)
  <span>→ Déplacer vers</span>
  <select id="pgBulkTarget"></select>
  <button type="button" class="btn btn-g" onclick="pgBulkMove()">Déplacer</button>
  <button type="button" class="btn" style="background:#b5352e;color:#fff;border:none;" onclick="pgBulkDelete()">Supprimer</button>
  <button type="button" class="btn" onclick="pgClearSel()">Annuler</button>
</div>

<input type="file" id="pgFile" accept="image/*" multiple hidden>
<form id="pgCsrf" hidden><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"></form>

<script>
const BIEN=<?= (int)$bienId ?>, CSRF=<?= json_encode($csrf) ?>, CSRF2=<?= json_encode($csrfAjout) ?>;
const U={
  rename:<?= json_encode(function_exists('app_url') ? app_url('/api/bien_photo_group_rename.php') : '/api/bien_photo_group_rename.php') ?>,
  move:<?= json_encode(function_exists('app_url') ? app_url('/api/bien_photo_move.php') : '/api/bien_photo_move.php') ?>,
  upload:<?= json_encode(function_exists('app_url') ? app_url('/api/bien_intake_photo_upload.php') : '/api/bien_intake_photo_upload.php') ?>,
  del:<?= json_encode(function_exists('app_url') ? app_url('/api/bien_photo_delete.php') : '/api/bien_photo_delete.php') ?>,
};
let MAXGNO=<?= (int)$maxGno ?>, UPTARGET=0;
const $=s=>document.querySelector(s), $$=s=>Array.prototype.slice.call(document.querySelectorAll(s));

function post(url, data, token){ const t=token||CSRF; const fd=new FormData(); fd.append('csrf_token',t); Object.keys(data).forEach(k=>fd.append(k,data[k]));
  return fetch(url,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':t},body:fd}).then(r=>r.json()); }

/* ── Renommer un groupe ── */
function pgRename(el){ const gno=el.dataset.gno; const label=el.textContent.trim();
  post(U.rename,{id_bien:BIEN,groupe_no:gno,groupe_label:label}).then(()=>{}); }

/* ── Nouveau groupe (client, se matérialise à la 1ère photo déposée) ── */
function pgNewGroup(){ MAXGNO++; const gno=MAXGNO;
  const card=document.createElement('div'); card.className='grp'; card.dataset.gno=gno;
  card.innerHTML='<div class="grp-h"><span>⠿</span><span class="name" contenteditable="true" data-gno="'+gno+'" onblur="pgRename(this)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();this.blur();}">Nouveau groupe</span><span class="cnt">0</span><span class="sp"></span><button type="button" class="btn" onclick="pgAddPhotos('+gno+')">＋ Photos</button><button type="button" class="btn" onclick="pgDeleteGroup('+gno+')">🗑️</button></div><div class="grp-b" data-gno="'+gno+'"><div class="grp-empty">Groupe vide — glisse des photos ici ou clique « ＋ Photos ».</div></div>';
  $('#pgGroups').appendChild(card); bindDrop(card); refreshTargets();
  const nm=card.querySelector('.name'); nm.focus(); document.execCommand&&document.getSelection().selectAllChildren(nm);
}

/* ── Supprimer un groupe → ses photos repassent en « Sans groupe » (gno 0) ── */
function pgDeleteGroup(gno){ const card=document.querySelector('.grp[data-gno="'+gno+'"]'); if(!card) return;
  const ids=$$('.grp[data-gno="'+gno+'"] .ph').map(p=>p.dataset.id);
  if(ids.length && !confirm('Supprimer ce groupe ? Ses '+ids.length+' photo(s) repasseront en « Sans groupe » (non supprimées).')) return;
  if(!ids.length){ card.remove(); refreshTargets(); return; }
  const photos=ids.map(id=>({id:parseInt(id,10),groupe_no:0,groupe_label:''}));
  post(U.move,{id_bien:BIEN,photos:JSON.stringify(photos)}).then(()=>location.reload());
}

/* ── Sélection multiple ── */
function pgToggle(e,cb){ e.stopPropagation(); cb.closest('.ph').classList.toggle('sel',cb.checked); updateBulk(); }
function selectedIds(){ return $$('.ph.sel').map(p=>parseInt(p.dataset.id,10)); }
function pgClearSel(){ $$('.ph.sel').forEach(p=>{p.classList.remove('sel');p.querySelector('.chk').checked=false;}); updateBulk(); }
function updateBulk(){ const n=selectedIds().length; $('#pgBulkN').textContent=n; $('#pgBulk').classList.toggle('on',n>0); refreshTargets(); }
function refreshTargets(){ const sel=$('#pgBulkTarget'); if(!sel) return; sel.innerHTML='';
  $$('.grp').forEach(g=>{ const gno=g.dataset.gno; const nm=g.querySelector('.name'); const lbl=(gno==='0')?'📥 Sans groupe':(nm?nm.textContent.trim():'Groupe '+gno);
    const o=document.createElement('option'); o.value=gno; o.textContent=lbl; sel.appendChild(o); }); }
function pgBulkMove(){ const gno=parseInt($('#pgBulkTarget').value,10); const ids=selectedIds(); if(!ids.length) return; moveTo(ids,gno); }
function pgBulkDelete(){ const ids=selectedIds(); if(!ids.length) return; if(!confirm('Supprimer '+ids.length+' photo(s) définitivement ?')) return;
  Promise.all(ids.map(id=>post(U.del,{id_photo:id},CSRF2))).then(()=>location.reload()); }

/* ── Déplacer des photos vers un groupe (drag ou bulk) ── */
function moveTo(ids, gno){
  const target=document.querySelector('.grp-b[data-gno="'+gno+'"]'); const nm=document.querySelector('.name[data-gno="'+gno+'"]');
  const label=nm?nm.textContent.trim():'';
  const base=$$('.grp-b[data-gno="'+gno+'"] .ph').length;
  const photos=ids.map((id,i)=>({id:id,groupe_no:gno,groupe_label:label,ordre:base+i}));
  post(U.move,{id_bien:BIEN,photos:JSON.stringify(photos)}).then(j=>{ if(j&&j.ok) location.reload(); else alert('❌ '+((j&&j.error)||'échec')); });
}

/* ── Drag & drop ── */
let DRAG=null;
$$('.ph').forEach(bindPhoto);
function bindPhoto(ph){
  ph.addEventListener('dragstart',e=>{ const sel=selectedIds(); DRAG = sel.length && ph.classList.contains('sel') ? sel : [parseInt(ph.dataset.id,10)]; e.dataTransfer.effectAllowed='move'; });
}
function bindDrop(card){
  const body=card.querySelector('.grp-b'); const gno=parseInt(card.dataset.gno,10);
  ['dragover','dragenter'].forEach(ev=>card.addEventListener(ev,e=>{e.preventDefault();card.classList.add('drop-hi');}));
  card.addEventListener('dragleave',()=>card.classList.remove('drop-hi'));
  card.addEventListener('drop',e=>{ e.preventDefault(); card.classList.remove('drop-hi'); if(DRAG&&DRAG.length){ moveTo(DRAG,gno); DRAG=null; } });
}
$$('.grp').forEach(bindDrop);

/* ── Ajouter des photos à un groupe ── */
function pgAddPhotos(gno){ UPTARGET=gno; $('#pgFile').value=''; $('#pgFile').click(); }
$('#pgFile').addEventListener('change',function(){ const files=Array.prototype.slice.call(this.files); if(!files.length) return;
  const nm=document.querySelector('.name[data-gno="'+UPTARGET+'"]'); const label=(UPTARGET===0)?'':(nm?nm.textContent.trim():'');
  let done=0; const top=document.querySelector('.pgtop h1'); const old=top.textContent; top.textContent='⏳ Import 0/'+files.length;
  (function next(i){ if(i>=files.length){ top.textContent=old; location.reload(); return; }
    const fd=new FormData(); fd.append('csrf_token',CSRF2); fd.append('id_bien',BIEN); fd.append('fichier',files[i]); if(label) fd.append('groupe_label',label);
    fetch(U.upload,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':CSRF2},body:fd}).then(r=>r.json()).finally(()=>{ done++; top.textContent='⏳ Import '+done+'/'+files.length; next(i+1); });
  })(0);
});

/* ── Couverture / suppression ── */
function pgCover(id){ moveToCover(id); }
function moveToCover(id){ const ph=document.querySelector('.ph[data-id="'+id+'"]'); const gno=parseInt(ph.dataset.gno,10);
  const ids=$$('.grp-b[data-gno="'+gno+'"] .ph').map(p=>parseInt(p.dataset.id,10)); const reordered=[id].concat(ids.filter(x=>x!==id));
  const nm=document.querySelector('.name[data-gno="'+gno+'"]'); const label=nm?nm.textContent.trim():'';
  const photos=reordered.map((pid,i)=>({id:pid,groupe_no:gno,groupe_label:label,ordre:i}));
  post(U.move,{id_bien:BIEN,photos:JSON.stringify(photos)}).then(()=>location.reload()); }
function pgDelete(id){ if(!confirm('Supprimer cette photo ?')) return; post(U.del,{id_photo:id},CSRF2).then(()=>location.reload()); }
refreshTargets();
</script>
</body></html>
