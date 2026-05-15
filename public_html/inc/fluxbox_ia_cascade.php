<?php
declare(strict_types=1);

/**
 * FluxBox — Cascade IA à coût maîtrisé
 *
 * Spec validée EMERY 2026-05-13 (project_fluxbox_module §3.4) :
 *
 *  N1. Règles déterministes (toujours en premier)         → coût 0 €
 *  N2. Cache (avant tout LLM)                             → coût 0 €
 *  N3. Modèle local (tâches simples)                      → coût ~0 €
 *  N4. LLM cloud léger (Haiku)                            → coût ~0,001 €
 *  N5. LLM premium (Sonnet) [demande explicite user]      → coût ~0,01 €
 *
 * Plafond IA : 75 €/société/mois par défaut (max 100 €).
 * Alertes admin agence : 50%, 80%, 95%, 100% → mode dégradé.
 *
 * Tables utilisées :
 *  - fluxbox_ia_usage         : tracking par appel
 *  - fluxbox_societe_plafonds : plafonds par société + état alertes
 *  - fluxbox_cache_*          : caches permanents
 */

require_once __DIR__ . '/ged_functions.php';
require_once __DIR__ . '/fluxbox_functions.php';

if (!defined('FLUXBOX_PLAFOND_DEFAULT_EUR')) {
    define('FLUXBOX_PLAFOND_DEFAULT_EUR', 75.00);
}
if (!defined('FLUXBOX_PLAFOND_MAX_EUR')) {
    define('FLUXBOX_PLAFOND_MAX_EUR', 100.00);
}

// ════════════════════════════════════════════════════════════════════════
// 1. PLAFONNEMENT IA — récupération état société
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_get_plafond_state')) {
    /**
     * Récupère l'état mensuel du plafond IA pour une société.
     *
     * @return array{
     *   plafond_eur:float,
     *   consomme_eur:float,
     *   pct:float,
     *   mode_degrade:bool,
     *   alert_thresholds_pending:array<int>
     * }
     */
    function fluxbox_ia_get_plafond_state(int $societeId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $mois = date('Y-m');

        // Plafond configuré (ou défaut)
        $plafond = FLUXBOX_PLAFOND_DEFAULT_EUR;
        $alertSent = ['50' => null, '80' => null, '95' => null, '100' => null];
        try {
            $st = $pdo->prepare("
                SELECT `plafond_eur`, `alert_50_sent`, `alert_80_sent`, `alert_95_sent`, `alert_100_sent`
                FROM `fluxbox_societe_plafonds`
                WHERE `tenant_id` = ? AND `societe_id` = ? AND `mois_courant` = ?
                LIMIT 1
            ");
            $st->execute([$tenantId, $societeId, $mois]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $plafond = (float)$row['plafond_eur'];
                $alertSent = [
                    '50'  => $row['alert_50_sent'],
                    '80'  => $row['alert_80_sent'],
                    '95'  => $row['alert_95_sent'],
                    '100' => $row['alert_100_sent'],
                ];
            }
        } catch (Throwable) {}

        // Consommation cumulée du mois
        $consomme = 0.0;
        try {
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(`cost_eur`), 0) AS total
                FROM `fluxbox_ia_usage`
                WHERE `tenant_id` = ?
                  AND `societe_id` = ?
                  AND `created_at` >= ?
                  AND `cache_hit` = 0
            ");
            $st->execute([$tenantId, $societeId, $mois . '-01 00:00:00']);
            $consomme = (float)$st->fetchColumn();
        } catch (Throwable) {}

        $pct = $plafond > 0 ? ($consomme / $plafond) * 100 : 0;
        $modeDegrade = $pct >= 100;

        // Détermine quels seuils d'alerte sont à déclencher
        $pending = [];
        foreach ([50, 80, 95, 100] as $threshold) {
            if ($pct >= $threshold && $alertSent[(string)$threshold] === null) {
                $pending[] = $threshold;
            }
        }

        return [
            'plafond_eur'              => $plafond,
            'consomme_eur'             => round($consomme, 4),
            'pct'                      => round($pct, 1),
            'mode_degrade'             => $modeDegrade,
            'alert_thresholds_pending' => $pending,
        ];
    }
}

