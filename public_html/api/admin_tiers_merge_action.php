<?php
// api/admin_tiers_merge_action.php — Fusion de tiers doublons (super admin)
// Merge tous les liens (tiers_roles, bien_baux.id_tiers_locataire, proprietaires.id_tiers,
// user_tiers, tiers_contacts) du tiers source vers le tiers destination, puis supprime
// le tiers source.
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/admin_tiers_scope.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$srcId = (int)(post('source_id') ?? 0);
$dstId = (int)(post('destination_id') ?? 0);
if ($srcId <= 0 || $dstId <= 0) { echo json_encode(['ok'=>false,'error'=>'source_id + destination_id requis']); exit; }
if ($srcId === $dstId) { echo json_encode(['ok'=>false,'error'=>'source et destination identiques']); exit; }

// Scope check : les 2 tiers doivent être dans le périmètre de l'user
$scope = tiers_check_scope($pdo, [$srcId, $dstId]);
if (!$scope['ok']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>$scope['error']]); exit; }

try {
    // Vérifie que les 2 existent
    $st = $pdo->prepare('SELECT id FROM tiers WHERE id IN (?, ?) LIMIT 2');
    $st->execute([$srcId, $dstId]);
    if (count($st->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
        echo json_encode(['ok'=>false,'error'=>'l\'un des tiers n\'existe pas']); exit;
    }

    $pdo->beginTransaction();
    $stats = [];

    // 1. tiers_roles : transfert + nettoyage doublons via INSERT IGNORE puis DELETE source
    // (la contrainte UNIQUE uniq_tiers_role_objet empêche les doublons)
    $st = $pdo->prepare('UPDATE IGNORE tiers_roles SET id_tiers = ? WHERE id_tiers = ?');
    $st->execute([$dstId, $srcId]);
    $stats['tiers_roles_transferes'] = $st->rowCount();
    // Reste : lignes qui n'ont pas pu être transférées car un doublon existait déjà côté dst → on supprime
    $st = $pdo->prepare('DELETE FROM tiers_roles WHERE id_tiers = ?');
    $st->execute([$srcId]);
    $stats['tiers_roles_supprimes_doublon'] = $st->rowCount();

    // 2. bien_baux.id_tiers_locataire
    try {
        $st = $pdo->prepare('UPDATE bien_baux SET id_tiers_locataire = ? WHERE id_tiers_locataire = ?');
        $st->execute([$dstId, $srcId]);
        $stats['bien_baux_relinkes'] = $st->rowCount();
    } catch (Throwable $e) {
        $stats['bien_baux_relinkes'] = 'skip (colonne absente)';
    }

    // 3. proprietaires.id_tiers
    try {
        $st = $pdo->prepare('UPDATE proprietaires SET id_tiers = ? WHERE id_tiers = ?');
        $st->execute([$dstId, $srcId]);
        $stats['proprietaires_relinkes'] = $st->rowCount();
    } catch (Throwable $e) { $stats['proprietaires_relinkes'] = 'skip'; }

    // 4. mandants.id_tiers
    try {
        $st = $pdo->prepare('UPDATE mandants SET id_tiers = ? WHERE id_tiers = ?');
        $st->execute([$dstId, $srcId]);
        $stats['mandants_relinkes'] = $st->rowCount();
    } catch (Throwable $e) { $stats['mandants_relinkes'] = 'skip'; }

    // 5. agency_mandant.id_tiers
    try {
        $st = $pdo->prepare('UPDATE agency_mandant SET id_tiers = ? WHERE id_tiers = ?');
        $st->execute([$dstId, $srcId]);
        $stats['agency_mandant_relinkes'] = $st->rowCount();
    } catch (Throwable $e) { $stats['agency_mandant_relinkes'] = 'skip'; }

    // 6. user_tiers
    try {
        $st = $pdo->prepare('UPDATE IGNORE user_tiers SET id_tiers = ? WHERE id_tiers = ?');
        $st->execute([$dstId, $srcId]);
        $stats['user_tiers_relinkes'] = $st->rowCount();
        $pdo->prepare('DELETE FROM user_tiers WHERE id_tiers = ?')->execute([$srcId]);
    } catch (Throwable $e) { $stats['user_tiers_relinkes'] = 'skip'; }

    // 7. tiers_contacts (2 colonnes)
    try {
        $st = $pdo->prepare('UPDATE IGNORE tiers_contacts SET id_tiers_entite = ? WHERE id_tiers_entite = ?');
        $st->execute([$dstId, $srcId]);
        $stats['tiers_contacts_entite'] = $st->rowCount();
        $pdo->prepare('DELETE FROM tiers_contacts WHERE id_tiers_entite = ?')->execute([$srcId]);

        $st = $pdo->prepare('UPDATE IGNORE tiers_contacts SET id_tiers_contact = ? WHERE id_tiers_contact = ?');
        $st->execute([$dstId, $srcId]);
        $stats['tiers_contacts_contact'] = $st->rowCount();
        $pdo->prepare('DELETE FROM tiers_contacts WHERE id_tiers_contact = ?')->execute([$srcId]);
    } catch (Throwable $e) { $stats['tiers_contacts'] = 'skip'; }

    // 8. Merge données complémentaires : si la cible a un champ vide et la source l'a, on copie
    $copiables = ['siret','siren','tva_intracom','rcs','email','email_secondaire','telephone','telephone_secondaire','mobile',
                  'adresse_ligne1','adresse_ligne2','code_postal','ville','pays','latitude','longitude','google_place_id'];
    $stSrc = $pdo->prepare('SELECT * FROM tiers WHERE id = ?');
    $stSrc->execute([$srcId]);
    $src = $stSrc->fetch(PDO::FETCH_ASSOC);
    $stDst = $pdo->prepare('SELECT * FROM tiers WHERE id = ?');
    $stDst->execute([$dstId]);
    $dst = $stDst->fetch(PDO::FETCH_ASSOC);
    $updates = [];
    $upParams = [':id' => $dstId];
    foreach ($copiables as $col) {
        if (empty($dst[$col]) && !empty($src[$col])) {
            $updates[] = $col . ' = :' . $col;
            $upParams[':' . $col] = $src[$col];
        }
    }
    if (!empty($updates)) {
        $sqlU = 'UPDATE tiers SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stU = $pdo->prepare($sqlU);
        foreach ($upParams as $k => $v) $stU->bindValue($k, $v);
        $stU->execute();
        $stats['champs_copies'] = count($updates);
    } else {
        $stats['champs_copies'] = 0;
    }

    // 9. Trace dans notes_internes du tiers destination
    $note = "[" . date('Y-m-d H:i') . "] Fusion : tiers #$srcId absorbé (" . trim((string)($src['nom_affichage'] ?? $src['raison_sociale'] ?? '')) . ")";
    $pdo->prepare('UPDATE tiers SET notes_internes = CONCAT(COALESCE(notes_internes, ""), "\n", ?) WHERE id = ?')
        ->execute([$note, $dstId]);

    // 10. DELETE tiers source
    $pdo->prepare('DELETE FROM tiers WHERE id = ?')->execute([$srcId]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'stats' => $stats, 'source_supprime' => $srcId, 'destination' => $dstId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin_tiers_merge_action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
