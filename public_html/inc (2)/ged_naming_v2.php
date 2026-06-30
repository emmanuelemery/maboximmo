<?php
declare(strict_types=1);

/**
 * Ma GED Box V2 — Moteur de nommage intelligent
 * ==============================================
 *
 * Helpers V2 :
 *   - build_canonical_name() : génère name_canonical (machine, stockage)
 *   - build_export_name()    : génère name_export (humain client) à la volée
 *                              → pas de colonne BDD, recalcul dynamique
 *   - parse_canonical_name() : reverse engineering (extraction segments)
 *   - compute_scores_granular() : 5 scores (type, entity, date, structure, dest)
 *   - find_duplicates_smart() : hash + size + mime + nom + entité + date ±2j
 *   - suggest_n6() : autocomplete depuis ged_synonyms + historique feedback
 *   - record_feedback() : stocke correction user pour apprentissage
 *
 * SCORE_VERSION_CURRENT : incrémenter à chaque modif algo de scoring → permet
 * recalcul ciblé des docs avec score_version < courant.
 */

require_once __DIR__ . '/ged_functions.php';

const GED_SCORE_VERSION_CURRENT = 1;

if (!function_exists('ged_naming_v2_pdo')) {
    function ged_naming_v2_pdo(): PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('ged_naming_v2 : PDO indisponible');
        }
        return $pdo;
    }
}

// ─── Normalisation ─────────────────────────────────────────────────────

/** Slug ASCII uppercase pour codes machine. */
function ged_v2_norm_code(string $s, int $maxLen = 0): string
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

/** Kebab-case lowercase pour TITLE. */
function ged_v2_norm_title(string $s, int $maxLen = 30): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tr !== false) $s = $tr;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    $s = preg_replace('/-+/', '-', $s) ?? $s;
    $s = trim($s, '-');
    if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
        $s = mb_substr($s, 0, $maxLen);
        $s = rtrim($s, '-');
    }
    return $s;
}

// ─── Build canonical (machine) ─────────────────────────────────────────

/**
 * Génère le nom canonique selon les règles ged_naming_rules.
 *
 * @param array $payload  Sélections cascade + métadonnées :
 *   ['n1','n2','n3','n4','n5','n6','soc','age','ref','entite','title','date','ext']
 * @param string $module Module (N1_CODE) pour charger la règle dédiée
 * @return array ['name_canonical' => string, 'segments' => array, 'truncated' => bool]
 */
