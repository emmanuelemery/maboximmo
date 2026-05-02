<?php
declare(strict_types=1);

/**
 * Ma GED Box V1.1 — Helpers d'import par lots (préclassement N1→N6)
 * ==================================================================
 *
 * Fonctions utilisées par super_admin_ged_import.php + api/ged_import_admin.php.
 *
 * AJOUT uniquement — ne touche pas aux tables/pages existantes.
 *
 * Tables :
 *   - ged_import_batches : 1 batch = 1 upload utilisateur
 *   - ged_import_items   : 1 ligne par fichier importé, sélection N1→N6 + canonical
 *   - ged_level_codes    : référentiel N1-N5 (catalogue cascade)
 *
 * Convention nom canonical (cf. brief v1.1) :
 *   {N1_CODE}_{SOC}_{AGENCE}_{REF_ENTITE}_{NOM15}_{N2}_{N3}_{N4}_{N5}_{N6}_{TITRE}_{YYMM}
 */

require_once __DIR__ . '/ged_functions.php';

// ─── Utilitaires ───────────────────────────────────────────────────────

if (!function_exists('ged_import_pdo')) {
    function ged_import_pdo(): PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('GED import : PDO indisponible');
        }
        return $pdo;
    }
}

/**
 * Slugifie + nettoie pour un segment de nom canonical (MAJUSCULES, _ séparateur,
 * sans accent ni caractère spécial).
 */
function ged_import_normalize_segment(string $s, int $maxLen = 0): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tr !== false) $s = $tr;
    }
    $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s) ?? $s;
    $s = strtoupper(trim($s, '_'));
    $s = preg_replace('/_+/', '_', $s) ?? $s;
    if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
        $s = mb_substr($s, 0, $maxLen);
        $s = rtrim($s, '_');
    }
    return $s;
}

/**
 * Génère le nom canonique d'un fichier d'import selon le format v1.1.
 *
 * @param array $item Ligne ged_import_items (avec selected_n1…selected_n6, title_user, etc.)
 * @param array $opts ['societe_code'=>'RE', 'agence_code'=>'LY', 'ref_entite'=>'2004', 'nom_entite'=>'PARC GARIGL', 'date'=>'2026-05-26']
 * @return string Nom canonique (sans extension)
 */
function ged_import_compute_canonical(array $item, array $opts = []): string
{
    $parts = [];

    // N1_CODE (ex: 04_SYNDIC → SY ou complet selon convention)
    $n1 = (string)($item['selected_n1'] ?? '');
    if ($n1 !== '') $parts[] = ged_import_normalize_segment(preg_replace('/^\d+_/', '', $n1));

    // Société / agence
    if (!empty($opts['societe_code'])) $parts[] = ged_import_normalize_segment($opts['societe_code']);
    if (!empty($opts['agence_code']))  $parts[] = ged_import_normalize_segment($opts['agence_code']);

    // Référence entité (numéro immeuble, bien, etc.)
    if (!empty($opts['ref_entite']))   $parts[] = ged_import_normalize_segment((string)$opts['ref_entite']);

    // Nom entité limité à 15 caractères
    if (!empty($opts['nom_entite']))   $parts[] = ged_import_normalize_segment((string)$opts['nom_entite'], 15);

    // N2 → N6 (skip si vide)
    foreach (['selected_n2', 'selected_n3', 'selected_n4', 'selected_n5', 'selected_n6'] as $k) {
        $v = (string)($item[$k] ?? '');
        if ($v !== '') $parts[] = ged_import_normalize_segment($v);
    }

    // Titre humain (utilisateur)
    $title = (string)($item['title_user'] ?? '');
    if ($title !== '') $parts[] = ged_import_normalize_segment($title);

    // Date YYMM
    $date = (string)($opts['date'] ?? '');
    if ($date !== '') {
        $ts = strtotime($date);
        if ($ts) $parts[] = date('ymd', $ts);
    }

    return implode('_', array_filter($parts, static fn($s) => $s !== ''));
}

/**
 * Calcule le score de complétude d'un item (0-100).
 *
 * Pondération : N1=20, N2=20, N3=15, N4=15, N5=10, title=10, ref_entite=10
 */
