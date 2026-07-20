<?php
/**
 * inc/bien_enrich_from_doc.php
 *
 * Module d'enrichissement automatique des tables métier (biens, immeubles, mandats)
 * à partir des données extraites par l'IA d'un document.
 *
 * RÈGLES MÉTIER (validées Emery 2026-05-25) :
 *   - Si IA conf ≥ 90% ET champ BDD est NULL → UPDATE auto.
 *   - Si IA conf ≥ 90% ET champ BDD a déjà une valeur DIFFÉRENTE → LOG warning, ne touche pas (review humaine).
 *   - Si IA conf < 90% → ne touche pas (review humaine).
 *   - Tous les UPDATE sont loggés dans fluxbox_actions_ia pour traçabilité + rollback.
 *
 * Anti-doublon TIERS via inc/entity_matcher.php :
 *   - Si IA détecte "Mr Dupont" et score matching ≥ 85 avec tiers existant → réutilise tiers existant.
 *   - Score 50-85 → propose en review (pas auto-action).
 *   - Score < 50 → propose CREATE nouveau tiers (flag à compléter).
 *
 * Réf : Sprint Objectif Ultime 2026-05-25 (étape 3/4)
 */

declare(strict_types=1);

require_once __DIR__ . '/entity_matcher.php';

if (!function_exists('bef_log_audit')) {
    function bef_log_audit(PDO $pdo, int $tenantId, ?int $carteId, string $action, string $label, array $payload, float $confiance = 1.0): void {
        try {
            $pdo->prepare("
                INSERT INTO fluxbox_actions_ia
                    (tenant_id, carte_id, action_type, action_label, payload_json, confiance, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$tenantId, $carteId, $action, $label, json_encode($payload, JSON_UNESCAPED_UNICODE), $confiance]);
        } catch (Throwable) {}
    }
}

if (!function_exists('bef_enrich_bien')) {
    /**
     * Enrichit la table biens depuis l'IA extraction.
     * Règles :
     *   - Si IA conf >= 90 ET BDD NULL → UPDATE
     *   - Si IA conf >= 90 ET BDD différent → log conflit, no-op
     *   - Si IA conf < 90 → no-op
     *
     * @param array $extraction  IA extraction (response_json de ia_extract_cache)
     * @param int $iaConf        confiance IA (0-100)
     * @return array {
     *   updated:string[],   // colonnes mises à jour
     *   conflicts:string[], // colonnes en conflit (non touchées)
     *   skipped:string[],   // colonnes IA vide ou conf faible
     * }
     */
    function bef_enrich_bien(int $bienId, array $extraction, int $iaConf, PDO $pdo, ?int $carteId = null): array {
        $result = ['updated' => [], 'conflicts' => [], 'skipped' => []];
        if ($iaConf < 90) {
            $result['skipped'][] = "iaConf=$iaConf < 90 (no enrichissement)";
            return $result;
        }

        // Map IA fields → biens columns
        $map = [
            'dpe_classe'              => 'dpe_classe',
            'dpe_valeur'              => 'dpe_valeur',
            'dpe_valeur_kwh'          => 'dpe_valeur',
            'dpe_consommation'        => 'dpe_valeur',
            'ges_classe'              => 'ges_classe',
            'ges_valeur'              => 'ges_valeur',
            'ges_emissions'           => 'ges_valeur',
            'dpe_date_realisation'    => 'dpe_date_realisation',
            'dpe_date'                => 'dpe_date_realisation',
            'dpe_reference'           => 'dpe_reference_certificat',
            'dpe_reference_certificat'=> 'dpe_reference_certificat',
            'dpe_version'             => 'dpe_version',
            'dpe_vierge'              => 'dpe_vierge',
            'surface_carrez'          => 'surface_carrez',
            'surface_habitable'       => 'surface_carrez', // fallback
        ];

        // Lecture bien actuel
        $cols = implode(',', array_unique(array_values($map)));
        $st = $pdo->prepare("SELECT id_societe, $cols FROM biens WHERE id = ?");
        $st->execute([$bienId]);
        $bien = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bien) {
            $result['skipped'][] = 'bien_not_found';
            return $result;
        }
        $tenantId = (int)($bien['id_societe'] ?? 1);

        $updates = [];
        foreach ($map as $iaKey => $bienCol) {
            $iaValue = $extraction[$iaKey] ?? null;
            if ($iaValue === null || $iaValue === '') continue;

            $bddValue = $bien[$bienCol] ?? null;
            // BDD a une valeur ET elle est différente
            if ($bddValue !== null && $bddValue !== '' && (string)$bddValue !== (string)$iaValue) {
                $result['conflicts'][] = "$bienCol: BDD='$bddValue' vs IA='$iaValue'";
                continue;
            }
            // BDD NULL/vide → on enrichit
            if ($bddValue === null || $bddValue === '') {
                $updates[$bienCol] = $iaValue;
            }
        }

        if (empty($updates)) {
            $result['skipped'][] = 'no_field_to_update';
            return $result;
        }

        $sets = [];
        $vals = [];
        foreach ($updates as $col => $val) {
            $sets[] = "`$col` = ?";
            $vals[] = $val;
        }
        $vals[] = $bienId;
        $sql = "UPDATE biens SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = ?";
        try {
            $pdo->prepare($sql)->execute($vals);
            $result['updated'] = array_keys($updates);

            bef_log_audit($pdo, $tenantId, $carteId, 'enrich_bien', 'Enrichissement bien #' . $bienId, [
                'bien_id' => $bienId, 'updated' => $updates, 'conflicts' => $result['conflicts'],
                'ia_confidence' => $iaConf,
            ], $iaConf / 100);
        } catch (Throwable $e) {
            $result['skipped'][] = 'sql_error: ' . $e->getMessage();
        }
        return $result;
    }
}

