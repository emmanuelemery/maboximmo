<?php
// api/annonce_create.php — Créer une annonce brouillon pour un bien
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$userId    = function_exists('current_user_id') ? (int)current_user_id() : 0;

$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
if ($bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_bien manquant']));
}

// Verification scope societe
try {
    $st = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ?");
    $st->execute([$bienId]);
    $bienSoc = (int)($st->fetchColumn() ?: 0);
    if ($societeId > 0 && $bienSoc !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Bien hors de votre société']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérification bien']));
}

// Si une annonce existe déjà pour ce bien, la retourner
try {
    $st = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$bienId]);
    $existing = (int)($st->fetchColumn() ?: 0);
    if ($existing > 0) {
        exit(json_encode(['ok' => true, 'id' => $existing, 'existed' => true]));
    }

    $st = $pdo->prepare("
        INSERT INTO annonces (id_bien, id_societe, id_agence, etat_publication,
                              visible_portails, visible_maboximmo, visible_site_perso,
                              date_creation, date_modification)
        VALUES (:bien, :soc, :ag, 'brouillon', 0, 0, 0, NOW(), NOW())
    ");
    $st->execute([
        ':bien' => $bienId,
        ':soc'  => $societeId ?: null,
        ':ag'   => $agenceId  ?: null,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Auto-lien : toutes les photos actuelles du bien sont par défaut incluses dans l'annonce.
    // Le tri d'exclusion se fait ensuite depuis la Card 2 Photos de l'annonce.
    $linked = 0;
    try {
        $stP = $pdo->prepare("SELECT id FROM biens_photos WHERE id_bien = ? ORDER BY ordre ASC, id ASC");
        $stP->execute([$bienId]);
        $photoIds = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($photoIds) {
            $ins = $pdo->prepare("INSERT INTO annonces_photos (id_annonce, id_biens_photo, ordre) VALUES (?, ?, ?)");
            foreach ($photoIds as $k => $pid) {
                $ins->execute([$id, $pid, $k]);
                $linked++;
            }
        }
    } catch (Throwable $e) {
        error_log('[annonce_create] auto-lien photos: ' . $e->getMessage());
    }

    exit(json_encode(['ok' => true, 'id' => $id, 'existed' => false, 'photos_linked' => $linked]));
} catch (Throwable $e) {
    error_log('[annonce_create] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
