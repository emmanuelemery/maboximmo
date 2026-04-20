<?php
declare(strict_types=1);

/**
 * Page one-shot pour exécuter la migration TIERS sur prod (ou rejouer sur dev).
 *
 * Idempotente : peut être rejouée sans danger. Détecte ce qui est déjà fait
 * via INFORMATION_SCHEMA et capture les codes erreur "déjà appliqué"
 * (1050 table exists, 1060 duplicate column, 1061 duplicate key,
 *  1826 duplicate FK).
 *
 * Étapes :
 *   1. CREATE 5 tables tiers + ALTER ajout id_tiers sur 3 tables (phase1)
 *   2. Seed 59 codes de rôle (idempotent ON DUPLICATE KEY)
 *   3. Backfill ~956 tiers depuis proprietaires + mandants + agency_mandant
 *   4. Dédoublonnage (sépare les tiers fusionnés à tort par le backfill)
 *   5. ALTER immeubles : google_place_id + adresse_formatee
 *   6. ALTER 8 tables FK legacy + backfill id_tiers
 *
 * Accès : role_id = 1 uniquement.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = $GLOBALS['pdo'];

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

const SQL_DIR = __DIR__ . '/../sql_migrations_tiers/';

// Codes erreur MySQL considérés comme "déjà appliqué" → on log et on continue
const IDEMPOTENT_ERROR_CODES = [
    '42S01' => '1050 table déjà existante',
    '42S21' => '1060 colonne déjà existante',
    '42000' => 'duplicate key/index',
    'HY000' => 'duplicate FK ou autre erreur récupérable',
];

function read_sql_file(string $name): string
{
    $path = SQL_DIR . $name;
    if (!is_file($path)) {
        throw new RuntimeException("Fichier SQL introuvable : $name");
    }
    return (string)file_get_contents($path);
}

/**
 * Splitter un fichier SQL en statements individuels.
 * Strip commentaires (`-- ...` et `/* ... *\/`), respecte les chaînes,
 * découpe sur `;`.
 */
function split_sql_statements(string $sql): array
{
    // Enlève les commentaires
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    $statements = [];
    $cur = '';
    $inString = false;
    $stringChar = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inString) {
            $cur .= $c;
            if ($c === $stringChar && ($i === 0 || $sql[$i-1] !== '\\')) {
                $inString = false;
            }
        } elseif ($c === "'" || $c === '"') {
            $cur .= $c;
            $inString = true;
            $stringChar = $c;
        } elseif ($c === ';') {
            $stmt = trim($cur);
            if ($stmt !== '') $statements[] = $stmt;
            $cur = '';
        } else {
            $cur .= $c;
        }
    }
    $stmt = trim($cur);
    if ($stmt !== '') $statements[] = $stmt;
    return $statements;
}

/**
 * Exécute un statement SQL avec capture des erreurs idempotentes.
 * Retourne ['ok'=>bool, 'msg'=>string, 'rows'=>int|null]
 */
function exec_statement(PDO $pdo, string $sql): array
{
    try {
        $rows = $pdo->exec($sql);
        return ['ok' => true, 'msg' => 'OK', 'rows' => $rows];
    } catch (PDOException $e) {
        $code = (string)$e->getCode();
        $errInfo = $e->errorInfo ?? [];
        $mysqlCode = isset($errInfo[1]) ? (int)$errInfo[1] : 0;
        // Codes idempotents : déjà appliqué, considère comme succès
        if (in_array($mysqlCode, [1050, 1060, 1061, 1826, 1022], true)) {
            return ['ok' => true, 'msg' => "déjà appliqué (errno $mysqlCode)", 'rows' => 0];
        }
        return ['ok' => false, 'msg' => "ERREUR errno=$mysqlCode : " . $e->getMessage(), 'rows' => null];
    }
}

/**
 * Exécute tous les statements d'un fichier SQL et retourne la liste des résultats.
 */
