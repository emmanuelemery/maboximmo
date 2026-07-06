<?php
/**
 * bailleur_dusart_concordance.php — Rapprochement MANUEL des lots d'un scénario
 * (placeholders SCN-XXX) avec les vrais biens : recherche + validation de concordance.
 *
 * Chaque lot Dusart affiche son adresse + montant + une SUGGESTION auto (par adresse).
 * On cherche/confirme le vrai bien, on Valide → la valeur passe sur le vrai bien.
 *
 * ?scenario=dusart. Réservé super-admin. Module bailleur (sidebar bailleur).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin.'); }
function dc_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$scenario = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['scenario'] ?? 'dusart'))) ?: 'dusart';

// ── Lots placeholders encore à rapprocher (is_courant=1) ──
$stP = $pdo->prepare("SELECT b.id, b.reference_bien, b.adresse_1, b.ville, b.id_proprietaire, bp.montant
                      FROM bien_prix bp JOIN biens b ON b.id=bp.id_bien
                      WHERE bp.scenario_code=? AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.montant>0
                        AND b.reference_bien LIKE 'SCN-%'
                      ORDER BY b.reference_bien");
$stP->execute([$scenario]);
$lots = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── Suggestion auto par adresse (même logique que le diagnostic) ──
function dc_nrm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','û'=>'u','ü'=>'u','œ'=>'oe']);
    $s = preg_replace('/\b(avenue|av|rue|r|boulevard|bd|cours|chemin|ch|place|pl|impasse|imp|allee|allée|route|rte|quai|bis|ter)\b/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
function dc_parts(string $adr, string $ville): array {
    $a = dc_nrm($adr); $v = dc_nrm($ville);
    if ($v !== '') foreach (explode(' ', $v) as $w) if (strlen($w) >= 3) $a = trim(preg_replace('/\b'.preg_quote($w,'/').'\b/', ' ', $a));
    $a = trim(preg_replace('/\s+/', ' ', $a));
    $toks = $a === '' ? [] : explode(' ', $a);
    $num=''; $w=[]; foreach ($toks as $t) { if ($num==='' && preg_match('/^\d+$/',$t)) $num=$t; elseif (strlen($t)>=2) $w[]=$t; }
    return [$num, array_values(array_unique($w))];
}
$cands = $pdo->query("SELECT b.id, b.reference_bien, b.id_proprietaire,
                             COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) adr, COALESCE(NULLIF(b.ville,''), i.ville) ville
                      FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
                      WHERE (b.reference_bien IS NULL OR b.reference_bien NOT LIKE 'SCN-%')
                        AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
                        AND COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($cands as &$c) { [$c['_num'],$c['_w']] = dc_parts((string)$c['adr'], (string)$c['ville']); } unset($c);
$suggest = function(array $lot) use ($cands): ?array {
    [$pnum,$pw] = dc_parts((string)$lot['adresse_1'], (string)$lot['ville']);
    if ($pnum==='' || !$pw) return null;
    $hits=[]; foreach ($cands as $c) { if ($c['_num']!==$pnum || !$c['_w']) continue;
        $inter=array_intersect($pw,$c['_w']); if ($inter && (count($inter)===count($pw)||count($inter)===count($c['_w']))) $hits[]=$c; }
    $samep = array_filter($hits, fn($c)=>(int)$c['id_proprietaire']===(int)$lot['id_proprietaire']);
    $use = $samep ?: $hits;
    return count($use)===1 ? array_values($use)[0] : null;
};

$nDone = (int)$pdo->query("SELECT COUNT(*) FROM bien_prix WHERE scenario_code=".$pdo->quote($scenario)."
                          AND type_valeur='prix_vente' AND source='concordance' AND is_courant=1")->fetchColumn();

$fmt = fn($v)=>number_format((float)$v,0,',',' ');
$pageTitle='Concordance scénario '.strtoupper($scenario);
$pageSubtitle='Ma Box Bailleur · Rapprochement des lots '.strtoupper($scenario).' avec les vrais biens';
$layoutSidebar='sidebar_bailleur_module'; $current_page='bailleur_patrimoine_actif';
$extraCss='<style>
.dc-wrap{padding:16px 20px 80px;}
.dc-head{color:#243B5C;margin:0 0 4px;} .dc-sub{color:#64748b;font-size:13px;margin:0 0 16px;}
table.dc{border-collapse:collapse;width:100%;font-size:13px;background:#fff;}
table.dc th,table.dc td{padding:9px 11px;border-bottom:1px solid #eef2f7;vertical-align:top;}
table.dc thead th{background:#243B5C;color:#fff;text-align:left;font-size:11.5px;position:sticky;top:0;z-index:5;}
.dc-lot{font-weight:700;color:#243B5C;} .dc-adr{color:#64748b;font-size:12px;}
.dc-mont{text-align:right;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap;}
.dc-search{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;font-size:13px;}
.dc-results{position:absolute;z-index:30;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:260px;overflow:auto;min-width:340px;}
.dc-results div{padding:8px 11px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:12.5px;}
.dc-results div:hover{background:#eff6ff;}
.dc-chosen{background:#e7f6ee;border:1px solid #bbe7cc;border-radius:8px;padding:6px 10px;font-size:12.5px;color:#166534;font-weight:600;}
.dc-sugg{background:#fff7ed;border:1px dashed #fdba74;border-radius:8px;padding:6px 10px;font-size:12px;color:#9a3412;cursor:pointer;}
.dc-btn{border:none;background:#0e6b75;color:#fff;border-radius:8px;padding:7px 14px;font-weight:700;cursor:pointer;font-size:12.5px;}
.dc-btn:disabled{background:#cbd5e1;cursor:not-allowed;}
.dc-ok{color:#166534;font-weight:700;} .dc-none{color:#94a3b8;}
tr.done{opacity:.55;}
</style>';
require_once __DIR__ . '/inc/agency_layout_top.php';
?>
<div class="dc-wrap">
  <h2 class="dc-head">🔗 Concordance — scénario <?= dc_h(strtoupper($scenario)) ?></h2>
  <p class="dc-sub"><?= count($lots) ?> lot(s) à rapprocher · <?= $nDone ?> déjà rapproché(s). Cherche le vrai bien, valide → la valeur passe sur ce bien (et quitte le lot placeholder).</p>

  <?php if (!$lots): ?>
    <p class="dc-ok">✅ Tous les lots « <?= dc_h($scenario) ?> » ont été rapprochés (ou aucun lot placeholder).</p>
  <?php else: ?>
  <table class="dc">
    <thead><tr><th>Lot <?= dc_h(strtoupper($scenario)) ?></th><th class="dc-mont">Valeur</th><th style="width:44%">Bien réel concordant</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($lots as $lot): $sg = $suggest($lot); ?>
        <tr id="row-<?= (int)$lot['id'] ?>" data-ph="<?= (int)$lot['id'] ?>">
          <td>
            <div class="dc-lot"><?= dc_h($lot['reference_bien']) ?></div>
            <div class="dc-adr"><?= dc_h(trim((string)$lot['adresse_1'].' '.$lot['ville'])) ?></div>
          </td>
          <td class="dc-mont"><?= $fmt($lot['montant']) ?> €</td>
          <td style="position:relative;">
            <input type="text" class="dc-search" placeholder="🔎 réf, adresse, locataire…" autocomplete="off"
                   oninput="dcSearch(this, <?= (int)$lot['id'] ?>)">
            <div class="dc-results" id="res-<?= (int)$lot['id'] ?>" style="display:none;"></div>
            <div id="chosen-<?= (int)$lot['id'] ?>" style="margin-top:6px;">
              <?php if ($sg): ?>
                <div class="dc-sugg" onclick="dcPick(<?= (int)$lot['id'] ?>, <?= (int)$sg['id'] ?>, '<?= dc_h(($sg['reference_bien'] ?: ('#'.$sg['id'])).' · '.$sg['adr']) ?>')">
                  💡 Suggéré : <?= dc_h(($sg['reference_bien'] ?: ('#'.$sg['id'])).' · '.$sg['adr']) ?> — clique pour choisir
                </div>
              <?php endif; ?>
            </div>
          </td>
          <td><button type="button" class="dc-btn" id="btn-<?= (int)$lot['id'] ?>" disabled onclick="dcValider(<?= (int)$lot['id'] ?>)">✓ Valider</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<script>
const DC_SCEN = <?= json_encode($scenario) ?>;
const dcSel = {}; // ph_id -> target_id
let dcTimer=null;
function dcSearch(inp, ph){
  clearTimeout(dcTimer);
  const q=inp.value.trim(); const box=document.getElementById('res-'+ph);
  if(q.length<2){ box.style.display='none'; return; }
  dcTimer=setTimeout(async ()=>{
    try{
      const r=await fetch('api/fluxbox_entity_search.php?types=bien&q='+encodeURIComponent(q),{credentials:'same-origin'});
      const d=await r.json(); const res=(d.results||[]).filter(x=>x.entity_type==='bien');
      if(!res.length){ box.innerHTML='<div class="dc-none">aucun bien</div>'; box.style.display='block'; return; }
      box.innerHTML=res.map(x=>`<div onclick="dcPick(${ph},${x.id},'${(String((x.label||('#'+x.id))+' · '+(x.repere1||''))).replace(/'/g,"\\'").replace(/</g,'&lt;')}')"><b>${x.label||('#'+x.id)}</b><br><span style="color:#64748b">${x.repere1||''} ${x.repere2||''}</span></div>`).join('');
      box.style.display='block';
    }catch(e){ box.style.display='none'; }
  },220);
}
function dcPick(ph, target, lbl){
  dcSel[ph]=target;
  document.getElementById('res-'+ph).style.display='none';
  document.getElementById('chosen-'+ph).innerHTML='<div class="dc-chosen">✓ '+lbl+'</div>';
  document.getElementById('btn-'+ph).disabled=false;
}
async function dcValider(ph){
  const target=dcSel[ph]; if(!target) return;
  const btn=document.getElementById('btn-'+ph); btn.disabled=true; btn.textContent='…';
  try{
    const fd=new FormData(); fd.append('placeholder_id',ph); fd.append('target_id',target); fd.append('scenario',DC_SCEN);
    const r=await fetch('bailleur_dusart_concordance_save.php',{method:'POST',body:fd,credentials:'same-origin'});
    const d=await r.json();
    if(d.ok){ const row=document.getElementById('row-'+ph); row.classList.add('done'); btn.textContent='✅ '+d.target_ref; btn.style.background='#166534'; }
    else { btn.disabled=false; btn.textContent='✓ Valider'; alert('❌ '+(d.error||'échec')); }
  }catch(e){ btn.disabled=false; btn.textContent='✓ Valider'; alert('❌ '+e.message); }
}
document.addEventListener('click', e=>{ if(!e.target.closest('td')) document.querySelectorAll('.dc-results').forEach(b=>b.style.display='none'); });
</script>
<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
