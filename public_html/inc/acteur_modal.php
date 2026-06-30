<?php
/**
 * inc/acteur_modal.php — Composant RÉUTILISABLE « Ajouter un acteur ».
 *
 * Reprend exactement l'UX du dossier de vente (transaction_dossier.php) :
 *   1) chips de rôle  2) recherche d'un tiers existant OU création (tiers_selector)
 *   3) « Ajouter au dossier ».
 *
 * À utiliser dans tout module qui rattache des tiers à une entité (créanciers, etc.).
 * Pré-requis : tiers_selector déjà disponible (appeler tiers_selector_assets() dans la page).
 *
 * Usage :
 *   require_once __DIR__.'/inc/acteur_modal.php';
 *   echo acteur_modal_button('cre_acteur');          // le bouton "+"
 *   acteur_modal_render([
 *     'id'         => 'cre_acteur',
 *     'roles'      => ['avocat'=>'Avocat', 'commissaire_justice'=>'Commissaire de justice', ...],
 *     'api_add'    => app_url('/api/creancier_contact_add.php'),
 *     'entity'     => ['id_dossier' => $idDossier],   // champs POST fixes
 *     'role_field' => 'role_dossier',                 // nom du champ POST du rôle
 *     'tiers_field'=> 'id_tiers',                     // nom du champ POST du tiers
 *     'csrf'       => $csrfContact,
 *   ]);
 */
declare(strict_types=1);

if (!function_exists('acteur_modal_button')) {
    function acteur_modal_button(string $id, string $title = 'Ajouter un acteur'): string {
        $jid = preg_replace('/[^a-zA-Z0-9_]/', '_', $id);
        return '<button type="button" class="am-add-btn" title="' . htmlspecialchars($title, ENT_QUOTES) . '"'
             . ' onclick="acteurModalOpen_' . $jid . '()">+</button>';
    }
}

