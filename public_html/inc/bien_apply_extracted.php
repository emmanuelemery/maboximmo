<?php
/**
 * inc/bien_apply_extracted.php
 * ─────────────────────────────────────────────────────────────────────────
 * Applique les champs extraits par l'IA (bail / dpe) vers les tables
 * structurées en mode UPSERT défensif :
 *   - cherche une ligne existante par clé naturelle
 *   - si trouvée : UPDATE uniquement les colonnes actuellement vides
 *   - sinon : INSERT
 * + propagation défensive vers `biens` (UPDATE des champs vides).
 *
 * Utilisé par :
 *   - api/biens_documents_reanalyze.php (bouton 🔄)
 *   - api/bien_intake_upload.php  (upload initial — futur refacto)
 *
 * Idempotent : peut tourner N fois sur le même doc sans créer de doublons.
 * ─────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

if (!function_exists('apply_bail_extracted_to_bien')) {
    /**
     * UPSERT bien_baux + sync biens (loyer_hc, statut_occupation, descriptifs vides).
     * Pas de création de tiers/locataire ici — c'est le job de transaction_bail_save.
     *
     * @return array { ok, action: 'inserted'|'updated'|'skipped', bail_id, notes:string[] }
     */
    function apply_bail_extracted_to_bien(PDO $pdo, int $bienId, array $fields, ?string $publicUrl, ?int $userId): array {
        $notes = [];
        if ($bienId <= 0) return ['ok' => false, 'action' => 'skipped', 'bail_id' => 0, 'notes' => ['bien_id invalide']];

        // 1. Vérifie que la table existe — remonte le message SQL exact si erreur
        try {
            $existCols = [];
            $stCols = $pdo->query('SHOW COLUMNS FROM bien_baux');
            while ($r = $stCols->fetch(PDO::FETCH_ASSOC)) $existCols[$r['Field']] = true;
        } catch (Throwable $e) {
            error_log('[apply_bail] SHOW COLUMNS bien_baux : ' . $e->getMessage());
            return ['ok' => false, 'action' => 'skipped', 'bail_id' => 0,
                    'notes' => ['SHOW COLUMNS bien_baux échec : ' . $e->getMessage()]];
        }

        // 2. Mapping fields IA → colonnes bien_baux (préfixes bail_/locataire_/caution_/etc.)
        $map = [
            'bail_nature'             => $fields['bail_bail_nature']      ?? null,
            'bail_type'               => $fields['bail_bail_type']        ?? null,
            'usage_bien'              => $fields['bail_usage_bien']       ?? ($fields['usage_bien'] ?? null),
            'destination_activite'    => $fields['bail_destination_activite'] ?? null,
            'reference_bail'          => $fields['bail_reference_bail']   ?? null,
            'date_signature'          => $fields['bail_date_signature']   ?? null,
            'date_prise_effet'        => $fields['bail_date_prise_effet'] ?? null,
            'duree_mois'              => $fields['bail_duree_mois']       ?? null,
            'date_fin'                => $fields['bail_date_fin']         ?? null,
            'periode_triennale'       => !empty($fields['bail_periode_triennale']) ? 1 : null,
            'reconduction'            => $fields['bail_reconduction']     ?? null,
            'clause_resolutoire'      => isset($fields['bail_clause_resolutoire']) ? (!empty($fields['bail_clause_resolutoire']) ? 1 : 0) : null,
            'loyer_mensuel_hc'        => $fields['loyer_mensuel_hc']      ?? null,
            'complement_loyer'        => $fields['complement_loyer']      ?? null,
            'charges_mensuelles'      => $fields['charges_mensuelles']    ?? null,
            'charges_type'            => $fields['charges_type']          ?? null,
            'total_mensuel'           => $fields['total_mensuel']         ?? null,
            'tva_applicable'          => !empty($fields['tva_applicable']) ? 1 : null,
            'tva_taux'                => $fields['tva_taux']              ?? null,
            'indice_type'             => $fields['indice_type']           ?? null,
            'indice_trimestre'        => $fields['indice_trimestre']      ?? null,
            'indice_valeur'           => $fields['indice_valeur']         ?? null,
            'date_revision_jour_mois' => $fields['date_revision_jour_mois'] ?? null,
            'zone_tendue'             => !empty($fields['zone_tendue']) ? 1 : null,
            'loyer_reference'         => $fields['loyer_reference']       ?? null,
            'loyer_reference_majore'  => $fields['loyer_reference_majore'] ?? null,
            'depot_garantie'          => $fields['depot_garantie']        ?? null,
            'nb_termes_garantie'      => $fields['nb_termes_garantie']    ?? null,
            'honoraires_bailleur_ttc'  => $fields['honoraires_bailleur_ttc']  ?? null,
            'honoraires_locataire_ttc' => $fields['honoraires_locataire_ttc'] ?? null,
            'honoraires_charge'        => $fields['honoraires_charge']        ?? null,
            'locataire_type'           => $fields['locataire_type_personne'] ?? ($fields['locataire_type'] ?? null),
            'locataire_nom'            => $fields['locataire_nom']           ?? null,
            'locataire_prenom'         => $fields['locataire_prenom']        ?? null,
            'locataire_raison_sociale' => $fields['locataire_raison_sociale'] ?? null,
            'locataire_siren'          => $fields['locataire_siren']         ?? null,
            'locataire_email'          => $fields['locataire_email']         ?? null,
            'locataire_telephone'      => $fields['locataire_telephone']     ?? null,
            'caution_type'             => $fields['caution_type_caution']    ?? ($fields['caution_type'] ?? null),
            'caution_nom'              => $fields['caution_nom']             ?? null,
            'caution_prenom'           => $fields['caution_prenom']          ?? null,
        ];
        // Garde uniquement les colonnes qui existent + valeurs non vides
        $map = array_filter($map, fn($v) => $v !== null && $v !== '');
        $map = array_intersect_key($map, $existCols);

        if (empty($map)) {
            return ['ok' => false, 'action' => 'skipped', 'bail_id' => 0, 'notes' => ['aucun champ exploitable']];
        }

        // 3. Recherche idempotence : (id_bien + locataire_nom + date_prise_effet)
        $locNom    = (string)($map['locataire_nom'] ?? '');
        $datePrise = (string)($map['date_prise_effet'] ?? '');
        $bailId    = 0;
        try {
            $st = $pdo->prepare("SELECT id FROM bien_baux
                                  WHERE id_bien = :b
                                    AND COALESCE(locataire_nom,'') = :ln
                                    AND COALESCE(DATE_FORMAT(date_prise_effet, '%Y-%m-%d'),'') = :dp
                                  ORDER BY id DESC LIMIT 1");
            $st->execute([':b' => $bienId, ':ln' => $locNom, ':dp' => $datePrise]);
            $bailId = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $notes[] = 'lookup bien_baux : ' . $e->getMessage();
        }

        try {
            if ($bailId > 0) {
                // UPDATE défensif : ne touche que les colonnes vides en base
                $setParts = []; $params = [':id' => $bailId];
                foreach ($map as $col => $val) {
                    $setParts[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
                    $params[":v_$col"] = $val;
                }
                $dateModFrag = isset($existCols['date_modification']) ? ', date_modification = NOW()' : '';
                $sql = 'UPDATE bien_baux SET ' . implode(', ', $setParts) . $dateModFrag . ' WHERE id = :id';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $notes[] = 'UPDATE défensif sur bien_baux #' . $bailId;
                $action = 'updated';
            } else {
                // INSERT
                $map['id_bien'] = $bienId;
                // Statut : 'actif' si on a le minimum vital (locataire + loyer + date d'effet),
                // sinon 'brouillon' (à compléter manuellement). Le 360 / les listes "baux actifs"
                // filtrent sur statut='actif' donc c'est ce statut qui fait apparaître le bail.
                if (isset($existCols['statut'])) {
                    $hasLocataire = !empty($map['locataire_nom']) || !empty($map['locataire_raison_sociale']);
                    $hasLoyer     = !empty($map['loyer_mensuel_hc']);
                    $hasDate      = !empty($map['date_prise_effet']);
                    $map['statut'] = ($hasLocataire && $hasLoyer && $hasDate) ? 'actif' : 'brouillon';
                    $notes[] = 'statut bail = ' . $map['statut']
                            . ' (loc:' . ($hasLocataire ? 'ok' : 'manque')
                            . ' loyer:' . ($hasLoyer ? 'ok' : 'manque')
                            . ' date:' . ($hasDate ? 'ok' : 'manque') . ')';
                }
                if (isset($existCols['id_user_created']))  $map['id_user_created']  = $userId ?: null;
                if (isset($existCols['document_pdf']) && $publicUrl) $map['document_pdf'] = $publicUrl;
                $cols = array_keys($map);
                $ph   = array_map(fn($c) => ':' . $c, $cols);
                $sql  = 'INSERT INTO bien_baux (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
                $st   = $pdo->prepare($sql);
                $bind = [];
                foreach ($map as $k => $v) $bind[':' . $k] = $v;
                $st->execute($bind);
                $bailId = (int)$pdo->lastInsertId();
                $notes[] = 'INSERT bien_baux #' . $bailId;
                $action = 'inserted';
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'action' => 'error', 'bail_id' => 0, 'notes' => array_merge($notes, ['INSERT/UPDATE bien_baux : ' . $e->getMessage()])];
        }

        // ─── 4-bis. Création tiers locataire + rôles (idempotent) ─────
        // Sans ça : locataire reste en texte dans bien_baux mais n'apparaît pas
        // dans le 360 du bien (qui se base sur tiers_roles).
        $idTiersLocataire = null;
        $locType   = (string)($map['locataire_type'] ?? 'physique');
        $locNom    = (string)($map['locataire_nom'] ?? '');
        $locPrenom = (string)($map['locataire_prenom'] ?? '');
        $locRaison = (string)($map['locataire_raison_sociale'] ?? '');
        $locEmail  = (string)($map['locataire_email'] ?? '');
        $locTel    = (string)($map['locataire_telephone'] ?? '');
        $locSiren  = (string)($map['locataire_siren'] ?? '');

        if ($locRaison !== '' || $locNom !== '' || $locEmail !== '') {
            try {
                // 1. Recherche tiers existant (SIREN → raison → email → nom+prénom)
                if ($locSiren !== '' && preg_match('/^\d{9}(\d{5})?$/', preg_replace('/\D/', '', $locSiren))) {
                    $sirenClean = substr(preg_replace('/\D/', '', $locSiren), 0, 9);
                    $st = $pdo->prepare('SELECT id FROM tiers WHERE REPLACE(siren," ","") = ? OR LEFT(REPLACE(siret," ",""),9) = ? LIMIT 1');
                    $st->execute([$sirenClean, $sirenClean]);
                    $idTiersLocataire = $st->fetchColumn() ?: null;
                }
                if (!$idTiersLocataire && $locRaison !== '') {
                    $rsNorm = preg_replace('/\s+(sas|sarl|sa|sci|snc|eurl|sasu|civile|et\s+cie)\b\.?/i', '', $locRaison);
                    $rsNorm = trim(preg_replace('/\s+/', ' ', (string)$rsNorm));
                    if ($rsNorm !== '') {
                        $st = $pdo->prepare('SELECT id FROM tiers WHERE LOWER(raison_sociale) LIKE LOWER(?) OR LOWER(nom_affichage) LIKE LOWER(?) LIMIT 1');
                        $like = '%' . $rsNorm . '%';
                        $st->execute([$like, $like]);
                        $idTiersLocataire = $st->fetchColumn() ?: null;
                    }
                }
                if (!$idTiersLocataire && $locEmail !== '') {
                    $st = $pdo->prepare('SELECT id FROM tiers WHERE LOWER(email) = LOWER(?) LIMIT 1');
                    $st->execute([$locEmail]);
                    $idTiersLocataire = $st->fetchColumn() ?: null;
                }
                if (!$idTiersLocataire && $locNom !== '' && $locType === 'physique') {
                    $st = $pdo->prepare('SELECT id FROM tiers WHERE type_tiers = "personne_physique"
                        AND LOWER(nom) = LOWER(?) AND LOWER(COALESCE(prenom, "")) = LOWER(?) LIMIT 1');
                    $st->execute([$locNom, $locPrenom]);
                    $idTiersLocataire = $st->fetchColumn() ?: null;
                }

                // 2. INSERT si pas trouvé
                if (!$idTiersLocataire) {
                    $typeT  = ($locType === 'physique') ? 'personne_physique' : 'personne_morale';
                    $nomAff = $locRaison ?: trim($locPrenom . ' ' . $locNom);
                    // Récup id_societe/agence du bien
                    $stB = $pdo->prepare('SELECT id_societe, id_agence FROM biens WHERE id = ? LIMIT 1');
                    $stB->execute([$bienId]);
                    $bRow = $stB->fetch(PDO::FETCH_ASSOC) ?: [];
                    $insT = $pdo->prepare('INSERT INTO tiers
                        (id_societe, id_agence, type_tiers, nom, prenom, raison_sociale, siren, email, telephone,
                         nom_affichage, source_creation, id_user_createur, actif)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "bien_apply_extracted", ?, 1)');
                    $insT->execute([
                        $bRow['id_societe'] ?? null,
                        $bRow['id_agence']  ?? null,
                        $typeT,
                        $locNom    ?: null,
                        $locPrenom ?: null,
                        $locRaison ?: null,
                        $locSiren  ?: null,
                        $locEmail  ?: null,
                        $locTel    ?: null,
                        $nomAff    ?: null,
                        $userId ?: null,
                    ]);
                    $idTiersLocataire = (int)$pdo->lastInsertId();
                    $notes[] = 'tiers locataire CRÉÉ #' . $idTiersLocataire;
                } else {
                    $idTiersLocataire = (int)$idTiersLocataire;
                    $notes[] = 'tiers locataire MATCHÉ #' . $idTiersLocataire;
                }

                // 3. Rôle 'locataire' sur le bail (objet_type='bail')
                $stRole = $pdo->prepare("INSERT INTO tiers_roles
                    (id_tiers, role_code, objet_type, id_objet, date_debut, date_fin, actif)
                    VALUES (?, 'locataire', 'bail', ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE actif = 1, date_debut = VALUES(date_debut), date_fin = VALUES(date_fin)");
                $stRole->execute([
                    $idTiersLocataire, $bailId,
                    $map['date_prise_effet'] ?? null,
                    $map['date_fin'] ?? null,
                ]);

                // 4. Rôle 'locataire' sur le bien (objet_type='bien') — c'est CE rôle que le 360 lit
                $stRole2 = $pdo->prepare("INSERT INTO tiers_roles
                    (id_tiers, role_code, objet_type, id_objet, date_debut, date_fin, actif)
                    VALUES (?, 'locataire', 'bien', ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE actif = 1, date_debut = VALUES(date_debut), date_fin = VALUES(date_fin)");
                $stRole2->execute([
                    $idTiersLocataire, $bienId,
                    $map['date_prise_effet'] ?? null,
                    $map['date_fin'] ?? null,
                ]);

                // 5. FK directe bien_baux.id_tiers_locataire (raccourci JOIN)
                try {
                    $pdo->prepare('UPDATE bien_baux SET id_tiers_locataire = ? WHERE id = ?')
                        ->execute([$idTiersLocataire, $bailId]);
                } catch (Throwable $e) {
                    // colonne pas dispo → on ignore
                }
                $notes[] = 'rôle locataire posé sur bail + bien';
            } catch (Throwable $e) {
                $notes[] = 'création tiers locataire échouée : ' . $e->getMessage();
                error_log('[apply_bail tiers] ' . $e->getMessage());
            }
        }

        // 4. Sync biens (UPDATE défensif des champs vides)
        try {
            $bienCols = [];
            $stB = $pdo->query('SHOW COLUMNS FROM biens');
            while ($r = $stB->fetch(PDO::FETCH_ASSOC)) $bienCols[$r['Field']] = true;
            $bienMap = [
                'loyer_hc'           => $fields['loyer_mensuel_hc']  ?? null,
                'charges_mensuelles' => $fields['charges_mensuelles'] ?? null,
                'statut_occupation'  => !empty($map['locataire_nom']) ? 'occupé' : null,
                'surface_habitable'  => $fields['surface_habitable']  ?? null,
                'nb_pieces'          => $fields['nb_pieces']          ?? null,
                'adresse_1'          => $fields['adresse_1']          ?? null,
                'code_postal'        => $fields['code_postal']        ?? null,
                'ville'              => $fields['ville']              ?? null,
                'annee_construction' => $fields['annee_construction'] ?? null,
                'description'        => $fields['description']        ?? null,
            ];
            $bienMap = array_filter($bienMap, fn($v) => $v !== null && $v !== '');
            $bienMap = array_intersect_key($bienMap, $bienCols);
            if (!empty($bienMap)) {
                $sets = []; $params = [':id' => $bienId];
                foreach ($bienMap as $col => $val) {
                    $sets[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
                    $params[":v_$col"] = $val;
                }
                $dateModBien = isset($bienCols['date_modification']) ? ', date_modification = NOW()' : '';
                $pdo->prepare('UPDATE biens SET ' . implode(', ', $sets) . $dateModBien . ' WHERE id = :id')
                    ->execute($params);
                $notes[] = 'sync biens : ' . count($bienMap) . ' champ(s) potentiellement complété(s)';
            }
        } catch (Throwable $e) {
            $notes[] = 'sync biens échoué : ' . $e->getMessage();
        }

        return ['ok' => true, 'action' => $action, 'bail_id' => $bailId, 'notes' => $notes];
    }
}

if (!function_exists('apply_dpe_extracted_to_bien')) {
    /**
     * UPSERT dpe_diags + sync biens (UPDATE défensif).
     * Idempotence par (id_bien + numero_ademe) ; sinon nouvel INSERT principal.
     */
    function apply_dpe_extracted_to_bien(PDO $pdo, int $bienId, array $fields, ?string $publicUrl, ?int $userId, bool $fillOnly = false): array {
        // $fillOnly = true : NON destructif — ne remplit que les colonnes vides (COALESCE),
        // n'écrase JAMAIS une valeur existante (utilisé par l'auto-reprise à l'ouverture
        // de l'onglet DPE). false = comportement historique (les champs propres au DPE
        // sont écrasés par la dernière extraction — pour les (ré)analyses explicites).
        $notes = [];
        if ($bienId <= 0) return ['ok' => false, 'action' => 'skipped', 'dpe_id' => 0, 'notes' => ['bien_id invalide']];

        try {
            $existCols = [];
            $stCols = $pdo->query('SHOW COLUMNS FROM dpe_diags');
            while ($r = $stCols->fetch(PDO::FETCH_ASSOC)) $existCols[$r['Field']] = true;
        } catch (Throwable $e) {
            // Tentative fallback : table parfois nommée dpe_diagnostics dans d'anciens schémas
            try {
                $stCols2 = $pdo->query('SHOW COLUMNS FROM dpe_diagnostics');
                while ($r = $stCols2->fetch(PDO::FETCH_ASSOC)) $existCols[$r['Field']] = true;
                // OK fallback → on alias en notes pour info
                error_log('[apply_dpe] fallback table dpe_diagnostics utilisée à la place de dpe_diags');
                return ['ok' => false, 'action' => 'skipped', 'dpe_id' => 0,
                        'notes' => ['table dpe_diags absente — found dpe_diagnostics, mais le code attend dpe_diags : ' . $e->getMessage()]];
            } catch (Throwable $e2) {
                return ['ok' => false, 'action' => 'skipped', 'dpe_id' => 0,
                        'notes' => ['dpe_diags introuvable : ' . $e->getMessage(), 'dpe_diagnostics aussi : ' . $e2->getMessage()]];
            }
        }

        $numAdeme = (string)($fields['dpe_reference_certificat'] ?? '');
        // Cherche par numero_ademe ; sinon par date_diagnostic + classe (fallback)
        $dpeId = 0;
        try {
            if ($numAdeme !== '') {
                $st = $pdo->prepare("SELECT id FROM dpe_diags WHERE id_bien = ? AND COALESCE(numero_ademe,'') = ? LIMIT 1");
                $st->execute([$bienId, $numAdeme]);
                $dpeId = (int)($st->fetchColumn() ?: 0);
            }
        } catch (Throwable $e) {
            $notes[] = 'lookup dpe_diags : ' . $e->getMessage();
        }

        $map = [
            'date_diagnostic'         => $fields['dpe_date_realisation'] ?? null,
            'dpe_version'             => $fields['dpe_version']          ?? null,
            'dpe_vierge'              => !empty($fields['dpe_vierge']) ? 1 : null,
            'dpe_classe'              => $fields['dpe_classe']           ?? null,
            'ges_classe'              => $fields['ges_classe']           ?? null,
            'consommation_energie'    => $fields['dpe_valeur']           ?? null,
            'conso_energie_primaire'  => $fields['dpe_valeur_conso_primaire'] ?? null,
            'conso_energie_finale'    => $fields['dpe_valeur_conso_finale']   ?? null,
            'emission_ges'            => $fields['ges_valeur']           ?? null,
            'montant_depenses_min'    => $fields['montant_estime_depenses_min'] ?? null,
            'montant_depenses_max'    => $fields['montant_estime_depenses_max'] ?? null,
            'date_indice_prix'        => $fields['date_indice_prix_energies']  ?? null,
            'numero_ademe'            => $numAdeme ?: null,
            'numero_rapport'          => $numAdeme ?: null,
            'adresse_detectee'        => $fields['adresse_1']            ?? null,
            'code_postal_detecte'     => $fields['code_postal']          ?? null,
            'ville_detectee'          => $fields['ville']                ?? null,
            'type_bien_detecte'       => $fields['type_bien']            ?? null,
            'surface_habitable_detectee' => $fields['surface_habitable'] ?? null,
            'surface_carrez_detectee' => $fields['surface_carrez']      ?? null,
            'surface_sejour_detectee' => $fields['surface_sejour']      ?? null,
            'nb_pieces_detecte'       => $fields['nb_pieces']            ?? null,
            'nb_chambres_detecte'     => $fields['nb_chambres']         ?? null,
            'nb_salles_bain_detecte'  => $fields['nb_salles_bain']      ?? null,
            'nb_salles_eau_detecte'   => $fields['nb_salles_eau']       ?? null,
            'nb_wc_detecte'           => $fields['nb_wc']               ?? null,
            'etage_detecte'           => $fields['etage']               ?? null,
            'lot_detecte'             => $fields['lot_principal'] ?? ($fields['lot'] ?? null),
            'annee_construction_detectee' => $fields['annee_construction'] ?? null,
            'altitude_detectee'       => $fields['altitude']            ?? null,
            'chauffage_type_detecte'  => $fields['chauffage_type']      ?? null,
            'chauffage_energie_detecte' => $fields['chauffage_energie'] ?? null,
            'eau_chaude_type_detecte' => $fields['eau_chaude_type'] ?? ($fields['eau_chaude'] ?? null),
            'menuiseries_detectees'   => $fields['menuiseries']         ?? null,
            'double_vitrage_detecte'  => isset($fields['double_vitrage']) ? (!empty($fields['double_vitrage']) ? 1 : 0) : null,
            'volets_roulants_detecte' => isset($fields['volets_roulants']) ? (!empty($fields['volets_roulants']) ? 1 : 0) : null,
            'date_validite'           => $fields['dpe_date_validite'] ?? ($fields['date_validite'] ?? null),
            'diagnostiqueur_nom'      => $fields['diag_operateur_nom'] ?? ($fields['diagnostiqueur_nom'] ?? null),
            'diagnostiqueur_societe'  => $fields['diag_operateur_societe'] ?? ($fields['diagnostiqueur_societe'] ?? null),
            'extraction_method'       => 'reanalyze',
        ];
        $map = array_filter($map, fn($v) => $v !== null && $v !== '');
        $map = array_intersect_key($map, $existCols);
        if (empty($map)) return ['ok' => false, 'action' => 'skipped', 'dpe_id' => 0, 'notes' => ['aucun champ exploitable']];

        try {
            if ($dpeId > 0) {
                $sets = []; $params = [':id' => $dpeId];
                foreach ($map as $col => $val) {
                    $sets[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
                    $params[":v_$col"] = $val;
                }
                $dateModDpe = isset($existCols['date_modification']) ? ', date_modification = NOW()' : '';
                $pdo->prepare('UPDATE dpe_diags SET ' . implode(', ', $sets) . $dateModDpe . ' WHERE id = :id')
                    ->execute($params);
                $notes[] = 'UPDATE défensif dpe_diags #' . $dpeId;
                $action = 'updated';
            } else {
                // Passe les autres lignes en non principal
                if (isset($existCols['est_diag_principal'])) {
                    $pdo->prepare('UPDATE dpe_diags SET est_diag_principal = 0 WHERE id_bien = ?')->execute([$bienId]);
                }
                $map['id_bien']            = $bienId;
                if (isset($existCols['type_diag']))         $map['type_diag']         = 'dpe';
                if (isset($existCols['est_diag_principal']))$map['est_diag_principal']= 1;
                if (isset($existCols['extraction_date']))   $map['extraction_date']   = date('Y-m-d H:i:s');
                if (isset($existCols['fichier_url']) && $publicUrl) $map['fichier_url'] = $publicUrl;
                $cols = array_keys($map);
                $ph = array_map(fn($c) => ':' . $c, $cols);
                $st = $pdo->prepare('INSERT INTO dpe_diags (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')');
                $bind = [];
                foreach ($map as $k => $v) $bind[':' . $k] = $v;
                $st->execute($bind);
                $dpeId = (int)$pdo->lastInsertId();
                $notes[] = 'INSERT dpe_diags #' . $dpeId . ' (devient principal)';
                $action = 'inserted';
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'action' => 'error', 'dpe_id' => 0, 'notes' => array_merge($notes, ['INSERT/UPDATE dpe_diags : ' . $e->getMessage()])];
        }

        // Sync biens (classes / valeurs / surfaces / adresse vide)
        try {
            $bienCols = [];
            $stB = $pdo->query('SHOW COLUMNS FROM biens');
            while ($r = $stB->fetch(PDO::FETCH_ASSOC)) $bienCols[$r['Field']] = true;
            // A) Toujours écraser : champs propres au DPE (dernier fait foi)
            $alwaysOverwrite = [
                'dpe_classe' => $fields['dpe_classe'] ?? null,
                'ges_classe' => $fields['ges_classe'] ?? null,
                'dpe_valeur' => $fields['dpe_valeur'] ?? null,
                'ges_valeur' => $fields['ges_valeur'] ?? null,
                'dpe_date_realisation' => $fields['dpe_date_realisation'] ?? null,
                'dpe_version' => $fields['dpe_version'] ?? null,
                'dpe_reference_certificat' => $fields['dpe_reference_certificat'] ?? null,
                'montant_estime_depenses_min' => $fields['montant_estime_depenses_min'] ?? null,
                'montant_estime_depenses_max' => $fields['montant_estime_depenses_max'] ?? null,
            ];
            // B) Fill if empty : descriptifs bien
            $fillIfEmpty = [
                'surface_habitable' => $fields['surface_habitable'] ?? null,
                'nb_pieces'         => $fields['nb_pieces'] ?? null,
                'adresse_1'         => $fields['adresse_1'] ?? null,
                'code_postal'       => $fields['code_postal'] ?? null,
                'ville'             => $fields['ville'] ?? null,
                'annee_construction'=> $fields['annee_construction'] ?? null,
            ];
            $sets = []; $params = [':id' => $bienId];
            foreach ($alwaysOverwrite as $col => $val) {
                if ($val === null || $val === '' || !isset($bienCols[$col])) continue;
                $sets[] = $fillOnly
                    ? "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)"
                    : "`$col` = :v_$col";
                $params[":v_$col"] = $val;
            }
            foreach ($fillIfEmpty as $col => $val) {
                if ($val === null || $val === '' || !isset($bienCols[$col])) continue;
                $sets[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
                $params[":v_$col"] = $val;
            }
            if (!empty($sets)) {
                $dateModBien = isset($bienCols['date_modification']) ? ', date_modification = NOW()' : '';
                $pdo->prepare('UPDATE biens SET ' . implode(', ', $sets) . $dateModBien . ' WHERE id = :id')
                    ->execute($params);
                $notes[] = 'sync biens : ' . count($sets) . ' colonne(s) mises à jour';
            }
        } catch (Throwable $e) {
            $notes[] = 'sync biens échoué : ' . $e->getMessage();
        }

        return ['ok' => true, 'action' => $action, 'dpe_id' => $dpeId, 'notes' => $notes];
    }
}
