<?php
declare(strict_types=1);
/**
 * financement_liste.php — Liste des dossiers financiers + création (Tranche 1).
 * Accès réservé super admin / admin pour le moment.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/financement.php';
require_login();

$roleId = (int)current_role_id();
$isSuper = (function_exists('is_super_admin') && is_super_admin()) || in_array($roleId, [1, 7], true);
$isBailleur = function_exists('is_caged_bailleur') && is_caged_bailleur();
if (!$isSuper && !$isBailleur) { http_response_code(403); exit('Accès réservé.'); }

$appLayout = true;
$pageTitle = 'Dossiers financiers';
$robots = 'noindex, nofollow';
$pdo = $GLOBALS['pdo'];
$soc = fin_soc();

// Bailleur : uniquement les dossiers de SON patrimoine (propriétaires/biens). Super/staff : tout.
$dossiers = $isBailleur ? fin_list($pdo, $soc, (int)current_user_id()) : fin_list($pdo, $soc);
$typeL = fin_type_labels(); $statL = fin_statut_labels(); $confL = fin_confid_labels();

// ── Création pré-branchée sur un PROPRIÉTAIRE (depuis sa fiche) : c'est lui qui emprunte.
//    On propose ses biens + ses dossiers créancier en cours à relier automatiquement.
$prefTiers = (int)($_GET['tiers'] ?? 0);
$prefTiersNom = ''; $prefBiens = []; $prefCreanciers = [];
if ($prefTiers > 0) {
    $t = $pdo->prepare("SELECT COALESCE(nom_affichage, CONCAT(COALESCE(nom,''),' ',COALESCE(prenom,''))) nom FROM tiers WHERE id=? AND id_societe=?");
    $t->execute([$prefTiers, $soc]);
    $prefTiersNom = trim((string)($t->fetchColumn() ?: ''));
    if ($prefTiersNom !== '') {
        $prefBiens = fin_biens_of_tiers($pdo, $prefTiers, $soc);
        $prefCreanciers = fin_creanciers_of_tiers($pdo, $prefTiers, $soc);
    } else { $prefTiers = 0; }
}

include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<style>
:root{ --fin:#7a6830; --fin-d:#5c4e22; }
.fin-wrap{margin-left:220px;padding:18px 22px;max-width:1400px}
@media(max-width:900px){.fin-wrap{margin-left:0}}
.fin-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.fin-title{display:flex;align-items:center;gap:10px;font-size:1.2rem;font-weight:800;color:#243B5C}
.fin-title .b{background:var(--fin);color:#fff;border-radius:8px;padding:2px 10px;font-size:.78rem}
.fin-btn{background:var(--fin);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-size:.9rem;font-weight:700;cursor:pointer}
.fin-btn.ghost{background:#fff;color:var(--fin-d);border:1px solid #e2d9bd}
.fin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.fin-card{background:#fff;border:1px solid #ece7d6;border-left:4px solid var(--fin);border-radius:14px;padding:14px 16px;text-decoration:none;display:block;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:.12s}
.fin-card:hover{box-shadow:0 6px 16px rgba(122,104,48,.14);transform:translateY(-1px)}
.fin-card .t{font-weight:700;color:#243B5C;font-size:.98rem}
.fin-card .ty{display:inline-block;background:#f3eeddff;color:#7a6830;border-radius:20px;padding:1px 9px;font-size:.72rem;font-weight:700;margin-top:4px}
.fin-card .m{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;font-size:.8rem;color:#6b6455}
.fin-card .m b{color:#243B5C}
.fin-pill{background:#f1f3f5;border-radius:20px;padding:1px 8px;font-size:.72rem;color:#5b5647}
.fin-empty{text-align:center;color:#98917f;padding:40px;background:#faf8f2;border:1px dashed #e2d9bd;border-radius:14px}
/* modal */
.fm-bg{position:fixed;inset:0;background:rgba(20,25,35,.5);display:none;align-items:flex-start;justify-content:center;z-index:2000;padding:40px 16px;overflow:auto}
.fm-bg.show{display:flex}
.fm{background:#fff;border-radius:16px;max-width:620px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.fm h3{margin:0;padding:16px 20px;border-bottom:1px solid #eee;font-size:1.05rem;color:#243B5C}
.fm .body{padding:18px 20px}
.ff{margin-bottom:12px}
.ff label{display:block;font-size:.78rem;font-weight:700;color:#5c5647;margin-bottom:4px}
.ff input,.ff select,.ff textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #d8d2c2;border-radius:9px;font-size:.9rem;font-family:inherit}
.ff .type-row{display:flex;gap:8px;flex-wrap:wrap}
.ff .type-btn{flex:1;border:2px solid #e2d9bd;background:#fff;color:#7a6830;border-radius:9px;padding:9px;font-size:.82rem;font-weight:700;cursor:pointer}
.ff .type-btn.on{background:var(--fin);color:#fff;border-color:var(--fin)}
.ff .res{border:1px solid #eee;border-radius:8px;margin-top:4px;max-height:160px;overflow:auto;display:none}
.ff .res.show{display:block}
.ff .res button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:8px 11px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f2f2f2}
.ff .res button:hover{background:#faf6ea}
.ff .picked{font-size:.82rem;color:#1e7d4f;margin-top:4px}
.fm .foot{display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #eee}
</style>

<div class="fin-wrap">
  <div class="fin-head">
    <div class="fin-title">💶 Dossiers financiers <span class="b">Financement</span></div>
    <button class="fin-btn" onclick="fmOpen()">+ Créer un dossier</button>
  </div>

  <?php if (!$dossiers): ?>
    <div class="fin-empty">Aucun dossier financier pour cette société.<br>Créez le premier avec « + Créer un dossier ».</div>
  <?php else: ?>
    <div class="fin-grid">
      <?php foreach ($dossiers as $d): $sujet = $d['tiers_nom'] ?: $d['societe_concernee_nom'] ?: '—'; ?>
        <a class="fin-card" href="<?= h(app_url('/financement_360.php?id=' . (int)$d['id'])) ?>">
          <div class="t"><?= h($d['libelle']) ?></div>
          <span class="ty"><?= h($typeL[$d['type']] ?? $d['type']) ?></span>
          <span class="fin-pill"><?= h($statL[$d['statut']] ?? $d['statut']) ?></span>
          <?php if ($d['confidentialite'] !== 'normal'): ?><span class="fin-pill">🔒 <?= h($confL[$d['confidentialite']] ?? '') ?></span><?php endif; ?>
          <div class="m">
            <span>👤 <b><?= h($sujet) ?></b></span>
            <span>🏘️ <?= (int)$d['nb_biens'] ?> bien(s)</span>
            <span>⚖️ <?= (int)$d['nb_creanciers'] ?> créancier(s)</span>
            <span>👥 <?= (int)$d['nb_participants'] ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Modal création -->
<div class="fm-bg" id="fmBg">
  <div class="fm">
    <h3>💶 Nouveau dossier financier</h3>
    <div class="body">
      <div class="ff">
        <label>Type de dossier</label>
        <div class="type-row" id="fmType">
          <?php foreach ($typeL as $k => $l): ?>
            <button type="button" class="type-btn <?= $k === 'financement_bancaire' ? 'on' : '' ?>" data-t="<?= h($k) ?>"><?= h($l) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="ff"><label>Libellé *</label><input type="text" id="fmLibelle" maxlength="200" placeholder="Ex. Prêt SCI SMH — 15 rue X"></div>
      <?php if ($prefTiers): ?>
      <div class="ff" style="background:#faf8f2;border:1px solid #f0ead9;border-radius:9px;padding:10px">
        <label>Propriétaire (emprunteur)</label>
        <div style="font-weight:700;color:#1e7d4f">✓ <?= h($prefTiersNom) ?></div>
        <?php if ($prefBiens): ?>
          <label style="margin-top:10px">Ses biens — cochez ceux à rattacher au dossier</label>
          <?php foreach ($prefBiens as $b): ?>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;font-size:.85rem;padding:3px 0">
              <input type="checkbox" class="prefBien" value="<?= (int)$b['id'] ?>" checked> <?= h($b['label']) ?></label>
          <?php endforeach; ?>
        <?php else: ?><div style="color:#98917f;font-size:.82rem;margin-top:6px">Aucun bien rattaché à ce propriétaire.</div><?php endif; ?>
        <?php if ($prefCreanciers): ?>
          <label style="margin-top:12px">Dossier(s) Créancier en cours — à relier ?</label>
          <?php foreach ($prefCreanciers as $c): ?>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;font-size:.85rem;padding:3px 0">
              <input type="checkbox" class="prefCre" value="<?= (int)$c['id'] ?>"> <?= h($c['label']) ?>
              <span class="fin-pill" style="margin-left:auto"><?= h($c['statut']) ?><?= $c['niveau_risque'] ? ' · ' . h($c['niveau_risque']) : '' ?></span></label>
          <?php endforeach; ?>
        <?php else: ?><div style="color:#98917f;font-size:.82rem;margin-top:8px">Aucun dossier Créancier en cours pour ce propriétaire.</div><?php endif; ?>
      </div>
      <?php else: ?>
      <div class="ff">
        <label>Propriétaire concerné (tiers)</label>
        <input type="text" id="fmTiersQ" placeholder="Rechercher un propriétaire…" autocomplete="off">
        <div class="res" id="fmTiersRes"></div>
        <div class="picked" id="fmTiersPick"></div>
      </div>
      <div class="ff">
        <label>Ou société concernée (facultatif)</label>
        <input type="text" id="fmSocQ" placeholder="Rechercher une société…" autocomplete="off">
        <div class="res" id="fmSocRes"></div>
        <div class="picked" id="fmSocPick"></div>
      </div>
      <?php endif; ?>
      <div class="ff">
        <label>Pilote interne</label>
        <input type="text" id="fmPiloteQ" placeholder="Rechercher un collaborateur… (défaut : vous)" autocomplete="off">
        <div class="res" id="fmPiloteRes"></div>
        <div class="picked" id="fmPilotePick"></div>
      </div>
      <div class="ff" style="display:flex;gap:10px">
        <div style="flex:1"><label>Statut</label><select id="fmStatut"><?php foreach ($statL as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div style="flex:1"><label>Confidentialité</label><select id="fmConfid"><?php foreach ($confL as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
      </div>
      <p style="color:#98917f;font-size:.8rem;margin:6px 0 0">Les biens, immeubles et dossiers Créancier se rattachent ensuite depuis la fiche du dossier. Aucun montant, taux ou échéance à ce stade.</p>
    </div>
    <div class="foot">
      <button class="fin-btn ghost" onclick="fmClose()">Annuler</button>
      <button class="fin-btn" id="fmSave" onclick="fmSubmit()">Créer le dossier</button>
    </div>
  </div>
</div>

<script>
const FIN_CSRF='<?= h(csrf_token('financement')) ?>';
const U_SAVE='<?= h(app_url('/api/financement_save.php')) ?>';
const U_LIEN='<?= h(app_url('/api/financement_lien.php')) ?>';
const U_ACC='<?= h(app_url('/api/financement_acces.php')) ?>';
let fmT='financement_bancaire', fmTiers=0, fmSoc=0, fmPilote=0;
function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function fmOpen(){document.getElementById('fmBg').classList.add('show');}
function fmClose(){document.getElementById('fmBg').classList.remove('show');}
document.getElementById('fmBg').addEventListener('click',e=>{if(e.target.id==='fmBg')fmClose();});
document.getElementById('fmType').addEventListener('click',e=>{const b=e.target.closest('.type-btn');if(!b)return;document.querySelectorAll('#fmType .type-btn').forEach(x=>x.classList.remove('on'));b.classList.add('on');fmT=b.dataset.t;});

const PREF_TIERS=<?= (int)$prefTiers ?>;
const PREF_TIERS_NOM=<?= json_encode($prefTiersNom) ?>;
function bindSearch(inputId,resId,pickId,url,onPick){
  const inp=document.getElementById(inputId),res=document.getElementById(resId);if(!inp||!res)return;let tmr=null;
  inp.addEventListener('input',function(){
    const q=this.value.trim();clearTimeout(tmr);if(q.length<2){res.classList.remove('show');res.innerHTML='';return;}
    tmr=setTimeout(()=>{fetch(url+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      res.innerHTML=(d.results||[]).map(x=>`<button type="button" data-id="${x.id}" data-lbl="${esc(x.label)}">${esc(x.label)}</button>`).join('')||'<div style="padding:8px;color:#999;font-size:.85rem">Aucun résultat</div>';
      res.classList.add('show');
    });},250);
  });
  res.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;onPick(parseInt(b.dataset.id),b.dataset.lbl);document.getElementById(pickId).textContent='✓ '+b.dataset.lbl;res.classList.remove('show');inp.value=b.dataset.lbl;});
}
bindSearch('fmTiersQ','fmTiersRes','fmTiersPick',U_LIEN+'?op=search&type=TIERS&q=',(id)=>{fmTiers=id;fmSoc=0;document.getElementById('fmSocPick').textContent='';});
bindSearch('fmSocQ','fmSocRes','fmSocPick',U_LIEN+'?op=search&type=SOCIETE&q=',(id)=>{fmSoc=id;fmTiers=0;document.getElementById('fmTiersPick').textContent='';});
bindSearch('fmPiloteQ','fmPiloteRes','fmPilotePick',U_ACC+'?op=search_user&q=',(id)=>{fmPilote=id;});

async function fmSubmit(){
  const lib=document.getElementById('fmLibelle').value.trim();
  if(!lib){alert('Libellé requis');return;}
  const btn=document.getElementById('fmSave');btn.disabled=true;
  const payload={type:fmT,libelle:lib,statut:document.getElementById('fmStatut').value,confidentialite:document.getElementById('fmConfid').value};
  if(PREF_TIERS)fmTiers=PREF_TIERS;
  if(fmTiers)payload.id_tiers=fmTiers; if(fmSoc)payload.id_societe_concernee=fmSoc; if(fmPilote)payload.pilote_user_id=fmPilote;
  // biens + créanciers cochés (création depuis la fiche propriétaire)
  const liens=[];
  document.querySelectorAll('.prefBien:checked').forEach(c=>liens.push({type:'BIEN',id:parseInt(c.value),role:'bien_concerne'}));
  document.querySelectorAll('.prefCre:checked').forEach(c=>liens.push({type:'CREANCIER_DOSSIER',id:parseInt(c.value),role:'creancier_lie'}));
  if(liens.length)payload.liens=liens;
  try{
    const r=await fetch(U_SAVE,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':FIN_CSRF},body:JSON.stringify(payload)});
    const j=await r.json();
    if(!j.ok){alert(j.error||'Erreur');btn.disabled=false;return;}
    location.href='<?= h(app_url('/financement_360.php?id=')) ?>'+j.id;
  }catch(e){alert('Erreur réseau');btn.disabled=false;}
}
// Création pré-branchée sur un propriétaire (depuis sa fiche) : pré-remplir + ouvrir.
if(PREF_TIERS){
  fmTiers=PREF_TIERS;
  const lib=document.getElementById('fmLibelle'); if(lib && !lib.value) lib.value='Financement — '+PREF_TIERS_NOM;
  fmOpen();
}
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
