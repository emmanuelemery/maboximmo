<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

// ── Vérification CSRF (token passé en GET) ──
$csrfToken   = (string)($_GET['csrf'] ?? '');
$sessionToken = $_SESSION['_csrf_default'] ?? '';
if (!$sessionToken || !$csrfToken || !hash_equals($sessionToken, $csrfToken)) {
    http_response_code(419);
    header('Location: ' . app_url('/bien_liste.php?err=csrf'));
    exit;
}

// ── Récupération et validation de l'ID ──
$bienId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($bienId <= 0) {
    header('Location: ' . app_url('/bien_liste.php?err=id_invalide'));
    exit;
}

// ── Vérification que le bien appartient à la société ──
$idSociete = (int)($_SESSION['id_societe'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM biens WHERE id = ? AND id_societe = ?");
$stmt->execute([$bienId, $idSociete]);
if (!$stmt->fetch()) {
    header('Location: ' . app_url('/bien_liste.php?err=bien_introuvable'));
    exit;
}

// ── Archivage (pas de suppression) ──
try {
    // Passe le bien en statut "archive"
    $pdo->prepare("UPDATE biens SET statut_bien = 'archive' WHERE id = ? AND id_societe = ?")
        ->execute([$bienId, $idSociete]);

    // Désactive la visibilité portails des annonces liées
    $pdo->prepare("UPDATE annonces SET visible_portails = 0 WHERE id_bien = ?")
        ->execute([$bienId]);

    header('Location: ' . app_url('/bien_liste.php?success=bien_archive'));
    exit;
} catch (Throwable $e) {
    error_log('[bien_supprimer] Erreur archivage bien #' . $bienId . ' : ' . $e->getMessage());
    header('Location: ' . app_url('/bien_liste.php?err=erreur_archivage'));
    exit;
}
