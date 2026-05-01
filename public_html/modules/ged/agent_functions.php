<?php
declare(strict_types=1);

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Agent GED MaBoxImmo — Fonctions métier
 * Fichier : modules/ged/agent_functions.php
 *
 * Contient :
 *   - buildGedPrompt()        construit le prompt IA (taxonomie + règles métier)
 *   - analyzeGedDocument()    appelle OpenAI sur le texte OCR + persiste
 *   - runPremiumOcr()         placeholder OCR premium (Mindee / Vision / Mistral)
 *   - validateGedAnalysis()   marque une analyse comme validée par un user
 *   - listAgentQueue()        retourne la liste des docs à traiter
 *   - statsByModule()         agrégats pour les cards stats
 *
 * Toutes les fonctions :
 *   - utilisent prepared statements
 *   - retournent des structures simples (array)
 *   - jettent des exceptions sur erreur (à attraper côté caller)
 *   - ne lisent JAMAIS de secret en dur (passent par u630423897/openai_config.php)
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!function_exists('agent_get_pdo')) {
    /**
     * Récupère la connexion PDO globale (déjà initialisée par bootstrap.php).
     * Lève une exception si le bootstrap n'a pas été fait au préalable.
     */
    function agent_get_pdo(): PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO non initialisé — appelle bootstrap.php d\'abord.');
        }
        return $pdo;
    }
}

if (!function_exists('agent_get_openai_key')) {
    /**
     * Récupère la clé OpenAI depuis le fichier de config sécurisé hors webroot.
     * Path : c:/xampp/htdocs/MaBoxImmo2026/u630423897/maboximmo_openai_config.php
     * (ou dev_maboximmo_openai_config.php selon environnement)
     */
    function agent_get_openai_key(): string
    {
        // Le fichier de config définit OPENAI_API_KEY via define()
        $configFiles = [
            dirname(__DIR__, 3) . '/u630423897/maboximmo_openai_config.php',
            dirname(__DIR__, 3) . '/u630423897/dev_maboximmo_openai_config.php',
        ];
        foreach ($configFiles as $f) {
            if (is_file($f) && !defined('OPENAI_API_KEY')) {
                require_once $f;
            }
        }
        if (!defined('OPENAI_API_KEY')) {
            throw new RuntimeException('OPENAI_API_KEY non configurée — vérifie u630423897/maboximmo_openai_config.php');
        }
        return (string)OPENAI_API_KEY;
    }
}

/**
 * Construit le prompt IA complet à envoyer à OpenAI/Claude/Mistral.
 *
 * Le prompt embarque :
 *   - L'arborescence métier (7 modules)
 *   - 25 règles de classement
 *   - La liste des champs JSON attendus en sortie
 *
 * @param string      $ocrText       Texte OCR brut (max 6000 chars utilisés)
 * @param string|null $forcedService Si l'utilisateur force un service métier
 * @return array{system:string, user:string}
 */
