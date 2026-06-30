<?php
/**
 * inc/transaction_metier_hooks.php
 *
 * Hooks métier Transaction : mise à jour conditionnelle de mandats/biens après
 * persistance d'un document via Sprint 3D + MVP Transaction E2E.
 *
 * APPROCHE :
 *   - Hooks OPT-IN (case à cocher dans la page review)
 *   - Conditionnés par confiance IA + type doc reconnu
 *   - Idempotents : update SEULEMENT si champ cible est NULL ou plus ancien
 *   - Dry-run par défaut, apply après validation user
 *   - Transaction PDO atomique (rollback si une étape plante)
 *
 * Mapping type_doc → action MVP V0 :
 *   - mandat_*       → mandats.date_signature (si vide) + date_debut (si vide)
 *   - compromis      → log audit seulement (pas de colonne date_compromis en BDD)
 *   - acte_authentique → biens.date_retrait_commercialisation + prix_final_vente + statut_bien='vendu'
 *   - dpe            → biens.dpe_classe, dpe_valeur, dpe_date_realisation (si extraction fiable)
 *   - erp_ernmt      → biens.erp_date_realisation
 *
 * AUCUN champ de date métier dédié n'existe pour compromis (date_compromis manquant en BDD).
 * V2 : migration séparée pour ajouter ces colonnes si besoin.
 *
 * Réf : Sprint MVP Transaction E2E (2026-05-24)
 */

declare(strict_types=1);

if (!function_exists('tmh_log')) {
    /** Log helper interne */
    function tmh_log(array &$report, string $level, string $msg, array $extra = []): void {
        $report['log'][] = ['ts' => date('H:i:s'), 'level' => $level, 'msg' => $msg, 'extra' => $extra];
    }
}

if (!function_exists('tmh_parse_date')) {
    /** Parse date robuste (ISO, FR, timestamp). NULL si invalide. */
    function tmh_parse_date(?string $d): ?string {
        if (!$d || trim($d) === '') return null;
        try {
            $dt = new DateTime(trim($d));
            return $dt->format('Y-m-d');
        } catch (Throwable) {
            // Tentative FR jj/mm/yyyy
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($d), $m)) {
                try {
                    return (new DateTime("{$m[3]}-{$m[2]}-{$m[1]}"))->format('Y-m-d');
                } catch (Throwable) {}
            }
            return null;
        }
    }
}

