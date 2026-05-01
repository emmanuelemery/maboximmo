<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Test reproductible du SOCLE complet.
 * Fichier : modules/ged/test_socle.php
 *
 * Couvre : naming + storage local + persistance ged_analyses (BDD).
 *
 * Usage :
 *   php public_html/modules/ged/test_socle.php
 *
 * Pré-requis :
 *   - Migrations sql/ged/001_*.sql et 002_*.sql appliquées
 *   - public_html/db_config_dev.php (ou db_config.php) configuré
 *
 * Multi-tenant : utilise id_societe=1, id_agence=1, role=1 (super-admin) pour le test.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('test_socle.php : exécutable uniquement en CLI.');
}

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once __DIR__ . '/ged_naming.php';
require_once __DIR__ . '/ged_storage.php';
require_once __DIR__ . '/ged_storage_local.php';

$pass = 0; $fail = 0;
function step(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL; }
    else     { $fail++; echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL; }
}

echo "=== GED Socle Tests (naming + storage + BDD) ===" . PHP_EOL;

// ── Section 1 : naming ──────────────────────────────────────────────────────
echo PHP_EOL . "── ged_naming ──" . PHP_EOL;

try {
    $name = gedBuildFilename([
        'type'        => 'FACTURE',
        'date'        => '2026-05-01',
        'ref_societe' => 'RE',
        'ref_agence'  => 'AGLYON',
        'objet_type'  => 'IMB',
        'objet_id'    => 123,
        'tiers'       => 'EDF',
        'description' => 'Electricite',
        'version'     => 1,
        'ext'         => 'pdf',
    ]);
    $expected = 'FACTURE_2026-05-01_RE_AGLYON_IMB-000123_EDF_ELECTRICITE_V1.pdf';
    step('Filename canonique', $name === $expected, "got={$name}");
} catch (Throwable $e) {
    step('Filename canonique', false, $e->getMessage());
}

try {
    $name = gedBuildFilename([
        'type'        => 'Mandat',
        'ref_societe' => 'régie emery',
        'ref_agence'  => 'Vienne 38',
        'objet_type'  => 'MDT',
        'objet_id'    => 7,
        'description' => 'SCI Hôtel-Dieu',
        'ext'         => 'PDF',
    ]);
    $okSlug = (strpos($name, 'MANDAT_') === 0)
           && (strpos($name, '_REGIE_EMERY_') !== false)
           && (strpos($name, '_VIENNE_38_') !== false)
           && (strpos($name, '_MDT-000007_') !== false)
           && (strpos($name, '_HOTEL_DIEU_') !== false || strpos($name, '_HOTEL_') !== false)
           && (substr($name, -4) === '.pdf');
    step('Slug + accents + casse', $okSlug, $name);
} catch (Throwable $e) {
    step('Slug + accents', false, $e->getMessage());
}

try {
    $longDesc = str_repeat('LOREM_IPSUM_DOLOR_SIT_AMET_', 10);
    $name = gedBuildFilename([
        'type' => 'TEST', 'ref_societe' => 'RE', 'ref_agence' => 'AGLYON',
        'objet_type' => 'IMB', 'objet_id' => 1, 'description' => $longDesc, 'ext' => 'pdf',
    ]);
    step('Cap 140 chars + extension préservée', strlen($name) <= 140 && substr($name, -4) === '.pdf', 'len=' . strlen($name));
} catch (Throwable $e) {
    step('Cap 140 chars', false, $e->getMessage());
}

try {
    $bad = false;
    try {
        gedBuildFilename(['type'=>'X','ref_societe'=>'RE','ref_agence'=>'AG','objet_type'=>'BIDON','objet_id'=>1,'ext'=>'pdf']);
    } catch (InvalidArgumentException $e) { $bad = true; }
    step('Rejet objet_type invalide', $bad);
} catch (Throwable $e) {
    step('Rejet objet_type invalide', false, $e->getMessage());
}

try {
    $parsed = gedParseFilename('FACTURE_2026-05-01_RE_AGLYON_IMB-000123_EDF_ELECTRICITE_V1.pdf');
    $okParse = is_array($parsed)
        && $parsed['type'] === 'FACTURE'
        && $parsed['date'] === '2026-05-01'
        && $parsed['ref_societe'] === 'RE'
        && $parsed['ref_agence'] === 'AGLYON'
        && $parsed['objet_type'] === 'IMB'
        && $parsed['objet_id'] === 123
        && $parsed['version'] === 1
        && $parsed['ext'] === 'pdf';
    step('Parse round-trip', $okParse);
} catch (Throwable $e) {
    step('Parse round-trip', false, $e->getMessage());
}

// ── Section 2 : storage local ───────────────────────────────────────────────
echo PHP_EOL . "── storage local ──" . PHP_EOL;

$srcPath = sys_get_temp_dir() . '/ged_socle_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($srcPath, "Socle test " . date('c'));
$srcSha = hash_file('sha256', $srcPath);

$localBase = dirname(__DIR__, 3) . '/storage/ged_socle_' . bin2hex(random_bytes(3));
$local = new GedStorageLocal($localBase);
$folder = $local->ensureFolder('00_A_CLASSER_IA');
$canonName = gedBuildFilename([
    'type'        => 'TEST',
    'ref_societe' => 'RE',
    'ref_agence'  => 'AGLYON',
    'objet_type'  => 'IMB',
    'objet_id'    => 1,
    'description' => 'Socle',
    'ext'         => 'txt',
]);
$up = $local->upload($srcPath, $canonName, $folder);
step('upload local + sha cohérent', $up['sha256'] === $srcSha, "name={$up['name']}");

