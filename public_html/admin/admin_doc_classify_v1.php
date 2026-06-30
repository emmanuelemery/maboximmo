<?php
/**
 * admin/admin_doc_classify_v1.php
 *
 * Sprint 3C V0 — Classement GED N1→N6.
 *
 * Cascade de sélecteurs basée sur ged_folders (arbre dynamique parent_id).
 * Pré-rempli depuis ?card_id=X (lit fluxbox_cartes.proposition_json).
 * Sauvegarde la proposition dans fluxbox_cartes.proposition_json.classification_v0
 * + collecte un feedback IA dans le même JSON.
 *
 * État : V0 squelette testable. AUCUNE création de table.
 *        Aucune création de dossier GED non plus (uniquement sélection dans
 *        l'existant). La boucle d'apprentissage (feedback → ajustement scoring
 *        IA) sera Sprint 3D.
 *
 * Accès super admin uniquement (role=1).
 *
 * Cohérent avec [[project_ged_arbitrage_modele_n1_2026-05-12]] (15 N1 canon BDD).
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

function cl_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cl_table_exists(PDO $pdo, string $name): bool {
    // MariaDB 11+ refuse `SHOW TABLES LIKE ?` en prepared statement quand
    // EMULATE_PREPARES=false. Passer par information_schema qui supporte les params.
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) { return false; }
}

$hasGedFolders = cl_table_exists($pdo, 'ged_folders');
$hasCartes     = cl_table_exists($pdo, 'fluxbox_cartes');

$cardId = (int)($_GET['card_id'] ?? 0);

// ─── Cascade : récupération des niveaux ─────────────────────────────
$selectedIds = [
    1 => (int)($_GET['n1'] ?? $_POST['n1'] ?? 0),
    2 => (int)($_GET['n2'] ?? $_POST['n2'] ?? 0),
    3 => (int)($_GET['n3'] ?? $_POST['n3'] ?? 0),
    4 => (int)($_GET['n4'] ?? $_POST['n4'] ?? 0),
    5 => (int)($_GET['n5'] ?? $_POST['n5'] ?? 0),
    6 => (int)($_GET['n6'] ?? $_POST['n6'] ?? 0),
];

// Reset hiérarchique : si n1 change, vider n2-n6 (côté GET déjà OK car
// chaque link rebuild l'URL). Ici on coupe la cascade dès qu'un parent invalide.

$levels = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []];

if ($hasGedFolders) {
    // N1 : racines (parent_id IS NULL OR parent_id = 0)
    try {
        $levels[1] = $pdo->query("
            SELECT id, name_display, name_canonical, module, depth, parent_id
            FROM ged_folders
            WHERE (parent_id IS NULL OR parent_id = 0)
              AND is_archived = 0
            ORDER BY position, name_display
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $levels[1] = [];
    }

    // Niveaux suivants : enfants du niveau précédent sélectionné
    for ($lvl = 2; $lvl <= 6; $lvl++) {
        $parentId = $selectedIds[$lvl - 1];
        if ($parentId <= 0) break;
        try {
            $st = $pdo->prepare("
                SELECT id, name_display, name_canonical, module, depth
                FROM ged_folders
                WHERE parent_id = ?
                  AND is_archived = 0
                ORDER BY position, name_display
            ");
            $st->execute([$parentId]);
            $levels[$lvl] = $st->fetchAll(PDO::FETCH_ASSOC);
            // Si vide → on arrête la cascade
            if (empty($levels[$lvl])) break;
        } catch (Throwable) {
            $levels[$lvl] = [];
            break;
        }
    }
}

// ─── Path résumé ─────────────────────────────────────────────────────
$pathSummary = [];
if ($hasGedFolders) {
    for ($lvl = 1; $lvl <= 6; $lvl++) {
        $id = $selectedIds[$lvl];
        if ($id <= 0) break;
        try {
            $st = $pdo->prepare("SELECT id, name_display, name_canonical FROM ged_folders WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) $pathSummary[] = $row;
        } catch (Throwable) {}
    }
}

// ─── Pré-remplissage depuis fluxbox_cartes ──────────────────────────
$cardInfo = null;
$existingClassification = null;
if ($cardId > 0 && $hasCartes) {
    try {
        $st = $pdo->prepare("SELECT id, titre, sous_titre, proposition_json FROM fluxbox_cartes WHERE id = ?");
        $st->execute([$cardId]);
        $cardInfo = $st->fetch(PDO::FETCH_ASSOC);
        if ($cardInfo && !empty($cardInfo['proposition_json'])) {
            $prop = json_decode((string)$cardInfo['proposition_json'], true);
            if (is_array($prop) && isset($prop['classification_v0'])) {
                $existingClassification = $prop['classification_v0'];
            }
        }
    } catch (Throwable) {}
}

// ─── Action POST : enregistrer le classement V0 ────────────────────
//
// Double écriture :
//   1. fluxbox_cartes.proposition_json.classification_v0   (snapshot complet)
//   2. ged_classification_feedback                          (1 ligne par champ
//                                                            corrigé, exploitable
//                                                            par la boucle IA)
$flash = null;
$hasFeedback = cl_table_exists($pdo, 'ged_classification_feedback');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_classification_v0') {
    if ($cardId > 0 && $hasCartes) {
        $feedbackType = (string)($_POST['feedback_type'] ?? 'neutral');
        $feedbackText = trim((string)($_POST['feedback_ia'] ?? ''));
        $userId       = (int)($_SESSION['id_user'] ?? 0);

        // Module N1 (extrait du 1er segment si dispo)
        $moduleN1 = $pathSummary[0]['name_canonical'] ?? null;

        $payload = [
            'folder_ids'    => array_values(array_filter($selectedIds)),
            'path'          => array_map(fn($r) => $r['name_display'], $pathSummary),
            'path_canonical'=> array_map(fn($r) => $r['name_canonical'], $pathSummary),
            'feedback_ia'   => $feedbackText,
            'feedback_type' => $feedbackType,
            'module_n1'     => $moduleN1,
            'classified_by' => $userId,
            'classified_at' => date('Y-m-d H:i:s'),
        ];

        $writes = ['fluxbox_cartes' => false, 'ged_feedback_rows' => 0];

        try {
            // 1) Snapshot dans fluxbox_cartes
            $st = $pdo->prepare("
                UPDATE fluxbox_cartes
                SET proposition_json = COALESCE(proposition_json, JSON_OBJECT()),
                    proposition_json = JSON_MERGE_PATCH(proposition_json, :patch),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $st->execute([
                ':patch' => json_encode(['classification_v0' => $payload], JSON_UNESCAPED_UNICODE),
                ':id'    => $cardId,
            ]);
            $writes['fluxbox_cartes'] = true;

            // 2) Feedback IA structuré dans ged_classification_feedback
            //    → uniquement si la carte porte un document_id et que la table existe
            if ($hasFeedback) {
                $docInfo = $pdo->prepare("
                    SELECT c.document_id, d.fichier_nom, d.hash_sha256
                    FROM fluxbox_cartes c
                    LEFT JOIN fluxbox_documents d ON d.id = c.document_id
                    WHERE c.id = ?
                ");
                $docInfo->execute([$cardId]);
                $docRow = $docInfo->fetch(PDO::FETCH_ASSOC) ?: [];

                $oldFilename = $docRow['fichier_nom'] ?? null;
                $docId       = !empty($docRow['document_id']) ? (int)$docRow['document_id'] : null;

                // Poids : correct=200 (auto-validé renforcé), neutral=100 (défaut),
                //         incorrect=300 (correction explicite, doit peser plus fort)
                $weight = ['correct' => 200, 'neutral' => 100, 'incorrect' => 300][$feedbackType] ?? 100;

                $insFb = $pdo->prepare("
                    INSERT INTO ged_classification_feedback
                        (tenant_id, import_item_id, document_id, old_filename,
                         field, suggestion_value, correction_value,
                         module, user_id, weight_applied, created_at)
                    VALUES
                        (NULL, NULL, :did, :fn,
                         :field, :sug, :cor,
                         :mod, :uid, :w, NOW())
                ");

                // Une ligne par niveau renseigné
                foreach ([1,2,3,4,5,6] as $lvl) {
                    if (empty($pathSummary[$lvl-1])) continue;
                    $seg = $pathSummary[$lvl-1];
                    $insFb->execute([
                        ':did'   => $docId,
                        ':fn'    => $oldFilename,
                        ':field' => 'n' . $lvl,
                        // V0 : on n'a pas la suggestion IA ici → on stocke uniquement
                        //      la correction humaine. Quand 3B fera son boulot,
                        //      la suggestion IA sera passée en hidden field.
                        ':sug'   => null,
                        ':cor'   => (string)$seg['name_canonical'],
                        ':mod'   => $moduleN1,
                        ':uid'   => $userId ?: null,
                        ':w'     => $weight,
                    ]);
                    $writes['ged_feedback_rows']++;
                }

                // Ligne supplémentaire "title" si l'user a laissé un commentaire
                if ($feedbackText !== '') {
                    $insFb->execute([
                        ':did'   => $docId,
                        ':fn'    => $oldFilename,
                        ':field' => 'title',
                        ':sug'   => null,
                        ':cor'   => mb_substr($feedbackText, 0, 250),
                        ':mod'   => $moduleN1,
                        ':uid'   => $userId ?: null,
                        ':w'     => $weight,
                    ]);
                    $writes['ged_feedback_rows']++;
                }
            }

            $flash = [
                'ok'  => true,
                'msg' => "Classement enregistré : fluxbox_cartes #$cardId mis à jour"
                       . ($writes['ged_feedback_rows'] > 0
                            ? " + {$writes['ged_feedback_rows']} ligne(s) dans ged_classification_feedback."
                            : ($hasFeedback ? '' : " (ged_classification_feedback absente — feedback non persisté).")),
            ];
        } catch (Throwable $e) {
            $flash = ['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()];
        }
    } else {
        $flash = ['ok' => false, 'msg' => 'card_id manquant — impossible de sauvegarder.'];
    }
}

// ─── URL builder pour les liens cascade ─────────────────────────────
function cl_build_url(array $selected, int $stopAt, ?int $cardId = null): string {
    $params = [];
    if ($cardId) $params['card_id'] = $cardId;
    for ($i = 1; $i <= $stopAt; $i++) {
        if (!empty($selected[$i])) $params["n$i"] = $selected[$i];
    }
    return '?' . http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Classement GED N1→N6 — Sprint 3C V0</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0;
            font-family: "DM Mono", "JetBrains Mono", monospace;
            background: var(--bg); color: var(--text); font-size: 12.5px;
            min-height: 100vh;
        }
        header {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 18px; background: #11203b;
            border-bottom: 2px solid var(--mbi-or);
        }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .v0-badge {
            background: #7c3aed; color: #fff; padding: 2px 8px;
            border-radius: 4px; font-size: 10px; font-weight: 700;
        }
        header nav { margin-left: auto; display: flex; gap: 10px; }
        header nav a {
            color: var(--muted); text-decoration: none; font-size: 11px;
            border: 1px solid var(--line); padding: 4px 10px; border-radius: 4px;
        }
        header nav a:hover { color: var(--text); border-color: var(--mbi-or); }

        .container { max-width: 1200px; margin: 0 auto; padding: 18px; }

        .panel {
            background: var(--panel); border-radius: 6px; padding: 16px 20px;
            margin-bottom: 14px;
        }
        .panel h2 {
            font-size: 13px; margin: 0 0 12px; color: var(--mbi-or);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--line); padding-bottom: 6px;
        }

        .flash {
            padding: 10px 14px; border-radius: 4px; margin-bottom: 14px;
            font-size: 11.5px;
        }
        .flash.ok { background: #14532d; color: #d1fae5; border-left: 3px solid var(--ok); }
        .flash.ko { background: #7f1d1d; color: #fee2e2; border-left: 3px solid var(--ko); }

        /* Breadcrumb path */
        .breadcrumb {
            display: flex; gap: 6px; flex-wrap: wrap;
            padding: 10px 14px; background: #0f172a;
            border-radius: 4px; border-left: 3px solid var(--mbi-or);
            font-size: 11.5px;
        }
        .breadcrumb .seg {
            background: var(--panel); padding: 3px 8px; border-radius: 3px;
            color: var(--text);
        }
        .breadcrumb .sep { color: var(--muted); }
        .breadcrumb .empty { color: var(--muted); font-style: italic; }

        /* Cascade selectors */
        .cascade {
            display: grid; gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            margin-top: 14px;
        }
        .level {
            background: #0f172a; padding: 10px; border-radius: 4px;
            border-top: 3px solid var(--line);
        }
        .level.has-selection { border-top-color: var(--mbi-or); }
        .level h3 {
            font-size: 10.5px; margin: 0 0 8px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .level h3 b { color: var(--mbi-or); }
        .level .options { display: flex; flex-direction: column; gap: 3px; max-height: 280px; overflow-y: auto; }
        .level .options a {
            display: block; padding: 5px 9px; color: var(--text);
            text-decoration: none; font-size: 11px;
            border-radius: 3px; border: 1px solid transparent;
        }
        .level .options a:hover { background: var(--panel); border-color: var(--line); }
        .level .options a.selected {
            background: var(--mbi-or); color: #1a1a1a; font-weight: 700;
            border-color: var(--mbi-or);
        }
        .level .options a .module-tag {
            float: right; font-size: 9px; color: var(--muted);
            background: var(--panel); padding: 0 4px; border-radius: 2px;
        }
        .level .empty {
            color: var(--muted); font-style: italic; font-size: 10.5px;
            padding: 6px 0;
        }

        .feedback-block { margin-top: 14px; }
        .feedback-block label {
            display: block; font-size: 10.5px; color: var(--muted);
            margin-bottom: 4px; text-transform: uppercase;
        }
        .feedback-block textarea {
            width: 100%; padding: 8px 10px;
            background: #0f172a; border: 1px solid var(--line);
            color: var(--text); border-radius: 3px;
            font-family: inherit; font-size: 12px; resize: vertical; min-height: 60px;
        }
        .feedback-types {
            display: flex; gap: 8px; margin-top: 6px;
        }
        .feedback-types label {
            display: flex; align-items: center; gap: 4px;
            padding: 4px 10px; background: #0f172a; border-radius: 3px;
            cursor: pointer; font-size: 10.5px; margin-bottom: 0;
            border: 1px solid var(--line);
        }
        .feedback-types label:has(input:checked) {
            background: var(--mbi-or); color: #1a1a1a; border-color: var(--mbi-or);
        }

        .actions {
            display: flex; gap: 10px; justify-content: space-between;
            margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line);
        }
        .btn {
            padding: 8px 14px; border: none; border-radius: 4px;
            font-family: inherit; font-size: 11.5px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary { background: var(--mbi-or); color: #1a1a1a; font-weight: 700; }
        .btn-primary:hover { background: #b8862f; }
        .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }
        .btn-ghost:hover { color: var(--text); border-color: var(--mbi-or); }

        .v0-warn {
            background: #422006; color: #fde68a; padding: 8px 12px;
            border-radius: 3px; font-size: 10.5px; margin-top: 14px;
            border-left: 3px solid var(--warn);
        }
        .meta {
            font-size: 10.5px; color: var(--muted); margin-bottom: 12px;
        }
        pre {
            background: #0f172a; padding: 10px; border-radius: 3px;
            font-size: 10.5px; overflow-x: auto; line-height: 1.5;
            border: 1px solid var(--line);
        }
    </style>
</head>
<body>

<header>
    <h1>📁 Classement GED N1→N6</h1>
    <span class="v0-badge">SPRINT 3C · V0</span>
    <nav>
        <a href="admin_doc_validation_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">← Validation 3A</a>
        <a href="admin_doc_match_assistant_v1.php<?= $cardId ? '?card_id='.$cardId : '' ?>">← Matching 3B</a>
        <a href="admin_ged_arborescence.php">🌳 Admin arbo</a>
    </nav>
</header>

<div class="container">

    <?php if ($flash): ?>
        <div class="flash <?= $flash['ok'] ? 'ok' : 'ko' ?>">
            <?= cl_html((string)$flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasGedFolders): ?>
        <div class="flash ko">⚠️ Table <code>ged_folders</code> absente. Lance les migrations GED V1.</div>
    <?php endif; ?>

    <?php if ($cardInfo): ?>
        <div class="meta">
            🔗 Carte FluxBox <code>#<?= (int)$cardInfo['id'] ?></code>
            : <b><?= cl_html((string)$cardInfo['titre']) ?></b>
            <?php if (!empty($cardInfo['sous_titre'])): ?>
                — <?= cl_html((string)$cardInfo['sous_titre']) ?>
            <?php endif; ?>
        </div>
    <?php elseif ($cardId > 0): ?>
        <div class="meta" style="color: var(--ko);">⚠️ card_id <?= (int)$cardId ?> introuvable.</div>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <div class="panel">
        <h2>📍 Chemin sélectionné</h2>
        <div class="breadcrumb">
            <?php if (empty($pathSummary)): ?>
                <span class="empty">(rien sélectionné — choisis un N1 pour commencer)</span>
            <?php else: ?>
                <?php foreach ($pathSummary as $i => $seg): ?>
                    <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
                    <span class="seg" title="<?= cl_html((string)$seg['name_canonical']) ?>">
                        N<?= $i+1 ?> · <?= cl_html((string)$seg['name_display']) ?>
                    </span>
                <?php endforeach; ?>
                <?php if (count($pathSummary) < 6): ?>
                    <span class="sep">/</span><span class="empty">…N<?= count($pathSummary)+1 ?>?</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cascade -->
    <div class="panel">
        <h2>🎯 Cascade N1 → N6</h2>

        <div class="cascade">
            <?php for ($lvl = 1; $lvl <= 6; $lvl++):
                $options = $levels[$lvl];
                $selectedId = $selectedIds[$lvl];
                $hasSelection = $selectedId > 0;
            ?>
                <div class="level <?= $hasSelection ? 'has-selection' : '' ?>">
                    <h3>
                        Niveau <b>N<?= $lvl ?></b>
                        <?php if ($hasSelection): ?>· <?= count($options) ?> choix<?php endif; ?>
                    </h3>
                    <div class="options">
                        <?php if (empty($options)): ?>
                            <span class="empty">
                                <?= $lvl === 1
                                    ? '(aucune racine — vérifier ged_folders)'
                                    : '(sélectionne un N'.($lvl-1).' d\'abord)' ?>
                            </span>
                        <?php else: ?>
                            <?php foreach ($options as $opt):
                                $isSel = ((int)$opt['id'] === $selectedId);
                                // Construire l'URL : reset des niveaux inférieurs
                                $newSel = $selectedIds;
                                $newSel[$lvl] = (int)$opt['id'];
                                for ($k = $lvl+1; $k <= 6; $k++) $newSel[$k] = 0;
                                $url = cl_build_url($newSel, $lvl, $cardId > 0 ? $cardId : null);
                            ?>
                                <a href="<?= cl_html($url) ?>"
                                   class="<?= $isSel ? 'selected' : '' ?>"
                                   title="<?= cl_html((string)$opt['name_canonical']) ?>">
                                    <?= cl_html((string)$opt['name_display']) ?>
                                    <?php if (!empty($opt['module']) && $lvl === 1): ?>
                                        <span class="module-tag"><?= cl_html((string)$opt['module']) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Feedback IA + sauvegarde -->
    <form method="POST" action="<?= cl_html(cl_build_url($selectedIds, 6, $cardId > 0 ? $cardId : null)) ?>">
        <input type="hidden" name="action" value="save_classification_v0">
        <?php for ($i = 1; $i <= 6; $i++): if ($selectedIds[$i] > 0): ?>
            <input type="hidden" name="n<?= $i ?>" value="<?= (int)$selectedIds[$i] ?>">
        <?php endif; endfor; ?>

        <div class="panel">
            <h2>🧠 Feedback IA (boucle d'apprentissage)</h2>

            <?php if ($existingClassification): ?>
                <div class="meta" style="color: var(--warn);">
                    ⚠️ Cette carte a déjà été classée le
                    <code><?= cl_html((string)($existingClassification['classified_at'] ?? '?')) ?></code>.
                    Sauvegarder écrasera le classement précédent.
                </div>
            <?php endif; ?>

            <div class="feedback-block">
                <label>Le classement proposé par l'IA était-il correct ?</label>
                <div class="feedback-types">
                    <label><input type="radio" name="feedback_type" value="correct"> ✅ Correct (l'IA avait raison)</label>
                    <label><input type="radio" name="feedback_type" value="neutral" checked> ➖ Neutre (cas ambigu)</label>
                    <label><input type="radio" name="feedback_type" value="incorrect"> ❌ Incorrect (IA s'est trompée)</label>
                </div>
            </div>

            <div class="feedback-block">
                <label>Commentaire feedback (optionnel)</label>
                <textarea name="feedback_ia" placeholder="Ex. l'IA a proposé SYNDIC/Travaux mais le bon classement est SYNDIC/Contrats..."></textarea>
            </div>

            <div class="actions">
                <a class="btn btn-ghost" href="<?= $cardId ? '?card_id='.$cardId : '?' ?>">↺ Reset cascade</a>
                <button type="submit" class="btn btn-primary"
                        <?= ($cardId <= 0 || empty($pathSummary)) ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : '' ?>>
                    💾 Enregistrer classement V0
                </button>
            </div>

            <?php if ($cardId <= 0): ?>
                <div class="v0-warn">
                    ⚠️ Aucune carte cible (<code>?card_id=X</code> manquant) — sauvegarde désactivée.
                    Naviguer depuis <a href="admin_doc_validation_v1.php" style="color: var(--warn);">admin_doc_validation_v1.php</a>
                    pour passer un card_id valide.
                </div>
            <?php elseif (empty($pathSummary)): ?>
                <div class="v0-warn">
                    ⚠️ Sélectionne au moins un N1 avant de sauvegarder.
                </div>
            <?php else: ?>
                <div class="v0-warn">
                    <b>V0 :</b> sauvegarde uniquement dans <code>fluxbox_cartes.proposition_json.classification_v0</code>.
                    Aucune création de dossier GED, aucun déplacement de fichier physique.
                    La boucle d'apprentissage (feedback → ajustement scoring IA) sera Sprint 3D.
                </div>
            <?php endif; ?>
        </div>
    </form>

    <details style="margin-top:20px; color: var(--muted); font-size: 11px;">
        <summary style="cursor:pointer;">🔍 État brut (debug)</summary>
        <pre><?= cl_html(json_encode([
            'card_id' => $cardId,
            'selectedIds' => $selectedIds,
            'levels_count' => array_map('count', $levels),
            'path' => array_map(fn($r) => $r['name_display'], $pathSummary),
            'existing_classification' => $existingClassification,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>

</div>

</body>
</html>