if (!function_exists('tmh_run_hooks')) {
    /**
     * Exécute les hooks métier Transaction pour un document validé.
     *
     * @param PDO   $pdo
     * @param array $ctx  Contexte :
     *   - type_doc        : type canonique (mandat_signe, compromis, acte_authentique, dpe, erp_ernmt)
     *   - confidence_ia   : int 0-100
     *   - date_doc        : date métier (ISO ou jj/mm/yyyy)
     *   - bien_id         : id du bien lié (recommandé)
     *   - mandat_id       : id du mandat lié (si applicable)
     *   - extracted_data  : payload IA (montants, DPE classe/valeur, etc.)
     * @param bool  $dryRun  Default true.
     * @param int   $minConfidence  Seuil minimal (90 par défaut)
     * @return array Rapport {mode, totals, log, actions, errors}
     */
    function tmh_run_hooks(PDO $pdo, array $ctx, bool $dryRun = true, int $minConfidence = 90): array
    {
        $report = [
            'mode'         => $dryRun ? 'dry_run' : 'apply',
            'started_at'   => date('Y-m-d H:i:s'),
            'totals'       => ['actions' => 0, 'skipped' => 0, 'errors' => 0],
            'log'          => [],
            'actions'      => [],
            'errors'       => [],
        ];

        $type = strtolower(trim((string)($ctx['type_doc'] ?? '')));
        $conf = (int)($ctx['confidence_ia'] ?? 0);
        $dateDoc = tmh_parse_date((string)($ctx['date_doc'] ?? ''));
        $bienId = (int)($ctx['bien_id'] ?? 0);
        $mandatId = (int)($ctx['mandat_id'] ?? 0);
        $extracted = (array)($ctx['extracted_data'] ?? []);

        tmh_log($report, 'info', "type_doc=$type, conf=$conf, date=$dateDoc, bien=$bienId, mandat=$mandatId");

        if ($type === '') {
            tmh_log($report, 'skip', 'Aucun type_doc reconnu → hooks bypassés');
            $report['totals']['skipped']++;
            $report['finished_at'] = date('Y-m-d H:i:s');
            return $report;
        }

        if ($conf < $minConfidence) {
            tmh_log($report, 'skip', "Confiance IA $conf < seuil $minConfidence → hooks bypassés");
            $report['totals']['skipped']++;
            $report['finished_at'] = date('Y-m-d H:i:s');
            return $report;
        }

        // Compatibilité transaction parent : ne pas ouvrir si déjà en transaction.
        $ownsTx = false;
        if (!$dryRun && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTx = true;
            tmh_log($report, 'info', '🔓 BEGIN TRANSACTION (hook owns)');
        } elseif (!$dryRun) {
            tmh_log($report, 'info', '↪ apply via parent transaction (no own BEGIN)');
        }

        try {
            // ─── Dispatch par type ─────────────────────────────────
            $matched = false;

            // 1. MANDATS — mandat_signe, mandat_exclusif, mandat_simple, mandat_vente
            if (in_array($type, ['mandat_signe','mandat_exclusif','mandat_simple','mandat_vente'], true)) {
                $matched = true;
                if ($mandatId <= 0) {
                    tmh_log($report, 'skip', "type=$type mais mandat_id manquant → skip");
                    $report['totals']['skipped']++;
                } elseif (!$dateDoc) {
                    tmh_log($report, 'skip', "type=$type mais date_doc invalide → skip");
                    $report['totals']['skipped']++;
                } else {
                    tmh_try_update_mandat_date($pdo, $report, $mandatId, $dateDoc, $dryRun);
                }
            }

            // 2. ACTE AUTHENTIQUE → biens.date_retrait_commercialisation + statut + prix
            // + synchro Bailleur : si tous les biens de l'immeuble sont vendu, marquer immeuble vendu
            if ($type === 'acte_authentique') {
                $matched = true;
                if ($bienId <= 0 || !$dateDoc) {
                    tmh_log($report, 'skip', "acte_authentique : bien_id ou date_doc manquant → skip");
                    $report['totals']['skipped']++;
                } else {
                    tmh_try_update_bien_vente($pdo, $report, $bienId, $dateDoc,
                                              isset($extracted['montant']) ? (float)$extracted['montant'] : null,
                                              $dryRun);
                    // Synchro Bailleur : vérifier si immeuble doit passer vendu
                    tmh_try_sync_immeuble_vendu($pdo, $report, $bienId, $dryRun);
                }
            }

            // 3. DPE → biens.dpe_classe + dpe_valeur + dpe_date_realisation
            if ($type === 'dpe') {
                $matched = true;
                if ($bienId <= 0) {
                    tmh_log($report, 'skip', "dpe : bien_id manquant → skip");
                    $report['totals']['skipped']++;
                } else {
                    tmh_try_update_bien_dpe($pdo, $report, $bienId, $extracted, $dateDoc, $dryRun);
                }
            }

            // 4. ERP / ERNMT → biens.erp_date_realisation
            if ($type === 'erp_ernmt') {
                $matched = true;
                if ($bienId <= 0 || !$dateDoc) {
                    tmh_log($report, 'skip', "erp_ernmt : bien_id ou date_doc manquant → skip");
                    $report['totals']['skipped']++;
                } else {
                    tmh_try_update_bien_erp($pdo, $report, $bienId, $dateDoc, $dryRun);
                }
            }

            // 5. COMPROMIS → audit log uniquement (pas de colonne dédiée en BDD V0)
            if ($type === 'compromis') {
                $matched = true;
                tmh_log($report, 'info', "compromis : aucune colonne BDD dédiée — audit log uniquement");
                $report['actions'][] = [
                    'table' => '(none)', 'mode' => 'audit_only',
                    'detail' => "Compromis du $dateDoc enregistré (pas de date_compromis en BDD V0)",
                ];
            }

            if (!$matched) {
                tmh_log($report, 'info', "type=$type : aucun hook défini → no-op");
            }

            if (!$dryRun && $ownsTx) {
                $pdo->commit();
                tmh_log($report, 'info', '🔒 COMMIT (hook owns)');
            }
        } catch (Throwable $e) {
            if (!$dryRun && $ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
                tmh_log($report, 'error', '🔴 ROLLBACK (hook owns) : ' . $e->getMessage());
            } elseif (!$dryRun) {
                // Re-throw pour que la transaction parent rollback
                $report['totals']['errors']++;
                $report['errors'][] = $e->getMessage();
                tmh_log($report, 'error', '⚠ exception re-thrown to parent tx : ' . $e->getMessage());
                throw $e;
            }
            $report['totals']['errors']++;
            $report['errors'][] = $e->getMessage();
        }

        $report['finished_at'] = date('Y-m-d H:i:s');
        return $report;
    }
}

// ─── Sous-routines par cible ────────────────────────────────────────

