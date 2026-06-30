<?php
/**
 * bailleur_dashboard.php — Dashboard Bailleur (v4)
 * Page d'accueil pour les utilisateurs avec service 'bailleur'
 * Accès : super admin + rôles avec service bailleur (9, 10, 1, 7, 8)
 * Filtre automatique sur les propriétaires de l'utilisateur (user_proprietaires)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();

// ── Accès : super admin OU service bailleur ─────────────
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403);
    exit('Accès réservé au module Bailleur.');
}

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ── Propriétaires accessibles ────────────────────────────
if ($isSuperAdmin) {
    $stmt = $pdo->query("
        SELECT p.id, COALESCE(p.societe, CONCAT(p.prenom,' ',p.nom)) AS label,
               p.societe, p.nom, p.prenom
        FROM proprietaires p
        WHERE EXISTS (SELECT 1 FROM crg_trimestres ct WHERE ct.id_proprietaire=p.id AND ct.parse_statut='ok')
        ORDER BY p.societe, p.nom
    ");
    $proprietaires = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT p.id, COALESCE(up.label, p.societe, CONCAT(p.prenom,' ',p.nom)) AS label,
               p.societe, p.nom, p.prenom
        FROM user_proprietaires up
        JOIN proprietaires p ON p.id=up.id_proprietaire
        WHERE up.id_user=?
        ORDER BY up.ordre, up.label
    ");
    $stmt->execute([$userId]);
    $proprietaires = $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

$allPropIds = array_column($proprietaires, 'id');

// ── Filtre multi-propriétaires (super admin uniquement) ───
$selectedProps = [];
if ($isSuperAdmin) {
    if (isset($_GET['props'])) {
        // Nouvelle sélection via GET → mémoriser en session
        foreach ((array)$_GET['props'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0 && in_array($sid, $allPropIds)) $selectedProps[] = $sid;
        }
        $_SESSION['bailleur_props'] = $selectedProps;
    } elseif (!empty($_SESSION['bailleur_props'])) {
        // Pas de GET → reprendre la session
        foreach ($_SESSION['bailleur_props'] as $sid) {
            $sid = (int)$sid;
            if (in_array($sid, $allPropIds)) $selectedProps[] = $sid;
        }
    }
}

// Si sélection → filtrer sur ces ids, sinon tous
$propIds = !empty($selectedProps) ? $selectedProps : $allPropIds;

// Labels des sélectionnés
$selectedLabels = [];
foreach ($proprietaires as $p) {
    if (in_array((int)$p['id'], $selectedProps)) $selectedLabels[] = $p['label'];
}

// URL query string pour transmettre la sélection aux autres pages
$propsQuery = !empty($selectedProps)
    ? '?' . http_build_query(['props' => $selectedProps])
    : '';

$kpis = ['nb_biens'=>0,'nb_actifs'=>0,'nb_partis'=>0,'loyer'=>0,'impaye_a'=>0,'impaye_p'=>0];
$alerts = [];
$lastCrgs = [];

if (!empty($propIds)) {
    $in = implode(',', array_map('intval', $propIds));

    // ── Sous-requête base commune ────────────────────────
    $subCrg = "(
        SELECT ct2.annee,ct2.trimestre FROM crg_trimestres ct2
        JOIN crg_situations_locataires c2 ON c2.id_crg=ct2.id
        WHERE ct2.id_proprietaire=ct.id_proprietaire AND ct2.parse_statut='ok'
          AND c2.id_bien=crg.id_bien AND c2.locataire_nom=crg.locataire_nom
        ORDER BY ct2.annee DESC,ct2.trimestre DESC LIMIT 1
    )";

    // ── KPIs ─────────────────────────────────────────────
    $r = $pdo->query("
        SELECT
          COUNT(DISTINCT CASE WHEN crg.loyer_appele>0 AND COALESCE(i.vendu,0)=0 AND ls.archive=0 THEN CONCAT(crg.id_bien,'-',crg.locataire_nom) END) AS nb_actifs,
          COUNT(DISTINCT CASE WHEN crg.loyer_appele=0 AND COALESCE(i.vendu,0)=0 AND ls.archive=0 THEN CONCAT(crg.id_bien,'-',crg.locataire_nom) END) AS nb_partis,
          COUNT(DISTINCT CASE WHEN COALESCE(i.vendu,0)=0 THEN crg.id_bien END) AS nb_biens,
          ROUND(SUM(CASE WHEN crg.loyer_appele>0 AND COALESCE(i.vendu,0)=0 AND ls.archive=0 THEN crg.loyer_appele ELSE 0 END)/3,0) AS loyer,
          ROUND(SUM(CASE WHEN crg.loyer_appele>0 AND COALESCE(i.vendu,0)=0 AND ls.archive=0 THEN crg.total_impaye ELSE 0 END),0) AS impaye_a,
          ROUND(SUM(CASE WHEN crg.loyer_appele=0 AND COALESCE(i.vendu,0)=0 AND ls.archive=0 THEN crg.total_impaye ELSE 0 END),0) AS impaye_p
        FROM crg_situations_locataires crg
        JOIN crg_trimestres ct ON crg.id_crg=ct.id
        JOIN locataires_statuts ls ON ls.locataire_nom=crg.locataire_nom AND ls.id_bien=crg.id_bien AND ls.id_proprietaire=ct.id_proprietaire AND ls.statut!='irrecoverable'
        LEFT JOIN biens b ON b.id=crg.id_bien
        LEFT JOIN immeubles i ON i.id=b.id_immeuble
        WHERE ct.id_proprietaire IN ({$in}) AND ct.parse_statut='ok'
          AND (ct.annee,ct.trimestre)={$subCrg}
    ")->fetch(\PDO::FETCH_ASSOC);
    if ($r) $kpis = $r;

    // ── Top alertes impayés > 500€ ───────────────────────
    $alerts = $pdo->query("
        SELECT crg.locataire_nom, crg.total_impaye, crg.loyer_appele,
               CONCAT(ct.annee,' T',ct.trimestre) AS dernier_crg,
               b.reference_bien, i.nom_immeuble, i.adresse_1,
               COALESCE(p.societe, CONCAT(p.prenom,' ',p.nom)) AS prop_nom
        FROM crg_situations_locataires crg
        JOIN crg_trimestres ct ON crg.id_crg=ct.id
        JOIN locataires_statuts ls ON ls.locataire_nom=crg.locataire_nom AND ls.id_bien=crg.id_bien AND ls.id_proprietaire=ct.id_proprietaire AND ls.statut!='irrecoverable' AND ls.archive=0
        LEFT JOIN biens b ON b.id=crg.id_bien
        LEFT JOIN immeubles i ON i.id=b.id_immeuble
        LEFT JOIN proprietaires p ON p.id=ct.id_proprietaire
        WHERE ct.id_proprietaire IN ({$in}) AND ct.parse_statut='ok'
          AND crg.total_impaye>500 AND COALESCE(i.vendu,0)=0
          AND (ct.annee,ct.trimestre)={$subCrg}
        ORDER BY crg.total_impaye DESC LIMIT 15
    ")->fetchAll(\PDO::FETCH_ASSOC);

    // ── Dernier CRG par propriétaire ─────────────────────
    $rows = $pdo->query("
        SELECT ct.id_proprietaire, ct.annee, ct.trimestre,
               CONCAT(ct.annee,' T',ct.trimestre) AS label,
               COALESCE(p.societe, CONCAT(p.prenom,' ',p.nom)) AS prop_nom
        FROM crg_trimestres ct
        JOIN proprietaires p ON p.id=ct.id_proprietaire
        WHERE ct.id_proprietaire IN ({$in}) AND ct.parse_statut='ok'
        ORDER BY ct.annee DESC,ct.trimestre DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);
    $seen = [];
    foreach ($rows as $c) {
        if (!isset($seen[$c['id_proprietaire']])) {
            $seen[$c['id_proprietaire']] = true;
            $lastCrgs[] = $c;
        }
    }
}

$userNom = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));

// ── Layout ───────────────────────────────────────────────
$pageTitle    = 'Tableau de bord Bailleur';
$pageSubtitle = 'Ma Box Bailleur';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_dashboard';

$extraCss = <<<CSS
<style>
.dash-greeting{font-size:1.05em;color:#555;margin-bottom:20px;}
.dash-greeting strong{color:#1a237e;}

.kpi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}
.kpi-card{background:white;border-radius:10px;padding:16px 18px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-left:4px solid #3f51b5;}
.kpi-card.green{border-left-color:#2e7d32;} .kpi-card.red{border-left-color:#c62828;}
.kpi-card.orange{border-left-color:#e65100;} .kpi-card.blue{border-left-color:#1565c0;}
.kpi-card.grey{border-left-color:#9e9e9e;}
.kpi-label{font-size:.75em;color:#888;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;}
.kpi-val{font-size:1.75em;font-weight:700;color:#1a237e;line-height:1;}
.kpi-val.green{color:#2e7d32;} .kpi-val.red{color:#c62828;}
.kpi-val.orange{color:#e65100;} .kpi-val.blue{color:#1565c0;}
.kpi-sub{font-size:.74em;color:#bbb;margin-top:3px;}

.quick-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;}
@media(max-width:900px){.quick-grid{grid-template-columns:repeat(2,1fr);}}
.quick-link{display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:white;border-radius:10px;padding:18px 10px;box-shadow:0 2px 8px rgba(0,0,0,.08);
  text-decoration:none;color:#1a237e;transition:all .15s;border:1px solid #e8eaf6;gap:7px;}
.quick-link:hover{background:#e8eaf6;transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.12);}
.ql-icon{font-size:1.7em;} .ql-label{font-size:.8em;font-weight:600;text-align:center;color:#333;}
.ql-desc{font-size:.72em;color:#888;text-align:center;}

.dash-section{background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:18px;overflow:hidden;}
.dash-section-header{padding:11px 18px;background:#f5f6fa;border-bottom:1px solid #eee;display:flex;align-items:center;gap:10px;}
.dash-section-header h3{margin:0;font-size:.9em;color:#1a237e;flex:1;}

table.alerts{border-collapse:collapse;width:100%;font-size:.83em;}
table.alerts th{background:#1a237e;color:white;padding:7px 12px;text-align:left;white-space:nowrap;}
table.alerts td{padding:7px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
table.alerts tr:last-child td{border-bottom:none;}
table.alerts tr:hover td{background:#fafbff;}
.imp-badge{display:inline-block;padding:2px 8px;border-radius:8px;font-weight:bold;font-size:.84em;}
.imp-high{background:#ffcdd2;color:#b71c1c;} .imp-medium{background:#ffe0b2;color:#bf360c;}
.imp-low{background:#fff9c4;color:#f57f17;}

.crg-pills{display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px;}
.crg-pill{background:#e8eaf6;border-radius:20px;padding:5px 14px;font-size:.81em;color:#1a237e;}
.crg-pill .cp-prop{font-weight:600;} .crg-pill .cp-date{color:#666;}

.prop-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:14px 18px;}
@media(max-width:900px){.prop-cards{grid-template-columns:repeat(2,1fr);}}
.prop-card{border:1px solid #e8eaf6;border-radius:8px;padding:11px 13px;}
.prop-card-name{font-weight:bold;font-size:.85em;color:#1a237e;margin-bottom:5px;}
.prop-card-stats{display:flex;gap:12px;font-size:.79em;flex-wrap:wrap;}
.prop-card-stats span{color:#666;}
.prop-card-stats strong{color:#1a237e;}
.empty-state{text-align:center;padding:36px;color:#aaa;font-size:.88em;}
</style>
CSS;

require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<div style="padding:20px 28px;max-width:1400px;margin:0 auto;">

<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
  <div class="dash-greeting" style="margin:0;">
    Bonjour <strong><?= e($userNom ?: 'Bailleur') ?></strong> 👋
    <?php if ($isSuperAdmin): ?>
      &nbsp;— Vue <strong>super admin</strong>
    <?php else: ?>
      &nbsp;— <strong><?= count($proprietaires) ?></strong> propriétaire(s)
    <?php endif; ?>
  </div>

  <?php if ($isSuperAdmin && count($proprietaires) > 1): ?>
  <form method="GET" id="prop-filter-form" style="margin-left:auto;position:relative;">
    <div style="display:flex;align-items:center;gap:8px;">
      <label style="font-size:.83em;color:#666;white-space:nowrap;">Filtrer :</label>
      <div style="position:relative;">
        <button type="button" onclick="togglePropDropdown()"
                style="border:1px solid #c5cae9;border-radius:8px;padding:6px 14px;font-size:.85em;
                       background:white;color:#1a237e;cursor:pointer;min-width:240px;text-align:left;
                       display:flex;align-items:center;justify-content:space-between;gap:8px;">
          <span id="prop-btn-label">
            <?= empty($selectedProps) ? '— Tous les propriétaires —'
                : (count($selectedProps)===1 ? e($selectedLabels[0])
                : count($selectedProps).' propriétaires sélectionnés') ?>
          </span>
          <span style="font-size:.8em;opacity:.5">▼</span>
        </button>
        <div id="prop-dropdown" style="display:none;position:absolute;top:calc(100% + 4px);right:0;
             background:white;border:1px solid #c5cae9;border-radius:8px;
             box-shadow:0 4px 20px rgba(0,0,0,.12);z-index:100;min-width:260px;padding:8px 0;">
          <label style="display:flex;align-items:center;gap:8px;padding:7px 14px;cursor:pointer;
                         font-size:.84em;border-bottom:1px solid #eee;color:#555;">
            <input type="checkbox" id="check-all" onchange="toggleAll(this)"
                   <?= empty($selectedProps)?'checked':'' ?>>
            <strong>— Tous —</strong>
          </label>
          <?php foreach ($proprietaires as $p): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:6px 14px;cursor:pointer;font-size:.84em;hover:background:#f5f5f5;">
            <input type="checkbox" name="props[]" value="<?= (int)$p['id'] ?>"
                   <?= in_array((int)$p['id'], $selectedProps)?'checked':'' ?>
                   onchange="updateLabel()">
            <?= e($p['label']) ?>
          </label>
          <?php endforeach; ?>
          <div style="padding:8px 14px;border-top:1px solid #eee;display:flex;gap:8px;">
            <button type="submit" style="flex:1;background:#3f51b5;color:white;border:none;
                    border-radius:6px;padding:6px;font-size:.82em;cursor:pointer;">Appliquer</button>
            <a href="?" style="flex:1;text-align:center;background:#f5f5f5;color:#555;border-radius:6px;
                    padding:6px;font-size:.82em;text-decoration:none;line-height:1.8;">Tout voir</a>
          </div>
        </div>
      </div>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php if (!empty($selectedProps)): ?>
<div style="background:#e8eaf6;border-left:4px solid #3f51b5;border-radius:6px;padding:8px 14px;
            margin-bottom:16px;font-size:.85em;color:#1a237e;display:flex;align-items:center;gap:10px;">
  <span>📊 Vue filtrée :
    <?php foreach ($selectedLabels as $i => $lbl): ?>
      <strong><?= e($lbl) ?></strong><?= $i < count($selectedLabels)-1 ? ' · ' : '' ?>
    <?php endforeach; ?>
  </span>
  <a href="?" style="margin-left:auto;color:#666;text-decoration:none;font-size:.9em;">✕ Voir tous</a>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card blue">
    <div class="kpi-label">🏢 Biens en gestion</div>
    <div class="kpi-val blue"><?= (int)($kpis['nb_biens']??0) ?></div>
    <div class="kpi-sub">lots actifs</div>
  </div>
  <div class="kpi-card green">
    <div class="kpi-label">🟢 Locataires présents</div>
    <div class="kpi-val green"><?= (int)($kpis['nb_actifs']??0) ?></div>
    <div class="kpi-sub">loyer appelé &gt; 0</div>
  </div>
  <div class="kpi-card grey">
    <div class="kpi-label">🚪 Partis-débiteurs</div>
    <div class="kpi-val" style="color:#555"><?= (int)($kpis['nb_partis']??0) ?></div>
    <div class="kpi-sub">créances résiduelles</div>
  </div>
  <div class="kpi-card blue">
    <div class="kpi-label">💶 Loyers mensuels</div>
    <div class="kpi-val blue">€<?= number_format((float)($kpis['loyer']??0),0,',',' ') ?></div>
    <div class="kpi-sub">appelés dernier CRG</div>
  </div>
  <div class="kpi-card red">
    <div class="kpi-label">🔴 Impayés actifs</div>
    <div class="kpi-val red">€<?= number_format((float)($kpis['impaye_a']??0),0,',',' ') ?></div>
    <div class="kpi-sub">locataires présents</div>
  </div>
  <div class="kpi-card orange">
    <div class="kpi-label">⚠️ Créances partis</div>
    <div class="kpi-val orange">€<?= number_format((float)($kpis['impaye_p']??0),0,',',' ') ?></div>
    <div class="kpi-sub">ex-locataires</div>
  </div>
</div>

<!-- Accès rapides -->
<div class="quick-grid">
  <a class="quick-link" href="bailleur_patrimoine_actif.php<?= $propsQuery ?>">
    <span class="ql-icon">🏛️</span>
    <span class="ql-label">Patrimoine actif</span>
    <span class="ql-desc">Immeubles · Locataires · Impayés</span>
  </a>
  <a class="quick-link" href="bailleur_ged.php">
    <span class="ql-icon">📁</span>
    <span class="ql-label">GED Documents</span>
    <span class="ql-desc">CRGs · Baux · Quittances</span>
  </a>
  <a class="quick-link" href="bailleur_revision_loyer.php">
    <span class="ql-icon">📈</span>
    <span class="ql-label">Révision des loyers</span>
    <span class="ql-desc">IRL · Calcul · Courrier</span>
  </a>
  <a class="quick-link" href="bail_360.php">
    <span class="ql-icon">📋</span>
    <span class="ql-label">Bail 360°</span>
    <span class="ql-desc">Fiche complète d'un bail</span>
  </a>
  <?php if ($isSuperAdmin): ?>
  <a class="quick-link" href="bailleur_patrimoine_actif.php">
    <span class="ql-icon">✅</span>
    <span class="ql-label">Validation imports</span>
    <span class="ql-desc">Contrôle qualité CRG</span>
  </a>
  <a class="quick-link" href="admin_bailleurs.php">
    <span class="ql-icon">👥</span>
    <span class="ql-label">Admin bailleurs</span>
    <span class="ql-desc">Comptes · Accès · Rôles</span>
  </a>
  <?php endif; ?>
</div>

<!-- Alertes impayés -->
<div class="dash-section">
  <div class="dash-section-header">
    <h3>🚨 Top impayés — locataires à surveiller</h3>
    <a href="bailleur_patrimoine_actif.php<?= $propsQuery ?>" style="font-size:.8em;color:#3f51b5;text-decoration:none;">Voir tout →</a>
  </div>
  <?php if (empty($alerts)): ?>
    <div class="empty-state">✅ Aucun impayé significatif détecté (seuil 500 €)</div>
  <?php else: ?>
  <table class="alerts">
    <thead><tr>
      <th>LOCATAIRE</th><th>IMMEUBLE / BIEN</th>
      <?php if (count($proprietaires)>1): ?><th>PROPRIÉTAIRE</th><?php endif; ?>
      <th>STATUT</th><th style="text-align:right">IMPAYÉ</th><th>CRG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($alerts as $a):
        $imp=(float)$a['total_impaye'];
        $cls=$imp>10000?'imp-high':($imp>3000?'imp-medium':'imp-low');
        $actif=(float)$a['loyer_appele']>0;
    ?>
      <tr>
        <td><strong><?=e($a['locataire_nom'])?></strong></td>
        <td><?=e($a['nom_immeuble']??'—')?><br>
          <code style="font-size:.78em;color:#777"><?=e($a['reference_bien']??'')?></code></td>
        <?php if(count($proprietaires)>1): ?><td style="color:#666;font-size:.82em"><?=e($a['prop_nom'])?></td><?php endif; ?>
        <td><?=$actif?'<span style="color:#2e7d32;font-weight:bold">🟢 Présent</span>':'<span style="color:#880e4f">🔴 Parti</span>'?></td>
        <td style="text-align:right"><span class="imp-badge <?=$cls?>">€<?=number_format($imp,0,',',' ')?></span></td>
        <td style="color:#888;font-size:.82em"><?=e($a['dernier_crg'])?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Derniers CRGs -->
<div class="dash-section">
  <div class="dash-section-header">
    <h3>📊 Dernier CRG disponible par propriétaire</h3>
  </div>
  <?php if(empty($lastCrgs)): ?>
    <div class="empty-state">Aucun CRG importé</div>
  <?php else: ?>
  <div class="crg-pills">
    <?php foreach($lastCrgs as $c): ?>
    <div class="crg-pill">
      <span class="cp-prop"><?=e($c['prop_nom'])?></span>
      <span class="cp-date">→ <?=e($c['label'])?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Récap par propriétaire -->
<?php if(count($proprietaires)>1): ?>
<div class="dash-section">
  <div class="dash-section-header">
    <h3>👤 Vos propriétaires</h3>
  </div>
  <div class="prop-cards">
    <?php foreach($proprietaires as $prop):
        $pid=(int)$prop['id'];
        $sp=$pdo->prepare("
            SELECT
              COUNT(DISTINCT CASE WHEN crg.loyer_appele>0 THEN CONCAT(crg.id_bien,'-',crg.locataire_nom) END) AS nb_a,
              COUNT(DISTINCT CASE WHEN crg.loyer_appele=0 THEN CONCAT(crg.id_bien,'-',crg.locataire_nom) END) AS nb_p,
              ROUND(SUM(CASE WHEN crg.loyer_appele>0 THEN crg.total_impaye ELSE 0 END),0) AS imp_a
            FROM crg_situations_locataires crg
            JOIN crg_trimestres ct ON crg.id_crg=ct.id
            JOIN locataires_statuts ls ON ls.locataire_nom=crg.locataire_nom AND ls.id_bien=crg.id_bien AND ls.id_proprietaire=ct.id_proprietaire AND ls.statut!='irrecoverable' AND ls.archive=0
            LEFT JOIN biens b ON b.id=crg.id_bien LEFT JOIN immeubles i ON i.id=b.id_immeuble
            WHERE ct.id_proprietaire=? AND ct.parse_statut='ok' AND COALESCE(i.vendu,0)=0
              AND (ct.annee,ct.trimestre)=(SELECT ct2.annee,ct2.trimestre FROM crg_trimestres ct2
                JOIN crg_situations_locataires c2 ON c2.id_crg=ct2.id
                WHERE ct2.id_proprietaire=ct.id_proprietaire AND ct2.parse_statut='ok'
                  AND c2.id_bien=crg.id_bien AND c2.locataire_nom=crg.locataire_nom
                ORDER BY ct2.annee DESC,ct2.trimestre DESC LIMIT 1)
        ");
        $sp->execute([$pid]);
        $s=$sp->fetch(\PDO::FETCH_ASSOC);
    ?>
    <div class="prop-card">
      <div class="prop-card-name"><?=e($prop['label'])?></div>
      <div class="prop-card-stats">
        <span>🟢 <strong><?=(int)($s['nb_a']??0)?></strong> actifs</span>
        <span>🚪 <strong><?=(int)($s['nb_p']??0)?></strong> partis</span>
        <?php if((float)($s['imp_a']??0)>0): ?>
          <span style="color:#c62828">⚠️ <strong>€<?=number_format((float)$s['imp_a'],0,',',' ')?></strong></span>
        <?php else: ?>
          <span style="color:#2e7d32">✅ OK</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</div>

<script>
function togglePropDropdown() {
    const d = document.getElementById('prop-dropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('#prop-filter-form')) {
        const d = document.getElementById('prop-dropdown');
        if (d) d.style.display = 'none';
    }
});
function toggleAll(cb) {
    document.querySelectorAll('#prop-dropdown input[name="props[]"]')
        .forEach(c => c.checked = false);
    updateLabel();
}
function updateLabel() {
    document.getElementById('check-all').checked = false;
    const checked = [...document.querySelectorAll('#prop-dropdown input[name="props[]"]:checked')];
    const btn = document.getElementById('prop-btn-label');
    if (checked.length === 0) {
        btn.textContent = '— Tous les propriétaires —';
        document.getElementById('check-all').checked = true;
    } else if (checked.length === 1) {
        btn.textContent = checked[0].closest('label').textContent.trim();
    } else {
        btn.textContent = checked.length + ' propriétaires sélectionnés';
    }
}
</script>
<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
