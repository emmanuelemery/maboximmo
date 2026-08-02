<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SESSION_TIMEOUT', 8 * 3600); // 8 heures

/**
 * Vrai si la colonne users.force_password_change existe (résultat cache).
 * Permet au code de tourner sur une BDD où la migration n'a pas encore été jouée.
 */
function users_has_force_password_change(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'force_password_change'")->fetchColumn();
    } catch (Throwable) {
        $has = false;
    }
    return $has;
}

function require_login(): void
{
    // Construit le paramètre ?next= pour revenir sur la même page après login
    $nextParam = '';
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
    if ($currentUri !== '' && strpos($currentUri, 'login.php') === false) {
        $nextParam = '?next=' . rawurlencode($currentUri);
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: ' . app_url('/login.php') . $nextParam);
        exit;
    }

    // Le switcher "TEST RÔLE" a été retiré : on purge tout rôle de test résiduel
    // pour qu'aucun admin ne reste bloqué sur une vue user/manager.
    if (isset($_SESSION['test_role_id'])) {
        unset($_SESSION['test_role_id']);
    }

    // Session timeout : déconnexion automatique après SESSION_TIMEOUT secondes d'inactivité
    $now = time();
    if (!empty($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $timeoutNext = $nextParam !== '' ? '&' . substr($nextParam, 1) : '';
        header('Location: ' . app_url('/login.php') . '?timeout=1' . $timeoutNext);
        exit;
    }
    $_SESSION['last_activity'] = $now;

    // ── CAGE MODULE BAILLEUR (default-deny) ──────────────────────────
    // Confine les comptes bailleurs externes (rôles 9/10) au seul module
    // Bailleur. N'a AUCUN effet sur le personnel interne ni le super admin :
    // enforce_scope() sort immédiatement pour eux → zéro régression possible.
    enforce_scope();

    // Forcer le changement de mot de passe si nécessaire
    // Compat : la colonne force_password_change peut ne pas exister sur les
    // BDDs sans la migration (Hostinger dev/prod tant que le SQL n'est pas joué).
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentScript !== 'change_password.php' && $currentScript !== 'login.php') {
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo && users_has_force_password_change($pdo)) {
            try {
                $stmtFpc = $pdo->prepare("SELECT force_password_change FROM users WHERE id = ? LIMIT 1");
                $stmtFpc->execute([$_SESSION['user_id']]);
                if ((int)$stmtFpc->fetchColumn() === 1) {
                    header('Location: ' . app_url('/change_password.php'));
                    exit;
                }
            } catch (Throwable) { /* no-op : colonne absente ou autre erreur, on laisse passer */ }
        }
    }
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Utilisateur en LECTURE SEULE : voit tout (dans son périmètre) mais ne peut RIEN
 * modifier. Basé sur users.lecture_seule. Ex. un propriétaire « consultation »
 * (Christelle SABY) rattaché à des proprios mais sans pouvoir de décision.
 * Résultat caché en session pour éviter une requête par appel.
 */
