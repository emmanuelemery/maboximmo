<?php
// api/bien_marquer_vendu.php — Marque un bien VENDU depuis sa fiche.
// Effet : sort le bien des « à vendre » (type_commercialisation = NULL) + pose la date de retrait
// (marqueur vendu) — le bien RESTE en base et dans le patrimoine (historique conservé).
// Sécurité : login + CSRF + (staff agence OU bailleur propriétaire du bien).
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_missions.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$idBien = (int)($_POST['id_bien'] ?? 0);
$retour = (string)($_POST['retour'] ?? '');
verify_csrf('bien_vendu');   // champ csrf_token dans le formulaire de la fiche

if ($idBien <= 0) { http_response_code(400); exit('Bien invalide.'); }

// Droits : staff agence OU bailleur ayant ce propriétaire assigné.
$roleId  = (int)current_role_id();
$isStaff = in_array($roleId, [1, 2, 3, 7], true);
$ok = $isStaff;
if (!$ok) {
    $uid = (int)current_user_id();
    $chk = $pdo->prepare("SELECT 1 FROM biens b JOIN user_proprietaires up ON up.id_proprietaire = b.id_proprietaire
                          WHERE b.id = ? AND up.id_user = ? LIMIT 1");
    $chk->execute([$idBien, $uid]);
    $ok = (bool)$chk->fetchColumn();
}
if (!$ok) { http_response_code(403); exit('Accès refusé.'); }

try {
    $pdo->beginTransaction();
    // VENDU = succès : mission vente → 'vendu', bien → statut_bien 'vendu',
    // dossier → étape 'acte', et type_commercialisation re-dérivé (→ NULL).
    // On ne le met PLUS à NULL en direct (miroir géré par derive_*).
    mandat_vente_vendu($pdo, $idBien);
    // Conserver le marqueur date de retrait commercialisation (info de fiche)
    $pdo->prepare("UPDATE biens
        SET date_retrait_commercialisation = COALESCE(date_retrait_commercialisation, CURDATE()),
            date_modification = NOW()
        WHERE id = ?")->execute([$idBien]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); exit('Erreur : ' . $e->getMessage());
}

// Retour vers la fiche (ou l'URL fournie), avec confirmation.
$dest = $retour !== '' && strpos($retour, '://') === false
    ? $retour
    : app_url('/bien_360.php?id=' . $idBien);
$dest .= (strpos($dest, '?') !== false ? '&' : '?') . 'vendu=1';
header('Location: ' . $dest);
exit;
