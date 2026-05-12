<?php
declare(strict_types=1);
set_time_limit(300);

/**
 * GED — Import ZIP relevés bancaires : upload + extraction/analyse (async best-effort)
 * Fichier : public_html/api/ged_import_releves_zip_upload.php
 *
 * Reçoit des ZIP, crée un batch, répond immédiatement (évite timeouts),
 * puis extrait/analyse en arrière-plan (si fastcgi_finish_request disponible).
 */

@ini_set('display_errors', '0');
error_reporting(0);
ob_start();

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
require_once dirname(__DIR__) . '/inc/csrf.php';
require_once dirname(__DIR__) . '/modules/ged/ged_import_releves_banque_zip_lib.php';

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) @ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'      => false,
            'message' => 'Erreur fatale serveur : ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function releves_up_respond(bool $ok, string $msg = '', array $extra = []): void
{
    while (ob_get_level()) @ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function releves_up_respond_and_continue(bool $ok, string $msg = '', array $extra = []): void
{
    while (ob_get_level()) @ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        @flush();
    }
    @ignore_user_abort(true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    releves_up_respond(false, 'POST requis');
}

verify_csrf_any('ged_import_releves_zip');

$pdo       = $GLOBALS['pdo'];
$userId    = (int)current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$roleId    = (int)current_role_id();
$isAdmin   = in_array($roleId, [1, 7, 8], true);

// Fichier input : zips[]
if (empty($_FILES['zips'])) {
    releves_up_respond(false, 'Aucun fichier reçu (champ zips[] manquant)');
}

$defaultYYYYMM = isset($_POST['mois_annee_defaut']) ? trim((string)$_POST['mois_annee_defaut']) : '';
if ($defaultYYYYMM === '') $defaultYYYYMM = null;
if ($defaultYYYYMM !== null && !preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $defaultYYYYMM)) {
    releves_up_respond(false, "mois_annee_defaut invalide (attendu YYYY-MM)");
}

// Normalise $_FILES multiple
$names = $_FILES['zips']['name'] ?? null;
$tmps  = $_FILES['zips']['tmp_name'] ?? null;
$errs  = $_FILES['zips']['error'] ?? null;
$sizes = $_FILES['zips']['size'] ?? null;
if (!is_array($names) || !is_array($tmps) || !is_array($errs) || !is_array($sizes)) {
    releves_up_respond(false, 'Payload upload invalide (zips[])');
}

$count = count($names);
if ($count <= 0) releves_up_respond(false, 'Aucun ZIP');
if ($count > GED_RELEVES_ZIP_MAX_ZIPS) {
    releves_up_respond(false, 'Trop de ZIP (' . $count . '). Limite: ' . GED_RELEVES_ZIP_MAX_ZIPS);
}

// ── 1) Création batch ──────────────────────────────────────────────────────
try {
    $st = $pdo->prepare("
        INSERT INTO ged_import_releves_batches (id_societe, created_by, statut, mois_annee_defaut, nb_zip)
        VALUES (:sid, :uid, 'extracting', :def, :nb)
    ");
    $st->execute([
        'sid' => $societeId ?: null,
        'uid' => $userId ?: null,
        'def' => $defaultYYYYMM,
        'nb'  => $count,
    ]);
    $batchId = (int)$pdo->lastInsertId();
    if ($batchId <= 0) throw new RuntimeException('batch_id introuvable');
} catch (Throwable $e) {
    releves_up_respond(false, 'Erreur création batch: ' . $e->getMessage());
}

// ── 2) Quarantaine ZIP ────────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
$base = $root . '/storage/ged/import_releves_banque_zip';
$qDir = $base . '/_batch_' . $batchId . '/zips';
$pdfDir = $base . '/_batch_' . $batchId . '/pdfs';
@mkdir($qDir, 0775, true);
@mkdir($pdfDir, 0775, true);

$zipPaths = [];
for ($i = 0; $i < $count; $i++) {
    $name = (string)($names[$i] ?? '');
    $tmp  = (string)($tmps[$i] ?? '');
    $err  = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($sizes[$i] ?? 0);

    if ($err !== UPLOAD_ERR_OK) {
        releves_up_respond(false, "Erreur upload ZIP #{$i} : code {$err}");
    }
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        releves_up_respond(false, "Fichier non ZIP : {$name}");
    }
    if ($size <= 0 || $size > GED_RELEVES_ZIP_MAX_ZIP_BYTES) {
        releves_up_respond(false, "ZIP trop volumineux : {$name} (" . $size . " octets)");
    }
    if (!is_uploaded_file($tmp)) {
        releves_up_respond(false, "Upload invalide : {$name}");
    }

    $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? ('zip_' . $i . '.zip');
    $safe = mb_substr($safe, 0, 180);
    $dest = $qDir . '/' . sprintf('%02d_', $i + 1) . $safe;
    if (!@move_uploaded_file($tmp, $dest)) {
        releves_up_respond(false, "Impossible de stocker le ZIP : {$name}");
    }
    $zipPaths[] = ['zip_name' => $name, 'zip_path' => $dest];
}

