<?php
/**
 * bailleur_impersonate.php — « Se connecter en tant que » (super admin only)
 *
 * Sécurité :
 *  - réservé au super admin (is_super_admin)
 *  - cible interdite : super_admin / admin (role 1,7) — pas d'escalade
 *  - la session admin d'origine est sauvegardée puis restaurée au retour
 *  - journalisé (error_log) : qui, quand, vers quel compte
 *
 * Usage :
 *  - bailleur_impersonate.php?as=<id_user>   → entrer dans la session du bailleur
 *  - bailleur_impersonate.php?stop=1         → revenir à son compte admin
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];

// ── STOP : revenir au compte admin d'origine ─────────────
if (isset($_GET['stop'])) {
    if (!empty($_SESSION['impersonator']['session'])) {
        $orig = $_SESSION['impersonator']['session'];
        $_SESSION = $orig;            // restauration intégrale
        unset($_SESSION['impersonator']);
    }
    header('Location: ' . app_url('/bailleur_admin_comptes.php'));
    exit;
}

// ── START : exige super admin ────────────────────────────
if (!is_super_admin()) {
    deny_access('Réservé au super admin.');
}

$targetId = (int)($_GET['as'] ?? 0);
if ($targetId <= 0) {
    deny_access('Compte cible invalide.');
}

// Cible : doit exister, être active, et NE PAS être admin/super admin
$st = $pdo->prepare("
    SELECT id, prenom, nom, email, username, id_role, id_societe, id_agence, super_admin, actif
    FROM users WHERE id = ? LIMIT 1
");
$st->execute([$targetId]);
$target = $st->fetch(\PDO::FETCH_ASSOC);

if (!$target) {
    deny_access('Compte introuvable.');
}
if ((int)$target['super_admin'] === 1 || in_array((int)$target['id_role'], [1, 7], true)) {
    deny_access('Impossible d’usurper un compte administrateur.');
}

// Sauvegarde de la session admin (1 seul niveau : pas d'impersonation imbriquée)
if (empty($_SESSION['impersonator'])) {
    $orig = $_SESSION;
    unset($orig['impersonator']);
    $adminLabel = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) ?: ('user #' . current_user_id());
} else {
    // déjà en impersonation → on garde l'admin d'origine, on swap juste la cible
    $orig = $_SESSION['impersonator']['session'];
    $adminLabel = $_SESSION['impersonator']['admin'] ?? 'admin';
}

// Journalisation best-effort
error_log(sprintf(
    '[IMPERSONATE] admin=%s (#%d) → bailleur %s %s (#%d) @ %s',
    $adminLabel, (int)($orig['user_id'] ?? 0),
    $target['prenom'], $target['nom'], (int)$target['id'],
    date('Y-m-d H:i:s')
));

// Bascule de session vers la cible (mirroir de login.php)
$_SESSION = [
    'user_id'    => (int)$target['id'],
    'id'         => (int)$target['id'],
    'username'   => $target['username'] ?? '',
    'email'      => $target['email'] ?? '',
    'id_role'    => (int)$target['id_role'],
    'id_societe' => $target['id_societe'] !== null ? (int)$target['id_societe'] : null,
    'id_agence'  => $target['id_agence'] !== null ? (int)$target['id_agence'] : null,
    'prenom'     => $target['prenom'] ?? '',
    'nom'        => $target['nom'] ?? '',
    'super_admin'=> false,
    'last_activity' => time(),
    // marqueur d'impersonation : permet le bandeau + le retour
    'impersonator' => [
        'session' => $orig,
        'admin'   => $adminLabel,
        'started' => time(),
    ],
];

header('Location: ' . app_url('/bailleur_dashboard_v2.php'));
exit;
