<?php
/**
 * bien_documents_list.php
 *
 * MVP Transaction E2E V0 — Liste documents d'un bien + bouton upload rapide.
 *
 * Affiche tous les docs liés via ged_document_links (entity_type=BIEN, entity_id=X)
 * groupés par module GED (TRANSACTION, GESTION, etc.).
 *
 * Bouton "📤 Charger un document" → /api/transaction_quick_upload.php qui POST
 * le fichier, crée fluxbox_documents + carte, puis redirige vers
 * transaction_upload_review.php pour la validation pré-commit.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$bienId = (int)($_GET['id'] ?? 0);
$flash  = $_GET['msg'] ?? '';
$commitStatus = $_GET['commit'] ?? '';

if ($bienId <= 0) { http_response_code(400); exit('id requis'); }

function bdl_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ─── Lecture bien ───────────────────────────────────────────────────
$st = $pdo->prepare("SELECT id, adresse_1, code_postal, ville, designation, statut_bien, id_societe, id_agence FROM biens WHERE id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit("Bien #$bienId introuvable"); }

// Scope check (admin bypass via id_role=1)
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$isAdmin && (int)$bien['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403); exit('Hors société');
}

// ─── Documents liés via ged_document_links ─────────────────────────
$docs = [];
try {
    $st = $pdo->prepare("
        SELECT d.id, d.uuid, d.name_display, d.name_file, d.document_type, d.source_module,
               d.mime_type, d.size_bytes, d.created_at, d.folder_id, d.fluxbox_source_id,
               l.relation_type, l.is_validated, l.validated_at,
               f.name_display AS folder_name, f.path_cache
        FROM ged_document_links l
        JOIN ged_documents d ON d.id = l.document_id
        LEFT JOIN ged_folders f ON f.id = d.folder_id
        WHERE l.entity_type = 'BIEN' AND l.entity_id = ?
          AND d.status = 'active'
        ORDER BY d.created_at DESC
    ");
    $st->execute([$bienId]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* swallow, just show empty */ }

