<?php
declare(strict_types=1);
/**
 * rh_doc_orphan_diag.php — outil admin one-shot
 * Liste les `rh_documents` dont le fichier physique manque sur disque.
 * Mode diagnostic par défaut (read-only). Mode cleanup avec confirmation.
 *
 * Réservé role_id=1 (super admin).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

if (current_role_id() !== 1) {
    http_response_code(403);
    exit('Réservé super admin (role_id=1).');
}

$pdo = $GLOBALS['pdo'];
$uploadsBase = realpath(__DIR__ . '/../uploads');
$rootDocs    = __DIR__ . '/../uploads/rh_docs';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── 1. Charger tous les records ──────────────────────────────────
$stmt = $pdo->query("
    SELECT d.id, d.id_user, d.filename, d.original_name, d.upload_date,
           u.prenom, u.nom, u.id_societe
    FROM rh_documents d
    LEFT JOIN users u ON u.id = d.id_user
    ORDER BY d.id_user, d.id
");
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Pour chaque record, vérifier la présence du fichier ──────
$orphans = [];
$present = 0;
foreach ($all as $doc) {
    $idUser   = (int)$doc['id_user'];
    $filename = (string)$doc['filename'];
    if ($filename === '') {
        $orphans[] = $doc + ['_reason' => 'filename vide en BDD'];
        continue;
    }
    $path = $rootDocs . '/' . $idUser . '/' . basename($filename);
    if (!file_exists($path)) {
        $orphans[] = $doc + ['_reason' => 'fichier absent du disque'];
    } else {
        $present++;
    }
}

// ── 3. Mode cleanup ─────────────────────────────────────────────
$cleanupDone = 0;
$cleanupErrors = [];
if (($_POST['action'] ?? '') === 'cleanup' && ($_POST['confirm'] ?? '') === 'YES_DELETE_ORPHANS') {
    verify_csrf();
    $idsToDelete = array_column($orphans, 'id');
    if (!empty($idsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
        try {
            $del = $pdo->prepare("DELETE FROM rh_documents WHERE id IN ($placeholders)");
            $del->execute($idsToDelete);
            $cleanupDone = $del->rowCount();
        } catch (Throwable $e) {
            $cleanupErrors[] = $e->getMessage();
        }
    }
    // Recompter après cleanup
    header('Location: rh_doc_orphan_diag.php?cleaned=' . $cleanupDone);
    exit;
}

$justCleaned = isset($_GET['cleaned']) ? (int)$_GET['cleaned'] : null;

// ── 4. Affichage ────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8">
<title>Diag orphelins rh_documents</title>
<style>
body { font-family: 'Sora', sans-serif; background: #f0f1f3; color: #1a1816; padding: 24px; max-width: 1200px; margin: 0 auto; }
h1 { font-size: 22px; color: #36577d; }
.kpi-row { display: flex; gap: 12px; margin: 16px 0 24px; }
.kpi { background: #fff; padding: 14px 20px; border-radius: 10px; box-shadow: 3px 3px 8px #d4d7de, -3px -3px 8px #fff; }
.kpi-val { font-size: 28px; font-weight: 700; color: #36577d; }
.kpi-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: #8a8680; }
.kpi.danger .kpi-val { color: #8a5040; }
.kpi.success .kpi-val { color: #4a6038; }
table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 3px 3px 8px #d4d7de; }
th { background: #36577d; color: #fff; padding: 10px 14px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
td { padding: 8px 14px; font-size: 12px; border-bottom: 1px solid #eef0f3; }
tr:hover td { background: #fafbfc; }
.cleanup-form { background: #fff5f0; border: 2px solid #e8b8a8; border-radius: 12px; padding: 18px; margin: 20px 0; }
.cleanup-form h2 { color: #8a5040; font-size: 16px; margin-top: 0; }
.btn-danger { background: #8a5040; color: #fff; padding: 10px 20px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 13px; }
.btn-danger:hover { background: #6e3f31; }
.notice-success { background: #d4f0df; color: #1a6035; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
small { color: #8a8680; }
code { font-family: 'DM Mono', monospace; font-size: 11px; background: #eef0f3; padding: 2px 6px; border-radius: 4px; }
.reason { font-size: 10px; padding: 2px 8px; border-radius: 999px; background: #fef5db; color: #7a4e0a; }
</style>
</head><body>

<h1>🩺 Diagnostic orphelins <code>rh_documents</code></h1>
<p><small>Outil admin one-shot. Liste les records dont le fichier physique manque sur le disque (<code>uploads/rh_docs/&lt;user_id&gt;/&lt;filename&gt;</code>).</small></p>

<?php if ($justCleaned !== null): ?>
<div class="notice-success">✅ Nettoyage terminé : <strong><?= $justCleaned ?> record(s)</strong> supprimé(s) de la table <code>rh_documents</code>.</div>
<?php endif; ?>

<?php if (!empty($cleanupErrors)): ?>
<div style="background:#ffe5e0;color:#8a2820;padding:12px 18px;border-radius:8px;margin-bottom:20px">
    ❌ Erreur(s) cleanup : <?= h(implode(' | ', $cleanupErrors)) ?>
</div>
<?php endif; ?>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-val"><?= count($all) ?></div><div class="kpi-lbl">Records BDD</div></div>
    <div class="kpi success"><div class="kpi-val"><?= $present ?></div><div class="kpi-lbl">Avec fichier</div></div>
    <div class="kpi danger"><div class="kpi-val"><?= count($orphans) ?></div><div class="kpi-lbl">Orphelins</div></div>
</div>

<p><small>📂 Dossier inspecté : <code><?= h($rootDocs) ?></code></small></p>

<?php if (count($orphans) > 0): ?>

<div class="cleanup-form">
    <h2>⚠️ Suppression des orphelins</h2>
    <p>Cette action supprimera <strong><?= count($orphans) ?> record(s)</strong> de la table <code>rh_documents</code> dont le fichier physique manque. <strong>Aucun fichier ne sera supprimé du disque</strong> (puisqu'ils n'y sont déjà plus).</p>
    <p>Avant de confirmer, vérifie la liste ci-dessous. Cette action est irréversible (mais des sauvegardes BDD régulières existent côté Hostinger).</p>
    <form method="POST">
        <input type="hidden" name="action" value="cleanup">
        <input type="hidden" name="confirm" value="YES_DELETE_ORPHANS">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button type="submit" class="btn-danger" onclick="return confirm('Supprimer définitivement les <?= count($orphans) ?> records orphelins ?');">
            🗑 Supprimer les <?= count($orphans) ?> orphelins
        </button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>Société</th>
            <th>Filename (BDD)</th>
            <th>Original name</th>
            <th>Upload date</th>
            <th>Raison</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($orphans as $o): ?>
        <tr>
            <td><code><?= (int)$o['id'] ?></code></td>
            <td><?= h(trim(($o['prenom'] ?? '') . ' ' . ($o['nom'] ?? ''))) ?> <small>#<?= (int)$o['id_user'] ?></small></td>
            <td><small>#<?= (int)($o['id_societe'] ?? 0) ?></small></td>
            <td><code><?= h($o['filename'] ?? '—') ?></code></td>
            <td><?= h($o['original_name'] ?? '—') ?></td>
            <td><small><?= h($o['upload_date'] ?? '—') ?></small></td>
            <td><span class="reason"><?= h($o['_reason']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php else: ?>
<div class="notice-success">✅ Aucun orphelin détecté. Tous les records BDD ont leur fichier physique.</div>
<?php endif; ?>

<p style="margin-top:30px"><small>🛠 Fichier : <code>public_html/admin/rh_doc_orphan_diag.php</code> — outil one-shot, à supprimer ou à laisser pour usage ponctuel.</small></p>

</body></html>
