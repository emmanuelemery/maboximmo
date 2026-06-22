<?php
/**
 * creancier_liste.php — Liste des dossiers CRÉANCIERS (chrome standard MaBoxImmo).
 *
 * Reprend le graphisme des pages de liste (Propriétaires/Biens) : header app +
 * sidebar_agency + liste_layout.css + topbar + page-head (KPIs) + barre de recherche
 * + cartes entity_card. N'affiche QUE les dossiers autorisés (ACL + tenant).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/entity_card.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_login();

if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$isSuper = function_exists('is_super_admin') && is_super_admin();
$roleId  = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr   = $isSuper || in_array($roleId, [1, 2, 3, 7], true);
$base    = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '';

$stU = $pdo->prepare("SELECT id_societe FROM users WHERE id = ? LIMIT 1");
$stU->execute([$userId]);
$idSociete = (int)($stU->fetchColumn() ?: 0);

if ($isSuper) {
    $dossiers = $pdo->query("SELECT * FROM creancier_dossier ORDER BY FIELD(niveau_risque,'rouge','orange','vert'), libelle")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare("SELECT d.* FROM creancier_dossier d
        JOIN creancier_dossier_acces a ON a.id_dossier = d.id AND a.id_user = :uid
        WHERE (d.id_societe IS NULL OR d.id_societe = :soc)
        ORDER BY FIELD(d.niveau_risque,'rouge','orange','vert'), d.libelle");
    $st->execute([':uid' => $userId, ':soc' => $idSociete]);
    $dossiers = $st->fetchAll(PDO::FETCH_ASSOC);
}

$rows = []; $kNet = 0.0; $kReste = 0.0; $kUrg = 0;
foreach ($dossiers as $d) {
    $u = creancier_urgence_data($pdo, (int)$d['id'], $userId);
    if (!$u['acces']) continue;
    $kNet += $u['total_net_bloque']; $kReste += $u['montant_du'];
    if ($u['butoirs_en_retard']) $kUrg++;
    $rows[] = ['d' => $d, 'u' => $u];
}

$riskColor = ['vert' => '#16a34a', 'orange' => '#ea580c', 'rouge' => '#dc2626'];
$statutLbl = ['actif' => 'Actif', 'surveillance' => 'Surveillance', 'clos' => 'Clos'];

$appLayout = true;
$pageTitle = 'Créanciers';
$bodyClass = '';
$robots    = 'noindex, nofollow';
include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  .mbi-main{ background:linear-gradient(135deg, rgba(220,38,38,0.10) 0%, rgba(255,255,255,0) 38%, rgba(72,120,166,0.12) 65%, rgba(255,255,255,0) 100%), #fafbfc; background-attachment:fixed; }
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:var(--card,#fff); border-radius:14px; padding:10px 16px; min-width:110px; box-shadow:var(--neu-out,4px 4px 10px #d4d7de,-4px -4px 10px #fff); text-align:center; }
  .pk-kpi-val { font-size:22px; font-weight:800; color:#243B5C; line-height:1.1; }
  .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; margin-top:3px; }
  .pk-bar { position:sticky; top:0; z-index:50; display:grid; grid-template-columns:1fr auto; align-items:center; gap:16px; padding:10px 2px; margin-bottom:14px; background:rgba(250,251,252,.92); backdrop-filter:blur(6px); }
  .pk-bar-search { width:100%; max-width:320px; display:flex; align-items:center; gap:8px; background:var(--card,#fff); border-radius:999px; padding:8px 16px; box-sizing:border-box; box-shadow:var(--neu-out,3px 3px 8px #d4d7de,-3px -3px 8px #fff); }
  .pk-bar-search input { border:none !important; outline:none !important; box-shadow:none !important; background:transparent !important; width:100%; font-family:'Sora',sans-serif; font-size:15px; font-weight:700; color:#243B5C; }
  .pk-bar-search input::placeholder { font-weight:600; color:#9a9690; }
</style>

<div class="mbi-main">

  <!-- TOPBAR -->
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span class="active" style="font-size:1.7rem;font-weight:800;">Créanciers</span></nav>
    <div class="topbar-spacer"></div>
    <?php if ($isMgr): ?>
    <div style="display:flex;gap:8px;align-items:center;margin-right:10px;">
      <button type="button" onclick="fbxOpenUploadModal({origin:'creancier'})" class="bl-btn" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">📄 Scanner un document</button>
      <a href="<?= e($base) ?>creancier_dossier_form.php" class="bl-btn" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">➕ Nouveau dossier</a>
    </div>
    <?php endif; ?>
    <button type="button" class="topbar-icon-btn" title="Notifications"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></button>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <!-- PAGE HEAD : KPIs -->
  <div class="page-head" style="flex-wrap:wrap;gap:16px;align-items:center;">
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= count($rows) ?></div><div class="pk-kpi-lbl">Dossiers</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#dc2626;"><?= $eur($kReste) ?></div><div class="pk-kpi-lbl">Reste dû</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val"><?= $eur($kNet) ?></div><div class="pk-kpi-lbl">Net bloqué</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:<?= $kUrg ? '#dc2626' : '#15803d' ?>;"><?= $kUrg ?></div><div class="pk-kpi-lbl">Urgences</div></div>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="bl-content">
    <?php if (!$rows): ?>
      <div class="bl-empty">
        <div class="bl-empty-icon">⚖️</div>
        <h2>Aucun dossier créancier accessible</h2>
        <p>Les dossiers sont confidentiels : ils n'apparaissent que pour les utilisateurs habilités.</p>
        <?php if ($isMgr): ?><a href="<?= e($base) ?>creancier_dossier_form.php" class="bl-btn bl-btn-primary" style="text-decoration:none;">➕ Nouveau dossier</a><?php endif; ?>
      </div>
    <?php else: ?>
      <?php entity_card_assets(); ?>
      <div class="pk-bar">
        <div class="pk-bar-search"><span class="search-icon">🔍</span><input type="text" id="creSearch" placeholder="Rechercher un dossier…" oninput="creFilter()" autocomplete="off"></div>
      </div>
      <div class="ec-grid" id="creGrid">
        <?php foreach ($rows as $r): $d = $r['d']; $u = $r['u']; $rc = $riskColor[$d['niveau_risque']] ?? '#ea580c';
          $chips = [];
          $chips[] = '💰 Dû <b style="color:#dc2626;margin-left:3px;">' . $eur($u['montant_du']) . '</b>';
          if ($u['total_net_bloque'] > 0) $chips[] = '🔒 ' . $eur($u['total_net_bloque']);
          if ($u['butoirs_en_retard']) $chips[] = '<span style="color:#dc2626;font-weight:700;">⏰ ' . count($u['butoirs_en_retard']) . ' retard(s)</span>';
          elseif ($u['prochaine_butoir']) $chips[] = '📅 ' . $dfr($u['prochaine_butoir']);
        ?>
        <?php entity_card([
          'url'    => $base . 'creancier_dossier360.php?id_dossier=' . (int)$d['id'],
          'accent' => $rc,
          'ref'    => (string)$d['code'],
          'title'  => (string)$d['libelle'],
          'badge'  => '<span class="ap-badge" style="background:' . $rc . '22;color:' . $rc . ';">' . e($statutLbl[$d['statut']] ?? $d['statut']) . '</span>',
          'chips'  => $chips,
          'data'   => ['name' => mb_strtolower((string)$d['libelle'] . ' ' . $d['code'])],
        ]); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function creFilter(){
  var q = (document.getElementById('creSearch').value || '').toLowerCase().trim();
  document.querySelectorAll('#creGrid .ec-card').forEach(function(c){
    c.style.display = (!q || (c.dataset.name||'').indexOf(q) !== -1) ? '' : 'none';
  });
}
</script>
<?php if ($isMgr) require __DIR__ . '/inc/fluxbox_upload_modal.php'; ?>
<?php include __DIR__ . '/inc/footer.php'; ?>
