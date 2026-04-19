<?php
/**
 * POST /api/annonce_creation_publish.php
 *
 * Bascule etat_publication entre 'publie' et 'brouillon' pour annonce_creation.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'POST requis']));
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = $GLOBALS['pdo'];
    $societeId = (int)($_SESSION['id_societe'] ?? 0);

    $annonceId = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
    $etat      = (string)($_POST['etat'] ?? 'publie');

    if ($annonceId <= 0) exit(json_encode(['ok' => false, 'error' => 'id manquant']));
    if (!in_array($etat, ['publie', 'brouillon'], true)) {
        exit(json_encode(['ok' => false, 'error' => 'état invalide']));
    }

    $stmt = $pdo->prepare("SELECT id_societe FROM annonces WHERE id = ? LIMIT 1");
    $stmt->execute([$annonceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'annonce introuvable']));
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'hors de votre société']));
    }

    $extra = '';
    $params = [':etat' => $etat, ':id' => $annonceId];
    if ($etat === 'publie') {
        // Si la colonne date_publication existe, on la remplit
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM annonces LIKE 'date_publication'")->fetchColumn();
            if ($has) $extra = ', date_publication = NOW()';
        } catch (Throwable) { /* ignore */ }
    }

    $pdo->prepare("UPDATE annonces SET etat_publication = :etat{$extra}, date_modification = NOW() WHERE id = :id")
        ->execute($params);

    echo json_encode(['ok' => true, 'etat' => $etat]);

} catch (Throwable $e) {
    error_log('[annonce_creation_publish] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
