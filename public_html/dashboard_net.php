<?php
declare(strict_types=1);

/**
 * dashboard_net.php — Dashboard MaBoxNet (User).
 * Module en cours : fournit un point d'entrée standard + liens existants.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$prenom     = (string)($_SESSION['prenom'] ?? $_SESSION['user_prenom'] ?? '');
$societeNom = (string)($_SESSION['societe_nom'] ?? '');

$layout_title          = 'Dashboard Net';
$layout_module         = 'Ma Box Net';
$layout_sidebar        = 'sidebar_net';
$layout_hide_page_head = true;

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$items = [
    ['label' => 'Dashboard Net (legacy)', 'desc' => 'Vue de démo existante (standalone).', 'url' => 'net_dashboard.php', 'icon' => '🌐', 'color' => '#36577d'],
];
if (function_exists('is_admin_or_super_admin') && is_admin_or_super_admin()) {
    $items[] = ['label' => 'Flux XML Ubiflow', 'desc' => 'Exports bruts XML par agence.', 'url' => 'admin/admin_flux_ubiflow.php', 'icon' => '📡', 'color' => '#2d5f6b'];
}

$layout_extra_css = '<style>
.nd-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }
.nd-welcome { text-align:center; margin-bottom: 24px; padding: 0 20px; }
.nd-welcome-hand { font-size: 48px; line-height: 1; display:inline-block; margin-bottom: 10px; }
.nd-welcome-title { font-family:"Sora",sans-serif; font-size: 30px; font-weight: 800; color: #36577d; letter-spacing:-0.02em; margin-bottom: 10px; }
.nd-welcome-sub { font-size: 15px; color:#6a6864; max-width: 680px; margin: 0 auto; line-height: 1.55; }
.nd-discover-label {
  font-family:"DM Mono",monospace; font-size:10px; font-weight:600; color:#9a9690;
  letter-spacing:0.16em; text-transform:uppercase; margin-bottom:16px; padding:0 4px;
  display:flex; align-items:center; gap:10px;
}
.nd-discover-label::after { content:""; flex:1; height:1px; background: linear-gradient(90deg,#d4d7de,transparent); }
.nd-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.nd-card {
  background:#fff; border-radius:20px; padding:22px 22px 20px; text-decoration:none; color:inherit;
  display:flex; flex-direction:column; gap:12px;
  box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff;
  transition: transform .22s cubic-bezier(0.22,1,0.36,1), box-shadow .22s;
  border-left: 4px solid transparent;
  min-height: 175px;
}
.nd-card:hover { transform: translateY(-4px); box-shadow: 10px 14px 24px rgba(196,192,186,0.65), -8px -8px 18px #fff; }
.nd-card-head { display:flex; align-items:center; gap:14px; }
.nd-card-ico { width:48px; height:48px; border-radius:13px; display:flex; align-items:center; justify-content:center;
  box-shadow: inset 3px 3px 6px rgba(0,0,0,0.06), inset -3px -3px 6px rgba(255,255,255,0.9); flex-shrink:0; font-size:22px; }
.nd-card-title { font-family:"Sora",sans-serif; font-size:17px; font-weight:700; color:#2c2a28; letter-spacing:-0.01em; }
.nd-card-desc { font-size:13px; color:#6a6864; line-height:1.55; flex:1; }
.nd-card-foot { display:flex; align-items:center; justify-content:flex-end; gap:6px; font-family:"DM Mono",monospace; font-size:11px; font-weight:600;
  letter-spacing:0.06em; text-transform:uppercase; transition: gap .18s; }
.nd-card:hover .nd-card-foot { gap: 10px; }
@media (max-width: 1100px) { .nd-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .nd-grid { grid-template-columns: 1fr; } .nd-welcome-title { font-size: 26px; } }
</style>';

ob_start();
?>

<div class="nd-wrap">
  <div class="nd-welcome">
    <div class="nd-welcome-hand">🌐</div>
    <div class="nd-welcome-title">Ma Box Net<?= $prenom !== '' ? ' · ' . h($prenom) : '' ?></div>
    <div class="nd-welcome-sub">Espace Net<?= $societeNom !== '' ? ' — ' . h($societeNom) : '' ?>. Module en cours de consolidation.</div>
  </div>

  <div class="nd-discover-label">Explorer</div>
  <div class="nd-grid">
    <?php foreach ($items as $it): ?>
      <a href="<?= h($it['url']) ?>" class="nd-card" style="border-left-color:<?= h($it['color']) ?>;color:<?= h($it['color']) ?>">
        <div class="nd-card-head">
          <div class="nd-card-ico" style="background:<?= h($it['color']) ?>14; color:<?= h($it['color']) ?>;">
            <?= h($it['icon']) ?>
          </div>
          <div class="nd-card-title"><?= h($it['label']) ?></div>
        </div>
        <div class="nd-card-desc"><?= h($it['desc']) ?></div>
        <div class="nd-card-foot">Ouvrir</div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>

