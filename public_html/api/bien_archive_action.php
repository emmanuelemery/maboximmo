<?php
// api/bien_archive_action.php — Actions sur un bien ARCHIVÉ depuis la fiche immeuble.
//   action=vendu      → statut_bien='vendu' (le bien RESTE en base)
//   action=supprimer  → snapshot dans biens_supprimes puis DELETE de biens (disparaît)
// GARDE-FOU : refus si annonce ACTIVE (publiée/diffusée). Sécurité : login + CSRF + staff.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Méthode non autorisée.']));
}

verify_csrf_any('archiver_bien');

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 3, 7], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Accès refusé.']));
}

$idBien = (int)($_POST['id_bien'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($idBien <= 0 || !in_array($action, ['vendu', 'supprimer'], true)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Paramètres invalides.']));
}

// Le bien doit exister ET être archivé (ces actions ne se font que sur la liste des archivés).
$chk = $pdo->prepare("SELECT * FROM biens WHERE id = ? LIMIT 1");
$chk->execute([$idBien]);
$bien = $chk->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit(json_encode(['success' => false, 'message' => 'Bien introuvable.']));
}
if ((string)$bien['statut_bien'] !== 'archive') {
    exit(json_encode(['success' => false, 'message' => 'Action réservée aux biens archivés.']));
}

// GARDE-FOU annonce active (vaut pour les deux actions).
$an = $pdo->prepare("SELECT COUNT(*) FROM annonces WHERE id_bien = ? AND (statut='publiee' OR etat_publication='diffusee')");
$an->execute([$idBien]);
if ((int)$an->fetchColumn() > 0) {
    http_response_code(409);
    exit(json_encode(['success' => false, 'code' => 'ANNONCE_ACTIVE',
        'message' => "Ce bien a une annonce active. Retirez-la d'abord."]));
}

// ─── Action VENDU : le bien reste, on change juste le statut ───
if ($action === 'vendu') {
    try {
        $pdo->prepare("UPDATE biens SET statut_bien='vendu', date_modification=NOW() WHERE id=?")->execute([$idBien]);
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]));
    }
    exit(json_encode(['success' => true, 'action' => 'vendu', 'id_bien' => $idBien]));
}

// ─── Action SUPPRIMER : snapshot puis retrait de biens ───
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO biens_supprimes
        (id_bien_origine, reference_bien, code_crg, id_immeuble, id_proprietaire, id_societe, id_agence,
         payload, raison, supprime_par)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        $idBien,
        $bien['reference_bien'] ?? null,
        $bien['code_crg'] ?? null,
        $bien['id_immeuble'] ?? null,
        $bien['id_proprietaire'] ?? null,
        $bien['id_societe'] ?? null,
        $bien['id_agence'] ?? null,
        json_encode($bien, JSON_UNESCAPED_UNICODE),
        (string)($_POST['raison'] ?? '') ?: null,
        (int)current_user_id() ?: null,
    ]);

    // Retire les annonces INACTIVES liées (les actives sont déjà bloquées plus haut)
    $pdo->prepare("DELETE FROM annonces WHERE id_bien = ?")->execute([$idBien]);

    // Retrait du bien (les FK CASCADE éventuelles s'appliquent ; crg_situations_locataires.id_bien = SET NULL)
    $pdo->prepare("DELETE FROM biens WHERE id = ?")->execute([$idBien]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    exit(json_encode(['success' => false,
        'message' => "Suppression impossible (lien bloquant ?) : " . $e->getMessage()]));
}

echo json_encode(['success' => true, 'action' => 'supprimer', 'id_bien' => $idBien]);
