<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

verify_csrf_any('ajouter_bien');

$data       = json_decode(file_get_contents('php://input'), true);
$fichierId  = (int)($data['fichier_id'] ?? 0);
$overrides  = $data['overrides'] ?? []; // champs corrigés manuellement

if ($fichierId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'ID fichier manquant']));
}

// ── Charge les données d'import ──────────────────────────────
$stmt = $pdo->prepare("
    SELECT f.*, d.donnees_mappees, d.champs_deduits_description_json, d.description_suggeree
    FROM bien_import_fichiers f
    LEFT JOIN bien_import_donnees d ON d.id_import_fichier = f.id
    JOIN bien_imports i ON i.id = f.id_import
    WHERE f.id = ? AND i.id_societe = ?
");
$stmt->execute([$fichierId, $societeId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    exit(json_encode(['ok' => false, 'error' => 'Import introuvable']));
}
if ($row['statut'] === 'created') {
    exit(json_encode(['ok' => false, 'error' => 'Bien déjà créé depuis cet import']));
}

$champs  = json_decode($row['donnees_mappees'] ?? '{}', true) ?: [];
$deduits = json_decode($row['champs_deduits_description_json'] ?? '{}', true) ?: [];
$descSug = $row['description_suggeree'] ?? '';

// Fusionne avec les corrections manuelles
foreach ($overrides as $k => $v) {
    if ($v !== null && $v !== '') {
        $champs[$k] = ['valeur' => $v, 'score' => 'eleve', 'source' => 'manuel'];
    }
}

// Helper pour extraire la valeur d'un champ mappé
$val = fn(string $key, mixed $default = null) => $champs[$key]['valeur'] ?? $default;

// ── Construit les données du bien ────────────────────────────
$typeBien        = (string)($val('type_bien', 'appartement'));
$typeOffre       = (string)($val('type_offre', ''));
$reference       = (string)($val('reference_bien', ''));
$designation     = (string)($val('designation', '') ?: self_designation($typeBien, $val('ville'), $val('surface_habitable')));
$adresse1        = (string)$val('adresse_1', '');
$ville           = (string)$val('ville', '');
$codePostal      = (string)$val('code_postal', '');
$surface         = $val('surface_habitable') !== null ? (float)$val('surface_habitable') : null;
$surfaceTerrain  = $val('surface_terrain') !== null ? (float)$val('surface_terrain') : null;
$prixVente       = $val('prix_vente') !== null ? (float)$val('prix_vente') : null;
$loyer           = $val('loyer') !== null ? (float)$val('loyer') : null;
$charges         = $val('charges_loc') !== null ? (float)$val('charges_loc') : null;
$nbPieces        = $val('nb_pieces') !== null ? (int)$val('nb_pieces') : null;
$nbChambres      = $val('nb_chambres') !== null ? (int)$val('nb_chambres') : null;
$etage           = $val('etage') !== null ? (int)$val('etage') : null;
$anneeConstr     = $val('annee_construction') !== null ? (int)$val('annee_construction') : null;
$description     = (string)($val('description', $descSug));

function self_designation(string $type, $ville, $surface): string {
    $labels = ['appartement'=>'Appartement','maison'=>'Maison','terrain'=>'Terrain',
               'local_commercial'=>'Local commercial','bureau'=>'Bureau','parking'=>'Parking',
               'garage'=>'Garage','immeuble'=>'Immeuble','loft'=>'Loft'];
    $t = $labels[$type] ?? ucfirst($type);
    $parts = [$t];
    if ($surface) $parts[] = round((float)$surface) . ' m²';
    if ($ville) $parts[] = 'à ' . $ville;
    return implode(' ', $parts);
}

// Désignation auto si vide
if (empty($designation)) {
    $designation = self_designation($typeBien, $ville, $surface);
}

// ── Résolution id_type_bien depuis le code ───────────────────
$stmtType = $pdo->prepare("SELECT id FROM types_bien WHERE code = ? LIMIT 1");
$stmtType->execute([$typeBien]);
$typeBienId = (int)($stmtType->fetchColumn() ?: 0);
if ($typeBienId <= 0) {
    // Fallback "appartement"
    $stmtType->execute(['appartement']);
    $typeBienId = (int)($stmtType->fetchColumn() ?: 2);
}

// ── INSERT bien ──────────────────────────────────────────────
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO biens (
            id_societe, id_agence,
            id_type_bien, reference_bien, designation,
            adresse_1, code_postal, ville,
            surface_habitable, surface_terrain,
            nb_pieces, nb_chambres,
            etage, annee_construction,
            description,
            statut_bien, disponibilite_bien,
            balcon, terrasse, jardin, cave, garage, piscine,
            date_creation, date_modification
        ) VALUES (
            :id_societe, :id_agence,
            :id_type_bien, :reference, :designation,
            :adresse_1, :code_postal, :ville,
            :surface, :surface_terrain,
            :nb_pieces, :nb_chambres,
            :etage, :annee_construction,
            :description,
            'actif', 'libre',
            :balcon, :terrasse, :jardin, :cave, :garage, :piscine,
            NOW(), NOW()
        )
    ");

    $depDeduits = $deduits['dependances'] ?? [];

    $stmt->execute([
        ':id_societe'        => $societeId,
        ':id_agence'         => $agenceId ?: null,
        ':id_type_bien'      => $typeBienId,
        ':reference'         => $reference ?: null,
        ':designation'       => $designation,
        ':adresse_1'         => $adresse1 ?: null,
        ':code_postal'       => $codePostal ?: null,
        ':ville'             => $ville ?: null,
        ':surface'           => $surface,
        ':surface_terrain'   => $surfaceTerrain,
        ':nb_pieces'         => $nbPieces,
        ':nb_chambres'       => $nbChambres,
        ':etage'             => $etage,
        ':annee_construction'=> $anneeConstr,
        ':description'       => $description ?: null,
        ':balcon'            => isset($depDeduits['balcon'])   ? 1 : 0,
        ':terrasse'          => isset($depDeduits['terrasse']) ? 1 : 0,
        ':jardin'            => isset($depDeduits['jardin'])   ? 1 : 0,
        ':cave'              => isset($depDeduits['cave'])     ? 1 : 0,
        ':garage'            => isset($depDeduits['garage'])   ? 1 : 0,
        ':piscine'           => isset($depDeduits['piscine'])  ? 1 : 0,
    ]);

    $bienId = (int)$pdo->lastInsertId();

    // ── Annonce / prix ──
    if ($typeOffre !== '') {
        $prix     = $typeOffre === 'vente' ? $prixVente : null;
        $loyerVal = $typeOffre === 'location' ? $loyer : null;
        $ref = $reference ? $reference . '-A' : 'ANN-' . date('Y') . '-' . rand(1000, 9999);
        $pdo->prepare("INSERT INTO annonces
            (id_bien, id_agence, id_societe, id_user, reference_annonce, type_transaction,
             prix, loyer, loyer_cc, charges, date_creation, date_modification)
            VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), NOW())")
            ->execute([$bienId, $agenceId ?: null, $societeId, $userId ?: null,
                       $ref, $typeOffre, $prix, $loyerVal, $loyerVal, $charges]);
    }

    // ── Vues déduits ──
    if (!empty($deduits['vues'])) {
        $stmtVue = $pdo->prepare("SELECT id FROM societe_vues WHERE id_societe = ? AND code = ? LIMIT 1");
        $stmtBV  = $pdo->prepare("INSERT IGNORE INTO bien_vues (id_bien, id_societe_vue) VALUES (?,?)");
        foreach ($deduits['vues'] as $code) {
            $stmtVue->execute([$societeId, $code]);
            $svId = $stmtVue->fetchColumn();
            if ($svId) $stmtBV->execute([$bienId, (int)$svId]);
        }
    }

    // ── Chauffage déduit ──
    if (!empty($deduits['chauffage'])) {
        $stmtTC = $pdo->prepare("SELECT id FROM societe_types_chauffage WHERE id_societe = ? AND code = ? LIMIT 1");
        $stmtBTC= $pdo->prepare("INSERT IGNORE INTO bien_types_chauffage (id_bien, id_societe_type_chauffage) VALUES (?,?)");
        foreach ($deduits['chauffage'] as $code) {
            $stmtTC->execute([$societeId, $code]);
            $stcId = $stmtTC->fetchColumn();
            if ($stcId) $stmtBTC->execute([$bienId, (int)$stcId]);
        }
    }

    // ── Met à jour le fichier d'import ──
    $pdo->prepare("UPDATE bien_import_fichiers SET statut = 'created', id_bien_cree = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$bienId, $fichierId]);
    $pdo->prepare("UPDATE bien_imports SET nb_biens_crees = nb_biens_crees + 1, updated_at = NOW() WHERE id = ?")
        ->execute([$row['id_import']]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'bien_id' => $bienId, 'message' => 'Bien créé avec succès']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
