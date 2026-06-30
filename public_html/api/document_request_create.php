<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/document_requests.php';
require_once __DIR__ . '/../inc/mailer.php';
require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'];
$in  = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$titre = trim((string)($in['titre'] ?? ''));
$email = trim((string)($in['recipient_email'] ?? ''));
if ($titre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Titre et email destinataire requis']); exit;
}

// ── Construction des pièces ──────────────────────────────────────────────
$items = [];
foreach ((array)($in['items'] ?? []) as $it) {
    $label = trim((string)($it['label'] ?? ''));
    if ($label === '') continue;
    $items[] = [
        'label'       => $label,
        'doc_type'    => $it['doc_type'] ?? null,
        'entity_type' => $it['entity_type'] ?? null,
        'entity_id'   => $it['entity_id'] ?? null,
        'period'      => $it['period'] ?? null,
        'required'    => isset($it['required']) ? (int)!empty($it['required']) : 1,
    ];
}

// Génération automatique d'une pièce par agence (ex. projet de salaires par agence).
$gen = $in['generator'] ?? null;
if (is_array($gen) && ($gen['type'] ?? '') === 'agences') {
    $baseLabel = trim((string)($gen['label'] ?? 'Document'));
    $baseType  = (string)($gen['doc_type'] ?? '');
    $period    = (string)($gen['period'] ?? '');
    $agIds     = array_filter(array_map('intval', (array)($gen['agence_ids'] ?? [])));
    $q = $pdo->query("SELECT id, nom_agence FROM agences " . ($agIds ? "WHERE id IN (" . implode(',', $agIds) . ")" : "") . " ORDER BY nom_agence");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $items[] = [
            'label'       => $baseLabel . ' · ' . $a['nom_agence'],
            'doc_type'    => $baseType ?: null,
            'entity_type' => 'AGENCE',
            'entity_id'   => (int)$a['id'],
            'period'      => $period ?: null,
            'required'    => 1,
        ];
    }
}

// ── Résolution des entités PAR PIÈCE ─────────────────────────────────────
// Chaque pièce porte un entity_type (IMMEUBLE / TIERS / BIEN). On résout son
// entity_id depuis le bien d'origine : bien → son immeuble + son propriétaire
// (classé en TIERS côté GED). Ainsi chaque doc déposé est rangé sur la BONNE entité.
$ctxType = strtoupper((string)($in['entity_type'] ?? ''));
$ctxId   = (int)($in['entity_id'] ?? 0);
$entityMap = [];
if ($ctxType === 'BIEN' && $ctxId > 0) {
    $entityMap['BIEN'] = $ctxId;
    try {
        $b = $pdo->prepare("SELECT id_immeuble, id_proprietaire FROM biens WHERE id = ?");
        $b->execute([$ctxId]);
        if ($row = $b->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['id_immeuble'])) $entityMap['IMMEUBLE'] = (int)$row['id_immeuble'];
            if (!empty($row['id_proprietaire'])) {
                $t = $pdo->prepare("SELECT id_tiers FROM proprietaires WHERE id = ?");
                $t->execute([(int)$row['id_proprietaire']]);
                $tiersId = (int)$t->fetchColumn();
                if ($tiersId > 0) $entityMap['TIERS'] = $tiersId;
            }
        }
    } catch (Throwable) {}
} elseif ($ctxType !== '' && $ctxId > 0) {
    $entityMap[$ctxType] = $ctxId;
}
foreach ($items as &$it) {
    $et = strtoupper((string)($it['entity_type'] ?? ''));
    if ($et !== '' && empty($it['entity_id']) && isset($entityMap[$et])) $it['entity_id'] = $entityMap[$et];
}
unset($it);

if (!$items) { echo json_encode(['ok' => false, 'error' => 'Aucune pièce à demander']); exit; }

// ── Échéance + rappels ───────────────────────────────────────────────────
$expDays = isset($in['expires_days']) && $in['expires_days'] !== '' ? max(1, (int)$in['expires_days']) : 30;
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expDays} days"));

