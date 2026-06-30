<?php
/**
 * tiers_documents_list.php
 *
 * Liste tous les documents liés à un tiers via ged_document_links
 * (entity_type='TIERS', entity_id=X).
 *
 * Couvre propriétaire / locataire / acquéreur / partenaire selon `tiers.type_tiers`.
 *
 * Sprint 5 — multi-entités.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$tiersId = (int)($_GET['id'] ?? 0);
if ($tiersId <= 0) { http_response_code(400); exit('id requis'); }

function tdl_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Lecture tiers + scope
$st = $pdo->prepare("SELECT id, type_tiers, sous_type, civilite, nom, prenom, raison_sociale, nom_affichage,
                            email, telephone, id_societe, id_agence
                     FROM tiers WHERE id = ?");
$st->execute([$tiersId]);
$tiers = $st->fetch(PDO::FETCH_ASSOC);
if (!$tiers) { http_response_code(404); exit("Tiers #$tiersId introuvable"); }

$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$isAdmin && (int)$tiers['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403); exit('Hors société');
}

$labelTiers = $tiers['raison_sociale'] ?: trim(($tiers['civilite'] ?? '').' '.($tiers['nom'] ?? '').' '.($tiers['prenom'] ?? ''));

// Documents liés
$docs = [];
try {
    $sql = "
        SELECT DISTINCT d.id, d.uuid, d.name_display, d.name_file, d.document_type, d.source_module,
               d.mime_type, d.size_bytes, d.created_at, d.folder_id,
               l.relation_type, l.is_validated, l.validated_at,
               f.name_display AS folder_name
        FROM ged_document_links l
        JOIN ged_documents d ON d.id = l.document_id
        LEFT JOIN ged_folders f ON f.id = d.folder_id
        WHERE l.entity_type = 'TIERS' AND l.entity_id = ?
          AND d.status = 'active'
        ORDER BY d.created_at DESC
        LIMIT 200
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$tiersId]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$byModule = [];
foreach ($docs as $d) {
    $mod = $d['source_module'] ?: 'autre';
    $byModule[$mod][] = $d;
}

// Biens associés à ce tiers (proprios via biens.id_tiers OU id_proprietaire)
$biens = [];
try {
    $st = $pdo->prepare("SELECT id, designation, adresse_1 FROM biens
                         WHERE id_tiers = ? OR id_proprietaire IN (SELECT id FROM proprietaires WHERE id_tiers = ?)
                         LIMIT 20");
    $st->execute([$tiersId, $tiersId]);
    $biens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>📁 Documents · Tiers #<?= (int)$tiersId ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="inc/css/mvpt_docs_list.css">
</head>
<body>

<header class="hdr-proprio">
    <h1>📁 Documents du tiers #<?= (int)$tiersId ?></h1>
    <span class="badge-proprio">SPRINT 5 · TIERS</span>
    <nav><a href="tiers_360.php?id=<?= (int)$tiersId ?>">← Retour tiers 360°</a></nav>
</header>

<div class="container">
    <div class="panel">
        <h2>👤 Tiers</h2>
        <div style="font-size: 12px;">
            <b>#<?= (int)$tiers['id'] ?></b> ·
            <?= tdl_html((string)$labelTiers) ?>
            <?php if (!empty($tiers['type_tiers'])): ?>
                · <code><?= tdl_html((string)$tiers['type_tiers']) ?></code>
            <?php endif; ?>
            <br><span style="color: var(--muted);">
                <?php if (!empty($tiers['email'])): ?>📧 <?= tdl_html((string)$tiers['email']) ?><?php endif; ?>
                <?php if (!empty($tiers['telephone'])): ?> · 📞 <?= tdl_html((string)$tiers['telephone']) ?><?php endif; ?>
            </span>
        </div>
    </div>

    <?php if (!empty($biens)): ?>
    <div class="info-zone">
        <span class="lbl">📤 Pour charger un document, choisis un bien lié à ce tiers :</span>
        <div class="bien-list">
            <?php foreach (array_slice($biens, 0, 10) as $b): ?>
                <a href="bien_documents_list.php?id=<?= (int)$b['id'] ?>" class="bien-chip">
                    🏠 #<?= (int)$b['id'] ?> <?= tdl_html(mb_substr((string)($b['designation'] ?? $b['adresse_1'] ?? ''), 0, 30)) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="stat-row">
        <div class="stat-box"><div class="v"><?= count($docs) ?></div><div class="l">Documents</div></div>
        <div class="stat-box"><div class="v"><?= count($byModule) ?></div><div class="l">Modules</div></div>
        <div class="stat-box"><div class="v"><?= count($biens) ?></div><div class="l">Biens liés</div></div>
    </div>

    <?php if (empty($docs)): ?>
        <div class="panel">
            <div class="empty">Aucun document directement lié à ce tiers.</div>
        </div>
    <?php else: ?>
        <?php foreach ($byModule as $module => $items): ?>
            <div class="module-card">
                <h3>📂 <?= tdl_html((string)$module) ?> (<?= count($items) ?>)</h3>
                <?php foreach ($items as $d): ?>
                    <div class="doc-item">
                        <div>
                            <a href="javascript:void(0)" class="doc-link"
                               onclick="mvptModalView(<?= (int)$d['id'] ?>, '<?= tdl_html((string)($d['name_display'] ?? $d['name_file'])) ?>')">
                                📄 <?= tdl_html((string)($d['name_display'] ?? $d['name_file'])) ?>
                            </a>
                            <?php if (!empty($d['relation_type'])): ?>
                                <span class="badge-rel <?= tdl_html((string)$d['relation_type']) ?>"><?= tdl_html((string)$d['relation_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($d['document_type'])): ?>
                                <span class="meta">· <?= tdl_html((string)$d['document_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($d['folder_name'])): ?>
                                <div class="meta">📁 <?= tdl_html((string)$d['folder_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="meta"><?= number_format((int)$d['size_bytes']/1024) ?> Ko</div>
                        <div class="date"><?= tdl_html(substr((string)$d['created_at'], 0, 10)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>

</body>
</html>
