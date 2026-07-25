<?php
/**
 * inc/agency_layout_bottom.php — Fermeture du layout agency normalisé
 * Ferme : </div> (agency-content) + </body></html>
 * Peut recevoir $extraJs (string) pour scripts spécifiques avant </body>
 */
?>
</div><!-- /agency-content -->
<?php if (!empty($extraJs)) echo $extraJs; ?>

<?php
// ── Modale FluxBox d'upload universelle (disponible sur toutes les pages) ──
// Fix B1 (2026-05-26) : require_once pour éviter double instance dans le DOM
// (cas pages qui includent aussi le modal manuellement).
$_fbxModalPath = __DIR__ . '/fluxbox_upload_modal.php';
if (is_file($_fbxModalPath)) {
    require_once $_fbxModalPath;
}
?>
<!-- ── Fiches 360 : toutes les cartes-listes REPLIÉES à l'ouverture (demande UX Emery) ──
     Ciblé .f360-card uniquement → no-op sur les pages sans fiche 360. Clic sur le titre = déplier. -->
<style>
  .f360-card.f360-collapsed > *:not(h3):not(summary), .c3-card.f360-collapsed > *:not(h3):not(summary){ display:none !important; }
  .f360-card > h3.f360-collap-h, .c3-card > h3.f360-collap-h{ cursor:pointer; user-select:none; }
  .f360-card > h3.f360-collap-h::before, .c3-card > h3.f360-collap-h::before{ content:'▸ '; color:#b9b3a8; font-size:12px; font-weight:400; }
  .f360-card:not(.f360-collapsed) > h3.f360-collap-h::before, .c3-card:not(.f360-collapsed) > h3.f360-collap-h::before{ content:'▾ '; }
</style>
<script>
(function(){
  if (window.__f360CollapseInit) return; window.__f360CollapseInit = true;   // idempotent
  function initCollapse(){
    document.querySelectorAll('.f360-card, .c3-card').forEach(function(card){
      if (card.tagName.toLowerCase() === 'details') return;      // <details> gèrent déjà leur repli
      var h = card.querySelector(':scope > h3');
      if (!h || h.classList.contains('f360-collap-h')) return;
      h.classList.add('f360-collap-h');
      card.classList.add('f360-collapsed');                      // tout replié à l'ouverture
      h.addEventListener('click', function(e){
        if (e.target.closest('button, a, input, select, textarea, label')) return; // ne pas toggler sur un contrôle
        card.classList.toggle('f360-collapsed');
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initCollapse);
  else initCollapse();
})();
</script>
</body>
</html>
