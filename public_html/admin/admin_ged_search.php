<?php
/**
 * admin/admin_ged_search.php
 *
 * GED CENTRALE — Recherche globale transversale.
 *
 * Recherche unifiée dans `ged_documents` + `ged_document_links`. Permet de trouver
 * un document par : nom (display/file/canonical), type, module, hash, ou par
 * l'entité liée (bien adresse/désignation/référence, propriétaire nom/email,
 * immeuble nom/adresse, mandat, bail).
 *
 * Résultats avec liens directs vers fiches 360° et page audit par entité.
 *
 * Réservé super admin (id_role=1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$q          = trim((string)($_GET['q'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
$filterModule = trim((string)($_GET['module'] ?? ''));
$dateFrom   = trim((string)($_GET['from'] ?? ''));
$dateTo     = trim((string)($_GET['to'] ?? ''));
$limit      = max(1, min(500, (int)($_GET['limit'] ?? 100)));

$results = [];
$totals = ['total' => 0, 'unique_docs' => 0, 'by_type' => [], 'by_entity' => []];

if ($q !== '' || $filterType !== '' || $filterModule !== '' || $dateFrom !== '' || $dateTo !== '') {
    // Construction WHERE dynamique
    $where = ["d.status = 'active'"];
    $args  = [];

    if ($q !== '') {
        // Recherche dans : noms du doc, hash, OU liens entité (bien/immeuble/tiers texte)
        $like = '%' . $q . '%';
        $where[] = "(d.name_display LIKE ? OR d.name_file LIKE ? OR d.name_canonical LIKE ?
                     OR d.hash_sha256 = ?
                     OR EXISTS (
                         SELECT 1 FROM ged_document_links dl
                         LEFT JOIN biens b      ON dl.entity_type = 'BIEN' AND b.id = dl.entity_id
                         LEFT JOIN immeubles i  ON dl.entity_type = 'IMB'  AND i.id = dl.entity_id
                         LEFT JOIN tiers t      ON dl.entity_type = 'TIERS' AND t.id = dl.entity_id
                         WHERE dl.document_id = d.id
                           AND (
                                b.designation LIKE ? OR b.reference_bien LIKE ? OR b.adresse_1 LIKE ? OR b.ville LIKE ?
                             OR i.nom_immeuble LIKE ? OR i.adresse_1 LIKE ? OR i.ville LIKE ?
                             OR t.raison_sociale LIKE ? OR t.nom LIKE ? OR t.prenom LIKE ? OR t.email LIKE ?
                           )
                     ))";
        for ($i = 0; $i < 4; $i++) $args[] = $like;
        $args[] = $q; // hash exact
        for ($i = 0; $i < 11; $i++) $args[] = $like;
    }

    if ($filterType !== '') {
        $where[] = "d.document_type = ?";
        $args[] = $filterType;
    }
    if ($filterModule !== '') {
        $where[] = "d.source_module = ?";
        $args[] = $filterModule;
    }
    if ($dateFrom !== '') {
        $where[] = "d.created_at >= ?";
        $args[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = "d.created_at <= ?";
        $args[] = $dateTo . ' 23:59:59';
    }

    $sql = "SELECT d.id, d.uuid, d.tenant_id, d.societe_id, d.agence_id,
                   d.name_display, d.name_canonical, d.name_file,
                   d.document_type, d.source_module, d.mime_type, d.size_bytes,
                   d.hash_sha256, d.linked_entities, d.created_at,
                   (SELECT COUNT(*) FROM ged_document_links dl WHERE dl.document_id = d.id) AS nb_links
            FROM ged_documents d
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.created_at DESC
            LIMIT $limit";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totals['total'] = count($results);
        $totals['unique_docs'] = $totals['total'];
        foreach ($results as $r) {
            $t = (string)($r['document_type'] ?? 'AUTRE');
            $totals['by_type'][$t] = ($totals['by_type'][$t] ?? 0) + 1;
            $links = json_decode((string)($r['linked_entities'] ?? '[]'), true) ?: [];
            foreach ($links as $l) {
                $key = (string)($l['type'] ?? '?');
                $totals['by_entity'][$key] = ($totals['by_entity'][$key] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Listes pour filtres (depuis BDD réelle)
$types = []; $modules = [];
try {
    $st = $pdo->query("SELECT document_type, COUNT(*) n FROM ged_documents WHERE status='active' AND document_type IS NOT NULL GROUP BY document_type ORDER BY n DESC LIMIT 50");
    $types = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $st = $pdo->query("SELECT source_module, COUNT(*) n FROM ged_documents WHERE status='active' AND source_module IS NOT NULL GROUP BY source_module ORDER BY n DESC LIMIT 30");
    $modules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

include __DIR__ . '/../inc/header.php';
?>
<style>
.ges-wrap { max-width: 1400px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; font-size: 13px; }
.ges-wrap h1 { color: #243B5C; font-size: 22px; margin-bottom: 4px; }
.ges-form { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; box-shadow: 2px 2px 6px #e3dfd8; }
.ges-form-row { display: grid; grid-template-columns: 2fr 1fr 1fr 120px 120px 100px auto; gap: 8px; align-items: center; }
.ges-form input, .ges-form select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; font-family: inherit; }
.ges-form button { padding: 8px 18px; background: #243B5C; color: #fff; border: 0; border-radius: 4px; font-weight: 700; cursor: pointer; }
.ges-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 12px 0; }
.ges-stat { background: #fff; padding: 12px; border-radius: 8px; border-left: 4px solid #D4A047; }
.ges-stat .v { font-size: 22px; font-weight: 800; color: #2c2a28; }
.ges-stat .l { font-size: 10px; color: #7a766f; text-transform: uppercase; }
.ges-tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
.ges-tbl th { background: #f1eee9; padding: 10px; text-align: left; font-size: 11px; }
.ges-tbl td { padding: 8px 10px; border-bottom: 1px solid #f1eee9; font-size: 12px; vertical-align: top; }
.ges-tbl tr:hover td { background: #fef9e7; }
.ges-tbl a { color: #243B5C; text-decoration: none; font-weight: 600; }
.ges-tbl a:hover { text-decoration: underline; }
.ges-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; margin-right: 4px; }
.ges-t-bien { background: #84a98c; color: #fff; }
.ges-t-imb { background: #7c9885; color: #fff; }
.ges-t-tiers { background: #0e7490; color: #fff; }
.ges-t-mdt { background: #f59e0b; color: #fff; }
.ges-t-bail { background: #2d5f6b; color: #fff; }
.ges-empty { text-align: center; padding: 40px; color: #7a766f; font-style: italic; }
.ges-quick { display: flex; gap: 6px; flex-wrap: wrap; margin: 6px 0; font-size: 11px; }
.ges-quick a { padding: 4px 10px; background: #e3dfd8; color: #2c2a28; text-decoration: none; border-radius: 4px; }
.ges-quick a:hover { background: #D4A047; color: #fff; }
</style>

<div class="ges-wrap">
    <h1>🔎 Recherche globale GED</h1>
    <p style="color: #7a766f;">Recherche transversale dans tous les documents — par nom, type, hash, ou par entité liée (bien, propriétaire, immeuble).</p>

    <form method="GET" class="ges-form">
        <div class="ges-form-row">
            <input type="text" name="q" value="<?= $h($q) ?>" placeholder="🔍 nom, hash, adresse, propriétaire, référence..." autofocus>
            <select name="type">
                <option value="">— Tous types —</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= $h($t['document_type']) ?>" <?= $filterType === $t['document_type'] ? 'selected' : '' ?>><?= $h($t['document_type']) ?> (<?= $t['n'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="module">
                <option value="">— Tous modules —</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= $h($m['source_module']) ?>" <?= $filterModule === $m['source_module'] ? 'selected' : '' ?>><?= $h($m['source_module']) ?> (<?= $m['n'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= $h($dateFrom) ?>" title="Date de début">
            <input type="date" name="to" value="<?= $h($dateTo) ?>" title="Date de fin">
            <input type="number" name="limit" value="<?= (int)$limit ?>" min="10" max="500" title="Limite résultats">
            <button type="submit">Chercher</button>
        </div>
    </form>

    <div class="ges-quick">
        <strong>Raccourcis :</strong>
        <a href="?type=DIAG_DPE">📊 Tous DPE</a>
        <a href="?type=MANDAT_VENTE">📜 Mandats vente</a>
        <a href="?type=BAIL">📝 Baux</a>
        <a href="?type=ACTE_AUTHENTIQUE">⚖️ Actes</a>
        <a href="?module=05_TRANSACTION">💼 Module Transaction</a>
        <a href="?module=03_GESTION_LOCATIVE">🏠 Module Gestion</a>
        <a href="?q=&from=<?= date('Y-m-d', strtotime('-7 days')) ?>">🕐 7 derniers jours</a>
        <a href="?q=&from=<?= date('Y-m-d', strtotime('-30 days')) ?>">📅 30 derniers jours</a>
    </div>

    <?php if (!empty($error ?? null)): ?>
        <div class="ges-stat" style="border-left-color: #dc2626;"><div class="v">❌ Erreur</div><div class="l"><?= $h($error) ?></div></div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="ges-stats">
            <div class="ges-stat"><div class="v"><?= $totals['total'] ?></div><div class="l">Documents trouvés</div></div>
            <div class="ges-stat"><div class="v"><?= count($totals['by_type']) ?></div><div class="l">Types distincts</div></div>
            <div class="ges-stat"><div class="v"><?= array_sum($totals['by_entity']) ?></div><div class="l">Liens entités totaux</div></div>
        </div>

        <table class="ges-tbl">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>name_display</th>
                    <th>Type / Module</th>
                    <th>Entités liées</th>
                    <th>Taille</th>
                    <th>Créé</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r):
                    $links = json_decode((string)($r['linked_entities'] ?? '[]'), true) ?: []; ?>
                    <tr>
                        <td><strong>#<?= (int)$r['id'] ?></strong></td>
                        <td>
                            <strong><?= $h($r['name_display']) ?></strong><br>
                            <small style="color: #7a766f; font-size: 10px;"><?= $h($r['name_file']) ?></small>
                        </td>
                        <td>
                            <span class="ges-tag" style="background:#243B5C;color:#fff;"><?= $h($r['document_type']) ?></span><br>
                            <small style="color: #7a766f;"><?= $h($r['source_module']) ?></small>
                        </td>
                        <td>
                            <?php foreach ($links as $l):
                                $type = (string)($l['type'] ?? '?');
                                $id   = (int)($l['id'] ?? 0);
                                $cls  = match ($type) {
                                    'BIEN' => 'ges-t-bien', 'IMB' => 'ges-t-imb', 'TIERS' => 'ges-t-tiers',
                                    'MDT' => 'ges-t-mdt', 'BAIL' => 'ges-t-bail', default => '',
                                };
                                $url = match ($type) {
                                    'BIEN' => app_url('/bien_360.php?id=' . $id),
                                    'TIERS' => app_url('/tiers_documents_list.php?id=' . $id),
                                    'IMB' => app_url('/admin/admin_audit_documents_entite.php?entity_type=IMB&entity_id=' . $id),
                                    default => app_url('/admin/admin_audit_documents_entite.php?entity_type=' . $type . '&entity_id=' . $id),
                                };
                            ?>
                                <a href="<?= $h($url) ?>" target="_blank" class="ges-tag <?= $cls ?>"><?= $h($type) ?>#<?= $id ?></a>
                            <?php endforeach; ?>
                        </td>
                        <td><?= (int)$r['size_bytes'] > 0 ? round((int)$r['size_bytes'] / 1024) . ' ko' : '—' ?></td>
                        <td><?= $h(substr((string)$r['created_at'], 0, 16)) ?></td>
                        <td>
                            <a href="<?= $h(app_url('/admin/admin_ged_doc_dump.php?id=' . (int)$r['id'])) ?>" target="_blank" title="Voir détail">🔬</a>
                            <?php if (!empty($links[0])): ?>
                                <a href="<?= $h(app_url('/admin/admin_audit_documents_entite.php?entity_type=' . $links[0]['type'] . '&entity_id=' . (int)$links[0]['id'])) ?>" target="_blank" title="Audit entité">📂</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($totals['by_type'])): ?>
        <div style="margin-top: 20px; padding: 12px; background: #fff; border-radius: 8px;">
            <strong style="color: #243B5C;">Répartition par type :</strong>
            <?php foreach ($totals['by_type'] as $t => $n): ?>
                <span class="ges-tag" style="background:#243B5C;color:#fff;"><?= $h($t) ?> · <?= $n ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php elseif ($q !== '' || $filterType !== '' || $filterModule !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
        <div class="ges-empty">Aucun document trouvé pour ces critères. Essaie d'élargir la recherche.</div>
    <?php else: ?>
        <div class="ges-empty">
            👋 Tape ta recherche en haut.<br><br>
            <strong>Exemples</strong> :<br>
            • <code>SABY</code> → tous les docs liés au propriétaire SABY<br>
            • <code>Boulevard Pinel</code> → docs des biens à cette adresse<br>
            • <code>DPE</code> → tous les diagnostics<br>
            • <code>2024</code> → docs avec "2024" dans le nom<br>
            • un hash SHA-256 → trouver un doc précis
        </div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 14px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047; font-size: 12px;">
        <strong>📚 GED CENTRALE UNIQUE</strong> — recherche sur <code>ged_documents</code> + <code>ged_document_links</code>.
        Tous les documents quel que soit leur module d'upload (FluxBox, bien_intake, transaction, dpe_import, immeuble, bailleur_ged, agency_proprio_fiche, p/upload) apparaissent ici.
    </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