if (!function_exists('acteur_modal_render')) {
    function acteur_modal_render(array $cfg): void {
        static $cssDone = false;
        $id        = (string)($cfg['id'] ?? 'acteur');
        $jid       = preg_replace('/[^a-zA-Z0-9_]/', '_', $id);
        $title     = (string)($cfg['title'] ?? '➕ Ajouter un acteur');
        $sub       = (string)($cfg['sub'] ?? 'Choisissez un rôle, puis recherchez un tiers existant ou créez-en un nouveau (zéro double saisie).');
        $roleLabel = (string)($cfg['role_label'] ?? 'Rôle');
        $roles     = (array)($cfg['roles'] ?? []);
        $apiAdd    = (string)($cfg['api_add'] ?? '');
        $entity    = (array)($cfg['entity'] ?? []);
        $roleField = (string)($cfg['role_field'] ?? 'role_dossier');
        $tiersField= (string)($cfg['tiers_field'] ?? 'id_tiers');
        $csrf      = (string)($cfg['csrf'] ?? '');
        $tsId      = $id . '_tiers';
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        if (!$cssDone): $cssDone = true; ?>
<style>
.am-add-btn{border:none;background:#243B5C;color:#fff;border-radius:9px;width:30px;height:30px;font-size:20px;font-weight:700;cursor:pointer;line-height:1;display:inline-flex;align-items:center;justify-content:center;}
.am-add-btn:hover{filter:brightness(1.1);}
.am-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9000;align-items:flex-start;justify-content:center;padding:48px 16px;overflow:auto;}
.am-backdrop.open{display:flex;}
.am{background:#fff;border-radius:16px;max-width:520px;width:100%;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.am h3{margin:0 0 4px;font-size:16px;font-weight:900;}
.am .sub{font-size:12px;color:#64748b;margin-bottom:16px;}
.am-roles{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.am-role{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:800;color:#334155;cursor:pointer;}
.am-role.active{border-color:#0f6cbd;background:#eef5fc;color:#0c5aa0;box-shadow:0 0 0 2px #0f6cbd22;}
.am-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
.am-btn{border:none;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;}
.am-btn.cancel{background:#eceef1;color:#374151;}
.am-btn.ok{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
.am-btn:disabled{opacity:.5;cursor:not-allowed;}
.am-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:#8a8680;text-transform:uppercase;margin:0 0 6px;}
/* Le modal de création de tiers (z-index 5000) doit passer AU-DESSUS de ce modal. */
.ts-modal-overlay{z-index:9500 !important;}
.tiers-selector .ts-dropdown{z-index:9600;}
.addr-modal{z-index:9700 !important;}
.addr-modal .places-dropdown{z-index:9800 !important;}
</style>
<?php endif; ?>
<div class="am-backdrop" id="am-<?= $h($id) ?>">
  <div class="am">
    <h3><?= $h($title) ?></h3>
    <div class="sub"><?= $h($sub) ?></div>

    <p class="am-label">1 · <?= $h($roleLabel) ?></p>
    <div class="am-roles" id="am-roles-<?= $h($id) ?>">
      <?php foreach ($roles as $code => $lib): ?>
        <button type="button" class="am-role" data-role="<?= $h($code) ?>"><?= $h($lib) ?></button>
      <?php endforeach; ?>
    </div>

    <p class="am-label">2 · Tiers</p>
    <?php if (function_exists('tiers_selector_render')) {
        tiers_selector_render([
            'id'           => $tsId,
            'name'         => $tiersField,
            'allow_create' => true,
            'placeholder'  => 'Rechercher (nom, email, téléphone…) ou créer',
        ]);
    } ?>

    <div class="am-actions">
      <button type="button" class="am-btn cancel" onclick="acteurModalClose_<?= $jid ?>()">Annuler</button>
      <button type="button" class="am-btn ok" id="am-submit-<?= $h($id) ?>" disabled onclick="acteurModalSubmit_<?= $jid ?>()">Ajouter au dossier</button>
    </div>
  </div>
</div>
<script>
(function(){
  var ID=<?= json_encode($id) ?>, API=<?= json_encode($apiAdd) ?>, CSRF=<?= json_encode($csrf) ?>;
  var ENTITY=<?= json_encode($entity, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var ROLE_FIELD=<?= json_encode($roleField) ?>, TIERS_FIELD=<?= json_encode($tiersField) ?>;
  var selectedRole='';
  function hidden(){ var r=document.querySelector('[data-ts-root="'+ID+'_tiers"]'); return r?r.querySelector('.ts-value'):null; }
  function search(){ var r=document.querySelector('[data-ts-root="'+ID+'_tiers"]'); return r?r.querySelector('.ts-search'):null; }
  function refresh(){ var b=document.getElementById('am-submit-'+ID); var h=hidden(); b.disabled=!(selectedRole && h && h.value); }
  function root(){ return document.querySelector('[data-ts-root="'+ID+'_tiers"]'); }
  document.querySelectorAll('#am-roles-'+ID+' .am-role').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('#am-roles-'+ID+' .am-role').forEach(x=>x.classList.remove('active'));
      btn.classList.add('active'); selectedRole=btn.getAttribute('data-role');
      // le rôle choisi devient le rôle posé à la création d'un nouveau tiers
      var r=root(); if(r) r.dataset.tsDefaultRoles=selectedRole;
      refresh();
    });
  });
  // refresh quand un tiers est sélectionné OU créé (events du composant tiers_selector)
  var rt=root();
  if(rt){
    rt.addEventListener('tiers:selected', refresh);
    rt.addEventListener('tiers:created',  refresh);
    var srch=rt.querySelector('.ts-search'); if(srch) srch.addEventListener('input', function(){ setTimeout(refresh,50); });
    var clr=rt.querySelector('.ts-clear'); if(clr) clr.addEventListener('click', function(){ setTimeout(refresh,10); });
  }
  var hv=hidden(); if(hv){ hv.addEventListener('change',refresh); }

  window['acteurModalOpen_'+<?= json_encode($jid) ?>]=function(){
    selectedRole='';
    document.querySelectorAll('#am-roles-'+ID+' .am-role').forEach(x=>x.classList.remove('active'));
    var h=hidden(); if(h)h.value=''; var s=search(); if(s){s.value='';s.classList.remove('is-selected');}
    refresh();
    document.getElementById('am-'+ID).classList.add('open');
  };
  window['acteurModalClose_'+<?= json_encode($jid) ?>]=function(){ document.getElementById('am-'+ID).classList.remove('open'); };
  window['acteurModalSubmit_'+<?= json_encode($jid) ?>]=async function(){
    var h=hidden(); var idt=h?h.value:''; if(!selectedRole||!idt) return;
    var b=document.getElementById('am-submit-'+ID); b.disabled=true; b.textContent='Ajout…';
    try{
      var fd=new FormData();
      Object.keys(ENTITY).forEach(function(k){ fd.append(k, ENTITY[k]); });
      fd.append(TIERS_FIELD, idt); fd.append(ROLE_FIELD, selectedRole); fd.append('csrf_token', CSRF);
      var r=await fetch(API,{method:'POST',body:fd,credentials:'same-origin'}); var j=await r.json();
      if(!j.ok){ alert('Erreur : '+(j.error||'inconnue')); b.disabled=false; b.textContent='Ajouter au dossier'; return; }
      location.reload();
    }catch(e){ alert('Erreur réseau : '+e.message); b.disabled=false; b.textContent='Ajouter au dossier'; }
  };
})();
</script>
<?php
    }
}