function exec_sql_file(PDO $pdo, string $name): array
{
    $sql = read_sql_file($name);
    $statements = split_sql_statements($sql);
    $results = [];
    foreach ($statements as $i => $stmt) {
        $short = preg_replace('/\s+/', ' ', substr($stmt, 0, 100));
        $r = exec_statement($pdo, $stmt);
        $r['stmt'] = $short . (strlen($stmt) > 100 ? '...' : '');
        $r['idx'] = $i + 1;
        $results[] = $r;
        // Si erreur non récupérable, on stoppe (autres statements pourraient
        // dépendre du précédent)
        if (!$r['ok']) break;
    }
    return $results;
}

/**
 * Vérifie l'existence d'une table.
 */
function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return ((int)$st->fetchColumn()) > 0;
}

/**
 * Vérifie l'existence d'une colonne.
 */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn()) > 0;
}

function safe_count(PDO $pdo, string $sql): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable) {
        return -1;
    }
}

/**
 * État courant de la BDD vis-à-vis de la migration TIERS.
 */
function get_state(PDO $pdo): array
{
    $tables = ['tiers', 'tiers_roles', 'tiers_roles_codes', 'user_tiers', 'tiers_contacts'];
    $tableState = [];
    foreach ($tables as $t) {
        $tableState[$t] = table_exists($pdo, $t);
    }

    // 11 colonnes id_tiers à vérifier
    $colTargets = [
        'proprietaires', 'mandants', 'agency_mandant',
        'baux', 'biens', 'crg_trimestres', 'documents', 'mandats',
        'user_proprietaires', 'factures', 'reg_mandats',
    ];
    $colState = [];
    foreach ($colTargets as $t) {
        $colState[$t] = column_exists($pdo, $t, 'id_tiers');
    }

    $googlePlace = column_exists($pdo, 'immeubles', 'google_place_id');

    $counts = [
        'tiers_roles_codes' => $tableState['tiers_roles_codes'] ? safe_count($pdo, 'SELECT COUNT(*) FROM tiers_roles_codes') : -1,
        'tiers'             => $tableState['tiers'] ? safe_count($pdo, 'SELECT COUNT(*) FROM tiers') : -1,
        'tiers_roles'       => $tableState['tiers_roles'] ? safe_count($pdo, 'SELECT COUNT(*) FROM tiers_roles') : -1,
        'proprietaires_lies' => $colState['proprietaires'] ? safe_count($pdo, 'SELECT COUNT(*) FROM proprietaires WHERE id_tiers IS NOT NULL') : -1,
        'proprietaires_total' => safe_count($pdo, 'SELECT COUNT(*) FROM proprietaires'),
        'mandants_lies'     => $colState['mandants'] ? safe_count($pdo, 'SELECT COUNT(*) FROM mandants WHERE id_tiers IS NOT NULL') : -1,
        'mandants_total'    => safe_count($pdo, 'SELECT COUNT(*) FROM mandants'),
    ];

    return [
        'tables' => $tableState,
        'columns' => $colState,
        'google_place' => $googlePlace,
        'counts' => $counts,
    ];
}

// ─────────────────────────────────────────────────────────────────────
// Logique de l'étape 4 (dédoublonnage) — portage du script CLI en page web
// ─────────────────────────────────────────────────────────────────────