function buildGedPrompt(string $ocrText, ?string $forcedService = null): array
{
    // On limite à 6000 caractères pour ne pas exploser le coût en tokens.
    // L'extraction se fait sur l'en-tête + 1ère page, suffit largement.
    $excerpt = mb_substr($ocrText, 0, 6000);

    $system = "Tu es un assistant d'extraction de documents immobiliers français pour cabinet de gestion (syndic + transaction + gérance). "
            . "Tu retournes TOUJOURS un JSON strict avec les clés demandées, SANS texte autour. "
            . "Si un champ est absent, mets null. Les dates en ISO 'YYYY-MM-DD'. "
            . "Tu ne dois JAMAIS deviner si tu n'es pas sûr — préfère null à une valeur fantaisiste.";

    $serviceHint = $forcedService !== null
        ? "ORIENTATION FORCÉE : ce document concerne le service métier **{$forcedService}**. "
          . "Tu DOIS classer dans ce service.\n\n"
        : '';

    $taxonomy = <<<TAXO
ARBORESCENCE MÉTIER (module > niveau_2 > niveau_3) :

RH (ressources humaines)
- Salariés : Dossier salarié / Pièces identité / Diplômes
- Paie : Bulletins / Charges (URSSAF, DSN, DPAE)
- Contrats : CDI / CDD / Avenants

COMPTA (comptabilité)
- Factures : Entrantes / Sortantes
- Banque : Relevés / Virements / RIB
- Fiscalité : TVA / Déclarations / IS

BAILLEUR (gestion locative)
- Locataires : Dossiers / Cautions
- Baux : Contrats / États des lieux / Avenants
- Loyers : Quittances / Avis échéance / Impayés

SYNDIC (copropriété)
- Copropriété : Règlement / Modificatifs
- Assemblées : Convocations / PV / Pouvoirs
- Travaux : Devis / Factures / Décisions
- Contentieux : Courriers / Procédures
- Comptabilité : Appels de fonds / CRG annuels

AGENCE (transaction)
- Mandats : Signés / En cours / Expirés
- Ventes : Compromis / Avis valeur / Actes
- Locations : Candidatures / Baux / Garanties

FOURNISSEURS
- Contrats / Conventions
- Factures / Devis
- Interventions / Rapports

ADMIN
- Assurances : Multirisque / RCP / Garanties
- Contentieux : Procédures / Jugements
- Administratif : KBIS / Statuts / RCS
TAXO;

    $rules = <<<RULES
RÈGLES MÉTIER :
1. Relevé bancaire (IBAN + opérations + solde) → COMPTA / Banque / Relevés (sauf si compte syndicat → SYNDIC / Comptabilité / Banque)
2. Facture + immeuble + travaux → SYNDIC / Travaux / Factures
3. Facture sans immeuble + cabinet émetteur → COMPTA / Factures / Sortantes
4. Facture avec cabinet destinataire → COMPTA / Factures / Entrantes
5. Bulletin de paie → RH / Paie / Bulletins
6. DPAE / déclaration embauche → RH / Paie / Charges
7. Contrat de travail (CDI/CDD) → RH / Contrats
8. Carte d'identité / passeport / titre séjour → RH / Salariés / Pièces identité
9. Permis de conduire → RH / Salariés / Pièces identité
10. Bail / contrat location → BAILLEUR / Baux / Contrats
11. État des lieux → BAILLEUR / Baux / États des lieux
12. Quittance loyer → BAILLEUR / Loyers / Quittances
13. Avis d'échéance loyer → BAILLEUR / Loyers / Avis échéance
14. PV d'assemblée générale → SYNDIC / Assemblées / PV
15. Convocation AG → SYNDIC / Assemblées / Convocations
16. Devis travaux copro → SYNDIC / Travaux / Devis
17. Devis intervention fournisseur (hors copro) → FOURNISSEURS / Devis
18. Mandat (gestion / vente / syndic) → AGENCE / Mandats
19. Compromis / promesse vente → AGENCE / Ventes / Compromis
20. Avis de valeur / estimation → AGENCE / Ventes / Avis valeur
21. Acte authentique / acte notarié → AGENCE / Ventes / Actes
22. DPE / diagnostic → BAILLEUR ou AGENCE / Diagnostics (selon contexte)
23. Police d'assurance → ADMIN / Assurances
24. KBIS / statuts société → ADMIN / Administratif
25. Si AUCUNE règle ne matche : module='ADMIN', niveau_2='Administratif', niveau_3='Divers'
RULES;

    $user = $serviceHint . $taxonomy . "\n\n" . $rules . "\n\n"
          . "EXTRAIS EN JSON les champs suivants :\n"
          . "- module : RH|COMPTA|BAILLEUR|SYNDIC|AGENCE|FOURNISSEURS|ADMIN (MAJUSCULES)\n"
          . "- niveau_2 : sous-catégorie selon arborescence\n"
          . "- niveau_3 : sous-sous-catégorie (null si pas applicable)\n"
          . "- suggested_filename : MODULE_IMMEUBLE_NIVEAU2_DESCR_DATE_MONTANT.pdf (max 100 chars)\n"
          . "- immeuble : nom/adresse de l'immeuble/copropriété (null si absent)\n"
          . "- fournisseur : nom du fournisseur émetteur (null si pas applicable)\n"
          . "- locataire : nom du locataire (null si pas applicable)\n"
          . "- salarie : nom du salarié (null si pas applicable)\n"
          . "- proprietaire : nom du propriétaire/SCI/SDC (null si absent)\n"
          . "- montant : montant principal en nombre (TTC pour facture, net pour bulletin) (null si absent)\n"
          . "- date_document : date principale du doc en ISO YYYY-MM-DD (null si absent)\n"
          . "- action : action à proposer en français court\n"
          . "- confiance : 0-100, confiance globale dans le classement\n\n"
          . "DOCUMENT (texte OCR) :\n---\n" . $excerpt . "\n---\n\nRÉPONSE JSON :";

    return ['system' => $system, 'user' => $user];
}

