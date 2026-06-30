<?php
/**
 * inc/rh_document_extractor.php — Router central d'extraction documentaire.
 *
 * Point d'entrée unique pour analyser un document déposé par un user.
 * S'appuie sur l'infra existante (ia_analyse, ik_carte_grise, poppler).
 *
 * PIPELINE :
 *   1. Extraction texte PDF (pdftotext → pdfparser → regex brut) ou image
 *   2. Auto-détection du type de document (keywords + scores)
 *   3. Routage vers l'extracteur spécialisé `inc/rh_extractors/{type}.php`
 *   4. Fallback IA Vision (pdftoppm → GPT-4o) si score regex < seuil
 *   5. Validation des champs extraits (IBAN mod97, NSS, VIN, MRZ…)
 *   6. Retour structuré normalisé
 *
 * STRUCTURE DE RETOUR (toujours la même, qu'il y ait erreur ou non) :
 *   [
 *     'success'    => bool,
 *     'doc_type'   => 'rib|cni|passeport|justif_domicile|carte_vitale|…|unknown',
 *     'confidence' => 0..100,   // confiance dans la détection du type
 *     'engine'     => 'regex|vision|hybrid|fallback_ia',
 *     'fields'     => [...],    // champs extraits (nom, iban, date_naissance, …)
 *     'validations'=> [...],    // statut par champ (ok | format_invalid | expired…)
 *     'raw_text'   => '…',      // texte extrait (tronqué)
 *     'error'      => null|string,
 *   ]
 *
 * USAGE :
 *   require_once __DIR__ . '/inc/rh_document_extractor.php';
 *   $res = rhExtractDocument('/path/to/rib.pdf', 'application/pdf');
 *   if ($res['success']) {
 *       // $res['fields'] contient iban, bic, titulaire, banque
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/ia_analyse.php';          // extractPdfText()
require_once __DIR__ . '/rh_field_validators.php'; // validators partagés

const RHDX_SCORE_THRESHOLD_IA = 60;   // en dessous → fallback IA
const RHDX_TEXT_MIN_CHARS     = 30;   // en dessous → bascule Vision

/**
 * Liste des types de documents supportés et leurs mots-clés de détection.
 * Chaque mot-clé est (motif_regex, poids). La somme des hits donne un score.
 */
