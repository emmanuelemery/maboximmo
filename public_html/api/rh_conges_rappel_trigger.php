<?php
declare(strict_types=1);

/**
 * POST /api/rh_conges_rappel_trigger.php
 *
 * Déclenche manuellement l'envoi du mail de rappel "solde congés à
 * prendre" — équivalent HTTP du script CLI `scripts/rh_conges_rappel.php`.
 *
 * Réservé aux admins (role_id=1) + CSRF. Pour éviter le spam :
 *  - La clé unique (annee, id_user) de rh_conges_rappel_log empêche les
 *    doublons d'envoi pour l'année en cours.
 *  - Le paramètre `dry_run=1` simule sans rien envoyer (mode test).
 *  - Le paramètre `force=1` ignore le log et renvoie (à manipuler
 *    avec précaution).
 *
 * Paramètres POST :
 *   - csrf_token : obligatoire
 *   - dry_run    : 0|1
 *   - force      : 0|1
 *   - user       : id (optionnel, cible un user pour test)
 *
 * Réponse JSON :
 *   { ok, annee, sent, skipped, errors, dry_run, cc }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']); exit;
}

// Auth : admin only
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé aux administrateurs']); exit;
}

// CSRF : token partagé "rh_rappel" (à injecter côté dashboard)
try {
    verify_csrf_any('rh_rappel');
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide']); exit;
}

// Propage les paramètres au script CLI via $_GET (utilisé en mode HTTP)
$_GET = array_merge($_GET, [
    'dry-run' => !empty($_POST['dry_run']) ? '1' : '',
    'force'   => !empty($_POST['force'])   ? '1' : '',
    'preview' => !empty($_POST['preview']) ? '1' : '',
    'user'    => isset($_POST['user']) && ctype_digit((string)$_POST['user']) ? (string)$_POST['user'] : '',
]);
if ($_GET['dry-run'] === '') unset($_GET['dry-run']);
if ($_GET['force']   === '') unset($_GET['force']);
if ($_GET['preview'] === '') unset($_GET['preview']);
if ($_GET['user']    === '') unset($_GET['user']);

// Flag d'autorisation requis par scripts/rh_conges_rappel.php pour
// accepter l'invocation HTTP (sinon il refuse avec 403).
define('__RH_RAPPEL_HTTP_OK__', true);

require __DIR__ . '/../scripts/rh_conges_rappel.php';
