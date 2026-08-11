<?php
declare(strict_types=1);
/**
 * api/rh_bulletins_zip.php — Télécharge en UN fichier les bulletins de paie
 * déposés pour toutes les agences d'une société (ou de tout le périmètre admin).
 *
 * GET : soc=<id|0 pour toutes> & mois=<1-12> & annee=<YYYY> [& agence=<id>]
 * Réponse : un .zip en flux, ou du JSON { ok:false, error } si rien à livrer.
 *
 * POURQUOI UN ZIP ET PAS UN PDF FUSIONNÉ : un ZIP conserve un fichier par agence,
 * lisible et ré-expédiable tel quel au comptable, et il ne dépend d'aucune
 * capacité de lecture des PDF sources — il ne peut donc pas échouer sur un PDF
 * récent, contrairement à une fusion.
 *
 * Sécurité : login + privilèges paie + périmètre société de l'utilisateur.
 */
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/rh_bulletins_lib.php';
require_login();
ini_set('display_errors', '0');

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$rhAdmin = in_array($roleId, [1, 7, 8], true);   // mêmes privilèges que rh_salaires.php

/** Sort en JSON (les erreurs doivent rester lisibles côté page). */
$echec = function (string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE));
};

if (!$rhAdmin) $echec('Accès refusé.', 403);

$soc    = (int)($_GET['soc']    ?? 0);
$agence = (int)($_GET['agence'] ?? 0);
$mois   = (int)($_GET['mois']   ?? 0);
$annee  = (int)($_GET['annee']  ?? 0);
if ($mois < 1 || $mois > 12 || $annee < 2000 || $annee > 2100) $echec('Mois ou année invalide.');

/* Périmètre : un admin de société ne peut pas aspirer les bulletins d'une autre.
   Seul le super admin (rôle 1/7) voit toutes les sociétés. */
$superAdmin = in_array($roleId, [1, 7], true);
if (!$superAdmin) {
    $uid = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
    $socUser = rhb_societe_utilisateur($pdo, $uid);      // `users` fait foi, pas la session
    if ($socUser <= 0) $echec('Société de rattachement inconnue.', 403);
    if ($soc === 0)          $soc = $socUser;           // « toutes » se réduit à la sienne
    elseif ($soc !== $socUser) $echec('Hors périmètre.', 403);
}

$depots = rhb_depots($pdo, $soc, $mois, $annee, $agence);
if (!$depots) $echec("Aucun bulletin déposé pour {$mois}/{$annee} sur ce périmètre.", 404);

$tmpDir = dirname(__DIR__) . '/uploads/_tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
$zipPath = $tmpDir . '/bulletins_' . bin2hex(random_bytes(6)) . '.zip';

$res = rhb_zip($depots, $zipPath);
if (!$res['ok']) { @unlink($zipPath); $echec($res['error'] ?? 'Constitution du ZIP impossible.', 500); }

/* Un fichier manquant sur le disque ne doit pas passer inaperçu : on le signale
   dans un en-tête plutôt que d'échouer, pour ne pas priver l'utilisateur des
   agences qui, elles, sont bien là. */
if ($res['manquants']) {
    header('X-Bulletins-Manquants: ' . rawurlencode(implode(' | ', $res['manquants'])));
}

$nomSociete = $soc > 0 ? (string)($depots[0]['societe_nom'] ?? 'societe') : 'toutes-societes';
$nomZip = sprintf('bulletins_%04d-%02d_%s.zip', $annee, $mois, rhb_slug($nomSociete, 32));

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nomZip . '"');
header('Content-Length: ' . filesize($zipPath));
header('X-Bulletins-Agences: ' . (int)$res['ajoutes']);
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);          // fichier de travail : rien ne doit s'accumuler sur le disque
