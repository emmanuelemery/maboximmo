<?php
declare(strict_types=1);

/**
 * Endpoint de suppression d'une entrée workflow_log salaires
 * (avec son fichier physique sur disque).
 *
 * Cas d'usage : l'utilisateur a uploadé 3 fois le même PDF -> 3 itérations.
 * Il peut supprimer les anciennes versions pour ne garder que la bonne.
 *
 * Effets :
 *  - Supprime la ligne rh_salaire_workflow_log
 *  - Supprime le fichier physique uploads/rh_salaires/.../<file>
 *  - NE TOUCHE PAS aux rh_salaires_comparaisons (qui n'ont pas de lien direct)
 *
 * Sécurité :
 *  - POST + CSRF
 *  - Auth + rh_wf_can_access sur l'agence
 *
 * URL : POST /rh_salaire_workflow_delete.php avec id={id_workflow_log}
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
verify_csrf();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();

$logId = (int)($_POST['id'] ?? 0);
if ($logId <= 0) {
    http_response_code(400);
    exit('Paramètre id manquant.');
}

$st = $pdo->prepare("SELECT id, id_societe, id_agence, fichier_path FROM rh_salaire_workflow_log WHERE id = ? LIMIT 1");
$st->execute([$logId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Log workflow introuvable.');
}

if (!rh_wf_can_access($pdo, $userId, (int)$row['id_agence'])) {
    http_response_code(403);
    exit('Accès refusé.');
}

// Suppression du fichier physique (best-effort, on continue même si fail)
$publicHtml = __DIR__;
if (!empty($row['fichier_path'])) {
    $abs = $publicHtml . '/' . ltrim((string)$row['fichier_path'], '/');
    $real = realpath($abs);
    $expected = realpath($publicHtml . '/uploads/rh_salaires');
    if ($real !== false && $expected !== false && strpos($real, $expected) === 0 && is_file($real)) {
        @unlink($real);
    }
}

// Suppression de la ligne workflow_log
$del = $pdo->prepare("DELETE FROM rh_salaire_workflow_log WHERE id = ?");
$del->execute([$logId]);

// Redirige vers la page rh_salaires (les query string sont preservees pour
// rester sur le bon mois/societe/agence).
$redirect = $_POST['redirect_to'] ?? 'rh_salaires.php';
$_SESSION['message_ok'] = 'Version supprimée de l\'historique.';
header('Location: ' . $redirect);
exit;