if (!function_exists('bef_enrich_mandat')) {
    /**
     * Enrichit la table mandats depuis l'IA extraction.
     */
    function bef_enrich_mandat(int $mandatId, array $extraction, int $iaConf, PDO $pdo, ?int $carteId = null): array {
        $result = ['updated' => [], 'conflicts' => [], 'skipped' => []];
        if ($iaConf < 90) {
            $result['skipped'][] = "iaConf=$iaConf < 90";
            return $result;
        }

        $map = [
            'numero_mandat'      => 'numero_mandat',
            'date_signature'     => 'date_signature',
            'date_debut'         => 'date_debut',
            'date_fin'           => 'date_fin',
            'honoraires'         => 'honoraires',
            'honoraires_pct'     => 'honoraires',
            'exclusif'           => 'exclusif',
        ];
        $cols = implode(',', array_unique(array_values($map)));
        $st = $pdo->prepare("SELECT id_societe, $cols FROM mandats m LEFT JOIN biens b ON b.id = m.id_bien WHERE m.id = ?");
        // erreur SQL ambiguïté si id_societe sur 2 tables — utilise sous-query
        $st = $pdo->prepare("SELECT $cols FROM mandats WHERE id = ?");
        $st->execute([$mandatId]);
        $mandat = $st->fetch(PDO::FETCH_ASSOC);
        if (!$mandat) { $result['skipped'][] = 'mandat_not_found'; return $result; }
        // Tenant
        $st = $pdo->prepare("SELECT b.id_societe FROM mandats m JOIN biens b ON b.id = m.id_bien WHERE m.id = ?");
        $st->execute([$mandatId]);
        $tenantId = (int)($st->fetchColumn() ?: 1);

        $updates = [];
        foreach ($map as $iaKey => $col) {
            $iaValue = $extraction[$iaKey] ?? null;
            if ($iaValue === null || $iaValue === '') continue;
            $bddValue = $mandat[$col] ?? null;
            if ($bddValue !== null && $bddValue !== '' && (string)$bddValue !== (string)$iaValue) {
                $result['conflicts'][] = "$col: BDD='$bddValue' vs IA='$iaValue'";
                continue;
            }
            if ($bddValue === null || $bddValue === '') $updates[$col] = $iaValue;
        }

        if (empty($updates)) { $result['skipped'][] = 'no_field_to_update'; return $result; }
        $sets = []; $vals = [];
        foreach ($updates as $col => $val) { $sets[] = "`$col` = ?"; $vals[] = $val; }
        $vals[] = $mandatId;
        try {
            $pdo->prepare("UPDATE mandats SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = ?")
                ->execute($vals);
            $result['updated'] = array_keys($updates);
            bef_log_audit($pdo, $tenantId, $carteId, 'enrich_mandat', 'Enrichissement mandat #' . $mandatId, [
                'mandat_id' => $mandatId, 'updated' => $updates, 'conflicts' => $result['conflicts'],
                'ia_confidence' => $iaConf,
            ], $iaConf / 100);
        } catch (Throwable $e) {
            $result['skipped'][] = 'sql_error: ' . $e->getMessage();
        }
        return $result;
    }
}

