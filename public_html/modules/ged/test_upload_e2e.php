<?php
declare(strict_types=1);

/**
 * GED — Test end-to-end COMPLET du pipeline d'upload.
 * Simule exactement ce que fait /api/ged_inbox_upload.php quand l'utilisateur
 * drop un PDF dans l'inbox, sans passer par HTTP.
 *
 * Usage : php public_html/modules/ged/test_upload_e2e.php
 *
 * Affiche chaque étape : stockage quarantaine, extraction IA, INSERT BDD.
 */

if (PHP_SAPI !== 'cli') exit('CLI only');

$root = dirname(__DIR__, 3);

// Charge config (sans bootstrap qui force display_errors=1)
foreach ([
    $root . '/u630423897/anthropic_config.php',
    $root . '/u630423897/maboximmo_openai_config.php',
] as $f) {
    if (is_file($f)) require_once $f;
}

require_once $root . '/public_html/config/db.php';
require_once $root . '/public_html/modules/ged/ged_storage.php';
require_once $root . '/public_html/modules/ged/ged_storage_local.php';
require_once $root . '/public_html/modules/ged/ged_extraction.php';

$fixture = $root . '/tests/fixtures/ged/02_facture_plombier.pdf';
if (!is_file($fixture)) exit("Fixture introuvable : {$fixture}\n");

echo "╔══════════════════════════════════════════════════════════════════════╗" . PHP_EOL;
echo "║       TEST END-TO-END : Upload + IA + Storage + BDD                  ║" . PHP_EOL;
echo "╚══════════════════════════════════════════════════════════════════════╝" . PHP_EOL . PHP_EOL;

// ── ÉTAPE 1 : Document source simulé (= ce que le user drop dans le navigateur) ──
$origName = basename($fixture);
$origSize = filesize($fixture);
$origSha  = hash_file('sha256', $fixture);
echo "📄 ÉTAPE 1 — Document à uploader" . PHP_EOL;
echo "   Nom    : {$origName}" . PHP_EOL;
echo "   Taille : {$origSize} octets" . PHP_EOL;
echo "   SHA256 : " . substr($origSha, 0, 16) . "..." . PHP_EOL . PHP_EOL;

// ── ÉTAPE 2 : Stockage en quarantaine LOCALE (driver storage local) ──
echo "📦 ÉTAPE 2 — Stockage quarantaine locale (driver local)" . PHP_EOL;
$base   = $root . '/storage/ged';
$local  = new GedStorageLocal($base);
$folder = $local->ensureFolder('00_A_CLASSER_IA');
$folder = $local->ensureFolder('_quarantine_' . date('Y-m'), $folder);
$uniq   = bin2hex(random_bytes(4));
$up = $local->upload($fixture, "{$uniq}_{$origName}", $folder);
$absStored = $base . '/' . $up['file_id'];

echo "   ✓ Stocké : {$absStored}" . PHP_EOL;
echo "   ✓ SHA cohérent : " . ($up['sha256'] === $origSha ? 'oui' : 'NON !') . PHP_EOL;
echo "   ✓ Folder : {$up['folder_id']}" . PHP_EOL . PHP_EOL;

// ── ÉTAPE 3 : Extraction IA ──
echo "🧠 ÉTAPE 3 — Extraction IA Claude Sonnet 4.6" . PHP_EOL;
$start = microtime(true);
$extr = gedExtractDocument($absStored);
$elapsed = round(microtime(true) - $start, 1);

echo "   ✓ Engine : " . ($extr['path_engine'] ?? '?') . PHP_EOL;
echo "   ✓ Modèle : " . ($extr['model_used'] ?? '?') . PHP_EOL;
echo "   ✓ Temps  : {$elapsed}s" . PHP_EOL;
echo "   ✓ OK     : " . ($extr['ok'] ? 'oui' : 'NON') . PHP_EOL . PHP_EOL;

if (!$extr['ok']) {
    echo "❌ Échec extraction. Erreurs :" . PHP_EOL;
    foreach ($extr['errors'] as $e) echo "   - {$e}" . PHP_EOL;
    exit(1);
}

$ai = $extr['data'];
echo "   📋 Champs extraits par l'IA :" . PHP_EOL;
$importants = ['type_document','module','niveau_2','niveau_3','date_document',
               'fournisseur','tiers_principal','montant_ttc','montant_ht','iban','bic',
               'numero_document','confiance_globale'];
foreach ($importants as $k) {
    $v = $ai[$k] ?? null;
    if ($v !== null && $v !== '') {
        $disp = is_string($v) ? '"' . mb_substr($v, 0, 60) . '"' : $v;
        echo "      " . str_pad($k, 22) . " : {$disp}" . PHP_EOL;
    }
}
echo PHP_EOL;

// ── ÉTAPE 4 : INSERT en BDD ──
echo "💾 ÉTAPE 4 — INSERT ged_analyses (status=to_validate)" . PHP_EOL;
$pdo = db();
$GLOBALS['pdo'] = $pdo;