function is_readonly_user(): bool
{
    if (array_key_exists('is_readonly', $_SESSION)) return (bool)$_SESSION['is_readonly'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return false;
    $ro = false;
    try {
        $pdo = $GLOBALS['pdo'] ?? (function_exists('db') ? db() : null);
        if ($pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT lecture_seule FROM users WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $ro = (bool)$st->fetchColumn();
        }
    } catch (Throwable $e) { $ro = false; }   // colonne absente (pré-migration) → non bloquant
    $_SESSION['is_readonly'] = $ro;
    return $ro;
}

function current_role_id(): int
{
    // Vérifier d'abord s'il y a un rôle de test défini (pour développement)
    if (!empty($_SESSION['test_role_id'])) {
        return (int)$_SESSION['test_role_id'];
    }
    return (int)($_SESSION['id_role'] ?? 0);
}

function set_test_role(int $roleId): void
{
    if ($roleId > 0) {
        $_SESSION['test_role_id'] = $roleId;
    } else {
        unset($_SESSION['test_role_id']);
    }
}

function get_test_role(): int
{
    return (int)($_SESSION['test_role_id'] ?? 0);
}

function current_societe_id(): ?int
{
    // En mode test, utiliser la société de l'admin réel (pas affectée par le test role)
    // Ainsi les données filtrées restent cohérentes avec le compte admin
    return isset($_SESSION['id_societe']) && $_SESSION['id_societe'] !== null
        ? (int)$_SESSION['id_societe']
        : null;
}

function current_agence_id(): ?int
{
    // En mode test, utiliser l'agence de l'admin réel (pas affectée par le test role)
    // Ainsi les données filtrées restent cohérentes avec le compte admin
    return isset($_SESSION['id_agence']) && $_SESSION['id_agence'] !== null
        ? (int)$_SESSION['id_agence']
        : null;
}

function deny_access(string $message = 'Accès refusé.'): never
{
    http_response_code(403);
    exit($message);
}

function require_role_ids(array $allowedRoleIds): void
{
    require_login();

    $roleId = current_role_id();

    if (!in_array($roleId, $allowedRoleIds, true)) {
        deny_access('Vous n’avez pas les droits nécessaires pour accéder à cette page.');
    }
}

function require_admin(): void
{
    require_role_ids([1]);
}

function require_manager_or_admin(): void
{
    require_role_ids([1, 2]);
}

function can_access_scope(?int $recordSocieteId = null, ?int $recordAgenceId = null): bool
{
    $roleId = current_role_id();

    // Admin et Super Admin ont accès à tout
    if ($roleId === 1 || $roleId === 7) {
        return true;
    }

    // Manager
    if ($roleId === 2) {
        if ($recordAgenceId !== null && current_agence_id() !== null) {
            return current_agence_id() === $recordAgenceId;
        }

        if ($recordSocieteId !== null && current_societe_id() !== null) {
            return current_societe_id() === $recordSocieteId;
        }

        return false;
    }

    // Collaborateur / autre
    return false;
}

/**
 * Retourne l'id de l'agence si l'utilisateur a la permission gestion_salaires,
 * 0 sinon. L'admin (role 1) retourne 0 également — il a déjà accès à tout.
 * Utilisation : if ($agenceScope = can_manage_salaires_agence()) { ... scoped à $agenceScope }
 */
function can_manage_salaires_agence(): int
{
    $roleId = current_role_id();
    if ($roleId === 1) return 0; // admin : pas besoin du scope

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return 0;

    $userId = current_user_id();
    $stmt   = $pdo->prepare("SELECT gestion_salaires, id_agence FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && (int)$row['gestion_salaires'] === 1 && (int)$row['id_agence'] > 0) {
        return (int)$row['id_agence'];
    }
    return 0;
}

/**
 * Vérifier si l'utilisateur a le flag super_admin (indépendant du rôle)
 */
function is_super_admin(): bool
{
    // Valeur session (si déjà déterminée) — mais si elle est fausse, on
    // revalide en DB une fois par requête (utile en dev quand on change les droits).
    static $validated = false;
    if (isset($_SESSION['super_admin']) && $_SESSION['super_admin']) {
        return true;
    }

    // Fallback : lecture DB
    $pdo = $GLOBALS['pdo'] ?? null;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if (!$pdo || $userId <= 0) {
        $_SESSION['super_admin'] = false;
        return false;
    }

    if ($validated && isset($_SESSION['super_admin'])) {
        return !empty($_SESSION['super_admin']);
    }

    try {
        $stmt = $pdo->prepare("SELECT super_admin FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $is = (int)$stmt->fetchColumn() === 1;
        $_SESSION['super_admin'] = $is;
        $validated = true;
        return $is;
    } catch (Throwable) {
        $_SESSION['super_admin'] = false;
        $validated = true;
        return false;
    }
}

/**
 * Protéger une page Super Admin
 */
function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        deny_access('Accès réservé au Super Admin.');
    }
}

/**
 * Accès dashboard "Admin + Super Admin" (mêmes actions).
 * - Admin = role_id 1
 * - Super Admin = role_id 7 OU flag session super_admin (legacy)
 */
function is_admin_or_super_admin(): bool
{
    $roleId = current_role_id();
    return $roleId === 1 || $roleId === 7 || is_super_admin();
}

