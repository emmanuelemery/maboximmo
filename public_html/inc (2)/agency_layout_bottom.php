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
</body>
</html>