function rhDxKeywordMap(): array
{
    return [
        'rib' => [
            ['/\bIBAN\b/i', 30],
            ['/\bBIC\b/i', 20],
            ['/\bRIB\b/i', 25],
            ['/Relev[ée] d.Identit[ée] Bancaire/i', 35],
            ['/Code\s+banque/i', 15],
            ['/Code\s+guichet/i', 15],
            ['/Domiciliation/i', 10],
            ['/\bFR\d{2}\s?\d{4}/i', 40],  // début IBAN français
        ],
        'cni' => [
            ['/R[ÉE]PUBLIQUE\s+FRAN[ÇC]AISE/i', 40],
            ['/CARTE\s+NATIONALE\s+D.IDENTIT[ÉE]/i', 50],
            ['/CARTE\s+D.IDENTIT[ÉE]/i', 30],
            ['/\bIDFRA\b/i', 40],            // début MRZ CNI
            ['/N[ée]\(e\)\s+le/i', 15],
            ['/Nom\s*:/i', 10],
            ['/Pr[ée]nom/i', 10],
        ],
        'passeport' => [
            ['/\bPASSEPORT\b/i', 45],
            ['/\bPASSPORT\b/i', 30],
            ['/\bP<FRA\b/', 60],              // début MRZ passeport FR
            ['/Passport\s*No/i', 25],
            ['/R[ÉE]PUBLIQUE\s+FRAN[ÇC]AISE/i', 15],
        ],
        'justif_domicile' => [
            ['/\bfacture\b/i', 15],
            ['/\bquittance\b/i', 25],
            ['/\bEDF\b/', 30],
            ['/\bENGIE\b/i', 30],
            ['/\bVeolia\b/i', 30],
            ['/\bGDF\b/', 20],
            ['/avis\s+d.imposition/i', 30],
            ['/bailleur/i', 20],
            ['/\bOrange\b/i', 15],
            ['/\bSFR\b/', 15],
            ['/\bBouygues\b/i', 15],
            ['/loyer/i', 20],
        ],
        'carte_vitale' => [
            ['/Assurance\s+Maladie/i', 35],
            ['/CPAM/i', 40],
            ['/Caisse\s+Primaire/i', 35],
            ['/Ameli/i', 25],
            ['/attestation\s+de\s+droits/i', 30],
            ['/N[°o]\s+de\s+s[ée]curit[ée]\s+sociale/i', 40],
            ['/m[ée]decin\s+traitant/i', 15],
        ],
        'mutuelle' => [
            ['/\bmutuelle\b/i', 30],
            ['/compl[ée]mentaire\s+sant[ée]/i', 35],
            ['/pr[ée]voyance/i', 20],
            ['/adh[ée]rent/i', 20],
            ['/garanties/i', 15],
            ['/contrat\s+collectif/i', 20],
            ['/dispense/i', 25],  // formulaire de dispense
        ],
        'titre_sejour' => [
            ['/titre\s+de\s+s[ée]jour/i', 50],
            ['/carte\s+de\s+s[ée]jour/i', 40],
            ['/pr[ée]fecture/i', 25],
            ['/autoris[ée]\s+[àa]\s+travailler/i', 30],
            ['/droit\s+au\s+travail/i', 25],
        ],
        'permis' => [
            ['/PERMIS\s+DE\s+CONDUIRE/i', 50],
            ['/Driving\s+Licence/i', 30],
            ['/Cat[ée]gorie\s+[ABCD]/i', 25],
            ['/D[ée]livr[ée]\s+le/i', 15],
        ],
        'carte_grise' => [
            ['/Certificat\s+d.immatriculation/i', 50],
            ['/Carte\s+grise/i', 40],
            ['/\bSIV\b/', 20],
            ['/Puissance\s+fiscale/i', 25],
            ['/\bP\.6\b/', 20],
            ['/\bD\.1\b/', 15],
            ['/\bE\s*:/', 10],  // VIN position
        ],
        'assurance_vehicule' => [
            ['/attestation\s+d.assurance/i', 40],
            ['/police\s+d.assurance/i', 30],
            ['/v[ée]hicule\s+assur[ée]/i', 35],
            ['/tous\s+risques/i', 20],
            ['/au\s+tiers/i', 15],
            ['/usage\s+professionnel/i', 30],
        ],
    ];
}

/**
 * Détection automatique du type de document à partir du texte.
 *
 * Retourne le meilleur match (ou 'unknown' si aucun score > seuil).
 */
function rhDetectDocumentType(string $text): array
{
    $scores = [];
    foreach (rhDxKeywordMap() as $type => $keywords) {
        $score = 0;
        foreach ($keywords as [$pattern, $weight]) {
            if (preg_match($pattern, $text)) {
                $score += $weight;
            }
        }
        $scores[$type] = $score;
    }

    arsort($scores);
    $top = array_key_first($scores);
    $topScore = $scores[$top] ?? 0;

    // Seuil minimum pour accepter une détection
    if ($topScore < 30) {
        return ['type' => 'unknown', 'confidence' => 0, 'scores' => $scores];
    }

    return [
        'type'       => (string)$top,
        'confidence' => (int)min(100, $topScore),
        'scores'     => $scores,
    ];
}

/**
 * Détection du type de document via OpenAI Vision quand aucun texte n'est
 * exploitable (images scannées, photos de pièces d'identité…).
 *
 * Consomme 1 appel OpenAI léger (~400 tokens) et retourne le type détecté
 * + son niveau de confiance. Utilisé en fallback de `rhDetectDocumentType()`
 * qui ne regarde que le texte.
 *
 * @return array ['type' => string, 'confidence' => int]
 */
