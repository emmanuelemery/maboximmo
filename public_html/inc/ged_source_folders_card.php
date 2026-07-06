<?php
declare(strict_types=1);
/**
 * inc/ged_source_folders_card.php — Card « 📁 Dossiers sources » pour fiches 360.
 *
 * Affiche les dossiers OneDrive LIÉS à une entité (archives de travail, non
 * importées) avec actions : Ouvrir · Importer des documents · Modifier · Supprimer.
 * Bouton « Lier un dossier » → navigateur OneDrive (composant réutilisable).
 *
 * Usage dans une fiche 360 :
 *   require_once __DIR__.'/inc/ged_source_folders_card.php';
 *   ged_source_folders_card($pdo, 'BIEN', $bienId, [
 *       'id_societe'=>$idSoc, 'id_agence'=>$idAge, 'metier'=>'gestion',
 *   ]);
 *
 * Émet son CSS/JS une seule fois. Inclut inc/ged_onedrive_browser.php.
 */
require_once __DIR__ . '/ged_source_folders.php';

if (!function_exists('ged_source_folders_card')) {

    function ged_source_folders_card(PDO $pdo, string $entType, int $entId, array $opts = []): void
    {
        $entType = strtoupper(trim($entType));
        if ($entType === '' || $entId <= 0) return;
        $folders = gsf_list_for_entity($pdo, $entType, $entId);

        $idSoc  = (int)($opts['id_societe'] ?? 0);
        $idAge  = (int)($opts['id_agence'] ?? 0);
        $metier = (string)($opts['metier'] ?? '');
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

        // CSS + navigateur (une seule fois par page).
        static $cssDone = false;
        if (!$cssDone) { $cssDone = true; echo gsf_card_css(); require_once __DIR__ . '/ged_onedrive_browser.php'; }
        ?>
        <div class="gsf-card" id="gsf-card-<?= $e($entType) ?>-<?= (int)$entId ?>"
             data-ent-type="<?= $e($entType) ?>" data-ent-id="<?= (int)$entId ?>"
             data-soc="<?= $idSoc ?>" data-age="<?= $idAge ?>" data-metier="<?= $e($metier) ?>">
            <div class="gsf-card-head">
                <div class="gsf-card-title">📁 Dossiers sources
                    <span class="gsf-card-hint">archives OneDrive liées — non importées</span></div>
                <button type="button" class="gsf-card-add" onclick="gsfCardAdd(this)">＋ Lier un dossier</button>
            </div>
            <div class="gsf-card-body">
                <?php if (!$folders): ?>
                    <div class="gsf-card-empty">Aucun dossier lié. « Lier un dossier » pointe une archive OneDrive (photos, devis, scans…) sans encombrer la GED.</div>
                <?php else: foreach ($folders as $f): ?>
                    <div class="gsf-card-row" data-id="<?= (int)$f['id'] ?>">
                        <span class="gsf-card-ic">📁</span>
                        <span class="gsf-card-nm" title="<?= $e($f['name']) ?>">
                            <?= $e($f['label'] ?: $f['name']) ?>
                            <?php if ($f['label'] && $f['label'] !== $f['name']): ?><em class="gsf-card-sub"><?= $e($f['name']) ?></em><?php endif; ?>
                        </span>
                        <span class="gsf-card-acts">
                            <?php if (!empty($f['web_url'])): ?><a class="gsf-card-btn" href="<?= $e($f['web_url']) ?>" target="_blank" rel="noopener">Ouvrir</a><?php endif; ?>
                            <button type="button" class="gsf-card-btn gsf-imp" onclick="gsfCardImport(<?= (int)$f['id'] ?>, this)">Importer</button>
                            <button type="button" class="gsf-card-btn gsf-del" onclick="gsfCardDelete(<?= (int)$f['id'] ?>, this)" title="Délier">✕</button>
                        </span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php
    }

    function gsf_card_css(): string
    {
        $csrf = function_exists('csrf_token') ? csrf_token('ged_source_folder') : '';
        $linkUrl = function_exists('app_url') ? app_url('/api/ged_source_folder_link.php') : '/api/ged_source_folder_link.php';
        ob_start(); ?>
<style>
.gsf-card{border:1px solid #d7e6e8;background:#fff;border-radius:16px;padding:16px 18px;margin:14px 0}
.gsf-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.gsf-card-title{font-size:13.5px;font-weight:700;color:#2c2a28}
.gsf-card-hint{display:block;font-size:11px;font-weight:500;color:#93a2ad;margin-left:0;margin-top:3px}
.gsf-card-add{flex-shrink:0;white-space:nowrap;border:1.5px solid #2d5f6b;background:#eaf3f4;color:#2d5f6b;border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:800;cursor:pointer}
.gsf-card-add:hover{background:#dcebed}
.gsf-card-empty{font-size:12.5px;color:#8a97a0;padding:8px 2px;line-height:1.5}
.gsf-card-row{display:flex;align-items:center;gap:10px;padding:9px 8px;border-radius:10px}
.gsf-card-row:hover{background:#f6fafa}
.gsf-card-ic{font-size:18px}
.gsf-card-nm{flex:1;min-width:0;font-size:13.5px;font-weight:700;color:#243B5C;display:flex;flex-direction:column}
.gsf-card-sub{font-style:normal;font-size:11px;font-weight:500;color:#93a2ad}
.gsf-card-acts{display:flex;align-items:center;gap:6px;flex-shrink:0}
.gsf-card-btn{border:1px solid #d7e0e2;background:#fff;color:#2d5f6b;border-radius:8px;padding:5px 11px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none}
.gsf-card-btn:hover{background:#eaf3f4}
.gsf-card-btn.gsf-del{color:#b3261e;border-color:#f0d4d1}
.gsf-card-btn.gsf-del:hover{background:#fdeceb}
</style>
<script>
(function(){
  var CSRF=<?= json_encode($csrf) ?>, LINK=<?= json_encode($linkUrl) ?>;
  window.gsfCardAdd=function(btn){
    var card=btn.closest('.gsf-card'); if(!card) return;
    if(typeof window.gsfOpenBrowser!=='function'){ alert('Navigateur OneDrive indisponible.'); return; }
    window.gsfOpenBrowser({
      entityType:card.dataset.entType, entityId:card.dataset.entId,
      idSociete:card.dataset.soc||'', idAgence:card.dataset.age||'', metier:card.dataset.metier||'',
      onLinked:function(){ location.reload(); }
    });
  };
  window.gsfCardDelete=function(id,btn){
    if(!confirm('Délier ce dossier ? (le dossier OneDrive n\'est pas supprimé)')) return;
    var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('action','delete'); fd.append('id',id);
    fetch(LINK,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(d&&d.ok){ var row=btn.closest('.gsf-card-row'); if(row) row.remove(); } else alert('Échec suppression');
    }).catch(function(e){ alert('Réseau : '+e.message); });
  };
  window.gsfCardImport=function(id,btn){
    if(typeof window.gsfOpenImport==='function') window.gsfOpenImport(id);
    else alert('Import à la carte — disponible très bientôt (étape suivante).');
  };
})();
</script>
        <?php
        return (string)ob_get_clean();
    }
}
