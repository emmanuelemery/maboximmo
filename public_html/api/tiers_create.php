<?php
// api/tiers_create.php — Création d'un tiers (+ rôles optionnels)
// POST JSON : { type_tiers, nom, prenom, raison_sociale, email, telephone, ... , roles: [{role_code, objet_type, id_objet, id_mandat, quote_part}] }
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST requis'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = $GLOBALS['pdo'];
$idSociete = (int)($_SESSION['id_societe'] ?? 0) ?: null;
$idAgence  = (int)($_SESSION['id_agence']  ?? 0) ?: null;
$userId    = (int)current_user_id();

// Lecture du body JSON
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide'], JSON_UNESCAPED_UNICODE);
    exit;
}

$typeTiers = (string)($body['type_tiers'] ?? 'personne_physique');
$allowedTypes = ['personne_physique','personne_morale','entite_juridique','indivision','syndicat_coprop'];
if (!in_array($typeTiers, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'type_tiers invalide'], JSON_UNESCAPED_UNICODE);
    exit;
}

$nom            = trim((string)($body['nom'] ?? ''));
$prenom         = trim((string)($body['prenom'] ?? ''));
$raisonSociale  = trim((string)($body['raison_sociale'] ?? ''));

// Validation minimale selon le type
if ($typeTiers === 'personne_physique' && $nom === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Le nom est obligatoire pour une personne physique'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (in_array($typeTiers, ['personne_morale','entite_juridique','syndicat_coprop'], true) && $raisonSociale === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La raison sociale est obligatoire pour une entité morale'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roles = is_array($body['roles'] ?? null) ? $body['roles'] : [];

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("
        INSERT INTO tiers (
            id_societe, id_agence, type_tiers, sous_type,
            civilite, nom, prenom, nom_naissance, date_naissance, lieu_naissance, nationalite,
            raison_sociale, forme_juridique, siret, siren, tva_intracom, rcs,
            nom_affichage,
            email, email_secondaire, telephone, telephone_secondaire, mobile,
            adresse_ligne1, adresse_ligne2, code_postal, ville, pays,
            latitude, longitude, google_place_id, adresse_formatee,
            commentaire, notes_internes,
            source_creation, origine, id_user_createur, actif
        ) VALUES (
            :id_societe, :id_agence, :type_tiers, :sous_type,
            :civilite, :nom, :prenom, :nom_naissance, :date_naissance, :lieu_naissance, :nationalite,
            :raison_sociale, :forme_juridique, :siret, :siren, :tva_intracom, :rcs,
            :nom_affichage,
            :email, :email_secondaire, :telephone, :telephone_secondaire, :mobile,
            :adresse_ligne1, :adresse_ligne2, :code_postal, :ville, :pays,
            :latitude, :longitude, :google_place_id, :adresse_formatee,
            :commentaire, :notes_internes,
            :source_creation, :origine, :id_user_createur, 1
        )
    ");

    $nomAffichage = trim((string)($body['nom_affichage'] ?? ''));
    if ($nomAffichage === '') {
        if ($typeTiers === 'personne_physique') {
            $nomAffichage = trim($prenom . ' ' . $nom);
        } else {
            $nomAffichage = $raisonSociale;
        }
    }

    $st->execute([
        ':id_societe' => $idSociete,
        ':id_agence'  => $idAgence,
        ':type_tiers' => $typeTiers,
        ':sous_type'  => $body['sous_type'] ?? null,
        ':civilite'   => $body['civilite'] ?? null,
        ':nom'        => $nom ?: null,
        ':prenom'     => $prenom ?: null,
        ':nom_naissance' => $body['nom_naissance'] ?? null,
        ':date_naissance' => $body['date_naissance'] ?? null,
        ':lieu_naissance' => $body['lieu_naissance'] ?? null,
        ':nationalite' => $body['nationalite'] ?? null,
        ':raison_sociale' => $raisonSociale ?: null,
        ':forme_juridique' => $body['forme_juridique'] ?? null,
        ':siret'      => $body['siret'] ?? null,
        ':siren'      => $body['siren'] ?? null,
        ':tva_intracom' => $body['tva_intracom'] ?? null,
        ':rcs'        => $body['rcs'] ?? null,
        ':nom_affichage' => $nomAffichage ?: null,
        ':email'      => $body['email'] ?? null,
        ':email_secondaire' => $body['email_secondaire'] ?? null,
        ':telephone'  => $body['telephone'] ?? null,
        ':telephone_secondaire' => $body['telephone_secondaire'] ?? null,
        ':mobile'     => $body['mobile'] ?? null,
        ':adresse_ligne1' => $body['adresse_ligne1'] ?? null,
        ':adresse_ligne2' => $body['adresse_ligne2'] ?? null,
        ':code_postal'=> $body['code_postal'] ?? null,
        ':ville'      => $body['ville'] ?? null,
        ':pays'       => $body['pays'] ?? 'France',
        ':latitude'   => $body['latitude'] ?? null,
        ':longitude'  => $body['longitude'] ?? null,
        ':google_place_id' => $body['google_place_id'] ?? null,
        ':adresse_formatee' => $body['adresse_formatee'] ?? null,
        ':commentaire' => $body['commentaire'] ?? null,
        ':notes_internes' => $body['notes_internes'] ?? null,
        ':source_creation' => $body['source_creation'] ?? 'manuel',
        ':origine' => $body['origine'] ?? null,
        ':id_user_createur' => $userId ?: null,
    ]);
    $idTiers = (int)$pdo->lastInsertId();

    // Ajout des rôles
    $rolesCrees = [];
    if (!empty($roles)) {
        $stRole = $pdo->prepare("
            INSERT IGNORE INTO tiers_roles
                (id_tiers, role_code, objet_type, id_objet, id_mandat, quote_part, date_debut, date_fin, priorite, metadata, actif)
            VALUES
                (:id_tiers, :role_code, :objet_type, :id_objet, :id_mandat, :quote_part, :date_debut, :date_fin, :priorite, :metadata, 1)
        ");
        foreach ($roles as $r) {
            if (!is_array($r) || empty($r['role_code'])) continue;
            $stRole->execute([
                ':id_tiers'   => $idTiers,
                ':role_code'  => (string)$r['role_code'],
                ':objet_type' => $r['objet_type'] ?? null,
                ':id_objet'   => isset($r['id_objet']) ? (int)$r['id_objet'] : null,
                ':id_mandat'  => isset($r['id_mandat']) ? (int)$r['id_mandat'] : null,
                ':quote_part' => $r['quote_part'] ?? null,
                ':date_debut' => $r['date_debut'] ?? null,
                ':date_fin'   => $r['date_fin'] ?? null,
                ':priorite'   => isset($r['priorite']) ? (int)$r['priorite'] : 0,
                ':metadata'   => isset($r['metadata']) ? json_encode($r['metadata'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            if ($pdo->lastInsertId()) {
                $rolesCrees[] = (string)$r['role_code'];
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'id_tiers' => $idTiers,
        'nom_affichage' => $nomAffichage,
        'roles_crees' => $rolesCrees,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
}
