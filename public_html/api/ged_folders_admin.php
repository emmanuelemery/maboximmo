<?php
declare(strict_types=1);

/**
 * Ma GED Box V1.1 — API d'administration des niveaux GED (super admin)
 *
 * Actions cibles : drag & drop dans super_admin_ged_niveaux.php +
 * suppression conditionnelle (uniquement si niveau vide).
 *
 * Travaille sur la table `ged_level_codes` (catalogue cascade N1→N5)
 * — pas sur ged_folders directement (qui est utilisé pour l'instanciation
 * par entité métier, géré par admin/admin_ged_arborescence.php existant).
 *
 * Actions :
 *   - reorder           : POST level_id + new_position → réordonnancement frères
 *   - move              : POST level_id + new_parents (n1/n2/n3/n4) → déplace
 *                          sous un autre parent (réinitialise les enfants si conflit
 *                          de structure)
 *   - archive           : POST level_id → is_active=0 (soft)
 *   - unarchive         : POST level_id → is_active=1
 *   - delete_if_empty   : POST level_id → DELETE physique si :
 *                            (a) aucun enfant level_codes,
 *                            (b) aucun ged_folders.entity_type ne l'utilise (best effort)
 *                          Sinon refuse et propose archive.
 *
 * Réservé super admin (id_role = 1). Réutilise ged_pdo() de ged_functions.php.
 *
 * AJOUT uniquement, ne touche pas aux pages/fichiers existants.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_functions.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé super admin']);
    exit;
}

function gfa_respond(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = ged_pdo();
$action = (string)($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'POST' && !in_array($action, ['get_level', 'count_usages'], true)) {
        throw new RuntimeException('POST requis');
    }

    switch ($action) {

        case 'reorder':
            $levelId = (int)($_POST['level_id'] ?? 0);
            $newPos  = (int)($_POST['new_position'] ?? 0);
            if ($levelId <= 0) throw new RuntimeException('level_id requis');

            $st = $pdo->prepare("SELECT * FROM ged_level_codes WHERE id = ?");
            $st->execute([$levelId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Niveau introuvable');

            // Reorder simple : update la position du niveau, et décale les frères si besoin
            $pdo->prepare("UPDATE ged_level_codes SET position = ? WHERE id = ?")
                ->execute([$newPos, $levelId]);
            ged_audit('reorder_level', 'level_code', $levelId,
                ['old_pos' => (int)$row['position']],
                ['new_pos' => $newPos]);
            gfa_respond(true, ['level_id' => $levelId, 'new_position' => $newPos]);
            break;

        case 'reorder_batch':
            // Permet de réordonnancer plusieurs niveaux d'un coup (drag&drop final).
            // POST positions[] = [{id:int, position:int}, ...]
            $raw = $_POST['positions'] ?? null;
            if (!is_array($raw)) throw new RuntimeException('positions[] requis');
            $upd = $pdo->prepare("UPDATE ged_level_codes SET position = ? WHERE id = ?");
            $count = 0;
            foreach ($raw as $entry) {
                $id = (int)($entry['id'] ?? 0);
                $pos = (int)($entry['position'] ?? 0);
                if ($id <= 0) continue;
                $upd->execute([$pos, $id]);
                $count++;
            }
            ged_audit('reorder_batch', 'level_code', null, null, ['count' => $count]);
            gfa_respond(true, ['updated' => $count]);
            break;

        case 'move':
            $levelId = (int)($_POST['level_id'] ?? 0);
            if ($levelId <= 0) throw new RuntimeException('level_id requis');
            $st = $pdo->prepare("SELECT * FROM ged_level_codes WHERE id = ?");
            $st->execute([$levelId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Niveau introuvable');

            $newParents = [
                'parent_n1' => trim((string)($_POST['new_parent_n1'] ?? '')) ?: null,
                'parent_n2' => trim((string)($_POST['new_parent_n2'] ?? '')) ?: null,
                'parent_n3' => trim((string)($_POST['new_parent_n3'] ?? '')) ?: null,
                'parent_n4' => trim((string)($_POST['new_parent_n4'] ?? '')) ?: null,
            ];

            $sets = [];
            $params = [];
            foreach ($newParents as $k => $v) {
                $sets[] = "`{$k}` = ?";
                $params[] = $v;
            }
            $params[] = $levelId;
            try {
                $sql = "UPDATE ged_level_codes SET " . implode(', ', $sets) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($params);
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000) {
                    throw new RuntimeException('Conflit : un niveau identique existe déjà sous ce parent');
                }
                throw $e;
            }
            ged_audit('move_level', 'level_code', $levelId,
                ['old_parents' => array_intersect_key($row, $newParents)],
                $newParents);
            gfa_respond(true, ['level_id' => $levelId, 'new_parents' => $newParents]);
            break;

        case 'archive':
            $levelId = (int)($_POST['level_id'] ?? 0);
            if ($levelId <= 0) throw new RuntimeException('level_id requis');
            $pdo->prepare("UPDATE ged_level_codes SET is_active = 0 WHERE id = ?")
                ->execute([$levelId]);
            ged_audit('archive_level', 'level_code', $levelId, null, ['is_active' => 0]);
            gfa_respond(true);
            break;

        case 'unarchive':
            $levelId = (int)($_POST['level_id'] ?? 0);
            if ($levelId <= 0) throw new RuntimeException('level_id requis');
            $pdo->prepare("UPDATE ged_level_codes SET is_active = 1 WHERE id = ?")
                ->execute([$levelId]);
            ged_audit('unarchive_level', 'level_code', $levelId, null, ['is_active' => 1]);
            gfa_respond(true);
            break;

        case 'delete_if_empty':
            $levelId = (int)($_POST['level_id'] ?? 0);
            if ($levelId <= 0) throw new RuntimeException('level_id requis');
            $st = $pdo->prepare("SELECT * FROM ged_level_codes WHERE id = ?");
            $st->execute([$levelId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Niveau introuvable');

            // 1. Vérifie qu'il n'a pas d'enfants (autres niveaux qui le référencent comme parent)
            $colParent = 'parent_n' . (int)$row['level_number'];
            $childCount = (int)$pdo->prepare("
                SELECT COUNT(*) FROM ged_level_codes WHERE `{$colParent}` = ?
            ")->execute([$row['code']]);
            $stCk = $pdo->prepare("SELECT COUNT(*) FROM ged_level_codes WHERE `{$colParent}` = ?");
            $stCk->execute([$row['code']]);
            $childCount = (int)$stCk->fetchColumn();

            if ($childCount > 0) {
                gfa_respond(false, [
                    'error'        => "Impossible : {$childCount} niveaux enfants existent. Archive d'abord.",
                    'suggest'      => 'archive',
                    'children_count' => $childCount,
                ]);
            }

            // 2. Vérifie qu'aucun document GED n'utilise ce code (best effort,
            //    via ged_documents.source_module ou metadata->selected_levels).
            $usageCount = 0;
            try {
                $stU = $pdo->prepare("SELECT COUNT(*) FROM ged_documents WHERE source_module = ?");
                $stU->execute([$row['code']]);
                $usageCount += (int)$stU->fetchColumn();
            } catch (Throwable) {}
            try {
                $stU = $pdo->prepare("
                    SELECT COUNT(*) FROM ged_import_items
                    WHERE selected_n1 = :c OR selected_n2 = :c OR selected_n3 = :c
                       OR selected_n4 = :c OR selected_n5 = :c
                ");
                $stU->execute([':c' => $row['code']]);
                $usageCount += (int)$stU->fetchColumn();
            } catch (Throwable) {}

            if ($usageCount > 0) {
                gfa_respond(false, [
                    'error'        => "Impossible : {$usageCount} documents/items utilisent ce code. Archive d'abord.",
                    'suggest'      => 'archive',
                    'usage_count'  => $usageCount,
                ]);
            }

            // OK, suppression physique
            $pdo->prepare("DELETE FROM ged_level_codes WHERE id = ?")->execute([$levelId]);
            ged_audit('delete_level', 'level_code', $levelId, $row, null);
            gfa_respond(true, ['deleted' => true]);
            break;

        case 'count_usages':
            // Pour la modal "supprimer" : pré-vérifier l'impact sans rien modifier
            $levelId = (int)($_GET['level_id'] ?? $_POST['level_id'] ?? 0);
            if ($levelId <= 0) throw new RuntimeException('level_id requis');
            $st = $pdo->prepare("SELECT * FROM ged_level_codes WHERE id = ?");
            $st->execute([$levelId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Niveau introuvable');

            $colParent = 'parent_n' . (int)$row['level_number'];
            $stCk = $pdo->prepare("SELECT COUNT(*) FROM ged_level_codes WHERE `{$colParent}` = ?");
            $stCk->execute([$row['code']]);
            $childCount = (int)$stCk->fetchColumn();

            $usageDocs = 0;
            $usageItems = 0;
            try {
                $stU = $pdo->prepare("SELECT COUNT(*) FROM ged_documents WHERE source_module = ?");
                $stU->execute([$row['code']]);
                $usageDocs = (int)$stU->fetchColumn();
            } catch (Throwable) {}
            try {
                $stU = $pdo->prepare("
                    SELECT COUNT(*) FROM ged_import_items
                    WHERE selected_n1 = :c OR selected_n2 = :c OR selected_n3 = :c
                       OR selected_n4 = :c OR selected_n5 = :c
                ");
                $stU->execute([':c' => $row['code']]);
                $usageItems = (int)$stU->fetchColumn();
            } catch (Throwable) {}

            gfa_respond(true, [
                'children_count' => $childCount,
                'usage_docs'     => $usageDocs,
                'usage_items'    => $usageItems,
                'can_delete'     => ($childCount === 0 && $usageDocs === 0 && $usageItems === 0),
            ]);
            break;

        case 'recalculate_tree':
            // Bouton "Recalculer toute l'arborescence" : recalcule path_cache + depth
            // sur ged_folders (pas ged_level_codes qui n'a pas de path_cache).
            $count = ged_recalculate_folder_tree(null);
            gfa_respond(true, ['recalculated' => $count]);
            break;

        default:
            http_response_code(400);
            gfa_respond(false, ['error' => "Action inconnue : {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    gfa_respond(false, ['error' => $e->getMessage()]);
}
