<?php
declare(strict_types=1);
/**
 * financement_360.php — Cockpit unique du dossier financier (Tranche 1).
 * 6 parties : Synthèse · Documents & informations extraites · Montants & décomptes ·
 * Créanciers/procédures/saisies · Paiements/échéances/actions · Participants & échanges.
 * T1 : synthèse, documents (GED), créancier lié (lecture+ouverture), participants, biens/immeubles.
 * Placeholders propres pour Montants et Paiements (prochaines tranches), sans données fictives.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/financement.php';
require_login();

$pdo = $GLOBALS['pdo'];
$soc = fin_soc();
$id  = (int)($_GET['id'] ?? 0);
$dossier = $id > 0 ? fin_get($pdo, $id) : null;
if (!$dossier || !fin_can_view($pdo, $dossier)) { http_response_code(403); exit('Dossier introuvable ou accès refusé.'); }

$typeL = fin_type_labels(); $statL = fin_statut_labels(); $confL = fin_confid_labels();
$roleL = fin_role_labels(); $nivL = fin_niveau_labels(); $catL = fin_ged_categories();

$links = fin_links($pdo, $id);
$participants = fin_acces_list($pdo, $id);

// Synthèses créancier (lecture seule, jamais de copie)
$creanciers = [];
foreach (($links['CREANCIER_DOSSIER'] ?? []) as $cl) {
    $s = fin_creancier_synthese($pdo, (int)$cl['entity_id']);
    if ($s) $creanciers[] = $s;
}
// Documents GED liés (avec catégorie = relation_type)
$docs = [];
try {
    $st = $pdo->prepare("SELECT d.id, d.name_display, d.document_type, dl.relation_type AS categorie, d.created_at
                         FROM ged_document_links dl JOIN ged_documents d ON d.id = dl.document_id
                         WHERE dl.entity_type='FIN' AND dl.entity_id=? AND d.status='active'
                         ORDER BY dl.relation_type, d.created_at DESC");
    $st->execute([$id]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$sujet = $dossier['tiers_nom'] ?: $dossier['societe_concernee_nom'] ?: '—';

$appLayout = true;
$pageTitle = 'Dossier financier — ' . $dossier['libelle'];
$robots = 'noindex, nofollow';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<style>
:root{ --fin:#7a6830; --fin-d:#5c4e22; }
.f3-wrap{margin-left:220px;padding:16px 20px;max-width:1400px}
@media(max-width:900px){.f3-wrap{margin-left:0}}
.f3-top{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.f3-back{color:var(--fin-d);text-decoration:none;font-size:.85rem}
.f3-h{font-size:1.25rem;font-weight:800;color:#243B5C;margin:2px 0}
.f3-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
.f3-b{border-radius:20px;padding:2px 10px;font-size:.74rem;font-weight:700}
.f3-b.ty{background:#f3eedd;color:#7a6830}.f3-b.st{background:#eef3f5;color:#48606a}.f3-b.cf{background:#fdecea;color:#c0392b}
.f3-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #ece7d6;margin-bottom:14px}
.f3-tab{padding:9px 13px;font-size:.85rem;cursor:pointer;color:#6b6455;border-bottom:2px solid transparent}
.f3-tab.on{color:var(--fin-d);border-bottom-color:var(--fin);font-weight:700}
.f3-sec{background:#fff;border:1px solid #ece7d6;border-radius:12px;padding:16px;margin-bottom:14px}
.f3-sec h4{margin:0 0 10px;font-size:.9rem;color:#243B5C}
.f3-ph{text-align:center;color:#98917f;padding:34px;background:#faf8f2;border:1px dashed #e2d9bd;border-radius:12px}
.f3-kv{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
.f3-kv .c{background:#faf8f2;border:1px solid #f0ead9;border-radius:9px;padding:8px 10px}
.f3-kv .k{font-size:.68rem;color:#98917f;text-transform:uppercase}.f3-kv .v{font-weight:700;color:#243B5C;font-size:.9rem}
.f3-list{display:flex;flex-direction:column;gap:8px}
.f3-item{display:flex;align-items:center;gap:10px;padding:9px 11px;border:1px solid #eee;border-radius:9px;background:#fff}
.f3-item .g{flex:1;min-width:0}.f3-item a{color:var(--fin-d)}
.f3-x{border:0;background:#f4f0e6;color:#c0392b;border-radius:7px;padding:4px 9px;cursor:pointer;font-weight:700}
.f3-btn{background:var(--fin);color:#fff;border:0;border-radius:9px;padding:8px 14px;font-size:.83rem;font-weight:700;cursor:pointer}
.f3-btn.ghost{background:#fff;color:var(--fin-d);border:1px solid #e2d9bd}
.f3-add{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}
.f3-add input,.f3-add select{padding:8px 10px;border:1px solid #d8d2c2;border-radius:8px;font-size:.85rem}
.f3-res{position:relative}
.f3-res .r{position:absolute;z-index:20;background:#fff;border:1px solid #ddd;border-radius:8px;margin-top:2px;max-height:180px;overflow:auto;min-width:280px;display:none}
.f3-res .r.show{display:block}
.f3-res .r button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:8px 11px;cursor:pointer;font-size:.84rem;border-bottom:1px solid #f2f2f2}
.f3-res .r button:hover{background:#faf6ea}
.cre-card{border:1px solid #e6ebf0;border-left:4px solid #DD4735;border-radius:10px;padding:11px 13px}
.cre-card .rk{font-size:.72rem;border-radius:20px;padding:1px 8px;font-weight:700}
.rk.vert{background:#e7f6ee;color:#1e7d4f}.rk.orange{background:#fff4e0;color:#a8730a}.rk.rouge{background:#fdecea;color:#c0392b}
textarea.f3-syn{width:100%;box-sizing:border-box;min-height:70px;padding:9px 11px;border:1px solid #d8d2c2;border-radius:9px;font-family:inherit;font-size:.88rem}
</style>

<div class="f3-wrap">
  <div class="f3-top">
    <div style="flex:1">
      <a class="f3-back" href="<?= h(app_url('/financement_liste.php')) ?>">← Dossiers financiers</a>
      <div class="f3-h">💶 <?= h($dossier['libelle']) ?></div>
      <div class="f3-badges">
        <span class="f3-b ty"><?= h($typeL[$dossier['type']] ?? $dossier['type']) ?></span>
        <span class="f3-b st"><?= h($statL[$dossier['statut']] ?? $dossier['statut']) ?></span>
        <?php if ($dossier['confidentialite'] !== 'normal'): ?><span class="f3-b cf">🔒 <?= h($confL[$dossier['confidentialite']] ?? '') ?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="f3-tabs" id="f3Tabs">
    <div class="f3-tab on" data-t="synthese">Synthèse</div>
    <div class="f3-tab" data-t="docs">Documents & informations extraites</div>
    <div class="f3-tab" data-t="montants">Montants & décomptes</div>
    <div class="f3-tab" data-t="creancier">Créanciers, procédures & saisies</div>
    <div class="f3-tab" data-t="paiements">Paiements, échéances & actions</div>
    <div class="f3-tab" data-t="participants">Participants & échanges</div>
  </div>

  <!-- 1. SYNTHÈSE -->
  <div class="f3-pane" data-p="synthese">
    <div class="f3-sec">
      <h4>Informations du dossier</h4>
      <div class="f3-kv">
        <div class="c"><div class="k">Sujet</div><div class="v"><?= h($sujet) ?></div></div>
        <div class="c"><div class="k">Type</div><div class="v"><?= h($typeL[$dossier['type']] ?? '') ?></div></div>
        <div class="c"><div class="k">Pilote</div><div class="v"><?= h($dossier['pilote_nom'] ?: '—') ?></div></div>
        <div class="c"><div class="k">Statut</div><div class="v">
          <select id="synStatut" onchange="saveHead('statut',this.value)" style="border:0;background:transparent;font-weight:700;color:#243B5C">
            <?php foreach ($statL as $k => $l): ?><option value="<?= h($k) ?>" <?= $dossier['statut'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select></div></div>
        <div class="c"><div class="k">Confidentialité</div><div class="v">
          <select id="synConfid" onchange="saveHead('confidentialite',this.value)" style="border:0;background:transparent;font-weight:700;color:#243B5C">
            <?php foreach ($confL as $k => $l): ?><option value="<?= h($k) ?>" <?= $dossier['confidentialite'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select></div></div>
      </div>
      <h4 style="margin-top:14px">Synthèse</h4>
      <textarea class="f3-syn" id="synText" onblur="saveHead('synthese',this.value)" placeholder="Contexte du dossier, points clés…"><?= h($dossier['synthese'] ?? '') ?></textarea>
    </div>

    <div class="f3-sec">
      <h4>Biens & immeubles concernés</h4>
      <div class="f3-list" id="lstBiens">
        <?php foreach (['BIEN' => '🏘️', 'IMMEUBLE' => '🏢'] as $et => $ic): foreach (($links[$et] ?? []) as $l): ?>
          <div class="f3-item"><span><?= $ic ?></span><div class="g"><a href="<?= h($l['url']) ?>"><?= h($l['label']) ?></a></div>
            <button class="f3-x" onclick="rmLink('<?= $et ?>',<?= (int)$l['entity_id'] ?>)">Retirer</button></div>
        <?php endforeach; endforeach; ?>
      </div>
      <div class="f3-add">
        <select id="addBienType"><option value="BIEN">Bien</option><option value="IMMEUBLE">Immeuble</option></select>
        <div class="f3-res"><input id="addBienQ" placeholder="Rechercher un bien / immeuble…" autocomplete="off"><div class="r" id="addBienRes"></div></div>
      </div>
    </div>
  </div>

  <!-- 2. DOCUMENTS -->
  <div class="f3-pane" data-p="docs" style="display:none">
    <div class="f3-sec">
      <h4>Documents rattachés (GED)</h4>
      <div class="f3-list" id="lstDocs">
        <?php if (!$docs): ?><div class="f3-ph" style="padding:18px">Aucun document rattaché. Reliez un document existant ci-dessous.</div><?php endif; ?>
        <?php foreach ($docs as $doc): ?>
          <div class="f3-item"><span>📄</span>
            <div class="g"><a href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$doc['id'])) ?>" target="_blank"><?= h($doc['name_display'] ?? ('Document #' . $doc['id'])) ?></a>
              <div style="font-size:.74rem;color:#98917f"><?= h($catL[$doc['categorie']] ?? $doc['categorie'] ?? '') ?></div></div>
            <button class="f3-x" onclick="detachDoc(<?= (int)$doc['id'] ?>)">Retirer</button></div>
        <?php endforeach; ?>
      </div>
      <?php require_once __DIR__ . '/inc/mail_button.php'; ?>
      <a href="<?= h(mail_compose_url('FIN', $id, 'financement_360.php?id=' . $id)) ?>" target="_blank" class="f3-btn ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin:6px 0 10px">📧 Envoyer un document par mail</a>
      <div style="font-weight:700;color:#5c4e22;font-size:.85rem;margin:14px 0 4px">📤 Charger un nouveau document</div>
      <div class="f3-add">
        <select id="upCat"><?php foreach ($catL as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
        <input type="file" id="upFile" style="font-size:.82rem">
        <button class="f3-btn" id="upBtn" onclick="uploadDoc()">Charger</button>
      </div>
      <div style="font-weight:700;color:#5c4e22;font-size:.85rem;margin:16px 0 4px">🔗 Ou rattacher un document déjà présent en GED</div>
      <div class="f3-add">
        <select id="docCat"><?php foreach ($catL as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
        <div class="f3-res"><input id="docQ" placeholder="Rechercher un document GED existant…" autocomplete="off"><div class="r" id="docRes"></div></div>
      </div>
      <p style="color:#98917f;font-size:.8rem;margin-top:8px">Le document est <b>conservé dans la GED générale</b> et relié à ce dossier (« Retirer » enlève le lien, pas le document). Formats : PDF, images, Word, Excel, ZIP… (max 25 Mo). L'analyse IA et l'extraction arrivent en Tranche 2.</p>
    </div>
  </div>

  <!-- 3. MONTANTS (placeholder) -->
  <div class="f3-pane" data-p="montants" style="display:none">
    <div class="f3-ph">💶 Montants & décomptes (principal, intérêts, agios, indemnités, frais, versions)<br><b>Fonction disponible dans une prochaine tranche.</b></div>
  </div>

  <!-- 4. CRÉANCIER -->
  <div class="f3-pane" data-p="creancier" style="display:none">
    <div class="f3-sec">
      <h4>Dossiers Créancier liés</h4>
      <div class="f3-list" id="lstCre">
        <?php if (!$creanciers): ?><div class="f3-ph" style="padding:18px">Aucun dossier Créancier lié. Reliez-en un ci-dessous.</div><?php endif; ?>
        <?php foreach ($creanciers as $c): $pe = $c['prochaine_echeance']; ?>
          <div class="cre-card">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <b style="color:#243B5C"><?= h(trim(($c['code'] ?? '') . ' — ' . ($c['libelle'] ?? ''), ' —')) ?></b>
              <span class="rk <?= h($c['niveau_risque'] ?? '') ?>"><?= h(ucfirst((string)($c['niveau_risque'] ?? '—'))) ?></span>
              <span class="f3-b st"><?= h($c['statut'] ?? '') ?></span>
              <a class="f3-btn ghost" style="margin-left:auto;text-decoration:none" href="<?= h(app_url('/creancier_dossier360.php?id=' . (int)$c['id'])) ?>">Ouvrir le dossier Créancier →</a>
              <button class="f3-x" onclick="rmLink('CREANCIER_DOSSIER',<?= (int)$c['id'] ?>)">Retirer</button>
            </div>
            <div style="font-size:.82rem;color:#6b6455;margin-top:6px">
              Créancier principal : <b><?= h($c['creancier_principal'] ?: '—') ?></b>
              <?php if ($pe): ?> · Prochaine échéance : <b><?= h(date('d/m/Y', strtotime((string)$pe['date_prevue']))) ?></b> (<?= number_format((float)$pe['montant'], 2, ',', ' ') ?> €)<?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="f3-add">
        <div class="f3-res"><input id="creQ" placeholder="Rechercher un dossier Créancier (code, libellé)…" autocomplete="off"><div class="r" id="creRes"></div></div>
      </div>
      <p style="color:#98917f;font-size:.8rem;margin-top:8px">Saisies, échéanciers et procédures se gèrent dans le module Créancier (bouton « Ouvrir »). Ici, lecture et rattachement uniquement — aucune donnée dupliquée.</p>
    </div>
  </div>

  <!-- 5. PAIEMENTS (placeholder) -->
  <div class="f3-pane" data-p="paiements" style="display:none">
    <div class="f3-ph">📆 Paiements, échéances & actions<br><b>Fonction disponible dans une prochaine tranche.</b></div>
  </div>

  <!-- 6. PARTICIPANTS -->
  <div class="f3-pane" data-p="participants" style="display:none">
    <div class="f3-sec">
      <h4>Participants & droits</h4>
      <div class="f3-list" id="lstPart">
        <?php foreach ($participants as $p): ?>
          <div class="f3-item"><span>👤</span>
            <div class="g"><b><?= h($p['nom'] ?: ('#' . $p['identite_id'])) ?></b>
              <div style="font-size:.74rem;color:#98917f"><?= h($roleL[$p['role_intervenant']] ?? $p['role_intervenant']) ?> · <?= h($nivL[$p['niveau']] ?? $p['niveau']) ?><?= $p['perimetre'] !== 'tout' ? ' · ' . h($p['perimetre']) : '' ?></div></div>
            <button class="f3-x" onclick="rmPart(<?= (int)$p['id'] ?>)">Retirer</button></div>
        <?php endforeach; ?>
      </div>
      <div class="f3-add">
        <select id="partIdType"><option value="user">Collaborateur</option><option value="tiers">Tiers (avocat, comptable, notaire…)</option></select>
        <div class="f3-res"><input id="partQ" placeholder="Rechercher…" autocomplete="off"><div class="r" id="partRes"></div></div>
        <select id="partRole"><?php foreach ($roleL as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
        <select id="partNiv"><?php foreach ($nivL as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
        <button class="f3-btn" onclick="addPart()">Ajouter</button>
      </div>
      <p style="color:#98917f;font-size:.8rem;margin-top:8px">Accès interne (ACL). Le portail externe sécurisé (avocat / comptable / notaire / propriétaire) arrive dans une prochaine tranche.</p>
    </div>
  </div>
</div>

<script>
const FID=<?= (int)$id ?>;
const FIN_CSRF='<?= h(csrf_token('financement')) ?>';
const U_SAVE='<?= h(app_url('/api/financement_save.php')) ?>';
const U_LIEN='<?= h(app_url('/api/financement_lien.php')) ?>';
const U_ACC='<?= h(app_url('/api/financement_acces.php')) ?>';
const U_GED='<?= h(app_url('/api/financement_ged.php')) ?>';
const U_UP='<?= h(app_url('/api/financement_upload.php')) ?>';
async function uploadDoc(){
  const fi=document.getElementById('upFile'); if(!fi.files||!fi.files[0]){alert('Choisissez un fichier');return;}
  const btn=document.getElementById('upBtn'); btn.disabled=true; btn.textContent='…';
  const fd=new FormData();
  fd.append('id_dossier',FID); fd.append('categorie',document.getElementById('upCat').value); fd.append('file',fi.files[0]);
  try{
    const r=await fetch(U_UP,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':FIN_CSRF},body:fd});
    const j=await r.json(); if(!j.ok){alert(j.error||'Erreur');btn.disabled=false;btn.textContent='Charger';return;}
    location.reload();
  }catch(e){alert('Erreur réseau');btn.disabled=false;btn.textContent='Charger';}
}
function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function P(url,data){return fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':FIN_CSRF},body:JSON.stringify(data)}).then(r=>r.json());}

// tabs
document.getElementById('f3Tabs').addEventListener('click',e=>{const t=e.target.closest('.f3-tab');if(!t)return;
  document.querySelectorAll('.f3-tab').forEach(x=>x.classList.toggle('on',x===t));
  document.querySelectorAll('.f3-pane').forEach(p=>p.style.display=(p.dataset.p===t.dataset.t)?'':'none');});

// synthèse : sauvegarde tête
function saveHead(field,val){ const d={id:FID,type:'<?= h($dossier['type']) ?>',libelle:<?= json_encode($dossier['libelle']) ?>}; d[field]=val;
  P(U_SAVE,d).then(j=>{if(!j.ok)alert(j.error||'Erreur');}); }

// recherche générique
function bindRes(inpId,resId,url,onPick){
  const inp=document.getElementById(inpId),res=document.getElementById(resId);let tmr=null;
  inp.addEventListener('input',function(){const q=this.value.trim();clearTimeout(tmr);if(q.length<2){res.classList.remove('show');return;}
    tmr=setTimeout(()=>{fetch(url+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      res.innerHTML=(d.results||[]).map(x=>`<button type="button" data-id="${x.id}" data-lbl="${esc(x.label)}">${esc(x.label)}</button>`).join('')||'<div style="padding:8px;color:#999">Aucun</div>';res.classList.add('show');});},250);});
  res.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;onPick(parseInt(b.dataset.id),b.dataset.lbl);res.classList.remove('show');inp.value='';});
}
// biens/immeubles
bindRes('addBienQ','addBienRes','',(id)=>{}); // remplacé ci-dessous (type dynamique)
(function(){const inp=document.getElementById('addBienQ'),res=document.getElementById('addBienRes');let tmr=null;
  inp.addEventListener('input',function(){const q=this.value.trim(),ty=document.getElementById('addBienType').value;clearTimeout(tmr);if(q.length<2){res.classList.remove('show');return;}
    tmr=setTimeout(()=>{fetch(U_LIEN+'?op=search&type='+ty+'&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      res.innerHTML=(d.results||[]).map(x=>`<button type="button" data-id="${x.id}" data-lbl="${esc(x.label)}">${esc(x.label)}</button>`).join('')||'<div style="padding:8px;color:#999">Aucun</div>';res.classList.add('show');});},250);});
  res.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;const ty=document.getElementById('addBienType').value;
    P(U_LIEN,{op:'add',id_dossier:FID,type:ty,entity_id:parseInt(b.dataset.id),role:'bien_concerne'}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');});});
})();
// créancier
(function(){const inp=document.getElementById('creQ'),res=document.getElementById('creRes');let tmr=null;
  inp.addEventListener('input',function(){const q=this.value.trim();clearTimeout(tmr);if(q.length<2){res.classList.remove('show');return;}
    tmr=setTimeout(()=>{fetch(U_LIEN+'?op=search&type=CREANCIER_DOSSIER&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      res.innerHTML=(d.results||[]).map(x=>`<button type="button" data-id="${x.id}" data-lbl="${esc(x.label)}">${esc(x.label)}</button>`).join('')||'<div style="padding:8px;color:#999">Aucun</div>';res.classList.add('show');});},250);});
  res.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;
    P(U_LIEN,{op:'add',id_dossier:FID,type:'CREANCIER_DOSSIER',entity_id:parseInt(b.dataset.id),role:'creancier_lie'}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');});});
})();
function rmLink(type,eid){ if(!confirm('Retirer ce lien ?'))return; P(U_LIEN,{op:'remove',id_dossier:FID,type:type,entity_id:eid}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');}); }

// GED
(function(){const inp=document.getElementById('docQ'),res=document.getElementById('docRes');let tmr=null;
  inp.addEventListener('input',function(){const q=this.value.trim();clearTimeout(tmr);if(q.length<2){res.classList.remove('show');return;}
    tmr=setTimeout(()=>{fetch(U_GED+'?op=search&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      res.innerHTML=(d.results||[]).map(x=>`<button type="button" data-id="${x.id}" data-lbl="${esc(x.name_display||('Doc #'+x.id))}">${esc(x.name_display||('Doc #'+x.id))}</button>`).join('')||'<div style="padding:8px;color:#999">Aucun</div>';res.classList.add('show');});},250);});
  res.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;const cat=document.getElementById('docCat').value;
    P(U_GED,{op:'attach',id_dossier:FID,doc_id:parseInt(b.dataset.id),categorie:cat}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');});});
})();
function detachDoc(docId){ if(!confirm('Retirer ce document du dossier (il reste dans la GED) ?'))return; P(U_GED,{op:'detach',id_dossier:FID,doc_id:docId}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');}); }

// participants
let partId=0;
(function(){const inp=document.getElementById('partQ'),res=document.getElementById('partRes');let tmr=null;
  inp.addEventListener('input',function(){const q=this.value.trim(),it=document.getElementById('partIdType').value;clearTimeout(tmr);if(q.length<2){res.classList.remove('show');return;}
    const u=(it==='user')?(U_ACC+'?op=search_user&q='):(U_ACC+'?op=search_tiers&q=');
    tmr=setTimeout(()=>{fetch(u+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      res.innerHTML=(d.results||[]).map(x=>`<button type="button" data-id="${x.id}" data-lbl="${esc(x.label)}">${esc(x.label)}</button>`).join('')||'<div style="padding:8px;color:#999">Aucun</div>';res.classList.add('show');});},250);});
  res.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;partId=parseInt(b.dataset.id);inp.value=b.dataset.lbl;res.classList.remove('show');});
})();
function addPart(){ if(!partId){alert('Choisir une personne');return;}
  P(U_ACC,{op:'add',id_dossier:FID,identite_type:document.getElementById('partIdType').value,identite_id:partId,role_intervenant:document.getElementById('partRole').value,niveau:document.getElementById('partNiv').value}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');}); }
function rmPart(aid){ if(!confirm('Retirer ce participant ?'))return; P(U_ACC,{op:'remove',id_dossier:FID,acces_id:aid}).then(j=>{if(j.ok)location.reload();else alert(j.error||'Erreur');}); }
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
