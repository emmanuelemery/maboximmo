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
require_once __DIR__ . '/ged_functions_legacy.php'; // archivé Phase 1.1 — fusion Phase 2/3

$pageTitle = 'Ma GED Box — Inbox de validation';
$bodyClass = 'aged-body';

$pdo       = $GLOBALS['pdo'];
$roleId    = current_role_id();
$userId    = current_user_id();
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$isAdmin   = in_array($roleId, [1, 7, 8], true);

// Établissements (pour presets ref_agence)
$etabs = [];
try {
    $etabs = $pdo->query("SELECT id, nom, sigle FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $etabs = [];
}

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

// Liste courte à gauche (navigation rapide) — 150px
$stList = $pdo->prepare("
    SELECT id, nom_original, suggested_filename, confidence_score, ia_engine, created_at
    FROM ged_analyses
    WHERE {$where}
    ORDER BY created_at ASC
    LIMIT 50
");
$stList->execute(array_diff_key($params, ['id' => true])); // enlève l'id si présent
$queueList = $stList->fetchAll(PDO::FETCH_ASSOC);

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
            <a href="<?= app_url('/modules/ged/import_releves_banque_zip.php') ?>" class="aged-btn">
                <span class="b-emoji">🏦</span> Import ZIP relevés banque
            </a>
        </div>
    </div>

    <!-- ── DROPZONE UPLOAD ── -->
    <div class="ged-inbox-dropzone" id="ged-dropzone">
        <div class="dz-icon">📤</div>
        <div class="dz-text">
            <div style="display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap">
                <div>
                    <strong>Dépose un fichier ici</strong> (PDF, JPG, PNG) ou
                    <label class="dz-link">
                        <input type="file" id="ged-file-input" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple style="display:none">
                        clique pour choisir des fichiers
                    </label>
                    <span style="opacity:.7">·</span>
                    <label class="dz-link">
                        <input type="file" id="ged-folder-input" webkitdirectory directory multiple style="display:none">
                        choisir un dossier
                    </label>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <label style="font-size:12px;color:#4b5563;font-weight:600">Orientation IA</label>
                    <select id="ged-orientation" style="padding:6px 10px;border:1px solid #d4d7de;border-radius:10px;font-size:12px">
                        <option value="">Auto (recommandé)</option>
                        <option value="SYNDIC">SYNDIC</option>
                        <option value="BAILLEUR">BAILLEUR</option>
                        <option value="COMPTA">COMPTA</option>
                        <option value="RH">RH</option>
                        <option value="AGENCE">AGENCE</option>
                        <option value="FOURNISSEURS">FOURNISSEURS</option>
                        <option value="ADMIN">ADMIN</option>
                    </select>
                    <label style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px">
                        <input type="checkbox" id="ged-auto-orientation" checked>
                        auto par nom de dossier
                    </label>
                </div>
            </div>
            <div style="margin-top:10px;font-size:12px;color:#6b7280;line-height:1.35">
                Conseil PDF scanné : vise <strong>150–200 DPI</strong> (gris) et active “<strong>OCR</strong>”/“<strong>Optimiser</strong>” avant upload.
                Les scans trop lourds ralentissent fortement l’analyse.
            </div>
        </div>
        <div class="dz-progress" id="dz-progress" style="display:none">
            <div class="dz-progress-bar"><div class="dz-progress-fill" id="dz-fill"></div></div>
            <div class="dz-progress-text" id="dz-text">Classement (éco) en cours…</div>
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
        $isQueued = (($a['ia_engine'] ?? '') === 'queued') || (($aiRaw['_status'] ?? '') === 'queued');
    ?>

        <!-- ── WORKSPACE 3 colonnes : liste / preview / form ── -->
        <div class="ged-inbox-workspace" id="ged-card" data-analysis-id="<?= (int)$a['id'] ?>" data-analysis-queued="<?= $isQueued ? '1' : '0' ?>">

            <!-- Colonne gauche : liste docs (150px) -->
            <div class="ged-inbox-queue" aria-label="Liste des documents à valider">
                <div class="queue-head">
                    <div class="queue-count"><?= (int)$pendingCount ?> restant<?= $pendingCount > 1 ? 's' : '' ?></div>
                    <div class="queue-sub">Liste (50 max)</div>
                </div>
                <div class="queue-list">
                    <?php foreach ($queueList as $q):
                        $qid = (int)($q['id'] ?? 0);
                        $qName = (string)($q['nom_original'] ?? $q['suggested_filename'] ?? ('#' . $qid));
                        $qName = $qName !== '' ? $qName : ('#' . $qid);
                        $isActive = $qid === (int)$a['id'];
                        $qConfRaw = $q['confidence_score'] ?? null;
                        $qQueued  = (($q['ia_engine'] ?? '') === 'queued');
                        $qConfTxt = ($qConfRaw !== null) ? (string)round((float)$qConfRaw) . '%' : ($qQueued ? '…' : '–');
                        $qConfVal = ($qConfRaw !== null) ? (float)$qConfRaw : null;
                        $qConfClass = ($qConfVal === null) ? 'c-na' : (($qConfVal >= 75) ? 'c-high' : (($qConfVal >= 50) ? 'c-mid' : 'c-low'));
                    ?>
                        <a class="queue-item<?= $isActive ? ' is-active' : '' ?>"
                           href="<?= app_url('/modules/ged/ged_inbox.php') ?>?id=<?= $qid ?>"
                           title="<?= htmlspecialchars($qName) ?>">
                            <span class="qi-name"><?= htmlspecialchars($qName) ?></span>
                            <span class="qi-conf <?= htmlspecialchars($qConfClass) ?>"><?= htmlspecialchars($qConfTxt) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Preview gauche -->
            <div class="ged-inbox-preview">
                <div class="preview-header">
                    <span class="preview-name"><?= htmlspecialchars($a['nom_original'] ?? $a['suggested_filename'] ?? 'Document') ?></span>
                    <span class="aged-conf <?= ($a['confidence_score'] ?? 0) >= 75 ? 'c-high' : (($a['confidence_score'] ?? 0) >= 50 ? 'c-mid' : 'c-low') ?>">
                        <?= $a['confidence_score'] !== null ? round((float)$a['confidence_score']) . '%' : '–' ?>
                    </span>
                </div>
                <?php if (!empty($a['storage_file_id']) && $a['storage_driver'] === 'local'): ?>
                    <iframe class="preview-iframe"
                            src="<?= app_url('/api/ged_inbox_preview.php') ?>?id=<?= (int)$a['id'] ?>#toolbar=0&navpanes=0&scrollbar=1"
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
                <?php if ($isQueued): ?>
                    <div class="form-section" style="border:1px solid #fde68a;background:#fffbeb;border-radius:14px;padding:12px 14px;margin-bottom:12px">
                        <div style="font-weight:800;color:#92400e;margin-bottom:4px">⏳ Analyse en cours</div>
                        <div style="font-size:12px;color:#92400e;line-height:1.4">
                            Analyse (IA avancée) : lancée en tâche de fond (cron GED). Cela peut prendre 1–3 minutes sur un PDF lourd. La page se rafraîchit automatiquement.
                            Si ça ne bouge pas, vérifie que le cron <code>/api/cron_ged_jobs.php</code> tourne bien.
                            Si ça reste bloqué, clique <strong>Skip</strong> puis reviens plus tard.
                        </div>
                    </div>
                <?php endif; ?>
                <div class="form-section">
                    <?php
                        $analysisLevel = (string)($aiRaw['_analysis_level'] ?? ($a['ia_engine'] ?? ''));
                        $analysisEngine = (string)($aiRaw['_engine'] ?? ($a['ocr_engine'] ?? ''));
                        $titleDetected = (string)($aiRaw['description_courte'] ?? $aiRaw['title'] ?? '');
                        $confScore = $a['confidence_score'] !== null ? (float)$a['confidence_score'] : null;
                        $needsAdvanced = ($confScore === null) || ($confScore < 75.0);
                        $advLabel = ($confScore !== null && $confScore < 50.0) ? 'Analyser avec IA renforcée' : 'Analyser avec IA avancée';
                    ?>

                    <div class="ged-analysis-meta" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 10px">
                        <span class="ged-chip" title="Niveau d'analyse utilisé">
                            Analyse : <strong><?= htmlspecialchars($analysisLevel !== '' ? $analysisLevel : '—') ?></strong>
                        </span>
                        <?php if ($analysisEngine !== ''): ?>
                            <span class="ged-chip" title="Moteur / méthode">
                                Méthode : <strong><?= htmlspecialchars($analysisEngine) ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($titleDetected !== ''): ?>
                            <span class="ged-chip" title="Titre détecté">
                                Titre : <strong><?= htmlspecialchars($titleDetected) ?></strong>
                            </span>
                        <?php endif; ?>
                    </div>

                    <h3>📋 Classement suggéré</h3>

                    <div class="ged-quick-actions" aria-label="Raccourcis de classement">
                        <button type="button" class="ged-quick-btn" id="btn-quick-releve-banque">🏦 Relevé Banque</button>
                        <button type="button" class="ged-quick-btn" id="btn-quick-taxe-fonciere">🏛️ Taxe foncière</button>
                        <button type="button" class="ged-quick-btn" id="btn-quick-diagnostic">🔬 Diagnostic / DPE</button>
                    </div>

                    <div class="ged-field">
                        <label>Nom proposé (low-cost)</label>
                        <input type="text" value="<?= htmlspecialchars($a['suggested_filename'] ?? '') ?>" readonly>
                        <div style="margin-top:6px;font-size:12px;color:#6b7280;line-height:1.35">
                            Proposé pour contrôle uniquement. Le nom final GED est construit au moment du <strong>Valider</strong>.
                        </div>
                    </div>

                    <div class="ged-field">
                        <label>Type de document</label>
                        <input type="text" name="type_document" value="<?= htmlspecialchars($aiRaw['type_document'] ?? '') ?>" placeholder="FACTURE, BAIL, RELEVE_BANCAIRE…">
                    </div>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Module (métier)</label>
                            <?php $curMod = (string)($a['suggested_module'] ?? ''); ?>
                            <input type="hidden" name="suggested_module" id="ged-suggested-module" value="<?= htmlspecialchars($curMod) ?>">
                            <div class="ged-mod-buttons" role="group" aria-label="Modules">
                                <?php foreach (['SYNDIC','BAILLEUR','COMPTA','RH','AGENCE'] as $m): ?>
                                    <button type="button"
                                            class="ged-mod-btn<?= $curMod === $m ? ' is-active' : '' ?>"
                                            data-mod="<?= $m ?>"><?= $m ?></button>
                                <?php endforeach; ?>
                                <select class="ged-mod-other" id="ged-mod-other" title="Autres modules">
                                    <option value="">Autres…</option>
                                    <?php foreach (['FOURNISSEURS','ADMIN'] as $m): ?>
                                        <option value="<?= $m ?>" <?= $curMod === $m ? 'selected' : '' ?>><?= $m ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Niveau 2</label>
                            <input type="text" name="suggested_level_2" value="<?= htmlspecialchars($a['suggested_level_2'] ?? '') ?>">
                            <div class="ged-chips" id="ged-level2-chips" aria-label="Suggestions niveau 2"></div>
                        </div>
                        <div class="ged-field">
                            <label>Niveau 3</label>
                            <input type="text" name="suggested_level_3" value="<?= htmlspecialchars($a['suggested_level_3'] ?? '') ?>">
                            <div class="ged-chips" id="ged-level3-chips" aria-label="Suggestions niveau 3"></div>
                        </div>
                    </div>

                    <div class="ged-field-row">
                        <div class="ged-field">
                            <label>Date document</label>
                            <input type="date" name="date_document" value="<?= htmlspecialchars($a['date_document'] ?? '') ?>">
                        </div>
                        <div class="ged-field" id="ged-montant-wrap">
                            <label>Montant TTC (€)</label>
                            <input type="number" step="0.01" name="detected_montant" value="<?= htmlspecialchars($a['detected_montant'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="ged-field">
                        <label>Tiers principal</label>
                        <div class="ged-tier-kind" role="group" aria-label="Type de tiers principal">
                            <button type="button" class="ged-tier-btn is-active" data-kind="SDC">SDC</button>
                            <button type="button" class="ged-tier-btn" data-kind="BAILLEUR">BAILLEUR</button>
                        </div>
                        <div class="ged-tier-search">
                            <input type="text" id="ged-tier-search" placeholder="Rechercher (nom, référence, adresse…)">
                            <div class="ged-tier-results" id="ged-tier-results" style="display:none"></div>
                        </div>
                        <div class="ged-tier-picked" id="ged-tier-picked" style="display:none"></div>
                    </div>

                    <div class="ged-field">
                        <label>Tiers (pour le nom de fichier)</label>
                        <input type="text" name="tiers_nom" id="ged-tiers-nom" value="<?= htmlspecialchars($a['tiers_nom'] ?? $aiRaw['tiers_principal'] ?? '') ?>">
                        <?php
                            $tierCandidates = [];
                            foreach ([
                                $a['tiers_nom'] ?? null,
                                $aiRaw['tiers_principal'] ?? null,
                                $aiRaw['fournisseur'] ?? null,
                                $aiRaw['emetteur_nom'] ?? null,
                            ] as $cand) {
                                $cand = is_string($cand) ? trim($cand) : '';
                                if ($cand === '' || mb_strlen($cand) < 2) continue;
                                $tierCandidates[] = $cand;
                            }
                            $tierCandidates = array_values(array_unique($tierCandidates));
                        ?>
                        <?php if ($tierCandidates): ?>
                            <div class="ged-chips" aria-label="Suggestions tiers">
                                <?php foreach (array_slice($tierCandidates, 0, 6) as $cand): ?>
                                    <button type="button" class="ged-chip" data-fill="#ged-tiers-nom" data-value="<?= htmlspecialchars($cand) ?>">
                                        <?= htmlspecialchars(mb_strlen($cand) > 22 ? mb_substr($cand, 0, 21) . '…' : $cand) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
                            <label>Agence (référence GED)</label>
                            <div class="ged-agence-row">
                                <div class="ged-ag-btns" aria-label="Agences rapides">
                                    <?php foreach (array_slice($etabs, 0, 6) as $e):
                                        $sigle = trim((string)($e['sigle'] ?? ''));
                                        $nom   = trim((string)($e['nom'] ?? ''));
                                        $code  = $sigle !== '' ? ('AG' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sigle))) : ('AG' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nom)));
                                        $code  = substr($code, 0, 12);
                                    ?>
                                        <button type="button" class="ged-ag-btn" data-ag="<?= htmlspecialchars($code) ?>" title="<?= htmlspecialchars($nom) ?>">
                                            <?= htmlspecialchars($code) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <select id="ged-agence-preset" title="Preset ref_agence">
                                    <option value="">Presets…</option>
                                    <?php foreach ($etabs as $e):
                                        $sigle = trim((string)($e['sigle'] ?? ''));
                                        $nom   = trim((string)($e['nom'] ?? ''));
                                        $code  = $sigle !== '' ? ('AG' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sigle))) : ('AG' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nom)));
                                        $code  = substr($code, 0, 12);
                                    ?>
                                        <option value="<?= htmlspecialchars($code) ?>" <?= ($a['ref_agence'] ?? '') === $code ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($nom ?: $code) ?> → <?= htmlspecialchars($code) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="ref_agence" id="ged-ref-agence" value="<?= htmlspecialchars($a['ref_agence'] ?? 'AGLYON') ?>" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions form-actions-analysis">
                    <button class="aged-btn b-primary" type="button" id="btn-classer" <?= $isQueued ? 'disabled' : '' ?>>
                        <span class="b-emoji">🗂</span> Classer (éco)
                    </button>
                    <?php if ($needsAdvanced): ?>
                        <button class="aged-btn" type="button" id="btn-analyze-advanced" <?= $isQueued ? 'disabled' : '' ?>>
                            <span class="b-emoji">✨</span> <?= htmlspecialchars($advLabel) ?>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button class="aged-btn b-danger" type="button" id="btn-reject">
                        <span class="b-emoji">✖</span> Rejeter
                    </button>
                    <button class="aged-btn" type="button" id="btn-skip">
                        <span class="b-emoji">⏭</span> Skip
                    </button>
                    <button class="aged-btn b-primary" type="button" id="btn-validate" <?= $isQueued ? 'disabled' : '' ?>>
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