function rhDxDetectTypeFromImage(array $visionImages): array
{
    if (empty($visionImages)) {
        return ['type' => 'unknown', 'confidence' => 0];
    }

    $systemPrompt = <<<SYS
Tu es un expert en documents administratifs français. Identifie le type
de document présent sur l'image et retourne UNIQUEMENT un JSON plat :

  - "type" : une des valeurs EXACTES suivantes (ou "unknown") :
      "rib"                - Relevé d'identité bancaire (IBAN, BIC)
      "cni"                - Carte nationale d'identité française
      "passeport"          - Passeport
      "justif_domicile"    - Facture EDF/Engie/eau, quittance loyer, avis d'imposition
      "carte_vitale"       - Carte Vitale ou attestation Ameli
      "mutuelle"           - Attestation de mutuelle / complémentaire santé
      "titre_sejour"       - Titre de séjour
      "permis"             - Permis de conduire (carte rose ou format CE carte-crédit)
      "carte_grise"        - Certificat d'immatriculation véhicule
      "assurance_vehicule" - Attestation d'assurance auto
      "unknown"            - Aucune catégorie ne correspond

  - "confidence" : entier 0-100

RÈGLES :
- N'utilise QUE les valeurs exactes de la liste.
- Retourne du JSON pur, pas de markdown.
SYS;

    try {
        // Détection = classification simple : on force gpt-4o-mini (rapide,
        // pas besoin du raisonnement gpt-5 qui consomme 16k tokens pour rien).
        $data = rhDxOpenAiJson($systemPrompt, 'Identifie ce document.', $visionImages, 'gpt-4o-mini');
        $type = strtolower(trim((string)($data['type'] ?? 'unknown')));
        $conf = (int)($data['confidence'] ?? 70);

        $allowedTypes = [
            'rib','cni','passeport','justif_domicile','carte_vitale',
            'mutuelle','titre_sejour','permis','carte_grise','assurance_vehicule','unknown',
        ];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'unknown';
            $conf = 0;
        }
        return ['type' => $type, 'confidence' => max(0, min(100, $conf))];
    } catch (Throwable $e) {
        error_log('[rhDxDetectTypeFromImage] ' . $e->getMessage());
        return ['type' => 'unknown', 'confidence' => 0];
    }
}

/**
 * Convertit un PDF en JPEG base64 (Vision fallback).
 * Réutilisable par les extracteurs qui ont besoin de Vision.
 */
