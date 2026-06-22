<?php
/**
 * api/creancier_doc_analyze.php — Analyse IA d'un document créancier (PDF).
 *
 * Réutilise le pipeline existant : extractPdfText() + OpenAI gpt-4o-mini (json_object),
 * sur le modèle de api/bail_analyze.php. NE TOUCHE PAS la GED (ged_documents) : l'extraction
 * est stockée dans creancier_doc_analyse (statut='a_valider'), pour validation humaine.
 *
 * Extrait : type de doc (conclusions/jugement/commandement/correspondance/mail/acte_saisie),
 * créancier, professionnel (avocat/huissier), n° de dossier, montants, objet/cause, dates.
 *
 * Anti-doublon : matching du créancier extrait sur les tiers existants (propose un lien,
 * ne crée rien). Comparaison inter-dossiers : rapproche par n° de dossier les analyses déjà
 * en base pour signaler doublons / incohérences de montants (review_flags).
 *
 * Le COMMIT GED + création/màj tiers + alimentation creancier_saisie se font à la VALIDATION
 * (endpoint séparé), pas ici.
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

// Tenant de l'utilisateur (filtrage multi-tenant d'abord).
$stU = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ? LIMIT 1");
$stU->execute([$userId]);
$u = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
$idSociete = isset($u['id_societe']) ? (int)$u['id_societe'] : null;
$idAgence  = isset($u['id_agence'])  ? (int)$u['id_agence']  : null;

$idDossier = (int)($_POST['id_dossier'] ?? 0) ?: null;

// ── Fichier ───────────────────────────────────────────────────────────
if (empty($_FILES['doc']['tmp_name']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Fichier PDF requis (champ "doc").']); exit;
}
$tmp = $_FILES['doc']['tmp_name'];
if (mime_content_type($tmp) !== 'application/pdf') {
    echo json_encode(['ok' => false, 'error' => 'Le fichier doit être un PDF.']); exit;
}

$text = extractPdfText($tmp);
if (strlen(trim($text)) < 100) {
    echo json_encode(['ok' => false, 'error' => "Impossible d'extraire le texte (PDF scanné sans OCR ?)."]); exit;
}

// ── Appel IA (gpt-4o-mini, json_object) ─────────────────────────────────
$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
if (!$api_key) {
    echo json_encode(['ok' => false, 'error' => 'Clé API OpenAI non configurée.']); exit;
}
$model = defined('OPENAI_EXTRACT_MODEL') ? OPENAI_EXTRACT_MODEL : 'gpt-4o-mini';
$text_truncated = mb_substr($text, 0, 45000);

$system_prompt = "Tu es un juriste expert en procédures civiles d'exécution françaises (saisies, "
    . "voies d'exécution, contentieux du recouvrement). Tu analyses des actes et correspondances "
    . "de créanciers et tu extrais des données structurées. Tu réponds UNIQUEMENT en JSON valide.";

$user_prompt = <<<PROMPT
Analyse ce document de procédure créancier / recouvrement et extrais les données structurées.

Réponds UNIQUEMENT en JSON valide avec cette structure exacte :
{
  "type_doc": "conclusions|jugement|commandement|acte_saisie|correspondance|mail|autre",
  "numero_dossier": "string ou null (référence dossier huissier / TJ / créancier)",
  "creancier": {
    "nom": "string (dénomination exacte du créancier)",
    "type": "tresor_public|sip|banque|organisme_social|fournisseur|particulier|autre",
    "adresse": "string ou null"
  },
  "professionnel": {
    "role": "avocat|commissaire_justice|notaire|aucun",
    "nom": "string ou null (cabinet / étude)"
  },
  "montants": {
    "principal": "number ou null (euros, sans symbole)",
    "interets": "number ou null",
    "frais": "number ou null",
    "total": "number ou null"
  },
  "objet": "string (cause / objet de la créance, 1 phrase)",
  "dates": {
    "date_acte": "YYYY-MM-DD ou null",
    "date_audience": "YYYY-MM-DD ou null",
    "date_butoir": "YYYY-MM-DD ou null (échéance / délai impératif)"
  },
  "debiteur_mentionne": "string ou null (nom de la société/personne débitrice citée)"
}

Règles :
- Montants en euros, nombres seuls (pas de symbole ni d'espace).
- Toute information absente = null.
- "date_butoir" = délai impératif d'action (ex. délai de contestation, de paiement, d'audience).
- "type_doc" déduit du contenu (un jugement tranche, des conclusions argumentent, un commandement somme de payer).

TEXTE DU DOCUMENT :
---
{$text_truncated}
---
PROMPT;

$payload = [
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $user_prompt],
    ],
    'response_format' => ['type' => 'json_object'],
    'max_tokens'      => 2000,
    'temperature'     => 0.1,
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 90,
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Erreur API IA (HTTP ' . $httpCode . ').']); exit;
}

$result   = json_decode((string)$response, true);
$content  = $result['choices'][0]['message']['content'] ?? '';
$tokensIn = (int)($result['usage']['prompt_tokens'] ?? 0);
$tokensOut= (int)($result['usage']['completion_tokens'] ?? 0);
$parsed   = json_decode($content, true);
if (!$parsed && preg_match('/\{[\s\S]*\}/u', $content, $m)) $parsed = json_decode($m[0], true);
if (!$parsed) {
    echo json_encode(['ok' => false, 'error' => 'Analyse IA échouée.', 'raw' => substr($content, 0, 500)]); exit;
}

// Coût indicatif gpt-4o-mini : $0.150 / 1M in, $0.600 / 1M out → en EUR (~0.92).
$costEur = round((($tokensIn / 1e6) * 0.150 + ($tokensOut / 1e6) * 0.600) * 0.92, 4);

$crea     = $parsed['creancier'] ?? [];
$pro      = $parsed['professionnel'] ?? [];
$mts      = $parsed['montants'] ?? [];
$dates    = $parsed['dates'] ?? [];
$creaNom  = trim((string)($crea['nom'] ?? ''));
$numDoss  = $parsed['numero_dossier'] ?? null;
$mtPrinc  = is_numeric($mts['principal'] ?? null) ? (float)$mts['principal'] : null;
$mtTotal  = is_numeric($mts['total'] ?? null) ? (float)$mts['total'] : null;

// ── Anti-doublon : matching du créancier sur tiers existants (propose, ne crée pas) ──
$matchTiers = null;
if ($creaNom !== '') {
    $like = '%' . $creaNom . '%';
    $stm = $pdo->prepare("
        SELECT id, COALESCE(NULLIF(nom_affichage,''), NULLIF(raison_sociale,''), nom) AS lib
        FROM tiers
        WHERE (raison_sociale LIKE :q OR nom LIKE :q OR nom_affichage LIKE :q)
          AND (:soc IS NULL OR id_societe IS NULL OR id_societe = :soc)
        ORDER BY (raison_sociale = :exact OR nom = :exact OR nom_affichage = :exact) DESC
        LIMIT 1
    ");
    $stm->execute([':q' => $like, ':exact' => $creaNom, ':soc' => $idSociete]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    if ($row) $matchTiers = ['id' => (int)$row['id'], 'libelle' => $row['lib']];
}

// ── Comparaison inter-dossiers : même n° de dossier déjà en base ─────────
$flags = [];
if ($numDoss) {
    $stc = $pdo->prepare("
        SELECT id, id_dossier, extr_montant_total, extr_creancier_nom, created_at
        FROM creancier_doc_analyse
        WHERE extr_numero_dossier = ? AND statut <> 'rejete'
        ORDER BY created_at DESC LIMIT 5
    ");
    $stc->execute([$numDoss]);
    $prev = $stc->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prev as $p) {
        $flags[] = "Doc déjà analysé pour le n° dossier $numDoss (analyse #{$p['id']}).";
        if ($mtTotal !== null && $p['extr_montant_total'] !== null
            && abs((float)$p['extr_montant_total'] - $mtTotal) > 0.01) {
            $flags[] = sprintf("Montant total incohérent : %.2f € ici vs %.2f € (analyse #%d).",
                $mtTotal, (float)$p['extr_montant_total'], $p['id']);
        }
    }
}

// ── Persistance staging (à valider) ──────────────────────────────────────
$stIns = $pdo->prepare("
    INSERT INTO creancier_doc_analyse
      (id_dossier, type_doc, donnees_json, extr_creancier_nom, extr_pro_nom, extr_numero_dossier,
       extr_montant_principal, extr_montant_total, extr_objet, id_tiers_creancier_match,
       confidence, model_used, tokens_in, tokens_out, cost_eur, statut, review_flags,
       id_societe, id_agence, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'a_valider', ?, ?, ?, ?)
");
$stIns->execute([
    $idDossier,
    $parsed['type_doc'] ?? 'autre',
    json_encode($parsed, JSON_UNESCAPED_UNICODE),
    $creaNom ?: null,
    trim((string)($pro['nom'] ?? '')) ?: null,
    $numDoss,
    $mtPrinc,
    $mtTotal,
    $parsed['objet'] ?? null,
    $matchTiers['id'] ?? null,
    $matchTiers ? 0.9 : 0.5,
    $model,
    $tokensIn,
    $tokensOut,
    $costEur,
    $flags ? implode("\n", $flags) : null,
    $idSociete,
    $idAgence,
    $userId,
]);
$analyseId = (int)$pdo->lastInsertId();

echo json_encode([
    'ok'             => true,
    'analyse_id'     => $analyseId,
    'statut'         => 'a_valider',
    'data'           => $parsed,
    'creancier_match'=> $matchTiers,      // null = à créer comme tiers (rôle creancier) à la validation
    'review_flags'   => $flags,
    'cost_eur'       => $costEur,
], JSON_UNESCAPED_UNICODE);
