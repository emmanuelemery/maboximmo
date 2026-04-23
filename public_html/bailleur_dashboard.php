<?php
/*
 * bailleur_dashboard.php (v3)
 * Dashboard Bailleur — connecté aux tables CRG, immeubles, biens, baux
 * 3 niveaux : Propriétaire → Immeuble → Lot
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();

/* ── POST : marquer locataire parti ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_parti'])) {
    verify_csrf_any();
    $slId = (int)$_POST['sl_id'];
    $bailId = (int)$_POST['bail_id'];
    if ($slId > 0) {
        $pdo->prepare("UPDATE crg_situations_locataires SET locataire_parti = 1 WHERE id = ?")->execute([$slId]);
        // Mettre à jour le bail si trouvé
        if ($bailId > 0) {
            // Trouver le dernier CRG avec un loyer > 0 pour ce bail
            $stmtLast = $pdo->prepare("
                SELECT ct.annee, ct.trimestre FROM crg_situations_locataires sl
                JOIN crg_trimestres ct ON ct.id = sl.id_crg
                WHERE sl.id_bail = ? AND sl.loyer_appele > 0
                ORDER BY ct.annee DESC, ct.trimestre DESC LIMIT 1
            ");
            $stmtLast->execute([$bailId]);
            $lastActive = $stmtLast->fetch(PDO::FETCH_ASSOC);
            $dateDepart = null;
            if ($lastActive) {
                $endMonth = match((int)$lastActive['trimestre']) { 1=>3, 2=>6, 3=>9, 4=>12, default=>12 };
                $dateDepart = $lastActive['annee'] . '-' . str_pad((string)$endMonth, 2, '0', STR_PAD_LEFT) . '-' . ($endMonth == 6 || $endMonth == 9 ? '30' : '31');
            }
            // Récupérer solde impayé
            $stmtSolde = $pdo->prepare("SELECT total_impaye FROM crg_situations_locataires WHERE id = ?");
            $stmtSolde->execute([$slId]);
            $solde = (float)$stmtSolde->fetchColumn();

            $pdo->prepare("UPDATE baux SET statut = 'termine', date_depart = ?, date_fin = ?, solde_depart = ? WHERE id = ?")
                ->execute([$dateDepart, $dateDepart, -$solde, $bailId]);
            // Marquer tous les trimestres de ce locataire comme parti
            $pdo->prepare("UPDATE crg_situations_locataires SET locataire_parti = 1 WHERE id_bail = ? AND loyer_appele = 0")->execute([$bailId]);
        }
    }
    header('Location: bailleur_dashboard.php?' . http_build_query(array_filter(['prop'=>$_POST['f_prop']??0,'annee'=>$_POST['f_annee']??0,'trim'=>$_POST['f_trim']??0,'imm'=>$_POST['f_imm']??0])));
    exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }

/* ── Propriétaires accessibles ── */
$stmtProp = $pdo->prepare("
    SELECT up.id_proprietaire, up.label, p.nom, p.prenom, p.societe
    FROM user_proprietaires up
    JOIN proprietaires p ON p.id = up.id_proprietaire
    WHERE up.id_user = ?
    ORDER BY up.ordre, up.label
");
$stmtProp->execute([$userId]);
$proprietaires = $stmtProp->fetchAll(PDO::FETCH_ASSOC);
$propIds = array_column($proprietaires, 'id_proprietaire');

// Admin/super-admin : si aucun lien, charger tous les propriétaires
if (empty($propIds) && in_array($roleId, [1, 7], true)) {
    $stmtAll = $pdo->query("SELECT id AS id_proprietaire, COALESCE(societe, CONCAT(nom,' ',prenom)) AS label, nom, prenom, societe FROM proprietaires WHERE actif=1 ORDER BY nom");
    $proprietaires = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    $propIds = array_column($proprietaires, 'id_proprietaire');
}

/* ── Filtres ── */
$fProp  = isset($_GET['prop'])  ? (int)$_GET['prop']  : 0;
$fAnnee = isset($_GET['annee']) ? (int)$_GET['annee']  : (int)date('Y');
$fTrim  = isset($_GET['trim'])  ? (int)$_GET['trim']   : 0;
$fImm   = isset($_GET['imm'])   ? (int)$_GET['imm']    : 0;
$fBien  = isset($_GET['bien'])  ? (int)$_GET['bien']   : 0;

// Rôles propriétaires externes (9 = PROPRIO, 10 = PROPRIO_VIP) :
// ne pas afficher les indicateurs internes (dépenses détaillées, impayés,
// débiteurs) — réservés à l'admin cabinet. On garde pour eux : encaissements,
// solde, lots, patrimoine.
$_idRoleDash = (int)($_SESSION['id_role'] ?? 0);
$isProprioDash = in_array($_idRoleDash, [9, 10], true);
$showInterne   = !$isProprioDash; // admin / collab / manager / super admin

if ($fProp <= 0 && count($propIds) === 1) $fProp = (int)$propIds[0];
$activePropIds = ($fProp > 0 && in_array($fProp, $propIds)) ? [$fProp] : $propIds;

$kpi = ['encaissements'=>0,'depenses'=>0,'solde'=>0,'lots_total'=>0,'lots_occupes'=>0,'lots_impayes'=>0];
$immeubles = []; $details = []; $annees = []; $depensesCat = []; $crgs = [];

if (!empty($activePropIds)) {
    $ph = implode(',', array_fill(0, count($activePropIds), '?'));

    // Années
    $stmtY = $pdo->prepare("SELECT DISTINCT annee FROM crg_trimestres WHERE id_proprietaire IN ($ph) ORDER BY annee DESC");
    $stmtY->execute($activePropIds);
    $annees = array_column($stmtY->fetchAll(), 'annee');

    // CRG
    $crgWhere = "id_proprietaire IN ($ph) AND annee = ?";
    $crgParams = array_merge($activePropIds, [$fAnnee]);
    if ($fTrim > 0) { $crgWhere .= " AND trimestre = ?"; $crgParams[] = $fTrim; }
    $stmtCrg = $pdo->prepare("SELECT id, id_proprietaire, annee, trimestre, total_credits, total_debits, solde_report FROM crg_trimestres WHERE $crgWhere ORDER BY trimestre");
    $stmtCrg->execute($crgParams);
    $crgs = $stmtCrg->fetchAll(PDO::FETCH_ASSOC);
    $crgIds = array_column($crgs, 'id');

    $totalE = 0; $totalD = 0;
    // Si un immeuble est sélectionné, recalculer depuis les écritures de cet immeuble
    if ($fImm > 0 && !empty($crgIds)) {
        $phC2 = implode(',', array_fill(0, count($crgIds), '?'));
        $stmtImmTotals = $pdo->prepare("
            SELECT SUM(e.credit) AS tot_credits, SUM(e.debit) AS tot_debits
            FROM crg_ecritures e
            JOIN biens b ON b.id = e.id_bien
            WHERE e.id_crg IN ($phC2) AND b.id_immeuble = ?
        ");
        $stmtImmTotals->execute(array_merge($crgIds, [$fImm]));
        $immTotals = $stmtImmTotals->fetch(PDO::FETCH_ASSOC);
        $totalE = (float)($immTotals['tot_credits'] ?? 0);
        $totalD = (float)($immTotals['tot_debits'] ?? 0);
        // Si écritures vides, fallback sur situations locataires
        if ($totalE == 0 && $totalD == 0) {
            $stmtSlTotals = $pdo->prepare("
                SELECT SUM(sl.total_regle) AS tot_credits, SUM(sl.loyer_appele) AS tot_debits
                FROM crg_situations_locataires sl
                JOIN biens b ON b.id = sl.id_bien
                WHERE sl.id_crg IN ($phC2) AND b.id_immeuble = ?
            ");
            $stmtSlTotals->execute(array_merge($crgIds, [$fImm]));
            $slTotals = $stmtSlTotals->fetch(PDO::FETCH_ASSOC);
            $totalE = (float)($slTotals['tot_credits'] ?? 0);
            $totalD = (float)($slTotals['tot_debits'] ?? 0);
        }
    } else {
        foreach ($crgs as $c) { $totalE += (float)$c['total_credits']; $totalD += (float)$c['total_debits']; }
    }

    // Immeubles
    $stmtImm = $pdo->prepare("SELECT id, id_proprietaire, nom_immeuble, adresse_1, code_postal, ville, code_crg, compte_gestion FROM immeubles WHERE id_proprietaire IN ($ph) ORDER BY nom_immeuble");
    $stmtImm->execute($activePropIds);
    $immeubles = $stmtImm->fetchAll(PDO::FETCH_ASSOC);

    // Situations locataires
    $lotsT = 0; $lotsO = 0; $lotsI = 0;
    if (!empty($crgIds)) {
        $phC = implode(',', array_fill(0, count($crgIds), '?'));
        $dW = "sl.id_crg IN ($phC)"; $dP = $crgIds;
        if ($fImm > 0) { $dW .= " AND b.id_immeuble = ?"; $dP[] = $fImm; }
        if ($fBien > 0) { $dW .= " AND sl.id_bien = ?"; $dP[] = $fBien; }
        $stmtDet = $pdo->prepare("
            SELECT sl.*, sl.locataire_parti, b.id_immeuble, i.nom_immeuble, i.adresse_1 AS imm_adresse, ct.annee, ct.trimestre
            FROM crg_situations_locataires sl
            LEFT JOIN biens b ON b.id = sl.id_bien
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            LEFT JOIN crg_trimestres ct ON ct.id = sl.id_crg
            WHERE $dW ORDER BY i.nom_immeuble, sl.numero_lot, ct.trimestre
        ");
        $stmtDet->execute($dP);
        $details = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        foreach ($details as $d) {
            $lotsT++;
            if ($d['statut_trimestre'] === 'occupe') $lotsO++;
            if ((float)$d['total_impaye'] > 0) $lotsI++;
        }

        // Dépenses par ligne (pour regroupement par rubrique)
        if ($fImm > 0) {
            $stmtEcr = $pdo->prepare("SELECT e.libelle, e.debit, e.credit FROM crg_ecritures e LEFT JOIN biens b ON b.id = e.id_bien WHERE e.id_crg IN ($phC) AND (b.id_immeuble = ? OR e.id_bien IS NULL)");
            $stmtEcr->execute(array_merge($crgIds, [$fImm]));
        } else {
            $stmtEcr = $pdo->prepare("SELECT libelle, debit, credit FROM crg_ecritures WHERE id_crg IN ($phC)");
            $stmtEcr->execute($crgIds);
        }
        $rawEcritures = $stmtEcr->fetchAll(PDO::FETCH_ASSOC);

        // Regroupement par rubrique métier
        $rubriques = [
            'Taxe foncière'  => ['taxe foncière', 'taxe fonci', 'ordures menag', 'ordures ménag'],
            'Syndic'         => ['syndic', 'appel de fonds', 'fonds de travaux', 'solde de charge', 'sdc '],
            'Assurance'      => ['assurance', 'pno', 'arilim'],
            'Honoraires'     => ['honoraires', 'tva/honoraires', 'tva/hono'],
            'Procédures'     => ['huissier', 'avocat', 'barlatier', 'procédure', 'procedure'],
        ];
        $depensesCat = [];
        foreach ($rawEcritures as $e) {
            $lib = mb_strtolower($e['libelle'] ?? '');
            $matched = 'Autres';
            foreach ($rubriques as $rubName => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($lib, $kw)) { $matched = $rubName; break 2; }
                }
            }
            if (!isset($depensesCat[$matched])) $depensesCat[$matched] = ['categorie' => $matched, 'total_debit' => 0, 'total_credit' => 0];
            $depensesCat[$matched]['total_debit'] += (float)$e['debit'];
            $depensesCat[$matched]['total_credit'] += (float)$e['credit'];
        }
        // Trier par total_debit desc
        usort($depensesCat, fn($a, $b) => $b['total_debit'] <=> $a['total_debit']);
    }

    // KPI calculés depuis les situations locataires (données fiables, filtrées par immeuble)
    // Encaissements = total réglé par les locataires sur la période
    // Dépenses = total appelé (loyers + charges) sur la période
    // Solde = encaissements - dépenses
    $kpiEncaiss = 0; $kpiDepenses = 0; $kpiImpayes = 0;
    foreach ($details as $d) {
        $kpiEncaiss  += (float)$d['total_regle'];
        $kpiDepenses += (float)$d['total_loyers'] + (float)$d['total_charges'];
        if ((float)$d['total_impaye'] > 0 && (float)$d['loyer_appele'] > 0) {
            $kpiImpayes += (float)$d['total_impaye'];
        }
    }

    $kpi = [
        'encaissements' => $kpiEncaiss,
        'depenses'      => $kpiDepenses,
        'solde'         => $kpiEncaiss - $kpiDepenses,
        'lots_total'    => $lotsT,
        'lots_occupes'  => $lotsO,
        'lots_impayes'  => $lotsI,
        'total_impayes' => $kpiImpayes,
    ];

    // Documents CRG liés (pour liens PDF)
    $crgDocs = [];
    $stmtCrgDocs = $pdo->prepare("SELECT id_proprietaire, annee, trimestre, chemin_fichier, titre FROM bailleur_documents WHERE id_proprietaire IN ($ph) AND type_document='CRG' ORDER BY annee DESC, trimestre DESC");
    $stmtCrgDocs->execute($activePropIds);
    foreach ($stmtCrgDocs->fetchAll(PDO::FETCH_ASSOC) as $cd) {
        $crgDocs[(int)$cd['id_proprietaire']][(int)$cd['annee']][(int)$cd['trimestre']] = $cd['chemin_fichier'];
    }
}

/* ── Layout ── */
$layout_title   = 'Dashboard Bailleur';
$layout_module  = 'Ma Box Bailleur';
$layout_sidebar = 'sidebar_bailleur';
$current_page   = 'bailleur_dashboard';
$_on = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;background:#4a6038;color:#fff;border:1px solid #4a6038;';
$_off = 'padding:8px 24px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;background:#fff;color:#555;border:1px solid #d4d7de;';
$_bailNav = '<div style="display:flex;gap:10px;justify-content:center;flex:1;">
    <a href="bailleur_dashboard.php?prop=' . $fProp . '" style="' . $_on . '">📊 Dashboard</a>
    <a href="bailleur_immeubles.php?prop=' . $fProp . '" style="' . $_off . '">🏢 Immeubles</a>
    <a href="bailleur_ged.php?prop=' . $fProp . '" style="' . $_off . '">📁 GED</a>
    <a href="bailleur_crg_audit.php?prop=' . $fProp . '&annee=' . $fAnnee . '" style="' . $_off . '">🔍 Audit CRG</a>
    <a href="bailleur_sci_organigramme.php" style="' . $_off . '">🏛 SCI</a>
</div>';
$layout_head_kpis = $_bailNav;

// Bouton dédié "Partis débiteurs" (masqué par défaut, accès sur demande)
// Réservé aux rôles internes (admin cabinet) — jamais affiché aux propriétaires externes.
$_debCount = isset($lotsPartisImpaye) ? count($lotsPartisImpaye) : 0;
if ($showInterne && $_debCount > 0) {
    $_debTarget = $_GET;
    if (isset($_debTarget['debiteurs'])) { unset($_debTarget['debiteurs']); $_debLabel = '🔼 Masquer débiteurs'; $_debActive = true; }
    else                                 { $_debTarget['debiteurs'] = 1;     $_debLabel = '⚠ Partis débiteurs (' . $_debCount . ')'; $_debActive = false; }
    $_debUrl = 'bailleur_dashboard.php?' . http_build_query($_debTarget);
    $_debStyle = 'padding:8px 18px;border-radius:10px;text-decoration:none;font-size:12.5px;font-weight:600;border:1px solid ' . ($_debActive ? '#dc2626' : '#d4d7de') . ';background:' . ($_debActive ? '#fef2f2' : '#fff') . ';color:#dc2626;';
    $layout_head_actions = '<a href="' . $_debUrl . '" style="' . $_debStyle . '">' . $_debLabel . '</a>';
} else {
    $layout_head_actions = '';
}

$layout_extra_css = '<style>
.bk{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px}
.bk>div{background:#fff;border-radius:14px;padding:16px;box-shadow:3px 3px 10px rgba(0,0,0,.06),-3px -3px 8px #fff;text-align:center}
.bk-v{font-size:20px;font-weight:700;font-family:"DM Mono",monospace}
.bk-l{font-size:10px;color:#888;margin-top:3px;text-transform:uppercase;letter-spacing:.05em}
.bf{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:12px 16px;background:#fff;border-radius:12px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bf select{padding:5px 8px;border:1px solid #d4d7de;border-radius:8px;font-size:12px}
.bf label{font-size:10px;font-weight:600;color:#666;display:block;margin-bottom:2px}
.bs{background:#fff;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:2px 2px 8px rgba(0,0,0,.04)}
.bs-t{font-size:14px;font-weight:700;margin-bottom:12px}
.bt{width:100%;border-collapse:collapse;font-size:12px}
.bt th{text-align:left;font-size:9px;text-transform:uppercase;color:#888;padding:6px 8px;border-bottom:2px solid #eee}
.bt td{padding:6px 8px;border-bottom:1px solid #f3f4f6}
.bt tr:hover{background:#f9fafb}
.bb{display:inline-block;padding:2px 7px;border-radius:7px;font-size:10px;font-weight:600}
.bb-ok{background:#f0fdf4;color:#16a34a}.bb-w{background:#fffbeb;color:#d97706}.bb-d{background:#fef2f2;color:#dc2626}.bb-v{background:#f3f4f6;color:#6b7280}
.bl{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.bl a{padding:7px 14px;background:#fff;border:1px solid #d4d7de;border-radius:9px;text-decoration:none;font-size:11px;font-weight:600;color:#555}
.bl a:hover{border-color:#4a6038;color:#4a6038}.bl a.on{background:#4a6038;color:#fff;border-color:#4a6038}
</style>';

ob_start();
?>

<form method="get" class="bf">
  <div><label>Propriétaire</label><select name="prop" onchange="this.form.submit()"><option value="0">— Tous —</option>
    <?php foreach ($proprietaires as $p): ?><option value="<?= $p['id_proprietaire'] ?>" <?= $fProp==$p['id_proprietaire']?'selected':'' ?>><?= h($p['societe']?:$p['label']?:$p['nom']) ?></option><?php endforeach; ?>
  </select></div>
  <div><label>Année</label><select name="annee" onchange="this.form.submit()">
    <?php foreach ($annees as $a): ?><option value="<?= $a ?>" <?= $fAnnee==$a?'selected':'' ?>><?= $a ?></option><?php endforeach; ?>
    <?php if (empty($annees)): ?><option value="<?= date('Y') ?>"><?= date('Y') ?></option><?php endif; ?>
  </select></div>
  <div><label>Trimestre</label><select name="trim" onchange="this.form.submit()">
    <option value="0" <?= $fTrim===0?'selected':'' ?>>Année</option>
    <option value="1" <?= $fTrim===1?'selected':'' ?>>T1</option><option value="2" <?= $fTrim===2?'selected':'' ?>>T2</option>
    <option value="3" <?= $fTrim===3?'selected':'' ?>>T3</option><option value="4" <?= $fTrim===4?'selected':'' ?>>T4</option>
  </select></div>
  <?php if (!empty($immeubles)): ?>
  <div><label>Immeuble</label><select name="imm" onchange="this.form.submit()"><option value="0">— Tous —</option>
    <?php foreach ($immeubles as $im): ?><option value="<?= $im['id'] ?>" <?= $fImm==$im['id']?'selected':'' ?>><?= h($im['nom_immeuble']?:$im['adresse_1']) ?></option><?php endforeach; ?>
  </select></div>
  <?php endif; ?>
</form>


<div class="bk">
  <div><div class="bk-v" style="color:#16a34a"><?= fmt($kpi['encaissements']) ?></div><div class="bk-l">Loyers appelés</div></div>
  <?php if ($showInterne): ?>
  <div><div class="bk-v" style="color:#dc2626"><?= fmt($kpi['depenses']) ?></div><div class="bk-l">Dépenses</div></div>
  <div><div class="bk-v" style="color:<?= $kpi['solde']>=0?'#16a34a':'#dc2626' ?>"><?= fmt($kpi['solde']) ?></div><div class="bk-l">Solde net</div></div>
  <?php endif; ?>
  <div><div class="bk-v"><?= $kpi['lots_total'] ?></div><div class="bk-l">Lots</div></div>
  <div><div class="bk-v" style="color:#16a34a"><?= $kpi['lots_occupes'] ?></div><div class="bk-l">Occupés</div></div>
  <?php if ($showInterne): ?>
  <div><div class="bk-v" style="color:<?= $kpi['lots_impayes']>0?'#dc2626':'#16a34a' ?>"><?= $kpi['lots_impayes'] ?></div><div class="bk-l">Lots en impayé</div></div>
  <div><div class="bk-v" style="color:<?= $kpi['total_impayes']>0?'#dc2626':'#16a34a' ?>"><?= fmt($kpi['total_impayes']) ?></div><div class="bk-l">Total impayés</div></div>
  <?php endif; ?>
</div>

<?php
// La répartition des dépenses n'a de sens QUE sur un immeuble précis.
// Sur une vue globale (plusieurs immeubles agrégés), les catégories
// cumulent des choses non comparables — on ne l'affiche donc que si
// un immeuble est sélectionné.
// Également réservé à l'admin (dépenses détaillées = info interne cabinet)
if ($showInterne && $fImm > 0 && !empty($depensesCat)):
    $selImmNom = $immeubles[array_search($fImm, array_column($immeubles, 'id'))]['nom_immeuble'] ?? ('Immeuble #' . $fImm);
?>
<div class="bs"><div class="bs-t">Répartition des dépenses — <?= h($selImmNom) ?></div>
<table class="bt"><thead><tr><th>Catégorie</th><th>Débits</th><th>Crédits</th><th style="width:30%">Part</th></tr></thead><tbody>
<?php $mx = max(array_column($depensesCat,'total_debit')?:[1]);
$clr = ['Taxe foncière'=>'#7c3aed','Syndic'=>'#4878a6','Assurance'=>'#d97706','Honoraires'=>'#d4a843','Procédures'=>'#dc2626','Autres'=>'#6b7280'];
foreach ($depensesCat as $c): $p = $mx>0?((float)$c['total_debit']/$mx*100):0; $co=$clr[$c['categorie']]??'#6b7280'; ?>
<tr><td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $co ?>;margin-right:5px"></span><?= h($c['categorie']) ?></td>
<td style="font-family:'DM Mono',monospace;color:#dc2626"><?= fmt((float)$c['total_debit']) ?></td>
<td style="font-family:'DM Mono',monospace;color:#16a34a"><?= fmt((float)$c['total_credit']) ?></td>
<td><div style="height:7px;border-radius:4px;background:#eee;overflow:hidden"><div style="height:100%;width:<?= round($p) ?>%;background:<?= $co ?>;border-radius:4px"></div></div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<?php if (!empty($immeubles)):
  // Règles d'affichage de la liste immeubles :
  //   - Immeuble sélectionné ($fImm > 0)   → 1 seule ligne (l'immeuble actif)
  //   - Propriétaire sélectionné ($fProp)  → max 5 lignes visibles + scroll interne
  //   - Sans filtre                         → 3 lignes visibles + bouton "Voir tout"
  $immFiltres = $immeubles;
  if ($fImm > 0) {
      $immFiltres = array_values(array_filter($immeubles, fn($x) => (int)$x['id'] === $fImm));
  }
  $nbTotal = count($immFiltres);
  if ($fImm > 0)         { $visible = $nbTotal; $maxH = 'auto'; $collapseBtn = false; }
  elseif ($fProp > 0)    { $visible = min(5, $nbTotal); $maxH = '260px'; $collapseBtn = false; }
  else                   { $visible = min(3, $nbTotal); $maxH = '160px'; $collapseBtn = ($nbTotal > 3); }
?>
<div class="bs">
  <div class="bs-t" style="display:flex;justify-content:space-between;align-items:center;">
    <span>Immeubles (<?= $nbTotal ?><?= $fImm > 0 ? ' — sélection' : '' ?>)</span>
    <?php if ($collapseBtn): ?>
      <button type="button" id="bs-imm-toggle" onclick="bsToggleImm()"
              style="padding:4px 10px;border:1px solid #d4d7de;background:#fff;border-radius:6px;font-size:11px;font-weight:600;color:#4a6038;cursor:pointer;">
        ▼ Voir tout (<?= $nbTotal ?>)
      </button>
    <?php endif; ?>
  </div>
  <div id="bs-imm-scroll" style="max-height:<?= $maxH ?>;overflow-y:auto;transition:max-height .25s;">
    <table class="bt" style="margin:0;">
      <thead style="position:sticky;top:0;background:#fff;z-index:1;">
        <tr><th>Immeuble</th><th>Adresse</th><th>Mandat</th><th>Lots</th><th>Occupés</th><?php if ($showInterne): ?><th>Impayés</th><?php endif; ?><th>CRG</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($immFiltres as $im):
          $il = array_filter($details, fn($d)=>(int)($d['id_immeuble']??0)===(int)$im['id']);
          $io = count(array_filter($il, fn($d)=>$d['statut_trimestre']==='occupe'));
          $ii = count(array_filter($il, fn($d)=>(float)$d['total_impaye']>0));
          $it = array_sum(array_map(fn($d)=>(float)$d['total_impaye'], $il));
          $immPropId = (int)($im['id_proprietaire'] ?? $fProp);
          $immPdf = '';
          if ($fTrim > 0) { $immPdf = $crgDocs[$immPropId][$fAnnee][$fTrim] ?? ''; }
          else { for ($__t=4;$__t>=1;$__t--) { if (!empty($crgDocs[$immPropId][$fAnnee][$__t])) { $immPdf = $crgDocs[$immPropId][$fAnnee][$__t]; break; } } }
          $isSelected = ($fImm > 0 && (int)$im['id'] === $fImm);
        ?>
          <tr<?= $isSelected ? ' style="background:#f0fdf4;"' : '' ?>>
            <td><strong><?= h($im['nom_immeuble']?:$im['code_crg']) ?></strong></td>
            <td style="font-size:11px;color:#666"><?= h(trim(($im['adresse_1']??'').' '.($im['code_postal']??'').' '.($im['ville']??''))) ?></td>
            <td style="font-size:10px;font-family:'DM Mono',monospace;color:#4878a6"><?= h($im['compte_gestion'] ?? '') ?></td>
            <td><?= count($il) ?></td>
            <td><span class="bb bb-ok"><?= $io ?></span></td>
            <?php if ($showInterne): ?><td><?= $ii>0?'<span class="bb bb-d">'.$ii.' ('.fmt($it).')</span>':'<span class="bb bb-ok">0</span>' ?></td><?php endif; ?>
            <td><?php if ($immPdf): ?><a href="<?= h($immPdf) ?>" target="_blank" title="Ouvrir le CRG PDF" style="font-size:14px">📄</a><?php else: ?><span style="color:#ccc;font-size:11px">—</span><?php endif; ?></td>
            <td style="white-space:nowrap;">
              <?php if ($isSelected):
                  // Récupérer la première analyse investisseur d'un bien de cet immeuble → bouton "🎤 Réunion"
                  $stFind = $pdo->prepare("SELECT a.id FROM investisseur_analyses a
                      JOIN biens b ON b.id = a.id_bien_source
                      WHERE b.id_immeuble = :im
                      ORDER BY COALESCE(a.priorite_vente, 0) DESC, a.score_global DESC LIMIT 1");
                  $stFind->bindValue(':im', $fImm, PDO::PARAM_INT);
                  $stFind->execute();
                  $firstAnalyse = (int)$stFind->fetchColumn();
              ?>
                <a href="bien_liste.php?imm=<?= $im['id'] ?>"
                   style="padding:4px 10px;border:1px solid #4878a6;background:#eff6ff;color:#4878a6;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;margin-right:4px;"
                   title="Voir les biens de cet immeuble">🏠 Biens</a>
                <?php if ($firstAnalyse > 0): ?>
                <a href="investisseur/reunion.php?id=<?= $firstAnalyse ?>"
                   style="padding:4px 10px;border:1px solid #b4443a;background:#fef2f2;color:#b4443a;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;margin-right:4px;"
                   title="Ouvrir un bien de cet immeuble en mode réunion">🎤 Réunion</a>
                <?php endif; ?>
                <a href="bailleur_dashboard.php?prop=<?= $fProp ?>&annee=<?= $fAnnee ?>&trim=<?= $fTrim ?>"
                   style="font-size:11px;color:#dc2626;">✕ Retirer</a>
              <?php else: ?>
                <a href="bailleur_dashboard.php?prop=<?= $fProp ?>&annee=<?= $fAnnee ?>&trim=<?= $fTrim ?>&imm=<?= $im['id'] ?>" style="font-size:11px;color:#4878a6">Détail →</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($collapseBtn): ?>
<script>
(function(){
  var box = document.getElementById('bs-imm-scroll');
  var btn = document.getElementById('bs-imm-toggle');
  if (!box || !btn) return;
  var expanded = false;
  window.bsToggleImm = function(){
    expanded = !expanded;
    box.style.maxHeight = expanded ? box.scrollHeight + 'px' : '160px';
    btn.innerHTML = expanded ? '▲ Réduire' : '▼ Voir tout (<?= $nbTotal ?>)';
  };
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($details)): ?>
<div class="bs"><div class="bs-t" style="display:flex;justify-content:space-between;align-items:center;">
<span>Détail lots<?= $fImm>0?' — '.h($immeubles[array_search($fImm,array_column($immeubles,'id'))]['nom_immeuble']??''):'' ?></span>
<?php if ($fImm > 0):
    $selImm = $immeubles[array_search($fImm, array_column($immeubles, 'id'))] ?? [];
    $selPropId = (int)($selImm['id_proprietaire'] ?? $fProp);
    $selPdf = '';
    if ($fTrim > 0) { $selPdf = $crgDocs[$selPropId][$fAnnee][$fTrim] ?? ''; }
    else { for ($__t=4;$__t>=1;$__t--) { if (!empty($crgDocs[$selPropId][$fAnnee][$__t])) { $selPdf = $crgDocs[$selPropId][$fAnnee][$__t]; break; } } }
?>
<span style="display:flex;gap:8px;">
    <?php if ($selPdf): ?><a href="<?= h($selPdf) ?>" target="_blank" style="font-size:12px;color:#4878a6;text-decoration:none;padding:4px 10px;border:1px solid #d4d7de;border-radius:6px;">📄 CRG PDF</a><?php endif; ?>
    <a href="bailleur_ged.php?prop=<?= $selPropId ?>" style="font-size:12px;color:#4878a6;text-decoration:none;padding:4px 10px;border:1px solid #d4d7de;border-radius:6px;">📁 GED</a>
</span>
<?php endif; ?>
</div>
<?php
// Séparer actifs et partis
// Parti = pas de loyer appelé ET a un nom de locataire, OU marqué parti
$lotsActifs = array_filter($details, fn($d) => (float)$d['loyer_appele'] > 0 && (int)($d['locataire_parti'] ?? 0) === 0);
$lotsPartis = array_filter($details, fn($d) =>
    ((float)$d['loyer_appele'] == 0 && !empty($d['locataire_nom']))
    || (int)($d['locataire_parti'] ?? 0) === 1
);
// Partis avec impayé > 0 = à surveiller
$lotsPartisImpaye = array_filter($lotsPartis, fn($d) => (float)$d['total_impaye'] > 0);
// Partis à jour = archivables (impayé = 0)
$lotsPartisOk = array_filter($lotsPartis, fn($d) => (float)$d['total_impaye'] <= 0);
// Afficher les archivés ?
$showArchives = isset($_GET['archives']);
?>

<!-- Locataires actifs -->
<div style="font-size:12px;font-weight:600;color:#16a34a;margin-bottom:8px;">Locataires présents (<?= count($lotsActifs) ?>)</div>
<table class="bt"><thead><tr><th>Lot</th><th>Locataire</th><th>Statut</th><th>Loyer appelé</th><th>Réglés</th><th>Solde impayé</th><th>Période</th><th>CRG</th></tr></thead><tbody>
<?php foreach ($lotsActifs as $d):
$imp = (float)$d['total_impaye'];
$loyerAppele = (float)$d['total_loyers'] + (float)$d['total_charges']; // total loyers + charges du trimestre
$totalRegle = (float)$d['total_regle'];
$bc = $imp > 0 ? 'bb-w' : 'bb-ok';
$crgRow = null; if (!empty($crgIds)) { foreach ($crgs as $_c) { if ((int)$_c['id']===(int)$d['id_crg']) { $crgRow=$_c; break; } } }
$pdfLink = ''; if ($crgRow) { $pdfLink = $crgDocs[(int)$crgRow['id_proprietaire']][(int)$crgRow['annee']][(int)$crgRow['trimestre']] ?? ''; }
$periode = ($d['trimestre'] ? 'T'.$d['trimestre'] : '') . ' ' . ($d['annee'] ?? '');
?>
<tr><td><strong><?= h($d['numero_lot']) ?></strong><?php if (!$fImm): ?><br><span style="font-size:10px;color:#888"><?= h($d['nom_immeuble']??'') ?></span><?php endif; ?></td>
<td><?= h($d['locataire_nom']?:'—') ?></td>
<td><span class="bb <?= $bc ?>">Présent</span></td>
<td style="font-family:'DM Mono',monospace"><?= fmt($loyerAppele) ?></td>
<td style="font-family:'DM Mono',monospace;color:#16a34a"><?= fmt($totalRegle) ?></td>
<td style="font-family:'DM Mono',monospace;color:<?= $imp>0?'#dc2626':'#16a34a' ?>;font-weight:<?= $imp>0?'700':'400' ?>"><?= fmt($imp) ?></td>
<td style="font-size:11px;white-space:nowrap"><?= h(trim($periode)) ?></td>
<?php $pagePdf = (int)($d['page_pdf'] ?? 0); ?>
<td><?php if ($pdfLink): ?>
  <?php if ($pagePdf > 0): ?>
    <a href="#" title="CRG <?= h($periode) ?> — page <?= $pagePdf ?>" style="color:#16a34a;font-size:12px;font-weight:600;text-decoration:none;" onclick="event.preventDefault();window.open('api/pdf_viewer.php?file=<?= urlencode($pdfLink) ?>&name=<?= urlencode($d['locataire_nom']??'') ?>&page=<?= $pagePdf ?>','crg_viewer','width=900,height=700,scrollbars=yes,resizable=yes');">📄 p.<?= $pagePdf ?></a>
  <?php else: ?>
    <a href="<?= h(app_url('/' . $pdfLink)) ?>" target="_blank" title="CRG <?= h($periode) ?>" style="color:#999;font-size:13px;">📄</a>
  <?php endif; ?>
<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table>


<?php
$showDebiteurs = isset($_GET['debiteurs']);
$totalImpayePartis = array_sum(array_map(fn($d) => (float)$d['total_impaye'], $lotsPartisImpaye));
// Le bloc "partis débiteurs" est volontairement invisible par défaut.
// Il n'apparaît QUE si l'utilisateur a cliqué sur le bouton dédié (?debiteurs=1).
// Cela évite de polluer le dashboard principal avec une section de suivi
// contentieux qui nécessite une attention spécifique.
if ($showDebiteurs && !empty($lotsPartisImpaye)): ?>
<!-- Locataires partis avec impayé (affichage explicite seulement) -->
<div style="margin:16px 0 8px;display:flex;align-items:center;gap:12px;">
    <span style="font-size:12px;font-weight:600;color:#dc2626;">Locataires partis — solde impayé (<?= count($lotsPartisImpaye) ?>) — Total : <?= fmt($totalImpayePartis) ?></span>
    <?php $debParams = $_GET; unset($debParams['debiteurs']); ?>
    <a href="bailleur_dashboard.php?<?= http_build_query($debParams) ?>" style="font-size:11px;padding:4px 12px;border-radius:6px;border:1px solid #d4d7de;background:#fef2f2;color:#dc2626;text-decoration:none;font-weight:600;">
        🔼 Masquer les débiteurs
    </a>
</div>
<?php if (true): ?>
<table class="bt"><thead><tr><th>Lot</th><th>Locataire</th><th>Statut</th><th>Solde impayé</th><th>Période</th><th>CRG</th></tr></thead><tbody>
<?php foreach ($lotsPartisImpaye as $d):
$crgRow = null; if (!empty($crgIds)) { foreach ($crgs as $_c) { if ((int)$_c['id']===(int)$d['id_crg']) { $crgRow=$_c; break; } } }
$pdfLink = ''; if ($crgRow) { $pdfLink = $crgDocs[(int)$crgRow['id_proprietaire']][(int)$crgRow['annee']][(int)$crgRow['trimestre']] ?? ''; }
$periode = ($d['trimestre'] ? 'T'.$d['trimestre'] : '') . ' ' . ($d['annee'] ?? '');
$impP = (float)$d['total_impaye'];
$pagePdfP = (int)($d['page_pdf'] ?? 0);
?>
<tr><td><strong><?= h($d['numero_lot']) ?></strong><?php if (!$fImm): ?><br><span style="font-size:10px;color:#888"><?= h($d['nom_immeuble']??'') ?></span><?php endif; ?></td>
<td><?= h($d['locataire_nom']) ?></td>
<td><span class="bb bb-d">Parti — impayé</span></td>
<td style="font-family:'DM Mono',monospace;color:#dc2626;font-weight:700"><?= fmt($impP) ?></td>
<td style="font-size:11px"><?= h(trim($periode)) ?></td>
<td><?php if ($pdfLink && $pagePdfP > 0): ?>
    <a href="#" title="Page <?= $pagePdfP ?>" style="color:#16a34a;font-size:12px;font-weight:600;text-decoration:none;" onclick="event.preventDefault();window.open('api/pdf_viewer.php?file=<?= urlencode($pdfLink) ?>&name=<?= urlencode($d['locataire_nom']??'') ?>&page=<?= $pagePdfP ?>','crg_viewer','width=900,height=700,scrollbars=yes,resizable=yes');">📄 p.<?= $pagePdfP ?></a>
  <?php elseif ($pdfLink): ?>
    <a href="<?= h(app_url('/' . $pdfLink)) ?>" target="_blank" style="color:#999;font-size:13px;">📄</a>
  <?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; /* showDebiteurs */ ?>
<?php endif; /* lotsPartisImpaye */ ?>

<!-- Bouton archives -->
<?php if (!empty($lotsPartisOk)): ?>
<div style="margin:16px 0 8px;display:flex;align-items:center;gap:12px;">
    <span style="font-size:12px;font-weight:600;color:#6b7280;">Locataires partis — à jour (<?= count($lotsPartisOk) ?>)</span>
    <?php
    $archiveParams = $_GET;
    if ($showArchives) { unset($archiveParams['archives']); } else { $archiveParams['archives'] = 1; }
    ?>
    <a href="bailleur_dashboard.php?<?= http_build_query($archiveParams) ?>" style="font-size:11px;padding:4px 12px;border-radius:6px;border:1px solid #d4d7de;background:<?= $showArchives ? '#f3f4f6' : '#fff' ?>;color:#666;text-decoration:none;font-weight:600;">
        <?= $showArchives ? '🔼 Masquer les archivés' : '🔽 Voir les archivés (' . count($lotsPartisOk) . ')' ?>
    </a>
</div>
<?php if ($showArchives): ?>
<table class="bt" style="opacity:0.5"><thead><tr><th>Lot</th><th>Locataire</th><th>Statut</th><th>Solde</th><th>Période</th><th>CRG</th></tr></thead><tbody>
<?php foreach ($lotsPartisOk as $d):
$crgRow = null; if (!empty($crgIds)) { foreach ($crgs as $_c) { if ((int)$_c['id']===(int)$d['id_crg']) { $crgRow=$_c; break; } } }
$pdfLink = ''; if ($crgRow) { $pdfLink = $crgDocs[(int)$crgRow['id_proprietaire']][(int)$crgRow['annee']][(int)$crgRow['trimestre']] ?? ''; }
$periode = ($d['trimestre'] ? 'T'.$d['trimestre'] : '') . ' ' . ($d['annee'] ?? '');
$pagePdfP = (int)($d['page_pdf'] ?? 0);
?>
<tr><td><strong><?= h($d['numero_lot']) ?></strong><?php if (!$fImm): ?><br><span style="font-size:10px;color:#888"><?= h($d['nom_immeuble']??'') ?></span><?php endif; ?></td>
<td style="color:#999"><?= h($d['locataire_nom']) ?></td>
<td><span class="bb" style="background:#f3f4f6;color:#9ca3af">Archivé</span></td>
<td style="font-family:'DM Mono',monospace;color:#16a34a">0,00 €</td>
<td style="font-size:11px;color:#999"><?= h(trim($periode)) ?></td>
<td><?php if ($pdfLink && $pagePdfP > 0): ?>
    <a href="#" style="color:#16a34a;font-size:12px;font-weight:600;text-decoration:none;" onclick="event.preventDefault();window.open('api/pdf_viewer.php?file=<?= urlencode($pdfLink) ?>&name=<?= urlencode($d['locataire_nom']??'') ?>&page=<?= $pagePdfP ?>','crg_viewer','width=900,height=700,scrollbars=yes,resizable=yes');">📄 p.<?= $pagePdfP ?></a>
  <?php elseif ($pdfLink): ?>
    <a href="<?= h(app_url('/' . $pdfLink)) ?>" target="_blank" style="color:#999;font-size:13px;">📄</a>
  <?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?>
<?php endif; ?>

</div>
<?php endif; ?>

<?php if (empty($propIds)): ?>
<div class="bs" style="text-align:center;padding:40px">
  <div style="font-size:36px;margin-bottom:12px">📊</div>
  <h3>Aucun propriétaire associé</h3>
  <p style="color:#888;font-size:13px">Liez des propriétaires à votre compte pour accéder aux données.</p>
</div>
<?php endif; ?>

<?php
$layout_content = ob_get_clean();
$layout_extra_js = '<script>
async function crgOpenAt(pdfUrl, locataireName, btnEl) {
    if (!locataireName) { window.open(pdfUrl, "_blank"); return; }
    if (btnEl) { btnEl.textContent = "⏳"; btnEl.style.pointerEvents = "none"; }
    try {
        var r = await fetch("api/crg_find_page.php?file=" + encodeURIComponent(pdfUrl) + "&search=" + encodeURIComponent(locataireName));
        var d = await r.json();
        if (d.ok && d.page > 0) {
            if (btnEl) { btnEl.textContent = "📄 p." + d.page; btnEl.style.color = "#16a34a"; btnEl.style.pointerEvents = ""; }
            window.open(pdfUrl + "#page=" + d.page, "_blank");
        } else {
            if (btnEl) { btnEl.textContent = "📄"; btnEl.style.color = "#999"; btnEl.style.pointerEvents = ""; }
            window.open(pdfUrl, "_blank");
        }
    } catch(e) {
        if (btnEl) { btnEl.textContent = "📄"; btnEl.style.pointerEvents = ""; }
        window.open(pdfUrl, "_blank");
    }
}
</script>';
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
