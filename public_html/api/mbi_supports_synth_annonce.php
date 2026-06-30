<?php
declare(strict_types=1);
set_time_limit(60);

/**
 * =======================================================================
 * api/mbi_supports_synth_annonce.php
 * Retourne le texte d'annonce SYNTHÉTISÉ pour l'affiche vitrine d'un bien.
 *
 *  - Si biens.bien_annonce_affiche est déjà rempli → on le renvoie tel quel
 *    (pas de nouvel appel IA : la synthèse est persistée une fois pour toutes).
 *  - Sinon : on synthétise via IA le texte de l'annonce (ou la description du
 *    bien), on le stocke sur biens.bien_annonce_affiche, et on le renvoie.
 *
 * POST : id_bien (int)
 * JSON : { ok, texte, source, erreur }
 * =======================================================================
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('mbi_synth_jsend')) {
    function mbi_synth_jsend(int $http, array $payload): void {
        http_response_code($http);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mbi_synth_jsend(405, ['ok' => false, 'erreur' => 'method_not_allowed']);
}
if (empty($_SESSION['user_id'])) {
    mbi_synth_jsend(401, ['ok' => false, 'erreur' => 'not_authenticated']);
}

$pdo    = $GLOBALS['pdo'] ?? db();
$idBien = (int)($_POST['id_bien'] ?? 0);
if ($idBien <= 0) {
    mbi_synth_jsend(400, ['ok' => false, 'erreur' => 'id_bien_required']);
}

// --- Chargement bien + scope (super admin role=1 bypass) ---
$roleId    = (int)($_SESSION['id_role'] ?? 0);
$idSocSess = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
try {
    $st = $pdo->prepare("SELECT id, id_societe, bien_annonce_affiche, description FROM biens WHERE id = :id LIMIT 1");
    $st->execute([':id' => $idBien]);
    $bien = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    mbi_synth_jsend(500, ['ok' => false, 'erreur' => 'db_error']);
}
if (!$bien) {
    mbi_synth_jsend(404, ['ok' => false, 'erreur' => 'bien_introuvable']);
}
if ($roleId !== 1 && $idSocSess !== null) {
    $idSocBien = (int)($bien['id_societe'] ?? 0);
    if ($idSocBien > 0 && $idSocBien !== $idSocSess) {
        mbi_synth_jsend(403, ['ok' => false, 'erreur' => 'hors_perimetre']);
    }
}

// 1. Déjà synthétisé → on renvoie le texte stocké (aucun appel IA)
$dejaSynth = trim((string)($bien['bien_annonce_affiche'] ?? ''));
if ($dejaSynth !== '') {
    mbi_synth_jsend(200, ['ok' => true, 'texte' => $dejaSynth, 'source' => 'stored']);
}

// 2. Texte source = description de l'annonce active, fallback description du bien
$source = '';
try {
    $sa = $pdo->prepare("
        SELECT description, texte_ia FROM annonces
        WHERE id_bien = :id
        ORDER BY date_modification DESC, id DESC LIMIT 1
    ");
    $sa->execute([':id' => $idBien]);
    $ann = $sa->fetch(PDO::FETCH_ASSOC) ?: [];
    $source = trim((string)($ann['description'] ?? $ann['texte_ia'] ?? ''));
} catch (Throwable) { $source = ''; }
if ($source === '') {
    $source = trim((string)($bien['description'] ?? ''));
}
if ($source === '' || str_starts_with($source, 'Bien créé')) {
    // Rien à synthétiser : on renvoie vide (le champ reste éditable manuellement)
    mbi_synth_jsend(200, ['ok' => true, 'texte' => '', 'source' => 'vide']);
}

// 3. Synthèse IA (modèle texte fréquent → gpt-3.5-turbo par défaut, cf. choix modèle IA)
$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
if (!$apiKey) {
    // Pas de clé : fallback troncature propre pour ne pas bloquer l'utilisateur
    $fallback = mbi_synth_troncature($source, 320);
    mbi_synth_persister($pdo, $idBien, $fallback);
    mbi_synth_jsend(200, ['ok' => true, 'texte' => $fallback, 'source' => 'troncature_sans_cle']);
}
$model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : ($GLOBALS['OPENAI_TEXT_MODEL'] ?? 'gpt-3.5-turbo');

$sourceTrunc = mb_substr($source, 0, 4000, 'UTF-8');
$system = "Tu es un rédacteur immobilier. Tu condenses une annonce en un paragraphe "
        . "court, fluide et vendeur destiné à une affiche vitrine. Tu réponds UNIQUEMENT "
        . "avec le texte final, sans titre, sans guillemets, sans liste.";
$user = "Résume l'annonce suivante en 2 à 3 phrases (260 à 320 caractères maximum), "
      . "ton commercial et chaleureux, en français, sans inventer d'information.\n\n"
      . "ANNONCE :\n---\n{$sourceTrunc}\n---";

$payloadArr = [
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ],
];
$useNewParam = (bool)preg_match('/^(gpt-5|o1|o3|gpt-4\.1)/i', $model);
if ($useNewParam) {
    $payloadArr['max_completion_tokens'] = 400;
} else {
    $payloadArr['max_tokens']  = 400;
    $payloadArr['temperature'] = 0.5;
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payloadArr, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp  = curl_exec($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr  = curl_error($ch);
curl_close($ch);

$texte = '';
if (!$cerr && $code === 200) {
    $data  = json_decode((string)$resp, true);
    $texte = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    $texte = trim($texte, " \t\n\r\0\x0B\"«»");
    // Cap dur : le modèle déborde souvent la consigne. On garantit que le texte
    // tient toujours en entier sur l'affiche (≈ 360 car. max, coupé à la phrase).
    if (mb_strlen($texte, 'UTF-8') > 360) {
        $texte = mbi_synth_troncature($texte, 360);
    }
}
// Fallback troncature si l'IA échoue (réseau / clé révoquée / quota)
if ($texte === '') {
    $texte = mbi_synth_troncature($source, 320);
    mbi_synth_persister($pdo, $idBien, $texte);
    mbi_synth_jsend(200, ['ok' => true, 'texte' => $texte, 'source' => 'troncature_fallback']);
}

mbi_synth_persister($pdo, $idBien, $texte);
mbi_synth_jsend(200, ['ok' => true, 'texte' => $texte, 'source' => 'ia']);

// ── Helpers ───────────────────────────────────────────────────────────
function mbi_synth_persister(PDO $pdo, int $idBien, string $texte): void {
    try {
        $up = $pdo->prepare("UPDATE biens SET bien_annonce_affiche = :t WHERE id = :id");
        $up->execute([':t' => $texte, ':id' => $idBien]);
    } catch (Throwable) {}
}
function mbi_synth_troncature(string $s, int $max): string {
    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') <= $max) return $s;
    $cut = mb_substr($s, 0, $max, 'UTF-8');
    $sp  = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp, 'UTF-8');
    return rtrim($cut, " \t\n\r,;:.") . '…';
}
