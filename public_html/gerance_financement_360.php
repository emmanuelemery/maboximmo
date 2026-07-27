<?php
/**
 * gerance_financement_360.php — Fiche d'un financement (LOA / LLD / crédit) — module GÉRANCE.
 *
 * Objet MULTI-RATTACHÉ : il porte id_vehicule ET id_societe. Il n'existe qu'une fois ;
 * la fiche véhicule et la card Financements du dashboard groupe l'affichent toutes deux
 * par filtre sur ces FK — jamais par copie.
 *
 * Cycle de vie INDÉPENDANT du véhicule (règle Emmanuel 2026-07-16) : le financement ne
 * s'arrête qu'au remboursement total (vente du véhicule ou fin de contrat). Un
 * financement `actif` sur un véhicule `archive` est un état normal, pas une anomalie.
 *
 * Piloté par le descripteur 'FIN' — aucun attribut ni échéance codés dans cette page.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_once __DIR__ . '/inc/fiche_360_descripteurs.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/gerance_data.php';
require_login();
gerance_require_access();

/** @var PDO $pdo */
$finId = (int)($_GET['id'] ?? 0);
if ($finId <= 0) { header('Location: ' . app_url('/gerance_dashboard.php')); exit; }

$desc = f360_descripteur('FIN');

$st = $pdo->prepare("SELECT f.*, s.nom AS societe_nom,
                            v.immatriculation, v.marque, v.modele, v.statut AS vehicule_statut
                     FROM gerance_financements f
                     LEFT JOIN societes s ON s.id = f.id_societe
                     LEFT JOIN gerance_vehicules v ON v.id = f.id_vehicule
                     WHERE f.id = ? LIMIT 1");
$st->execute([$finId]);
$fin = $st->fetch(PDO::FETCH_ASSOC);
if (!$fin) { http_response_code(404); exit('Financement introuvable.'); }

gerance_require_societe((int)$fin['id_societe']);

$docs = [];
try {
    $docs = gdl_documents_for_entity($pdo, 'FIN', $finId, ['status'=>'active', 'limit'=>50]);
} catch (Throwable $e) {}
$docsByType = [];
foreach ($docs as $d) $docsByType[$d['document_type']] = ($docsByType[$d['document_type']] ?? 0) + 1;

$pi        = f360_pieces_items($desc, $docsByType);
$echeances = f360_echeances($desc, $fin);
$pire      = f360_echeance_pire($echeances);

$statut  = (string)($fin['statut'] ?? 'actif');
$titre   = trim((string)$fin['type'] . ' · ' . (string)($fin['organisme'] ?? '')) ?: ('Financement #' . $finId);
$vehNom  = trim((string)($fin['marque'] ?? '') . ' ' . (string)($fin['modele'] ?? ''));

// Bandeau : le statut du financement prime, l'échéance ensuite.
if ($statut === 'solde') {
    $statusColor = 'green'; $statusIcon = '✅';
    $statusMsg = 'Financement <strong>soldé</strong>'
        . (!empty($fin['date_solde']) ? ' le ' . h(f360_format_attr($fin['date_solde'], 'date')) : '')
        . (!empty($fin['motif_solde']) ? ' — ' . h(str_replace('_', ' ', (string)$fin['motif_solde'])) : '');
} elseif ($statut === 'archive') {
    $statusColor = 'gray'; $statusIcon = '📦'; $statusMsg = 'Financement <strong>archivé</strong>.';
} elseif ($pire['niveau'] === 'expire') {
    $statusColor = 'orange'; $statusIcon = '⏰';
    $statusMsg = 'Contrat <strong>arrivé à terme</strong> (' . h($pire['echeance']['texte']) . ') mais toujours actif — à solder.';
} elseif ($pire['niveau'] === 'alerte') {
    $statusColor = 'orange'; $statusIcon = '⏰';
    $statusMsg = 'Fin de contrat proche — <strong>' . h($pire['echeance']['texte']) . '</strong>.';
} else {
    $statusColor = 'green'; $statusIcon = '✅'; $statusMsg = 'Financement en cours.';
}

$pageTitle    = 'Financement · ' . ($fin['reference'] ?: '#' . $finId);
$pageSubtitle = 'Vue 360° · ' . ($fin['societe_nom'] ?? '');
$extraCss     = fiche360_css() . <<<'CSS'
<style>
.veh-attrs { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.veh-attr-lbl { font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:.04em; }
.veh-attr-val { font-size:13.5px; font-weight:700; color:#2c2a28; margin-top:2px; }
.veh-attr-val.mono { font-family:'DM Mono',monospace; }
.veh-doc { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0ece6; font-size:12px; }
.veh-doc:last-child { border-bottom:none; }
.veh-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:99px; }
</style>
CSS;
include __DIR__ . '/inc/agency_layout_top.php';
?>
<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>
<?php

$chain = [['icon'=>'🏢', 'label'=>(string)($fin['societe_nom'] ?? 'Société'), 'url'=>app_url('/gerance_societe_360.php?id=' . (int)$fin['id_societe'])]];
if (!empty($fin['id_vehicule'])) {
    $chain[] = ['icon'=>'🚗', 'label'=>$vehNom ?: (string)$fin['immatriculation'], 'url'=>app_url('/gerance_vehicule_360.php?id=' . (int)$fin['id_vehicule'])];
}
$chain[] = ['icon'=>$desc['icone'], 'label'=>$titre];
fiche360_breadcrumb($chain, 'Gérance');

$badgeCls = ['actif'=>'actif', 'solde'=>'loue', 'archive'=>'vendu'][$statut] ?? 'actif';
$metas = [];
if (!empty($fin['montant_mensuel'])) $metas[] = ['icon'=>'💶', 'text'=>f360_format_attr($fin['montant_mensuel'], 'euro') . '/mois'];
if (!empty($fin['duree_mois']))      $metas[] = ['icon'=>'📆', 'text'=>(int)$fin['duree_mois'] . ' mois'];
if (!empty($fin['societe_nom']))     $metas[] = ['icon'=>'🏢', 'text'=>(string)$fin['societe_nom']];

fiche360_header($desc['icone'], $titre, ['label'=>ucfirst($statut), 'class'=>$badgeCls],
    (string)($fin['reference'] ?? ''), $metas);

fiche360_status_banner($statusMsg, $statusColor, $statusIcon);
?>

<div class="f360-grid">
  <div>
    <div class="f360-card">
      <h3>💳 Conditions</h3>
      <div class="veh-attrs">
        <?php foreach ($desc['attributs'] as $a): ?>
          <div>
            <div class="veh-attr-lbl"><?= h($a['label']) ?></div>
            <div class="veh-attr-val <?= $a['format'] === 'mono' ? 'mono' : '' ?>">
              <?= h(f360_format_attr($fin[$a['champ']] ?? null, $a['format'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($echeances as $e): ?>
          <div>
            <div class="veh-attr-lbl"><?= h($e['label']) ?></div>
            <div class="veh-attr-val"><?= h($e['date'] ?? '—') ?>
              <span style="font-weight:400;font-size:11px;color:#7a766f;"><?= h($e['texte']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="f360-card">
      <h3>📄 Documents <span class="count"><?= count($docs) ?></span></h3>
      <?php if (!$docs): ?>
        <div class="f360-empty"><div class="em-ico">📭</div>Aucun document rattaché à ce financement.</div>
      <?php else: foreach ($docs as $d): $pl = f360_presence_label(f360_presence_state($d)); ?>
        <div class="veh-doc">
          <span><?= h($pl['icone']) ?></span>
          <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h((string)$d['name_display']) ?></span>
          <span class="veh-badge" style="background:<?= h($pl['color']) ?>1a;color:<?= h($pl['color']) ?>;"><?= h((string)($d['document_type'] ?: $pl['label'])) ?></span>
          <a href="javascript:void(0)" onclick="mvptModalView(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)($d['name_display'] ?? $d['name_file'] ?? ('Doc #'.(int)$d['id']))), ENT_QUOTES) ?>)" style="color:#4878a6;text-decoration:none;font-size:11px;">👁 Voir</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div>
    <?php
    fiche360_checklist('Pièces du financement', $pi['items']);

    $links = [[
        'icon'=>'🏢', 'name'=>(string)($fin['societe_nom'] ?? '—'), 'ref'=>'Société porteuse',
        'url'=>app_url('/gerance_societe_360.php?id=' . (int)$fin['id_societe']),
    ]];
    if (!empty($fin['id_vehicule'])) {
        $links[] = [
            'icon'=>'🚗',
            'name'=>$vehNom ?: (string)$fin['immatriculation'],
            'ref'=>(string)$fin['immatriculation'] . (($fin['vehicule_statut'] ?? '') === 'archive' ? ' · archivé' : ''),
            'url'=>app_url('/gerance_vehicule_360.php?id=' . (int)$fin['id_vehicule']),
        ];
    }
    fiche360_attach('RATTACHEMENTS', $links);

    fiche360_actions_panel('ACTIONS FINANCEMENT', [
        ['icon'=>'💳', 'label'=>'Tous les financements', 'url'=>app_url('/gerance_dashboard.php#financements')],
    ]);
    ?>
  </div>
</div>

<?= fiche360_js() ?>
<?php if (!defined('MVPT_DOC_VIEWER_LOADED')) { define('MVPT_DOC_VIEWER_LOADED', 1); include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; } ?>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
