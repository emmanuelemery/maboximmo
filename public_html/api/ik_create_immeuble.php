<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'] ?? null;
$userId    = current_user_id();
$agenceId  = current_agence_id();
$societeId = current_societe_id();

if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$nom      = trim($data['nom_immeuble']   ?? '');
$adresse  = trim($data['adresse_1']      ?? '');
$cp       = trim($data['code_postal']    ?? '');
$ville    = trim($data['ville']          ?? '');
$type     = trim($data['type_immeuble']  ?? '');
$idAgence = (int)($data['id_agence']     ?? $agenceId);
$idSociete= (int)($data['id_societe']    ?? $societeId);
$lat             = isset($data['latitude'])  && $data['latitude']  !== '' ? (float)$data['latitude']  : null;
$lng             = isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null;
$pays            = trim($data['pays']             ?? 'France') ?: 'France';
$googlePlaceId   = trim($data['google_place_id']  ?? '') ?: null;
$adresseFormatee = trim($data['adresse_formatee'] ?? '') ?: null;

// Champs complémentaires
$nbLots          = isset($data['nb_lots'])           ? (int)$data['nb_lots']           : null;
$nbNiveaux       = isset($data['nb_niveaux'])        ? (int)$data['nb_niveaux']        : null;
$anneeConst      = isset($data['annee_construction'])? (int)$data['annee_construction']: null;
$syndicType      = trim($data['syndic_type']      ?? '');
$syndicActuel    = trim($data['syndic_actuel']    ?? '');
$digicode        = trim($data['digicode']         ?? '');
$interphone      = trim($data['interphone']       ?? '');
$nbStation       = isset($data['nb_stationnements']) ? (int)$data['nb_stationnements'] : null;
$ascenseur       = isset($data['presence_ascenseur']) ? (int)$data['presence_ascenseur'] : 0;
$commentaire     = trim($data['commentaire']      ?? '');
$modeGestion     = $syndicType ?: null;

if (!$nom || !$adresse) {
    echo json_encode(['ok' => false, 'error' => 'Nom et adresse obligatoires']); exit;
}

// Vérifier que l'agence appartient bien à la société de l'utilisateur (sécurité)
$checkAg = $pdo->prepare("SELECT id_societe FROM agences WHERE id = ?");
$checkAg->execute([$idAgence]);
$ag = $checkAg->fetch(PDO::FETCH_ASSOC);
if (!$ag) { echo json_encode(['ok' => false, 'error' => 'Agence invalide']); exit; }

// Enrichir commentaire avec adresse formatée Google si disponible
if ($adresseFormatee && !$commentaire) $commentaire = 'Adresse Google: '.$adresseFormatee;

$stmt = $pdo->prepare("INSERT INTO immeubles
    (id_agence, id_societe, nom_immeuble, adresse_1, code_postal, ville, pays,
     type_immeuble, statut_immeuble, latitude, longitude,
     nb_lots, nb_niveaux, annee_construction, mode_gestion,
     syndic_actuel, nb_stationnements, presence_ascenseur, commentaire)
    VALUES (?,?,?,?,?,?,?,?,'actif',?,?,?,?,?,?,?,?,?,?)");
$stmt->execute([
    $idAgence, $idSociete, $nom, $adresse, $cp, $ville, $pays,
    $type ?: null, $lat, $lng,
    $nbLots, $nbNiveaux, $anneeConst ?: null, $modeGestion,
    $syndicActuel ?: null, $nbStation, $ascenseur,
    $commentaire ?: null,
]);
$newId = (int)$pdo->lastInsertId();

// Stocker digicode/interphone dans commentaire si non nul (pas de colonnes dédiées dans immeubles)
if (($digicode || $interphone) && !$commentaire) {
    $extra = array_filter(['Digicode: '.$digicode, 'Interphone: '.$interphone]);
    if ($extra) {
        $pdo->prepare("UPDATE immeubles SET commentaire = ? WHERE id = ?")
            ->execute([implode(' | ', $extra), $newId]);
    }
} elseif ($digicode || $interphone) {
    $extra = array_filter(['Digicode: '.$digicode, 'Interphone: '.$interphone]);
    if ($extra) {
        $pdo->prepare("UPDATE immeubles SET commentaire = CONCAT(commentaire, ' | ', ?) WHERE id = ?")
            ->execute([implode(' | ', $extra), $newId]);
    }
}

echo json_encode([
    'ok'          => true,
    'id'          => $newId,
    'nom_immeuble'=> $nom,
    'adresse'     => $adresse,
    'code_postal' => $cp,
    'ville'       => $ville,
    'latitude'    => $lat,
    'longitude'   => $lng,
]);
