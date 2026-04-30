<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mbi_annonces_helpers.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$annonceId = (int)($_GET['id'] ?? 0);
$row = mbi_annonces_fetch_detail($pdo, $annonceId);
if (!$row) {
    http_response_code(404);
    include __DIR__ . '/inc/mbi_annonces_header.php';
    echo '<section class="mbi-section"><div class="mbi-container"><div class="mbi-empty"><h1>Annonce introuvable</h1><p>Cette annonce n\'est plus disponible ou n\'a jamais été diffusée sur MaBoxImmo.</p><a class="mbi-btn mbi-btn-primary" href="' . h(app_url('/mbi_annonces_index.php')) . '">Retour aux annonces</a></div></div></section>';
    include __DIR__ . '/inc/mbi_annonces_footer.php';
    exit;
}

$photos = mbi_annonces_fetch_photos($pdo, $annonceId);
$mainPhoto = mbi_annonces_pick_photo($photos, 'large');
$mainSrc = mbi_annonces_photo_src($mainPhoto);

$h1 = mbi_annonces_h1($row);
$canonical = mbi_annonces_abs_url(mbi_annonces_url_detail($annonceId, (string)($row['annonce_slug'] ?? '')));
$ogImage = $mainSrc !== '' ? mbi_annonces_abs_url($mainSrc) : '';

$imageAbsUrls = [];
foreach (array_slice($photos, 0, 5) as $p) {
    $u = mbi_annonces_photo_src($p);
    if ($u !== '') $imageAbsUrls[] = mbi_annonces_abs_url($u);
}

$mbiMeta = [
    'title'       => $h1 . ' — ' . (string)($row['nom_agence'] ?? 'MaBoxImmo'),
    'description' => mbi_annonces_meta_description($row),
    'canonical'   => $canonical,
    'image'       => $ogImage,
];

$mbiJsonLd = mbi_annonces_jsonld($row, $canonical, $imageAbsUrls)
           . mbi_annonces_breadcrumb_jsonld([
               ['name' => 'Accueil',    'url' => app_url('/mbi_annonces_index.php')],
               ['name' => 'Annonces',   'url' => app_url('/mbi_annonces_recherche.php')],
               ['name' => $h1,          'url' => mbi_annonces_url_detail($annonceId, (string)($row['annonce_slug'] ?? ''))],
           ]);

$cat = (string)($row['type_bien_categorie'] ?? '');
if (in_array($cat, ['professionnel', 'commerce'], true)) {
    $mbiNavActive = 'entreprise';
} elseif (($row['type_transaction'] ?? '') === 'vente') {
    $mbiNavActive = 'acheter';
} elseif (($row['type_transaction'] ?? '') === 'location') {
    $mbiNavActive = 'louer';
} else {
    $mbiNavActive = 'home';
}
$mbiBodyClass = 'mbi-page-detail';

$contactOk = isset($_GET['contact_ok']) && $_GET['contact_ok'] === '1';
$contactErr = (string)($_GET['contact_err'] ?? '');

include __DIR__ . '/inc/mbi_annonces_header.php';
?>