function ged_import_compute_score(array $item, array $opts = []): int
{
    $score = 0;
    if (!empty($item['selected_n1'])) $score += 20;
    if (!empty($item['selected_n2'])) $score += 20;
    if (!empty($item['selected_n3'])) $score += 15;
    if (!empty($item['selected_n4'])) $score += 15;
    if (!empty($item['selected_n5'])) $score += 10;
    if (!empty($item['title_user'])) $score += 10;
    if (!empty($opts['ref_entite']) || !empty($opts['nom_entite'])) $score += 10;
    return min(100, $score);
}

/**
 * Calcule la destination GED MBI proposée (path slugs/separated).
 */
function ged_import_compute_destination(array $item): string
{
    $parts = [];
    foreach (['selected_n1', 'selected_n2', 'selected_n3', 'selected_n4', 'selected_n5', 'selected_n6'] as $k) {
        $v = (string)($item[$k] ?? '');
        if ($v !== '') $parts[] = strtolower(ged_import_normalize_segment($v));
    }
    return implode('/', $parts);
}

// ─── CRUD batches ──────────────────────────────────────────────────────

function ged_import_create_batch(string $batchName, string $sourceType = 'upload_files'): int
{
    $pdo = ged_import_pdo();
    $uuid = ged_generate_uuid();
    $st = $pdo->prepare("
        INSERT INTO ged_import_batches
            (uuid, tenant_id, batch_name, source_type, uploaded_by, status)
        VALUES (?, ?, ?, ?, ?, 'open')
    ");
    $st->execute([$uuid, ged_current_tenant_id(), $batchName, $sourceType, ged_current_user_id()]);
    $id = (int)$pdo->lastInsertId();
    ged_audit('import_batch_create', 'import_batch', $id, null, ['name' => $batchName, 'source' => $sourceType]);
    return $id;
}

function ged_import_get_batch(int $batchId): ?array
{
    $st = ged_import_pdo()->prepare("SELECT * FROM ged_import_batches WHERE id = ?");
    $st->execute([$batchId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ged_import_update_batch_counters(int $batchId): void
{
    $pdo = ged_import_pdo();
    $pdo->prepare("
        UPDATE ged_import_batches b
        SET nb_items     = (SELECT COUNT(*) FROM ged_import_items WHERE batch_id = b.id),
            nb_validated = (SELECT COUNT(*) FROM ged_import_items WHERE batch_id = b.id AND status = 'validated'),
            nb_ignored   = (SELECT COUNT(*) FROM ged_import_items WHERE batch_id = b.id AND status = 'ignored')
        WHERE b.id = ?
    ")->execute([$batchId]);
}

// ─── Ajout d'un item (depuis un fichier uploadé) ───────────────────────

/**
 * Ajoute un item au batch à partir d'un fichier uploadé.
 *
 * @param int    $batchId
 * @param array  $file    Entrée $_FILES['xxx'] (name, tmp_name, type, size, error)
 * @param string $relPath Chemin relatif d'origine (si upload dossier — ex: "Téléchargements/Factures/2024/")
 * @param string $storageDir Dossier où copier le fichier (ex: uploads/ged_import/<batch_uuid>/)
 * @return int   ID du nouvel item
 */
function ged_import_add_item(int $batchId, array $file, string $relPath, string $storageDir): int
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Fichier upload invalide');
    }

    @mkdir($storageDir, 0755, true);
    $name = (string)($file['name'] ?? 'unknown.bin');
    $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $hash = hash_file('sha256', $file['tmp_name']);
    $storedName = $hash . ($ext !== '' ? ('.' . $ext) : '');
    $absPath = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;

    if (!file_exists($absPath)) {
        if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
            // Fallback copy
            if (!@copy($file['tmp_name'], $absPath)) {
                throw new RuntimeException('Impossible de stocker le fichier sur disque');
            }
        }
    }

    $pdo = ged_import_pdo();
    $st = $pdo->prepare("
        INSERT INTO ged_import_items
            (batch_id, tenant_id, old_folder_path, old_filename, file_extension, mime_type,
             size_bytes, hash_sha256, storage_path, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'imported')
    ");
    $st->execute([
        $batchId,
        ged_current_tenant_id(),
        $relPath,
        $name,
        $ext,
        (string)($file['type'] ?? null),
        (int)($file['size'] ?? 0),
        $hash,
        $absPath,
    ]);
    $itemId = (int)$pdo->lastInsertId();
    ged_import_update_batch_counters($batchId);
    return $itemId;
}

// ─── Mise à jour d'un item (sélection cascade) ─────────────────────────

/**
 * Met à jour les sélections N1→N6 et recalcule canonical/destination/score.
 *
 * @param int   $itemId
 * @param array $fields  Sous-set de : selected_n1…selected_n6, title_user
 * @param array $opts    Options pour le canonical (societe_code, agence_code, ref_entite, nom_entite, date)
 * @return array         L'item à jour
 */
function ged_import_update_item(int $itemId, array $fields, array $opts = []): array
{
    $allowed = ['selected_n1', 'selected_n2', 'selected_n3', 'selected_n4', 'selected_n5', 'selected_n6', 'title_user'];
    $sets = [];
    $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $fields)) continue;
        $sets[] = "`{$k}` = ?";
        $val = $fields[$k];
        $params[] = is_string($val) ? trim($val) : $val;
    }
    if ($sets) {
        $params[] = $itemId;
        $sql = "UPDATE ged_import_items SET " . implode(', ', $sets) . " WHERE id = ?";
        ged_import_pdo()->prepare($sql)->execute($params);
    }

    // Recharge l'item pour calculer canonical
    $st = ged_import_pdo()->prepare("SELECT * FROM ged_import_items WHERE id = ?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException("Item {$itemId} introuvable");

    $canonical = ged_import_compute_canonical($item, $opts);
    $destination = ged_import_compute_destination($item);
    $score = ged_import_compute_score($item, $opts);
    $titleDisplay = trim((string)($item['title_user'] ?? '')) ?: $item['old_filename'];

    $newStatus = $score >= 80 ? 'proposed' : 'imported';
    if (!empty($item['status']) && in_array($item['status'], ['validated', 'to_review', 'ignored'], true)) {
        $newStatus = $item['status']; // ne pas régresser
    }

    ged_import_pdo()->prepare("
        UPDATE ged_import_items
        SET name_canonical = ?, name_display = ?, proposed_destination = ?,
            confidence_score = ?, status = ?
        WHERE id = ?
    ")->execute([$canonical, $titleDisplay, $destination, $score, $newStatus, $itemId]);

    $st->execute([$itemId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// ─── Validation finale (création ged_documents) ───────────────────────

/**
 * Valide un item : crée le ged_documents associé (sans dupliquer le fichier
 * physique — réutilise storage_path), conserve old_filename + old_folder_path
 * dans metadata JSON, journalise.
 *
 * @return int ID du ged_documents créé
 */
function ged_import_validate_item(int $itemId): int
{
    $pdo = ged_import_pdo();
    $st = $pdo->prepare("SELECT * FROM ged_import_items WHERE id = ?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException("Item {$itemId} introuvable");
    if (($item['status'] ?? '') === 'validated' && (int)($item['created_document_id'] ?? 0) > 0) {
        return (int)$item['created_document_id'];
    }

    $uuid = ged_generate_uuid();
    $name = (string)($item['name_display'] ?? $item['old_filename']);
    $canonical = (string)($item['name_canonical'] ?? '');
    if ($canonical === '') $canonical = ged_import_normalize_segment(pathinfo($item['old_filename'], PATHINFO_FILENAME));

    $ext = (string)($item['file_extension'] ?? '');
    $nameFile = $canonical . ($ext !== '' ? ('.' . $ext) : '');

    $metadata = [
        'old_filename'    => $item['old_filename'],
        'old_folder_path' => $item['old_folder_path'],
        'import_batch_id' => $item['batch_id'],
        'import_item_id'  => $item['id'],
        'selected_levels' => [
            'n1' => $item['selected_n1'],
            'n2' => $item['selected_n2'],
            'n3' => $item['selected_n3'],
            'n4' => $item['selected_n4'],
            'n5' => $item['selected_n5'],
            'n6' => $item['selected_n6'],
        ],
        'proposed_destination' => $item['proposed_destination'],
    ];

    $pdo->prepare("
        INSERT INTO ged_documents
            (uuid, tenant_id, name_display, name_canonical, name_file,
             document_type, source_module, storage_provider, mime_type, size_bytes, hash_sha256,
             metadata, security_level, status, version,
             confidence_score, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'local', ?, ?, ?, ?, 'interne', 'active', 1, ?, ?, ?)
    ")->execute([
        $uuid, $item['tenant_id'],
        $name, $canonical, $nameFile,
        $item['selected_n6'] ?: $item['selected_n4'] ?: $item['selected_n2'],
        $item['selected_n1'],
        $item['mime_type'], $item['size_bytes'], $item['hash_sha256'],
        json_encode($metadata, JSON_UNESCAPED_UNICODE),
        (int)($item['confidence_score'] ?? 0),
        ged_current_user_id(), ged_current_user_id(),
    ]);
    $docId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        UPDATE ged_import_items
        SET status = 'validated', created_document_id = ?, final_destination = ?
        WHERE id = ?
    ")->execute([$docId, $item['proposed_destination'], $itemId]);

    ged_import_update_batch_counters((int)$item['batch_id']);
    ged_audit('import_validate', 'document', $docId, null, [
        'item_id' => $itemId,
        'canonical' => $canonical,
        'destination' => $item['proposed_destination'],
    ]);

    return $docId;
}

/**
 * Validation rapide ("mode quick") :
 *   - Conditions minimales : validated_n1 + validated_n2 + validated_entity = 1
 *     (vérifié dans l'API avant appel)
 *   - Crée le ged_documents avec :
 *       • mode = 'quick'
 *       • name_file = uuid.ext (pas de renommage physique)
 *       • name_canonical généré en arrière-plan
 *       • status_doc = 'active'
 *   - Marque l'import_item : status = 'classified_quick', mode = 'quick'
 *
 * @return int document_id créé
 */
function ged_import_validate_item_quick(int $itemId): int
{
    $pdo = ged_import_pdo();
    $st = $pdo->prepare("SELECT * FROM ged_import_items WHERE id = ?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException("Item {$itemId} introuvable");

    // Garde-fou : N1 + N2 obligatoires (entité optionnelle si pas applicable)
    if (empty($item['selected_n1']) || empty($item['selected_n2'])) {
        throw new RuntimeException('Validation rapide : N1 + N2 requis');
    }

    $uuid = ged_generate_uuid();
    $ext  = (string)($item['file_extension'] ?? '');
    $nameFile = $uuid . ($ext !== '' ? ('.' . $ext) : '');

    // Génère name_canonical en arrière-plan (pour traçabilité, mais le fichier
    // physique reste en uuid.ext sur le storage).
    $canonical = (string)($item['name_canonical'] ?? '');
    if ($canonical === '') {
        $canonical = ged_import_normalize_segment(pathinfo($item['old_filename'], PATHINFO_FILENAME));
    }

    // Titre humain : utilise title_user si rempli, sinon old_filename
    $nameDisplay = trim((string)($item['title_user'] ?? '')) ?: (string)($item['old_filename'] ?? 'Document');

    $metadata = [
        'old_filename'    => $item['old_filename'],
        'old_folder_path' => $item['old_folder_path'],
        'import_batch_id' => $item['batch_id'],
        'import_item_id'  => $item['id'],
        'import_mode'     => 'quick',
        'selected_levels' => [
            'n1' => $item['selected_n1'],
            'n2' => $item['selected_n2'],
            'n3' => $item['selected_n3'],
            'n4' => $item['selected_n4'],
            'n5' => $item['selected_n5'],
            'n6' => $item['selected_n6'],
        ],
        'proposed_destination' => $item['proposed_destination'],
    ];

    $pdo->prepare("
        INSERT INTO ged_documents
            (uuid, tenant_id, name_display, name_canonical, name_file,
             document_type, source_module, storage_provider, mime_type, size_bytes, hash_sha256,
             metadata, security_level, status, version,
             confidence_score, mode, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'local', ?, ?, ?, ?, 'interne', 'active', 1, ?, 'quick', ?, ?)
    ")->execute([
        $uuid, $item['tenant_id'],
        $nameDisplay, $canonical, $nameFile,
        $item['selected_n6'] ?: $item['selected_n4'] ?: $item['selected_n2'],
        $item['selected_n1'],
        $item['mime_type'], $item['size_bytes'], $item['hash_sha256'],
        json_encode($metadata, JSON_UNESCAPED_UNICODE),
        (int)($item['confidence_score'] ?? 0),
        ged_current_user_id(), ged_current_user_id(),
    ]);
    $docId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        UPDATE ged_import_items
        SET status = 'classified_quick', mode = 'quick',
            created_document_id = ?, final_destination = ?
        WHERE id = ?
    ")->execute([$docId, $item['proposed_destination'], $itemId]);

    ged_import_update_batch_counters((int)$item['batch_id']);
    ged_audit('import_validate_quick', 'document', $docId, null, [
        'item_id' => $itemId,
        'name_file' => $nameFile,
        'canonical' => $canonical,
    ]);

    return $docId;
}

// ─── Lookups niveaux (cascade UI) ──────────────────────────────────────

/**
 * Renvoie les options disponibles pour un niveau donné, filtrées par les parents sélectionnés.
 *
 * @param int $levelNumber 1-6
 * @param array $parents ['n1'=>'04_SYNDIC', 'n2'=>'IMMEUBLES', 'n3'=>'IMMEUBLE', 'n4'=>'AG']
 * @return array Liste de [code, label, position]
 */
function ged_import_get_levels_for(int $levelNumber, array $parents = []): array
{
    $where = "level_number = ? AND is_active = 1";
    $params = [$levelNumber];

    foreach (['n1', 'n2', 'n3', 'n4'] as $i => $key) {
        $col = "parent_{$key}";
        if ($levelNumber > $i + 1) {
            $val = $parents[$key] ?? null;
            if ($val) {
                $where .= " AND `{$col}` = ?";
                $params[] = $val;
            } else {
                // Pas de parent fourni pour ce niveau → on prend les codes sans parent à ce rang
                $where .= " AND (`{$col}` IS NULL OR `{$col}` = '')";
            }
        }
    }

    // V2.5 : retourne aussi is_entity_placeholder si la colonne existe (try/catch fallback)
    try {
        $sql = "SELECT code, label, position, is_entity_placeholder
                FROM ged_level_codes WHERE {$where} ORDER BY position ASC, label ASC";
        $st = ged_import_pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        // Migration v2_21 pas appliquée → fallback sans le flag
        $sql = "SELECT code, label, position FROM ged_level_codes WHERE {$where} ORDER BY position ASC, label ASC";
        $st = ged_import_pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

/**
 * Liste les items d'un batch, avec filtres optionnels.
 */
function ged_import_list_items(int $batchId, array $filters = []): array
{
    $where = ["batch_id = ?"];
    $params = [$batchId];

    if (!empty($filters['status'])) {
        $where[] = "status = ?";
        $params[] = (string)$filters['status'];
    }
    if (!empty($filters['n1'])) {
        $where[] = "selected_n1 = ?";
        $params[] = (string)$filters['n1'];
    }
    if (!empty($filters['min_score']) && is_numeric($filters['min_score'])) {
        $where[] = "confidence_score >= ?";
        $params[] = (int)$filters['min_score'];
    }
    if (!empty($filters['ext'])) {
        $where[] = "file_extension = ?";
        $params[] = (string)$filters['ext'];
    }
    if (!empty($filters['search'])) {
        $where[] = "(old_filename LIKE ? OR name_display LIKE ? OR title_user LIKE ?)";
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$filters['search']) . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // Filtre Mode : 'quick' | 'normalized' | 'to_review' (ce dernier = status spécial)
    if (!empty($filters['mode'])) {
        $modeFilter = (string)$filters['mode'];
        if ($modeFilter === 'to_review') {
            $where[] = "(status = 'to_review' OR needs_review_reason IS NOT NULL)";
        } elseif ($modeFilter === 'quick') {
            $where[] = "(mode = 'quick' OR status = 'classified_quick')";
        } elseif ($modeFilter === 'normalized') {
            $where[] = "(mode = 'normalized' AND status <> 'to_review')";
        }
    }

    $sql = "SELECT * FROM ged_import_items WHERE " . implode(' AND ', $where) .
           " ORDER BY status ASC, confidence_score DESC, id ASC LIMIT 500";
    $st = ged_import_pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