/**
 * Appelle OpenAI Chat Completions en JSON mode.
 * Lève une RuntimeException si l'API échoue ou retourne du non-JSON.
 *
 * @param string $systemPrompt Instructions système
 * @param string $userPrompt   Contenu user (doit contenir le mot "JSON")
 * @return array<string,mixed> JSON parsé
 */
function callOpenAiJson(string $systemPrompt, string $userPrompt): array
{
    $apiKey = agent_get_openai_key();
    $payload = [
        'model'           => 'gpt-4o-mini',
        'response_format' => ['type' => 'json_object'],
        'temperature'     => 0.0, // déterministe pour extraction structurée
        'max_tokens'      => 1200,
        'messages'        => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('OpenAI cURL error');
    }
    if ($code !== 200) {
        $body = json_decode((string)$raw, true);
        $msg  = $body['error']['message'] ?? (string)$raw;
        throw new RuntimeException("OpenAI HTTP {$code}: {$msg}");
    }
    $body = json_decode((string)$raw, true);
    $content = $body['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) {
        throw new RuntimeException('OpenAI response missing content');
    }
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('OpenAI returned non-JSON');
    }
    return $parsed;
}

/**
 * Analyse un document : récupère son OCR, appelle OpenAI, persiste le résultat.
 *
 * @param int         $documentId       ID du document source
 * @param string      $documentTable    Nom de la table source ('admin_documents'...)
 * @param string      $ocrText          Texte OCR du document (peut être vide)
 * @param string|null $forcedService    Si l'utilisateur force un service métier
 * @return array{analysis_id:int, result:array} Résultat IA + id de la ligne agent_ged_analyses
 * @throws RuntimeException
 */
