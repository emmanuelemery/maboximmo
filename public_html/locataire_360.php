<?php
/**
 * locataire_360.php — Fiche 360° (module Bailleur), présentation fiche_360_layout
 * Propriétaire + Immeuble + Bien + Bail + Documents (GED) + Annonces + Historique CRG
 * Params GET : id_bien, id_proprietaire, loc (locataire_nom)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); exit('Accès réservé au module Bailleur.');
}

$idBien = (int)($_GET['id_bien'] ?? 0);
$idProp = (int)($_GET['id_proprietaire'] ?? 0);
$locNom = trim((string)($_GET['loc'] ?? ''));
if ($idBien <= 0 || $idProp <= 0) { http_response_code(400); exit('Paramètres manquants.'); }

if (!$isSuperAdmin) {
    $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
    $chk->execute([$userId, $idProp]);
    if (!$chk->fetchColumn()) { http_response_code(403); exit('Propriétaire hors périmètre.'); }
}

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
function eur($v): string { return ($v === null || (float)$v == 0) ? '—' : '€'.number_format((float)$v, 2, ',', ' '); }
function dfr($d): string { return $d ? date('d/m/Y', strtotime((string)$d)) : '—'; }
function vv($x): string { return ($x === null || $x === '') ? '—' : h($x); }

// ── Données ──
$st = $pdo->prepare("SELECT b.*, i.id AS imm_id, i.nom_immeuble, i.adresse_1 AS imm_adr, i.code_postal AS imm_cp,
        i.ville AS imm_ville, i.vendu AS imm_vendu, i.date_vente
    FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ? LIMIT 1");
$st->execute([$idBien]);
$bien = $st->fetch(\PDO::FETCH_ASSOC) ?: [];

$st = $pdo->prepare("SELECT * FROM proprietaires WHERE id = ? LIMIT 1");
$st->execute([$idProp]);
$prop = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
$propNom = trim((string)($prop['societe'] ?: trim(($prop['civilite']??'').' '.($prop['prenom']??'').' '.($prop['nom']??''))));

$st = $pdo->prepare("SELECT * FROM baux WHERE id_bien=? AND id_proprietaire=?
    ORDER BY (locataire_nom LIKE ?) DESC, (statut='actif') DESC, id DESC LIMIT 1");
$st->execute([$idBien, $idProp, '%'.$locNom.'%']);
$bail = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
$loyerMois = (float)($bail['loyer'] ?? 0);

$docs = [];
try {
    require_once __DIR__ . '/inc/ged_document_links.php';
    if (function_exists('gdl_documents_for_entity')) $docs = gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['status'=>'active','limit'=>50]);
} catch (Throwable $e) {}

$annonces = [];
try {
    $st = $pdo->prepare("SELECT id, type_transaction, statut, etat_publication, titre, prix, date_creation FROM annonces WHERE id_bien=? ORDER BY date_creation DESC");
    $st->execute([$idBien]); $annonces = $st->fetchAll(\PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$crg = [];
if ($locNom !== '') {
    $st = $pdo->prepare("SELECT ct.annee, ct.trimestre, crg.loyer_appele, crg.total_regle, crg.total_impaye
        FROM crg_situations_locataires crg JOIN crg_trimestres ct ON crg.id_crg=ct.id
        WHERE crg.id_bien=? AND crg.locataire_nom=? AND ct.id_proprietaire=? ORDER BY ct.annee, ct.trimestre");
    $st->execute([$idBien, $locNom, $idProp]); $crg = $st->fetchAll(\PDO::FETCH_ASSOC);
}
$cumulRegle  = array_sum(array_column($crg, 'total_regle'));
$soldeImpaye = $crg ? (float)end($crg)['total_impaye'] : 0;
$surfAff = ($bien['surface_carrez'] ?? 0) ?: ($bien['surface_habitable'] ?? 0);

// Badge statut
if ((int)($bien['imm_vendu'] ?? 0) === 1) { $badge = ['label'=>'Immeuble vendu','class'=>'vendu']; }
elseif ($loyerMois > 0)                   { $badge = ['label'=>'Loué','class'=>'loue']; }
else                                       { $badge = ['label'=>'Vacant','class'=>'vacant']; }

$pageTitle     = ($locNom !== '' ? 'Locataire · '.$locNom : 'Bien · '.($bien['reference_bien'] ?? ''));
$pageSubtitle  = 'Fiche 360°';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_patrimoine_actif';
$extraCss = fiche360_css();

require_once __DIR__ . '/inc/agency_layout_top.php';

// Breadcrumb : Propriétaire → Immeuble → Bien → Locataire
$chain = [
    ['icon'=>'👤','label'=>$propNom ?: 'Propriétaire','url'=>app_url('/bailleur_patrimoine_actif.php?props[]='.$idProp)],
];
if (!empty($bien['imm_id'])) $chain[] = ['icon'=>'🏢','label'=>($bien['nom_immeuble'] ?: $bien['imm_adr']),'url'=>app_url('/immeuble_360.php?id='.(int)$bien['imm_id'])];
$chain[] = ['icon'=>'🏠','label'=>($bien['reference_bien'] ?? 'Bien'),'url'=>app_url('/bien_360.php?id='.$idBien)];
if ($locNom !== '') $chain[] = ['icon'=>'🔑','label'=>$locNom,'url'=>null];
fiche360_breadcrumb($chain, 'Patrimoine');

// Header
$metas = [];
if ($surfAff) $metas[] = ['icon'=>'📐','text'=>number_format((float)$surfAff,2,',',' ').' m²'];
if (!empty($bien['nb_pieces'])) $metas[] = ['icon'=>'🚪','text'=>$bien['nb_pieces'].' pièces'];
if ($loyerMois > 0) $metas[] = ['icon'=>'💶','text'=>eur($loyerMois).' /mois'];
fiche360_header('🔑', $locNom !== '' ? $locNom : ($bien['reference_bien'] ?? 'Bien'), $badge,
    trim((string)($bien['imm_adr'] ?? '').' '.($bien['imm_cp'] ?? '').' '.($bien['imm_ville'] ?? '')), $metas,
    [['label'=>'← Patrimoine','url'=>app_url('/bailleur_patrimoine_actif.php'),'class'=>'tr-btn']]);
?>

<div class="f360-grid">
  <!-- COLONNE PRINCIPALE -->
  <div>
    <div class="f360-card">
      <h3>📋 Bail</h3>
      <?php if ($bail): ?>
      <table class="t" style="width:100%;border-collapse:collapse;font-size:12.5px">
        <tr><td style="color:#7a766f;padding:4px 8px">Locataire</td><td style="padding:4px 8px"><strong><?=vv($bail['locataire_nom']??$locNom)?></strong></td>
            <td style="color:#7a766f;padding:4px 8px">Type</td><td style="padding:4px 8px"><?=vv($bail['type_bail']??'')?></td></tr>
        <tr><td style="color:#7a766f;padding:4px 8px">Entrée</td><td style="padding:4px 8px"><?=dfr($bail['date_debut']??null)?></td>
            <td style="color:#7a766f;padding:4px 8px">Fin</td><td style="padding:4px 8px"><?=dfr($bail['date_fin']??null)?></td></tr>
        <tr><td style="color:#7a766f;padding:4px 8px">Loyer /mois</td><td style="padding:4px 8px"><strong><?=eur($bail['loyer']??0)?></strong></td>
            <td style="color:#7a766f;padding:4px 8px">Loyer /an</td><td style="padding:4px 8px"><?=eur($loyerMois>0?$loyerMois*12:0)?></td></tr>
        <tr><td style="color:#7a766f;padding:4px 8px">Charges /an</td><td style="padding:4px 8px"><?=eur($bail['charges']??0)?></td>
            <td style="color:#7a766f;padding:4px 8px">Dépôt</td><td style="padding:4px 8px"><?=eur($bail['depot_garantie']??0)?></td></tr>
        <tr><td style="color:#7a766f;padding:4px 8px">Téléphone</td><td style="padding:4px 8px"><?=vv($bail['locataire_telephone']??'')?></td>
            <td style="color:#7a766f;padding:4px 8px">Email</td><td style="padding:4px 8px"><?=vv($bail['locataire_email']??'')?></td></tr>
      </table>
      <?php else: ?><div class="f360-empty"><div class="em-ico">📋</div>Aucun bail enregistré</div><?php endif; ?>
    </div>

    <div class="f360-card">
      <h3>📁 Documents <span class="count"><?=count($docs)?></span></h3>
      <?php if ($docs): ?>
      <table class="t" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="color:#9a9690;text-align:left"><th style="padding:5px 8px">Document</th><th style="padding:5px 8px">Type</th><th style="padding:5px 8px">Date</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $d): ?>
          <?php $lnm = (string)($d['name_display'] ?? $d['name_file'] ?? '—'); ?>
          <tr style="border-top:1px solid #f0ece6"><td style="padding:5px 8px"><a href="javascript:void(0)" onclick="mvptModalView(<?= (int)($d['id'] ?? 0) ?>, <?= htmlspecialchars(json_encode($lnm), ENT_QUOTES) ?>)" style="color:#243B5C;text-decoration:none;font-weight:600;" title="<?= h($lnm) ?>">📄 <?= vv($lnm) ?></a></td>
          <td style="padding:5px 8px"><span style="background:#eef4fb;color:#4878a6;border-radius:4px;padding:1px 6px;font-size:11px"><?=vv($d['document_type'] ?? '')?></span></td>
          <td style="padding:5px 8px"><?=dfr($d['created_at'] ?? null)?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div class="f360-empty"><div class="em-ico">📁</div>Aucun document rattaché</div><?php endif; ?>
    </div>

    <div class="f360-card">
      <h3>📣 Annonces <span class="count"><?=count($annonces)?></span></h3>
      <?php if ($annonces): ?>
      <table class="t" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="color:#9a9690;text-align:left"><th style="padding:5px 8px">Titre</th><th style="padding:5px 8px">Transaction</th><th style="padding:5px 8px">Statut</th><th style="padding:5px 8px;text-align:right">Prix</th></tr></thead>
        <tbody>
        <?php foreach ($annonces as $a): ?>
          <tr style="border-top:1px solid #f0ece6"><td style="padding:5px 8px"><?=vv($a['titre']??'')?></td>
          <td style="padding:5px 8px"><?=vv($a['type_transaction']??'')?></td>
          <td style="padding:5px 8px"><?=vv($a['statut']??$a['etat_publication']??'')?></td>
          <td style="padding:5px 8px;text-align:right"><?=eur($a['prix']??0)?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div class="f360-empty"><div class="em-ico">📣</div>Aucune annonce pour ce bien</div><?php endif; ?>
    </div>

    <div class="f360-card">
      <h3>📊 Historique CRG <span class="count"><?=count($crg)?></span></h3>
      <?php if ($crg): ?>
      <table class="t" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="color:#9a9690;text-align:left"><th style="padding:5px 8px">Trimestre</th><th style="padding:5px 8px;text-align:right">Loyer appelé</th><th style="padding:5px 8px;text-align:right">Encaissé</th><th style="padding:5px 8px;text-align:right">Impayé</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($crg) as $t): ?>
          <tr style="border-top:1px solid #f0ece6"><td style="padding:5px 8px"><?=($t['loyer_appele']>0?'🟢':'🔴')?> <strong><?=(int)$t['annee']?> T<?=(int)$t['trimestre']?></strong></td>
          <td style="padding:5px 8px;text-align:right"><?=eur($t['loyer_appele'])?></td>
          <td style="padding:5px 8px;text-align:right;color:#2d6a35"><?=eur($t['total_regle'])?></td>
          <td style="padding:5px 8px;text-align:right;color:<?=$t['total_impaye']>0?'#a8323b':'#2d6a35'?>"><?=eur($t['total_impaye'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div class="f360-empty"><div class="em-ico">📊</div>Aucun historique CRG</div><?php endif; ?>
    </div>
  </div>

  <!-- COLONNE LATÉRALE -->
  <div>
    <div class="f360-card">
      <h3>👤 Propriétaire</h3>
      <div style="font-size:12.5px;line-height:1.8">
        <strong><?=vv($propNom)?></strong><br>
        <span style="color:#7a766f"><?=($prop['societe']??'')?'Société':'Personne physique'?></span><br>
        <?php if(!empty($prop['email'])): ?>✉ <?=vv($prop['email'])?><br><?php endif; ?>
        <?php if(!empty($prop['telephone'])): ?>📞 <?=vv($prop['telephone'])?><br><?php endif; ?>
        <?php if(!empty($prop['adresse_1'])): ?>📍 <?=vv(trim(($prop['adresse_1']??'').' '.($prop['code_postal']??'').' '.($prop['ville']??'')))?><?php endif; ?>
      </div>
    </div>

    <div class="f360-card">
      <h3>🏢 Immeuble</h3>
      <div style="font-size:12.5px;line-height:1.8">
        <strong><?=vv($bien['nom_immeuble']??'')?></strong><br>
        📍 <?=vv(trim(($bien['imm_adr']??'').' '.($bien['imm_cp']??'').' '.($bien['imm_ville']??'')))?><br>
        <?=((int)($bien['imm_vendu']??0)===1)?'<span class="badge vendu">● Vendu '.dfr($bien['date_vente']??null).'</span>':'<span class="badge actif">● Actif</span>'?>
      </div>
    </div>

    <div class="f360-card">
      <h3>🏠 Bien</h3>
      <div style="font-size:12.5px;line-height:1.9">
        <span style="color:#7a766f">Référence</span> <code><?=vv($bien['reference_bien']??'')?></code><br>
        <?php if(!empty($bien['designation'])): ?><span style="color:#7a766f">Désignation</span> <?=vv($bien['designation'])?><br><?php endif; ?>
        <span style="color:#7a766f">Lot / Étage</span> <?=vv($bien['numero_lot']??'')?> <?=($bien['etage']??'')!==''?'· ét. '.h($bien['etage']):''?><br>
        <span style="color:#7a766f">Surface</span> <?=$surfAff?number_format((float)$surfAff,2,',',' ').' m²':'—'?><br>
        <span style="color:#7a766f">Pièces</span> <?=vv($bien['nb_pieces']??'')?><br>
        <span style="color:#7a766f">DPE / GES</span> <?=vv($bien['dpe_classe']??'')?> / <?=vv($bien['ges_classe']??'')?><br>
        <span style="color:#7a766f">Commercialisation</span> <?=vv($bien['type_commercialisation']??'')?> <?=vv($bien['statut_bien']??'')?>
      </div>
    </div>

    <div class="f360-card">
      <h3>💰 Synthèse</h3>
      <div style="font-size:12.5px;line-height:2">
        <span style="color:#7a766f">Loyer /mois</span> <strong><?=eur($loyerMois)?></strong><br>
        <span style="color:#7a766f">Loyer /an</span> <strong><?=eur($loyerMois>0?$loyerMois*12:0)?></strong><br>
        <span style="color:#7a766f">Cumulé encaissé</span> <strong style="color:#2d6a35"><?=eur($cumulRegle)?></strong><br>
        <span style="color:#7a766f">Solde impayé</span> <strong style="color:<?=$soldeImpaye>0?'#a8323b':'#2d6a35'?>"><?=eur($soldeImpaye)?></strong>
      </div>
    </div>
  </div>
</div>
<?php
if (!defined('MVPT_DOC_VIEWER_LOADED')) { define('MVPT_DOC_VIEWER_LOADED', 1); include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; }
require_once __DIR__ . '/inc/agency_layout_bottom.php';
