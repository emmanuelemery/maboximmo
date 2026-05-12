<?php
declare(strict_types=1);

/**
 * Ma GED Box V1.1 — API d'import GED (super admin)
 *
 * Actions :
 *   - upload      : POST FILES + batch_name → crée batch + items
 *   - list_items  : GET batch_id [+ filtres] → JSON
 *   - get_levels  : GET level + parents → JSON
 *   - update_item : POST item_id + champs → recalcul canonical
 *   - validate    : POST item_id → crée ged_documents
 *   - validate_bulk: POST batch_id + min_score|item_ids[] → bulk validate
 *   - ignore      : POST item_id → status='ignored'
 *   - to_review   : POST item_id → status='to_review'
 *
 * Réservé super admin (id_role = 1). CSRF non requis pour V1 (intra-app),
 * à durcir si exposition publique.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_import_functions.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé super admin']);
    exit;
}

function api_respond(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {

        case 'upload':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $batchName = trim((string)($_POST['batch_name'] ?? ''));
            if ($batchName === '') $batchName = 'Import ' . date('Y-m-d H:i');
            $sourceType = (string)($_POST['source_type'] ?? 'upload_files');
            $defaultMode = (string)($_POST['default_mode'] ?? 'normalized');
            if (!in_array($defaultMode, ['normalized', 'quick'], true)) $defaultMode = 'normalized';

            $batchId = ged_import_create_batch($batchName, $sourceType);
            // Persiste le default_mode du batch (rétrocompat : col existe seulement après v2_20)
            try {
                ged_import_pdo()->prepare("UPDATE ged_import_batches SET default_mode = ? WHERE id = ?")
                    ->execute([$defaultMode, $batchId]);
            } catch (Throwable) { /* col absente, on ignore */ }
            $batch = ged_import_get_batch($batchId);
            $storageDir = dirname(__DIR__) . '/uploads/ged_import/' . $batch['uuid'];

            $created = [];
            $errors = [];
            $files = $_FILES['files'] ?? null;
            if ($files && is_array($files['name'])) {
                $count = count($files['name']);
                for ($i = 0; $i < $count; $i++) {
                    if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) {
                        $errors[] = ['name' => $files['name'][$i], 'error' => 'upload error code ' . $files['error'][$i]];
                        continue;
                    }
                    $fileEntry = [
                        'name'     => $files['name'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'type'     => $files['type'][$i],
                        'size'     => $files['size'][$i],
                        'error'    => $files['error'][$i],
                    ];
                    // Pour upload dossier (webkitdirectory), $files['full_path'] contient le chemin relatif
                    $relPath = '';
                    if (isset($files['full_path']) && is_array($files['full_path'])) {
                        $rel = (string)$files['full_path'][$i];
                        $relPath = dirname($rel);
                        if ($relPath === '.' || $relPath === '/') $relPath = '';
                    } elseif (isset($_POST['rel_paths']) && is_array($_POST['rel_paths']) && isset($_POST['rel_paths'][$i])) {
                        $relPath = dirname((string)$_POST['rel_paths'][$i]);
                    }
                    try {
                        $itemId = ged_import_add_item($batchId, $fileEntry, $relPath, $storageDir);
                        // Propage le mode du batch sur chaque item créé (best-effort)
                        if ($defaultMode === 'quick') {
                            try {
                                ged_import_pdo()->prepare("UPDATE ged_import_items SET mode = 'quick' WHERE id = ?")
                                    ->execute([$itemId]);
                            } catch (Throwable) {}
                        }
                        $created[] = $itemId;
                    } catch (Throwable $e) {
                        $errors[] = ['name' => $files['name'][$i], 'error' => $e->getMessage()];
                    }
                }
            }

            api_respond(true, [
                'batch_id'     => $batchId,
                'batch_uuid'   => $batch['uuid'],
                'default_mode' => $defaultMode,
                'created'      => $created,
                'errors'       => $errors,
            ]);
            break;

        case 'list_items':
            $batchId = (int)($_GET['batch_id'] ?? 0);
            if ($batchId <= 0) throw new RuntimeException('batch_id requis');
            $filters = [
                'status'    => (string)($_GET['status']    ?? ''),
                'n1'        => (string)($_GET['n1']        ?? ''),
                'min_score' => (string)($_GET['min_score'] ?? ''),
                'ext'       => (string)($_GET['ext']       ?? ''),
                'search'    => (string)($_GET['search']    ?? ''),
                'mode'      => (string)($_GET['mode']      ?? ''),
            ];
            $items = ged_import_list_items($batchId, $filters);
            $batch = ged_import_get_batch($batchId);
            api_respond(true, ['batch' => $batch, 'items' => $items]);
            break;

        case 'get_levels':
            $level = (int)($_GET['level'] ?? 0);
            if ($level < 1 || $level > 5) throw new RuntimeException('level entre 1 et 5');
            $parents = [
                'n1' => (string)($_GET['n1'] ?? ''),
                'n2' => (string)($_GET['n2'] ?? ''),
                'n3' => (string)($_GET['n3'] ?? ''),
                'n4' => (string)($_GET['n4'] ?? ''),
            ];
            $opts = ged_import_get_levels_for($level, $parents);
            api_respond(true, ['level' => $level, 'options' => $opts]);
            break;

        case 'add_level':
            // V2.5 : ajout à la volée d'un code N3/N4/N5 depuis la modal d'import (super admin only)
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $level = (int)($_POST['level'] ?? 0);
            if ($level < 3 || $level > 5) throw new RuntimeException('level entre 3 et 5 (ajout à la volée)');
            $parents = [
                'n1' => (string)($_POST['n1'] ?? ''),
                'n2' => (string)($_POST['n2'] ?? ''),
                'n3' => (string)($_POST['n3'] ?? ''),
                'n4' => (string)($_POST['n4'] ?? ''),
            ];
            $code  = (string)($_POST['code']  ?? '');
            $label = isset($_POST['label']) ? (string)$_POST['label'] : null;
            $row = ged_import_add_level_code($level, $parents, $code, $label);
            api_respond(true, ['level' => $level, 'row' => $row]);
            break;

        case 'update_item':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            $fields = [];
            foreach (['selected_n1','selected_n2','selected_n3','selected_n4','selected_n5','selected_n6','title_user'] as $k) {
                if (array_key_exists($k, $_POST)) $fields[$k] = (string)$_POST[$k];
            }
            $opts = [
                'societe_code' => (string)($_POST['societe_code'] ?? ''),
                'agence_code'  => (string)($_POST['agence_code']  ?? ''),
                'ref_entite'   => (string)($_POST['ref_entite']   ?? ''),
                'nom_entite'   => (string)($_POST['nom_entite']   ?? ''),
                'date'         => (string)($_POST['date']         ?? ''),
            ];
            $item = ged_import_update_item($itemId, $fields, $opts);
            api_respond(true, ['item' => $item]);
            break;

        case 'validate':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            $docId = ged_import_validate_item($itemId);
            api_respond(true, ['document_id' => $docId]);
            break;

        case 'validate_quick':
            // Validation rapide : ne demande que N1+N2+entité ; le reste est optionnel.
            // Crée le document avec mode='quick' et name_file=uuid.ext (pas de renommage physique).
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            $docId = ged_import_validate_item_quick($itemId);
            api_respond(true, ['document_id' => $docId, 'mode' => 'quick']);
            break;

        case 'validate_bulk':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $minScore = isset($_POST['min_score']) ? (int)$_POST['min_score'] : 0;
            $ids = $_POST['item_ids'] ?? null;
            if ($batchId <= 0 && empty($ids)) throw new RuntimeException('batch_id ou item_ids[] requis');

            $targetIds = [];
            if (is_array($ids)) {
                foreach ($ids as $id) if ((int)$id > 0) $targetIds[] = (int)$id;
            } elseif ($batchId > 0 && $minScore > 0) {
                $st = ged_import_pdo()->prepare("
                    SELECT id FROM ged_import_items
                    WHERE batch_id = ? AND status IN ('proposed','imported') AND confidence_score >= ?
                ");
                $st->execute([$batchId, $minScore]);
                $targetIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
            $done = []; $err = [];
            foreach ($targetIds as $id) {
                try { $done[$id] = ged_import_validate_item($id); } catch (Throwable $e) { $err[$id] = $e->getMessage(); }
            }
            api_respond(true, ['validated' => $done, 'errors' => $err]);
            break;

        case 'ignore':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            ged_import_pdo()->prepare("UPDATE ged_import_items SET status = 'ignored' WHERE id = ?")
                ->execute([$itemId]);
            api_respond(true, ['item_id' => $itemId]);
            break;

        case 'to_review':
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            ged_import_pdo()->prepare("UPDATE ged_import_items SET status = 'to_review' WHERE id = ?")
                ->execute([$itemId]);
            api_respond(true, ['item_id' => $itemId]);
            break;

        case 'list_batches':
            $st = ged_import_pdo()->query("
                SELECT id, uuid, batch_name, source_type, nb_items, nb_validated, nb_ignored, status, created_at, validated_at
                FROM ged_import_batches
                ORDER BY id DESC
                LIMIT 100
            ");
            api_respond(true, ['batches' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'export_csv':
            // Export CSV du tableau de préclassement d'un batch
            $batchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
            if ($batchId <= 0) throw new RuntimeException('batch_id requis');
            $items = ged_import_list_items($batchId, []);
            $batch = ged_import_get_batch($batchId);

            // Override Content-Type
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            $filename = 'ged_import_batch_' . $batchId . '_' . date('Ymd_His') . '.csv';
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            // BOM pour Excel UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'id', 'old_folder_path', 'old_filename', 'file_extension', 'mime_type', 'size_bytes',
                'selected_n1', 'selected_n2', 'selected_n3', 'selected_n4', 'selected_n5', 'selected_n6',
                'title_user', 'name_display', 'name_canonical', 'proposed_destination', 'final_destination',
                'confidence_score', 'status', 'created_document_id', 'hash_sha256', 'created_at',
            ], ';');
            foreach ($items as $it) {
                fputcsv($out, [
                    $it['id'], $it['old_folder_path'], $it['old_filename'], $it['file_extension'],
                    $it['mime_type'], $it['size_bytes'],
                    $it['selected_n1'], $it['selected_n2'], $it['selected_n3'],
                    $it['selected_n4'], $it['selected_n5'], $it['selected_n6'],
                    $it['title_user'], $it['name_display'], $it['name_canonical'],
                    $it['proposed_destination'], $it['final_destination'],
                    $it['confidence_score'], $it['status'], $it['created_document_id'],
                    $it['hash_sha256'], $it['created_at'],
                ], ';');
            }
            fclose($out);
            exit;

        case 'validate_selected':
            // Valide une liste d'items précis (POST item_ids[])
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $ids = $_POST['item_ids'] ?? null;
            if (!is_array($ids) || !$ids) throw new RuntimeException('item_ids[] requis');
            $done = []; $err = [];
            foreach ($ids as $id) {
                $iid = (int)$id;
                if ($iid <= 0) continue;
                try { $done[$iid] = ged_import_validate_item($iid); }
                catch (Throwable $e) { $err[$iid] = $e->getMessage(); }
            }
            api_respond(true, ['validated' => $done, 'errors' => $err]);
            break;

        case 'set_selected_status':
            // Change le statut d'un lot d'items (to_review / ignored)
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $ids = $_POST['item_ids'] ?? null;
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['to_review', 'ignored', 'imported'], true)) {
                throw new RuntimeException("status doit être : to_review | ignored | imported");
            }
            if (!is_array($ids) || !$ids) throw new RuntimeException('item_ids[] requis');
            $st = ged_import_pdo()->prepare("UPDATE ged_import_items SET status = ? WHERE id = ?");
            $count = 0;
            foreach ($ids as $id) {
                $iid = (int)$id;
                if ($iid <= 0) continue;
                $st->execute([$status, $iid]);
                $count++;
            }
            api_respond(true, ['updated' => $count, 'status' => $status]);
            break;

        case 'find_duplicates':
            // Détecte les items du batch qui ont le même hash_sha256 que d'autres
            // (soit dans le batch, soit dans ged_documents existants).
            $batchId = (int)($_GET['batch_id'] ?? 0);
            if ($batchId <= 0) throw new RuntimeException('batch_id requis');
            $st = ged_import_pdo()->prepare("
                SELECT i1.id, i1.old_filename, i1.hash_sha256,
                       (SELECT GROUP_CONCAT(i2.id)
                        FROM ged_import_items i2
                        WHERE i2.hash_sha256 = i1.hash_sha256
                          AND i2.id <> i1.id) AS other_item_ids,
                       (SELECT GROUP_CONCAT(d.id)
                        FROM ged_documents d
                        WHERE d.hash_sha256 = i1.hash_sha256
                          AND d.status <> 'deleted') AS matching_doc_ids
                FROM ged_import_items i1
                WHERE i1.batch_id = ? AND i1.hash_sha256 IS NOT NULL AND i1.hash_sha256 <> ''
                HAVING other_item_ids IS NOT NULL OR matching_doc_ids IS NOT NULL
                ORDER BY i1.hash_sha256, i1.id
            ");
            $st->execute([$batchId]);
            $dupes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            api_respond(true, ['duplicates' => $dupes, 'count' => count($dupes)]);
            break;

        default:
            http_response_code(400);
            api_respond(false, ['error' => "Action inconnue : {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    api_respond(false, ['error' => $e->getMessage()]);
}
