<?php
// api/portefeuille_envoyer.php — Crée un ENVOI à jeton d'un portefeuille (mini-site privé) + email.
// Sécurité : login + rôle gestionnaire + CSRF. Le snapshot financier est FIGÉ à l'instant de l'envoi.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/mailer.php';
require_once __DIR__ . '/../inc/portefeuille_mail.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$roleId = (int)current_role_id();
// Staff (1,2,3) + super-admin (7) + BAILLEURS (9,10) : un bailleur peut envoyer SES portefeuilles.
if (!in_array($roleId, [1, 2, 3, 7, 9, 10], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}
if (function_exists('is_readonly_user') && is_readonly_user()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Compte en lecture seule : envoi non autorisé.']);
    exit;
}
verify_csrf_any('portefeuille_save');

$idPf  = (int)($_POST['id_portefeuille'] ?? 0);
$duree = max(1, min(365, (int)($_POST['duree'] ?? 30)));   // jours de validité (défaut 30)
$emailOverride = trim((string)($_POST['email'] ?? ''));
$envoyerMail   = (string)($_POST['envoyer_mail'] ?? '1') !== '0';
if ($idPf <= 0) { echo json_encode(['success' => false, 'message' => 'Portefeuille manquant.']); exit; }

// ── En-tête du portefeuille ──
$pf = $pdo->prepare("SELECT * FROM portefeuilles WHERE id = ?");
$pf->execute([$idPf]);
$pf = $pf->fetch(PDO::FETCH_ASSOC);
if (!$pf) { echo json_encode(['success' => false, 'message' => 'Portefeuille introuvable.']); exit; }

// ── Lignes (snapshot financier figé) ──
$bl = $pdo->prepare("SELECT * FROM portefeuille_biens WHERE id_portefeuille = ? ORDER BY ordre, id");
$bl->execute([$idPf]);
$lignes = $bl->fetchAll(PDO::FETCH_ASSOC);
if (!$lignes) { echo json_encode(['success' => false, 'message' => 'Le portefeuille ne contient aucun bien.']); exit; }

// ── Garde-fou périmètre pour un BAILLEUR (non-staff) : il ne peut envoyer qu'un
// portefeuille contenant au moins un de SES biens (évite l'accès par id deviné). ──
if (in_array($roleId, [9, 10], true)) {
    require_once __DIR__ . '/../inc/portefeuille_scope.php';
    $myIds = pf_scope($pdo)['ids'];
    $allowed = false;
    if (!empty($myIds)) {
        $inMy = implode(',', array_map('intval', $myIds));
        $bids = implode(',', array_map(fn($l) => (int)$l['id_bien'], $lignes)) ?: '0';
        $chk = $pdo->query("SELECT 1 FROM biens WHERE id IN ($bids) AND id_proprietaire IN ($inMy) LIMIT 1");
        $allowed = (bool)$chk->fetchColumn();
    }
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Portefeuille hors de votre périmètre.']);
        exit;
    }
}

