<?php
declare(strict_types=1);

/**
 * Endpoint de validation d'un projet de paie comptable.
 *
 * L'agent valide le projet uploade par le comptable -> envoi mail retour
 * avec :
 *   - le PDF du projet original (en piece jointe)
 *   - un commentaire libre de l'agent (corps mail)
 *   - sujet pre-rempli "Validation projet salaires <agence> <mois> <annee>"
 *
 * Effets :
 *   - Envoi mail (sauf en dev/local : simulation)
 *   - Workflow_log entree type_action = 'validation_projet' avec le PDF en
 *     reference + le commentaire dans error_msg/commentaire
 *   - Le PDF est conserve sur disque sous uploads/rh_salaires/<soc>/<ag>/<mois>/validation_projet/
 *
 * Securite : POST + CSRF + auth + rh_wf_can_access par agence.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_once __DIR__ . '/inc/mailer.php';
require_login();

// Helper local : mois_fr() est defini dans rh_salaires.php mais pas exposé
// dans un include partage. On le redeclare ici pour autonomie de l'endpoint.
if (!function_exists('mois_fr')) {
    function mois_fr(int $m): string {
        $n = [1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        return $n[$m] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
verify_csrf();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();

$compareId  = (int)($_POST['compare_id'] ?? 0);
$commentaire = trim((string)($_POST['commentaire'] ?? ''));
$redirect   = (string)($_POST['redirect_to'] ?? 'rh_salaires.php');

if ($compareId <= 0) {
    $_SESSION['message_err'] = 'Paramètre compare_id manquant.';
    header('Location: ' . $redirect);
    exit;
}

// Charge la comparaison + le PDF
$st = $pdo->prepare("SELECT id, id_societe, id_agence, mois, annee, file_path, file_name FROM rh_salaires_comparaisons WHERE id = ? LIMIT 1");
$st->execute([$compareId]);
$cmp = $st->fetch(PDO::FETCH_ASSOC);
if (!$cmp || empty($cmp['file_path'])) {
    $_SESSION['message_err'] = 'Comparaison ou PDF introuvable.';
    header('Location: ' . $redirect);
    exit;
}

if (!rh_wf_can_access($pdo, $userId, (int)$cmp['id_agence'])) {
    $_SESSION['message_err'] = 'Accès refusé.';
    header('Location: ' . $redirect);
    exit;
}

// Resolution PDF physique
$publicHtml = __DIR__;
$absPdf = $publicHtml . '/' . ltrim((string)$cmp['file_path'], '/');
$realPdf = realpath($absPdf);
$expected = realpath($publicHtml . '/uploads/salaires_comptable');
if ($realPdf === false || $expected === false || strpos($realPdf, $expected) !== 0 || !is_file($realPdf)) {
    $_SESSION['message_err'] = 'PDF introuvable sur le serveur.';
    header('Location: ' . $redirect);
    exit;
}

// Resolution destinataire comptable + nom agence
$stSoc = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
$stSoc->execute([(int)$cmp['id_societe']]);
$societe = $stSoc->fetch(PDO::FETCH_ASSOC) ?: [];
$comptableEmail = '';
foreach (['comptable_email', 'email_comptable', 'comptable_mail'] as $k) {
    if (!empty($societe[$k])) { $comptableEmail = (string)$societe[$k]; break; }
}
if ($comptableEmail === '' || !filter_var($comptableEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['message_err'] = 'Email comptable manquant pour cette société.';
    header('Location: ' . $redirect);
    exit;
}

$stAg = $pdo->prepare("SELECT nom_agence FROM agences WHERE id = ? LIMIT 1");
$stAg->execute([(int)$cmp['id_agence']]);
$nomAgence = (string)($stAg->fetchColumn() ?: ('Agence_' . $cmp['id_agence']));
$moisLabel = mois_fr((int)$cmp['mois']);

// Mail : le commentaire saisi par l'agent EST le corps du mail (controle total).
// Si vide, fallback sur un message generique.
$subject = "Validation projet salaires — $nomAgence — $moisLabel " . (int)$cmp['annee'];
if ($commentaire !== '') {
    $body = $commentaire;
} else {
    $comptableNom = trim((string)($societe['comptable_nom'] ?? ''));
    $bonjour = $comptableNom !== ''
        ? 'Bonjour ' . trim((string)preg_split('/\s+/', $comptableNom)[0])
        : 'Bonjour';
    $body = "$bonjour,\n\nC'est OK pour ce projet ($nomAgence — $moisLabel " . (int)$cmp['annee'] . "), merci de valider et envoyer les bulletins dans digiposte.\n\nJe reste dans l'attente des bulletins définitifs pour mon dossier.\n\nÀ plus tard,\nEmmanuel";
}

$hostNow = (string)($_SERVER['HTTP_HOST'] ?? '');
$isDevOrLocal = (
    str_contains($hostNow, 'dev.maboximmo')
    || str_contains($hostNow, 'localhost')
    || str_contains($hostNow, '127.0.0.1')
);
$ccDirection = 'emmanuel.emery@regie-emery.com';

if ($isDevOrLocal) {
    $ok = true;
    $devMessage = ' (mode test dev — mail NON envoyé)';
} else {
    $ok = send_mail($comptableEmail, $subject, $body, [$realPdf], false, $ccDirection, 'salaire@maboximmo.fr');
    $devMessage = '';
}

if (!$ok) {
    $_SESSION['message_err'] = 'Erreur lors de l\'envoi du mail de validation.';
    header('Location: ' . $redirect);
    exit;
}

// Workflow log : sauvegarder une copie du PDF dans validation_projet/ + log.
// Wrap dans try/catch : si la migration SQL #3 (ajout 'validation_projet'
// a l'enum type_action) n'a pas ete appliquee, l'INSERT echouera. On ne
// veut pas faire planter la page (le mail est deja parti) -> on log
// l'erreur et on prevent l'utilisateur via message_warn.
$logWarning = null;
try {
    $moisRefLog = sprintf('%04d-%02d-01', (int)$cmp['annee'], (int)$cmp['mois']);
    $content = @file_get_contents($realPdf);
    $relPath = null;
    if ($content !== false) {
        $iter = rh_wf_next_iteration($pdo, (int)$cmp['id_agence'], $moisRefLog, RH_WF_TYPE_VALIDATION);
        $relPath = rh_wf_save_file(
            (int)$cmp['id_societe'], (int)$cmp['id_agence'], $moisRefLog, RH_WF_TYPE_VALIDATION,
            $iter, $content, $cmp['file_name'] ?: basename($realPdf)
        );
    }
    $cmtForLog = $commentaire !== '' ? mb_substr($commentaire, 0, 480) : 'Validation sans commentaire';
    rh_wf_log_action(
        $pdo, (int)$cmp['id_societe'], (int)$cmp['id_agence'], $moisRefLog, RH_WF_TYPE_VALIDATION,
        $relPath, $cmp['file_name'], $content !== false ? strlen($content) : null, $comptableEmail,
        $userId, 'ok',
        $isDevOrLocal ? '🧪 Mode test (mail non envoyé)' : null,
        $cmtForLog
    );
} catch (Throwable $e) {
    error_log('[rh_salaire_validate_projet] Workflow log failed : ' . $e->getMessage());
    $logWarning = 'Mail envoyé mais historique non écrit (migration SQL #3 manquante ?) — erreur : ' . $e->getMessage();
}

if ($logWarning) {
    $_SESSION['message_err'] = '⚠️ ' . $logWarning;
} else {
    $_SESSION['message_ok'] = 'Projet validé et envoyé au comptable ✅' . $devMessage;
}
header('Location: ' . $redirect);
exit;
