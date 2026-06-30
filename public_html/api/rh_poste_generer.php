<?php
declare(strict_types=1);
/**
 * api/rh_poste_generer.php — Rédaction IA d'une DÉFINITION DE POSTE GÉNÉRALE.
 *
 * Génère une définition du poste volontairement GÉNÉRALE (mission, finalité, posture),
 * SANS liste exhaustive de tâches — pour ne pas enfermer le collaborateur. Guidé par les
 * consignes libres de l'utilisateur + le contexte RH (fonction, contrat, société/agence).
 *
 * POST JSON : { user_id, guidance, csrf? }
 * Réponse : { ok, text, model, error }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) exit(json_encode(['ok'=>false,'error'=>'JSON invalide']));

$pdo      = $GLOBALS['pdo'];
$userId   = (int)($body['user_id'] ?? 0);
$guidance = trim((string)($body['guidance'] ?? ''));
if ($userId <= 0) exit(json_encode(['ok'=>false,'error'=>'user_id requis']));

// Contexte RH du collaborateur
$st = $pdo->prepare("SELECT u.prenom, u.nom, u.fonction, u.type_contrat, u.temps_travail,
                            s.nom AS societe_nom, a.nom_agence AS agence_nom
                     FROM users u
                     LEFT JOIN societes s ON s.id = u.id_societe
                     LEFT JOIN agences  a ON a.id = u.id_agence
                     WHERE u.id = ? LIMIT 1");
$st->execute([$userId]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) exit(json_encode(['ok'=>false,'error'=>'Collaborateur introuvable']));

$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
if ($apiKey === '') exit(json_encode(['ok'=>false,'error'=>'Clé OpenAI non configurée']));
$model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : ($GLOBALS['OPENAI_TEXT_MODEL'] ?? 'gpt-4o-mini');

$ctx = [
    'collaborateur' => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
    'fonction'      => $u['fonction'] ?? '',
    'type_contrat'  => $u['type_contrat'] ?? '',
    'temps_travail' => $u['temps_travail'] ?? '',
    'societe'       => $u['societe_nom'] ?? '',
    'agence'        => $u['agence_nom'] ?? '',
];

$system = "Tu es responsable RH d'une agence immobilière (convention collective de l'immobilier). "
        . "Tu rédiges la DÉFINITION GÉNÉRALE d'un poste. RÈGLES IMPÉRATIVES :\n"
        . "- Rester GÉNÉRAL : décrire la mission, la finalité, le périmètre et la posture attendue.\n"
        . "- NE PAS faire de liste exhaustive de tâches (pas de puces interminables) — éviter d'enfermer le collaborateur.\n"
        . "- Formuler en quelques paragraphes courts, ton professionnel, français impeccable.\n"
        . "- Mentionner que les missions peuvent évoluer selon les besoins de l'agence.\n"
        . "- Pas de salaire, pas d'horaires, pas de clauses juridiques (ça relève du contrat).\n"
        . "Retourne UNIQUEMENT le texte de la définition, sans titre ni préambule.";

$userMsg = "Contexte du poste : " . json_encode($ctx, JSON_UNESCAPED_UNICODE)
         . "\n\nConsignes de l'utilisateur (à suivre) : " . ($guidance !== '' ? $guidance : "(aucune consigne particulière — propose une définition générale adaptée à la fonction)");

// Compat modèles récents (gpt-5/o-series) : max_completion_tokens au lieu de max_tokens,
// et pas de temperature custom (certains modèles n'acceptent que la valeur par défaut).
$payload = json_encode([
    'model'    => $model,
    'messages' => [
        ['role'=>'system','content'=>$system],
        ['role'=>'user','content'=>$userMsg],
    ],
    'max_completion_tokens' => 3000, // modèles « raisonnement » : prévoir le budget reasoning + sortie
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);

if ($err)        exit(json_encode(['ok'=>false,'error'=>'Réseau : '.$err]));
if ($code !== 200) exit(json_encode(['ok'=>false,'error'=>'OpenAI HTTP '.$code.' : '.mb_substr((string)$resp,0,300)]));

$data = json_decode((string)$resp, true);
$msg  = $data['choices'][0]['message'] ?? [];
$text = trim((string)($msg['content'] ?? ''));
// Repli : certains modèles renvoient le texte dans un tableau de blocs
if ($text === '' && is_array($msg['content'] ?? null)) {
    foreach ($msg['content'] as $blk) { if (!empty($blk['text'])) $text .= $blk['text']; }
    $text = trim($text);
}
if ($text === '') {
    $fr = (string)($data['choices'][0]['finish_reason'] ?? '?');
    exit(json_encode(['ok'=>false,'error'=>'Réponse IA vide (finish_reason='.$fr.', modèle '.$model.')']));
}

echo json_encode(['ok'=>true, 'text'=>$text, 'model'=>$model], JSON_UNESCAPED_UNICODE);
