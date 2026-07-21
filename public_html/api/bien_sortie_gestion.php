<?php
// api/bien_sortie_gestion.php — Sort un bien de la GESTION (archivage) depuis sa fiche.
// Motif : 'vente' (bien vendu → statut_bien='vendu') ou 'perte_gestion' (→ 'perdu_gestion').
// DANS TOUS LES CAS = ARCHIVAGE : le bien RESTE en base (historique conservé), il sort juste
// du portefeuille actif. RÉVERSIBLE (désarchivage). AUCUN lien avec le dossier de vente
// (module séparé). AUCUNE suppression physique — la suppression d'un lot est réservée au
// super admin directement en base.
// Sécurité : login + CSRF (form 'bien_sortie_gestion') + rôles staff agence.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_statut.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$idBien      = (int)($_POST['id_bien'] ?? 0);
$retour      = (string)($_POST['retour'] ?? '');
$motif       = (string)($_POST['motif'] ?? '');
$commentaire = trim((string)($_POST['commentaire'] ?? ''));
verify_csrf('bien_sortie_gestion');

if ($idBien <= 0) { http_response_code(400); exit('Bien invalide.'); }

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 3, 7], true)) { http_response_code(403); exit('Accès refusé.'); }

// Motif → statut cible + libellé.
$map = ['vente' => ['vendu', 'Vendu — sortie de gestion'],
        'perte_gestion' => ['perdu_gestion', 'Perte de gestion']];
if (!isset($map[$motif])) { http_response_code(400); exit('Motif invalide.'); }
[$statutCible, $libelle] = $map[$motif];

// Le bien doit exister et ne pas déjà être sorti du portefeuille.
$chk = $pdo->prepare("SELECT statut_bien FROM biens WHERE id = ? LIMIT 1");
$chk->execute([$idBien]);
$statut = $chk->fetchColumn();
if ($statut === false) { http_response_code(404); exit('Bien introuvable.'); }
if (in_array((string)$statut, ['vendu', 'perdu_gestion', 'archive', 'supprime'], true)) {
    $dest = $retour !== '' && strpos($retour, '://') === false ? $retour : app_url('/bien_360.php?id=' . $idBien);
    header('Location: ' . $dest); exit;
}

// Motif tracé (libellé + commentaire éventuel).
$motifTrace = $libelle . ($commentaire !== '' ? ' — ' . $commentaire : '');

// Archivage centralisé + tracé (AuditLog). bien_set_statut ne cascade les annonces que pour
// 'archive' → on ferme donc explicitement les annonces actives (le bien quitte le portefeuille).
$res = bien_set_statut($pdo, $idBien, $statutCible, ['motif' => $motifTrace, 'source' => 'bien_sortie_gestion']);
if (!$res['ok']) { http_response_code(500); exit('Erreur : ' . ($res['error'] ?? 'inconnue')); }
try { bien_statut_cascade_annonces($pdo, $idBien, 'archive'); } catch (Throwable $e) { /* best-effort */ }

$dest = $retour !== '' && strpos($retour, '://') === false ? $retour : app_url('/bien_360.php?id=' . $idBien);
$dest .= (strpos($dest, '?') !== false ? '&' : '?') . ($motif === 'vente' ? 'vendu=1' : 'perte_gestion=1');
header('Location: ' . $dest);
exit;
