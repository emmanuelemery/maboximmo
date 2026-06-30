<?php
/**
 * api/creancier_frais_extract.php — Extraction IA des FRAIS depuis un décompte huissier / jugement.
 *
 * Upload d'un PDF → extraction texte → IA (gpt-4o-mini, json_object) qui isole les lignes
 * financières (honoraires avocat, frais huissier/commissaire, frais de procédure, versements).
 * NE PERSISTE RIEN : renvoie des propositions à valider. L'insertion dans creancier_mouvement
 * se fait ensuite via api/creancier_mouvement_action.php (action=add_bulk) après revue humaine.
 *
 * POST : id_dossier, doc (PDF), csrf_token ('creancier_frais').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ia_analyse.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_frais');

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$idDossier = (int)($_POST['id_dossier'] ?? 0);
if ($idDossier <= 0 || !creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès dossier refusé']); exit;
}

if (empty($_FILES['doc']['tmp_name']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'Fichier PDF requis (champ "doc").']); exit;
}
$tmp = $_FILES['doc']['tmp_name'];
if (function_exists('mime_content_type') && mime_content_type($tmp) !== 'application/pdf') {
    echo json_encode(['ok'=>false,'error'=>'Le fichier doit être un PDF.']); exit;
}
if (!function_exists('extractPdfText')) { echo json_encode(['ok'=>false,'error'=>'Extracteur PDF indisponible.']); exit; }
$text = extractPdfText($tmp);
if (strlen(trim($text)) < 80) { echo json_encode(['ok'=>false,'error'=>"Texte non extractible (PDF scanné sans OCR ?)."]); exit; }

$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
if (!$api_key) { echo json_encode(['ok'=>false,'error'=>'Clé API OpenAI non configurée.']); exit; }
$model = defined('OPENAI_EXTRACT_MODEL') ? OPENAI_EXTRACT_MODEL : 'gpt-4o-mini';
$textT = mb_substr($text, 0, 45000);

$system = "Tu es un expert des frais de procédure civile d'exécution française (décomptes de "
        . "commissaire de justice/huissier, jugements, factures d'avocat). Tu extrais les LIGNES "
        . "FINANCIÈRES d'un document. Tu réponds UNIQUEMENT en JSON valide.";

$user = <<<PROMPT
Lis ce document et extrais TOUTES les lignes de frais / honoraires / versements identifiables.
Classe chaque ligne dans l'un de ces types :
- "honoraire_avocat"   : honoraires / émoluments d'avocat, facture d'avocat
- "frais_huissier"     : émoluments, droits et débours du commissaire de justice / huissier
- "frais_procedure"    : frais de greffe, droit de plaidoirie, frais d'expertise, dépens, autres frais de procédure
- "versement_creancier": somme versée/réglée AU créancier (acompte, paiement partiel de la dette)
- "autre"              : tout le reste pertinent

Réponds UNIQUEMENT en JSON avec cette structure exacte :
{
  "lignes": [
    {
      "type": "honoraire_avocat|frais_huissier|frais_procedure|versement_creancier|autre",
      "libelle": "string court (intitulé de la ligne)",
      "montant": number,            // euros TTC si dispo, nombre seul
      "date": "YYYY-MM-DD ou null", // date de la pièce/règlement si présente
      "beneficiaire": "string ou null (cabinet/étude/créancier)",
      "reference": "string ou null (n° facture/pièce/acte)"
    }
  ],
  "total_detecte": number
}

Règles :
- Montants en euros, nombres seuls (pas de symbole ni d'espace, point décimal).
- N'invente RIEN : si un champ est absent, mets null. Si aucune ligne, "lignes": [].
- Préfère le TTC quand le document distingue HT/TVA/TTC.

TEXTE DU DOCUMENT :
---
{$textT}
---
PROMPT;

$payload = [
    'model' => $model,
    'messages' => [
        ['role'=>'system','content'=>$system],
        ['role'=>'user','content'=>$user],
    ],
    'response_format' => ['type'=>'json_object'],
    'max_tokens' => 2000,
    'temperature' => 0.1,
];
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$api_key],
    CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_TIMEOUT=>90,
]);
$resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code !== 200) { echo json_encode(['ok'=>false,'error'=>'Erreur API IA (HTTP '.$code.').']); exit; }

$res = json_decode((string)$resp, true);
$content = $res['choices'][0]['message']['content'] ?? '';
$parsed = json_decode($content, true);
if (!$parsed && preg_match('/\{[\s\S]*\}/u', $content, $m)) $parsed = json_decode($m[0], true);
if (!is_array($parsed)) { echo json_encode(['ok'=>false,'error'=>'Analyse IA illisible.']); exit; }

$VALID = ['honoraire_avocat','frais_huissier','frais_procedure','versement_creancier','autre'];
$lignes = [];
foreach (($parsed['lignes'] ?? []) as $l) {
    $montant = is_numeric($l['montant'] ?? null) ? round((float)$l['montant'], 2) : 0.0;
    if ($montant <= 0) continue;
    $type = in_array(($l['type'] ?? ''), $VALID, true) ? $l['type'] : 'autre';
    $date = (isset($l['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$l['date'])) ? $l['date'] : null;
    $lignes[] = [
        'type'         => $type,
        'libelle'      => mb_substr(trim((string)($l['libelle'] ?? '')), 0, 190),
        'montant'      => $montant,
        'date'         => $date,
        'beneficiaire' => trim((string)($l['beneficiaire'] ?? '')) ?: null,
        'reference'    => trim((string)($l['reference'] ?? '')) ?: null,
    ];
}

$tokensIn = (int)($res['usage']['prompt_tokens'] ?? 0);
$tokensOut= (int)($res['usage']['completion_tokens'] ?? 0);
$costEur  = round((($tokensIn/1e6)*0.150 + ($tokensOut/1e6)*0.600)*0.92, 4);

echo json_encode([
    'ok'       => true,
    'lignes'   => $lignes,
    'count'    => count($lignes),
    'cost_eur' => $costEur,
], JSON_UNESCAPED_UNICODE);