function rhPdfToJpegBase64(string $pdfPath, int $maxPages = 2, int $dpi = 150): array
{
    // ── Tentative 1 : pdftoppm (Poppler CLI) ──────────────────
    $shellOk = function_exists('shell_exec')
        && !in_array('shell_exec', explode(',', ini_get('disable_functions')));

    if ($shellOk) {
        $pdftoppm = null;
        $candidates = [
            'C:\\poppler\\Library\\bin\\pdftoppm.exe',
            'C:\\Program Files\\poppler\\bin\\pdftoppm.exe',
            'C:\\poppler\\bin\\pdftoppm.exe',
            'pdftoppm',
        ];
        foreach ($candidates as $cand) {
            if ($cand === 'pdftoppm') { $pdftoppm = $cand; break; }
            if (is_file($cand))        { $pdftoppm = $cand; break; }
        }

        if ($pdftoppm) {
            $tmpBase = sys_get_temp_dir() . '/rhdx_pdf_' . bin2hex(random_bytes(6));
            @mkdir($tmpBase, 0755, true);
            $outPrefix = $tmpBase . '/page';

            $cmd = (PHP_OS_FAMILY === 'Windows' ? '"' . $pdftoppm . '"' : $pdftoppm)
                 . " -jpeg -r {$dpi} -l {$maxPages} "
                 . escapeshellarg($pdfPath) . ' '
                 . escapeshellarg($outPrefix)
                 . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
            @shell_exec($cmd);

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

            if (!empty($images)) {
                return $images;
            }
        }
    }

    // ── Tentative 2 : Imagick (PHP extension) ─────────────────
    if (extension_loaded('imagick')) {
        try {
            $images = [];
            $im = new \Imagick();
            $im->setResolution($dpi, $dpi);
            // Lire uniquement les N premières pages
            $pageRange = $maxPages > 1 ? '[0-' . ($maxPages - 1) . ']' : '[0]';
            $im->readImage($pdfPath . $pageRange);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);

            foreach ($im as $page) {
                $page->setImageFormat('jpeg');
                $blob = $page->getImageBlob();
                // Vérifier que c'est bien un JPEG (commence par FF D8 FF)
                if (strlen($blob) > 200 && substr($blob, 0, 3) === "\xFF\xD8\xFF") {
                    $images[] = 'data:image/jpeg;base64,' . base64_encode($blob);
                }
            }
            $im->clear();
            $im->destroy();

            if (!empty($images)) {
                return $images;
            }
        } catch (Throwable $e) {
            error_log('[rhPdfToJpegBase64] Imagick fallback failed: ' . $e->getMessage());
        }
    }

    // ── Tentative 3 : extraire les images JPEG embarquées dans le PDF (GD) ──
    $bin = @file_get_contents($pdfPath);
    if ($bin !== false && strlen($bin) > 100) {
        $images = [];
        $offset = 0;
        $len = strlen($bin);
        while ($offset < $len && count($images) < $maxPages) {
            $soi = strpos($bin, "\xFF\xD8\xFF", $offset);
            if ($soi === false) break;
            $eoi = false;
            $pos = $soi + 100;
            $searchEnd = min($soi + 5 * 1024 * 1024, $len);
            while ($pos < $searchEnd) {
                $found = strpos($bin, "\xFF\xD9", $pos);
                if ($found === false) break;
                $eoi = $found + 2;
                $pos = $found + 2;
            }
            if ($eoi === false) { $offset = $soi + 3; continue; }
            $jpeg = substr($bin, $soi, $eoi - $soi);
            if (strlen($jpeg) >= 2000) { // ignorer les miniatures
                $images[] = 'data:image/jpeg;base64,' . base64_encode($jpeg);
            }
            $offset = $eoi;
        }
        if (!empty($images)) {
            return $images;
        }
    }

    throw new RuntimeException('Conversion PDF→image échouée (ni Poppler, ni Imagick, ni images embarquées).');
}

/**
 * Appel générique OpenAI Chat Completions en mode JSON strict.
 *
 * @param string      $systemPrompt  Instructions système
 * @param string      $userText      Texte brut ou instruction utilisateur
 * @param array       $visionImages  Tableau de data:image/…;base64 (vide = texte seul)
 * @param string|null $modelOverride Modèle à forcer (ex: 'gpt-4o-mini' pour détection rapide)
 * @return array Décodé JSON (ou lève RuntimeException)
 */
function rhDxOpenAiJson(string $systemPrompt, string $userText, array $visionImages = [], ?string $modelOverride = null): array
{
    $apiKey = defined('OPENAI_API_KEY')
        ? OPENAI_API_KEY
        : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model = $modelOverride ?? (defined('OPENAI_TEXT_MODEL')
        ? OPENAI_TEXT_MODEL
        : ($GLOBALS['OPENAI_TEXT_MODEL'] ?? 'gpt-4o'));

    if (!$apiKey) {
        throw new RuntimeException('Clé OPENAI_API_KEY non configurée');
    }

    $isGpt5 = stripos((string)$model, 'gpt-5') !== false;

    if (!empty($visionImages)) {
        $content = [['type' => 'text', 'text' => $userText]];
        foreach ($visionImages as $dataUri) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $dataUri, 'detail' => 'high'],
            ];
        }
        $userMsg = ['role' => 'user', 'content' => $content];
    } else {
        $userMsg = ['role' => 'user', 'content' => $userText];
    }

    $payload = [
        'model'           => $model,
        'messages'        => [
            ['role' => 'system', 'content' => $systemPrompt],
            $userMsg,
        ],
        'response_format' => ['type' => 'json_object'],
    ];
    if ($isGpt5) {
        // GPT-5 = modèle de raisonnement : tokens reasoning invisibles avant le content.
        // Budget généreux obligatoire (sinon finish_reason=length, content vide).
        $payload['max_completion_tokens'] = 16000;
    } else {
        $payload['max_tokens']  = 1200;
        $payload['temperature'] = 0.0;
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
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Erreur curl : ' . $curlErr);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('OpenAI HTTP ' . $httpCode . ' : ' . substr((string)$response, 0, 200));
    }

    $api     = json_decode((string)$response, true);
    $content = (string)($api['choices'][0]['message']['content'] ?? '');

    if (trim($content) === '') {
        $finish = (string)($api['choices'][0]['finish_reason'] ?? '');
        throw new RuntimeException("Réponse IA vide (finish={$finish})");
    }

    // Strip éventuel markdown
    $content = preg_replace('/^\s*```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```\s*$/', '', $content);

    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException('Réponse IA non-JSON : ' . substr($content, 0, 200));
    }
    return $data;
}

