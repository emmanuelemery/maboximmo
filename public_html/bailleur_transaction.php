<?php
/**
 * bailleur_transaction.php — Dashboard du module TRANSACTION (Ma Box Bailleur).
 *
 * Hub à onglets (iframes embed) AVEC sidebar bailleur — rendu via inc/bailleur_hub.php.
 * Onglets : Tableau de bord · En vente (transaction_index staff / bailleur_transactions) ·
 * Mandats · Factures (staff/SA). Toutes ces pages gèrent l'embed (agency_layout_top /
 * layout_maboximmo). Le dossier de vente 360 s'ouvre depuis « En vente ».
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

// KPIs (périmètre bailleur) — requêtes défensives (0 si table/colonne absente).
$venteExpr = "EXISTS(SELECT 1 FROM annonces a WHERE a.id_bien=b.id AND a.type_transaction='vente'
    AND (a.visible_portails=1 OR a.visible_site=1 OR a.visible_maboximmo=1 OR a.visible_site_perso=1)
    AND (a.etat_publication IS NULL OR a.etat_publication<>'archive'))";
$k = ['vente'=>0,'mandats'=>0,'offres'=>0,'dossiers'=>0];
if ($in !== '0') {
    try { $k['vente'] = (int)$pdo->query("SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire IN ($in) AND $venteExpr")->fetchColumn(); } catch (Throwable $e) {}
    try { $k['mandats'] = (int)$pdo->query("SELECT COUNT(*) FROM mandats m JOIN biens b ON b.id=m.id_bien WHERE b.id_proprietaire IN ($in) AND (m.statut IS NULL OR m.statut NOT IN ('resilie','expire','archive'))")->fetchColumn(); } catch (Throwable $e) {}
    try { $k['offres'] = (int)$pdo->query("SELECT COUNT(*) FROM leads_annonces la JOIN biens b ON b.id=la.id_bien WHERE b.id_proprietaire IN ($in) AND la.type_contact='offre'")->fetchColumn(); } catch (Throwable $e) {}
    try { $k['dossiers'] = (int)$pdo->query("SELECT COUNT(DISTINCT dv.id) FROM dossier_vente dv JOIN dossier_vente_bien dvb ON dvb.id_dossier=dv.id JOIN biens b ON b.id=dvb.id_bien WHERE b.id_proprietaire IN ($in)")->fetchColumn(); } catch (Throwable $e) {}
}

$enVenteUrl = app_url(($isStaff ? '/transaction_index.php?embed=1' : '/bailleur_transactions.php?embed=1') . $bSuffix);
$TABS = [
    ['k'=>'dash',   'lbl'=>'Tableau de bord','ic'=>'📊', 'url'=>''],
    ['k'=>'envente','lbl'=>'En vente',       'ic'=>'🎯','url'=>$enVenteUrl],
];
if ($isStaff) {
    $TABS[] = ['k'=>'mandats', 'lbl'=>'Mandats', 'ic'=>'📜','url'=>app_url('/agency_mandats.php?embed=1')];
    $TABS[] = ['k'=>'factures','lbl'=>'Factures','ic'=>'🧾','url'=>app_url('/agency_factures.php?embed=1')];
}

$pageTitle     = 'Transaction';
$pageSubtitle  = 'Ma Box Bailleur';
$layoutSidebar = 'sidebar_bailleur_module';
require_once __DIR__ . '/inc/bailleur_hub.php';
$extraCss = bailleur_hub_css();
include __DIR__ . '/inc/agency_layout_top.php';

$dash  = '<p class="hb-sub" style="margin-top:4px">Commercialisation à la vente : biens diffusés, mandats, offres, dossiers de vente.</p>';
$dash .= '<div class="hb-kpis">'
       . bailleur_hub_kpi('red',   '🎯','Biens en vente', (string)$k['vente'], 'annonces diffusées')
       . bailleur_hub_kpi('blue',  '📜','Mandats actifs', (string)$k['mandats'], '')
       . bailleur_hub_kpi('green', '💰','Offres reçues', (string)$k['offres'], '')
       . bailleur_hub_kpi('purple','📁','Dossiers de vente', (string)$k['dossiers'], '')
       . '</div>';
$dash .= '<div class="hb-cards">'
       . bailleur_hub_card('envente','🎯','En vente','Biens diffusés à la vente : annonces, offres, documents, dossier de vente 360°.')
       . ($isStaff ? bailleur_hub_card('mandats','📜','Mandats','Registre des mandats de vente (création, signatures, suivi).') : '')
       . ($isStaff ? bailleur_hub_card('factures','🧾','Factures','Factures d\'honoraires (numérotation, lignes, TVA, envoi).') : '')
       . '</div>';

$staffBar = $isStaff ? bailleur_hub_staffbar($bailleurs, $viewAs) : '';
bailleur_hub_render($TABS, $dash, $staffBar);
