<?php
declare(strict_types=1);
set_time_limit(120);

/**
 * POST /api/admin_doc_analyze.php
 *
 * Analyse un document administratif via GPT-4o (texte ou vision).
 * Retourne un résumé + champs extraits, stockés dans analysis_json.
 * Accepte les documents société, agence OU utilisateur.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/ia_analyse.php';

/**
 * Convertit un PDF en JPEG(s) base64 via pdftoppm (Poppler).
 */
function pdfToJpegBase64(string $pdfPath, int $maxPages = 4, int $dpi = 150): array
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
    if (!$pdftoppm) return [];

    $tmpBase = sys_get_temp_dir() . '/adoc_pdf_' . bin2hex(random_bytes(6));
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
    return $images;
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Non connecté']); exit;
}

// CSRF
$sessionToken = $_SESSION['_csrf_admin_documents'] ?? '';
$clientToken  = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$sessionToken || !$clientToken || !hash_equals($sessionToken, (string)$clientToken)) {
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide']); exit;
}

$roleId = function_exists('current_role_id') ? current_role_id() : 0;
if (!in_array($roleId, [1, 7, 8], true)) {
    echo json_encode(['ok' => false, 'error' => 'Accès réservé aux administrateurs']); exit;
}

$pdo       = db();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$docId     = (int)($_POST['doc_id'] ?? 0);

if ($docId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'doc_id manquant']); exit;
}

try {
    // Charger le document (même société)
    $stmt = $pdo->prepare("
        SELECT id, id_societe, type_document, nom_fichier, chemin_fichier, mime_type, titre
        FROM documents WHERE id = ? AND id_societe = ? LIMIT 1
    ");
    $stmt->execute([$docId, $societeId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        throw new RuntimeException("Document #{$docId} introuvable ou hors périmètre.");
    }

    $filepath = dirname(__DIR__) . '/' . ltrim((string)$doc['chemin_fichier'], '/');
    if (!is_file($filepath)) {
        throw new RuntimeException('Fichier absent sur le disque : ' . $doc['chemin_fichier']);
    }

    // ── Extraction texte / vision ──
    $mime = (string)$doc['mime_type'];
    $text = '';
    $visionImages = [];

    if ($mime === 'application/pdf' || str_ends_with(strtolower($doc['nom_fichier']), '.pdf')) {
        $text = function_exists('extractPdfText') ? extractPdfText($filepath) : '';
        if (trim($text) === '' || strlen(trim($text)) < 50) {
            $visionImages = pdfToJpegBase64($filepath, 4, 150);
            $text = '';
        }
    } elseif (str_starts_with($mime, 'image/')) {
        $bin = @file_get_contents($filepath);
        if ($bin !== false) {
            $visionImages = ['data:' . $mime . ';base64,' . base64_encode($bin)];
        }
    } else {
        // Tentative extraction texte brut
        $raw = (string)@file_get_contents($filepath);
        $text = preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '';
        $text = mb_substr(trim($text), 0, 30000);
    }

    $visionMode = !empty($visionImages);

    // ── Appel ChatGPT ──
    // Config paths pour les constantes
    $configPaths = [
        '/home/u630423897/maboximmo_openai_config.php',
        dirname(__DIR__, 2) . '/u630423897/maboximmo_openai_config.php',
    ];
    foreach ($configPaths as $cp) {
        if (is_file($cp)) { @include_once $cp; break; }
    }

    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    $model  = 'gpt-4o'; // Vision requis
    if (!$apiKey) throw new RuntimeException('Clé OPENAI_API_KEY non configurée');

    $systemPrompt = <<<SYS
Tu es un assistant spécialisé dans l'analyse de documents administratifs français.
Analyse le document fourni et retourne un JSON avec les clés suivantes :
- "resume" : un résumé concis du document en 2-3 phrases (nature, émetteur, dates clés, objet principal)
- "type_detecte" : le type de document détecté parmi : kbis, assurance_rcp, assurance_locative, carte_professionnelle, garantie_financiere, rib, inscription_insee, bail_commercial, contrat, attestation, facture, autre
- "emetteur" : organisme ou personne ayant émis le document
- "destinataire" : personne ou entité à qui le document est adressé
- "date_document" : date du document au format YYYY-MM-DD (null si non trouvée)
- "date_expiration" : date d'expiration au format YYYY-MM-DD (null si non applicable)
- "numero_reference" : numéro de référence, police, contrat, etc.
- "montant" : montant principal en euros (null si non applicable)
- "champs_extraits" : objet libre avec les informations clés extraites du document

Retourne UNIQUEMENT le JSON, sans markdown ni commentaire.
SYS;

    if ($visionMode) {
        $content = [];
        $content[] = ['type' => 'text', 'text' => "Analyse ce document administratif. Titre connu : " . ($doc['titre'] ?: $doc['nom_fichier'])];
        foreach ($visionImages as $img) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $img, 'detail' => 'high']];
        }
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $content],
        ];
    } else {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Analyse ce document administratif.\nTitre : " . ($doc['titre'] ?: $doc['nom_fichier']) . "\n\nContenu :\n" . mb_substr($text, 0, 30000)],
        ];
    }

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => 2000,
        'temperature' => 0.1,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Erreur cURL : ' . $curlErr);
    }
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? "HTTP {$httpCode}";
        throw new RuntimeException('Erreur OpenAI : ' . $errMsg);
    }

    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '';

    // Nettoyer le JSON (enlever ```json ... ```)
    $content = trim($content);
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);

    $analysisData = json_decode($content, true);
    if (!is_array($analysisData)) {
        throw new RuntimeException('Réponse IA non parsable : ' . mb_substr($content, 0, 200));
    }

    // ── Sauvegarder l'analyse ──
    $fieldsCount = 0;
    foreach ($analysisData as $v) {
        if ($v !== null && $v !== '') $fieldsCount++;
    }

    $pdo->prepare("
        UPDATE documents
        SET analysis_json = ?, analyzed_at = NOW(), analysis_fields_filled = ?, analysis_error = NULL
        WHERE id = ?
    ")->execute([json_encode($analysisData, JSON_UNESCAPED_UNICODE), $fieldsCount, $docId]);

    // Mettre à jour type_document si détecté et différent de 'autre'
    $typeDetecte = $analysisData['type_detecte'] ?? '';
    if ($typeDetecte !== '' && $typeDetecte !== 'autre' && ($doc['type_document'] === 'autre' || $doc['type_document'] === '')) {
        $pdo->prepare("UPDATE documents SET type_document = ? WHERE id = ?")->execute([$typeDetecte, $docId]);
    }

    // Mettre à jour date_expiration si détectée
    if (!empty($analysisData['date_expiration'])) {
        $pdo->prepare("UPDATE documents SET date_expiration = ? WHERE id = ?")->execute([$analysisData['date_expiration'], $docId]);
    }

    echo json_encode([
        'ok'            => true,
        'doc_id'        => $docId,
        'fields_filled' => $fieldsCount,
        'analysis'      => $analysisData,
        'resume'        => $analysisData['resume'] ?? '',
    ]);

} catch (Throwable $e) {
    // Persister l'erreur
    if ($docId > 0) {
        $pdo->prepare("UPDATE documents SET analysis_error = ?, analyzed_at = NOW() WHERE id = ?")
            ->execute([mb_substr($e->getMessage(), 0, 500), $docId]);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
