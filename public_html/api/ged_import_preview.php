<?php
declare(strict_types=1);

/**
 * Ma GED Box V2.5 — Endpoint de prévisualisation/téléchargement d'un item d'import
 *
 * Usage : GET /api/ged_import_preview.php?item_id=123[&disposition=inline|attachment][&as=html]
 *
 * - item_id      : ID dans ged_import_items (super admin only)
 * - disposition  : inline (par défaut pour types previewable) ou attachment (force download)
 * - as=html      : pour .eml uniquement → parse mail et renvoie HTML lisible (sujet, from, body)
 *
 * Sécurité :
 *   - super admin uniquement (id_role = 1)
 *   - storage_path validé : doit pointer dans le storage import (anti path traversal)
 *   - readfile() avec Content-Type adapté au mime + extension
 *
 * Types email supportés :
 *   - .eml  → message/rfc822 (inline ou as=html)
 *   - .msg  → application/vnd.ms-outlook (toujours attachment, à ouvrir dans Outlook)
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_import_functions.php';
require_login();

function preview_die(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) preview_die(403, 'Accès réservé super admin');

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) preview_die(400, 'item_id requis');

$disposition = (string)($_GET['disposition'] ?? '');
$as          = (string)($_GET['as'] ?? '');

$st = ged_import_pdo()->prepare("
    SELECT id, batch_id, old_filename, file_extension, mime_type, storage_path, size_bytes
    FROM ged_import_items WHERE id = ? LIMIT 1
");
$st->execute([$itemId]);
$item = $st->fetch(PDO::FETCH_ASSOC);
if (!$item) preview_die(404, 'Item introuvable');

$path = (string)$item['storage_path'];
if ($path === '' || !is_file($path)) preview_die(404, 'Fichier physique introuvable');

// Sécurité : valider que le path résolu est bien dans un sous-dossier autorisé.
// On accepte uniquement les chemins qui contiennent /uploads/ged_import/ ou \uploads\ged_import\
// (compatible Windows + Linux, anti-traversal).
$norm = str_replace('\\', '/', realpath($path) ?: $path);
if (strpos($norm, '/uploads/ged_import/') === false) {
    preview_die(403, 'Chemin non autorisé');
}

$ext  = strtolower((string)$item['file_extension']);
$name = (string)$item['old_filename'];
$size = (int)$item['size_bytes'];

// Mode HTML pour .eml : parse minimaliste et rendu lisible
if ($as === 'html' && $ext === 'eml') {
    $raw = @file_get_contents($path);
    if ($raw === false) preview_die(500, 'Lecture .eml échouée');
    render_eml_as_html($raw, $name);
    exit;
}

// Détermine le Content-Type final
$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'txt'  => 'text/plain; charset=utf-8',
    'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
    'csv'  => 'text/csv; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'xml'  => 'application/xml; charset=utf-8',
    'zip'  => 'application/zip',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'eml'  => 'message/rfc822',
    'msg'  => 'application/vnd.ms-outlook',
    'mp4'  => 'video/mp4', 'webm' => 'video/webm',
    'mp3'  => 'audio/mpeg',
];
$mime = $mimeMap[$ext] ?? ($item['mime_type'] ?: 'application/octet-stream');

// Disposition par défaut : inline pour types affichables nativement, attachment sinon
$inlineSafe = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'txt', 'html', 'htm', 'csv', 'mp4', 'webm', 'mp3'];
if ($disposition === '') {
    $disposition = in_array($ext, $inlineSafe, true) ? 'inline' : 'attachment';
}
if ($disposition !== 'inline') $disposition = 'attachment';

// .msg : forcer attachment (les browsers ne savent pas afficher Outlook)
if ($ext === 'msg') $disposition = 'attachment';

$nameSafe = preg_replace('/[\r\n"]/', '_', $name);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: ' . $disposition . '; filename="' . $nameSafe . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;

/**
 * Parse minimaliste d'un .eml et rendu HTML lisible (headers + corps texte).
 * Pas d'extension PHP requise (mailparse non supposé installé sur Hostinger).
 */
