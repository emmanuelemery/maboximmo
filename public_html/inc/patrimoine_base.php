<?php
// inc/patrimoine_base.php — Source UNIQUE de la requête "présence locataire" (CRG)
// utilisée par l'onglet Patrimoine actif (bailleur_patrimoine_actif.php) ET par les
// KPIs du hub (transaction_portefeuilles_hub.php). Évite toute divergence entre le
// nombre d'« Actifs » affiché dans l'onglet et le KPI « Patrimoine actif » du dashboard.
//
// presence='present'  ⇔  loyer appelé > 0 (ou OCCUPÉ PAR PROPRIÉTAIRE), sur le DERNIER
// trimestre CRG du propriétaire. On ne garde que ce dernier trimestre par propriétaire.
declare(strict_types=1);

if (!function_exists('patrimoine_base_sql')) {
    /**
     * @param string $propFilterWhere Clause SQL prête à coller, ex. "AND ct.id_proprietaire IN (1,2,3)"
     *                                ou "AND 1=0" (aucun périmètre) ou '' (tous).
     * @return string Sous-requête SELECT (à envelopper : "SELECT ... FROM (".patrimoine_base_sql(..).") sub").
     */
    function patrimoine_base_sql(string $propFilterWhere, string $scenarioCode = 'courant'): string
    {
        // Filtre équivalent côté table biens (pour la partie "hors CRG").
        // $propFilterWhere ne référence que ct.id_proprietaire → simple substitution.
        $bienFilterWhere = str_replace('ct.id_proprietaire', 'b.id_proprietaire', $propFilterWhere);

        // Scénario sélectionné : par défaut 'courant' (comportement historique inchangé).
        // Quand un scénario alternatif est chargé (ex. 'dusart'), on fait AUSSI entrer dans
        // la population les biens hors-CRG qui portent une valeur DANS CE SCÉNARIO — même si
        // leur prix COURANT est nul. Ces "lots porteurs de scénario" restent donc invisibles
        // en vue Courant (et partout ailleurs), et n'apparaissent QUE sous ce scénario.
        $scenarioCode = preg_replace('/[^a-z0-9_\-]/', '', strtolower($scenarioCode)) ?: 'courant';
        $scenarioInclude = '';
        if ($scenarioCode !== 'courant') {
            $scenarioInclude = "
        OR EXISTS (SELECT 1 FROM bien_prix bps
                     WHERE bps.id_bien = b.id AND bps.type_valeur='prix_vente'
                       AND bps.is_courant=1 AND bps.scenario_code='{$scenarioCode}' AND bps.montant > 0)";
        }

        return "
    SELECT
      crg.id_bien,
      crg.locataire_nom,
      crg.loyer_appele,
      crg.total_loyers,
      crg.total_regle,
      crg.total_impaye,
      ct.id_proprietaire,
      ct.annee,
      ct.trimestre,
      CASE WHEN crg.loyer_appele > 0 OR crg.locataire_nom = 'OCCUPÉ PAR PROPRIÉTAIRE'
           THEN 'present' ELSE 'parti' END AS presence,
      b.id_immeuble,
      COALESCE(i.vendu, 0)    AS imm_vendu,
      COALESCE(ls.archive, 0) AS loc_archive,
      0 AS hors_crg
    FROM crg_situations_locataires crg
    JOIN crg_trimestres ct ON crg.id_crg = ct.id
    LEFT JOIN locataires_statuts ls
      ON ls.locataire_nom = crg.locataire_nom
     AND ls.id_bien = crg.id_bien
     AND ls.id_proprietaire = ct.id_proprietaire
     AND (ls.statut IS NULL OR ls.statut != 'irrecoverable')
    LEFT JOIN biens b ON b.id = crg.id_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    WHERE (ct.parse_statut IS NULL OR ct.parse_statut <> 'erreur') {$propFilterWhere}
      AND (ct.annee, ct.trimestre) = (
        SELECT ct2.annee, ct2.trimestre
        FROM crg_trimestres ct2
        WHERE ct2.id_proprietaire = ct.id_proprietaire
          AND (ct2.parse_statut IS NULL OR ct2.parse_statut <> 'erreur')
        ORDER BY ct2.annee DESC, ct2.trimestre DESC
        LIMIT 1
      )

    UNION ALL

    -- Biens du propriétaire ABSENTS du CRG → intégrés au patrimoine en VACANT (estimé).
    -- Dès qu'un CRG arrive pour ce bien, il bascule dans la partie CRG ci-dessus (et
    -- disparaît d'ici via le NOT EXISTS) : les chiffres réels remplacent l'estimation.
    SELECT
      b.id                    AS id_bien,
      'LOGEMENT VACANT'       AS locataire_nom,
      0                       AS loyer_appele,
      0                       AS total_loyers,
      0                       AS total_regle,
      0                       AS total_impaye,
      b.id_proprietaire,
      NULL                    AS annee,
      NULL                    AS trimestre,
      'parti'                 AS presence,
      b.id_immeuble,
      COALESCE(i.vendu, 0)    AS imm_vendu,
      0                       AS loc_archive,
      1                       AS hors_crg
    FROM biens b
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    WHERE 1=1
      {$bienFilterWhere}
      -- Deux portes d'entrée hors-CRG :
      --  1) Bien VALORISÉ COURANT : actif, non retiré, prix courant > 0 (comportement historique).
      --     Lecture verrouillée sur scenario_code='courant' → aucun prix de scénario ne fuit ici.
      --  2) LOT PORTEUR DE SCÉNARIO : dès qu'un bien porte une valeur dans le scénario chargé,
      --     il entre dans la population MÊME s'il est vendu/archivé/sans prix courant → il
      --     n'apparaît alors QUE sous ce scénario (invisible en Courant et partout ailleurs).
      AND (
        (
          (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
          AND b.date_retrait_commercialisation IS NULL AND b.prix_final_vente IS NULL
          AND COALESCE(
                (SELECT bp.montant FROM bien_prix bp
                   WHERE bp.id_bien = b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code='courant'
                   ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
                b.prix_demande_initial, 0) > 0
        ){$scenarioInclude}
      )
      AND NOT EXISTS (
        SELECT 1 FROM crg_situations_locataires c
        JOIN crg_trimestres t ON t.id = c.id_crg
        WHERE c.id_bien = b.id AND t.id_proprietaire = b.id_proprietaire
          AND (t.parse_statut IS NULL OR t.parse_statut <> 'erreur')
      )
";
    }
}

