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
    function bef_enrich_from_carte(int $carteId, PDO $pdo): array {
        $result = ['bien' => null, 'mandat' => null, 'tiers_resolution' => null, 'skipped' => null];

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

        // Résolution tiers anti-doublon (propose, ne crée pas auto)
        $nomTiers = $extraction['proprietaire'] ?? $extraction['locataire'] ?? $extraction['bailleur_representant_nom'] ?? null;
        if ($nomTiers) {
            $result['tiers_resolution'] = bef_resolve_tiers_with_dedup($pdo, (string)$nomTiers);
        }

        return $result;
    }
}
