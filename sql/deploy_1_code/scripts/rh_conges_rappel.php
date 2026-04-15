<?php
declare(strict_types=1);

/**
 * RH — ENVOI AUTOMATIQUE DU MAIL DE RAPPEL "SOLDE CONGÉS À PRENDRE"
 * =================================================================
 *
 * Parcourt tous les users actifs avec un email, récupère leur solde
 * congés du mois le plus récent dans `conges_soldes`, et leur envoie
 * un rappel personnalisé avec l'admin en copie (CC).
 *
 * Idempotence : écrit dans `rh_conges_rappel_log` avec une clé unique
 * (annee, id_user). Un relancement dans la même année ne renvoie RIEN
 * aux users déjà traités — sauf `--force` qui ignore le log.
 *
 * UTILISATION
 * -----------
 *   # Cron Linux
 *   0 9 31 1 * php /var/www/public_html/scripts/rh_conges_rappel.php
 *
 *   # Cron Linux — sans attendre le 31 janvier, avec garde "janvier only"
 *   0 9 * * * php /var/www/public_html/scripts/rh_conges_rappel.php --january-only
 *
 *   # Tâche planifiée Windows (XAMPP)
 *   schtasks /Create /TN "MaBoxImmo — Rappel congés" ^
 *     /TR "C:\xampp\php\php.exe C:\xampp\htdocs\MaBoxImmo2026\public_html\scripts\rh_conges_rappel.php" ^
 *     /SC ONCE /SD 31/01/2027 /ST 09:00
 *
 *   # Test à la main (ignore le log, dry-run)
 *   php rh_conges_rappel.php --dry-run
 *
 *   # Forcer le renvoi pour l'année courante
 *   php rh_conges_rappel.php --force
 *
 *   # Envoyer uniquement à un user précis (debug)
 *   php rh_conges_rappel.php --user=42
 *
 * OPTIONS CLI
 * -----------
 *   --dry-run        Simule, n'envoie rien, n'écrit rien dans le log.
 *   --force          Renvoie même si le log existe pour l'année en cours.
 *   --january-only   Ne fait rien si le mois courant n'est pas janvier.
 *                    Permet d'installer un cron quotidien inoffensif.
 *   --user=ID        Cible un seul user (utile pour tester).
 *   --cc=email       Override l'adresse CC (par défaut : CONGES_RAPPEL_CC_EMAIL
 *                    ou premier admin actif).
 *   --no-cc          Désactive le CC pour ce run.
 *
 * MODE HTTP
 * ---------
 * Le script peut aussi être appelé par URL pour un déclenchement manuel
 * depuis le dashboard RH (bouton "Envoyer maintenant"). Accès réservé
 * à l'admin (role_id = 1) + CSRF token. Cf. api/rh_conges_rappel_trigger.php.
 */

$isCli = (PHP_SAPI === 'cli');

// Bootstrap : CLI n'a pas de session ; on charge uniquement la BDD et le mailer.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/mailer.php';

// ─────────────────────────────────────────────────────────
// Parse options CLI (ou paramètres HTTP pour debug/test)
// ─────────────────────────────────────────────────────────
$opts = $isCli
    ? getopt('', ['dry-run', 'force', 'january-only', 'user::', 'cc::', 'no-cc', 'preview'])
    : $_GET;

$dryRun      = $isCli ? array_key_exists('dry-run', $opts)      : !empty($opts['dry-run']);
$force       = $isCli ? array_key_exists('force', $opts)        : !empty($opts['force']);
$januaryOnly = $isCli ? array_key_exists('january-only', $opts) : !empty($opts['january-only']);
$noCc        = $isCli ? array_key_exists('no-cc', $opts)        : !empty($opts['no-cc']);
$preview     = $isCli ? array_key_exists('preview', $opts)      : !empty($opts['preview']);
$filterUser  = isset($opts['user']) && ctype_digit((string)$opts['user']) ? (int)$opts['user'] : 0;
$ccOverride  = isset($opts['cc']) && $opts['cc'] !== '' ? trim((string)$opts['cc']) : null;

// ─────────────────────────────────────────────────────────
// Garde mode HTTP : accès admin + CSRF vérifiés dans l'endpoint trigger.
// Ici on refuse tout accès direct HTTP pour ne pas exposer un spam.
// ─────────────────────────────────────────────────────────
if (!$isCli) {
    // Le script n'est jamais invoqué directement via HTTP : l'endpoint
    // api/rh_conges_rappel_trigger.php définit __RH_RAPPEL_HTTP_OK__
    // après vérification d'auth + CSRF.
    if (!defined('__RH_RAPPEL_HTTP_OK__')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('403 — Utiliser api/rh_conges_rappel_trigger.php pour un déclenchement HTTP autorisé.');
    }
}

