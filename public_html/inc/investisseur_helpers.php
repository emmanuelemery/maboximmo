<?php
declare(strict_types=1);

/**
 * inc/investisseur_helpers.php
 *
 * Accès BDD + utilitaires UI pour le module Analyse Investisseur.
 *
 * Sépare rigoureusement :
 *   - la persistance (charger / sauver / lister / dupliquer / supprimer)
 *   - les helpers de rendu (couleur barre carte, classe score, etc.)
 *   - le scoping multi-tenant (filtrage id_societe / id_agence)
 */

require_once __DIR__ . '/investisseur_calculs.php';
require_once __DIR__ . '/investisseur_interpretations.php';

// ═══════════════════════════════════════════════════════════════════════════
// SCOPE — multi-tenant
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_current_scope')) {
    /**
     * Retourne [id_societe, id_agence, id_user] du user connecté.
     * Les tests sont tolérants : certains users historiques n'ont pas d'agence.
     */
    function inv_current_scope(): array {
        $s = $_SESSION['user'] ?? $_SESSION ?? [];
        return [
            'id_societe' => isset($s['id_societe']) ? (int)$s['id_societe'] : null,
            'id_agence'  => isset($s['id_agence'])  ? (int)$s['id_agence']  : null,
            'id_user'    => isset($s['id'])         ? (int)$s['id']
                           : (isset($s['id_user']) ? (int)$s['id_user'] : null),
        ];
    }
}

