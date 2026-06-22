<?php
declare(strict_types=1);

/**
 * inc/creancier_urgence_data.php — Couche de lecture du cockpit CRÉANCIERS.
 *
 * Agrège les saisies (objets plateforme) rattachées à un dossier créancier via
 * creancier_dossier_lien (entity_type='SAISIE'), applique le filtrage multi-tenant
 * + l'ACL par dossier, et renvoie un tableau associatif au CONTRAT FIGÉ.
 *
 * PRINCIPE : lecture seule, requêtes préparées uniquement, aucune écriture.
 *
 * Périmètre des montants (IMPORTANT) :
 *   - Saisies "actives" = statut IN ('en_cours','cantonnee','mainlevee_partielle','contestee').
 *   - EXCLURE 'mainlevee' et 'soldee' de toutes les sommes.
 */

if (!function_exists('creancier_user_can_access_dossier')) {
    /**
     * ACL : un user voit un dossier créancier UNIQUEMENT s'il y est habilité
     * (creancier_dossier_acces), en PLUS du filtrage multi-tenant.
     * Super admin (role_id=1) : accès total.
     */
    function creancier_user_can_access_dossier(PDO $pdo, int $id_dossier, int $userId): bool
    {
        if ($id_dossier <= 0 || $userId <= 0) return false;
        if (function_exists('is_super_admin') && is_super_admin()) return true;

        // Filtrage multi-tenant : le dossier doit être dans la société/agence de l'user.
        $st = $pdo->prepare("
            SELECT d.id
            FROM creancier_dossier d
            JOIN users u ON u.id = :uid
            WHERE d.id = :did
              AND (d.id_societe IS NULL OR d.id_societe = u.id_societe)
            LIMIT 1
        ");
        $st->execute([':uid' => $userId, ':did' => $id_dossier]);
        if (!$st->fetchColumn()) return false;

        // ACL nominative par dossier.
        $st = $pdo->prepare("
            SELECT 1 FROM creancier_dossier_acces
            WHERE id_dossier = :did AND id_user = :uid LIMIT 1
        ");
        $st->execute([':did' => $id_dossier, ':uid' => $userId]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('creancier_urgence_data')) {
    /**
     * Données d'urgence d'un dossier créancier (cockpit).
     *
     * @param PDO      $pdo
     * @param int      $id_dossier
     * @param int|null $userId   user pour l'ACL (défaut : current_user_id()).
     * @return array  Contrat figé (voir clés ci-dessous). Tableau vide-typé si accès refusé.
     */
    function creancier_urgence_data(PDO $pdo, int $id_dossier, ?int $userId = null): array
    {
        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : 0);

        // Contrat de retour FIGÉ — structure identique même si accès refusé / vide.
        $out = [
            'total_net_bloque'            => 0.0,
            'total_verse'                 => 0.0,   // cumul versements (saisies actives)
            'reste_du'                    => 0.0,   // total_net_bloque - total_verse
            'tresorerie_captee_mensuelle' => 0.0,
            'prochaine_butoir'            => null,
            'butoirs_en_retard'           => [],
            'saisies_par_creancier'       => [],
            'saisies_par_cible'           => [],
            'actions_prioritaires'        => [],
            'acces'                       => false,
        ];

        if (!creancier_user_can_access_dossier($pdo, $id_dossier, $userId)) {
            return $out;
        }
        $out['acces'] = true;

        $ACTIVES = ['en_cours', 'cantonnee', 'mainlevee_partielle', 'contestee'];
        $in      = implode(',', array_fill(0, count($ACTIVES), '?'));

        // ── Saisies actives du dossier (via lien polymorphe SAISIE) ──────────
        $sql = "
            SELECT s.*,
                   tc.nom_affichage  AS creancier_nom_affichage,
                   tc.nom            AS creancier_nom,
                   tc.raison_sociale AS creancier_raison
            FROM creancier_dossier_lien l
            JOIN creancier_saisie s ON s.id = l.entity_id
            LEFT JOIN tiers tc ON tc.id = s.id_creancier
            WHERE l.id_dossier = ?
              AND l.entity_type = 'SAISIE'
              AND s.statut IN ($in)
        ";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$id_dossier], $ACTIVES));
        $saisies = $st->fetchAll(PDO::FETCH_ASSOC);

        if (!$saisies) return $out;

        $ids = array_column($saisies, 'id');

        // ── Cumul des versements par saisie (saisies actives uniquement) ─────
        $verseParSaisie = [];
        if ($ids) {
            $inIds = implode(',', array_fill(0, count($ids), '?'));
            $stv = $pdo->prepare("
                SELECT id_saisie, COALESCE(SUM(montant),0) AS total
                FROM creancier_versement
                WHERE id_saisie IN ($inIds)
                GROUP BY id_saisie
            ");
            $stv->execute($ids);
            foreach ($stv as $r) $verseParSaisie[(int)$r['id_saisie']] = (float)$r['total'];
        }

        $today = date('Y-m-d');
        $creanciers = [];   // id_creancier => agrégat
        $cibles     = [];   // cible_type|cible_id => agrégat

        foreach ($saisies as $s) {
            $sid       = (int)$s['id'];
            $net       = (float)($s['montant_net_bloque'] ?? 0);
            $verse     = $verseParSaisie[$sid] ?? 0.0;
            $loyer     = (float)($s['loyer_mensuel_capte'] ?? 0);
            $butoir    = $s['date_butoir'] ?: null;
            $libCrea   = trim((string)($s['creancier_nom_affichage'] ?: $s['creancier_raison'] ?: $s['creancier_nom'] ?: ('Créancier #' . (int)$s['id_creancier'])));

            $out['total_net_bloque'] += $net;
            $out['total_verse']      += $verse;
            if ($s['type_saisie'] === 'ATTRIBUTION_LOYER') {
                $out['tresorerie_captee_mensuelle'] += $loyer;
            }

            // Prochaine butoir (>= aujourd'hui) + retards (< aujourd'hui).
            if ($butoir !== null) {
                if ($butoir >= $today) {
                    if ($out['prochaine_butoir'] === null || $butoir < $out['prochaine_butoir']) {
                        $out['prochaine_butoir'] = $butoir;
                    }
                } else {
                    $jours = (int)floor((strtotime($butoir) - strtotime($today)) / 86400); // négatif = retard
                    $out['butoirs_en_retard'][] = [
                        'id_saisie'   => $sid,
                        'creancier'   => $libCrea,
                        'date_butoir' => $butoir,
                        'jours'       => $jours,
                        'type_saisie' => $s['type_saisie'],
                        'net_bloque'  => $net,
                    ];
                }
            }

            // Agrégat par créancier.
            $kc = (int)$s['id_creancier'];
            if (!isset($creanciers[$kc])) {
                $creanciers[$kc] = ['id_creancier' => $kc, 'libelle' => $libCrea, 'total_net' => 0.0, 'nb' => 0, 'prochaine_butoir' => null];
            }
            $creanciers[$kc]['total_net'] += $net;
            $creanciers[$kc]['nb']        += 1;
            if ($butoir !== null && $butoir >= $today
                && ($creanciers[$kc]['prochaine_butoir'] === null || $butoir < $creanciers[$kc]['prochaine_butoir'])) {
                $creanciers[$kc]['prochaine_butoir'] = $butoir;
            }

            // Agrégat par cible.
            $kt = $s['cible_type'] . '|' . (int)$s['cible_id'];
            if (!isset($cibles[$kt])) {
                $cibles[$kt] = ['cible_type' => $s['cible_type'], 'cible_id' => (int)$s['cible_id'], 'libelle' => null, 'total_net' => 0.0, 'loyer_capte' => 0.0];
            }
            $cibles[$kt]['total_net']   += $net;
            $cibles[$kt]['loyer_capte'] += $loyer;

            // Actions prioritaires (saisies avec une butoir).
            $out['actions_prioritaires'][] = [
                'id_saisie'        => $sid,
                'creancier'        => $libCrea,
                'cible_type'       => $s['cible_type'],
                'cible_id'         => (int)$s['cible_id'],
                'type_saisie'      => $s['type_saisie'],
                'statut'           => $s['statut'],
                'date_butoir'      => $butoir,
                'prochaine_action' => $s['prochaine_action'],
                'net_bloque'       => $net,
            ];
        }

        $out['reste_du'] = round($out['total_net_bloque'] - $out['total_verse'], 2);

        // Libellés cibles (résolus depuis l'existant, jamais recopiés en base).
        creancier_resolve_cibles($pdo, $cibles);

        // Tris : retards les plus urgents d'abord (jours ASC = plus négatif en tête).
        usort($out['butoirs_en_retard'], fn($a, $b) => $a['jours'] <=> $b['jours']);

        // Actions : date_butoir ASC (NULL en dernier), retards d'abord.
        usort($out['actions_prioritaires'], function ($a, $b) {
            $da = $a['date_butoir'] ?? '9999-12-31';
            $db = $b['date_butoir'] ?? '9999-12-31';
            return $da <=> $db;
        });

        $out['saisies_par_creancier'] = array_values($creanciers);
        $out['saisies_par_cible']     = array_values($cibles);

        // Arrondis finaux.
        $out['total_net_bloque']            = round($out['total_net_bloque'], 2);
        $out['total_verse']                 = round($out['total_verse'], 2);
        $out['tresorerie_captee_mensuelle'] = round($out['tresorerie_captee_mensuelle'], 2);

        return $out;
    }
}

if (!function_exists('creancier_resolve_cibles')) {
    /**
     * Résout les libellés des cibles polymorphes depuis les tables existantes
     * (biens, societes, baux/tiers). Lecture seule, par lots préparés. Mutation
     * par référence du tableau $cibles.
     */
    function creancier_resolve_cibles(PDO $pdo, array &$cibles): void
    {
        $parType = [];
        foreach ($cibles as $k => $c) {
            if ((int)$c['cible_id'] > 0) $parType[$c['cible_type']][(int)$c['cible_id']][] = $k;
        }

        $resolvers = [
            'BIEN'    => "SELECT id, COALESCE(NULLIF(reference,''), CONCAT('Bien #', id)) AS lib FROM biens WHERE id IN (%s)",
            'SOCIETE' => "SELECT id, COALESCE(NULLIF(raison_sociale,''), NULLIF(nom,''), CONCAT('Société #', id)) AS lib FROM societes WHERE id IN (%s)",
        ];

        foreach ($parType as $type => $idsMap) {
            $ids = array_keys($idsMap);
            if (!$ids) continue;
            $in  = implode(',', array_fill(0, count($ids), '?'));

            if (isset($resolvers[$type])) {
                $st = $pdo->prepare(sprintf($resolvers[$type], $in));
                $st->execute($ids);
            } elseif ($type === 'LOCATAIRE') {
                // cible_id = baux.id → libellé via le tiers locataire si disponible, sinon le bail.
                $st = $pdo->prepare("SELECT id, CONCAT('Bail #', id) AS lib FROM baux WHERE id IN ($in)");
                $st->execute($ids);
            } else {
                continue; // COMPTE ou type non résolvable → on garde le libellé null.
            }

            foreach ($st as $row) {
                foreach ($idsMap[(int)$row['id']] ?? [] as $k) {
                    $cibles[$k]['libelle'] = $row['lib'];
                }
            }
        }
    }
}