if (!function_exists('tmh_try_update_mandat_date')) {
    function tmh_try_update_mandat_date(PDO $pdo, array &$report, int $mandatId, string $dateDoc, bool $dryRun): void {
        $st = $pdo->prepare("SELECT date_signature, date_debut FROM mandats WHERE id = ?");
        $st->execute([$mandatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            tmh_log($report, 'skip', "mandat #$mandatId introuvable");
            $report['totals']['skipped']++;
            return;
        }

        $updates = [];
        // date_signature : set si NULL ou si dateDoc est plus ancien (rare)
        if (!$row['date_signature']) {
            $updates['date_signature'] = $dateDoc;
        }
        // date_debut : set si NULL
        if (!$row['date_debut']) {
            $updates['date_debut'] = $dateDoc;
        }

        if (empty($updates)) {
            tmh_log($report, 'skip', "mandat #$mandatId : champs déjà renseignés");
            $report['totals']['skipped']++;
            return;
        }

        $report['actions'][] = ['table' => 'mandats', 'id' => $mandatId, 'mode' => 'update', 'fields' => $updates];
        $report['totals']['actions']++;

        if ($dryRun) {
            tmh_log($report, 'dry', "UPDATE mandats #$mandatId SET " . json_encode($updates));
        } else {
            $sets = []; $args = [];
            foreach ($updates as $f => $v) { $sets[] = "$f = ?"; $args[] = $v; }
            $args[] = $mandatId;
            $sql = "UPDATE mandats SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = ?";
            $pdo->prepare($sql)->execute($args);
            tmh_log($report, 'apply', "✅ UPDATE mandats #$mandatId — " . json_encode($updates));
        }
    }
}

if (!function_exists('tmh_try_update_bien_vente')) {
    function tmh_try_update_bien_vente(PDO $pdo, array &$report, int $bienId, string $dateDoc, ?float $montant, bool $dryRun): void {
        $st = $pdo->prepare("SELECT statut_bien, date_retrait_commercialisation, prix_final_vente FROM biens WHERE id = ?");
        $st->execute([$bienId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            tmh_log($report, 'skip', "bien #$bienId introuvable");
            $report['totals']['skipped']++;
            return;
        }

        $updates = [];
        if (!$row['date_retrait_commercialisation']) {
            $updates['date_retrait_commercialisation'] = $dateDoc;
        }
        if ($montant !== null && $montant > 0 && !$row['prix_final_vente']) {
            $updates['prix_final_vente'] = $montant;
        }
        if ($row['statut_bien'] !== 'vendu') {
            $updates['statut_bien'] = 'vendu';
        }

        if (empty($updates)) {
            tmh_log($report, 'skip', "bien #$bienId : déjà à jour");
            $report['totals']['skipped']++;
            return;
        }

        $report['actions'][] = ['table' => 'biens', 'id' => $bienId, 'mode' => 'update', 'fields' => $updates];
        $report['totals']['actions']++;

        if ($dryRun) {
            tmh_log($report, 'dry', "UPDATE biens #$bienId SET " . json_encode($updates));
        } else {
            $sets = []; $args = [];
            foreach ($updates as $f => $v) { $sets[] = "$f = ?"; $args[] = $v; }
            $args[] = $bienId;
            $sql = "UPDATE biens SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = ?";
            $pdo->prepare($sql)->execute($args);
            tmh_log($report, 'apply', "✅ UPDATE biens #$bienId — " . json_encode($updates));
        }
    }
}

if (!function_exists('tmh_try_update_bien_dpe')) {
    function tmh_try_update_bien_dpe(PDO $pdo, array &$report, int $bienId, array $extracted, ?string $dateDoc, bool $dryRun): void {
        $st = $pdo->prepare("SELECT dpe_classe, dpe_valeur, dpe_date_realisation FROM biens WHERE id = ?");
        $st->execute([$bienId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            tmh_log($report, 'skip', "bien #$bienId introuvable");
            $report['totals']['skipped']++;
            return;
        }

        $updates = [];
        $classe = strtoupper(trim((string)($extracted['dpe_classe'] ?? '')));
        $valeur = isset($extracted['dpe_valeur']) ? (int)$extracted['dpe_valeur'] : null;

        if ($classe !== '' && in_array($classe, ['A','B','C','D','E','F','G'], true) && !$row['dpe_classe']) {
            $updates['dpe_classe'] = $classe;
        }
        if ($valeur !== null && $valeur > 0 && !$row['dpe_valeur']) {
            $updates['dpe_valeur'] = $valeur;
        }
        if ($dateDoc && !$row['dpe_date_realisation']) {
            $updates['dpe_date_realisation'] = $dateDoc;
        }

        if (empty($updates)) {
            tmh_log($report, 'skip', "bien #$bienId : DPE déjà renseigné ou données extraction insuffisantes");
            $report['totals']['skipped']++;
            return;
        }

        $report['actions'][] = ['table' => 'biens', 'id' => $bienId, 'mode' => 'update', 'fields' => $updates];
        $report['totals']['actions']++;

        if ($dryRun) {
            tmh_log($report, 'dry', "UPDATE biens #$bienId (DPE) SET " . json_encode($updates));
        } else {
            $sets = []; $args = [];
            foreach ($updates as $f => $v) { $sets[] = "$f = ?"; $args[] = $v; }
            $args[] = $bienId;
            $sql = "UPDATE biens SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = ?";
            $pdo->prepare($sql)->execute($args);
            tmh_log($report, 'apply', "✅ UPDATE biens #$bienId (DPE) — " . json_encode($updates));
        }
    }
}

if (!function_exists('tmh_try_update_bien_erp')) {
    function tmh_try_update_bien_erp(PDO $pdo, array &$report, int $bienId, string $dateDoc, bool $dryRun): void {
        $st = $pdo->prepare("SELECT erp_date_realisation FROM biens WHERE id = ?");
        $st->execute([$bienId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            tmh_log($report, 'skip', "bien #$bienId introuvable");
            $report['totals']['skipped']++;
            return;
        }
        if ($row['erp_date_realisation']) {
            tmh_log($report, 'skip', "bien #$bienId : erp_date_realisation déjà renseignée");
            $report['totals']['skipped']++;
            return;
        }

        $updates = ['erp_date_realisation' => $dateDoc];
        $report['actions'][] = ['table' => 'biens', 'id' => $bienId, 'mode' => 'update', 'fields' => $updates];
        $report['totals']['actions']++;

        if ($dryRun) {
            tmh_log($report, 'dry', "UPDATE biens #$bienId (ERP) SET " . json_encode($updates));
        } else {
            $pdo->prepare("UPDATE biens SET erp_date_realisation = ?, date_modification = NOW() WHERE id = ?")
                ->execute([$dateDoc, $bienId]);
            tmh_log($report, 'apply', "✅ UPDATE biens #$bienId (ERP) — date_realisation=$dateDoc");
        }
    }
}

if (!function_exists('tmh_try_sync_immeuble_vendu')) {
    /**
     * Synchro Bailleur : quand un bien passe statut_bien='vendu',
     * vérifie si TOUS les biens de l'immeuble sont vendus.
     * Si oui, marque immeubles.vendu=1 avec la date du jour.
     */
    function tmh_try_sync_immeuble_vendu(PDO $pdo, array &$report, int $bienId, bool $dryRun): void {
        // 1. Récupérer l'immeuble lié au bien
        $st = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ?");
        $st->execute([$bienId]);
        $bien = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bien || !$bien['id_immeuble']) {
            tmh_log($report, 'info', "bien #$bienId : pas d'immeuble associé (bien isolé) → skip synchro");
            return;
        }

        $immId = (int)$bien['id_immeuble'];

        // 2. Vérifier si TOUS les biens de cet immeuble sont statut_bien='vendu'
        $st = $pdo->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN statut_bien = 'vendu' THEN 1 ELSE 0 END) as vendu
            FROM biens
            WHERE id_immeuble = ?
        ");
        $st->execute([$immId]);
        $stats = $st->fetch(PDO::FETCH_ASSOC);
        if (!$stats || $stats['total'] == 0) {
            tmh_log($report, 'info', "immeuble #$immId : aucun bien trouvé → skip synchro");
            return;
        }

        if ($stats['vendu'] != $stats['total']) {
            tmh_log($report, 'info', "immeuble #$immId : {$stats['vendu']}/{$stats['total']} biens vendus → skip synchro");
            return;
        }

        // 3. TOUS les biens sont vendus → marquer immeuble vendu
        $st = $pdo->prepare("SELECT vendu, date_vente FROM immeubles WHERE id = ?");
        $st->execute([$immId]);
        $imm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$imm) {
            tmh_log($report, 'warn', "immeuble #$immId introuvable → skip synchro");
            return;
        }

        if ($imm['vendu']) {
            tmh_log($report, 'info', "immeuble #$immId : déjà marqué vendu → skip synchro");
            return;
        }

        $dateToday = date('Y-m-d');
        $report['actions'][] = [
            'table' => 'immeubles',
            'id' => $immId,
            'mode' => 'update',
            'fields' => ['vendu' => 1, 'date_vente' => $dateToday],
            'reason' => 'all_biens_sold'
        ];
        $report['totals']['actions']++;

        if ($dryRun) {
            tmh_log($report, 'dry', "🔗 SYNCHRO Bailleur : UPDATE immeubles #$immId SET vendu=1, date_vente='$dateToday' (tous biens vendus)");
        } else {
            $pdo->prepare("UPDATE immeubles SET vendu=1, date_vente=?, date_modification=NOW() WHERE id=?")
                ->execute([$dateToday, $immId]);
            tmh_log($report, 'apply', "✅ 🔗 SYNCHRO Bailleur : immeuble #$immId marqué vendu (tous {$stats['total']} biens vendus)");
        }
    }
}
