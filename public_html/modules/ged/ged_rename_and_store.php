<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Renommage effectif + stockage.
 * Fichier : modules/ged/ged_rename_and_store.php
 *
 * Corrige LE bug majeur de l'ancienne version :
 *   l'ancienne stockait `suggested_filename` en BDD mais ne renommait JAMAIS le
 *   fichier physique. Cette fonction effectue VRAIMENT la copie + persiste les
 *   colonnes storage_*, nom_renomme, sha256.
 *
 * Pipeline :
 *   1. Calcule le nom canonique via gedBuildFilename()
 *   2. Détermine le folder cible (00_A_CLASSER_IA si pas de validation, sinon
 *      02_SYNDIC / 03_GESTION_LOCATIVE / etc. selon module)
 *   3. ensureFolder + upload via le driver actif (local/google_drive)
 *   4. UPDATE ged_analyses : storage_*, nom_renomme, sha256, confidence_score
 */

require_once __DIR__ . '/ged_storage.php';
require_once __DIR__ . '/ged_naming.php';

/**
 * Mapping module métier → dossier Drive racine (cf. prompt maître §3).
 * Dossier 00_A_CLASSER_IA = quarantaine pré-validation.
 */
const GED_MODULE_TO_FOLDER = [
    'SYNDIC'       => '02_SYNDIC',
    'BAILLEUR'     => '03_GESTION_LOCATIVE',
    'AGENCE'       => '04_REGISTRE_MANDATS',
    'COMPTA'       => '05_COMPTABILITE',
    'FOURNISSEURS' => '05_COMPTABILITE',
    'RH'           => '07_RH',
    'ADMIN'        => '01_REFERENTIEL',
];

/**
 * Renomme + stocke un document selon une analyse extraite.
 *
 * @param string $localPath        Chemin du fichier local source.
 * @param array  $extraction       Résultat de gedExtractDocument()['data'].
 * @param array  $context          [
 *   'analysis_id'  => int,        // ID ged_analyses à updater
 *   'ref_societe'  => string,     // ex 'RE'
 *   'ref_agence'   => string,     // ex 'AGLYON'
 *   'objet_type'   => string,     // IMB|BIEN|MDT|CTX|EMP|FOUR
 *   'objet_id'     => int,
 *   'is_validated' => bool,       // false → goes to 00_A_CLASSER_IA
 * ]
 * @return array{
 *   nom_renomme:string,
 *   storage_driver:string,
 *   storage_file_id:string,
 *   storage_folder_id:?string,
 *   sha256:string,
 *   size:int,
 *   mime:string
 * }
 * @throws RuntimeException
 */
