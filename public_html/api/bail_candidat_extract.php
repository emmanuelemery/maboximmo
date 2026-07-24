<?php
/**
 * api/bail_candidat_extract.php
 *
 * Extraction IA des documents du CANDIDAT locataire (KBIS, CNI, RIB, bilan, prévisionnel…)
 * pour pré-remplir le projet de bail. NON bloquant : renvoie les champs trouvés, l'utilisateur
 * garde la main. Réutilise extractPdfText() (OCR unique du projet) — aucune duplication OCR.
 *
 * POST multipart : document[] (1..n fichiers)
 * Réponse : { ok, fields:{ type, raison_sociale, siren, forme_juridique, nom, prenom,
 *   representant_nom, representant_qualite, email, telephone, activite, loyer_annuel_ht,
 *   capital_social }, sources:[noms fichiers lus], note }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ia_analyse.php';   // extractPdfText()
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
if (empty($_FILES['document'])) exit(json_encode(['ok'=>false,'error'=>'Aucun document']));

// ── Extraction texte d'un .docx / .xlsx (OOXML = zip) — best-effort, sans dépendance ──
function bce_ooxml_text(string $path, string $ext): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $parts = [];
    if ($ext === 'docx') {
        $xml = $zip->getFromName('word/document.xml');
        if ($xml !== false) $parts[] = $xml;
    } elseif ($ext === 'xlsx') {
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared !== false) $parts[] = $shared;
        for ($s = 1; $s <= 8; $s++) {
            $sheet = $zip->getFromName('xl/worksheets/sheet' . $s . '.xml');
            if ($sheet !== false) $parts[] = $sheet;
        }
    }
    $zip->close();
    if (!$parts) return '';
    // Balises de fin de paragraphe/ligne → espace ; puis strip des balises XML.
    $raw = implode("\n", $parts);
    $raw = preg_replace('#<(w:p|w:br|w:tab|row|c)\b[^>]*/?>#i', ' ', $raw);
    $txt = strip_tags($raw);
    $txt = html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $txt = preg_replace('/[ \t]+/', ' ', $txt);
    return trim((string)$txt);
}

// ── Classe chaque fichier : texte (PDF/Word/Excel) OU image (photo/scan → vision) ──
$texte = ''; $images = []; $sources = []; $skipped = [];
$IMG_EXT = ['jpg','jpeg','png','webp','gif','bmp','heic','heif'];
$IMG_MIME = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','bmp'=>'image/bmp','heic'=>'image/heic','heif'=>'image/heif'];
$files = $_FILES['document'];
$n = is_array($files['name']) ? count($files['name']) : 0;
for ($i=0; $i<$n; $i++) {
    if ((int)$files['error'][$i] !== 0) continue;
    $tmp = $files['tmp_name'][$i]; $name = (string)$files['name'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'pdf' && function_exists('extractPdfText')) {
        $t = (string)extractPdfText($tmp);
        if (strlen(trim($t)) >= 60) { $texte .= "\n\n=== " . $name . " ===\n" . $t; $sources[] = $name; }
        else { $skipped[] = $name . ' (PDF scanné sans texte — convertis-le en photo pour la lecture IA)'; }
    } elseif (in_array($ext, ['docx','xlsx'], true)) {
        $t = bce_ooxml_text($tmp, $ext);
        if (strlen(trim($t)) >= 20) { $texte .= "\n\n=== " . $name . " ===\n" . $t; $sources[] = $name; }
        else { $skipped[] = $name . ' (' . $ext . ' vide ou illisible)'; }
    } elseif (in_array($ext, ['doc','xls'], true)) {
        $skipped[] = $name . ' (ancien format ' . $ext . ' non lu — enregistre en .' . $ext . 'x ou en PDF)';
    } elseif (in_array($ext, $IMG_EXT, true) && count($images) < 8) {
        $bytes = @file_get_contents($tmp);
        if ($bytes !== false && strlen($bytes) <= 12 * 1024 * 1024) {
            $mime = $IMG_MIME[$ext] ?? 'image/jpeg';
            $images[] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            $sources[] = $name;
        } else {
            $skipped[] = $name . ' (image > 12 Mo — réduis-la)';
        }
    } else {
        $skipped[] = $name . ' (' . ($ext ?: 'type inconnu') . ' — non pris en charge)';
    }
}
if (trim($texte) === '' && !$images) {
    exit(json_encode(['ok'=>false,'error'=>'Aucun document exploitable','skipped'=>$skipped], JSON_UNESCAPED_UNICODE));
}

$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
if (!$api_key) exit(json_encode(['ok'=>false,'error'=>'Clé OpenAI absente'], JSON_UNESCAPED_UNICODE));

