<?php
/**
 * admin/admin_dedup_conflits_dossiers.php — Contrôle des conflits de dossier de vente
 * issus de la fusion des coquilles CRG (LECTURE SEULE par défaut).
 *
 * Conflit = un lot a DEUX dossiers de vente : un sur la coquille (soft-deleted) et un
 * sur le gardien. La fusion a conservé celui du gardien. Cette page compare la "richesse"
 * des deux dossiers pour vérifier qu'on n'a pas masqué le bon.
 *
 * GET                  : comparaison côte à côte + recommandation.
 * POST action=swap&...  : (optionnel) bascule le dossier de la coquille vers le gardien
 *                         APRÈS avoir détaché le dossier actuel du gardien. Une paire à la fois.
 *
 * Réservé super-admin (role 1).
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin (role 1).'); }

$ETAPE_RANK = ['estimation'=>0,'mandat'=>1,'commercialisation'=>2,'offre'=>3,'compromis'=>4,'acte'=>5,'solde'=>6,'sans_suite'=>1,'perdu'=>1];

/** Métriques de richesse d'un dossier de vente. */
function dossier_metrics(PDO $pdo, array $dv, array $etapeRank): array {
    $id = (int)$dv['id'];
    $m = [
        'id'        => $id,
        'etape'     => (string)$dv['etape'],
        'reference' => (string)($dv['reference'] ?? ''),
        'has_mandat'=> !empty($dv['id_mandat']),
        'created_at'=> (string)$dv['created_at'],
        'updated_at'=> (string)$dv['updated_at'],
        'acteurs'   => 0, 'avant_contrats' => 0, 'docs' => 0,
    ];
    try { $st=$pdo->prepare("SELECT COUNT(*) FROM tiers_roles WHERE objet_type='dossier_vente' AND id_objet=? AND actif=1");
          $st->execute([$id]); $m['acteurs']=(int)$st->fetchColumn(); } catch (Throwable $e) {}
    try { $st=$pdo->prepare("SELECT COUNT(*) FROM dossier_avant_contrat WHERE id_dossier=?");
          $st->execute([$id]); $m['avant_contrats']=(int)$st->fetchColumn(); } catch (Throwable $e) {}
    try { $st=$pdo->prepare("SELECT COUNT(*) FROM ged_document_links WHERE entity_type IN ('DOSSIER','DOSSIER_VENTE') AND entity_id=?");
          $st->execute([$id]); $m['docs']=(int)$st->fetchColumn(); } catch (Throwable $e) {}
    // Score = signaux de travail réel.
    $m['score'] = ($etapeRank[$m['etape']] ?? 0)
                + ($m['has_mandat'] ? 3 : 0)
                + $m['acteurs'] + 2 * $m['avant_contrats'] + 2 * $m['docs'];
    return $m;
}

/** Paires en conflit : un même lot avec un dossier sur la coquille ET sur le gardien. */
function conflict_pairs(PDO $pdo): array {
    $sql = "
        SELECT s.id AS coq_id, s.reference_bien AS coq_ref, s.code_crg, s.statut_bien AS coq_statut,
               g.id AS gar_id, g.reference_bien AS gar_ref,
               dsc.id AS coq_dv, dsg.id AS gar_dv
          FROM biens s
          JOIN biens g
            ON g.id <> s.id
           AND (g.reference_bien = REPLACE(s.code_crg,'_','-') OR g.code_crg = s.code_crg)
          JOIN dossier_vente dsc ON dsc.id_bien = s.id
          JOIN dossier_vente dsg ON dsg.id_bien = g.id
         WHERE s.code_crg LIKE '%\\_%'          -- la coquille a un code_crg à underscore
         ORDER BY s.code_crg";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function dv_get_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM dossier_vente WHERE id = ? LIMIT 1");
    $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── SWAP (optionnel, une paire) : remet le dossier de la coquille sur le gardien ──
$swapMsg = null;
if (($_POST['action'] ?? '') === 'swap') {
    verify_csrf('dedup_conflit');
    $coqDv = (int)($_POST['coq_dv'] ?? 0);
    $garId = (int)($_POST['gar_id'] ?? 0);
    $garDv = (int)($_POST['gar_dv'] ?? 0);
    try {
        $pdo->beginTransaction();
        // 1) Détacher le dossier actuel du gardien (on le rattache à un bien fantôme = lui-même mais
        //    on le neutralise en le déplaçant sur la coquille pour libérer l'UNIQUE id_bien).
        //    Récupère l'id_bien de la coquille (source du dossier coq).
        $coqBien = (int)$pdo->query("SELECT id_bien FROM dossier_vente WHERE id = " . $coqDv)->fetchColumn();
        $garBien = (int)$pdo->query("SELECT id_bien FROM dossier_vente WHERE id = " . $garDv)->fetchColumn();
        if ($coqBien && $garBien && $garBien === $garId) {
            // échange des id_bien entre les deux dossiers (via valeur temporaire pour éviter collision UNIQUE)
            $pdo->prepare("UPDATE dossier_vente SET id_bien = 0 WHERE id = ?")->execute([$garDv]);
            $pdo->prepare("UPDATE dossier_vente SET id_bien = ? WHERE id = ?")->execute([$garBien, $coqDv]);
            $pdo->prepare("UPDATE dossier_vente SET id_bien = ? WHERE id = ?")->execute([$coqBien, $garDv]);
            $pdo->commit();
            $swapMsg = "✅ Dossier #$coqDv (coquille) basculé sur le gardien #$garId. L'ancien dossier gardien #$garDv est désormais sur la coquille masquée.";
        } else { $pdo->rollBack(); $swapMsg = "❌ Incohérence — aucune modification."; }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $swapMsg = '❌ Erreur : ' . $e->getMessage();
    }
}