if (!function_exists('fluxbox_ia_log_usage')) {
    /**
     * Logge un appel IA dans fluxbox_ia_usage. Met à jour les alertes si nécessaire.
     */
    function fluxbox_ia_log_usage(array $log, ?PDO $pdo = null): int
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();
        $societeId = (int)(current_societe_id() ?? 0);
        $mois = date('Y-m');

        $st = $pdo->prepare("
            INSERT INTO `fluxbox_ia_usage`
              (`tenant_id`, `societe_id`, `user_id`, `carte_id`, `ia_provider`, `ia_model`,
               `purpose`, `tokens_in`, `tokens_out`, `cost_eur`, `duration_ms`, `cache_hit`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $tenantId, $societeId ?: null, $userId ?: null,
            $log['carte_id'] ?? null,
            (string)($log['ia_provider'] ?? 'anthropic'),
            (string)($log['ia_model'] ?? 'unknown'),
            (string)($log['purpose'] ?? 'autre'),
            (int)($log['tokens_in'] ?? 0),
            (int)($log['tokens_out'] ?? 0),
            (float)($log['cost_eur'] ?? 0),
            isset($log['duration_ms']) ? (int)$log['duration_ms'] : null,
            !empty($log['cache_hit']) ? 1 : 0,
        ]);
        $logId = (int)$pdo->lastInsertId();

        // Maj plafond mois courant (création si absent)
        if ($societeId > 0 && empty($log['cache_hit'])) {
            $pdo->prepare("
                INSERT IGNORE INTO `fluxbox_societe_plafonds`
                  (`tenant_id`, `societe_id`, `plafond_eur`, `mois_courant`)
                VALUES (?, ?, ?, ?)
            ")->execute([$tenantId, $societeId, FLUXBOX_PLAFOND_DEFAULT_EUR, $mois]);

            // Vérifie seuils d'alerte
            $state = fluxbox_ia_get_plafond_state($societeId, $pdo);
            foreach ($state['alert_thresholds_pending'] as $threshold) {
                $col = "alert_{$threshold}_sent";
                $pdo->prepare("
                    UPDATE `fluxbox_societe_plafonds`
                    SET `{$col}` = NOW()
                    WHERE `tenant_id` = ? AND `societe_id` = ? AND `mois_courant` = ?
                ")->execute([$tenantId, $societeId, $mois]);
                // NB : envoi de mail d'alerte = à brancher V1.1 (PHPMailer existant)
            }
        }

        return $logId;
    }
}

// ════════════════════════════════════════════════════════════════════════
// 2. CASCADE — niveaux N1 à N5
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_run_cascade')) {
    /**
     * Exécute la cascade IA pour proposer un classement + actions sur un fluxbox_document.
     * Renvoie une proposition prête à être stockée dans fluxbox_cartes.proposition_json.
     *
     * @param int $fluxboxDocId
     * @return array{
     *   classement:array,
     *   actions:array<array>,
     *   confiance:float,
     *   niveau_utilise:string,  // N1|N2|N3|N4|N5
     *   raison:string,
     *   cost_eur:float
     * }
     */
    function fluxbox_ia_run_cascade(int $fluxboxDocId, ?PDO $pdo = null): array
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();

        $st = $pdo->prepare("SELECT * FROM `fluxbox_documents` WHERE `id` = ? AND `tenant_id` = ?");
        $st->execute([$fluxboxDocId, $tenantId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException("Document FluxBox $fluxboxDocId introuvable");
        }

        // ──── N1 — Règles déterministes ─────────────────────────────────────
        $r1 = fluxbox_ia_n1_rules($doc, $pdo);
        if ($r1 !== null) {
            fluxbox_ia_log_usage([
                'ia_provider' => 'local', 'ia_model' => 'rules', 'purpose' => 'classement',
                'cost_eur' => 0, 'cache_hit' => 1,
            ], $pdo);
            return $r1;
        }

        // ──── N2 — Cache (fournisseur connu / pattern connu) ───────────────
        $r2 = fluxbox_ia_n2_cache($doc, $pdo);
        if ($r2 !== null) {
            fluxbox_ia_log_usage([
                'ia_provider' => 'local', 'ia_model' => 'cache', 'purpose' => 'classement',
                'cost_eur' => 0, 'cache_hit' => 1,
            ], $pdo);
            return $r2;
        }

        // ──── Vérif plafond avant tout appel cloud ─────────────────────────
        $societeId = (int)(current_societe_id() ?? 0);
        $state = $societeId > 0 ? fluxbox_ia_get_plafond_state($societeId, $pdo) : ['mode_degrade' => false];

        if (!empty($state['mode_degrade'])) {
            // Mode dégradé : pas d'appel cloud, on renvoie un classement vide à compléter manuellement
            return [
                'classement'     => ['n1' => '', 'n2' => '', 'n3' => '', 'n4' => '', 'n5' => '', 'n6' => ''],
                'actions'        => [],
                'confiance'      => 0,
                'niveau_utilise' => 'DEGRADE',
                'raison'         => 'Plafond IA mensuel atteint — classement manuel requis.',
                'cost_eur'       => 0,
            ];
        }

        // ──── N3 — Modèle local (heuristique légère sur OCR) ───────────────
        $r3 = fluxbox_ia_n3_local($doc, $pdo);
        if ($r3 !== null && ($r3['confiance'] ?? 0) >= 80) {
            fluxbox_ia_log_usage([
                'ia_provider' => 'local', 'ia_model' => 'heuristic', 'purpose' => 'classement',
                'cost_eur' => 0, 'cache_hit' => 0,
            ], $pdo);
            return $r3;
        }

        // ──── N4 — LLM cloud léger (Haiku) ─────────────────────────────────
        // V1 : on ne fait pas d'appel Anthropic réel ici (nécessite clé API + intégration HTTP).
        // On renvoie le résultat N3 s'il existe (même si confiance < 80%), sinon proposition vide.
        // À brancher V1.1 : appel ged_vision.php / ged_extraction.php existant.
        if ($r3 !== null) {
            fluxbox_ia_log_usage([
                'ia_provider' => 'local', 'ia_model' => 'heuristic-low-confidence', 'purpose' => 'classement',
                'cost_eur' => 0, 'cache_hit' => 0,
            ], $pdo);
            return $r3;
        }

        return [
            'classement'     => ['n1' => '', 'n2' => '', 'n3' => '', 'n4' => '', 'n5' => '', 'n6' => ''],
            'actions'        => [],
            'confiance'      => 0,
            'niveau_utilise' => 'N4_TODO',
            'raison'         => 'Cascade N1-N3 a échoué. LLM cloud à brancher V1.1.',
            'cost_eur'       => 0,
        ];
    }
}

