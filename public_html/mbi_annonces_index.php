<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mbi_annonces_helpers.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$filters = mbi_annonces_filters_from_get($_GET);
$filters['per_page'] = 12;
$filters['page'] = 1;
$list = mbi_annonces_fetch_list($pdo, $filters);

$typesBien = mbi_annonces_fetch_types_bien_with_annonces($pdo);

$canonical = mbi_annonces_abs_url(app_url('/mbi_annonces_index.php'));

$mbiMeta = [
    'title'       => 'MaBoxImmo — Trouvez votre bien idéal',
    'description' => 'Le portail immobilier pensé pour particuliers et professionnels. Acheter, louer, estimer : toutes les annonces des agences MaBoxImmo.',
    'canonical'   => $canonical,
    'image'       => mbi_annonces_abs_url(app_url('/images/Home.png')),
];

$mbiJsonLd = mbi_annonces_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => app_url('/mbi_annonces_index.php')],
]);

$mbiNavActive = 'home';
$mbiBodyClass = 'mbi-page-home';

include __DIR__ . '/inc/mbi_annonces_header.php';
?>

<section class="mbi-hero" aria-labelledby="mbi-hero-title">
  <div class="mbi-container mbi-hero-inner">
    <h1 id="mbi-hero-title" class="mbi-hero-title">Trouvez votre bien idéal</h1>
    <p class="mbi-hero-subtitle">Le portail immobilier pensé pour particuliers et professionnels.</p>

    <form class="mbi-search" method="get" action="<?= h(app_url('/mbi_annonces_recherche.php')) ?>" role="search" aria-label="Rechercher un bien">
      <div class="mbi-search-field mbi-search-field-loc">
        <label for="mbi-q-loc">Où ?</label>
        <input id="mbi-q-loc" name="ville" type="text" autocomplete="off" placeholder="Ville, code postal, adresse">
      </div>
      <div class="mbi-search-field">
        <label for="mbi-q-tx">Transaction</label>
        <select id="mbi-q-tx" name="transaction">
          <option value="">Tous</option>
          <option value="vente">Achat</option>
          <option value="location">Location</option>
        </select>
      </div>
      <div class="mbi-search-field">
        <label for="mbi-q-prix">Prix max</label>
        <input id="mbi-q-prix" name="prix_max" type="number" inputmode="numeric" placeholder="€" min="0" step="10000">
      </div>
      <div class="mbi-search-field">
        <label for="mbi-q-type">Type de bien</label>
        <select id="mbi-q-type" name="type_bien">
          <option value="">Tous</option>
          <?php foreach ($typesBien as $tb): ?>
            <option value="<?= h((string)$tb['code']) ?>"><?= h((string)$tb['libelle']) ?> (<?= (int)$tb['nb'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mbi-search-actions">
        <button class="mbi-btn mbi-btn-primary mbi-btn-search" type="submit" aria-label="Rechercher">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          Rechercher
        </button>
        <a class="mbi-link mbi-link-more" href="<?= h(app_url('/mbi_annonces_recherche.php')) ?>">Plus de filtres</a>
      </div>
    </form>
  </div>
</section>

<section class="mbi-section">
  <div class="mbi-container">
    <div class="mbi-section-head">
      <h2 class="mbi-section-title">Dernières annonces</h2>
      <a class="mbi-link" href="<?= h(app_url('/mbi_annonces_recherche.php')) ?>">Voir tout →</a>
    </div>

    <?php if (empty($list['items'])): ?>
      <div class="mbi-empty">Aucune annonce diffusée pour le moment.</div>
    <?php else: ?>
      <div class="mbi-grid">
        <?php foreach ($list['items'] as $a): ?>
          <?php
            $href = mbi_annonces_url_detail((int)$a['annonce_id'], (string)($a['annonce_slug'] ?? ''));
            $w = (int)($a['photo_w'] ?? 0); if ($w <= 0) $w = 800;
            $hh = (int)($a['photo_h'] ?? 0); if ($hh <= 0) $hh = 600;
            $imgSrc = $a['photo_url']
                ? app_url('/' . ltrim((string)$a['photo_url'], '/'))
                : app_url('/images/Home.png');
            $alt = (string)($a['photo_alt'] ?? mbi_annonces_h1($a));

            $isNew = false;
            $dml = (string)($a['date_mise_en_ligne'] ?? '');
            if ($dml !== '' && strtotime($dml) !== false) {
              $isNew = (time() - strtotime($dml)) < (7 * 86400);
            }
          ?>
          <a class="mbi-card" href="<?= h($href) ?>">
            <div class="mbi-card-media">
              <img class="mbi-card-img" src="<?= h($imgSrc) ?>" alt="<?= h($alt) ?>" width="<?= $w ?>" height="<?= $hh ?>" loading="lazy" decoding="async">
              <?php if ($isNew): ?><span class="mbi-badge mbi-badge-new">Nouveau</span><?php endif; ?>
              <?php if (!empty($a['exclusivite'])): ?><span class="mbi-badge mbi-badge-excl">Exclusivité</span><?php endif; ?>
            </div>
            <div class="mbi-card-body">
              <div class="mbi-card-top">
                <span class="mbi-card-tx"><?= h(mbi_annonces_tx_label((string)($a['type_transaction'] ?? ''))) ?></span>
                <span class="mbi-card-price"><?= h(mbi_annonces_price_label($a)) ?></span>
              </div>
              <div class="mbi-card-title"><?= h(mbi_annonces_h1($a)) ?></div>
              <div class="mbi-card-meta">
                <span><?= h((string)($a['ville'] ?? '')) ?></span>
                <?php if (!empty($a['surface_habitable'])): ?>
                  <span class="mbi-sep">•</span><span><?= (int)$a['surface_habitable'] ?> m²</span>
                <?php endif; ?>
                <?php if (!empty($a['nb_pieces'])): ?>
                  <span class="mbi-sep">•</span><span><?= (int)$a['nb_pieces'] ?> p.</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($a['nom_agence'])): ?>
                <div class="mbi-card-agence"><?= h((string)$a['nom_agence']) ?></div>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="mbi-section mbi-section-alt">
  <div class="mbi-container mbi-trust">
    <div class="mbi-trust-item">
      <div class="mbi-trust-num"><?= (int)$list['total'] ?></div>
      <div class="mbi-trust-lbl">Annonces actives</div>
    </div>
    <div class="mbi-trust-item">
      <div class="mbi-trust-num"><?= count(mbi_annonces_fetch_agences_with_annonces($pdo)) ?></div>
      <div class="mbi-trust-lbl">Agences partenaires</div>
    </div>
    <div class="mbi-trust-item">
      <div class="mbi-trust-num">100%</div>
      <div class="mbi-trust-lbl">Annonces de pros</div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/inc/mbi_annonces_footer.php'; ?>
