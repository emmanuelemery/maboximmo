<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// API: return JSON 401 instead of redirecting to login
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

global $ANTHROPIC_API_KEY, $ANTHROPIC_MODEL;
$pdo = $GLOBALS['pdo'];

// Ensure code_acces is in session
if (empty($_SESSION['code_acces'])) {
    $stmtCA = $pdo->prepare("SELECT code_acces FROM users WHERE id = ?");
    $stmtCA->execute([$_SESSION['user_id']]);
    $_SESSION['code_acces'] = $stmtCA->fetchColumn() ?: '';
}

// Accept both JSON body and form POST
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$question = trim($input['question'] ?? $_POST['question'] ?? '');
if ($question === '') {
    echo json_encode(['error' => 'La question est requise.']);
    exit;
}

$contexte = trim($input['contexte'] ?? $_POST['contexte'] ?? ($_SESSION['code_acces'] ?? 'proprietaire'));

// ── Map contexte to rule filter ──
function map_contexte_regles(string $code): string
{
    return match (strtoupper($code)) {
        'AGENCE', 'NEGO' => 'agence',
        default           => 'proprietaire',
    };
}

// ── Load filtering rules ──
function load_regles(PDO $pdo, string $code): array
{
    if (strtoupper($code) === 'ADMIN') {
        return [];
    }
    $ctx = map_contexte_regles($code);
    $stmt = $pdo->prepare(
        "SELECT description FROM ia_regles_filtrage
         WHERE actif = 1 AND (contexte = ? OR contexte = 'tous')
         ORDER BY ordre"
    );
    $stmt->execute([$ctx]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ── Build contextual data ──
function build_context_data(PDO $pdo, string $code): string
{
    $upper = strtoupper($code);

    // AGENCE / NEGO
    if (in_array($upper, ['AGENCE', 'NEGO'], true)) {
        $idAgence = (int) ($_SESSION['id_agence'] ?? 0);
        $stmt = $pdo->prepare(
            "SELECT b.designation, b.adresse_1, b.ville, b.statut_occupation,
                    b.loyer_mandat, b.charges_mandat
             FROM biens b
             JOIN mandats m ON m.id_bien = b.id
             JOIN mandats_agences_ext mae ON mae.id_mandat = m.id
             WHERE mae.id_agence = ? AND mae.actif = 1"
        );
        $stmt->execute([$idAgence]);
        return json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    }

    // SIR — multi-proprietaire, no locataire_nom
    if ($upper === 'SIR') {
        $idUser = (int) ($_SESSION['user_id'] ?? 0);
        $ids = get_sir_proprietaire_ids($idUser);
        if (empty($ids)) {
            return 'Aucune donnée disponible.';
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT annee, trimestre, date_arrete, total_debits, total_credits, total_tva, solde_report
             FROM crg_trimestres
             WHERE id_proprietaire IN ({$ph}) AND parse_statut = 'ok'
             ORDER BY annee DESC, trimestre DESC LIMIT 8"
        );
        $stmt->execute($ids);
        $crg = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $situations = [];
        if (!empty($crg)) {
            $lastA = $crg[0]['annee'];
            $lastT = $crg[0]['trimestre'];
            $stmt2 = $pdo->prepare(
                "SELECT sl.type_bien, sl.categorie_bien, sl.numero_lot,
                        sl.loyer_appele, sl.total_impaye, sl.solde_anterieur,
                        sl.statut_trimestre,
                        i.nom_immeuble
                 FROM crg_situations_locataires sl
                 JOIN crg_trimestres ct ON ct.id = sl.id_crg
                 LEFT JOIN biens b ON b.id = sl.id_bien
                 LEFT JOIN immeubles i ON i.id = b.id_immeuble
                 WHERE ct.id_proprietaire IN ({$ph}) AND ct.annee = ? AND ct.trimestre = ?
                 ORDER BY sl.total_impaye DESC"
            );
            $stmt2->execute(array_merge($ids, [$lastA, $lastT]));
            $situations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        return json_encode([
            'crg_historique' => $crg,
            'situations'     => $situations,
        ], JSON_UNESCAPED_UNICODE);
    }

    // PROPRIO — single proprietaire, includes locataire_nom
    if ($upper === 'PROPRIO' && !empty($_SESSION['id_proprietaire'])) {
        $idProp = (int) $_SESSION['id_proprietaire'];

        $stmt = $pdo->prepare(
            "SELECT * FROM crg_trimestres
             WHERE id_proprietaire = ?
             ORDER BY date_fin DESC LIMIT 8"
        );
        $stmt->execute([$idProp]);
        $crg = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $situations = [];
        if (!empty($crg)) {
            $latestId = (int) $crg[0]['id'];
            $stmt2 = $pdo->prepare(
                "SELECT sl.locataire_nom, sl.type_bien, sl.categorie_bien,
                        sl.numero_lot, sl.loyer_appele, sl.total_impaye,
                        sl.solde_anterieur, sl.statut_trimestre,
                        i.nom AS nom_immeuble
                 FROM crg_situations_locataires sl
                 LEFT JOIN immeubles i ON i.id = sl.id_immeuble
                 WHERE sl.id_crg_trimestre = ?"
            );
            $stmt2->execute([$latestId]);
            $situations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        return json_encode([
            'crg_historique' => $crg,
            'situations'     => $situations,
        ], JSON_UNESCAPED_UNICODE);
    }

    // ADMIN — handled separately in system prompt
    if ($upper === 'ADMIN') {
        return 'Accès complet aux données.';
    }

    return 'Aucune donnée disponible.';
}

// ── Main ──
$regles = load_regles($pdo, $contexte);
$data   = build_context_data($pdo, $contexte);
error_log("ask_ia: contexte=$contexte, user_id={$_SESSION['user_id']}, code_acces={$_SESSION['code_acces']}, data_length=" . strlen($data));
$upper  = strtoupper($contexte);

if ($upper === 'ADMIN') {
    $system = "Tu es l'assistant interne de Loca Immo Régie Emery. Accès complet. Réponds en français.\n\nDonnées:\n{$data}";
} elseif ($upper === 'SIR') {
    $rulesText = implode("\n- ", $regles);
    $system = "Tu es l'assistant patrimonial du Groupe SIR & SABY, géré par Loca Immo Régie Emery.\n"
        . "Tu as accès à TOUTES les données financières du portefeuille ci-dessous.\n"
        . "RÉPONDS TOUJOURS avec les chiffres extraits des données fournies. NE DIS JAMAIS que tu n'as pas accès aux données.\n"
        . "Si la question est vague, réponds avec une vue d'ensemble du dernier trimestre disponible.\n"
        . "NE DEMANDE PAS de précisions inutiles. Réponds directement avec les données.\n"
        . "Pour toute question opérationnelle (baux, travaux, locataires), renvoie vers la régie.\n\n"
        . "RÈGLES STRICTES :\n- {$rulesText}\n\n"
        . "TU RÉPONDS SUR :\n"
        . "- Taux d'encaissement et son évolution\n"
        . "- Répartition habitation / commercial / géographique\n"
        . "- Montants globaux : encaissements, impayés, créances\n"
        . "- Comparaisons inter-trimestres et tendances\n"
        . "- Nombre de lots occupés / vacants / partis-débiteurs\n\n"
        . "DONNÉES DU PORTEFEUILLE (JSON) :\n{$data}";
} else {
    $rulesText = implode("\n- ", $regles);
    $system = "Tu es l'assistant IA de Ma Box Immo. Réponds en français, de manière claire et concise.\n\n"
        . "Règles strictes :\n- {$rulesText}\n\n"
        . "Données disponibles :\n{$data}";
}

// ── Call OpenAI API ──
global $OPENAI_API_KEY, $OPENAI_TEXT_MODEL;
$model = $OPENAI_TEXT_MODEL ?: 'gpt-4o';

$payload = json_encode([
    'model'      => $model,
    'max_completion_tokens' => 1500,
    'messages'   => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $question],
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
    $errBody = json_decode($raw, true);
    $errMsg = $errBody['error']['message'] ?? "HTTP $httpCode";
    echo json_encode(['error' => 'Erreur API IA : ' . $errMsg]);
    exit;
}

$result  = json_decode($raw, true);
$reponse = $result['choices'][0]['message']['content']
        ?? $result['output'][0]['content'][0]['text']
        ?? $result['output_text']
        ?? '';

// Debug: if still empty, log raw response
if ($reponse === '') {
    error_log('ask_ia.php: empty response. Raw: ' . substr($raw, 0, 500));
}
$tokens  = ($result['usage']['total_tokens'] ?? 0);

// ── Log conversation ──
$idProprietaire = ($upper === 'SIR') ? null : (($_SESSION['id_proprietaire'] ?? null) ? (int) $_SESSION['id_proprietaire'] : null);

$stmt = $pdo->prepare(
    "INSERT INTO ia_conversations (id_user, id_proprietaire, contexte, question, reponse, tokens_prompt, tokens_reponse)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->execute([
    (int) ($_SESSION['user_id'] ?? 0),
    $idProprietaire,
    $contexte,
    $question,
    $reponse,
    $result['usage']['prompt_tokens'] ?? 0,
    $result['usage']['completion_tokens'] ?? 0,
]);

echo json_encode(['reponse' => $reponse], JSON_UNESCAPED_UNICODE);