// ─────────────────────────────────────────────────────────
// Garde "janvier uniquement" pour les crons quotidiens
// ─────────────────────────────────────────────────────────
$moisCourant = (int) date('n');
if ($januaryOnly && $moisCourant !== 1) {
    rappel_log("[SKIP] Mois courant = {$moisCourant} (--january-only), rien à faire.", $isCli);
    exit(0);
}

$annee = (int) date('Y');
$pdo   = db();

// ─────────────────────────────────────────────────────────
// Résolution de l'adresse en copie (CC admin)
// Priorité : --cc arg > CONGES_RAPPEL_CC_EMAIL > premier admin actif
// ─────────────────────────────────────────────────────────
$ccAdmin = null;
if (!$noCc) {
    if ($ccOverride !== null) {
        $ccAdmin = $ccOverride;
    } elseif (defined('CONGES_RAPPEL_CC_EMAIL') && CONGES_RAPPEL_CC_EMAIL !== '') {
        $ccAdmin = (string) CONGES_RAPPEL_CC_EMAIL;
    } else {
        // Cherche le premier super_admin actif avec un email.
        // La colonne de rôle dans la table `users` est `id_role` (1=admin),
        // et `super_admin` (bool) sert de flag additionnel pour les super-users.
        try {
            $ccAdmin = $pdo->query("
                SELECT email FROM users
                WHERE actif = 1
                  AND (super_admin = 1 OR id_role = 1)
                  AND email IS NOT NULL AND email <> ''
                ORDER BY super_admin DESC, id ASC
                LIMIT 1
            ")->fetchColumn() ?: null;
        } catch (Throwable $e) {
            $ccAdmin = null;
        }
    }
    if ($ccAdmin !== null && !filter_var($ccAdmin, FILTER_VALIDATE_EMAIL)) {
        rappel_log("[WARN] CC invalide : '{$ccAdmin}' — ignoré.", $isCli);
        $ccAdmin = null;
    }
}

// ─────────────────────────────────────────────────────────
// Récupération des users actifs avec leur solde congés
// ─────────────────────────────────────────────────────────
$userFilterSql = $filterUser > 0 ? " AND u.id = " . (int)$filterUser : "";
$sql = "
    SELECT
        u.id                         AS id_user,
        u.prenom                     AS prenom,
        u.nom                        AS nom,
        u.email                      AS email,
        cs.conge_a_prendre           AS conge_a_prendre,
        cs.conge_en_acquisition      AS conge_en_acquisition,
        cs.conge_pris_n              AS conge_pris_n,
        cs.solde_restant             AS solde_restant,
        cs.mois_annee                AS mois_annee
    FROM users u
    LEFT JOIN (
        SELECT cs1.*
        FROM conges_soldes cs1
        INNER JOIN (
            SELECT id_user, MAX(mois_annee) AS mois_max
            FROM conges_soldes
            GROUP BY id_user
        ) cs2 ON cs2.id_user = cs1.id_user AND cs2.mois_max = cs1.mois_annee
    ) cs ON cs.id_user = u.id
    WHERE u.actif = 1
      AND u.email IS NOT NULL
      AND u.email <> ''
      {$userFilterSql}
    ORDER BY u.nom, u.prenom
";
try {
    $users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    rappel_log("[FATAL] SQL users/soldes : " . $e->getMessage(), $isCli);
    exit(2);
}

if (empty($users)) {
    rappel_log("[INFO] Aucun user à traiter.", $isCli);
    exit(0);
}

// ─────────────────────────────────────────────────────────
// Mode PREVIEW : retourne le HTML rendu pour UN seul user,
// sans rien envoyer ni logger. Destiné à l'aperçu dans l'UI
// avant déclenchement de l'envoi réel.
// ─────────────────────────────────────────────────────────
if ($preview) {
    // Si --user=ID n'est pas fourni, on prend le premier user de la liste.
    $target = $filterUser > 0
        ? array_values(array_filter($users, fn($u) => (int)$u['id_user'] === $filterUser))[0] ?? null
        : $users[0];

    if (!$target) {
        $payload = ['ok' => false, 'error' => "Aucun user cible (id={$filterUser})"];
    } else {
        $nom      = trim(($target['prenom'] ?? '') . ' ' . ($target['nom'] ?? ''));
        $aPrendre = (float)($target['conge_a_prendre']      ?? 0);
        $enAcq    = (float)($target['conge_en_acquisition'] ?? 0);
        $prisN    = (float)($target['conge_pris_n']         ?? 0);
        $restant  = (float)($target['solde_restant']        ?? 0);

        $subject = "Rappel congés — votre solde à prendre cette année";
        $html    = render_mail_body($nom, $aPrendre, $enAcq, $prisN, $restant, $annee);

        $payload = [
            'ok'      => true,
            'preview' => true,
            'annee'   => $annee,
            'user'    => [
                'id'              => (int)$target['id_user'],
                'nom'             => $nom,
                'email'           => (string)$target['email'],
                'conge_a_prendre' => $aPrendre,
                'en_acquisition'  => $enAcq,
                'pris_n'          => $prisN,
                'solde_restant'   => $restant,
            ],
            'subject' => $subject,
            'to'      => (string)$target['email'],
            'cc'      => $ccAdmin,
            'html'    => $html,
            // Liste des users disponibles pour le dropdown de l'UI
            'all_users' => array_map(static fn($u) => [
                'id'    => (int)$u['id_user'],
                'label' => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) . ' <' . $u['email'] . '>',
            ], $users),
        ];
    }

    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit(0);
}

