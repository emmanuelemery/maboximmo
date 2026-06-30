<?php
declare(strict_types=1);

/**
 * inc/investisseur_valo.php
 *
 * Moteur de valorisation pilotable + versionning.
 *
 * Logique :
 *   1. Pour chaque bien, on détermine sa typologie (commercial/bureau/activité/...)
 *      et son secteur (lyon/métropole/RA/France) à partir de type_bien + code_postal.
 *   2. Taux retenu = taux_typologie + ajust_secteur + ajust_global.
 *   3. Méthode capitalisation : prix_théorique = loyer_annuel / (taux/100)
 *      Sinon méthode prix_m2 : prix_théorique = surface × prix_m2_plancher
 *   4. Honoraires théoriques = prix_théorique × taux_honoraires / 100.
 *
 * Un scénario enregistré fige toutes les lignes dans investisseur_valo_snapshots.
 */

require_once __DIR__ . '/investisseur_helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
// CLASSIFICATION
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_valo_secteur_of')) {
    /**
     * Classe un code postal en secteur.
     *   lyon       : 69001..69009 (Lyon intra)
     *   metropole  : 69xxx (autres)
     *   ra         : Auvergne-Rhône-Alpes hors Lyon/métro (01,03,07,15,26,38,42,43,63,73,74)
     *   france     : tout le reste
     */
    function inv_valo_secteur_of(?string $cp): string {
        $cp = preg_replace('/\D/', '', (string)$cp);
        if (strlen($cp) !== 5) return 'france';
        $n = (int)$cp;
        if ($n >= 69001 && $n <= 69009) return 'lyon';
        if ($n >= 69000 && $n <= 69999) return 'metropole';
        $dep = (int)substr($cp, 0, 2);
        if (in_array($dep, [1, 3, 7, 15, 26, 38, 42, 43, 63, 73, 74], true)) return 'ra';
        return 'france';
    }
}