function gedRenameAndStore(string $localPath, array $extraction, array $context): array
{
    if (!is_file($localPath)) {
        throw new InvalidArgumentException("Fichier source introuvable : {$localPath}");
    }
    foreach (['analysis_id', 'ref_societe', 'ref_agence', 'objet_type', 'objet_id'] as $req) {
        if (!isset($context[$req])) {
            throw new InvalidArgumentException("gedRenameAndStore : context.{$req} manquant.");
        }
    }
    $isValidated = (bool)($context['is_validated'] ?? false);

    // ── 1. Nom canonique ────────────────────────────────────────────────────
    $type = !empty($extraction['type_document'])
        ? (string)$extraction['type_document']
        : 'DOC';
    $date = !empty($extraction['date_document'])
        ? (string)$extraction['date_document']
        : date('Y-m-d');

    $tiers = $extraction['tiers_principal']
          ?? $extraction['fournisseur']
          ?? $extraction['emetteur_nom']
          ?? null;
    $description = $extraction['description_courte'] ?? null;

    $ext = strtolower((string)pathinfo($localPath, PATHINFO_EXTENSION)) ?: 'bin';

    $canonName = gedBuildFilename([
        'type'        => $type,
        'date'        => $date,
        'ref_societe' => (string)$context['ref_societe'],
        'ref_agence'  => (string)$context['ref_agence'],
        'objet_type'  => (string)$context['objet_type'],
        'objet_id'    => (int)$context['objet_id'],
        'tiers'       => is_string($tiers) ? $tiers : null,
        'description' => is_string($description) ? $description : null,
        'version'     => 1,
        'ext'         => $ext,
    ]);

    // ── 2. Dossier cible ────────────────────────────────────────────────────
    $module = !empty($extraction['module']) ? strtoupper((string)$extraction['module']) : 'ADMIN';
    $rootFolder = $isValidated
        ? (GED_MODULE_TO_FOLDER[$module] ?? '01_REFERENTIEL')
        : '00_A_CLASSER_IA';

    // Sous-dossier par année (cf. arborescence prompt §3 + bonne pratique)
    $year = substr($date, 0, 4);
    if (!preg_match('/^\d{4}$/', $year)) $year = date('Y');

    // ── 3. Upload via driver ────────────────────────────────────────────────
    $driver = ged_storage_default();
    $folderId = $driver->ensureFolder($rootFolder);
    $folderId = $driver->ensureFolder($year, $folderId);

    $up = $driver->upload($localPath, $canonName, $folderId);

    // ── 4. Persistance BDD ──────────────────────────────────────────────────
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        // Lazy-load db() si bootstrap pas encore exécuté
        if (function_exists('db')) {
            $pdo = db();
            $GLOBALS['pdo'] = $pdo;
        } else {
            throw new RuntimeException('PDO non disponible — appelle bootstrap.php ou db() d\'abord.');
        }
    }

    $stmt = $pdo->prepare("
        UPDATE ged_analyses SET
            sha256            = :sha,
            storage_driver    = :driver,
            storage_file_id   = :file_id,
            storage_folder_id = :folder_id,
            storage_size      = :size,
            storage_mime      = :mime,
            objet_type        = :objet_type,
            objet_id          = :objet_id,
            ref_societe       = :ref_soc,
            ref_agence        = :ref_ag,
            tiers_nom         = :tiers,
            nom_original      = :nom_ori,
            nom_renomme       = :nom_canon,
            extension         = :ext,
            date_document     = :date_doc,
            suggested_module  = :module,
            suggested_level_2 = :niv2,
            suggested_level_3 = :niv3,
            suggested_filename = :nom_canon,
            confidence_score  = :conf,
            updated_at        = NOW()
        WHERE id = :aid
    ");
    $stmt->execute([
        'sha'        => $up['sha256'],
        'driver'     => $driver->getName(),
        'file_id'    => $up['file_id'],
        'folder_id'  => $up['folder_id'],
        'size'       => $up['size'],
        'mime'       => $up['mime'],
        'objet_type' => (string)$context['objet_type'],
        'objet_id'   => (int)$context['objet_id'],
        'ref_soc'    => (string)$context['ref_societe'],
        'ref_ag'     => (string)$context['ref_agence'],
        'tiers'      => is_string($tiers) ? mb_substr($tiers, 0, 255) : null,
        'nom_ori'    => mb_substr(basename($localPath), 0, 255),
        'nom_canon'  => $canonName,
        'ext'        => $ext,
        'date_doc'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
        'module'     => $module,
        'niv2'       => isset($extraction['niveau_2']) ? mb_substr((string)$extraction['niveau_2'], 0, 100) : null,
        'niv3'       => isset($extraction['niveau_3']) ? mb_substr((string)$extraction['niveau_3'], 0, 100) : null,
        'conf'       => isset($extraction['confiance_globale']) ? (float)$extraction['confiance_globale'] : null,
        'aid'        => (int)$context['analysis_id'],
    ]);

    return [
        'nom_renomme'       => $canonName,
        'storage_driver'    => $driver->getName(),
        'storage_file_id'   => $up['file_id'],
        'storage_folder_id' => $up['folder_id'],
        'sha256'            => $up['sha256'],
        'size'              => $up['size'],
        'mime'              => $up['mime'],
    ];
}

