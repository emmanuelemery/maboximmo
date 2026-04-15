<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

global $pdo, $OPENAI_API_KEY, $OPENAI_TEXT_MODEL;

$idBien = (int) ($_POST['id_bien'] ?? 0);
if ($idBien === 0) {
    echo json_encode(['error' => 'id_bien est requis.']);
    exit;
}

// ── Access check for AGENCE ──
if (($_SESSION['code_acces'] ?? '') === 'AGENCE') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM mandats m
         JOIN mandats_agences_ext mae ON mae.id_mandat = m.id
         WHERE m.id_bien = ? AND mae.id_agence = ? AND mae.actif = 1 AND m.statut = 'actif'"
    );
    $stmt->execute([$idBien, (int) ($_SESSION['id_agence'] ?? 0)]);
    if ((int) $stmt->fetchColumn() === 0) {
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }
}

// ── Fetch bien data ──
$stmt = $pdo->prepare(
    "SELECT b.*, d.dpe_classe, d.ges_classe, d.date_validite AS dpe_validite
     FROM biens b
     LEFT JOIN dpe_diags d ON d.id_bien = b.id AND d.est_diag_principal = 1
     WHERE b.id = ?"
);
$stmt->execute([$idBien]);
$bien = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bien) {
    echo json_encode(['error' => 'Bien introuvable.']);
    exit;
}

// ── Build prompt ──
$bienJson = json_encode($bien, JSON_UNESCAPED_UNICODE);
$prompt = <<<PROMPT
Tu es un rédacteur immobilier expert pour Leboncoin (LBC).
À partir des données du bien ci-dessous, génère une annonce au format JSON strict avec deux clés :
- "titre" : accrocheur, 60 à 70 caractères max
- "description" : texte fluide de 300 à 400 mots, professionnel, mettant en avant les atouts du bien. Mentionne le DPE si disponible.

Données du bien :
{$bienJson}

Réponds UNIQUEMENT avec le JSON, sans texte avant ou après.
PROMPT;

// ── Call OpenAI API ──
$model = $OPENAI_TEXT_MODEL ?: 'gpt-4o';
$payload = json_encode([
    'model'      => $model,
    'max_completion_tokens' => 1500,
    'messages'   => [
        ['role' => 'system', 'content' => 'Tu es un rédacteur immobilier professionnel. Réponds uniquement en JSON valide.'],
        ['role' => 'user', 'content' => $prompt],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT => 60,
]);

$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['error' => 'Erreur API IA (' . $httpCode . ')']);
    exit;
}

$result = json_decode($raw, true);
$text   = $result['choices'][0]['message']['content'] ?? '';

// Strip ```json markers if present
$text = preg_replace('/^```json\s*/i', '', $text);
$text = preg_replace('/\s*```$/i', '', $text);
$text = trim($text);

$annonce = json_decode($text, true);
if (!$annonce || empty($annonce['titre']) || empty($annonce['description'])) {
    echo json_encode(['error' => 'Impossible de parser la réponse IA.']);
    exit;
}

// ── Save to DB ──
$stmt = $pdo->prepare(
    "UPDATE biens SET annonce_texte_lbc = ?, annonce_lbc_generee_le = NOW() WHERE id = ?"
);
$stmt->execute([json_encode($annonce, JSON_UNESCAPED_UNICODE), $idBien]);

echo json_encode(['annonce' => $annonce], JSON_UNESCAPED_UNICODE);
