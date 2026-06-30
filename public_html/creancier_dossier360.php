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
require_once __DIR__ . '/inc/tiers_selector.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_once __DIR__ . '/inc/creancier_mouvement.php';
require_once __DIR__ . '/inc/acteur_modal.php';
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
    elseif (str_contains($role, 'debit') || $role === 'groupe') $debiteurs[] = $l;
    elseif ($l['entity_type'] === 'TIERS') $pros[] = $l; // tout autre intervenant = contact (icône selon métier)
}

$sti = $pdo->prepare("SELECT * FROM creancier_dossier_item WHERE id_dossier = ? ORDER BY priorite DESC, date_echeance IS NULL, date_echeance ASC");
$sti->execute([$idDossier]); $items = $sti->fetchAll(PDO::FETCH_ASSOC);

$sta = $pdo->prepare("SELECT id, type_doc, extr_creancier_nom, extr_montant_total, confidence, review_flags FROM creancier_doc_analyse WHERE id_dossier = ? AND statut='a_valider' ORDER BY created_at DESC");
$sta->execute([$idDossier]); $analyses = $sta->fetchAll(PDO::FETCH_ASSOC);

$agenda = creancier_agenda($pdo, [$idDossier], 365);

$stm = $pdo->prepare("SELECT role, message FROM creancier_dossier_message WHERE id_dossier = ? AND canal = 'chat' ORDER BY id ASC LIMIT 100");
$stm->execute([$idDossier]); $messages = $stm->fetchAll(PDO::FETCH_ASSOC);

// Fil d'actualité (commentaires signés des intervenants) — canal 'feed'.
$feed = [];
try {
    $stf = $pdo->prepare("
        SELECT m.id, m.message, m.created_at,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.prenom,u.nom)),''), u.username, 'Intervenant') AS auteur
        FROM creancier_dossier_message m
        LEFT JOIN users u ON u.id = m.id_user
        WHERE m.id_dossier = ? AND m.canal = 'feed'
        ORDER BY m.id DESC LIMIT 200");
    $stf->execute([$idDossier]); $feed = $stf->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $feed = []; }