function require_admin_or_super_admin(): void
{
    require_login();
    if (!is_admin_or_super_admin()) {
        deny_access('Accès réservé aux administrateurs.');
    }
}

// ═══════════════════════════════════════════════════════════════
// MODULE GESTION LOCATIVE — Fonctions d'accès CRG / SIR / Agence
// ═══════════════════════════════════════════════════════════════

/**
 * Authentification module gestion locative.
 * Vérifie que le user a acces_maboximmo=1 et le code_acces requis.
 */
function check_auth_gestion(?string $code_requis = null): array
{
    require_login();
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) { deny_access('Erreur base de données.'); }

    $stmt = $pdo->prepare("
        SELECT u.*, r.code AS role_code, r.niveau_acces
        FROM users u JOIN roles r ON u.id_role = r.id
        WHERE u.id = ? AND u.actif = 1 AND u.acces_maboximmo = 1
    ");
    $stmt->execute([current_user_id()]);
    $user = $stmt->fetch();

    if (!$user) {
        deny_access('Accès au portail MaBoxImmo non autorisé.');
    }

    if ($code_requis && ($user['code_acces'] ?? '') !== $code_requis && !($user['super_admin'] ?? false)) {
        // SIR peut accéder aux pages PROPRIO
        $ok = ($code_requis === 'PROPRIO' && ($user['code_acces'] ?? '') === 'SIR');
        if (!$ok) { deny_access('Accès refusé.'); }
    }

    return $user;
}

/**
 * Retourne les id_proprietaire accessibles pour un user SIR
 * selon l'entité active en session (SIR / SABY / GROUPE = tous)
 */
function get_sir_proprietaire_ids(int $id_user): array
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return [];

    $entite = $_SESSION['sir_entite'] ?? 'GROUPE';

    // If entite is a numeric ID → single proprietaire
    if (is_numeric($entite)) {
        // Verify user has access to this proprietaire
        $s = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ? AND id_proprietaire = ?");
        $s->execute([$id_user, (int)$entite]);
        $ids = array_column($s->fetchAll(), 'id_proprietaire');
        return $ids ?: [];
    }

    $q = "SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?";
    $params = [$id_user];

    if ($entite !== 'GROUPE') {
        $q .= " AND label = ?";
        $params[] = $entite;
    }

    $s = $pdo->prepare($q);
    $s->execute($params);
    return array_column($s->fetchAll(), 'id_proprietaire');
}

/**
 * Vérifie qu'un user AGENCE/NEGO a accès à un bien spécifique
 * via un mandat de commercialisation actif
 */
function check_bien_agence(int $id_bien, int $id_agence): bool
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return false;

    $s = $pdo->prepare("
        SELECT COUNT(*) FROM mandats m
        JOIN mandats_agences_ext mae ON mae.id_mandat = m.id
        WHERE m.id_bien = ? AND mae.id_agence = ?
        AND mae.actif = 1 AND m.statut = 'actif' AND m.mandat_commercialisation = 1
    ");
    $s->execute([$id_bien, $id_agence]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Peut administrer le module Bailleur (comptes + droits) :
 * super admin, Admin (1), Manager (2), Admin Régie (8).
 * Les comptes bailleurs externes (9/10) NE peuvent pas — ils sont cagés.
 */
function can_admin_bailleur(): bool
{
    if (is_super_admin()) return true;
    return in_array(current_role_id(), [1, 2, 8], true);
}

// ═══════════════════════════════════════════════════════════════
// CAGE MODULE BAILLEUR — confinement des comptes bailleurs externes
// ═══════════════════════════════════════════════════════════════

/**
 * Vrai UNIQUEMENT pour un compte bailleur externe (rôle 9 = Propriétaire
 * standard, rôle 10 = Investisseur/Bailleur VIP), jamais super admin.
 *
 * Le choix de baser la cage sur le RÔLE (et non sur getAvailableServices,
 * qui peut être enrichi par les modules de la société) garantit qu'AUCUN
 * collaborateur interne (rôles 1,2,3,7,8) n'est jamais concerné → aucune
 * régression possible sur l'existant.
 */
function is_caged_bailleur(): bool
{
    if (is_super_admin()) return false;
    return in_array(current_role_id(), [9, 10], true);
}

/**
 * Peut créer un bien (bouton « créer/ajouter un bien » + points d'entrée de création).
 * Réservé au personnel interne : exclut les comptes bailleurs/propriétaires externes
 * (rôles 9/10, cagés au module Bailleur) et les comptes en lecture seule.
 * Super admin toujours autorisé. Helper d'accès unique — même règle côté affichage et serveur.
 */
function can_create_bien(): bool
{
    if (is_super_admin()) return true;
    return !is_caged_bailleur() && !is_readonly_user();
}

/**
 * Liste blanche : la page demandée fait-elle partie de la surface autorisée
 * pour un compte bailleur ? (dashboard + tiroirs : révision, créanciers,
 * portefeuille, investisseur, contentieux + dépendances partagées).
 */
/**
 * Modules autorisés d'un compte bailleur (table user_bailleur_modules).
 * Défaut si rien de configuré : ['patrimoine','ged'] — IDENTIQUE à la sidebar bailleur
 * (inc/sidebar_bailleur_module.php) → la cage page = ce que le bailleur VOIT.
 */
function bailleur_user_modules(int $userId): array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $mods = [];
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo && $userId > 0) {
        try {
            $st = $pdo->prepare("SELECT module_code FROM user_bailleur_modules WHERE id_user = ?");
            $st->execute([$userId]);
            $mods = $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) { $mods = []; }
    }
    if (empty($mods)) $mods = ['patrimoine', 'ged'];
    return $cache[$userId] = $mods;
}

