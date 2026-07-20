<?php
/**
 * inc/ged_durable.php — Copie DURABLE d'un document GED, indépendante de la pile FluxBox.
 *
 * PROBLÈME résolu : un doc classé en GED via l'auto-commit FluxBox n'a longtemps eu
 * qu'un pointeur `ged_documents.fluxbox_source_id → fluxbox_documents.fichier_chemin`.
 * Supprimer la ligne/le fichier de la pile (doublon, purge, « supprimer » MaBoxOffice)
 * orphelinait alors le doc GED → « Fichier physique introuvable ».
 *
 * Principe : à la validation GED (et, en filet, juste avant toute suppression de pile),
 * on copie le binaire dans un emplacement PROPRIÉTÉ de la GED (`uploads/ged/…`) et on
 * renseigne `ged_documents.final_destination`. Dès lors le doc GED ne dépend plus de la
 * survie de la ligne FluxBox : on peut supprimer librement dans la pile.
 *
 * Aucune migration : `final_destination` (relatif à public_html) + dossier `uploads/ged/`.
 */
declare(strict_types=1);

if (!function_exists('ged_flux_src_abspath')) {
    /** Résout le chemin absolu d'un `fichier_chemin` FluxBox (absolu, ou relatif à storage_fluxbox). */
    function ged_flux_src_abspath(string $fichierChemin): string {
        $fc = trim($fichierChemin);
        if ($fc === '') return '';
        // Chemin déjà absolu (Windows "C:\" ou POSIX "/") → tel quel.
        if (preg_match('#^([A-Za-z]:|/)#', $fc)) return $fc;
        return dirname(__DIR__) . '/storage_fluxbox/' . ltrim($fc, '/');
    }
}

if (!function_exists('ged_persist_own_copy')) {
    /**
     * Copie $srcAbs dans uploads/ged/{tenant}/{Y}/{m}/ et retourne le chemin RELATIF
     * (depuis public_html), ou null si échec. Ne modifie pas la BDD.
     */
    function ged_persist_own_copy(int $gedDocId, string $srcAbs, ?int $tenantId): ?string {
        if ($gedDocId <= 0 || $srcAbs === '' || !is_file($srcAbs)) return null;
        $publicHtml = dirname(__DIR__);
        $ten = $tenantId !== null && $tenantId > 0 ? $tenantId : 0;
        $rel = 'uploads/ged/' . $ten . '/' . date('Y') . '/' . date('m');
        $destDir = $publicHtml . '/' . $rel;
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
        if (!is_dir($destDir)) return null;
        $ext  = strtolower((string)pathinfo($srcAbs, PATHINFO_EXTENSION));
        $name = $gedDocId . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
        $destAbs = $destDir . '/' . $name;
        if (!@copy($srcAbs, $destAbs)) return null;
        return $rel . '/' . $name;
    }
}

if (!function_exists('ged_doc_has_own_copy')) {
    /** true si le doc a déjà une copie servable propre (final_destination existant sur disque). */
    function ged_doc_has_own_copy(?string $finalDestination): bool {
        $fd = (string)($finalDestination ?? '');
        if ($fd === '') return false;
        return is_file(dirname(__DIR__) . '/' . ltrim($fd, '/'));
    }
}

if (!function_exists('ged_ensure_own_copy')) {
    /**
     * Garantit qu'un doc GED précis possède sa copie durable, à partir d'un chemin source connu.
     * Appelé en post-commit d'auto-commit FluxBox. Retourne le chemin relatif posé, ou null.
     */
    function ged_ensure_own_copy(PDO $pdo, int $gedDocId, string $srcAbs, ?int $tenantId): ?string {
        if ($gedDocId <= 0) return null;
        try {
            $st = $pdo->prepare("SELECT final_destination FROM ged_documents WHERE id = ?");
            $st->execute([$gedDocId]);
            if (ged_doc_has_own_copy((string)$st->fetchColumn())) return null; // déjà couvert
        } catch (Throwable $e) { return null; }
        $rel = ged_persist_own_copy($gedDocId, $srcAbs, $tenantId);
        if ($rel === null) return null;
        try {
            $pdo->prepare("UPDATE ged_documents SET final_destination = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$rel, $gedDocId]);
        } catch (Throwable $e) { return null; }
        return $rel;
    }
}

if (!function_exists('ged_ensure_durable_from_fluxbox')) {
    /**
     * FILET DE SÉCURITÉ avant toute suppression d'une ligne/fichier FluxBox : sauvegarde
     * une copie durable pour CHAQUE doc GED actif qui dépend encore de cette source
     * (final_destination vide/introuvable). Ainsi la suppression pile ne casse rien.
     * Retourne le nombre de docs GED sauvés.
     */
    function ged_ensure_durable_from_fluxbox(PDO $pdo, int $fluxboxDocId): int {
        if ($fluxboxDocId <= 0) return 0;
        try {
            $st = $pdo->prepare("SELECT fichier_chemin FROM fluxbox_documents WHERE id = ?");
            $st->execute([$fluxboxDocId]);
            $srcAbs = ged_flux_src_abspath((string)$st->fetchColumn());
        } catch (Throwable $e) { return 0; }
        if ($srcAbs === '' || !is_file($srcAbs)) return 0; // rien à sauver

        try {
            $st = $pdo->prepare("SELECT id, tenant_id, final_destination
                                   FROM ged_documents
                                  WHERE fluxbox_source_id = ? AND COALESCE(status,'active') = 'active'");
            $st->execute([$fluxboxDocId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return 0; }

        $rescued = 0;
        foreach ($rows as $d) {
            if (ged_doc_has_own_copy((string)($d['final_destination'] ?? ''))) continue;
            $rel = ged_persist_own_copy((int)$d['id'], $srcAbs, $d['tenant_id'] !== null ? (int)$d['tenant_id'] : null);
            if ($rel === null) continue;
            try {
                $pdo->prepare("UPDATE ged_documents SET final_destination = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$rel, (int)$d['id']]);
                $rescued++;
            } catch (Throwable $e) { /* best-effort */ }
        }
        return $rescued;
    }
}
