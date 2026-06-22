<?php
/**
 * bailleur_dashboard_v2.php — Dashboard Bailleur V2 (WIP)
 * Refonte : vitrine des modules (cartes riches + droits user_bailleur_modules).
 * Base identique à bailleur_dashboard.php — ne PAS remplacer l'existante tant que non validée.
 * Accès : super admin + rôles avec service bailleur.
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
        SELECT p.id,
               COALESCE(NULLIF(TRIM(p.societe),''),
                        NULLIF(TRIM(CONCAT_WS(' ',
                            NULLIF(TRIM(p.civilite),''),
                            NULLIF(TRIM(p.prenom),''),
                            NULLIF(TRIM(p.nom),''))),''),
                        CONCAT('Propriétaire #', p.id)) AS label,
               p.societe, p.nom, p.prenom
        FROM proprietaires p
        WHERE EXISTS (
            SELECT 1 FROM crg_trimestres ct
            JOIN crg_situations_locataires c ON c.id_crg = ct.id
            WHERE ct.id_proprietaire = p.id
        )
        ORDER BY label
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
        foreach ((array)$_GET['props'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0 && in_array($sid, $allPropIds)) $selectedProps[] = $sid;
        }
        $_SESSION['bailleur_props'] = $selectedProps;
    } elseif (!empty($_SESSION['bailleur_props'])) {
        foreach ($_SESSION['bailleur_props'] as $sid) {
            $sid = (int)$sid;
            if (in_array($sid, $allPropIds)) $selectedProps[] = $sid;
        }
    }
}

$propIds = !empty($selectedProps) ? $selectedProps : $allPropIds;

$selectedLabels = [];
foreach ($proprietaires as $p) {
    if (in_array((int)$p['id'], $selectedProps)) $selectedLabels[] = $p['label'];
}

$propsQuery = !empty($selectedProps)
    ? '?' . http_build_query(['props' => $selectedProps])
    : '';

// ── Droits modules de l'utilisateur ──────────────────────
// Super admin = tous les modules actifs. Sinon lecture user_bailleur_modules.
$userModules = [];
if ($isSuperAdmin) {
    $userModules = ['patrimoine','ged','revision','bail','diffusion','edl','transaction'];
} else {
    try {
        $stM = $pdo->prepare("SELECT module_code FROM user_bailleur_modules WHERE id_user=?");
        $stM->execute([$userId]);
        $userModules = $stM->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $ex) {
        // table absente en local tant que migration non jouée : on n'affiche aucun droit
        $userModules = [];
    }
}

$kpis = ['nb_biens'=>0,'nb_actifs'=>0,'nb_partis'=>0,'loyer'=>0,'impaye_a'=>0,'impaye_p'=>0];
$alerts = [];
$lastCrgs = [];

if (!empty($propIds)) {
    $in = implode(',', array_map('intval', $propIds));

    $subCrg = "(
        SELECT ct2.annee,ct2.trimestre FROM crg_trimestres ct2
        JOIN crg_situations_locataires c2 ON c2.id_crg=ct2.id
        WHERE ct2.id_proprietaire=ct.id_proprietaire AND ct2.parse_statut='ok'
          AND c2.id_bien=crg.id_bien AND c2.locataire_nom=crg.locataire_nom
        ORDER BY ct2.annee DESC,ct2.trimestre DESC LIMIT 1
    )";

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

// ── Catalogue des modules (vitrine) ──────────────────────
// badge : inclus | premium | option | usage  ·  price : libellé tarif (usage)
$MODULES_CATALOG = [
    'patrimoine' => [
        'icon'  => '🏛️', 'label' => 'Patrimoine actif', 'badge' => 'premium',
        'href'  => 'bailleur_patrimoine_actif.php' . $propsQuery,
        'desc'  => "Visualisez tous vos immeubles, lots et locataires en un coup d'œil. Suivez en temps réel loyers appelés, impayés et taux d'occupation de votre patrimoine.",
    ],
    'ged' => [
        'icon'  => '📁', 'label' => 'GED Documents', 'badge' => 'inclus',
        'href'  => 'bailleur_ged.php',
        'desc'  => "Tous vos documents classés automatiquement : comptes-rendus de gestion, baux, quittances et courriers, téléchargeables à tout moment.",
    ],
    'revision' => [
        'icon'  => '📈', 'label' => 'Révision des loyers', 'badge' => 'inclus',
        'href'  => 'bailleur_revision_loyer.php',
        'desc'  => "Calculez la révision annuelle selon l'indice IRL et générez en un clic le courrier à adresser à votre locataire.",
    ],
    'diffusion' => [
        'icon'  => '📣', 'label' => 'Diffusion annonce', 'badge' => 'inclus',
        'href'  => 'annonce_liste.php',
        'desc'  => "Publiez vos biens à louer ou à vendre directement sur le site Ma Box Immo en quelques clics.",
    ],
    'bail' => [
        'icon'  => '📋', 'label' => 'Bail 360°', 'badge' => 'option',
        'href'  => 'bail_360.php',
        'desc'  => "La fiche complète de chaque bail : locataire, loyer, charges, dépôt de garantie, échéances et historique réunis sur une seule page.",
    ],
    'edl' => [
        'icon'  => '📐', 'label' => 'État des lieux (EDL)', 'badge' => 'usage', 'price' => '30 € / EDL',
        'href'  => '#',
        'desc'  => "Réalisez vos états des lieux d'entrée et de sortie, guidés et horodatés. Facturé 30 € par état des lieux réalisé.",
    ],
    'transaction' => [
        'icon'  => '🏷️', 'label' => 'Transaction', 'badge' => 'usage', 'price' => '10 € / dossier',
        'href'  => 'transaction_index.php?type=vente&scope=bailleur',
        'desc'  => "Pilotez la vente de vos biens de A à Z : dossier, acquéreurs, notaire et suivi jusqu'à la signature. Facturé 10 € par dossier.",
    ],
];

// ── Layout ───────────────────────────────────────────────
$pageTitle    = 'Tableau de bord Bailleur';
$pageSubtitle = 'Ma Box Bailleur · V2';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_dashboard';

$extraCss = <<<CSS
<style>
.dash-greeting{font-size:1.05em;color:#555;margin-bottom:20px;}
.dash-greeting strong{color:#1a237e;}

.kpi-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:18px;}
@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:600px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}
.kpi-card{background:white;border-radius:8px;padding:10px 12px;box-shadow:0 1px 5px rgba(0,0,0,.07);border-left:3px solid #3f51b5;}
.kpi-card.green{border-left-color:#2e7d32;} .kpi-card.red{border-left-color:#c62828;}
.kpi-card.orange{border-left-color:#e65100;} .kpi-card.blue{border-left-color:#1565c0;}
.kpi-card.grey{border-left-color:#9e9e9e;}
.kpi-label{font-size:.66em;color:#888;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;line-height:1.2;}
.kpi-val{font-size:1.35em;font-weight:700;color:#1a237e;line-height:1;}
.kpi-val.green{color:#2e7d32;} .kpi-val.red{color:#c62828;}
.kpi-val.orange{color:#e65100;} .kpi-val.blue{color:#1565c0;}
.kpi-sub{font-size:.66em;color:#bbb;margin-top:2px;}

/* ── VITRINE MODULES ── */
.modules-title{font-size:1em;color:#1a237e;font-weight:700;margin:6px 0 14px;display:flex;align-items:center;gap:8px;}
.modules-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:26px;}
@media(max-width:1000px){.modules-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:640px){.modules-grid{grid-template-columns:1fr;}}
.mod-card{position:relative;background:white;border-radius:12px;padding:18px 18px 16px;
  box-shadow:0 2px 10px rgba(0,0,0,.07);border:1px solid #e8eaf6;text-decoration:none;color:inherit;
  display:flex;flex-direction:column;gap:9px;transition:all .15s;}
