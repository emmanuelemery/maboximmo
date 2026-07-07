<?php
/**
 * api/bail_pdf.php — Génère et diffuse le PDF du bail commercial (trame FNAIM + clauses greffées).
 *
 * GET : ?id=<bailId>[&dl=1]   (dl=1 force le téléchargement, sinon affichage inline)
 * Auth : user + scope société (bypass admin, exception bailleur rôles 9/10).
 * Filigrane PROJET tant que le bail n'est pas signé (géré dans bail_commercial_build_pdf).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bail_commercial_pdf.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);
$userSoc= (int)($_SESSION['id_societe'] ?? 0);
$isAdmin= ((int)($_SESSION['id_role'] ?? 0) === 1);
$bailId = (int)($_GET['id'] ?? 0);
if ($bailId <= 0) { http_response_code(400); exit('id requis'); }

// Scope : société du bail (via le bien), avec exception bailleur.
$st = $pdo->prepare("SELECT bb.id, bb.id_societe, b.id_proprietaire
    FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien WHERE bb.id = ?");
$st->execute([$bailId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Bail introuvable'); }
if (!$isAdmin && !empty($row['id_societe']) && (int)$row['id_societe'] !== $userSoc) {
    $ok = false;
    if (in_array((int)($_SESSION['id_role'] ?? 0), [9,10], true) && (int)($row['id_proprietaire'] ?? 0) > 0) {
        $c = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId, (int)$row['id_proprietaire']]); $ok = (bool)$c->fetchColumn();
    }
    if (!$ok) { http_response_code(403); exit('Hors périmètre'); }
}

// Toggle filigrane : ?final=1 → version définitive (sans PROJET) ; ?projet=1 → force le filigrane.
$forceProjet = null;
if (isset($_GET['final']))  $forceProjet = !((int)$_GET['final'] === 1);
if (isset($_GET['projet'])) $forceProjet = ((int)$_GET['projet'] === 1);
try {
    $path = bail_commercial_build_pdf($pdo, $bailId, $forceProjet);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Génération du PDF échouée : ' . $e->getMessage());
}

$fname = 'bail_' . $bailId . '.pdf';
$disp  = !empty($_GET['dl']) ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disp . '; filename="' . $fname . '"');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
@unlink($path);
