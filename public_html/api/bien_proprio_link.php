<?php
// api/bien_proprio_link.php — Lie un tiers en tant que propriétaire d'un bien
//
// Historique : biens.id_proprietaire a une FK vers `proprietaires.id` (table
// legacy), PAS vers `tiers.id`. Pour lier un tiers au bien, il faut donc :
//   1. S'assurer qu'une ligne `proprietaires` existe avec id_tiers = <X>
//      (création à la volée si absente, copie des données du tiers)
//   2. UPDATE biens.id_proprietaire = <proprietaires.id>
//
// Dissocier : passer id_tiers = 0 pour mettre biens.id_proprietaire = NULL.
//
// POST JSON : { id_bien, id_tiers }
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

// CSRF (token dans body JSON ou header X-CSRF-Token)
verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    exit(json_encode(['ok' => false, 'error' => 'JSON invalide']));
}

$idBien  = isset($body['id_bien'])  && ctype_digit((string)$body['id_bien'])  ? (int)$body['id_bien']  : 0;
$idTiers = isset($body['id_tiers']) && ctype_digit((string)$body['id_tiers']) ? (int)$body['id_tiers'] : 0;
if ($idBien <= 0) exit(json_encode(['ok' => false, 'error' => 'id_bien manquant']));

try {
    // Scope : le bien doit appartenir à la société de l'user (ou super admin)
    $roleId = (int)($_SESSION['id_role'] ?? 0);
    if ($roleId !== 1) {
        $stB = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ? LIMIT 1");
        $stB->execute([$idBien]);
        $rowB = $stB->fetch(PDO::FETCH_ASSOC);
        if (!$rowB) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
        if ($societeId > 0 && (int)$rowB['id_societe'] !== $societeId) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
        }
    }

    // ── Cas 1 : dissociation (id_tiers = 0) ─────────────────────
    if ($idTiers === 0) {
        $pdo->prepare("UPDATE biens SET id_proprietaire = NULL, date_modification = NOW() WHERE id = ?")
            ->execute([$idBien]);
        exit(json_encode(['ok' => true, 'action' => 'unlinked', 'id_bien' => $idBien]));
    }

    // ── Cas 2 : liaison ─────────────────────────────────────────
    // Charger le tiers
    $stT = $pdo->prepare("
        SELECT id, type_tiers, civilite, nom, prenom, raison_sociale,
               email, telephone, adresse_ligne1, code_postal, ville
        FROM tiers
        WHERE id = ? AND actif = 1
        LIMIT 1
    ");
    $stT->execute([$idTiers]);
    $tiers = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$tiers) exit(json_encode(['ok' => false, 'error' => 'Tiers introuvable ou inactif']));

    // Cherche une ligne proprietaires existante pour ce tiers
    $stP = $pdo->prepare("SELECT id FROM proprietaires WHERE id_tiers = ? ORDER BY id DESC LIMIT 1");
    $stP->execute([$idTiers]);
    $idProprioLegacy = (int)($stP->fetchColumn() ?: 0);

    // Sinon, crée la ligne (données copiées depuis tiers)
    if ($idProprioLegacy <= 0) {
        $typePersonne = $tiers['type_tiers'] === 'personne_morale' ? 'morale' : 'physique';
        $nomLegacy    = $typePersonne === 'morale'
            ? (string)($tiers['raison_sociale'] ?: $tiers['nom'] ?: '')
            : (string)($tiers['nom'] ?: '');
        if ($nomLegacy === '') {
            exit(json_encode(['ok' => false, 'error' => 'Tiers sans nom — impossible de créer la ligne propriétaire legacy']));
        }
        $pdo->prepare("
            INSERT INTO proprietaires
                (id_tiers, id_agence, type_personne, civilite, nom, prenom, societe,
                 email, telephone, adresse_1, code_postal, ville,
                 actif, date_creation, date_modification)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ")->execute([
            $idTiers,
            $agenceId ?: null,
            $typePersonne,
            $tiers['civilite']       ?: null,
            $nomLegacy,
            $tiers['prenom']         ?: null,
            $tiers['raison_sociale'] ?: null,
            $tiers['email']          ?: null,
            $tiers['telephone']      ?: null,
            $tiers['adresse_ligne1'] ?: null,
            $tiers['code_postal']    ?: null,
            $tiers['ville']          ?: null,
        ]);
        $idProprioLegacy = (int)$pdo->lastInsertId();
    }

    // UPDATE biens.id_proprietaire (FK vers proprietaires.id)
    $pdo->prepare("UPDATE biens SET id_proprietaire = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$idProprioLegacy, $idBien]);

    // Auto-activation brouillon -> actif si conditions réunies
    require_once dirname(__DIR__) . '/inc/bien_auto_activate.php';
    $autoActivated = bien_maybe_activate($pdo, $idBien);

    exit(json_encode([
        'ok'                 => true,
        'action'             => 'linked',
        'id_bien'            => $idBien,
        'id_tiers'           => $idTiers,
        'id_proprio_legacy'  => $idProprioLegacy,
        'auto_activated'     => $autoActivated,
    ]));
} catch (Throwable $e) {
    error_log('[bien_proprio_link] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
