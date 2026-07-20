<?php defined('MBI') or exit(http_response_code(403)); ?>

<?php include __DIR__ . '/_mission_bureau.php'; /* rail permanent + drawer + sidebar off-canvas */ ?>
<?php include __DIR__ . '/_mission_fiche.php'; /* LA fiche-modèle UNIQUE (jamais dupliquée) */ ?>
</div><!-- /.mbi-mission -->

<script>
/* Contexte mission passé au JS centralisé (config, pas de logique inline) */
window.MBI_MISSION = window.MBI_MISSION || {};
window.MBI_MISSION.location = {
  endpoints: <?= json_encode($EP, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
  gmapsKey: <?= json_encode((string)$GOOGLE_MAPS_API_KEY) ?>,
  docCsrf: <?= json_encode($docCsrf) ?>,
  idAgence: <?= json_encode((int)$ml_agenceId) ?>,
  agences: <?= json_encode(array_map(fn($a)=>['id'=>(int)$a['id'],'lib'=>$a['lib']], $ml_agences), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<!-- Composant Google Places existant (émet 'places:filled') + Maps JS pour Street View -->
<script src="<?= h($assets('/js/places.js')) ?>?v=<?= @filemtime(__DIR__ . '/../js/places.js') ?: '1' ?>"></script>
<?php if (!empty($GOOGLE_MAPS_API_KEY)): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>
<script src="<?= h($assets('/assets/js/mission.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/mission.js') ?: '1' ?>"></script>
<script src="<?= h($assets('/assets/js/mission-cascade.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/mission-cascade.js') ?: '1' ?>"></script>
<?php include __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