/**
 * Extrait le texte d'un fichier (PDF ou image). Pour une image, utilise
 * OpenAI Vision via `rhDxOpenAiJson()` avec une requête OCR simple —
 * mais dans la pratique, les extracteurs préfèrent recevoir l'image en
 * base64 et la passer directement à leur propre appel Vision. Cette
 * fonction est donc surtout utile pour le texte PDF natif.
 *
 * @return array ['text' => string, 'needs_vision' => bool]
 */
function rhDxExtractText(string $filePath, string $mime): array
{
    $isPdf = $mime === 'application/pdf'
          || stripos($filePath, '.pdf') !== false;

    if ($isPdf) {
        $text = extractPdfText($filePath);
        $needsVision = strlen(trim($text)) < RHDX_TEXT_MIN_CHARS;
        return ['text' => $text, 'needs_vision' => $needsVision];
    }

    // Image : on ne fait pas d'OCR en PHP pur (pas de Tesseract garanti).
    // On signale juste au caller qu'il doit passer en mode Vision.
    return ['text' => '', 'needs_vision' => true];
}

/**
 * Charge dynamiquement un extracteur spécialisé et appelle sa fonction
 * `rhExtract_<type>($text, $visionImages, $filePath)`.
 *
 * Retourne un tableau standardisé :
 *   ['fields' => [...], 'score' => 0..100, 'engine' => 'regex|vision|hybrid']
 */
function rhDxRunExtractor(string $type, string $text, array $visionImages, string $filePath): array
{
    $extractorFile = __DIR__ . '/rh_extractors/' . $type . '.php';
    if (!is_file($extractorFile)) {
        return [
            'fields' => [],
            'score'  => 0,
            'engine' => 'none',
            'error'  => "Extracteur absent pour le type '{$type}'",
        ];
    }
    require_once $extractorFile;

    $fn = 'rhExtract_' . $type;
    if (!function_exists($fn)) {
        return [
            'fields' => [],
            'score'  => 0,
            'engine' => 'none',
            'error'  => "Fonction {$fn}() manquante dans {$extractorFile}",
        ];
    }
    return $fn($text, $visionImages, $filePath);
}

/**
 * Point d'entrée principal : analyse un document et retourne sa structure.
 *
 * @param string      $filePath  Chemin absolu du fichier
 * @param string      $mime      Type MIME (application/pdf, image/jpeg, …)
 * @param string|null $hintType  Si connu, force ce type (skip détection)
 */