$remMode = in_array(($in['reminder_mode'] ?? 'none'), ['none','once','recurring'], true) ? $in['reminder_mode'] : 'none';
$remFirstAt = null; $remInterval = null;
if ($remMode === 'once' || $remMode === 'recurring') {
    $firstDays = isset($in['reminder_first_days']) && $in['reminder_first_days'] !== '' ? max(1, (int)$in['reminder_first_days']) : 7;
    $remFirstAt = date('Y-m-d H:i:s', strtotime("+{$firstDays} days"));
}
if ($remMode === 'recurring') {
    $remInterval = isset($in['reminder_interval_days']) && $in['reminder_interval_days'] !== '' ? max(1, (int)$in['reminder_interval_days']) : 7;
}

$res = dr_create_request($pdo, [
    'titre'           => $titre,
    'message'         => $in['message'] ?? null,
    'recipient_email' => $email,
    'recipient_name'  => $in['recipient_name'] ?? null,
    'template_code'   => $in['template_code'] ?? null,
    'entity_type'     => $in['entity_type'] ?? null,
    'entity_id'       => $in['entity_id'] ?? null,
    'societe_id'      => $in['societe_id'] ?? ($_SESSION['id_societe'] ?? null),
    'agence_id'       => $in['agence_id'] ?? null,
    'created_by'      => (int)current_user_id(),
    'require_email_gate' => isset($in['require_email_gate']) ? (int)!empty($in['require_email_gate']) : 1,
    'expires_at'      => $expiresAt,
    'reminder_mode'   => $remMode,
    'reminder_first_at' => $remFirstAt,
    'reminder_interval_days' => $remInterval,
], $items);

if (empty($res['ok'])) { echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Échec création']); exit; }

// ── Envoi du lien par mail (interne, pas Outlook) ────────────────────────
$url = $res['url'];
$nbPieces = count($items);
$senderEmail = ''; $senderNom = '';
try {
    $stE = $pdo->prepare("SELECT email, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) AS nom FROM users WHERE id = ? LIMIT 1");
    $stE->execute([(int)current_user_id()]);
    if ($u = $stE->fetch(PDO::FETCH_ASSOC)) { $senderEmail = (string)$u['email']; $senderNom = trim((string)$u['nom']); }
} catch (Throwable $e) {}
$replyTo = filter_var($senderEmail, FILTER_VALIDATE_EMAIL) ? $senderEmail : '';

$liste = '';
foreach ($items as $it) $liste .= '<li>' . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</li>';
$bodyHtml = '<p>Bonjour' . ($res ? '' : '') . ',</p>'
    . '<p>' . htmlspecialchars($senderNom ?: 'La Régie', ENT_QUOTES, 'UTF-8') . ' vous demande de déposer le(s) document(s) suivant(s) :</p>'
    . '<ul>' . $liste . '</ul>'
    . (!empty($in['message']) ? '<p>' . nl2br(htmlspecialchars((string)$in['message'], ENT_QUOTES, 'UTF-8')) . '</p>' : '')
    . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
    . 'style="background:#0e7490;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700;">Déposer mes documents</a></p>'
    . '<p style="color:#64748b;font-size:12px;">Lien sécurisé, valable jusqu\'au ' . date('d/m/Y', strtotime($expiresAt)) . '. '
    . 'Si le bouton ne fonctionne pas, copiez ce lien : ' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</p>';

$mailOk = false;
try { $mailOk = send_mail($email, 'Demande de documents : ' . $titre, $bodyHtml, [], true, '', $replyTo, '', $senderNom); } catch (Throwable $e) {}

echo json_encode([
    'ok' => true, 'id' => $res['id'], 'token' => $res['token'], 'url' => $url,
    'nb_pieces' => $nbPieces, 'mail_sent' => $mailOk,
], JSON_UNESCAPED_UNICODE);
