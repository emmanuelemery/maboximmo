<?php
declare(strict_types=1);
/**
 * inc/bien_missions.php — POINT D'ACCÈS UNIQUE aux missions d'un bien.
 *
 * Principe directeur (Phase 1) : la mission canonique est portée UNIQUEMENT par
 * `mandats` (1 ligne = 1 mission : gestion / location / vente). Cette fonction est
 * la SEULE porte d'entrée pour lire les missions d'un bien. Tous les développements
 * futurs doivent passer par elle (ne plus lire `biens.type_commercialisation`).
 *
 * Garde `function_exists` : la structure de la prod est dupliquée (dev/, public_html/…),
 * un double require via un chemin différent provoquerait un fatal de redéclaration.
 */

if (!function_exists('bien_missions')) {

    /** Statuts de mandat considérés comme TERMINAUX (mission close). */
    function mission_statuts_terminaux(): array
    {
        return ['resilie','expire','annule','archive','termine','perdu','refuse','clos','vendu','sans_suite'];
    }

    /**
     * Retourne TOUTES les missions (mandats) d'un bien, enrichies.
     *
     * @return array<int,array> Chaque mission :
     *   id_mandat, id_bien, type ('gestion'|'location'|'vente'), nature, numero_mandat,
     *   exclusif (bool), statut (brut), est_actif (bool calculé),
     *   date_signature, date_debut, date_fin,
     *   honoraires, honoraires_charge, loyer_mandat, charges_mandat,   ← VALEURS CONTRACTUELLES (mission)
     *   mandat_commercialisation (bool), autorisation_diffusion (bool),
     *   id_agence, agence, id_societe, societe,
     *   id_proprietaire, id_tiers, id_user, date_creation, date_modification.
     *   Tableau vide si aucun mandat.
     */
    function bien_missions(PDO $pdo, int $idBien): array
    {
        if ($idBien <= 0) return [];

        $sql = "SELECT
                    m.id            AS id_mandat,
                    m.id_bien,
                    m.type_mandat   AS type,
                    m.nature_mandat AS nature,
                    m.numero_mandat,
                    m.exclusif,
                    m.statut,
                    m.date_signature, m.date_debut, m.date_fin,
                    m.honoraires, m.honoraires_charge, m.loyer_mandat, m.charges_mandat,
                    m.mandat_commercialisation, m.autorisation_diffusion,
                    m.id_agence, a.nom_agence AS agence,
                    a.id_societe, s.nom AS societe,
                    m.id_proprietaire, m.id_tiers, m.id_user,
                    m.date_creation, m.date_modification
                FROM mandats m
                LEFT JOIN agences  a ON a.id = m.id_agence
                LEFT JOIN societes s ON s.id = a.id_societe
                WHERE m.id_bien = ?
                ORDER BY FIELD(m.type_mandat,'gestion','location','vente'),
                         m.date_signature DESC, m.id DESC";

        $st = $pdo->prepare($sql);
        $st->execute([$idBien]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Loyer CONTRACTUEL : il n'est PAS sur le mandat (mandats.loyer_mandat vide
        // partout) mais sur le BAIL actif. On lit donc le bail actif du bien une fois.
        $bailRow = null;
        try {
            $bst = $pdo->prepare("SELECT loyer_mensuel_hc, charges_mensuelles
                                  FROM bien_baux
                                  WHERE id_bien = ? AND statut = 'actif'
                                  ORDER BY date_prise_effet DESC, id DESC LIMIT 1");
            $bst->execute([$idBien]);
            $bailRow = $bst->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) { /* table absente → non bloquant */ }

        // Statuts TERMINAUX (mission close) vs « projet » (mise en route, pas encore signée).
        //   - est_termine : mandat clos → ne compte plus.
        //   - est_en_cours : non clos (projet INCLUS) → la mission existe (mise en vente, etc.).
        //   - est_actif : signé et valide (projet EXCLU) → pour l'affichage / le contractuel.
        $statutsTerminaux = mission_statuts_terminaux();
        $today = date('Y-m-d');

        foreach ($rows as &$r) {
            $r['exclusif']                 = (bool)$r['exclusif'];
            $r['mandat_commercialisation'] = (bool)$r['mandat_commercialisation'];
            $r['autorisation_diffusion']   = (bool)$r['autorisation_diffusion'];
            $statut = strtolower(trim((string)$r['statut']));
            $dateOk = empty($r['date_fin']) || $r['date_fin'] >= $today;
            $r['est_termine']  = in_array($statut, $statutsTerminaux, true);
            $r['est_en_cours'] = !$r['est_termine'];                                  // projet inclus
            $r['est_actif']    = !$r['est_termine'] && $statut !== 'projet' && $dateOk; // signé & valide

            // Loyer CONTRACTUEL exposé proprement :
            //   - location : le loyer du bail actif (source réelle), à défaut loyer_mandat ;
            //   - autres missions : loyer_mandat s'il existe (souvent NULL).
            $type = strtolower((string)$r['type']);
            // Loyer contractuel = le bail actif pour toute mission « louée » (gestion ou location).
            if (in_array($type, ['location','gestion'], true) && $bailRow !== null && (float)($bailRow['loyer_mensuel_hc'] ?? 0) > 0) {
                $r['loyer_contractuel']    = (float)$bailRow['loyer_mensuel_hc'];
                $r['charges_contractuelles'] = isset($bailRow['charges_mensuelles']) ? (float)$bailRow['charges_mensuelles'] : null;
                $r['loyer_contractuel_source'] = 'bail';
            } else {
                $r['loyer_contractuel']    = ($r['loyer_mandat'] !== null) ? (float)$r['loyer_mandat'] : null;
                $r['charges_contractuelles'] = ($r['charges_mandat'] !== null) ? (float)$r['charges_mandat'] : null;
                $r['loyer_contractuel_source'] = ($r['loyer_mandat'] !== null) ? 'mandat' : null;
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Le bien a-t-il une mission d'un type donné ? (optionnellement active seulement)
     * @param string $type 'gestion' | 'location' | 'vente'
     */
    function bien_a_mission(PDO $pdo, int $idBien, string $type, bool $activeOnly = false): bool
    {
        foreach (bien_missions($pdo, $idBien) as $m) {
            if (strtolower((string)$m['type']) === strtolower($type) && (!$activeOnly || $m['est_actif'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retourne la mission active d'un type (la plus récente), ou null.
     */
    function bien_mission_active(PDO $pdo, int $idBien, string $type): ?array
    {
        foreach (bien_missions($pdo, $idBien) as $m) {
            if (strtolower((string)$m['type']) === strtolower($type) && $m['est_actif']) {
                return $m;
            }
        }
        return null;
    }

    /**
     * MIROIR DÉRIVÉ : recalcule `biens.type_commercialisation` depuis les missions
     * canoniques (mandats). C'est désormais le SEUL endroit qui écrit cette colonne.
     * Priorité : vente > location > NULL (gestion seule ≠ commercialisation).
     * À appeler après toute écriture de mandat sur le bien.
     *
     * @return string|null La valeur écrite ('vente' | 'location' | null).
     */
    function derive_type_commercialisation(PDO $pdo, int $idBien): ?string
    {
        if ($idBien <= 0) return null;
        $missions = bien_missions($pdo, $idBien);
        // Le miroir reflète une mission EN COURS (projet inclus) : une mise en vente
        // (mandat projet) doit faire apparaître le bien en 'vente', sans mandat signé.
        $aType = static function (string $t) use ($missions): bool {
            foreach ($missions as $m) {
                if ($m['est_en_cours'] && strtolower((string)$m['type']) === $t) return true;
            }
            return false;
        };
        $val = $aType('vente') ? 'vente' : ($aType('location') ? 'location' : null);
        $pdo->prepare("UPDATE biens SET type_commercialisation = ?, date_modification = NOW() WHERE id = ?")
            ->execute([$val, $idBien]);
        return $val;
    }

    /**
     * Garantit qu'une mission VENTE existe pour le bien (idempotent). Si un mandat
     * vente non clôturé existe déjà, on le renvoie ; sinon on crée un mandat vente
     * « projet » minimal (création jamais bloquée : aucun champ obligatoire au-delà
     * du bien). N'écrit PAS type_commercialisation (faire `derive_*` ensuite).
     *
     * @return int id du mandat vente (existant ou créé).
     */
    function ensure_mandat(PDO $pdo, int $idBien, string $type, array $opts = []): int
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['vente','location','gestion'], true)) {
            throw new InvalidArgumentException("type de mission invalide: $type");
        }
        $term = "'" . implode("','", mission_statuts_terminaux()) . "'";

        // 1. Mandat de ce type DÉJÀ en cours (projet/actif…) → rien à faire.
        $st = $pdo->prepare("SELECT id FROM mandats
                             WHERE id_bien = ? AND type_mandat = ? AND statut NOT IN ($term)
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$idBien, $type]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) return $id;

        // 2. Mandat de ce type CLÔTURÉ (sans_suite/vendu…) → on le RÉACTIVE (re-listing,
        //    ex. bien retiré en mars puis remis en vente en septembre). Un seul mandat
        //    par type et par bien : son statut raconte l'histoire. Pas de doublon, pas
        //    de collision de numéro. On repasse en 'projet', sans date contractuelle.
        $stT = $pdo->prepare("SELECT id FROM mandats
                              WHERE id_bien = ? AND type_mandat = ?
                              ORDER BY id DESC LIMIT 1");
        $stT->execute([$idBien, $type]);
        $reviveId = (int)($stT->fetchColumn() ?: 0);
        if ($reviveId > 0) {
            $pdo->prepare("UPDATE mandats
                           SET statut='projet', date_fin=NULL, date_signature=NULL,
                               id_proprietaire = COALESCE(id_proprietaire, ?),
                               id_agence       = COALESCE(id_agence, ?),
                               date_modification = NOW()
                           WHERE id = ?")
                ->execute([$opts['id_proprietaire'] ?? null, $opts['id_agence'] ?? null, $reviveId]);
            return $reviveId;
        }

        // 3. Aucun mandat de ce type → création (numéro optionnel fourni par l'appelant).
        $ins = $pdo->prepare("INSERT INTO mandats
                                (id_bien, id_agence, id_proprietaire, id_user, numero_mandat, type_mandat, statut, date_creation)
                              VALUES (?, ?, ?, ?, ?, ?, 'projet', NOW())");
        $ins->execute([
            $idBien,
            $opts['id_agence']       ?? null,
            $opts['id_proprietaire'] ?? null,
            $opts['id_user']         ?? null,
            $opts['numero']          ?? null,
            $type,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Raccourci historique : garantit la mission VENTE. */
    function ensure_mandat_vente(PDO $pdo, int $idBien, array $opts = []): int
    {
        return ensure_mandat($pdo, $idBien, 'vente', $opts);
    }

    /**
     * VENDU (succès) : clôt la mission vente en SUCCÈS.
     *   - mandat vente en cours → statut 'vendu' (+ date_fin)
     *   - biens.statut_bien = 'vendu'
     *   - dossier de vente → étape 'acte' (si module dossier dispo)
     *   - miroir type_commercialisation re-dérivé (→ NULL, 'vendu' est terminal)
     */
    function mandat_vente_vendu(PDO $pdo, int $idBien): void
    {
        $term = "'" . implode("','", mission_statuts_terminaux()) . "'";
        $pdo->prepare("UPDATE mandats
                       SET statut='vendu', date_fin=COALESCE(date_fin,CURDATE()), date_modification=NOW()
                       WHERE id_bien=? AND type_mandat='vente' AND statut NOT IN ($term)")->execute([$idBien]);

        $pdo->prepare("UPDATE biens SET statut_bien='vendu', date_modification=NOW() WHERE id=?")->execute([$idBien]);

        if (function_exists('dv_ensure_for_bien')) {
            $idD = dv_ensure_for_bien($pdo, $idBien);
            if ($idD > 0) $pdo->prepare("UPDATE dossier_vente SET etape='acte', updated_at=NOW() WHERE id=?")->execute([$idD]);
        }
        derive_type_commercialisation($pdo, $idBien);
    }

    /**
     * RETRAIT (annulation) : clôt la mission vente SANS SUITE (pas une vente).
     *   - mandat vente en cours → statut 'sans_suite' (+ date_fin)
     *   - biens.statut_bien INCHANGÉ (le bien n'est pas vendu)
     *   - dossier de vente → étape 'sans_suite' (si module dispo)
     *   - miroir type_commercialisation re-dérivé (→ NULL)
     */
    function mandat_vente_sans_suite(PDO $pdo, int $idBien): void
    {
        $term = "'" . implode("','", mission_statuts_terminaux()) . "'";
        $pdo->prepare("UPDATE mandats
                       SET statut='sans_suite', date_fin=COALESCE(date_fin,CURDATE()), date_modification=NOW()
                       WHERE id_bien=? AND type_mandat='vente' AND statut NOT IN ($term)")->execute([$idBien]);

        if (function_exists('dv_ensure_for_bien')) {
            $idD = dv_ensure_for_bien($pdo, $idBien);
            if ($idD > 0) $pdo->prepare("UPDATE dossier_vente SET etape='sans_suite', updated_at=NOW() WHERE id=?")->execute([$idD]);
        }
        derive_type_commercialisation($pdo, $idBien);
    }
}
