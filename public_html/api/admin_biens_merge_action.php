<?php
// api/admin_biens_merge_action.php — Fusion de 2 biens (source → destination)
// Transfère : bien_baux, annonces, ged_documents, biens_documents, leads_annonces,
//             tiers_roles (objet='bien'), biens_photos
// Puis SOFT DELETE du bien source (statut='supprime').
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 7 || $roleId === 2);
if (!$isManager) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }
if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$srcId = (int)(post('source_id') ?? 0);
$dstId = (int)(post('destination_id') ?? 0);
if ($srcId <= 0 || $dstId <= 0) { echo json_encode(['ok'=>false,'error'=>'source_id + destination_id requis']); exit; }
if ($srcId === $dstId) { echo json_encode(['ok'=>false,'error'=>'source et destination identiques']); exit; }

try {
    // Vérifie existence des 2 biens
    $st = $pdo->prepare('SELECT id, reference_bien, id_societe FROM biens WHERE id IN (?, ?) LIMIT 2');
    $st->execute([$srcId, $dstId]);
    $biens = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($biens) !== 2) { echo json_encode(['ok'=>false,'error'=>'l\'un des biens n\'existe pas']); exit; }

    // Scope check (non super-admin) : les 2 biens doivent être de la société de l'user
    if ($roleId !== 1 && isset($_SESSION['id_societe'])) {
        $idSoc = (int)$_SESSION['id_societe'];
        foreach ($biens as $b) {
            if (!empty($b['id_societe']) && (int)$b['id_societe'] !== $idSoc) {
                http_response_code(403);
                echo json_encode(['ok'=>false,'error'=>'bien #' . $b['id'] . ' hors scope société']); exit;
            }
        }
    }

    $pdo->beginTransaction();
    $stats = [];

    // 1. bien_baux : transfert id_bien
    $st = $pdo->prepare('UPDATE bien_baux SET id_bien = ? WHERE id_bien = ?');
    $st->execute([$dstId, $srcId]);
    $stats['bien_baux'] = $st->rowCount();

    // 1bis. dossier_vente (UNIQUE id_bien = 1 dossier/bien)
    //   - si la destination n'a pas de dossier : on transfère celui de la source ;
    //   - si elle en a déjà un : on ne touche pas (le dossier source reste sur le
    //     bien soft-deleted) pour éviter la collision sur la clé unique id_bien.
    try {
        $dstHas = (int)$pdo->query('SELECT COUNT(*) FROM dossier_vente WHERE id_bien = ' . $dstId)->fetchColumn();
        if ($dstHas === 0) {
            $st = $pdo->prepare('UPDATE dossier_vente SET id_bien = ? WHERE id_bien = ?');
            $st->execute([$dstId, $srcId]);
            $stats['dossier_vente'] = $st->rowCount();
        } else {
            $srcHas = (int)$pdo->query('SELECT COUNT(*) FROM dossier_vente WHERE id_bien = ' . $srcId)->fetchColumn();
            $stats['dossier_vente'] = $srcHas > 0 ? 'conflit: destination a déjà un dossier (source conservé)' : 0;
        }
    } catch (Throwable $e) { $stats['dossier_vente'] = 'skip'; }

    // 2. annonces
    try {
        $st = $pdo->prepare('UPDATE annonces SET id_bien = ? WHERE id_bien = ?');
        $st->execute([$dstId, $srcId]);
        $stats['annonces'] = $st->rowCount();
    } catch (Throwable $e) { $stats['annonces'] = 'skip'; }

    // 3. ged_documents (FK id_bien si dispo, sinon update metadata JSON)
    try {
        $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM ged_documents LIKE 'id_bien'")->fetchColumn();
        if ($hasCol) {
            $st = $pdo->prepare('UPDATE ged_documents SET id_bien = ? WHERE id_bien = ?');
            $st->execute([$dstId, $srcId]);
            $stats['ged_documents_fk'] = $st->rowCount();
        }
        // Update aussi metadata JSON (compat ged_consult)
        $st = $pdo->prepare("UPDATE ged_documents
            SET metadata = JSON_SET(metadata, '$.classement.bien_id_bdd', ?)
            WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.bien_id_bdd')) = ?");
        $st->execute([$dstId, $srcId]);
        $stats['ged_documents_meta'] = $st->rowCount();
    } catch (Throwable $e) { $stats['ged_documents'] = 'err: ' . $e->getMessage(); }

    // 4. biens_documents (legacy)
    try {
        $st = $pdo->prepare('UPDATE biens_documents SET id_bien = ? WHERE id_bien = ?');
        $st->execute([$dstId, $srcId]);
        $stats['biens_documents'] = $st->rowCount();
    } catch (Throwable $e) { $stats['biens_documents'] = 'skip'; }

    // 5. leads_annonces
    try {
        $st = $pdo->prepare('UPDATE leads_annonces SET id_bien = ? WHERE id_bien = ?');
        $st->execute([$dstId, $srcId]);
        $stats['leads_annonces'] = $st->rowCount();
    } catch (Throwable $e) { $stats['leads_annonces'] = 'skip'; }

    // 6. tiers_roles (objet='bien')
    try {
        // UPDATE IGNORE pour éviter doublons (UNIQUE id_tiers,role,objet_type,id_objet,id_mandat)
        $st = $pdo->prepare("UPDATE IGNORE tiers_roles SET id_objet = ?
            WHERE objet_type = 'bien' AND id_objet = ?");
        $st->execute([$dstId, $srcId]);
        $stats['tiers_roles'] = $st->rowCount();
        // Supprime restants en doublon
        $pdo->prepare("DELETE FROM tiers_roles WHERE objet_type = 'bien' AND id_objet = ?")
            ->execute([$srcId]);
    } catch (Throwable $e) { $stats['tiers_roles'] = 'skip'; }

    // 7. biens_photos
    try {
        $st = $pdo->prepare('UPDATE biens_photos SET id_bien = ? WHERE id_bien = ?');
        $st->execute([$dstId, $srcId]);
        $stats['biens_photos'] = $st->rowCount();
    } catch (Throwable $e) { $stats['biens_photos'] = 'skip'; }

    // 8. mandats (FK id_bien)
    try {
        $st = $pdo->prepare('UPDATE mandats SET id_bien = ? WHERE id_bien = ?');
        $st->execute([$dstId, $srcId]);
        $stats['mandats'] = $st->rowCount();
    } catch (Throwable $e) { $stats['mandats'] = 'skip'; }

    // 9. Copy défensif des champs vides de DST depuis SRC (anti-perte de données)
    $stD = $pdo->prepare('SELECT * FROM biens WHERE id = ?');
    $stD->execute([$dstId]); $dst = $stD->fetch(PDO::FETCH_ASSOC);
    $stS = $pdo->prepare('SELECT * FROM biens WHERE id = ?');
    $stS->execute([$srcId]); $src = $stS->fetch(PDO::FETCH_ASSOC);

    $copiables = ['adresse_1','code_postal','ville','latitude','longitude','google_place_id',
                  'surface_habitable','surface_totale','surface_commerciale','surface_bureau',
                  'nb_pieces','etage','numero_lot','copro_quote_part_charges','parking_nb',
                  'designation','description','commentaire','usage_bien','dpe_classe','ges_classe',
                  'annee_construction','type_commercialisation','prix_demande_initial','prix_vente_estime',
                  'loyer_hc','charges_locatives'];
    $upd = []; $params = [':id' => $dstId];
    foreach ($copiables as $c) {
        if (empty($dst[$c]) && !empty($src[$c])) {
            $upd[] = "$c = :$c";
            $params[':' . $c] = $src[$c];
        }
    }
    if (!empty($upd)) {
        $sql = 'UPDATE biens SET ' . implode(', ', $upd) . ', date_modification = NOW() WHERE id = :id';
        $stU = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stU->bindValue($k, $v);
        $stU->execute();
        $stats['champs_copies'] = count($upd);
    } else { $stats['champs_copies'] = 0; }

    // 9bis. CHOIX CHAMP PAR CHAMP (fusion guidée) : applique les valeurs retenues
    //       par l'utilisateur sur la destination (prioritaire sur la copie défensive).
    $fieldsJson = post('fields_json') ?? '';
    if ($fieldsJson !== '') {
        $override = json_decode((string)$fieldsJson, true);
        if (is_array($override) && $override) {
            $allowed = ['reference_bien','designation','usage_bien','type_commercialisation',
                        'statut_bien','adresse_1','code_postal','ville','lot_principal',
                        'lot_secondaire','etage','surface_habitable','surface_carrez',
                        'nb_pieces','nb_chambres','dpe_classe','ges_classe','annee_construction',
                        'loyer_hc','charges_locatives','id_immeuble','id_proprietaire'];
            $updF = []; $parF = [':id' => $dstId];
            foreach ($override as $col => $val) {
                if (!in_array($col, $allowed, true)) continue;
                $updF[] = "`$col` = :$col";
                $parF[":$col"] = ($val === '' ? null : $val);
            }
            if ($updF) {
                $sqlF = 'UPDATE biens SET ' . implode(', ', $updF) . ', date_modification = NOW() WHERE id = :id';
                $stF = $pdo->prepare($sqlF);
                foreach ($parF as $k => $v) $stF->bindValue($k, $v);
                $stF->execute();
                $stats['champs_choisis'] = count($updF);
            }
        }
    }

    // 10. Note d'audit sur destination
    $note = "[" . date('Y-m-d H:i') . "] Fusion : bien #$srcId (" . ($src['reference_bien'] ?? '?') . ") absorbé.";
    $pdo->prepare("UPDATE biens SET commentaire = CONCAT(COALESCE(commentaire, ''), '\n', ?) WHERE id = ?")
        ->execute([$note, $dstId]);

    // 11. SOFT DELETE source (préserve l'historique, désactive sans casser les FK)
    $pdo->prepare("UPDATE biens SET statut_bien = 'supprime',
        designation = CONCAT('[FUSIONNÉ→#$dstId] ', COALESCE(designation, '')),
        date_modification = NOW() WHERE id = ?")
        ->execute([$srcId]);

    $pdo->commit();
    echo json_encode([
        'ok'             => true,
        'source_id'      => $srcId,
        'destination_id' => $dstId,
        'stats'          => $stats,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin_biens_merge_action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
