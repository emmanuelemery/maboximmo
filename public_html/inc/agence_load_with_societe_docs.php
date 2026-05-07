<?php
declare(strict_types=1);

/**
 * inc/agence_load_with_societe_docs.php — Helper de chargement agence enrichi.
 *
 * CONTEXTE
 * Refactor 2026-05-08 : les docs officiels (KBIS, CPI, GF, RC pro, barème)
 * sont désormais stockés UNIQUEMENT sur `societes.*` (single source of truth).
 * Les readers historiques (affiches, critic engine, image_compose, admin diag)
 * lisent toujours `$agence['rc_pro']`, `$agence['carte_pro_numero']`, etc.
 * sans changement.
 *
 * Ce helper charge l'agence avec les colonnes officielles MERGÉES depuis la
 * société liée (LEFT JOIN societes via agences.id_societe). Comme ça les
 * readers continuent de fonctionner sans modification : `$agence['rc_pro']`
 * vaut désormais `societes.rc_pro` (et plus l'ancienne valeur agences.rc_pro
 * qui n'est plus alimentée).
 *
 * RESTENT AU NIVEAU AGENCE (ne sont pas overridées) :
 *   - bareme_url_doc / bareme_url (peuvent être spécifiques à l'agence)
 *   - mri_assureur, mri_numero, mri_validite (assurance par établissement)
 *
 * USAGE
 *   require_once __DIR__ . '/inc/agence_load_with_societe_docs.php';
 *   $agence = agence_load_with_societe_docs($pdo, $idAgence);
 *   // $agence['rc_pro'], $agence['carte_pro_numero'], etc. sont remplis
 *   // via JOIN societes (transparent pour les anciens readers).
 */

if (!function_exists('agence_load_with_societe_docs')) {
    /**
     * Charge UNE agence + merge des colonnes officielles depuis sa société.
     *
     * @return array|null L'agence enrichie, ou null si introuvable.
     */
    function agence_load_with_societe_docs(PDO $pdo, int $idAgence): ?array
    {
        if ($idAgence <= 0) return null;
        try {
            $sql = "
                SELECT a.*,
                  -- Colonnes officielles depuis societes (override les éventuelles
                  -- valeurs résiduelles sur agences.* via COALESCE prio société).
                  COALESCE(s.rc_pro,             a.rc_pro)             AS rc_pro,
                  COALESCE(s.rc_pro_numero,      NULL)                 AS rc_pro_numero,
                  COALESCE(s.rc_pro_validite,    a.rc_pro_validite)    AS rc_pro_validite,
                  COALESCE(s.rc_pro_montant,     NULL)                 AS rc_pro_montant,
                  COALESCE(s.carte_pro_numero,   a.carte_pro_numero)   AS carte_pro_numero,
                  COALESCE(s.carte_pro_cci,      a.carte_pro_cci)      AS carte_pro_cci,
                  COALESCE(s.carte_pro_validite, a.carte_pro_validite) AS carte_pro_validite,
                  COALESCE(s.garant_financier,   a.garant_financier)   AS garant_financier,
                  COALESCE(s.garant_validite,    a.garant_validite)    AS garant_validite,
                  COALESCE(s.garant_montant,     a.garant_montant)     AS garant_montant,
                  COALESCE(s.kbis_numero,        a.kbis_numero)        AS kbis_numero,
                  COALESCE(s.kbis_date,          a.kbis_date)          AS kbis_date,
                  s.bareme_url     AS societe_bareme_url,
                  s.bareme_url_doc AS societe_bareme_url_doc,
                  s.nom            AS societe_nom
                FROM agences a
                LEFT JOIN societes s ON s.id = a.id_societe
                WHERE a.id = :id
                LIMIT 1
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':id' => $idAgence]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            // Fallback gracieux : si certaines colonnes n'existent pas encore
            // (migration pas appliquée), on retourne juste l'agence brute.
            error_log('[agence_load_with_societe_docs] JOIN failed: ' . $e->getMessage());
            try {
                $st = $pdo->prepare("SELECT * FROM agences WHERE id = :id LIMIT 1");
                $st->execute([':id' => $idAgence]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (Throwable) {
                return null;
            }
        }
    }
}