if (!function_exists('bef_resolve_tiers_with_dedup')) {
    /**
     * Cherche un tiers existant via entity_matcher, ou propose création.
     *
     * @return array {
     *   action: 'matched_existing' | 'propose_create' | 'propose_review' | 'no_data',
     *   tiers_id: ?int,
     *   score: int,
     *   matched: array,   // candidats matchés (top 3)
     * }
     */
    function bef_resolve_tiers_with_dedup(PDO $pdo, ?string $nom, ?string $raisonSociale = null, ?string $email = null): array {
        $result = ['action' => 'no_data', 'tiers_id' => null, 'score' => 0, 'matched' => []];
        $nom = trim((string)$nom);
        $rs  = trim((string)$raisonSociale);
        if ($nom === '' && $rs === '') return $result;

        try {
            $match = em_match_tiers($pdo, [
                'nom' => $nom,
                'raison_sociale' => $rs,
                'email' => $email,
            ]);
        } catch (Throwable) {
            $match = ['found' => false, 'matches' => []];
        }

        $matches = $match['matches'] ?? [];
        $result['matched'] = array_slice($matches, 0, 3);

        if (empty($matches)) {
            $result['action'] = 'propose_create';
            return $result;
        }

        $best = $matches[0];
        $score = (int)($best['score'] ?? 0);
        $result['score'] = $score;

        if ($score >= 85) {
            $result['action'] = 'matched_existing';
            $result['tiers_id'] = (int)$best['id'];
        } elseif ($score >= 50) {
            $result['action'] = 'propose_review';
            $result['tiers_id'] = (int)$best['id']; // proposé mais pas confirmé
        } else {
            $result['action'] = 'propose_create';
        }
        return $result;
    }
}

