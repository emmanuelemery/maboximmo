<?php
declare(strict_types=1);

/**
 * POST /api/annonce_remonter.php
 *
 * Bump la date_modification d'une annonce pour la faire remonter dans le flux
 * Ubiflow / LBC au prochain export. C'est l'action déclenchée par le bouton
 * "🚀 Remonter" du modal "Biens diffusés" (agency_dashboard_diffusion.php).
 *
 * Hypothèse : LBC priorise les annonces récemment modifiées dans le flux XML
 * (= "Remontée aujourd'hui" sur l'interface LBC). Bumper la date_modification
 * fait remonter l'annonce en tête du tri, donc LBC la republie en priorité.
 *
 * Paramètres POST : id_annonce, csrf_token (token "ubiflow_trigger" — partagé avec
 *                   les autres boutons Ubiflow du dashboard diffusion)
 * Retour JSON     : { ok: bool, error?: string }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
    }
    verify_csrf_any('ubiflow_trigger');

    $pdo       = $GLOBALS['pdo'];
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $idAnnonce = (int)($_POST['id_annonce'] ?? 0);
    if ($idAnnonce <= 0) {
        throw new RuntimeException('id_annonce manquant');
    }

    // Vérifie que l'annonce existe + scope société (via le bien)
    $st = $pdo->prepare("
        SELECT a.id, b.id_societe
        FROM annonces a
        JOIN biens b ON b.id = a.id_bien
        WHERE a.id = ?
        LIMIT 1
    ");
    $st->execute([$idAnnonce]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Annonce introuvable');
    }
    // Super-admin (role=1) bypass le filtre société
    $isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
    if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        throw new RuntimeException('Accès refusé');
    }

    // Le bump = simple UPDATE date_modification → NOW(). Au prochain export Ubiflow,
    // cette annonce sera en tête du tri ORDER BY date_modification DESC.
    $pdo->prepare("UPDATE annonces SET date_modification = NOW() WHERE id = ?")
        ->execute([$idAnnonce]);

    exit(json_encode(['ok' => true, 'id_annonce' => $idAnnonce, 'bumped_at' => date('Y-m-d H:i:s')]));

} catch (Throwable $e) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
}