if (!function_exists('patrimoine_totaux')) {
    /**
     * Totaux du patrimoine pour un périmètre — IDENTIQUES à ce qu'affiche l'onglet
     * Patrimoine actif (colonne « VALEUR PATRIMOINE » + nb de biens).
     *
     * Réplique EXACTEMENT sumPrixVente() de bailleur_patrimoine_actif.php :
     *   - population = TOUS les biens distincts de la base CRG (présents + partis + archives),
     *   - prix par bien = prix_vente validé courant (bien_prix) SINON prix_demande_initial.
     *
     * Renvoie aussi nb_occupes = compteur « Actifs » de l'onglet (locataire présent).
     *
     * @return array{nb:int, valeur:float, nb_occupes:int}
     */
    function patrimoine_totaux(PDO $pdo, string $propFilterWhere): array
    {
        $base = patrimoine_base_sql($propFilterWhere);
        $prix = "COALESCE(
            (SELECT bp.montant FROM bien_prix bp
               WHERE bp.id_bien = x.id_bien AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code='courant'
               ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
            (SELECT b2.prix_demande_initial FROM biens b2 WHERE b2.id = x.id_bien),
            0)";
        // Valeur + nb total : TOUS les biens distincts de la base (= colonne VALEUR PATRIMOINE de l'onglet).
        $sql = "SELECT COUNT(*) AS nb, ROUND(SUM({$prix})) AS valeur
                FROM (SELECT DISTINCT sub.id_bien FROM ({$base}) sub WHERE sub.id_bien IS NOT NULL) x";
        $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
        // nb_occupes : biens avec locataire présent (= compteur « Actifs » de l'onglet).
        $occ = $pdo->query("SELECT COUNT(DISTINCT CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                                THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS n
                            FROM ({$base}) sub")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'nb'         => (int)($r['nb'] ?? 0),
            'valeur'     => (float)($r['valeur'] ?? 0),
            'nb_occupes' => (int)($occ['n'] ?? 0),
        ];
    }
}