// ── Section 3 : persistance BDD ged_analyses ────────────────────────────────
echo PHP_EOL . "── BDD ged_analyses ──" . PHP_EOL;

try {
    $pdo = db();
    $GLOBALS['pdo'] = $pdo;
    step('Connexion PDO', true);
} catch (Throwable $e) {
    step('Connexion PDO', false, $e->getMessage());
    echo PHP_EOL . "=== Résumé partiel : {$pass} PASS, {$fail} FAIL ===" . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

// Vérifie que la table ged_analyses existe (migrations appliquées ?)
try {
    $pdo->query("SELECT 1 FROM ged_analyses LIMIT 1");
    step('Table ged_analyses existe', true);
} catch (Throwable $e) {
    step('Table ged_analyses existe', false, "Applique sql/ged/001_*.sql et 002_*.sql d'abord");
    echo PHP_EOL . "=== Résumé partiel : {$pass} PASS, {$fail} FAIL ===" . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

// Vérifie que les colonnes Drive existent (migration 002)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM ged_analyses")->fetchAll(PDO::FETCH_COLUMN);
    $needed = ['sha256', 'storage_driver', 'storage_file_id', 'storage_folder_id',
               'objet_type', 'objet_id', 'ref_societe', 'ref_agence', 'nom_renomme', 'version_doc'];
    $missing = array_diff($needed, $cols);
    step('Colonnes storage présentes (migration 002)', empty($missing),
         empty($missing) ? '' : 'manquantes: ' . implode(',', $missing));
} catch (Throwable $e) {
    step('Colonnes storage', false, $e->getMessage());
}

// Vérifie la VIEW de compat agent_ged_analyses
try {
    $v = $pdo->query("SELECT COUNT(*) FROM agent_ged_analyses")->fetchColumn();
    step('VIEW agent_ged_analyses (compat)', true, "count={$v}");
} catch (Throwable $e) {
    step('VIEW agent_ged_analyses (compat)', false, $e->getMessage());
}

// INSERT de test (multi-tenant : id_societe + id_agence renseignés)
$insertedId = null;
try {
    $stmt = $pdo->prepare("
        INSERT INTO ged_analyses (
            document_id, document_table, source_type, ocr_engine, ia_engine,
            suggested_module, suggested_filename,
            confidence_score, status,
            id_societe, id_agence,
            sha256, storage_driver, storage_file_id, storage_folder_id,
            storage_size, storage_mime,
            objet_type, objet_id, ref_societe, ref_agence,
            nom_original, nom_renomme, extension, version_doc, date_document
        ) VALUES (
            NULL, 'test_socle', 'manual', 'none', 'test',
            'ADMIN', :nom_renomme,
            99.0, 'draft',
            :id_soc, :id_ag,
            :sha, 'local', :file_id, :folder_id,
            :size, 'text/plain',
            'IMB', 1, 'RE', 'AGLYON',
            :nom_ori, :nom_renomme2, 'txt', 1, :date_doc
        )
    ");
    $stmt->execute([
        'nom_renomme'  => $up['name'],
        'id_soc'       => 1,
        'id_ag'        => 1,
        'sha'          => $up['sha256'],
        'file_id'      => $up['file_id'],
        'folder_id'    => $up['folder_id'],
        'size'         => $up['size'],
        'nom_ori'      => basename($srcPath),
        'nom_renomme2' => $up['name'],
        'date_doc'     => date('Y-m-d'),
    ]);
    $insertedId = (int)$pdo->lastInsertId();
    step('INSERT ged_analyses', $insertedId > 0, "id={$insertedId}");
} catch (Throwable $e) {
    step('INSERT ged_analyses', false, $e->getMessage());
}

// SELECT roundtrip
if ($insertedId) {
    try {
        $row = $pdo->query("SELECT * FROM ged_analyses WHERE id = {$insertedId}")->fetch(PDO::FETCH_ASSOC);
        $ok = is_array($row)
            && $row['sha256'] === $up['sha256']
            && $row['storage_driver'] === 'local'
            && (int)$row['id_societe'] === 1
            && (int)$row['id_agence']  === 1
            && $row['nom_renomme'] === $up['name'];
        step('SELECT roundtrip + multi-tenant + sha', $ok);
    } catch (Throwable $e) {
        step('SELECT roundtrip', false, $e->getMessage());
    }

    // Vérifie aussi via la VIEW de compat
    try {
        $row2 = $pdo->query("SELECT id, sha256, storage_driver FROM agent_ged_analyses WHERE id = {$insertedId}")
                    ->fetch(PDO::FETCH_ASSOC);
        step('SELECT via VIEW agent_ged_analyses', is_array($row2) && $row2['sha256'] === $up['sha256']);
    } catch (Throwable $e) {
        step('SELECT via VIEW', false, $e->getMessage());
    }

    // DELETE de la ligne test
    try {
        $pdo->prepare("DELETE FROM ged_analyses WHERE id = ?")->execute([$insertedId]);
        step('DELETE ligne test', true);
    } catch (Throwable $e) {
        step('DELETE ligne test', false, $e->getMessage());
    }
}

// ── Cleanup ─────────────────────────────────────────────────────────────────
try { $local->delete($up['file_id']); } catch (Throwable) {}
@unlink($srcPath);
if (is_dir($localBase)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localBase, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($localBase);
}

echo PHP_EOL . "=== Résumé : {$pass} PASS, {$fail} FAIL ===" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
