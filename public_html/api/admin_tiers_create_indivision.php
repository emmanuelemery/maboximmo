<?php
// api/admin_tiers_create_indivision.php — Crée une indivision regroupant N tiers personnes physiques
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/admin_tiers_scope.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$tiersIds = post('tiers_ids') ?? [];
if (is_string($tiersIds)) $tiersIds = explode(',', $tiersIds);
$tiersIds = array_values(array_unique(array_filter(array_map('intval', (array)$tiersIds))));
if (count($tiersIds) < 2) { echo json_encode(['ok'=>false,'error'=>'au moins 2 tiers requis pour une indivision']); exit; }

// Scope check
$scope = tiers_check_scope($pdo, $tiersIds);
if (!$scope['ok']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>$scope['error']]); exit; }

$nomCustom       = trim((string)(post('nom') ?? ''));
// Modes de répartition :
//   'unifie'       = indivision seule propriétaire à 100%, rôles individuels désactivés (1 RIB, courriers groupés)
//   'individuel'   = pas de rôle indivision, quote_part 50/50 (ou 100/N) sur les personnes individuelles (2 RIB, courriers séparés)
//   'mixte'        = indivision 100% ET personnes 50/50 (3 rôles propriétaire actifs simultanément)
//   'aucun'        = ne touche aux rôles ; l'indivision sert uniquement de regroupement via tiers_contacts
$mode            = (string)(post('mode') ?? 'aucun');
if (!in_array($mode, ['unifie','individuel','mixte','aucun'], true)) $mode = 'aucun';
$userId          = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$idSocSession    = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAgeSession    = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;

