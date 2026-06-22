<?php
/**
 * creancier_dossier360.php — 360 d'UN dossier créancier, sur le MODÈLE bien_360.
 *
 * Chaîne d'architecture en haut (débiteur → ce dossier), header objet, barre IA,
 * bandeau statut, 2 colonnes (cards à gauche, panneau ACTIONS noir + contacts à droite).
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

// Liens : acteurs (tiers) / sociétés / biens.
$stl = $pdo->prepare("
    SELECT l.entity_type, l.entity_id, l.role_dossier,
           COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),''),CONCAT('Tiers #',t.id)) AS tiers_lib,
           t.email AS tiers_email, t.telephone AS tiers_tel, t.mobile AS tiers_mobile,
           COALESCE(NULLIF(s.raison_sociale,''),NULLIF(s.nom,'')) AS soc_lib,
           COALESCE(NULLIF(b.reference_bien,''),NULLIF(b.bien_titre_affiche,'')) AS bien_ref
    FROM creancier_dossier_lien l
    LEFT JOIN tiers t    ON l.entity_type='TIERS'   AND t.id = l.entity_id
    LEFT JOIN societes s ON l.entity_type='SOCIETE' AND s.id = l.entity_id
    LEFT JOIN biens b    ON l.entity_type='BIEN'    AND b.id = l.entity_id
    WHERE l.id_dossier = ? ORDER BY l.entity_type, l.role_dossier");
$stl->execute([$idDossier]);
$acteurs = []; $societes = []; $biens = []; $debiteurs = [];
foreach ($stl as $l) {
    if ($l['entity_type'] === 'TIERS')   $acteurs[] = $l;
    elseif ($l['entity_type'] === 'SOCIETE') { $societes[] = $l; if (str_contains((string)$l['role_dossier'], 'debit')) $debiteurs[] = $l; }
    elseif ($l['entity_type'] === 'BIEN') $biens[] = $l;
}

$sti = $pdo->prepare("SELECT * FROM creancier_dossier_item WHERE id_dossier = ? ORDER BY priorite DESC, date_echeance IS NULL, date_echeance ASC");
$sti->execute([$idDossier]); $items = $sti->fetchAll(PDO::FETCH_ASSOC);

$sta = $pdo->prepare("SELECT id, type_doc, extr_creancier_nom, extr_numero_dossier, extr_montant_total, confidence, review_flags FROM creancier_doc_analyse WHERE id_dossier = ? AND statut='a_valider' ORDER BY created_at DESC");
$sta->execute([$idDossier]); $analyses = $sta->fetchAll(PDO::FETCH_ASSOC);

$agenda = creancier_agenda($pdo, [$idDossier], 365);

$stm = $pdo->prepare("SELECT role, message FROM creancier_dossier_message WHERE id_dossier = ? ORDER BY id ASC LIMIT 100");
$stm->execute([$idDossier]); $messages = $stm->fetchAll(PDO::FETCH_ASSOC);
$csrfChat = csrf_token('creancier_chat');

$docs = [];
if (function_exists('gdl_documents_for_entity')) {
    try { $docs = gdl_documents_for_entity($pdo, 'CREANCIER_DOSSIER', $idDossier, ['limit' => 20]); } catch (Throwable $e) { $docs = []; }
}

$riskColor = ['vert' => 'green', 'orange' => 'orange', 'rouge' => 'red'];
$statutLbl = ['actif' => 'Actif', 'surveillance' => 'Surveillance', 'clos' => 'Clos'];

$pageTitle    = 'Dossier · ' . ($dossier['libelle'] ?: $dossier['code']);
$pageSubtitle = 'Vue 360° · créancier';
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>
<script>window.APP_BASE = <?= json_encode(rtrim($base, '/')) ?>;</script>
<?php
// ─── Chaîne d'architecture : débiteur(s) → ce dossier ──
$chaine = [];
foreach ($debiteurs as $d0) $chaine[] = ['icon'=>'🏢','label'=>$d0['soc_lib'] ?: ('Société #'.$d0['entity_id']),'url'=>$base.'creancier360.php?type=SOCIETE&id='.(int)$d0['entity_id']];
foreach ($biens as $b0)     $chaine[] = ['icon'=>'🏠','label'=>$b0['bien_ref'] ?: ('Bien #'.$b0['entity_id']),'url'=>null];
$chaine[] = ['icon'=>'⚖️','label'=>'Ce dossier','url'=>null];
fiche360_breadcrumb($chaine, 'Architecture');

// ─── Header ──
$metas = [
    ['icon'=>'💰','text'=>'Reste dû ' . $eur($data['reste_du'])],
    ['icon'=>'🔒','text'=>$eur($data['total_net_bloque']) . ' bloqué'],
];
if ($data['butoirs_en_retard']) $metas[] = ['icon'=>'⏰','text'=>count($data['butoirs_en_retard']) . ' retard(s)'];
$headerActions = [];
if ($isMgr) $headerActions[] = ['label'=>'📄 Charger un document','url'=>'#','class'=>'tr-btn tr-btn-primary','onclick'=>"fbxOpenUploadModal({creancier_dossier_id:{$idDossier}, soc_id:" . (int)($dossier['id_societe']??0) . ", age_id:" . (int)($dossier['id_agence']??0) . ", origin:'creancier'});return false;"];
$badgeClass = $dossier['niveau_risque'] === 'rouge' ? 'vacant' : ($dossier['niveau_risque'] === 'vert' ? 'loue' : 'dispo');
fiche360_header('⚖️', ($dossier['libelle'] ?: $dossier['code']),
    ['label'=>strtoupper((string)$dossier['niveau_risque']),'class'=>$badgeClass],
    ($dossier['synthese'] ?: ''), $metas, $headerActions);

// ─── Barre IA + statut ──
$rid = 'cre-ia';
?>
<?php
$bcol = ['rouge'=>'red','orange'=>'orange','vert'=>'green'][$dossier['niveau_risque']] ?? 'gray';
$bmsg = 'Dossier <b>' . h($statutLbl[$dossier['statut']] ?? $dossier['statut']) . '</b>'
      . ($dossier['numero_dossier_adverse'] ? ' · n° ' . h($dossier['numero_dossier_adverse']) : '');
fiche360_status_banner($bmsg, $bcol, ($dossier['niveau_risque']==='rouge'?'🚨':'⚖️'),
    $data['prochaine_butoir'] ? ('Prochaine échéance ' . $dfr($data['prochaine_butoir'])) : '');
?>

<div class="f360-grid">
  <!-- ══ COLONNE PRINCIPALE ══ -->
  <div>

    <?php if ($data['butoirs_en_retard']): ?>
    <div class="f360-card" style="background:#fef2f2;border-left:4px solid #dc2626;">
      <h3 style="color:#991b1b;">🚨 Butoirs en retard</h3>
      <?php foreach ($data['butoirs_en_retard'] as $r): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #fde0e0;font-size:12.5px;">
          <span><?= h($r['creancier']) ?> · <span style="color:#9a9690;"><?= h($r['type_saisie']) ?></span></span>
          <strong style="color:#dc2626;"><?= $dfr($r['date_butoir']) ?> (J<?= (int)$r['jours'] ?>)</strong></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Saisies par créancier -->
    <div class="f360-card">
      <h3>⚖️ Saisies par créancier <span class="count"><?= count($data['saisies_par_creancier']) ?></span></h3>
      <?php if (!$data['saisies_par_creancier']): ?><div class="f360-empty"><div class="em-ico">⚖️</div>Aucune saisie active.</div><?php endif; ?>
      <?php foreach ($data['saisies_par_creancier'] as $c): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
          <a href="<?= h($base) ?>creancier_creancier360.php?id=<?= (int)$c['id_creancier'] ?>" style="color:#5b21b6;text-decoration:none;font-weight:600;"><?= h($c['libelle']) ?> ↗</a>
          <span><strong><?= $eur($c['total_net']) ?></strong> · <span style="color:#9a9690;"><?= $dfr($c['prochaine_butoir']) ?></span></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Dettes / items -->
    <div class="f360-card">
      <h3>📌 Dettes · risques · décisions <span class="count"><?= count($items) ?></span></h3>
      <?php if (!$items): ?><div class="f360-empty"><div class="em-ico">📌</div>Aucun item.</div><?php endif; ?>
      <?php foreach ($items as $it): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
          <span><span style="background:#eef1f6;color:#4878a6;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;"><?= h($it['type']) ?></span> <?= h($it['titre']) ?><?php if ($it['date_echeance']): ?> · <span style="color:#9a9690;"><?= $dfr($it['date_echeance']) ?></span><?php endif; ?></span>
          <span><?= $it['montant'] !== null ? '<strong>' . $eur($it['montant']) . '</strong>' : '' ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Agenda du dossier -->
    <div class="f360-card">
      <h3>📅 Agenda du dossier <span class="count"><?= count($agenda) ?></span></h3>
      <?php if (!$agenda): ?><div class="f360-empty"><div class="em-ico">📅</div>Aucune date.</div><?php endif; ?>
      <?php foreach ($agenda as $ev): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
          <span><span style="background:#eef1f6;color:#4878a6;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;"><?= h($ev['type']) ?></span> <?= h($ev['libelle']) ?></span>
          <strong style="color:<?= $ev['retard'] ? '#dc2626' : '#4878a6' ?>;"><?= $dfr($ev['date']) ?></strong></div>
      <?php endforeach; ?>
    </div>

    <!-- Commentaire avocat -->
    <?php if (!empty($dossier['commentaire'])): ?>
    <div class="f360-card" style="border-left:4px solid #7c3aed;">
      <h3>⚖️ Commentaire / avis avocat</h3>
      <div style="font-size:12.5px;white-space:pre-line;color:#3a3830;"><?= h($dossier['commentaire']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Analyses IA à valider -->
    <?php if ($analyses): ?>
    <div class="f360-card" style="border-left:4px solid #eab308;">
      <h3>📄 Analyses IA à valider <span class="count"><?= count($analyses) ?></span></h3>
      <?php foreach ($analyses as $an): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
          <span><span style="background:#eef1f6;color:#4878a6;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;"><?= h($an['type_doc']) ?></span> <?= h($an['extr_creancier_nom']) ?> · <?= $an['extr_montant_total']!==null?$eur($an['extr_montant_total']):'—' ?> · <?= (int)round((float)$an['confidence']*100) ?>%<?= $an['review_flags']?' ⚠️':'' ?></span>
          <a class="tr-btn" href="<?= h($base) ?>creancier_scan.php?analyse_id=<?= (int)$an['id'] ?>" style="padding:4px 10px;font-size:11px;">Réviser</a></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Documents GED -->
    <div class="f360-card">
      <h3>📂 Documents (GED) <span class="count"><?= count($docs) ?></span></h3>
      <?php if (!$docs): ?><div class="f360-empty"><div class="em-ico">📄</div>Aucun document lié.</div><?php endif; ?>
      <?php foreach ($docs as $d): ?>
        <div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12px;">
          <span style="font-family:'DM Mono',monospace;color:#5b21b6;font-weight:700;min-width:110px;">[<?= h($d['document_type'] ?? '') ?>]</span>
          <span style="flex:1;">📄 <?= h($d['name_display'] ?? $d['name_file'] ?? ('Doc #'.($d['id']??'?'))) ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Chat du dossier -->
    <div class="f360-card" style="border-left:4px solid #4878a6;">
      <h3>💬 Chat du dossier</h3>
      <div id="creChatBox" style="max-height:300px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
        <?php if (!$messages): ?><div class="f360-empty" id="creChatEmpty"><div class="em-ico">💬</div>Posez une question sur ce dossier — l'IA répond à partir de son contexte.</div><?php endif; ?>
        <?php foreach ($messages as $m): $me = $m['role'] === 'user'; ?>
          <div style="align-self:<?= $me?'flex-end':'flex-start' ?>;max-width:80%;background:<?= $me?'#243b5c':'#eef1f6' ?>;color:<?= $me?'#fff':'#243b5c' ?>;padding:8px 12px;border-radius:12px;font-size:12.5px;white-space:pre-line;"><?= h($m['message']) ?></div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:8px;">
        <input type="text" id="creChatInput" placeholder="Votre question…" style="flex:1;padding:9px 12px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
        <button type="button" id="creChatSend" class="tr-btn tr-btn-primary">Envoyer</button>
      </div>
    </div>

  </div>

  <!-- ══ COLONNE LATÉRALE ══ -->
  <div>
    <?php
    $actions = [];
    if ($isMgr) {
        $actions[] = ['icon'=>'📄','label'=>'Charger un document','url'=>'#','onclick'=>"fbxOpenUploadModal({creancier_dossier_id:{$idDossier}, soc_id:" . (int)($dossier['id_societe']??0) . ", age_id:" . (int)($dossier['id_agence']??0) . ", origin:'creancier'});return false;"];
    }
    $actions[] = ['icon'=>'📊','label'=>'Dashboard créanciers','url'=>$base.'creancier_dashboard.php'];
    $actions[] = ['icon'=>'📂','label'=>'Tous les dossiers','url'=>$base.'creancier_liste.php'];
    foreach ($debiteurs as $d0) $actions[] = ['icon'=>'🏢','label'=>'Débiteur 360 · ' . ($d0['soc_lib'] ?: '#'.$d0['entity_id']),'url'=>$base.'creancier360.php?type=SOCIETE&id='.(int)$d0['entity_id']];
    fiche360_actions_panel('Actions dossier', $actions);

    // Contacts : créanciers + pros (acteurs tiers).
    $creLinks = []; $proLinks = [];
    foreach ($acteurs as $a) {
        $role = (string)$a['role_dossier'];
        $entry = ['icon'=>'⚖️','name'=>$a['tiers_lib'],'ref'=>($a['tiers_email'] ?: $a['tiers_mobile'] ?: $a['tiers_tel'] ?: $role),'url'=>$base.'creancier_creancier360.php?id='.(int)$a['entity_id']];
        if (str_starts_with($role, 'creancier')) $creLinks[] = $entry;
        else { $entry['icon'] = '👔'; $entry['url'] = '#'; $proLinks[] = $entry; }
    }
    if ($creLinks) fiche360_attach('CRÉANCIERS', $creLinks);
    if ($proLinks) fiche360_attach('AVOCAT / HUISSIER', $proLinks);
    $debLinks = [];
    foreach ($debiteurs as $d0) $debLinks[] = ['icon'=>'🏢','name'=>$d0['soc_lib'] ?: ('Société #'.$d0['entity_id']),'ref'=>'débiteur','url'=>$base.'creancier360.php?type=SOCIETE&id='.(int)$d0['entity_id']];
    if ($debLinks) fiche360_attach('DÉBITEUR', $debLinks);
    ?>
  </div>
</div>

<?= fiche360_js() ?>
<script>
(function(){
  const dossier = <?= (int)$idDossier ?>, csrf = <?= json_encode($csrfChat) ?>;
  const box=document.getElementById('creChatBox'), input=document.getElementById('creChatInput'), btn=document.getElementById('creChatSend');
  function bubble(t,me){const e=document.getElementById('creChatEmpty');if(e)e.remove();const d=document.createElement('div');
    d.style.cssText='align-self:'+(me?'flex-end':'flex-start')+';max-width:80%;background:'+(me?'#243b5c':'#eef1f6')+';color:'+(me?'#fff':'#243b5c')+';padding:8px 12px;border-radius:12px;font-size:12.5px;white-space:pre-line';
    d.textContent=t;box.appendChild(d);box.scrollTop=box.scrollHeight;return d;}
  async function send(){const msg=(input.value||'').trim();if(!msg)return;bubble(msg,true);input.value='';btn.disabled=true;const w=bubble('…',false);
    const fd=new FormData();fd.append('id_dossier',dossier);fd.append('message',msg);fd.append('csrf_token',csrf);
    try{const r=await fetch('api/creancier_chat_post.php',{method:'POST',body:fd});const j=await r.json();w.textContent=j.ok?j.reply:('Erreur : '+(j.error||'échec'));}
    catch(e){w.textContent='Erreur : '+e;}finally{btn.disabled=false;box.scrollTop=box.scrollHeight;}}
  btn.addEventListener('click',send);input.addEventListener('keydown',e=>{if(e.key==='Enter')send();});box.scrollTop=box.scrollHeight;
})();
</script>
<?php if ($isMgr) require __DIR__ . '/inc/fluxbox_upload_modal.php'; ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