function analyzeGedDocument(
    int $documentId,
    string $documentTable,
    string $ocrText,
    ?string $forcedService = null
): array {
    if (trim($ocrText) === '') {
        throw new RuntimeException('OCR text vide — impossible d\'analyser. Lance un OCR d\'abord.');
    }

    // 1. Construire le prompt
    $prompts = buildGedPrompt($ocrText, $forcedService);

    // 2. Appeler OpenAI
    $result = callOpenAiJson($prompts['system'], $prompts['user']);

    // 3. Persister dans agent_ged_analyses
    $pdo = agent_get_pdo();
    $stmt = $pdo->prepare("
        INSERT INTO agent_ged_analyses (
            document_id, document_table, source_type,
            ocr_engine, ocr_text, ia_engine,
            suggested_module, suggested_level_2, suggested_level_3, suggested_filename,
            detected_immeuble, detected_fournisseur, detected_locataire,
            detected_salarie, detected_proprietaire,
            detected_montant, detected_date,
            suggested_action, confidence_score, status,
            id_societe, id_agence, ai_raw_response
        ) VALUES (
            :doc_id, :doc_table, 'reanalyze',
            'tesseract', :ocr_text, 'openai-gpt-4o-mini',
            :module, :n2, :n3, :filename,
            :immeuble, :fournisseur, :locataire,
            :salarie, :proprietaire,
            :montant, :date_doc,
            :action, :confiance, 'to_validate',
            :id_societe, :id_agence, :ai_raw
        )
    ");
    $stmt->execute([
        'doc_id'       => $documentId > 0 ? $documentId : null,
        'doc_table'    => $documentTable,
        'ocr_text'     => mb_substr($ocrText, 0, 65535),
        'module'       => isset($result['module'])    ? mb_substr((string)$result['module'], 0, 50)    : null,
        'n2'           => isset($result['niveau_2'])  ? mb_substr((string)$result['niveau_2'], 0, 100) : null,
        'n3'           => isset($result['niveau_3'])  ? mb_substr((string)$result['niveau_3'], 0, 100) : null,
        'filename'     => isset($result['suggested_filename']) ? mb_substr((string)$result['suggested_filename'], 0, 255) : null,
        'immeuble'     => isset($result['immeuble'])     ? mb_substr((string)$result['immeuble'], 0, 255)     : null,
        'fournisseur'  => isset($result['fournisseur'])  ? mb_substr((string)$result['fournisseur'], 0, 255)  : null,
        'locataire'    => isset($result['locataire'])    ? mb_substr((string)$result['locataire'], 0, 255)    : null,
        'salarie'      => isset($result['salarie'])      ? mb_substr((string)$result['salarie'], 0, 255)      : null,
        'proprietaire' => isset($result['proprietaire']) ? mb_substr((string)$result['proprietaire'], 0, 255) : null,
        'montant'      => isset($result['montant'])      ? (float)$result['montant']                          : null,
        'date_doc'     => isset($result['date_document']) && $result['date_document'] !== null
                                                         ? (string)$result['date_document']                   : null,
        'action'       => isset($result['action'])       ? (string)$result['action']                          : null,
        'confiance'    => isset($result['confiance'])    ? (float)$result['confiance']                        : null,
        'id_societe'   => $_SESSION['id_societe'] ?? null,
        'id_agence'    => $_SESSION['id_agence']  ?? null,
        'ai_raw'       => json_encode($result, JSON_UNESCAPED_UNICODE),
    ]);
    $analysisId = (int)$pdo->lastInsertId();

    return ['analysis_id' => $analysisId, 'result' => $result];
}

/**
 * Placeholder pour OCR premium. À brancher plus tard sur Mindee / Google Vision /
 * Mistral OCR. Pour l'instant, lève une exception "non configuré".
 *
 * @param int $documentId
 * @return array{text:string, engine:string, confidence:float}
 */
function runPremiumOcr(int $documentId): array
{
    throw new RuntimeException(
        'OCR premium non configuré. Brancher Mindee (clé MINDEE_API_KEY) ou '
        . 'Google Vision (clé GOOGLE_VISION_API_KEY) puis remplacer ce placeholder '
        . 'par un appel à l\'adapter correspondant.'
    );
}

/**
 * Marque une analyse comme validée par l'utilisateur courant.
 * Met à jour status, validated_by, validated_at.
 *
 * @param int $analysisId ID de la ligne agent_ged_analyses
 * @param int $userId     ID du user qui valide
 * @return bool true si update OK, false si analyse introuvable ou déjà validée
 */
function validateGedAnalysis(int $analysisId, int $userId): bool
{
    $pdo = agent_get_pdo();
    $stmt = $pdo->prepare("
        UPDATE agent_ged_analyses
        SET status = 'validated', validated_by = :uid, validated_at = NOW()
        WHERE id = :id AND status IN ('to_validate','draft','manual_review')
    ");
    $stmt->execute(['uid' => $userId, 'id' => $analysisId]);
    return $stmt->rowCount() > 0;
}

/**
 * Marque une analyse comme rejetée (l'utilisateur n'est pas d'accord avec l'IA).
 */
function rejectGedAnalysis(int $analysisId, int $userId): bool
{
    $pdo = agent_get_pdo();
    $stmt = $pdo->prepare("
        UPDATE agent_ged_analyses
        SET status = 'rejected', validated_by = :uid, validated_at = NOW()
        WHERE id = :id AND status IN ('to_validate','draft','manual_review')
    ");
    $stmt->execute(['uid' => $userId, 'id' => $analysisId]);
    return $stmt->rowCount() > 0;
}

/**
 * Liste paginée des analyses pour la page agent_ged.php.
 * Filtrage multi-tenant : on ne montre que les analyses de la société de l'utilisateur,
 * sauf pour les rôles admin (1, 7, 8) qui voient tout.
 *
 * @param array $filters {status?, module?, search?}
 * @return list<array>
 */
function listAgentQueue(array $filters = [], int $limit = 100): array
{
    $pdo = agent_get_pdo();
    $isAdmin = in_array($_SESSION['role_id'] ?? 0, [1, 7, 8], true);

    $where = '1=1';
    $params = [];
    if (!empty($filters['status'])) {
        $where .= ' AND status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['module'])) {
        $where .= ' AND suggested_module = :module';
        $params['module'] = $filters['module'];
    }
    if (!empty($filters['search'])) {
        $where .= ' AND (detected_immeuble LIKE :q OR detected_fournisseur LIKE :q OR suggested_filename LIKE :q)';
        $params['q'] = '%' . $filters['search'] . '%';
    }
    if (!$isAdmin && !empty($_SESSION['id_societe'])) {
        $where .= ' AND id_societe = :sid';
        $params['sid'] = (int)$_SESSION['id_societe'];
    }

    $sql = "SELECT * FROM agent_ged_analyses
            WHERE {$where}
            ORDER BY status = 'to_validate' DESC, confidence_score ASC, created_at DESC
            LIMIT " . max(1, min(500, $limit));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Stats pour les cards en haut de la page : nombre total / à valider / validés / rejetés.
 * Filtrées par société pour les non-admins.
 *
 * @return array{total:int, to_validate:int, validated:int, rejected:int, manual:int}
 */
function agentQueueStats(): array
{
    $pdo = agent_get_pdo();
    $isAdmin = in_array($_SESSION['role_id'] ?? 0, [1, 7, 8], true);

    $where = '1=1';
    $params = [];
    if (!$isAdmin && !empty($_SESSION['id_societe'])) {
        $where .= ' AND id_societe = :sid';
        $params['sid'] = (int)$_SESSION['id_societe'];
    }

    $sql = "SELECT
                COUNT(*)                                AS total,
                SUM(status='to_validate')               AS to_validate,
                SUM(status='validated')                 AS validated,
                SUM(status='rejected')                  AS rejected,
                SUM(status='manual_review')             AS manual,
                AVG(confidence_score)                   AS avg_conf
            FROM agent_ged_analyses
            WHERE {$where}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total'       => (int)($row['total']       ?? 0),
        'to_validate' => (int)($row['to_validate'] ?? 0),
        'validated'   => (int)($row['validated']   ?? 0),
        'rejected'    => (int)($row['rejected']    ?? 0),
        'manual'      => (int)($row['manual']      ?? 0),
        'avg_conf'    => round((float)($row['avg_conf'] ?? 0), 1),
    ];
}

/**
 * Stats par module pour le tableau de répartition.
 * @return list<array{module:string, total:int, avg_conf:float}>
 */
function agentStatsByModule(): array
{
    $pdo = agent_get_pdo();
    $isAdmin = in_array($_SESSION['role_id'] ?? 0, [1, 7, 8], true);

    $where = 'suggested_module IS NOT NULL';
    $params = [];
    if (!$isAdmin && !empty($_SESSION['id_societe'])) {
        $where .= ' AND id_societe = :sid';
        $params['sid'] = (int)$_SESSION['id_societe'];
    }

    $sql = "SELECT suggested_module AS module, COUNT(*) AS total, AVG(confidence_score) AS avg_conf
            FROM agent_ged_analyses
            WHERE {$where}
            GROUP BY suggested_module
            ORDER BY total DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
