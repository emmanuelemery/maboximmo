<?php
declare(strict_types=1);

/**
 * Hook GED — création automatique des boîtes mail dans la GED.
 *
 * Pour chaque adresse email enregistrée dans `users.email` (et plus tard
 * dans la table d'aliases du module MAIL.MBI), on crée un nœud N4
 * dans la cascade GED :
 *   11_MAILS_COMMUNICATIONS > MAILS_ENTRANTS > BOITE_MAIL > {email-slug}
 *   11_MAILS_COMMUNICATIONS > MAILS_SORTANTS > BOITE_MAIL > {email-slug}
 *
 * Le N3 placeholder `BOITE_MAIL` (is_entity_placeholder=1) est déjà seedé.
 * Cette fonction matérialise les instances par adresse email.
 *
 * Usage :
 *   require_once __DIR__ . '/ged_user_mail_hook.php';
 *
 *   // À l'inscription d'un user
 *   ged_user_mail_register($pdo, 'jean.dupont@regie-emery.com', 'Jean DUPONT');
 *
 *   // À la migration initiale (one-shot, idempotent)
 *   ged_user_mail_register_all_existing($pdo);
 *
 *   // À la suppression / désactivation
 *   ged_user_mail_deactivate($pdo, 'jean.dupont@regie-emery.com');
 *
 * Idempotent : INSERT IGNORE + UPDATE conditionnel.
 */

if (!function_exists('ged_user_mail_email_to_code')) {
    /**
     * Convertit une adresse email en code N4 stable.
     * Ex : 'Jean.DUPONT+test@regie-emery.com' → 'JEAN_DUPONT_REGIE_EMERY_COM'
     */
    function ged_user_mail_email_to_code(string $email): string
    {
        $email = strtolower(trim($email));
        $email = preg_replace('/\+[^@]+@/', '@', $email); // strip +tag
        $code = preg_replace('/[^a-z0-9]+/i', '_', $email);
        $code = trim((string)$code, '_');
        return strtoupper($code);
    }
}

if (!function_exists('ged_user_mail_register')) {
    /**
     * Crée (ou réactive) la boîte mail dans la cascade GED pour 1 adresse email.
     * Crée les 2 instances : MAILS_ENTRANTS et MAILS_SORTANTS.
     *
     * @return int Nombre de codes créés (0 si déjà existants, max 2 par appel)
     */
    function ged_user_mail_register(PDO $pdo, string $email, ?string $displayName = null): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }

        $code  = ged_user_mail_email_to_code($email);
        $label = $displayName !== null && trim($displayName) !== ''
            ? trim($displayName) . ' (' . $email . ')'
            : $email;

        // Position alphabétique : on prend le 1er char du code (A=1, B=2, ...)
        $position = max(1, ord($code[0] ?? 'A') - 64);

        $st = $pdo->prepare("
            INSERT IGNORE INTO `ged_level_codes`
              (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`, `is_active`)
            VALUES (NULL, 4, '11_MAILS_COMMUNICATIONS', :n2, 'BOITE_MAIL', :code, :label, :position, 1)
        ");

        $created = 0;
        foreach (['MAILS_ENTRANTS', 'MAILS_SORTANTS'] as $n2) {
            $st->execute([
                ':n2' => $n2, ':code' => $code, ':label' => $label, ':position' => $position,
            ]);
            $created += $st->rowCount();
        }

        // Réactive si désactivé précédemment (changement d'email ou réinscription)
        $upd = $pdo->prepare("
            UPDATE `ged_level_codes`
            SET `is_active` = 1, `label` = :label
            WHERE `level_number` = 4
              AND `parent_n1` = '11_MAILS_COMMUNICATIONS'
              AND `parent_n2` IN ('MAILS_ENTRANTS','MAILS_SORTANTS')
              AND `parent_n3` = 'BOITE_MAIL'
              AND `code` = :code
        ");
        $upd->execute([':label' => $label, ':code' => $code]);

        return $created;
    }
}

if (!function_exists('ged_user_mail_deactivate')) {
    /**
     * Désactive la boîte mail (soft delete via is_active=0) — l'historique des docs reste.
     */
    function ged_user_mail_deactivate(PDO $pdo, string $email): int
    {
        $code = ged_user_mail_email_to_code(strtolower(trim($email)));
        if ($code === '') return 0;

        $st = $pdo->prepare("
            UPDATE `ged_level_codes`
            SET `is_active` = 0
            WHERE `level_number` = 4
              AND `parent_n1` = '11_MAILS_COMMUNICATIONS'
              AND `parent_n2` IN ('MAILS_ENTRANTS','MAILS_SORTANTS')
              AND `parent_n3` = 'BOITE_MAIL'
              AND `code` = :code
        ");
        $st->execute([':code' => $code]);
        return $st->rowCount();
    }
}

if (!function_exists('ged_user_mail_register_all_existing')) {
    /**
     * Migration one-shot : parcourt la table users et crée les boîtes mail.
     * Idempotent (INSERT IGNORE). Appelable manuellement après _v2_25.
     *
     * @return array{processed:int, created:int, skipped:int, errors:array}
     */
    function ged_user_mail_register_all_existing(PDO $pdo): array
    {
        $processed = 0;
        $created   = 0;
        $skipped   = 0;
        $errors    = [];

        // On suppose users(id, email, nom, prenom). Adapte si schéma différent.
        try {
            $st = $pdo->query("
                SELECT id, email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', prenom, nom)), ''), email) AS display_name
                FROM users
                WHERE email IS NOT NULL AND email <> ''
            ");
        } catch (Throwable $e) {
            return ['processed' => 0, 'created' => 0, 'skipped' => 0,
                    'errors' => ['users table read failed: ' . $e->getMessage()]];
        }

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $processed++;
            try {
                $n = ged_user_mail_register($pdo, (string)$row['email'], (string)($row['display_name'] ?? ''));
                if ($n > 0) $created += $n; else $skipped++;
            } catch (Throwable $e) {
                $errors[] = "user#{$row['id']} ({$row['email']}): " . $e->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'created'   => $created,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }
}
