<?php
declare(strict_types=1);
set_time_limit(120);

/**
 * POST /api/societe_doc_analyze.php
 *
 * Analyse un document uploadé dans societe.php (Kbis, carte T, attestation
 * d'assurance RCP, garantie financière, RIB, extrait immatriculation INSEE…)
 * via GPT-4o et pré-remplit automatiquement les champs `societes` vides.
 *
 * Paramètres POST :
 *   - csrf_token (token 'rh_societe')
 *   - doc_id
 *   - comment      (optionnel) : instruction libre pour l'IA
 *   - force        (0|1)      : écrase aussi les champs déjà remplis
 *
 * Réponse JSON :
 *   {
 *     ok, doc_id, fields_filled, extracted: {...}, applied: {field: newValue, ...},
 *     skipped: {field: reason, ...}, analyzed_at, analysis_comment
 *   }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ia_analyse.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/**
 * Convertit un PDF en JPEG(s) base64 via pdftoppm (Poppler).
 * Utilisé comme fallback quand l'extraction texte échoue (PDF scanné).
 *
 * @return array liste de data-URIs "data:image/jpeg;base64,..." (max $maxPages)
 * @throws RuntimeException si pdftoppm introuvable ou conversion ratée
 */
function societe_pdf_to_jpeg_base64(string $pdfPath, int $maxPages = 4, int $dpi = 150): array
{
    $pdftoppm = null;
    foreach ([
        'C:\\poppler\\Library\\bin\\pdftoppm.exe',
        'C:\\Program Files\\poppler\\bin\\pdftoppm.exe',
        'C:\\poppler\\bin\\pdftoppm.exe',
        'pdftoppm',
    ] as $cand) {
        if ($cand === 'pdftoppm') { $pdftoppm = $cand; break; }
        if (is_file($cand))        { $pdftoppm = $cand; break; }
    }
    if (!$pdftoppm) {
        throw new RuntimeException('pdftoppm introuvable (Poppler non installé).');
    }

    // Dossier temporaire dédié par appel
    $tmpBase = sys_get_temp_dir() . '/societe_pdf_' . bin2hex(random_bytes(6));
    @mkdir($tmpBase, 0755, true);
    $outPrefix = $tmpBase . '/page';

    // pdftoppm -jpeg -r 150 -l 4 input.pdf outprefix
    $cmd = (PHP_OS_FAMILY === 'Windows' ? '"' . $pdftoppm . '"' : $pdftoppm)
         . " -jpeg -r {$dpi} -l {$maxPages} "
         . escapeshellarg($pdfPath) . ' '
         . escapeshellarg($outPrefix)
         . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    @shell_exec($cmd);

    // pdftoppm écrit page-01.jpg, page-02.jpg, etc. (avec zéro-padding selon le nombre total)
    $images = [];
    $generated = glob($tmpBase . '/page-*.jpg') ?: [];
    sort($generated);
    foreach (array_slice($generated, 0, $maxPages) as $img) {
        $bin = @file_get_contents($img);
        if ($bin !== false && strlen($bin) > 200) {
            $images[] = 'data:image/jpeg;base64,' . base64_encode($bin);
        }
        @unlink($img);
    }
    @rmdir($tmpBase);

    if (empty($images)) {
        throw new RuntimeException('Conversion PDF→JPEG échouée (pdftoppm n\'a rien produit).');
    }
    return $images;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']); exit;
    }
    verify_csrf_any('rh_societe');

    $pdo           = db();
    $isSuperAdmin  = function_exists('is_super_admin') ? is_super_admin() : false;
    // Résolution de la société courante. Un super-admin peut consulter
    // n'importe quelle société via ?sa_id=X sur societe.php — dans ce cas
    // le front poste `soc_override` pour indiquer quelle société est active.
    $mySocieteId = function_exists('current_societe_id') ? (int)current_societe_id() : 0;
    if ($mySocieteId <= 0) $mySocieteId = (int)($_SESSION['id_societe'] ?? 0);
    $socOverride = $isSuperAdmin && isset($_POST['soc_override'])
        ? (int)$_POST['soc_override'] : 0;
    $societeId   = $socOverride > 0 ? $socOverride : $mySocieteId;

    $docId     = (int)($_POST['doc_id'] ?? 0);
    $comment   = trim((string)($_POST['comment'] ?? ''));
    $force     = !empty($_POST['force']);

    if ($docId <= 0) throw new RuntimeException('doc_id manquant');
    if ($societeId <= 0 && !$isSuperAdmin) throw new RuntimeException('Aucune société en session');

    // ─── Charger le document (diagnostic précis en cas d'erreur) ──
    // On charge d'abord SANS filtre société/agence/user pour distinguer
    // "doc inexistant" de "doc hors-périmètre".
    $stmt = $pdo->prepare("
        SELECT id, id_societe, id_agence, id_user, type_document, categorie_document,
               nom_fichier, chemin_fichier, mime_type, titre
        FROM documents
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        throw new RuntimeException("Document id={$docId} inexistant en base (peut-être supprimé ?).");
    }
    if ((int)$doc['id_societe'] !== $societeId) {
        throw new RuntimeException(
            "Document id={$docId} appartient à la société #{$doc['id_societe']}, "
            . "vous êtes connecté sur la société #{$societeId}."
        );
    }
    if ($doc['id_agence'] !== null) {
        throw new RuntimeException(
            "Document id={$docId} est rattaché à l'agence #{$doc['id_agence']} "
            . "— pas à la société. Utilisez l'onglet de l'agence concernée."
        );
    }
    if ($doc['id_user'] !== null) {
        throw new RuntimeException(
            "Document id={$docId} est rattaché à l'utilisateur #{$doc['id_user']} "
            . "— ce n'est pas un document société."
        );
    }

    $filepath = dirname(__DIR__) . '/' . ltrim((string)$doc['chemin_fichier'], '/');
    if (!is_file($filepath)) throw new RuntimeException('Fichier absent sur le disque : ' . $doc['chemin_fichier']);

    // Persiste le commentaire (même si l'analyse échoue ensuite)
    if ($comment !== '') {
        $pdo->prepare("UPDATE documents SET analysis_comment = ? WHERE id = ?")
            ->execute([$comment, $docId]);
    }

    // ─── Extraction du texte (avec fallback Vision sur image si vide) ──
    $mime        = (string)$doc['mime_type'];
    $text        = '';
    $visionImages = []; // data-URIs si on doit passer en vision

    if ($mime === 'application/pdf' || str_ends_with(strtolower($doc['nom_fichier']), '.pdf')) {
        $text = extractPdfText($filepath);
        // Si le PDF n'a pas de couche texte (scan d'image), on bascule en Vision
        if (trim($text) === '' || strlen(trim($text)) < 50) {
            try {
                $visionImages = societe_pdf_to_jpeg_base64($filepath, 4, 150);
                $text = ''; // forcera le mode vision ci-dessous
            } catch (Throwable $e) {
                throw new RuntimeException("Extraction texte impossible et conversion vision échouée : " . $e->getMessage());
            }
        }
    } elseif (str_starts_with($mime, 'image/')) {
        // JPG/PNG/WEBP direct → vision mode
        $bin = @file_get_contents($filepath);
        if ($bin === false) throw new RuntimeException('Image illisible');
        $visionImages = ['data:' . $mime . ';base64,' . base64_encode($bin)];
    } elseif (in_array($mime, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
        // DOC/DOCX : extraction basique (peut être améliorée avec PHPOffice)
        $raw = (string)file_get_contents($filepath);
        $text = preg_replace('/<[^>]+>/', ' ', $raw) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = mb_substr(trim($text), 0, 50000);
    } else {
        throw new RuntimeException('Type de fichier non supporté pour l\'analyse : ' . $mime);
    }

    $visionMode = !empty($visionImages);

    // ─── Préparation du prompt ChatGPT ──────────────────────────────
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model  = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4o';
    if (!$apiKey) throw new RuntimeException('Clé OPENAI_API_KEY non configurée');

    // Liste des champs à extraire — alignée sur les colonnes `societes`
    $extractibleFields = [
        'nom'                    => 'Nom commercial',
        'raison_sociale'         => 'Raison sociale officielle',
        'forme_juridique'        => 'Forme juridique (SAS, SARL, SCI…)',
        'capital_social'         => 'Capital social en € (nombre uniquement)',
        'siret'                  => 'Numéro SIRET (14 chiffres)',
        'siren'                  => 'Numéro SIREN (9 chiffres)',
        'tva_intracom'           => 'Numéro TVA intracommunautaire (ex: FR12345678901)',
        'code_ape'               => 'Code APE / NAF (ex: "6831Z" pour agences immobilières) — format : 4 chiffres + 1 lettre majuscule',
        'numero_carte_t'         => 'Numéro de carte professionnelle T (transaction/gestion/syndic)',
        'cci_carte_t'            => 'CCI émettrice de la carte T',
        'carte_t_date_expiration'=> 'Date d\'expiration de la carte T (format YYYY-MM-DD)',
        'adresse_1'              => 'Adresse ligne 1 (numéro + rue)',
        'adresse_2'              => 'Adresse ligne 2 (bâtiment, étage, lieu-dit)',
        'code_postal'            => 'Code postal (5 chiffres)',
        'ville'                  => 'Ville',
        'pays'                   => 'Pays',
        'telephone'              => 'Téléphone principal',
        'email'                  => 'Email principal',
        'site_web'               => 'Site web (URL complète)',
        'rib_emetteur_nom'       => 'Nom du titulaire du compte bancaire',
        'rib_emetteur_iban'      => 'IBAN (sans espaces)',
        'rib_emetteur_bic'       => 'BIC / SWIFT',
    ];

    // Champs "couvertures" retournés sous forme de TABLEAU (une entrée par
    // attestation détectée dans le document). Un même PDF peut en contenir
    // plusieurs : par exemple une attestation "Garantie financière + RCP"
    // Galian sur plusieurs pages, ou une attestation couvrant 3 activités.
    // Chaque entrée a la structure : {type, activite, compagnie, numero_police,
    // montant, date_debut, date_expiration}
    $couvertureFieldDoc = <<<DOC
"couvertures" : TABLEAU d'objets — UNE entrée par attestation trouvée dans le document.
  Si le document est une attestation GALIAN contenant à la fois Garantie
  financière ET RCP pour la même activité, retourne DEUX entrées distinctes.
  Si l'attestation couvre 3 activités, retourne 3 entrées.
  N'inclus pas cette clé si le document n'est pas une attestation.

  Chaque entrée est un objet avec les clés :
    - "type" : "rcp" | "garantie_financiere"
    - "activite" : "transaction" | "gestion" | "syndic" | "location" | "neuf" | "multi"
    - "compagnie" : "Galian-SMABTP" | "MMA" | "CEGC" | "SOCAF" | "AXA" | …
    - "numero_police" : numéro de contrat / attestation
    - "montant" : nombre en € (surtout pour garantie financière, null sinon)
    - "date_debut" : YYYY-MM-DD
    - "date_expiration" : YYYY-MM-DD

  RÈGLES D'IDENTIFICATION :
    - "ATTESTATION DE GARANTIE" / "GARANTIE D'UN MONTANT DE"
      → type = "garantie_financiere"
    - "ATTESTATION D'ASSURANCE RESPONSABILITE CIVILE" / "RCP" / "responsabilité civile professionnelle"
      → type = "rcp"
    - Les mentions "AU TITRE DE L'ACTIVITE DE GESTION IMMOBILIERE" / "transaction sur immeubles" /
      "syndic de copropriété" déterminent l'activité :
        * "GESTION IMMOBILIERE" → "gestion"
        * "TRANSACTION SUR IMMEUBLES" / "transaction sur immeubles et fonds de commerce" → "transaction"
        * "SYNDIC DE COPROPRIETE" → "syndic"
        * "location de biens immobiliers" → "location"
        * "VEFA" / "immobilier neuf" → "neuf"
        * plusieurs activités dans le même texte → "multi"
DOC;

    $fieldList = '';
    foreach ($extractibleFields as $k => $desc) {
        $fieldList .= "  - \"{$k}\" : {$desc}\n";
    }
    $fieldList .= "\n" . $couvertureFieldDoc . "\n";

    $systemPrompt = <<<SYS
Tu es un assistant spécialisé dans l'analyse de documents administratifs français d'agences
immobilières : Kbis, extrait INSEE, carte professionnelle T, attestation d'assurance RCP,
garantie financière GALIAN/CEGC/SOCAF, RIB, statuts, contrat d'assurance, etc.

Tu extrais les informations factuelles présentes dans le document et tu retournes
UNIQUEMENT un JSON valide avec les clés demandées. N'invente JAMAIS de données. Si une
valeur n'est pas clairement présente dans le document, omets la clé (ne pas renvoyer null,
ne pas renvoyer une chaîne vide — juste ne pas inclure la clé dans l'objet).

RÈGLES DE FORMATAGE :
- SIRET / SIREN : chiffres uniquement, sans espaces
- Dates : format YYYY-MM-DD (ISO)
- Capital social : nombre entier ou décimal, sans "€" ni espaces
- Téléphone : format international ou national propre (+33 1 23 45 67 89 ou 01 23 45 67 89)
- IBAN : sans espaces
- TVA intracom : format FR99999999999 (lettres majuscules + chiffres sans espaces)
- URL : avec https:// au début
SYS;

    $userCommentBlock = $comment !== ''
        ? "\nATTENTION — Instruction spécifique de l'utilisateur :\n\"{$comment}\"\nDonne la priorité à ces éléments dans l'extraction.\n"
        : '';

    if ($visionMode) {
        // Mode Vision : le document est fourni sous forme d'image(s) — on
        // demande à GPT d'effectuer l'OCR + extraction en un seul appel.
        $userPromptIntro = "Voici un document d'une agence immobilière française ({$doc['titre']}).\n"
            . "Catégorie : {$doc['categorie_document']}\n"
            . $userCommentBlock . "\n"
            . "Le document est fourni sous forme d'image(s) scannée(s). Lis attentivement le texte "
            . "visible et extrais les informations suivantes si elles sont présentes (omet toute clé "
            . "dont la valeur n'est pas visible dans l'image) :\n\n"
            . $fieldList . "\n"
            . "Retourne UNIQUEMENT un objet JSON plat avec les clés extraites, rien d'autre.";
    } else {
        $userPromptIntro = null; // signal pour construire $userPrompt texte ci-dessous
    }

    $userPrompt = <<<USER
Voici un document d'une agence immobilière française ({$doc['titre']}).
Catégorie : {$doc['categorie_document']}
{$userCommentBlock}
Extrais les informations suivantes si elles sont présentes (omet toute clé dont la valeur
n'est pas dans le document) :

{$fieldList}

Retourne UNIQUEMENT un objet JSON plat avec les clés extraites, rien d'autre.

--- DÉBUT DU DOCUMENT ---
{$text}
--- FIN DU DOCUMENT ---
USER;

    if (!$visionMode && trim($text) === '') {
        throw new RuntimeException("Impossible d'extraire le texte du document.");
    }

    // ─── Appel OpenAI ──────────────────────────────────────────────
    // GPT-5 : n'accepte PAS `temperature` ni `max_tokens` — utilise
    // `max_completion_tokens` uniquement. Le reasoning invisible de GPT-5
    // consomme une grosse partie du budget : il faut une limite généreuse
    // pour que le content final soit complet.
    $isGpt5 = stripos((string)$model, 'gpt-5') !== false;

    // Construction du message utilisateur : texte simple OU multi-part avec
    // images pour le mode vision (PDF scanné, JPG/PNG).
    if ($visionMode) {
        $userContentParts = [['type' => 'text', 'text' => $userPromptIntro]];
        foreach ($visionImages as $dataUri) {
            $userContentParts[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $dataUri, 'detail' => 'high'],
            ];
        }
        $userMessage = ['role' => 'user', 'content' => $userContentParts];
    } else {
        $userMessage = ['role' => 'user', 'content' => $userPrompt];
    }

    $payload = [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            $userMessage,
        ],
        'response_format' => ['type' => 'json_object'],
    ];
    if ($isGpt5) {
        $payload['max_completion_tokens'] = 8000;   // reasoning+content
    } else {
        $payload['max_tokens']  = 2000;
        $payload['temperature'] = 0.1;
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false)  throw new RuntimeException('Erreur curl : ' . $curlErr);
    if ($httpCode !== 200)    throw new RuntimeException('OpenAI HTTP ' . $httpCode . ' : ' . substr((string)$response, 0, 300));

    // CRITIQUE : reconnect MySQL après le call OpenAI long (10-30s).
    // Sinon "MySQL server has gone away" sur les UPDATE qui suivent →
    // OCR payé pour rien, données perdues. Voir feedback_ocr_fonction_unique.
    if (function_exists('db_keepalive')) {
        try { $pdo = db_keepalive(); } catch (Throwable) {}
    }

    $api          = json_decode((string)$response, true);
    $content      = (string)($api['choices'][0]['message']['content'] ?? '');
    $refusal      = (string)($api['choices'][0]['message']['refusal'] ?? '');
    $finishReason = (string)($api['choices'][0]['finish_reason'] ?? '');
    $usage        = $api['usage'] ?? [];

    // Log complet pour debug (voir logs/php)
    error_log('[societe_doc_analyze] finish=' . $finishReason
              . ' usage=' . json_encode($usage)
              . ' content_len=' . strlen($content)
              . ' refusal_len=' . strlen($refusal));

    // Refus explicite du modèle (sécurité OpenAI)
    if ($refusal !== '') {
        throw new RuntimeException('IA a refusé : ' . substr($refusal, 0, 200));
    }

    // Content vide = presque toujours un dépassement de reasoning budget sur GPT-5
    if (trim($content) === '') {
        $reasoningTokens = $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
        $hint = $finishReason === 'length'
            ? " (limite max_completion_tokens atteinte ; reasoning a consommé {$reasoningTokens} tokens)"
            : " (finish_reason={$finishReason})";
        throw new RuntimeException("L'IA n'a retourné aucun contenu{$hint}. Augmentez max_completion_tokens ou simplifiez le document.");
    }

    // Strip markdown fences au cas où (GPT peut encadrer en ```json ... ```)
    $content = preg_replace('/^\s*```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```\s*$/', '', $content);

    $extracted = json_decode((string)$content, true);
    if (!is_array($extracted)) {
        throw new RuntimeException('Réponse IA non-JSON (finish=' . $finishReason . ') : ' . substr($content, 0, 300));
    }

    // CRITIQUE : sauvegarde du JSON brut DÈS QU'ON L'A, avant les UPDATE
    // structurés qui suivent. Si une étape plante (timeout, FK, colonne
    // manquante), le raw analysis_json est en BDD → replay possible sans
    // re-payer l'OCR (coûte 25 cts/doc Sonnet). Voir feedback_ocr_fonction_unique.
    if (function_exists('db_keepalive')) {
        try { $pdo = db_keepalive(); } catch (Throwable) {}
    }
    try {
        $rawJsonEarly = json_encode([
            'extracted'     => $extracted,
            'raw_content'   => (string)$content,
            '_save_step'    => 'pre_update_structured',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE documents SET analysis_json = ?, analyzed_at = NOW() WHERE id = ?")
            ->execute([$rawJsonEarly, $docId]);
    } catch (Throwable $e) {
        error_log('[societe_doc_analyze EARLY raw save] ' . $e->getMessage());
        // Non-bloquant : on continue, mais le user pourra perdre les données
        // si la suite plante (rare car db_keepalive vient juste d'être appelée).
    }

    // ─── Application des champs extraits ────────────────────────────
    // Séparation : champs société standards (colonnes `societes`) vs champs
    // de couverture assurance/garantie (table `societes_couvertures`).
    $stmtSoc = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
    $stmtSoc->execute([$societeId]);
    $soc = $stmtSoc->fetch(PDO::FETCH_ASSOC) ?: [];

    $applied       = [];
    $skipped       = [];
    $couverturesIn = [];  // tableau d'attestations à upserter

    foreach ($extracted as $field => $value) {
        // ── NOUVEAU FORMAT : "couvertures" est un tableau d'objets ──
        if ($field === 'couvertures' && is_array($value)) {
            foreach ($value as $cov) {
                if (is_array($cov)) $couverturesIn[] = $cov;
            }
            continue;
        }

        // ── Rétro-compat : ancien format "couverture_type", "couverture_*" ──
        // Si l'IA retourne encore un objet plat (ancien prompt en cache),
        // on l'agrège en une seule entrée du tableau $couverturesIn.
        if (is_string($field) && str_starts_with($field, 'couverture_')) {
            static $legacyCov = [];
            $legacyCov[substr($field, strlen('couverture_'))] = is_string($value) ? trim($value) : $value;
            // À la fin de la boucle on le pousse dans $couverturesIn (voir plus bas)
            continue;
        }

        if (!array_key_exists($field, $extractibleFields)) {
            continue; // clés hallucinées
        }

        $value = is_string($value) ? trim($value) : $value;
        if ($value === '' || $value === null) continue;

        // Champs société standards : skip si déjà rempli (sauf --force)
        $current = $soc[$field] ?? null;
        if (!$force && $current !== null && $current !== '' && $current !== '0') {
            $skipped[$field] = 'déjà rempli (valeur=' . mb_substr((string)$current, 0, 40) . ')';
            continue;
        }
        if (in_array($field, ['siret','siren'], true)) {
            $value = preg_replace('/\D+/', '', (string)$value);
        }
        if ($field === 'capital_social') {
            $value = (float) preg_replace('/[^\d.,]/', '', (string)$value);
            $value = str_replace(',', '.', (string)$value);
        }
        $applied[$field] = $value;
    }

    // Agrégation du format legacy (couverture_*) en une entrée du tableau
    if (isset($legacyCov) && !empty($legacyCov) && isset($legacyCov['type'])) {
        $couverturesIn[] = $legacyCov;
    }

    // ── UPDATE societes : champs standards ──
    if (!empty($applied)) {
        $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($applied)));
        $update = $pdo->prepare("UPDATE societes SET $set WHERE id = :_id");
        $execParams = [];
        foreach ($applied as $k => $v) $execParams[':' . $k] = $v;
        $execParams[':_id'] = $societeId;
        $update->execute($execParams);
    }

    // ── VERSIONNING societes_couvertures ────────────────────────────
    // Logique TOLÉRANTE AUX UPLOADS DANS LE DÉSORDRE (tu peux très bien
    // uploader l'attestation 2019 alors que la 2026 est déjà là).
    //
    // Algorithme pour chaque (societe, type, activite) :
    //   1. Si une ligne existe déjà avec le même (numero_police, date_exp)
    //      → update_in_place (ré-analyse de la même attestation)
    //   2. Sinon : INSERT la nouvelle ligne
    //   3. Re-calcul final : la ligne avec la PLUS RÉCENTE date_expiration
    //      devient est_active=1, toutes les autres sont archivées.
    //      Les version_num sont attribués dans l'ordre chronologique
    //      des date_expiration (v1 = la plus ancienne).
    //
    // → Tu peux uploader 2019, 2020, 2023, 2026, 2025 dans n'importe quel
    //   ordre : l'affichage "version active" sera toujours celle dont la
    //   date d'expiration est la plus récente.
    $couvertureInserted = [];

    // Retrouve toutes les versions existantes pour cette combinaison
    $stmtFindAll = $pdo->prepare("
        SELECT id, numero_police, date_expiration, version_num
        FROM societes_couvertures
        WHERE id_societe = :soc AND type = :type AND activite = :act
        ORDER BY date_expiration ASC, id ASC
    ");
    $stmtInsert = $pdo->prepare("
        INSERT INTO societes_couvertures
            (id_societe, type, activite, compagnie, numero_police, montant,
             date_debut, date_expiration, id_document_source,
             est_active, version_num)
        VALUES
            (:soc, :type, :act, :comp, :np, :mt, :dd, :de, :docid, 0, 0)
    ");
    $stmtUpdateInPlace = $pdo->prepare("
        UPDATE societes_couvertures
        SET compagnie = :comp, numero_police = :np, montant = :mt,
            date_debut = :dd, date_expiration = :de, id_document_source = :docid
        WHERE id = :id
    ");
    // Renumérotation / basculement active : utilisé après chaque insert
    $stmtSetArchived = $pdo->prepare("
        UPDATE societes_couvertures
        SET est_active = 0,
            date_archive = COALESCE(date_archive, NOW()),
            version_num = :vnum
        WHERE id = :id
    ");
    $stmtSetActive = $pdo->prepare("
        UPDATE societes_couvertures
        SET est_active = 1,
            date_archive = NULL,
            version_num = :vnum
        WHERE id = :id
    ");

    foreach ($couverturesIn as $cov) {
        if (!is_array($cov)) continue;

        $covType = strtolower(trim((string)($cov['type'] ?? '')));
        if (!in_array($covType, ['rcp', 'garantie_financiere'], true)) continue;

        $covActivite = strtolower(trim((string)($cov['activite'] ?? 'multi')));
        if (!in_array($covActivite, ['transaction','gestion','syndic','location','neuf','multi'], true)) {
            $covActivite = 'multi';
        }

        $covMontant = null;
        if (!empty($cov['montant'])) {
            $clean = (string) preg_replace('/[^\d.,]/', '', (string)$cov['montant']);
            $clean = str_replace(',', '.', $clean);
            $f = (float)$clean;
            if ($f > 0) $covMontant = $f;
        }

        $params = [
            ':soc'   => $societeId,
            ':type'  => $covType,
            ':act'   => $covActivite,
            ':comp'  => $cov['compagnie']       ?? null,
            ':np'    => $cov['numero_police']   ?? null,
            ':mt'    => $covMontant,
            ':dd'    => $cov['date_debut']      ?? null,
            ':de'    => $cov['date_expiration'] ?? null,
            ':docid' => $docId,
        ];

        try {
            // 1. Charger toutes les versions existantes (dans l'ordre chronologique)
            $stmtFindAll->execute([
                ':soc'  => $societeId,
                ':type' => $covType,
                ':act'  => $covActivite,
            ]);
            $existing = $stmtFindAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 2. Est-ce qu'une ligne a déjà le même n° police + même date_exp ?
            //    (= ré-analyse du même doc, on enrichit juste les champs)
            $matched = null;
            foreach ($existing as $row) {
                $sameNp  = trim((string)$row['numero_police']) === trim((string)$params[':np']);
                $sameExp = (string)$row['date_expiration'] === (string)$params[':de'];
                if ($sameNp && $sameExp) { $matched = $row; break; }
            }

            $action = '';
            if ($matched) {
                $stmtUpdateInPlace->execute([
                    ':id'    => (int)$matched['id'],
                    ':comp'  => $params[':comp'],
                    ':np'    => $params[':np'],
                    ':mt'    => $params[':mt'],
                    ':dd'    => $params[':dd'],
                    ':de'    => $params[':de'],
                    ':docid' => $params[':docid'],
                ]);
                $action = 'update_in_place';
            } else {
                // Nouvelle ligne — on l'insère SANS est_active (=0) et sans vnum :
                // le re-calcul ci-dessous positionnera tout.
                $stmtInsert->execute($params);
                $action = 'inserted';
            }

            // 3. Re-calcul CHRONOLOGIQUE : on re-lit toutes les lignes et on
            //    attribue version_num selon l'ordre de date_expiration croissante
            //    (v1 = la plus ancienne). La dernière devient est_active=1.
            $stmtFindAll->execute([
                ':soc'  => $societeId,
                ':type' => $covType,
                ':act'  => $covActivite,
            ]);
            $all = $stmtFindAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $n = count($all);
            $thisVersion = 0;
            foreach ($all as $i => $row) {
                $vNum = $i + 1;
                $isLast = ($i === $n - 1);
                $stmt = $isLast ? $stmtSetActive : $stmtSetArchived;
                $stmt->execute([':vnum' => $vNum, ':id' => (int)$row['id']]);
                // Retient le vnum de la ligne qu'on vient d'insérer/updater
                $isThisOne = $matched
                    ? ((int)$row['id'] === (int)$matched['id'])
                    : ((string)$row['date_expiration'] === (string)$params[':de']
                       && trim((string)$row['numero_police']) === trim((string)$params[':np']));
                if ($isThisOne) $thisVersion = $vNum;
            }

            $couvertureInserted[] = [
                'type'            => $covType,
                'activite'        => $covActivite,
                'compagnie'       => $params[':comp'],
                'numero_police'   => $params[':np'],
                'montant'         => $covMontant,
                'date_debut'      => $params[':dd'],
                'date_expiration' => $params[':de'],
                'version_num'     => $thisVersion,
                'total_versions'  => $n,
                'is_latest'       => ($thisVersion === $n),
                'action'          => $action, // update_in_place | inserted
            ];
            $applied['__cov_' . $covType . '_' . $covActivite] =
                "{$covType} / {$covActivite} (v{$thisVersion}/{$n})";
        } catch (Throwable $e) {
            error_log('[societe_doc_analyze] couverture error : ' . $e->getMessage()
                      . ' (type=' . $covType . ' activite=' . $covActivite . ')');
        }
    }

    $analysisJson = json_encode([
        'extracted' => $extracted,
        'applied'   => $applied,
        'skipped'   => $skipped,
        'comment'   => $comment,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $pdo->prepare("
        UPDATE documents
        SET analyzed_at = NOW(),
            analysis_json = ?,
            analysis_fields_filled = ?,
            analysis_error = NULL
        WHERE id = ?
    ")->execute([$analysisJson, count($applied), $docId]);

    echo json_encode([
        'ok'            => true,
        'doc_id'        => $docId,
        'fields_filled' => count($applied),
        'extracted'     => $extracted,
        'applied'       => $applied,
        'skipped'       => $skipped,
        'couverture'    => $couvertureInserted,  // null sauf si RCP/garantie détectée
        'analyzed_at'   => date('Y-m-d H:i:s'),
        'comment'       => $comment,
        'vision_mode'   => $visionMode,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Log erreur sur la ligne document si possible
    try {
        if (isset($docId) && $docId > 0) {
            db()->prepare("UPDATE documents SET analysis_error = ?, analyzed_at = NOW() WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 500), $docId]);
        }
    } catch (Throwable) {}
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
