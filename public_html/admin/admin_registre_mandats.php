<?php
declare(strict_types=1);
/**
 * admin/admin_registre_mandats.php — Registre des mandats de gestion (loi Hoguet).
 * Liste tous les mandats extraits (n° de registre réel, dates, honoraires, statut actif/terminé)
 * + une file des mandats GED à extraire par IA (bouton qui boucle sur api/mandat_extraire.php).
 * Réservé admin / super admin.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin_or_super_admin();
require_once __DIR__ . '/../inc/mandat_registre.php';

$pdo      = $GLOBALS['pdo'];
$registre = mr_list_registre($pdo);
$doublons = mr_doublons($pdo);
$masques  = mr_doublons_masques($pdo);
$audit    = mr_audit_crg($pdo);
$manquants = mr_proprios_sans_mandat($pdo);
$nbAExtraire = count(array_filter($manquants, fn($m) => (int)$m['nb_doc_mandat'] > 0));
$nbVidesMandat = count($manquants) - $nbAExtraire;
$aFaire   = mr_mandats_a_extraire($pdo);
$nbDupRows = array_sum(array_map(fn($g) => count($g['rows']) - 1, $doublons)); // lignes en trop
$csrf     = function_exists('csrf_token') ? csrf_token('mandat_extraire') : '';
$apiUrl   = app_url('/api/mandat_extraire.php');

$nbActif  = count(array_filter($registre, fn($m) => $m['statut'] === 'actif'));
$nbTerm   = count(array_filter($registre, fn($m) => $m['statut'] === 'termine'));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fd = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registre des mandats — MaBoxImmo</title>
<style>
 body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f6f5f2;margin:0;color:#2c2a28;}
 .wrap{max-width:1300px;margin:0 auto;padding:20px;}
 h1{font-size:22px;margin:0 0 4px;}
 .sub{color:#7a766f;font-size:13px;margin-bottom:16px;}
 .stats{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
 .stat{background:#fff;border-radius:12px;padding:12px 18px;box-shadow:0 2px 8px rgba(0,0,0,.05);}
 .stat b{font-size:20px;display:block;}
 .bar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;}
 .btn{border:none;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;font-size:13px;}
 .btn-go{background:#5e35b1;color:#fff;} .btn-go:disabled{opacity:.5;}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05);font-size:12.5px;}
 th{background:#ede7f6;color:#4527a0;text-align:left;padding:9px 10px;font-size:11px;text-transform:uppercase;}
 td{padding:8px 10px;border-top:1px solid #f0ecf6;vertical-align:middle;}
 .tag{padding:2px 9px;border-radius:99px;font-weight:800;font-size:11px;}
 .tag.actif{background:#d9f0db;color:#2d6a35;} .tag.termine{background:#fde2e2;color:#a23;} .tag.inconnu{background:#eee;color:#777;}
 .muted{color:#9a9690;} .prog{font-weight:700;color:#4527a0;font-size:13px;}
 a{color:#5b21b6;}
</style></head><body>
<div class="wrap">
  <h1>📜 Registre des mandats de gestion</h1>
  <div class="sub">Loi Hoguet · art. 65 décret 72-678 — registre chronologique numéroté. Numéro = celui porté par le mandat. Statut calculé (durée ferme / tacite reconduction, plafond 30 ans).</div>

  <div class="stats">
    <div class="stat"><b><?= count($registre) ?></b>mandats au registre</div>
    <div class="stat"><b style="color:#2d6a35;"><?= $nbActif ?></b>actifs</div>
    <div class="stat"><b style="color:#a23;"><?= $nbTerm ?></b>terminés</div>
    <div class="stat"><b style="color:#8a6d1b;"><?= count($aFaire) ?></b>à extraire (IA)</div>
  </div>

  <div class="bar">
    <?php if ($aFaire): ?>
    <button type="button" class="btn btn-go" id="goBtn" onclick="extraireTout()">🤖 Extraire les <?= count($aFaire) ?> restants (Haiku)</button>
    <?php endif; ?>
    <?php if ($registre): ?>
    <button type="button" class="btn" id="reBtn" style="background:#fff;color:#5e35b1;border:1px solid #b39ddb;"
            onclick="reAnalyser()">🔁 Ré-analyser TOUT en Sonnet (qualité max, re-paye)</button>
    <?php endif; ?>
    <span class="prog" id="prog"></span>
  </div>

  <?php $as = $audit['stats']; ?>
  <details style="margin-bottom:14px;background:#eef6ff;border:1px solid #bcdcff;border-radius:12px;padding:12px 16px;" <?= ($as['sans_proprio']||$as['proprio_sans_crg'])?'open':'' ?>>
    <summary style="cursor:pointer;font-weight:800;color:#1e40af;">
      🔗 Rattachement CRG : <b style="color:#2d6a35;"><?= $as['avec_crg'] ?></b> liés à un proprio CRG ·
      <b style="color:#b45309;"><?= $as['proprio_sans_crg'] ?></b> proprio sans CRG ·
      <b style="color:#a23;"><?= $as['sans_proprio'] ?></b> sans proprio
      <span style="font-weight:400;color:#7a766f;">(sur <?= $as['total'] ?>)</span>
    </summary>
    <?php if ($audit['problematiques']): ?>
    <div style="font-size:12px;color:#1e40af;margin:8px 0;">Mandats <b>mal/non rattachés</b> à un proprio CRG (à vérifier — souvent des non-mandats ou un proprio doublon) :</div>
    <table><thead><tr><th>N°</th><th>Propriétaire</th><th>Problème</th><th>Doc</th></tr></thead><tbody>
      <?php foreach ($audit['problematiques'] as $r): ?>
      <tr>
        <td><b><?= $h($r['numero_mandat'] ?: '?') ?></b></td>
        <td><?php if($r['id_proprietaire']): ?><a href="<?= $h(app_url('/agency_proprietaire_fiche.php?id='.(int)$r['id_proprietaire'])) ?>"><?= $h($r['proprio_nom']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= $r['sans_proprio'] ? '<span style="color:#a23;font-weight:700;">aucun propriétaire lié</span>' : '<span style="color:#b45309;font-weight:700;">proprio sans compte CRG</span>' ?></td>
        <td><?php if($r['ged_document_id']): ?><a href="<?= $h(app_url('/api/ged_document_view.php?id='.(int)$r['ged_document_id'].'&mode=inline')) ?>" target="_blank">PDF↗</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php else: ?>
    <div style="font-size:12px;color:#2d6a35;margin-top:6px;">✅ Tous les mandats sont rattachés à un propriétaire ayant un compte CRG.</div>
    <?php endif; ?>
  </details>

  <?php if ($manquants): ?>
  <details style="margin-bottom:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px 16px;">
    <summary style="cursor:pointer;font-weight:800;color:#991b1b;">
      📭 <?= count($manquants) ?> propriétaires SANS mandat au registre ·
      <b style="color:#b45309;"><?= $nbAExtraire ?></b> ont un doc à extraire ·
      <b style="color:#a23;"><?= $nbVidesMandat ?></b> sans aucun mandat trouvé
    </summary>
    <div style="font-size:12px;color:#7a766f;margin:8px 0;">« doc à extraire » = un mandat est en GED mais pas encore analysé → relance l'extraction. « aucun mandat » = à récupérer/scanner dans OneDrive.</div>
    <table><thead><tr><th>Propriétaire</th><th>Agence</th><th>Compte CRG</th><th>Biens</th><th>Situation</th></tr></thead><tbody>
      <?php foreach ($manquants as $m): ?>
      <tr>
        <td><a href="<?= $h(app_url('/agency_proprietaire_fiche.php?id='.(int)$m['id'])) ?>"><?= $h($m['nom']) ?></a> <span class="muted">#<?= (int)$m['id'] ?></span></td>
        <td><?= $h($m['code_agence']) ?></td>
        <td class="muted"><?= $h($m['code_compte'] ?: '—') ?></td>
        <td><?= (int)$m['nb_biens'] ?></td>
        <td><?= (int)$m['nb_doc_mandat'] > 0
              ? '<span style="color:#b45309;font-weight:700;">📄 mandat en GED, à extraire ('.(int)$m['nb_doc_mandat'].')</span>'
              : '<span style="color:#a23;font-weight:700;">⛔ aucun mandat trouvé</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
  </details>
  <?php endif; ?>

  <?php if ($doublons): ?>
  <details style="margin-bottom:18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px 16px;">
    <summary style="cursor:pointer;font-weight:800;color:#9a3412;">⚠️ <?= count($doublons) ?> mandats en doublon · <?= (int)$nbDupRows ?> lignes en trop <span style="font-weight:400;color:#7a766f;">(cliquer pour détailler — rien n'est supprimé)</span></summary>
    <div style="font-size:12px;color:#7a5b00;margin:8px 0 12px;">
      Coche <b>Garder</b> pour chaque ligne à conserver au registre. Les lignes <b>décochées</b> seront <b>marquées comme doublon</b> (masquées, rien n'est supprimé — réversible).<br>
      ⚠️ <b>Faux doublon ?</b> Coche <b>Garder</b> sur <b>toutes</b> les lignes du groupe : les deux sont conservées. Tu peux éditer chaque ligne avant de valider.
    </div>
    <table>
      <thead><tr><th>Garder</th><th>N°</th><th>Propriétaire</th><th>Bien</th><th>Effet</th><th>Statut</th><th>Doc</th></tr></thead>
      <tbody>
      <?php foreach ($doublons as $gi => $g):
        $dejaTraite = false; foreach ($g['rows'] as $r) { if ((int)($r['is_doublon'] ?? 0) === 1) { $dejaTraite = true; break; } }
        foreach ($g['rows'] as $i => $r):
          // État de la case : si le groupe a déjà été traité, on reflète l'enregistré ; sinon, suggestion IA (1re ligne = à garder).
          $keep = $dejaTraite ? ((int)($r['is_doublon'] ?? 0) === 0) : ($i === 0);
      ?>
        <tr data-grp="<?= (int)$gi ?>" style="<?= $i===0 ? 'background:#f0fdf4;' : '' ?>">
          <td style="text-align:center;">
            <input type="checkbox" class="dup-keep" data-id="<?= (int)$r['id'] ?>" data-grp="<?= (int)$gi ?>" <?= $keep ? 'checked' : '' ?> style="width:18px;height:18px;cursor:pointer;">
          </td>
          <td><b><?= $h($r['numero_mandat'] ?: '?') ?></b></td>
          <td><?= $h($r['proprio_nom'] ?: $r['mandant_noms']) ?></td>
          <td class="muted"><?= $h(mb_substr((string)$r['bien_designation'],0,40)) ?></td>
          <td><?= $fd($r['date_effet']) ?></td>
          <td><span class="tag <?= $h($r['statut']) ?>"><?= $h($r['statut']) ?></span></td>
          <td>
            <a href="javascript:void(0)" onclick='mandatEditOpen(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>✏️</a>
            <?php if($r['ged_document_id']): ?> <a href="<?= $h(app_url('/api/ged_document_view.php?id='.(int)$r['ged_document_id'].'&mode=inline')) ?>" target="_blank">PDF↗</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:10px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <button type="button" id="dupSave" style="background:#9a3412;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;">💾 Valider les choix</button>
      <span id="dupMsg" style="font-size:12px;color:#7a5b00;"></span>
    </div>
    <div style="margin-top:8px;font-size:11px;color:#7a766f;">
      Les lignes décochées sont <b>masquées</b> du registre (champ <code>is_doublon</code>), jamais supprimées. Re-cocher puis valider les restaure. Les fichiers restent dans la GED.
    </div>
  </details>
  <?php endif; ?>

  <?php if ($masques): ?>
  <details style="margin-bottom:18px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:12px;padding:12px 16px;">
    <summary style="cursor:pointer;font-weight:800;color:#374151;">🙈 <?= count($masques) ?> ligne(s) masquée(s) (doublons) <span style="font-weight:400;color:#7a766f;">— exclues du registre, restaurables</span></summary>
    <table style="margin-top:10px;">
      <thead><tr><th>N°</th><th>Propriétaire</th><th>Bien</th><th>Effet</th><th>Statut</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($masques as $r): ?>
        <tr>
          <td><b><?= $h($r['numero_mandat'] ?: '?') ?></b></td>
          <td><?= $h($r['proprio_nom'] ?: $r['mandant_noms']) ?></td>
          <td class="muted"><?= $h(mb_substr((string)$r['bien_designation'],0,40)) ?></td>
          <td><?= $fd($r['date_effet']) ?></td>
          <td><span class="tag <?= $h($r['statut']) ?>"><?= $h($r['statut']) ?></span></td>
          <td><button type="button" class="dup-restore" data-id="<?= (int)$r['id'] ?>" style="background:#065f46;color:#fff;border:0;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;">↩️ Restaurer</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>

  <table>
    <thead><tr>
      <th>N°</th><th>Propriétaire</th><th>Bien</th><th>Effet</th><th>Fin théorique</th>
      <th>Type durée</th><th>Hono. gestion</th><th>Statut</th><th>Éditer / Doc</th>
    </tr></thead>
    <tbody>
    <?php if (!$registre): ?>
      <tr><td colspan="9" class="muted" style="padding:16px;">Aucun mandat extrait pour l'instant. Lance l'extraction ci-dessus.</td></tr>
    <?php else: foreach ($registre as $m):
      $duree = $m['duree_ferme'] ? ('ferme '.$h($m['duree_initiale_ans']).' ans')
             : ($m['tacite_reconduction'] ? 'tacite reconduction' : '—');
      $hono  = $m['hono_gestion_taux_ttc'] ? ($h(rtrim(rtrim((string)$m['hono_gestion_taux_ttc'],'0'),'.')).' % TTC') : '—';
    ?>
      <tr>
        <td><b><?= $h($m['numero_mandat'] ?: '?') ?></b><?php if (!empty($m['numero_mandat_alt'])): ?><br><span class="muted" style="font-size:10px;">anc. <?= $h($m['numero_mandat_alt']) ?></span><?php endif; ?></td>
        <td><?php if($m['id_proprietaire']): ?><a href="<?= $h(app_url('/agency_proprietaire_fiche.php?id='.(int)$m['id_proprietaire'])) ?>"><?= $h($m['proprio_nom']) ?></a><?php else: ?><?= $h($m['mandant_noms']) ?><?php endif; ?></td>
        <td class="muted"><?= $h(mb_substr((string)$m['bien_designation'],0,48)) ?></td>
        <td><?= $fd($m['date_effet']) ?></td>
        <td><?= $fd($m['date_fin_theorique']) ?></td>
        <td class="muted"><?= $duree ?></td>
        <td><?= $hono ?></td>
        <td><span class="tag <?= $h($m['statut']) ?>"><?= $h($m['statut']) ?></span></td>
        <td>
          <a href="javascript:void(0)" onclick='mandatEditOpen(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)' style="font-weight:700;">✏️ Éditer</a>
          <?php if($m['ged_document_id']): ?> · <a href="<?= $h(app_url('/api/ged_document_view.php?id='.(int)$m['ged_document_id'].'&mode=inline')) ?>" target="_blank">PDF ↗</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
(function(){
  var CSRF=<?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode($apiUrl, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var TODO=<?= json_encode(array_map(fn($x)=>['doc'=>(int)$x['ged_document_id'],'pid'=>(int)($x['proprio_id']??0),'tid'=>(int)($x['tiers_id']??0)], $aFaire), JSON_HEX_TAG) ?>;
  var DONE=<?= json_encode(array_map(fn($x)=>['doc'=>(int)$x['ged_document_id'],'pid'=>(int)($x['id_proprietaire']??0),'tid'=>(int)($x['id_tiers']??0)], $registre), JSON_HEX_TAG) ?>;
  function runBatch(list, modele, btn){
    if(!list.length){return;}
    var b=btn?document.getElementById(btn):null; if(b)b.disabled=true;
    var p=document.getElementById('prog'); var i=0, ok=0, err=0, cts=0;
    function next(){
      if(i>=list.length){ p.textContent='✅ Terminé ('+modele+') — '+ok+' ok, '+err+' err, ~'+(cts/100).toFixed(2)+' € IA. Recharge la page.'; return; }
      var t=list[i]; p.textContent='Analyse '+modele+' '+(i+1)+'/'+list.length+'…';
      var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('action','one'); fd.append('modele',modele);
      fd.append('ged_document_id',t.doc); if(t.pid)fd.append('id_proprietaire',t.pid); if(t.tid)fd.append('id_tiers',t.tid);
      fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(function(j){
        if(j&&j.ok){ ok++; cts+=(j.cout_centimes||0); } else { err++; }
      }).catch(function(){err++;}).finally(function(){ i++; next(); });
    }
    next();
  }
  window.reAnalyser=function(){ if(confirm('Ré-analyser les '+DONE.length+' mandats en Sonnet ? (re-paye ~'+(DONE.length*3.5/100).toFixed(2)+' €)')) runBatch(DONE,'sonnet','reBtn'); };
  window.extraireTout=function(){
    var b=document.getElementById('goBtn'); if(b)b.disabled=true;
    var p=document.getElementById('prog'); var i=0, ok=0, err=0, cts=0;
    function next(){
      if(i>=TODO.length){ p.textContent='✅ Terminé — '+ok+' extrait(s), '+err+' erreur(s), ~'+(cts/100).toFixed(2)+' € IA. Recharge la page.'; return; }
      var t=TODO[i]; p.textContent='Extraction '+(i+1)+'/'+TODO.length+'…';
      var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('action','one');
      fd.append('ged_document_id',t.doc); if(t.pid)fd.append('id_proprietaire',t.pid); if(t.tid)fd.append('id_tiers',t.tid);
      fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(function(j){
        if(j&&j.ok){ ok++; cts+=(j.cout_centimes||0); } else { err++; }
      }).catch(function(){err++;}).finally(function(){ i++; next(); });
    }
    next();
  };

  // --- Validation des doublons : coché=garder (is_doublon=0), décoché=masquer (is_doublon=1) ---
  var FLAG_URL=<?= json_encode(app_url('/api/mandat_doublon_flag.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var dupBtn=document.getElementById('dupSave');
  if(dupBtn){
    dupBtn.addEventListener('click',function(){
      var boxes=Array.prototype.slice.call(document.querySelectorAll('.dup-keep'));
      // Garde-fou : au moins une ligne gardée par groupe.
      var byGrp={}; boxes.forEach(function(b){ var g=b.getAttribute('data-grp'); (byGrp[g]=byGrp[g]||[]).push(b); });
      var vides=Object.keys(byGrp).filter(function(g){ return byGrp[g].every(function(b){ return !b.checked; }); });
      if(vides.length){ alert('Chaque groupe doit garder au moins une ligne. '+vides.length+' groupe(s) ont tout décoché — coche-en au moins une.'); return; }
      var msg=document.getElementById('dupMsg');
      dupBtn.disabled=true; var i=0, ok=0, err=0, lastErr='';
      function next(){
        if(i>=boxes.length){
          if(err){ msg.style.color='#b91c1c'; msg.textContent='⚠️ '+err+' erreur(s) — '+lastErr+' (rien enregistré sur ces lignes).'; }
          else { msg.style.color='#065f46'; msg.textContent='✅ '+ok+' ligne(s) enregistrée(s).'; setTimeout(function(){location.reload();},700); }
          dupBtn.disabled=false; return;
        }
        var b=boxes[i]; msg.style.color='#7a5b00'; msg.textContent='Enregistrement '+(i+1)+'/'+boxes.length+'…';
        var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('id',b.getAttribute('data-id')); fd.append('flag', b.checked?'0':'1');
        fetch(FLAG_URL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(function(j){
          if(j&&j.ok){ ok++; } else { err++; lastErr=(j&&j.error)?j.error:'réponse invalide'; }
        }).catch(function(e){err++; lastErr='réseau';}).finally(function(){ i++; next(); });
      }
      next();
    });
  }

  // --- Restaurer une ligne masquée (is_doublon -> 0) ---
  Array.prototype.slice.call(document.querySelectorAll('.dup-restore')).forEach(function(btn){
    btn.addEventListener('click',function(){
      btn.disabled=true; btn.textContent='…';
      var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('id',btn.getAttribute('data-id')); fd.append('flag','0');
      fetch(FLAG_URL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(function(j){
        if(j&&j.ok){ location.reload(); } else { btn.disabled=false; btn.textContent='↩️ Restaurer'; alert('Échec : '+((j&&j.error)||'réponse invalide')); }
      }).catch(function(){ btn.disabled=false; btn.textContent='↩️ Restaurer'; alert('Échec réseau'); });
    });
  });
})();
</script>
<?php include __DIR__ . '/../inc/mandat_edit_modal.php'; ?>
</body></html>
