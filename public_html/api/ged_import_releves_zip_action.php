<?php
declare(strict_types=1);
set_time_limit(300);

/**
 * GED — Import ZIP relevés bancaires : actions (update/validate/reject/reanalyze)
 * Fichier : public_html/api/ged_import_releves_zip_action.php
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/csrf.php';
require_login();

require_once dirname(__DIR__) . '/modules/ged/ged_import_releves_banque_zip_lib.php';
require_once dirname(__DIR__) . '/modules/ged/ged_storage.php';
require_once dirname(__DIR__) . '/modules/ged/ged_logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST requis']);
    exit;
}

verify_csrf_any('ged_import_releves_zip');

$pdo       = $GLOBALS['pdo'];
$userId    = (int)current_user_id();
$roleId    = (int)current_role_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

$action  = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$batchId = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : 0;
if ($batchId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'batch_id manquant']);
    exit;
}

function releves_guard_batch(PDO $pdo, int $batchId, bool $isAdmin, int $societeId): array
{
    $where = "id = :id";
    $params = ['id' => $batchId];
    if (!$isAdmin && $societeId > 0) {
        $where .= " AND (id_societe = :sid OR id_societe IS NULL)";
        $params['sid'] = $societeId;
    }
    $st = $pdo->prepare("SELECT * FROM ged_import_releves_batches WHERE {$where} LIMIT 1");
    $st->execute($params);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) throw new RuntimeException('Batch introuvable');
    return $b;
}

function releves_parse_period(?string $yyyymm): array
{
    $yyyymm = $yyyymm !== null ? trim($yyyymm) : '';
    if ($yyyymm === '') return [null, null];
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $yyyymm, $m)) return [null, null];
    return [(int)$m[1], (int)$m[2]];
}

try {
    $batch = releves_guard_batch($pdo, $batchId, $isAdmin, $societeId);

    switch ($action) {
        case 'update_item': {
            $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
            if ($itemId <= 0) throw new RuntimeException('item_id manquant');

            $idImmeuble = isset($_POST['id_immeuble']) ? (int)$_POST['id_immeuble'] : 0;
            $logiciel   = isset($_POST['logiciel_comptable']) ? strtoupper(trim((string)$_POST['logiciel_comptable'])) : '';
            if ($logiciel === '') $logiciel = null;
            if ($logiciel !== null && !in_array($logiciel, ['SEPTEO', 'LOJJI', 'AUTRE'], true)) {
                throw new RuntimeException('logiciel_comptable invalide');
            }
            $banque = isset($_POST['banque_detectee']) ? trim((string)$_POST['banque_detectee']) : '';
            if ($banque === '') $banque = null;
            $periode = isset($_POST['periode']) ? trim((string)$_POST['periode']) : '';
            [$yy, $mm] = releves_parse_period($periode !== '' ? $periode : null);

            $upd = $pdo->prepare("
                UPDATE ged_import_releves_items
                SET id_immeuble = :imm,
                    logiciel_comptable = :log,
                    banque_detectee = :bank,
                    periode_annee = :yy,
                    periode_mois = :mm,
                    statut = CASE
                        WHEN :imm2 IS NULL THEN 'uncertain'
                        WHEN statut IN ('imported','rejected') THEN statut
                        ELSE 'validated'
                    END
                WHERE id = :id AND batch_id = :bid
            ");
            $immVal = $idImmeuble > 0 ? $idImmeuble : null;
            $upd->execute([
                'imm'  => $immVal,
                'imm2' => $immVal,
                'log'  => $logiciel,
                'bank' => $banque !== null ? mb_substr($banque, 0, 80) : null,
                'yy'   => $yy,
                'mm'   => $mm,
                'id'   => $itemId,
                'bid'  => $batchId,
            ]);

            // Si immeuble.logiciel_comptable est NULL, on le remplit (sans écraser un existant)
            if ($immVal !== null && $logiciel !== null) {
                try {
                    $cur = $pdo->prepare("SELECT logiciel_comptable FROM immeubles WHERE id = ? LIMIT 1");
                    $cur->execute([$immVal]);
                    $curVal = $cur->fetchColumn();
                    $curVal = is_string($curVal) ? strtoupper(trim($curVal)) : '';
                    if ($curVal === '') {
                        $pdo->prepare("UPDATE immeubles SET logiciel_comptable = ? WHERE id = ?")->execute([$logiciel, $immVal]);
                    }
                } catch (Throwable) {
                    // best-effort
                }
            }

            echo json_encode(['ok' => true, 'message' => 'Item mis à jour']);
            exit;
        }

        case 'reanalyze': {
            $pdo->prepare("UPDATE ged_import_releves_batches SET statut='analyzing' WHERE id=?")->execute([$batchId]);
            $default = isset($batch['mois_annee_defaut']) ? (string)$batch['mois_annee_defaut'] : null;
            $immeubles = ged_releves_load_immeubles($pdo, (!$isAdmin && $societeId > 0) ? $societeId : 0);
            $root = dirname(__DIR__, 2);

            $st = $pdo->prepare("
                SELECT id, fichier_original, pdf_rel_path, statut
                FROM ged_import_releves_items
                WHERE batch_id = ?
                  AND statut NOT IN ('imported','rejected')
                ORDER BY id ASC
                LIMIT 2000
            ");
            $st->execute([$batchId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $updated = 0;
            foreach ($rows as $r) {
                $abs = (string)($r['pdf_rel_path'] ?? '');
                $abs = str_replace('\\', '/', $abs);
                if ($abs !== '' && !preg_match('/^[A-Za-z]:\//', $abs) && !str_starts_with($abs, '/')) {
                    $abs = str_replace('\\', '/', $root) . '/' . ltrim($abs, '/');
                }
                if ($abs === '' || !is_file($abs)) {
                    $pdo->prepare("UPDATE ged_import_releves_items SET statut='error', erreur='PDF staging introuvable' WHERE id=?")->execute([(int)$r['id']]);
                    continue;
                }

                $pdfText = null;
                try { $pdfText = gedPdfExtractText($abs, 80); } catch (Throwable) { $pdfText = null; }
                [$yy, $mm, $perReason] = ged_releves_detect_period((string)$r['fichier_original'], $pdfText, $default);
                [$bank, $bankReason]   = ged_releves_detect_bank((string)$r['fichier_original'], $pdfText);
                [$immId, $immScore, $immReason] = ged_releves_match_immeuble((string)$r['fichier_original'], $pdfText, $immeubles);

                $logiciel = null;
                if ($immId !== null) {
                    foreach ($immeubles as $immRow) {
                        if ((int)$immRow['id'] === (int)$immId) { $logiciel = $immRow['logiciel'] ?? null; break; }
                    }
                }

                $status = ($immId !== null && $immScore >= 85.0) ? 'recognized'
                        : (($immId !== null && $immScore >= 60.0) ? 'uncertain' : 'uncertain');

                $raison = implode(' | ', array_filter([
                    $perReason !== '' ? "periode={$perReason}" : null,
                    $bankReason !== '' ? "banque={$bankReason}" : null,
                    $immReason !== '' ? "immeuble={$immReason}" : null,
                ]));

                $pdo->prepare("
                    UPDATE ged_import_releves_items
                    SET id_immeuble=:imm,
                        logiciel_comptable=:log,
                        banque_detectee=:bank,
                        periode_annee=:yy,
                        periode_mois=:mm,
                        confiance_globale=:cg,
                        confiance_immeuble=:ci,
                        raison_detection=:raison,
                        statut=:stat,
                        erreur=NULL
                    WHERE id=:id AND batch_id=:bid
                ")->execute([
                    'imm' => $immId,
                    'log' => $logiciel,
                    'bank' => $bank,
                    'yy' => $yy,
                    'mm' => $mm,
                    'cg' => $immScore,
                    'ci' => $immScore,
                    'raison' => $raison !== '' ? $raison : null,
                    'stat' => $status,
                    'id' => (int)$r['id'],
                    'bid' => $batchId,
                ]);
                $updated++;
            }

            $pdo->prepare("UPDATE ged_import_releves_batches SET statut='ready' WHERE id=?")->execute([$batchId]);
            echo json_encode(['ok' => true, 'message' => "Ré-analyse terminée ({$updated} items)"]);
            exit;
        }

        case 'reject_unrecognized': {
            $st = $pdo->prepare("
                UPDATE ged_import_releves_items
                SET statut='rejected', validated_at=NOW(), validated_by=?
                WHERE batch_id=?
                  AND statut NOT IN ('imported','rejected')
                  AND (id_immeuble IS NULL OR statut IN ('uncertain','error'))
            ");
            $st->execute([$userId ?: null, $batchId]);
            echo json_encode(['ok' => true, 'message' => 'Items non reconnus rejetés', 'affected' => $st->rowCount()]);
            exit;
        }

        case 'validate_recognized':
        case 'validate_all': {
            $driver = ged_storage_default();

            $default = isset($batch['mois_annee_defaut']) ? (string)$batch['mois_annee_defaut'] : null;
            [$defY, $defM] = releves_parse_period($default);

            $pdo->prepare("UPDATE ged_import_releves_batches SET statut='importing' WHERE id=?")->execute([$batchId]);

            $cond = ($action === 'validate_recognized')
                ? "i.statut IN ('recognized','validated')"
                : "i.statut IN ('recognized','uncertain','validated')";

            $st = $pdo->prepare("
                SELECT i.*,
                       m.reference_immeuble, m.nom_immeuble, m.adresse_1, m.code_postal, m.ville,
                       m.logiciel_comptable AS imm_logiciel
                FROM ged_import_releves_items i
                LEFT JOIN immeubles m ON m.id = i.id_immeuble
                WHERE i.batch_id = :bid
                  AND i.id_immeuble IS NOT NULL
                  AND i.statut NOT IN ('imported','rejected','duplicate')
                  AND {$cond}
                ORDER BY i.id ASC
                LIMIT 2000
            ");
            $st->execute(['bid' => $batchId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $root = dirname(__DIR__, 2);
            $imported = 0;
            $skipped = 0;
            $failed = 0;
            $sinceKeepalive = 0;

            foreach ($rows as $r) {
                $itemId = (int)($r['id'] ?? 0);
                $sinceKeepalive++;
                if ($sinceKeepalive >= 20 && function_exists('db_keepalive')) {
                    $pdo = db_keepalive();
                    $GLOBALS['pdo'] = $pdo;
                    $sinceKeepalive = 0;
                }

                $rel = (string)($r['pdf_rel_path'] ?? '');
                $relNorm = str_replace('\\', '/', $rel);
                $abs = $relNorm;
                if ($abs !== '' && !preg_match('/^[A-Za-z]:\//', $abs) && !str_starts_with($abs, '/')) {
                    $abs = str_replace('\\', '/', $root) . '/' . ltrim($abs, '/');
                }
                if ($abs === '' || !is_file($abs)) {
                    $pdo->prepare("UPDATE ged_import_releves_items SET statut='error', erreur='PDF staging introuvable' WHERE id=?")->execute([$itemId]);
                    $failed++;
                    continue;
                }

                $yy = isset($r['periode_annee']) ? (int)$r['periode_annee'] : 0;
                $mm = isset($r['periode_mois']) ? (int)$r['periode_mois'] : 0;
                if ($yy <= 0 || $mm <= 0) {
                    if ($defY !== null && $defM !== null) {
                        $yy = $defY; $mm = $defM;
                    } else {
                        $pdo->prepare("UPDATE ged_import_releves_items SET statut='uncertain', erreur='Période manquante' WHERE id=?")->execute([$itemId]);
                        $skipped++;
                        continue;
                    }
                }

                $logiciel = (string)($r['imm_logiciel'] ?? $r['logiciel_comptable'] ?? '');
                $logiciel = strtoupper(trim($logiciel));
                if ($logiciel === '') {
                    $pdo->prepare("UPDATE ged_import_releves_items SET statut='uncertain', erreur='logiciel_comptable manquant sur immeuble' WHERE id=?")->execute([$itemId]);
                    $skipped++;
                    continue;
                }

                $immRow = [
                    'id' => (int)($r['id_immeuble'] ?? 0),
                    'reference_immeuble' => $r['reference_immeuble'] ?? null,
                    'nom_immeuble' => $r['nom_immeuble'] ?? null,
                ];

                $sha = (string)($r['pdf_sha256'] ?? '');
                if ($sha === '') {
                    try { [$sha, ] = ged_releves_sha_size($abs); } catch (Throwable) { $sha = bin2hex(random_bytes(6)); }
                }

                $finalName = ged_releves_build_pdf_name($yy, $mm, $immRow, $r['banque_detectee'] ?? null, (string)($r['fichier_original'] ?? 'releve.pdf'), $sha);
                if ($driver->getName() === 'local') {
                    $finalName = preg_replace('/\.pdf$/i', '_ITEM' . $itemId . '.pdf', $finalName) ?? $finalName;
                }

                try {
                    $folderImm = ged_releves_drive_folder_for_immeuble($driver, $immRow, $yy, $mm);
                    $up1 = $driver->upload($abs, $finalName, $folderImm, 'application/pdf');

                    $folderCompta = ged_releves_drive_folder_for_compta($driver, $logiciel, $yy, $mm);

                    $compId = null;
                    $compUrl = null;
                    if ($driver->getName() === 'google_drive' && method_exists($driver, 'createShortcut')) {
                        /** @var GedStorageGoogleDrive $driver */
                        $sc = $driver->createShortcut((string)$up1['file_id'], $finalName, $folderCompta);
                        $compId = (string)($sc['file_id'] ?? '');
                        $compUrl = $compId !== '' ? ged_drive_file_url($compId) : null;
                    } else {
                        $up2 = $driver->upload($abs, $finalName, $folderCompta, 'application/pdf');
                        $compId = (string)($up2['file_id'] ?? '');
                        $compUrl = $compId !== '' ? (($driver->getName() === 'google_drive') ? ged_drive_file_url($compId) : null) : null;
                    }

                    $immUrl = ($driver->getName() === 'google_drive' && !empty($up1['file_id'])) ? ged_drive_file_url((string)$up1['file_id']) : null;

                    $pdo->prepare("
                        UPDATE ged_import_releves_items
                        SET statut='imported',
                            validated_at=NOW(),
                            validated_by=:uid,
                            logiciel_comptable=:log,
                            drive_file_id_immeuble=:f1,
                            drive_file_id_compta=:f2,
                            drive_url_immeuble=:u1,
                            drive_url_compta=:u2,
                            erreur=NULL
                        WHERE id=:id AND batch_id=:bid
                    ")->execute([
                        'uid' => $userId ?: null,
                        'log' => $logiciel,
                        'f1'  => (string)($up1['file_id'] ?? ''),
                        'f2'  => $compId,
                        'u1'  => $immUrl,
                        'u2'  => $compUrl,
                        'id'  => $itemId,
                        'bid' => $batchId,
                    ]);

                    $imported++;
                } catch (Throwable $e) {
                    $pdo->prepare("UPDATE ged_import_releves_items SET statut='error', erreur=:e WHERE id=:id")
                        ->execute(['e' => mb_substr($e->getMessage(), 0, 2000), 'id' => $itemId]);
                    gedLog('import_releves_zip', 'import error', ['batch_id' => $batchId, 'item_id' => $itemId, 'err' => $e->getMessage()]);
                    $failed++;
                }
            }

            // Recalc batch counters (best-effort)
            $cnt = $pdo->prepare("
                SELECT
                  SUM(statut='imported') AS imported,
                  SUM(statut IN ('recognized')) AS rec,
                  SUM(statut IN ('uncertain','validated')) AS unc,
                  SUM(statut='error') AS err,
                  COUNT(*) AS total
                FROM ged_import_releves_items WHERE batch_id=?
            ");
            $cnt->execute([$batchId]);
            $s = $cnt->fetch(PDO::FETCH_ASSOC) ?: [];

            $newStat = ((int)($s['err'] ?? 0) > 0) ? 'ready' : 'done';
            $pdo->prepare("
                UPDATE ged_import_releves_batches
                SET statut=:st,
                    nb_pdf=:t,
                    nb_reconnus=:r,
                    nb_a_valider=:u,
                    nb_erreurs=:e
                WHERE id=:id
            ")->execute([
                'st' => $newStat,
                't'  => (int)($s['total'] ?? 0),
                'r'  => (int)($s['rec'] ?? 0),
                'u'  => (int)($s['unc'] ?? 0),
                'e'  => (int)($s['err'] ?? 0),
                'id' => $batchId,
            ]);

            echo json_encode([
                'ok' => true,
                'message' => "Import Drive: {$imported} importés, {$skipped} ignorés, {$failed} erreurs",
                'imported' => $imported,
                'skipped'  => $skipped,
                'failed'   => $failed,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            throw new RuntimeException('Action inconnue : ' . $action);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
