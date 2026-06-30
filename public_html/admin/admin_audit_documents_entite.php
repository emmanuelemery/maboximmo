<?php
/**
 * admin/admin_audit_documents_entite.php
 *
 * GED CENTRALE UNIQUE — Audit transversal des documents par entité.
 *
 * Affiche TOUS les documents rattachés à un BIEN / PROPRIETAIRE (TIERS) / IMMEUBLE
 * en lecture directe depuis ged_documents + ged_document_links (source unique).
 *
 * Compare avec les tables legacy (biens_documents, bailleur_documents,
 * immeubles_documents) pour identifier les docs non encore migrés.
 *
 * Réservé super admin (id_role=1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ged_document_links.php';
require_login();

$pdo = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$entityType = strtoupper(trim((string)($_GET['entity_type'] ?? 'BIEN')));
$entityId   = (int)($_GET['entity_id'] ?? 0);

$gedDocs = [];
$legacyDocs = [];
$entityInfo = null;
$legacyTable = null;

if ($entityId > 0) {
    try {
        $gedDocs = gdl_documents_for_entity($pdo, $entityType, $entityId, [
            'status'   => 'active',
            'limit'    => 500,
        ]);
    } catch (Throwable $e) {
        $gedDocs = [];
    }

    // Identification entité + recherche legacy
    switch ($entityType) {
        case 'BIEN':
            $st = $pdo->prepare("SELECT b.id, b.designation, b.reference_bien, b.adresse_1, b.code_postal, b.ville,
                                         b.id_proprietaire, b.id_immeuble
                                  FROM biens b WHERE b.id = ?");
            $st->execute([$entityId]);
            $entityInfo = $st->fetch(PDO::FETCH_ASSOC);
            $legacyTable = 'biens_documents';
            try {
                $st = $pdo->prepare("SELECT id, type_document, libelle, nom_original, taille_octets, date_upload
                                      FROM biens_documents WHERE id_bien = ? ORDER BY date_upload DESC LIMIT 100");
                $st->execute([$entityId]);
                $legacyDocs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {}
            break;

        case 'TIERS':
            // Trouver le proprietaires.id correspondant pour la table legacy bailleur_documents
            $st = $pdo->prepare("SELECT t.id, t.raison_sociale, t.nom, t.prenom, t.email,
                                         p.id AS proprio_id_legacy
                                  FROM tiers t LEFT JOIN proprietaires p ON p.id_tiers = t.id
                                  WHERE t.id = ? LIMIT 1");
            $st->execute([$entityId]);
            $entityInfo = $st->fetch(PDO::FETCH_ASSOC);
            $legacyTable = 'bailleur_documents';
            if ($entityInfo && !empty($entityInfo['proprio_id_legacy'])) {
                try {
                    $st = $pdo->prepare("SELECT id, type_document, titre, nom_fichier, taille, date_upload
                                          FROM bailleur_documents WHERE id_proprietaire = ?
                                          ORDER BY date_upload DESC LIMIT 100");
                    $st->execute([(int)$entityInfo['proprio_id_legacy']]);
                    $legacyDocs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {}
            }
            break;

        case 'IMB':
        case 'IMMEUBLE':
            $entityType = 'IMB';
            $st = $pdo->prepare("SELECT id, nom_immeuble, adresse_1, code_postal, ville FROM immeubles WHERE id = ?");
            $st->execute([$entityId]);
            $entityInfo = $st->fetch(PDO::FETCH_ASSOC);
            $legacyTable = 'immeubles_documents';
            try {
                $st = $pdo->prepare("SELECT id, type_document, sous_type, nom_fichier, taille_octets, created_at AS date_upload
                                      FROM immeubles_documents WHERE id_immeuble = ?
                                      ORDER BY id DESC LIMIT 100");
                $st->execute([$entityId]);
                $legacyDocs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {}
            break;
    }
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.aud-wrap { max-width: 1300px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; }
.aud-wrap h1 { color: #243B5C; font-size: 22px; margin-bottom: 4px; }
.aud-form { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.aud-form select, .aud-form input { padding: 8px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; }
.aud-form button { padding: 8px 18px; background: #243B5C; color: #fff; border: 0; border-radius: 4px; font-weight: 700; cursor: pointer; }
.aud-quick { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0; }
.aud-quick a { padding: 4px 10px; background: #e3dfd8; color: #2c2a28; text-decoration: none; border-radius: 4px; font-size: 11px; }
.aud-quick a:hover { background: #D4A047; color: #fff; }
.aud-card { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; box-shadow: 2px 2px 6px #e3dfd8; }
.aud-card h2 { margin: 0 0 12px; color: #243B5C; font-size: 18px; }
.aud-card h3 { margin: 12px 0 6px; color: #2c2a28; font-size: 14px; }
.aud-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.aud-tbl th { background: #f1eee9; padding: 8px 10px; text-align: left; }
.aud-tbl td { padding: 6px 10px; border-bottom: 1px solid #f1eee9; }
.aud-tbl tr:hover td { background: #fef9e7; }
.aud-badge { padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.aud-b-ged { background: #d1fae5; color: #065f46; }
.aud-b-legacy { background: #fee2e2; color: #991b1b; }
.aud-b-main { background: #dbeafe; color: #1e3a8a; }
.aud-b-annexe { background: #f3e8ff; color: #581c87; }
.aud-empty { color: #7a766f; font-style: italic; padding: 20px; text-align: center; }
.aud-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 12px 0; }
.aud-stat { background: #fff; padding: 12px; border-radius: 8px; text-align: center; border-left: 4px solid #D4A047; }
.aud-stat .v { font-size: 22px; font-weight: 800; color: #2c2a28; }
.aud-stat .l { font-size: 10px; color: #7a766f; text-transform: uppercase; }
</style>

<div class="aud-wrap">
    <h1>🔍 Audit GED transversal par entité</h1>
    <p style="color: #7a766f; font-size: 13px;">
        Vue unifiée : tous les documents rattachés à une entité métier (bien / propriétaire / immeuble)
        via <code>ged_document_links</code>. Compare avec les tables legacy pour identifier les docs non migrés.
    </p>

    <form method="GET" class="aud-form">
        <label>Entité :</label>
        <select name="entity_type">
            <?php foreach (['BIEN' => '🏠 Bien', 'TIERS' => '👤 Tiers (propriétaire/locataire)', 'IMB' => '🏢 Immeuble'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $entityType === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <label>ID :</label>
        <input type="number" name="entity_id" value="<?= (int)$entityId ?>" min="1" required style="width: 100px;">
        <button type="submit">🔍 Auditer</button>
    </form>

    <div class="aud-quick">
        <strong style="font-size: 11px; align-self: center;">Raccourcis :</strong>
        <a href="?entity_type=BIEN&entity_id=726">Bien #726 (test refactor)</a>
        <a href="?entity_type=BIEN&entity_id=733">Bien #733 (ged_doc #682)</a>
        <a href="?entity_type=TIERS&entity_id=5">Tiers #5 (SABY)</a>
        <a href="?entity_type=IMB&entity_id=830">Immeuble #830</a>
    </div>

    <?php if ($entityId > 0 && $entityInfo): ?>
        <div class="aud-card">
            <h2><?= $h($entityType) ?> #<?= (int)$entityId ?>
                <?php if ($entityType === 'BIEN'): ?>
                    — <?= $h($entityInfo['designation'] ?? $entityInfo['reference_bien'] ?? '?') ?>
                    <small style="color: #7a766f; font-size: 12px;"><?= $h(trim(($entityInfo['adresse_1'] ?? '') . ' ' . ($entityInfo['code_postal'] ?? '') . ' ' . ($entityInfo['ville'] ?? ''))) ?></small>
                <?php elseif ($entityType === 'TIERS'): ?>
                    — <?= $h($entityInfo['raison_sociale'] ?: trim(($entityInfo['prenom'] ?? '') . ' ' . ($entityInfo['nom'] ?? ''))) ?>
                    <?php if (!empty($entityInfo['email'])): ?><small style="color: #7a766f; font-size: 12px;"><?= $h($entityInfo['email']) ?></small><?php endif; ?>
                <?php elseif ($entityType === 'IMB'): ?>
                    — <?= $h($entityInfo['nom_immeuble'] ?? '?') ?>
                    <small style="color: #7a766f; font-size: 12px;"><?= $h(trim(($entityInfo['adresse_1'] ?? '') . ' ' . ($entityInfo['code_postal'] ?? '') . ' ' . ($entityInfo['ville'] ?? ''))) ?></small>
                <?php endif; ?>
            </h2>

            <div class="aud-stats">
                <div class="aud-stat"><div class="v"><?= count($gedDocs) ?></div><div class="l">Docs GED centrale</div></div>
                <div class="aud-stat"><div class="v"><?= count($legacyDocs) ?></div><div class="l">Docs legacy (<?= $h($legacyTable) ?>)</div></div>
                <div class="aud-stat" style="border-left-color: <?= count($legacyDocs) > count($gedDocs) ? '#dc2626' : '#16a34a' ?>;">
                    <div class="v"><?= count($legacyDocs) - count($gedDocs) ?></div>
                    <div class="l">Écart (legacy - GED)</div>
                </div>
                <div class="aud-stat">
                    <div class="v"><?= count($gedDocs) > 0 ? array_sum(array_map(fn($d) => (int)$d['size_bytes'], $gedDocs)) > 0 ? round(array_sum(array_map(fn($d) => (int)$d['size_bytes'], $gedDocs))/1024/1024, 1) : 0 : 0 ?> Mo</div>
                    <div class="l">Taille GED</div>
                </div>
            </div>

            <h3>✅ GED CENTRALE (ged_documents + ged_document_links)</h3>
            <?php if (empty($gedDocs)): ?>
                <div class="aud-empty">Aucun document GED pour cette entité.</div>
            <?php else: ?>
                <table class="aud-tbl">
                    <thead>
                        <tr>
                            <th>ID</th><th>Lien</th><th>Type</th><th>Module</th>
                            <th>name_display</th><th>name_file</th>
                            <th>Taille</th><th>Créé</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gedDocs as $d):
                            $rel = (string)($d['link_relation_type'] ?? 'main');
                            $relBadge = $rel === 'main' ? 'aud-b-main' : 'aud-b-annexe'; ?>
                            <tr>
                                <td><strong>#<?= (int)$d['id'] ?></strong></td>
                                <td><span class="aud-badge <?= $relBadge ?>"><?= $h($rel) ?></span></td>
                                <td><?= $h($d['document_type'] ?? '—') ?></td>
                                <td><?= $h($d['source_module'] ?? '—') ?></td>
                                <td><?= $h($d['name_display'] ?? '—') ?></td>
                                <td style="font-size: 10px; color: #7a766f;"><?= $h($d['name_file'] ?? '—') ?></td>
                                <td><?= (int)$d['size_bytes'] > 0 ? round((int)$d['size_bytes']/1024) . ' ko' : '—' ?></td>
                                <td><?= $h(substr((string)$d['created_at'], 0, 16)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3 style="margin-top: 24px;">⚠️ Legacy (<?= $h($legacyTable) ?>) — à migrer si non vide</h3>
            <?php if (empty($legacyDocs)): ?>
                <div class="aud-empty">✅ Aucun doc dans la table legacy.</div>
            <?php else: ?>
                <table class="aud-tbl">
                    <thead>
                        <tr><th>ID legacy</th><th>Type</th><th>Titre/Libellé</th><th>Nom original</th><th>Taille</th><th>Upload</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($legacyDocs as $d): ?>
                            <tr>
                                <td><span class="aud-badge aud-b-legacy">L#<?= (int)$d['id'] ?></span></td>
                                <td><?= $h($d['type_document'] ?? '—') ?></td>
                                <td><?= $h($d['titre'] ?? $d['libelle'] ?? $d['sous_type'] ?? '—') ?></td>
                                <td><?= $h($d['nom_original'] ?? $d['nom_fichier'] ?? '—') ?></td>
                                <td><?= (int)($d['taille'] ?? $d['taille_octets'] ?? 0) > 0 ? round((int)($d['taille'] ?? $d['taille_octets']) /1024) . ' ko' : '—' ?></td>
                                <td><?= $h(substr((string)($d['date_upload'] ?? ''), 0, 16)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php elseif ($entityId > 0): ?>
        <div class="aud-card"><div class="aud-empty">Entité <?= $h($entityType) ?> #<?= (int)$entityId ?> introuvable en BDD.</div></div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 14px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047; font-size: 12px;">
        <strong>📚 Décision GED CENTRALE UNIQUE 2026-05-25</strong>
        <ul style="margin: 8px 0 0 16px;">
            <li>Source unique : <code>ged_documents</code> + <code>ged_document_links</code></li>
            <li>Pipeline unique : <code>gus_commit_document()</code></li>
            <li>Tables legacy en transition : <code>biens_documents</code>, <code>bailleur_documents</code>, <code>immeubles_documents</code></li>
            <li>5 endpoints d'upload migrés (Sprint 7B + 7D) : bien_intake, immeuble_doc, bailleur_ged, agency_proprio_fiche, dpe_import, p/upload</li>
            <li>Migration douce des docs legacy historiques → script à venir</li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
