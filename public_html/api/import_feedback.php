<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);

// ── GET : rapport des erreurs fréquentes ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT champ, logiciel_source,
                   COUNT(*) AS nb_total,
                   SUM(qualite='ok')       AS nb_ok,
                   SUM(qualite='mauvais')  AS nb_mauvais,
                   SUM(qualite='manquant') AS nb_manquant,
                   ROUND(100*SUM(qualite='ok')/COUNT(*),1) AS taux_reussite
            FROM bien_import_feedback
            WHERE id_societe = ?
            GROUP BY champ, logiciel_source
            ORDER BY nb_mauvais DESC, champ
        ");
        $stmt->execute([$societeId]);
        exit(json_encode(['ok' => true, 'stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
    }
}

// ── POST : enregistrer feedbacks ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) exit(json_encode(['ok' => false, 'error' => 'Corps JSON invalide']));

// CSRF depuis body JSON
$csrfKey   = '_csrf_ajouter_bien';
$sessToken = $_SESSION[$csrfKey] ?? '';
$bodyToken = (string)($body['csrf_token'] ?? '');
if (!$sessToken || !$bodyToken || !hash_equals($sessToken, $bodyToken)) {
    http_response_code(419); exit(json_encode(['ok' => false, 'error' => 'CSRF invalide']));
}

$fichierId    = (int)($body['fichier_id']     ?? 0);
$nomFiche     = trim((string)($body['nom_fiche']      ?? ''));
$logiciel     = trim((string)($body['logiciel_source']?? ''));
$feedbacks    = $body['feedbacks'] ?? [];   // [{ champ, valeur_extraite, valeur_correcte, qualite, commentaire }]

if (!$fichierId || empty($feedbacks)) {
    exit(json_encode(['ok' => false, 'error' => 'Données manquantes']));
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO bien_import_feedback
            (id_import_fichier, id_societe, id_user, nom_fiche, champ,
             valeur_extraite, valeur_correcte, qualite, commentaire, logiciel_source, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?, NOW())
    ");
    $nb = 0;
    foreach ($feedbacks as $fb) {
        $champ   = trim((string)($fb['champ']           ?? ''));
        $qualite = in_array($fb['qualite'] ?? '', ['ok','mauvais','manquant'], true)
                   ? $fb['qualite'] : 'ok';
        if (!$champ) continue;
        $stmt->execute([
            $fichierId, $societeId, $userId, $nomFiche ?: null, $champ,
            $fb['valeur_extraite'] ?? null,
            $fb['valeur_correcte'] ?? null,
            $qualite,
            $fb['commentaire'] ?? null,
            $logiciel ?: null,
        ]);
        $nb++;
    }
    exit(json_encode(['ok' => true, 'nb' => $nb], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