// ════════════════════════════════════════════════════════════════════════
// 3. NIVEAU N1 — Règles déterministes
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_n1_rules')) {
    /**
     * Applique les règles apprises (fluxbox_regles_apprises) sur le document.
     * Renvoie null si aucune règle ne matche.
     */
    function fluxbox_ia_n1_rules(array $doc, PDO $pdo): ?array
    {
        $tenantId = (int)$doc['tenant_id'];
        $st = $pdo->prepare("
            SELECT * FROM `fluxbox_regles_apprises`
            WHERE `tenant_id` = ? AND `is_active` = 1
            ORDER BY `usage_count` DESC, `updated_at` DESC
        ");
        $st->execute([$tenantId]);
        $regles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($regles as $regle) {
            $match  = json_decode((string)$regle['match_json'], true) ?: [];
            $action = json_decode((string)$regle['action_json'], true) ?: [];

            if (fluxbox_ia_match_rule($doc, $match)) {
                // Increment usage
                $pdo->prepare("UPDATE `fluxbox_regles_apprises` SET `usage_count` = `usage_count` + 1 WHERE `id` = ?")
                    ->execute([(int)$regle['id']]);

                return [
                    'classement'     => $action['classement'] ?? [],
                    'actions'        => $action['actions'] ?? [],
                    'confiance'      => 100, // règle déterministe = certitude
                    'niveau_utilise' => 'N1',
                    'raison'         => "Règle apprise : " . (string)$regle['regle_label'],
                    'cost_eur'       => 0,
                ];
            }
        }

        return null;
    }
}

