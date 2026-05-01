<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Inbox de validation Gmail-like.
 * Fichier : modules/ged/ged_inbox.php
 *
 * UX : 1 document à la fois, "Valider→suivant" instantané (optimistic UI).
 * L'upload Drive se fait en background côté serveur, l'utilisateur enchaîne sans attendre.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_login();
require_once __DIR__ . '/ged_functions.php';

$pageTitle = 'Ma GED Box — Inbox de validation';
$bodyClass = 'aged-body';

$pdo       = $GLOBALS['pdo'];
$roleId    = current_role_id();
$userId    = current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

// Compteur total + récup de l'analyse courante (la plus prioritaire à valider)
$where  = "status IN ('to_validate','manual_review')";
$params = [];
if (!$isAdmin && $societeId > 0) {
    $where .= " AND id_societe = :sid";
    $params['sid'] = $societeId;
}

// Compteur
$stCount = $pdo->prepare("SELECT COUNT(*) FROM ged_analyses WHERE {$where}");
$stCount->execute($params);
$pendingCount = (int)$stCount->fetchColumn();

// Analyse courante : par défaut la plus ancienne to_validate avec confidence la plus basse
$currentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($currentId > 0) {
    $stCur = $pdo->prepare("SELECT * FROM ged_analyses WHERE id = :id AND ({$where})");
    $params['id'] = $currentId;
    $stCur->execute($params);
} else {
    $stCur = $pdo->prepare("
        SELECT * FROM ged_analyses
        WHERE {$where}
        ORDER BY (confidence_score IS NULL) DESC,
                 confidence_score ASC,
                 created_at ASC
        LIMIT 1
    ");
    $stCur->execute($params);
}
$current = $stCur->fetch(PDO::FETCH_ASSOC);

// Position dans la file
$position = 0;
if ($current) {
    $stPos = $pdo->prepare("
        SELECT COUNT(*) FROM ged_analyses
        WHERE {$where}
          AND (
              (confidence_score IS NULL AND ged_analyses.confidence_score IS NOT NULL)
              OR (confidence_score < :curConf)
              OR (confidence_score = :curConf AND created_at < :curCreated)
          )
    ");
    // Simple count fallback : nombre déjà traités avant celui-ci
    $stPos = $pdo->prepare("SELECT COUNT(*) FROM ged_analyses WHERE id <= :id AND ({$where})");
    $params['id'] = (int)$current['id'];
    $stPos->execute($params);
    $position = (int)$stPos->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ma GED Box — Inbox</title>
<meta name="robots" content="noindex, nofollow">
<?php
// Cache busters basés sur mtime — invalide automatiquement le cache à chaque modif fichier
$assetsRoot = dirname(__DIR__, 2) . '/assets';
$vGedCss      = @filemtime($assetsRoot . '/css/ged.css')      ?: time();
$vGedInboxCss = @filemtime($assetsRoot . '/css/ged_inbox.css') ?: time();
$vGedJs       = @filemtime($assetsRoot . '/js/ged.js')        ?: time();
$vGedInboxJs  = @filemtime($assetsRoot . '/js/ged_inbox.js')  ?: time();
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/ged.css') ?>?v=<?= $vGedCss ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/ged_inbox.css') ?>?v=<?= $vGedInboxCss ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<?php
$layoutTop = __DIR__ . '/../../inc/agency_layout_top.php';
if (is_file($layoutTop)) require $layoutTop;
?>

<div class="ged-inbox-page">

    <!-- ── HEADER ── -->
    <div class="ged-inbox-header">
        <div>
            <h1>📥 Inbox de validation GED</h1>
            <div class="ged-inbox-sub">
                <strong><?= $pendingCount ?></strong> document<?= $pendingCount > 1 ? 's' : '' ?> en attente de validation
                <?php if ($current): ?>
                    · Position <strong><?= $position ?>/<?= $pendingCount ?></strong>
                <?php endif; ?>
            </div>
        </div>
        <div class="ged-inbox-actions-top">
            <a href="<?= app_url('/modules/ged/ged_dashboard.php') ?>" class="aged-btn">
                <span class="b-emoji">📊</span> Dashboard
            </a>
        </div>
    </div>

    <!-- ── DROPZONE UPLOAD ── -->
    <div class="ged-inbox-dropzone" id="ged-dropzone">
        <div class="dz-icon">📤</div>
        <div class="dz-text">
            <strong>Dépose un fichier ici</strong> (PDF, JPG, PNG) ou
            <label class="dz-link">
                <input type="file" id="ged-file-input" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
                clique pour choisir
            </label>
        </div>
        <div class="dz-progress" id="dz-progress" style="display:none">
            <div class="dz-progress-bar"><div class="dz-progress-fill" id="dz-fill"></div></div>
            <div class="dz-progress-text" id="dz-text">Analyse IA en cours…</div>
        </div>
    </div>

    <?php if (!$current): ?>

        <!-- ── FILE VIDE ── -->
        <div class="ged-inbox-empty">
            <div class="empty-icon">🎉</div>
            <h2>Boîte vide</h2>
            <p>Tous les documents sont validés. Drop un nouveau fichier ci-dessus pour ajouter à la file.</p>
        </div>

    <?php else:
        $a = $current;
        $aiRaw = !empty($a['ai_raw_response']) ? json_decode((string)$a['ai_raw_response'], true) : [];
        if (!is_array($aiRaw)) $aiRaw = [];
    ?>

        <!-- ── CARTE DOC COURANT ── -->
        <div class="ged-inbox-card" id="ged-card" data-analysis-id="<?= (int)$a['id'] ?>">

            <!-- Preview gauche -->
            <div class="ged-inbox-preview">
                <div class="preview-header">
                    <span class="preview-name"><?= htmlspecialchars($a['nom_original'] ?? $a['suggested_filename'] ?? 'Document') ?></span>
                    <span class="aged-conf <?= ($a['confidence_score'] ?? 0) >= 85 ? 'c-high' : (($a['confidence_score'] ?? 0) >= 60 ? 'c-mid' : 'c-low') ?>">
                        <?= $a['confidence_score'] !== null ? round((float)$a['confidence_score']) . '%' : '–' ?>
                    </span>
                </div>
                <?php if (!empty($a['storage_file_id']) && $a['storage_driver'] === 'local'): ?>
                    <iframe class="preview-iframe"
                            src="<?= app_url('/api/ged_inbox_preview.php') ?>?id=<?= (int)$a['id'] ?>"
                            title="Aperçu document"></iframe>
                <?php else: ?>
                    <div class="preview-placeholder">
                        <div>📄</div>
                        <p>Pas de fichier physique attaché.<br>L'analyse IA reste validable manuellement.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Form droite -->
            <div class="ged-inbox-form">
                <div class="form-section">
                    <h3>📋 Classement IA suggéré</h3>

                    <div class="ged-field">
                        <label>Type de document</label>
                        <input type="text" name="type_document" value="<?= htmlspecialchars($aiRaw['type_document'] ?? '') ?>" placeholder="FACTURE, BAIL, RELEVE_BANCAIRE…">
                    </div>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Module</label>
                            <select name="suggested_module">
                                <?php foreach (['', 'RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','ADMIN'] as $m): ?>
                                    <option value="<?= $m ?>" <?= ($a['suggested_module'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?: '—' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ged-field">
                            <label>Niveau 2</label>
                            <input type="text" name="suggested_level_2" value="<?= htmlspecialchars($a['suggested_level_2'] ?? '') ?>">
                        </div>
                        <div class="ged-field">
                            <label>Niveau 3</label>
                            <input type="text" name="suggested_level_3" value="<?= htmlspecialchars($a['suggested_level_3'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Date document</label>
                            <input type="date" name="date_document" value="<?= htmlspecialchars($a['date_document'] ?? '') ?>">
                        </div>
                        <div class="ged-field">
                            <label>Montant TTC (€)</label>
                            <input type="number" step="0.01" name="detected_montant" value="<?= htmlspecialchars($a['detected_montant'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="ged-field">
                        <label>Tiers principal</label>
                        <input type="text" name="tiers_nom" value="<?= htmlspecialchars($a['tiers_nom'] ?? $aiRaw['tiers_principal'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-section">
                    <h3>🎯 Rattachement métier (obligatoire)</h3>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Type d'objet</label>
                            <select name="objet_type" required>
                                <option value="IMB"  <?= ($a['objet_type'] ?? 'IMB') === 'IMB'  ? 'selected' : '' ?>>IMB — Immeuble</option>
                                <option value="BIEN" <?= ($a['objet_type'] ?? '')    === 'BIEN' ? 'selected' : '' ?>>BIEN — Gestion locative</option>
                                <option value="MDT"  <?= ($a['objet_type'] ?? '')    === 'MDT'  ? 'selected' : '' ?>>MDT — Mandat</option>
                                <option value="CTX"  <?= ($a['objet_type'] ?? '')    === 'CTX'  ? 'selected' : '' ?>>CTX — Contentieux</option>
                                <option value="EMP"  <?= ($a['objet_type'] ?? '')    === 'EMP'  ? 'selected' : '' ?>>EMP — RH / Salarié</option>
                                <option value="FOUR" <?= ($a['objet_type'] ?? '')    === 'FOUR' ? 'selected' : '' ?>>FOUR — Fournisseur</option>
                            </select>
                        </div>
                        <div class="ged-field">
                            <label>ID objet</label>
                            <input type="number" name="objet_id" value="<?= (int)($a['objet_id'] ?? 0) ?>" required min="1">
                        </div>
                    </div>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Réf. société</label>
                            <input type="text" name="ref_societe" value="<?= htmlspecialchars($a['ref_societe'] ?? 'RE') ?>" required>
                        </div>
                        <div class="ged-field">
                            <label>Réf. agence</label>
                            <input type="text" name="ref_agence" value="<?= htmlspecialchars($a['ref_agence'] ?? 'AGLYON') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="aged-btn b-danger" type="button" id="btn-reject">
                        <span class="b-emoji">✖</span> Rejeter
                    </button>
                    <button class="aged-btn" type="button" id="btn-skip">
                        <span class="b-emoji">⏭</span> Skip
                    </button>
                    <button class="aged-btn b-primary" type="button" id="btn-validate">
                        <span class="b-emoji">✅</span> Valider &amp; suivant
                    </button>
                </div>

                <div class="form-help">
                    Raccourcis : <kbd>V</kbd> Valider · <kbd>S</kbd> Skip · <kbd>R</kbd> Rejeter · <kbd>↵</kbd> Valider
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<script src="<?= app_url('/assets/js/ged.js') ?>?v=<?= $vGedJs ?>"></script>
<script src="<?= app_url('/assets/js/ged_inbox.js') ?>?v=<?= $vGedInboxJs ?>"></script>

<?php
$layoutBottom = __DIR__ . '/../../inc/agency_layout_bottom.php';
if (is_file($layoutBottom)) require $layoutBottom;
?>

</body>
</html>
