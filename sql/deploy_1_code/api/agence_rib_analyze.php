<?php
declare(strict_types=1);
set_time_limit(90);

/**
 * POST /api/agence_rib_analyze.php
 *
 * Analyse un fichier RIB uploadé (PDF ou image) via GPT-4o/5 Vision et
 * retourne les 4 champs normalisés : titulaire_compte, banque_nom, iban, bic.
 *
 * Le fichier est passé en multipart (pas stocké en base). L'appelant (JS de
 * societe.php) récupère le JSON et remplit directement les inputs du form
 * d'agence — l'auto-save existant (debounce 800ms) se charge de persister
 * les valeurs dans `agences`.
 *
 * Paramètres POST (multipart/form-data) :
 *   - csrf_token  : token 'rh_societe'
 *   - id_agence   : vérifie que l'agence appartient à la société courante
 *   - rib_file    : fichier RIB (PDF, JPG, PNG, WEBP)
 *
 * Réponse JSON :
 *   {
 *     ok: true,
 *     extracted: { titulaire, banque, iban, bic },
 *     vision_mode: bool
 *   }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ia_analyse.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/**
 * Réutilise le helper PDF→JPEG déjà défini dans societe_doc_analyze.php
 * (duplication légère pour éviter un `require_once` qui exécuterait tout
 * l'endpoint d'analyse de doc).
 */
function rib_pdf_to_jpeg_base64(string $pdfPath, int $maxPages = 2, int $dpi = 150): array
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
    if (!$pdftoppm) throw new RuntimeException('pdftoppm introuvable (Poppler non installé).');

    $tmpBase = sys_get_temp_dir() . '/rib_pdf_' . bin2hex(random_bytes(6));
    @mkdir($tmpBase, 0755, true);
    $outPrefix = $tmpBase . '/page';

    $cmd = (PHP_OS_FAMILY === 'Windows' ? '"' . $pdftoppm . '"' : $pdftoppm)
         . " -jpeg -r {$dpi} -l {$maxPages} "
         . escapeshellarg($pdfPath) . ' '
         . escapeshellarg($outPrefix)
         . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    @shell_exec($cmd);

    $images = [];
    $gen = glob($tmpBase . '/page-*.jpg') ?: [];
    sort($gen);
    foreach (array_slice($gen, 0, $maxPages) as $img) {
        $bin = @file_get_contents($img);
        if ($bin !== false && strlen($bin) > 200) {
            $images[] = 'data:image/jpeg;base64,' . base64_encode($bin);
        }
        @unlink($img);
    }
    @rmdir($tmpBase);

    if (empty($images)) throw new RuntimeException('Conversion PDF→JPEG échouée.');
    return $images;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']); exit;
    }
    verify_csrf_any('rh_societe');

    $pdo          = db();
    $isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : false;
    $mySoc        = function_exists('current_societe_id') ? (int)current_societe_id() : 0;
    if ($mySoc <= 0) $mySoc = (int)($_SESSION['id_societe'] ?? 0);
    $socOverride  = $isSuperAdmin && isset($_POST['soc_override']) ? (int)$_POST['soc_override'] : 0;
    $societeId    = $socOverride > 0 ? $socOverride : $mySoc;

    $agenceId = (int)($_POST['id_agence'] ?? 0);
    if ($agenceId <= 0) throw new RuntimeException('id_agence manquant');

    // Vérifie que l'agence appartient à la société en session (ou à la
    // société consultée par le super-admin).
    $chk = $pdo->prepare("SELECT id FROM agences WHERE id = ? AND id_societe = ? LIMIT 1");
    $chk->execute([$agenceId, $societeId]);
    if (!$chk->fetchColumn()) {
        throw new RuntimeException("Agence #{$agenceId} introuvable ou hors-périmètre (société #{$societeId}).");
    }

    // Fichier uploadé
    if (empty($_FILES['rib_file']) || $_FILES['rib_file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['rib_file']['error'] ?? 'absent';
        throw new RuntimeException("Fichier RIB manquant (upload err={$err})");
    }
    $file = $_FILES['rib_file'];
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Fichier trop volumineux (max 8 Mo)');
    }

    $mime = (string)($file['type'] ?? '');
    $tmp  = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload invalide');
    }

    // ─── Extraction texte ou fallback Vision ────────────────────────
    $text         = '';
    $visionImages = [];

    $isPdf = $mime === 'application/pdf'
           || stripos($file['name'], '.pdf') !== false;

    if ($isPdf) {
        $text = extractPdfText($tmp);
        if (trim($text) === '' || strlen(trim($text)) < 30) {
            $visionImages = rib_pdf_to_jpeg_base64($tmp, 2, 150);
            $text = '';
        }
    } elseif (str_starts_with($mime, 'image/')) {
        $bin = @file_get_contents($tmp);
        if ($bin === false) throw new RuntimeException('Image illisible');
        $visionImages = ['data:' . $mime . ';base64,' . base64_encode($bin)];
    } else {
        throw new RuntimeException("Type de fichier non supporté : {$mime}");
    }
    $visionMode = !empty($visionImages);

    // ─── Prompt IA ──────────────────────────────────────────────────
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model  = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4o';
    if (!$apiKey) throw new RuntimeException('Clé OPENAI_API_KEY non configurée');

    $systemPrompt = <<<SYS
