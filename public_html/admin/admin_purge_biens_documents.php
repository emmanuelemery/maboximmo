<?php
/**
 * admin/admin_purge_biens_documents.php
 *
 * GED CENTRALE UNIQUE — Audit + purge sous contrôle de la table legacy biens_documents.
 *
 * Contexte (Emmanuel 2026-05-25) :
 *  - Décision : 1 seule GED centrale (ged_documents + ged_document_links)
 *  - biens_documents N'EST PLUS la source documentaire — uniquement legacy
 *  - L'user confirme "on n'a pas commencé à utiliser donc s'il faut supprimer on peut"
 *  - Cette page permet d'auditer le contenu de biens_documents et de purger
 *    sous contrôle (dry-run obligatoire, super admin uniquement)
 *
 * RÉSERVÉ super admin (id_role=1). Dry-run par défaut.
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
$mode = (string)($_GET['mode'] ?? 'audit'); // audit | purge

$report = [];
$totals = [
    'biens_documents_total'      => 0,
    'biens_documents_par_type'   => [],
    'fichiers_disque_existants'  => 0,
    'fichiers_disque_orphelins'  => 0,
    'taille_totale_mo'           => 0.0,
    'biens_documents_supprimes'  => 0,
];

// ─── 1. Audit table biens_documents (always, lecture seule) ───
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'biens_documents'")->fetchColumn();
} catch (Throwable $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $report[] = "✅ Table biens_documents N'EXISTE PAS — déjà nettoyée ou jamais créée.";
} else {
    try {
        $stCount = $pdo->query("SELECT COUNT(*) FROM biens_documents");
        $totals['biens_documents_total'] = (int)$stCount->fetchColumn();
    } catch (Throwable $e) {
        $report[] = "❌ Erreur lecture biens_documents : " . $e->getMessage();
    }

    try {
        $stTypes = $pdo->query("SELECT type_document, COUNT(*) n, COALESCE(SUM(taille_octets),0) bytes
                                  FROM biens_documents GROUP BY type_document ORDER BY n DESC");
        foreach ($stTypes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals['biens_documents_par_type'][(string)$row['type_document']] = [
                'count' => (int)$row['n'],
                'mo'    => round(((int)$row['bytes']) / 1024 / 1024, 2),
            ];
            $totals['taille_totale_mo'] += (float)$totals['biens_documents_par_type'][(string)$row['type_document']]['mo'];
        }
    } catch (Throwable $e) {
        $report[] = "❌ Erreur agrégation types : " . $e->getMessage();
    }

    // Vérification présence physique des fichiers + tag orphelins
    try {
        $stRows = $pdo->query("SELECT id, url_fichier FROM biens_documents WHERE url_fichier IS NOT NULL AND url_fichier <> ''");
        $base = dirname(__DIR__);
        $orphelins = [];
        foreach ($stRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $path = $base . '/' . ltrim((string)$r['url_fichier'], '/');
            if (is_file($path)) {
                $totals['fichiers_disque_existants']++;
            } else {
                $totals['fichiers_disque_orphelins']++;
                if (count($orphelins) < 10) $orphelins[] = (string)$r['url_fichier'];
            }
        }
        if ($orphelins) {
            $report[] = "── Échantillon (max 10) de fichiers introuvables sur disque : ──";
            foreach ($orphelins as $o) $report[] = "  • $o";
        }
    } catch (Throwable $e) {
        $report[] = "❌ Erreur vérif fichiers : " . $e->getMessage();
    }
}

// ─── 2. Mode purge (irréversible) ───
if ($mode === 'purge' && $tableExists) {
    if (($_POST['confirm'] ?? '') !== 'OUI JE CONFIRME LA PURGE') {
        $report[] = "⚠️ PURGE refusée : confirmation textuelle manquante.";
    } else {
        try {
            // On ne supprime PAS les fichiers physiques (rester safe — possible récupération).
            $stDel = $pdo->prepare("DELETE FROM biens_documents");
            $stDel->execute();
            $totals['biens_documents_supprimes'] = $stDel->rowCount();
            $report[] = "🔥 PURGE EFFECTUÉE : {$totals['biens_documents_supprimes']} rows supprimées.";
            $report[] = "ℹ️  Les fichiers physiques restent dans /uploads/biens_docs/ (cleanup manuel à faire si vraiment inutiles).";
        } catch (Throwable $e) {
            $report[] = "❌ Erreur PURGE : " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.purge-wrap { max-width: 1100px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; }
.purge-wrap h1 { color: #243B5C; font-size: 22px; margin-bottom: 4px; }
.purge-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 16px 0; }
.purge-stat { background: #fff; padding: 12px; border-radius: 8px; box-shadow: 2px 2px 6px #e3dfd8; text-align: center; border-left: 4px solid #D4A047; }
.purge-stat .v { font-size: 24px; font-weight: 800; color: #2c2a28; }
.purge-stat .l { font-size: 10px; color: #7a766f; text-transform: uppercase; }
.purge-actions { display: flex; gap: 10px; margin: 16px 0; flex-wrap: wrap; }
.purge-btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; }
.purge-btn-audit { background: #60a5fa; color: #fff; }
.purge-btn-purge { background: #dc2626; color: #fff; }
.purge-btn-back  { background: #e3dfd8; color: #2c2a28; }
.purge-log { background: #0f172a; color: #d1d5db; padding: 16px; border-radius: 8px; font-size: 12px; line-height: 1.5; max-height: 500px; overflow-y: auto; white-space: pre-wrap; }
.purge-table { width: 100%; background: #fff; border-radius: 8px; overflow: hidden; margin: 12px 0; }
.purge-table th, .purge-table td { padding: 8px 12px; border-bottom: 1px solid #f1eee9; text-align: left; font-size: 12px; }
.purge-table th { background: #f1eee9; color: #2c2a28; }
.purge-confirm { background: #fef3c7; padding: 14px; border-radius: 8px; margin: 12px 0; border: 2px solid #f59e0b; }
.purge-confirm input { width: 100%; padding: 8px; font-family: monospace; border: 1px solid #d4a047; border-radius: 4px; margin: 8px 0; }
</style>
<div class="purge-wrap">
    <h1>🗑️ Purge biens_documents — Migration GED centrale</h1>
    <p style="color: #7a766f; font-size: 13px;">
        Depuis le 2026-05-25, la GED centrale (<code>ged_documents</code> + <code>ged_document_links</code>) est l'unique source documentaire.
        La table legacy <code>biens_documents</code> n'est plus utilisée. Cette page permet d'auditer son contenu puis de la vider sous contrôle.
    </p>

    <div class="purge-stats">
        <div class="purge-stat">
            <div class="v"><?= number_format($totals['biens_documents_total'], 0, '.', ' ') ?></div>
            <div class="l">Rows biens_documents</div>
        </div>
        <div class="purge-stat">
            <div class="v"><?= number_format($totals['fichiers_disque_existants'], 0, '.', ' ') ?></div>
            <div class="l">Fichiers disque OK</div>
        </div>
        <div class="purge-stat" style="border-left-color: <?= $totals['fichiers_disque_orphelins'] > 0 ? '#dc2626' : '#2d6a35' ?>;">
            <div class="v"><?= number_format($totals['fichiers_disque_orphelins'], 0, '.', ' ') ?></div>
            <div class="l">Fichiers orphelins</div>
        </div>
        <div class="purge-stat">
            <div class="v"><?= number_format($totals['taille_totale_mo'], 1, '.', ' ') ?> Mo</div>
            <div class="l">Taille totale</div>
        </div>
    </div>

    <?php if (!empty($totals['biens_documents_par_type'])): ?>
    <h3 style="font-size: 14px; color: #243B5C; margin-top: 20px;">📊 Répartition par type</h3>
    <table class="purge-table">
        <thead><tr><th>type_document</th><th>Nombre</th><th>Taille (Mo)</th></tr></thead>
        <tbody>
            <?php foreach ($totals['biens_documents_par_type'] as $type => $info): ?>
            <tr>
                <td><code><?= $h($type) ?></code></td>
                <td><?= $info['count'] ?></td>
                <td><?= $info['mo'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="purge-actions">
        <a href="?mode=audit" class="purge-btn purge-btn-audit">🔍 Re-auditer</a>
        <?php if ($totals['biens_documents_total'] > 0): ?>
        <form method="POST" action="?mode=purge" style="display: contents;" id="purge-form">
            <input type="hidden" name="confirm" value="OUI JE CONFIRME LA PURGE">
            <button type="button" id="purge-btn-trigger" class="purge-btn purge-btn-purge">🔥 PURGE DÉFINITIVE (BDD only, fichiers gardés)</button>
        </form>
        <?php endif; ?>
        <a href="admin_migrations.php" class="purge-btn purge-btn-back">← Retour migrations</a>
    </div>

    <?php if ($mode === 'purge'): ?>
        <div class="purge-confirm">
            <strong>Mode PURGE actif</strong> — voir log ci-dessous.
        </div>
    <?php endif; ?>

    <?php if (!empty($report)): ?>
    <h3 style="font-size: 14px; color: #243B5C; margin-top: 20px;">📋 Journal</h3>
    <div class="purge-log"><?php foreach ($report as $line) echo $h($line) . "\n"; ?></div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 16px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047;">
        <strong>📚 Décision architecturale 2026-05-25</strong>
        <ul style="margin: 8px 0 0 16px; font-size: 12px;">
            <li>Plus aucun INSERT dans <code>biens_documents</code> (refactor <code>api/bien_intake_upload.php</code>)</li>
            <li>Plus aucune lecture depuis <code>biens_documents</code> (refactor <code>bien_detail.php</code>, <code>bien_360.php</code>)</li>
            <li>Toutes les pages lisent désormais via <code>gdl_documents_for_entity()</code></li>
            <li>Tous les uploads passent par <code>gus_commit_document()</code> (pipeline unique)</li>
        </ul>
    </div>
</div>
<script>
// Confirmation double pour action destructive (P2 fix 2026-05-25)
document.getElementById('purge-btn-trigger')?.addEventListener('click', function() {
    const n = <?= (int)$totals['biens_documents_total'] ?>;
    const c1 = confirm(`⚠️ PURGE DÉFINITIVE — ${n} rows seront supprimées de biens_documents.\n\nLes fichiers physiques /uploads/biens_docs/ restent sur disque.\nLa GED centrale (ged_documents) n'est PAS touchée.\n\nConfirmer une 1ère fois ?`);
    if (!c1) return;
    const c2 = prompt(`Tape exactement "PURGE" en majuscules pour confirmer définitivement :`);
    if (c2 !== 'PURGE') { alert('Annulé.'); return; }
    document.getElementById('purge-form').submit();
});
</script>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
