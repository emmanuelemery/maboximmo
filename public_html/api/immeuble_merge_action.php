<?php
/**
 * api/immeuble_merge_action.php — Fusion de 2 immeubles en doublon (source → destination).
 * Relie TOUTES les tables portant id_immeuble (biens, documents, factures, syndic…)
 * de la source vers la destination, applique les champs d'adresse retenus, puis
 * SOFT DELETE l'immeuble source (statut_immeuble='supprime').
 *
 * Sécurité : manager (rôle 1,2,7) ou super admin.
 * Entrée POST : destination_id, source_id, fields_json (optionnel : {col:valeur}).
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = in_array($roleId, [1, 2, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isManager) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$srcId = (int)($_POST['source_id'] ?? 0);
$dstId = (int)($_POST['destination_id'] ?? 0);
if ($srcId <= 0 || $dstId <= 0) { echo json_encode(['ok'=>false,'error'=>'source_id + destination_id requis']); exit; }
if ($srcId === $dstId) { echo json_encode(['ok'=>false,'error'=>'source = destination']); exit; }

try {
    $st = $pdo->prepare('SELECT id, nom_immeuble FROM immeubles WHERE id IN (?, ?) LIMIT 2');
    $st->execute([$srcId, $dstId]);
    if (count($st->fetchAll()) !== 2) { echo json_encode(['ok'=>false,'error'=>'immeuble inexistant']); exit; }

    // Tables référençant id_immeuble (découverte dynamique)
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $pdo->quote($db) . " AND COLUMN_NAME = 'id_immeuble'
          AND TABLE_NAME <> 'immeubles'")->fetchAll(PDO::FETCH_COLUMN);

    $pdo->beginTransaction();
    $stats = [];
    foreach ($tables as $tbl) {
        // Ignore les tables de sauvegarde
        if (str_ends_with($tbl, '_bak')) { $stats[$tbl] = 'skip(bak)'; continue; }
        try {
            // UPDATE IGNORE : si une contrainte unique (ex. 1 ligne/immeuble) bloque, on n'échoue pas.
            $q = $pdo->prepare("UPDATE IGNORE `$tbl` SET id_immeuble = ? WHERE id_immeuble = ?");
            $q->execute([$dstId, $srcId]);
            $stats[$tbl] = $q->rowCount();
        } catch (Throwable $e) {
            $stats[$tbl] = 'err';
        }
    }

    // Choix champ par champ (adresse / nom) appliqués à la destination
    $fieldsJson = $_POST['fields_json'] ?? '';
    if ($fieldsJson !== '') {
        $override = json_decode((string)$fieldsJson, true);
        if (is_array($override) && $override) {
            $allowed = ['reference_immeuble','nom_immeuble','adresse_1','adresse_2',
                        'code_postal','ville','type_immeuble','statut_immeuble','nb_lots'];
            $upd = []; $par = [':id' => $dstId];
            foreach ($override as $col => $val) {
                if (!in_array($col, $allowed, true)) continue;
                $upd[] = "`$col` = :$col"; $par[":$col"] = ($val === '' ? null : $val);
            }
            if ($upd) {
                $q = $pdo->prepare('UPDATE immeubles SET ' . implode(', ', $upd) . ' WHERE id = :id');
                foreach ($par as $k => $v) $q->bindValue($k, $v);
                $q->execute();
                $stats['champs_choisis'] = count($upd);
            }
        }
    }

    // Soft delete de la source
    $pdo->prepare("UPDATE immeubles
        SET statut_immeuble = 'supprime',
            nom_immeuble = CONCAT('[FUSIONNÉ→#$dstId] ', COALESCE(nom_immeuble, '')),
            motif_archivage = 'Fusion doublon → immeuble #$dstId'
        WHERE id = ?")->execute([$srcId]);

    $pdo->commit();
    echo json_encode(['ok'=>true, 'source_id'=>$srcId, 'destination_id'=>$dstId, 'stats'=>$stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[immeuble_merge_action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
