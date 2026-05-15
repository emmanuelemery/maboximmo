<?php
declare(strict_types=1);

/**
 * Admin GED — Glossaire des codes courts (lecture + seed initial).
 *
 * Phase 1+2 : visualisation du glossaire actuel + bouton "Seed initial"
 * qui peuple : sociétés, agences, users, immeubles, types document de base,
 * métiers N1/N2/N3 principaux.
 *
 * Phase 3 ultérieure : édition inline, ajout manuel, intégration nommage.
 *
 * Réservé super admin (id_role = 1).
 */

// Élargi pour les gros seed (629 immeubles + précharge) ; ne tue pas la session si l'user ferme l'onglet
@set_time_limit(600);
@ignore_user_abort(true);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_functions.php';
require_once __DIR__ . '/../inc/ged_glossary.php';
require_once __DIR__ . '/../inc/ged_glossary_seed.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tenantId = (int)(ged_current_tenant_id() ?? 0);

// Définir $categories AVANT le traitement POST (utilisé par add_code pour valider la catégorie)
$categories = ged_glossary_categories();

$flash = null;
$seedReport = null;

// ────────────────────────────────────────────────────────────────
// Actions POST : seed initial + CRUD codes glossaire
// ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'seed_initial') {
            $dryRun = !empty($_POST['dry_run']);
            $seedReport = ged_glossary_seed_initial($pdo, $dryRun);
            $flash = ['type'=>'success',
                      'msg' => $dryRun
                          ? "🧪 Dry-run terminé : voir le rapport ci-dessous (rien n'a été inséré en BDD)."
                          : "✅ Seed appliqué : voir le rapport ci-dessous."];
        }
        elseif ($action === 'update_code') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id invalide');
            $newCode  = trim((string)($_POST['code']  ?? ''));
            $newLabel = trim((string)($_POST['label'] ?? ''));
            if ($newLabel === '') throw new RuntimeException('Le label ne peut pas être vide');

            // Récupère catégorie pour validation
            $st = $pdo->prepare("SELECT category, code FROM ged_codes_glossaire WHERE id = ? AND tenant_id = ?");
            $st->execute([$id, $tenantId]);
            $current = $st->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new RuntimeException("Entrée #$id introuvable");

            $check = ged_glossary_validate_code((string)$current['category'], $newCode, $pdo, $id);
            if (!$check['ok']) throw new RuntimeException(implode(' / ', $check['errors']));

            $pdo->prepare("
                UPDATE ged_codes_glossaire
                SET code = ?, label = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ")->execute([$check['normalized'], $newLabel, $id, $tenantId]);

            $flash = ['type'=>'success', 'msg' => "✏️ Code « {$current['code']} » → « {$check['normalized']} » mis à jour."];
        }
        elseif ($action === 'add_code') {
            $cat      = (string)($_POST['category'] ?? '');
            $newCode  = trim((string)($_POST['code']  ?? ''));
            $newLabel = trim((string)($_POST['label'] ?? ''));
            if ($newLabel === '') throw new RuntimeException('Le label ne peut pas être vide');
            if (!isset($categories[$cat])) throw new RuntimeException("Catégorie invalide : $cat");

            $check = ged_glossary_validate_code($cat, $newCode, $pdo);
            if (!$check['ok']) throw new RuntimeException(implode(' / ', $check['errors']));

            $createdBy = function_exists('current_user_id') ? (int)current_user_id() : null;
            $pdo->prepare("
                INSERT INTO ged_codes_glossaire
                  (tenant_id, category, entity_table, entity_id, code, label, is_locked, is_active, notes, created_by)
                VALUES (?, ?, NULL, NULL, ?, ?, 1, 1, ?, ?)
            ")->execute([$tenantId, $cat, $check['normalized'], $newLabel,
                         'Ajouté manuellement le ' . date('Y-m-d H:i:s'),
                         $createdBy ?: null]);

            $flash = ['type'=>'success', 'msg' => "➕ Code « {$check['normalized']} » ajouté dans la catégorie « {$categories[$cat]['label']} »."];
        }
        elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id invalide');
            $pdo->prepare("UPDATE ged_codes_glossaire SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$id, $tenantId]);
            $flash = ['type'=>'success', 'msg' => "🔄 Code #$id bascule actif/désactivé."];
        }
        elseif ($action === 'toggle_locked') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id invalide');
            $pdo->prepare("UPDATE ged_codes_glossaire SET is_locked = 1 - is_locked, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$id, $tenantId]);
            $flash = ['type'=>'success', 'msg' => "🔒 Verrou bascule pour code #$id."];
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'error', 'msg' => '❌ ' . $e->getMessage()];
    }
}

// ────────────────────────────────────────────────────────────────
// Lecture du glossaire actuel
// ────────────────────────────────────────────────────────────────
$filterCat = (string)($_GET['category'] ?? '');
$search    = trim((string)($_GET['q'] ?? ''));