function render_eml_as_html(string $raw, string $filename): void
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // Sépare headers / corps (premier double saut de ligne)
    $sepPos = strpos($raw, "\r\n\r\n");
    if ($sepPos === false) $sepPos = strpos($raw, "\n\n");
    $headersRaw = $sepPos !== false ? substr($raw, 0, $sepPos) : $raw;
    $body       = $sepPos !== false ? substr($raw, $sepPos + (substr($raw, $sepPos, 4) === "\r\n\r\n" ? 4 : 2)) : '';

    // Parse headers (gère les continuations RFC822 via leading whitespace)
    $headers = [];
    $current = '';
    foreach (preg_split('/\r?\n/', $headersRaw) as $line) {
        if ($line === '') continue;
        if (preg_match('/^\s/', $line) && $current !== '') {
            $headers[$current] .= ' ' . trim($line);
        } elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
            $current = strtolower(trim($m[1]));
            $headers[$current] = trim($m[2]);
        }
    }
    // Décodage RFC2047 ultra-basique (=?UTF-8?B?...?= ou =?UTF-8?Q?...?=)
    $decode = function (string $s): string {
        return preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
            $cs = strtoupper($m[1]);
            $enc = strtoupper($m[2]);
            $val = $m[3];
            if ($enc === 'B') $val = base64_decode($val) ?: '';
            else if ($enc === 'Q') $val = quoted_printable_decode(str_replace('_', ' ', $val));
            if ($cs !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $val = mb_convert_encoding($val, 'UTF-8', $cs);
            }
            return $val;
        }, $s);
    };

    $subject = $decode($headers['subject'] ?? '(sans sujet)');
    $from    = $decode($headers['from']    ?? '(inconnu)');
    $to      = $decode($headers['to']      ?? '');
    $cc      = $decode($headers['cc']      ?? '');
    $date    = $headers['date'] ?? '';
    $ct      = strtolower($headers['content-type'] ?? '');
    $cte     = strtolower($headers['content-transfer-encoding'] ?? '');

    // Décodage du corps si transfer-encoding détecté (mono-part uniquement, pas multipart)
    $bodyDecoded = $body;
    if (strpos($ct, 'multipart/') === false) {
        if ($cte === 'base64') $bodyDecoded = base64_decode($body) ?: $body;
        elseif ($cte === 'quoted-printable') $bodyDecoded = quoted_printable_decode($body);
        // Charset
        if (preg_match('/charset="?([^";\s]+)"?/i', $ct, $m)) {
            $cs = strtoupper($m[1]);
            if ($cs !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $bodyDecoded = mb_convert_encoding($bodyDecoded, 'UTF-8', $cs);
            }
        }
    } else {
        $bodyDecoded = "(message multipart — affichage simplifié non disponible. Télécharger le .eml pour le voir dans un client mail.)\n\n" . $body;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
       . '<title>' . $h($subject) . '</title>'
       . '<style>'
       . 'body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;margin:20px auto;padding:0 20px;color:#0f172a;background:#f8fafc}'
       . '.eml-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05)}'
       . '.eml-h1{font-size:18px;margin:0 0 16px;color:#0f172a;padding-bottom:12px;border-bottom:2px solid #f1f5f9}'
       . '.eml-meta{display:grid;grid-template-columns:80px 1fr;gap:6px 12px;font-size:13px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9}'
       . '.eml-meta dt{font-weight:600;color:#64748b}'
       . '.eml-meta dd{margin:0;color:#0f172a;word-break:break-word}'
       . '.eml-body{white-space:pre-wrap;word-break:break-word;font-size:13.5px;line-height:1.55;color:#1e293b;font-family:ui-sans-serif,system-ui,sans-serif}'
       . '.eml-actions{margin-top:20px;display:flex;gap:8px}'
       . '.eml-btn{padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;display:inline-block}'
       . '.eml-btn-primary{background:#0ea5e9;color:#fff}'
       . '.eml-btn-ghost{background:#fff;color:#0369a1;border:1px solid #0ea5e9}'
       . '</style></head><body>'
       . '<div class="eml-card">'
       . '<h1 class="eml-h1">📧 ' . $h($subject) . '</h1>'
       . '<dl class="eml-meta">'
       . '<dt>De</dt><dd>' . $h($from) . '</dd>'
       . ($to   ? '<dt>À</dt><dd>'   . $h($to)   . '</dd>' : '')
       . ($cc   ? '<dt>Cc</dt><dd>'  . $h($cc)   . '</dd>' : '')
       . ($date ? '<dt>Date</dt><dd>'. $h($date) . '</dd>' : '')
       . '<dt>Fichier</dt><dd><code>' . $h($filename) . '</code></dd>'
       . '</dl>'
       . '<div class="eml-body">' . $h($bodyDecoded) . '</div>'
       . '<div class="eml-actions">'
       . '<a class="eml-btn eml-btn-primary" href="?item_id=' . (int)($_GET['item_id'] ?? 0) . '&disposition=attachment">⬇ Télécharger .eml</a>'
       . '<a class="eml-btn eml-btn-ghost" href="javascript:window.close()">Fermer</a>'
       . '</div>'
       . '</div></body></html>';
}
