<?php
/**
 * admin/admin_sync_proprietaires_tiers_apply.php
 *
 * Sprint 2B Phase 2 — Synchronisation ASSISTÉE proprietaires ↔ tiers.
 *
 * AUTORISATIONS STRICTES (validées user 2026-05-23) :
 *   - UPDATE proprietaires.id_tiers = 1838 WHERE id = 335
 *   - INSERT INTO tiers_roles (rôle proprietaire OU bailleur sur le tiers 1838)
 *     UNIQUEMENT si absent
 *
 * INTERDIT :
 *   - Aucune création de nouveau tiers
 *   - Aucune suppression
 *   - Aucun UPDATE destructif (uniquement set d'id_tiers s'il était NULL)
 *
 * Workflow :
 *   1. GET (par défaut)      → AUDIT (snapshot avant + plan d'action)
 *   2. POST avec confirm     → APPLY (UPDATE + INSERT idempotent dans transaction)
 *
 * Idempotent : on peut relancer sans risque.
 * Rapport avant/après affiché côte à côte.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès super admin uniquement.');
}

header('Content-Type: text/html; charset=utf-8');
$pdo = $GLOBALS['pdo'];

// ─── PARAMÈTRES FIGÉS (autorisés explicitement) ─────────────────────
const TARGET_PROPRIO_ID = 335;
const TARGET_TIERS_ID   = 1838;
const ALLOWED_ROLES     = ['proprietaire', 'bailleur'];   // un de ces 2 suffit
const DEFAULT_ROLE      = 'proprietaire';                  // si aucun présent → on crée celui-ci

$mode    = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === 'YES')
           ? 'apply' : 'audit';
$report  = ['mode' => $mode, 'cible' => ['proprio_id' => TARGET_PROPRIO_ID, 'tiers_id' => TARGET_TIERS_ID]];

// ═══════════════════════════════════════════════════════════════════
// PHASE 1 : SNAPSHOT AVANT (toujours exécuté)
// ═══════════════════════════════════════════════════════════════════
$before = ['proprio' => null, 'tiers' => null, 'roles_actifs_du_tiers' => []];

try {
    $st = $pdo->prepare("SELECT id, id_tiers, nom, prenom, societe, email, telephone, actif
                         FROM proprietaires WHERE id = ? LIMIT 1");
    $st->execute([TARGET_PROPRIO_ID]);
    $before['proprio'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $report['ERREUR_lecture_proprio'] = $e->getMessage(); }

try {
    $st = $pdo->prepare("SELECT id, type_tiers, nom, prenom, raison_sociale, email, telephone, actif
                         FROM tiers WHERE id = ? LIMIT 1");
    $st->execute([TARGET_TIERS_ID]);
    $before['tiers'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $report['ERREUR_lecture_tiers'] = $e->getMessage(); }

try {
    $st = $pdo->prepare("SELECT id, role_code, objet_type, id_objet, date_debut, date_fin, actif
                         FROM tiers_roles WHERE id_tiers = ? ORDER BY id DESC");
    $st->execute([TARGET_TIERS_ID]);
    $before['roles_actifs_du_tiers'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $report['ERREUR_lecture_roles'] = $e->getMessage(); }

$report['before'] = $before;

// ═══════════════════════════════════════════════════════════════════
// PRÉ-CHECKS de sécurité
// ═══════════════════════════════════════════════════════════════════
$blockers = [];

if (!$before['proprio']) {
    $blockers[] = "Propriétaire #" . TARGET_PROPRIO_ID . " introuvable en BDD.";
} else {
    if ((int)$before['proprio']['actif'] !== 1) {
        $blockers[] = "Propriétaire #" . TARGET_PROPRIO_ID . " est INACTIF (actif=0). On ne migre pas un inactif.";
    }
    if (!empty($before['proprio']['id_tiers']) && (int)$before['proprio']['id_tiers'] !== TARGET_TIERS_ID) {
        $blockers[] = "Propriétaire #" . TARGET_PROPRIO_ID . " a déjà un id_tiers ≠ cible : "
                    . (int)$before['proprio']['id_tiers'] . " ≠ " . TARGET_TIERS_ID . ". UPDATE BLOQUÉ pour éviter destruction.";
    }
}

if (!$before['tiers']) {
    $blockers[] = "Tiers #" . TARGET_TIERS_ID . " introuvable en BDD.";
} else {
    if ((int)$before['tiers']['actif'] !== 1) {
        $blockers[] = "Tiers #" . TARGET_TIERS_ID . " est INACTIF (actif=0). On ne lie pas à un inactif.";
    }
}

$report['pre_checks_blockers'] = $blockers;

// ═══════════════════════════════════════════════════════════════════
// PLAN D'ACTION (calculé toujours, exécuté seulement en mode apply)
// ═══════════════════════════════════════════════════════════════════
$plan = [];

// Action 1 : lier proprio → tiers (si pas déjà lié)
if ($before['proprio'] && empty($before['proprio']['id_tiers'])) {
    $plan[] = [
        'action' => 'UPDATE proprietaires SET id_tiers = ' . TARGET_TIERS_ID . ' WHERE id = ' . TARGET_PROPRIO_ID,
        'effet'  => 'Lien proprio→tiers créé',
    ];
} elseif ($before['proprio'] && (int)$before['proprio']['id_tiers'] === TARGET_TIERS_ID) {
    $plan[] = ['action' => '(skip) proprio.id_tiers déjà = ' . TARGET_TIERS_ID, 'effet' => 'idempotent — rien à faire'];
}

// Action 2 : ajouter rôle dans tiers_roles s'il manque
$hasAllowedRoleActive = false;
foreach ($before['roles_actifs_du_tiers'] as $r) {
    if ((int)$r['actif'] === 1 && in_array(strtolower((string)$r['role_code']), ALLOWED_ROLES, true)) {
        $hasAllowedRoleActive = true;
        break;
    }
}
if (!$hasAllowedRoleActive) {
    $plan[] = [
        'action' => "INSERT INTO tiers_roles (id_tiers, role_code, actif) VALUES ("
                  . TARGET_TIERS_ID . ", '" . DEFAULT_ROLE . "', 1)",
        'effet'  => "Rôle '" . DEFAULT_ROLE . "' ajouté au tiers (aucun des rôles autorisés n'était actif)",
    ];
} else {
    $plan[] = ['action' => '(skip) un rôle parmi ' . implode(',', ALLOWED_ROLES) . ' est déjà actif',
               'effet'  => 'idempotent — rien à faire'];
}

$report['plan'] = $plan;

// ═══════════════════════════════════════════════════════════════════
// PHASE 2 : APPLY (seulement si mode=apply ET pas de blocker)
// ═══════════════════════════════════════════════════════════════════
$applied = [];
$report['executed'] = ($mode === 'apply' && empty($blockers));

if ($mode === 'apply' && !empty($blockers)) {
    $report['error'] = 'APPLY bloqué par pré-checks — voir blockers ci-dessus.';
} elseif ($mode === 'apply') {
    try {
        $pdo->beginTransaction();

        // Action 1 : UPDATE proprietaires.id_tiers si NULL
        if ($before['proprio'] && empty($before['proprio']['id_tiers'])) {
            $st = $pdo->prepare("UPDATE proprietaires SET id_tiers = :tid WHERE id = :pid AND id_tiers IS NULL");
            $st->execute([':tid' => TARGET_TIERS_ID, ':pid' => TARGET_PROPRIO_ID]);
            $applied[] = ['action' => 'UPDATE proprietaires.id_tiers', 'rows_affected' => $st->rowCount()];
        }

        // Action 2 : INSERT tiers_roles si rôle absent (recheck dans la transaction)
        $stChk = $pdo->prepare("SELECT COUNT(*) FROM tiers_roles
                                WHERE id_tiers = :tid AND actif = 1
                                  AND LOWER(role_code) IN ('" . implode("','", ALLOWED_ROLES) . "')");
        $stChk->execute([':tid' => TARGET_TIERS_ID]);
        $alreadyHasRole = (int)$stChk->fetchColumn() > 0;

        if (!$alreadyHasRole) {
            $stIns = $pdo->prepare("INSERT INTO tiers_roles (id_tiers, role_code, actif)
                                    VALUES (:tid, :role, 1)");
            $stIns->execute([':tid' => TARGET_TIERS_ID, ':role' => DEFAULT_ROLE]);
            $applied[] = ['action' => "INSERT tiers_roles role={" . DEFAULT_ROLE . "}",
                          'new_id' => (int)$pdo->lastInsertId()];
        } else {
            $applied[] = ['action' => '(skip) rôle ' . DEFAULT_ROLE . ' (ou bailleur) déjà actif'];
        }

        $pdo->commit();
        error_log(sprintf(
            '[sync_proprio_tiers_apply] OK user=%d proprio=%d tiers=%d actions=%s',
            (int)($_SESSION['user_id'] ?? 0),
            TARGET_PROPRIO_ID, TARGET_TIERS_ID,
            json_encode($applied)
        ));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $report['error'] = 'APPLY échec, rollback : ' . $e->getMessage();
        $applied[] = ['error' => $e->getMessage()];
    }
}
$report['applied'] = $applied;

// ═══════════════════════════════════════════════════════════════════
// PHASE 3 : SNAPSHOT APRÈS (seulement si apply exécuté)
// ═══════════════════════════════════════════════════════════════════
$after = null;
if ($mode === 'apply' && empty($blockers) && empty($report['error'])) {
    $after = ['proprio' => null, 'tiers' => null, 'roles_actifs_du_tiers' => []];
    try {
        $st = $pdo->prepare("SELECT id, id_tiers, nom, prenom, societe, actif FROM proprietaires WHERE id = ?");
        $st->execute([TARGET_PROPRIO_ID]);
        $after['proprio'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        $st = $pdo->prepare("SELECT id, role_code, actif, date_debut FROM tiers_roles WHERE id_tiers = ? ORDER BY id DESC");
        $st->execute([TARGET_TIERS_ID]);
        $after['roles_actifs_du_tiers'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $after['error'] = $e->getMessage(); }
}
$report['after'] = $after;

$canApply = ($mode === 'audit' && empty($blockers));
$applyDone = ($mode === 'apply' && empty($blockers) && empty($report['error']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Sync proprio→tiers APPLY — Sprint 2B</title>
    <style>
        body { font-family: "DM Mono", monospace; background: #0f172a; color: #f1f5f9; padding: 24px; max-width: 1100px; margin: 0 auto; }
        h1 { color: #fde68a; margin: 0 0 8px; font-size: 22px; }
        h2 { color: #84a98c; font-size: 14px; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #334155; }
        pre { background: #1e293b; padding: 10px 14px; border-radius: 6px; overflow-x: auto; font-size: 11.5px; line-height: 1.5; }
        .ok { color: #84a98c; font-weight: 700; }
        .ko { color: #f87171; font-weight: 700; }
        .warn { color: #fde68a; }
        .alert { padding: 14px 18px; border-radius: 8px; margin: 16px 0; }
        .alert.ok    { background: rgba(132,169,140,0.15); border-left: 4px solid #84a98c; }
        .alert.warn  { background: rgba(253,230,138,0.15); border-left: 4px solid #fde68a; }
        .alert.ko    { background: rgba(248,113,113,0.15); border-left: 4px solid #f87171; }
        button.apply { background: #b91c1c; color: #fff; border: none; padding: 14px 28px; border-radius: 8px; font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer; box-shadow: 2px 2px 6px rgba(185,28,28,0.4); }
        button.apply:hover { background: #991b1b; }
        a.next { display: inline-block; padding: 10px 18px; background: #0e7490; color: #fff; border-radius: 6px; text-decoration: none; font-weight: 700; margin-top: 10px; }
    </style>
</head>
<body>

<h1>🔄 Sync proprietaire #<?= TARGET_PROPRIO_ID ?> → tiers #<?= TARGET_TIERS_ID ?> — Sprint 2B</h1>
<p>Action <strong>strictement limitée</strong> et idempotente. Aucune création de tiers, aucune suppression.</p>

<h2>📋 Mode : <?= $mode === 'apply' ? '🟢 APPLY (exécuté)' : '🟡 AUDIT (lecture seule + plan)' ?></h2>

<?php if (!empty($blockers)): ?>
    <div class="alert ko">
        <strong class="ko">❌ Blockers de sécurité — APPLY refusé :</strong>
        <ul><?php foreach ($blockers as $b) echo '<li>' . htmlspecialchars($b) . '</li>'; ?></ul>
    </div>
<?php endif; ?>

<h2>1. Avant (snapshot)</h2>
<pre><?= htmlspecialchars(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

<h2>2. Plan d'action calculé</h2>
<pre><?= htmlspecialchars(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

<?php if ($mode === 'apply'): ?>
<h2>3. Exécution</h2>
<pre><?= htmlspecialchars(json_encode($applied, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

<?php if ($applyDone): ?>
    <h2>4. Après (snapshot)</h2>
    <pre><?= htmlspecialchars(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <div class="alert ok">
        <strong class="ok">✅ Synchronisation terminée.</strong>
        <p>Prochaine étape : relancer l'audit pour confirmer 20/20.</p>
        <a class="next" href="admin_sync_proprietaires_tiers.php">↻ Relancer l'audit</a>
    </div>
<?php elseif (isset($report['error'])): ?>
    <div class="alert ko"><strong class="ko">❌ Erreur :</strong> <?= htmlspecialchars($report['error']) ?></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($canApply): ?>
    <h2>3. Confirmation requise</h2>
    <div class="alert warn">
        <strong>⚠️ Tu es sur le point de :</strong>
        <ul>
            <?php foreach ($plan as $p) echo '<li>' . htmlspecialchars($p['action'] . ' — ' . $p['effet']) . '</li>'; ?>
        </ul>
        <p>Tout est exécuté en transaction (rollback si erreur). Idempotent (relançable sans risque).</p>
        <form method="POST" action="">
            <input type="hidden" name="confirm" value="YES">
            <button type="submit" class="apply">🚀 Appliquer maintenant</button>
        </form>
    </div>
<?php endif; ?>

<h2>📌 Garanties</h2>
<ul style="font-size: 12px; color: #94a3b8;">
    <li>Cible HARD-CODÉE : proprio <?= TARGET_PROPRIO_ID ?> → tiers <?= TARGET_TIERS_ID ?>. Pas modifiable par URL/POST.</li>
    <li>UPDATE de proprietaires.id_tiers UNIQUEMENT si id_tiers IS NULL (jamais d'écrasement).</li>
    <li>INSERT tiers_roles UNIQUEMENT si aucun rôle (<?= implode(', ', ALLOWED_ROLES) ?>) déjà actif.</li>
    <li>Aucune création de tiers, aucune suppression, aucun UPDATE destructif.</li>
    <li>Transaction PDO + log error_log + rollback si erreur.</li>
    <li>Production OFF — page accessible uniquement super admin role=1.</li>
</ul>

</body>
</html>
