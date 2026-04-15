<?php
/**
 * inc/agency_layout_bottom.php — Fermeture du layout agency normalisé
 * Ferme : </div> (agency-content) + </body></html>
 * Peut recevoir $extraJs (string) pour scripts spécifiques avant </body>
 */
?>
</div><!-- /agency-content -->
<?php if (!empty($extraJs)) echo $extraJs; ?>
</body>
</html>
