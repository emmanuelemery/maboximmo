<?php
/**
 * creancier_dossier360.php — 360 d'UN dossier créancier (modèle bien_360 + onglets).
 *
 * 2 colonnes : GAUCHE = onglets (Dashboard / Dossier / Documents) · DROITE = chat permanent.
 * Réutilise inc/fiche_360_layout.php + agency_layout_top/bottom + le modal FluxBox.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';
/** Rendu markdown léger (gras + retours ligne) pour l'analyse IA. */
$mdlite = function (string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    return nl2br($s);
};

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
$base   = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';
$idDossier = (int)($_GET['id_dossier'] ?? 0);
if ($idDossier <= 0) { header('Location: ' . $base . 'creancier_liste.php'); exit; }

$std = $pdo->prepare("SELECT * FROM creancier_dossier WHERE id = ? LIMIT 1");
$std->execute([$idDossier]);
$dossier = $std->fetch(PDO::FETCH_ASSOC);
if (!$dossier) { http_response_code(404); exit('Dossier introuvable.'); }

$data = creancier_urgence_data($pdo, $idDossier, $userId);
if (!$data['acces']) { http_response_code(403); exit('Accès non autorisé à ce dossier.'); }

$stl = $pdo->prepare("
    SELECT l.entity_type, l.entity_id, l.role_dossier,
           COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),''),CONCAT('Tiers #',t.id)) AS tiers_lib,
           t.email AS tiers_email, t.telephone AS tiers_tel, t.mobile AS tiers_mobile,
           COALESCE(NULLIF(s.raison_sociale,''),NULLIF(s.nom,'')) AS soc_lib
    FROM creancier_dossier_lien l
    LEFT JOIN tiers t    ON l.entity_type='TIERS'   AND t.id = l.entity_id
    LEFT JOIN societes s ON l.entity_type='SOCIETE' AND s.id = l.entity_id
    WHERE l.id_dossier = ? ORDER BY l.entity_type, l.role_dossier");
$stl->execute([$idDossier]);
$creanciers = []; $pros = []; $debiteurs = [];
foreach ($stl as $l) {
    $role = (string)$l['role_dossier'];
    if ($l['entity_type'] === 'TIERS' && str_starts_with($role, 'creancier')) $creanciers[] = $l;
    elseif ($l['entity_type'] === 'TIERS' && in_array($role, ['avocat','commissaire_justice','expert_comptable','gerant','gestionnaire','notaire'], true)) $pros[] = $l;
    elseif (str_contains($role, 'debit')) $debiteurs[] = $l;
}

$sti = $pdo->prepare("SELECT * FROM creancier_dossier_item WHERE id_dossier = ? ORDER BY priorite DESC, date_echeance IS NULL, date_echeance ASC");
$sti->execute([$idDossier]); $items = $sti->fetchAll(PDO::FETCH_ASSOC);

$sta = $pdo->prepare("SELECT id, type_doc, extr_creancier_nom, extr_montant_total, confidence, review_flags FROM creancier_doc_analyse WHERE id_dossier = ? AND statut='a_valider' ORDER BY created_at DESC");
$sta->execute([$idDossier]); $analyses = $sta->fetchAll(PDO::FETCH_ASSOC);

$agenda = creancier_agenda($pdo, [$idDossier], 365);

$stm = $pdo->prepare("SELECT role, message FROM creancier_dossier_message WHERE id_dossier = ? ORDER BY id ASC LIMIT 100");
$stm->execute([$idDossier]); $messages = $stm->fetchAll(PDO::FETCH_ASSOC);
$csrfChat    = csrf_token('creancier_chat');
$csrfAnalyse = csrf_token('creancier_analyse');

$docs = [];
if (function_exists('gdl_documents_for_entity')) {
    try { $docs = gdl_documents_for_entity($pdo, 'CREANCIER_DOSSIER', $idDossier, ['limit' => 50]); } catch (Throwable $e) { $docs = []; }
}

$statutLbl = ['actif'=>'Actif','surveillance'=>'Surveillance','clos'=>'Clos'];

