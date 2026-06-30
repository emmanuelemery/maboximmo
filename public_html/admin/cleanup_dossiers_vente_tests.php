<?php
declare(strict_types=1);
/**
 * admin/cleanup_dossiers_vente_tests.php
 *
 * Purge des dossiers de vente de TEST (agence REGIE EMERY LYON + autres + sans agence).
 * On NE GARDE que les agences réelles : CHAPONOST (69-1), RIOM (63-1), CHAMALIERES (63-2).
 *
 * - Par défaut : DRY-RUN (lecture seule) → liste ce qui serait supprimé, groupé par agence.
 * - Suppression réelle UNIQUEMENT via le bouton (POST + CSRF + confirmation) :
 *     1) backup des dossier_vente dans `dossier_vente_backup_tests`
 *     2) suppression en cascade des enfants (dossier_avant_contrat, dossier_vente_bien,
 *        mandat_signatures, tiers_roles objet_type='dossier_vente')
 *     3) suppression des dossier_vente
 *   Le set à supprimer est TOUJOURS recalculé côté serveur (jamais d'ids venant du client).
 *   Ne touche JAMAIS aux biens ni aux mandats.
 *
 * Accès : admin / super admin.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
// NB : csrf.php est déjà chargé par bootstrap.php — ne PAS le ré-inclure
// (sur la structure dupliquée de prod, un 2e require via un autre chemin
//  provoque « Cannot redeclare csrf_token() » → HTTP 500).
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Agences RÉELLES à conserver (par code, robuste local/prod)
const KEEP_CODES = ['69-1', '63-1', '63-2']; // CHAPONOST, RIOM, CHAMALIERES

/** Renvoie la liste des dossiers à supprimer (agence ∉ KEEP_CODES, NULL inclus). */
function dossiers_a_supprimer(PDO $pdo): array {
    $keep = "'" . implode("','", KEEP_CODES) . "'";
    return $pdo->query("
        SELECT dv.id, dv.id_bien, dv.etape, dv.statut,
               b.reference_bien,
               COALESCE(ab.nom_agence, ad.nom_agence, '(sans agence)') AS agence,
               COALESCE(ab.code_agence, ad.code_agence)               AS code_agence
        FROM dossier_vente dv
        LEFT JOIN biens   b  ON b.id  = dv.id_bien
        LEFT JOIN agences ab ON ab.id = b.id_agence
        LEFT JOIN agences ad ON ad.id = dv.id_agence
        WHERE COALESCE(ab.code_agence, ad.code_agence) IS NULL
           OR COALESCE(ab.code_agence, ad.code_agence) NOT IN ($keep)
        ORDER BY agence, dv.id
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$flash = null;

// ── Suppression réelle ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge') {
    verify_csrf('cleanup_dv_tests');
    $rows = dossiers_a_supprimer($pdo);
    $ids  = array_map(fn($r) => (int)$r['id'], $rows);

    if (empty($ids)) {
        $flash = ['ok' => true, 'msg' => 'Rien à supprimer.'];
    } else {
        $in = implode(',', $ids);
        $pdo->beginTransaction();
        try {
            // 1) Backup
            $pdo->exec("CREATE TABLE IF NOT EXISTS `dossier_vente_backup_tests` LIKE `dossier_vente`");
            try { $pdo->exec("ALTER TABLE `dossier_vente_backup_tests` ADD COLUMN `purged_at` DATETIME NULL"); } catch (Throwable) {}
            $pdo->exec("INSERT INTO `dossier_vente_backup_tests` SELECT *, NOW() FROM `dossier_vente` WHERE id IN ($in)");

            // 2) Enfants (orphelins évités)
            $childDeleted = [];
            $childDeleted['dossier_avant_contrat'] = $pdo->exec("DELETE FROM `dossier_avant_contrat` WHERE id_dossier IN ($in)");
            $childDeleted['dossier_vente_bien']    = $pdo->exec("DELETE FROM `dossier_vente_bien` WHERE id_dossier IN ($in)");
            $childDeleted['mandat_signatures']     = $pdo->exec("DELETE FROM `mandat_signatures` WHERE id_dossier IN ($in)");
            $childDeleted['tiers_roles']           = $pdo->exec("DELETE FROM `tiers_roles` WHERE objet_type='dossier_vente' AND id_objet IN ($in)");

            // 3) Dossiers
            $nDoss = $pdo->exec("DELETE FROM `dossier_vente` WHERE id IN ($in)");

            $pdo->commit();
            $flash = ['ok' => true, 'msg' =>
                "$nDoss dossier(s) supprimé(s) (backup dans dossier_vente_backup_tests). Enfants : " .
                implode(' · ', array_map(fn($k,$v)=>"$k=$v", array_keys($childDeleted), $childDeleted)) . '.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $flash = ['ok' => false, 'msg' => 'ROLLBACK — ' . $e->getMessage()];
        }
    }
}

// ── État courant (dry-run) ──────────────────────────────────────────────
$rows = dossiers_a_supprimer($pdo);
$byAgence = [];
foreach ($rows as $r) { $byAgence[$r['agence']][] = $r; }
$totalDel = count($rows);
$csrf = csrf_token('cleanup_dv_tests');

$pageTitle = 'Purge dossiers de vente (tests)';
$appLayout = true;
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .cd-wrap{max-width:960px;margin:0 auto;padding:20px}
  .cd-wrap h1{font-size:21px;color:#0f172a;margin:0 0 4px}
  .cd-sub{color:#64748b;font-size:13px;margin:0 0 16px}
  .cd-flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px}
  .cd-flash.ok{background:#f0fdf4;border-left:4px solid #16a34a;color:#14532d}
  .cd-flash.ko{background:#fef2f2;border-left:4px solid #dc2626;color:#7f1d1d}
  .cd-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;margin-bottom:16px}
  table.cd{width:100%;border-collapse:collapse;font-size:12.5px}
  table.cd th{text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;border-bottom:2px solid #eef2f7;padding:6px 8px}
  table.cd td{padding:6px 8px;border-bottom:1px solid #f1f5f9}
  .cd-keep{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:8px 12px;font-size:12.5px;color:#065f46;margin-bottom:14px}
  .cd-btn{padding:11px 20px;border-radius:10px;background:#dc2626;color:#fff;border:none;font-size:14px;font-weight:700;cursor:pointer}
  .cd-back{display:inline-flex;gap:6px;color:#4f46e5;text-decoration:none;font-size:13px;font-weight:600;margin-bottom:14px}
</style>
<div class="cd-wrap">
  <a class="cd-back" href="<?= h(function_exists('app_url')?app_url('/agency_dashboard.php'):'/agency_dashboard.php') ?>">← Retour à Ma Box Agency</a>
  <h1>🗑️ Purge des dossiers de vente — TESTS</h1>
  <p class="cd-sub">Conserve uniquement les agences réelles. Tout le reste (LYON, sans agence…) est considéré comme test.</p>

  <?php if ($flash): ?><div class="cd-flash <?= $flash['ok']?'ok':'ko' ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <div class="cd-keep">✅ <strong>Conservés</strong> : CHAPONOST (69-1) · RIOM (63-1) · CHAMALIERES (63-2)</div>

  <div class="cd-card">
    <strong><?= $totalDel ?></strong> dossier(s) seraient supprimés (dry-run) :
    <table class="cd" style="margin-top:10px">
      <tr><th>Agence</th><th>Dossier</th><th>Bien</th><th>Réf</th><th>Étape</th><th>Statut</th></tr>
      <?php foreach ($byAgence as $ag => $list): ?>
        <tr><td colspan="6" style="background:#f8fafc;font-weight:700;color:#334155"><?= h($ag) ?> — <?= count($list) ?></td></tr>
        <?php foreach ($list as $r): ?>
        <tr><td></td><td>#<?= (int)$r['id'] ?></td><td>#<?= (int)$r['id_bien'] ?></td><td><?= h($r['reference_bien']) ?></td><td><?= h($r['etape']) ?></td><td><?= h($r['statut']) ?></td></tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($totalDel===0): ?><tr><td colspan="6" style="color:#16a34a">Rien à supprimer.</td></tr><?php endif; ?>
    </table>
  </div>

  <?php if ($totalDel > 0): ?>
  <form method="post" onsubmit="return confirm('Supprimer définitivement <?= $totalDel ?> dossier(s) de vente (tests) ?\n\nUn backup est fait dans dossier_vente_backup_tests. Les biens et mandats ne sont PAS touchés.');">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="purge">
    <button type="submit" class="cd-btn">🗑️ Supprimer ces <?= $totalDel ?> dossiers de test (backup auto)</button>
  </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