.mod-card.active:hover{transform:translateY(-3px);box-shadow:0 8px 22px rgba(26,35,126,.15);border-color:#c5cae9;}
.mod-card.locked{opacity:.62;background:#fafafa;cursor:default;}
.mod-head{display:flex;align-items:center;gap:10px;}
.mod-icon{font-size:1.7em;line-height:1;}
.mod-name{font-size:1em;font-weight:700;color:#1a237e;}
.mod-desc{font-size:.83em;color:#555;line-height:1.45;flex:1;}
.mod-foot{display:flex;align-items:center;justify-content:space-between;margin-top:4px;}
.mod-cta{font-size:.82em;font-weight:600;color:#3f51b5;}
.mod-card.locked .mod-cta{color:#9e9e9e;}
.badge{font-size:.68em;font-weight:700;padding:2px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.03em;}
.badge-inclus{background:#e8f5e9;color:#2e7d32;}
.badge-premium{background:#ede7f6;color:#5e35b1;}
.badge-option{background:#e3f2fd;color:#1565c0;}
.badge-usage{background:#fff3e0;color:#e65100;}
.lock-tag{position:absolute;top:14px;right:14px;font-size:.7em;color:#9e9e9e;background:#eee;
  padding:2px 8px;border-radius:20px;font-weight:600;}

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
</div>

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
    <div class="kpi-val blue"><?= fmt_euro((float)($kpis['loyer']??0)) ?></div>
    <div class="kpi-sub">appelés dernier CRG</div>
  </div>
  <div class="kpi-card red">
    <div class="kpi-label">🔴 Impayés actifs</div>
    <div class="kpi-val red"><?= fmt_euro((float)($kpis['impaye_a']??0)) ?></div>
    <div class="kpi-sub">locataires présents</div>
  </div>
  <div class="kpi-card orange">
    <div class="kpi-label">⚠️ Créances partis</div>
    <div class="kpi-val orange"><?= fmt_euro((float)($kpis['impaye_p']??0)) ?></div>
    <div class="kpi-sub">ex-locataires</div>
  </div>
</div>

<!-- ══ VITRINE MODULES ═══════════════════════════════════ -->
<div class="modules-title">🧩 Vos modules Ma Box Bailleur</div>
<div class="modules-grid">
  <?php foreach ($MODULES_CATALOG as $code => $m):
      $active = in_array($code, $userModules, true);
      $tag = $active ? 'a' : 'div';
      $badgeClass = 'badge-' . $m['badge'];
      $badgeLabel = $m['badge'] === 'usage' ? ($m['price'] ?? 'À l\'usage')
                  : ($m['badge'] === 'inclus' ? 'Inclus'
                  : ($m['badge'] === 'premium' ? 'Premium' : 'Option'));
  ?>
  <<?= $tag ?> class="mod-card <?= $active ? 'active' : 'locked' ?>"
     <?= $active && $m['href'] !== '#' ? 'href="'.e($m['href']).'"' : '' ?>>
    <?php if (!$active): ?><span class="lock-tag">🔒 Non activé</span><?php endif; ?>
    <div class="mod-head">
      <span class="mod-icon"><?= $m['icon'] ?></span>
      <span class="mod-name"><?= e($m['label']) ?></span>
    </div>
    <div class="mod-desc"><?= e($m['desc']) ?></div>
    <div class="mod-foot">
      <span class="badge <?= $badgeClass ?>"><?= e($badgeLabel) ?></span>
      <span class="mod-cta">
        <?= $active ? ($m['href'] === '#' ? 'Bientôt disponible' : 'Ouvrir →') : 'Activer cette option' ?>
      </span>
    </div>
  </<?= $tag ?>>
  <?php endforeach; ?>
</div>

<!-- Assistant Claude -->
<div class="dash-section" id="assistant-box">
  <div class="dash-section-header"><h3>🤖 Assistant — posez votre question</h3></div>
  <div style="padding:14px 18px;">
    <form id="ask-form" style="display:flex;gap:8px;flex-wrap:wrap">
      <input id="ask-q" type="text" placeholder="Ex : qui a des impayés ? où réviser un loyer ? infos sur le locataire DUPONT ?"
             style="flex:1;min-width:260px;padding:10px 12px;border:1px solid #d8d3cb;border-radius:8px;font-size:14px" autocomplete="off">
      <button type="submit" style="padding:10px 18px;border:0;border-radius:8px;background:#3f51b5;color:#fff;font-weight:600;cursor:pointer">Demander</button>
    </form>
    <div id="ask-ans" style="margin-top:12px;font-size:14px;line-height:1.5;color:#333;white-space:pre-wrap;"></div>
  </div>
</div>
<script>
(function(){
  const f=document.getElementById('ask-form'), q=document.getElementById('ask-q'), a=document.getElementById('ask-ans');
  function mdLinks(t){ return t
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2" style="color:#3f51b5;font-weight:600">$1</a>')
    .replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>'); }
  f.addEventListener('submit',async e=>{
    e.preventDefault(); const question=q.value.trim(); if(!question)return;
    a.innerHTML='⏳ …';
    try{
      const r=await fetch('api/bailleur_assistant.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question})});
      const d=await r.json();
      a.innerHTML = d.error ? ('⚠️ '+d.error) : mdLinks(d.answer||'(pas de réponse)');
    }catch(err){ a.innerHTML='⚠️ Erreur réseau'; }
  });
})();
</script>

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
        <td style="text-align:right"><span class="imp-badge <?=$cls?>"><?=fmt_euro($imp) ?></span></td>
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

</div>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
