<?php
declare(strict_types=1);

/**
 * Ma GED Box V2 — API moteur de nommage intelligent
 *
 * Endpoints :
 *   - POST /api/ged_naming.php?action=build_canonical
 *     IN  : payload (n1...n6, soc, age, ref, entite, title, date, ext) + module
 *     OUT : { ok, name_canonical, name_export, segments, truncated, scores, warnings }
 *
 *   - POST /api/ged_naming.php?action=parse_canonical
 *     IN  : name (string)
 *     OUT : { ok, parts, guess_n1, guess_soc, guess_age, guess_date }
 *
 *   - GET  /api/ged_naming.php?action=n6_autocomplete&q=xxx&n1=04_SYNDIC
 *     OUT : { ok, suggestions: [{code, label, source, weight}] }
 *
 *   - GET  /api/ged_naming.php?action=cascade_options&level=N&n1=...&n2=...
 *     OUT : { ok, options: [{code, label, position}] }   (wrapper get_levels)
 *
 *   - POST /api/ged_naming.php?action=feedback_learning
 *     IN  : field, suggestion_value, correction_value, [item_id, document_id, old_filename, module]
 *     OUT : { ok }
 *
 *   - POST /api/ged_naming.php?action=find_duplicates_smart
 *     IN  : item_id (depuis ged_import_items)
 *     OUT : { ok, hash_matches, similar, risk }
 *
 * Sécurité : super admin (id_role=1) requis.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_naming_v2.php';
require_once dirname(__DIR__) . '/inc/ged_import_functions.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé super admin']);
    exit;
}

function naming_respond(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {

        case 'build_canonical': {
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $payload = [
                'n1'     => (string)($_POST['n1']     ?? ''),
                'n2'     => (string)($_POST['n2']     ?? ''),
                'n3'     => (string)($_POST['n3']     ?? ''),
                'n4'     => (string)($_POST['n4']     ?? ''),
                'n5'     => (string)($_POST['n5']     ?? ''),
                'n6'     => (string)($_POST['n6']     ?? ''),
                'soc'    => (string)($_POST['soc']    ?? ''),
                'age'    => (string)($_POST['age']    ?? ''),
                'ref'    => (string)($_POST['ref']    ?? ''),
                'entite' => (string)($_POST['entite'] ?? ''),
                'title'  => (string)($_POST['title']  ?? ''),
                'date'   => (string)($_POST['date']   ?? ''),
                'ext'    => (string)($_POST['ext']    ?? ''),
            ];
            $module = isset($_POST['module']) ? (string)$_POST['module'] : null;
            $built = ged_v2_build_canonical_name($payload, $module);

            // Build export (dynamique)
            $exportName = ged_v2_build_export_name([
                'title_user'            => $payload['title'],
                'entity_label_resolved' => $payload['entite'],
                'date_document'         => $payload['date'],
                'file_extension'        => $payload['ext'],
            ]);

            // Scores granulaires
            $scores = ged_v2_compute_scores_granular(
                [
                    'selected_n1' => $payload['n1'],
                    'selected_n2' => $payload['n2'],
                    'selected_n3' => $payload['n3'],
                    'selected_n4' => $payload['n4'],
                    'selected_n5' => $payload['n5'],
                    'date_document' => $payload['date'],
                    'date_estimated' => 0,
                    'proposed_destination' => implode('/', array_filter([
                        strtolower($payload['n1']), strtolower($payload['n2']),
                        strtolower($payload['n3']), strtolower($payload['n4']),
                        strtolower($payload['n5']), strtolower($payload['n6']),
                    ])),
                ],
                $payload
            );
            $reasons = ged_v2_compute_review_reasons($scores);

            naming_respond(true, [
                'name_canonical'      => $built['name_canonical'],
                'name_export'         => $exportName,
                'segments'            => $built['segments'],
                'truncated'           => $built['truncated'],
                'rule_module'         => $built['rule_module'],
                'scores'              => $scores,
                'needs_review_reason' => $reasons,
            ]);
            break;
        }

        case 'parse_canonical': {
            $name = (string)($_REQUEST['name'] ?? '');
            if ($name === '') throw new RuntimeException('name requis');
            $parsed = ged_v2_parse_canonical_name($name);
            naming_respond(true, $parsed);
            break;
        }

        case 'n6_autocomplete': {
            $q = (string)($_GET['q'] ?? '');
            $n1 = (string)($_GET['n1'] ?? '');
            $limit = (int)($_GET['limit'] ?? 10);
            if ($limit < 1 || $limit > 50) $limit = 10;
            $sugg = ged_v2_suggest_n6($q, $n1 !== '' ? $n1 : null, $limit);
            naming_respond(true, ['suggestions' => $sugg]);
            break;
        }

        case 'cascade_options': {
            // Wrapper du get_levels existant pour cohérence d'API V2
            $level = (int)($_GET['level'] ?? 0);
            if ($level < 1 || $level > 5) throw new RuntimeException('level entre 1 et 5');
            $parents = [
                'n1' => (string)($_GET['n1'] ?? ''),
                'n2' => (string)($_GET['n2'] ?? ''),
                'n3' => (string)($_GET['n3'] ?? ''),
                'n4' => (string)($_GET['n4'] ?? ''),
            ];
            $opts = ged_import_get_levels_for($level, $parents);
            naming_respond(true, ['level' => $level, 'options' => $opts]);
            break;
        }

        case 'feedback_learning': {
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $field = (string)($_POST['field'] ?? '');
            if ($field === '') throw new RuntimeException('field requis');
            ged_v2_record_feedback(
                $field,
                isset($_POST['suggestion_value']) ? (string)$_POST['suggestion_value'] : null,
                isset($_POST['correction_value']) ? (string)$_POST['correction_value'] : null,
                isset($_POST['item_id']) ? (int)$_POST['item_id'] : null,
                isset($_POST['document_id']) ? (int)$_POST['document_id'] : null,
                isset($_POST['old_filename']) ? (string)$_POST['old_filename'] : null,
                isset($_POST['module']) ? (string)$_POST['module'] : null,
                isset($_POST['weight']) ? (int)$_POST['weight'] : 100
            );
            naming_respond(true);
            break;
        }

        case 'find_duplicates_smart': {
            $itemId = (int)($_REQUEST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            $st = ged_naming_v2_pdo()->prepare("SELECT * FROM ged_import_items WHERE id = ?");
            $st->execute([$itemId]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new RuntimeException('Item introuvable');
            $res = ged_v2_find_duplicates_smart($item);
            naming_respond(true, $res);
            break;
        }

        case 'recompute_item_scores': {
            // Recalcule les scores granulaires d'un item ged_import_items + persiste
            if ($method !== 'POST') throw new RuntimeException('POST requis');
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_id requis');
            $pdo = ged_naming_v2_pdo();
            $st = $pdo->prepare("SELECT * FROM ged_import_items WHERE id = ?");
            $st->execute([$itemId]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new RuntimeException('Item introuvable');

            $scores = ged_v2_compute_scores_granular($item, [
                'ref_entite' => $item['entity_id_resolved'] ?? null,
                'nom_entite' => $item['entity_label_resolved'] ?? null,
                'date'       => $item['date_document'] ?? null,
                'title'      => $item['title_user'] ?? null,
            ]);
            $reasons = ged_v2_compute_review_reasons($scores, $item);

            $pdo->prepare("
                UPDATE ged_import_items
                SET score_type = ?, score_entity = ?, score_date = ?,
                    score_structure = ?, score_destination = ?,
                    confidence_score = ?, score_version = ?,
                    needs_review_reason = ?
                WHERE id = ?
            ")->execute([
                $scores['score_type'], $scores['score_entity'], $scores['score_date'],
                $scores['score_structure'], $scores['score_destination'],
                $scores['score_global'], $scores['score_version'],
                empty($reasons) ? null : json_encode($reasons),
                $itemId,
            ]);
            naming_respond(true, ['scores' => $scores, 'needs_review_reason' => $reasons]);
            break;
        }

        default:
            http_response_code(400);
            naming_respond(false, ['error' => "Action inconnue : {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    naming_respond(false, ['error' => $e->getMessage()]);
}