function bailleur_page_allowed(string $script, string $fullPath): bool
{
    // 0. REFUS ABSOLU (prioritaire) : actions réservées à l'AGENCE — un bailleur ne
    //    diffuse JAMAIS sur les portails (leboncoin/Ubiflow), c'est notre activité
    //    réglementée. Bloqué même si le préfixe annonce_ est autorisé pour l'édition.
    static $bailleurDeny = [
        'annonce_diffuser.php', 'annonce_diffusion_action.php', 'annonce_remonter.php',
        'agence_biens_diffuses.php', 'ubiflow.php',
    ];
    if (in_array($script, $bailleurDeny, true)) return false;

    // 0bis. CAGE MODULE : les pages d'un module ne passent QUE si le bailleur a le droit-module
    //       (user_bailleur_modules). Sinon → refus (403 + redirection dashboard) : le bailleur ne
    //       peut PAS atteindre un module non attribué, même en tapant l'URL. Cohérent avec la
    //       sidebar (mêmes modules visibles = accessibles). Cœur (patrimoine/ged/financement/
    //       bailleur_ + biens/baux/tiers partagés) : jamais gaté.
    $reqMod = null;
    if (strpos($fullPath, '/investisseur/') !== false)                              $reqMod = 'investisseur';
    elseif ($script === 'bailleur_transaction.php')                                 $reqMod = 'transaction'; // dashboard module Transaction
    elseif (strncmp($script, 'creancier_', 10) === 0)                               $reqMod = 'creancier';
    elseif (strncmp($script, 'transaction_portefeuilles', 25) === 0
            || strncmp($script, 'portefeuille_', 13) === 0)                          $reqMod = 'portefeuille';
    elseif (strncmp($script, 'transaction_', 12) === 0)                             $reqMod = 'transaction';
    if ($reqMod !== null && !in_array($reqMod, bailleur_user_modules((int)($_SESSION['user_id'] ?? 0)), true)) {
        return false;
    }

    // 1. Répertoire investisseur (tiroir « Analyse investisseur »)
    if (strpos($fullPath, '/investisseur/') !== false) return true;

    // 2. Fichiers du module par préfixe (pages + API)
    static $prefixes = ['bailleur_', 'creancier_', 'portefeuille_', 'financement_'];
    foreach ($prefixes as $p) {
        if (strncmp($script, $p, strlen($p)) === 0) return true;
    }

    // 3. Pages/API partagées explicitement autorisées dans le parcours bailleur
    static $shared = [
        // Chrome / compte
        'logout.php', 'change_password.php', 'landing.php',
        // GED bailleur
        'ged_dashboard.php',
        // Baux / bail 360°
        'bail_360.php', 'bien_baux_liste.php',
        // Consultation des entités du patrimoine (biens / immeubles / tiers) —
        // pages liées depuis les vues bailleur (cards, fiches, annonces).
        'bien_360.php', 'bien_detail.php', 'bien_liste.php', 'bien_recherche.php',
        'bien_documents_list.php', 'bien_doc_360.php',
        'immeuble_360.php', 'immeuble_details.php',
        'tiers_360.php',
        // Dossier de vente (transaction) rattaché à un bien du bailleur
        'transaction_dossier.php',
        // Tiroir Portefeuille (module Transaction/Portefeuilles côté bailleur)
        'transaction_portefeuilles_hub.php',
        'transaction_portefeuilles.php',
        'transaction_portefeuilles_selection.php',
        'transaction_portefeuilles_liste.php',
        'transaction_portefeuilles_envois.php',
        // Recherche d'entités (autocomplete réutilisé par les modales)
        'fluxbox_entity_search.php',
        // Recherche générale (topbar + patrimoine) — quick_search scope déjà les résultats
        // au périmètre du compte (ses propriétaires via user_proprietaires) pour un non-staff.
        'quick_search.php',
        // Patrimoine « plein accès » (vue admin) — la page se scope elle-même au patrimoine
        // du bailleur connecté (ses user_proprietaires) ; pour un bailleur = SON patrimoine.
        'patrimoine_partage.php',
        // Vues créanciers ET financement de la liste partagée (même auth/scoping que
        // patrimoine_partage : inc/patrimoine_partage_auth.php restreint au patrimoine du
        // bailleur connecté). Sans elles, cliquer créancier/financement renvoyait au dashboard.
        'patrimoine_creancier.php', 'patrimoine_creancier_dossier.php',
        'patrimoine_financement.php', 'patrimoine_financement_dossier.php',
    ];
    if (in_array($script, $shared, true)) return true;

    // APIs de DONNÉES réutilisées par les pages du parcours bailleur (biens, immeubles,
    // annonces, diagnostics, tiers, GED, géo, encadrement…). Reste bloqué : RH, admin,
    // compta, fluxbox (sauf fluxbox_entity_search whitelisté ci-dessus).
    if (strpos($fullPath, '/api/') !== false) {
        static $apiPrefixes = [
            'bien_', 'biens_', 'immeuble_', 'annonce_', 'dpe_', 'geo_', 'erp_',
            'tiers_', 'ged_', 'encadrement_', 'bail_', 'dvf_', 'document_', 'mail_',
            'pappers_', 'registre_',
        ];
        foreach ($apiPrefixes as $p) {
            if (strncmp($script, $p, strlen($p)) === 0) return true;
        }
    }

    return false;
}