// ─────────────────────────────────────────────────────────
// Résolution des users déjà envoyés cette année (idempotence)
// ─────────────────────────────────────────────────────────
$alreadySent = [];
if (!$force && !$dryRun) {
    try {
        $stmt = $pdo->prepare("SELECT id_user FROM rh_conges_rappel_log WHERE annee = ? AND status = 'ok'");
        $stmt->execute([$annee]);
        $alreadySent = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable) { /* table absente → rien à exclure */ }
}

// ─────────────────────────────────────────────────────────
// Envoi
// ─────────────────────────────────────────────────────────
rappel_log("[START] annee={$annee}  users=" . count($users)
           . "  déjà-envoyés=" . count($alreadySent)
           . "  cc=" . ($ccAdmin ?: '(aucun)')
           . ($dryRun ? "  DRY-RUN" : ""), $isCli);

$ok = 0; $skipped = 0; $errors = 0;
$logStmt = $pdo->prepare("
    INSERT INTO rh_conges_rappel_log
        (annee, id_user, email, solde_restant, conge_a_prendre, status, error_msg)
    VALUES
        (:an, :uid, :em, :sr, :ap, :st, :err)
    ON DUPLICATE KEY UPDATE
        status        = VALUES(status),
        error_msg     = VALUES(error_msg),
        solde_restant = VALUES(solde_restant),
        sent_at       = CURRENT_TIMESTAMP
");

foreach ($users as $u) {
    $uid   = (int)$u['id_user'];
    $email = trim((string)$u['email']);
    $nom   = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        rappel_log("  [SKIP] {$nom} — email invalide : '{$email}'", $isCli);
        $skipped++;
        continue;
    }
    if (isset($alreadySent[$uid])) {
        rappel_log("  [SKIP] {$nom} — déjà envoyé en {$annee}", $isCli);
        $skipped++;
        continue;
    }

    // Valeurs numériques (gérer NULL si le user n'a pas de ligne dans conges_soldes)
    $aPrendre = isset($u['conge_a_prendre'])      ? (float)$u['conge_a_prendre']      : 0.0;
    $enAcq    = isset($u['conge_en_acquisition']) ? (float)$u['conge_en_acquisition'] : 0.0;
    $prisN    = isset($u['conge_pris_n'])         ? (float)$u['conge_pris_n']         : 0.0;
    $restant  = isset($u['solde_restant'])        ? (float)$u['solde_restant']        : 0.0;

    $subject = "Rappel congés — votre solde à prendre cette année";
    $body    = render_mail_body($nom, $aPrendre, $enAcq, $prisN, $restant, $annee);

    if ($dryRun) {
        rappel_log("  [DRY] {$nom} <{$email}>  a_prendre={$aPrendre}  restant={$restant}"
                   . ($ccAdmin ? "  cc={$ccAdmin}" : ""), $isCli);
        $ok++;
        continue;
    }

    $sent = send_mail(
        $email,
        $subject,
        $body,
        [],                       // pas de pièce jointe
        true,                     // HTML
        $ccAdmin ?? '',           // CC admin
        '',                       // reply-to
        '',                       // from override
        ''                        // from name override
    );

    if ($sent) {
        rappel_log("  [OK]   {$nom} <{$email}>  restant={$restant}j", $isCli);
        $logStmt->execute([
            ':an'  => $annee, ':uid' => $uid, ':em' => $email,
            ':sr'  => $restant, ':ap' => $aPrendre,
            ':st'  => 'ok', ':err' => null,
        ]);
        $ok++;
    } else {
        rappel_log("  [FAIL] {$nom} <{$email}>", $isCli);
        $logStmt->execute([
            ':an'  => $annee, ':uid' => $uid, ':em' => $email,
            ':sr'  => $restant, ':ap' => $aPrendre,
            ':st'  => 'error', ':err' => 'send_mail() returned false',
        ]);
        $errors++;
    }
}

