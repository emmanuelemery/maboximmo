<?php
declare(strict_types=1);
/**
 * rh_salaire_validate_projet.php — VALIDATION d'un projet par AGENCE (marquage).
 *
 * Nouveau flux consolidé (2026-07-01) : valider une agence ne déclenche PLUS de
 * mail. Ça enregistre les remarques + marque l'agence validée. Quand TOUTES les
 * agences de la société sont validées, la page propose l'envoi consolidé au
 * comptable (rh_salaire_envoyer_comptable.php) : un seul mail + un seul lien.
 *
 * Sécurité : POST + CSRF + auth + rh_wf_can_access par agence.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
verify_csrf();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$compareId   = (int)($_POST['compare_id'] ?? 0);
$remarques   = trim((string)($_POST['commentaire'] ?? ''));
$redirect    = (string)($_POST['redirect_to'] ?? 'rh_salaires.php');

if ($compareId <= 0) { $_SESSION['message_err'] = 'Paramètre compare_id manquant.'; header('Location: ' . $redirect); exit; }

$st = $pdo->prepare("SELECT id, id_societe, id_agence, mois, annee FROM rh_salaires_comparaisons WHERE id = ? LIMIT 1");
$st->execute([$compareId]);
$cmp = $st->fetch(PDO::FETCH_ASSOC);
if (!$cmp) { $_SESSION['message_err'] = 'Comparaison introuvable.'; header('Location: ' . $redirect); exit; }
if (!rh_wf_can_access($pdo, $userId, (int)$cmp['id_agence'])) { $_SESSION['message_err'] = 'Accès refusé.'; header('Location: ' . $redirect); exit; }

// Marque l'agence validée + enregistre les remarques (sans envoyer de mail).
try {
    $pdo->prepare("UPDATE rh_salaires_comparaisons SET validated_at = NOW(), validated_by = ?, remarques = ? WHERE id = ?")
        ->execute([$userId, $remarques !== '' ? $remarques : null, $compareId]);
} catch (Throwable $e) {
    $_SESSION['message_err'] = 'Erreur enregistrement validation : ' . $e->getMessage();
    header('Location: ' . $redirect); exit;
}

// Trace workflow (sans fichier, sans mail).
try {
    $moisRef = sprintf('%04d-%02d-01', (int)$cmp['annee'], (int)$cmp['mois']);
    rh_wf_log_action($pdo, (int)$cmp['id_societe'], (int)$cmp['id_agence'], $moisRef, RH_WF_TYPE_VALIDATION,
        null, null, null, null, $userId, 'ok', null,
        'Agence validée' . ($remarques !== '' ? ' — ' . mb_substr($remarques, 0, 300) : ''));
} catch (Throwable $e) { /* enum type_action éventuellement manquant : non bloquant */ }

// Toutes les agences (ayant un projet) de la société sont-elles validées ?
$stAll = $pdo->prepare("SELECT id_agence, MAX(validated_at IS NOT NULL) AS v
                        FROM rh_salaires_comparaisons
                        WHERE id_societe = ? AND mois = ? AND annee = ? AND type = 'projet'
                        GROUP BY id_agence");
$stAll->execute([(int)$cmp['id_societe'], (int)$cmp['mois'], (int)$cmp['annee']]);
$rowsAll = $stAll->fetchAll(PDO::FETCH_ASSOC);
$total = count($rowsAll);
$valides = count(array_filter($rowsAll, fn($r) => (int)$r['v'] === 1));
$allValidated = $total > 0 && $valides === $total;

if ($allValidated) {
    // Déclenche l'ouverture du modal d'autorisation d'envoi consolidé au retour.
    $_SESSION['dr_offer_send'] = ['societe' => (int)$cmp['id_societe'], 'mois' => (int)$cmp['mois'], 'annee' => (int)$cmp['annee']];
    $_SESSION['message_ok'] = "✅ Agence validée. Toutes les agences sont validées ($valides/$total) — prêt à envoyer au comptable.";
} else {
    $_SESSION['message_ok'] = "✅ Agence validée ($valides/$total). En attente des autres agences avant l'envoi au comptable.";
}
header('Location: ' . $redirect);
exit;
