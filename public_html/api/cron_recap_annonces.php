<?php
// api/cron_recap_annonces.php
// Endpoint déclenché par le cron Hostinger chaque matin à 9h00.
// Envoie le récap individuel à chaque commercial actif + récap global au super-admin.
//
// Appel cron :
//   0 9 * * *  curl -s "https://maboximmo.fr/api/cron_recap_annonces.php?token=XXX" > /dev/null
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/mailer.php';
require_once dirname(__DIR__) . '/inc/recap_mailer.php';

// Config cron (token + emails) — fichier config/cron.php gitignored
$cronConfig = dirname(__DIR__) . '/config/cron.php';
if (file_exists($cronConfig)) {
    require_once $cronConfig;
}

header('Content-Type: application/json; charset=utf-8');

// ─── Auth par token ─────────────────────────────────────────────────
$expected = defined('CRON_RECAP_TOKEN') ? (string)CRON_RECAP_TOKEN : '';
$got      = (string)($_GET['token'] ?? '');
if ($expected === '' || $got === '' || !hash_equals($expected, $got)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Token invalide ou config absente']));
}

$pdo       = $GLOBALS['pdo'];
$adminMail = defined('CRON_RECAP_ADMIN_EMAIL') ? (string)CRON_RECAP_ADMIN_EMAIL : 'emmanuel.emery@regie-emery.com';
$fromEmail = defined('CRON_RECAP_FROM_EMAIL')  ? (string)CRON_RECAP_FROM_EMAIL  : 'ne-pas-repondre@maboximmo.fr';
$fromName  = defined('CRON_RECAP_FROM_NAME')   ? (string)CRON_RECAP_FROM_NAME   : 'MaBoxImmo';

$window = recap_window_from_now();

// ─── Log début exécution ────────────────────────────────────────────
$logId = null;
try {
    $stL = $pdo->prepare("
        INSERT INTO cron_recap_log (started_at, window_start, window_end, window_hours)
        VALUES (NOW(), :s, :e, :h)
    ");
    $stL->execute([
        ':s' => $window['start_sql'],
        ':e' => $window['end_sql'],
        ':h' => $window['hours'],
    ]);
    $logId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('[cron_recap_annonces] log insert: ' . $e->getMessage());
}

// ─── Collecte + envoi ────────────────────────────────────────────────
$candidates = recap_collect_candidates($pdo, $window);
$nbSent = 0; $nbFail = 0; $nbConges = 0; $nbNoActivity = 0;
$details = [];

foreach ($candidates as $u) {
    $uid = (int)$u['id'];
    $email = trim((string)$u['email']);
    $prenom = (string)$u['prenom'];
    $nom    = (string)$u['nom'];

    // Skip si en congés validé aujourd'hui
    if (recap_user_is_en_conges($pdo, $uid, $window['end_sql'])) {
        $nbConges++;
        $details[] = ['uid' => $uid, 'email' => $email, 'status' => 'skipped_conges'];
        continue;
    }

    $activity = recap_collect_user_activity($pdo, $uid, $window);
    $counts   = $activity['counts'];
    $total    = $counts['diffusees'] + $counts['nouvelles'] + $counts['archivees'] + $counts['supprimees'];

    // Skip si aucune activité ET aucune annonce en cours
    if ($total === 0 && $counts['en_cours'] === 0) {
        $nbNoActivity++;
        $details[] = ['uid' => $uid, 'email' => $email, 'status' => 'skipped_no_activity'];
        continue;
    }

    $subject = '🏠 Bonjour ' . $prenom . ' — votre récap MaBoxImmo du matin';
    $html    = recap_build_email_user($pdo, $u, $activity, $window);
    $ok      = send_mail($email, $subject, $html, [], true, '', '', $fromEmail, $fromName);

    if ($ok) {
        $nbSent++;
        $details[] = ['uid' => $uid, 'email' => $email, 'status' => 'sent', 'counts' => $counts];
    } else {
        $nbFail++;
        $details[] = ['uid' => $uid, 'email' => $email, 'status' => 'send_failed'];
    }
}

// ─── Récap global → super-admin ────────────────────────────────────
$globalData = recap_collect_global($pdo, $window);
$globalSubject = '📊 Récap global MaBoxImmo — ' . $window['day_fr'];
$globalHtml    = recap_build_email_global($globalData, $window);
$globalOk      = send_mail($adminMail, $globalSubject, $globalHtml, [], true, '', '', $fromEmail, $fromName);
$details[] = ['uid' => 0, 'email' => $adminMail, 'status' => $globalOk ? 'sent_global' : 'global_failed'];
if ($globalOk) $nbSent++; else $nbFail++;

// ─── Log final ──────────────────────────────────────────────────────
if ($logId) {
    try {
        $stU = $pdo->prepare("
            UPDATE cron_recap_log
            SET ended_at = NOW(),
                mails_envoyes = :s,
                mails_echecs = :f,
                users_skipped_conges = :c,
                users_sans_activite = :n,
                details_json = :d
            WHERE id = :id
        ");
        $stU->execute([
            ':s' => $nbSent,
            ':f' => $nbFail,
            ':c' => $nbConges,
            ':n' => $nbNoActivity,
            ':d' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ':id'=> $logId,
        ]);
    } catch (Throwable $e) {
        error_log('[cron_recap_annonces] log update: ' . $e->getMessage());
    }
}

exit(json_encode([
    'ok' => true,
    'window'          => ['hours' => $window['hours'], 'start' => $window['start_sql'], 'end' => $window['end_sql']],
    'candidates'      => count($candidates),
    'mails_envoyes'   => $nbSent,
    'mails_echecs'    => $nbFail,
    'skipped_conges'  => $nbConges,
    'skipped_inactifs'=> $nbNoActivity,
    'log_id'          => $logId,
], JSON_UNESCAPED_UNICODE));