$dateDoc = !empty($ai['date_document']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ai['date_document'])
    ? (string)$ai['date_document'] : null;

$stmt = $pdo->prepare("
    INSERT INTO ged_analyses (
        document_id, document_table, source_type,
        ocr_engine, ia_engine,
        suggested_module, suggested_level_2, suggested_level_3,
        detected_immeuble, detected_fournisseur, detected_montant, detected_date,
        suggested_action, confidence_score, status,
        id_societe, id_agence, ai_raw_response,
        sha256, storage_driver, storage_file_id, storage_folder_id,
        storage_size, storage_mime, tiers_nom, nom_original,
        extension, version_doc, date_document
    ) VALUES (
        NULL, 'ged_inbox_test_e2e', 'upload',
        :ocr_eng, :ia_eng,
        :module, :n2, :n3,
        :immeuble, :fournisseur, :montant, :date_doc,
        :action, :conf, 'to_validate',
        1, 1, :ai_raw,
        :sha, 'local', :file_id, :folder_id,
        :size, :mime, :tiers, :nom_ori,
        :ext, 1, :date_doc2
    )
");
$stmt->execute([
    'ocr_eng'   => $extr['path_engine'],
    'ia_eng'    => $extr['model_used'],
    'module'    => $ai['module']      ?? null,
    'n2'        => $ai['niveau_2']    ?? null,
    'n3'        => $ai['niveau_3']    ?? null,
    'immeuble'  => $ai['immeuble']    ?? null,
    'fournisseur' => $ai['fournisseur'] ?? null,
    'montant'   => isset($ai['montant_ttc']) ? (float)$ai['montant_ttc'] : null,
    'date_doc'  => $dateDoc,
    'action'    => $ai['action_proposee'] ?? null,
    'conf'      => isset($ai['confiance_globale']) ? (float)$ai['confiance_globale'] : null,
    'ai_raw'    => json_encode($ai, JSON_UNESCAPED_UNICODE),
    'sha'       => $up['sha256'],
    'file_id'   => $up['file_id'],
    'folder_id' => $up['folder_id'],
    'size'      => $up['size'],
    'mime'      => $up['mime'],
    'tiers'     => $ai['tiers_principal'] ?? null,
    'nom_ori'   => $origName,
    'ext'       => 'pdf',
    'date_doc2' => $dateDoc,
]);
$insertedId = (int)$pdo->lastInsertId();
echo "   ✓ Ligne insérée : ged_analyses.id = {$insertedId}" . PHP_EOL;
echo "   ✓ Status : to_validate (en attente validation humaine)" . PHP_EOL . PHP_EOL;

// ── ÉTAPE 5 : Nom canonique qui sera utilisé au moment du Valider ──
require_once $root . '/public_html/modules/ged/ged_naming.php';
$canonName = gedBuildFilename([
    'type'        => $ai['type_document'] ?? 'DOC',
    'date'        => $dateDoc ?? date('Y-m-d'),
    'ref_societe' => 'RE',
    'ref_agence'  => 'AGLYON',
    'objet_type'  => 'IMB',
    'objet_id'    => 1,
    'tiers'       => $ai['tiers_principal'] ?? $ai['fournisseur'] ?? null,
    'description' => $ai['description_courte'] ?? null,
    'version'     => 1,
    'ext'         => 'pdf',
]);
echo "🏷️  ÉTAPE 5 — Nom canonique (généré quand l'utilisateur clique Valider)" . PHP_EOL;
echo "   ➜ {$canonName}" . PHP_EOL . PHP_EOL;

echo "═══════════════════════════════════════════════════════════════════════" . PHP_EOL;
echo "✅ END-TO-END OK — workflow complet validé" . PHP_EOL;
echo PHP_EOL;
echo "Récap des emplacements :" . PHP_EOL;
echo "   • Quarantaine locale (étape 2) : {$absStored}" . PHP_EOL;
echo "   • BDD analyse (étape 4)        : ged_analyses.id = {$insertedId}" . PHP_EOL;
echo "   • Nom Drive futur (étape 5)    : MaBoxImmo/GED/COMPTA/2026/{$canonName}" . PHP_EOL;
echo PHP_EOL;
echo "Le doc est en file. Quand l'utilisateur ouvrira /modules/ged/ged_inbox.php il verra" . PHP_EOL;
echo "ce doc, vérifiera les champs IA, cliquera Valider → renomme + upload Drive." . PHP_EOL . PHP_EOL;

// Cleanup
echo "🧹 Cleanup test (suppression de la ligne BDD et du fichier quarantaine)" . PHP_EOL;
$pdo->prepare("DELETE FROM ged_analyses WHERE id = ?")->execute([$insertedId]);
$local->delete($up['file_id']);
echo "   ✓ Nettoyé." . PHP_EOL;