function dedupTable(PDO $pdo, string $table): array
{
    $logs = [];
    $roleCode = $table === 'proprietaires' ? 'proprietaire' : 'mandant';

    $st = $pdo->query("
        SELECT id_tiers, COUNT(*) AS n
        FROM `$table`
        WHERE id_tiers IS NOT NULL
        GROUP BY id_tiers
        HAVING n > 1
        ORDER BY n DESC
    ");
    $groupes = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($groupes as $g) {
        $total += (int)$g['n'] - 1;
    }
    $logs[] = ['ok' => true, 'msg' => "$table : " . count($groupes) . " groupes en doublon, $total lignes à séparer"];

    if ($total === 0) {
        $logs[] = ['ok' => true, 'msg' => "$table : rien à dédoublonner ✓"];
        return $logs;
    }

    $pdo->beginTransaction();
    try {
        if ($table === 'proprietaires') {
            $insert = $pdo->prepare("
                INSERT INTO tiers
                    (id_agence, type_tiers, civilite, nom, prenom, raison_sociale,
                     email, telephone, telephone_secondaire,
                     adresse_ligne1, adresse_ligne2, code_postal, ville, pays,
                     commentaire, notes_internes, actif,
                     date_creation, date_modification, source_creation)
                SELECT p.id_agence,
                       CASE WHEN p.type_personne='morale' THEN 'personne_morale' ELSE 'personne_physique' END,
                       p.civilite, p.nom, p.prenom, p.societe,
                       p.email, p.telephone, p.telephone_2,
                       p.adresse_1, p.adresse_2, p.code_postal, p.ville, p.pays,
                       p.commentaire, p.notes_internes, p.actif,
                       p.date_creation, p.date_modification, 'dedup_proprietaires'
                FROM proprietaires p WHERE p.id = :row_id
            ");
        } else {
            $insert = $pdo->prepare("
                INSERT INTO tiers
                    (type_tiers, civilite, nom, prenom, email, telephone,
                     adresse_ligne1, code_postal, ville, commentaire, actif, source_creation)
                SELECT 'personne_physique', m.civilite, m.nom, m.prenom, m.email, m.telephone,
                       m.adresse, m.code_postal, m.ville, m.commentaire, 1, 'dedup_mandants'
                FROM mandants m WHERE m.id = :row_id
            ");
        }
        $updateRow  = $pdo->prepare("UPDATE `$table` SET id_tiers = :new_tiers WHERE id = :row_id");
        $insertRole = $pdo->prepare("
            INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif)
            VALUES (:id_tiers, '$roleCode', NULL, NULL, :actif)
        ");

        $actifCol = $table === 'proprietaires' ? 'actif' : '1 AS actif';
        $nb = 0;
        foreach ($groupes as $g) {
            $stR = $pdo->prepare("SELECT id, $actifCol FROM `$table` WHERE id_tiers = ? ORDER BY id ASC");
            $stR->execute([$g['id_tiers']]);
            $rows = $stR->fetchAll(PDO::FETCH_ASSOC);
            array_shift($rows);
            foreach ($rows as $r) {
                $insert->execute([':row_id' => $r['id']]);
                $newId = (int)$pdo->lastInsertId();
                $updateRow->execute([':new_tiers' => $newId, ':row_id' => $r['id']]);
                $insertRole->execute([':id_tiers' => $newId, ':actif' => (int)($r['actif'] ?? 1)]);
                $nb++;
            }
        }

        // Nettoyage des doublons tiers_roles (NULL objet_type ne respecte pas UNIQUE)
        $pdo->exec("
            DELETE tr1 FROM tiers_roles tr1
            INNER JOIN tiers_roles tr2
              ON tr1.id_tiers = tr2.id_tiers
              AND tr1.role_code = tr2.role_code
              AND tr1.objet_type IS NULL AND tr2.objet_type IS NULL
              AND tr1.id > tr2.id
        ");

        $pdo->commit();
        $logs[] = ['ok' => true, 'msg' => "$table : ✓ $nb nouveaux tiers créés"];
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $logs[] = ['ok' => false, 'msg' => "$table : ✗ ERREUR " . $ex->getMessage()];
    }
    return $logs;
}

function cleanOrphans(PDO $pdo): array
{
    $logs = [];
    $sqlWhere = "
        NOT EXISTS (SELECT 1 FROM proprietaires WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM mandants WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM agency_mandant WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM tiers_roles WHERE id_tiers=t.id)
        AND NOT EXISTS (SELECT 1 FROM tiers_contacts WHERE id_tiers_entite=t.id OR id_tiers_contact=t.id)
        AND NOT EXISTS (SELECT 1 FROM user_tiers WHERE id_tiers=t.id)
    ";
    try {
        $nb = (int)$pdo->query("SELECT COUNT(*) FROM tiers t WHERE $sqlWhere")->fetchColumn();
        $logs[] = ['ok' => true, 'msg' => "Orphelins identifiés : $nb"];
        if ($nb > 0) {
            $pdo->exec("DELETE t FROM tiers t WHERE $sqlWhere");
            $logs[] = ['ok' => true, 'msg' => "✓ $nb tiers orphelins supprimés"];
        }
    } catch (Throwable $ex) {
        $logs[] = ['ok' => false, 'msg' => "ERREUR cleanOrphans : " . $ex->getMessage()];
    }
    return $logs;
}

// ─────────────────────────────────────────────────────────────────────
// Définition des étapes
// ─────────────────────────────────────────────────────────────────────

$STEPS = [
    1 => [
        'title' => 'CREATE tables TIERS + ALTER proprietaires/mandants/agency_mandant',
        'kind'  => 'sql_file',
        'file'  => 'migration_tiers_architecture_phase1.sql',
        'note'  => "Crée 5 tables (tiers, tiers_roles, tiers_roles_codes, user_tiers, tiers_contacts), ajoute id_tiers sur 3 tables existantes, et fait le backfill initial. Inclut aussi le seed des codes (mais voir étape 2 pour s'en assurer).",
    ],
    2 => [
        'title' => 'Seed des 59 codes de rôle (tiers_roles_codes)',
        'kind'  => 'sql_file',
        'file'  => 'migration_tiers_roles_seed.sql',
        'note'  => "INSERT ... ON DUPLICATE KEY → idempotent. Indispensable AVANT le backfill tiers_roles (FK).",
    ],
    3 => [
        'title' => 'Re-jouer le backfill tiers_roles (sécurité après seed)',
        'kind'  => 'sql_file',
        'file'  => 'migration_tiers_architecture_phase1.sql',
        'note'  => "Re-rejoue tout le fichier phase1 : les CREATE/ALTER déjà faits sont skippés (idempotents), le backfill tiers_roles peut maintenant insérer puisque les codes existent.",
    ],
    4 => [
        'title' => 'Dédoublonnage tiers (sépare les fusions abusives)',
        'kind'  => 'php',
        'note'  => "Le backfill mergait les tiers sans identité (champs vides). Sépare 1 tiers ↔ 1 ligne legacy.",
    ],
    5 => [
        'title' => 'ALTER immeubles : google_place_id + adresse_formatee',
        'kind'  => 'sql_file',
        'file'  => 'migration_immeubles_google_place_id.sql',
        'note'  => "Ajoute 2 colonnes à immeubles pour Google Places.",
    ],
    6 => [
        'title' => 'ALTER 8 tables FK legacy + backfill id_tiers',
        'kind'  => 'sql_file',
        'file'  => 'migration_tiers_fk_legacy.sql',
        'note'  => "Ajoute id_tiers sur baux, biens, crg_trimestres, documents, mandats, user_proprietaires, factures, reg_mandats. Backfill depuis proprietaires/mandants.",
    ],
];

// ─────────────────────────────────────────────────────────────────────
// POST handlers
// ─────────────────────────────────────────────────────────────────────

$flash = null;
$logs  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('migrate_tiers_run');
    $action = $_POST['action'] ?? '';

    if ($action === 'run_step') {
        $stepNum = (int)($_POST['step'] ?? 0);
        if (!isset($STEPS[$stepNum])) {
            $flash = ['type' => 'error', 'msg' => "Étape inconnue : $stepNum"];
        } else {
            $step = $STEPS[$stepNum];
            $logs[] = ['ok' => true, 'msg' => "▶ ÉTAPE $stepNum — " . $step['title']];

            if ($step['kind'] === 'sql_file') {
                try {
                    $results = exec_sql_file($pdo, $step['file']);
                    $okCount = 0; $errCount = 0;
                    foreach ($results as $r) {
                        $logs[] = $r;
                        if ($r['ok']) $okCount++; else $errCount++;
                    }
                    $logs[] = ['ok' => $errCount === 0, 'msg' => "→ Étape $stepNum terminée : $okCount OK, $errCount erreurs"];
                } catch (Throwable $ex) {
                    $logs[] = ['ok' => false, 'msg' => 'EXCEPTION : ' . $ex->getMessage()];
                }
            } elseif ($step['kind'] === 'php' && $stepNum === 4) {
                $logs = array_merge($logs, dedupTable($pdo, 'proprietaires'));
                $logs = array_merge($logs, dedupTable($pdo, 'mandants'));
                $logs = array_merge($logs, cleanOrphans($pdo));
            }

            $flash = ['type' => 'success', 'msg' => "Étape $stepNum exécutée — voir les logs ci-dessous"];
        }
    } elseif ($action === 'run_all') {
        foreach ($STEPS as $num => $step) {
            $logs[] = ['ok' => true, 'msg' => "▶ ÉTAPE $num — " . $step['title']];
            if ($step['kind'] === 'sql_file') {
                $results = exec_sql_file($pdo, $step['file']);
                foreach ($results as $r) $logs[] = $r;
            } elseif ($step['kind'] === 'php' && $num === 4) {
                $logs = array_merge($logs, dedupTable($pdo, 'proprietaires'));
                $logs = array_merge($logs, dedupTable($pdo, 'mandants'));
                $logs = array_merge($logs, cleanOrphans($pdo));
            }
        }
        $flash = ['type' => 'success', 'msg' => "Toutes les étapes exécutées — voir les logs ci-dessous"];
    }
}

$state = get_state($pdo);
$csrf  = csrf_token('migrate_tiers_run');

$appLayout = true;
$pageTitle = 'Migration TIERS (one-shot)';
$bodyClass = '';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
  .mt-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 20px; }
  .mt-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .mt-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; }

  .mt-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
  .mt-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .mt-flash.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
  .mt-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }

  .mt-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
  .mt-section h2 { font-size: 16px; margin: 0 0 14px; color: #0f172a; display: flex; align-items: center; gap: 10px; }

  .mt-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
  @media (max-width: 780px) { .mt-grid { grid-template-columns: 1fr; } }
  .mt-card { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; font-size: 12px; font-family: monospace; }
  .mt-kv { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px dashed #e5e7eb; }
  .mt-kv:last-child { border-bottom: 0; }
  .mt-kv .k { color: #64748b; }
  .mt-kv .v { color: #0f172a; font-weight: 600; }

  .mt-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .mt-badge.ok    { background: #dcfce7; color: #166534; }
  .mt-badge.miss  { background: #fee2e2; color: #991b1b; }
  .mt-badge.warn  { background: #fef3c7; color: #92400e; }

  .mt-step { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; background: #fff; }
  .mt-step h3 { margin: 0 0 4px; font-size: 14px; color: #0f172a; }
  .mt-step p  { margin: 0 0 10px; color: #64748b; font-size: 12px; }

  .mt-btn { padding: 8px 14px; border-radius: 8px; background: #0ea5e9; color: #fff; border: none; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .mt-btn:hover { background: #0284c7; }
  .mt-btn.warn { background: #f59e0b; }
  .mt-btn.warn:hover { background: #d97706; }
  .mt-btn.danger { background: #dc2626; }
  .mt-btn.danger:hover { background: #b91c1c; }

  .mt-logs { background: #0f172a; color: #f1f5f9; border-radius: 10px; padding: 16px 18px; font-family: monospace; font-size: 12px; max-height: 600px; overflow-y: auto; }
  .mt-logs .ok  { color: #86efac; }
  .mt-logs .err { color: #fca5a5; }
  .mt-logs .hdr { color: #fde047; margin-top: 6px; font-weight: 700; }
  .mt-logs .stmt { color: #93c5fd; font-style: italic; }
</style>

<div class="mt-wrap">
  <h1>🔧 Migration TIERS (one-shot)</h1>
  <p class="sub">Page d'exécution sécurisée des 6 étapes SQL/PHP de la migration TIERS Phase 1. Idempotente — tu peux rejouer sans danger.</p>

  <?php if ($flash): ?>
    <div class="mt-flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <!-- ÉTAT COURANT -->
  <div class="mt-section">
    <h2>📊 État courant de la BDD</h2>
    <div class="mt-grid">
      <div class="mt-card">
        <strong style="display:block; margin-bottom:8px; color:#0f172a;">Tables TIERS</strong>
        <?php foreach ($state['tables'] as $t => $exists): ?>
          <div class="mt-kv">
            <span class="k"><?= htmlspecialchars($t) ?></span>
            <span class="v"><?= $exists ? '<span class="mt-badge ok">PRÉSENTE</span>' : '<span class="mt-badge miss">MANQUE</span>' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-card">
        <strong style="display:block; margin-bottom:8px; color:#0f172a;">Colonnes id_tiers</strong>
        <?php foreach ($state['columns'] as $t => $exists): ?>
          <div class="mt-kv">
            <span class="k"><?= htmlspecialchars($t) ?>.id_tiers</span>
            <span class="v"><?= $exists ? '<span class="mt-badge ok">OK</span>' : '<span class="mt-badge miss">MANQUE</span>' ?></span>
          </div>
        <?php endforeach; ?>
        <div class="mt-kv">
          <span class="k">immeubles.google_place_id</span>
          <span class="v"><?= $state['google_place'] ? '<span class="mt-badge ok">OK</span>' : '<span class="mt-badge miss">MANQUE</span>' ?></span>
        </div>
      </div>
    </div>

    <div class="mt-grid" style="margin-top:14px;">
      <div class="mt-card">
        <strong style="display:block; margin-bottom:8px; color:#0f172a;">Données</strong>
        <div class="mt-kv"><span class="k">tiers_roles_codes</span><span class="v"><?= $state['counts']['tiers_roles_codes'] ?> / 59</span></div>
        <div class="mt-kv"><span class="k">tiers</span><span class="v"><?= $state['counts']['tiers'] ?></span></div>
        <div class="mt-kv"><span class="k">tiers_roles</span><span class="v"><?= $state['counts']['tiers_roles'] ?></span></div>
      </div>
      <div class="mt-card">
        <strong style="display:block; margin-bottom:8px; color:#0f172a;">Liaisons legacy</strong>
        <div class="mt-kv"><span class="k">proprietaires liés</span><span class="v"><?= $state['counts']['proprietaires_lies'] ?> / <?= $state['counts']['proprietaires_total'] ?></span></div>
        <div class="mt-kv"><span class="k">mandants liés</span><span class="v"><?= $state['counts']['mandants_lies'] ?> / <?= $state['counts']['mandants_total'] ?></span></div>
      </div>
    </div>
  </div>

  <!-- ÉTAPES -->
  <div class="mt-section">
    <h2>⚙️ Étapes de migration</h2>

    <?php foreach ($STEPS as $num => $step): ?>
      <div class="mt-step">
        <h3>Étape <?= $num ?> — <?= htmlspecialchars($step['title']) ?></h3>
        <p><?= htmlspecialchars($step['note']) ?></p>
        <form method="post" style="margin:0;" onsubmit="return confirm('Lancer l\'étape <?= $num ?> ?');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="run_step">
          <input type="hidden" name="step" value="<?= $num ?>">
          <button type="submit" class="mt-btn">▶ Lancer étape <?= $num ?></button>
        </form>
      </div>
    <?php endforeach; ?>

    <div class="mt-step" style="border-color:#fbbf24; background:#fffbeb;">
      <h3>🚀 Tout exécuter en 1 clic (étapes 1 → 6 dans l'ordre)</h3>
      <p>Pour quand tu es sûr du backup. Lance les 6 étapes sans interruption.</p>
      <form method="post" style="margin:0;" onsubmit="return confirm('⚠️ Confirme-tu avoir fait le backup BDD ?\n\nTu vas lancer les 6 étapes en chaîne.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="run_all">
        <button type="submit" class="mt-btn warn">🚀 LANCER LES 6 ÉTAPES</button>
      </form>
    </div>
  </div>

  <!-- LOGS -->
  <?php if (!empty($logs)): ?>
    <div class="mt-section">
      <h2>📜 Logs d'exécution</h2>
      <div class="mt-logs">
        <?php foreach ($logs as $log):
          $cls = isset($log['ok']) && !$log['ok'] ? 'err' : 'ok';
          $isHdr = strpos($log['msg'], '▶') === 0;
          if ($isHdr) $cls = 'hdr';
        ?>
          <div class="<?= $cls ?>">
            <?php if (!empty($log['stmt'])): ?>
              [<?= $log['idx'] ?? '?' ?>] <span class="stmt"><?= htmlspecialchars($log['stmt']) ?></span> → <?= htmlspecialchars($log['msg']) ?><?= isset($log['rows']) && $log['rows'] !== null ? " ({$log['rows']} lignes)" : '' ?>
            <?php else: ?>
              <?= htmlspecialchars($log['msg']) ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
