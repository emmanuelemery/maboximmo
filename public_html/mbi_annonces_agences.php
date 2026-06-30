<?php
declare(strict_types=1);

/**
 * mbi_annonces_agences.php — Page publique « Nos agences ».
 * Coordonnées, horaires d'ouverture et activités de chaque agence MaBoxImmo.
 * Données issues de la table `agences` (actif = 1).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mbi_annonces_helpers.php';
require_once __DIR__ . '/inc/mbi_annonces_pages_helpers.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Seules les agences ayant au moins une annonce active visible sur MBI.
$agences   = mbi_pages_fetch_public_agences($pdo, true);
$canonical = mbi_annonces_abs_url(app_url('/mbi_annonces_agences.php'));

$mbiMeta = [
    'title'       => 'Nos agences immobilières — adresses & horaires · Ma Box Immo',
    'description' => "Retrouvez les coordonnées, horaires d'ouverture et activités des agences Ma Box Immo à Lyon, dans le Rhône, la Loire et l'Isère. Prenez rendez-vous avec un conseiller proche de chez vous.",
    'canonical'   => $canonical,
    'image'       => mbi_annonces_abs_url(app_url('/images/Home.png')),
];

// JSON-LD : une entité LocalBusiness/RealEstateAgent par agence + fil d'Ariane.
$ld = '';
foreach ($agences as $ag) {
    $node = [
        '@context' => 'https://schema.org',
        '@type'    => 'RealEstateAgent',
        'name'     => (string)($ag['nom_commercial'] ?: $ag['nom_agence']),
        'url'      => $canonical,
    ];
    $addr = [];
    if (!empty($ag['adresse_1']))  $addr['streetAddress']   = trim((string)$ag['adresse_1']);
    if (!empty($ag['code_postal'])) $addr['postalCode']      = (string)$ag['code_postal'];
    if (!empty($ag['ville']))      $addr['addressLocality']  = (string)$ag['ville'];
    if ($addr) { $addr['@type'] = 'PostalAddress'; $addr['addressCountry'] = 'FR'; $node['address'] = $addr; }
    if (!empty($ag['telephone'])) $node['telephone'] = (string)$ag['telephone'];
    if (!empty($ag['email']))     $node['email']     = (string)$ag['email'];
    if (!empty($ag['latitude']) && !empty($ag['longitude'])) {
        $node['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$ag['latitude'], 'longitude' => (float)$ag['longitude']];
    }
    if (!empty($ag['note_google'])) {
        $node['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string)$ag['note_google'], 'reviewCount' => (string)max(1, (int)($ag['nb_avis_google'] ?? 1))];
    }
    $ld .= '<script type="application/ld+json">' . json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
$ld .= mbi_annonces_breadcrumb_jsonld([
    ['name' => 'Accueil',     'url' => app_url('/mbi_annonces_index.php')],
    ['name' => 'Nos agences', 'url' => app_url('/mbi_annonces_agences.php')],
]);

$mbiJsonLd    = $ld;
$mbiNavActive = 'agences';
$mbiBodyClass = 'mbi-page-editorial mbi-page-agences';

include __DIR__ . '/inc/mbi_annonces_header.php';
?>

<section class="mbi-page-hero">
  <div class="mbi-container">
    <span class="mbi-page-eyebrow">Proches de chez vous</span>
    <h1>Nos agences immobilières</h1>
    <p class="mbi-page-lead">Une question, un projet, une estimation&nbsp;? Poussez la porte de l'agence Ma Box Immo la plus proche. Adresses, horaires d'ouverture et coordonnées directes&nbsp;: tout est ci-dessous.</p>
  </div>
</section>

<article class="mbi-prose">
  <?php if (empty($agences)): ?>
    <p>La liste de nos agences sera bientôt disponible. En attendant, <a href="<?= h(app_url('/mbi_annonces_contact_general.php')) ?>">contactez-nous</a> directement.</p>
  <?php else: ?>
    <p>Le réseau Ma Box Immo rassemble des agences indépendantes ancrées dans leur territoire, en Auvergne-Rhône-Alpes. Chacune connaît son marché, ses quartiers et ses prix. Choisissez la vôtre&nbsp;:</p>

    <div class="mbi-agence-grid">
      <?php foreach ($agences as $ag):
        $nom     = (string)($ag['nom_commercial'] ?: $ag['nom_agence']);
        $siteAgenceUrl = app_url('/mbi_annonces_index.php?net_agence=' . (int)$ag['id']);
        $adresse = mbi_pages_agence_address($ag);
        $tel     = trim((string)($ag['telephone'] ?? ''));
        $mail    = trim((string)($ag['email_contact'] ?: ($ag['email'] ?? '')));
        $site    = trim((string)($ag['site_web'] ?? ''));
        $horaires = trim((string)($ag['horaires'] ?? ''));
        $mapsQ   = (!empty($ag['latitude']) && !empty($ag['longitude']))
            ? ((string)$ag['latitude'] . ',' . (string)$ag['longitude'])
            : ($adresse !== '' ? $adresse : $nom);
        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapsQ);

        $acts = [];
        if (!empty($ag['transaction_active'])) $acts[] = 'Transaction';
        if (!empty($ag['location_active']))    $acts[] = 'Location';
        if (!empty($ag['gestion_active']))     $acts[] = 'Gestion';
        if (!empty($ag['syndic_active']))      $acts[] = 'Syndic';
        if (!empty($ag['neuf_active']))        $acts[] = 'Neuf';
      ?>
        <div class="mbi-agence-card">
          <?php $ville = trim((string)($ag['ville'] ?? '')); ?>
          <?php if ($ville !== ''): ?>
            <h3><a href="<?= h($siteAgenceUrl) ?>" style="color:inherit;text-decoration:none;" title="Voir le site de l'agence"><?= h($ville) ?></a></h3>
            <div class="mbi-agence-city"><?= h($nom) ?></div>
          <?php else: ?>
            <h3><a href="<?= h($siteAgenceUrl) ?>" style="color:inherit;text-decoration:none;" title="Voir le site de l'agence"><?= h($nom) ?></a></h3>
          <?php endif; ?>

          <?php if ($acts): ?>
            <div class="mbi-agence-tags">
              <?php foreach ($acts as $a): ?><span class="mbi-agence-tag"><?= h($a) ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($adresse !== ''): ?>
            <div class="mbi-agence-row"><span class="ic" aria-hidden="true">📍</span>
              <a href="<?= h($mapsUrl) ?>" target="_blank" rel="noopener"><?= h($adresse) ?></a></div>
          <?php endif; ?>
          <?php if ($tel !== ''): ?>
            <div class="mbi-agence-row"><span class="ic" aria-hidden="true">📞</span>
              <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $tel)) ?>"><?= h($tel) ?></a></div>
          <?php endif; ?>
          <?php if ($mail !== ''): ?>
            <div class="mbi-agence-row"><span class="ic" aria-hidden="true">✉️</span>
              <a href="mailto:<?= h($mail) ?>"><?= h($mail) ?></a></div>
          <?php endif; ?>
          <?php if ($site !== ''): ?>
            <div class="mbi-agence-row"><span class="ic" aria-hidden="true">🌐</span>
              <a href="<?= h(preg_match('~^https?://~', $site) ? $site : 'https://' . $site) ?>" target="_blank" rel="noopener"><?= h(preg_replace('~^https?://~', '', $site)) ?></a></div>
          <?php endif; ?>

          <?php
            $agQ      = !empty($ag['slug']) ? '?agence=' . rawurlencode((string)$ag['slug']) : '';
            $idSoc    = (int)($ag['id_societe'] ?? 0);
            $tarifUrl = $idSoc > 0 ? app_url('/tarifs_societe.php?societe=' . $idSoc) : app_url('/tarifs.php');
          ?>
          <details class="mbi-agence-hours-dd">
            <summary>🕒 Horaires d'ouverture</summary>
            <div class="mbi-agence-hours-body"><?= $horaires !== '' ? h($horaires) : 'Contactez l\'agence pour connaître les horaires.' ?></div>
          </details>

          <div class="mbi-agence-actions">
            <a class="mbi-btn mbi-btn-primary mbi-btn-block" href="<?= h($siteAgenceUrl) ?>">🌐 Voir le site de l'agence</a>
            <a class="mbi-btn mbi-btn-ghost mbi-btn-block" href="<?= h(app_url('/mbi_annonces_contact_general.php' . $agQ)) ?>#form">Contacter cette agence</a>
            <a class="mbi-btn mbi-btn-ghost mbi-btn-block" href="<?= h($tarifUrl) ?>">💶 Tarifs particuliers</a>
            <a class="mbi-agence-maplink" href="<?= h($mapsUrl) ?>" target="_blank" rel="noopener">📍 Itinéraire</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mbi-cta-band">
      <h2>Vous ne savez pas vers qui vous tourner&nbsp;?</h2>
      <p>Écrivez-nous&nbsp;: nous orientons votre demande vers l'agence la plus adaptée.</p>
      <a class="mbi-btn mbi-btn-primary" href="<?= h(app_url('/mbi_annonces_contact_general.php')) ?>">Nous contacter</a>
    </div>
  <?php endif; ?>
</article>

<?php include __DIR__ . '/inc/mbi_annonces_footer.php'; ?>
