<?php
/**
 * inc/ged_inject_sidebar.php — Injection sidebar GED + container content
 *
 * À inclure dans chaque page GED qui utilise `inc/header.php` (et donc
 * n'utilise pas agency_layout_top.php). Pattern :
 *
 *   require_once __DIR__ . '/inc/header.php';
 *   require_once __DIR__ . '/inc/ged_inject_sidebar.php';
 *   // ... contenu HTML de la page ...
 *
 * Effet :
 *   - rend la sidebar dédiée GED (sidebar_ged.php) à gauche
 *   - décale le contenu de 248px à droite via .ged-content-wrap
 *   - responsive : sidebar repliée en mobile (<768px)
 *
 * Si la page utilise déjà agency_layout_top.php (modules/ged/ged_dashboard,
 * ged_inbox, ged_dashboard_legacy), pas besoin de ce fichier — préférer :
 *   $layoutSidebar = 'sidebar_ged';
 *   require_once __DIR__ . '/inc/agency_layout_top.php';
 *
 * Le </div> n'est pas explicitement fermé : le body se ferme naturellement
 * en fin de document. Aucune dette HTML car aucun élément frère n'est
 * attendu après le contenu de la page.
 */
?>
<link rel="stylesheet" href="/css/sidebar.css">
<style>
  /* ─── Reset minimal pour intégration sidebar ─── */
  body { margin: 0; }
  .ged-content-wrap {
    margin-left: 248px;
    padding: 24px 28px 60px;
    min-height: 100vh;
    box-sizing: border-box;
    background: #f8f7f5;
  }
  @media (max-width: 768px) {
    .ged-content-wrap { margin-left: 0; padding: 12px; }
  }
</style>
<?php require_once __DIR__ . '/sidebar_ged.php'; ?>
<div class="ged-content-wrap">