$pageTitle    = 'Dossier · ' . ($dossier['libelle'] ?: $dossier['code']);
$pageSubtitle = 'Vue 360° · créancier';
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>
<script>window.APP_BASE = <?= json_encode(rtrim($base, '/')) ?>;</script>
<style>
.f360-grid { grid-template-columns:2fr 1fr !important; align-items:start; }  /* chat/actions = 1/3 */
@media (max-width:900px){ .f360-grid { grid-template-columns:1fr !important; } }
.cre-tabs { display:flex; gap:4px; border-bottom:2px solid #e6e1d8; margin-bottom:14px; flex-wrap:wrap; }
.cre-tab { padding:10px 18px; border:none; background:transparent; font-family:'Sora',sans-serif; font-size:13px; font-weight:700; color:#8a8680; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; }
.cre-tab:hover { color:#243B5C; }
.cre-tab.active { color:#243B5C; border-bottom-color:#D4A047; }
.cre-tabpane { display:none; }
.cre-tabpane.active { display:block; }
.cre-chat-col { position:sticky; top:12px; }
.cre-chatbox { max-height:42vh; overflow-y:auto; display:flex; flex-direction:column; gap:8px; margin-bottom:10px; }
.cre-bub { max-width:85%; padding:8px 12px; border-radius:12px; font-size:12.5px; white-space:pre-line; }
.cre-bub.me { align-self:flex-end; background:#243b5c; color:#fff; }
.cre-bub.ia { align-self:flex-start; background:#eef1f6; color:#243b5c; }
.cre-analyse { background:#fff; border:1px solid #e6e1d8; border-radius:10px; padding:16px 18px; font-size:13.5px; line-height:1.55; color:#3a3830; }
</style>
<?php
$chaine = [];
foreach ($debiteurs as $d0) {
    $lib = $d0['soc_lib'] ?: $d0['tiers_lib'] ?: ('#'.$d0['entity_id']);
    $url = $d0['entity_type']==='SOCIETE' ? $base.'creancier360.php?type=SOCIETE&id='.(int)$d0['entity_id'] : $base.'creancier360.php?type=TIERS&id='.(int)$d0['entity_id'];
    $chaine[] = ['icon'=>'🏢','label'=>$lib,'url'=>$url];
}
$chaine[] = ['icon'=>'⚖️','label'=>'Ce dossier','url'=>null];
fiche360_breadcrumb($chaine, 'Architecture');

$metas = [['icon'=>'💰','text'=>'Dû '.$eur($data['montant_du'])]];
if ($data['total_net_bloque'] > 0) $metas[] = ['icon'=>'🔒','text'=>$eur($data['total_net_bloque']).' bloqué'];
if ($data['butoirs_en_retard']) $metas[] = ['icon'=>'⏰','text'=>count($data['butoirs_en_retard']).' retard(s)'];
$headerActions = [];
if ($isMgr) $headerActions[] = ['label'=>'📄 Charger un document','url'=>'#','class'=>'tr-btn tr-btn-primary','onclick'=>"fbxOpenUploadModal({creancier_dossier_id:{$idDossier}, soc_id:".(int)($dossier['id_societe']??0).", age_id:".(int)($dossier['id_agence']??0).", origin:'creancier'});return false;"];
$badgeClass = $dossier['niveau_risque']==='rouge' ? 'vacant' : ($dossier['niveau_risque']==='vert' ? 'loue' : 'dispo');
fiche360_header('⚖️', ($dossier['libelle'] ?: $dossier['code']), ['label'=>strtoupper((string)$dossier['niveau_risque']),'class'=>$badgeClass], ($dossier['synthese'] ?: ''), $metas, $headerActions);

$bcol = ['rouge'=>'red','orange'=>'orange','vert'=>'green'][$dossier['niveau_risque']] ?? 'gray';
fiche360_status_banner('Dossier <b>'.h($statutLbl[$dossier['statut']] ?? $dossier['statut']).'</b>'.($dossier['numero_dossier_adverse']?' · n° '.h($dossier['numero_dossier_adverse']):''), $bcol, ($dossier['niveau_risque']==='rouge'?'🚨':'⚖️'), $data['prochaine_butoir']?('Prochaine échéance '.$dfr($data['prochaine_butoir'])):'');
?>

<div class="f360-grid">
  <!-- ══ COLONNE GAUCHE : ONGLETS ══ -->
  <div>
    <div class="cre-tabs">
      <button type="button" class="cre-tab active" data-pane="pane-dash">📊 Dashboard</button>
      <button type="button" class="cre-tab" data-pane="pane-dossier">⚖️ Dossier</button>
      <button type="button" class="cre-tab" data-pane="pane-docs">📂 Documents <span style="opacity:.6"><?= count($docs) ?></span></button>
    </div>

    <!-- ── ONGLET DASHBOARD ── -->
    <div class="cre-tabpane active" id="pane-dash">
      <?php if ($data['butoirs_en_retard']): ?>
      <div class="f360-card" style="background:#fef2f2;border-left:4px solid #dc2626;">
        <h3 style="color:#991b1b;">🚨 Butoirs en retard</h3>
        <?php foreach ($data['butoirs_en_retard'] as $r): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #fde0e0;font-size:12.5px;"><span><?= h($r['creancier']) ?></span><strong style="color:#dc2626;"><?= $dfr($r['date_butoir']) ?> (J<?= (int)$r['jours'] ?>)</strong></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="f360-card">
        <h3>📅 Agenda <span class="count"><?= count($agenda) ?></span></h3>
        <?php if (!$agenda): ?><div class="f360-empty"><div class="em-ico">📅</div>Aucune date.</div><?php endif; ?>
        <?php foreach ($agenda as $ev): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;"><span><span style="background:#eef1f6;color:#4878a6;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;"><?= h($ev['type']) ?></span> <?= h($ev['libelle']) ?></span><strong style="color:<?= $ev['retard']?'#dc2626':'#4878a6' ?>;"><?= $dfr($ev['date']) ?></strong></div>
        <?php endforeach; ?>
      </div>

      <div class="f360-card">
        <h3>💰 Montants dus</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:8px;">
          <div><div style="font-size:10px;color:#9a9690;">MONTANT DÛ</div><strong style="font-size:18px;color:#dc2626;"><?= $eur($data['montant_du']) ?></strong></div>
          <div><div style="font-size:10px;color:#9a9690;">DETTE</div><strong style="font-size:18px;"><?= $eur($data['total_dette']) ?></strong></div>
          <div><div style="font-size:10px;color:#9a9690;">NET BLOQUÉ</div><strong style="font-size:18px;"><?= $eur($data['total_net_bloque']) ?></strong></div>
          <div><div style="font-size:10px;color:#9a9690;">CAPTÉ/MOIS</div><strong style="font-size:18px;color:#4878a6;"><?= $eur($data['tresorerie_captee_mensuelle']) ?></strong></div>
        </div>
        <?php foreach ($items as $it): if ($it['type']!=='DETTE') continue; ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;"><span><?= h($it['titre']) ?></span><strong><?= $it['montant']!==null?$eur($it['montant']):'' ?></strong></div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($data['locataires_saisis'])): ?>
      <div class="f360-card" style="border-left:4px solid #2d5f6b;">
        <h3>🔑 Locataires saisis <span class="count"><?= count($data['locataires_saisis']) ?></span></h3>
        <div style="font-size:11px;color:#9a9690;margin-bottom:8px;font-style:italic;">Loyers captés au profit du créancier. Le locataire peut occuper un autre immeuble que celui en cause.</div>
        <?php foreach ($data['locataires_saisis'] as $ls): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
            <span><strong><?= h($ls['locataire']) ?></strong>
              <span style="color:#9a9690;"> · immeuble : <?= h($ls['immeuble']) ?><?= $ls['bien_ref'] ? ' ('.h($ls['bien_ref']).')' : '' ?></span>
              <span style="color:#9a9690;"> · créancier <?= h($ls['creancier']) ?></span></span>
            <strong style="color:#2d5f6b;"><?= $ls['loyer'] ? $eur($ls['loyer']).'/mois' : $eur($ls['net_bloque']) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="f360-card">
        <h3>⚖️ Créanciers <span class="count"><?= count($creanciers) ?></span></h3>
        <?php if (!$creanciers): ?><div class="f360-empty"><div class="em-ico">⚖️</div>Aucun créancier lié.</div><?php endif; ?>
        <?php foreach ($creanciers as $c): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
            <a href="<?= h($base) ?>creancier_creancier360.php?id=<?= (int)$c['entity_id'] ?>" style="color:#5b21b6;text-decoration:none;font-weight:600;"><?= h($c['tiers_lib']) ?> ↗</a>
            <span class="muted" style="color:#9a9690;"><?= h($c['tiers_email'] ?: $c['tiers_tel'] ?: '') ?></span></div>
        <?php endforeach; ?>
      </div>

      <div class="f360-card">
        <h3>📂 Documents <span class="count"><?= count($docs) ?></span></h3>
        <?php if (!$docs): ?><div class="f360-empty"><div class="em-ico">📄</div>Aucun document.</div><?php endif; ?>
        <?php foreach (array_slice($docs,0,6) as $d): ?>
          <div style="padding:5px 0;border-bottom:1px solid #f0ece6;font-size:12px;">📄 <?= h($d['name_display'] ?? $d['name_file'] ?? '') ?></div>
        <?php endforeach; ?>
        <?php if (count($docs)>6): ?><div style="font-size:11px;color:#9a9690;margin-top:6px;">+ <?= count($docs)-6 ?> autres (onglet Documents)</div><?php endif; ?>
      </div>

      <?php if ($pros): ?>
      <div class="f360-card">
        <h3>👔 Contacts du dossier</h3>
        <?php foreach ($pros as $p): ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;"><span><?= h($p['tiers_lib']) ?> <span style="background:#eef1f6;color:#4878a6;border-radius:99px;padding:1px 7px;font-size:10px;"><?= h($p['role_dossier']) ?></span></span><span style="color:#9a9690;"><?= h($p['tiers_email'] ?: $p['tiers_tel'] ?: '') ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── ONGLET DOSSIER (résumé + analyse IA) ── -->
    <div class="cre-tabpane" id="pane-dossier">
      <div class="f360-card" style="border-left:4px solid #243B5C;">
        <h3>📝 Résumé du dossier</h3>
        <div style="font-size:13.5px;line-height:1.55;color:#3a3830;white-space:pre-line;"><?= $dossier['synthese'] ? h($dossier['synthese']) : '<span style="color:#9a9690;font-style:italic;">Aucun résumé saisi.</span>' ?></div>
      </div>
      <div class="f360-card">
        <h3>🧠 Analyse IA du dossier
          <?php if ($isMgr): ?><button type="button" id="genAnalyse" class="tr-btn" style="float:right;padding:4px 12px;font-size:11px;"><?= $dossier['analyse_ia'] ? '↻ Régénérer' : '🧠 Générer' ?></button><?php endif; ?>
        </h3>
        <div id="analyseBox" class="cre-analyse">
          <?php if ($dossier['analyse_ia']): ?>
            <?= $mdlite((string)$dossier['analyse_ia']) ?>
            <div style="font-size:10px;color:#9a9690;margin-top:10px;">Généré le <?= $dfr($dossier['analyse_ia_at']) ?></div>
          <?php else: ?>
            <div class="f360-empty" style="padding:18px;"><div class="em-ico">🧠</div>Pas encore d'analyse. <?= $isMgr ? 'Clique sur « Générer » — l\'IA synthétise le dossier à partir des documents analysés.' : 'À générer par un manager.' ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($dossier['commentaire']): ?>
      <div class="f360-card" style="border-left:4px solid #7c3aed;">
        <h3>⚖️ Commentaire / avis avocat</h3>
        <div style="font-size:12.5px;white-space:pre-line;color:#3a3830;"><?= h($dossier['commentaire']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── ONGLET DOCUMENTS ── -->
    <div class="cre-tabpane" id="pane-docs">
      <?php if ($analyses): ?>
      <div class="f360-card" style="border-left:4px solid #eab308;">
        <h3>📄 Analyses IA à valider <span class="count"><?= count($analyses) ?></span></h3>
        <?php foreach ($analyses as $an): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;"><span><?= h($an['type_doc']) ?> · <?= h($an['extr_creancier_nom']) ?> · <?= $an['extr_montant_total']!==null?$eur($an['extr_montant_total']):'—' ?><?= $an['review_flags']?' ⚠️':'' ?></span><a class="tr-btn" href="<?= h($base) ?>creancier_scan.php?analyse_id=<?= (int)$an['id'] ?>" style="padding:4px 10px;font-size:11px;">Réviser</a></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="f360-card">
        <h3>📂 Tous les documents <span class="count"><?= count($docs) ?></span></h3>
        <?php if (!$docs): ?><div class="f360-empty"><div class="em-ico">📄</div>Aucun document lié. <?= $isMgr?'Utilise « Charger un document ».':'' ?></div><?php endif; ?>
        <?php foreach ($docs as $d): ?>
          <div style="display:flex;gap:8px;padding:7px 0;border-bottom:1px solid #f0ece6;font-size:12px;align-items:center;">
            <span style="font-family:'DM Mono',monospace;color:#5b21b6;font-weight:700;min-width:120px;">[<?= h($d['document_type'] ?? '') ?>]</span>
            <span style="flex:1;">📄 <?= h($d['name_display'] ?? $d['name_file'] ?? ('Doc #'.($d['id']??'?'))) ?></span>
            <span style="color:#9a9690;font-size:10px;"><?= isset($d['created_at'])?h(date('d/m/y',strtotime((string)$d['created_at']))):'' ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ══ COLONNE DROITE (1/3) : ACTIONS + CONTACTS + CHAT ══ -->
  <div>
    <?php
    // Panneau Actions du dossier
    $actDossier = [];
    if ($isMgr) {
        $actDossier[] = ['icon'=>'📄','label'=>'Charger un document','url'=>'#','onclick'=>"fbxOpenUploadModal({creancier_dossier_id:{$idDossier}, soc_id:".(int)($dossier['id_societe']??0).", age_id:".(int)($dossier['id_agence']??0).", origin:'creancier'});return false;"];
    }
    foreach ($debiteurs as $d0) {
        $lib = $d0['soc_lib'] ?: $d0['tiers_lib'] ?: ('#'.$d0['entity_id']);
        $url = $d0['entity_type']==='SOCIETE' ? $base.'creancier360.php?type=SOCIETE&id='.(int)$d0['entity_id'] : $base.'creancier360.php?type=TIERS&id='.(int)$d0['entity_id'];
        $actDossier[] = ['icon'=>'🏢','label'=>'Débiteur 360 · '.mb_substr($lib,0,22),'url'=>$url];
    }
    $actDossier[] = ['icon'=>'📊','label'=>'Dashboard créanciers','url'=>$base.'creancier_dashboard.php'];
    $actDossier[] = ['icon'=>'📂','label'=>'Tous les dossiers','url'=>$base.'creancier_liste.php'];
    fiche360_actions_panel('Actions dossier', $actDossier);

    // Contacts : créanciers + intervenants
    $creLinks = [];
    foreach ($creanciers as $c) $creLinks[] = ['icon'=>'⚖️','name'=>$c['tiers_lib'],'ref'=>($c['tiers_email'] ?: $c['tiers_tel'] ?: 'créancier'),'url'=>$base.'creancier_creancier360.php?id='.(int)$c['entity_id']];
    if ($creLinks) fiche360_attach('CRÉANCIERS', $creLinks);
    $coLinks = [];
    foreach ($pros as $p) $coLinks[] = ['icon'=>'👔','name'=>$p['tiers_lib'].' ('.$p['role_dossier'].')','ref'=>($p['tiers_email'] ?: $p['tiers_tel'] ?: ''),'url'=>'#'];
    if ($coLinks) fiche360_attach('CONTACTS', $coLinks);
    ?>

    <div class="f360-card" style="border-left:4px solid #4878a6;margin-top:12px;">
      <h3>💬 Discussion du dossier</h3>
      <div id="creChatBox" class="cre-chatbox">
        <?php if (!$messages): ?><div class="f360-empty" id="creChatEmpty"><div class="em-ico">💬</div>Posez une question sur ce dossier — l'IA répond à partir de son contexte.</div><?php endif; ?>
        <?php foreach ($messages as $m): $me = $m['role']==='user'; ?>
          <div class="cre-bub <?= $me?'me':'ia' ?>"><?= h($m['message']) ?></div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:8px;">
        <input type="text" id="creChatInput" placeholder="Votre question…" style="flex:1;padding:9px 12px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
        <button type="button" id="creChatSend" class="tr-btn tr-btn-primary">Envoyer</button>
      </div>
    </div>
  </div>
</div>

<?= fiche360_js() ?>
<script>
(function(){
  // Onglets
  document.querySelectorAll('.cre-tab').forEach(function(t){
    t.addEventListener('click', function(){
      document.querySelectorAll('.cre-tab').forEach(x=>x.classList.remove('active'));
      document.querySelectorAll('.cre-tabpane').forEach(x=>x.classList.remove('active'));
      t.classList.add('active');
      document.getElementById(t.dataset.pane).classList.add('active');
    });
  });

  // Chat
  const dossier=<?= (int)$idDossier ?>, csrf=<?= json_encode($csrfChat) ?>;
  const box=document.getElementById('creChatBox'), input=document.getElementById('creChatInput'), btn=document.getElementById('creChatSend');
  function bubble(t,me){const e=document.getElementById('creChatEmpty');if(e)e.remove();const d=document.createElement('div');d.className='cre-bub '+(me?'me':'ia');d.textContent=t;box.appendChild(d);box.scrollTop=box.scrollHeight;return d;}
  async function send(){const msg=(input.value||'').trim();if(!msg)return;bubble(msg,true);input.value='';btn.disabled=true;const w=bubble('…',false);
    const fd=new FormData();fd.append('id_dossier',dossier);fd.append('message',msg);fd.append('csrf_token',csrf);
    try{const r=await fetch('api/creancier_chat_post.php',{method:'POST',body:fd});const j=await r.json();w.textContent=j.ok?j.reply:('Erreur : '+(j.error||'échec'));}
    catch(e){w.textContent='Erreur : '+e;}finally{btn.disabled=false;box.scrollTop=box.scrollHeight;}}
  btn.addEventListener('click',send);input.addEventListener('keydown',e=>{if(e.key==='Enter')send();});box.scrollTop=box.scrollHeight;

  // Analyse IA
  const gen=document.getElementById('genAnalyse');
  if(gen) gen.addEventListener('click', async function(){
    gen.disabled=true; const t=gen.textContent; gen.textContent='⏳ Analyse…';
    const ab=document.getElementById('analyseBox'); ab.innerHTML='<div style="color:#9a9690;padding:14px;">🧠 L\'IA analyse le dossier… (10-20s)</div>';
    const fd=new FormData(); fd.append('id_dossier',dossier); fd.append('csrf_token',<?= json_encode($csrfAnalyse) ?>);
    try{
      const r=await fetch('api/creancier_analyse_generate.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ ab.innerHTML = j.analyse.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>'); }
      else { ab.innerHTML='<div style="color:#dc2626;padding:14px;">✗ '+(j.error||'échec')+'</div>'; }
    }catch(e){ ab.innerHTML='<div style="color:#dc2626;padding:14px;">✗ '+e+'</div>'; }
    finally{ gen.disabled=false; gen.textContent=t; }
  });
})();
</script>
<?php if ($isMgr) require __DIR__ . '/inc/fluxbox_upload_modal.php'; ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
