<?php
/**
 * inc/creancier_mouvement.php — Helpers pour les mouvements financiers d'un dossier créancier.
 *
 * Source unique de vérité pour le 360 (édition) et le partage public (lecture seule).
 */
declare(strict_types=1);

if (!function_exists('creancier_mouvement_labels')) {
    /** Libellés affichables des types de mouvement. */
    function creancier_mouvement_labels(): array {
        return [
            'versement_creancier' => 'Versement au créancier',
            'honoraire_avocat'    => 'Honoraires avocat',
            'frais_huissier'      => 'Frais huissier / commissaire',
            'frais_procedure'     => 'Frais de procédure',
            'autre'               => 'Autre',
        ];
    }
}

if (!function_exists('creancier_mouvements')) {
    /**
     * Charge les mouvements d'un dossier + les totaux par type.
     * @return array{rows: array, totaux: array, total_general: float}
     */
    function creancier_mouvements(PDO $pdo, int $idDossier): array {
        $rows = []; $totaux = []; $total = 0.0;
        foreach (array_keys(creancier_mouvement_labels()) as $k) $totaux[$k] = 0.0;
        try {
            $st = $pdo->prepare("
                SELECT m.*,
                       COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),'')) AS beneficiaire
                FROM creancier_mouvement m
                LEFT JOIN tiers t ON t.id = m.id_tiers_beneficiaire
                WHERE m.id_dossier = ?
                ORDER BY m.date_mouvement IS NULL, m.date_mouvement DESC, m.id DESC");
            $st->execute([$idDossier]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $mt = (float)$r['montant'];
                $totaux[$r['type']] = ($totaux[$r['type']] ?? 0.0) + $mt;
                $total += $mt;
            }
        } catch (Throwable $e) { /* table absente → vide */ }
        return ['rows' => $rows, 'totaux' => $totaux, 'total_general' => round($total, 2)];
    }
}
