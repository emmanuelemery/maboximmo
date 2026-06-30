<?php
// rh_dashboard_admin.php — Dashboard RH ADMIN (rôles 1, 7, 8) : pilotage RH complet.
$current_page = 'dashboard';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

// Réservé Administrateur (1) / Super Admin (7) / Administrateur Régie (8).
$__rhRole = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if (!in_array($__rhRole, [1, 7, 8], true)) {
    header('Location: rh_dashboard_user.php');
    exit;
}

$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$layout_title          = in_array($__rhRole, [1, 7], true) ? 'Dashboard Super Admin' : 'Dashboard Admin';
$layout_module         = 'Ma Box RH';
$layout_sidebar        = 'rh_sidebar';
$layout_hide_page_head = true;

function rh_svg(string $path, string $stroke = 'currentColor', int $size = 28): string {
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$stroke.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

// Icônes
$icoUser     = '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>';
$icoUsers    = '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>';
$icoCalendar = '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>';
$icoChat     = '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>';
$icoCash     = '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>';
$icoFile     = '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>';
$icoCar      = '<path d="M5 17h14l-1.5-5H6.5z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>';
$icoMail     = '<path d="M4 4h16a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2z"/><polyline points="22,6 12,13 2,6"/>';
$icoCheck    = '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>';
$icoProfile  = '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M6 20a6 6 0 0112 0"/>';
$icoArrow    = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';
$icoBuilding = '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><line x1="9" y1="9" x2="9" y2="9"/><line x1="9" y1="12" x2="9" y2="12"/><line x1="9" y1="15" x2="9" y2="15"/>';

// Le Super Admin (1, 7) peut créer une société — privilège exclusif vs Admin (8).
$rhSuperAdmin = in_array($__rhRole, [1, 7], true);

// ── Barre de création — admin : tout le périmètre RH ──
$creerItems = [
    ['label' => 'Collaborateur', 'url' => 'rh_user_add.php',          'ico' => $icoUser,     'color' => '#4878a6'],
    ['label' => 'Congé',         'url' => 'rh_conges.php?new=1',      'ico' => $icoCalendar, 'color' => '#c97b2e'],
    ['label' => 'Entretien',     'url' => 'rh_entretien_ajouter.php', 'ico' => $icoChat,     'color' => '#7a6898'],
    ['label' => 'Salaire',       'url' => 'rh_salaires.php?new=1',    'ico' => $icoCash,     'color' => '#6b8e6f'],
    ['label' => 'Document',      'url' => 'rh_documents.php?new=1',   'ico' => $icoFile,     'color' => '#2d5f6b'],
    ['label' => 'Indemnité KM',  'url' => 'rh_indemnite_km.php?new=1','ico' => $icoCar,      'color' => '#a85858'],
    ['label' => 'Mail RH',       'url' => 'rh_mails.php?new=1',       'ico' => $icoMail,     'color' => '#7a6830'],
];

// Privilège Super Admin uniquement : créer une nouvelle société.
if ($rhSuperAdmin) {
    array_unshift($creerItems, [
        'label' => 'Société', 'url' => 'societe_super_admin.php', 'ico' => $icoBuilding, 'color' => '#243B5C',
    ]);
}

// ── Rubriques à explorer ──
$decouvrirItems = [
    ['label' => 'Collaborateurs', 'desc' => 'Fiches de votre équipe : coordonnées, poste, contrats et historique.',         'url' => 'rh_user.php',              'ico' => $icoUsers,    'color' => '#4878a6'],
    ['label' => 'Congés',         'desc' => 'Demandes en cours, soldes par collaborateur et historique des validations.',   'url' => 'rh_conges.php',            'ico' => $icoCalendar, 'color' => '#c97b2e'],
    ['label' => 'Validation congés','desc' => 'Examinez et validez les demandes de congés en attente.',                      'url' => 'rh_conges_validation.php', 'ico' => $icoCheck,    'color' => '#a85858'],
    ['label' => 'Entretiens',     'desc' => 'Planification, conduite et archivage des entretiens annuels et pro.',           'url' => 'rh_entretien_liste.php',   'ico' => $icoChat,     'color' => '#7a6898'],
    ['label' => 'Salaires',       'desc' => 'Saisie des fiches de paie, barèmes, suivi mensuel et historique.',              'url' => 'rh_salaires.php',          'ico' => $icoCash,     'color' => '#6b8e6f'],
    ['label' => 'Documents',      'desc' => 'Pièces administratives, contrats, assurances et documents obligatoires.',       'url' => 'rh_documents.php',         'ico' => $icoFile,     'color' => '#2d5f6b'],
    ['label' => 'Indemnités KM',  'desc' => 'Calcul et suivi des indemnités kilométriques selon le barème légal.',           'url' => 'rh_indemnite_km.php',      'ico' => $icoCar,      'color' => '#a85858'],
    ['label' => 'Mails RH',       'desc' => 'Modèles d\'emails, envois automatiques, notifications collaborateurs.',         'url' => 'rh_mails.php',             'ico' => $icoMail,     'color' => '#7a6830'],
    ['label' => 'Mon profil',     'desc' => 'Vos informations personnelles, soldes congés et fiches de paie.',               'url' => 'rh_profil.php',            'ico' => $icoProfile,  'color' => '#5a6e8a'],
];

$rhWelcomeLead = 'Pilotez l\'ensemble du périmètre RH : créez rapidement au-dessus ou explorez les rubriques ci-dessous.';

require __DIR__ . '/inc/rh_dashboard_pilot_view.php';
