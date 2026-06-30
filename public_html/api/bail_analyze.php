<?php
/**
 * api/bail_analyze.php — Analyse d'un bail PDF via IA (GPT-4o)
 * Extrait : locataire, loyer, charges, dates, type de révision, indice de référence
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ia_analyse.php';
require_login();
verify_csrf_any();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();

if (empty($_FILES['bail_pdf']['tmp_name']) || $_FILES['bail_pdf']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Fichier PDF requis.']); exit;
}

$tmp = $_FILES['bail_pdf']['tmp_name'];
if (mime_content_type($tmp) !== 'application/pdf') {
    echo json_encode(['ok' => false, 'error' => 'Le fichier doit être un PDF.']); exit;
}

// Extraire le texte
$text = extractPdfText($tmp);
if (strlen(trim($text)) < 100) {
    echo json_encode(['ok' => false, 'error' => 'Impossible d\'extraire le texte du bail. Vérifiez que le PDF n\'est pas un scan sans OCR.']); exit;
}

// Sauvegarder le PDF
$propId = (int)($_POST['id_proprietaire'] ?? 0);
$destDir = __DIR__ . '/../uploads/bailleur_docs/' . ($propId ?: 'tmp');
if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
$safeName = 'bail_' . date('Ymd_His') . '_' . uniqid() . '.pdf';
$destPath = $destDir . '/' . $safeName;
$relPath = 'uploads/bailleur_docs/' . ($propId ?: 'tmp') . '/' . $safeName;
move_uploaded_file($tmp, $destPath);

// Analyse IA
$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
$model = 'gpt-4o-mini'; // extraction structurée — reasoning models = JSON tronqué + lent

$text_truncated = mb_substr($text, 0, 40000);

$system_prompt = "Tu es un juriste expert en droit immobilier français, spécialisé en baux d'habitation (loi du 6 juillet 1989) et baux commerciaux. Tu analyses des baux et extrais toutes les données structurées. Tu réponds UNIQUEMENT en JSON valide.";

$user_prompt = <<<PROMPT
Analyse ce bail et extrais toutes les données structurées.

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
  "type_bail": "habitation|commercial|professionnel|meuble",
  "locataire": {
    "nom": "string (nom complet)",
    "prenom": "string ou null",
    "adresse_precedente": "string ou null"
  },
  "bailleur": {
    "nom": "string",
    "adresse": "string ou null"
  },
  "bien": {
    "adresse": "string (adresse complète du bien loué)",
    "type": "appartement|maison|local_commercial|bureau|autre",
    "surface": "number ou null (m²)",
    "nb_pieces": "number ou null",
    "etage": "string ou null",
    "description": "string (description courte)"
  },
  "conditions": {
    "loyer_mensuel_hc": "number (loyer hors charges en euros)",
    "charges_provisions": "number (provisions pour charges en euros)",
    "loyer_total_cc": "number (loyer charges comprises)",
    "depot_garantie": "number ou null",
    "date_signature": "string (format YYYY-MM-DD ou null)",
    "date_entree": "string (format YYYY-MM-DD — date d'effet/prise d'effet)",
    "duree_bail": "string (ex: 3 ans, 6 ans, 9 ans)",
    "date_fin_prevue": "string (format YYYY-MM-DD ou null)"
  },
  "revision": {
    "type": "IRL|ICC|ILAT|fixe|aucune",
    "trimestre_reference": "number (1-4) — trimestre de l'indice de référence mentionné",
    "annee_reference": "number — année de l'indice de référence",
    "indice_reference": "number ou null — valeur de l'indice mentionné dans le bail",
    "date_revision_annuelle": "string — date anniversaire de révision (format MM-DD ou texte)",
    "clause_revision_texte": "string — texte exact de la clause de révision si trouvé"
  },
  "clauses_particulieres": ["string — liste des clauses particulières notables"],
  "diagnostics_mentionnes": ["string — DPE, amiante, plomb, etc. mentionnés"]
}

Règles :
- Les montants sont en euros, sans symbole
- Si une information est absente du bail, utilise null
- Le type_bail se déduit du contexte (loi 89 = habitation, code de commerce = commercial)
- Pour la révision, cherche la mention de l'IRL, ICC ou ILAT et le trimestre/année de référence
- La date d'entrée est la date de prise d'effet, pas la date de signature

TEXTE DU BAIL :
{$text_truncated}
PROMPT;

$useNewParam = (bool)preg_match('/^(gpt-5|o1|o3|gpt-4\.1)/i', $model);
$payload = [
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $user_prompt],
    ],
    'response_format' => ['type' => 'json_object'],
];
if ($useNewParam) {
    $payload['max_completion_tokens'] = 4000;
} else {
    $payload['max_tokens']  = 4000;
    $payload['temperature'] = 0.1;
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 90,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Erreur API IA (HTTP ' . $httpCode . ')']); exit;
}

$result = json_decode($response, true);
$content = $result['choices'][0]['message']['content'] ?? '';
$parsed = json_decode($content, true);

if (!$parsed) {
    if (preg_match('/\{[\s\S]*\}/u', $content, $m)) $parsed = json_decode($m[0], true);
}
if (!$parsed) {
    echo json_encode(['ok' => false, 'error' => 'Analyse IA échouée.', 'raw' => substr($content, 0, 500)]); exit;
}

// Sauvegarder l'analyse en base
$cond = $parsed['conditions'] ?? [];
$rev = $parsed['revision'] ?? [];
$bien = $parsed['bien'] ?? [];
$loc = $parsed['locataire'] ?? [];

$stmt = $pdo->prepare("INSERT INTO bailleur_baux_analyses
    (id_proprietaire, locataire_nom, loyer_initial, charges_provisions, date_bail, date_entree,
     duree_bail, type_revision, trimestre_reference, annee_reference, indice_reference,
     depot_garantie, type_bail, adresse_bien, surface, fichier_bail, donnees_ia, uploaded_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$stmt->execute([
    $propId ?: null,
    trim(($loc['nom'] ?? '') . ' ' . ($loc['prenom'] ?? '')),
    $cond['loyer_mensuel_hc'] ?? null,
    $cond['charges_provisions'] ?? null,
    $cond['date_signature'] ?? null,
    $cond['date_entree'] ?? null,
    $cond['duree_bail'] ?? null,
    $rev['type'] ?? 'IRL',
    $rev['trimestre_reference'] ?? null,
    $rev['annee_reference'] ?? null,
    $rev['indice_reference'] ?? null,
    $cond['depot_garantie'] ?? null,
    $parsed['type_bail'] ?? 'habitation',
    $bien['adresse'] ?? null,
    $bien['surface'] ?? null,
    $relPath,
    json_encode($parsed, JSON_UNESCAPED_UNICODE),
    $userId,
]);
$analyseId = (int)$pdo->lastInsertId();

// Calculer la révision
$revisionResult = null;
$loyerActuel = (float)($cond['loyer_mensuel_hc'] ?? 0);
$typeRev = $rev['type'] ?? 'IRL';
$trimRef = (int)($rev['trimestre_reference'] ?? 0);
$anneeRef = (int)($rev['annee_reference'] ?? 0);
$indiceRef = (float)($rev['indice_reference'] ?? 0);

if ($loyerActuel > 0 && $typeRev === 'IRL' && $trimRef > 0) {
    // Chercher l'IRL actuel (même trimestre, année la plus récente)
    $stmtIrl = $pdo->prepare("SELECT valeur, annee FROM irl_indices WHERE trimestre = ? ORDER BY annee DESC LIMIT 1");
    $stmtIrl->execute([$trimRef]);
    $irlActuel = $stmtIrl->fetch(PDO::FETCH_ASSOC);

    if ($irlActuel && $indiceRef > 0) {
        $nouveauLoyer = round($loyerActuel * (float)$irlActuel['valeur'] / $indiceRef, 2);
        $augmentation = round($nouveauLoyer - $loyerActuel, 2);
        $pctAugmentation = round(($nouveauLoyer / $loyerActuel - 1) * 100, 2);
        $revisionResult = [
            'loyer_actuel' => $loyerActuel,
            'loyer_revise' => $nouveauLoyer,
            'augmentation' => $augmentation,
            'pct' => $pctAugmentation,
            'indice_ancien' => $indiceRef,
            'indice_nouveau' => (float)$irlActuel['valeur'],
            'annee_nouveau' => (int)$irlActuel['annee'],
            'trimestre' => $trimRef,
        ];
    }
}

echo json_encode([
    'ok' => true,
    'analyse_id' => $analyseId,
    'data' => $parsed,
    'revision' => $revisionResult,
    'fichier' => $relPath,
]);