try {
    // 1. Charge les tiers sources
    $in = implode(',', array_fill(0, count($tiersIds), '?'));
    $st = $pdo->prepare("SELECT id, type_tiers, nom, prenom, raison_sociale, email, ville, code_postal, id_societe, id_agence
        FROM tiers WHERE id IN ($in)");
    $st->execute($tiersIds);
    $sources = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($sources) !== count($tiersIds)) {
        echo json_encode(['ok'=>false,'error'=>'un ou plusieurs tiers introuvables']); exit;
    }

    // 2. Calcule le nom de l'indivision
    $nomIndiv = $nomCustom;
    if ($nomIndiv === '') {
        $noms = [];
        foreach ($sources as $s) {
            $n = trim((string)($s['nom'] ?: $s['raison_sociale'] ?: ''));
            if ($n !== '') $noms[] = mb_strtoupper($n);
        }
        $nomIndiv = 'Indivision ' . implode(' / ', array_unique($noms));
    }

    // Société/agence : prend la 1re non-NULL des sources, sinon session
    $idSoc = null; $idAge = null;
    foreach ($sources as $s) {
        if (!$idSoc && !empty($s['id_societe'])) $idSoc = (int)$s['id_societe'];
        if (!$idAge && !empty($s['id_agence']))  $idAge = (int)$s['id_agence'];
    }
    $idSoc = $idSoc ?: $idSocSession;
    $idAge = $idAge ?: $idAgeSession;

    $pdo->beginTransaction();

    // 3. INSERT tiers indivision
    $ins = $pdo->prepare("INSERT INTO tiers
        (id_societe, id_agence, type_tiers, nom_affichage, raison_sociale,
         source_creation, id_user_createur, actif, date_creation)
        VALUES (?, ?, 'indivision', ?, ?, 'admin_indivision', ?, 1, NOW())");
    $ins->execute([$idSoc, $idAge, $nomIndiv, $nomIndiv, $userId ?: null]);
    $idIndiv = (int)$pdo->lastInsertId();

    // 4. Lien tiers_contacts (indivisaires)
    $nbContacts = 0;
    foreach ($sources as $idx => $s) {
        try {
            $stC = $pdo->prepare("INSERT IGNORE INTO tiers_contacts
                (id_tiers_entite, id_tiers_contact, qualite, priorite, canal_principal, actif, date_creation)
                VALUES (?, ?, 'indivisaire', ?, 'email', 1, NOW())");
            $stC->execute([$idIndiv, (int)$s['id'], $idx]);
            if ($stC->rowCount() > 0) $nbContacts++;
        } catch (Throwable $e) {
            error_log('[indivision contacts] ' . $e->getMessage());
        }
    }

    // 5. Application du mode de répartition sur les biens
    $nbBiensTraites = 0;
    $biensConcernes = [];
    if ($mode !== 'aucun') {
        $stB = $pdo->prepare("SELECT DISTINCT id_objet FROM tiers_roles
            WHERE id_tiers IN ($in) AND role_code = 'proprietaire' AND objet_type = 'bien' AND actif = 1");
        $stB->execute($tiersIds);
        $biensConcernes = array_column($stB->fetchAll(PDO::FETCH_ASSOC), 'id_objet');

        // Si pas de rôle existant via tiers_roles, on regarde aussi biens.id_proprietaire (legacy)
        if (empty($biensConcernes)) {
            $stPl = $pdo->prepare("SELECT DISTINCT b.id FROM proprietaires p
                INNER JOIN biens b ON b.id_proprietaire = p.id
                WHERE p.id_tiers IN ($in)");
            $stPl->execute($tiersIds);
            $biensConcernes = array_column($stPl->fetchAll(PDO::FETCH_ASSOC), 'id');
        }

        $quotePartIndiv = ($mode === 'individuel') ? null : 100.00;
        $quotePartIndiv = ($mode === 'mixte') ? 100.00 : $quotePartIndiv;
        $quotePartIndividuel = round(100.0 / max(1, count($tiersIds)), 2); // 50.00 si 2 personnes

        foreach ($biensConcernes as $idBien) {
            $nbBiensTraites++;

            // (a) Rôle indivision (sauf mode 'individuel' qui ne touche pas l'indivision)
            if ($mode === 'unifie' || $mode === 'mixte') {
                $stR = $pdo->prepare("INSERT INTO tiers_roles
                    (id_tiers, role_code, objet_type, id_objet, quote_part, actif, date_creation)
                    VALUES (?, 'proprietaire', 'bien', ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE quote_part = VALUES(quote_part), actif = 1");
                $stR->execute([$idIndiv, (int)$idBien, $quotePartIndiv]);
            }

            // (b) Rôles individuels
            if ($mode === 'unifie') {
                // Désactive les rôles individuels (l'indivision prend tout)
                $stD = $pdo->prepare("UPDATE tiers_roles SET actif = 0
                    WHERE id_tiers IN ($in) AND role_code = 'proprietaire' AND objet_type = 'bien' AND id_objet = ?");
                $stD->execute(array_merge($tiersIds, [(int)$idBien]));
            } elseif ($mode === 'individuel' || $mode === 'mixte') {
                // Active/met à jour les rôles individuels avec quote_part 50/50
                foreach ($tiersIds as $idT) {
                    $stU = $pdo->prepare("INSERT INTO tiers_roles
                        (id_tiers, role_code, objet_type, id_objet, quote_part, actif, date_creation)
                        VALUES (?, 'proprietaire', 'bien', ?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE quote_part = VALUES(quote_part), actif = 1");
                    $stU->execute([(int)$idT, (int)$idBien, $quotePartIndividuel]);
                }
            }
        }
    }

    // 6. Note d'audit sur l'indivision
    $modeLabel = [
        'unifie'     => 'Indivision seule propriétaire 100% (rôles individuels désactivés)',
        'individuel' => 'Personnes à quote_part ' . round(100.0 / count($tiersIds), 2) . '% chacune (indivision = regroupement seul)',
        'mixte'      => 'Indivision 100% + personnes 50/50 actives simultanément',
        'aucun'      => 'Regroupement via tiers_contacts uniquement, rôles inchangés',
    ][$mode];
    $note = "[" . date('Y-m-d H:i') . "] Indivision créée à partir des tiers : "
          . implode(', ', array_map(fn($s) => '#' . $s['id'] . ' ' . trim((string)$s['prenom'] . ' ' . $s['nom']), $sources))
          . "\nMode : " . $modeLabel
          . "\nBiens traités : " . $nbBiensTraites;
    $pdo->prepare('UPDATE tiers SET notes_internes = ? WHERE id = ?')->execute([$note, $idIndiv]);

    $pdo->commit();
    echo json_encode([
        'ok'              => true,
        'indivision_id'   => $idIndiv,
        'nom'             => $nomIndiv,
        'nb_indivisaires' => $nbContacts,
        'mode'            => $mode,
        'mode_label'      => $modeLabel,
        'nb_biens_traites'=> $nbBiensTraites,
        'biens_concernes' => $biensConcernes,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin_tiers_create_indivision] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
