<?php
declare(strict_types=1);
/**
 * admin/phase2_etape3_dossiers_mandats.php — PHASE 2 / Étape 3 (bidirectionnelle).
 *
 * Rend `mandats` pleinement canonique vis-à-vis de `dossier_vente`, dans les 2 sens :
 *   A) Mandat VENTE en cours SANS dossier_vente → créer le dossier (dv_ensure_for_bien).
 *   B) dossier_vente SANS mandat vente          → créer le mandat vente manquant,
 *      avec un statut COHÉRENT avec l'étape du dossier :
 *        - étape sans_suite / perdu      → mandat 'sans_suite'
 *        - étape acte / solde            → mandat 'vendu'
 *        - sinon (estimation…compromis)  → mandat 'projet'
 *
 * Dry-run par défaut ; écriture via bouton (CSRF), transaction, recompute du miroir.
 * Accès : admin / super admin.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_missions.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$term = "'" . implode("','", mission_statuts_terminaux()) . "'";

/** Étape dossier → statut de mandat cohérent. */
function etape_to_statut(string $etape): string {
    $e = strtolower(trim($etape));
    if (in_array($e, ['sans_suite','perdu'], true)) return 'sans_suite';
    if (in_array($e, ['acte','solde'], true))       return 'vendu';
    return 'projet';
}

// Direction A : mandats vente EN COURS sans dossier_vente
$SQL_A = "SELECT m.id AS id_mandat, m.id_bien, m.statut, b.reference_bien
          FROM mandats m JOIN biens b ON b.id = m.id_bien
          WHERE m.type_mandat='vente' AND m.statut NOT IN ($term)
            AND NOT EXISTS (SELECT 1 FROM dossier_vente dv WHERE dv.id_bien = m.id_bien)
          ORDER BY m.id_bien";

// Direction B : dossier_vente sans mandat vente (aucun, quel que soit le statut)
$SQL_B = "SELECT dv.id AS id_dossier, dv.id_bien, dv.etape, b.reference_bien,
                 b.id_agence, b.id_proprietaire
          FROM dossier_vente dv JOIN biens b ON b.id = dv.id_bien
          WHERE NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien = dv.id_bien AND m.type_mandat='vente')
          ORDER BY dv.id_bien";

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit') {
    verify_csrf('phase2_etape3');
    @set_time_limit(300);
    try {
        $pdo->beginTransaction();

        // A) créer les dossiers manquants
        $nA = 0;
        foreach ($pdo->query($SQL_A)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            dv_ensure_for_bien($pdo, (int)$r['id_bien']);
            $nA++;
        }

        // B) créer les mandats vente manquants, statut cohérent avec l'étape
        $nB = 0;
        foreach ($pdo->query($SQL_B)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idBien = (int)$r['id_bien'];
            $mid = ensure_mandat_vente($pdo, $idBien, [
                'id_agence'       => (int)($r['id_agence'] ?: 0) ?: null,
                'id_proprietaire' => (int)($r['id_proprietaire'] ?: 0) ?: null,
            ]);
            $statut = etape_to_statut((string)$r['etape']);
            if ($statut !== 'projet') {
                $pdo->prepare("UPDATE mandats SET statut=?, date_modification=NOW() WHERE id=?")->execute([$statut, $mid]);
            }
            derive_type_commercialisation($pdo, $idBien);
            $nB++;
        }

        $pdo->commit();
        $flash = ['ok'=>true, 'msg'=>"Étape 3 appliquée : $nA dossier(s) créé(s) · $nB mandat(s) vente créé(s)."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['ok'=>false, 'msg'=>'ROLLBACK — '.$e->getMessage()];
    }
}

// Dry-run
$rowsA = $pdo->query($SQL_A)->fetchAll(PDO::FETCH_ASSOC);
$rowsB = $pdo->query($SQL_B)->fetchAll(PDO::FETCH_ASSOC);
$bStatuts = [];
foreach ($rowsB as $r) { $s = etape_to_statut((string)$r['etape']); $bStatuts[$s] = ($bStatuts[$s] ?? 0) + 1; }
$csrf = csrf_token('phase2_etape3');