$pairs = conflict_pairs($pdo);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Conflits dossiers de vente — Admin</title>
<style>
body{font-family:system-ui,Segoe UI,sans-serif;padding:26px;background:#f7f4ef;max-width:1100px;margin:0 auto;color:#1f2937;}
h1{font-size:21px;} .muted{color:#6b7280;font-size:13px;}
.pair{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;margin:14px 0;}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.box{border:1px solid #eef0f2;border-radius:9px;padding:12px;}
.box.win{border-color:#10b981;background:#ecfdf5;}
.box h4{margin:0 0 8px;font-size:13px;}
.ref{font-family:monospace;font-weight:700;color:#4338ca;}
.k{color:#6b7280;font-size:11px;text-transform:uppercase;}
.v{font-weight:700;}
.ok{background:#ecfdf5;border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;margin:14px 0;}
.warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin:14px 0;}
.btn{background:#b45309;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;}
.score{font-size:22px;font-weight:800;color:#b45309;}
</style></head><body>

<h1>⚖️ Conflits de dossier de vente <span class="muted">(coquille vs gardien)</span></h1>

<?php if ($swapMsg): ?><div class="<?= str_starts_with($swapMsg,'✅')?'ok':'warn' ?>"><?= htmlspecialchars($swapMsg) ?></div><?php endif; ?>

<?php if (!$pairs): ?>
  <div class="ok">✅ Aucun conflit de dossier de vente restant. Tout est cohérent.</div>
<?php else: ?>
  <p class="muted">Pour chaque lot ayant deux dossiers, on compare leur « richesse » (étape, mandat, acteurs,
  avant-contrats, documents). Le <strong>gardien</strong> est celui actuellement visible. Si la <strong>coquille</strong>
  est plus riche, un bouton permet de basculer son dossier sur le gardien.</p>

  <?php foreach ($pairs as $p):
        $coq = dv_get_row($pdo, (int)$p['coq_dv']); $gar = dv_get_row($pdo, (int)$p['gar_dv']);
        if (!$coq || !$gar) continue;
        $mc = dossier_metrics($pdo, $coq, $ETAPE_RANK);
        $mg = dossier_metrics($pdo, $gar, $ETAPE_RANK);
        $coqWin = $mc['score'] > $mg['score'];
  ?>
  <div class="pair">
    <div style="font-weight:800;margin-bottom:8px;">
      <code><?= htmlspecialchars((string)$p['code_crg']) ?></code> —
      gardien <span class="ref">#<?= (int)$p['gar_id'] ?> <?= htmlspecialchars((string)($p['gar_ref'] ?? '')) ?></span>
      vs coquille <span class="ref">#<?= (int)$p['coq_id'] ?></span> <span class="muted">(<?= htmlspecialchars((string)$p['coq_statut']) ?>)</span>
    </div>
    <div class="cols">
      <?php foreach ([['Gardien (visible)',$mg,!$coqWin],['Coquille (masquée)',$mc,$coqWin]] as [$titre,$m,$win]): ?>
        <div class="box <?= $win?'win':'' ?>">
          <h4><?= $titre ?> — dossier #<?= $m['id'] ?> <?= $win?'· ⭐ plus riche':'' ?></h4>
          <div class="score"><?= $m['score'] ?> <span class="muted" style="font-size:12px;">pts</span></div>
          <div><span class="k">Étape</span> <span class="v"><?= htmlspecialchars($m['etape']) ?></span></div>
          <div><span class="k">Mandat lié</span> <span class="v"><?= $m['has_mandat']?'oui':'—' ?></span></div>
          <div><span class="k">Acteurs</span> <span class="v"><?= $m['acteurs'] ?></span></div>
          <div><span class="k">Avant-contrats</span> <span class="v"><?= $m['avant_contrats'] ?></span></div>
          <div><span class="k">Documents</span> <span class="v"><?= $m['docs'] ?></span></div>
          <div><span class="k">Créé</span> <span class="muted"><?= htmlspecialchars($m['created_at']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($coqWin): ?>
      <div class="warn" style="margin:12px 0 0;">⚠️ La coquille porte le dossier le plus travaillé. Tu peux le basculer sur le gardien :</div>
      <form method="post" style="margin-top:8px;" onsubmit="return confirm('Basculer le dossier de la coquille sur le gardien #<?= (int)$p['gar_id'] ?> ?');">
        <?= csrf_field('dedup_conflit') ?>
        <input type="hidden" name="action" value="swap">
        <input type="hidden" name="coq_dv" value="<?= (int)$p['coq_dv'] ?>">
        <input type="hidden" name="gar_id" value="<?= (int)$p['gar_id'] ?>">
        <input type="hidden" name="gar_dv" value="<?= (int)$p['gar_dv'] ?>">
        <button class="btn" type="submit">↪ Basculer le dossier de la coquille sur le gardien</button>
      </form>
    <?php else: ?>
      <div class="ok" style="margin:12px 0 0;">✅ Le gardien a le dossier au moins aussi riche — rien à faire.</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

</body></html>