if (!function_exists('bef_enrich_from_carte')) {
    /**
     * Helper haut niveau : enrichit toutes les tables impactées par les données IA
     * d'une carte FluxBox.
     *
     * Appelé après auto-commit GED (ou manuellement depuis review).
     */
    /**
     * T3 — Enrichit DÉFENSIVEMENT le propriétaire (SIREN) et l'immeuble (adresse) DÉJÀ liés au
     * bien, depuis l'extraction. Jamais de création ni de re-lien : on ne complète que des
     * colonnes vides d'entités existantes (biens.id_proprietaire / biens.id_immeuble).
     */
    function bef_enrich_proprio_immeuble(PDO $pdo, int $bienId, array $ext, ?int $carteId, int $iaConf): array {
        $out = ['proprio_siren' => null, 'immeuble' => null];

        // Propriétaire : biens.id_proprietaire → proprietaires.id_tiers → tiers.siren (si vide).
        $siren = preg_replace('/\D/', '', (string)($ext['proprietaire_siren'] ?? $ext['bailleur_siren'] ?? ''));
        if (strlen((string)$siren) === 9) {
            try {
                $st = $pdo->prepare("SELECT t.id, COALESCE(t.siren,'') siren
                                       FROM biens b JOIN proprietaires p ON p.id = b.id_proprietaire
                                       JOIN tiers t ON t.id = p.id_tiers WHERE b.id = ? LIMIT 1");
                $st->execute([$bienId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && $r['siren'] === '') {
                    $pdo->prepare("UPDATE tiers SET siren = ? WHERE id = ?")->execute([$siren, (int)$r['id']]);
                    $out['proprio_siren'] = $siren;
                    bef_log_audit($pdo, 0, $carteId, 'enrich_proprio', 'SIREN propriétaire → tiers #' . $r['id'], ['siren' => $siren], $iaConf / 100);
                }
            } catch (Throwable $e) { /* best-effort */ }
        }

        // Immeuble : adresse/cp/ville (si vides) depuis l'adresse du bien extraite.
        $adr = trim((string)($ext['adresse_bien'] ?? '')); $cp = trim((string)($ext['code_postal'] ?? '')); $ville = trim((string)($ext['ville'] ?? ''));
        if ($adr !== '' || $cp !== '' || $ville !== '') {
            try {
                $st = $pdo->prepare("SELECT i.id, COALESCE(i.adresse_1,'') a, COALESCE(i.code_postal,'') cp, COALESCE(i.ville,'') v
                                       FROM biens b JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ? LIMIT 1");
                $st->execute([$bienId]);
                $im = $st->fetch(PDO::FETCH_ASSOC);
                if ($im) {
                    $sets = []; $p = [];
                    if ($adr !== ''   && $im['a']  === '') { $sets[] = 'adresse_1 = ?';   $p[] = $adr; }
                    if ($cp !== ''    && $im['cp'] === '') { $sets[] = 'code_postal = ?'; $p[] = $cp; }
                    if ($ville !== '' && $im['v']  === '') { $sets[] = 'ville = ?';       $p[] = $ville; }
                    if ($sets) {
                        $p[] = (int)$im['id'];
                        $pdo->prepare('UPDATE immeubles SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($p);
                        $out['immeuble'] = ['id' => (int)$im['id'], 'champs' => count($sets)];
                        bef_log_audit($pdo, 0, $carteId, 'enrich_immeuble', 'Adresse immeuble #' . $im['id'], ['champs' => $sets], $iaConf / 100);
                    }
                }
            } catch (Throwable $e) { /* best-effort */ }
        }
        return $out;
    }

    /**
     * T2b — AUTO-EXTRACTION AU CHARGEMENT. Lance l'extraction IA dédiée dès le classement
     * d'un bail / mandat / DPE, puis reporte dans les tables (sans attendre un cache préalable).
     *   - bail   : transaction_doc_extract_ia (cache auto) → bien_baux (adaptateur) + proprio/immeuble
     *   - mandat : transaction_doc_extract_ia → mandat actif du bien (bef_enrich_mandat)
     *   - dpe    : dpe_analyser (texte→OCR→IA) → dpe_diags + biens (apply_dpe, non destructif)
     * Best-effort, idempotent (extractions à cache/anti-doublon internes). $bienId requis.
     */
    function bef_autoextract_on_load(PDO $pdo, int $carteId, string $typeDoc, string $pdfPath, int $bienId): array {
        $t = strtolower($typeDoc);
        $out = ['ran' => []];
        if ($pdfPath === '' || !is_file($pdfPath) || $bienId <= 0) { $out['ran'][] = 'skip(no_path/bien)'; return $out; }
        require_once __DIR__ . '/bien_apply_extracted.php';

        if (str_contains($t, 'bail')) {
            try {
                require_once __DIR__ . '/transaction_doc_extract_ia.php';
                $r = transaction_doc_extract_ia($pdfPath);
                if (!empty($r['ok']) && !empty($r['data'])) {
                    $f = bail_map_transaction_extraction($r['data']);
                    if ($f) {
                        $out['bail'] = apply_bail_extracted_to_bien($pdo, $bienId, $f, null, null);
                        bef_enrich_proprio_immeuble($pdo, $bienId, $r['data'], $carteId, (int)($r['confidence'] ?? 0));
                        $out['ran'][] = 'bail:' . ($out['bail']['action'] ?? '?');
                        bef_log_audit($pdo, 0, $carteId, 'autoextract_bail', 'Bail auto-extrait → bien_baux', ['conf' => $r['confidence'] ?? 0], (int)($r['confidence'] ?? 0) / 100);
                    }
                } else { $out['ran'][] = 'bail:extract_ko'; }
            } catch (Throwable $e) { $out['ran'][] = 'bail_err'; error_log('[autoextract bail] ' . $e->getMessage()); }
        } elseif (str_contains($t, 'mandat')) {
            try {
                require_once __DIR__ . '/transaction_doc_extract_ia.php';
                $r = transaction_doc_extract_ia($pdfPath);
                if (!empty($r['ok']) && !empty($r['data'])) {
                    $st = $pdo->prepare("SELECT id FROM mandats WHERE id_bien = ? ORDER BY (statut IN ('actif','en_cours','signe')) DESC, date_signature DESC LIMIT 1");
                    $st->execute([$bienId]);
                    $mid = (int)$st->fetchColumn();
                    if ($mid > 0) {
                        $out['mandat'] = bef_enrich_mandat($mid, $r['data'], (int)($r['confidence'] ?? 0), $pdo, $carteId);
                        $out['ran'][] = 'mandat:#' . $mid;
                    } else { $out['ran'][] = 'mandat:aucun_mandat'; }
                } else { $out['ran'][] = 'mandat:extract_ko'; }
            } catch (Throwable $e) { $out['ran'][] = 'mandat_err'; error_log('[autoextract mandat] ' . $e->getMessage()); }
        } elseif (str_contains($t, 'dpe') || str_contains($t, 'diagnostic')) {
            try {
                require_once __DIR__ . '/dpe_service.php';
                $r = dpe_analyser($pdfPath);
                if (!empty($r['ok']) && !empty($r['fields'])) {
                    $out['dpe'] = apply_dpe_extracted_to_bien($pdo, $bienId, $r['fields'], null, null, true);
                    $out['ran'][] = 'dpe:' . ($out['dpe']['action'] ?? '?');
                    bef_log_audit($pdo, 0, $carteId, 'autoextract_dpe', 'DPE auto-extrait → dpe_diags/biens', ['score' => $r['score'] ?? 0], (int)($r['score'] ?? 0) / 100);
                } else { $out['ran'][] = 'dpe:extract_ko'; }
            } catch (Throwable $e) { $out['ran'][] = 'dpe_err'; error_log('[autoextract dpe] ' . $e->getMessage()); }
        }
        return $out;
    }

    function bef_enrich_from_carte(int $carteId, PDO $pdo): array {
        $result = ['bien' => null, 'mandat' => null, 'bail' => null, 'dpe' => null, 'proprio_immeuble' => null, 'tiers_resolution' => null, 'skipped' => null];

        // Lit carte + doc + hash + entity
        $st = $pdo->prepare("SELECT c.proposition_json, d.hash_sha256 FROM fluxbox_cartes c LEFT JOIN fluxbox_documents d ON d.id = c.document_id WHERE c.id = ?");
        $st->execute([$carteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $result['skipped'] = 'carte_not_found'; return $result; }

        $extraction = [];
        $iaConf = 0;
        if (!empty($row['hash_sha256'])) {
            $st = $pdo->prepare("SELECT response_json, confidence FROM ia_extract_cache WHERE hash_sha256 = ? ORDER BY last_at DESC LIMIT 1");
            $st->execute([$row['hash_sha256']]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($c['response_json'])) {
                $extraction = json_decode((string)$c['response_json'], true) ?: [];
                $iaConf = (int)$c['confidence'];
            }
        }
        if (empty($extraction) || $iaConf < 90) {
            $result['skipped'] = "iaConf=$iaConf or no extraction";
            return $result;
        }

        // Entité depuis proposition
        $prop = !empty($row['proposition_json']) ? json_decode((string)$row['proposition_json'], true) : [];

        if (!empty($prop['bien_id'])) {
            $result['bien'] = bef_enrich_bien((int)$prop['bien_id'], $extraction, $iaConf, $pdo, $carteId);
        }

        // Si type_doc = mandat → enrichit le mandat actif du bien
        $typeDoc = strtolower((string)($extraction['type_doc'] ?? ''));
        if (str_contains($typeDoc, 'mandat') && !empty($prop['bien_id'])) {
            $st = $pdo->prepare("SELECT id FROM mandats WHERE id_bien = ? ORDER BY (statut IN ('actif','en_cours','signe')) DESC, date_signature DESC LIMIT 1");
            $st->execute([(int)$prop['bien_id']]);
            $mandatId = (int)$st->fetchColumn();
            if ($mandatId > 0) {
                $result['mandat'] = bef_enrich_mandat($mandatId, $extraction, $iaConf, $pdo, $carteId);
            }
        }

        // Si type_doc = bail → reporte l'extraction dans bien_baux (via l'adaptateur, sans IA
        // supplémentaire). Corrige le fait que le flux FluxBox n'alimentait jamais le bail.
        if (str_contains($typeDoc, 'bail') && !empty($prop['bien_id'])) {
            require_once __DIR__ . '/bien_apply_extracted.php';
            $bailFields = bail_map_transaction_extraction($extraction);
            if (!empty($bailFields)) {
                $result['bail'] = apply_bail_extracted_to_bien($pdo, (int)$prop['bien_id'], $bailFields, null, null);
                bef_log_audit($pdo, 0, $carteId, 'enrich_bail',
                    'Report bail → bien_baux #' . ($result['bail']['bail_id'] ?? 0),
                    ['champs' => array_keys($bailFields), 'action' => $result['bail']['action'] ?? null], $iaConf / 100);
            }
        }

        // Si type_doc = DPE/diagnostic → report complet (dpe_diags + sync biens) via la fonction
        // dédiée, en mode NON destructif (fillOnly). Complète bef_enrich_bien (qui ne pose qu'un
        // sous-ensemble sur biens) et crée la ligne dpe_diags. Corrige « le DPE ne remplit rien ».
        if ((str_contains($typeDoc, 'dpe') || str_contains($typeDoc, 'diagnostic')) && !empty($prop['bien_id'])) {
            require_once __DIR__ . '/bien_apply_extracted.php';
            $result['dpe'] = apply_dpe_extracted_to_bien($pdo, (int)$prop['bien_id'], $extraction, null, null, true);
            bef_log_audit($pdo, 0, $carteId, 'enrich_dpe',
                'Report DPE → dpe_diags/biens #' . ($result['dpe']['dpe_id'] ?? 0),
                ['action' => $result['dpe']['action'] ?? null], $iaConf / 100);
        }

        // T3 : enrichit défensivement le propriétaire (SIREN) + l'immeuble (adresse) existants.
        if (!empty($prop['bien_id'])) {
            $result['proprio_immeuble'] = bef_enrich_proprio_immeuble($pdo, (int)$prop['bien_id'], $extraction, $carteId, $iaConf);
        }

        // Résolution tiers anti-doublon (propose, ne crée pas auto)
        $nomTiers = $extraction['proprietaire'] ?? $extraction['locataire'] ?? $extraction['bailleur_representant_nom'] ?? null;
        if ($nomTiers) {
            $result['tiers_resolution'] = bef_resolve_tiers_with_dedup($pdo, (string)$nomTiers);
        }

        return $result;
    }
}