if (!function_exists('inv_scope_where')) {
    /**
     * Produit la clause WHERE + params pour filtrer un SELECT par scope.
     * Retour : ['sql' => '...', 'params' => [':k' => v, ...]]
     */
    function inv_scope_where(string $alias = ''): array {
        $sc = inv_current_scope();
        $p  = $alias ? $alias . '.' : '';
        $where = [];
        $params = [];
        if ($sc['id_societe'] !== null) {
            $where[] = "{$p}id_societe = :scope_societe";
            $params[':scope_societe'] = $sc['id_societe'];
        }
        if ($sc['id_agence'] !== null) {
            $where[] = "({$p}id_agence = :scope_agence OR {$p}id_agence IS NULL)";
            $params[':scope_agence'] = $sc['id_agence'];
        }
        return [
            'sql'    => $where ? implode(' AND ', $where) : '1=1',
            'params' => $params,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PERSISTANCE
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_columns_assignables')) {
    /**
     * Liste blanche des colonnes qu'un formulaire peut remplir directement.
     * (hors calculs / interprétations générés, hors champs système)
     */
    function inv_columns_assignables(): array {
        return [
            'id_bien_source','id_proprietaire',
            'titre_analyse','reference_bien','type_bien','ville','quartier','adresse',
            'surface','nb_pieces','etage','etat_general','annee_construction',
            'exterieur','cave','garage','parking','dpe','ges',
            'prix_vente_catalogue','prix_achat','frais_notaire','frais_agence','travaux','travaux_bailleur','ameublement',
            'apport','taux_credit','duree_credit',
            'credit_crd','credit_duree_restante_mois',
            'revalorisation_bien_pct_an','indexation_loyer_pct_an','ira_pct','taux_imposition_pct',
            'loyer_estime','charges_recuperables','charges_non_recuperables',
            'taxe_fonciere','assurance_pno','gestion_locative','vacance_locative',
            'entretien_imprevus','regime_fiscal','type_location',
            'locataire_nom','bail_fin','photovoltaique','nb_parkings','honoraires_vente',
            'strategie','potentiel_valorisation','tension_locative','facilite_revente',
            'niveau_risque','qualite_emplacement','commentaire_humain',
            'statut',
        ];
    }
}

if (!function_exists('inv_columns_calculees')) {
    function inv_columns_calculees(): array {
        return [
            'cout_total','montant_finance','mensualite_credit',
            'revenu_annuel','charges_annuelles','rendement_brut','rendement_net',
            'cashflow_mensuel','effort_epargne','projection_10_ans',
            'score_risque','score_attractivite','score_global',
            'prix_m2','multiple_loyer',
        ];
    }
}

if (!function_exists('inv_columns_textes')) {
    function inv_columns_textes(): array {
        return [
            'synthese','forces_txt','faiblesses_txt','risques_txt','opportunites_txt',
            'reco_finale','argumentaire_prudent','argumentaire_equilibre',
            'argumentaire_offensif','presentation_client',
        ];
    }
}

if (!function_exists('inv_sanitize_post')) {
    /**
     * Réduit $_POST aux colonnes assignables et normalise (float, int, string).
     */
    function inv_sanitize_post(array $src): array {
        $out = [];
        foreach (inv_columns_assignables() as $col) {
            if (!array_key_exists($col, $src)) continue;
            $v = $src[$col];
            $out[$col] = match (true) {
                in_array($col, [
                    'surface','prix_vente_catalogue','prix_achat','frais_notaire','frais_agence','travaux','travaux_bailleur',
                    'ameublement','apport','taux_credit','loyer_estime',
                    'charges_recuperables','charges_non_recuperables','taxe_fonciere',
                    'assurance_pno','gestion_locative','vacance_locative','entretien_imprevus',
                    'honoraires_vente','credit_crd',
                    'revalorisation_bien_pct_an','indexation_loyer_pct_an','ira_pct','taux_imposition_pct',
                ], true) => inv_f($v),
                in_array($col, [
                    'id_bien_source','id_proprietaire',
                    'nb_pieces','annee_construction','cave','garage','parking',
                    'duree_credit','potentiel_valorisation','tension_locative',
                    'facilite_revente','niveau_risque','qualite_emplacement',
                    'photovoltaique','nb_parkings','credit_duree_restante_mois',
                ], true) => inv_i($v),
                $col === 'bail_fin' => (trim((string)$v) !== '' ? substr(trim((string)$v), 0, 10) : null),
                default  => trim((string)$v),
            };
        }
        return $out;
    }
}

if (!function_exists('inv_recalc_and_merge')) {
    /**
     * Prend une saisie, lance calculs + interprétations, et — si une connexion PDO est
     * fournie + un id_analyse existant — fusionne les commentaires orientés
     * (force/faiblesse/risque/opportunite/notes) dans les textes d'interprétation.
     * Les directives 'instruction_ia' ne sont PAS injectées ici (réservées au prompt LLM).
     */
    function inv_recalc_and_merge(array $data, ?PDO $pdo = null, ?int $idAnalyse = null): array {
        $calc  = inv_compute_all($data);
        $texts = inv_generate_all_texts($data, $calc);

        if ($pdo && file_exists(__DIR__ . '/investisseur_commentaires.php')) {
            require_once __DIR__ . '/investisseur_commentaires.php';
            $filters = [];
            if ($idAnalyse) {
                $filters['id_analyse'] = $idAnalyse;
                $filters['inclure_bien_via_analyse'] = true;
            } elseif (!empty($data['id_bien_source'])) {
                $filters['id_bien'] = (int)$data['id_bien_source'];
            }
            if (!empty($filters)) {
                try {
                    $comments = inv_comm_fetch($pdo, $filters);
                    if (!empty($comments)) {
                        $overlay = inv_comm_compile_overlay($comments);
                        $texts   = inv_comm_merge_into_texts($texts, $overlay);
                    }
                } catch (Throwable $e) {
                    // Si la table n'existe pas encore, on ignore silencieusement.
                }
            }
        }

        return array_merge($data, $calc, $texts);
    }
}

if (!function_exists('inv_save')) {
    /**
     * INSERT ou UPDATE.
     *   - $id === null   -> INSERT
     *   - $id > 0        -> UPDATE (après contrôle de scope)
     * Retourne l'id de la ligne.
     */
    function inv_save(PDO $pdo, array $data, ?int $id = null): int {
        $sc = inv_current_scope();
        $data = inv_recalc_and_merge($data, $pdo, $id);

        if ($id === null) {
            $data['id_societe'] = $sc['id_societe'];
            $data['id_agence']  = $sc['id_agence'];
            $data['id_user']    = $sc['id_user'];
        }

        $allowed = array_merge(
            ['id_societe','id_agence','id_user'],
            inv_columns_assignables(),
            inv_columns_calculees(),
            inv_columns_textes()
        );
        $data = array_intersect_key($data, array_flip($allowed));

        if ($id === null) {
            $cols = array_keys($data);
            $placeholders = array_map(fn($c) => ':' . $c, $cols);
            $sql = "INSERT INTO investisseur_analyses (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
            $st  = $pdo->prepare($sql);
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
            $st->execute();
            return (int)$pdo->lastInsertId();
        }

        // UPDATE avec contrôle scope
        $existing = inv_load($pdo, $id);
        if (!$existing) throw new RuntimeException("Analyse introuvable ou hors de votre périmètre.");

        $sets = [];
        foreach (array_keys($data) as $c) $sets[] = "`$c` = :$c";
        $sql = "UPDATE investisseur_analyses SET " . implode(',', $sets) . " WHERE id = :id_row";
        $st  = $pdo->prepare($sql);
        foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
        $st->bindValue(':id_row', $id, PDO::PARAM_INT);
        $st->execute();
        return $id;
    }
}

if (!function_exists('inv_load')) {
    function inv_load(PDO $pdo, int $id): ?array {
        $scope = inv_scope_where();
        $sql = "SELECT * FROM investisseur_analyses WHERE id = :id AND " . $scope['sql'] . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        foreach ($scope['params'] as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('inv_list')) {
    /**
     * Liste les analyses (filtrée par scope).
     * $filters : statut, min_score, max_score, ville, strategie, q (recherche libre)
     */
    function inv_list(PDO $pdo, array $filters = [], int $limit = 200): array {
        $scope = inv_scope_where();
        $where = [$scope['sql']];
        $params = $scope['params'];

        if (!empty($filters['statut'])) {
            $where[] = 'statut = :f_statut';
            $params[':f_statut'] = $filters['statut'];
        }
        if (isset($filters['min_score']) && $filters['min_score'] !== '') {
            $where[] = 'score_global >= :f_min';
            $params[':f_min'] = (int)$filters['min_score'];
        }
        if (isset($filters['max_score']) && $filters['max_score'] !== '') {
            $where[] = 'score_global <= :f_max';
            $params[':f_max'] = (int)$filters['max_score'];
        }
        if (!empty($filters['ville'])) {
            $where[] = 'ville LIKE :f_ville';
            $params[':f_ville'] = '%' . $filters['ville'] . '%';
        }
        if (!empty($filters['strategie'])) {
            $where[] = 'strategie = :f_strat';
            $params[':f_strat'] = $filters['strategie'];
        }
        if (!empty($filters['typologie'])) {
            [$frag, $tParams] = inv_typologie_sql_or((string)$filters['typologie'], 'typo');
            $where[] = $frag;
            $params = array_merge($params, $tParams);
        }
        if (!empty($filters['q'])) {
            // Placeholders uniques (MariaDB refuse les réutilisations quand EMULATE_PREPARES=false)
            $where[] = '(titre_analyse LIKE :f_q1 OR reference_bien LIKE :f_q2 OR ville LIKE :f_q3 OR locataire_nom LIKE :f_q4)';
            $needle = '%' . $filters['q'] . '%';
            $params[':f_q1'] = $needle;
            $params[':f_q2'] = $needle;
            $params[':f_q3'] = $needle;
            $params[':f_q4'] = $needle;
        }

        $sql = "SELECT id, titre_analyse, reference_bien, type_bien, ville, quartier, adresse,
                       surface, nb_pieces, prix_vente_catalogue, prix_achat, loyer_estime,
                       locataire_nom, bail_fin, photovoltaique, nb_parkings, honoraires_vente,
                       taxe_fonciere,
                       rendement_brut, rendement_net, cashflow_mensuel,
                       prix_m2, multiple_loyer,
                       score_risque, score_attractivite, score_global,
                       statut, reco_finale, updated_at
                FROM investisseur_analyses
                WHERE " . implode(' AND ', $where) . "
                ORDER BY updated_at DESC
                LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_delete')) {
    function inv_delete(PDO $pdo, int $id): bool {
        $row = inv_load($pdo, $id);
        if (!$row) return false;
        $st = $pdo->prepare("DELETE FROM investisseur_analyses WHERE id = :id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        return $st->execute();
    }
}

if (!function_exists('inv_duplicate')) {
    function inv_duplicate(PDO $pdo, int $id): ?int {
        $row = inv_load($pdo, $id);
        if (!$row) return null;
        unset($row['id'], $row['created_at'], $row['updated_at']);
        $row['titre_analyse'] = 'Copie — ' . $row['titre_analyse'];
        $row['statut'] = 'brouillon';
        return inv_save($pdo, $row, null);
    }
}

if (!function_exists('inv_load_many')) {
    function inv_load_many(PDO $pdo, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) return [];
        $scope = inv_scope_where();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM investisseur_analyses WHERE id IN ($in) AND " . $scope['sql'];
        $st = $pdo->prepare($sql);
        $i = 1;
        foreach ($ids as $v) $st->bindValue($i++, $v, PDO::PARAM_INT);
        foreach ($scope['params'] as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS UI — rendu visuel cohérent avec le design V2
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_score_color')) {
    /**
     * Retourne la couleur CSS (barre gauche de la carte + pastille score).
     *   >=75 vert / >=60 olive / >=45 jaune / >=30 orange / <30 rouge
     */
    function inv_score_color(int $score): string {
        return match (true) {
            $score >= 75 => '#4f7a3a',   // vert olive foncé
            $score >= 60 => '#7ba056',   // vert tendre
            $score >= 45 => '#d9b13a',   // jaune ocre
            $score >= 30 => '#d97a3a',   // orange
            default      => '#b4443a',   // rouge terre cuite
        };
    }
}

if (!function_exists('inv_score_libelle')) {
    function inv_score_libelle(int $score): string {
        return match (true) {
            $score >= 75 => 'Excellent',
            $score >= 60 => 'Solide',
            $score >= 45 => 'Correct',
            $score >= 30 => 'Fragile',
            default      => 'À écarter',
        };
    }
}

if (!function_exists('inv_strategies')) {
    function inv_strategies(): array {
        return [
            'Location nue'      => 'Location nue (Pinel, nu classique)',
            'Meublé LMNP'       => 'Meublé LMNP',
            'Colocation'        => 'Colocation',
            'Saisonnier'        => 'Saisonnier / LCD',
            'Mixte'             => 'Mixte / multi-usages',
            'Revente plus-value'=> 'Achat-revente (plus-value)',
            'Patrimonial'       => 'Patrimonial (long terme)',
        ];
    }
}

if (!function_exists('inv_types_bien')) {
    function inv_types_bien(): array {
        return ['Appartement','Maison','Immeuble','Studio','Local commercial','Local d\'activité','Bureaux','Dépôt','Parking/Garage','Terrain','Autre'];
    }
}

if (!function_exists('inv_typologies')) {
    /**
     * Regroupement "typologie" pour filtrage tableau d'arbitrage.
     * Clé => [libellé, liste de valeurs type_bien qui y tombent (insensible à la casse)]
     */
    function inv_typologies(): array {
        return [
            'commercial' => ['Commercial',     ['local commercial','commerce','boutique']],
            'bureau'     => ['Bureau',         ['bureaux','bureau']],
            'activite'   => ['Activité / Dépôt', ['local d\'activité','local activité','activité','atelier','entrepôt','entrepot','dépôt','depot']],
            'habitation' => ['Habitation',     ['appartement','maison','studio','villa','habitation']],
            'immeuble'   => ['Immeuble',       ['immeuble']],
            'parking'    => ['Parking / Terrain', ['parking','garage','parking/garage','terrain']],
        ];
    }
}

if (!function_exists('inv_typologie_of')) {
    /** Retourne la clé typologie (commercial / bureau / activite / habitation / immeuble / parking / autre). */
    function inv_typologie_of(?string $typeBien): string {
        $tb = strtolower(trim((string)$typeBien));
        if ($tb === '') return 'autre';
        foreach (inv_typologies() as $key => [$lbl, $matches]) {
            foreach ($matches as $m) {
                if ($tb === $m || str_contains($tb, $m)) return $key;
            }
        }
        return 'autre';
    }
}

if (!function_exists('inv_typologie_sql_or')) {
    /**
     * Construit un OR SQL pour filtrer par typologie sur type_bien.
     * Retour : [sqlFragment, paramsAssoc]
     */
    function inv_typologie_sql_or(string $typologieKey, string $prefix = 'typo'): array {
        $typos = inv_typologies();
        if (!isset($typos[$typologieKey])) return ['1=1', []];
        $matches = $typos[$typologieKey][1];
        if (empty($matches)) return ['1=1', []];
        $ors = []; $params = [];
        foreach ($matches as $i => $m) {
            $p = ':' . $prefix . '_' . $i;
            $ors[] = "LOWER(type_bien) LIKE $p";
            $params[$p] = '%' . strtolower($m) . '%';
        }
        return ['(' . implode(' OR ', $ors) . ')', $params];
    }
}

if (!function_exists('inv_etats')) {
    function inv_etats(): array {
        return ['Neuf','Bon état','À rafraîchir','À rénover','Travaux lourds'];
    }
}

if (!function_exists('inv_regimes_fiscaux')) {
    function inv_regimes_fiscaux(): array {
        return ['Micro-foncier','Réel foncier','Micro-BIC','Réel BIC / LMNP','SCI à l\'IS','SCI à l\'IR'];
    }
}

if (!function_exists('inv_types_location')) {
    function inv_types_location(): array {
        return ['Nu résidence principale','Meublé résidence principale','Meublé tourisme','Colocation','Bail étudiant','Bail mobilité','Bail commercial'];
    }
}