// Grouper par source_module
$byModule = [];
foreach ($docs as $d) {
    $mod = $d['source_module'] ?: 'autre';
    $byModule[$mod][] = $d;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>📁 Documents · Bien #<?= (int)$bienId ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --mbi-or: #D4A047;
            --bg: #0f172a; --panel: #1e293b; --line: #334155;
            --text: #f1f5f9; --muted: #94a3b8;
            --ok: #84a98c; --warn: #fde68a; --ko: #f87171; --info: #60a5fa;
            --bien: #84a98c; --transaction: #eab308; --chargement: #7c3aed;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DM Mono", monospace; background: var(--bg); color: var(--text); font-size: 12.5px; }
        header {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 18px; background: #11203b; border-bottom: 2px solid var(--bien);
        }
        header h1 { font-size: 14px; margin: 0; color: var(--warn); }
        header .badge { background: var(--bien); color: #1a1a1a; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
        header nav { margin-left: auto; display: flex; gap: 10px; }
        header nav a { color: var(--muted); text-decoration: none; font-size: 11px; border: 1px solid var(--line); padding: 4px 10px; border-radius: 4px; }
        header nav a:hover { color: var(--text); border-color: var(--mbi-or); }

        .container { max-width: 1100px; margin: 0 auto; padding: 18px; }

        .panel { background: var(--panel); border-radius: 6px; padding: 14px 18px; margin-bottom: 14px; }
        .panel h2 { font-size: 12px; margin: 0 0 10px; color: var(--mbi-or); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--line); padding-bottom: 6px; }

        .upload-zone {
            background: #0f172a; border: 2px dashed var(--transaction);
            border-radius: 6px; padding: 22px; text-align: center;
            margin-bottom: 14px;
        }
        .upload-zone form { display: inline-flex; gap: 10px; align-items: center; }
        .upload-zone input[type=file] {
            padding: 8px; background: #1e293b; border: 1px solid var(--line);
            color: var(--text); border-radius: 4px; font-family: inherit;
        }
        .upload-zone button {
            background: var(--transaction); color: #1a1a1a; border: none;
            padding: 10px 18px; border-radius: 4px; font-weight: 700;
            font-family: inherit; font-size: 12.5px; cursor: pointer;
        }
        .upload-zone button:hover { background: #ca9408; }
        .upload-zone .lbl { display: block; margin-bottom: 12px; font-size: 13px; color: var(--warn); }
        .upload-zone .sub { font-size: 10.5px; color: var(--muted); margin-top: 8px; }

        .module-card {
            background: #0f172a; border-radius: 4px; padding: 10px 14px;
            margin-bottom: 10px; border-left: 4px solid var(--info);
        }
        .module-card h3 { font-size: 11px; margin: 0 0 8px; color: var(--info); text-transform: uppercase; }

        .doc-item {
            display: grid; grid-template-columns: 1fr auto auto;
            gap: 10px; align-items: center;
            padding: 6px 10px; margin-bottom: 4px;
            background: var(--panel); border-radius: 3px; font-size: 11px;
        }
        .doc-item .name { font-weight: 700; }
        .doc-item .meta { color: var(--muted); font-size: 10px; }
        .doc-item .date { color: var(--muted); font-size: 10px; }
        .doc-item .badge-rel { display: inline-block; padding: 1px 6px; border-radius: 2px; font-size: 9px; font-weight: 700; background: var(--bien); color: #1a1a1a; margin-left: 6px; }
        .doc-item .badge-rel.reference { background: var(--info); color: #1a1a1a; }
        .doc-item .badge-rel.annexe { background: var(--warn); color: #1a1a1a; }

        .empty { text-align: center; padding: 30px; color: var(--muted); font-style: italic; }

        .flash {
            padding: 10px 14px; border-radius: 4px; margin-bottom: 14px; font-size: 11.5px;
        }
        .flash.ok { background: #14532d; color: #d1fae5; border-left: 4px solid var(--ok); }
        .flash.ko { background: #7f1d1d; color: #fee2e2; border-left: 4px solid var(--ko); }

        .stat-row { display: flex; gap: 16px; margin-bottom: 14px; }
        .stat-box { background: #0f172a; padding: 8px 14px; border-radius: 4px; flex: 1; text-align: center; }
        .stat-box .v { font-size: 22px; font-weight: 700; color: var(--mbi-or); }
        .stat-box .l { font-size: 10px; color: var(--muted); text-transform: uppercase; }
    </style>
</head>
<body>

<header>
    <h1>📁 Documents du bien #<?= (int)$bienId ?></h1>
    <span class="badge">MVP-T E2E</span>
    <nav>
        <a href="bien_360.php?id=<?= (int)$bienId ?>">← Retour bien 360°</a>
    </nav>
</header>

<div class="container">

    <?php if ($commitStatus === 'ok'): ?>
        <div class="flash ok">
            ✅ <b>Document validé et persisté</b><?= $flash ? ' · ' . bdl_html((string)$flash) : '' ?>
        </div>
    <?php elseif ($commitStatus === 'ko'): ?>
        <div class="flash ko">
            🔴 Erreur lors de la persistance<?= $flash ? ' · ' . bdl_html((string)$flash) : '' ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>🏠 Bien</h2>
        <div style="font-size: 12px;">
            <b>#<?= (int)$bien['id'] ?></b> ·
            <?= bdl_html((string)($bien['designation'] ?? '')) ?>
            <br><span style="color: var(--muted);">
                <?= bdl_html((string)($bien['adresse_1'] ?? '')) ?>
                <?php if (!empty($bien['code_postal'])): ?>
                    · <?= bdl_html((string)$bien['code_postal']) ?> <?= bdl_html((string)($bien['ville'] ?? '')) ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <!-- Zone upload rapide (multi-fichiers + ZIP) -->
    <div class="upload-zone">
        <span class="lbl">📤 Charger un ou plusieurs documents Transaction sur ce bien</span>
        <form method="POST" action="api/transaction_quick_upload.php" enctype="multipart/form-data">
            <input type="hidden" name="bien_id" value="<?= (int)$bienId ?>">
            <input type="file" name="document[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.tiff,.zip">
            <button type="submit">📤 Upload + Review</button>
        </form>
        <div class="sub">
            Fichier seul, multi-fichiers ou ZIP (extraction auto). PDF, images, ZIP autorisés.
        </div>
    </div>

    <!-- Stats -->
    <div class="stat-row">
        <div class="stat-box"><div class="v"><?= count($docs) ?></div><div class="l">Documents liés</div></div>
        <div class="stat-box"><div class="v"><?= count($byModule) ?></div><div class="l">Modules</div></div>
        <div class="stat-box">
            <div class="v"><?= count(array_filter($docs, fn($d) => (int)$d['is_validated'] === 1)) ?></div>
            <div class="l">Validés</div>
        </div>
    </div>

    <!-- Liste docs groupés par module -->
    <?php if (empty($docs)): ?>
        <div class="panel">
            <div class="empty">
                Aucun document lié à ce bien.<br>
                Charge le premier via la zone ci-dessus 👆
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($byModule as $module => $items): ?>
            <div class="module-card">
                <h3>📂 <?= bdl_html((string)$module) ?> (<?= count($items) ?>)</h3>
                <?php foreach ($items as $d): ?>
                    <div class="doc-item">
                        <div>
                            <a href="javascript:void(0)" class="doc-link"
                               onclick="mvptModalView(<?= (int)$d['id'] ?>, '<?= bdl_html((string)($d['name_display'] ?? $d['name_file'])) ?>')">
                                📄 <?= bdl_html((string)($d['name_display'] ?? $d['name_file'])) ?>
                            </a>
                            <?php if (!empty($d['relation_type'])): ?>
                                <span class="badge-rel <?= bdl_html((string)$d['relation_type']) ?>">
                                    <?= bdl_html((string)$d['relation_type']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($d['document_type'])): ?>
                                <span class="meta">· <?= bdl_html((string)$d['document_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($d['folder_name'])): ?>
                                <div class="meta">📁 <?= bdl_html((string)$d['folder_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="meta">
                            <?= number_format((int)$d['size_bytes']/1024) ?> Ko
                        </div>
                        <div class="date">
                            <?= bdl_html(substr((string)$d['created_at'], 0, 10)) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>

</body>
</html>
