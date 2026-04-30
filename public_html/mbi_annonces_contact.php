<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mbi_annonces_helpers.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

if (!is_post()) {
    redirect('/mbi_annonces_index.php');
}

RateLimiter::check('mbi_annonces_contact', 10, 600);
verify_csrf('mbi_annonces_contact');

$annonceId = (int)post('id_annonce', 0);

// Honeypot anti-bot : si rempli, on simule un succès et on n'enregistre rien.
$honeypot = trim((string)post('website', ''));
if ($honeypot !== '') {
    redirect('/mbi_annonces_detail.php?id=' . $annonceId . '&contact_ok=1#contact');
}

$row = mbi_annonces_fetch_detail($pdo, $annonceId);
if (!$row) {
    redirect('/mbi_annonces_index.php');
}

$nom       = trim((string)post('nom', ''));
$email     = trim((string)post('email', ''));
$telephone = trim((string)post('telephone', ''));
$message   = trim((string)post('message', ''));

$errors = [];
if ($nom === '' || mb_strlen($nom) > 120) $errors[] = 'Nom invalide.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
if ($telephone !== '' && mb_strlen($telephone) > 30) $errors[] = 'Téléphone invalide.';
if (mb_strlen($message) > 2000) $errors[] = 'Message trop long.';

if ($errors) {
    $err = implode(' ', $errors);
    redirect('/mbi_annonces_detail.php?id=' . $annonceId . '&contact_err=' . rawurlencode($err) . '#contact');
}

$leadMessage = $message;
if (!empty($row['reference_annonce'])) {
    $leadMessage .= "\n\n— Annonce : réf. " . (string)$row['reference_annonce'];
}
$leadMessage .= "\n— URL : " . mbi_annonces_abs_url(mbi_annonces_url_detail($annonceId, (string)($row['annonce_slug'] ?? '')));

$inserted = false;
try {
    $st = $pdo->prepare("
        INSERT INTO vitrine_leads
          (id_societe, id_agence, type, nom, email, telephone, ville, message, source_url, ip, user_agent, created_at)
        VALUES
          (:id_societe, :id_agence, :type, :nom, :email, :telephone, :ville, :message, :source_url, :ip, :user_agent, NOW())
    ");
    $st->execute([
        ':id_societe' => (int)($row['id_societe'] ?? 0) ?: null,
        ':id_agence'  => (int)($row['agence_id'] ?? 0) ?: null,
        ':type'       => 'contact_annonce',
        ':nom'        => $nom,
        ':email'      => $email,
        ':telephone'  => $telephone !== '' ? $telephone : null,
        ':ville'      => (string)($row['ville'] ?? '') ?: null,
        ':message'    => $leadMessage,
        ':source_url' => mbi_annonces_abs_url(mbi_annonces_url_detail($annonceId, (string)($row['annonce_slug'] ?? ''))),
        ':ip'         => client_ip(),
        ':user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
    $inserted = true;
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        error_log('[mbi_annonces_contact] insert failed: ' . $e->getMessage());
    }
}

if (!$inserted) {
    redirect('/mbi_annonces_detail.php?id=' . $annonceId . '&contact_err=' . rawurlencode("Votre demande n'a pas pu être enregistrée. Réessayez dans quelques minutes.") . '#contact');
}

redirect('/mbi_annonces_detail.php?id=' . $annonceId . '&contact_ok=1#contact');