rappel_log("[END] ok={$ok}  skipped={$skipped}  errors={$errors}"
           . ($dryRun ? "  (dry-run)" : ""), $isCli);

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => $errors === 0,
        'annee'   => $annee,
        'sent'    => $ok,
        'skipped' => $skipped,
        'errors'  => $errors,
        'dry_run' => $dryRun,
        'cc'      => $ccAdmin,
    ], JSON_UNESCAPED_UNICODE);
}
exit($errors === 0 ? 0 : 1);


// =====================================================================
// Helpers
// =====================================================================

function rappel_log(string $msg, bool $isCli): void {
    if ($isCli) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$msg}\n");
    } else {
        error_log('[rh_conges_rappel] ' . $msg);
    }
}

function render_mail_body(string $nom, float $aPrendre, float $enAcq, float $prisN, float $restant, int $annee): string {
    $prenomNom = htmlspecialchars($nom, ENT_QUOTES, 'UTF-8');
    $f = static fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');

    return '<!doctype html>
<html lang="fr"><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;color:#2a2622;max-width:600px;margin:0 auto;padding:24px;background:#f5f1ea;">
  <div style="background:#ffffff;border-radius:12px;padding:28px 28px 24px;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
    <h1 style="margin:0 0 6px;font-size:20px;color:#2f587d;">Rappel — Congés à prendre ' . $annee . '</h1>
    <p style="margin:0 0 20px;color:#8a8680;font-size:13px;">Bonjour ' . $prenomNom . ',</p>
    <p style="font-size:14px;line-height:1.55;">
      Voici le récapitulatif de votre solde de congés pour l\'année en cours. Nous vous invitons
      à <strong>poser et planifier</strong> vos jours restants avec votre manager afin de les consommer
      avant la fin de la période de prise de congés.
    </p>

    <table cellpadding="12" cellspacing="0" style="width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;">
      <tr style="background:#f0ebe3;">
        <td style="border:1px solid #e2dcd2;"><strong>Congés à prendre</strong></td>
        <td style="border:1px solid #e2dcd2;text-align:right;font-size:16px;color:#8a5040;"><strong>' . $f($aPrendre) . '</strong> jours</td>
      </tr>
      <tr>
        <td style="border:1px solid #e2dcd2;">En acquisition</td>
        <td style="border:1px solid #e2dcd2;text-align:right;">' . $f($enAcq) . ' jours</td>
      </tr>
      <tr style="background:#f8f5ef;">
        <td style="border:1px solid #e2dcd2;">Déjà pris cette année</td>
        <td style="border:1px solid #e2dcd2;text-align:right;">' . $f($prisN) . ' jours</td>
      </tr>
      <tr style="background:#e8f0e0;">
        <td style="border:1px solid #e2dcd2;"><strong>Solde restant</strong></td>
        <td style="border:1px solid #e2dcd2;text-align:right;font-size:18px;color:#4a6038;"><strong>' . $f($restant) . '</strong> jours</td>
      </tr>
    </table>

    <div style="background:#fff7e6;border-left:4px solid #f59e0b;padding:12px 14px;margin:18px 0;border-radius:6px;font-size:13px;color:#6a4820;">
      <strong>💡 Règles de prise de congés :</strong><br>
      • Les congés se prennent par journée ou demi-journée entière.<br>
      • Toute demande doit être soumise à votre manager au minimum 2 semaines à l\'avance.<br>
      • Les soldes non consommés à la fin de la période sont perdus sauf accord exceptionnel.
    </div>

    <p style="font-size:13px;color:#6a6560;">
      Pour consulter le détail et poser vos congés, rendez-vous dans votre espace personnel Ma Box RH.
    </p>

    <hr style="border:none;border-top:1px solid #e2dcd2;margin:22px 0;">
    <p style="font-size:11px;color:#a8a49e;margin:0;">
      Cet email est envoyé automatiquement par Ma Box RH. Pour toute question,
      rapprochez-vous de votre manager ou du service RH.
    </p>
  </div>
</body></html>';
}
