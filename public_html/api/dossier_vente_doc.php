<?php
declare(strict_types=1);
/**
 * api/dossier_vente_doc.php — Sert un document d'un dossier de vente à un destinataire
 * (acquéreur / notaire), SANS login, garanti par le jeton de partage (?t=) + appartenance
 * du document au jeu AUTORISÉ (docs_json du partage).
 *
 * Identité jeton (user_id=0) → confidentiel/coffre STRUCTURELLEMENT refusés (ged_grant).
 * GET : t=TOKEN, doc=ID, mode=inline|download
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_access.php';
$pdo = $GLOBALS['pdo'] ?? db();

header('X-Robots-Tag: noindex, nofollow, noarchive');
function dvd_stop(int $code, string $msg): void { http_response_code($code); header('Content-Type:text/plain; charset=utf-8'); echo $msg; exit; }

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$docId = (int)($_GET['doc'] ?? 0);
$mode  = ($_GET['mode'] ?? 'inline') === 'download' ? 'attachment' : 'inline';
if ($docId <= 0 || strlen($token) < 32) dvd_stop(400, 'Requête invalide.');

// 1) Jeton valide (non révoqué, non expiré).
$st = $pdo->prepare("SELECT id, docs_json, revoked_at, expires_at FROM dossier_vente_partage WHERE token = ? LIMIT 1");
$st->execute([$token]);
$share = $st->fetch(PDO::FETCH_ASSOC);
if (!$share || !empty($share['revoked_at'])) dvd_stop(403, 'Accès clôturé.');
if (!empty($share['expires_at']) && strtotime((string)$share['expires_at']) < time()) dvd_stop(403, 'Accès expiré.');

// 2) Le document doit faire partie du jeu AUTORISÉ pour ce partage.
$allowed = json_decode((string)($share['docs_json'] ?? '[]'), true) ?: [];
$allowed = array_map('intval', (array)$allowed);
if (!in_array($docId, $allowed, true)) dvd_stop(403, 'Document non autorisé.');

// 3) Le document doit être actif.
$chk = $pdo->prepare("SELECT id, name_file, name_display, mime_type FROM ged_documents WHERE id = ? AND status='active' LIMIT 1");
$chk->execute([$docId]);
$doc = $chk->fetch(PDO::FETCH_ASSOC);
if (!$doc) dvd_stop(404, 'Document indisponible.');

// 4) Résolution physique via le POINT DE PASSAGE CENTRAL — identité JETON (user_id=0) :
//    admin_sup lève le scope société mais AUCUNE permission → confidentiel/coffre refusés.
$g = ged_grant($docId, $mode === 'attachment' ? 'download' : 'preview', ['user_id'=>0, 'societe'=>0, 'admin_sup'=>true]);
if ($g === null || empty($g['path'])) dvd_stop(404, 'Document momentanément indisponible. Contactez votre conseiller.');
$real = $g['path'];

$mime  = (string)($doc['mime_type'] ?: 'application/pdf');
$fname = (string)($doc['name_display'] ?: $doc['name_file'] ?: 'document');
if (!preg_match('/\.[a-z0-9]{2,5}$/i', $fname)) $fname .= '.pdf';

// Compteur de vues (best-effort).
try { $pdo->prepare("UPDATE dossier_vente_partage SET nb_vues = nb_vues + 1, last_view_at = NOW() WHERE id = ?")->execute([(int)$share['id']]); } catch (Throwable) {}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($real));
header('Content-Disposition: ' . $mode . '; filename="' . str_replace('"', '', $fname) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');
readfile($real);
exit;
