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
$gen   = $in['generator'] ?? null;
// Mode « scission par comptable » : le destinataire est dérivé de chaque société,
// l'email saisi en tête n'est donc pas requis (seul le titre l'est).
$splitByComptable = is_array($gen) && ($gen['type'] ?? '') === 'agences' && !empty($gen['by_comptable']);
if ($titre === '') { echo json_encode(['ok' => false, 'error' => 'Titre requis']); exit; }
if (!$splitByComptable && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Email destinataire requis']); exit;
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
// En mode scission par comptable, la génération est traitée plus bas (une demande
// par comptable) : on n'ajoute donc rien à la liste plate ici.
if (is_array($gen) && ($gen['type'] ?? '') === 'agences' && !$splitByComptable) {
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

if (!$items && !$splitByComptable) { echo json_encode(['ok' => false, 'error' => 'Aucune pièce à demander']); exit; }

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

// Expéditeur (pour reply-to + signature du mail), commun aux deux chemins.
$senderEmail = ''; $senderNom = '';
try {
    $stE = $pdo->prepare("SELECT email, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) AS nom FROM users WHERE id = ? LIMIT 1");
    $stE->execute([(int)current_user_id()]);
    if ($u = $stE->fetch(PDO::FETCH_ASSOC)) { $senderEmail = (string)$u['email']; $senderNom = trim((string)$u['nom']); }
} catch (Throwable $e) {}
$replyTo = filter_var($senderEmail, FILTER_VALIDATE_EMAIL) ? $senderEmail : '';

// ── SCISSION PAR COMPTABLE ────────────────────────────────────────────────
// Une demande distincte par comptable (societes.comptable_email), ne contenant
// que les agences des sociétés qu'il gère → chacun reçoit son propre lien, zéro
// mélange. Les agences sans comptable renseigné sont signalées (skipped).
if ($splitByComptable) {
    $baseLabel = trim((string)($gen['label'] ?? 'Document'));
    $baseType  = (string)($gen['doc_type'] ?? '');
    $period    = (string)($gen['period'] ?? '');
    $rows = $pdo->query(
        "SELECT a.id AS id_agence, a.nom_agence, a.id_societe,
                s.comptable_email, s.comptable_nom
         FROM agences a JOIN societes s ON s.id = a.id_societe
         ORDER BY s.comptable_email, a.nom_agence"
    )->fetchAll(PDO::FETCH_ASSOC);

    $groups = []; $skipped = [];
    foreach ($rows as $r) {
        $ce = strtolower(trim((string)$r['comptable_email']));
        if ($ce === '' || !filter_var($ce, FILTER_VALIDATE_EMAIL)) { $skipped[] = (string)$r['nom_agence']; continue; }
        if (!isset($groups[$ce])) $groups[$ce] = ['email' => trim((string)$r['comptable_email']), 'nom' => trim((string)$r['comptable_nom']), 'agences' => []];
        $groups[$ce]['agences'][] = $r;
    }
    if (!$groups) { echo json_encode(['ok' => false, 'error' => 'Aucun comptable renseigné sur les sociétés — impossible de scinder. Renseigne l\'email comptable dans le module Salaires.', 'skipped' => $skipped], JSON_UNESCAPED_UNICODE); exit; }

    $created = [];
    foreach ($groups as $g) {
        $gItems = [];
        foreach ($g['agences'] as $a) {
            $gItems[] = [
                'label'       => $baseLabel . ' · ' . $a['nom_agence'],
                'doc_type'    => $baseType ?: null,
                'entity_type' => 'AGENCE',
                'entity_id'   => (int)$a['id_agence'],
                'period'      => $period ?: null,
                'required'    => 1,
            ];
        }
        $r = dr_create_request($pdo, [
            'titre'           => $titre,
            'message'         => $in['message'] ?? null,
            'recipient_email' => $g['email'],
            'recipient_name'  => $g['nom'],
            'template_code'   => $in['template_code'] ?? null,
            'societe_id'      => (int)($g['agences'][0]['id_societe'] ?? 0) ?: ($_SESSION['id_societe'] ?? null),
            'created_by'      => (int)current_user_id(),
            'require_email_gate' => isset($in['require_email_gate']) ? (int)!empty($in['require_email_gate']) : 1,
            'expires_at'      => $expiresAt,
            'reminder_mode'   => $remMode,
            'reminder_first_at' => $remFirstAt,
            'reminder_interval_days' => $remInterval,
        ], $gItems);
        if (empty($r['ok'])) { $created[] = ['email' => $g['email'], 'ok' => false, 'error' => $r['error'] ?? 'échec']; continue; }
        $mailOk = dr_send_link_mail($g['email'], $titre, $gItems, $r['url'], $expiresAt, (string)($in['message'] ?? ''), $senderNom, $replyTo);
        $created[] = ['email' => $g['email'], 'nom' => $g['nom'], 'ok' => true, 'id' => $r['id'], 'url' => $r['url'], 'nb_pieces' => count($gItems), 'mail_sent' => $mailOk];
    }
    echo json_encode([
        'ok' => true, 'split' => true, 'nb_requests' => count($created),
        'requests' => $created, 'skipped' => $skipped,
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
