<?php
/**
 * inc/immeuble_recherche_mbi.php — STANDARD MBI : modal qui CONTIENT la vraie page
 * de recherche/création d'immeuble (agency_immeuble_form.php) dans une IFRAME.
 *
 * Pourquoi une iframe : l'autocomplete Google ne fonctionne QUE sur une vraie page
 * (pas réinjecté dans un modal). On affiche donc la page qui marche, telle quelle,
 * SANS la modifier (zéro régression). À la création, la page redirige vers
 * agency_immeuble_fiche.php?id=X : on capte cet id, on ferme, on rend l'immeuble.
 *
 * Logo Ma Box Immo = marque « validé & fonctionnel ».
 *
 * USAGE : immeuble_mbi_render(); immeuble_mbi_assets();
 *   ImmeubleRechercheMBI.open(function(imm){ imm.id, imm.nom, imm.adresse_1 ... });
 */
declare(strict_types=1);

if (!function_exists('immeuble_mbi_render')) {
    function immeuble_mbi_render(): void
    {
        if (defined('IMMEUBLE_MBI_RENDERED')) return;
        define('IMMEUBLE_MBI_RENDERED', true);
        $logo = function_exists('asset_url') ? asset_url('/images/mbi_annonces_logo2.png') : '/images/mbi_annonces_logo2.png';
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="imbm-backdrop" id="imbm-modal" aria-hidden="true">
          <div class="imbm-card" role="dialog" aria-modal="true">
            <div class="imbm-head">
              <span class="imbm-logo" role="img" aria-label="Ma Box Immo — validé"
                    style="background-image:url('<?= $h($logo) ?>')"></span>
              <h3>🏢 Rechercher / créer un immeuble</h3>
              <button type="button" class="imbm-x" data-imbm-close aria-label="Fermer">×</button>
            </div>
            <div class="imbm-framewrap">
              <div class="imbm-loading" id="imbm-loading">Chargement…</div>
              <iframe id="imbm-frame" class="imbm-frame" title="Recherche immeuble" src="about:blank"></iframe>
            </div>
          </div>
        </div>
        <?php
    }
}

if (!function_exists('immeuble_mbi_assets')) {
    function immeuble_mbi_assets(): void
    {
        if (defined('IMMEUBLE_MBI_ASSETS')) return;
        define('IMMEUBLE_MBI_ASSETS', true);
        $formUrl = function_exists('app_url') ? app_url('/immeuble_recherche_mbi_page.php') : '/immeuble_recherche_mbi_page.php';
        ?>
        <style>
          .imbm-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;align-items:center;justify-content:center;padding:24px;}
          .imbm-backdrop.open{display:flex;}
          .imbm-card{background:#fff;border-radius:16px;width:min(560px,96vw);height:min(860px,92vh);display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.3);overflow:hidden;}
          .imbm-head{display:flex;align-items:center;gap:12px;padding:6px 18px;border-bottom:1px solid #e5e7eb;flex:none;}
          /* logo recadré (le PNG a de grandes marges transparentes : on zoome dessus).
             display:inline-block OBLIGATOIRE sur un span sinon width/height ignorés. */
          .imbm-logo{display:inline-block;flex:none;width:120px;height:64px;background-repeat:no-repeat;background-position:center;background-size:175%;}
          .imbm-head h3{margin:0;font-size:17px;font-weight:800;color:#0f172a;flex:1;}
          .imbm-x{border:none;background:none;font-size:26px;color:#64748b;cursor:pointer;line-height:1;}
          .imbm-x:hover{color:#0f172a;}
          .imbm-framewrap{position:relative;flex:1;min-height:0;}
          .imbm-frame{border:0;width:100%;height:100%;display:block;}
          .imbm-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#64748b;font-weight:700;background:#fff;z-index:1;}
        </style>
        <script>
        (function(){
          const FORM_URL = <?= json_encode($formUrl) ?>;
          let onResult = null;   // callback création/liaison (imbm_created)
          let onPick = null;     // callback sélection seule (imbm_picked)
          const $ = id => document.getElementById(id);

          function open(cb){
            onResult = (typeof cb==='function') ? cb : null;
            onPick = null;
            $('imbm-loading').style.display = 'flex';
            // Recharge la page propre à chaque ouverture (nouvel immeuble).
            $('imbm-frame').src = FORM_URL;
            $('imbm-modal').classList.add('open');
            $('imbm-modal').setAttribute('aria-hidden','false');
          }
          // Mode "pick" : recherche Google + renvoi de l'adresse au parent, SANS
          // créer d'immeuble (ex. édition de l'adresse d'un immeuble existant).
          function openPick(cb){
            onPick = (typeof cb==='function') ? cb : null;
            onResult = null;
            $('imbm-loading').style.display = 'flex';
            $('imbm-frame').src = FORM_URL + (FORM_URL.indexOf('?')>=0 ? '&' : '?') + 'mode=pick';
            $('imbm-modal').classList.add('open');
            $('imbm-modal').setAttribute('aria-hidden','false');
          }
          function close(){
            $('imbm-modal').classList.remove('open');
            $('imbm-modal').setAttribute('aria-hidden','true');
            $('imbm-frame').src = 'about:blank';
          }

          document.addEventListener('click', e=>{
            if(e.target.closest('[data-imbm-close]')){ close(); return; }
            if(e.target.id==='imbm-modal'){ close(); }
          });
          document.addEventListener('keydown', e=>{ if(e.key==='Escape' && $('imbm-modal').classList.contains('open')) close(); });

          $('imbm-frame').addEventListener('load', function(){ $('imbm-loading').style.display = 'none'; });

          // La page dédiée (dans l'iframe) communique par postMessage.
          window.addEventListener('message', function(ev){
            const d = ev.data || {};
            if (d.type === 'imbm_height' && d.height) {
              // Modal qui colle au contenu : hauteur = en-tête + contenu (plafonné à 90vh).
              const headH = document.querySelector('.imbm-head').offsetHeight || 76;
              const maxH = Math.round(window.innerHeight * 0.9);
              document.querySelector('.imbm-card').style.height = Math.min(headH + d.height, maxH) + 'px';
              return;
            }
            if (d.type === 'imbm_cancel') { close(); return; }
            if (d.type === 'imbm_picked') {
              if (onPick) onPick(d.address);
              close();
              return;
            }
            if (d.type === 'imbm_created') {
              if (onResult) onResult(d.immeuble, d.created);
              close();
            }
          });

          window.ImmeubleRechercheMBI = { open, openPick, close };
        })();
        </script>
        <?php
    }
}
