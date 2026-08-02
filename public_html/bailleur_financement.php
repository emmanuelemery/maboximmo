<?php
/**
 * bailleur_financement.php — Dashboard du module FINANCEMENT (Ma Box Bailleur).
 *
 * Landing (sidebar bailleur + KPIs + cartes) : le module est encore « fin » (montants/échéances
 * en placeholder). Les dossiers vivent dans financement_liste.php (ancien layout header.php, non
 * embeddable) → on y NAVIGUE via cartes-liens plutôt qu'en iframe. Rendu via inc/bailleur_hub.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/financement.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();
$roleId    = (int)current_role_id();
$isSuper   = (function_exists('is_super_admin') && is_super_admin()) || in_array($roleId, [1, 7], true);
$isBailleur = function_exists('is_caged_bailleur') && is_caged_bailleur();
if (!$isSuper && !$isBailleur) { http_response_code(403); exit('Accès réservé.'); }

$soc = fin_soc();
$dossiers = $isBailleur ? fin_list($pdo, $soc, (int)current_user_id()) : fin_list($pdo, $soc);
$nbDossiers = count($dossiers);
$nbBiens = 0; $nbCrea = 0; $nbPart = 0; $nbEnCours = 0;
foreach ($dossiers as $d) {
    $nbBiens += (int)($d['nb_biens'] ?? 0);
    $nbCrea  += (int)($d['nb_creanciers'] ?? 0);
    $nbPart  += (int)($d['nb_participants'] ?? 0);
    if (!in_array((string)($d['statut'] ?? ''), ['clos','abandonne','solde'], true)) $nbEnCours++;
}

$pageTitle     = 'Financement';
$pageSubtitle  = 'Ma Box Bailleur';
$layoutSidebar = 'sidebar_bailleur_module';
require_once __DIR__ . '/inc/bailleur_hub.php';
$extraCss = bailleur_hub_css();
include __DIR__ . '/inc/agency_layout_top.php';

$dash  = '<p class="hb-sub" style="margin-top:4px">Dossiers de financement reliés au patrimoine (biens, créanciers, participants).</p>';
$dash .= '<div class="hb-kpis">'
       . bailleur_hub_kpi('blue',  '💶','Dossiers', (string)$nbDossiers, $nbEnCours . ' en cours')
       . bailleur_hub_kpi('green', '🏠','Biens reliés', (string)$nbBiens, '')
       . bailleur_hub_kpi('amber', '⚖️','Créanciers reliés', (string)$nbCrea, '')
       . bailleur_hub_kpi('purple','👥','Participants', (string)$nbPart, 'collaborateurs / tiers')
       . '</div>';
$dash .= '<div class="hb-cards">'
       . bailleur_hub_card_link(app_url('/financement_liste.php'), '💶','Dossiers de financement','Voir et gérer les dossiers de financement (rattachements biens/créanciers, documents, participants).')
       . bailleur_hub_card_link(app_url('/financement_liste.php'), '➕','Nouveau dossier','Créer un dossier de financement (le propriétaire emprunteur, ses biens et ses créanciers reliés).')
       . '</div>';
$dash .= '<div class="hb-note" style="margin-top:18px;max-width:640px;font-size:12.5px;color:#7a766f;background:#faf8f5;border:1px solid #ece7df;border-left:3px solid #a8741d;border-radius:10px;padding:11px 13px;">'
       . '🚧 Les montants chiffrés (capital, taux, échéancier, amortissement) arriveront dans une prochaine tranche.</div>';

bailleur_hub_landing($dash);
