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

if (!function_exists('societe_load_activites_docs')) {
    /**
     * Charge le détail RCP + GF par activité pour une société.
     * Retourne un array indexé par activité : ['transaction' => [...], 'gestion' => [...], ...].
     */
    function societe_load_activites_docs(PDO $pdo, int $idSociete): array
    {
        if ($idSociete <= 0) return [];
        try {
            $st = $pdo->prepare("
                SELECT activite, rc_pro_assureur, rc_pro_numero, rc_pro_validite, rc_pro_montant,
                       garant_nom, garant_numero, garant_validite, garant_montant
                FROM societe_activites_docs
                WHERE id_societe = :id
            ");
            $st->execute([':id' => $idSociete]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) { $map[$r['activite']] = $r; }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('agence_load_with_societe_docs')) {
    /**
     * Charge UNE agence + merge des colonnes officielles depuis sa société.
     *
     * Champs ajoutés au tableau retourné :
     *   - Colonnes racine (KBIS, carte pro CPI) depuis societes
     *   - 'activites' : map des RCP/GF par activité (T/G/S/M) si renseignées
     *     dans societe_activites_docs. Format :
     *     ['transaction' => ['rc_pro_assureur'=>..., 'garant_nom'=>...], ...]
     *   - 'activites_actives' : flags depuis societes (transaction/gestion/...)
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
            if (!$row) return null;

            // Enrichit avec les RCP/GF par activité + flags activités société
            $idSoc = (int)($row['id_societe'] ?? 0);
            $row['activites'] = societe_load_activites_docs($pdo, $idSoc);
            // Lecture sécurisée des flags activités (peut-être absents si migration pas appliquée)
            try {
                $st2 = $pdo->prepare("SELECT activite_immobilier, activite_transaction, activite_gestion, activite_syndic, activite_marchand, activite_rh FROM societes WHERE id = :id");
                $st2->execute([':id' => $idSoc]);
                $row['activites_actives'] = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $row['activites_actives'] = [];
            }

            return $row;
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
