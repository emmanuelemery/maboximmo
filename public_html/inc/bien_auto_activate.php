<?php
declare(strict_types=1);

/**
 * bien_auto_activate.php — Auto-activation d'un bien brouillon
 *
 * Règle métier (2026-04-20) : un bien passe automatiquement du statut
 * `brouillon` à `actif` dès qu'il dispose :
 *   - d'une adresse (biens.adresse_1 non vide)
 *   - d'un propriétaire (biens.id_proprietaire > 0)
 *
 * Cette bascule est déclenchée côté serveur par les endpoints qui
 * modifient le bien (bien_autosave, dpe_diag_update, tiers_create +
 * liaison) pour offrir une UX fluide : pas de clic manuel requis sur
 * le statut.
 *
 * L'auto-activation ne s'applique QUE si le statut courant est
 * 'brouillon' — on ne touche pas aux biens déjà actifs / archivés /
 * vendus / loués.
 */

if (!function_exists('bien_maybe_activate')) {
    /**
     * @param PDO $pdo
     * @param int $bienId
     * @return bool true si le bien vient de passer en 'actif' via cette fonction
     */
    function bien_maybe_activate(PDO $pdo, int $bienId): bool {
        if ($bienId <= 0) return false;
        try {
            $st = $pdo->prepare("
                SELECT statut_bien, adresse_1, id_proprietaire
                FROM biens
                WHERE id = ?
                LIMIT 1
            ");
            $st->execute([$bienId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;

            $statut = (string)($row['statut_bien'] ?? '');
            $adresse = trim((string)($row['adresse_1'] ?? ''));
            $proprio = (int)($row['id_proprietaire'] ?? 0);

            if ($statut !== 'brouillon') return false;   // Ne touche qu'aux brouillons
            if ($adresse === '')        return false;
            if ($proprio <= 0)          return false;

            $pdo->prepare("
                UPDATE biens
                SET statut_bien = 'actif',
                    date_modification = NOW()
                WHERE id = ? AND statut_bien = 'brouillon'
            ")->execute([$bienId]);

            return true;
        } catch (Throwable $e) {
            error_log('[bien_maybe_activate] ' . $e->getMessage());
            return false;
        }
    }
}