/**
 * Variante : promeut une analyse de la quarantaine LOCALE vers le DRIVE final.
 *
 * Workflow : à la validation côté UI, le fichier est en `storage/ged/00_A_CLASSER_IA/...`
 * (driver local, quarantaine post-upload). Cette fonction :
 *   1. Récupère le fichier local via le driver source (généralement local)
 *   2. Appelle gedRenameAndStore() qui upload vers le driver default (Drive en prod)
 *   3. Supprime le fichier local de quarantaine
 *
 * @param int   $analysisId  ID de la ligne ged_analyses (status='to_validate')
 * @param array $context     ['ref_societe', 'ref_agence', 'objet_type', 'objet_id']
 *                           — peut overrider les valeurs déjà en BDD
 * @return array Idem gedRenameAndStore.
 */
function gedRenameAndStoreFromAnalysis(int $analysisId, array $context = []): array
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        if (function_exists('db')) { $pdo = db(); $GLOBALS['pdo'] = $pdo; }
        else throw new RuntimeException('PDO non disponible.');
    }

    $row = $pdo->prepare("SELECT * FROM ged_analyses WHERE id = ?");
    $row->execute([$analysisId]);
    $a = $row->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new RuntimeException("Analyse #{$analysisId} introuvable.");

    if (empty($a['storage_file_id'])) {
        throw new RuntimeException("Analyse #{$analysisId} : aucun fichier physique attaché (storage_file_id vide). Re-uploade le doc.");
    }

    // 1. Driver source = celui qui était stocké en BDD au moment de l'upload (typiquement 'local')
    $srcDriverName = $a['storage_driver'] ?: 'local';
    if ($srcDriverName === 'local') {
        require_once __DIR__ . '/ged_storage_local.php';
        $base = defined('GED_STORAGE_LOCAL_BASE') ? (string)GED_STORAGE_LOCAL_BASE
              : dirname(__DIR__, 3) . '/storage/ged';
        $srcDriver = new GedStorageLocal($base);
    } else {
        // Si déjà sur Drive : pas besoin de promouvoir, on stoppe.
        throw new RuntimeException("Analyse #{$analysisId} déjà stockée sur '{$srcDriverName}', promotion non nécessaire.");
    }

    // 2. Télécharge en temp local
    $tmp = tempnam(sys_get_temp_dir(), 'gedval_') . '.' . ($a['extension'] ?: 'bin');
    $srcDriver->download((string)$a['storage_file_id'], $tmp);

    try {
        // 3. Reconstruit le tableau extraction depuis les colonnes BDD
        $extraction = [
            'type_document'      => $a['suggested_filename'] ? null : null, // sera dérivé
            'module'             => $a['suggested_module'],
            'niveau_2'           => $a['suggested_level_2'],
            'niveau_3'           => $a['suggested_level_3'],
            'date_document'      => $a['date_document'],
            'tiers_principal'    => $a['tiers_nom'],
            'fournisseur'        => $a['detected_fournisseur'],
            'description_courte' => null,
            'confiance_globale'  => $a['confidence_score'] !== null ? (float)$a['confidence_score'] : null,
        ];
        // Récupère type_document depuis ai_raw_response si dispo
        if (!empty($a['ai_raw_response'])) {
            $raw = json_decode((string)$a['ai_raw_response'], true);
            if (is_array($raw)) {
                $extraction['type_document']      = $raw['type_document']      ?? $extraction['type_document'];
                $extraction['description_courte'] = $raw['description_courte'] ?? $extraction['description_courte'];
                $extraction['tiers_principal']    = $raw['tiers_principal']    ?? $extraction['tiers_principal'];
            }
        }

        $ctx = array_merge([
            'analysis_id'  => $analysisId,
            'ref_societe'  => $a['ref_societe'] ?: 'RE',
            'ref_agence'   => $a['ref_agence']  ?: 'AGLYON',
            'objet_type'   => $a['objet_type']  ?: 'IMB',
            'objet_id'     => (int)($a['objet_id'] ?: 1),
            'is_validated' => true,
        ], $context);

        $result = gedRenameAndStore($tmp, $extraction, $ctx);

        // 4. Cleanup : supprime la version locale de quarantaine (le fichier vit maintenant sur Drive)
        try { $srcDriver->delete((string)$a['storage_file_id']); } catch (Throwable) {}

        return $result;

    } finally {
        @unlink($tmp);
    }
}