if (!function_exists('fluxbox_ia_match_rule')) {
    /**
     * Évalue si un document matche une règle (match_json).
     * Formats supportés :
     *   - filename_contains, filename_regex
     *   - ocr_contains, ocr_regex
     *   - mime, source_type
     */
    function fluxbox_ia_match_rule(array $doc, array $match): bool
    {
        $filename = mb_strtolower((string)($doc['fichier_nom'] ?? ''));
        $ocr      = mb_strtolower((string)($doc['ocr_text'] ?? ''));

        if (!empty($match['filename_contains'])) {
            $needle = mb_strtolower((string)$match['filename_contains']);
            if (str_contains($filename, $needle) === false) return false;
        }
        if (!empty($match['filename_regex'])) {
            if (!@preg_match((string)$match['filename_regex'], $filename)) return false;
        }
        if (!empty($match['ocr_contains'])) {
            $needle = mb_strtolower((string)$match['ocr_contains']);
            if (str_contains($ocr, $needle) === false) return false;
        }
        if (!empty($match['ocr_regex'])) {
            if (!@preg_match((string)$match['ocr_regex'], $ocr)) return false;
        }
        if (!empty($match['mime'])) {
            if ((string)$doc['mime_type'] !== (string)$match['mime']) return false;
        }
        if (!empty($match['source_type'])) {
            if ((string)$doc['source_type'] !== (string)$match['source_type']) return false;
        }
        return true;
    }
}

// ════════════════════════════════════════════════════════════════════════
// 4. NIVEAU N2 — Cache (fournisseur connu / pattern email)
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_n2_cache')) {
    /**
     * Cherche dans fluxbox_cache_fournisseurs un fournisseur connu mentionné dans le nom/OCR.
     */
    function fluxbox_ia_n2_cache(array $doc, PDO $pdo): ?array
    {
        $tenantId = (int)$doc['tenant_id'];
        $haystack = mb_strtolower(((string)$doc['fichier_nom']) . ' ' . ((string)($doc['ocr_text'] ?? '')));

        $st = $pdo->prepare("
            SELECT * FROM `fluxbox_cache_fournisseurs`
            WHERE `tenant_id` = ?
            ORDER BY `usage_count` DESC, `last_used_at` DESC
            LIMIT 200
        ");
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $key   = mb_strtolower((string)$row['fournisseur_key']);
            $label = mb_strtolower((string)$row['fournisseur_label']);
            if (($key !== '' && str_contains($haystack, $key)) ||
                ($label !== '' && str_contains($haystack, $label))) {

                // Increment usage
                $pdo->prepare("
                    UPDATE `fluxbox_cache_fournisseurs`
                    SET `usage_count` = `usage_count` + 1, `last_used_at` = NOW()
                    WHERE `id` = ?
                ")->execute([(int)$row['id']]);

                return [
                    'classement' => [
                        'n1' => (string)($row['n1_proposed'] ?? ''),
                        'n2' => (string)($row['n2_proposed'] ?? ''),
                        'n3' => (string)($row['n3_proposed'] ?? ''),
                        'n4' => '',
                        'n5' => '',
                        'n6' => (string)$row['fournisseur_label'],
                    ],
                    'actions' => [
                        [
                            'type'  => 'classement_ged',
                            'label' => "Classer (fournisseur connu : {$row['fournisseur_label']})",
                            'payload' => ['from_cache' => true],
                            'confiance' => 95,
                        ],
                    ],
                    'confiance'      => 95,
                    'niveau_utilise' => 'N2',
                    'raison'         => "Fournisseur connu : {$row['fournisseur_label']}",
                    'cost_eur'       => 0,
                ];
            }
        }

        return null;
    }
}