/**
 * CAGE default-deny. Appelée dans require_login().
 * - Personnel interne / super admin  → return immédiat (no-op total).
 * - Compte bailleur (rôle 9/10)       → seules les pages de la liste blanche
 *   passent ; toute autre page → 403 + redirection vers le dashboard bailleur.
 */
function enforce_scope(): void
{
    if (!is_caged_bailleur()) return; // ← garantie zéro régression

    $fullPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $script   = basename($fullPath);

    if (bailleur_page_allowed($script, $fullPath)) return;

    // Refus : hors module Bailleur.
    http_response_code(403);

    // Appel API bloqué (fetch/AJAX) : répondre en JSON, pas en HTML — sinon le JS
    // reçoit « <!DOCTYPE… » et casse (« Unexpected token '<' »).
    if (strpos($fullPath, '/api/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'Accès réservé au module Bailleur.']));
    }

    // Si la page bloquée était chargée en iframe (embed=1), on préserve embed
    // pour que la page de repli ne rende PAS de sidebar/topbar (évite le double-layout).
    $isEmbed = (($_GET['embed'] ?? '') === '1');
    $target  = 'bailleur_dashboard.php' . ($isEmbed ? '?embed=1' : '');
    $dest    = function_exists('app_url') ? app_url('/' . $target) : '/' . $target;
    header('Location: ' . $dest);
    exit('Accès réservé au module Bailleur.');
}
