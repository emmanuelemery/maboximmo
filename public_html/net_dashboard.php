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

$isManager = in_array((int)current_role_id(), [1, 2], true);

// ── KPI réels (périmètre MaBoxNet) ────────────────────────────────
require_once __DIR__ . '/inc/mbi_annonces_helpers.php';
require_once __DIR__ . '/inc/net_context.php';

$kpi = ['annonces' => 0, 'ventes' => 0, 'locations' => 0, 'agences' => 0];
try {
    $perim    = net_societes_perimetre();                       // [1, 2]
    $inSoc    = implode(',', array_map('intval', $perim));
    $statuses = mbi_annonces_active_statuses();
    $inA      = implode(',', array_fill(0, count($statuses), '?'));
    $bs       = mbi_biens_publishable_statuses();
    $inB      = implode(',', array_fill(0, count($bs), '?'));
    $params   = array_merge($statuses, $bs);

    $sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN a.type_transaction = 'vente'    THEN 1 ELSE 0 END) AS ventes,
                SUM(CASE WHEN a.type_transaction = 'location' THEN 1 ELSE 0 END) AS locations,
                COUNT(DISTINCT a.id_agence) AS agences
            FROM annonces a
            INNER JOIN biens b ON b.id = a.id_bien
            WHERE a.statut IN ($inA)
              AND b.statut_bien IN ($inB)
              AND a.visible_maboximmo = 1
              AND a.id_societe IN ($inSoc)";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $kpi['annonces']  = (int)$r['total'];
        $kpi['ventes']    = (int)$r['ventes'];
        $kpi['locations'] = (int)$r['locations'];
        $kpi['agences']   = (int)$r['agences'];
    }
} catch (Throwable $e) { /* KPI silencieux si schéma indisponible */ }

$kpis = [
    ['label' => 'Annonces en ligne', 'value' => $kpi['annonces'],  'sub' => 'visibles sur le portail',      'color' => '#3a7ab8', 'url' => 'mbi_annonces_index.php'],
    ['label' => 'À la vente',        'value' => $kpi['ventes'],     'sub' => 'biens en vente diffusés',       'color' => '#c8883a', 'url' => 'mbi_annonces_recherche.php?transaction=vente'],
    ['label' => 'En location',       'value' => $kpi['locations'],  'sub' => 'biens en location diffusés',    'color' => '#7c9885', 'url' => 'mbi_annonces_recherche.php?transaction=location'],
    ['label' => 'Agences en ligne',  'value' => $kpi['agences'],    'sub' => 'avec au moins une annonce',     'color' => '#6a4ca8', 'url' => 'mbi_annonces_agences.php'],
];

$items = [];
if ($isManager) {
    $items[] = ['label' => 'Textes des pages', 'desc' => 'Contenu éditorial local de chaque vitrine (SEO).', 'url' => 'net_admin_pages.php', 'icon' => '📝', 'color' => '#36577d'];
    $items[] = ['label' => 'Coordonnées & horaires', 'desc' => 'Téléphone, e-mail, adresse et horaires par agence.', 'url' => 'net_admin_contact.php', 'icon' => '📍', 'color' => '#1f6f7a'];
    $items[] = ['label' => 'Collaborateurs', 'desc' => 'Équipe affichée sur la vitrine (photo, fonction).', 'url' => 'net_admin_collaborateurs.php', 'icon' => '👥', 'color' => '#7c9885'];
}
$items[] = ['label' => 'Tarifs & honoraires', 'desc' => 'Barème de l\'agence (vente, location, gestion, syndic).', 'url' => 'agency_honoraires_config.php', 'icon' => '💶', 'color' => '#c8883a'];
$items[] = ['label' => 'Portail annonces', 'desc' => 'Le site public et toutes les annonces diffusées.', 'url' => 'mbi_annonces_index.php', 'icon' => '🌐', 'color' => '#3a7ab8'];

// « Voir le site agence » = le VRAI site de l'agence (sous-dossier marque/ville), pas le portail général.
$_idAgenceSess = (int)($_SESSION['id_agence'] ?? 0);
$_netSiteUrl   = net_agence_site_url($_idAgenceSess);
if ($_netSiteUrl !== '') {
    $items[] = ['label' => 'Voir le site agence', 'desc' => 'Aperçu du site personnalisé de votre agence ↗', 'url' => $_netSiteUrl, 'icon' => '🌍', 'color' => '#1f6f7a', 'blank' => true, 'abs' => true];
}
// (Les flux XML Ubiflow restent réservés à l'admin → accessibles depuis super_admin_dashboard.php.)

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
.nd-kpis { display:grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 30px; }
.nd-kpi {
  background:#fff; border-radius:18px; padding:18px 20px; text-decoration:none; color:inherit;
  display:flex; flex-direction:column; gap:6px; border-top: 4px solid transparent;
  box-shadow: 6px 6px 16px rgba(196,192,186,0.5), -6px -6px 16px #fff;
  transition: transform .2s cubic-bezier(0.22,1,0.36,1), box-shadow .2s;
}
.nd-kpi:hover { transform: translateY(-3px); box-shadow: 10px 12px 22px rgba(196,192,186,0.6), -8px -8px 18px #fff; }
.nd-kpi-val { font-family:"DM Mono",monospace; font-size: 34px; font-weight: 500; line-height: 1; }
.nd-kpi-label { font-size: 13px; font-weight: 700; color:#2c2a28; letter-spacing:-0.01em; }
.nd-kpi-sub { font-size: 11px; color:#9a9690; font-family:"DM Mono",monospace; letter-spacing:0.04em; }
@media (max-width: 1100px) { .nd-kpis { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px)  { .nd-kpis { grid-template-columns: 1fr 1fr; } }
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

  <div class="nd-kpis">
    <?php foreach ($kpis as $k): ?>
      <a href="<?= h($k['url']) ?>" class="nd-kpi" style="border-top-color:<?= h($k['color']) ?>;">
        <div class="nd-kpi-val" style="color:<?= h($k['color']) ?>;"><?= (int)$k['value'] ?></div>
        <div class="nd-kpi-label"><?= h($k['label']) ?></div>
        <div class="nd-kpi-sub"><?= h($k['sub']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="nd-discover-label">Explorer</div>
  <div class="nd-grid">
    <?php foreach ($items as $it): ?>
      <a href="<?= h($it['url']) ?>" class="nd-card"<?= !empty($it['blank']) ? ' target="_blank" rel="noopener"' : '' ?> style="border-left-color:<?= h($it['color']) ?>;color:<?= h($it['color']) ?>">
        <div class="nd-card-head">
          <div class="nd-card-ico" style="background:<?= h($it['color']) ?>14; color:<?= h($it['color']) ?>;">
            <?= h($it['icon']) ?>
          </div>
          <div class="nd-card-title"><?= h($it['label']) ?></div>
        </div>
        <div class="nd-card-desc"><?= h($it['desc']) ?></div>
        <div class="nd-card-foot"><?= !empty($it['blank']) ? 'Ouvrir ↗' : 'Ouvrir' ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>

