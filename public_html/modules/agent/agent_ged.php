<?php
declare(strict_types=1);

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Agent GED MaBoxImmo — Page principale
 * Fichier : modules/agent/agent_ged.php
 *
 * Affiche :
 *   - 5 cards statistiques (total / à valider / validés / rejetés / confiance moy.)
 *   - Filtres (statut, module, recherche)
 *   - Tableau des analyses IA avec :
 *       - document
 *       - module proposé (badge coloré)
 *       - niveau 2 / 3
 *       - immeuble détecté
 *       - score de confiance (badge couleur)
 *       - statut
 *       - boutons d'action (Voir / Relancer OCR / OCR premium / Analyser IA / Valider / Modifier)
 *
 * Sécurité :
 *   - require_login() obligatoire
 *   - Filtrage multi-tenant côté SQL (id_societe / id_agence)
 *   - Tous les boutons appellent agent_ged_action.php (handler) avec token CSRF
 * ───────────────────────────────────────────────────────────────────────────── */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_login();

// Inclut les fonctions métier (dans /modules/ged/)
require_once __DIR__ . '/../ged/agent_functions.php';

$pageTitle  = 'Agent GED — Analyse IA des documents';
$bodyClass  = 'aged-body';
$robots     = 'noindex, nofollow';
$appLayout  = true;

$pdo       = $GLOBALS['pdo'] ?? null;
$roleId    = current_role_id();
$userId    = current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

// ── Filtres GET ─────────────────────────────────────────────────────────────
$filterStatus = isset($_GET['status']) ? (string)$_GET['status'] : '';
$filterModule = isset($_GET['module']) ? (string)$_GET['module'] : '';
$filterSearch = isset($_GET['q'])      ? trim((string)$_GET['q']) : '';

$validStatuses = ['', 'to_validate', 'validated', 'rejected', 'manual_review'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}
$validModules = ['', 'RH', 'COMPTA', 'BAILLEUR', 'SYNDIC', 'AGENCE', 'FOURNISSEURS', 'ADMIN'];
if (!in_array($filterModule, $validModules, true)) {
    $filterModule = '';
}

// ── Données ─────────────────────────────────────────────────────────────────
try {
    $stats   = agentQueueStats();
    $byModule = agentStatsByModule();
    $rows    = listAgentQueue([
        'status' => $filterStatus,
        'module' => $filterModule,
        'search' => $filterSearch,
    ], 200);
    $loadError = null;
} catch (Throwable $e) {
    // Si la table n'existe pas encore (migration pas appliquée), on l'indique clairement.
    $stats    = ['total' => 0, 'to_validate' => 0, 'validated' => 0, 'rejected' => 0, 'manual' => 0, 'avg_conf' => 0];
    $byModule = [];
    $rows     = [];
    $loadError = $e->getMessage();
}

// ── Helpers d'affichage ─────────────────────────────────────────────────────

/**
 * Classe CSS de la pastille de confiance selon le score.
 */
function aged_confClass(?float $score): string
{
    if ($score === null) return 'c-na';
    if ($score >= 85) return 'c-high';
    if ($score >= 60) return 'c-mid';
    return 'c-low';
}

/**
 * Slug d'un module pour utiliser comme classe CSS (RH→rh, COMPTA→compta, etc.).
 */
function aged_modSlug(?string $mod): string
{
    return strtolower((string)$mod);
}

/**
 * Slug d'un statut pour classe CSS (to_validate → to-validate).
 */
function aged_statSlug(?string $status): string
{
    return str_replace('_', '-', (string)$status);
}

/**
 * Échappe + tronque pour affichage dans une cellule.
 */
function aged_h(?string $s, int $max = 80): string
{
    if ($s === null || $s === '') return '<span style="color:#9ca3af">—</span>';
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    if (mb_strlen($s) > $max) {
        return mb_substr($s, 0, $max - 1) . '…';
    }
    return $s;
}

// ── Rendu ───────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agent GED — MaBoxImmo</title>
<meta name="robots" content="<?= htmlspecialchars($robots) ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/agent_ged.css') ?>?v=1">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<?php
// On utilise le layout MaBoxImmo (sidebar + topbar). Si non disponible, on rend
// la page seule (mode standalone pour tests).
$layoutTop = __DIR__ . '/../../inc/agency_layout_top.php';
if (is_file($layoutTop)) {
    require $layoutTop;
}
?>

