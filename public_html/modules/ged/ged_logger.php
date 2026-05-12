<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Logger minimal lisible (fichier).
 * Fichier : modules/ged/ged_logger.php
 *
 * Objectif : garder une trace simple des syncs Drive (succès/erreurs) sans
 * dépendre de syslog/Hostinger. Aucun secret ne doit être loggé.
 */

/**
 * @param array<string,mixed> $context
 */
function gedLog(string $channel, string $message, array $context = []): void
{
    try {
        $root = dirname(__DIR__, 3);
        $dir  = $root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/ged_drive_sync.log';

        // Normalisation context : coupe les valeurs trop longues (ex: stacktrace HTML)
        foreach ($context as $k => $v) {
            if (is_string($v) && mb_strlen($v) > 2000) {
                $context[$k] = mb_substr($v, 0, 2000) . '…';
            }
        }

        $line = json_encode([
            'ts'      => date('c'),
            'channel' => $channel,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE);

        if (!is_string($line)) return;
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // best-effort : pas d'exception depuis le logger
    }
}

