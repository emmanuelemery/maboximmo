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
     * Charge le détail RCP + GF par activité pour une société depuis la table
     * EXISTANTE `societes_couvertures` (créée par migration_societes_couvertures.sql,
     * 2026-04-11). Source de vérité depuis le départ — alimentée par l'analyse
     * IA des docs upload via societe.php → onglet Documents.
     *
     * Schéma `societes_couvertures` :
     *   - id_societe + type ('rcp' | 'garantie_financiere') + activite
     *     ('transaction'/'gestion'/'syndic'/'location'/'neuf'/'multi')
     *   - compagnie, numero_police, montant, date_debut, date_expiration
     *   - est_active (0/1) — versions archivées vs version courante
     *
     * Retourne un array indexé par activité, format compatible avec les readers
     * qui consomment $agence['activites'][T|G|S|M] :
     *   ['transaction' => ['rc_pro_assureur'=>..., 'garant_nom'=>...], ...]
     */
    function societe_load_activites_docs(PDO $pdo, int $idSociete): array
    {
        if ($idSociete <= 0) return [];
        $map = [];
        try {
            $st = $pdo->prepare("
                SELECT type, activite, compagnie, numero_police, montant,
                       date_debut, date_expiration
                FROM societes_couvertures
                WHERE id_societe = :id AND est_active = 1
            ");
            $st->execute([':id' => $idSociete]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $act = $c['activite'];
                if (!isset($map[$act])) $map[$act] = [];
                if ($c['type'] === 'rcp') {
                    $map[$act]['rc_pro_assureur'] = $c['compagnie'];
                    $map[$act]['rc_pro_numero']   = $c['numero_police'];
                    $map[$act]['rc_pro_validite'] = $c['date_expiration'];
                    $map[$act]['rc_pro_montant']  = $c['montant'];
                } elseif ($c['type'] === 'garantie_financiere') {
                    $map[$act]['garant_nom']      = $c['compagnie'];
                    $map[$act]['garant_numero']   = $c['numero_police'];
                    $map[$act]['garant_validite'] = $c['date_expiration'];
                    $map[$act]['garant_montant']  = $c['montant'];
                }
            }
        } catch (Throwable) { /* table n'existe pas → map vide, fallback sur générique */ }
        return $map;
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
            // COALESCE étendu pour rattraper le mismatch de noms entre :
            //  - api/societe_doc_analyze.php qui écrit dans societes.numero_carte_t /
            //    cci_carte_t / carte_t_date_expiration (anciens noms historiques)
            //  - migration 20260508_2 qui a ajouté carte_pro_numero / carte_pro_cci /
            //    carte_pro_validite (nouveaux noms canoniques)
            // Le helper expose toujours les noms canoniques (carte_pro_*) en sortie,
            // mais lit les 2 sources pour ne rater aucune valeur OCR existante.
            $sql = "
                SELECT a.*,
                  -- RC pro : société prio, fallback agence
                  COALESCE(s.rc_pro,             a.rc_pro)             AS rc_pro,
                  COALESCE(s.rc_pro_numero,      NULL)                 AS rc_pro_numero,
                  COALESCE(s.rc_pro_validite,    a.rc_pro_validite)    AS rc_pro_validite,
                  COALESCE(s.rc_pro_montant,     NULL)                 AS rc_pro_montant,

                  -- Carte pro CPI : alias entre noms canoniques et anciens
                  COALESCE(s.carte_pro_numero,   s.numero_carte_t,          a.carte_pro_numero)   AS carte_pro_numero,
                  COALESCE(s.carte_pro_cci,      s.cci_carte_t,             a.carte_pro_cci)      AS carte_pro_cci,
                  COALESCE(s.carte_pro_validite, s.carte_t_date_expiration, a.carte_pro_validite) AS carte_pro_validite,

                  -- Garantie financière : alias garant_financier ↔ garantie_financiere
                  COALESCE(s.garant_financier,   s.garantie_financiere,     a.garant_financier)   AS garant_financier,
                  COALESCE(s.garant_validite,    a.garant_validite)         AS garant_validite,
                  COALESCE(s.garant_montant,     a.garant_montant)          AS garant_montant,

                  COALESCE(s.kbis_numero,        a.kbis_numero)        AS kbis_numero,
                  COALESCE(s.kbis_date,          a.kbis_date)          AS kbis_date,

                  s.bareme_url     AS societe_bareme_url,
                  s.bareme_url_doc AS societe_bareme_url_doc,
                  s.nom            AS societe_nom,
                  s.logo_url       AS societe_logo_url
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

            // Si les champs FLAT rc_pro / garant_financier sont vides (cas
            // typique : OCR a écrit dans societes_couvertures par activité mais
            // pas sur les colonnes flat de societes), on les remplit avec la
            // première activité disponible. Le critic_engine et les readers
            // legacy lisent les champs flat ; on assure la cohérence.
            if (empty($row['rc_pro']) && !empty($row['activites'])) {
                foreach (['transaction', 'gestion', 'syndic', 'multi'] as $act) {
                    if (!empty($row['activites'][$act]['rc_pro_assureur'])) {
                        $row['rc_pro']           = $row['activites'][$act]['rc_pro_assureur'];
                        $row['rc_pro_numero']    = $row['activites'][$act]['rc_pro_numero']    ?? null;
                        $row['rc_pro_validite']  = $row['activites'][$act]['rc_pro_validite']  ?? null;
                        $row['rc_pro_montant']   = $row['activites'][$act]['rc_pro_montant']   ?? null;
                        break;
                    }
                }
            }
            if (empty($row['garant_financier']) && !empty($row['activites'])) {
                foreach (['transaction', 'gestion', 'syndic', 'multi'] as $act) {
                    if (!empty($row['activites'][$act]['garant_nom'])) {
                        $row['garant_financier'] = $row['activites'][$act]['garant_nom'];
                        $row['garant_validite']  = $row['activites'][$act]['garant_validite']  ?? null;
                        $row['garant_montant']   = $row['activites'][$act]['garant_montant']   ?? null;
                        break;
                    }
                }
            }
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