function rhExtractDocument(string $filePath, string $mime, ?string $hintType = null): array
{
    $result = [
        'success'     => false,
        'doc_type'    => 'unknown',
        'confidence'  => 0,
        'engine'      => 'none',
        'fields'      => [],
        'validations' => [],
        'raw_text'    => '',
        'error'       => null,
    ];

    if (!is_file($filePath)) {
        $result['error'] = 'Fichier introuvable : ' . $filePath;
        return $result;
    }

    // 1. Extraction texte
    try {
        $ext = rhDxExtractText($filePath, $mime);
    } catch (Throwable $e) {
        $result['error'] = 'Extraction texte : ' . $e->getMessage();
        return $result;
    }
    $text         = $ext['text'];
    $needsVision  = $ext['needs_vision'];
    $visionImages = [];

    // 2. Si pas de texte exploitable → prépare Vision (PDF→JPEG ou image directe)
    if ($needsVision) {
        try {
            if (stripos($mime, 'image/') === 0 || preg_match('/\.(jpg|jpeg|png|webp|heic)$/i', $filePath)) {
                $bin = @file_get_contents($filePath);
                if ($bin !== false) {
                    $mimeReal = $mime !== '' ? $mime : 'image/jpeg';
                    $visionImages[] = 'data:' . $mimeReal . ';base64,' . base64_encode($bin);
                }
            } else {
                $visionImages = rhPdfToJpegBase64($filePath, 2, 150);
            }
            error_log('[rhExtractDocument] Vision images count: ' . count($visionImages));
        } catch (Throwable $e) {
            error_log('[rhExtractDocument] Vision error: ' . $e->getMessage());
            $result['error'] = 'Conversion Vision : ' . $e->getMessage();
            return $result;
        }
    }

    // 3. Détection du type
    if ($hintType !== null && $hintType !== '') {
        $docType    = $hintType;
        $confidence = 100; // forcé par l'utilisateur
    } else {
        // 3a. Tentative de détection regex sur le texte (gratuit)
        $det        = rhDetectDocumentType($text);
        $docType    = $det['type'];
        $confidence = $det['confidence'];

        // 3b. Si le texte n'a rien donné MAIS qu'on a des images
        //     → détection via Vision IA (image scannée / photo)
        if ($docType === 'unknown' && !empty($visionImages)) {
            $imgDet = rhDxDetectTypeFromImage($visionImages);
            if ($imgDet['type'] !== 'unknown') {
                $docType    = $imgDet['type'];
                $confidence = $imgDet['confidence'];
            }
        }
    }

    $result['doc_type']   = $docType;
    $result['confidence'] = $confidence;
    $result['raw_text']   = mb_substr($text, 0, 2000, 'UTF-8');

    if ($docType === 'unknown') {
        $result['error'] = 'Type de document non reconnu (ni par regex texte, ni par Vision IA)';
        return $result;
    }

    // 4. Appel de l'extracteur spécialisé
    try {
        $extracted = rhDxRunExtractor($docType, $text, $visionImages, $filePath);
    } catch (Throwable $e) {
        $result['error'] = 'Extracteur ' . $docType . ' : ' . $e->getMessage();
        return $result;
    }

    $result['fields']          = $extracted['fields']      ?? [];
    $result['validations']     = $extracted['validations'] ?? [];
    $result['engine']          = $extracted['engine']      ?? 'unknown';
    $result['extractor_score'] = (int)($extracted['score'] ?? 0);

    if (!empty($extracted['error'])) {
        $result['error'] = $extracted['error'];
        return $result;
    }

    $result['success'] = true;
    return $result;
}

/* ══════════════════════════════════════════════════════════════════════
   APPLICATION AU PROFIL USER
   Helper d'écriture des champs extraits dans la table users.
   Règle : ne JAMAIS écraser un champ déjà rempli (cf. spec). Les champs
   déjà remplis sont renvoyés en 'candidates' pour confirmation manuelle
   ultérieure.
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Mapping entre les clés retournées par les extracteurs et les colonnes
 * de la table `users`. Chaque entrée = [extractor_key => users_column].
 * Les clés absentes du mapping sont ignorées (pas écrites).
 */