gedLog('import_releves_zip', 'batch created', [
    'batch_id' => $batchId,
    'zips'     => $count,
    'sid'      => $societeId,
    'uid'      => $userId,
]);

// Réponse immédiate (évite 504)
releves_up_respond_and_continue(true, 'ZIP reçus — extraction/analyse en cours', [
    'batch_id' => $batchId,
]);

// ── 3) Extraction + analyse ───────────────────────────────────────────────
try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive non disponible (extension PHP zip manquante).');
    }

    // Charge immeubles (scope societe). Admin = societe 0 => tout.
    $immeubles = ged_releves_load_immeubles($pdo, (!$isAdmin && $societeId > 0) ? $societeId : 0);

    $pdo->prepare("UPDATE ged_import_releves_batches SET statut='extracting' WHERE id=?")->execute([$batchId]);

    $pdfCount = 0;
    $recognized = 0;
    $uncertain = 0;
    $errors = 0;

    $sinceKeepalive = 0;
    foreach ($zipPaths as $zp) {
        $zipName = (string)$zp['zip_name'];
        $zipPath = (string)$zp['zip_path'];

        $za = new ZipArchive();
        $open = $za->open($zipPath);
        if ($open !== true) {
            $errors++;
            gedLog('import_releves_zip', 'zip open failed', ['batch_id' => $batchId, 'zip' => $zipName, 'code' => $open]);
            continue;
        }

        for ($idx = 0; $idx < $za->numFiles; $idx++) {
            if ($pdfCount >= GED_RELEVES_ZIP_MAX_PDFS_PER_BATCH) break;

            $stat = $za->statIndex($idx);
            if (!is_array($stat)) continue;
            $entry = (string)($stat['name'] ?? '');
            $isDir = str_ends_with($entry, '/');
            if ($isDir) continue;

            if (!ged_releves_zip_entry_is_safe($entry)) {
                gedLog('import_releves_zip', 'zip entry refused (unsafe)', ['batch_id' => $batchId, 'zip' => $zipName, 'entry' => $entry]);
                continue;
            }

            $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                gedLog('import_releves_zip', 'zip entry ignored (non-pdf)', ['batch_id' => $batchId, 'zip' => $zipName, 'entry' => $entry]);
                continue;
            }

            $entrySize = isset($stat['size']) ? (int)$stat['size'] : 0;
            if ($entrySize <= 0 || $entrySize > GED_RELEVES_ZIP_MAX_PDF_BYTES) {
                gedLog('import_releves_zip', 'zip entry ignored (size)', ['batch_id' => $batchId, 'zip' => $zipName, 'entry' => $entry, 'size' => $entrySize]);
                continue;
            }

            $stream = $za->getStream($entry);
            if (!is_resource($stream)) {
                gedLog('import_releves_zip', 'zip entry read failed', ['batch_id' => $batchId, 'zip' => $zipName, 'entry' => $entry]);
                continue;
            }

            $pdfCount++;
            $uniq = bin2hex(random_bytes(6));
            $origBase = basename(str_replace('\\', '/', $entry));
            $origSafe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $origBase) ?? ('pdf_' . $pdfCount . '.pdf');
            $origSafe = mb_substr($origSafe, 0, 180);
            $destAbs = $pdfDir . '/' . $pdfCount . '_' . $uniq . '_' . $origSafe;

            $out = @fopen($destAbs, 'wb');
            if (!$out) {
                fclose($stream);
                gedLog('import_releves_zip', 'write failed', ['batch_id' => $batchId, 'dest' => $destAbs]);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            // sanity mime PDF (best-effort)
            $mime = function_exists('mime_content_type') ? @mime_content_type($destAbs) : '';
            if (is_string($mime) && $mime !== '' && $mime !== 'application/pdf') {
                @unlink($destAbs);
                gedLog('import_releves_zip', 'ignored (mime)', ['batch_id' => $batchId, 'zip' => $zipName, 'file' => $origBase, 'mime' => $mime]);
                continue;
            }

            // SHA + size
            [$sha, $sz] = ged_releves_sha_size($destAbs);

            // Dédup : si le même sha existe déjà (dans ce batch OU importé dans un batch précédent) => duplicate
            $stDup = $pdo->prepare("SELECT id FROM ged_import_releves_items WHERE batch_id=? AND pdf_sha256=? LIMIT 1");
            $stDup->execute([$batchId, $sha]);
            $dupId = (int)($stDup->fetchColumn() ?: 0);
            $isDup = ($dupId > 0);
            if (!$isDup) {
                $stDup2 = $pdo->prepare("SELECT id FROM ged_import_releves_items WHERE pdf_sha256=? AND statut='imported' LIMIT 1");
                $stDup2->execute([$sha]);
                $dup2 = (int)($stDup2->fetchColumn() ?: 0);
                if ($dup2 > 0) $isDup = true;
            }

            // Texte PDF (best-effort) pour reconnaissance sans IA
            $pdfText = null;
            try { $pdfText = gedPdfExtractText($destAbs, 80); } catch (Throwable) { $pdfText = null; }

            // Période / banque / immeuble
            [$yy, $mm, $perReason] = ged_releves_detect_period($origBase, $pdfText, $defaultYYYYMM);
            [$bank, $bankReason]   = ged_releves_detect_bank($origBase, $pdfText);
            [$immId, $immScore, $immReason] = ged_releves_match_immeuble($origBase, $pdfText, $immeubles);

            $logiciel = null;
            if ($immId !== null) {
                foreach ($immeubles as $immRow) {
                    if ((int)$immRow['id'] === (int)$immId) { $logiciel = $immRow['logiciel'] ?? null; break; }
                }
            }

            $status = 'analyzed';
            $confGlobal = null;
            $confImm = null;
            $raison = implode(' | ', array_filter([
                $perReason !== '' ? "periode={$perReason}" : null,
                $bankReason !== '' ? "banque={$bankReason}" : null,
                $immReason !== '' ? "immeuble={$immReason}" : null,
            ]));

            if ($isDup) {
                $status = 'duplicate';
                $confGlobal = 0.0;
                $confImm = 0.0;
            } elseif ($immId !== null && $immScore >= 85.0) {
                $status = 'recognized';
                $confGlobal = $immScore;
                $confImm = $immScore;
                $recognized++;
            } elseif ($immId !== null && $immScore >= 60.0) {
                $status = 'uncertain';
                $confGlobal = $immScore;
                $confImm = $immScore;
                $uncertain++;
            } else {
                $status = 'uncertain';
                $confGlobal = $immScore > 0 ? $immScore : 30.0;
                $confImm = $immScore;
                $uncertain++;
            }

            // Insert item
            $sinceKeepalive++;
            if ($sinceKeepalive >= 25 && function_exists('db_keepalive')) {
                $pdo = db_keepalive();
                $GLOBALS['pdo'] = $pdo;
                $sinceKeepalive = 0;
            }
            $stIns = $pdo->prepare("
                INSERT INTO ged_import_releves_items (
                    batch_id, zip_original, fichier_original, pdf_rel_path, pdf_sha256, pdf_taille_octets,
                    id_immeuble, logiciel_comptable, banque_detectee, periode_annee, periode_mois,
                    confiance_globale, confiance_immeuble, raison_detection, statut
                ) VALUES (
                    :bid, :zip, :fn, :rel, :sha, :sz,
                    :imm, :log, :bank, :yy, :mm,
                    :cg, :ci, :raison, :statut
                )
            ");
            $rootNorm = str_replace('\\', '/', $root);
            $destNorm = str_replace('\\', '/', $destAbs);
            $rel = str_starts_with($destNorm, $rootNorm . '/')
                ? substr($destNorm, strlen($rootNorm) + 1)
                : $destNorm;

            $stIns->execute([
                'bid'    => $batchId,
                'zip'    => mb_substr($zipName, 0, 255),
                'fn'     => mb_substr($origBase, 0, 255),
                'rel'    => mb_substr($rel, 0, 500),
                'sha'    => $sha,
                'sz'     => $sz,
                'imm'    => $immId,
                'log'    => $logiciel,
                'bank'   => $bank,
                'yy'     => $yy,
                'mm'     => $mm,
                'cg'     => $confGlobal,
                'ci'     => $confImm,
                'raison' => $raison !== '' ? $raison : null,
                'statut' => $status,
            ]);
        }
        $za->close();
        if ($pdfCount >= GED_RELEVES_ZIP_MAX_PDFS_PER_BATCH) break;
    }

    // Stats batch
    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM ged_import_releves_items WHERE batch_id=?");
    $stCnt->execute([$batchId]);
    $totalItems = (int)$stCnt->fetchColumn();

    $finalStat = 'ready';
    $finalComment = null;
    if ($totalItems <= 0) {
        $finalStat = 'error';
        $finalComment = 'Erreur: aucun PDF détecté dans les ZIP (vérifie que les ZIP contiennent des .pdf non protégés et non corrompus).';
    }

    $pdo->prepare("
        UPDATE ged_import_releves_batches
        SET statut=:st,
            commentaire=:c,
            nb_pdf=:nb,
            nb_reconnus=:rec,
            nb_a_valider=:unc,
            nb_erreurs=:err
        WHERE id=:id
    ")->execute([
        'st'  => $finalStat,
        'c'   => $finalComment,
        'nb'  => $totalItems,
        'rec' => $recognized,
        'unc' => $uncertain,
        'err' => $errors,
        'id'  => $batchId,
    ]);

    gedLog('import_releves_zip', 'batch finalized', [
        'batch_id' => $batchId,
        'statut'   => $finalStat,
        'pdf'      => $totalItems,
        'recognized' => $recognized,
        'uncertain'  => $uncertain,
        'errors'     => $errors,
    ]);

} catch (Throwable $e) {
    $pdo->prepare("UPDATE ged_import_releves_batches SET statut='error', commentaire=:c WHERE id=:id")
        ->execute(['c' => mb_substr('Erreur: ' . $e->getMessage(), 0, 2000), 'id' => $batchId]);
    gedLog('import_releves_zip', 'batch error', ['batch_id' => $batchId, 'err' => $e->getMessage()]);
}
