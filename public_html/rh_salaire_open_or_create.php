<?php
declare(strict_types=1);

/**
 * rh_salaire_open_or_create.php
 *
 * Ouvre le détail salaire pour (user courant, mois demandé).
 * Si le salaire n'existe pas encore, le crée à partir du salaire_modele
 * de l'utilisateur, puis redirige vers rh_salaire_detail.php.
 *
 * Règle : création autorisée pour le mois courant et le mois suivant
 * uniquement (et tous mois antérieurs). Un mois futur > current+1 est refusé.
 *
 * Params GET :
 *   - mois  (int 1-12)
 *   - annee (int YYYY)
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$roleId = current_role_id();
if (!in_array($roleId, [1, 2, 3], true)) {
    deny_access('Accès RH restreint.');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$idUser = current_user_id();
if ($idUser <= 0) { http_response_code(400); exit('Utilisateur introuvable'); }

$mois = (int)($_GET['mois'] ?? 0);
$annee = (int)($_GET['annee'] ?? 0);

if ($mois < 1 || $mois > 12 || $annee < 2000 || $annee > 2100) {
    http_response_code(400);
    exit('Paramètres mois/annee invalides');
}

$mois_ref = sprintf('%04d-%02d-01', $annee, $mois);

// Garde-fou : pas de création > mois courant + 1
$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$current = new DateTime($now->format('Y-m-01'), new DateTimeZone('Europe/Paris'));
$maxAllowed = (clone $current)->modify('+1 month');
$target = new DateTime($mois_ref, new DateTimeZone('Europe/Paris'));

$idUserList = rh_user_salary_ids($pdo, $idUser);
if (!$idUserList) { $idUserList = [$idUser]; }
$in = implode(',', array_fill(0, count($idUserList), '?'));

// Vérifie si le salaire existe déjà
$stmt = $pdo->prepare("SELECT id FROM salaires WHERE id_user IN ($in) AND mois_reference = ? LIMIT 1");
$stmt->execute(array_merge($idUserList, [$mois_ref]));
$existingId = $stmt->fetchColumn();

if ($existingId) {
    header('Location: rh_salaire_detail.php?id_user=' . $idUser . '&mois_ref=' . $mois_ref);
    exit;
}

// Pas encore créé : on vérifie si la création est autorisée
if ($target > $maxAllowed) {
    http_response_code(403);
    exit('Création non autorisée : le mois ' . $mois_ref . ' est trop éloigné. Vous ne pouvez préparer au maximum que le mois suivant le mois courant.');
}

// Récupère le salaire_modele du user
$stmtModel = $pdo->prepare("SELECT * FROM salaires WHERE id_user IN ($in) AND mois_reference='0000-00-00' AND salaire_modele=1 LIMIT 1");
$stmtModel->execute($idUserList);
$model = $stmtModel->fetch(PDO::FETCH_ASSOC);

if (!$model) {
    http_response_code(409);
    exit('Aucun modèle de salaire défini pour vous. Demandez à votre administrateur de configurer votre salaire de référence avant de remplir un mois.');
}

// Colonnes de valeurs à copier depuis le modèle (numériques + textuelles structurelles)
$columnsToCopy = [
    'salaire_brut_base',
    'treizieme_mois',
    'anciennete',
    'avantage_nature',
    'heures_supp',
    'commission_ca',
    'commission_ca_nouvelles_affaires',
    'prime_admin',
    'prime_exceptionnelle',
    'stationnement',
    'frais_professionnels',
    'frais_reception',
    'frais_deplacement',
    'remboursement_achat',
    'total_ik',
    'ik_nb_km',
    'ik_montant',
    'vehicule_utilise',
];

// Filtre aux colonnes existantes (défensif : la table peut évoluer)
$existing = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[] = $row['Field'];
    }
} catch (Exception $e) {}

$copyCols = array_values(array_intersect($columnsToCopy, $existing));

$insertCols = ['id_user', 'mois_reference', 'salaire_modele', 'termine_user'];
$insertPlaceholders = [':id_user', ':mois_reference', ':salaire_modele', ':termine_user'];
$insertParams = [
    ':id_user'        => (int)$model['id_user'],
    ':mois_reference' => $mois_ref,
    ':salaire_modele' => 0,
    ':termine_user'   => 0,
];

foreach ($copyCols as $col) {
    $insertCols[] = $col;
    $insertPlaceholders[] = ':' . $col;
    $insertParams[':' . $col] = $model[$col] ?? null;
}

$sql = 'INSERT INTO salaires (`' . implode('`, `', $insertCols) . '`) VALUES (' . implode(', ', $insertPlaceholders) . ')';

try {
    $pdo->prepare($sql)->execute($insertParams);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[rh_salaire_open_or_create] INSERT failed: ' . $e->getMessage());
    exit('Erreur lors de la création du salaire à partir du modèle.');
}

header('Location: rh_salaire_detail.php?id_user=' . $idUser . '&mois_ref=' . $mois_ref);
exit;