function rhDxFieldMapping(): array
{
    return [
        // Doc type → [extractor_field => users_column]
        'rib' => [
            'iban'      => 'iban',
            'bic'       => 'bic',
        ],
        'cni' => [
            'nom'              => 'nom',
            'prenom'           => 'prenom',
            'date_naissance'   => 'date_naissance',
            'lieu_naissance'   => 'lieu_naissance',
            'nationalite'      => 'nationalite',
            'civilite'         => 'civilite',
        ],
        'passeport' => [
            'nom'              => 'nom',
            'prenom'           => 'prenom',
            'date_naissance'   => 'date_naissance',
            'lieu_naissance'   => 'lieu_naissance',
            'nationalite'      => 'nationalite',
            'civilite'         => 'civilite',
        ],
        'justif_domicile' => [
            'adresse'     => 'adresse',
            'code_postal' => 'code_postal',
            'ville'       => 'ville',
        ],
        'carte_vitale' => [
            'num_secu'       => 'num_secu',
            'nom'            => 'nom',
            'prenom'         => 'prenom',
            'date_naissance' => 'date_naissance',
        ],
        'carte_grise' => [
            'vehicule_marque'            => 'vehicule_nom',
            'vehicule_modele'            => 'vehicule_type',
            'vehicule_immat'             => 'vehicule_immat',
            'vehicule_puissance_fiscale' => 'vehicule_puissance_fiscale',
        ],
        'permis' => [
            'numero_permis'  => 'permis_conduire',
            'nom'            => 'nom',
            'prenom'         => 'prenom',
            'date_naissance' => 'date_naissance',
            'lieu_naissance' => 'lieu_naissance',
        ],
        'passeport' => [
            'nom'            => 'nom',
            'prenom'         => 'prenom',
            'date_naissance' => 'date_naissance',
            'lieu_naissance' => 'lieu_naissance',
            'nationalite'    => 'nationalite',
            'civilite'       => 'civilite',
        ],
        'mutuelle' => [
            // La table users n'a pas de colonne mutuelle dédiée.
            // On se contente de stocker l'extraction dans rh_user_documents.extracted_data
            // sans écriture directe en profil. Mapping vide = aucun champ appliqué.
        ],
        'titre_sejour' => [
            'nom'            => 'nom',
            'prenom'         => 'prenom',
            'date_naissance' => 'date_naissance',
            'nationalite'    => 'nationalite',
        ],
        'assurance_vehicule' => [
            // On écrit UNIQUEMENT l'immat si elle est absente, pour éviter
            // toute confusion avec les champs carte grise (assureur ≠ vehicule_nom).
            'vehicule_immat' => 'vehicule_immat',
        ],
    ];
}

/**
 * Applique les champs extraits à la fiche users, en respectant la règle
 * « ne jamais écraser un champ déjà rempli ». Les champs en conflit sont
 * renvoyés dans `candidates` pour décision manuelle.
 *
 * Cas particulier carte_grise :
 *   - si cv présent → calcul du taux IK via ik_bareme + indemnite_km
 *   - passe ik_enabled=1 si au moins un champ appliqué
 *
 * @return array ['applied' => [col=>val], 'candidates' => [col=>['old','new']], 'skipped' => [col=>reason]]
 */
