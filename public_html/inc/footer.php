<?php
declare(strict_types=1);

$appLayout = $appLayout ?? false;
$includeGooglePlaces = $includeGooglePlaces ?? false;
$includeGoogleMapsJs = $includeGoogleMapsJs ?? false;
$GOOGLE_MAPS_API_KEY = $GOOGLE_MAPS_API_KEY ?? '';
?>

<?php if (!$appLayout): ?>
</div>

<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-left">
            <div class="site-footer-title">MaBoxImmo</div>
            <div class="site-footer-text">© <?= date('Y') ?> MaBoxImmo - Tous droits réservés</div>
        </div>

        <div class="site-footer-nav">
            <a href="<?= htmlspecialchars(app_url('/mentions-legales.php')) ?>">Mentions légales</a>
            <a href="<?= htmlspecialchars(app_url('/politique-confidentialite.php')) ?>">Confidentialité</a>
            <a href="<?= htmlspecialchars(app_url('/contact.php')) ?>">Contact</a>
        </div>
    </div>
</footer>
<?php endif; ?>

<script src="<?= htmlspecialchars(asset_url('/js/app.js')) ?>"></script>
<?php if ($includeGooglePlaces): ?>
    <script src="<?= htmlspecialchars(asset_url('/js/places.js')) ?>"></script>
<?php endif; ?>
<?php if ($includeGoogleMapsJs && $GOOGLE_MAPS_API_KEY !== ''): ?>
    <script
        src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"
        async
        defer
    ></script>
<?php endif; ?>
</body>
</html>

