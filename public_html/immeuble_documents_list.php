<?php
/**
 * immeuble_documents_list.php
 *
 * Liste tous les documents liés à un immeuble via ged_document_links
 * (entity_type='IMB', entity_id=X) + agrégation des docs liés à ses biens
 * (entity_type='BIEN' filtré sur biens.id_immeuble = X).
 *
 * Pas d'upload direct ici (l'upload se fait toujours sur un bien précis ;
 * choisir un bien depuis la fiche immeuble pour uploader).
 *
 * Sprint 5 — multi-entités (clone paramétré de bien_documents_list).
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$immId = (int)($_GET['id'] ?? 0);
if ($immId <= 0) { http_response_code(400); exit('id requis'); }

function idl_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Lecture immeuble + scope
$st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville, id_agence, id_societe FROM immeubles WHERE id = ?");
$st->execute([$immId]);
$imm = $st->fetch(PDO::FETCH_ASSOC);
if (!$imm) { http_response_code(404); exit("Immeuble #$immId introuvable"); }

$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$isAdmin && (int)$imm['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403); exit('Hors société');
}

// ─── Documents liés (3 sources agrégées) ──
// 1) directement à l'immeuble (entity_type=IMB)
// 2) aux biens de l'immeuble (entity_type=BIEN WHERE biens.id_immeuble=X)
$docs = [];
try {
    $sql = "
        SELECT DISTINCT d.id, d.uuid, d.name_display, d.name_file, d.document_type, d.source_module,
               d.mime_type, d.size_bytes, d.created_at, d.folder_id, d.fluxbox_source_id,
               GROUP_CONCAT(DISTINCT CONCAT(l.entity_type, '#', l.entity_id, '/', l.relation_type) ORDER BY l.id SEPARATOR ' · ') AS links_summary,
               f.name_display AS folder_name
        FROM ged_documents d
        JOIN ged_document_links l ON l.document_id = d.id
        LEFT JOIN ged_folders f ON f.id = d.folder_id
        WHERE d.status = 'active'
          AND (
              (l.entity_type = 'IMB' AND l.entity_id = :imm_a)
              OR (l.entity_type = 'BIEN' AND l.entity_id IN (SELECT id FROM biens WHERE id_immeuble = :imm_b))
          )
        GROUP BY d.id
        ORDER BY d.created_at DESC
        LIMIT 200
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':imm_a' => $immId, ':imm_b' => $immId]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// Grouper par source_module
$byModule = [];
foreach ($docs as $d) {
    $mod = $d['source_module'] ?: 'autre';
    $byModule[$mod][] = $d;
}

// Liste des biens de l'immeuble (pour le bouton "Charger un doc")
$biens = [];
try {
    $st = $pdo->prepare("SELECT id, designation, adresse_1 FROM biens WHERE id_immeuble = ? ORDER BY id LIMIT 50");
    $st->execute([$immId]);
    $biens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>📁 Documents · Immeuble #<?= (int)$immId ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="inc/css/mvpt_docs_list.css">
</head>
<body>

<header class="hdr-imm">
    <h1>📁 Documents de l'immeuble #<?= (int)$immId ?></h1>
    <span class="badge-imm">SPRINT 5 · IMMEUBLE</span>
    <nav><a href="immeuble_360.php?id=<?= (int)$immId ?>">← Retour immeuble 360°</a></nav>
</header>

<div class="container">
    <div class="panel">
        <h2>🏢 Immeuble</h2>
        <div style="font-size: 12px;">
            <b>#<?= (int)$imm['id'] ?></b> ·
            <?= idl_html((string)($imm['nom_immeuble'] ?? '')) ?>
            <br><span style="color: var(--muted);">
                <?= idl_html((string)($imm['adresse_1'] ?? '')) ?>
                <?php if (!empty($imm['code_postal'])): ?>
                    · <?= idl_html((string)$imm['code_postal']) ?> <?= idl_html((string)($imm['ville'] ?? '')) ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <div class="info-zone">
        <span class="lbl">📤 Pour charger un document, choisis un bien de cet immeuble :</span>
        <?php if (empty($biens)): ?>
            <div class="meta">Aucun bien lié à cet immeuble.</div>
        <?php else: ?>
            <div class="bien-list">
                <?php foreach (array_slice($biens, 0, 10) as $b): ?>
                    <a href="bien_documents_list.php?id=<?= (int)$b['id'] ?>" class="bien-chip">
                        🏠 #<?= (int)$b['id'] ?> <?= idl_html(mb_substr((string)($b['designation'] ?? $b['adresse_1'] ?? ''), 0, 30)) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (count($biens) > 10): ?><span class="meta">… +<?= count($biens) - 10 ?> autres</span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="stat-row">
        <div class="stat-box"><div class="v"><?= count($docs) ?></div><div class="l">Docs cumulés (immeuble + biens)</div></div>
        <div class="stat-box"><div class="v"><?= count($byModule) ?></div><div class="l">Modules</div></div>
        <div class="stat-box"><div class="v"><?= count($biens) ?></div><div class="l">Biens liés</div></div>
    </div>

    <?php if (empty($docs)): ?>
        <div class="panel">
            <div class="empty">Aucun document lié à cet immeuble ni à ses biens.</div>
        </div>
    <?php else: ?>
        <?php foreach ($byModule as $module => $items): ?>
            <div class="module-card">
                <h3>📂 <?= idl_html((string)$module) ?> (<?= count($items) ?>)</h3>
                <?php foreach ($items as $d): ?>
                    <div class="doc-item">
                        <div>
                            <a href="javascript:void(0)" class="doc-link" data-doc-id="<?= (int)$d['id'] ?>"
                               onclick="mvptModalView(<?= (int)$d['id'] ?>, '<?= idl_html((string)($d['name_display'] ?? $d['name_file'])) ?>')">
                                📄 <?= idl_html((string)($d['name_display'] ?? $d['name_file'])) ?>
                            </a>
                            <?php if (!empty($d['document_type'])): ?>
                                <span class="meta">· <?= idl_html((string)$d['document_type']) ?></span>
                            <?php endif; ?>
                            <div class="meta-links">🔗 <?= idl_html((string)($d['links_summary'] ?? '')) ?></div>
                        </div>
                        <div class="meta"><?= number_format((int)$d['size_bytes']/1024) ?> Ko</div>
                        <div class="date"><?= idl_html(substr((string)$d['created_at'], 0, 10)) ?></div>
                        <button type="button" class="idl-btn-reclass" onclick="sendDocToReclass(<?= (int)$d['id'] ?>)" title="Re-classer ce document (envoyer dans la pile FluxBox)">✏️ Re-classer</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>

<style>
.idl-btn-reclass {
    padding: 4px 10px;
    background: #fef9e7;
    border: 1px solid #d4a047;
    color: #92400e;
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 700;
    margin-left: 8px;
    transition: all 0.15s;
}
.idl-btn-reclass:hover { background: #d4a047; color: #fff; }
</style>
<script>
// Sprint R-RECLASS 2026-05-25 : envoie un doc dans la pile FluxBox pour re-classement
window.sendDocToReclass = async function(docId) {
    if (!confirm('Re-classer ce document via la pile FluxBox ?')) return;
    try {
        const res = await fetch('/api/ged_doc_send_to_reclass.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ doc_id: docId }),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (data.ok && data.redirect_url) {
            window.location.href = data.redirect_url;
        } else {
            alert('❌ Erreur : ' + (data.error || 'inconnue'));
        }
    } catch (e) {
        alert('❌ Réseau : ' + e.message);
    }
};
</script>

</body>
</html>
