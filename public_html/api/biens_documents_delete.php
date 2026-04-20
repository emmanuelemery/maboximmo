<?php
// api/biens_documents_delete.php — Supprime un document rattaché à un bien
//
// Cas d'usage : l'utilisateur a uploadé un DPE / mandat / autre document en
// double, et veut retirer le doublon depuis la Card 2/3/4 de la section
// Documents de bien_detail.
//
// POST { id_doc, csrf_token }
//
// Flux :
//   1. Charge la ligne biens_documents + joint biens pour le scope société
//   2. Vérifie scope (403 si pas même société, sauf super admin)
//   3. Supprime le fichier physique sur disque (si présent)
//   4. DELETE la ligne
//
// Ne touche PAS aux tables associées (dpe_diags, mandats…) — l'user peut
// garder l'analyse même s'il supprime le PDF.
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$roleId    = (int)($_SESSION['id_role']    ?? 0);

$idDoc = isset($_POST['id_doc']) && ctype_digit((string)$_POST['id_doc']) ? (int)$_POST['id_doc'] : 0;
if ($idDoc <= 0) exit(json_encode(['ok' => false, 'error' => 'id_doc manquant']));

try {
    // Charge le doc + société du bien
    $st = $pdo->prepare("
        SELECT bd.id, bd.id_bien, bd.url_fichier, bd.nom_original, bd.type_document,
               b.id_societe
        FROM biens_documents bd
        JOIN biens b ON b.id = bd.id_bien
        WHERE bd.id = ?
        LIMIT 1
    ");
    $st->execute([$idDoc]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Document introuvable']));

    // Scope société (sauf super admin)
    if ($roleId !== 1 && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }

    // Supprime le fichier physique si présent
    $fileDeleted = false;
    if (!empty($row['url_fichier'])) {
        $fp = dirname(__DIR__) . '/' . ltrim((string)$row['url_fichier'], '/');
        if (is_file($fp) && @unlink($fp)) $fileDeleted = true;
    }

    // DELETE la ligne
    $pdo->prepare("DELETE FROM biens_documents WHERE id = ?")->execute([$idDoc]);

    exit(json_encode([
        'ok'           => true,
        'id_doc'       => $idDoc,
        'id_bien'      => (int)$row['id_bien'],
        'file_deleted' => $fileDeleted,
        'type'         => (string)$row['type_document'],
    ]));
} catch (Throwable $e) {
    error_log('[biens_documents_delete] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