$texte = mb_substr($texte, 0, 40000);
$system = "Tu es un assistant juridique qui prépare un bail commercial. À partir des documents d'un candidat locataire (extrait KBIS, pièce d'identité, RIB, bilan, prévisionnel d'activité), fournis en texte et/ou en photos/scans, tu extrais l'identité du preneur et, si présents, les éléments financiers. Réponds UNIQUEMENT en JSON valide.";
$user = <<<PROMPT
Extrais les informations du CANDIDAT LOCATAIRE (le futur preneur du bail), au format JSON strict :
{
  "type": "societe|physique",
  "raison_sociale": "string ou null",
  "forme_juridique": "string ou null (SAS, SARL, SASU…)",
  "siren": "string ou null (9 chiffres)",
  "capital_social": "number ou null",
  "nom": "string ou null (si personne physique)",
  "prenom": "string ou null",
  "representant_nom": "string ou null (dirigeant / gérant / président)",
  "representant_qualite": "string ou null (Gérant, Président…)",
  "email": "string ou null",
  "telephone": "string ou null",
  "adresse": "string ou null (adresse complète : domicile si personne physique, siège si société)",
  "date_naissance": "string ou null (AAAA-MM-JJ, si personne physique — depuis CNI/passeport)",
  "lieu_naissance": "string ou null (ville et département de naissance)",
  "nationalite": "string ou null (si personne physique)",
  "activite": "string ou null (activité exercée / destination envisagée)",
  "loyer_annuel_ht": "number ou null (loyer envisagé si un prévisionnel le mentionne)"
}
Règles : si l'info est absente, mets null. Montants en euros sans symbole. Dates au format AAAA-MM-JJ. Ne renvoie que le preneur, jamais le bailleur ni le notaire ni l'agence.

DOCUMENTS DU CANDIDAT (texte) :
{$texte}
PROMPT;

// Contenu utilisateur : texte + images (vision). image_url en data URI base64.
$userContent = [['type'=>'text','text'=>$user]];
foreach ($images as $dataUri) {
    $userContent[] = ['type'=>'image_url','image_url'=>['url'=>$dataUri, 'detail'=>'high']];
}
$payload = ['model'=>'gpt-4o-mini','messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$userContent]],
    'response_format'=>['type'=>'json_object'],'max_tokens'=>1500,'temperature'=>0.1];
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$api_key],
    CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_TIMEOUT=>90]);
$resp = curl_exec($ch); $code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code !== 200) exit(json_encode(['ok'=>false,'error'=>'IA HTTP '.$code,'sources'=>$sources], JSON_UNESCAPED_UNICODE));
$res = json_decode((string)$resp, true);
$content = $res['choices'][0]['message']['content'] ?? '';
$fields = json_decode((string)$content, true);
if (!$fields && preg_match('/\{[\s\S]*\}/u', (string)$content, $m)) $fields = json_decode($m[0], true);
if (!is_array($fields)) exit(json_encode(['ok'=>false,'error'=>'Réponse IA non parsable','sources'=>$sources], JSON_UNESCAPED_UNICODE));

// ── Écriture dans la FICHE du candidat (tiers) — non-destructif ──
// Si un bail_id est fourni, on résout son tiers candidat et on complète sa fiche : société (KBIS)
// → infos juridiques ; personne physique (CNI) → nom/prénom/naissance/nationalité.
$applied = null;
$bailId = (int)($_POST['bail_id'] ?? 0);
if ($bailId > 0 && is_array($fields)) {
    try {
        $pdo = $GLOBALS['pdo'];
        require_once dirname(__DIR__) . '/inc/tiers_apply_extracted.php';
        $qt = $pdo->prepare("SELECT candidat_tiers_id FROM bien_baux WHERE id=? LIMIT 1");
        $qt->execute([$bailId]); $tiersId = (int)($qt->fetchColumn() ?: 0);
        if ($tiersId > 0) {
            $ty = (($fields['type'] ?? '') === 'physique') ? 'physique' : 'societe';
            $hasSoc = trim((string)($fields['raison_sociale'] ?? '')) !== '' || (preg_replace('/\D+/', '', (string)($fields['siren'] ?? '')) ?? '') !== '';
            $hasPer = trim((string)($fields['nom'] ?? '')) !== '' || trim((string)($fields['prenom'] ?? '')) !== '';
            if ($ty === 'societe' && $hasSoc)       $applied = apply_societe_extracted_to_tiers($pdo, $tiersId, $fields);
            elseif ($ty === 'physique' && $hasPer)  $applied = apply_personne_extracted_to_tiers($pdo, $tiersId, $fields);
        }
    } catch (Throwable $e) { error_log('[bail_candidat_extract apply] ' . $e->getMessage()); }
}

echo json_encode([
    'ok'      => true,
    'fields'  => $fields,
    'sources' => $sources,
    'skipped' => $skipped,
    'applied' => $applied,
    'note'    => count($sources) . ' document(s) lu(s)' . ($skipped ? ' · ' . count($skipped) . ' ignoré(s)' : '') . ($applied && !empty($applied['ok']) ? ' · fiche candidat complétée' : ''),
], JSON_UNESCAPED_UNICODE);
