<?php
/**
 * gerance_vehicule_360.php — Vue 360° d'un véhicule (module GÉRANCE).
 *
 * PREMIÈRE fiche pilotée par le descripteur déclaratif (inc/fiche_360_descripteurs.php) :
 * les attributs affichés, les échéances suivies et les pièces attendues ne sont PAS
 * écrits ici — ils sont lus depuis le descripteur 'VEH'. Le rendu passe par les
 * composants partagés de inc/fiche_360_layout.php. Aucune page « spéciale ».
 *
 * Le financement du véhicule est affiché par LIEN (financements.id_vehicule), jamais
 * par copie. Son cycle de vie est indépendant : archiver ce véhicule ne le termine pas.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/fiche_360_descripteurs.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/gerance_data.php';
require_once __DIR__ . '/inc/gerance_form.php';
require_once __DIR__ . '/inc/mail_button.php';   // mail_compose_url()
require_login();
gerance_require_access();

/** @var PDO $pdo */
$vehId = (int)($_GET['id'] ?? 0);
if ($vehId <= 0) {
    header('Location: ' . app_url('/gerance_dashboard.php'));
    exit;
}

$desc = f360_descripteur('VEH');

// ─── Charge le véhicule + sa société ──
$st = $pdo->prepare("SELECT v.*, s.nom AS societe_nom, s.raison_sociale
                     FROM gerance_vehicules v
                     LEFT JOIN societes s ON s.id = v.id_societe
                     WHERE v.id = ? LIMIT 1");
$st->execute([$vehId]);
$veh = $st->fetch(PDO::FETCH_ASSOC);
if (!$veh) { http_response_code(404); exit('Véhicule introuvable.'); }

// Scope : hors super admin, on ne voit que les véhicules de SA société.
gerance_require_societe((int)$veh['id_societe']);

$isArchive = ($veh['statut'] ?? 'actif') === 'archive';
$titre = trim((string)($veh['marque'] ?? '') . ' ' . (string)($veh['modele'] ?? '')) ?: ('Véhicule #' . $vehId);

// ─── Documents (GED centrale) ──
$docs = [];
try {
    $docs = gdl_documents_for_entity($pdo, 'VEH', $vehId, [
        'status' => 'active', 'limit' => 50, 'order_by' => 'd.created_at DESC',
    ]);
} catch (Throwable $e) { error_log('[vehicule_360] docs #' . $vehId . ' : ' . $e->getMessage()); }
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

// ─── Checklist + échéances : 100 % dérivées du descripteur ──
$pi          = f360_pieces_items($desc, $docsByType);
$piecesItems = $pi['items'];
$echeances   = f360_echeances($desc, $veh);
$pire        = f360_echeance_pire($echeances);
$nbManquant  = count(array_filter($piecesItems, static fn($i) => empty($i['ok'])));

// ─── Financements rattachés (par LIEN, jamais par copie) ──
$fins = [];
try {
    $stF = $pdo->prepare("SELECT * FROM gerance_financements WHERE id_vehicule = ? ORDER BY FIELD(statut,'actif','solde','archive'), date_fin ASC");
    $stF->execute([$vehId]);
    $fins = $stF->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ─── Bandeau de statut : piloté par l'échéance la plus critique ──
if ($isArchive) {
    $statusColor = 'gray'; $statusIcon = '📦';
    $statusMsg = 'Véhicule <strong>archivé</strong>' . ($veh['archive_motif'] ? ' — ' . h((string)$veh['archive_motif']) : '');
} elseif ($pire['niveau'] === 'expire') {
    $statusColor = 'red'; $statusIcon = '⛔';
    $statusMsg = '<strong>' . h($pire['echeance']['label']) . '</strong> dépassé — ' . h($pire['echeance']['texte']);
} elseif ($pire['niveau'] === 'alerte') {
    $statusColor = 'orange'; $statusIcon = '⏰';
    $statusMsg = '<strong>' . h($pire['echeance']['label']) . '</strong> à renouveler — ' . h($pire['echeance']['texte']);
} elseif ($pire['niveau'] === 'inconnu') {
    $statusColor = 'orange'; $statusIcon = 'ℹ️';
    $statusMsg = '<strong>' . h($pire['echeance']['label']) . '</strong> non renseignée.';
} else {
    $statusColor = 'green'; $statusIcon = '✅';
    $statusMsg = 'Échéances à jour.';
}
$statusAlertes = $nbManquant > 0 ? $nbManquant . ' pièce(s) à charger' : '';

$pageTitle    = 'Véhicule · ' . ($veh['immatriculation'] ?: '#' . $vehId);
$pageSubtitle = 'Vue 360° · ' . ($veh['societe_nom'] ?? '');
$extraCss     = fiche360_css() . <<<'CSS'
<style>
/* Échéances — la brique absente des fiches 360 historiques (durées en texte libre). */
.veh-ech { display:flex; gap:10px; flex-wrap:wrap; }
.veh-ech-item { flex:1; min-width:180px; border-radius:10px; padding:10px 12px; background:#fafaf6;
  border-left:4px solid #c8c4be; }
.veh-ech-item.expire { border-left-color:#a8323b; background:#fbe9e9; }
.veh-ech-item.alerte { border-left-color:#d97706; background:#fff7ed; }
.veh-ech-item.ok     { border-left-color:#2d6a35; background:#f2f9f3; }
.veh-ech-lbl  { font-size:10px; color:#7a766f; text-transform:uppercase; letter-spacing:.04em; }
.veh-ech-date { font-size:15px; font-weight:800; color:#2c2a28; margin-top:2px; }
.veh-ech-txt  { font-size:11px; color:#5a5650; margin-top:2px; }
.veh-attrs { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.veh-attr-lbl { font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.04em; }
.veh-attr-val { font-size:13.5px; font-weight:700; color:#2c2a28; margin-top:2px; }
.veh-attr-val.mono { font-family:'DM Mono',monospace; }
.veh-doc { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px; }
.veh-doc:last-child { border-bottom:none; }
.veh-doc-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.veh-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:99px; }
.veh-fin { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid #f0ece6; }
.veh-fin:last-child { border-bottom:none; }
</style>
CSS;
include __DIR__ . '/inc/agency_layout_top.php';
?>
<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>
<?php

// ─── Breadcrumb : Société → Véhicule ──
fiche360_breadcrumb([
    ['icon'=>'🏢', 'label'=>(string)($veh['societe_nom'] ?? 'Société'), 'url'=>app_url('/gerance_societe_360.php?id=' . (int)$veh['id_societe'])],
    ['icon'=>$desc['icone'], 'label'=>$titre],
], 'Gérance');

// ─── Header ──
$metas = [];
if (!empty($veh['date_mise_circulation'])) $metas[] = ['icon'=>'📅', 'text'=>'MEC ' . f360_format_attr($veh['date_mise_circulation'], 'date')];
if (!empty($veh['kilometrage']))           $metas[] = ['icon'=>'🛣', 'text'=>f360_format_attr($veh['kilometrage'], 'km')];
if (!empty($veh['societe_nom']))           $metas[] = ['icon'=>'🏢', 'text'=>(string)$veh['societe_nom']];

fiche360_header(
    $desc['icone'],
    $titre,
    $isArchive ? ['label'=>'Archivé', 'class'=>'vendu'] : ['label'=>'Actif', 'class'=>'actif'],
    (string)($veh['immatriculation'] ?? ''),
    $metas,
    [
        ['label'=>'✏️ Modifier', 'url'=>'#', 'onclick'=>'gfOpen(\'gf-veh-edit\');return false;'],
        ['label'=>'📎 Charger un document', 'url'=>'#', 'onclick'=>'document.getElementById(\'veh-upload\').scrollIntoView({behavior:\'smooth\'});return false;'],
    ]
);

fiche360_status_banner($statusMsg, $statusColor, $statusIcon, $statusAlertes);
?>

<div class="f360-grid">
  <div>
    <!-- Échéances suivies — déclarées au descripteur, calculées par f360_echeances() -->
    <?php if ($echeances): ?>
    <div class="f360-card">
      <h3>⏱️ Échéances suivies</h3>
      <div class="veh-ech">
        <?php foreach ($echeances as $e): ?>
          <div class="veh-ech-item <?= h($e['niveau']) ?>">
            <div class="veh-ech-lbl"><?= h($e['icone'] . ' ' . $e['label']) ?></div>
            <div class="veh-ech-date"><?= h($e['date'] ?? '—') ?></div>
            <div class="veh-ech-txt"><?= h($e['texte']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Attributs de fiche — déclarés au descripteur -->
    <div class="f360-card">
      <h3>🚗 Caractéristiques</h3>
      <div class="veh-attrs">
        <?php foreach ($desc['attributs'] as $a): ?>
          <div>
            <div class="veh-attr-lbl"><?= h($a['label']) ?></div>
            <div class="veh-attr-val <?= $a['format'] === 'mono' ? 'mono' : '' ?>">
              <?= h(f360_format_attr($veh[$a['champ']] ?? null, $a['format'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Financements : par LIEN (id_vehicule), jamais par copie -->
    <div class="f360-card">
      <h3>💳 Financements <span class="count"><?= count($fins) ?></span></h3>
      <div style="font-size:11px;color:#9a9690;margin-bottom:8px;font-style:italic;">
        Un financement n'existe qu'une fois. Il apparaît ici et dans la vue Financements par filtre.
        Archiver ce véhicule ne l'interrompt pas : il court jusqu'au remboursement total.
      </div>
      <?php if (!$fins): ?>
        <div class="f360-empty"><div class="em-ico">💳</div>Aucun financement rattaché.</div>
      <?php else: foreach ($fins as $f):
        $fEch  = f360_echeances(f360_descripteur('FIN'), $f);
        $fPire = f360_echeance_pire($fEch);
        $col   = ['actif'=>'#2d6a35', 'solde'=>'#7a766f', 'archive'=>'#9a9690'][$f['statut']] ?? '#7a766f';
      ?>
        <div class="veh-fin">
          <span style="font-size:16px;">💳</span>
          <div style="flex:1;min-width:0;">
            <a href="<?= h(app_url('/gerance_financement_360.php?id=' . (int)$f['id'])) ?>" style="font-weight:700;font-size:12.5px;color:#2c2a28;text-decoration:none;">
              <?= h($f['type']) ?><?= $f['organisme'] ? ' · ' . h((string)$f['organisme']) : '' ?>
            </a>
            <div style="font-size:11px;color:#7a766f;">
              <?= h(f360_format_attr($f['montant_mensuel'] ?? null, 'euro')) ?>/mois
              <?php if (!empty($f['date_fin'])): ?>
                · fin <?= h(f360_format_attr($f['date_fin'], 'date')) ?>
                <?php if ($fPire['niveau'] === 'alerte' || $fPire['niveau'] === 'expire'): ?>
                  <span style="color:#a8323b;font-weight:700;">(<?= h($fPire['echeance']['texte']) ?>)</span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <span class="veh-badge" style="background:<?= h($col) ?>1a;color:<?= h($col) ?>;"><?= h(strtoupper((string)$f['statut'])) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Documents GED : entity_type=VEH, statut de présence 3 états -->
    <div class="f360-card" id="veh-upload">
      <h3>📄 Documents <span class="count"><?= count($docs) ?></span></h3>
      <?php if (!$docs): ?>
        <div class="f360-empty"><div class="em-ico">📭</div>Aucun document chargé.</div>
      <?php else: foreach ($docs as $d):
        $state = f360_presence_state($d);
        $pl    = f360_presence_label($state);
      ?>
        <div class="veh-doc">
          <span><?= h($pl['icone']) ?></span>
          <span class="veh-doc-name" title="<?= h((string)$d['name_display']) ?>"><?= h((string)$d['name_display']) ?></span>
          <span class="veh-badge" style="background:<?= h($pl['color']) ?>1a;color:<?= h($pl['color']) ?>;"><?= h((string)($d['document_type'] ?: $pl['label'])) ?></span>
          <a href="javascript:void(0)" onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)($d['name_display'] ?? $d['name_file'] ?? ('Doc #'.(int)$d['id']))), ENT_QUOTES) ?>)" style="color:#4878a6;text-decoration:none;font-size:11px;">👁 Voir</a>
        </div>
      <?php endforeach; endif; ?>

      <?php if (!$isArchive): ?>
      <form id="veh-upload-form" style="margin-top:12px;padding-top:12px;border-top:1px solid #f0ece6;display:flex;gap:8px;flex-wrap:wrap;align-items:center;"
            onsubmit="return vehUpload(event)">
        <?= csrf_field('gerance_doc_upload') ?>
        <input type="hidden" name="id_vehicule" value="<?= (int)$vehId ?>">
        <select name="type_piece" style="padding:7px 10px;border:1px solid #e3dfd8;border-radius:8px;font-family:inherit;font-size:12px;">
          <?php foreach ($desc['pieces'] as $p): ?>
            <option value="<?= h((string)($p['fbx_type'] ?? $p['code'])) ?>"><?= h($p['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="file" name="document[]" multiple required style="font-size:12px;flex:1;min-width:180px;">
        <button type="submit" class="tr-btn" style="background:#243B5C;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:12px;cursor:pointer;">Charger</button>
        <span id="veh-upload-msg" style="font-size:11.5px;"></span>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <?php
    fiche360_checklist('Pièces du véhicule', $piecesItems);

    // ─── Dossiers sources OneDrive (composant partagé, déjà utilisé par bien/immeuble/bail/tiers) ──
    // Lier un dossier OneDrive au véhicule → scan récursif → import sélectif typé
    // → gus_commit_document() avec entity_type=VEH. Rien de spécifique au module :
    // ged_source_folders est polymorphe, l'import lit l'entity_type du dossier lié.
    // Inclusion défensive : même pattern que bien_360.php:1137.
    $gsfCardFile = __DIR__ . '/inc/ged_source_folders_card.php';
    if (is_file($gsfCardFile)) { require_once $gsfCardFile;
        if (function_exists('ged_source_folders_card')) { try {
            ged_source_folders_card($pdo, 'VEH', $vehId, [
                'id_societe' => (int)$veh['id_societe'],
                'id_agence'  => (int)($veh['id_agence'] ?? 0),
                'metier'     => 'gerance',   // → active les règles de typage véhicule au scan
            ]);
        } catch (Throwable $e) {} } }

    fiche360_attach('SOCIÉTÉ PROPRIÉTAIRE', [[
        'icon' => '🏢',
        'name' => (string)($veh['societe_nom'] ?? '—'),
        'ref'  => (string)($veh['raison_sociale'] ?? ''),
        'url'  => app_url('/gerance_societe_360.php?id=' . (int)$veh['id_societe']),
    ]]);

    // Panneau Actions. Les 2 premières entrées sont les actions du véhicule, les
    // suivantes la navigation.
    $backVeh = 'gerance_vehicule_360.php?id=' . $vehId;
    $actions = [];
    if (!$isArchive) {
        $actions[] = ['icon'=>'📎', 'label'=>'Charger des documents', 'url'=>'#',
                      'onclick'=>"document.getElementById('veh-upload').scrollIntoView({behavior:'smooth'});"
                               . "document.querySelector('#veh-upload-form input[type=file]').focus();return false;"];
    }
    // Composeur générique (inc/mail_button.php) : contacts + pièces jointes résolus
    // par mail_context('VEH') — les documents GED du véhicule sont joignables.
    $actions[] = ['icon'=>'✉️', 'label'=>'Envoyer par mail',
                  'url'=>mail_compose_url('VEH', $vehId, $backVeh), 'target'=>'_blank'];
    $actions[] = ['icon'=>'✏️', 'label'=>'Modifier la fiche', 'url'=>'#', 'onclick'=>"gfOpen('gf-veh-edit');return false;"];
    $actions[] = ['icon'=>'💳', 'label'=>'Tous les financements', 'url'=>app_url('/gerance_financements.php')];
    $actions[] = ['icon'=>'🚗', 'label'=>'Retour à la flotte',    'url'=>app_url('/gerance_vehicules.php')];
    fiche360_actions_panel('ACTIONS VÉHICULE', $actions);
    ?>
  </div>
</div>

<?php
// Modal d'édition — même moteur, même descripteur que la création. Le statut et le
// motif d'archivage n'apparaissent qu'ici (edit_only), l'archivage étant le seul
// retrait possible : pas de suppression par conception.
gerance_form_modal($desc, [
    'id'     => 'gf-veh-edit',
    'mode'   => 'edit',
    'titre'  => 'Modifier · ' . $titre,
    'url'    => app_url('/api/gerance_vehicule_save.php'),
    'submit' => 'Enregistrer',
    'row'    => $veh,
]);
?>

<script>
async function vehUpload(ev) {
    ev.preventDefault();
    const form = document.getElementById('veh-upload-form');
    const msg  = document.getElementById('veh-upload-msg');
    msg.textContent = '⏳ Envoi…'; msg.style.color = '#7a766f';
    try {
        const res  = await fetch((window.APP_BASE || '') + '/api/gerance_doc_upload.php', { method:'POST', body: new FormData(form) });
        const data = await res.json();
        if (data.ok) {
            const dedup = (data.docs || []).filter(d => d.deduplicated).length;
            msg.style.color = '#2d6a35';
            msg.textContent = '✅ ' + data.n + ' document(s)' + (dedup ? ' · ' + dedup + ' déjà en GED (rattaché)' : '');
            setTimeout(() => location.reload(), 900);
        } else {
            msg.style.color = '#a8323b';
            msg.textContent = '⚠️ ' + (data.error || (data.errors || []).join(' / ') || 'Échec');
        }
    } catch (e) {
        msg.style.color = '#a8323b'; msg.textContent = '⚠️ Erreur réseau';
    }
    return false;
}
</script>

<?= fiche360_js() ?>
<?php if (!defined('MVPT_DOC_VIEWER_LOADED')) { define('MVPT_DOC_VIEWER_LOADED', 1); include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; } ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
