<?php
// api/portefeuille_doc.php — Sert un document GED (DPE / DIAG / BAIL) à un destinataire de portefeuille.
// Accès SANS login, garanti par le jeton de l'envoi (?t=) + appartenance du doc à un bien du portefeuille.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

header('X-Robots-Tag: noindex, nofollow, noarchive');
function pf_doc_stop(int $code, string $msg): void { http_response_code($code); header('Content-Type:text/plain; charset=utf-8'); echo $msg; exit; }

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$docId = (int)($_GET['doc'] ?? 0);
$mode  = ($_GET['mode'] ?? 'inline') === 'download' ? 'attachment' : 'inline';
if (strlen($token) < 20 || $docId <= 0) pf_doc_stop(400, 'Requête invalide.');

// 1) Jeton valide (actif + non expiré).
$st = $pdo->prepare("SELECT id, id_portefeuille, email_destinataire, snapshot_json, actif, date_expiration FROM portefeuille_envois WHERE token = ? LIMIT 1");
$st->execute([$token]);
$envoi = $st->fetch(PDO::FETCH_ASSOC);
if (!$envoi || (int)$envoi['actif'] !== 1) pf_doc_stop(403, 'Accès clôturé.');
if (!empty($envoi['date_expiration']) && strtotime((string)$envoi['date_expiration']) < time()) pf_doc_stop(403, 'Accès expiré.');

// 2) Le document doit être rattaché à un BIEN du portefeuille de cet envoi, ET :
//    - soit explicitement coché par l'agence (documents_joints) → autorisé quel que soit le type,
//    - soit (rétrocompat) d'un type autorisé (DPE/DIAG/BAIL/Surface/Taxe/EDL entrée).
$idsBien = [];
$explicitDocIds = [];
try {
    $pb = $pdo->prepare("SELECT id_bien, documents_joints FROM portefeuille_biens WHERE id_portefeuille = ?");
    $pb->execute([(int)$envoi['id_portefeuille']]);
    foreach ($pb->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idsBien[] = (int)$r['id_bien'];
        if (!empty($r['documents_joints'])) {
            $ids = json_decode((string)$r['documents_joints'], true);
            if (is_array($ids)) foreach ($ids as $v) { if ((int)$v > 0) $explicitDocIds[(int)$v] = true; }
        }
    }
} catch (Throwable $ex) {}
if (!$idsBien) {   // repli : biens depuis le snapshot du jeton
    $snap = json_decode((string)$envoi['snapshot_json'], true) ?: [];
    $idsBien = array_values(array_filter(array_map(fn($l) => (int)($l['id_bien'] ?? 0), $snap['lignes'] ?? [])));
}
$idsBien = array_values(array_unique(array_filter($idsBien)));
if (!$idsBien) pf_doc_stop(403, 'Document non autorisé.');
$inBien = implode(',', $idsBien);

$docTypes = ['BAIL', 'BAIL_SIGNE', 'DPE', 'DIAG_DPE', 'DIAG', 'DIAGNOSTIC', 'DDT',
             'SURFACE_CARREZ', 'CARREZ', 'BOUTIN', 'SURFACE',
             'TAXE_FONCIERE', 'TF', 'EDL_ENTREE'];

if (isset($explicitDocIds[$docId])) {
    // Coché explicitement : on vérifie seulement qu'il est actif et rattaché à un bien du portefeuille.
    $chk = $pdo->prepare("SELECT gd.id, gd.name_file, gd.name_display, gd.mime_type, gd.final_destination, gd.metadata, gd.storage_provider
                          FROM ged_documents gd
                          WHERE gd.id = ? AND gd.status='active'
                            AND (gd.id_bien IN ($inBien)
                                 OR EXISTS(SELECT 1 FROM ged_document_links gdl
                                           WHERE gdl.document_id=gd.id AND gdl.entity_type='BIEN' AND gdl.entity_id IN ($inBien)))
                          LIMIT 1");
    $chk->execute([$docId]);
} else {
    $inType = implode(',', array_fill(0, count($docTypes), '?'));
    $chk = $pdo->prepare("SELECT gd.id, gd.name_file, gd.name_display, gd.mime_type, gd.final_destination, gd.metadata, gd.storage_provider
                          FROM ged_documents gd
                          JOIN ged_document_links gdl ON gdl.document_id = gd.id
                          WHERE gd.id = ? AND gd.status='active'
                            AND gdl.entity_type='BIEN' AND gdl.entity_id IN ($inBien)
                            AND UPPER(gd.document_type) IN ($inType)
                          LIMIT 1");
    $chk->execute(array_merge([$docId], $docTypes));
}
$doc = $chk->fetch(PDO::FETCH_ASSOC);
if (!$doc) pf_doc_stop(403, 'Document non autorisé.');

// 3) Résolution du fichier physique local (comme ged_doc_serve : final_destination → metadata.public_url).
$publicHtml = dirname(__DIR__);
$path = '';
foreach ([$doc['final_destination'] ?? '', null] as $cand) {
    if ($cand) { $p = $publicHtml . '/' . ltrim((string)$cand, '/'); if (is_file($p)) { $path = $p; break; } }
}
if (!$path && !empty($doc['metadata'])) {
    $meta = json_decode((string)$doc['metadata'], true) ?: [];
    $pub  = (string)($meta['public_url'] ?? '');
    if ($pub) { $p = $publicHtml . '/' . ltrim($pub, '/'); if (is_file($p)) $path = $p; }
}
if (!$path) pf_doc_stop(404, 'Document momentanément indisponible. Contactez votre conseiller.');

// Sécurité : le chemin réel doit rester sous /uploads ou /storage (anti-traversal).
$real = realpath($path);
if ($real === false || !preg_match('#[\\\\/](uploads|storage)[\\\\/]#i', $real)) pf_doc_stop(403, 'Accès refusé.');

$mime = (string)($doc['mime_type'] ?: 'application/pdf');
$fname = (string)($doc['name_display'] ?: $doc['name_file'] ?: 'document');
if (!preg_match('/\.[a-z0-9]{2,5}$/i', $fname)) $fname .= '.pdf';

// Journal d'événements : téléchargement d'un document (best-effort).
require_once __DIR__ . '/../inc/portefeuille_track.php';
pf_track($pdo, $envoi, 'download', ['doc_id' => $docId, 'doc_label' => (string)($doc['name_display'] ?: $doc['name_file'] ?: ('Doc ' . $docId))]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($real));
header('Content-Disposition: ' . $mode . '; filename="' . str_replace('"', '', $fname) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');
readfile($real);
exit;