Tu es un expert en RIB / IBAN français. Tu extrais les informations
bancaires présentes dans le document et retournes UNIQUEMENT un objet
JSON plat avec ces 4 clés exactes :

  - "titulaire" : nom exact du titulaire du compte tel qu'il apparaît
  - "banque"    : nom de la banque ou de l'établissement (ex: "BNP Paribas",
                  "Crédit Agricole Centre-Est", "Banque Populaire AURA")
  - "iban"      : IBAN complet SANS ESPACES (ex: "FR7630004000031234567890143")
  - "bic"       : code BIC/SWIFT (ex: "BNPAFRPP" ou "AGRIFRPP881")

RÈGLES STRICTES :
- Omets toute clé dont la valeur n'est pas clairement présente dans le document.
- N'INVENTE JAMAIS de valeur. Si tu n'es pas sûr d'un caractère, n'émets pas la clé.
- IBAN : vérifier qu'il commence par FR (France) ou autre code pays ISO,
  suivi de 25 caractères (27 au total pour FR). Retire TOUS les espaces.
- BIC : 8 ou 11 caractères majuscules/chiffres, pas d'espaces.
- Retourne du JSON pur, pas de markdown, pas de ```json fences.
SYS;

    $userText = trim($text) !== ''
        ? "Voici le texte extrait d'un RIB français :\n\n--- DÉBUT ---\n{$text}\n--- FIN ---\n\nRetourne le JSON."
        : "Voici un scan de RIB français. Lis attentivement et retourne le JSON avec titulaire, banque, iban (sans espaces), bic.";

    $isGpt5 = stripos((string)$model, 'gpt-5') !== false;

    if ($visionMode) {
        $userContent = [['type' => 'text', 'text' => $userText]];
        foreach ($visionImages as $dataUri) {
            $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => 'high']];
        }
        $userMsg = ['role' => 'user', 'content' => $userContent];
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
        // GPT-5 consomme des tokens de reasoning invisibles avant le content.
        // Il faut une enveloppe généreuse même pour un petit JSON de 4 clés,
        // sinon finish_reason=length et content vide.
        $payload['max_completion_tokens'] = 8000;
    } else {
        $payload['max_tokens']  = 500;
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

    if ($response === false) throw new RuntimeException('Erreur curl : ' . $curlErr);
    if ($httpCode !== 200)   throw new RuntimeException('OpenAI HTTP ' . $httpCode . ' : ' . substr((string)$response, 0, 200));

    $api          = json_decode((string)$response, true);
    $content      = (string)($api['choices'][0]['message']['content'] ?? '');
    $finishReason = (string)($api['choices'][0]['finish_reason'] ?? '');
    $usage        = $api['usage'] ?? [];

    error_log('[agence_rib_analyze] finish=' . $finishReason
              . ' usage=' . json_encode($usage)
              . ' content_len=' . strlen($content));

    if (trim($content) === '') {
        throw new RuntimeException("L'IA n'a retourné aucun contenu (finish={$finishReason})");
    }

    // Strip markdown au cas où
    $content = preg_replace('/^\s*```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```\s*$/', '', $content);

    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException("Réponse IA non-JSON : " . substr($content, 0, 200));
    }

    // ─── Normalisation ──────────────────────────────────────────────
    $clean = static fn($v) => is_string($v) ? trim($v) : $v;

    $titulaire = $clean($data['titulaire'] ?? '');
    $banque    = $clean($data['banque']    ?? '');
    $iban      = strtoupper((string)($data['iban'] ?? ''));
    $iban      = preg_replace('/\s+/', '', $iban); // retire les espaces
    $bic       = strtoupper((string)($data['bic'] ?? ''));
    $bic       = preg_replace('/\s+/', '', $bic);

    // Validation légère IBAN FR (format uniquement, pas checksum)
    $ibanValid = $iban === '' || preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban);
    // Validation BIC (8 ou 11 caractères alphanumériques)
    $bicValid  = $bic === ''  || preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic);

    $extracted = array_filter([
        'titulaire' => $titulaire !== '' ? $titulaire : null,
        'banque'    => $banque    !== '' ? $banque    : null,
        'iban'      => $ibanValid && $iban !== '' ? $iban : null,
        'bic'       => $bicValid  && $bic  !== '' ? $bic  : null,
    ], static fn($v) => $v !== null);

    echo json_encode([
        'ok'           => true,
        'id_agence'    => $agenceId,
        'extracted'    => $extracted,
        'fields_count' => count($extracted),
        'vision_mode'  => $visionMode,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