if (!function_exists('inv_valo_default_params')) {
    function inv_valo_default_params(): array {
        return [
            'taux_commercial' => 7.00, 'taux_bureau' => 6.50, 'taux_activite' => 8.00,
            'taux_immeuble'   => 5.50, 'taux_habitation' => 4.00,
            'taux_parking'    => 8.00, 'taux_autre' => 7.00,
            'ajust_lyon'      => -1.00, 'ajust_metropole' => 0.00,
            'ajust_ra'        => 0.50,  'ajust_france' => 1.50,
            'prixm2_commercial' => 2000, 'prixm2_bureau' => 2500,
            'prixm2_activite'   => 900,  'prixm2_immeuble' => 2500,
            'prixm2_habitation' => 3500, 'prixm2_parking' => 500,
            'prixm2_autre'      => 1500,
            'ajust_global'   => 0.00,
            'taux_honoraires'=> 4.00,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CALCUL UNITAIRE — un bien + un jeu de paramètres = une ligne snapshot
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_valo_compute_line')) {
    /**
     * Calcule la valorisation d'une analyse selon un set de paramètres.
     *
     * $analyse   : ligne d'investisseur_analyses (tableau assoc)
     * $params    : tableau de paramètres (taux_*, ajust_*, prixm2_*, taux_honoraires, ajust_global)
     *
     * Retour : array prêt à insérer dans investisseur_valo_snapshots.
     */
    function inv_valo_compute_line(array $analyse, array $params): array {
        $p = array_merge(inv_valo_default_params(), $params);

        $typ = inv_typologie_of((string)($analyse['type_bien'] ?? ''));
        // Code postal : tenté via reference_bien/adresse, sinon champ dédié ville
        $cp = '';
        if (preg_match('/\b(\d{5})\b/', (string)($analyse['adresse'] ?? ''), $m)) $cp = $m[1];
        if (!$cp && preg_match('/\b(\d{5})\b/', (string)($analyse['reference_bien'] ?? ''), $m)) $cp = $m[1];
        $secteur = inv_valo_secteur_of($cp);

        // Taux typologie
        $tauxBase = (float)($p['taux_' . $typ] ?? $p['taux_autre']);
        $ajust    = (float)($p['ajust_' . $secteur] ?? 0);
        $tauxApp  = round($tauxBase + $ajust + (float)$p['ajust_global'], 2);
        if ($tauxApp < 0.5) $tauxApp = 0.5;  // garde-fou

        $loyerMens = (float)($analyse['loyer_estime'] ?? 0);
        $loyerAn   = $loyerMens * 12;
        $surface   = (float)($analyse['surface'] ?? 0);
        $prixCatalogue = (float)($analyse['prix_achat'] ?? 0);
        $honoCatalogue = (float)($analyse['honoraires_vente'] ?? 0);

        $prixCapit = 0.0;
        $prixM2    = 0.0;
        $methode   = 'manuel';

        if ($loyerAn > 0 && $tauxApp > 0) {
            $prixCapit = round($loyerAn / ($tauxApp / 100), 2);
        }
        $pm2 = (float)($p['prixm2_' . $typ] ?? $p['prixm2_autre']);
        if ($surface > 0 && $pm2 > 0) {
            $prixM2 = round($surface * $pm2, 2);
        }

        // Méthode retenue : capitalisation prioritaire, sinon prix_m2, sinon manuel (catalogue)
        if ($prixCapit > 0) {
            $prixTheo = $prixCapit;
            $methode = 'capitalisation';
        } elseif ($prixM2 > 0) {
            $prixTheo = $prixM2;
            $methode = 'prix_m2';
        } else {
            $prixTheo = $prixCatalogue;
            $methode = 'manuel';
        }

        $honoTheo = round($prixTheo * ((float)$p['taux_honoraires'] / 100), 2);
        $prixTotalTheo = round($prixTheo + $honoTheo, 2);
        $ecartVal = round($prixTheo - $prixCatalogue, 2);
        $ecartPct = $prixCatalogue > 0 ? round(($ecartVal / $prixCatalogue) * 100, 2) : 0.0;

        return [
            'id_analyse'     => (int)$analyse['id'],
            'titre_analyse'  => (string)($analyse['titre_analyse'] ?? ''),
            'type_bien'      => (string)($analyse['type_bien'] ?? ''),
            'typologie'      => $typ,
            'ville'          => (string)($analyse['ville'] ?? ''),
            'code_postal'    => $cp,
            'secteur'        => $secteur,
            'surface'        => $surface,
            'loyer_annuel'   => $loyerAn,
            'prix_catalogue' => $prixCatalogue,
            'honoraires_catalogue' => $honoCatalogue,
            'taux_applique'  => $tauxApp,
            'prix_theorique' => $prixTheo,
            'honoraires_theoriques' => $honoTheo,
            'prix_total_theorique'  => $prixTotalTheo,
            'ecart_valeur'   => $ecartVal,
            'ecart_pct'      => $ecartPct,
            'methode'        => $methode,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CALCUL GLOBAL — applique un set de paramètres à tout un portefeuille
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_valo_compute_portefeuille')) {
    function inv_valo_compute_portefeuille(PDO $pdo, array $params, array $filters = []): array {
        $analyses = inv_list($pdo, $filters, 1000);
        $lines = [];
        $totCatalogue = 0; $totTheo = 0; $totHonoCat = 0; $totHonoTheo = 0;
        $totLoyerAn = 0; $totSurface = 0;
        $parTypo = []; $parSecteur = [];
        foreach ($analyses as $a) {
            $line = inv_valo_compute_line($a, $params);
            $lines[] = $line;
            $totCatalogue += (float)$line['prix_catalogue'];
            $totTheo      += (float)$line['prix_theorique'];
            $totHonoCat   += (float)$line['honoraires_catalogue'];
            $totHonoTheo  += (float)$line['honoraires_theoriques'];
            $totLoyerAn   += (float)$line['loyer_annuel'];
            $totSurface   += (float)$line['surface'];
            $parTypo[$line['typologie']] = ($parTypo[$line['typologie']] ?? 0) + (float)$line['prix_theorique'];
            $parSecteur[$line['secteur']] = ($parSecteur[$line['secteur']] ?? 0) + (float)$line['prix_theorique'];
        }
        return [
            'lines'   => $lines,
            'totals'  => [
                'nb'                => count($lines),
                'prix_catalogue'    => $totCatalogue,
                'prix_theorique'    => $totTheo,
                'ecart_valeur'      => $totTheo - $totCatalogue,
                'ecart_pct'         => $totCatalogue > 0 ? round((($totTheo - $totCatalogue) / $totCatalogue) * 100, 2) : 0,
                'honoraires_catalogue' => $totHonoCat,
                'honoraires_theoriques' => $totHonoTheo,
                'prix_total_theorique'  => $totTheo + $totHonoTheo,
                'loyer_annuel'      => $totLoyerAn,
                'surface'           => $totSurface,
                'prix_m2_moyen'     => $totSurface > 0 ? round($totTheo / $totSurface, 0) : 0,
                'rdt_brut_theo'     => $totTheo > 0 ? round($totLoyerAn / $totTheo * 100, 2) : 0,
            ],
            'par_typologie' => $parTypo,
            'par_secteur'   => $parSecteur,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CRUD SCÉNARIOS
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_valo_scenario_cols')) {
    function inv_valo_scenario_cols(): array {
        return array_merge(
            ['nom_scenario','description','is_default'],
            array_keys(inv_valo_default_params())
        );
    }
}

if (!function_exists('inv_valo_save_scenario')) {
    /**
     * Enregistre / met à jour un scénario et fige les snapshots à jour.
     * Retourne l'id du scénario.
     */
    function inv_valo_save_scenario(PDO $pdo, array $params, ?int $id = null, array $filters = []): int {
        $scope = inv_current_scope();
        $data = [
            'id_societe'   => $scope['id_societe'],
            'id_agence'    => $scope['id_agence'],
            'id_user'      => $scope['id_user'],
            'nom_scenario' => trim((string)($params['nom_scenario'] ?? 'Scénario ' . date('Y-m-d H:i'))),
            'description'  => trim((string)($params['description'] ?? '')),
            'is_default'   => !empty($params['is_default']) ? 1 : 0,
        ];
        foreach (array_keys(inv_valo_default_params()) as $k) {
            $data[$k] = isset($params[$k]) ? (float)$params[$k] : inv_valo_default_params()[$k];
        }

        if ($id === null) {
            $cols = array_keys($data);
            $ph   = array_map(fn($c) => ':' . $c, $cols);
            $sql  = "INSERT INTO investisseur_valo_scenarios (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
            $st   = $pdo->prepare($sql);
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
            $st->execute();
            $id = (int)$pdo->lastInsertId();
        } else {
            // Vérif scope
            $existing = inv_valo_load_scenario($pdo, $id);
            if (!$existing) throw new RuntimeException("Scénario introuvable ou hors périmètre.");
            $sets = [];
            foreach (array_keys($data) as $c) $sets[] = "`$c` = :$c";
            $sql = "UPDATE investisseur_valo_scenarios SET " . implode(',', $sets) . " WHERE id = :id_row";
            $st  = $pdo->prepare($sql);
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
            $st->bindValue(':id_row', $id, PDO::PARAM_INT);
            $st->execute();
            // Reset snapshots — on va les re-figer
            $pdo->prepare("DELETE FROM investisseur_valo_snapshots WHERE id_scenario = :s")
                ->execute([':s' => $id]);
        }

        // Fige les snapshots (état du parc au moment de la sauvegarde)
        $port = inv_valo_compute_portefeuille($pdo, $data, $filters);
        $insertSql = "INSERT INTO investisseur_valo_snapshots
            (id_scenario, id_analyse, titre_analyse, type_bien, typologie, ville, code_postal, secteur,
             surface, loyer_annuel, prix_catalogue, honoraires_catalogue,
             taux_applique, prix_theorique, honoraires_theoriques, prix_total_theorique,
             ecart_valeur, ecart_pct, methode)
            VALUES (:id_scenario, :id_analyse, :titre_analyse, :type_bien, :typologie, :ville, :code_postal, :secteur,
                    :surface, :loyer_annuel, :prix_catalogue, :honoraires_catalogue,
                    :taux_applique, :prix_theorique, :honoraires_theoriques, :prix_total_theorique,
                    :ecart_valeur, :ecart_pct, :methode)";
        $ins = $pdo->prepare($insertSql);
        foreach ($port['lines'] as $line) {
            $line['id_scenario'] = $id;
            foreach ($line as $k => $v) $ins->bindValue(':' . $k, $v);
            $ins->execute();
        }
        return $id;
    }
}

if (!function_exists('inv_valo_load_scenario')) {
    function inv_valo_load_scenario(PDO $pdo, int $id): ?array {
        $scope = inv_current_scope();
        $sql = "SELECT * FROM investisseur_valo_scenarios WHERE id = :id";
        $params = [':id' => $id];
        if ($scope['id_societe'] !== null) {
            $sql .= " AND (id_societe = :s OR id_societe IS NULL)";
            $params[':s'] = $scope['id_societe'];
        }
        if ($scope['id_user'] !== null) {
            $sql .= " AND (id_user = :u OR id_user IS NULL)";
            $params[':u'] = $scope['id_user'];
        }
        $sql .= " LIMIT 1";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('inv_valo_list_scenarios')) {
    function inv_valo_list_scenarios(PDO $pdo): array {
        $scope = inv_current_scope();
        $where = [];
        $params = [];
        if ($scope['id_user'] !== null) {
            $where[] = "id_user = :u";
            $params[':u'] = $scope['id_user'];
        }
        $sql = "SELECT s.*,
                  (SELECT COUNT(*) FROM investisseur_valo_snapshots WHERE id_scenario = s.id) AS nb_snapshots,
                  (SELECT SUM(prix_theorique) FROM investisseur_valo_snapshots WHERE id_scenario = s.id) AS total_theorique,
                  (SELECT SUM(prix_catalogue) FROM investisseur_valo_snapshots WHERE id_scenario = s.id) AS total_catalogue
                FROM investisseur_valo_scenarios s";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY s.updated_at DESC";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_valo_load_snapshots')) {
    function inv_valo_load_snapshots(PDO $pdo, int $idScenario): array {
        $st = $pdo->prepare("SELECT * FROM investisseur_valo_snapshots WHERE id_scenario = :s ORDER BY prix_theorique DESC");
        $st->bindValue(':s', $idScenario, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_valo_delete_scenario')) {
    function inv_valo_delete_scenario(PDO $pdo, int $id): bool {
        $s = inv_valo_load_scenario($pdo, $id);
        if (!$s) return false;
        $pdo->prepare("DELETE FROM investisseur_valo_scenarios WHERE id = :id")
            ->execute([':id' => $id]);
        return true;
    }
}
