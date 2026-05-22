<?php
/*
 * bailleur_crg_audit.php — Audit CRG : détection manquants et incohérences
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo']; $userId = (int)current_user_id(); $roleId = (int)current_role_id();
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }

$current_page = 'bailleur_crg_audit';

/* ── POST : justifier un CRG manquant ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['justify_absence'])) {
    verify_csrf_any();
    $jProp = (int)$_POST['j_prop'];
    $jAnnee = (int)$_POST['j_annee'];
    $jTrim = (int)$_POST['j_trim'];
    $jMotif = trim($_POST['j_motif'] ?? '');
    if ($jProp > 0 && $jAnnee > 0 && $jTrim > 0 && $jMotif !== '') {
        // Vérifier si entrée existe
        $chk = $pdo->prepare("SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?");
        $chk->execute([$jProp, $jAnnee, $jTrim]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE crg_trimestres SET parse_statut='absent', motif_absence=? WHERE id_proprietaire=? AND annee=? AND trimestre=?")->execute([$jMotif, $jProp, $jAnnee, $jTrim]);
        } else {
            $pdo->prepare("INSERT INTO crg_trimestres (id_proprietaire, annee, trimestre, parse_statut, motif_absence, uploaded_at) VALUES (?,?,?,'absent',?,NOW())")->execute([$jProp, $jAnnee, $jTrim, $jMotif]);
        }
    }
    header('Location: bailleur_crg_audit.php?' . http_build_query(['prop' => $_POST['f_prop'] ?? 0, 'annee' => $_POST['f_annee'] ?? date('Y')]));
    exit;
}

/* ── Propriétaires ── */
$stmtProp = $pdo->prepare("SELECT up.id_proprietaire, up.label, p.nom, p.societe FROM user_proprietaires up JOIN proprietaires p ON p.id = up.id_proprietaire WHERE up.id_user = ? ORDER BY up.ordre");
$stmtProp->execute([$userId]);
$proprietaires = $stmtProp->fetchAll(PDO::FETCH_ASSOC);
$propIds = array_column($proprietaires, 'id_proprietaire');
if (empty($propIds) && in_array($roleId, [1,7], true)) {
    $proprietaires = $pdo->query("SELECT id AS id_proprietaire, COALESCE(societe,CONCAT(nom,' ',prenom)) AS label, nom, societe FROM proprietaires WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $propIds = array_column($proprietaires, 'id_proprietaire');
}

$fProp  = isset($_GET['prop'])  ? (int)$_GET['prop']  : 0;
$fAnnee = isset($_GET['annee']) ? (int)$_GET['annee']  : (int)date('Y');

if ($fProp <= 0 && count($propIds) === 1) $fProp = (int)$propIds[0];
$activePropIds = ($fProp > 0 && in_array($fProp, $propIds)) ? [$fProp] : $propIds;

$annees = range((int)date('Y'), (int)date('Y') - 4);
$manquants = []; $incoherents = [];

if (!empty($activePropIds)) {
    $ph = implode(',', array_fill(0, count($activePropIds), '?'));

    // CRG existants
    $stmtE = $pdo->prepare("SELECT id_proprietaire, trimestre, parse_statut, total_credits, total_debits, date_arrete, motif_absence FROM crg_trimestres WHERE id_proprietaire IN ($ph) AND annee = ?");
    $stmtE->execute(array_merge($activePropIds, [$fAnnee]));
    $existing = [];
    foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $existing[(int)$c['id_proprietaire']][(int)$c['trimestre']] = $c;
    }

    // Détecter manquants, absents justifiés, et incohérences
    // Un CRG est considéré manquant 10 jours après la fin du trimestre
    // T1 fin 31/03 → manquant à partir du 10/04
    // T2 fin 30/06 → manquant à partir du 10/07
    // T3 fin 30/09 → manquant à partir du 10/10
    // T4 fin 31/12 → manquant à partir du 10/01 année suivante
    $today = new DateTimeImmutable();
    $trimEndDates = [
        1 => $fAnnee . '-04-10',
        2 => $fAnnee . '-07-10',
        3 => $fAnnee . '-10-10',
        4 => ($fAnnee + 1) . '-01-10',
    ];
    $absents = [];
    foreach ($activePropIds as $pid) {
        $propLabel = '';
        foreach ($proprietaires as $p) { if ((int)$p['id_proprietaire'] === (int)$pid) { $propLabel = $p['societe'] ?: $p['label'] ?: $p['nom']; break; } }

        for ($t = 1; $t <= 4; $t++) {
            // Pas encore exigible si on n'a pas dépassé J+10 après fin trimestre
            $deadline = new DateTimeImmutable($trimEndDates[$t]);
            if ($today < $deadline) continue;
            if (!isset($existing[$pid][$t])) {
                $manquants[] = ['prop_id' => $pid, 'prop' => $propLabel, 'trimestre' => $t];
            } elseif ($existing[$pid][$t]['parse_statut'] === 'absent') {
                $absents[] = ['prop_id' => $pid, 'prop' => $propLabel, 'trimestre' => $t, 'motif' => $existing[$pid][$t]['motif_absence'] ?? ''];
            } else {
                $c = $existing[$pid][$t];
                $issues = [];
                if ($c['parse_statut'] === 'erreur') $issues[] = 'Parsing en erreur';
                if ((float)$c['total_credits'] == 0 && (float)$c['total_debits'] == 0) $issues[] = 'Crédits et débits à 0';
                if ((float)$c['total_debits'] > (float)$c['total_credits'] * 3) $issues[] = 'Débits anormalement élevés (>3x crédits)';
                if (!empty($issues)) {
                    $incoherents[] = ['prop_id' => $pid, 'prop' => $propLabel, 'trimestre' => $t, 'credits' => (float)$c['total_credits'], 'debits' => (float)$c['total_debits'], 'issues' => $issues];
                }
            }
        }
    }
}

