<?php
/**
 * inc/ged_file_path.php — Résolution du chemin disque physique d'un document GED.
 *
 * Même cascade que api/ged_doc_serve.php :
 *   1. fluxbox_documents.fichier_chemin (legacy, chemin absolu)
 *   2. ged_documents.final_destination (relatif → public_html)
 *   3. metadata.public_url (relatif → public_html)
 *   4. metadata.source_path (absolu)
 *
 * Retourne le chemin absolu lisible, ou null si introuvable.
 */
declare(strict_types=1);

if (!function_exists('ged_file_path')) {
    function ged_file_path(PDO $pdo, int $docId): ?string {
        if ($docId <= 0) return null;
        $st = $pdo->prepare("SELECT id, name_file, final_destination, metadata, fluxbox_source_id
                               FROM ged_documents WHERE id = ? LIMIT 1");
        $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return null;

        $publicHtml = dirname(__DIR__); // .../public_html
        $path = null;

        // 1. Legacy fluxbox
        if (!empty($doc['fluxbox_source_id'])) {
            try {
                $stF = $pdo->prepare("SELECT fichier_chemin FROM fluxbox_documents WHERE id = ?");
                $stF->execute([(int)$doc['fluxbox_source_id']]);
                $cand = (string)$stF->fetchColumn();
                if ($cand && is_file($cand)) $path = $cand;
            } catch (Throwable $e) { /* table absente possible */ }
        }
        // 2. final_destination (relatif)
        if (!$path && !empty($doc['final_destination'])) {
            $cand = $publicHtml . '/' . ltrim((string)$doc['final_destination'], '/');
            if (is_file($cand)) $path = $cand;
        }
        // 3 & 4. metadata
        if (!$path && !empty($doc['metadata'])) {
            $meta = json_decode((string)$doc['metadata'], true) ?: [];
            $pub = (string)($meta['public_url'] ?? '');
            if ($pub !== '') {
                $cand = $publicHtml . '/' . ltrim($pub, '/');
                if (is_file($cand)) $path = $cand;
            }
            if (!$path) {
                $sp = (string)($meta['source_path'] ?? '');
                if ($sp !== '' && is_file($sp)) $path = $sp;
            }
        }
        return ($path && is_readable($path)) ? $path : null;
    }
}
