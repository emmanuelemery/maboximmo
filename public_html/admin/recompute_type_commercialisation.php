<?php
declare(strict_types=1);
/**
 * admin/recompute_type_commercialisation.php  —  RATTRAPAGE ONE-SHOT (Phase 1)
 *
 * ⚠️ Sens INVERSE du fonctionnement normal, et ASSUMÉ : ici l'ancien champ
 * `biens.type_commercialisation` GÉNÈRE les mandats manquants, parce que pour
 * l'historique c'est la SEULE trace de l'intention de mise en marché. C'est un
 * one-shot de rattrapage. APRÈS ça : mandats = vérité, le champ ne crée plus
 * jamais de mandat (cf. les 7 écrivains déjà recâblés).
 *
 * Étapes (dry-run par défaut, écriture via bouton + CSRF) :
 *   1. BACKFILL : tout bien `type_commercialisation ∈ {vente, location}` SANS mandat
 *      en cours de ce type → on crée un mandat « projet » (ensure_mandat).
 *      → mandat PROJET, jamais signé : statut='projet', date_signature/débit/fin NULL
 *        (on n'invente pas un fait juridique, juste une intention).
 *   2. DERIVE : on réaligne le miroir partout (vente>location>NULL). Les labels
 *      vente/location sont préservés (via le backfill) ; `gestion` et autres
 *      sortent du pipeline (→ NULL), car la gestion n'est pas une commercialisation.
 *
 * Accès : admin / super admin.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_missions.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$term = "'" . implode("','", mission_statuts_terminaux()) . "'";

// Biens à BACKFILLER : label vente/location SANS mandat en cours du même type.
$SQL_BACKFILL = "
    SELECT b.id, b.reference_bien, b.id_agence, b.id_proprietaire,
           LOWER(b.type_commercialisation) AS type
    FROM biens b
    WHERE LOWER(b.type_commercialisation) IN ('vente','location')
      AND NOT EXISTS (
            SELECT 1 FROM mandats m
            WHERE m.id_bien = b.id
              AND m.type_mandat = LOWER(b.type_commercialisation)
              AND m.statut NOT IN ($term)
      )";

// Biens dont le label sortira (→ NULL) : ni vente ni location (ex. gestion), label non vide.
$SQL_TONULL = "
    SELECT b.id, b.reference_bien, b.type_commercialisation AS avant
    FROM biens b
    WHERE b.type_commercialisation IS NOT NULL AND b.type_commercialisation <> ''
      AND LOWER(b.type_commercialisation) NOT IN ('vente','location')";

$flash = null;

// ── Commit ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit') {
    verify_csrf('recompute_tc');
    try {
        @set_time_limit(300);
        $pdo->beginTransaction();

        // 1. BACKFILL — créer les mandats projet manquants (canonique = ensure_mandat)
        $toBackfill = $pdo->query($SQL_BACKFILL)->fetchAll(PDO::FETCH_ASSOC);
        $nBackfill = 0;
        foreach ($toBackfill as $r) {
            ensure_mandat($pdo, (int)$r['id'], (string)$r['type'], [
                'id_agence'       => (int)($r['id_agence'] ?: 0) ?: null,
                'id_proprietaire' => (int)($r['id_proprietaire'] ?: 0) ?: null,
            ]);
            $nBackfill++;
        }

        // 2. DERIVE — réaligner le miroir partout (un seul UPDATE, même règle que derive)
        $CASE = "CASE
            WHEN EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=b.id AND m.type_mandat='vente'    AND m.statut NOT IN ($term)) THEN 'vente'
            WHEN EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=b.id AND m.type_mandat='location' AND m.statut NOT IN ($term)) THEN 'location'
            ELSE NULL END";
        $nDerive = $pdo->exec("UPDATE biens b
                               SET b.type_commercialisation = ($CASE), b.date_modification = NOW()
                               WHERE NOT (b.type_commercialisation <=> ($CASE))");

        $pdo->commit();
        $flash = ['ok'=>true, 'msg'=>"Rattrapage appliqué : $nBackfill mandat(s) projet créé(s) · $nDerive miroir(s) réaligné(s)."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['ok'=>false, 'msg'=>'ROLLBACK — '.$e->getMessage()];
    }
}

// ── Dry-run ─────────────────────────────────────────────────────────────
$total      = (int)$pdo->query("SELECT COUNT(*) FROM biens")->fetchColumn();
$backfill    = $pdo->query($SQL_BACKFILL)->fetchAll(PDO::FETCH_ASSOC);
$toNull      = $pdo->query($SQL_TONULL)->fetchAll(PDO::FETCH_ASSOC);

$backByType = ['vente'=>0,'location'=>0];
foreach ($backfill as $r) { $backByType[$r['type']] = ($backByType[$r['type']] ?? 0) + 1; }
$nullByLabel = [];
foreach ($toNull as $r) { $k = strtolower((string)$r['avant']); $nullByLabel[$k] = ($nullByLabel[$k] ?? 0) + 1; }

$csrf = csrf_token('recompute_tc');
$pageTitle = 'Recompute type_commercialisation (rattrapage)';
$appLayout = true;
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .rc-wrap{max-width:960px;margin:0 auto;padding:20px}
  .rc-wrap h1{font-size:21px;color:#0f172a;margin:0 0 4px}
  .rc-sub{color:#64748b;font-size:13px;margin:0 0 16px}
  .rc-oneshot{background:#fffbeb;border-left:4px solid #f59e0b;color:#92400e;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:16px}
  .rc-flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px}
  .rc-flash.ok{background:#f0fdf4;border-left:4px solid #16a34a;color:#14532d}
  .rc-flash.ko{background:#fef2f2;border-left:4px solid #dc2626;color:#7f1d1d}
  .rc-cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}
  .rc-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;flex:1;min-width:220px}
  .rc-big{font-size:28px;font-weight:800}
  .rc-b{color:#2563eb}.rc-n{color:#dc2626}
  table.rc{width:100%;border-collapse:collapse;font-size:12.5px}
  table.rc th{text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;border-bottom:2px solid #eef2f7;padding:6px 8px}
  table.rc td{padding:6px 8px;border-bottom:1px solid #f1f5f9}
  .rc-btn{padding:11px 20px;border-radius:10px;background:#2563eb;color:#fff;border:none;font-size:14px;font-weight:700;cursor:pointer}
  .rc-back{display:inline-flex;gap:6px;color:#4f46e5;text-decoration:none;font-size:13px;font-weight:600;margin-bottom:14px}
  code{background:#f1f5f9;padding:1px 5px;border-radius:4px}
</style>
<div class="rc-wrap">
  <a class="rc-back" href="<?= h(function_exists('app_url')?app_url('/agency_dashboard.php'):'/agency_dashboard.php') ?>">← Retour à Ma Box Agency</a>
  <h1>🔄 Rattrapage <code>type_commercialisation</code> → <code>mandats</code></h1>
  <p class="rc-sub">Backfill-then-derive. Dry-run par défaut.</p>
  <div class="rc-oneshot">⚠️ <strong>One-shot de rattrapage</strong> : ici le vieux champ génère les mandats manquants (seule trace de l'intention). Mandats créés en <strong>projet</strong> (jamais signés, sans date contractuelle). Après ça, le champ ne crée plus jamais de mandat.</div>

  <?php if ($flash): ?><div class="rc-flash <?= $flash['ok']?'ok':'ko' ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <div class="rc-cards">
    <div class="rc-card"><div>Mandats <strong>projet</strong> à créer (backfill)</div>
      <div class="rc-big rc-b"><?= count($backfill) ?></div>
      <div style="font-size:12px;color:#64748b">vente <?= (int)$backByType['vente'] ?> · location <?= (int)$backByType['location'] ?></div>
    </div>
    <div class="rc-card"><div>Labels → <code>NULL</code> (hors marché vente/loc)</div>
      <div class="rc-big rc-n"><?= count($toNull) ?></div>
      <div style="font-size:12px;color:#64748b"><?php $p=[]; foreach($nullByLabel as $k=>$v){$p[]="$k $v";} echo h(implode(' · ',$p)); ?></div>
    </div>
    <div class="rc-card"><div>Biens au total</div><div class="rc-big" style="color:#334155"><?= $total ?></div></div>
  </div>

  <div class="rc-card" style="margin-bottom:16px">
    <h3 style="margin:0 0 8px;font-size:13px;color:#334155">Exemples — mandats projet créés</h3>
    <table class="rc"><tr><th>Bien</th><th>Réf</th><th>Mission créée</th></tr>
      <?php foreach (array_slice($backfill,0,30) as $r): ?>
      <tr><td>#<?= (int)$r['id'] ?></td><td><?= h($r['reference_bien']) ?></td><td><code><?= h($r['type']) ?></code> (projet)</td></tr>
      <?php endforeach; ?>
      <?php if (!$backfill): ?><tr><td colspan="3" style="color:#16a34a">Aucun backfill nécessaire.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="rc-card" style="margin-bottom:16px">
    <h3 style="margin:0 0 8px;font-size:13px;color:#334155">Exemples — labels → NULL</h3>
    <table class="rc"><tr><th>Bien</th><th>Réf</th><th>Avant</th></tr>
      <?php foreach (array_slice($toNull,0,30) as $r): ?>
      <tr><td>#<?= (int)$r['id'] ?></td><td><?= h($r['reference_bien']) ?></td><td><code><?= h($r['avant']) ?></code> → null</td></tr>
      <?php endforeach; ?>
      <?php if (!$toNull): ?><tr><td colspan="3" style="color:#16a34a">Aucun label à retirer.</td></tr><?php endif; ?>
    </table>
  </div>

  <?php if (count($backfill) > 0 || count($toNull) > 0): ?>
  <form method="post" onsubmit="return confirm('Rattrapage : créer <?= count($backfill) ?> mandat(s) projet et réaligner le miroir ? (mandats projet, non signés)');">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="commit">
    <button type="submit" class="rc-btn">🔄 Appliquer le rattrapage</button>
  </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
