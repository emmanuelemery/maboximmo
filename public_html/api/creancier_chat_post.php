<?php
/**
 * api/creancier_chat_post.php — Chat IA spécifique à un dossier créancier.
 *
 * L'utilisateur pose une question sur le dossier ; l'IA répond en s'appuyant sur le
 * CONTEXTE du dossier (synthèse, commentaire avocat, créanciers, dettes, saisies, docs).
 * Messages persistés dans creancier_dossier_message (role user / ia).
 *
 * POST : id_dossier, message, csrf_token (form 'creancier_chat').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_chat');

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$idDossier = (int)($_POST['id_dossier'] ?? 0);
$message   = trim((string)($_POST['message'] ?? ''));
if ($idDossier <= 0 || $message === '') { echo json_encode(['ok'=>false,'error'=>'id_dossier et message requis']); exit; }

if (!creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès dossier refusé']); exit;
}

// Persiste le message utilisateur.
$pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, id_user, message) VALUES (?, 'user', ?, ?)")
    ->execute([$idDossier, $userId, $message]);

// ── Contexte du dossier ──────────────────────────────────────────────
$d = $pdo->prepare("SELECT code, libelle, statut, niveau_risque, synthese, commentaire, numero_dossier_adverse FROM creancier_dossier WHERE id = ?");
$d->execute([$idDossier]); $dos = $d->fetch(PDO::FETCH_ASSOC) ?: [];
$data = creancier_urgence_data($pdo, $idDossier, $userId);

$items = $pdo->prepare("SELECT type, titre, montant, date_echeance, statut FROM creancier_dossier_item WHERE id_dossier = ? ORDER BY priorite DESC LIMIT 30");
$items->execute([$idDossier]); $itemsRows = $items->fetchAll(PDO::FETCH_ASSOC);

$ctxLines = [];
$ctxLines[] = "Dossier: {$dos['libelle']} ({$dos['code']}) — statut {$dos['statut']}, risque {$dos['niveau_risque']}.";
if (!empty($dos['numero_dossier_adverse'])) $ctxLines[] = "N° dossier adverse: {$dos['numero_dossier_adverse']}.";
if (!empty($dos['synthese'])) $ctxLines[] = "Synthèse: {$dos['synthese']}";
if (!empty($dos['commentaire'])) $ctxLines[] = "Commentaire avocat: {$dos['commentaire']}";
$ctxLines[] = sprintf("Total net bloqué: %s € · reste dû: %s € · trésorerie captée/mois: %s €.",
    number_format($data['total_net_bloque'],2,',',' '), number_format($data['reste_du'],2,',',' '), number_format($data['tresorerie_captee_mensuelle'],2,',',' '));
if ($data['butoirs_en_retard']) {
    foreach ($data['butoirs_en_retard'] as $b) $ctxLines[] = "RETARD: {$b['creancier']} échéance {$b['date_butoir']} (J{$b['jours']}).";
}
foreach ($data['saisies_par_creancier'] as $c) $ctxLines[] = "Créancier {$c['libelle']}: net " . number_format($c['total_net'],2,',',' ') . " €, {$c['nb']} saisie(s).";
foreach ($itemsRows as $it) {
    $ctxLines[] = "{$it['type']}: {$it['titre']}" . ($it['montant'] !== null ? ' (' . number_format((float)$it['montant'],2,',',' ') . ' €)' : '') . ($it['date_echeance'] ? ' échéance ' . $it['date_echeance'] : '');
}
$contexte = implode("\n", $ctxLines);

// Historique récent (pour la continuité du chat).
$hist = $pdo->prepare("SELECT role, message FROM creancier_dossier_message WHERE id_dossier = ? ORDER BY id DESC LIMIT 10");
$hist->execute([$idDossier]);
$histRows = array_reverse($hist->fetchAll(PDO::FETCH_ASSOC));

// ── Appel IA ─────────────────────────────────────────────────────────
$api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
$reply = null;
if ($api_key) {
    $messages = [[
        'role' => 'system',
        'content' => "Tu es l'assistant juridique du dossier créancier ci-dessous. Réponds de façon concise, factuelle et opérationnelle, UNIQUEMENT à partir du contexte fourni. Si une information manque, dis-le clairement. Contexte du dossier :\n" . $contexte,
    ]];
    foreach ($histRows as $h) {
        $messages[] = ['role' => $h['role'] === 'ia' ? 'assistant' : 'user', 'content' => $h['message']];
    }
    $payload = ['model' => 'gpt-4o-mini', 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => 700];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 200) { $j = json_decode((string)$resp, true); $reply = trim((string)($j['choices'][0]['message']['content'] ?? '')); }
}
if ($reply === null || $reply === '') {
    $reply = "Assistant IA indisponible (clé OpenAI non configurée ou erreur). Le message a été enregistré.";
}

$pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, message) VALUES (?, 'ia', ?)")
    ->execute([$idDossier, $reply]);

echo json_encode(['ok' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE);
