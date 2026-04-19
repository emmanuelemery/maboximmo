<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    exit(json_encode(['ok' => false, 'error' => 'Corps JSON invalide']));
}

// CSRF : token dans le body JSON (verify_csrf_any lit $_POST ou header, pas le body JSON)
$csrfKey   = '_csrf_ajouter_bien';
$sessToken = $_SESSION[$csrfKey] ?? '';
$bodyToken = (string)($body['csrf_token'] ?? '');
if (!$sessToken || !$bodyToken || !hash_equals($sessToken, $bodyToken)) {
    http_response_code(419);
    exit(json_encode(['ok' => false, 'error' => 'Requête invalide (CSRF).']));
}

$pdo      = $GLOBALS['pdo'];
$agenceId = (int)($_SESSION['id_agence'] ?? 0);

$nom  = trim((string)($body['nom']  ?? ''));
if ($nom === '') {
    exit(json_encode(['ok' => false, 'error' => 'Le nom est obligatoire']));
}

$civilite   = trim((string)($body['civilite']   ?? ''));
$prenom     = trim((string)($body['prenom']     ?? ''));
$societe    = trim((string)($body['societe']    ?? ''));
$email      = trim((string)($body['email']      ?? ''));
$telephone  = trim((string)($body['telephone']  ?? ''));
$telephone2 = trim((string)($body['telephone_2']?? ''));
$adresse1   = trim((string)($body['adresse_1']  ?? ''));
$ville      = trim((string)($body['ville']      ?? ''));
$cp         = trim((string)($body['code_postal']?? ''));
$commentaire= trim((string)($body['commentaire']?? ''));
$typePersonne = in_array($body['type_personne'] ?? '', ['physique','morale'], true)
              ? $body['type_personne'] : 'physique';

try {
    // ── Vérification doublon avant création ──────────────────────
    $doublonWhere  = [];
    $doublonParams = [];
    if ($email !== '') {
        $doublonWhere[]  = 'email = ?';
        $doublonParams[] = $email;
    }
    if ($telephone !== '') {
        $telNorm = preg_replace('/[\s.\-]/', '', $telephone);
        $doublonWhere[]  = "REPLACE(REPLACE(REPLACE(telephone,' ',''),'.',''),'-','') = ?";
        $doublonParams[] = $telNorm;
    }
    if ($nom !== '') {
        if ($prenom !== '') {
            $doublonWhere[]  = '(nom = ? AND prenom = ?)';
            $doublonParams[] = $nom;
            $doublonParams[] = $prenom;
        } else {
            $doublonWhere[]  = 'nom = ?';
            $doublonParams[] = $nom;
        }
    }
    if (!empty($doublonWhere)) {
        $existStmt = $pdo->prepare(
            'SELECT id, civilite, nom, prenom, societe, email, telephone, telephone_2, adresse_1, ville, code_postal
             FROM proprietaires WHERE (' . implode(' OR ', $doublonWhere) . ') LIMIT 1'
        );
        $existStmt->execute($doublonParams);
        $existing = $existStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $label = trim(($existing['prenom'] ? $existing['prenom'] . ' ' : '') . $existing['nom']);
            exit(json_encode([
                'ok'       => true,
                'id'       => (int)$existing['id'],
                'nom'      => $label,
                'existant' => true,
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    // ── Création en transaction : tiers + tiers_roles + proprietaires ──
    // (Phase 3.3 — double écriture vers la source unique tiers)
    $societeId = (int)($_SESSION['id_societe'] ?? 0) ?: null;
    $userId    = (int)current_user_id() ?: null;
    $typeTiers = $typePersonne === 'morale' ? 'personne_morale' : 'personne_physique';
    $nomAffichage = $societe !== '' ? $societe : trim($prenom . ' ' . $nom);

    $pdo->beginTransaction();
    try {
        // 1. INSERT tiers
        $stT = $pdo->prepare("
            INSERT INTO tiers
                (id_societe, id_agence, type_tiers,
                 civilite, nom, prenom, raison_sociale, nom_affichage,
                 email, telephone, telephone_secondaire,
                 adresse_ligne1, code_postal, ville, pays,
                 commentaire, actif, source_creation, id_user_createur)
            VALUES
                (:id_societe, :id_agence, :type_tiers,
                 :civilite, :nom, :prenom, :raison_sociale, :nom_affichage,
                 :email, :telephone, :telephone2,
                 :adresse1, :cp, :ville, 'France',
                 :commentaire, 1, 'agency_proprietaires_modal', :user_id)
        ");
        $stT->execute([
            ':id_societe' => $societeId,
            ':id_agence'  => $agenceId ?: null,
            ':type_tiers' => $typeTiers,
            ':civilite'   => $civilite  ?: null,
            ':nom'        => $nom,
            ':prenom'     => $prenom    ?: null,
            ':raison_sociale' => $societe ?: null,
            ':nom_affichage'  => $nomAffichage,
            ':email'      => $email     ?: null,
            ':telephone'  => $telephone ?: null,
            ':telephone2' => $telephone2?: null,
            ':adresse1'   => $adresse1  ?: null,
            ':cp'         => $cp        ?: null,
            ':ville'      => $ville     ?: null,
            ':commentaire'=> $commentaire?: null,
            ':user_id'    => $userId,
        ]);
        $idTiers = (int)$pdo->lastInsertId();

        // 2. INSERT tiers_roles (role=proprietaire, objet global)
        $pdo->prepare("
            INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif)
            VALUES (?, 'proprietaire', NULL, NULL, 1)
        ")->execute([$idTiers]);

        // 3. INSERT proprietaires (legacy + lien vers tiers)
        $stmt = $pdo->prepare("
            INSERT INTO proprietaires
                (id_tiers, id_agence, type_personne, civilite, nom, prenom, societe,
                 email, telephone, telephone_2,
                 adresse_1, code_postal, ville,
                 commentaire, actif, date_creation, date_modification)
            VALUES
                (?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, 1, NOW(), NOW())
        ");
        $stmt->execute([
            $idTiers,
            $agenceId ?: null,
            $typePersonne,
            $civilite  ?: null,
            $nom,
            $prenom    ?: null,
            $societe   ?: null,
            $email     ?: null,
            $telephone ?: null,
            $telephone2?: null,
            $adresse1  ?: null,
            $cp        ?: null,
            $ville     ?: null,
            $commentaire?: null,
        ]);
        $id = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $txEx) {
        $pdo->rollBack();
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => 'Erreur transaction : ' . $txEx->getMessage()]));
    }

    exit(json_encode([
        'ok'       => true,
        'id'       => $id,
        'id_tiers' => $idTiers,
        'nom'      => ($prenom ? $prenom . ' ' : '') . $nom,
    ], JSON_UNESCAPED_UNICODE));

} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
