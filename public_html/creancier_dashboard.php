<?php
/**
 * creancier_dashboard.php — DASHBOARD du module Créanciers (lanceur + KPIs + agenda).
 *
 * Pas de détail : pilotage global. KPIs consolidés, boutons d'action (Scanner / Nouveau),
 * agenda des prochaines échéances tous dossiers, urgences en tête, accès à la liste.
 * Chrome standard MaBoxImmo (header app + sidebar_agency + liste_layout.css).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
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

$dossiers = creancier_accessible_dossiers($pdo, $userId);
$ids = array_map(fn($d) => (int)$d['id'], $dossiers);

$kNet = 0.0; $kReste = 0.0; $kUrg = 0; $urgences = [];
foreach ($dossiers as $d) {
    $u = creancier_urgence_data($pdo, (int)$d['id'], $userId);
    if (!$u['acces']) continue;
    $kNet += $u['total_net_bloque']; $kReste += $u['reste_du'];
    if ($u['butoirs_en_retard']) { $kUrg++; $urgences[] = ['d' => $d, 'u' => $u]; }
}
$agenda = creancier_agenda($pdo, $ids, 120);
$libById = []; foreach ($dossiers as $d) $libById[(int)$d['id']] = $d['libelle'];

$appLayout = true; $pageTitle = 'Créanciers'; $bodyClass = ''; $robots = 'noindex, nofollow';
include __DIR__ . '/inc/header.php';
$sidebarType = 'agency';
include __DIR__ . '/inc/sidebar_agency.php';
?>
<link rel="stylesheet" href="<?= asset_url('/css/liste_layout.css') ?>">
<style>
  .mbi-main{ background:linear-gradient(135deg, rgba(220,38,38,0.10) 0%, rgba(255,255,255,0) 38%, rgba(72,120,166,0.12) 65%, rgba(255,255,255,0) 100%), #fafbfc; background-attachment:fixed; }
  .pk-kpis { display:flex; gap:10px; flex-wrap:wrap; }
  .pk-kpi { background:#fff; border-radius:14px; padding:10px 16px; min-width:120px; box-shadow:4px 4px 10px #d4d7de,-4px -4px 10px #fff; text-align:center; }
  .pk-kpi-val { font-size:24px; font-weight:800; color:#243B5C; line-height:1.1; }
  .pk-kpi-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#8a8680; margin-top:3px; }
  .cd-cols { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:4px; }
  @media (max-width:900px){ .cd-cols{ grid-template-columns:1fr; } }
  .cd-card { background:#fff; border:1px solid #e6e1d8; border-radius:14px; padding:16px 18px; box-shadow:4px 4px 10px #e3e6ec,-4px -4px 10px #fff; }
  .cd-card h3 { margin:0 0 12px; font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:#8a8680; font-family:'Sora',sans-serif; }
  .cd-row { display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px dashed #efeae1; font-size:13px; text-decoration:none; color:inherit; }
  .cd-row:last-child { border-bottom:none; }
  .cd-row:hover { background:#fafbfc; }
  .cd-date { font-family:'DM Mono',monospace; font-weight:700; color:#4878a6; }
  .cd-date.retard { color:#dc2626; }
  .cd-pill { font-size:10px; padding:2px 8px; border-radius:999px; background:#eef1f6; color:#4878a6; font-weight:600; }
  .cd-empty { color:#aab; font-style:italic; font-size:13px; }
</style>

<div class="mbi-main">
  <div class="bl-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb"><span class="active" style="font-size:1.7rem;font-weight:800;">Créanciers</span></nav>
    <div class="topbar-spacer"></div>
    <?php if ($isMgr): ?>
    <div style="display:flex;gap:8px;align-items:center;margin-right:10px;">
      <button type="button" onclick="fbxOpenUploadModal({origin:'creancier'})" class="bl-btn" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:700;cursor:pointer;">📄 Scanner un document</button>
      <a href="<?= e($base) ?>creancier_dossier_form.php" class="bl-btn" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-weight:700;text-decoration:none;">➕ Nouveau dossier</a>
      <a href="<?= e($base) ?>creancier_liste.php" class="bl-btn" style="background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;font-weight:700;text-decoration:none;">📂 Tous les dossiers</a>
    </div>
    <?php endif; ?>
    <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['username'] ?? 'U'), 0, 1)) ?></div>
  </div>

  <div class="page-head" style="flex-wrap:wrap;gap:16px;align-items:center;">
    <div class="pk-kpis">
      <div class="pk-kpi"><div class="pk-kpi-val"><?= count($dossiers) ?></div><div class="pk-kpi-lbl">Dossiers</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:#dc2626;"><?= $eur($kReste) ?></div><div class="pk-kpi-lbl">Reste dû</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val"><?= $eur($kNet) ?></div><div class="pk-kpi-lbl">Net bloqué</div></div>
      <div class="pk-kpi"><div class="pk-kpi-val" style="color:<?= $kUrg ? '#dc2626' : '#15803d' ?>;"><?= $kUrg ?></div><div class="pk-kpi-lbl">Urgences</div></div>
    </div>
  </div>

  <div class="bl-content">
    <div class="cd-cols">

      <!-- Agenda global -->
      <div class="cd-card">
        <h3>📅 Agenda — prochaines échéances</h3>
        <?php if (!$agenda): ?><div class="cd-empty">Aucune échéance à venir.</div><?php endif; ?>
        <?php foreach (array_slice($agenda, 0, 14) as $ev): ?>
          <a class="cd-row" href="<?= e($base) ?>creancier_dossier360.php?id_dossier=<?= (int)$ev['id_dossier'] ?>">
            <span><span class="cd-pill"><?= e($ev['type']) ?></span> <?= e($ev['libelle']) ?>
              <span style="color:#9aa0a8"> · <?= e($libById[$ev['id_dossier']] ?? ('#'.$ev['id_dossier'])) ?></span></span>
            <span class="cd-date <?= $ev['retard'] ? 'retard' : '' ?>"><?= $dfr($ev['date']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Urgences (retards) -->
      <div class="cd-card">
        <h3 style="color:#dc2626">⚠️ Dossiers en urgence</h3>
        <?php if (!$urgences): ?><div class="cd-empty">Aucun retard. 👍</div><?php endif; ?>
        <?php foreach ($urgences as $r): $d = $r['d']; $u = $r['u']; ?>
          <a class="cd-row" href="<?= e($base) ?>creancier_dossier360.php?id_dossier=<?= (int)$d['id'] ?>">
            <span><b><?= e($d['libelle']) ?></b> <span class="cd-pill"><?= e($d['code']) ?></span></span>
            <span style="color:#dc2626;font-weight:700"><?= count($u['butoirs_en_retard']) ?> retard(s) · <?= $eur($u['reste_du']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</div>
<?php if ($isMgr) require __DIR__ . '/inc/fluxbox_upload_modal.php'; ?>
<?php include __DIR__ . '/inc/footer.php'; ?>
