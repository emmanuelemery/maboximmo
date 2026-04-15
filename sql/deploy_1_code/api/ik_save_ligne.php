<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();

if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$idSession = (int)($data['id_session'] ?? 0);
$idLigne   = isset($data['id']) ? (int)$data['id'] : 0;

// Vérifier accès à la session
$stmt = $pdo->prepare("SELECT id_user, mois_paie FROM rh_ik_sessions WHERE id = ?");
$stmt->execute([$idSession]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session || $session['id_user'] != $userId) {
    echo json_encode(['ok' => false, 'error' => 'Accès refusé']); exit;
}

if (rh_is_salary_month_closed($pdo, $session['mois_paie'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Mois de paie clôturé']); exit;
}

// Sanitiser les données
$date         = !empty($data['date_deplacement']) ? $data['date_deplacement'] : null;
$typeDepart   = in_array($data['type_depart'] ?? '', ['agence','domicile','immeuble','adresse','autre']) ? $data['type_depart'] : 'agence';
// id_agence_depart stocke l'ID agence OU l'ID immeuble départ selon type_depart
$idAgenceDepart = isset($data['id_agence_depart']) && $data['id_agence_depart'] ? (int)$data['id_agence_depart']
                : (isset($data['id_depart_immeuble']) && $data['id_depart_immeuble'] ? (int)$data['id_depart_immeuble'] : null);
$departLabel  = trim($data['depart_label'] ?? '');
$departLat    = isset($data['depart_lat']) && $data['depart_lat'] !== '' ? (float)$data['depart_lat'] : null;
$departLng    = isset($data['depart_lng']) && $data['depart_lng'] !== '' ? (float)$data['depart_lng'] : null;
$idImmeuble   = isset($data['id_immeuble']) && $data['id_immeuble'] ? (int)$data['id_immeuble'] : null;
$destLabel    = trim($data['destination_label'] ?? '');
$destVille    = trim($data['destination_ville'] ?? '');
$destLat      = isset($data['destination_lat']) && $data['destination_lat'] !== '' ? (float)$data['destination_lat'] : null;
$destLng      = isset($data['destination_lng']) && $data['destination_lng'] !== '' ? (float)$data['destination_lng'] : null;
$distCalc     = isset($data['distance_calculee']) && $data['distance_calculee'] !== '' ? (float)$data['distance_calculee'] : null;
$kmAller      = isset($data['km_aller']) && $data['km_aller'] !== '' ? (float)$data['km_aller'] : null;
$kmRetour     = isset($data['km_retour']) && $data['km_retour'] !== '' ? (float)$data['km_retour'] : null;
$motif        = trim($data['motif'] ?? '');
$observation  = trim($data['observation'] ?? '');
$ordre        = (int)($data['ordre'] ?? 0);

if ($idLigne > 0) {
    // Vérifier que la ligne appartient bien à cette session
    $chk = $pdo->prepare("SELECT id FROM rh_ik_lignes WHERE id=? AND id_session=?");
    $chk->execute([$idLigne, $idSession]);
    if (!$chk->fetch()) { echo json_encode(['ok' => false, 'error' => 'Ligne introuvable']); exit; }

    $upd = $pdo->prepare("UPDATE rh_ik_lignes SET
        date_deplacement=?, type_depart=?, id_agence_depart=?, depart_label=?,
        depart_lat=?, depart_lng=?, id_immeuble=?, destination_label=?, destination_ville=?,
        destination_lat=?, destination_lng=?, distance_calculee=?,
        km_aller=?, km_retour=?, motif=?, observation=?, ordre=?
        WHERE id=?");
    $upd->execute([
        $date, $typeDepart, $idAgenceDepart, $departLabel,
        $departLat, $departLng, $idImmeuble, $destLabel, $destVille,
        $destLat, $destLng, $distCalc,
        $kmAller, $kmRetour, $motif, $observation, $ordre,
        $idLigne
    ]);
    echo json_encode(['ok' => true, 'id' => $idLigne]);
} else {
    $ins = $pdo->prepare("INSERT INTO rh_ik_lignes
        (id_session, date_deplacement, type_depart, id_agence_depart, depart_label,
         depart_lat, depart_lng, id_immeuble, destination_label, destination_ville,
         destination_lat, destination_lng, distance_calculee, km_aller, km_retour,
         motif, observation, ordre)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        $idSession, $date, $typeDepart, $idAgenceDepart, $departLabel,
        $departLat, $departLng, $idImmeuble, $destLabel, $destVille,
        $destLat, $destLng, $distCalc, $kmAller, $kmRetour,
        $motif, $observation, $ordre
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}