function ged_v2_build_canonical_name(array $payload, ?string $module = null): array
{
    $module = $module ?: ($payload['n1'] ?? 'DEFAULT');
    $rules = ged_v2_load_naming_rule($module);

    $maxLen        = (int)$rules['max_length'];
    $entityMaxLen  = (int)$rules['entity_max_length'];
    $titleMaxLen   = (int)$rules['title_max_length'];
    $n6MaxLen      = (int)$rules['n6_max_length'];

    // Construction segments (l'ordre suit le template)
    $segments = [];

    $n1 = (string)($payload['n1'] ?? '');
    if ($n1 !== '') {
        // Strip prefix numerique (04_SYNDIC → SYNDIC) puis abregé 2 chars (SY) si demandé
        $n1Clean = preg_replace('/^\d+_/', '', $n1);
        $segments['N1'] = ged_v2_norm_code($n1Clean);
    }
    if (!empty($payload['soc']))    $segments['SOC']    = ged_v2_norm_code((string)$payload['soc']);
    if (!empty($payload['age']))    $segments['AGE']    = ged_v2_norm_code((string)$payload['age']);
    if (!empty($payload['ref']))    $segments['REF']    = ged_v2_norm_code((string)$payload['ref']);
    if (!empty($payload['entite'])) $segments['ENTITE'] = ged_v2_norm_code((string)$payload['entite'], $entityMaxLen) ?: 'XXX';
    if (!empty($payload['n2']))     $segments['N2']     = ged_v2_norm_code((string)$payload['n2']);
    if (!empty($payload['n3']))     $segments['N3']     = ged_v2_norm_code((string)$payload['n3']);
    if (!empty($payload['n4']))     $segments['N4']     = ged_v2_norm_code((string)$payload['n4']);
    if (!empty($payload['n5']))     $segments['N5']     = ged_v2_norm_code((string)$payload['n5']);
    if (!empty($payload['n6']))     $segments['N6']     = ged_v2_norm_code((string)$payload['n6'], $n6MaxLen);

    // TITLE obligatoire avec fallback dernier niveau
    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        // Fallback : prendre le dernier niveau non vide
        for ($i = 6; $i >= 1; $i--) {
            $k = 'N' . $i;
            if (!empty($segments[$k])) {
                $title = $segments[$k];
                break;
            }
        }
        if ($title === '') $title = 'sans-titre';
    }
    $segments['TITLE'] = ged_v2_norm_code($title, $titleMaxLen) ?: 'SANS_TITRE';

    // Date YYMMDD
    if (!empty($payload['date'])) {
        $ts = strtotime((string)$payload['date']);
        if ($ts) $segments['YYMMDD'] = date('ymd', $ts);
    }

    // Suppression des doublons consécutifs (ex: N4=ARCHIVES, N5=ARCHIVES → un seul)
    $valuesOrdered = array_values($segments);
    $deduped = [];
    $prev = null;
    foreach ($valuesOrdered as $v) {
        if ($v !== $prev) $deduped[] = $v;
        $prev = $v;
    }

    // Joinder
    $sep = (string)$rules['separator'];
    $name = implode($sep, $deduped);

    // Tronquage si dépassement (ordre : TITLE, ENTITE, N6)
    $truncated = false;
    $ext = trim((string)($payload['ext'] ?? ''), '.');
    $extLen = $ext !== '' ? strlen('.' . $ext) : 0;
    $maxNameOnly = $maxLen - $extLen;

    if (strlen($name) > $maxNameOnly) {
        $truncated = true;
        $dropOrder = explode(',', $rules['priority_drop_order']);
        foreach ($dropOrder as $segName) {
            $segName = trim($segName);
            if (!isset($segments[$segName])) continue;
            // Réduire à 50% puis remonter le name
            $cur = $segments[$segName];
            $segments[$segName] = mb_substr($cur, 0, max(8, intdiv(mb_strlen($cur), 2)));
            // Reconstruit
            $valuesOrdered = array_values($segments);
            $deduped = [];
            $prev = null;
            foreach ($valuesOrdered as $v) {
                if ($v !== $prev) $deduped[] = $v;
                $prev = $v;
            }
            $name = implode($sep, $deduped);
            if (strlen($name) <= $maxNameOnly) break;
        }
        if (strlen($name) > $maxNameOnly) {
            $name = substr($name, 0, $maxNameOnly);
        }
    }

    if ($ext !== '') $name .= '.' . $ext;

    return [
        'name_canonical' => $name,
        'segments'       => $segments,
        'truncated'      => $truncated,
        'rule_module'    => $rules['module'],
    ];
}

