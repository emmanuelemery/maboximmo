<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_pdf_download.php — Téléchargement contrôlé d'un support PDF
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Usage :
 *   /mbi_supports_pdf_download.php?id=<support_id>           (inline)
 *   /mbi_supports_pdf_download.php?id=<support_id>&dl=1      (attachment)
 *
 * Garanties V1 :
 *   - Scope multi-tenant : un user ne peut télécharger que les supports
 *     de sa société (sauf super-admin role=1)
 *   - Fiches visite interne (is_interne=1) :
 *       → réservées aux rôles agence/admin (role 1, 2, 7) et au user créateur
 *       → journalisation error_log (audit V1, table dédiée prévue V2)
 *   - Headers explicites Content-Type / Content-Length / Content-Disposition
 *   - Anti-cache / anti-MIME-sniff
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$supportId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$forceAttachment = isset($_GET['dl']) && $_GET['dl'] === '1';

if ($supportId <= 0) {
    http_response_code(400);
    exit('Paramètre id manquant.');
}

// Charge le support
try {
    $st = $pdo->prepare("
        SELECT s.*, b.id_societe AS bien_id_societe
        FROM mbi_supports_commerciaux s
        LEFT JOIN biens b ON b.id = s.id_bien
        WHERE s.id = :id AND s.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([':id' => $supportId]);
    $support = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[mbi_supports_pdf_download] ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur lecture du support.');
}
if (!$support) {
    http_response_code(404);
    exit('Support introuvable.');
}

// Scope multi-tenant
$roleId    = (int)($_SESSION['id_role'] ?? 0);
$idSocSess = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$isSuperAdmin = ($roleId === 1);

if (!$isSuperAdmin) {
    $idSocSupport = (int)($support['id_societe'] ?? $support['bien_id_societe'] ?? 0);
    if ($idSocSess === null || $idSocSupport === 0 || $idSocSupport !== $idSocSess) {
        http_response_code(403);
        exit('Accès refusé (scope société).');
    }
}

// Restriction supplémentaire pour les fiches internes
$isInterne = ((int)($support['is_interne'] ?? 0) === 1);
if ($isInterne) {
    $rolesAutorises = [1, 2, 7]; // admin, manager, super-admin
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $isAuteur = ($userId > 0 && $userId === (int)$support['id_user']);
    if (!in_array($roleId, $rolesAutorises, true) && !$isAuteur) {
        http_response_code(403);
        exit('Accès refusé : document interne réservé à l\'agence.');
    }

    // Journalisation (V1 : error_log — V2 : table dédiée)
    error_log(sprintf(
        '[mbi_supports_pdf_download INTERNE] support_id=%d id_bien=%d user=%d role=%d ip=%s',
        $supportId,
        (int)($support['id_bien'] ?? 0),
        $userId,
        $roleId,
        $_SERVER['REMOTE_ADDR'] ?? '?'
    ));
}

// Résout le chemin disque
$cheminRel = (string)($support['fichier_pdf_path'] ?? $support['chemin_stockage'] ?? '');
if ($cheminRel === '') {
    http_response_code(404);
    exit('Aucun fichier associé à ce support.');
}
$cheminAbs = __DIR__ . '/' . ltrim($cheminRel, '/');
if (!is_file($cheminAbs) || !is_readable($cheminAbs)) {
    http_response_code(404);
    exit('Fichier introuvable sur le serveur : ' . htmlspecialchars(basename($cheminRel)));
}

// Nom proposé au navigateur
$nomFichier = (string)($support['nom_fichier'] ?? basename($cheminAbs));
if ($nomFichier === '' || pathinfo($nomFichier, PATHINFO_EXTENSION) === '') {
    $nomFichier = 'support_' . $supportId . '.pdf';
}

// Headers
$disposition = $forceAttachment ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($cheminAbs));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '_', $nomFichier) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

// Stream le fichier
readfile($cheminAbs);
exit;
