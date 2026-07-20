<?php
declare(strict_types=1);

/**
 * pilotage_service_location.php — Page de répartition et pilotage des missions
 * du service Location (3 colonnes : collaborateurs / missions / non attribuées).
 *
 * Réutilise : auth MBI, multi-tenant id_societe, users, layout agency legacy.
 * Données 100 % issues de la base (aucun bouton métier codé en dur).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/pilotage.php';
require_login();

$appLayout = true;
$pageTitle = 'Service Location';
$robots    = 'noindex, nofollow';
$pdo = $GLOBALS['pdo'];

$roleId = (int)current_role_id();
$soc    = (int)(current_societe_id() ?? 0);
$canManage = in_array($roleId, [1, 2, 7], true) || (function_exists('is_super_admin') && is_super_admin());

$service = pilotage_get_service($pdo, $soc, 'location');

$collaborators = [];
$tasks = [];
$categories = [];
if ($service) {
    $collaborators = pilotage_collaborators($pdo, $soc, (int)$service['id']);
    $tasks = pilotage_tasks_for_service($pdo, $soc, (int)$service['id']);
    // catégories distinctes (ordre)
    foreach ($tasks as $t) {
        if ($t['category_id'] && !isset($categories[$t['category_id']])) {
            $categories[$t['category_id']] = ['id' => $t['category_id'], 'name' => $t['category_name'], 'order' => $t['cat_order']];
        }
    }
    uasort($categories, fn($a, $b) => ($a['order'] <=> $b['order']));
}

// KPIs
$nbTasks = count($tasks);
$nbUnassigned = 0; $nbYellow = 0; $nbRed = 0;
foreach ($tasks as $t) {
    if (!$t['has_primary']) $nbUnassigned++;
    if ($t['documentation_status'] === 'yellow') $nbYellow++;
    if ($t['documentation_status'] === 'red') $nbRed++;
}

$bootData = [
    'canManage' => $canManage,
    'csrf' => csrf_token('pilotage'),
    'collaborators' => array_map(fn($c) => [
        'id' => (int)$c['id'],
        'name' => trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')),
        'fonction' => $c['fonction'] ?? '',
        'nb_principales' => (int)$c['nb_principales'],
        'nb_soutien' => (int)$c['nb_soutien'],
    ], $collaborators),
    'tasks' => array_map(fn($t) => [
        'id' => (int)$t['id'],
        'name' => $t['name'],
        'category_id' => (int)$t['category_id'],
        'category_name' => $t['category_name'],
        'level' => $t['required_level'],
        'freq_type' => $t['frequency_type'],
        'freq_label' => pilotage_freq_label($t['frequency_type'], $t['frequency_value']),
        'freq_icon' => pilotage_freq_icon($t['frequency_type']),
        'status' => $t['documentation_status'],
        'automation' => $t['automation_level'],
        'has_procedure' => (bool)$t['has_procedure'],
        'nb_checklist' => (int)$t['nb_checklist'],
        'nb_steps' => (int)($t['nb_steps'] ?? 0),
        'nb_automations' => (int)($t['nb_automations'] ?? 0),
        'nb_instances' => (int)($t['nb_instances'] ?? 0),
        'has_primary' => (bool)$t['has_primary'],
        'poste_primary' => (function () use ($t) {
            foreach (($t['postes'] ?? []) as $p) if ((int)$p['is_primary'] === 1) return pilotage_poste_short($p['poste_code']);
            return null;
        })(),
        'assignments' => array_map(fn($a) => [
            'user_id' => (int)$a['user_id'],
            'role' => $a['assignment_role'],
            'role_label' => pilotage_role_label($a['assignment_role']),
            'is_primary' => (int)$a['is_primary'] === 1,
            'name' => trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')),
        ], $t['assignments']),
    ], array_values($tasks)),
    'categories' => array_values($categories),
];

include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<style>
:root{ --pl-loc:#84A7AB; --pl-loc-d:#5c8388; }
.pl-wrap{padding:18px 22px;max-width:1600px;margin:0 auto}
.pl-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px}
.pl-title{display:flex;align-items:center;gap:10px;font-size:1.15rem;font-weight:700;color:#243B5C}
.pl-title .pl-badge{background:var(--pl-loc);color:#fff;border-radius:8px;padding:2px 10px;font-size:.8rem}
.pl-kpis{display:flex;gap:10px;flex-wrap:wrap}
.pl-kpi{background:#fff;border:1px solid #e6ebf0;border-radius:12px;padding:8px 14px;min-width:96px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.pl-kpi b{display:block;font-size:1.25rem;color:#243B5C;line-height:1}
.pl-kpi span{font-size:.72rem;color:#64748b}
.pl-grid{display:grid;grid-template-columns:260px 1fr 320px;gap:16px;align-items:start}
.pl-col{background:#f7f9fb;border:1px solid #e6ebf0;border-radius:16px;padding:12px;min-height:200px}
.pl-col h3{font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin:2px 4px 10px}
.pl-collab{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:12px;cursor:pointer;border:1px solid transparent;margin-bottom:6px;background:#fff}
.pl-collab:hover{border-color:#d6e0e6}
.pl-collab.active{border-color:var(--pl-loc);box-shadow:0 0 0 2px rgba(132,167,171,.25)}
.pl-ava{width:34px;height:34px;border-radius:50%;background:var(--pl-loc);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0}
.pl-collab .nm{font-weight:600;color:#243B5C;font-size:.9rem;line-height:1.1}
.pl-collab .fn{font-size:.72rem;color:#64748b}
.pl-collab .cnt{margin-left:auto;text-align:right;font-size:.7rem;color:#64748b}
.pl-collab .cnt b{color:var(--pl-loc-d)}
.pl-catgrp{margin-bottom:14px}
.pl-catgrp .cat{font-size:.78rem;font-weight:700;color:var(--pl-loc-d);margin:4px 2px 8px;display:flex;align-items:center;gap:6px}
.pl-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
.pl-card{background:#fff;border:1px solid #e6ebf0;border-radius:12px;padding:11px 12px;cursor:pointer;position:relative;transition:.12s;border-left:3px solid var(--pl-loc)}
.pl-card:hover{box-shadow:0 4px 14px rgba(36,59,92,.1);transform:translateY(-1px)}
.pl-card.drag{opacity:.5}
.pl-card .cn{font-weight:600;color:#243B5C;font-size:.86rem;line-height:1.2;margin-bottom:6px;padding-right:16px}
.pl-card .meta{display:flex;flex-wrap:wrap;gap:5px;align-items:center;font-size:.7rem;color:#64748b}
.pl-pill{background:#eef3f5;border-radius:20px;padding:1px 8px;white-space:nowrap}
.pl-dot{display:inline-block;width:10px;height:10px;border-radius:50%;position:absolute;top:11px;right:11px}
.pl-role{background:var(--pl-loc);color:#fff;border-radius:20px;padding:1px 8px}
.pl-col.drop-on{outline:2px dashed var(--pl-loc);outline-offset:-4px;background:#eef5f6}
.pl-empty{color:#94a3b8;font-size:.85rem;text-align:center;padding:26px 10px}
.pl-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.pl-fbtn{border:1px solid #d6e0e6;background:#fff;border-radius:20px;padding:3px 11px;font-size:.75rem;cursor:pointer;color:#475569}
.pl-fbtn.on{background:var(--pl-loc);color:#fff;border-color:var(--pl-loc)}
.pl-search{width:100%;padding:8px 12px;border:1px solid #d6e0e6;border-radius:10px;margin-bottom:12px;font-size:.85rem}
/* modal */
.pl-modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:flex-start;justify-content:center;z-index:2000;padding:40px 16px;overflow:auto}
.pl-modal-bg.show{display:flex}
.pl-modal{background:#fff;border-radius:18px;max-width:820px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.pl-mhead{padding:18px 22px;border-bottom:1px solid #eef2f5}
.pl-mhead .t{font-size:1.05rem;font-weight:700;color:#243B5C}
.pl-mhead .s{font-size:.78rem;color:#64748b;margin-top:3px}
.pl-mtabs{display:flex;gap:4px;padding:0 16px;border-bottom:1px solid #eef2f5;flex-wrap:wrap}
.pl-mtab{padding:9px 13px;font-size:.82rem;cursor:pointer;color:#64748b;border-bottom:2px solid transparent}
.pl-mtab.on{color:var(--pl-loc-d);border-bottom-color:var(--pl-loc);font-weight:600}
.pl-mbody{padding:18px 22px;min-height:160px}
.pl-mbody h4{font-size:.85rem;color:#243B5C;margin:12px 0 6px}
.pl-mbody ul{margin:4px 0 4px 4px;padding:0;list-style:none}
.pl-mbody li{padding:5px 0;border-bottom:1px dashed #eef2f5;font-size:.86rem;color:#334155}
.pl-warn{background:#fef9ec;border:1px solid #f3e2ad;color:#92660c;padding:10px 12px;border-radius:10px;font-size:.82rem;margin-bottom:10px}
.pl-close{float:right;cursor:pointer;font-size:1.4rem;color:#94a3b8;line-height:1}
.pl-mfoot{padding:12px 22px;border-top:1px solid #eef2f5;display:flex;gap:8px;flex-wrap:wrap}
.pl-btn{border:1px solid var(--pl-loc);background:var(--pl-loc);color:#fff;border-radius:10px;padding:7px 14px;font-size:.83rem;cursor:pointer}
.pl-btn.ghost{background:#fff;color:var(--pl-loc-d)}
/* Vue rapide dense */
.pl-hbadges{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.pl-hb{border-radius:20px;padding:2px 10px;font-size:.74rem;font-weight:600}
.pl-hb.st-red{background:#fdecea;color:#c0392b}.pl-hb.st-yellow{background:#fef7e6;color:#a6791b}.pl-hb.st-green{background:#e7f6ee;color:#1e7d4f}
.pl-hb.info{background:#eef3f5;color:#48606a}
.pl-sec{margin:16px 0 6px;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;font-weight:700}
.pl-syn{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.pl-syn .c{background:#f6f8fa;border:1px solid #eef2f5;border-radius:10px;padding:8px 10px}
.pl-syn .c .k{font-size:.68rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em}
.pl-syn .c .v{font-size:.88rem;color:#243B5C;font-weight:600;margin-top:2px}
.pl-syn .c .v.muted{color:#b0b8c1;font-weight:500;font-style:italic}
.pl-org{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.pl-org .p{background:#fff;border:1px solid #e6ebf0;border-radius:10px;padding:8px 10px;border-top:3px solid #84A7AB}
.pl-org .p .r{font-size:.68rem;color:#5c8388;text-transform:uppercase;letter-spacing:.03em;font-weight:700}
.pl-org .p .n{font-size:.88rem;color:#243B5C;margin-top:3px}
.pl-org .p .n.none{color:#b0b8c1;font-style:italic}
.pl-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.pl-two ul{margin:4px 0;padding-left:16px;list-style:disc}
.pl-two li{font-size:.85rem;color:#334155;padding:2px 0;border:0}
.pl-mbi{display:flex;gap:8px;flex-wrap:wrap}
.pl-mbi a,.pl-mbi button{background:#eef5f6;color:#3d6d72;border:1px solid #d6e6e8;border-radius:9px;padding:8px 12px;font-size:.82rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.pl-mbi a:hover,.pl-mbi button:hover{background:#e0eef0}
.pl-badge-y{background:#fef7e6;color:#a6791b;border-radius:6px;padding:1px 7px;font-size:.72rem;font-weight:600}
.pl-edit{border:1px solid #d6e0e6;background:#fff;border-radius:8px;padding:4px 10px;font-size:.76rem;cursor:pointer;color:#5c8388;float:right}
.pl-field{margin-bottom:10px}
.pl-field label{display:block;font-size:.75rem;color:#64748b;margin-bottom:3px;font-weight:600}
.pl-field input,.pl-field select,.pl-field textarea{width:100%;padding:7px 9px;border:1px solid #d6e0e6;border-radius:8px;font-size:.86rem;font-family:inherit}
.pl-field textarea{min-height:60px;resize:vertical}
.pl-row{display:flex;gap:8px}.pl-row>*{flex:1}
.pl-item{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px dashed #eef2f5}
.pl-item .gr{cursor:grab;color:#cbd5e1}
.pl-item input[type=text]{flex:1;border:1px solid transparent;background:transparent;padding:4px 6px;border-radius:6px;font-size:.86rem}
.pl-item input[type=text]:focus{border-color:#d6e0e6;background:#fff}
.pl-item .del{color:#cbd5e1;cursor:pointer;border:0;background:0}.pl-item .del:hover{color:#DD4735}
.pl-add{border:1px dashed #cbd5e1;background:#fafbfc;border-radius:9px;padding:8px 12px;font-size:.82rem;cursor:pointer;color:#5c8388;width:100%;margin-top:8px}
.pl-hist{font-size:.82rem;color:#475569;padding:6px 0;border-bottom:1px dashed #eef2f5}
.pl-hist .w{color:#94a3b8}
.pl-rd-wrap{display:flex;gap:5px;flex-wrap:wrap;margin-right:auto}
.pl-rd{font-size:.7rem;border-radius:20px;padding:2px 8px;white-space:nowrap}
.pl-rd.ok{background:#e7f6ee;color:#1e7d4f}.pl-rd.no{background:#fdecea;color:#c0392b}
.pl-cl-group{font-size:.74rem;font-weight:700;color:#5c8388;text-transform:uppercase;letter-spacing:.03em;margin:12px 0 4px}
.pl-auto{border:1px solid #e6ebf0;border-radius:12px;padding:11px 13px;margin-bottom:9px;background:#fff}
.pl-auto.off{opacity:.55}
.pl-auto .when{font-size:.7rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em}
.pl-auto .line{font-size:.88rem;color:#243B5C;margin:2px 0}
.pl-auto .line b{color:#7a4b86}
.pl-auto .foot{display:flex;gap:8px;align-items:center;margin-top:7px;flex-wrap:wrap}
.pl-auto .mode{font-size:.7rem;border-radius:20px;padding:1px 8px;background:#eef3f5;color:#48606a}
.pl-auto .mode.vr{background:#fef7e6;color:#a6791b}
.pl-sw{cursor:pointer;font-size:.75rem;border:1px solid #cbd5e1;border-radius:20px;padding:2px 10px;background:#fff}
.pl-sw.on{background:#e7f6ee;color:#1e7d4f;border-color:#a7d8bf}
.pl-jn{display:flex;gap:10px;padding:9px 0;border-bottom:1px dashed #eef2f5}
.pl-jn .no{width:22px;height:22px;border-radius:50%;background:#84A7AB;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0}
.pl-jn .b{flex:1}
.pl-jn .t{font-weight:600;color:#243B5C;font-size:.86rem}
.pl-jn .st{font-size:.72rem;color:#94a3b8}
.pl-jn .hp{font-size:.78rem;color:#64748b;margin-top:2px}
@media(max-width:1100px){.pl-grid{grid-template-columns:1fr}}
@media(max-width:720px){.pl-syn,.pl-org{grid-template-columns:1fr 1fr}.pl-two{grid-template-columns:1fr}}
</style>

<div class="mbi-main pl-wrap">
  <div class="pl-head">
    <div class="pl-title">🔑 Service Location <span class="pl-badge">Pilotage</span></div>
    <div class="pl-kpis">
      <div class="pl-kpi"><b><?= $nbTasks ?></b><span>Missions</span></div>
      <div class="pl-kpi"><b><?= count($collaborators) ?></b><span>Collaborateurs</span></div>
      <div class="pl-kpi"><b><?= $nbUnassigned ?></b><span>Non attribuées</span></div>
      <div class="pl-kpi"><b><?= $nbYellow ?></b><span>À valider (jaune)</span></div>
    </div>
  </div>

<?php if (!$service): ?>
  <div class="pl-col"><div class="pl-empty">
    Le référentiel Location n'est pas encore initialisé pour cette société.<br>
    <?php if ($canManage): ?>
      👉 <a href="<?= h(app_url('/admin/pilotage_seed.php')) ?>">Lancer le seed du référentiel</a>
    <?php else: ?>
      Contactez un administrateur pour l'initialiser.
    <?php endif; ?>
  </div></div>
<?php else: ?>

  <div class="pl-grid">
    <!-- COL 1 : collaborateurs -->
    <div class="pl-col">
      <h3>Collaborateurs</h3>
      <div class="pl-collab active" data-uid="all">
        <div class="pl-ava" style="background:#243B5C">∑</div>
        <div><div class="nm">Toutes les missions</div><div class="fn">Vue service</div></div>
        <div class="cnt"><b><?= $nbTasks ?></b></div>
      </div>
      <div id="plCollabList"></div>
    </div>

    <!-- COL 2 : missions du collaborateur -->
    <div class="pl-col" id="plMissionsCol">
      <h3 id="plMissionsTitle">Toutes les missions</h3>
      <input type="text" class="pl-search" id="plSearch" placeholder="Rechercher une mission…">
      <div class="pl-filters" id="plStatusFilters">
        <button class="pl-fbtn on" data-st="all">Toutes</button>
        <button class="pl-fbtn" data-st="green">🟢 Validées</button>
        <button class="pl-fbtn" data-st="yellow">🟡 À contrôler</button>
        <button class="pl-fbtn" data-st="red">🔴 Non doc.</button>
      </div>
      <div id="plMissions"></div>
    </div>

    <!-- COL 3 : non attribuées / à compléter -->
    <div class="pl-col" id="plUnassignedCol">
      <h3>À traiter</h3>
      <div class="pl-filters" id="plUnFilters">
        <button class="pl-fbtn on" data-f="unassigned">Non attribuées</button>
        <button class="pl-fbtn" data-f="yellow">À valider</button>
        <button class="pl-fbtn" data-f="red">À documenter</button>
        <button class="pl-fbtn" data-f="all">Toutes</button>
      </div>
      <div id="plUnassigned"></div>
    </div>
  </div>
<?php endif; ?>
</div>

<!-- MODAL mission -->
<div class="pl-modal-bg" id="plModalBg">
  <div class="pl-modal">
    <div class="pl-mhead">
      <span class="pl-close" onclick="plCloseModal()">&times;</span>
      <div class="t" id="plmTitle">—</div>
      <div class="s" id="plmSub">—</div>
    </div>
    <div class="pl-mtabs" id="plmTabs"></div>
    <div class="pl-mbody" id="plmBody"><div class="pl-empty">Chargement…</div></div>
    <div class="pl-mfoot">
      <div id="plmValidate" style="display:flex;gap:8px;flex-wrap:wrap"></div>
      <a class="pl-btn ghost" id="plmGed" style="display:none" href="#">📁 Documents & GED</a>
      <button class="pl-btn ghost" style="margin-left:auto" onclick="plCloseModal()">Fermer</button>
    </div>
  </div>
</div>

<script>
const PL = <?= json_encode($bootData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const PL_L = <?= json_encode(pilotage_labels(), JSON_UNESCAPED_UNICODE) ?>;
const PL_APP = '<?= h(rtrim(app_url('/'), '/')) ?>';
// Libellé FR centralisé : aucune valeur technique ne doit atteindre l'écran.
function L(group, code, fallback){
  if(code===null||code===undefined||code==='') return fallback||'';
  const g = PL_L[group]||{};
  return g[code] || fallback || (''+code).replace(/_/g,' ');
}
const PL_ASSIGN_URL = '<?= h(app_url('/api/pilotage_assign.php')) ?>';
const PL_TASK_URL   = '<?= h(app_url('/api/pilotage_task.php')) ?>';
const PL_SAVE_URL   = '<?= h(app_url('/api/pilotage_task_save.php')) ?>';
const PL_ITEMS_URL  = '<?= h(app_url('/api/pilotage_task_items.php')) ?>';
const PL_ASGN2_URL  = '<?= h(app_url('/api/pilotage_assignment.php')) ?>';
const PL_AUTO_URL   = '<?= h(app_url('/api/pilotage_automation.php')) ?>';
const PL_POSTES = <?= json_encode(array_map(fn($c) => $c['short'], pilotage_poste_catalog()), JSON_UNESCAPED_UNICODE) ?>;
function respLabel(code){ if(!code) return ''; return PL_POSTES[code] || L('role',code,code); }
async function plPost(url, payload){
  const r = await fetch(url,{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':PL.csrf}, body:JSON.stringify(payload)});
  return r.json();
}

let plSelUid = 'all';       // collaborateur sélectionné
let plStatus = 'all';       // filtre statut colonne centrale
let plUnFilter = 'unassigned';
let plSearch = '';

function initials(name){ return (name||'?').split(' ').filter(Boolean).slice(0,2).map(s=>s[0].toUpperCase()).join(''); }
function taskById(id){ return PL.tasks.find(t=>t.id===id); }
function esc(s){ const d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

// ---- COL 1 : collaborateurs ----
function renderCollabs(){
  const el = document.getElementById('plCollabList'); if(!el) return;
  el.innerHTML = PL.collaborators.map(c=>`
    <div class="pl-collab ${plSelUid==c.id?'active':''}" data-uid="${c.id}" ondragover="plDragOver(event,this)" ondragleave="plDragLeave(this)" ondrop="plDrop(event,${c.id})">
      <div class="pl-ava">${esc(initials(c.name))}</div>
      <div><div class="nm">${esc(c.name)}</div><div class="fn">${esc(c.fonction||'')}</div></div>
      <div class="cnt"><b>${c.nb_principales}</b> princ.<br>${c.nb_soutien} soutien</div>
    </div>`).join('');
  document.querySelectorAll('.pl-collab').forEach(n=>{
    n.addEventListener('click', ()=>{ plSelUid = n.dataset.uid==='all'?'all':parseInt(n.dataset.uid); selectCollab(); });
  });
}
function selectCollab(){
  document.querySelectorAll('.pl-collab').forEach(n=>n.classList.toggle('active', String(plSelUid)===n.dataset.uid));
  const title = plSelUid==='all' ? 'Toutes les missions' : ('Missions de '+ (PL.collaborators.find(c=>c.id===plSelUid)?.name||''));
  document.getElementById('plMissionsTitle').textContent = title;
  renderMissions();
}

// ---- Carte mission ----
function cardHtml(t){
  const roleForSel = plSelUid==='all' ? null : t.assignments.find(a=>a.user_id===plSelUid);
  const roleTag = roleForSel ? `<span class="pl-role">${esc(roleForSel.role_label)}</span>` : '';
  return `<div class="pl-card" draggable="true" data-id="${t.id}" onclick="plOpen(${t.id})"
       ondragstart="plDragStart(event,${t.id})" ondragend="plDragEnd(this)">
      <span class="pl-dot" style="background:${statusColor(t.status)}" title="${statusLabel(t.status)}"></span>
      <div class="cn">${esc(t.name)}</div>
      <div class="meta">
        ${roleTag}
        <span class="pl-pill">${t.freq_icon} ${esc(t.freq_label)}</span>
        ${t.nb_steps>0?`<span class="pl-pill" title="Étapes">🔢 ${t.nb_steps}</span>`:(t.has_procedure?'<span class="pl-pill" title="Procédure">📋</span>':'')}
        ${t.nb_checklist>0?`<span class="pl-pill" title="Checklist">☑️ ${t.nb_checklist}</span>`:''}
        ${t.nb_automations>0?`<span class="pl-pill" title="Automatisations actives" style="background:#efe7f3;color:#7a4b86">⚙️ ${t.nb_automations}</span>`:''}
        ${t.nb_instances>0?`<span class="pl-pill" title="Dossiers en cours" style="background:#e7f0f6;color:#2f6d86">📂 ${t.nb_instances}</span>`:''}
        ${!t.has_primary && t.poste_primary?`<span class="pl-pill" style="background:#eef3f5;color:#5c8388" title="Poste attendu">👤 ${esc(t.poste_primary)}</span>`:''}
        ${!t.has_primary?'<span class="pl-pill" style="background:#fde8e8;color:#c0392b">Non attribuée</span>':''}
      </div>
    </div>`;
}
function statusColor(s){ return {red:'#DD4735',yellow:'#E0A82E',green:'#2FA36B'}[s]||'#9ca3af'; }
function statusLabel(s){ return {red:'Non documentée',yellow:'À contrôler',green:'Validée'}[s]||''; }

// ---- COL 2 : missions (groupées par catégorie) ----
function renderMissions(){
  let list = PL.tasks.slice();
  if(plSelUid!=='all') list = list.filter(t=>t.assignments.some(a=>a.user_id===plSelUid));
  if(plStatus!=='all') list = list.filter(t=>t.status===plStatus);
  if(plSearch) list = list.filter(t=>t.name.toLowerCase().includes(plSearch));
  const wrap = document.getElementById('plMissions');
  if(!list.length){ wrap.innerHTML = '<div class="pl-empty">Aucune mission.</div>'; return; }
  const byCat = {};
  list.forEach(t=>{ (byCat[t.category_id]=byCat[t.category_id]||[]).push(t); });
  let html='';
  PL.categories.forEach(c=>{
    const arr = byCat[c.id]; if(!arr||!arr.length) return;
    html += `<div class="pl-catgrp"><div class="cat">▸ ${esc(c.name)} <span style="color:#94a3b8;font-weight:400">(${arr.length})</span></div><div class="pl-cards">${arr.map(cardHtml).join('')}</div></div>`;
  });
  wrap.innerHTML = html || '<div class="pl-empty">Aucune mission.</div>';
}

// ---- COL 3 : à traiter ----
function renderUnassigned(){
  let list = PL.tasks.slice();
  if(plUnFilter==='unassigned') list = list.filter(t=>!t.has_primary);
  else if(plUnFilter==='yellow') list = list.filter(t=>t.status==='yellow');
  else if(plUnFilter==='red') list = list.filter(t=>t.status==='red');
  const wrap = document.getElementById('plUnassigned');
  if(!list.length){ wrap.innerHTML='<div class="pl-empty">Rien à traiter ici 👍</div>'; return; }
  wrap.innerHTML = `<div class="pl-cards" style="grid-template-columns:1fr">${list.map(cardHtml).join('')}</div>`;
}

// ---- Drag & drop (réaffectation exécutant principal) ----
let plDragId = null;
function plDragStart(e,id){ plDragId=id; e.dataTransfer.effectAllowed='move'; e.currentTarget.classList.add('drag'); }
function plDragEnd(el){ el.classList.remove('drag'); }
function plDragOver(e,el){ if(!PL.canManage) return; e.preventDefault(); el.classList.add('drop-on'); }
function plDragLeave(el){ el.classList.remove('drop-on'); }
async function plDrop(e, uid){
  e.preventDefault();
  document.querySelectorAll('.drop-on').forEach(n=>n.classList.remove('drop-on'));
  if(!PL.canManage || plDragId==null) return;
  await assign(plDragId, uid);
  plDragId=null;
}

async function assign(taskId, userId){
  try{
    const r = await fetch(PL_ASSIGN_URL, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':PL.csrf},
      body: JSON.stringify({task_id:taskId, user_id:userId})
    });
    const j = await r.json();
    if(!j.ok){ alert(j.error||'Erreur'); return; }
    // maj locale : retirer is_primary des executor, poser le nouveau
    const t = taskById(taskId); if(t){
      t.assignments = t.assignments.filter(a=>a.role!=='executor');
      if(userId!=null){
        const c = PL.collaborators.find(x=>x.id===userId);
        t.assignments.push({user_id:userId, role:'executor', role_label:'Exécutant', is_primary:true, name:c?c.name:''});
      }
      t.has_primary = userId!=null;
    }
    recomputeCounts(); renderCollabs(); selectCollab(); renderUnassigned();
  }catch(err){ alert('Erreur réseau'); }
}
function recomputeCounts(){
  PL.collaborators.forEach(c=>{ c.nb_principales=0; c.nb_soutien=0; });
  PL.tasks.forEach(t=>t.assignments.forEach(a=>{
    const c=PL.collaborators.find(x=>x.id===a.user_id); if(!c) return;
    if(a.is_primary) c.nb_principales++; else c.nb_soutien++;
  }));
}

// ---- MODAL (opérationnel) ----
const PL_TABS = [['quick','Vue rapide'],['procedure','Procédure'],['checklist','Checklist'],['legal','Juridique'],['compta','Comptabilité'],['docs','Documents & GED'],['automations','Automatisations'],['history','Historique']];
let plModalData=null, plModalTab='quick', plCurrentId=null, plQuickEdit=false, plTeamEdit=false;

async function plOpen(id){
  plCurrentId=id; plQuickEdit=false; plTeamEdit=false;
  const bg=document.getElementById('plModalBg'); bg.classList.add('show');
  document.getElementById('plmBody').innerHTML='<div class="pl-empty">Chargement…</div>';
  document.getElementById('plmTabs').innerHTML = PL_TABS.map(([k,l],i)=>`<div class="pl-mtab ${i===0?'on':''}" data-k="${k}" onclick="plTab('${k}')">${esc(l)}</div>`).join('');
  plModalTab='quick';
  await plReload();
}
async function plReload(){
  try{
    const r=await fetch(PL_TASK_URL+'?id='+plCurrentId,{credentials:'same-origin'});
    plModalData=await r.json();
    const body=document.getElementById('plmBody');
    if(!plModalData.ok){ body.innerHTML='<div class="pl-empty">'+esc(plModalData.error||'Erreur')+'</div>'; return; }
    plRenderHeader(); plRenderTab();
  }catch(e){ document.getElementById('plmBody').innerHTML='<div class="pl-empty">Erreur réseau</div>'; }
}
function plRenderHeader(){
  const t=plModalData.task, p=plModalData.perms||{};
  document.getElementById('plmTitle').textContent=t.name;
  document.getElementById('plmSub').textContent=[t.service_name,t.category_name].filter(Boolean).join(' • ');
  const badges=`
    <span class="pl-hb st-${t.documentation_status}">${esc(L('status',t.documentation_status,'—'))}</span>
    <span class="pl-hb info">${esc(t.frequency_value || L('freq',t.frequency_type))}</span>
    ${t.required_level?`<span class="pl-hb info">${esc(t.required_level)}</span>`:''}
    <span class="pl-hb info">${esc(L('auto',t.automation_level))}</span>`;
  let hb=document.getElementById('plmBadges'); if(!hb){ hb=document.createElement('div'); hb.id='plmBadges'; hb.className='pl-hbadges'; document.getElementById('plmSub').after(hb); }
  hb.innerHTML=badges;
  // contrôle préalable (readiness) + boutons validation dans le pied
  const foot=document.getElementById('plmValidate');
  if(foot){
    let h='';
    const rd=plModalData.readiness||{};
    const chips=Object.keys(rd).map(k=>`<span class="pl-rd ${rd[k]?'ok':'no'}">${rd[k]?'✓':'✗'} ${esc(k)}</span>`).join('');
    if(chips) h+=`<div class="pl-rd-wrap" title="Contrôle préalable à la validation">${chips}</div>`;
    if(p.validate){
      if(t.documentation_status!=='green') h+=`<button class="pl-btn" onclick="plValidate('validate')">✓ Valider (vert)</button>`;
      if(t.documentation_status==='green') h+=`<button class="pl-btn ghost" onclick="plValidate('reopen')">↩ Repasser en jaune</button>`;
    }
    foot.innerHTML=h;
  }
  const ged=document.getElementById('plmGed');
  if(t.ged_entity_type){ ged.style.display=''; ged.textContent='📁 Documents & GED'; ged.onclick=(e)=>{e.preventDefault();plTab('docs');}; } else ged.style.display='none';
}
function plTab(k){ plModalTab=k; plQuickEdit=false; document.querySelectorAll('.pl-mtab').forEach(n=>n.classList.toggle('on',n.dataset.k===k)); plRenderTab(); }

// liste → texte à puces
function bullets(txt){ if(!txt) return null; return String(txt).split(/[\n;•]+/).map(s=>s.trim()).filter(Boolean); }
function fmtDate(s){ if(!s) return null; const d=new Date(s.replace(' ','T')); if(isNaN(d)) return s; return d.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'}); }

function personFor(role, primaryOnly){
  const a=(plModalData.assignments||[]).find(x=>x.assignment_role===role && (!primaryOnly || parseInt(x.is_primary)===1));
  return a? (a.prenom+' '+a.nom).trim() : null;
}

function plRenderTab(){
  const d=plModalData, t=d.task, b=document.getElementById('plmBody'), p=d.perms||{};
  if(plModalTab==='quick') return plRenderQuick(b,t,d,p);
  if(plModalTab==='procedure') return plRenderProcedure(b,t,d,p);
  if(plModalTab==='checklist') return plRenderChecklist(b,t,d,p);
  if(plModalTab==='legal'){
    let h=(t.documentation_status!=='green')?'<div class="pl-warn">⚠️ Attention : contenu juridique à contrôler avant toute action engageante.</div>':'';
    h+=d.legal.length?'<ul>'+d.legal.map(l=>`<li><b>${esc(l.title)}</b>${l.legal_reference?` — ${esc(l.legal_reference)}`:''}${l.summary?`<br><span style="color:#64748b">${esc(l.summary)}</span>`:''}</li>`).join('')+'</ul>':(t.legal_notes?`<div style="white-space:pre-wrap">${esc(t.legal_notes)}</div>`:'<div class="pl-empty">Aucune règle juridique saisie.</div>');
    b.innerHTML=h; return;
  }
  if(plModalTab==='compta'){ b.innerHTML=t.accounting_notes?`<div style="white-space:pre-wrap">${esc(t.accounting_notes)}</div>`:'<div class="pl-empty">Aucune action comptable renseignée pour cette mission.</div>'; return; }
  if(plModalTab==='docs') return plRenderDocs(b,t,d);
  if(plModalTab==='automations') return plRenderAutomations(b,d,p);
  if(plModalTab==='history') return plRenderHistory(b,d);
}

// ---------- AUTOMATISATIONS ----------
function plRenderAutomations(b,d,p){
  const list=d.automations||[];
  if(!list.length){ b.innerHTML='<div class="pl-empty">Aucune automatisation définie pour cette mission.</div>'; return; }
  b.innerHTML = `<p class="pl-hp" style="color:#64748b;font-size:.82rem;margin:0 0 10px">Règles QUAND / SI / ALORS. Une action juridiquement engageante n'est jamais exécutée automatiquement (mode « Soumis à validation »).</p>`
    + list.map(a=>{
    const cond = a.condition_json ? `<div class="line"><b>SI</b> ${esc(condHuman(a.condition_json))}</div>` : '';
    const tgt = a.target_task_name ? ` → <b>${esc(a.target_task_name)}</b>` : '';
    const lastRun = a.last_run_at ? `<span class="st" style="color:#94a3b8;font-size:.72rem">Dernière exéc. : ${esc(fmtDate(a.last_run_at))} (${esc(a.last_run_status||'')})</span>` : '<span class="st" style="color:#c0392b;font-size:.72rem">Jamais testée</span>';
    return `<div class="pl-auto ${a.is_active?'':'off'}">
      <div class="when">QUAND ${esc(a.trigger_label)}${a.trigger_status?' ('+esc(a.trigger_status)+')':''}</div>
      ${cond}
      <div class="line"><b>ALORS</b> ${esc(a.action_label)}${tgt}${a.assigned_user_rule==='previous_executor'?' — attribuée à l’exécutant précédent':''}</div>
      <div class="foot">
        <span class="mode ${a.exec_mode==='validation_required'?'vr':''}">${esc(a.exec_mode_label)}</span>
        <span class="pl-pill">⏱ ${esc(a.delay_label)}</span>
        ${lastRun}
        ${p.edit?`<span style="margin-left:auto;display:flex;gap:6px">
          <button class="pl-sw ${a.is_active?'on':''}" onclick="plAutoToggle(${a.id})">${a.is_active?'Active':'Inactive'}</button>
          <button class="pl-sw" onclick="plAutoFire(${a.id})">▶ Lancer (test)</button>
        </span>`:''}
      </div>
    </div>`;
  }).join('');
}
function condHuman(json){ try{ const c=JSON.parse(json); if(c.field) return `${c.field} ${c.op||'='} ${c.value}`; return json; }catch(e){ return json; } }
async function plAutoToggle(id){ const j=await plPost(PL_AUTO_URL,{automation_id:id,op:'toggle'}); if(!j.ok){alert(j.error||'Erreur');return;} await plReload(); plSyncCard(); }
async function plAutoFire(id){
  const j=await plPost(PL_AUTO_URL,{automation_id:id,op:'fire',context:{proprietaire:'(test)',entity_type:'TEST',entity_id:0}});
  if(!j.ok){alert(j.error||'Erreur');return;}
  const r=(j.runs&&j.runs[0])||{}; alert('Règle exécutée (test) : '+(r.status||'ok')+(r.message?'\n'+r.message:''));
  await plReload();
}

// ---------- VUE RAPIDE ----------
function synCell(k,v,muted){ return `<div class="c"><div class="k">${esc(k)}</div><div class="v ${muted?'muted':''}">${esc(v)}</div></div>`; }
function plRenderQuick(b,t,d,p){
  if(plQuickEdit) return plRenderQuickEdit(b,t);
  const objectif = t.objective ? esc(t.objective) : '<span class="pl-badge-y">Objectif à compléter</span>';
  const syn1 = [
    synCell('Fréquence', t.frequency_value || L('freq',t.frequency_type,'—')),
    synCell('Niveau requis', t.required_level || 'Non précisé', !t.required_level),
    synCell('Automatisation', L('auto',t.automation_level,'—')),
    synCell('Statut documentaire', L('status',t.documentation_status,'—')),
  ].join('');
  const syn2 = [
    synCell('Durée indicative', t.estimated_duration_minutes? (t.estimated_duration_minutes+' minutes') : 'Non renseignée', !t.estimated_duration_minutes),
    synCell('Priorité', L('priority',t.priority_level,'Normale')),
    synCell('Déclencheur', t.trigger_event || 'Non défini', !t.trigger_event),
    synCell('Dernière validation', t.validated_at? fmtDate(t.validated_at) : 'Non validée', !t.validated_at),
  ].join('');
  // organisation
  const exe=personFor('executor',true), sup=personFor('supervisor',false), val=personFor('validator',false), bak=personFor('backup',false);
  const orgCell=(r,n,none)=>`<div class="p"><div class="r">${esc(r)}</div><div class="n ${n?'':'none'}">${esc(n||none)}</div></div>`;
  const org = orgCell('Exécutant principal',exe,'Non attribué')+orgCell('Superviseur',sup,'Non défini')+orgCell('Validateur',val,'Non défini')+orgCell('Remplaçant',bak,'Non défini');
  const noneAssigned = !(d.assignments||[]).length;
  // résultat / vigilance
  const res=bullets(t.expected_result), vig=bullets(t.errors_to_avoid);
  const resHtml = res? '<ul>'+res.map(x=>`<li>${esc(x)}</li>`).join('')+'</ul>' : '<div class="pl-empty" style="padding:8px;text-align:left"><span class="pl-badge-y">Résultat attendu à préciser</span></div>';
  const vigHtml = vig? '<ul>'+vig.map(x=>`<li>${esc(x)}</li>`).join('')+'</ul>' : '<div class="pl-empty" style="padding:8px;text-align:left"><span class="pl-badge-y">Points de vigilance à préciser</span></div>';

  b.innerHTML = `
    ${p.edit?`<button class="pl-edit" onclick="plQuickEdit=true;plRenderTab()">✎ Modifier la synthèse</button>`:''}
    <div class="pl-sec">Objectif</div>
    <div>${objectif}</div>

    <div class="pl-sec">Synthèse</div>
    <div class="pl-syn">${syn1}</div>
    <div class="pl-syn" style="margin-top:8px">${syn2}</div>

    <div class="pl-sec">Organisation
      ${p.assign?`<button class="pl-edit" onclick="plTeamEdit=!plTeamEdit;plRenderTab()">${plTeamEdit?'Fermer':'Gérer l’équipe'}</button>`:''}
    </div>
    ${noneAssigned && !plTeamEdit ? `<div class="pl-empty" style="text-align:left;padding:10px 0">Mission non attribuée. ${p.assign?`<button class="pl-btn" style="margin-left:8px" onclick="plTeamEdit=true;plRenderTab()">Attribuer la mission</button>`:''}</div>` : `<div class="pl-org">${org}</div>`}
    ${plTeamEdit? plTeamEditor(d) : ''}

    ${entryHtml(t)}

    <div class="pl-sec">Détails</div>
    <div class="pl-two">
      <div><div class="pl-sec" style="margin-top:0">Résultat attendu</div>${resHtml}</div>
      <div><div class="pl-sec" style="margin-top:0">Points de vigilance</div>${vigHtml}</div>
    </div>

    <div class="pl-sec">Travailler dans MBI</div>
    ${mbiJourney(t,d)}
  `;
}
function entryHtml(t){
  const c=bullets(t.entry_conditions);
  if(!c) return '';
  return `<div class="pl-sec">Conditions d’entrée</div><div class="pl-two"><div><ul>${c.map(x=>`<li>${esc(x)}</li>`).join('')}</ul></div><div></div></div>`;
}
// Parcours MBI pas à pas (routes réelles) ou boutons génériques
function mbiJourney(t,d){
  if(t.mbi_journey && t.mbi_journey.length){
    return '<div>'+t.mbi_journey.map((s,i)=>`
      <div class="pl-jn">
        <div class="no">${i+1}</div>
        <div class="b">
          <div class="t">${esc((s.title||'').replace(/^\d+\.\s*/,''))} ${s.status_hint?`<span class="st">— ${esc(s.status_hint)}</span>`:''}</div>
          ${s.help?`<div class="hp">${esc(s.help)}</div>`:''}
          <div class="pl-mbi" style="margin-top:6px">
            ${s.route?`<a href="${PL_APP}/${esc(s.route)}">Ouvrir</a>`:''}
            ${s.open && s.open!==s.route?`<a href="${PL_APP}/${esc(s.open)}">Liste</a>`:''}
          </div>
        </div>
      </div>`).join('')+'</div>';
  }
  return `<div class="pl-mbi">${mbiButtons(t,d)}</div>`;
}
function plRenderQuickEdit(b,t){
  b.innerHTML=`
    <div class="pl-field"><label>Objectif</label><textarea id="qeObj">${esc(t.objective||'')}</textarea></div>
    <div class="pl-row">
      <div class="pl-field"><label>Durée indicative (minutes)</label><input id="qeDur" type="number" value="${t.estimated_duration_minutes||''}"></div>
      <div class="pl-field"><label>Priorité</label><select id="qePrio">
        ${['low','normal','high','critical'].map(v=>`<option value="${v}" ${t.priority_level===v?'selected':''}>${esc(L('priority',v))}</option>`).join('')}
      </select></div>
    </div>
    <div class="pl-field"><label>Événement déclencheur</label><input id="qeTrig" type="text" value="${esc(t.trigger_event||'')}"></div>
    <div class="pl-field"><label>Résultat attendu (un élément par ligne)</label><textarea id="qeRes">${esc(t.expected_result||'')}</textarea></div>
    <div class="pl-field"><label>Points de vigilance (un élément par ligne)</label><textarea id="qeVig">${esc(t.errors_to_avoid||'')}</textarea></div>
    <div style="display:flex;gap:8px;margin-top:6px">
      <button class="pl-btn" onclick="plSaveQuick()">Enregistrer</button>
      <button class="pl-btn ghost" onclick="plQuickEdit=false;plRenderTab()">Annuler</button>
    </div>
    <p style="color:#94a3b8;font-size:.78rem;margin-top:8px">Modifier une mission validée la repasse automatiquement « À contrôler » et conserve la version précédente.</p>`;
}
async function plSaveQuick(){
  const fields={
    objective:document.getElementById('qeObj').value,
    estimated_duration_minutes:document.getElementById('qeDur').value||null,
    priority_level:document.getElementById('qePrio').value,
    trigger_event:document.getElementById('qeTrig').value,
    expected_result:document.getElementById('qeRes').value,
    errors_to_avoid:document.getElementById('qeVig').value,
  };
  const j=await plPost(PL_SAVE_URL,{task_id:plCurrentId,op:'save_fields',fields});
  if(!j.ok){ alert(j.error||'Erreur'); return; }
  plQuickEdit=false; await plReload(); plSyncCard();
}

// ---------- ORGANISATION : éditeur d'équipe ----------
function userOptions(sel){ return `<option value="0">— Non défini —</option>`+ (plModalData.users||[]).map(u=>`<option value="${u.id}" ${sel==u.id?'selected':''}>${esc(u.name)}${u.fonction?' — '+esc(u.fonction):''}</option>`).join(''); }
function currentRoleUser(role,primaryOnly){ const a=(plModalData.assignments||[]).find(x=>x.assignment_role===role && (!primaryOnly||parseInt(x.is_primary)===1)); return a?a.user_id:0; }
function plTeamEditor(d){
  return `<div style="background:#f7f9fb;border:1px solid #e6ebf0;border-radius:10px;padding:12px;margin-top:8px">
    <div class="pl-field"><label>Exécutant principal</label><select onchange="plSetPrimary(this.value)">${userOptions(currentRoleUser('executor',true))}</select></div>
    <div class="pl-field"><label>Superviseur</label><select onchange="plSwapRole('supervisor',this.value)">${userOptions(currentRoleUser('supervisor',false))}</select></div>
    <div class="pl-field"><label>Validateur</label><select onchange="plSwapRole('validator',this.value)">${userOptions(currentRoleUser('validator',false))}</select></div>
    <div class="pl-field"><label>Remplaçant</label><select onchange="plSwapRole('backup',this.value)">${userOptions(currentRoleUser('backup',false))}</select></div>
  </div>`;
}
async function plSetPrimary(uid){
  uid=parseInt(uid)||null;
  const j=await plPost(PL_ASSIGN_URL,{task_id:plCurrentId,user_id:uid});
  if(!j.ok){ alert(j.error||'Erreur'); return; }
  await plReload(); plSyncCard();
}
async function plSwapRole(role,uid){
  uid=parseInt(uid)||0;
  const old=currentRoleUser(role,false);
  if(old && old!=uid){ await plPost(PL_ASGN2_URL,{task_id:plCurrentId,user_id:old,op:'remove',role}); }
  if(uid){ const j=await plPost(PL_ASGN2_URL,{task_id:plCurrentId,user_id:uid,op:'add',role}); if(!j.ok){ alert(j.error||'Erreur'); return; } }
  await plReload(); plSyncCard();
}

// ---------- PROCÉDURE ----------
function plRenderProcedure(b,t,d,p){
  let h='';
  if(p.edit){ h+=`<div class="pl-field"><label>Procédure (texte)</label><textarea id="procTxt" style="min-height:120px">${esc(t.procedure_text||'')}</textarea>
      <button class="pl-btn" style="margin-top:6px" onclick="plSaveProc()">Enregistrer la procédure</button></div>`; }
  else h+= t.procedure_text?`<div style="white-space:pre-wrap">${esc(t.procedure_text)}</div>`:'<div class="pl-empty">Procédure à documenter.</div>';
  h+='<div class="pl-sec">Étapes</div><div id="stepList">';
  h+= d.steps.length? d.steps.map(s=>plStepRow(s,p)).join('') : '<div class="pl-empty" style="text-align:left;padding:8px 0">Aucune étape.</div>';
  h+='</div>';
  if(p.edit) h+=`<button class="pl-add" onclick="plAddStep()">+ Ajouter une étape</button>`;
  b.innerHTML=h;
}
function plStepRow(s,p){
  return `<div style="padding:8px 0;border-bottom:1px dashed #eef2f5">
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-weight:600;color:#5c8388">${s.step_number}.</span>
      <span style="flex:1;font-weight:600;color:#243B5C">${esc(s.title)}</span>
      ${s.responsible_role?`<span class="pl-pill">${esc(respLabel(s.responsible_role))}</span>`:''}
      ${parseInt(s.requires_validation)?'<span class="pl-pill" style="background:#fef7e6;color:#a6791b">validation requise</span>':''}
      ${p.edit?`<button class="del" title="Supprimer" onclick="plDelStep(${s.id||0},'${esc(s.title)}')">🗑</button>`:''}
    </div>
    ${s.description?`<div style="font-size:.83rem;color:#475569;margin:3px 0 0 22px">${esc(s.description)}</div>`:''}
    ${s.expected_result?`<div style="font-size:.78rem;color:#5c8388;margin:2px 0 0 22px">→ ${esc(s.expected_result)}</div>`:''}
    ${s.automation_note?`<div style="font-size:.76rem;color:#7a4b86;margin:2px 0 0 22px">⚙️ ${esc(s.automation_note)}</div>`:''}
  </div>`;
}
async function plSaveProc(){ const j=await plPost(PL_SAVE_URL,{task_id:plCurrentId,op:'save_fields',fields:{procedure_text:document.getElementById('procTxt').value}}); if(!j.ok){alert(j.error||'Erreur');return;} await plReload(); plSyncCard(); }
async function plAddStep(){ const title=prompt('Titre de l’étape :'); if(!title) return; const j=await plPost(PL_ITEMS_URL,{task_id:plCurrentId,kind:'step',op:'add',title}); if(!j.ok){alert(j.error||'Erreur');return;} await plReload(); }
async function plDelStep(id,title){ if(!id||!confirm('Supprimer l’étape « '+title+' » ?')) return; const j=await plPost(PL_ITEMS_URL,{task_id:plCurrentId,kind:'step',op:'delete',id}); if(!j.ok){alert(j.error||'Erreur');return;} await plReload(); }

// ---------- CHECKLIST ----------
function plRenderChecklist(b,t,d,p){
  if(!d.checklist.length){ b.innerHTML='<div class="pl-empty">Aucun élément de contrôle.</div>'+(p.edit?`<button class="pl-add" onclick="plAddCheck()">+ Ajouter un élément</button>`:''); return; }
  // regroupement par group_label
  const groups={}; const order=[];
  d.checklist.forEach(c=>{ const g=c.group_label||''; if(!(g in groups)){groups[g]=[];order.push(g);} groups[g].push(c); });
  let h='';
  order.forEach(g=>{
    if(g) h+=`<div class="pl-cl-group">${esc(g)}</div>`;
    h+=groups[g].map(c=>`<div class="pl-item">
        <span>☐</span>
        ${p.edit?`<input type="text" value="${esc(c.label)}" onchange="plUpdCheck(${c.id||0},this.value)">`:`<span style="flex:1">${esc(c.label)}</span>`}
        ${c.auto_source?'<span class="pl-pill" title="Coché auto depuis MBI">auto</span>':''}
        ${parseInt(c.is_mandatory)?'':'<span class="pl-pill">facultatif</span>'}
        ${p.edit?`<button class="del" onclick="plDelCheck(${c.id||0})">🗑</button>`:''}
      </div>`).join('');
  });
  if(p.edit) h+=`<button class="pl-add" onclick="plAddCheck()">+ Ajouter un élément</button>`;
  b.innerHTML=h;
}
async function plAddCheck(){ const label=prompt('Libellé du point de contrôle :'); if(!label) return; const j=await plPost(PL_ITEMS_URL,{task_id:plCurrentId,kind:'checklist',op:'add',label}); if(!j.ok){alert(j.error||'Erreur');return;} await plReload(); plSyncCard(); }
async function plUpdCheck(id,label){ if(!id) return; await plPost(PL_ITEMS_URL,{task_id:plCurrentId,kind:'checklist',op:'update',id,fields:{label}}); }
async function plDelCheck(id){ if(!id||!confirm('Supprimer cet élément ?')) return; const j=await plPost(PL_ITEMS_URL,{task_id:plCurrentId,kind:'checklist',op:'delete',id}); if(!j.ok){alert(j.error||'Erreur');return;} await plReload(); plSyncCard(); }

// ---------- DOCUMENTS & GED ----------
function plRenderDocs(b,t,d){
  let h='';
  if(t.ged_entity_type){
    h+=`<div class="pl-sec" style="margin-top:0">Rattachement GED</div>
      <div class="pl-syn"><div class="c"><div class="k">Entité de destination</div><div class="v">${esc(t.ged_entity_type)}</div></div>
      ${t.default_doc_template?`<div class="c"><div class="k">Modèle de pièces</div><div class="v">${esc(t.default_doc_template)}</div></div>`:''}</div>`;
  }
  const docRes=d.resources.filter(r=>r.resource_type==='ged_document'||r.resource_type==='document_template');
  if(docRes.length){ h+='<div class="pl-sec">Documents & modèles</div><ul>'+docRes.map(r=>`<li>${esc(r.title)}</li>`).join('')+'</ul>'; }
  h+=`<div class="pl-sec">Actions documentaires</div><div class="pl-mbi">
      <button onclick="alert('Le lien sécurisé de chargement se déclenche depuis une action réelle (dossier candidat). Disponible à la tranche GED.')">📤 Envoyer un lien de chargement</button>
    </div>
    <p style="color:#94a3b8;font-size:.8rem;margin-top:8px">Les documents concrets et l'envoi de lien sécurisé s'attachent à une <b>action réelle</b> (un dossier), pas au modèle de mission. Réutilise la « Demande de document » existante — aucune GED parallèle.</p>`;
  b.innerHTML=h||'<div class="pl-empty">Aucun document lié.</div>';
}

// ---------- HISTORIQUE ----------
const HIST_LABELS={reassign:'Réaffectation exécutant',unassign:'Retrait exécutant',assign_add:'Ajout d’un rôle',assign_remove:'Retrait d’un rôle',edit:'Modification',validate:'Validation (vert)',reopen:'Retour en jaune',reopen_yellow:'Retour en jaune (modif.)',add_checklist:'Ajout checklist',update_checklist:'Modif checklist',delete_checklist:'Suppression checklist',duplicate_checklist:'Duplication checklist',reorder_checklist:'Réordonnancement',add_step:'Ajout étape',update_step:'Modif étape',delete_step:'Suppression étape',reorder_step:'Réordonnancement étapes'};
function plRenderHistory(b,d){
  let h='';
  if((d.versions||[]).length){ h+='<div class="pl-sec" style="margin-top:0">Versions conservées</div>'+d.versions.map(v=>`<div class="pl-hist">Version ${v.version_number} — ${esc(L('status',v.documentation_status,v.documentation_status))} <span class="w">• ${esc(fmtDate(v.created_at)||'')}${v.author?' • '+esc(v.author):''}</span></div>`).join(''); }
  h+='<div class="pl-sec">Journal</div>';
  h+= (d.history||[]).length? d.history.map(x=>`<div class="pl-hist"><b>${esc(HIST_LABELS[x.action]||x.action)}</b>${x.field?' — '+esc(x.field):''} <span class="w">• ${esc(fmtDate(x.created_at)||'')}${x.author?' • '+esc(x.author):''}</span>${x.reason?`<br><span class="w">${esc(x.reason)}</span>`:''}</div>`).join('') : '<div class="pl-empty" style="text-align:left;padding:8px 0">Aucun évènement.</div>';
  b.innerHTML=h;
}

// ---------- Validation ----------
async function plValidate(op){
  if(op==='validate' && !confirm('Valider définitivement cette mission (passage au vert) ?')) return;
  const j=await plPost(PL_SAVE_URL,{task_id:plCurrentId,op});
  if(!j.ok){ alert(j.error||'Erreur'); return; }
  await plReload(); plSyncCard();
}

// ---------- Boutons MBI contextuels (ouvrent des pages existantes) ----------
function mbiButtons(t,d){
  const cat=(t.category_name||'').toLowerCase();
  const btn=(label,page)=>`<a href="${PL_APP}/${page}">${esc(label)}</a>`;
  let out=[];
  if(/propri|portefeuille|communication propri/.test(cat)) out.push(btn('👥 Ouvrir les propriétaires','agency_proprietaires.php'));
  if(/candidat|dossier|solvab|validation du candidat|communication candidat/.test(cat)) out.push(btn('🔑 Ouvrir les locataires','agency_locataires.php'));
  if(/bien|entrée|commercialisation|diagnostic|visite/.test(cat)) out.push(btn('🏘️ Voir les biens','agency_biens.php'));
  if(/diffusion|annonce/.test(cat)) out.push(btn('📡 Ouvrir la diffusion','agency_dashboard_diffusion.php'));
  if(t.ged_entity_type==='BAIL' || /bail|signature|encaiss|état des lieux|remise/.test(cat)) out.push(btn('📄 Ouvrir les baux','bien_baux_liste.php'));
  // ressources page_route explicites
  (d.resources||[]).filter(r=>r.page_route).forEach(r=>out.push(`<a href="${PL_APP}/${esc(r.page_route)}">${esc(r.title)}</a>`));
  out.push(`<button onclick="plTab('history')">🕑 Voir l’historique</button>`);
  return out.length? out.join('') : '<span class="pl-empty" style="padding:6px;text-align:left">Aucun lien MBI configuré.</span>';
}

// Rafraîchit la carte de la page après une modif dans le modal (statut/affectation/checklist)
function plSyncCard(){
  if(!plModalData||!plModalData.ok) return;
  const t=plModalData.task, card=PL.tasks.find(x=>x.id===t.id); if(!card) return;
  card.status=t.documentation_status;
  card.nb_checklist=(plModalData.checklist||[]).length;
  card.has_procedure=!!(t.procedure_text&&t.procedure_text.trim());
  card.assignments=(plModalData.assignments||[]).map(a=>({user_id:parseInt(a.user_id),role:a.assignment_role,role_label:L('role',a.assignment_role,a.assignment_role),is_primary:parseInt(a.is_primary)===1,name:(a.prenom+' '+a.nom).trim()}));
  card.has_primary=card.assignments.some(a=>a.is_primary);
  recomputeCounts(); renderCollabs(); selectCollab(); renderUnassigned();
}

function plCloseModal(){ document.getElementById('plModalBg').classList.remove('show'); }
document.getElementById('plModalBg')?.addEventListener('click',e=>{ if(e.target.id==='plModalBg') plCloseModal(); });

// ---- Filtres / recherche ----
document.getElementById('plStatusFilters')?.addEventListener('click',e=>{
  const b=e.target.closest('.pl-fbtn'); if(!b) return;
  document.querySelectorAll('#plStatusFilters .pl-fbtn').forEach(n=>n.classList.remove('on')); b.classList.add('on');
  plStatus=b.dataset.st; renderMissions();
});
document.getElementById('plUnFilters')?.addEventListener('click',e=>{
  const b=e.target.closest('.pl-fbtn'); if(!b) return;
  document.querySelectorAll('#plUnFilters .pl-fbtn').forEach(n=>n.classList.remove('on')); b.classList.add('on');
  plUnFilter=b.dataset.f; renderUnassigned();
});
document.getElementById('plSearch')?.addEventListener('input',e=>{ plSearch=e.target.value.toLowerCase().trim(); renderMissions(); });

// ---- Init ----
if(PL.collaborators!==undefined && document.getElementById('plMissions')){
  renderCollabs(); renderMissions(); renderUnassigned();
}
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