<div class="aged-page">

    <!-- ── HEADER ── -->
    <div class="aged-header">
        <div>
            <h1>🤖 Agent GED — Analyse IA des documents</h1>
            <div class="aged-sub">
                Classement automatique IA + validation humaine.
                Confiance moyenne : <strong><?= htmlspecialchars((string)$stats['avg_conf']) ?>%</strong>
            </div>
        </div>
    </div>

    <?php if ($loadError !== null): ?>
        <div class="aged-stat-card s-bad" style="margin-bottom:16px">
            <div class="aged-stat-label">⚠ Erreur de chargement</div>
            <div style="font-size:13px;color:#991b1b">
                <?= htmlspecialchars($loadError) ?>
            </div>
            <div class="aged-stat-sub" style="margin-top:6px">
                Vérifie que la table <code>agent_ged_analyses</code> existe.
                Applique <code>sql/agent_ged/001_create_agent_ged_analyses.sql</code>.
            </div>
        </div>
    <?php endif; ?>

    <!-- ── CARDS STATS ── -->
    <div class="aged-stats">
        <div class="aged-stat-card s-info">
            <div class="aged-stat-label">Total analyses</div>
            <div class="aged-stat-value"><?= (int)$stats['total'] ?></div>
        </div>
        <div class="aged-stat-card s-warn">
            <div class="aged-stat-label">À valider</div>
            <div class="aged-stat-value"><?= (int)$stats['to_validate'] ?></div>
            <div class="aged-stat-sub">en attente humaine</div>
        </div>
        <div class="aged-stat-card s-ok">
            <div class="aged-stat-label">Validés</div>
            <div class="aged-stat-value"><?= (int)$stats['validated'] ?></div>
        </div>
        <div class="aged-stat-card s-bad">
            <div class="aged-stat-label">Rejetés</div>
            <div class="aged-stat-value"><?= (int)$stats['rejected'] ?></div>
        </div>
        <div class="aged-stat-card s-info">
            <div class="aged-stat-label">Revue manuelle</div>
            <div class="aged-stat-value"><?= (int)$stats['manual'] ?></div>
            <div class="aged-stat-sub">classement à refaire</div>
        </div>
    </div>

    <!-- ── FILTRES ── -->
    <form class="aged-filters" method="get" action="">
        <label for="aged-f-status">Statut</label>
        <select id="aged-f-status" name="status">
            <option value="">Tous</option>
            <option value="to_validate"   <?= $filterStatus === 'to_validate'   ? 'selected' : '' ?>>À valider</option>
            <option value="validated"     <?= $filterStatus === 'validated'     ? 'selected' : '' ?>>Validés</option>
            <option value="rejected"      <?= $filterStatus === 'rejected'      ? 'selected' : '' ?>>Rejetés</option>
            <option value="manual_review" <?= $filterStatus === 'manual_review' ? 'selected' : '' ?>>Revue manuelle</option>
        </select>

        <label for="aged-f-module">Module</label>
        <select id="aged-f-module" name="module">
            <option value="">Tous</option>
            <?php foreach (['RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','ADMIN'] as $m): ?>
                <option value="<?= $m ?>" <?= $filterModule === $m ? 'selected' : '' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>

        <label for="aged-f-search">Recherche</label>
        <input type="text" id="aged-f-search" name="q" placeholder="immeuble, fournisseur, fichier..."
               value="<?= htmlspecialchars($filterSearch) ?>">

        <button type="submit" class="aged-btn b-primary">Filtrer</button>
        <?php if ($filterStatus !== '' || $filterModule !== '' || $filterSearch !== ''): ?>
            <a class="aged-btn" href="<?= app_url('/modules/agent/agent_ged.php') ?>">Réinitialiser</a>
        <?php endif; ?>
    </form>

    <!-- ── TABLE QUEUE ── -->
    <div class="aged-queue-wrap">
        <table class="aged-table" id="aged-queue-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Module</th>
                    <th>Niveau 2 / 3</th>
                    <th>Immeuble détecté</th>
                    <th>Action proposée</th>
                    <th>Confiance</th>
                    <th>Statut</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" class="aged-empty">
                            <div class="em-icon">📭</div>
                            <?php if ($loadError !== null): ?>
                                Table non disponible — applique la migration <code>sql/agent_ged/001_*.sql</code>
                            <?php elseif ($filterStatus !== '' || $filterModule !== '' || $filterSearch !== ''): ?>
                                Aucune analyse ne correspond aux filtres.
                            <?php else: ?>
                                Aucune analyse pour le moment.<br>
                                Lance une analyse depuis la page d'un document avec le bouton "Analyser avec IA".
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: foreach ($rows as $r):
                    $conf = $r['confidence_score'] !== null ? (float)$r['confidence_score'] : null;
                    $confDisplay = $conf !== null ? round($conf) . '%' : '–';
                ?>
                    <tr data-analysis-id="<?= (int)$r['id'] ?>" data-doc-id="<?= (int)($r['document_id'] ?? 0) ?>">
                        <td class="aged-cell-doc">
                            <?= aged_h($r['suggested_filename'] ?: ('Document #' . (int)($r['document_id'] ?? 0)), 60) ?>
                            <small>
                                <?= aged_h((string)($r['document_table'] ?? '—'), 30) ?> ·
                                <?= aged_h((string)($r['ia_engine']      ?? '—'), 30) ?>
                            </small>
                        </td>
                        <td>
                            <?php if (!empty($r['suggested_module'])): ?>
                                <span class="aged-mod m-<?= aged_modSlug($r['suggested_module']) ?>">
                                    <?= htmlspecialchars((string)$r['suggested_module']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#9ca3af">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="aged-cell-meta">
                            <?= aged_h($r['suggested_level_2'], 30) ?>
                            <?php if (!empty($r['suggested_level_3'])): ?>
                                <br><small><?= aged_h($r['suggested_level_3'], 30) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="aged-cell-meta">
                            <?= aged_h($r['detected_immeuble'], 40) ?>
                            <?php if (!empty($r['detected_montant'])): ?>
                                <br><small><?= number_format((float)$r['detected_montant'], 2, ',', ' ') ?> €</small>
                            <?php endif; ?>
                        </td>
                        <td class="aged-cell-meta" style="max-width:240px"><?= aged_h($r['suggested_action'], 80) ?></td>
                        <td>
                            <span class="aged-conf <?= aged_confClass($conf) ?>"><?= $confDisplay ?></span>
                        </td>
                        <td>
                            <span class="aged-status s-<?= aged_statSlug($r['status']) ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', (string)$r['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="aged-actions">
                                <?php if (!empty($r['document_id'])): ?>
                                    <button class="aged-btn" type="button" title="Voir document"
                                            onclick="agedView(<?= (int)$r['document_id'] ?>, '<?= htmlspecialchars((string)($r['document_table'] ?? ''), ENT_QUOTES) ?>')">
                                        <span class="b-emoji">👁</span>
                                    </button>
                                <?php endif; ?>
                                <button class="aged-btn" type="button" title="Relancer OCR (gratuit)"
                                        onclick="agedAction(this, 'ocr_free', <?= (int)$r['id'] ?>)">
                                    <span class="b-emoji">🔁</span>
                                </button>
                                <button class="aged-btn" type="button" title="OCR premium (Mindee)"
                                        onclick="agedAction(this, 'ocr_premium', <?= (int)$r['id'] ?>)">
                                    <span class="b-emoji">⚡</span>
                                </button>
                                <button class="aged-btn" type="button" title="Re-analyser via IA"
                                        onclick="agedAction(this, 'reanalyze', <?= (int)$r['id'] ?>)">
                                    <span class="b-emoji">🧠</span>
                                </button>
                                <?php if ($r['status'] === 'to_validate' || $r['status'] === 'manual_review'): ?>
                                    <button class="aged-btn b-primary" type="button" title="Valider l'analyse"
                                            onclick="agedAction(this, 'validate', <?= (int)$r['id'] ?>)">
                                        <span class="b-emoji">✅</span> Valider
                                    </button>
                                    <button class="aged-btn b-danger" type="button" title="Rejeter"
                                            onclick="agedAction(this, 'reject', <?= (int)$r['id'] ?>)">
                                        <span class="b-emoji">✖</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script src="<?= app_url('/assets/js/agent_ged.js') ?>?v=1"></script>

<?php
$layoutBottom = __DIR__ . '/../../inc/agency_layout_bottom.php';
if (is_file($layoutBottom)) {
    require $layoutBottom;
}
?>

</body>
</html>
