<?php
/**
 * admin/admin_ged_doc_dump.php
 *
 * Debug endpoint — dump le metadata complet d'un ged_doc + test du marker
 * d'idempotence (`metadata.extra.legacy_biens_doc_id`).
 *
 * Usage : ?id=<ged_doc_id>[&legacy_id=<biens_documents.id>]
 *
 * Permet de diagnostiquer pourquoi admin_migrate_legacy_to_ged compte
 * "Déjà migrés : 71" au lieu de 87 en prod après APPLY.
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

$gedId    = (int)($_GET['id'] ?? 0);
$legacyId = (int)($_GET['legacy_id'] ?? 0);

$doc = null;
$links = [];
$markerTests = [];

if ($gedId > 0) {
    try {
        $st = $pdo->prepare("SELECT id, uuid, tenant_id, societe_id, agence_id,
                                    name_display, name_canonical, name_file,
                                    document_type, source_module, mime_type, size_bytes,
                                    hash_sha256, metadata, linked_entities, status,
                                    fluxbox_source_id, created_by, created_at, updated_at
                              FROM ged_documents WHERE id = ?");
        $st->execute([$gedId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT id, entity_type, entity_id, relation_type, confidence,
                                    is_validated, created_at
                              FROM ged_document_links WHERE document_id = ? ORDER BY id");
        $st->execute([$gedId]);
        $links = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $doc = ['__error__' => $e->getMessage()];
    }
}

// Tests du marker legacy_biens_doc_id avec plusieurs variantes de requête
if ($legacyId > 0) {
    $variants = [
        'V1_brut_int' => [
            'sql'  => "SELECT id FROM ged_documents WHERE JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id') = ? LIMIT 1",
            'bind' => [$legacyId],
        ],
        'V2_brut_string' => [
            'sql'  => "SELECT id FROM ged_documents WHERE JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id') = ? LIMIT 1",
            'bind' => [(string)$legacyId],
        ],
        'V3_unquote_int' => [
            'sql'  => "SELECT id FROM ged_documents WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id')) = ? LIMIT 1",
            'bind' => [$legacyId],
        ],
        'V4_unquote_string' => [
            'sql'  => "SELECT id FROM ged_documents WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id')) = ? LIMIT 1",
            'bind' => [(string)$legacyId],
        ],
        'V5_cast_unsigned' => [
            'sql'  => "SELECT id FROM ged_documents WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id')) AS UNSIGNED) = ? LIMIT 1",
            'bind' => [$legacyId],
        ],
        'V6_like_number' => [
            'sql'  => "SELECT id FROM ged_documents WHERE metadata LIKE ? LIMIT 1",
            'bind' => ['%"legacy_biens_doc_id":' . $legacyId . '%'],
        ],
        'V7_like_string' => [
            'sql'  => "SELECT id FROM ged_documents WHERE metadata LIKE ? LIMIT 1",
            'bind' => ['%"legacy_biens_doc_id":"' . $legacyId . '"%'],
        ],
        'V8_json_search' => [
            'sql'  => "SELECT id FROM ged_documents WHERE JSON_SEARCH(metadata, 'one', ?, NULL, '\$.extra.legacy_biens_doc_id') IS NOT NULL LIMIT 1",
            'bind' => [(string)$legacyId],
        ],
    ];

    foreach ($variants as $name => $v) {
        try {
            $st = $pdo->prepare($v['sql']);
            $st->execute($v['bind']);
            $result = $st->fetchColumn();
            $markerTests[$name] = [
                'sql'    => $v['sql'],
                'bind'   => $v['bind'],
                'match'  => $result ? "ged #$result" : 'NULL (no match)',
                'status' => $result ? '✅' : '❌',
            ];
        } catch (Throwable $e) {
            $markerTests[$name] = [
                'sql'    => $v['sql'],
                'bind'   => $v['bind'],
                'match'  => 'ERROR: ' . $e->getMessage(),
                'status' => '⚠️',
            ];
        }
    }
}

// Trouver le ged_doc le plus récent avec un marker legacy_biens_doc_id (pour aider l'audit)
$lastWithMarker = null;
try {
    $st = $pdo->query("SELECT id, name_display,
                              JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id')) AS legacy_id_unquote,
                              JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id') AS legacy_id_raw,
                              JSON_EXTRACT(metadata, '\$.extra.legacy_source') AS legacy_source
                       FROM ged_documents
                       WHERE JSON_EXTRACT(metadata, '\$.extra.legacy_biens_doc_id') IS NOT NULL
                       ORDER BY id DESC LIMIT 5");
    $lastWithMarker = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $lastWithMarker = [['__error__' => $e->getMessage()]];
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.dbg-wrap { max-width: 1400px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; font-size: 13px; }
.dbg-wrap h1 { color: #243B5C; font-size: 22px; }
.dbg-form { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; display: flex; gap: 10px; align-items: center; }
.dbg-form input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
.dbg-form button { padding: 8px 18px; background: #243B5C; color: #fff; border: 0; border-radius: 4px; font-weight: 700; cursor: pointer; }
.dbg-card { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; box-shadow: 2px 2px 6px #e3dfd8; }
.dbg-card h2 { margin: 0 0 12px; color: #243B5C; font-size: 16px; }
.dbg-json { background: #0f172a; color: #d1d5db; padding: 14px; border-radius: 6px; white-space: pre-wrap; font-size: 11px; max-height: 400px; overflow-y: auto; }
.dbg-row { display: grid; grid-template-columns: 200px 1fr; gap: 8px; padding: 4px 0; border-bottom: 1px solid #f1eee9; }
.dbg-row .l { color: #7a766f; font-weight: 700; }
.dbg-row .v { color: #2c2a28; word-break: break-all; }
.dbg-tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin: 8px 0; }
.dbg-tbl th { background: #f1eee9; padding: 8px 10px; text-align: left; }
.dbg-tbl td { padding: 6px 10px; border-bottom: 1px solid #f1eee9; }
.dbg-tbl code { background: #f3f4f6; padding: 2px 4px; border-radius: 3px; font-size: 11px; }
.dbg-pass { color: #16a34a; font-weight: 700; }
.dbg-fail { color: #dc2626; font-weight: 700; }
</style>

<div class="dbg-wrap">
    <h1>🔬 Debug ged_doc dump + test idempotence marker</h1>
    <p style="color: #7a766f;">Outil de diagnostic pour comprendre pourquoi admin_migrate_legacy_to_ged compte les marker manquants.</p>

    <form method="GET" class="dbg-form">
        <label>GED doc ID :</label>
        <input type="number" name="id" value="<?= (int)$gedId ?>" min="1" style="width:120px;" placeholder="ex: 12">
        <label>Legacy ID (optionnel) :</label>
        <input type="number" name="legacy_id" value="<?= (int)$legacyId ?>" min="0" style="width:120px;" placeholder="ex: 14">
        <button type="submit">🔍 Dump</button>
    </form>

    <?php if (!empty($lastWithMarker) && empty($lastWithMarker[0]['__error__'])): ?>
    <div class="dbg-card">
        <h2>📌 5 derniers ged_documents AVEC marker legacy_biens_doc_id</h2>
        <table class="dbg-tbl">
            <thead><tr><th>ged ID</th><th>name_display</th><th>legacy_id_raw (JSON)</th><th>legacy_id_unquote</th><th>legacy_source</th></tr></thead>
            <tbody>
                <?php foreach ($lastWithMarker as $r): ?>
                <tr>
                    <td><strong>#<?= (int)$r['id'] ?></strong></td>
                    <td><?= $h($r['name_display']) ?></td>
                    <td><code><?= $h($r['legacy_id_raw']) ?></code></td>
                    <td><code><?= $h($r['legacy_id_unquote']) ?></code></td>
                    <td><?= $h($r['legacy_source']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size: 11px; color: #7a766f;">
            Si "legacy_id_raw" affiche <code>14</code> (sans quotes) et "legacy_id_unquote" affiche <code>14</code>, le marker EST présent.
            Le bug d'idempotence vient alors d'une mauvaise comparaison PDO bind ↔ JSON.
        </p>
    </div>
    <?php elseif (!empty($lastWithMarker[0]['__error__'])): ?>
    <div class="dbg-card" style="border-left: 4px solid #dc2626;">
        <strong>❌ Erreur SQL :</strong> <?= $h($lastWithMarker[0]['__error__']) ?>
    </div>
    <?php else: ?>
    <div class="dbg-card" style="border-left: 4px solid #f59e0b;">
        <strong>⚠️ Aucun ged_doc avec marker <code>$.extra.legacy_biens_doc_id</code></strong> trouvé en BDD.<br>
        Cela signifie que le pipeline `gus_commit_document` N'ENREGISTRE PAS le marker correctement.
    </div>
    <?php endif; ?>

    <?php if ($doc && empty($doc['__error__'])): ?>
    <div class="dbg-card">
        <h2>📄 ged_documents #<?= (int)$doc['id'] ?></h2>
        <div class="dbg-row"><span class="l">name_display</span><span class="v"><?= $h($doc['name_display']) ?></span></div>
        <div class="dbg-row"><span class="l">name_file</span><span class="v"><?= $h($doc['name_file']) ?></span></div>
        <div class="dbg-row"><span class="l">document_type</span><span class="v"><?= $h($doc['document_type']) ?></span></div>
        <div class="dbg-row"><span class="l">source_module</span><span class="v"><?= $h($doc['source_module']) ?></span></div>
        <div class="dbg-row"><span class="l">tenant_id / societe / agence</span><span class="v"><?= (int)$doc['tenant_id'] ?> / <?= (int)$doc['societe_id'] ?> / <?= (int)$doc['agence_id'] ?></span></div>
        <div class="dbg-row"><span class="l">hash_sha256</span><span class="v"><?= $h($doc['hash_sha256']) ?></span></div>
        <div class="dbg-row"><span class="l">created_at</span><span class="v"><?= $h($doc['created_at']) ?></span></div>
        <div class="dbg-row"><span class="l">fluxbox_source_id</span><span class="v"><?= $doc['fluxbox_source_id'] ?? '—' ?></span></div>

        <h3 style="margin-top: 16px; color: #243B5C;">metadata (JSON brut)</h3>
        <div class="dbg-json"><?= $h(json_encode(json_decode((string)$doc['metadata'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>

        <h3 style="margin-top: 16px; color: #243B5C;">linked_entities (snapshot)</h3>
        <div class="dbg-json"><?= $h(json_encode(json_decode((string)$doc['linked_entities'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>

        <h3 style="margin-top: 16px; color: #243B5C;">ged_document_links (table)</h3>
        <table class="dbg-tbl">
            <thead><tr><th>link ID</th><th>entity_type</th><th>entity_id</th><th>relation_type</th><th>confidence</th><th>is_validated</th></tr></thead>
            <tbody>
                <?php foreach ($links as $l): ?>
                    <tr>
                        <td>#<?= (int)$l['id'] ?></td>
                        <td><strong><?= $h($l['entity_type']) ?></strong></td>
                        <td>#<?= (int)$l['entity_id'] ?></td>
                        <td><?= $h($l['relation_type']) ?></td>
                        <td><?= $l['confidence'] ?? '—' ?></td>
                        <td><?= (int)$l['is_validated'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif (!empty($doc['__error__'])): ?>
    <div class="dbg-card" style="border-left: 4px solid #dc2626;">
        <strong>❌ Erreur lecture ged_documents #<?= (int)$gedId ?> :</strong> <?= $h($doc['__error__']) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($markerTests)): ?>
    <div class="dbg-card">
        <h2>🧪 Tests de la requête d'idempotence (legacy_id = <?= (int)$legacyId ?>)</h2>
        <p style="font-size: 11px; color: #7a766f;">8 variantes testées. Celle qui ✅ match est la bonne à utiliser dans admin_migrate_legacy_to_ged.</p>
        <table class="dbg-tbl">
            <thead><tr><th>Variant</th><th>SQL pattern</th><th>Bind type</th><th>Résultat</th></tr></thead>
            <tbody>
                <?php foreach ($markerTests as $name => $t): ?>
                <tr>
                    <td><strong><?= $h($name) ?></strong></td>
                    <td style="font-size: 10px;"><code><?= $h(preg_replace('/^.*?WHERE\s+/', '', $t['sql'])) ?></code></td>
                    <td><code><?= $h(gettype($t['bind'][0]) . ': ' . $t['bind'][0]) ?></code></td>
                    <td class="<?= str_starts_with($t['match'], 'ged') ? 'dbg-pass' : 'dbg-fail' ?>"><?= $t['status'] ?> <?= $h($t['match']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 14px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047; font-size: 12px;">
        <strong>📖 Mode d'emploi</strong>
        <ol style="margin: 8px 0 0 16px;">
            <li>Examine d'abord la table "5 derniers ged_documents AVEC marker" en haut. Si vide → le marker n'est pas posé par le pipeline.</li>
            <li>Si la table montre des rows, note un <strong>legacy_id_unquote</strong> (ex: 14).</li>
            <li>Re-ouvre la page avec <code>?id=&lt;ged_id&gt;&legacy_id=&lt;legacy_id&gt;</code> (ex: <code>?id=12&legacy_id=14</code>).</li>
            <li>La section "metadata (JSON brut)" doit montrer <code>"extra": {"legacy_biens_doc_id": 14, ...}</code>.</li>
            <li>La section "Tests d'idempotence" affiche 8 variantes — celle qui ✅ match donne la bonne syntaxe à utiliser.</li>
        </ol>
    </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
