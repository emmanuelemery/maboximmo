<?php
declare(strict_types=1);
set_time_limit(0);

/**
 * Script d'importation directe des relevés bancaires PDF
 * (Sans passage par l'interface ZIP)
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/modules/ged/ged_import_releves_banque_zip_lib.php';

$pdo = $GLOBALS['pdo'];

// Configuration
$basePdfDir = 'C:/tmp/MABOXIMMO_COMPTA/RELEVES BANQUE CREDIT MUTUEL/MIONS';
$months = ['03_MARS 2026 CREDIT MUTUEL', '04_AVRIL 2026 CREDIT MUTUEL'];
$societeId = null; // null = admin, ou l'id de la société MIONS
$userId = 1; // Admin user
$defaultYYYYMM = null;

echo "=== IMPORT DIRECT RELEVÉS BANCAIRES ===\n\n";

// Vérifier ZipArchive
if (!class_exists('ZipArchive')) {
    die("❌ ZipArchive non disponible\n");
}

// Charger immeubles
try {
    $immeubles = ged_releves_load_immeubles($pdo, $societeId ? $societeId : 0);
    echo "✓ " . count($immeubles) . " immeubles chargés\n";
} catch (Throwable $e) {
    die("❌ Erreur chargement immeubles: " . $e->getMessage() . "\n");
}

// Créer batch
try {
    $st = $pdo->prepare("
        INSERT INTO ged_import_releves_batches (id_societe, created_by, statut, mois_annee_defaut, nb_zip)
        VALUES (:sid, :uid, 'extracting', :def, :nb)
    ");
    $st->execute([
        'sid' => $societeId,
        'uid' => $userId,
        'def' => $defaultYYYYMM,
        'nb'  => count($months),
    ]);
    $batchId = (int)$pdo->lastInsertId();
    echo "✓ Batch créé: #$batchId\n\n";
} catch (Throwable $e) {
    die("❌ Erreur création batch: " . $e->getMessage() . "\n");
}

// Répertoire de stockage
$root = dirname(__DIR__);
$base = $root . '/storage/ged/import_releves_banque_zip';
$pdfDir = $base . '/_batch_' . $batchId . '/pdfs';
@mkdir($pdfDir, 0775, true);

// Import des PDFs
$pdfCount = 0;
$recognized = 0;
$uncertain = 0;
$errors = 0;

foreach ($months as $monthFolder) {
    $monthPath = str_replace('/', '\\', $basePdfDir) . '\\' . $monthFolder;

    if (!is_dir($monthPath)) {
        echo "⚠️  Dossier introuvable: $monthPath\n";
        continue;
    }

    echo "📁 Traitement: $monthFolder\n";
    $files = glob($monthPath . '/*.pdf');
    echo "   Fichiers PDF trouvés: " . count($files) . "\n";

    foreach ($files as $filePath) {
        if ($pdfCount >= 800) break;

        $fileName = basename($filePath);
        $pdfCount++;

        // Copy to storage
        $uniq = bin2hex(random_bytes(6));
        $origSafe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $fileName) ?? ('pdf_' . $pdfCount . '.pdf');
        $origSafe = mb_substr($origSafe, 0, 180);
        $destAbs = $pdfDir . '/' . $pdfCount . '_' . $uniq . '_' . $origSafe;

        if (!@copy($filePath, $destAbs)) {
            echo "   ❌ Impossible de copier: $fileName\n";
            $errors++;
            continue;
        }

        // SHA + size
        $sha = hash_file('sha256', $destAbs);
        $sz = filesize($destAbs);

        // Dédup
        $stDup = $pdo->prepare("SELECT id FROM ged_import_releves_items WHERE batch_id=? AND pdf_sha256=? LIMIT 1");
        $stDup->execute([$batchId, $sha]);
        $isDup = (int)($stDup->fetchColumn() ?: 0) > 0;

        if (!$isDup) {
            $stDup2 = $pdo->prepare("SELECT id FROM ged_import_releves_items WHERE pdf_sha256=? AND statut='imported' LIMIT 1");
            $stDup2->execute([$sha]);
            if ((int)($stDup2->fetchColumn() ?: 0) > 0) {
                $isDup = true;
            }
        }

        // Extract PDF text
        $pdfText = null;
        try {
            $pdfText = gedPdfExtractText($destAbs, 80);
        } catch (Throwable) {
            $pdfText = null;
        }

        // Detect metadata
        [$yy, $mm, $perReason] = ged_releves_detect_period($fileName, $pdfText, $defaultYYYYMM);
        [$bank, $bankReason]   = ged_releves_detect_bank($fileName, $pdfText);
        [$immId, $immScore, $immReason] = ged_releves_match_immeuble($fileName, $pdfText, $immeubles);

        $logiciel = null;
        if ($immId !== null) {
            foreach ($immeubles as $immRow) {
                if ((int)$immRow['id'] === (int)$immId) {
                    $logiciel = $immRow['logiciel'] ?? null;
                    break;
                }
            }
        }

        // Determine status
        $status = 'analyzed';
        $confGlobal = null;
        $confImm = null;
        $raison = implode(' | ', array_filter([
            $perReason !== '' ? "periode=$perReason" : null,
            $bankReason !== '' ? "banque=$bankReason" : null,
            $immReason !== '' ? "immeuble=$immReason" : null,
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
        $rootNorm = str_replace('\\', '/', $root);
        $destNorm = str_replace('\\', '/', $destAbs);
        $rel = str_starts_with($destNorm, $rootNorm . '/')
            ? substr($destNorm, strlen($rootNorm) + 1)
            : $destNorm;

        try {
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
            $stIns->execute([
                'bid'    => $batchId,
                'zip'    => 'direct_import',
                'fn'     => mb_substr($fileName, 0, 255),
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

            $immName = 'N/A';
            if ($immId) {
                foreach ($immeubles as $row) {
                    if ($row['id'] == $immId) {
                        $immName = $row['nom_immeuble'] ?? 'N/A';
                        break;
                    }
                }
            }

            echo "   ✓ $fileName [$status] -> $immName ($yy-$mm)\n";
        } catch (Throwable $e) {
            echo "   ❌ Insert failed: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}

// Update batch stats
try {
    $finalStat = ($pdfCount > 0) ? 'ready' : 'error';
    $pdo->prepare("
        UPDATE ged_import_releves_batches
        SET statut=:st, nb_pdf=:nb, nb_reconnus=:rec, nb_a_valider=:unc, nb_erreurs=:err
        WHERE id=:id
    ")->execute([
        'st'  => $finalStat,
        'nb'  => $pdfCount,
        'rec' => $recognized,
        'unc' => $uncertain,
        'err' => $errors,
        'id'  => $batchId,
    ]);
} catch (Throwable $e) {
    echo "❌ Erreur mise à jour batch: " . $e->getMessage() . "\n";
}

echo "\n=== RÉSUMÉ ===\n";
echo "Batch #$batchId\n";
echo "PDFs traités: $pdfCount\n";
echo "  - Reconnus: $recognized\n";
echo "  - À valider: $uncertain\n";
echo "  - Erreurs: $errors\n";
echo "\nAccès: http://localhost:3080/app/releves?batch_id=$batchId\n";