/** Charge ged_naming_rules pour un module, fallback DEFAULT. */
function ged_v2_load_naming_rule(string $module): array
{
    $pdo = ged_naming_v2_pdo();
    try {
        $st = $pdo->prepare("SELECT * FROM ged_naming_rules WHERE module = ? AND is_active = 1 LIMIT 1");
        $st->execute([$module]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $st->execute(['DEFAULT']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable) {
        // Table pas créée — fallback hardcodé
    }
    return [
        'module' => 'DEFAULT',
        'template' => '{N1}_{SOC}_{AGE}_[{REF}_{ENTITE}_]{N2}[_{N3}[_{N4}[_{N5}]]]_[{N6}_]{TITLE}_{YYMMDD}.{ext}',
        'separator' => '_',
        'max_length' => 120,
        'entity_max_length' => 15,
        'title_max_length' => 30,
        'n6_max_length' => 25,
        'priority_drop_order' => 'TITLE,ENTITE,N6',
    ];
}

// ─── Build export (humain, dynamique — pas de colonne BDD) ─────────────

/**
 * Génère le nom export humain à partir d'un ged_documents (ou ged_import_items).
 * Format : "{TITRE} - {ENTITE} - {DATE_FR}.{ext}"
 *
 * Exemple : "PV AG signé - Parc des Garigliano - 15-03-2026.pdf"
 *
 * @param array $doc Ligne ged_documents ou ged_import_items
 * @return string
 */
function ged_v2_build_export_name(array $doc): string
{
    $title = trim((string)($doc['title_user']
        ?? $doc['name_display']
        ?? $doc['old_filename']
        ?? 'Document'));
    // Strip extension du title si présente
    $title = preg_replace('/\.(pdf|jpg|jpeg|png|docx?|xlsx?|eml|msg)$/i', '', $title);

    $entityLabel = (string)($doc['entity_label_resolved']
        ?? $doc['nom_entite']
        ?? '');

    $date = $doc['date_document'] ?? null;
    $dateFr = '';
    if ($date) {
        $ts = strtotime((string)$date);
        if ($ts) $dateFr = date('d-m-Y', $ts);
    }

    $parts = [trim($title)];
    if ($entityLabel !== '') $parts[] = $entityLabel;
    if ($dateFr !== '')      $parts[] = $dateFr;

    $ext = (string)($doc['file_extension'] ?? '');
    if ($ext === '' && !empty($doc['name_file'])) {
        $ext = (string)pathinfo($doc['name_file'], PATHINFO_EXTENSION);
    }

    $name = implode(' - ', array_filter($parts, static fn($s) => trim($s) !== ''));
    if ($ext !== '') $name .= '.' . $ext;
    return $name;
}

// ─── Parse canonical (extraction segments) ────────────────────────────

/**
 * Parse un nom canonique pour extraire les segments approximatifs.
 * Best effort — utile pour debug / migration ancien stock.
 */
function ged_v2_parse_canonical_name(string $name): array
{
    $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name);
    $parts = explode('_', $base);
    return [
        'parts'       => $parts,
        'guess_n1'    => $parts[0] ?? null,
        'guess_soc'   => $parts[1] ?? null,
        'guess_age'   => $parts[2] ?? null,
        'guess_date'  => preg_match('/^(\d{6})$/', end($parts), $m) ? $m[1] : null,
    ];
}

// ─── Scores granulaires V2 ─────────────────────────────────────────────

/**
 * Calcule les 5 scores granulaires + score_global.
 *
 * @param array $item   Ligne ged_import_items (ou structure équivalente)
 * @param array $opts   ['ref_entite','nom_entite','date','title']
 * @return array ['score_type','score_entity','score_date','score_structure','score_destination','score_global']
 */
function ged_v2_compute_scores_granular(array $item, array $opts = []): array
{
    // score_type : N1 + N2 (= type/famille du doc)
    $sType = 0;
    if (!empty($item['selected_n1'])) $sType += 50;
    if (!empty($item['selected_n2'])) $sType += 50;

    // score_entity : entité résolue (proprio, immeuble, bien...)
    $sEntity = 0;
    if (!empty($opts['ref_entite']) || !empty($item['entity_id_resolved'])) $sEntity += 50;
    if (!empty($opts['nom_entite']) || !empty($item['entity_label_resolved'])) $sEntity += 50;

    // score_date : date_document non nulle ET non estimée
    $sDate = 0;
    $hasDate = !empty($opts['date']) || !empty($item['date_document']);
    $isEstimated = !empty($item['date_estimated']);
    if ($hasDate) $sDate += $isEstimated ? 50 : 100;

    // score_structure : profondeur cascade N3+N4+N5
    $sStruct = 0;
    if (!empty($item['selected_n3'])) $sStruct += 33;
    if (!empty($item['selected_n4'])) $sStruct += 33;
    if (!empty($item['selected_n5'])) $sStruct += 34;

    // score_destination : proposed_destination cohérente
    $sDest = 0;
    $dest = (string)($item['proposed_destination'] ?? '');
    if ($dest !== '') {
        $segs = explode('/', $dest);
        $sDest = min(100, count(array_filter($segs)) * 20);
    }

    // score_global agrégé pondéré (somme normalisée)
    $sGlobal = (int)round(
        $sType * 0.25 +
        $sEntity * 0.25 +
        $sDate * 0.15 +
        $sStruct * 0.20 +
        $sDest * 0.15
    );

    return [
        'score_type'        => $sType,
        'score_entity'      => $sEntity,
        'score_date'        => $sDate,
        'score_structure'   => $sStruct,
        'score_destination' => $sDest,
        'score_global'      => $sGlobal,
        'score_version'     => GED_SCORE_VERSION_CURRENT,
    ];
}

/**
 * Calcule la liste des raisons "à revoir" à partir des scores et d'un seuil.
 *
 * @return array  Liste de codes : low_confidence, missing_entity, unknown_date, ambiguous_type, manual_flag
 */
function ged_v2_compute_review_reasons(array $scores, array $item = []): array
{
    $reasons = [];
    if (($scores['score_global'] ?? 0) < 70) $reasons[] = 'low_confidence';
    if (($scores['score_entity'] ?? 0) < 50) $reasons[] = 'missing_entity';
    if (($scores['score_date'] ?? 0) < 50)   $reasons[] = 'unknown_date';
    if (($scores['score_type'] ?? 0) < 50)   $reasons[] = 'ambiguous_type';
    if (!empty($item['_marked_review'])) $reasons[] = 'manual_flag';
    return $reasons;
}

// ─── Anti-doublon enrichi ──────────────────────────────────────────────

/**
 * Détection doublons enrichie : hash + size + mime + entité + date ±2j.
 *
 * @param array $item Ligne ged_import_items en cours d'analyse
 * @param int   $dateToleranceDays
 * @return array ['hash_matches' => [...], 'similar' => [...], 'risk' => 'high|medium|low|none']
 */
function ged_v2_find_duplicates_smart(array $item, int $dateToleranceDays = 2): array
{
    $pdo = ged_naming_v2_pdo();
    $hashMatches = [];
    $similar = [];

    $hash = (string)($item['hash_sha256'] ?? '');
    $size = (int)($item['size_bytes'] ?? 0);
    $mime = (string)($item['mime_type'] ?? '');
    $entityType = (string)($item['entity_type_resolved'] ?? '');
    $entityId   = (int)($item['entity_id_resolved'] ?? 0);
    $dateDoc    = (string)($item['date_document'] ?? '');

    // 1. Hash strict (immédiatement bloquant si match)
    if ($hash !== '') {
        $st = $pdo->prepare("
            SELECT id, name_display, name_canonical, status, created_at
            FROM ged_documents
            WHERE hash_sha256 = ? AND status <> 'deleted'
            LIMIT 5
        ");
        $st->execute([$hash]);
        $hashMatches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // 2. Similar (tolérance) : même size + même mime + même entité + date ±N jours
    if ($size > 0 && $mime !== '' && $entityType !== '' && $entityId > 0) {
        $sql = "
            SELECT d.id, d.name_display, d.name_canonical, d.size_bytes, d.created_at,
                   l.entity_type, l.entity_id
            FROM ged_documents d
            JOIN ged_document_links l ON l.document_id = d.id
            WHERE d.status <> 'deleted'
              AND d.size_bytes = ? AND d.mime_type = ?
              AND l.entity_type = ? AND l.entity_id = ?
        ";
        $params = [$size, $mime, $entityType, $entityId];
        if ($dateDoc !== '') {
            $sql .= " AND ABS(DATEDIFF(d.created_at, ?)) <= ?";
            $params[] = $dateDoc; $params[] = $dateToleranceDays;
        }
        $sql .= " LIMIT 5";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $similar = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $similar = [];
        }
    }

    // Risque
    $risk = 'none';
    if (!empty($hashMatches)) $risk = 'high';
    elseif (!empty($similar)) $risk = 'medium';
    elseif ($size > 0)        $risk = 'low';

    return [
        'hash_matches' => $hashMatches,
        'similar'      => $similar,
        'risk'         => $risk,
    ];
}

// ─── Suggestions N6 (autocomplete) ─────────────────────────────────────

/**
 * Suggère des codes N6 depuis :
 *   1. ged_synonyms (keyword LIKE q + module match)
 *   2. ged_classification_feedback (corrections N6 historiques)
 *   3. Codes N6 déjà utilisés dans ged_import_items / ged_documents
 *
 * @param string $query    Texte tapé par l'utilisateur (préfixe)
 * @param string|null $n1  Module pour filtrer (ex: 04_SYNDIC)
 * @param int $limit
 * @return array Liste de ['code'=>string, 'label'=>string, 'source'=>'synonym|feedback|history', 'weight'=>int]
 */
function ged_v2_suggest_n6(string $query, ?string $n1 = null, int $limit = 10): array
{
    $pdo = ged_naming_v2_pdo();
    $q = trim($query);
    $like = $q === '' ? '%' : ($q . '%');
    $suggestions = [];

    // 1. Synonymes
    try {
        $sql = "SELECT normalized_code AS code, normalized_code AS label, weight, 'synonym' AS source
                FROM ged_synonyms
                WHERE keyword LIKE ?";
        $params = [$like];
        if ($n1) {
            $sql .= " AND (module IS NULL OR module = ?)";
            $params[] = $n1;
        }
        $sql .= " ORDER BY weight DESC, normalized_code ASC LIMIT 30";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $suggestions[$r['code']] = $r;
        }
    } catch (Throwable) {}

    // 2. Feedback historique
    try {
        $sql = "SELECT correction_value AS code, correction_value AS label,
                       COUNT(*) * 10 AS weight, 'feedback' AS source
                FROM ged_classification_feedback
                WHERE field = 'n6' AND correction_value LIKE ?";
        $params = [$like];
        if ($n1) { $sql .= " AND module = ?"; $params[] = $n1; }
        $sql .= " GROUP BY correction_value ORDER BY weight DESC LIMIT 30";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = (string)$r['code'];
            if (isset($suggestions[$code])) {
                $suggestions[$code]['weight'] = (int)$suggestions[$code]['weight'] + (int)$r['weight'];
            } else {
                $suggestions[$code] = $r;
            }
        }
    } catch (Throwable) {}

    // 3. Historique items (codes N6 déjà utilisés dans le batch)
    try {
        $sql = "SELECT selected_n6 AS code, selected_n6 AS label, COUNT(*) AS weight, 'history' AS source
                FROM ged_import_items
                WHERE selected_n6 IS NOT NULL AND selected_n6 <> '' AND selected_n6 LIKE ?";
        $params = [$like];
        if ($n1) { $sql .= " AND selected_n1 = ?"; $params[] = $n1; }
        $sql .= " GROUP BY selected_n6 ORDER BY weight DESC LIMIT 30";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = (string)$r['code'];
            if (!isset($suggestions[$code])) $suggestions[$code] = $r;
        }
    } catch (Throwable) {}

    // Tri par weight desc puis code asc
    $list = array_values($suggestions);
    usort($list, static fn($a, $b) =>
        ((int)$b['weight'] <=> (int)$a['weight']) ?: strcmp((string)$a['code'], (string)$b['code'])
    );
    return array_slice($list, 0, $limit);
}

// ─── Apprentissage (feedback user) ─────────────────────────────────────

/**
 * Enregistre une correction utilisateur dans ged_classification_feedback.
 * Utilisé par l'API feedback_learning et automatiquement quand un item est
 * validé avec des champs corrigés vs la suggestion IA initiale.
 */
function ged_v2_record_feedback(
    string $field,
    ?string $suggestionValue,
    ?string $correctionValue,
    ?int $importItemId = null,
    ?int $documentId = null,
    ?string $oldFilename = null,
    ?string $module = null,
    int $weight = 100
): void {
    if ($suggestionValue === $correctionValue) return; // pas de correction
    try {
        ged_naming_v2_pdo()->prepare("
            INSERT INTO ged_classification_feedback
                (tenant_id, import_item_id, document_id, old_filename, field,
                 suggestion_value, correction_value, module, user_id, weight_applied)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            ged_current_tenant_id(),
            $importItemId,
            $documentId,
            $oldFilename,
            $field,
            $suggestionValue,
            $correctionValue,
            $module,
            ged_current_user_id(),
            $weight,
        ]);
    } catch (Throwable $e) {
        error_log('[ged_v2_record_feedback] ' . $e->getMessage());
    }
}