$pageTitle = 'Phase 2 — Étape 3 (dossiers ↔ mandats)';
$appLayout = true;
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .p3-wrap{max-width:980px;margin:0 auto;padding:20px}
  .p3-wrap h1{font-size:21px;color:#0f172a;margin:0 0 4px}
  .p3-sub{color:#64748b;font-size:13px;margin:0 0 16px}
  .p3-flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px}
  .p3-flash.ok{background:#f0fdf4;border-left:4px solid #16a34a;color:#14532d}
  .p3-flash.ko{background:#fef2f2;border-left:4px solid #dc2626;color:#7f1d1d}
  .p3-cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}
  .p3-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;flex:1;min-width:240px}
  .p3-big{font-size:28px;font-weight:800;color:#2563eb}
  table.p3{width:100%;border-collapse:collapse;font-size:12.5px}
  table.p3 th{text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;border-bottom:2px solid #eef2f7;padding:6px 8px}
  table.p3 td{padding:6px 8px;border-bottom:1px solid #f1f5f9}
  .p3-btn{padding:11px 20px;border-radius:10px;background:#2563eb;color:#fff;border:none;font-size:14px;font-weight:700;cursor:pointer}
  .p3-back{display:inline-flex;gap:6px;color:#4f46e5;text-decoration:none;font-size:13px;font-weight:600;margin-bottom:14px}
  code{background:#f1f5f9;padding:1px 5px;border-radius:4px}
</style>
<div class="p3-wrap">
  <a class="p3-back" href="<?= h(function_exists('app_url')?app_url('/agency_dashboard.php'):'/agency_dashboard.php') ?>">← Retour à Ma Box Agency</a>
  <h1>🔗 Phase 2 · Étape 3 — Cohérence dossiers ↔ mandats</h1>
  <p class="p3-sub">Dry-run par défaut. Rend <code>mandats</code> canonique des deux côtés.</p>

  <?php if ($flash): ?><div class="p3-flash <?= $flash['ok']?'ok':'ko' ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <div class="p3-cards">
    <div class="p3-card"><div><strong>A.</strong> Mandats vente sans dossier → <em>créer dossier</em></div><div class="p3-big"><?= count($rowsA) ?></div></div>
    <div class="p3-card"><div><strong>B.</strong> Dossiers sans mandat vente → <em>créer mandat</em></div><div class="p3-big"><?= count($rowsB) ?></div>
      <div style="font-size:12px;color:#64748b"><?php $p=[];foreach($bStatuts as $k=>$v){$p[]="$k $v";} echo h(implode(' · ',$p)); ?></div>
    </div>
  </div>

  <div class="p3-card" style="margin-bottom:16px">
    <h3 style="margin:0 0 8px;font-size:13px;color:#334155">A — dossier à créer (max 40)</h3>
    <table class="p3"><tr><th>Mandat</th><th>Bien</th><th>Réf</th><th>Statut mandat</th></tr>
      <?php foreach (array_slice($rowsA,0,40) as $r): ?>
      <tr><td>#<?= (int)$r['id_mandat'] ?></td><td>#<?= (int)$r['id_bien'] ?></td><td><?= h($r['reference_bien']) ?></td><td><?= h($r['statut']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rowsA): ?><tr><td colspan="4" style="color:#16a34a">Rien à créer côté A.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="p3-card" style="margin-bottom:16px">
    <h3 style="margin:0 0 8px;font-size:13px;color:#334155">B — mandat vente à créer (max 40)</h3>
    <table class="p3"><tr><th>Dossier</th><th>Bien</th><th>Réf</th><th>Étape dossier</th><th>→ statut mandat</th></tr>
      <?php foreach (array_slice($rowsB,0,40) as $r): ?>
      <tr><td>#<?= (int)$r['id_dossier'] ?></td><td>#<?= (int)$r['id_bien'] ?></td><td><?= h($r['reference_bien']) ?></td><td><?= h($r['etape']) ?></td><td><code><?= h(etape_to_statut((string)$r['etape'])) ?></code></td></tr>
      <?php endforeach; ?>
      <?php if (!$rowsB): ?><tr><td colspan="5" style="color:#16a34a">Rien à créer côté B.</td></tr><?php endif; ?>
    </table>
  </div>

  <?php if (count($rowsA) > 0 || count($rowsB) > 0): ?>
  <form method="post" onsubmit="return confirm('Appliquer l\'Étape 3 : créer <?= count($rowsA) ?> dossier(s) et <?= count($rowsB) ?> mandat(s) vente ?');">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="commit">
    <button type="submit" class="p3-btn">🔗 Appliquer l'Étape 3</button>
  </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