<article class="mbi-detail">
  <div class="mbi-container">

    <nav class="mbi-breadcrumb" aria-label="Fil d'Ariane">
      <a href="<?= h(app_url('/mbi_annonces_index.php')) ?>">Accueil</a>
      <span>›</span>
      <a href="<?= h(app_url('/mbi_annonces_recherche.php')) ?>">Annonces</a>
      <span>›</span>
      <span class="mbi-breadcrumb-current"><?= h($h1) ?></span>
    </nav>

    <header class="mbi-detail-head">
      <div class="mbi-detail-head-left">
        <div class="mbi-detail-tags">
          <span class="mbi-tag"><?= h(mbi_annonces_tx_label((string)($row['type_transaction'] ?? ''))) ?></span>
          <?php if (!empty($row['exclusivite'])): ?><span class="mbi-tag mbi-tag-excl">Exclusivité</span><?php endif; ?>
          <?php if (!empty($row['nouveaute'])): ?><span class="mbi-tag mbi-tag-new">Nouveauté</span><?php endif; ?>
          <?php if (!empty($row['coup_coeur'])): ?><span class="mbi-tag mbi-tag-cc">Coup de cœur</span><?php endif; ?>
        </div>
        <h1 class="mbi-detail-title"><?= h($h1) ?></h1>
        <div class="mbi-detail-loc">
          <?= h((string)($row['ville'] ?? '')) ?><?= !empty($row['code_postal']) ? ' (' . h((string)$row['code_postal']) . ')' : '' ?>
          <?php if (!empty($row['reference_annonce'])): ?>
            <span class="mbi-sep">•</span><span class="mbi-detail-ref">Réf. <?= h((string)$row['reference_annonce']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="mbi-detail-head-right">
        <div class="mbi-detail-price"><?= h(mbi_annonces_price_label($row)) ?></div>
        <?php if (($row['type_transaction'] ?? '') === 'vente' && !empty($row['honoraires_charge_acquereur'])): ?>
          <div class="mbi-detail-price-sub">Honoraires charge acquéreur</div>
        <?php elseif (($row['type_transaction'] ?? '') === 'location' && !empty($row['charges'])): ?>
          <div class="mbi-detail-price-sub">+ <?= h(mbi_annonces_fmt_eur((float)$row['charges'])) ?> de charges</div>
        <?php endif; ?>
      </div>
    </header>

    <section class="mbi-gallery" aria-label="Photos du bien">
      <div class="mbi-gallery-main">
        <img src="<?= h($mainSrc) ?>" alt="<?= h((string)($mainPhoto['caption'] ?? $mainPhoto['alt_photo'] ?? $h1)) ?>"
             width="<?= (int)($mainPhoto['largeur'] ?? 1600) ?>" height="<?= (int)($mainPhoto['hauteur'] ?? 1200) ?>"
             loading="eager" decoding="async">
      </div>
      <?php if (count($photos) > 1): ?>
        <div class="mbi-gallery-thumbs">
          <?php foreach (array_slice($photos, 0, 8) as $idx => $p):
            $src = mbi_annonces_photo_src($p);
            if ($src === '') continue;
          ?>
            <button type="button" class="mbi-gallery-thumb<?= $idx === 0 ? ' is-active' : '' ?>"
                    data-mbi-thumb="<?= h($src) ?>"
                    aria-label="Voir la photo <?= $idx + 1 ?>">
              <img src="<?= h($src) ?>" alt="" width="<?= (int)($p['largeur'] ?? 200) ?>" height="<?= (int)($p['hauteur'] ?? 150) ?>" loading="lazy" decoding="async">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <div class="mbi-detail-grid">
      <div class="mbi-detail-main">

        <section class="mbi-block">
          <div class="mbi-specs">
            <?php if (!empty($row['surface_habitable'])): ?>
              <div class="mbi-spec"><div class="mbi-spec-k">Surface</div><div class="mbi-spec-v"><?= (int)$row['surface_habitable'] ?> m²</div></div>
            <?php endif; ?>
            <?php if (!empty($row['nb_pieces'])): ?>
              <div class="mbi-spec"><div class="mbi-spec-k">Pièces</div><div class="mbi-spec-v"><?= (int)$row['nb_pieces'] ?></div></div>
            <?php endif; ?>
            <?php if (!empty($row['nb_chambres'])): ?>
              <div class="mbi-spec"><div class="mbi-spec-k">Chambres</div><div class="mbi-spec-v"><?= (int)$row['nb_chambres'] ?></div></div>
            <?php endif; ?>
            <?php if (!empty($row['etage']) || (string)($row['etage'] ?? '') === '0'): ?>
              <div class="mbi-spec"><div class="mbi-spec-k">Étage</div><div class="mbi-spec-v"><?= (int)$row['etage'] ?></div></div>
            <?php endif; ?>
            <?php if (!empty($row['surface_terrain'])): ?>
              <div class="mbi-spec"><div class="mbi-spec-k">Terrain</div><div class="mbi-spec-v"><?= (int)$row['surface_terrain'] ?> m²</div></div>
            <?php endif; ?>
            <?php if (!empty($row['annee_construction'])): ?>
              <div class="mbi-spec"><div class="mbi-spec-k">Construction</div><div class="mbi-spec-v"><?= (int)$row['annee_construction'] ?></div></div>
            <?php endif; ?>
          </div>
        </section>

        <?php
          $description = trim((string)($row['description'] ?? ''));
          if ($description === '') $description = trim((string)($row['accroche_commerciale'] ?? ''));
          if ($description === '') $description = trim((string)($row['resume_court'] ?? ''));
        ?>
        <?php if ($description !== ''): ?>
          <section class="mbi-block">
            <h2 class="mbi-block-title">Description</h2>
            <div class="mbi-prose"><?= nl2br(h($description)) ?></div>
          </section>
        <?php endif; ?>

        <?php
          $features = [];
          $featureMap = [
            'ascenseur' => 'Ascenseur', 'balcon' => 'Balcon', 'terrasse' => 'Terrasse',
            'jardin' => 'Jardin', 'cave' => 'Cave', 'garage' => 'Garage', 'box' => 'Box',
            'piscine' => 'Piscine', 'climatisation' => 'Climatisation', 'fibre' => 'Fibre',
            'double_vitrage' => 'Double vitrage', 'cheminee' => 'Cheminée',
            'cuisine_equipee' => 'Cuisine équipée', 'meuble' => 'Meublé',
            'interphone' => 'Interphone', 'digicode' => 'Digicode', 'alarme' => 'Alarme',
            'dernier_etage' => 'Dernier étage', 'volets_roulants' => 'Volets roulants',
          ];
          foreach ($featureMap as $k => $label) {
            if (!empty($row[$k])) $features[] = $label;
          }
          if (!empty($row['parking_nb']) && (int)$row['parking_nb'] > 0) {
            $features[] = (int)$row['parking_nb'] . ' place' . ((int)$row['parking_nb'] > 1 ? 's' : '') . ' de parking';
          }
        ?>
        <?php if ($features): ?>
          <section class="mbi-block">
            <h2 class="mbi-block-title">Caractéristiques</h2>
            <ul class="mbi-features">
              <?php foreach ($features as $f): ?>
                <li><?= h($f) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>

        <?php if (!empty($row['dpe_classe']) || !empty($row['ges_classe'])): ?>
          <section class="mbi-block">
            <h2 class="mbi-block-title">Performance énergétique</h2>
            <div class="mbi-energy">
              <?php if (!empty($row['dpe_classe'])): ?>
                <div class="mbi-energy-card mbi-energy-dpe-<?= h(strtolower((string)$row['dpe_classe'])) ?>">
                  <div class="mbi-energy-lbl">DPE</div>
                  <div class="mbi-energy-val"><?= h((string)$row['dpe_classe']) ?></div>
                  <?php if (!empty($row['dpe_valeur'])): ?>
                    <div class="mbi-energy-sub"><?= (int)$row['dpe_valeur'] ?> kWh/m²/an</div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($row['ges_classe'])): ?>
                <div class="mbi-energy-card mbi-energy-ges-<?= h(strtolower((string)$row['ges_classe'])) ?>">
                  <div class="mbi-energy-lbl">GES</div>
                  <div class="mbi-energy-val"><?= h((string)$row['ges_classe']) ?></div>
                  <?php if (!empty($row['ges_valeur'])): ?>
                    <div class="mbi-energy-sub"><?= (int)$row['ges_valeur'] ?> kg CO₂/m²/an</div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <?php if (!empty($row['montant_estime_depenses_min']) || !empty($row['montant_estime_depenses_max'])): ?>
              <p class="mbi-muted mbi-small">Dépenses énergétiques estimées : <?= mbi_annonces_fmt_eur((float)$row['montant_estime_depenses_min']) ?> à <?= mbi_annonces_fmt_eur((float)$row['montant_estime_depenses_max']) ?> / an<?= !empty($row['annee_reference_depenses']) ? ' (réf. ' . (int)$row['annee_reference_depenses'] . ')' : '' ?>.</p>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <?php if (!empty($row['ville'])): ?>
          <section class="mbi-block">
            <h2 class="mbi-block-title">Localisation</h2>
            <p>Bien situé à <strong><?= h((string)$row['ville']) ?></strong><?= !empty($row['code_postal']) ? ' (' . h((string)$row['code_postal']) . ')' : '' ?>.<?= !empty($row['adresse_1']) ? ' ' . h((string)$row['adresse_1']) : '' ?></p>
            <p class="mbi-muted mbi-small">L'adresse complète est communiquée à la prise de contact avec l'agence.</p>
          </section>
        <?php endif; ?>

      </div>

      <aside class="mbi-detail-aside">
        <div class="mbi-aside-card mbi-aside-agence">
          <?php if (!empty($row['nom_agence'])): ?>
            <div class="mbi-aside-agence-title"><?= h((string)$row['nom_agence']) ?></div>
            <?php if (!empty($row['agence_ville'])): ?>
              <div class="mbi-aside-agence-loc"><?= h((string)$row['agence_ville']) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="mbi-aside-card mbi-aside-contact" id="contact">
          <h3 class="mbi-aside-title">Contacter l'agence</h3>

          <?php if ($contactOk): ?>
            <div class="mbi-alert mbi-alert-ok">Merci ! Votre demande a bien été envoyée. L'agence vous recontactera rapidement.</div>
          <?php elseif ($contactErr !== ''): ?>
            <div class="mbi-alert mbi-alert-err"><?= h($contactErr) ?></div>
          <?php endif; ?>

          <form method="post" action="<?= h(app_url('/mbi_annonces_contact.php')) ?>" class="mbi-form">
            <?= csrf_field('mbi_annonces_contact') ?>
            <input type="text" name="website" value="" autocomplete="off" tabindex="-1" aria-hidden="true" class="mbi-hp">
            <input type="hidden" name="id_annonce" value="<?= (int)$annonceId ?>">

            <label class="mbi-field">
              <span>Nom *</span>
              <input name="nom" required maxlength="120" autocomplete="name">
            </label>
            <label class="mbi-field">
              <span>Email *</span>
              <input name="email" type="email" required maxlength="160" autocomplete="email">
            </label>
            <label class="mbi-field">
              <span>Téléphone</span>
              <input name="telephone" inputmode="tel" maxlength="30" autocomplete="tel">
            </label>
            <label class="mbi-field">
              <span>Message</span>
              <textarea name="message" rows="5" maxlength="2000">Bonjour, je souhaite obtenir plus d'informations concernant cette annonce<?= !empty($row['reference_annonce']) ? ' (réf. ' . (string)$row['reference_annonce'] . ')' : '' ?>.</textarea>
            </label>

            <button class="mbi-btn mbi-btn-primary mbi-btn-block" type="submit">Envoyer ma demande</button>
            <p class="mbi-muted mbi-small">En envoyant ce message, vous acceptez d'être recontacté par l'agence concernée.</p>
          </form>
        </div>

        <?php if (!empty($row['video_url']) || !empty($row['visite_virtuelle_url'])): ?>
          <div class="mbi-aside-card">
            <h3 class="mbi-aside-title">Médias</h3>
            <ul class="mbi-aside-links">
              <?php if (!empty($row['visite_virtuelle_url'])): ?>
                <li><a href="<?= h((string)$row['visite_virtuelle_url']) ?>" target="_blank" rel="noopener">Visite virtuelle ↗</a></li>
              <?php endif; ?>
              <?php if (!empty($row['video_url'])): ?>
                <li><a href="<?= h((string)$row['video_url']) ?>" target="_blank" rel="noopener">Vidéo ↗</a></li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>
      </aside>
    </div>

  </div>
</article>

<?php include __DIR__ . '/inc/mbi_annonces_footer.php'; ?>
