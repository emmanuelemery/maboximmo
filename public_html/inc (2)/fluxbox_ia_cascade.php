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

        // ──── N4 — LLM Haiku 4.5 (PDF multimodal direct) ────────────────────
        $r4 = fluxbox_ia_n4_haiku_llm($doc, $pdo);
        if ($r4 !== null) {
            fluxbox_ia_log_usage([
                'ia_provider' => 'anthropic',
                'ia_model'    => 'claude-haiku-4-5',
                'purpose'     => 'classement',
                'cost_eur'    => (float)($r4['cost_eur'] ?? 0),
                'cache_hit'   => 0,
            ], $pdo);
            return $r4;
        }

        // ──── Fallback : heuristique partielle ou manuel ───────────────────
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
            'niveau_utilise' => 'MANUAL',
            'raison'         => 'Classement manuel requis — cliquez sur Ajuster pour choisir.',
            'cost_eur'       => 0,
        ];
    }
}

// ════════════════════════════════════════════════════════════════════════
// 4bis. NIVEAU N4 — LLM Haiku 4.5 (appel PDF multimodal direct)
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('fluxbox_ia_n4_haiku_llm')) {
    /**
     * Envoie le PDF directement à Claude Haiku 4.5 pour classification fine.
     * Renvoie null si :
     *  - pas de clé API
     *  - fichier non-PDF / introuvable / trop volumineux
     *  - erreur HTTP
     * Sinon renvoie un classement complet (n1→n5 + entity + matching BDD).
     */
    function fluxbox_ia_n4_haiku_llm(array $doc, PDO $pdo): ?array
    {
        global $ANTHROPIC_API_KEY;
        if (empty($ANTHROPIC_API_KEY)) return null;

        $path = (string)($doc['fichier_chemin'] ?? '');
        if ($path === '' || !is_file($path)) return null;
        $ext = strtolower(pathinfo((string)$doc['fichier_nom'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') return null; // V1 : PDF uniquement ; PNG/JPG/MSG à brancher ensuite
        $bytes = @file_get_contents($path);
        if ($bytes === false || strlen($bytes) === 0) return null;
        $b64 = base64_encode($bytes);
        if (strlen($b64) > 30_000_000) return null; // trop gros pour l'API

        // Glossaire condensé (entités placeholder uniquement, pour rester court)
        $glossStr = '';
        try {
            $st = $pdo->query("
                SELECT parent_n1, parent_n3, code
                FROM ged_level_codes
                WHERE level_number = 4 AND is_active = 1
                AND parent_n3 IN ('IMMEUBLE','COLLABORATEUR','BAILLEUR','LOCATAIRE','SOCIETE','BANQUE','FOURNISSEUR')
                ORDER BY parent_n1, parent_n3, code
            ");
            $gloss = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $key = $r['parent_n1'] . ' > ' . $r['parent_n3'];
                $gloss[$key][] = $r['code'];
            }
            foreach ($gloss as $k => $codes) {
                $glossStr .= "• $k : " . implode(', ', array_unique($codes)) . "\n";
            }
        } catch (Throwable) {}

        $systemPrompt = <<<TXT
Tu es un classificateur expert pour un cabinet immobilier (syndic + gestion + transaction + RH + comptabilité).
Tu reçois un document PDF. Tu identifies sa nature précise et tu retournes UNIQUEMENT un JSON sur une ligne, SANS markdown :

{"n1":"04_SYNDIC|03_GESTION_LOCATIVE|06_COMPTABILITE|02_RH|01_DIRECTION",
 "n2":"IMMEUBLES|BAUX|BANQUES|COLLABORATEURS|01_SOCIETES|FOURNISSEURS",
 "n3":"IMMEUBLE|BAILLEUR|LOCATAIRE|COLLABORATEUR|BANQUE|FOURNISSEUR|SOCIETE",
 "entity_instance":"nom propre détecté (ex: Les Chamois, 1 Rue Teste du Bailler, MINET-CAPRA)",
 "entity_ref":"référence interne si visible (ex: 3005, AD3-526) ou vide",
 "n4":"code N4 du glossaire (voir liste)",
 "n5":"code N5 si applicable (ex: PV_AGO, PV_AGE, CV_AGO, CV_AGE pour syndic AG, sinon vide)",
 "n6":"SIGNE | NON_SIGNE | vide (voir règles signature)",
 "titre_court":"titre métier précis ≤70 char (PAS le nom de fichier brut)",
 "date":"YYYY-MM-DD — date MÉTIER précise de l'événement (voir règles)",
 "annee":"2024|2025... (fallback si date jour/mois pas visible)",
 "adresse":"adresse complète si visible",
 "ville":"...","code_postal":"...","tiers_externe":"...",
 "confiance":85,
 "raison":"phrase courte"}

Règles strictes :
- PV Assemblée Générale Ordinaire → n4=AG, n5=PV_AGO
- PV Assemblée Générale Extraordinaire → n4=AG, n5=PV_AGE
- Convocation AGO → n4=AG, n5=CV_AGO ; Convocation AGE → n4=AG, n5=CV_AGE
- Contrat de syndic → n4=CONTRATS
- Acte/notification de mutation → n4=MUTATIONS
- Plan technique/architecte → n4=PHOTOS_TECHNIQUES
- Devis/proposition diagnostics → n4=DEVIS
- Facture fournisseur (immeuble) → n1=06_COMPTABILITE, n3=FOURNISSEUR, n4=FACTURES
- Bulletin paie → n1=02_RH, n3=COLLABORATEUR, n4=03_PAIE
- Bail commercial/habitation → n1=03_GESTION_LOCATIVE, n3=LOCATAIRE
- Si tu ne sais pas → n4=A_CLASSER, confiance < 50

RÈGLES VÉHICULES (CRITIQUE — TOUT DOC VÉHICULE VA ICI, PAS AILLEURS) :
- Si le doc mentionne un véhicule (Audi, BMW, Tesla, Peugeot, Renault, Citroen, Volvo, VW Polo, Porsche, Yamaha, etc.), une immatriculation (FR), un certificat d'immatriculation, une carte grise, une assurance auto, un contrat leasing/LOA/LLD voiture, un entretien automobile, un PV de cession véhicule, un mandat d'immatriculation, un bon de commande véhicule, une carte VW Bank / RCI / Crédit Auto, etc. → c'est UN DOC VÉHICULE
- Pour TOUT doc véhicule : n1=01_DIRECTION, n2=17_VEHICULES, n3=CODE_VEHICULE_DIRECT (depuis la liste ci-dessous, PAS le placeholder VEHICULE)
- n3 = code véhicule directement depuis cette LISTE (CHOISIS LE PLUS PROCHE — ce sont des codes N3 valides en BDD) :
  • AUDI_A3, AUDI_A4, AUDI_A6_2023, AUDI_Q5_2018, X3_BMW, MERCEDES_CLASS_C,
  • PEUGEOT_3008, 206_CHAPONOST, 207_BLANCHE_EMMELYNE, 207_GRISE_PERSO,
  • CITROEN_C3_CEP, PORSCHE_CAYENNE_SIR, VOLVO_V40_SERVAJEAN,
  • ESPACE_5_2015, RENAULT_ESPACE_2008, SCENIC_2008, LOCATION_A4_2018,
  • POLO_VW_RIOM_2023, POLO_VW_MIONS_2024, POLO_VW_VIENNE_2025,
  • TESLA_MODELE_3, TESLA_MODELE_Y_2025, PIAGGIO_MP3, TROTINETTE_2022, YAMAHA_MT07
- n4 selon le type de doc véhicule :
  • Certificat immatriculation / carte grise → n4=CARTE_GRISE
  • Attestation/contrat assurance auto → n4=ASSURANCE
  • Procès-verbal contrôle technique → n4=CONTROLE_TECHNIQUE
  • Facture entretien, devis entretien, vidange, freins, pneus → n4=ENTRETIEN
  • Facture achat véhicule, facture concessionnaire → n4=FACTURES
  • Contrat LOA, LLD, leasing, tableau amortissement, mandat SEPA véhicule, RIB véhicule, crédit auto → n4=FINANCEMENTS
  • Bon de commande, configuration véhicule, offre véhicule, proposition tarif → n4=BON_COMMANDE
  • Certificat cession, mandat immatriculation transfert → n4=VENTE_CESSION
  • Doc véhicule ancien/archivé → n4=ARCHIVES
- target_societe_id = 3 (LOCA IMMO Holding — TOUS les véhicules sont à LOCA IMMO)
- target_agence_id = 0 (société uniquement, pas d'agence)

RÈGLES SIGNATURE (champ n6) — UNIQUEMENT pour docs qui DOIVENT être signés :
- Types concernés : PV AG (PV_AGO/PV_AGE), Contrats (syndic, bail, travail…), Mandats, Actes, Devis acceptés, Bons de commande
- Si tu détectes des signatures visibles (paraphes, tampons signés, mention "signé le…") → n6="SIGNE"
- Si le doc nécessite signature mais zone signature VIDE/non remplie → n6="NON_SIGNE"
- Pour TOUS les autres types (factures, relevés, plans, courriers, notifications…) → n6="" (vide)
- Convocations AG (CV_AGO/CV_AGE) → n6="" (pas de signature requise pour convoc)

RÈGLES DATE (CRITIQUE — NE JAMAIS OMETTRE LA CLÉ "date" DANS LE JSON) :
- "date" est OBLIGATOIRE dans le JSON. Si tu hésites, MIEUX VAUT une date approximative que rien.
- Format STRICT : "YYYY-MM-DD" (10 caractères, ex: "2024-03-21")
- "21 mars 2024" → "2024-03-21" / "3 juillet 2025" → "2025-07-03"
- Mois français : janvier=01, février=02, mars=03, avril=04, mai=05, juin=06, juillet=07, août=08, septembre=09, octobre=10, novembre=11, décembre=12
- Quelle date prendre selon le type :
  • PV AG (toute) → date de TENUE de l'assemblée
  • Convocation AG → date de la PROCHAINE AG annoncée (pas l'envoi)
  • Contrat / bail → date de SIGNATURE
  • Acte mutation → date de l'acte notarié ("en date du XX")
  • Facture → date de facturation
  • Devis → date du devis
  • Bulletin paie → dernier jour du mois payé (ex paie de mars 2024 → "2024-03-31")
- Si VRAIMENT aucune date jour/mois visible → date="" + annee="2024"

EXEMPLE COMPLET pour un PV AG du 21 mars 2024 sur l'immeuble 3005 :
{"n1":"04_SYNDIC","n2":"IMMEUBLES","n3":"IMMEUBLE","entity_instance":"1 rue Teste du Bailler","entity_ref":"3005","n4":"AG","n5":"PV_AGO","titre_court":"PV AG Ordinaire 21/03/2024","date":"2024-03-21","annee":"2024","adresse":"1 rue Teste du Bailler","ville":"VIENNE","code_postal":"38200","tiers_externe":"REGIE EMERY","confiance":95,"raison":"PV AG ordinaire tenue le 21 mars 2024"}
TXT;

        $userContent = [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]],
            ['type' => 'text', 'text' => "GLOSSAIRE N4 par branche :\n$glossStr\n\nNom fichier : " . (string)$doc['fichier_nom'] . "\n\nClasse ce document. JSON uniquement."],
        ];

        $payload = [
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 800,
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $userContent]],
        ];

        // Reset chrono PHP avant chaque appel IA (cascade Haiku/Sonnet, 30-90s)
        @set_time_limit(180);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 90,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;
        $j = json_decode($resp, true);
        if (!is_array($j)) return null;
        $raw = $j['content'][0]['text'] ?? '';
        $usage = $j['usage'] ?? [];
        $costEur = (($usage['input_tokens'] ?? 0) * 0.001 + ($usage['output_tokens'] ?? 0) * 0.005) / 1000 * 0.92;

        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $p = json_decode($clean, true);
        if (!is_array($p)) return null;

        // Matching véhicule : si n2=17_VEHICULES, le n3 doit être un code véhicule valide en BDD
        $matchedVehicleCode = null;
        $isVehicleDoc = (($p['n2'] ?? '') === '17_VEHICULES');
        if ($isVehicleDoc) {
            $n3Test = trim((string)($p['n3'] ?? ''));
            if ($n3Test !== '' && $n3Test !== 'VEHICULE') {
                // Vérifie que le code existe en ged_level_codes N3 sous DIRECTION/VEHICULES
                $stV = $pdo->prepare("
                    SELECT code FROM ged_level_codes
                    WHERE level_number=3 AND code=? COLLATE utf8mb4_unicode_ci
                      AND parent_n1='01_DIRECTION' AND parent_n2='17_VEHICULES' AND is_active=1
                    LIMIT 1
                ");
                $stV->execute([$n3Test]);
                $matchedVehicleCode = (string)$stV->fetchColumn() ?: null;
            }
        }

        // Matching immeuble en BDD (priorité ref → adresse → tokens nom)
        $matchedImmId = null; $matchedSocId = null; $matchedAgeId = null; $matchedRef = null; $matchedNom = null;
        if (($p['n3'] ?? '') === 'IMMEUBLE') {
            $ref = trim((string)($p['entity_ref'] ?? ''));
            $entity = trim((string)($p['entity_instance'] ?? ''));
            $adresse = trim((string)($p['adresse'] ?? ''));
            $captureMatch = function($m) use (&$matchedImmId, &$matchedSocId, &$matchedAgeId, &$matchedRef, &$matchedNom) {
                $matchedImmId = (int)$m['id'];
                $matchedSocId = $m['id_societe'];
                $matchedAgeId = $m['id_agence'];
                $matchedRef = $m['reference_immeuble'];
                $matchedNom = $m['nom_immeuble'];
            };
            if ($ref !== '' && preg_match('/^\d+$/', $ref)) {
                $stM = $pdo->prepare("SELECT id, reference_immeuble, nom_immeuble, id_societe, id_agence FROM immeubles WHERE reference_immeuble = ? LIMIT 1");
                $stM->execute([$ref]);
                if ($m = $stM->fetch(PDO::FETCH_ASSOC)) $captureMatch($m);
            }
            if (!$matchedImmId && $adresse !== '') {
                $stM = $pdo->prepare("SELECT id, reference_immeuble, nom_immeuble, id_societe, id_agence FROM immeubles WHERE LOWER(CONCAT(adresse_1, ' ', COALESCE(adresse_2, ''))) LIKE ? LIMIT 1");
                $stM->execute(['%' . mb_strtolower($adresse) . '%']);
                if ($m = $stM->fetch(PDO::FETCH_ASSOC)) $captureMatch($m);
            }
            if (!$matchedImmId && $entity !== '') {
                $tokens = array_filter(preg_split('/[\s_\-,]+/', mb_strtolower($entity)), fn($t) => mb_strlen($t) >= 5);
                if ($tokens) {
                    $where = []; $params = [];
                    foreach ($tokens as $t) { $where[] = "LOWER(nom_immeuble) LIKE ?"; $params[] = '%' . $t . '%'; }
                    $stM = $pdo->prepare("SELECT id, reference_immeuble, nom_immeuble, id_societe, id_agence FROM immeubles WHERE " . implode(' AND ', $where) . " LIMIT 1");
                    $stM->execute($params);
                    if ($m = $stM->fetch(PDO::FETCH_ASSOC)) $captureMatch($m);
                }
            }
        }

        $classement = [
            'n1' => (string)($p['n1'] ?? ''),
            'n2' => (string)($p['n2'] ?? ''),
            'n3' => (string)($p['n3'] ?? ''),
            'n4' => (string)($p['n4'] ?? ''),
            'n5' => (string)($p['n5'] ?? ''),
            // n6 = SIGNE/NON_SIGNE pour docs qui doivent être signés, sinon vide (titre_court va dans carte.titre)
            'n6' => in_array(strtoupper((string)($p['n6'] ?? '')), ['SIGNE', 'NON_SIGNE'], true)
                    ? strtoupper((string)$p['n6'])
                    : '',
            'entity_instance' => (string)($p['entity_instance'] ?? ''),
            'entity_ref' => (string)($p['entity_ref'] ?? ''),
            'date' => (string)($p['date'] ?? '') ?: (!empty($p['annee']) ? $p['annee'] . '-01-01' : null),
            'annee' => (string)($p['annee'] ?? ''),
            'adresse' => (string)($p['adresse'] ?? ''),
            'ville' => (string)($p['ville'] ?? ''),
            'code_postal' => (string)($p['code_postal'] ?? ''),
            'tiers_externe' => (string)($p['tiers_externe'] ?? ''),
            'target_societe_id' => $isVehicleDoc ? 3 : $matchedSocId,  // véhicule → LOCA IMMO (3)
            'target_agence_id'  => $isVehicleDoc ? 0 : $matchedAgeId,  // véhicule → société uniquement
            'immeuble_id_bdd' => $matchedImmId,
            'immeuble_ref_bdd' => $matchedRef,
            'immeuble_nom_bdd' => $matchedNom,
            'vehicule_code' => $matchedVehicleCode, // code véhicule glossaire si matché
        ];

        return [
            'classement' => $classement,
            'actions' => [[
                'type' => 'classement_ged',
                'label' => trim($classement['n1'] . ' > ' . $classement['n2'] . ' > ' . $classement['n4']),
                'payload' => ['source' => 'haiku-4-5'],
                'confiance' => (float)($p['confiance'] ?? 0),
            ]],
            'confiance' => (float)($p['confiance'] ?? 0),
            'niveau_utilise' => 'N4_HAIKU',
            'raison' => (string)($p['raison'] ?? ''),
            'cost_eur' => $costEur,
            'priorite' => 'normal',
            'titre_court' => (string)($p['titre_court'] ?? ''),
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
            // [keywords[], n1, n2, n3, n4, label, priorite, confiance]
            [['facture', 'invoice'],
                '06_COMPTABILITE',  'FOURNISSEURS', 'FACTURES_A_PAYER',  '', 'Facture',          'important', 75],
            [['relevé bancaire', 'releve bancaire', 'extrait de compte'],
                '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE',          '', 'Relevé bancaire', 'normal', 80],
            // ⬇ Bulletin de paie / fiche de paie / DPAE → COLLABORATEURS > COLLABORATEUR (placeholder) > 03_PAIE
            [['bulletin de paie', 'bulletin de salaire', 'bulletin salaire', 'fiche de paie',
              'fiche paie', 'fiche de salaire', 'salaire ', 'paie ', 'dpae', 'bulletin'],
                '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '03_PAIE', 'Bulletin de paie', 'normal', 78],
            // Contrat de travail
            [['contrat de travail', 'contrat travail', 'cdi ', 'cdd '],
                '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '02_CONTRAT_TRAVAIL', 'Contrat de travail', 'normal', 76],
            [['bail', 'contrat de location', 'bail commercial'],
                '03_GESTION_LOCATIVE', 'BAUX', 'BAUX_HABITATION',         '', 'Bail',  'important', 76],
            [['kbis', 'k-bis', 'extrait kbis'],
                '01_DIRECTION', '01_SOCIETES', 'SOCIETE',                 '', 'KBIS',           'normal', 82],
            [['rib', 'iban'],
                '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE',          '', 'RIB',     'normal', 80],
            [['pv ag', 'pv assemblée', 'procès-verbal', 'proces verbal'],
                '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE',                     '', 'PV',  'important', 74],
            [['ascenseur', 'otis', 'schindler', 'koné'],
                '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE',                     '', 'Travaux ascenseur', 'normal', 70],
        ];

        foreach ($patterns as $p) {
            [$kws, $n1, $n2, $n3, $n4, $label, $priorite, $confiance] = $p;
            foreach ($kws as $kw) {
                if (str_contains($text, $kw)) {
                    return [
                        'classement' => [
                            'n1' => $n1, 'n2' => $n2, 'n3' => $n3,
                            'n4' => $n4, 'n5' => '', 'n6' => $label,
                        ],
                        'actions' => [
                            [
                                'type'  => 'classement_ged',
                                'label' => trim("Classer en {$n2} > {$n3}" . ($n4 ? " > {$n4}" : '')),
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
