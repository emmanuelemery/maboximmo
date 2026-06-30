<?php
/**
 * Ma GED Box V2.5 — Bouton "Aide / Guide GED" fixe top-right
 * ============================================================
 *
 * À inclure depuis chaque page du module GED via :
 *   require_once dirname(__DIR__) . '/inc/ged_help_button.php'; // (depuis modules/ged/)
 *   require_once __DIR__ . '/inc/ged_help_button.php';          // (depuis public_html/)
 *
 * Affiche un bouton fixe en haut à droite (style topbar) qui ouvre le guide
 * d'utilisation du module GED dans un nouvel onglet. Présent sur toutes les
 * pages du module pour rappeler à l'utilisateur où trouver l'aide.
 *
 * Sans paramètres. Pas de side-effect autre que l'output HTML/CSS.
 */
?>
<a href="/super_admin_ged_guide.php" target="_blank" rel="noopener"
   class="ged-help-fab" id="ged-help-fab"
   title="Guide d'utilisation du module GED (nouvel onglet)">
  <span class="ged-help-fab-icon">📖</span>
  <span class="ged-help-fab-label">Guide GED</span>
</a>
<style>
  .ged-help-fab {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 9998;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: linear-gradient(135deg, #243B5C 0%, #1e3050 100%);
    color: #fff;
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    box-shadow: 0 3px 10px rgba(36, 59, 92, .35);
    transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    border: 2px solid #D4A047;
    font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  .ged-help-fab:hover {
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(36, 59, 92, .45);
    background: linear-gradient(135deg, #2c4870 0%, #243B5C 100%);
    color: #fff;
  }
  .ged-help-fab:active { transform: translateY(0); }
  .ged-help-fab-icon { font-size: 14px; line-height: 1; }
  .ged-help-fab-label { line-height: 1; }
  @media (max-width: 720px) {
    .ged-help-fab-label { display: none; }
    .ged-help-fab { padding: 8px 10px; }
  }
</style>