$g = fn($k) => $pf[$k] ?? null;   // accès souple aux colonnes d'en-tête
$emailDest = $emailOverride !== '' ? $emailOverride : (string)($pf['destinataire_email'] ?? '');
if ($envoyerMail && !filter_var($emailDest, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Email du destinataire invalide ou manquant.']);
    exit;
}

// ── Snapshot JSON : en-tête + lignes financières figées (le contenu du bien reste lu en direct sur p.php) ──
$snapshot = [
    'header' => [
        'nom'               => $pf['nom'] ?? '',
        'type_destinataire' => $pf['type_destinataire'] ?? null,
        'destinataire_nom'  => $pf['destinataire_nom'] ?? null,
        'destinataire_prenom' => $pf['destinataire_prenom'] ?? null,
        'destinataire_email'  => $pf['destinataire_email'] ?? null,
        'destinataire_tel'    => $pf['destinataire_tel'] ?? null,
        'id_tiers_destinataire' => $pf['id_tiers_destinataire'] ?? null,
        'critere_prix_min'  => $pf['critere_prix_min'] ?? null,
        'critere_prix_max'  => $pf['critere_prix_max'] ?? null,
        'critere_surf_min'  => $pf['critere_surf_min'] ?? null,
        'critere_surf_max'  => $pf['critere_surf_max'] ?? null,
    ],
    'lignes' => array_map(fn($l) => [
        'id_bien'             => (int)$l['id_bien'],
        'prix_vente'          => $l['prix_vente'],
        'prix_m2'             => $l['prix_m2'],
        'rendement'           => $l['rendement'],
        'honoraires_pct'      => $l['honoraires_pct'],
        'honoraires_montant'  => $l['honoraires_montant'],
        'net_vendeur'         => $l['net_vendeur'],
        'snap_adresse'        => $l['snap_adresse'],
        'snap_ville'          => $l['snap_ville'],
        'snap_reference'      => $l['snap_reference'],
        'snap_surface'        => $l['snap_surface'],
        'snap_loyer_mensuel'  => $l['snap_loyer_mensuel'],
        'ordre'               => (int)($l['ordre'] ?? 0),
    ], $lignes),
];

$token   = bin2hex(random_bytes(20));   // 40 hex, imprévisible
$totPV   = array_sum(array_map(fn($l) => (float)($l['prix_vente'] ?? 0), $lignes));
$totHo   = array_sum(array_map(fn($l) => (float)($l['honoraires_montant'] ?? 0), $lignes));
$userId  = function_exists('current_user_id') ? (int)current_user_id() : null;
$sujet   = trim((string)($_POST['sujet'] ?? '')) ?: ('Votre portefeuille immobilier — ' . ($pf['nom'] ?? ''));

try {
    $ins = $pdo->prepare("INSERT INTO portefeuille_envois
        (id_portefeuille, token, date_expiration, actif, email_destinataire, destinataire_nom, type_destinataire,
         sujet, snapshot_json, nb_biens, total_prix_vente, total_honoraires, id_user)
        VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? DAY), 1, ?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        $idPf, $token, $duree, $emailDest, $pf['destinataire_nom'] ?? null, $pf['type_destinataire'] ?? null,
        $sujet, json_encode($snapshot, JSON_UNESCAPED_UNICODE), count($lignes), $totPV, $totHo, $userId,
    ]);
    $envoiId = (int)$pdo->lastInsertId();
    // Marque le portefeuille comme envoyé (best-effort).
    try { $pdo->prepare("UPDATE portefeuilles SET statut='envoye' WHERE id=?")->execute([$idPf]); } catch (Throwable $e) {}
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Création de l\'envoi : ' . $e->getMessage()]);
    exit;
}

// URL ABSOLUE obligatoire pour un lien dans un email (sinon /p.php?t=… est relatif et cassé).
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'maboximmo.fr';
$publicPath = app_url('/p.php?t=' . $token);
if (preg_match('~^https?://~i', $publicPath)) {
    $publicUrl = $publicPath;                       // app_url renvoie déjà un absolu (config)
} else {
    $publicUrl = $scheme . '://' . $host . '/' . ltrim($publicPath, '/');
}

// ── Email (best-effort) : texte de confidentialité + bouton vers le lien personnel ──
$mailSent = false; $mailError = '';
if ($envoyerMail) {
    $civilite = 'Madame, Monsieur';
    $html = pf_envoi_email_html($publicUrl, $civilite, (string)($pf['nom'] ?? ''));
    try {
        if (function_exists('send_mail')) {
            $mailSent = send_mail($emailDest, $sujet, $html, [], true);
            if (!$mailSent) $mailError = 'Le serveur mail a refusé l\'envoi.';
        } else {
            $mailError = 'Aucun service mail configuré (send_mail absent).';
        }
    } catch (Throwable $e) { $mailError = $e->getMessage(); }
}

echo json_encode([
    'success'    => true,
    'envoi_id'   => $envoiId,
    'token'      => $token,
    'url'        => $publicUrl,
    'mail_sent'  => $mailSent,
    'mail_error' => $mailError,
    'message'    => $mailSent ? 'Portefeuille envoyé par email.' : 'Lien d\'accès créé' . ($envoyerMail ? ' (email non envoyé : ' . $mailError . ')' : '.'),
], JSON_UNESCAPED_UNICODE);