function rhApplyExtractedToProfile(PDO $pdo, int $userId, string $docType, array $extractedFields): array
{
    $applied    = [];
    $candidates = [];
    $skipped    = [];

    $mapping = rhDxFieldMapping()[$docType] ?? [];
    if (empty($mapping)) {
        return ['applied' => [], 'candidates' => [], 'skipped' => [], 'reason' => "no_mapping_for_{$docType}"];
    }

    // 1. Récupère les valeurs actuelles des colonnes concernées
    $cols = array_values($mapping);
    // Certaines colonnes additionnelles à lire selon le type
    if ($docType === 'carte_grise') {
        $cols[] = 'indemnite_km';
        $cols[] = 'ik_enabled';
    }
    $cols = array_unique($cols);
    $colsSql = implode(',', array_map(fn($c) => "`{$c}`", $cols));

    $stmt = $pdo->prepare("SELECT {$colsSql} FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2. Pour chaque champ mappé, décide : apply / candidate / skip
    $sets   = [];
    $params = [];

    foreach ($mapping as $extractorKey => $usersCol) {
        if (!array_key_exists($extractorKey, $extractedFields)) continue;
        $newVal = $extractedFields[$extractorKey];
        if ($newVal === null || $newVal === '') {
            $skipped[$usersCol] = 'empty_extracted';
            continue;
        }

        $currentVal = $current[$usersCol] ?? null;
        if ($currentVal !== null && $currentVal !== '' && !rhDxIsPlaceholderValue($currentVal)) {
            // Champ déjà rempli → candidate (pas d'écrasement)
            if ((string)$currentVal !== (string)$newVal) {
                $candidates[$usersCol] = ['old' => $currentVal, 'new' => $newVal];
            } else {
                $skipped[$usersCol] = 'already_identical';
            }
            continue;
        }

        // Champ vide → application immédiate
        $sets[] = "`{$usersCol}` = ?";
        $params[] = $newVal;
        $applied[$usersCol] = $newVal;
    }

    // 3. Cas particulier carte grise : calcul taux IK et activation ik_enabled
    if ($docType === 'carte_grise' && !empty($applied)) {
        // Active ik_enabled si carte grise exploitée (l'user a bien un véhicule)
        if ((int)($current['ik_enabled'] ?? 0) !== 1) {
            $sets[] = "`ik_enabled` = ?";
            $params[] = 1;
            $applied['ik_enabled'] = 1;
        }
        if (empty($current['ik_declared_at'])) {
            $sets[] = "`ik_declared_at` = NOW()";
        }

        // Calcul indemnite_km via ik_bareme si cv présent
        $cv = null;
        if (!empty($extractedFields['vehicule_puissance_fiscale'])) {
            $cv = (int)preg_replace('/[^0-9]/', '', (string)$extractedFields['vehicule_puissance_fiscale']);
        }
        $isElectric = !empty($extractedFields['vehicule_carburant'])
            && stripos((string)$extractedFields['vehicule_carburant'], 'elec') !== false;

        if ($cv && (empty($current['indemnite_km']) || (float)$current['indemnite_km'] <= 0)) {
            if (!function_exists('ik_rate_per_km')) {
                @require_once __DIR__ . '/ik_bareme.php';
            }
            if (function_exists('ik_rate_per_km') && function_exists('ik_bareme_default_year')) {
                try {
                    $year = ik_bareme_default_year();
                    $rate = ik_rate_per_km($pdo, $cv, $year, $isElectric);
                    if ($rate !== null && $rate > 0) {
                        $sets[] = "`indemnite_km` = ?";
                        $params[] = round($rate, 4);
                        $applied['indemnite_km'] = round($rate, 4);
                    }
                } catch (Throwable $e) {
                    error_log('[rhApplyExtractedToProfile] ik_rate: ' . $e->getMessage());
                }
            }
        }
    }

    // 4. Exécution du UPDATE
    if (!empty($sets)) {
        $sql = "UPDATE users SET " . implode(', ', $sets)
             . ", date_modification = NOW() WHERE id = ?";
        $params[] = $userId;
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            error_log('[rhApplyExtractedToProfile] UPDATE failed: ' . $e->getMessage());
            return [
                'applied'    => [],
                'candidates' => $candidates,
                'skipped'    => $skipped,
                'error'      => $e->getMessage(),
            ];
        }
    }

    return [
        'applied'    => $applied,
        'candidates' => $candidates,
        'skipped'    => $skipped,
    ];
}

/**
 * Détecte si une valeur est un placeholder "équivalent à vide" (trait,
 * point d'interrogation, chaîne "N/A"…). Utilisé pour ne pas considérer
 * ces valeurs comme « déjà remplies ».
 */
function rhDxIsPlaceholderValue($v): bool
{
    if (!is_string($v)) return false;
    $t = trim($v);
    if ($t === '') return true;
    $lower = mb_strtolower($t, 'UTF-8');
    return in_array($lower, ['-', '—', '?', 'n/a', 'na', 'null', 'none', 'aucun'], true);
}
