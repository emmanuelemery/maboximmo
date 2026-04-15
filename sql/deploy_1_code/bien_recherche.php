<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Recherche';
$pageDescription = 'Recherchez un bien immobilier par adresse ou ville et affinez les critères principaux.';
$pageCanonical = app_url('/bien_recherche.php');
$includeGooglePlaces = true;
$includeGoogleMapsJs = true;

include __DIR__ . '/inc/header.php';
?>

<section class="card">
    <h2>Rechercher un bien</h2>
    <p>Entrez une adresse ou une ville, puis affinez vos critères.</p>

    <form method="get" action="/bien_recherche.php">
        <div class="form-grid">
            <div class="form-group full">
                <label for="recherche_adresse">Adresse ou ville</label>
                <input
                    type="text"
                    id="recherche_adresse"
                    name="q"
                    placeholder="Ex: Lyon, 24 rue Victor Hugo"
                    data-places-input
                    data-places-endpoint="<?= h(app_url('/api/places_autocomplete.php')) ?>"
                    data-places-details-endpoint="<?= h(app_url('/api/places_details.php')) ?>"
                    data-places-geocode-endpoint="<?= h(app_url('/api/geocode_address.php')) ?>"
                    data-places-street1="recherche_adresse_1"
                    data-places-street2="recherche_adresse_2"
                    data-places-postal="recherche_code_postal"
                    data-places-city="recherche_ville"
                    data-places-country="recherche_pays"
                    data-places-lat="recherche_latitude"
                    data-places-lng="recherche_longitude"
                    data-places-place-id="recherche_google_place_id"
                    data-places-formatted="recherche_adresse_formatee"
                    data-places-country-code="fr"
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label for="budget_max">Budget max</label>
                <input type="number" id="budget_max" name="budget_max" placeholder="€">
            </div>

            <div class="form-group">
                <label for="surface_min">Surface min</label>
                <input type="number" id="surface_min" name="surface_min" placeholder="m²">
            </div>
        </div>

        <input type="hidden" id="recherche_adresse_1" name="adresse_1">
        <input type="hidden" id="recherche_adresse_2" name="adresse_2">
        <input type="hidden" id="recherche_code_postal" name="code_postal">
        <input type="hidden" id="recherche_ville" name="ville">
        <input type="hidden" id="recherche_pays" name="pays">
        <input type="hidden" id="recherche_latitude" name="latitude">
        <input type="hidden" id="recherche_longitude" name="longitude">
        <input type="hidden" id="recherche_google_place_id" name="google_place_id">
        <input type="hidden" id="recherche_adresse_formatee" name="adresse_formatee">

        <div class="actions" style="margin-top:12px;">
            <button type="submit" class="btn btn-primary">Rechercher</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