$list = ged_glossary_list([
    'category' => $filterCat,
    'search'   => $search,
    'limit'    => 500,
], $pdo);

$rowsByCat = [];
foreach ($list['rows'] as $r) {
    $rowsByCat[$r['category']][] = $r;
}

$categories = ged_glossary_categories();

// ════════════════════════════════════════════════════════════════
// FONCTION DE SEED — version optimisée mémoire (1 SELECT global)
// ════════════════════════════════════════════════════════════════
function run_seed_initial(PDO $pdo, bool $dryRun): array
{
    $tenantId = (int)(ged_current_tenant_id() ?? 0);
    $startTime = microtime(true);
    $report = [
        'dry_run'    => $dryRun,
        'tenant_id'  => $tenantId,
        'created'    => 0,
        'skipped'    => 0,
        'errors'     => [],
        'by_category'=> [],
        'samples'    => [],
        'duration_ms'=> 0,
    ];

    // ── PRÉCHARGE en mémoire : 1 seul SELECT pour tout savoir ───
    $takenCodes = [];   // [category => [code => true]]
    $existing   = [];   // [category => [entity_table:entity_id => true]]
    try {
        $st = $pdo->prepare("
            SELECT category, entity_table, entity_id, code
            FROM ged_codes_glossaire
            WHERE tenant_id = ?
        ");
        $st->execute([$tenantId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $cat = (string)$r['category'];
            $takenCodes[$cat][(string)$r['code']] = true;
            if (!empty($r['entity_id']) && !empty($r['entity_table'])) {
                $existing[$cat][$r['entity_table'] . ':' . $r['entity_id']] = true;
            }
        }
    } catch (Throwable $e) {
        $report['errors'][] = "Précharge cache : " . $e->getMessage();
    }

    // Prepared statement réutilisé pour les INSERTs
    // (pas de NULLIF SQL pour éviter le conflit de collation utf8mb4_general_ci vs _unicode_ci ;
    //  on passe NULL directement depuis PHP)
    $stIns = $pdo->prepare("
        INSERT INTO ged_codes_glossaire
          (tenant_id, category, entity_table, entity_id, code, label, is_locked, is_active, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?)
    ");
    $createdBy = function_exists('current_user_id') ? (int)current_user_id() : null;
    $notes = 'Seedé le ' . date('Y-m-d H:i:s');

    // Helper local : dérive un code unique en utilisant le cache mémoire (sans BDD)
    $deriveUnique = function (string $category, string $label, array $opts = [])
                    use (&$takenCodes): string {
        $maxLen = ged_glossary_max_length($category);

        // Candidate selon catégorie
        $candidate = '';
        if ($category === 'user') {
            $mat = trim((string)($opts['matricule_paie'] ?? ''));
            if ($mat !== '') {
                $candidate = ged_glossary_slug_code($mat);
            } else {
                $candidate = 'U' . (int)($opts['user_id'] ?? 0);
            }
        } elseif (in_array($category, ['agence', 'immeuble'], true)) {
            $existingCode = trim((string)($opts['code_existant'] ?? ''));
            if ($existingCode !== '') {
                $candidate = ged_glossary_slug_code($existingCode);
            } else {
                $candidate = ged_glossary_derive_from_label($label, $maxLen);
            }
        } elseif (in_array($category, ['metier_n1', 'metier_n2', 'metier_n3'], true)) {
            $clean = preg_replace('/^\d+\s*[-_]?\s*/', '', $label) ?? $label;
            $candidate = ged_glossary_derive_from_label($clean, $maxLen);
        } else {
            $candidate = ged_glossary_derive_from_label($label, $maxLen);
        }

        if (mb_strlen($candidate) > $maxLen) {
            $candidate = mb_substr($candidate, 0, $maxLen);
        }

        // Unicité en mémoire (pas de BDD)
        if ($candidate === '' || isset($takenCodes[$category][$candidate])) {
            $base = $candidate !== '' ? $candidate : 'X';
            $found = false;
            for ($i = 1; $i <= 99; $i++) {
                $suffix = (string)$i;
                $room = $maxLen - mb_strlen($suffix);
                if ($room < 1) { $base = mb_substr($base, 0, 1); $room = $maxLen - mb_strlen($suffix); }
                $try = mb_substr($base, 0, $room) . $suffix;
                if (!isset($takenCodes[$category][$try])) {
                    $candidate = $try; $found = true; break;
                }
            }
            if (!$found) {
                $candidate = mb_substr(strtoupper(bin2hex(random_bytes(4))), 0, $maxLen);
            }
        }
        $takenCodes[$category][$candidate] = true;
        return $candidate;
    };

    // Helper local : seed une entrée (skip si existe, INSERT direct sinon)
    $seedOne = function (string $category, ?string $entityTable, ?int $entityId,
                        string $code, string $label)
               use (&$report, &$existing, &$takenCodes, $stIns, $tenantId, $dryRun, $createdBy, $notes): string {
        if (!isset($report['by_category'][$category])) {
            $report['by_category'][$category] = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // Skip si déjà en mémoire
        if ($entityId !== null && $entityTable !== null) {
            if (isset($existing[$category][$entityTable . ':' . $entityId])) {
                $report['skipped']++;
                $report['by_category'][$category]['skipped']++;
                return 'exists';
            }
        }

        if ($dryRun) {
            $report['created']++;
            $report['by_category'][$category]['created']++;
            return 'would_create';
        }

        try {
            $stIns->execute([
                $tenantId,
                $category,
                ($entityTable !== null && $entityTable !== '') ? $entityTable : null,
                $entityId, // déjà null si pas d'entité (catégorie libre)
                $code,
                $label,
                $notes,
                $createdBy ?: null,
            ]);
            // Update cache
            if ($entityId !== null && $entityTable !== null) {
                $existing[$category][$entityTable . ':' . $entityId] = true;
            }
            $report['created']++;
            $report['by_category'][$category]['created']++;
            return 'created';
        } catch (Throwable $e) {
            $report['errors'][] = "$category | $label → $code : " . $e->getMessage();
            $report['by_category'][$category]['failed']++;
            return 'error';
        }
    };

    // ── Transaction pour batch ───────────────────────────────────
    if (!$dryRun) {
        try { $pdo->beginTransaction(); } catch (Throwable) {}
    }

    // ── 1. Sociétés ──────────────────────────────────────────────
    try {
        $st = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
            $name = (string)$s['nom'];
            $code = $deriveUnique('societe', $name);
            $r = $seedOne('societe', 'societes', (int)$s['id'], $code, $name);
            $report['samples']['societe'][] = ['id' => (int)$s['id'], 'label' => $name, 'code' => $code, 'status' => $r];
        }
    } catch (Throwable $e) {
        $report['errors'][] = "Sociétés : " . $e->getMessage();
    }

    // ── 2. Agences ───────────────────────────────────────────────
    try {
        $st = $pdo->query("
            SELECT id, nom_agence,
                   COALESCE(NULLIF(code_agence,''), NULLIF(code_interne,'')) AS code_existant
            FROM agences ORDER BY nom_agence
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
            $name = (string)$a['nom_agence'];
            $code = $deriveUnique('agence', $name, ['code_existant' => (string)$a['code_existant']]);
            $r = $seedOne('agence', 'agences', (int)$a['id'], $code, $name);
            $report['samples']['agence'][] = ['id' => (int)$a['id'], 'label' => $name, 'code' => $code, 'status' => $r];
        }
    } catch (Throwable $e) {
        $report['errors'][] = "Agences : " . $e->getMessage();
    }

    // ── 3. Users actifs ──────────────────────────────────────────
    try {
        $st = $pdo->query("
            SELECT id, prenom, nom, matricule_paie
            FROM users WHERE actif = 1 ORDER BY nom, prenom
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $label = trim((string)$u['prenom'] . ' ' . (string)$u['nom']);
            if ($label === '') $label = 'User#' . $u['id'];
            $code = $deriveUnique('user', $label, [
                'matricule_paie' => (string)$u['matricule_paie'],
                'user_id'        => (int)$u['id'],
            ]);
            $r = $seedOne('user', 'users', (int)$u['id'], $code, $label);
            $report['samples']['user'][] = ['id' => (int)$u['id'], 'label' => $label, 'code' => $code, 'status' => $r];
        }
    } catch (Throwable $e) {
        $report['errors'][] = "Users : " . $e->getMessage();
    }

    // ── 4. Immeubles ─────────────────────────────────────────────
    try {
        $st = $pdo->query("
            SELECT id, nom_immeuble, reference_immeuble
            FROM immeubles ORDER BY nom_immeuble LIMIT 1000
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $imm) {
            $name = (string)$imm['nom_immeuble'];
            $code = $deriveUnique('immeuble', $name, [
                'code_existant' => (string)$imm['reference_immeuble'],
            ]);
            $r = $seedOne('immeuble', 'immeubles', (int)$imm['id'], $code, $name);
            $report['samples']['immeuble'][] = ['id' => (int)$imm['id'], 'label' => $name, 'code' => $code, 'status' => $r];
        }
    } catch (Throwable $e) {
        $report['errors'][] = "Immeubles : " . $e->getMessage();
    }

    // ── 5. Types document de base (codes libres) ─────────────────
    $typesBase = [
        'RELEVE-BANQ' => 'Relevé bancaire', 'FACTURE' => 'Facture fournisseur',
        'BULLETIN' => 'Bulletin de paie', 'PV-AG' => 'PV Assemblée Générale',
        'MANDAT' => 'Mandat (gestion/syndic/vente)', 'KBIS' => 'Extrait Kbis',
        'DEVIS' => 'Devis', 'BAIL' => 'Bail',
        'COMPROMIS' => 'Compromis de vente', 'ACTE' => 'Acte de propriété',
        'EDL' => 'État des lieux', 'RIB' => 'RIB',
        'CARTE-PRO' => 'Carte professionnelle', 'GARANTIE' => 'Garantie financière',
        'RC-PRO' => 'RC Pro', 'ATTESTATION' => 'Attestation',
        'COURRIER' => 'Courrier', 'MAIL' => 'Email',
        'CONTRAT' => 'Contrat', 'AVENANT' => 'Avenant',
    ];
    foreach ($typesBase as $code => $label) {
        // Pour les codes libres : skip si déjà présent dans takenCodes
        if (isset($takenCodes['type_document'][$code])) {
            $report['skipped']++;
            $report['by_category']['type_document']['skipped'] = ($report['by_category']['type_document']['skipped'] ?? 0) + 1;
            $report['samples']['type_document'][] = ['id' => null, 'label' => $label, 'code' => $code, 'status' => 'exists'];
            continue;
        }
        $takenCodes['type_document'][$code] = true;
        $r = $seedOne('type_document', null, null, $code, $label);
        $report['samples']['type_document'][] = ['id' => null, 'label' => $label, 'code' => $code, 'status' => $r];
    }

    // ── 6. Métiers N1/N2/N3 depuis ged_level_codes ──────────────
    foreach (['metier_n1' => 1, 'metier_n2' => 2, 'metier_n3' => 3] as $cat => $lvl) {
        try {
            $st = $pdo->prepare("
                SELECT id, code AS source_code, label
                FROM ged_level_codes
                WHERE level_number = ? AND is_active = 1 AND COALESCE(is_virtual, 0) = 0
                ORDER BY position
            ");
            $st->execute([$lvl]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lc) {
                $label = (string)$lc['label'];
                $sourceCode = (string)$lc['source_code'];
                $code = $deriveUnique($cat, $sourceCode !== '' ? $sourceCode : $label);
                $r = $seedOne($cat, 'ged_level_codes', (int)$lc['id'], $code, $label);
                $report['samples'][$cat][] = ['id' => (int)$lc['id'], 'label' => $label, 'code' => $code, 'status' => $r];
            }
        } catch (Throwable $e) {
            $report['errors'][] = "Métier $cat : " . $e->getMessage();
        }
    }

    if (!$dryRun) {
        try { $pdo->commit(); } catch (Throwable) {}
    }

    $report['duration_ms'] = (int)round((microtime(true) - $startTime) * 1000);
    return $report;
}

// ────────────────────────────────────────────────────────────────
// Rendu page
// ────────────────────────────────────────────────────────────────
$layout_title          = 'GED — Glossaire des codes';
$layout_module         = 'Ma GED Box';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.gglo-wrap { max-width:1200px; margin:0 auto; padding:24px 0 40px; }
.gglo-h1 { font-family:"Sora",sans-serif; font-size:26px; color:#243B5C; margin:0 0 10px; }
.gglo-sub { color:#64748b; font-size:13px; margin-bottom:18px; }
.gglo-flash { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; }
.gglo-flash.success { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.gglo-flash.error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

.gglo-bar { background:#fff; padding:14px 18px; border-radius:14px;
            box-shadow:4px 4px 12px rgba(196,192,186,0.4), -4px -4px 12px #fff;
            margin-bottom:18px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.gglo-field { display:flex; flex-direction:column; gap:4px; }
.gglo-field label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; }
.gglo-field input, .gglo-field select { padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:13px; min-width:180px; }
.gglo-btn { padding:9px 18px; border-radius:10px; border:none; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; }
.gglo-btn-primary { background:linear-gradient(135deg,#243B5C,#1e3050); color:#fff; }
.gglo-btn-warn { background:linear-gradient(135deg,#D4A047,#b88835); color:#1a1816; }
.gglo-btn-secondary { background:#f1f5f9; color:#475569; }

.gglo-section { background:#fff; border-radius:14px; padding:16px 20px;
                box-shadow:4px 4px 12px rgba(196,192,186,0.4), -4px -4px 12px #fff;
                margin-bottom:14px; }
.gglo-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.gglo-section-head h3 { margin:0; font-size:16px; color:#243B5C; font-weight:700;
                       display:inline-flex; align-items:center; gap:8px; }
.gglo-section-count { background:#D4A047; color:#1a1816; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:700; }
.gglo-section-meta { font-size:11px; color:#94a3b8; }

.gglo-table { width:100%; border-collapse:collapse; font-size:12px; }
.gglo-table th { text-align:left; padding:8px 10px; background:#f8fafc;
                 font-size:10px; font-weight:700; color:#64748b;
                 text-transform:uppercase; letter-spacing:0.05em;
                 border-bottom:1px solid #e2e8f0; }
.gglo-table td { padding:6px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.gglo-table tr:hover { background:#fef9e8; }
.gglo-code-cell { font-family:"JetBrains Mono",monospace; font-weight:700; color:#243B5C; font-size:13px; }
.gglo-lock { font-size:14px; }
.gglo-meta { font-size:10px; color:#94a3b8; }

.gglo-seed-section { background:linear-gradient(135deg,#fef3c7,#fde68a);
                     border:1px solid #fbbf24; border-radius:14px;
                     padding:16px 20px; margin-bottom:18px; }
.gglo-seed-section h3 { margin:0 0 8px; color:#92400e; font-size:16px; }
.gglo-seed-section p { margin:0 0 12px; font-size:13px; color:#78350f; }
.gglo-seed-actions { display:flex; gap:10px; flex-wrap:wrap; }

.gglo-report { background:#fff; border-radius:14px; padding:18px 22px;
               box-shadow:4px 4px 12px rgba(196,192,186,0.4), -4px -4px 12px #fff;
               margin-bottom:18px; }
.gglo-report h3 { margin:0 0 12px; color:#243B5C; font-size:16px; }
.gglo-report-stats { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:14px; }
.gglo-report-stat { display:flex; flex-direction:column; }
.gglo-report-stat strong { font-size:22px; color:#243B5C; font-weight:700; }
.gglo-report-stat small { font-size:11px; color:#64748b; }
.gglo-report-by-cat { font-size:12px; }
.gglo-report-by-cat table { width:100%; border-collapse:collapse; }
.gglo-report-by-cat th, .gglo-report-by-cat td { padding:5px 8px; border-bottom:1px solid #f1f5f9; }
.gglo-report-by-cat th { background:#f8fafc; font-size:10px; text-transform:uppercase; color:#64748b; }
.gglo-empty { color:#94a3b8; font-style:italic; text-align:center; padding:20px 0; }

details summary { cursor:pointer; padding:6px 0; font-size:12px; color:#243B5C; font-weight:600; }
details[open] summary { margin-bottom:6px; }

/* ── Édition inline ─────────────────────────────────────── */
.gglo-row-edit { background:#fef9e8 !important; }
.gglo-edit-input {
    padding:5px 8px; border:1px solid #cbd5e1; border-radius:6px;
    font-family:"JetBrains Mono",monospace; font-size:12px;
    width: 110px;
}
.gglo-edit-input.label-input {
    font-family:"Manrope","Sora",sans-serif; width: 100%; max-width:300px;
}
.gglo-edit-input:focus { outline:2px solid #243B5C; border-color:#243B5C; }
.gglo-icon-btn {
    background:transparent; border:none; cursor:pointer;
    padding:4px 6px; border-radius:4px; font-size:14px;
    color:#64748b; transition:all .12s;
}
.gglo-icon-btn:hover { background:#f1f5f9; color:#243B5C; }
.gglo-icon-btn.danger:hover { background:#fee2e2; color:#991b1b; }
.gglo-icon-btn.save { background:#243B5C; color:#fff; }
.gglo-icon-btn.save:hover { background:#1e3050; }

/* ── Form add row ───────────────────────────────────────── */
.gglo-add-row { background:#f8fafc; padding:10px 14px; border-bottom:1px solid #e2e8f0;
                display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.gglo-add-row strong { color:#475569; font-size:11px; text-transform:uppercase;
                       letter-spacing:0.05em; min-width:80px; }
.gglo-add-row input { padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; }
.gglo-add-row input.code { width:110px; font-family:"JetBrains Mono",monospace; }
.gglo-add-row input.label { flex:1; min-width:160px; }
.gglo-add-row button { padding:6px 14px; background:linear-gradient(135deg,#243B5C,#1e3050);
                       color:#fff; border:none; border-radius:6px; cursor:pointer;
                       font-family:inherit; font-size:12px; font-weight:600; }
.gglo-add-row button:hover { background:linear-gradient(135deg,#1e3050,#142440); }
.gglo-add-toggle {
    background:linear-gradient(135deg,#D4A047,#b88835); color:#1a1816;
    border:none; padding:5px 12px; border-radius:6px; cursor:pointer;
    font-family:inherit; font-size:11px; font-weight:700;
}
.gglo-add-toggle:hover { background:linear-gradient(135deg,#b88835,#9a7028); }
.gglo-add-form { display:none; }
.gglo-add-form.is-open { display:flex; }

.gglo-row-inactive { opacity:0.45; }
.gglo-row-inactive .gglo-code-cell { text-decoration:line-through; }
</style>';

ob_start();
?>
<div class="gglo-wrap">
    <h1 class="gglo-h1">🏷️ GED — Glossaire des codes</h1>
    <p class="gglo-sub">
        Nomenclature centralisée des codes courts utilisés pour nommer les fichiers (sociétés, agences, users, immeubles, types document, métiers).
        <br>Phase 1+2 : visualisation + seed initial. <strong>Pas encore intégré au renommage des fichiers</strong> (phase 3 ultérieure).
    </p>

    <?php if ($flash): ?>
        <div class="gglo-flash <?= $h($flash['type']) ?>"><?= $flash['msg'] ?></div>
    <?php endif; ?>

    <!-- ── Section Seed initial ── -->
    <div class="gglo-seed-section">
        <h3>🌱 Seed initial du glossaire</h3>
        <p>
            Peuple le glossaire à partir des données existantes (sociétés, agences, users, immeubles)
            et des types document de base. <strong>Idempotent</strong> : les entrées existantes ne sont pas écrasées.
            <br>Toujours faire un <strong>DRY-RUN d'abord</strong> pour vérifier les codes proposés avant d'appliquer.
        </p>
        <form method="POST" class="gglo-seed-actions">
            <input type="hidden" name="action" value="seed_initial">
            <button type="submit" name="dry_run" value="1" class="gglo-btn gglo-btn-primary">
                🧪 DRY-RUN (afficher proposition)
            </button>
            <button type="submit" name="dry_run" value="0" class="gglo-btn gglo-btn-warn"
                    onclick="return confirm('Appliquer le seed sur la BDD ? (idempotent : ne touche pas l\'existant)');">
                ⚡ APPLIQUER le seed
            </button>
        </form>
    </div>

    <!-- ── Rapport de seed ── -->
    <?php if ($seedReport !== null): ?>
        <?php if ((int)$seedReport['created'] === 0 && count($seedReport['errors']) > 10): ?>
            <div class="gglo-flash error" style="margin-bottom:14px">
                <strong>🚨 Le seed a échoué entièrement</strong> — voici le 1<sup>er</sup> message :
                <div style="margin-top:8px;padding:8px 12px;background:#fef2f2;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#7f1d1d">
                    <?= $h($seedReport['errors'][0] ?? 'Aucun message') ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="gglo-report">
            <h3>
                <?= $seedReport['dry_run'] ? '🧪 Rapport DRY-RUN' : '✅ Rapport APPLIQUÉ' ?>
                <small style="font-weight:400;color:#94a3b8;font-size:12px">— tenant <?= (int)$seedReport['tenant_id'] ?></small>
            </h3>
            <div class="gglo-report-stats">
                <div class="gglo-report-stat">
                    <strong style="color:#16a34a"><?= (int)$seedReport['created'] ?></strong>
                    <small><?= $seedReport['dry_run'] ? 'À CRÉER' : 'CRÉÉS' ?></small>
                </div>
                <div class="gglo-report-stat">
                    <strong style="color:#ca8a04"><?= (int)$seedReport['skipped'] ?></strong>
                    <small>DÉJÀ EXISTANTS</small>
                </div>
                <div class="gglo-report-stat">
                    <strong style="color:#dc2626"><?= count($seedReport['errors']) ?></strong>
                    <small>ERREURS</small>
                </div>
                <?php if (!empty($seedReport['duration_ms'])): ?>
                <div class="gglo-report-stat">
                    <strong style="color:#243B5C"><?= round((int)$seedReport['duration_ms'] / 1000, 1) ?>s</strong>
                    <small>DURÉE</small>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($seedReport['by_category'])): ?>
                <div class="gglo-report-by-cat">
                    <table>
                        <thead><tr><th>Catégorie</th><th>Créés</th><th>Skipped</th><th>Failed</th></tr></thead>
                        <tbody>
                        <?php foreach ($seedReport['by_category'] as $cat => $stats): ?>
                            <tr>
                                <td><strong><?= $h($cat) ?></strong> <small style="color:#94a3b8">(<?= $h($categories[$cat]['label'] ?? '?') ?>)</small></td>
                                <td><?= (int)$stats['created'] ?></td>
                                <td><?= (int)$stats['skipped'] ?></td>
                                <td><?= (int)$stats['failed'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($seedReport['samples'])): ?>
                <details style="margin-top:14px">
                    <summary>📋 Voir les codes générés (échantillon)</summary>
                    <?php foreach ($seedReport['samples'] as $cat => $items): ?>
                        <div style="margin:10px 0">
                            <strong style="font-size:12px;color:#475569"><?= $h($cat) ?></strong>
                            <table class="gglo-table" style="margin-top:4px">
                                <thead><tr><th>Label</th><th>Code généré</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($items, 0, 20) as $it): ?>
                                    <tr>
                                        <td><?= $h($it['label']) ?></td>
                                        <td class="gglo-code-cell"><?= $h($it['code']) ?></td>
                                        <td>
                                            <?php
                                            echo [
                                                'created'      => '<span style="color:#16a34a">✅ CRÉÉ</span>',
                                                'would_create' => '<span style="color:#1e40af">🧪 PROPOSÉ</span>',
                                                'exists'       => '<span style="color:#ca8a04">⏭ EXISTE DÉJÀ</span>',
                                                'error'        => '<span style="color:#dc2626">❌ ERREUR</span>',
                                            ][$it['status']] ?? $h($it['status']);
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($items) > 20): ?>
                                    <tr><td colspan="3" style="color:#94a3b8;font-style:italic">… et <?= count($items)-20 ?> autres</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>

            <?php if (!empty($seedReport['errors'])): ?>
                <details style="margin-top:14px" open>
                    <summary>❌ Erreurs (<?= count($seedReport['errors']) ?>) — clic pour replier</summary>
                    <ul style="font-size:12px;color:#991b1b;margin:8px 0;font-family:'JetBrains Mono',monospace">
                        <?php foreach (array_slice($seedReport['errors'], 0, 30) as $e): ?>
                            <li><?= $h($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── Filtres + total ── -->
    <form method="GET" class="gglo-bar">
        <div class="gglo-field">
            <label>Catégorie</label>
            <select name="category">
                <option value="">— Toutes —</option>
                <?php foreach ($categories as $cat => $cfg): ?>
                    <option value="<?= $h($cat) ?>" <?= $filterCat === $cat ? 'selected' : '' ?>>
                        <?= $h($cfg['label']) ?> (max <?= (int)$cfg['max_len'] ?> chars)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="gglo-field" style="flex:1">
            <label>Recherche code / label</label>
            <input type="text" name="q" value="<?= $h($search) ?>" placeholder="ex: RE, ALIZEE, releve…">
        </div>
        <button type="submit" class="gglo-btn gglo-btn-primary">Filtrer</button>
        <?php if ($filterCat !== '' || $search !== ''): ?>
            <a href="?" class="gglo-btn gglo-btn-secondary" style="text-decoration:none">Réinit.</a>
        <?php endif; ?>
        <div style="margin-left:auto;font-size:12px;color:#64748b">
            <strong style="color:#243B5C;font-size:14px"><?= (int)$list['total'] ?></strong> codes
        </div>
    </form>

    <!-- ── Glossaire par catégorie (toutes affichées, même vides) ── -->
    <?php
    // Si BDD totalement vide ET pas de filtre → message d'invitation au seed
    if ($list['total'] === 0 && $filterCat === '' && $search === ''):
    ?>
        <div class="gglo-section">
            <div class="gglo-empty">
                📭 Aucun code dans le glossaire.
                <br>Lance un <strong>seed initial</strong> ci-dessus pour peupler les sociétés/agences/users/immeubles existants,
                <br>ou clique sur <strong>➕ Ajouter</strong> dans une section pour créer un code manuellement.
            </div>
        </div>
    <?php endif; ?>
    <?php foreach ($categories as $cat => $cfg):
        $rows = $rowsByCat[$cat] ?? [];
        // Skip uniquement si un filtre catégorie est posé et qu'on n'est pas dessus
        if ($filterCat !== '' && $filterCat !== $cat) continue;
        // Si recherche posée et catégorie vide → on skip aussi
        if (empty($rows) && $search !== '') continue;
    ?>
            <div class="gglo-section">
                <div class="gglo-section-head">
                    <h3>
                        <?= $h($cfg['label']) ?>
                        <span class="gglo-section-count"><?= count($rows) ?></span>
                    </h3>
                    <span class="gglo-section-meta">
                        max <?= (int)$cfg['max_len'] ?> chars
                        <?php if (!empty($cfg['source_table'])): ?>
                            · source : <code><?= $h($cfg['source_table']) ?></code>
                        <?php else: ?>
                            · codes libres
                        <?php endif; ?>
                        <button type="button" class="gglo-add-toggle" data-toggle-add="<?= $h($cat) ?>">
                            ➕ Ajouter
                        </button>
                    </span>
                </div>

                <!-- Form ajout (replié par défaut) -->
                <form method="POST" class="gglo-add-row gglo-add-form" id="fbx-add-<?= $h($cat) ?>">
                    <input type="hidden" name="action" value="add_code">
                    <input type="hidden" name="category" value="<?= $h($cat) ?>">
                    <strong>Nouveau :</strong>
                    <input type="text" name="code" class="code" placeholder="CODE (max <?= (int)$cfg['max_len'] ?>)" maxlength="<?= (int)$cfg['max_len'] ?>" required autocomplete="off">
                    <input type="text" name="label" class="label" placeholder="Libellé humain" required autocomplete="off">
                    <button type="submit">💾 Créer</button>
                    <button type="button" data-cancel-add="<?= $h($cat) ?>" class="gglo-icon-btn">✕</button>
                </form>

                <table class="gglo-table">
                    <thead>
                        <tr>
                            <th style="width:130px">Code</th>
                            <th>Label</th>
                            <th style="width:50px">🔒</th>
                            <th style="width:170px">Source</th>
                            <th style="width:120px" class="gglo-meta">Créé</th>
                            <th style="width:90px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#94a3b8;font-style:italic;padding:24px">
                                📭 Aucun code dans cette catégorie.<br>
                                <small>Clique sur <strong>➕ Ajouter</strong> en haut pour en créer un.</small>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r):
                        $isInactive = (int)$r['is_active'] === 0;
                    ?>
                        <!-- Ligne en mode LECTURE -->
                        <tr class="gglo-row-read<?= $isInactive ? ' gglo-row-inactive' : '' ?>" data-row-id="<?= (int)$r['id'] ?>">
                            <td class="gglo-code-cell"><?= $h($r['code']) ?></td>
                            <td><?= $h($r['label']) ?></td>
                            <td class="gglo-lock">
                                <form method="POST" style="display:inline;margin:0">
                                    <input type="hidden" name="action" value="toggle_locked">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="gglo-icon-btn" title="<?= (int)$r['is_locked'] === 1 ? 'Verrouillé (clic pour déverrouiller)' : 'Modifiable (clic pour verrouiller)' ?>">
                                        <?= (int)$r['is_locked'] === 1 ? '🔒' : '✏️' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="gglo-meta">
                                <?php if (!empty($r['entity_table'])): ?>
                                    <?= $h($r['entity_table']) ?>#<?= (int)$r['entity_id'] ?>
                                <?php else: ?>
                                    <em>libre</em>
                                <?php endif; ?>
                            </td>
                            <td class="gglo-meta"><?= $h(substr((string)$r['created_at'], 0, 16)) ?></td>
                            <td>
                                <button type="button" class="gglo-icon-btn" data-edit-row="<?= (int)$r['id'] ?>" title="Modifier">✏️</button>
                                <form method="POST" style="display:inline;margin:0">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="gglo-icon-btn danger" title="<?= $isInactive ? 'Réactiver' : 'Désactiver' ?>" onclick="return confirm('<?= $isInactive ? 'Réactiver' : 'Désactiver' ?> ce code ?');">
                                        <?= $isInactive ? '↩️' : '🚫' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <!-- Ligne en mode ÉDITION (cachée par défaut) -->
                        <tr class="gglo-row-edit" data-edit-id="<?= (int)$r['id'] ?>" style="display:none;background:#fef9e8">
                            <form method="POST" style="display:contents">
                                <input type="hidden" name="action" value="update_code">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <td><input type="text" name="code" value="<?= $h($r['code']) ?>" class="gglo-edit-input" maxlength="<?= (int)$cfg['max_len'] ?>" required></td>
                                <td><input type="text" name="label" value="<?= $h($r['label']) ?>" class="gglo-edit-input label-input" required></td>
                                <td colspan="3" style="color:#94a3b8;font-style:italic;font-size:11px">
                                    Édition de l'entrée #<?= (int)$r['id'] ?>
                                </td>
                                <td>
                                    <button type="submit" class="gglo-icon-btn save" title="Sauver">💾</button>
                                    <button type="button" class="gglo-icon-btn" data-cancel-edit="<?= (int)$r['id'] ?>" title="Annuler">✕</button>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

</div>
<script>
// Bascule mode édition inline
document.querySelectorAll('[data-edit-row]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-edit-row');
        var readRow = document.querySelector('[data-row-id="' + id + '"]');
        var editRow = document.querySelector('[data-edit-id="' + id + '"]');
        if (readRow) readRow.style.display = 'none';
        if (editRow) {
            editRow.style.display = '';
            var first = editRow.querySelector('input[type="text"]');
            if (first) first.focus();
        }
    });
});
document.querySelectorAll('[data-cancel-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-cancel-edit');
        var readRow = document.querySelector('[data-row-id="' + id + '"]');
        var editRow = document.querySelector('[data-edit-id="' + id + '"]');
        if (editRow) editRow.style.display = 'none';
        if (readRow) readRow.style.display = '';
    });
});

// Bascule form ajout
document.querySelectorAll('[data-toggle-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var cat = btn.getAttribute('data-toggle-add');
        var form = document.getElementById('fbx-add-' + cat);
        if (!form) return;
        form.classList.toggle('is-open');
        if (form.classList.contains('is-open')) {
            var first = form.querySelector('input[type="text"]');
            if (first) first.focus();
        }
    });
});
document.querySelectorAll('[data-cancel-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var cat = btn.getAttribute('data-cancel-add');
        var form = document.getElementById('fbx-add-' + cat);
        if (form) form.classList.remove('is-open');
    });
});
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/../inc/layout_maboximmo.php';
?>
