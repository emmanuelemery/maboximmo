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
