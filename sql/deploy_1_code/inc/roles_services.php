<?php
declare(strict_types=1);

/**
 * Mapping simple: rôles → services accessibles
 * Remplace complètement le système d'abonnement
 */

// Récupérer les rôles depuis la base de données avec cache
function getRolesServices($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $stmt = $pdo->query("
            SELECT id, nom FROM roles ORDER BY id
        ");
        $roles = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $roles = [
            1 => 'Admin',
            2 => 'Manager',
            3 => 'Collaborateur',
            4 => 'Syndic',
            5 => 'Propriétaire',
            6 => 'Locataire',
            7 => 'Super Admin'
        ];
    }

    // Mapping simple: rôle => liste des services
    $services = [
        // Services: syndic, rh, agency, proprietaire, superadmin
        1 => ['syndic', 'rh', 'agency', 'proprietaire'],  // Admin = tout
        2 => ['syndic', 'rh', 'agency'],                  // Manager = syndic + RH + agency
        3 => ['rh'],                                       // Collaborateur = RH seulement
        4 => ['syndic'],                                   // Syndic = syndic seulement
        5 => ['proprietaire'],                             // Propriétaire = proprietaire seulement
        6 => ['proprietaire'],                             // Locataire = proprietaire seulement
        7 => ['syndic', 'rh', 'agency', 'proprietaire', 'superadmin'], // Super Admin = tout + admin BDD
        8 => ['syndic', 'rh', 'agency', 'proprietaire', 'gestion'],   // Admin Régie = tout + gestion locative
        9 => ['gestion'],                                              // Propriétaire standard = gestion
        10 => ['gestion'],                                             // Investisseur SIR = gestion locative
    ];

    $cache = [
        'roles' => $roles,
        'services' => $services
    ];

    return $cache;
}

/**
 * Obtenir les services accessibles pour l'utilisateur
 */
function getAvailableServices($roleId) {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }

    $config = getRolesServices($pdo);
    $services = $config['services'][$roleId] ?? [];

    // Définir les métadonnées pour chaque service
    $serviceConfig = [
        'syndic' => [
            'nom' => 'Syndic',
            'icon' => '🏢',
            'description' => 'Gestion des syndics et immeubles',
            'couleur' => '#4878a6',
            'dashboard' => 'dashboard_syndic.php'
        ],
        'rh' => [
            'nom' => 'RH',
            'icon' => '👥',
            'description' => 'Gestion des ressources humaines',
            'couleur' => '#4a6038',
            'dashboard' => 'rh_dashboard.php'
        ],
        'agency' => [
            'nom' => 'Agency',
            'icon' => '🏠',
            'description' => 'Gestion immobilière et agences',
            'couleur' => '#ffd479',
            'dashboard' => 'dashboard_agency.php'
        ],
        'proprietaire' => [
            'nom' => 'Propriétaire Pro',
            'icon' => '👤',
            'description' => 'Portail propriétaire avancé',
            'couleur' => '#ff9aab',
            'dashboard' => 'dashboard_proprietaire.php'
        ],
        'superadmin' => [
            'nom' => 'Super Admin',
            'icon' => '⚙',
            'description' => 'Gestion complète de la base de données',
            'couleur' => '#ff6b9d',
            'dashboard' => 'admin/admin_database.php'
        ],
        'gestion' => [
            'nom' => 'Gestion Locative',
            'icon' => '📊',
            'description' => 'CRG, patrimoine, analyses et assistant IA',
            'couleur' => '#d4a843',
            'dashboard' => 'gestion/dashboard_sir.php'
        ]
    ];

    // Retourner la config complète pour les services accessibles
    $result = [];
    foreach ($services as $service) {
        $result[$service] = $serviceConfig[$service] ?? null;
    }

    return $result;
}

/**
 * Vérifier si l'utilisateur a accès à un service
 */
function hasServiceAccess($roleId, $service) {
    $services = getAvailableServices($roleId);
    return isset($services[$service]);
}

/**
 * Protéger une page (redirection si pas d'accès)
 */
function requireServiceAccess($roleId, $service) {
    if (!hasServiceAccess($roleId, $service)) {
        http_response_code(403);
        header("Location: /MaBoxImmo2026/public_html/landing.php");
        exit('Accès non autorisé.');
    }
}

/**
 * Déterminer quelle sidebar afficher selon le rôle
 */
function getSidebarForRole($roleId) {
    $services = getAvailableServices($roleId);

    // Déterminer le service principal de l'utilisateur
    if (isset($services['superadmin'])) {
        return 'sidebar_agency';      // Super Admin (rôle 7) = sidebar agency + section Super Admin
    } elseif (isset($services['agency'])) {
        return 'sidebar_agency';      // Admin (rôle 1), Manager (rôle 2), Agency user
    } elseif (isset($services['rh'])) {
        return 'sidebar_rh';          // RH user
    } elseif (isset($services['syndic'])) {
        return 'sidebar_syndic';      // Syndic user
    } elseif (isset($services['proprietaire'])) {
        return 'sidebar_proprietaire'; // Propriétaire user
    }

    return 'sidebar_agency'; // Default
}

/**
 * Obtenir les pages accessibles pour un rôle/service
 */
function getServicePages($roleId, $service) {
    $pages = [
        'syndic' => [
            ['url' => 'dashboard_syndic.php', 'nom' => '⊞ Dashboard', 'icon' => '⊞'],
            ['url' => 'immeubles_syndic.php', 'nom' => '🏢 Immeubles', 'icon' => '🏢'],
            ['url' => 'coproprietes_syndic.php', 'nom' => '👥 Copropriétaires', 'icon' => '👥'],
            ['url' => 'charges_syndic.php', 'nom' => '💰 Charges', 'icon' => '💰'],
        ],
        'rh' => [
            ['url' => 'rh_dashboard.php', 'nom' => '⊞ Dashboard', 'icon' => '⊞'],
            ['url' => 'rh_salaires.php', 'nom' => '💶 Salaires', 'icon' => '💶'],
            ['url' => 'rh_conges.php', 'nom' => '🏖️ Congés', 'icon' => '🏖️'],
            ['url' => 'rh_modeles.php', 'nom' => '📋 Modèles', 'icon' => '📋'],
        ],
        'agency' => [
            ['url' => 'dashboard_agency.php', 'nom' => '⊞ Dashboard', 'icon' => '⊞'],
            ['url' => 'biens.php', 'nom' => '🏠 Immeubles', 'icon' => '🏠'],
            ['url' => 'mandats.php', 'nom' => '📝 Mandats', 'icon' => '📝'],
            ['url' => 'agency_invoices.php', 'nom' => '💰 Factures', 'icon' => '💰'],
        ],
        'proprietaire' => [
            ['url' => 'dashboard_proprietaire.php', 'nom' => '⊞ Dashboard', 'icon' => '⊞'],
            ['url' => 'mes_biens.php', 'nom' => '🏠 Mes Propriétés', 'icon' => '🏠'],
            ['url' => 'mes_locations.php', 'nom' => '🔑 Locations', 'icon' => '🔑'],
            ['url' => 'mes_documents.php', 'nom' => '📄 Documents', 'icon' => '📄'],
        ]
    ];

    return $pages[$service] ?? [];
}
?>
