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
$_fbxModalPath = __DIR__ . '/fluxbox_upload_modal.php';
if (is_file($_fbxModalPath)) {
    require $_fbxModalPath;
}
?>
</body>
</html>
