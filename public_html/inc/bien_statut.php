<?php
declare(strict_types=1);

/**
 * inc/bien_statut.php — Changement de statut d'un bien, CENTRALISÉ, TRACÉ, SÛR.
 *
 * Objectif (2026-07-20) : plus aucun archivage « silencieux ». Toute transition de
 * `biens.statut_bien` passe ici → journalisée via AuditLog (qui/quand/IP/ancien→nouveau).
 *  - L'autosave n'a plus le droit de toucher au statut (voir api/bien_autosave.php).
 *  - L'archivage manuel exige une confirmation + un motif (double validation).
 *  - Le désarchivage remet le bien en 'actif' et est tracé de la même façon.
 */

require_once __DIR__ . '/AuditLog.php';

if (!function_exists('bien_statut_labels')) {
    function bien_statut_labels(): array {
        return [
            'actif' => 'Actif', 'brouillon' => 'Brouillon', 'archive' => 'Archivé',
            'vendu' => 'Vendu', 'supprime' => 'Supprimé', 'perdu_gestion' => 'Perdu (gestion)',
        ];
    }
}

if (!function_exists('bien_set_statut')) {
    /**
     * Change le statut d'un bien avec journalisation.
     *
     * @param array $opts { motif?:string, source?:string, cascade_annonces?:bool }
     * @return array{ok:bool, error?:string, old?:string, new?:string, cascaded_annonces?:int}
     */
    function bien_set_statut(PDO $pdo, int $bienId, string $newStatut, array $opts = []): array
    {
        $valid = array_keys(bien_statut_labels());
        if (!in_array($newStatut, $valid, true)) {
            return ['ok' => false, 'error' => 'Statut invalide.'];
        }

        $st = $pdo->prepare("SELECT id, statut_bien, id_societe FROM biens WHERE id = ? LIMIT 1");
        $st->execute([$bienId]);
        $bien = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bien) return ['ok' => false, 'error' => 'Bien introuvable.'];

        $old = (string)($bien['statut_bien'] ?? '');
        if ($old === $newStatut) {
            return ['ok' => true, 'old' => $old, 'new' => $newStatut, 'cascaded_annonces' => 0, 'noop' => true];
        }

        $cascaded = 0;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE biens SET statut_bien = ?, date_modification = NOW() WHERE id = ?")
                ->execute([$newStatut, $bienId]);

            // Cascade : archiver le bien archive ses annonces (unidirectionnel).
            if ($newStatut === 'archive' && ($opts['cascade_annonces'] ?? true)) {
                $cascaded = bien_statut_cascade_annonces($pdo, $bienId, 'archive');
            }

            // Journal d'audit (qui/quand/IP/ancien→nouveau + motif + source).
            AuditLog::log($pdo, 'STATUT_BIEN', 'biens', $bienId,
                ['statut_bien' => $old],
                [
                    'statut_bien' => $newStatut,
                    'motif'       => (string)($opts['motif'] ?? ''),
                    'source'      => (string)($opts['source'] ?? 'inconnu'),
                    'cascaded_annonces' => $cascaded,
                ]
            );

            $pdo->commit();
            return ['ok' => true, 'old' => $old, 'new' => $newStatut, 'cascaded_annonces' => $cascaded];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('bien_statut_cascade_annonces')) {
    /** Archive les annonces d'un bien (colonnes visible_* présentes seulement). @return int lignes touchées */
    function bien_statut_cascade_annonces(PDO $pdo, int $bienId, string $etat): int
    {
        $sets = ["`statut` = " . $pdo->quote($etat)];
        try { if ($pdo->query("SHOW COLUMNS FROM annonces LIKE 'etat_publication'")->fetchColumn()) $sets[] = "`etat_publication` = " . $pdo->quote($etat); } catch (Throwable) {}
        foreach (['visible_portails', 'visible_site', 'visible_maboximmo', 'visible_site_perso'] as $c) {
            try { if ($pdo->query("SHOW COLUMNS FROM annonces LIKE " . $pdo->quote($c))->fetchColumn()) $sets[] = "`{$c}` = 0"; } catch (Throwable) {}
        }
        $sets[] = "`date_modification` = NOW()";
        $stmt = $pdo->prepare("UPDATE annonces SET " . implode(', ', $sets) . " WHERE id_bien = ?");
        $stmt->execute([$bienId]);
        return $stmt->rowCount();
    }
}

if (!function_exists('bien_statut_can_archive')) {
    /**
     * Contrôle métier avant archivage : refuse si une annonce est ACTIVE.
     * @return array{ok:bool, error?:string, code?:string}
     */
    function bien_statut_can_archive(PDO $pdo, int $bienId): array
    {
        $an = $pdo->prepare("SELECT COUNT(*) FROM annonces WHERE id_bien = ? AND (statut = 'publiee' OR etat_publication = 'diffusee')");
        $an->execute([$bienId]);
        if ((int)$an->fetchColumn() > 0) {
            return ['ok' => false, 'code' => 'ANNONCE_ACTIVE',
                    'error' => "Impossible d'archiver : ce bien a une annonce active. Retirez/archivez d'abord l'annonce."];
        }
        return ['ok' => true];
    }
}

if (!function_exists('bien_statut_history')) {
    /** Historique des changements de statut d'un bien (depuis audit_log). */
    function bien_statut_history(PDO $pdo, int $bienId, int $limit = 50): array
    {
        try {
            $st = $pdo->prepare(
                "SELECT a.action, a.old_values, a.new_values, a.ip_address, a.created_at,
                        TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS author
                 FROM audit_log a LEFT JOIN users u ON u.id = a.id_user
                 WHERE a.table_name = 'biens' AND a.record_id = ? AND a.action = 'STATUT_BIEN'
                 ORDER BY a.id DESC LIMIT " . (int)$limit
            );
            $st->execute([$bienId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