// Notes PERSONNELLES (privées) de l'utilisateur courant — canal 'note_privee'.
$notesPriv = [];
try {
    $stn = $pdo->prepare("SELECT id, message, created_at FROM creancier_dossier_message
                          WHERE id_dossier = ? AND canal = 'note_privee' AND id_user = ?
                          ORDER BY id DESC LIMIT 200");
    $stn->execute([$idDossier, $userId]); $notesPriv = $stn->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $notesPriv = []; }

// Liens de partage actifs.
$partages = [];
if ($isMgr) {
    try {
        $stp = $pdo->prepare("SELECT * FROM creancier_dossier_partage WHERE id_dossier = ? AND revoked_at IS NULL ORDER BY id DESC");
        $stp->execute([$idDossier]); $partages = $stp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $partages = []; }
}

$csrfChat    = csrf_token('creancier_chat');
$csrfAnalyse = csrf_token('creancier_analyse');
$csrfContact = csrf_token('creancier_contact');
$csrfFeed    = csrf_token('creancier_feed');
$csrfNote    = csrf_token('creancier_note');
$csrfPartage = csrf_token('creancier_partage');
$csrfMouvement = csrf_token('creancier_mouvement');
$csrfItem      = csrf_token('creancier_item');
$csrfFrais     = csrf_token('creancier_frais');

$mvt      = creancier_mouvements($pdo, $idDossier);
$mvtLabels = creancier_mouvement_labels();

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
.cre-2col { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start; }
@media (max-width:1100px){ .cre-2col { grid-template-columns:1fr; } }
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
      <button type="button" class="cre-tab" data-pane="pane-feed">📣 Fil d'actualité <span style="opacity:.6"><?= count($feed) ?></span></button>
      <button type="button" class="cre-tab" data-pane="pane-notes">🔒 Notes perso <span style="opacity:.6"><?= count($notesPriv) ?></span></button>
      <button type="button" class="cre-tab" data-pane="pane-finances">💶 Finances <span style="opacity:.6"><?= count($mvt['rows']) ?></span></button>
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

      <div class="cre-2col">
      <div class="f360-card">
        <h3>📅 Agenda <span class="count"><?= count($agenda) ?></span>
          <button type="button" id="evToggle" class="tr-btn" style="float:right;padding:3px 10px;font-size:11px;">➕ Échéance</button>
        </h3>
        <div id="evForm" style="display:none;background:#f9f8f5;border:1px solid #e6e1d8;border-radius:8px;padding:10px;margin-bottom:10px;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
            <select id="evType" style="padding:7px 9px;border:1px solid #d8d2c8;border-radius:7px;font-size:12.5px;">
              <option value="ECHEANCE">Échéance</option>
              <option value="PROCEDURE">Procédure</option>
              <option value="ACTION">Action à faire</option>
              <option value="DECISION">Décision</option>
            </select>
            <input type="date" id="evDate" style="padding:7px 9px;border:1px solid #d8d2c8;border-radius:7px;font-size:12.5px;">
            <input type="text" id="evTitre" placeholder="Intitulé de l'échéance *" style="grid-column:1/3;padding:7px 9px;border:1px solid #d8d2c8;border-radius:7px;font-size:12.5px;">
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
            <button type="button" id="evAdd" class="tr-btn tr-btn-primary" style="padding:5px 12px;font-size:12px;">Ajouter à l'agenda</button>
            <span id="evMsg" style="font-size:11.5px;"></span>
          </div>
        </div>
        <?php if (!$agenda): ?><div class="f360-empty" id="evEmpty"><div class="em-ico">📅</div>Aucune date.</div><?php endif; ?>
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
      </div><!-- /cre-2col -->

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

    </div>

    <!-- ── ONGLET FIL D'ACTUALITÉ ── -->
    <div class="cre-tabpane" id="pane-feed">
      <div class="f360-card" style="border-left:4px solid #D4A047;">
        <h3>📣 Fil d'actualité du dossier</h3>
        <div style="font-size:11px;color:#9a9690;margin-bottom:10px;">Les intervenants ajoutent ici leurs commentaires (horodatés et signés). Visible dans le partage en lecture seule.</div>
        <div style="display:flex;gap:8px;margin-bottom:14px;">
          <textarea id="feedInput" placeholder="Ajouter un commentaire au fil…" rows="2" style="flex:1;padding:9px 12px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>
          <button type="button" id="feedSend" class="tr-btn tr-btn-primary" style="align-self:flex-end;">Publier</button>
        </div>
        <div id="feedMsg" style="font-size:12px;margin-bottom:8px;"></div>
        <div id="feedList">
          <?php if (!$feed): ?><div class="f360-empty" id="feedEmpty"><div class="em-ico">📣</div>Aucun commentaire. Soyez le premier à alimenter le fil.</div><?php endif; ?>
          <?php foreach ($feed as $f): ?>
            <div style="padding:10px 0;border-bottom:1px solid #f0ece6;">
              <div style="font-size:12px;color:#8a8680;margin-bottom:3px;"><strong style="color:#243B5C;"><?= h($f['auteur']) ?></strong> · <?= h(date('d/m/Y H:i', strtotime((string)$f['created_at']))) ?></div>
              <div style="font-size:13px;white-space:pre-line;color:#3a3830;"><?= h($f['message']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── ONGLET NOTES PERSO (privées) ── -->
    <div class="cre-tabpane" id="pane-notes">
      <div class="f360-card" style="border-left:4px solid #6b7280;">
        <h3>🔒 Mes notes personnelles</h3>
        <div style="font-size:11px;color:#9a9690;margin-bottom:10px;">Privé — visible de vous seul. Jamais partagé, jamais inclus dans le lien public. Pour partager, utilisez le Fil d'actualité.</div>
        <div style="display:flex;gap:8px;margin-bottom:14px;">
          <textarea id="noteInput" placeholder="Note perso : stratégie, rappel, point de vigilance…" rows="2" style="flex:1;padding:9px 12px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>
          <button type="button" id="noteSend" class="tr-btn tr-btn-primary" style="align-self:flex-end;">Noter</button>
        </div>
        <div id="noteMsg" style="font-size:12px;margin-bottom:8px;"></div>
        <div id="noteList">
          <?php if (!$notesPriv): ?><div class="f360-empty" id="noteEmpty"><div class="em-ico">🔒</div>Aucune note. Notez ce que vous voulez, quand vous voulez.</div><?php endif; ?>
          <?php foreach ($notesPriv as $n): ?>
            <div style="padding:10px 0;border-bottom:1px solid #f0ece6;" data-note-id="<?= (int)$n['id'] ?>">
              <div style="font-size:11px;color:#8a8680;margin-bottom:3px;display:flex;justify-content:space-between;">
                <span><?= h(date('d/m/Y H:i', strtotime((string)$n['created_at']))) ?></span>
                <button type="button" class="noteDel" data-id="<?= (int)$n['id'] ?>" style="border:none;background:none;color:#a85858;cursor:pointer;font-size:11px;">supprimer</button>
              </div>
              <div style="font-size:13px;white-space:pre-line;color:#3a3830;"><?= h($n['message']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── ONGLET FINANCES (mouvements) ── -->
    <div class="cre-tabpane" id="pane-finances">
      <?php
        $mvtColors = ['versement_creancier'=>'#dc2626','honoraire_avocat'=>'#7c3aed','frais_huissier'=>'#b45309','frais_procedure'=>'#0e7490','autre'=>'#6b7280'];
      ?>
      <div class="f360-card">
        <h3>💶 Totaux par poste</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap;">
          <?php foreach ($mvtLabels as $k => $lbl): ?>
            <div><div style="font-size:10px;color:#9a9690;text-transform:uppercase;"><?= h($lbl) ?></div>
              <strong style="font-size:17px;color:<?= $mvtColors[$k] ?>;"><?= $eur($mvt['totaux'][$k] ?? 0) ?></strong></div>
          <?php endforeach; ?>
          <div style="border-left:1px solid #e6e1d8;padding-left:18px;"><div style="font-size:10px;color:#9a9690;text-transform:uppercase;">Total décaissé</div>
            <strong style="font-size:17px;color:#243B5C;"><?= $eur($mvt['total_general']) ?></strong></div>
        </div>
      </div>

      <div class="f360-card">
        <h3>➕ Ajouter un mouvement</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <select id="mvType" style="padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
            <?php foreach ($mvtLabels as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
          </select>
          <input type="text" id="mvMontant" inputmode="decimal" placeholder="Montant € *" style="padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
          <input type="date" id="mvDate" style="padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
          <select id="mvMode" style="padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
            <option value="">Mode…</option><option value="virement">Virement</option><option value="cheque">Chèque</option>
            <option value="prelevement">Prélèvement</option><option value="especes">Espèces</option><option value="autre">Autre</option>
          </select>
          <input type="text" id="mvRef" placeholder="Référence / n° pièce" style="grid-column:1/2;padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
          <input type="text" id="mvNote" placeholder="Note (ex. bénéficiaire)" style="grid-column:2/3;padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
          <button type="button" id="mvAdd" class="tr-btn tr-btn-primary">Enregistrer</button>
          <span id="mvMsg" style="font-size:12px;"></span>
        </div>
        <div style="font-size:11px;color:#9a9690;margin-top:6px;">💡 Honoraires avocat & frais à reporter depuis les décomptes huissier / jugements (extraction IA à venir).</div>
      </div>

      <div class="f360-card" style="border-left:4px solid #eab308;">
        <h3>🧠 Extraire les frais d'un décompte / jugement (IA)</h3>
        <div style="font-size:11px;color:#9a9690;margin-bottom:8px;">Dépose un décompte huissier, une facture d'avocat ou un jugement (PDF) : l'IA propose les lignes de frais, tu coches celles à enregistrer.</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="file" id="fraisFile" accept="application/pdf" style="font-size:12.5px;">
          <button type="button" id="fraisAnalyse" class="tr-btn tr-btn-primary">Analyser</button>
          <span id="fraisMsg" style="font-size:12px;"></span>
        </div>
        <div id="fraisResult" style="margin-top:10px;"></div>
      </div>

      <div class="f360-card">
        <h3>📒 Mouvements <span class="count"><?= count($mvt['rows']) ?></span></h3>
        <div id="mvList">
          <?php if (!$mvt['rows']): ?><div class="f360-empty" id="mvEmpty"><div class="em-ico">💶</div>Aucun mouvement enregistré.</div><?php endif; ?>
          <?php foreach ($mvt['rows'] as $m): ?>
            <div class="mv-row" data-mid="<?= (int)$m['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">
              <span>
                <span style="background:<?= $mvtColors[$m['type']] ?? '#6b7280' ?>1a;color:<?= $mvtColors[$m['type']] ?? '#6b7280' ?>;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;"><?= h($mvtLabels[$m['type']] ?? $m['type']) ?></span>
                <?= $m['date_mouvement'] ? ' · ' . $dfr($m['date_mouvement']) : '' ?>
                <?= $m['beneficiaire'] ? ' · ' . h($m['beneficiaire']) : '' ?>
                <?= $m['reference'] ? ' · <span style="color:#9a9690;">' . h($m['reference']) . '</span>' : '' ?>
                <?= $m['note'] ? ' · <span style="color:#9a9690;">' . h($m['note']) . '</span>' : '' ?>
              </span>
              <span style="display:flex;align-items:center;gap:8px;">
                <strong style="color:<?= $mvtColors[$m['type']] ?? '#243B5C' ?>;"><?= $eur($m['montant']) ?></strong>
                <?php if ($isMgr): ?><button type="button" class="tr-btn" style="padding:2px 7px;color:#b91c1c;" onclick="mvDelete(<?= (int)$m['id'] ?>)">✕</button><?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
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

    // Icône selon le métier (rôle) du contact.
    $roleIcon = static function (string $role): string {
        $role = strtolower($role);
        return [
            'creancier'           => '🏦',
            'avocat'              => '👔',
            'commissaire_justice' => '📜',
            'notaire'             => '⚖️',
            'expert_comptable'    => '🧮',
            'conseil'             => '💡',
            'gerant'              => '🧑‍💼',
            'gestionnaire'        => '🏢',
            'heritier'            => '👪',
            'associe'             => '🤝',
            'contact'             => '👤',
        ][$role] ?? '👤';
    };
    // Le nom du tiers contient parfois déjà le métier entre parenthèses (ex.
    // « Pouderoux (avocat) ») : on le retire car l'icône porte désormais le métier.
    $stripRole = static function (?string $name): string {
        return trim(preg_replace(
            '/\s*\((?:avocat|expert[\- _]?comptable|g[eé]rant|gestionnaire|notaire|cr[eé]ancier|huissier|commissaire[^)]*|associ[eé]|h[eé]ritier|contact)\)\s*$/iu',
            '', (string)$name) ?? (string)$name);
    };

    // Créanciers (banques / Trésor public…) — panneau dédié, icône métier.
    $creLinks = [];
    foreach ($creanciers as $c) $creLinks[] = ['icon'=>$roleIcon('creancier'),'name'=>$stripRole($c['tiers_lib']),'ref'=>($c['tiers_email'] ?: $c['tiers_tel'] ?: 'créancier'),'url'=>$base.'creancier_creancier360.php?id='.(int)$c['entity_id']];
    if ($creLinks) fiche360_attach('CRÉANCIERS', $creLinks);

    // Contacts (intervenants) — icône selon le métier + bouton « + » dans l'en-tête.
    // Le métier n'est plus écrit (porté par l'icône) → plus de doublon.
    $coLinks = [];
    foreach ($pros as $p) {
        $r = (string)$p['role_dossier'];
        $coLinks[] = ['icon'=>$roleIcon($r),'name'=>$stripRole($p['tiers_lib']),'ref'=>($p['tiers_email'] ?: $p['tiers_tel'] ?: ''),'url'=>$base.'tiers_360.php?id='.(int)$p['entity_id']];
    }
    $addBtn = $isMgr ? acteur_modal_button('cre_acteur', 'Ajouter un acteur (avocat, huissier, créancier…)') : '';
    fiche360_attach('CONTACTS', $coLinks, $addBtn);
    ?>

    <?php if ($isMgr): ?>
    <?php
      acteur_modal_render([
        'id'         => 'cre_acteur',
        'title'      => '➕ Ajouter un acteur',
        'role_label' => 'Rôle dans le dossier',
        'roles'      => [
            'creancier'           => 'Créancier',
            'avocat'              => 'Avocat',
            'commissaire_justice' => 'Commissaire de justice',
            'expert_comptable'    => 'Expert-comptable',
            'conseil'             => 'Conseil',
            'notaire'             => 'Notaire',
            'gerant'              => 'Gérant',
            'gestionnaire'        => 'Gestionnaire (régie)',
            'heritier'            => 'Héritier',
            'associe'             => 'Associé',
            'contact'             => 'Contact',
        ],
        'api_add'    => app_url('/api/creancier_contact_add.php'),
        'entity'     => ['id_dossier' => $idDossier],
        'role_field' => 'role_dossier',
        'tiers_field'=> 'id_tiers',
        'csrf'       => $csrfContact,
      ]);
    ?>
    <?php endif; ?>

    <?php if ($isMgr): ?>
    <div class="f360-card" style="border-left:4px solid #16a34a;margin-top:12px;">
      <h3>🔗 Partager le dossier (lecture seule)</h3>
      <div style="font-size:11px;color:#9a9690;margin-bottom:8px;">Génère un lien public read-only (synthèse, agenda, montants, acteurs, fil, liste des pièces). Révocable.</div>
      <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
        <select id="partExpire" style="flex:1;min-width:120px;padding:8px 10px;border:1px solid #d8d2c8;border-radius:8px;font-size:13px;">
          <option value="0">Sans expiration</option>
          <option value="7">Expire dans 7 j</option>
          <option value="30" selected>Expire dans 30 j</option>
          <option value="90">Expire dans 90 j</option>
        </select>
        <button type="button" id="partCreate" class="tr-btn tr-btn-primary">Créer un lien</button>
      </div>
      <div id="partMsg" style="font-size:12px;margin-bottom:8px;"></div>
      <div id="partList">
        <?php foreach ($partages as $pg):
            $purl = rtrim($base, '/') . '/creancier_partage.php?t=' . $pg['token'];
        ?>
          <div class="part-row" data-pid="<?= (int)$pg['id'] ?>" style="display:flex;gap:6px;align-items:center;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12px;">
            <input type="text" readonly value="<?= h($purl) ?>" onclick="this.select()" style="flex:1;padding:5px 8px;border:1px solid #e2ddd3;border-radius:6px;font-size:11px;color:#3a3830;">
            <button type="button" class="tr-btn" style="padding:4px 8px;" onclick="partCopy(this)">📋</button>
            <button type="button" class="tr-btn" style="padding:4px 8px;color:#b91c1c;" onclick="partRevoke(<?= (int)$pg['id'] ?>)">✕</button>
          </div>
          <div style="font-size:10px;color:#9a9690;margin:-2px 0 6px;"><?= $pg['expires_at'] ? 'expire le ' . $dfr($pg['expires_at']) : 'sans expiration' ?> · <?= (int)$pg['nb_vues'] ?> vue(s)</div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

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
  function creActivate(pane){
    const btn=document.querySelector('.cre-tab[data-pane="'+pane+'"]'); const el=document.getElementById(pane);
    if(!btn||!el) return;
    document.querySelectorAll('.cre-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.cre-tabpane').forEach(x=>x.classList.remove('active'));
    btn.classList.add('active'); el.classList.add('active');
  }
  document.querySelectorAll('.cre-tab').forEach(function(t){
    t.addEventListener('click', function(){ creActivate(t.dataset.pane); });
  });
  // Restaure l'onglet après un rechargement (ex. ajout d'un mouvement).
  try { const keep=sessionStorage.getItem('creTab'); if(keep){ sessionStorage.removeItem('creTab'); creActivate(keep); } } catch(e){}

  // Chat
  const dossier=<?= (int)$idDossier ?>, csrf=<?= json_encode($csrfChat) ?>;
  const box=document.getElementById('creChatBox'), input=document.getElementById('creChatInput'), btn=document.getElementById('creChatSend');
  function bubble(t,me){const e=document.getElementById('creChatEmpty');if(e)e.remove();const d=document.createElement('div');d.className='cre-bub '+(me?'me':'ia');d.textContent=t;box.appendChild(d);box.scrollTop=box.scrollHeight;return d;}
  async function send(){const msg=(input.value||'').trim();if(!msg)return;bubble(msg,true);input.value='';btn.disabled=true;const w=bubble('…',false);
    const fd=new FormData();fd.append('id_dossier',dossier);fd.append('message',msg);fd.append('csrf_token',csrf);
    try{const r=await fetch('api/creancier_chat_post.php',{method:'POST',body:fd});const j=await r.json();w.textContent=j.ok?j.reply:('Erreur : '+(j.error||'échec'));}
    catch(e){w.textContent='Erreur : '+e;}finally{btn.disabled=false;box.scrollTop=box.scrollHeight;}}
  btn.addEventListener('click',send);input.addEventListener('keydown',e=>{if(e.key==='Enter')send();});box.scrollTop=box.scrollHeight;

  // (Ajout d'acteur : géré par le composant réutilisable inc/acteur_modal.php)

  // Fil d'actualité
  const feedBtn=document.getElementById('feedSend'), feedIn=document.getElementById('feedInput');
  if(feedBtn) feedBtn.addEventListener('click', async function(){
    const msg=(feedIn.value||'').trim(); const out=document.getElementById('feedMsg');
    if(!msg){ out.style.color='#dc2626'; out.textContent='Écris un commentaire.'; return; }
    feedBtn.disabled=true; out.style.color='#5b6470'; out.textContent='Publication…';
    const fd=new FormData(); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('message',msg); fd.append('csrf_token',<?= json_encode($csrfFeed) ?>);
    try{
      const r=await fetch('api/creancier_feed_post.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){
        const empty=document.getElementById('feedEmpty'); if(empty)empty.remove();
        const list=document.getElementById('feedList');
        const esc=s=>String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
        const div=document.createElement('div'); div.style.cssText='padding:10px 0;border-bottom:1px solid #f0ece6;';
        div.innerHTML='<div style="font-size:12px;color:#8a8680;margin-bottom:3px;"><strong style="color:#243B5C;">'+esc(j.auteur)+'</strong> · '+esc(j.date)+'</div>'
          +'<div style="font-size:13px;white-space:pre-line;color:#3a3830;">'+esc(j.message)+'</div>';
        list.insertBefore(div, list.firstChild);
        feedIn.value=''; out.textContent='';
      } else { out.style.color='#dc2626'; out.textContent='✗ '+(j.error||'échec'); }
    }catch(e){ out.style.color='#dc2626'; out.textContent='✗ '+e; }
    finally{ feedBtn.disabled=false; }
  });

  // Notes personnelles (privées)
  const noteCsrf=<?= json_encode($csrfNote) ?>;
  const noteEsc=s=>String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  const noteBtn=document.getElementById('noteSend'), noteIn=document.getElementById('noteInput');
  if(noteBtn) noteBtn.addEventListener('click', async function(){
    const msg=(noteIn.value||'').trim(); const out=document.getElementById('noteMsg');
    if(!msg){ out.style.color='#dc2626'; out.textContent='Écris une note.'; return; }
    noteBtn.disabled=true; out.style.color='#5b6470'; out.textContent='Enregistrement…';
    const fd=new FormData(); fd.append('action','add'); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('message',msg); fd.append('csrf_token',noteCsrf);
    try{
      const r=await fetch('api/creancier_note_post.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){
        const empty=document.getElementById('noteEmpty'); if(empty)empty.remove();
        const list=document.getElementById('noteList');
        const div=document.createElement('div'); div.style.cssText='padding:10px 0;border-bottom:1px solid #f0ece6;'; div.setAttribute('data-note-id',j.id);
        div.innerHTML='<div style="font-size:11px;color:#8a8680;margin-bottom:3px;display:flex;justify-content:space-between;"><span>'+noteEsc(j.date)+'</span>'
          +'<button type="button" class="noteDel" data-id="'+j.id+'" style="border:none;background:none;color:#a85858;cursor:pointer;font-size:11px;">supprimer</button></div>'
          +'<div style="font-size:13px;white-space:pre-line;color:#3a3830;">'+noteEsc(j.message)+'</div>';
        list.insertBefore(div, list.firstChild);
        noteIn.value=''; out.textContent='';
      } else { out.style.color='#dc2626'; out.textContent='✗ '+(j.error||'échec'); }
    }catch(e){ out.style.color='#dc2626'; out.textContent='✗ '+e; }
    finally{ noteBtn.disabled=false; }
  });
  const noteListEl=document.getElementById('noteList');
  if(noteListEl) noteListEl.addEventListener('click', async function(e){
    const del=e.target.closest('.noteDel'); if(!del) return;
    if(!confirm('Supprimer cette note ?')) return;
    const fd=new FormData(); fd.append('action','delete'); fd.append('note_id',del.dataset.id); fd.append('csrf_token',noteCsrf);
    try{ const r=await fetch('api/creancier_note_post.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ const row=del.closest('[data-note-id]'); if(row) row.remove(); } } catch(e){}
  });

  // Partage (managers)
  const partBtn=document.getElementById('partCreate');
  if(partBtn) partBtn.addEventListener('click', async function(){
    const out=document.getElementById('partMsg'); const days=document.getElementById('partExpire').value;
    partBtn.disabled=true; out.style.color='#5b6470'; out.textContent='Création…';
    const fd=new FormData(); fd.append('action','create'); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('expires_days',days); fd.append('csrf_token',<?= json_encode($csrfPartage) ?>);
    try{
      const r=await fetch('api/creancier_partage_action.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ out.style.color='#166534'; out.textContent='✓ Lien créé.'; location.reload(); }
      else { out.style.color='#dc2626'; out.textContent='✗ '+(j.error||'échec'); partBtn.disabled=false; }
    }catch(e){ out.style.color='#dc2626'; out.textContent='✗ '+e; partBtn.disabled=false; }
  });
  window.partCopy=function(btn){ const inp=btn.parentElement.querySelector('input'); inp.select(); try{document.execCommand('copy'); btn.textContent='✓';}catch(e){} setTimeout(()=>btn.textContent='📋',1200); };
  window.partRevoke=async function(pid){
    if(!confirm('Révoquer ce lien de partage ? Il ne sera plus accessible.')) return;
    const fd=new FormData(); fd.append('action','revoke'); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('partage_id',pid); fd.append('csrf_token',<?= json_encode($csrfPartage) ?>);
    try{ const r=await fetch('api/creancier_partage_action.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ const row=document.querySelector('.part-row[data-pid="'+pid+'"]'); if(row){ row.nextElementSibling&&row.nextElementSibling.remove(); row.remove(); } }
      else alert('✗ '+(j.error||'échec')); }
    catch(e){ alert('✗ '+e); }
  };

  // Événements → agenda
  const evTgl=document.getElementById('evToggle'), evForm=document.getElementById('evForm');
  if(evTgl) evTgl.addEventListener('click',function(){ evForm.style.display = evForm.style.display==='none' ? 'block':'none'; });
  const evBtn=document.getElementById('evAdd');
  if(evBtn) evBtn.addEventListener('click', async function(){
    const out=document.getElementById('evMsg');
    const titre=(document.getElementById('evTitre').value||'').trim();
    if(!titre){ out.style.color='#dc2626'; out.textContent='Intitulé requis.'; return; }
    evBtn.disabled=true; out.style.color='#5b6470'; out.textContent='Ajout…';
    const fd=new FormData();
    fd.append('action','add'); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('csrf_token',<?= json_encode($csrfItem) ?>);
    fd.append('type',document.getElementById('evType').value);
    fd.append('titre',titre);
    fd.append('date_echeance',document.getElementById('evDate').value);
    try{
      const r=await fetch('api/creancier_item_action.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ out.style.color='#166534'; out.textContent='✓ Ajouté.'; location.reload(); }
      else { out.style.color='#dc2626'; out.textContent='✗ '+(j.error||'échec'); evBtn.disabled=false; }
    }catch(e){ out.style.color='#dc2626'; out.textContent='✗ '+e; evBtn.disabled=false; }
  });

  // Mouvements financiers
  const mvBtn=document.getElementById('mvAdd');
  if(mvBtn) mvBtn.addEventListener('click', async function(){
    const out=document.getElementById('mvMsg');
    const montant=(document.getElementById('mvMontant').value||'').trim();
    if(!montant){ out.style.color='#dc2626'; out.textContent='Montant requis.'; return; }
    mvBtn.disabled=true; out.style.color='#5b6470'; out.textContent='Enregistrement…';
    const fd=new FormData();
    fd.append('action','add'); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('csrf_token',<?= json_encode($csrfMouvement) ?>);
    fd.append('type',document.getElementById('mvType').value);
    fd.append('montant',montant);
    fd.append('date_mouvement',document.getElementById('mvDate').value);
    fd.append('mode',document.getElementById('mvMode').value);
    fd.append('reference',document.getElementById('mvRef').value);
    fd.append('note',document.getElementById('mvNote').value);
    try{
      const r=await fetch('api/creancier_mouvement_action.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ out.style.color='#166534'; out.textContent='✓ Enregistré.'; sessionStorage.setItem('creTab','pane-finances'); location.reload(); }
      else { out.style.color='#dc2626'; out.textContent='✗ '+(j.error||'échec'); mvBtn.disabled=false; }
    }catch(e){ out.style.color='#dc2626'; out.textContent='✗ '+e; mvBtn.disabled=false; }
  });
  window.mvDelete=async function(mid){
    if(!confirm('Supprimer ce mouvement ?')) return;
    const fd=new FormData(); fd.append('action','delete'); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('mouvement_id',mid); fd.append('csrf_token',<?= json_encode($csrfMouvement) ?>);
    try{ const r=await fetch('api/creancier_mouvement_action.php',{method:'POST',body:fd}); const j=await r.json();
      if(j.ok){ sessionStorage.setItem('creTab','pane-finances'); location.reload(); } else alert('✗ '+(j.error||'échec')); }
    catch(e){ alert('✗ '+e); }
  };

  // Extraction IA des frais (PDF → propositions → ajout en lot)
  const MVLBL={versement_creancier:'Versement créancier',honoraire_avocat:'Honoraires avocat',frais_huissier:'Frais huissier',frais_procedure:'Frais procédure',autre:'Autre'};
  const fraisBtn=document.getElementById('fraisAnalyse');
  if(fraisBtn) fraisBtn.addEventListener('click', async function(){
    const f=document.getElementById('fraisFile').files[0]; const out=document.getElementById('fraisMsg'); const res=document.getElementById('fraisResult');
    if(!f){ out.style.color='#dc2626'; out.textContent='Choisis un PDF.'; return; }
    fraisBtn.disabled=true; out.style.color='#5b6470'; out.textContent='🧠 Analyse IA… (10-20s)'; res.innerHTML='';
    const fd=new FormData(); fd.append('doc',f); fd.append('id_dossier',<?= (int)$idDossier ?>); fd.append('csrf_token',<?= json_encode($csrfFrais) ?>);
    try{
      const r=await fetch('api/creancier_frais_extract.php',{method:'POST',body:fd}); const j=await r.json();
      if(!j.ok){ out.style.color='#dc2626'; out.textContent='✗ '+(j.error||'échec'); fraisBtn.disabled=false; return; }
      out.style.color='#166534'; out.textContent='✓ '+j.count+' ligne(s) · ~'+(j.cost_eur||0)+' €';
      if(!j.count){ res.innerHTML='<div style="color:#9a9690;font-size:12.5px;padding:8px 0;">Aucune ligne financière détectée.</div>'; fraisBtn.disabled=false; return; }
      const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
      let html='<div style="font-size:11.5px;color:#9a9690;margin-bottom:6px;">Coche les lignes à enregistrer, ajuste le type si besoin :</div>';
      j.lignes.forEach(function(l,i){
        let opts=''; Object.keys(MVLBL).forEach(function(k){ opts+='<option value="'+k+'"'+(k===l.type?' selected':'')+'>'+MVLBL[k]+'</option>'; });
        html+='<div class="frais-line" data-i="'+i+'" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0ece6;font-size:12.5px;">'
          +'<input type="checkbox" class="frais-cb" checked>'
          +'<select class="frais-type" style="padding:4px 6px;border:1px solid #d8d2c8;border-radius:6px;font-size:11.5px;">'+opts+'</select>'
          +'<span style="flex:1;">'+esc(l.libelle||'—')+(l.date?(' · '+esc(l.date)):'')+(l.beneficiaire?(' · '+esc(l.beneficiaire)):'')+(l.reference?(' · '+esc(l.reference)):'')+'</span>'
          +'<strong>'+Number(l.montant).toLocaleString('fr-FR')+' €</strong></div>';
      });
      html+='<div style="margin-top:10px;"><button type="button" id="fraisCommit" class="tr-btn tr-btn-primary">➕ Ajouter les lignes cochées</button> <span id="fraisCommitMsg" style="font-size:12px;"></span></div>';
      res.innerHTML=html;
      window.__fraisLignes=j.lignes;
      document.getElementById('fraisCommit').addEventListener('click', async function(){
        const cm=document.getElementById('fraisCommitMsg'); const rows=res.querySelectorAll('.frais-line'); const out=[];
        rows.forEach(function(row){ const cb=row.querySelector('.frais-cb'); if(!cb.checked) return;
          const i=parseInt(row.dataset.i,10); const l=Object.assign({},window.__fraisLignes[i]); l.type=row.querySelector('.frais-type').value; out.push(l); });
        if(!out.length){ cm.style.color='#dc2626'; cm.textContent='Coche au moins une ligne.'; return; }
        this.disabled=true; cm.style.color='#5b6470'; cm.textContent='Enregistrement…';
        const fd2=new FormData(); fd2.append('action','add_bulk'); fd2.append('id_dossier',<?= (int)$idDossier ?>); fd2.append('csrf_token',<?= json_encode($csrfMouvement) ?>); fd2.append('lignes',JSON.stringify(out));
        try{ const r2=await fetch('api/creancier_mouvement_action.php',{method:'POST',body:fd2}); const j2=await r2.json();
          if(j2.ok){ cm.style.color='#166534'; cm.textContent='✓ '+j2.inserted+' ajouté(s).'; sessionStorage.setItem('creTab','pane-finances'); location.reload(); }
          else { cm.style.color='#dc2626'; cm.textContent='✗ '+(j2.error||'échec'); }
        }catch(e){ cm.style.color='#dc2626'; cm.textContent='✗ '+e; }
      });
    }catch(e){ out.style.color='#dc2626'; out.textContent='✗ '+e; }
    finally{ fraisBtn.disabled=false; }
  });

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
<?php if ($isMgr) { tiers_selector_assets(); require __DIR__ . '/inc/fluxbox_upload_modal.php'; } ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
