<?php
// rh_dashboard_user.php — Dashboard RH COLLABORATEUR (rôle 3) : self-service.
// Même présentation (Créer / Explorer) que les dashboards manager & admin,
// mais limité aux actions et rubriques PERSONNELLES du collaborateur.
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

// Dashboard PERSONNEL — accessible à TOUS les rôles (lien « RH » de la navigation).
// Chacun y retrouve ses propres congés, salaires, documents, IK, profil, entretiens.
// Les dashboards de pilotage (Manager / Admin) sont des liens dédiés en bas de sidebar.

$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$layout_title          = 'Mon espace RH';
$layout_module         = 'Ma Box RH';
$layout_sidebar        = 'rh_sidebar';
$layout_hide_page_head = true;

function rh_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

// Icônes
$icoCalendar = '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>';
$icoChat     = '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>';
$icoCash     = '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>';
$icoFile     = '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>';
$icoCar      = '<path d="M5 17h14l-1.5-5H6.5z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>';
$icoProfile  = '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M6 20a6 6 0 0112 0"/>';
$icoArrow    = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';

// ── Barre de création — actions personnelles du collaborateur ──
$creerItems = [
    ['label' => 'Congé',        'url' => 'rh_conges.php?new=1',          'ico' => $icoCalendar, 'color' => '#c97b2e'],
    ['label' => 'Salaire',      'url' => 'rh_salaires_user_list.php',     'ico' => $icoCash,     'color' => '#6b8e6f'],
    ['label' => 'Document',     'url' => 'rh_documents_user.php',         'ico' => $icoFile,     'color' => '#2d5f6b'],
    ['label' => 'Indemnité KM', 'url' => 'rh_indemnite_km.php?new=1',     'ico' => $icoCar,      'color' => '#a85858'],
];

// ── Rubriques à explorer — espace personnel du collaborateur ──
$decouvrirItems = [
    ['label' => 'Mon profil',     'desc' => 'Vos informations personnelles, coordonnées et fiche véhicule.',          'url' => 'rh_profil_user.php',                 'ico' => $icoProfile,  'color' => '#5a6e8a'],
    ['label' => 'Mes congés',     'desc' => 'Vos demandes de congés, soldes et historique des validations.',          'url' => 'rh_conges_historiq_user.php',        'ico' => $icoCalendar, 'color' => '#c97b2e'],
    ['label' => 'Mes entretiens', 'desc' => 'Vos entretiens annuels et professionnels, comptes-rendus.',              'url' => 'rh_entretien_vue_collaborateur.php', 'ico' => $icoChat,     'color' => '#7a6898'],
    ['label' => 'Mes salaires',   'desc' => 'Vos fiches de paie mensuelles et éléments de rémunération.',             'url' => 'rh_salaires_user_list.php',          'ico' => $icoCash,     'color' => '#6b8e6f'],
    ['label' => 'Mes documents',  'desc' => 'Vos pièces administratives, contrats et justificatifs.',                 'url' => 'rh_documents_user.php',              'ico' => $icoFile,     'color' => '#2d5f6b'],
    ['label' => 'Mes IK',         'desc' => 'Vos indemnités kilométriques et trajets professionnels déclarés.',       'url' => 'rh_indemnite_km.php',                'ico' => $icoCar,      'color' => '#a85858'],
];

$rhWelcomeLead = 'Retrouvez ici vos congés, salaires, documents et indemnités. Créez rapidement au-dessus ou explorez votre espace ci-dessous.';

require __DIR__ . '/inc/rh_dashboard_pilot_view.php';
