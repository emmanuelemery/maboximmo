<?php
declare(strict_types=1);
/**
 * inc/immeuble_public_card.php — Carte « Données publiques de l'immeuble ».
 *
 * Affiche, dans une fiche (bien_360…), l'enrichissement public PERSISTÉ sur l'immeuble
 * (copropriété/RNC, parcelle cadastrale, zone PLU, altitude) + ses dates de fraîcheur.
 * Si une donnée manque — ou pour rafraîchir — un bouton (admin) relance les recherches
 * publiques depuis le GPS de l'immeuble et les re-persiste (api/immeuble_enrichir_save).
 *
 * Usage : immeuble_public_card($pdo, (int)$immeubleId, $canAct, ['lat'=>..,'lng'=>..]);
 *   $canAct = true → affiche le bouton de relance (réserver aux admins/pilote).
 */
if (!function_exists('immeuble_public_card')) {
function immeuble_public_card(PDO $pdo, int $immId, bool $canAct = false, array $fallback = []): void
{
    if ($immId <= 0) return;
    $st = $pdo->prepare("SELECT latitude, longitude, registre_copro_immatriculation, registre_copro_periode,
                                registre_copro_maj, copro_nb_lots, parcelle_reference, zone_plu, altitude,
                                enrichi_cadastre_le, enrichi_registre_le, enrichi_risques_le,
                                enrichissement_public_json
                         FROM immeubles WHERE id = ? LIMIT 1");
    try { $st->execute([$immId]); } catch (Throwable) { return; } // colonnes non migrées
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) return;

    $lat = $r['latitude'] ?: ($fallback['lat'] ?? '');
    $lng = $r['longitude'] ?: ($fallback['lng'] ?? '');
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $fr = static function($d){ if(!$d) return ''; $t=strtotime((string)$d); return $t?date('d/m/Y',$t):''; };
    $au = static fn(string $p) => function_exists('app_url') ? app_url($p) : $p;

    // Copropriété : immatriculation présente → copro ; sinon, si l'enrichissement a tourné
    // (parcelle/cadastre récupérés) → MONOPROPRIÉTÉ affirmée ; sinon « non vérifié ».
    $immat    = trim((string)($r['registre_copro_immatriculation'] ?? ''));
    $parcelle = trim((string)($r['parcelle_reference'] ?? ''));
    $enrichi  = ($parcelle !== '' || !empty($r['enrichi_cadastre_le']) || !empty($r['enrichi_registre_le']));

    $rows = [];
    if ($immat !== '') {
        // Copropriété : on garde immatriculation + lots
        $rows[] = ['🏛️', 'Copropriété (immatriculation)', $immat];
        $rows[] = ['🏠', 'Lots (copropriété)',            $r['copro_nb_lots'] ?? ''];
    } elseif ($enrichi) {
        // Monopropriété : on CACHE immatriculation + lots (sans intérêt)
        $rows[] = ['🏛️', 'Copropriété', 'Monopropriété'];
    } else {
        $rows[] = ['🏛️', 'Copropriété (immatriculation)', ''];
    }
    // Période de construction : TOUJOURS (info bâtiment générale, copro ou mono)
    $rows[] = ['🏗️', 'Période de construction', $r['registre_copro_periode'] ?? ''];
    $rows[] = ['📐', 'Parcelle cadastrale',     $parcelle];
    $rows[] = ['🗺️', 'Zone PLU',                $r['zone_plu'] ?? ''];
    $rows[] = ['⛰️', 'Altitude',                ($r['altitude'] ? $r['altitude'].' m' : '')];
    $manquant = 0; foreach ($rows as $x){ if (trim((string)$x[2])==='') $manquant++; }
    $csrf = function_exists('csrf_token') ? csrf_token('immeuble_enrichir') : '';

    // Détail complet par source (snapshot stocké à la création) → titres cliquables + modal lecture
    $snap = json_decode((string)($r['enrichissement_public_json'] ?? ''), true);
    $details = (is_array($snap) && !empty($snap['details']) && is_array($snap['details'])) ? $snap['details'] : [];
    ?>
    <div class="imc-card" id="imc-<?= (int)$immId ?>">
      <div class="imc-head">
        <div class="imc-title">🌐 Données publiques de l'immeuble</div>
        <div style="display:flex;align-items:center;gap:8px">
          <?php if ($lat && $lng): $gvLabel = trim((string)($fallback['label'] ?? '')); ?>
            <button type="button" class="imc-btn" style="background:linear-gradient(135deg,#243B5C,#1a2c45);color:#fff;border-color:#243B5C"
              onclick="if(window.openGeoViews)openGeoViews(<?= $h($lat) ?>,<?= $h($lng) ?>,<?= htmlspecialchars(json_encode($gvLabel ?: ($lat.', '.$lng)), ENT_QUOTES, 'UTF-8') ?>)"
              title="Plan 2D · Street View · Vue 3D / Earth">🛰️ 3 vues</button>
          <?php endif; ?>
          <?php if ($canAct): ?>
            <button type="button" class="imc-btn" id="imc-run"
              <?= ($lat && $lng) ? '' : 'disabled title="GPS de l\'immeuble manquant"' ?>>
              🔄 <?= $manquant ? 'Lancer les recherches' : 'Actualiser' ?>
            </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="imc-body" id="imc-body">
        <?php foreach ($rows as $x): $val = trim((string)$x[2]); ?>
          <div class="imc-row">
            <span class="imc-lbl"><?= $x[0] ?> <?= $h($x[1]) ?></span>
            <?php if ($val !== ''): ?>
              <b class="imc-val"><?= $h($val) ?></b>
            <?php else: ?>
              <span class="imc-miss">🔴 non récupéré</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($details): ?>
      <div class="imc-sources">
        <div class="imc-sources-h">📂 Détail des données récupérées — cliquez une source</div>
        <?php foreach ($details as $i => $d): ?>
          <button type="button" class="imc-src" data-i="<?= (int)$i ?>"><?= $h($d['icon'] ?? '🔎') ?> <?= $h($d['title'] ?? 'Source') ?> <span class="imc-src-n"><?= count($d['items'] ?? []) ?></span></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="imc-foot" id="imc-status"></div>
    </div>

    <?php if ($details): ?>
    <div class="imc-modal" id="imc-modal-<?= (int)$immId ?>">
      <div class="imc-modal-ov" data-imc-close></div>
      <div class="imc-modal-card">
        <div class="imc-modal-head"><span id="imc-modal-title"></span><button type="button" class="imc-modal-x" data-imc-close>✕</button></div>
        <div class="imc-modal-body" id="imc-modal-body"></div>
      </div>
    </div>
    <script>
    (function(){
      var DETAILS = <?= json_encode($details, JSON_UNESCAPED_UNICODE) ?>;
      var m=document.getElementById('imc-modal-<?= (int)$immId ?>');
      var card=document.getElementById('imc-<?= (int)$immId ?>');
      function esc(s){return (''+s).replace(/&/g,'&amp;').replace(/</g,'&lt;');}
      function openSrc(i){
        var d=DETAILS[i]; if(!d) return;
        document.getElementById('imc-modal-title').textContent=(d.icon||'')+' '+(d.title||'');
        document.getElementById('imc-modal-body').innerHTML=(d.items||[]).map(function(it){
          return '<div class="imc-it"><span>'+esc(it.label||'')+'</span><b>'+esc(it.value||'')+'</b></div>'; }).join('') || '<div style="color:#94a3b8;font-size:13px">Aucun détail.</div>';
        m.classList.add('open');
      }
      card.querySelectorAll('.imc-src').forEach(function(b){ b.addEventListener('click', function(){ openSrc(+b.getAttribute('data-i')); }); });
      m.querySelectorAll('[data-imc-close]').forEach(function(el){ el.addEventListener('click', function(){ m.classList.remove('open'); }); });
    })();
    </script>
    <?php endif; ?>
    <style>
      .imc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;margin:14px 0}
      .imc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
      .imc-title{font-size:14px;font-weight:800;color:#1B4A52}
      .imc-btn{background:#1B4A52;color:#fff;border:none;border-radius:9px;padding:7px 13px;font-size:12.5px;font-weight:700;cursor:pointer}
      .imc-btn:disabled{opacity:.5;cursor:default}
      .imc-row{display:flex;justify-content:space-between;gap:16px;padding:7px 0;border-top:1px solid #f1f5f9;font-size:13px}
      .imc-lbl{color:#64748b}
      .imc-val{color:#0f172a;text-align:right}
      .imc-miss{color:#B14A30;font-size:12px}
      .imc-foot{font-size:12px;color:#64748b;margin-top:8px;min-height:16px}
      .imc-sources{margin-top:12px;border-top:1px dashed #e2e8f0;padding-top:10px;display:flex;flex-wrap:wrap;gap:7px}
      .imc-sources-h{flex-basis:100%;font-size:11px;color:#8A8472;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
      .imc-src{background:#f4f6f9;border:1px solid #e2e8f0;border-radius:9px;padding:6px 10px;font-size:12px;font-weight:600;color:#1B4A52;cursor:pointer}
      .imc-src:hover{background:#e9eef3}
      .imc-src-n{background:#1B4A52;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:4px}
      .imc-modal{position:fixed;inset:0;z-index:9100;display:none;align-items:center;justify-content:center;padding:20px}
      .imc-modal.open{display:flex}
      .imc-modal-ov{position:absolute;inset:0;background:rgba(15,23,42,.55)}
      .imc-modal-card{position:relative;background:#fff;border-radius:14px;width:min(520px,100%);max-height:86vh;overflow:auto;box-shadow:0 24px 64px rgba(0,0,0,.3)}
      .imc-modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eef2f7;font-weight:800;color:#143A41;position:sticky;top:0;background:#fff}
      .imc-modal-x{background:#f1f5f9;border:none;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:14px}
      .imc-modal-body{padding:8px 16px 16px}
      .imc-it{display:flex;justify-content:space-between;gap:18px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13.5px}
      .imc-it span{color:#8A8472}
      .imc-it b{color:#143A41;text-align:right}
    </style>
    <?php if ($canAct): ?>
    <script>
    (function(){
      var btn=document.getElementById('imc-run'); if(!btn) return;
      var LAT=<?= json_encode((string)$lat) ?>, LNG=<?= json_encode((string)$lng) ?>;
      var IMM=<?= (int)$immId ?>, CSRF=<?= json_encode((string)$csrf) ?>;
      var EP={ cad:<?= json_encode($au('/api/geo_cadastre_plu.php')) ?>, copro:<?= json_encode($au('/api/registre_copro.php')) ?>,
               risk:<?= json_encode($au('/api/geo_risques.php')) ?>, alt:<?= json_encode($au('/api/geo_altitude.php')) ?>,
               save:<?= json_encode($au('/api/immeuble_enrichir_save.php')) ?> };
      var status=document.getElementById('imc-status');
      btn.addEventListener('click', function(){
        if(!LAT||!LNG) return;
        btn.disabled=true; status.textContent='⏳ Recherches publiques en cours…';
        var q='?lat='+encodeURIComponent(LAT)+'&lng='+encodeURIComponent(LNG);
        Promise.all([
          fetch(EP.cad+q).then(function(r){return r.json();}).catch(function(){return null;}),
          fetch(EP.copro+q).then(function(r){return r.json();}).catch(function(){return null;}),
          fetch(EP.risk+q).then(function(r){return r.json();}).catch(function(){return null;}),
          fetch(EP.alt+q).then(function(r){return r.json();}).catch(function(){return null;})
        ]).then(function(res){
          var cad=res[0]||{}, copro=res[1]||{}, risk=res[2]||{}, alt=res[3]||{};
          var payload={ immeuble_id:IMM, csrf:CSRF };
          if(cad.parcelle) payload.cadastre={reference:cad.parcelle.reference};
          if(cad.plu) payload.plu={type:cad.plu.type};
          if(alt && alt.altitude!=null) payload.altitude=alt.altitude;
          if(copro && copro.trouve) payload.registre={immatriculation:copro.immatriculation,construction:copro.construction,date_maj:copro.date_maj,nb_lots:copro.nb_lots};
          if(risk && risk.risques) payload.risques=risk;
          // Détail par source → pour les modals de lecture en fiche
          var det=[], ci=[];
          if(cad.parcelle){ if(cad.parcelle.section) ci.push({label:'Parcelle',value:cad.parcelle.section+' '+cad.parcelle.numero}); if(cad.parcelle.contenance) ci.push({label:'Surface parcelle',value:cad.parcelle.contenance+' m²'}); }
          if(cad.plu&&cad.plu.type) ci.push({label:'Zone PLU',value:cad.plu.type+(cad.plu.libelle?(' — '+cad.plu.libelle):'')});
          if(ci.length) det.push({icon:'📐',title:'Cadastre & PLU',items:ci});
          if(alt&&alt.altitude!=null) det.push({icon:'⛰️',title:'Altitude',items:[{label:'Altitude',value:alt.altitude+' m'+(alt.altitude>800?' (zone DPE > 800 m)':'')}]});
          if(risk&&risk.risques&&risk.risques.length) det.push({icon:'⚠️',title:'Risques ERP',items:risk.risques.map(function(x){return {label:x.label,value:x.statut||'présent'};})});
          if(copro&&copro.trouve&&copro.infos) det.push({icon:'🏛️',title:'Copropriété (registre national)',items:copro.infos});
          if(det.length) payload.details=det;
          return fetch(EP.save,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(function(r){return r.json();});
        }).then(function(s){
          status.textContent = (s&&s.ok) ? '✓ Données récupérées et enregistrées. Rechargement…' : '⚠️ Enregistrement partiel.';
          if(s&&s.ok) setTimeout(function(){ location.reload(); }, 800);
          else btn.disabled=false;
        }).catch(function(){ status.textContent='⚠️ Échec des recherches.'; btn.disabled=false; });
      });
    })();
    </script>
    <?php endif;
}
}