// ════════════════════════════════════════════════════════════════════════
// 5. NIVEAU N3 — Modèle local (heuristique sur OCR / nom)
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_n3_local')) {
    /**
     * Heuristique simple basée sur mots-clés dans le nom de fichier et l'OCR.
     * Renvoie null si rien de détectable.
     */
    function fluxbox_ia_n3_local(array $doc, PDO $pdo): ?array
    {
        $text = mb_strtolower(((string)$doc['fichier_nom']) . ' ' . ((string)($doc['ocr_text'] ?? '')));
        if ($text === '') return null;

        $patterns = [
            // [keywords[], n1, n2, n3, label, priorite, confiance]
            [['facture', 'invoice'],           '06_COMPTABILITE',  'FOURNISSEURS', 'FACTURES_A_PAYER',          'Facture',          'important', 75],
            [['relevé bancaire', 'releve bancaire', 'extrait de compte'], '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', 'Relevé bancaire', 'normal', 80],
            [['bulletin', 'bulletin de paie', 'fiche de paie'],  '02_RH', 'SALAIRES', 'ANNEE', 'Bulletin de paie', 'normal',    78],
            [['bail', 'contrat de location', 'bail commercial'], '03_GESTION_LOCATIVE', 'BAUX', 'BAUX_HABITATION', 'Bail',  'important', 76],
            [['kbis', 'k-bis', 'extrait kbis'],                  '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'KBIS',           'normal', 82],
            [['rib', 'iban'],                                    '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', 'RIB',     'normal', 80],
            [['pv ag', 'pv assemblée', 'procès-verbal', 'proces verbal'], '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'PV',  'important', 74],
            [['ascenseur', 'otis', 'schindler', 'koné'],         '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'Travaux ascenseur', 'normal', 70],
        ];

        foreach ($patterns as $p) {
            [$kws, $n1, $n2, $n3, $label, $priorite, $confiance] = $p;
            foreach ($kws as $kw) {
                if (str_contains($text, $kw)) {
                    return [
                        'classement' => [
                            'n1' => $n1, 'n2' => $n2, 'n3' => $n3,
                            'n4' => '', 'n5' => '', 'n6' => $label,
                        ],
                        'actions' => [
                            [
                                'type'  => 'classement_ged',
                                'label' => "Classer en {$n2} > {$n3}",
                                'payload' => ['from_heuristic' => true, 'matched' => $kw],
                                'confiance' => $confiance,
                            ],
                        ],
                        'confiance'      => (float)$confiance,
                        'niveau_utilise' => 'N3',
                        'raison'         => "Mot-clé détecté : « {$kw} »",
                        'cost_eur'       => 0,
                        'priorite'       => $priorite,
                    ];
                }
            }
        }

        return null;
    }
}

// ════════════════════════════════════════════════════════════════════════
// 6. APPRENTISSAGE — quand user corrige une suggestion
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_learn_correction')) {
    /**
     * Quand le user corrige une suggestion IA, on peut mémoriser la règle.
     * Appelée depuis l'API quand user clique "Toujours" dans la modal.
     */
    function fluxbox_ia_learn_correction(string $label, array $match, array $action, ?PDO $pdo = null): int
    {
        if ($pdo === null) $pdo = fluxbox_pdo();
        $tenantId = fluxbox_current_tenant_id();
        $userId   = current_user_id();

        $st = $pdo->prepare("
            INSERT INTO `fluxbox_regles_apprises`
              (`tenant_id`, `regle_type`, `regle_label`, `match_json`, `action_json`, `created_by`)
            VALUES (?, 'fournisseur_categorie', ?, ?, ?, ?)
        ");
        $st->execute([
            $tenantId, $label,
            json_encode($match, JSON_UNESCAPED_UNICODE),
            json_encode($action, JSON_UNESCAPED_UNICODE),
            $userId ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}
