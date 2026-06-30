<?php
/**
 * 20260524_ged_seed_v1_2_n2_bien_gestion.php
 *
 * Seed idempotent — Ajoute le N2 "03_bien" sous 05_gestion_locative
 * + ses N3 (diagnostics techniques + assurances).
 *
 * Pourquoi : pendant Sprint 4A (seed v1), la branche 05_gestion_locative
 * a été créée SANS N2 "diagnostics" dédié. Les diagnostics d'un bien en
 * location (ERNT, DPE, amiante, etc.) étaient temporairement fallback
 * sous 02_bail (annexe contractuelle), ce qui n'est pas correct métier :
 * les diagnostics caractérisent le BIEN, pas le bail.
 *
 * Cette migration crée :
 *   05_gestion_locative
 *     └─ 03_bien                        (N2)
 *         ├─ 01_dpe                     (N3)
 *         ├─ 02_erp_ernmt               (N3)
 *         ├─ 03_amiante                 (N3)
 *         ├─ 04_plomb_crep              (N3)
 *         ├─ 05_electricite             (N3)
 *         ├─ 06_gaz                     (N3)
 *         ├─ 07_termites                (N3)
 *         ├─ 08_surface_carrez          (N3)
 *         └─ 09_assurance_proprietaire  (N3)
 *
 * Idempotent : INSERT ON DUPLICATE KEY UPDATE sur UK (parent_id, slug).
 * Marqueur storage_path = 'seed:skeleton_v1.2'.
 *
 * Réf : bug ERNT bien #733 (2026-05-24)
 */

declare(strict_types=1);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once dirname(__DIR__, 2) . '/config/db.php';
    $pdo = db();
}

$dryRun = !empty($GLOBALS['__SEED_DRY_RUN']);
$log = [];

// ─── 1. Trouver le parent N1 = 05_gestion_locative ──
$st = $pdo->prepare("SELECT id, name_display FROM ged_folders WHERE slug = '05_gestion_locative' AND (parent_id IS NULL OR parent_id = 0) LIMIT 1");
$st->execute();
$n1 = $st->fetch(PDO::FETCH_ASSOC);
if (!$n1) {
    $log[] = "❌ N1 '05_gestion_locative' introuvable. Lance d'abord seed skeleton v1 (Sprint 4A).";
    return ['log' => $log, 'inserted' => 0, 'skipped' => 0, 'ok' => false];
}
$n1Id = (int)$n1['id'];
$log[] = "✅ N1 trouvé : #$n1Id · {$n1['name_display']}";

// ─── 2. Définir N2 + N3 à seeder ──
$n2Slug    = '03_bien';
$n2Display = '03 - Bien (diagnostics & assurances)';

$n3List = [
    ['slug' => '01_dpe',                    'name' => '01 - DPE'],
    ['slug' => '02_erp_ernmt',              'name' => '02 - ERP/ERNMT (état des risques)'],
    ['slug' => '03_amiante',                'name' => '03 - Amiante'],
    ['slug' => '04_plomb_crep',             'name' => '04 - Plomb (CREP)'],
    ['slug' => '05_electricite',            'name' => '05 - Électricité'],
    ['slug' => '06_gaz',                    'name' => '06 - Gaz'],
    ['slug' => '07_termites',               'name' => '07 - Termites / Mérule'],
    ['slug' => '08_surface_carrez',         'name' => '08 - Surface Carrez / Boutin'],
    ['slug' => '09_assurance_proprietaire', 'name' => '09 - Assurance propriétaire (PNO)'],
];

// ─── 3. Helper INSERT idempotent ──
$insertFolder = function (PDO $pdo, int $parentId, string $slug, string $display, string $kind = 'business_view') use (&$log, $dryRun): int {
    // Check existant via UK (parent_id, slug)
    $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = ? LIMIT 1");
    $st->execute([$parentId, $slug]);
    $existing = (int)$st->fetchColumn();
    if ($existing > 0) {
        $log[] = "  ⏭️  SKIP (déjà existant) parent=$parentId slug=$slug → folder #$existing";
        return $existing;
    }
    if ($dryRun) {
        $log[] = "  [DRY-RUN] INSERT parent=$parentId slug=$slug name='$display' kind=$kind";
        return -1;
    }
    // depth = parent_depth + 1, ou 1 par défaut. Lecture parent.
    $depth = 1;
    $tenantId = null;
    if ($parentId > 0) {
        $stP = $pdo->prepare("SELECT depth, tenant_id FROM ged_folders WHERE id = ?");
        $stP->execute([$parentId]);
        $p = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
        $depth = (int)($p['depth'] ?? 0) + 1;
        $tenantId = $p['tenant_id'] ?? null;
    }
    // UUID v4 généré (colonne UNIQUE)
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));

    $st = $pdo->prepare("INSERT INTO ged_folders
        (uuid, parent_id, slug, name_display, folder_kind, is_archived, storage_path, depth, tenant_id, position, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, 'seed:skeleton_v1.2', ?, ?, 0, NOW(), NOW())");
    $st->execute([$uuid, $parentId, $slug, $display, $kind, $depth, $tenantId]);
    $newId = (int)$pdo->lastInsertId();
    $log[] = "  ✅ INSERT parent=$parentId slug=$slug → folder #$newId";
    return $newId;
};

// ─── 4. Seed N2 ──
$log[] = "── Création N2 '$n2Slug' sous gestion locative ──";
$n2Id = $insertFolder($pdo, $n1Id, $n2Slug, $n2Display, 'business_view');

// ─── 5. Seed N3 (seulement si N2 OK) ──
$insertedN3 = 0;
$skippedN3  = 0;
if ($n2Id > 0 || $dryRun) {
    $log[] = "── Création N3 sous '$n2Slug' ──";
    $effectiveN2Id = $n2Id > 0 ? $n2Id : 99999; // dummy si dry-run
    foreach ($n3List as $n3) {
        $before = count($log);
        $insertFolder($pdo, $effectiveN2Id, $n3['slug'], $n3['name'], 'business_view');
        if (str_contains($log[count($log)-1], 'INSERT')) $insertedN3++;
        else $skippedN3++;
    }
}

$inserted = ($n2Id > 0 ? 1 : 0) + $insertedN3;
$skipped  = (($n2Id === -1 && !$dryRun) ? 0 : 0) + $skippedN3;

$log[] = "";
$log[] = $dryRun
    ? "═ DRY-RUN terminé · serait inséré : " . (1 + count($n3List)) . " rows"
    : "═ APPLY terminé · inséré : $inserted · skip : $skipped";

return [
    'log' => $log,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'n1_id'    => $n1Id,
    'n2_id'    => $n2Id > 0 ? $n2Id : null,
    'ok'       => true,
];
