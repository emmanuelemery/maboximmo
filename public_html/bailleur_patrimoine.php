<?php
/**
 * bailleur_patrimoine.php — Dashboard du module PATRIMOINE (Ma Box Bailleur).
 *
 * Hub à onglets (iframes embed) AVEC sidebar bailleur — rendu mutualisé via inc/bailleur_hub.php.
 * Accès demandés (Emmanuel 2026-08) : Patrimoine actif (tableau modifiable) · Arbitrage
 * (transaction_portefeuilles) · Biens à proposer (?mode=proposer) · Propositions
 * (transaction_portefeuilles_liste) · Partages (bailleur_partages, admin/SA).
 * « En vente » n'est PAS ici : module Transaction (complet, à part).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

require_once __DIR__ . '/inc/portefeuille_scope.php';
$scope    = pf_scope($pdo);
$isStaff  = $scope['is_staff'];
$viewAs   = (int)$scope['view_as'];
$scopeIds = $scope['ids'];
$bailleurs = $isStaff ? pf_bailleurs_list($pdo) : [];
$in = !empty($scopeIds) ? implode(',', array_map('intval', $scopeIds)) : '0';
$bSuffix = $viewAs > 0 ? '&bailleur=' . $viewAs : '';

// KPIs (identiques à l'onglet Patrimoine actif via la fonction partagée).
$prixExpr = "COALESCE((SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1), b.prix_demande_initial, b.prix_vente_estime)";
$loyExpr  = "COALESCE((SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1), b.loyer_hc)";
$k = ['nb'=>0,'val_tot'=>0,'nb_prop'=>0,'val_prop'=>0,'loyer'=>0,'nb_portef'=>0];
if ($in !== '0') {
    require_once __DIR__ . '/inc/patrimoine_base.php';
    $tot = patrimoine_totaux($pdo, "AND ct.id_proprietaire IN ($in)");
    $k['nb'] = (int)$tot['nb_occupes']; $k['val_tot'] = (float)$tot['valeur'];
    $row = $pdo->query("SELECT SUM(b.a_proposer=1) nb_prop,
               SUM(CASE WHEN b.a_proposer=1 THEN $prixExpr ELSE 0 END) val_prop,
               SUM($loyExpr) loyer
        FROM biens b WHERE b.id_proprietaire IN ($in)
          AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
          AND (b.date_retrait_commercialisation IS NULL AND b.prix_final_vente IS NULL)")->fetch(PDO::FETCH_ASSOC) ?: [];
    $k['nb_prop']=(int)($row['nb_prop']??0); $k['val_prop']=(float)($row['val_prop']??0); $k['loyer']=(float)($row['loyer']??0);
    try { $k['nb_portef'] = (int)$pdo->query("SELECT COUNT(*) FROM portefeuilles WHERE id_proprietaire IN ($in)")->fetchColumn(); } catch (Throwable $e) {}
}

$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';

// Onglets.
$TABS = [
    ['k'=>'dash',        'lbl'=>'Tableau de bord','ic'=>'📊', 'url'=>''],
    ['k'=>'patrimoine',  'lbl'=>'Patrimoine actif','ic'=>'🏛️','url'=>app_url('/bailleur_patrimoine_actif.php?embed=1' . $bSuffix)],
    ['k'=>'arbitrage',   'lbl'=>'Arbitrage',       'ic'=>'⚖️','url'=>app_url('/transaction_portefeuilles.php?embed=1' . $bSuffix)],
    ['k'=>'avendre',     'lbl'=>'Biens à proposer','ic'=>'🏷️','url'=>app_url('/bailleur_patrimoine_actif.php?embed=1&mode=proposer' . $bSuffix)],
    ['k'=>'propositions','lbl'=>'Propositions',    'ic'=>'📚','url'=>app_url('/transaction_portefeuilles_liste.php?embed=1' . $bSuffix)],
];
$canPartages = (function_exists('is_super_admin') && is_super_admin()) || (function_exists('can_admin_bailleur') && can_admin_bailleur());
if ($canPartages) $TABS[] = ['k'=>'partages','lbl'=>'Partages','ic'=>'🔗','url'=>app_url('/bailleur_partages.php?embed=1' . $bSuffix)];

$pageTitle     = 'Patrimoine';
$pageSubtitle  = 'Ma Box Bailleur';
$layoutSidebar = 'sidebar_bailleur_module';
require_once __DIR__ . '/inc/bailleur_hub.php';
$extraCss = bailleur_hub_css();
include __DIR__ . '/inc/agency_layout_top.php';

// Tableau de bord : KPIs + cartes.
$dash  = '<p class="hb-sub" style="margin-top:4px">Vue d\'ensemble du patrimoine · ' . (int)$k['nb'] . ' bien(s) actif(s)</p>';
$dash .= '<div class="hb-kpis">'
       . bailleur_hub_kpi('blue',  '🏛️','Patrimoine actif', $eur($k['val_tot']), (int)$k['nb'] . ' bien(s) occupé(s)')
       . bailleur_hub_kpi('green', '🏷️','Biens à proposer', $eur($k['val_prop']), (int)$k['nb_prop'] . ' bien(s) proposé(s)')
       . bailleur_hub_kpi('amber', '📚','Propositions', (string)(int)$k['nb_portef'], 'portefeuille(s) enregistré(s)')
       . bailleur_hub_kpi('purple','💶','Loyers / mois', $eur($k['loyer']), $eur($k['loyer'] * 12) . ' /an')
       . '</div>';
$dash .= '<div class="hb-cards">'
       . bailleur_hub_card('patrimoine',  '🏛️','Patrimoine actif','Tableau général modifiable : propriétaires, immeubles, biens, CRG, loyers, prix.')
       . bailleur_hub_card('arbitrage',   '⚖️','Arbitrage','Sélectionner les biens d\'un périmètre et fixer un prix proposé (décider quoi vendre).')
       . bailleur_hub_card('avendre',     '🏷️','Biens à proposer','Choisir les biens à proposer à la vente, ajuster les prix, filtrer par prix / surface.')
       . bailleur_hub_card('propositions','📚','Propositions','Reprendre une sélection enregistrée ou en créer une nouvelle (portefeuilles).')
       . ($canPartages ? bailleur_hub_card('partages','🔗','Partages','Partager le patrimoine par lien à jeton (banquier, avocat, comptable, notaire, gestionnaire) — lecture seule, révocable.') : '')
       . '</div>';

$staffBar = $isStaff ? bailleur_hub_staffbar($bailleurs, $viewAs) : '';
bailleur_hub_render($TABS, $dash, $staffBar);
