<?php
declare(strict_types=1);

/**
 * api/rh_salaire_doc_download.php
 *
 * Endpoint robuste de téléchargement / streaming des documents de salaire
 * (table salaires_documents). Lit le fichier côté serveur via readfile()
 * et le streame au client.
 *
 * Vérifie les droits :
 *   - role=1 (admin) → accès total
 *   - propriétaire (id_user = current user) → OK
 *   - gestionnaire d'agence (can_manage_salaires_agence>0) → OK si user cible
 *     appartient à son agence
 *
 * Cherche le fichier physique parmi plusieurs emplacements connus pour
 * supporter à la fois les nouveaux uploads (chemin corrigé) et les fichiers
 * historiques sauvegardés à des emplacements buggés.
 *
 * Params GET :
 *   - id      : (int) salaires_documents.id
 *   - inline  : (0/1) si 1, Content-Disposition=inline (utile pour print/preview)
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('DB error');
}

$docId = (int)($_GET['id'] ?? 0);
if ($docId <= 0) {
    http_response_code(400);
    exit('Invalid document id');
}

$stmt = $pdo->prepare("SELECT * FROM salaires_documents WHERE id = ? LIMIT 1");
$stmt->execute([$docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit('Document not found');
}

// Access control
$roleId        = current_role_id();
$currentUserId = current_user_id();
$agenceScope   = function_exists('can_manage_salaires_agence') ? can_manage_salaires_agence() : 0;
$ownerUserId   = (int)($doc['id_user'] ?? 0);

if ($roleId !== 1 && $ownerUserId !== $currentUserId) {
    if ($agenceScope > 0) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
        $chk->execute([$ownerUserId, $agenceScope]);
        if (!$chk->fetch()) {
            http_response_code(403);
            exit('Access denied');
        }
    } else {
        http_response_code(403);
        exit('Access denied');
    }
}

// Hardening : empêche les directory traversals via filename DB
$filename = basename((string)($doc['filename'] ?? ''));
if ($filename === '' || $filename === '.' || $filename === '..') {
    http_response_code(400);
    exit('Invalid filename');
}

/*
 * Liste des emplacements candidats où peut se trouver le fichier physique.
 *
 * 1) Nouveau chemin correct (après fix upload path) :
 *    public_html/uploads/salaires/<filename>
 *
 * 2) Ancien chemin buggé sur DEV (script en public_html/dev/) :
 *    public_html/dev/../uploads/salaires = public_html/uploads/salaires
 *    => même que (1) pour dev, mais d'autres anciens uploads peuvent être
 *    plus haut :
 *    public_html/dev/../../uploads/salaires = domains/maboximmo.fr/uploads/salaires
 *
 * 3) Ancien chemin buggé sur PROD (script en public_html/) :
 *    public_html/../../uploads/salaires = domains/uploads/salaires
 */
$candidates = [
    __DIR__ . '/../uploads/salaires/' . $filename,        // public_html/uploads/salaires (NEW correct)
    __DIR__ . '/../../uploads/salaires/' . $filename,     // 1 niveau au-dessus (DEV legacy bug)
    __DIR__ . '/../../../uploads/salaires/' . $filename,  // 2 niveaux au-dessus (PROD legacy bug)
];

$resolved = null;
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if ($real && is_file($real) && is_readable($real)) {
        $resolved = $real;
        break;
    }
}

if (!$resolved) {
    http_response_code(404);
    error_log('[rh_salaire_doc_download] File not found on disk for doc id=' . $docId . ' filename=' . $filename);
    exit('Fichier introuvable sur le serveur (id=' . $docId . ').');
}

// Output filename
$outName = (string)($doc['original_name'] ?? '');
if ($outName === '') {
    $outName = $filename;
}
$outName = str_replace(['"', "\r", "\n"], '', $outName);

// Mime type detection
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $resolved);
        @finfo_close($finfo);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
}

$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
$disposition = $inline ? 'inline' : 'attachment';

// Stream the file
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $outName . '"');
header('Content-Length: ' . filesize($resolved));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

readfile($resolved);
exit;