$layout_title = 'Audit CRG'; $layout_module = 'Ma Box Bailleur'; $layout_sidebar = 'sidebar_agency';
$_act = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;';
$_on = $_act.'background:#4a6038;color:#fff;border:1px solid #4a6038;';
$_off = $_act.'background:#fff;color:#555;border:1px solid #d4d7de;';
$layout_head_kpis = '<div style="display:flex;gap:10px;justify-content:center;flex:1;">
    <a href="bailleur_dashboard.php?prop='.$fProp.'" style="'.$_off.'">📊 Dashboard</a>
    <a href="bailleur_immeubles.php?prop='.$fProp.'" style="'.$_off.'">🏢 Immeubles</a>
    <a href="bailleur_ged.php?prop='.$fProp.'" style="'.$_off.'">📁 GED</a>
    <a href="bailleur_crg_audit.php?prop='.$fProp.'&annee='.$fAnnee.'" style="'.$_on.'">🔍 Audit CRG</a>
    <a href="bailleur_sci_organigramme.php" style="'.$_off.'">🏛 SCI</a>
</div>';
$layout_head_actions = '';
$layout_extra_css = '<style>
.bf{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:12px 16px;background:#fff;border-radius:12px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bf select{padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px}
.bf label{font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px}
.bs{background:#fff;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bs-t{font-size:14px;font-weight:700;margin-bottom:12px}
.bt{width:100%;border-collapse:collapse;font-size:12px}
.bt th{text-align:left;font-size:9px;text-transform:uppercase;color:#888;padding:6px 8px;border-bottom:2px solid #eee}
.bt td{padding:6px 8px;border-bottom:1px solid #f3f4f6}
.bt tr:hover{background:#f9fafb}
.bl{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.bl a{padding:7px 14px;background:#fff;border:1px solid #d4d7de;border-radius:9px;text-decoration:none;font-size:11px;font-weight:600;color:#555}
.bl a:hover{border-color:#4a6038;color:#4a6038}.bl a.on{background:#4a6038;color:#fff;border-color:#4a6038}
.bb{display:inline-block;padding:2px 7px;border-radius:7px;font-size:10px;font-weight:600}
.bb-d{background:#fef2f2;color:#dc2626}.bb-w{background:#fffbeb;color:#d97706}.bb-ok{background:#f0fdf4;color:#16a34a}
</style>';

ob_start();
?>

<form method="get" class="bf">
  <div><label>Propriétaire</label><select name="prop" onchange="this.form.submit()"><option value="0">— Tous —</option>
    <?php foreach ($proprietaires as $p): ?><option value="<?= $p['id_proprietaire'] ?>" <?= $fProp==$p['id_proprietaire']?'selected':'' ?>><?= h($p['societe']?:$p['label']?:$p['nom']) ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Année</label><select name="annee" onchange="this.form.submit()">
    <?php foreach ($annees as $a): ?><option value="<?= $a ?>" <?= $fAnnee==$a?'selected':'' ?>><?= $a ?></option><?php endforeach; ?>
  </select></div>
</form>

<!-- Manquants -->
<div class="bs">
  <div class="bs-t">CRG manquants — <?= $fAnnee ?> (<?= count($manquants) ?>)</div>
  <?php if (empty($manquants)): ?>
    <p style="text-align:center;color:#16a34a;font-weight:600;padding:12px">✅ Tous les CRG sont présents</p>
  <?php else: ?>
  <table class="bt"><thead><tr><th>Propriétaire</th><th>Trimestre</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($manquants as $mi => $m): ?>
  <tr><td><?= h($m['prop']) ?></td><td>T<?= $m['trimestre'] ?> <?= $fAnnee ?></td>
  <td><span class="bb bb-d">Manquant</span></td>
  <td style="display:flex;gap:6px;align-items:center;">
    <a href="agency_immeubles.php?modal_crg=1" style="font-size:11px;color:#4878a6;white-space:nowrap;">📤 Importer</a>
    <button type="button" onclick="document.getElementById('justify-<?= $mi ?>').style.display=document.getElementById('justify-<?= $mi ?>').style.display==='none'?'table-row':'none'" style="font-size:10px;padding:3px 8px;border:1px solid #d97706;border-radius:6px;background:#fffbeb;color:#d97706;cursor:pointer;white-space:nowrap;">⚠ Justifier l'absence</button>
  </td></tr>
  <tr id="justify-<?= $mi ?>" style="display:none;background:#fffdf5;">
    <td colspan="4">
      <form method="post" style="display:flex;gap:8px;align-items:center;padding:6px 0;">
        <input type="hidden" name="justify_absence" value="1">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="j_prop" value="<?= $m['prop_id'] ?>">
        <input type="hidden" name="j_annee" value="<?= $fAnnee ?>">
        <input type="hidden" name="j_trim" value="<?= $m['trimestre'] ?>">
        <input type="hidden" name="f_prop" value="<?= $fProp ?>">
        <input type="hidden" name="f_annee" value="<?= $fAnnee ?>">
        <select name="j_motif" required style="padding:5px 8px;border:1px solid #d4d7de;border-radius:6px;font-size:11px;">
          <option value="">— Raison —</option>
          <option value="Pas de mouvement ce trimestre">Pas de mouvement</option>
          <option value="CRG non encore reçu du gestionnaire">Non reçu du gestionnaire</option>
          <option value="Immeuble vendu / mandat résilié">Immeuble vendu / mandat résilié</option>
          <option value="Immeuble en travaux, pas de locataire">En travaux, pas de locataire</option>
          <option value="Erreur — CRG à retrouver">À retrouver</option>
        </select>
        <button type="submit" style="padding:4px 12px;background:#d97706;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;">Confirmer</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>

<!-- Absents justifiés -->
<?php if (!empty($absents)): ?>
<div class="bs">
  <div class="bs-t">CRG absents justifiés (<?= count($absents) ?>)</div>
  <table class="bt"><thead><tr><th>Propriétaire</th><th>Trimestre</th><th>Motif</th><th>Statut</th></tr></thead><tbody>
  <?php foreach ($absents as $a): ?>
  <tr><td><?= h($a['prop']) ?></td><td>T<?= $a['trimestre'] ?> <?= $fAnnee ?></td>
  <td style="font-size:11px;color:#666"><?= h($a['motif']) ?></td>
  <td><span class="bb bb-w">Justifié</span></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>

<!-- Incohérences -->
<div class="bs">
  <div class="bs-t">Incohérences détectées (<?= count($incoherents) ?>)</div>
  <?php if (empty($incoherents)): ?>
    <p style="text-align:center;color:#16a34a;font-weight:600;padding:12px">✅ Aucune incohérence</p>
  <?php else: ?>
  <table class="bt"><thead><tr><th>Propriétaire</th><th>Trim.</th><th>Crédits</th><th>Débits</th><th>Problème(s)</th></tr></thead><tbody>
  <?php foreach ($incoherents as $i): ?>
  <tr><td><?= h($i['prop']) ?></td><td>T<?= $i['trimestre'] ?></td>
  <td style="font-family:'DM Mono',monospace"><?= fmt($i['credits']) ?></td>
  <td style="font-family:'DM Mono',monospace"><?= fmt($i['debits']) ?></td>
  <td><?php foreach ($i['issues'] as $is): ?><span class="bb bb-w"><?= h($is) ?></span> <?php endforeach; ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
