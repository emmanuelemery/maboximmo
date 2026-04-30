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
$list = mbi_annonces_fetch_list($pdo, $filters);

$typesBien = mbi_annonces_fetch_types_bien_with_annonces($pdo);
$agences = mbi_annonces_fetch_agences_with_annonces($pdo);

$qsBase = array_filter([
    'q'           => $filters['q'],
    'ville'       => $filters['ville'],
    'code_postal' => $filters['code_postal'],
    'transaction' => $filters['transaction'],
    'categorie'   => $filters['categorie'],
    'type_bien'   => $filters['type_bien'],
    'prix_min'    => $filters['prix_min'] > 0 ? $filters['prix_min'] : '',
    'prix_max'    => $filters['prix_max'] > 0 ? $filters['prix_max'] : '',
    'surface_min' => $filters['surface_min'] > 0 ? $filters['surface_min'] : '',
    'pieces_min'  => $filters['pieces_min'] > 0 ? $filters['pieces_min'] : '',
    'id_agence'   => $filters['id_agence'] > 0 ? $filters['id_agence'] : '',
    'tri'         => $filters['tri'] !== 'recent' ? $filters['tri'] : '',
], fn($v) => $v !== '' && $v !== 0 && $v !== null);

$baseAction = app_url('/mbi_annonces_recherche.php');
$linkPage = function (int $p) use ($baseAction, $qsBase): string {
    $qs = $qsBase; $qs['page'] = $p;
    return $baseAction . '?' . http_build_query($qs);
};

// Titre / meta dynamiques selon filtres principaux
$titleParts = [];
if ($filters['categorie'] === 'entreprise') {
    $titleParts[] = 'Immobilier d\'entreprise';
    if ($filters['transaction'] === 'vente') $titleParts[] = '— à vendre';
    elseif ($filters['transaction'] === 'location') $titleParts[] = '— à louer';
} else {
    if ($filters['transaction'] === 'vente') $titleParts[] = 'À vendre';
    elseif ($filters['transaction'] === 'location') $titleParts[] = 'À louer';
    else $titleParts[] = 'Annonces immobilières';
}
if ($filters['ville'] !== '') $titleParts[] = 'à ' . ucfirst(mb_strtolower($filters['ville']));
$pageTitle = implode(' ', $titleParts) . ' — MaBoxImmo';

$canonical = mbi_annonces_abs_url($baseAction . ($qsBase ? ('?' . http_build_query($qsBase)) : ''));

$mbiMeta = [
    'title'       => $pageTitle,
    'description' => 'Trouvez votre bien parmi ' . (int)$list['total'] . ' annonces sur MaBoxImmo. Filtres ville, prix, surface, type de bien.',
    'canonical'   => $canonical,
    'image'       => mbi_annonces_abs_url(app_url('/images/Home.png')),
    // page de recherche : indexable en V1, mais on retire si filtres trop spécifiques
    'robots'      => count($qsBase) > 3 ? 'noindex, follow' : 'index, follow',
];

$mbiJsonLd = mbi_annonces_breadcrumb_jsonld([
    ['name' => 'Accueil',    'url' => app_url('/mbi_annonces_index.php')],
    ['name' => 'Recherche',  'url' => $baseAction],
]);

if ($filters['categorie'] === 'entreprise') {
    $mbiNavActive = 'entreprise';
} elseif ($filters['transaction'] === 'vente') {
    $mbiNavActive = 'acheter';
} elseif ($filters['transaction'] === 'location') {
    $mbiNavActive = 'louer';
} else {
    $mbiNavActive = 'home';
}
$mbiBodyClass = 'mbi-page-search';

include __DIR__ . '/inc/mbi_annonces_header.php';
?>

<section class="mbi-search-bar mbi-search-bar-compact">
  <div class="mbi-container">
    <form class="mbi-search mbi-search-compact" method="get" action="<?= h($baseAction) ?>" role="search" aria-label="Rechercher un bien">
      <?php if ($filters['categorie'] !== ''): ?>
        <input type="hidden" name="categorie" value="<?= h($filters['categorie']) ?>">
      <?php endif; ?>
      <div class="mbi-search-field mbi-search-field-loc">
        <label for="mbi-q-loc">Où ?</label>
        <input id="mbi-q-loc" name="ville" type="text" autocomplete="off" placeholder="Ville, code postal" value="<?= h($filters['ville']) ?>">
      </div>
      <div class="mbi-search-field">
        <label for="mbi-q-tx">Transaction</label>
        <select id="mbi-q-tx" name="transaction">
          <option value=""<?= $filters['transaction']==='' ? ' selected':'' ?>>Tous</option>
          <option value="vente"<?= $filters['transaction']==='vente' ? ' selected':'' ?>>Achat</option>
          <option value="location"<?= $filters['transaction']==='location' ? ' selected':'' ?>>Location</option>
        </select>
      </div>
      <div class="mbi-search-field">
        <label for="mbi-q-type">Type</label>
        <select id="mbi-q-type" name="type_bien">
          <option value="">Tous</option>
          <?php foreach ($typesBien as $tb): ?>
            <option value="<?= h((string)$tb['code']) ?>"<?= $filters['type_bien'] === $tb['code'] ? ' selected':'' ?>><?= h((string)$tb['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mbi-search-actions">
        <button class="mbi-btn mbi-btn-primary" type="submit">Rechercher</button>
      </div>
    </form>
  </div>
</section>

<section class="mbi-section">
  <div class="mbi-container mbi-results-layout">

    <aside class="mbi-filters" aria-label="Filtres avancés">
      <form method="get" action="<?= h($baseAction) ?>" id="mbi-filters-form">
        <input type="hidden" name="ville" value="<?= h($filters['ville']) ?>">
        <input type="hidden" name="transaction" value="<?= h($filters['transaction']) ?>">
        <input type="hidden" name="categorie" value="<?= h($filters['categorie']) ?>">
        <input type="hidden" name="type_bien" value="<?= h($filters['type_bien']) ?>">

        <h3 class="mbi-filters-title">Filtres</h3>

        <div class="mbi-filter-group">
          <label>Code postal</label>
          <input name="code_postal" type="text" inputmode="numeric" placeholder="Ex: 69007" value="<?= h($filters['code_postal']) ?>">
        </div>

        <div class="mbi-filter-group">
          <label>Prix min (€)</label>
          <input name="prix_min" type="number" inputmode="numeric" min="0" step="10000" value="<?= $filters['prix_min'] > 0 ? (int)$filters['prix_min'] : '' ?>">
        </div>
        <div class="mbi-filter-group">
          <label>Prix max (€)</label>
          <input name="prix_max" type="number" inputmode="numeric" min="0" step="10000" value="<?= $filters['prix_max'] > 0 ? (int)$filters['prix_max'] : '' ?>">
        </div>

        <div class="mbi-filter-group">
          <label>Surface min (m²)</label>
          <input name="surface_min" type="number" inputmode="numeric" min="0" step="5" value="<?= $filters['surface_min'] > 0 ? (int)$filters['surface_min'] : '' ?>">
        </div>

        <div class="mbi-filter-group">
          <label>Pièces min</label>
          <input name="pieces_min" type="number" inputmode="numeric" min="0" step="1" value="<?= $filters['pieces_min'] > 0 ? (int)$filters['pieces_min'] : '' ?>">
        </div>

        <?php if ($agences): ?>
        <div class="mbi-filter-group">
          <label>Agence</label>
          <select name="id_agence">
            <option value="">Toutes les agences</option>
            <?php foreach ($agences as $ag): ?>
              <option value="<?= (int)$ag['id'] ?>"<?= $filters['id_agence'] === (int)$ag['id'] ? ' selected':'' ?>>
                <?= h((string)$ag['nom_agence']) ?> (<?= (int)$ag['nb'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <button class="mbi-btn mbi-btn-primary mbi-btn-block" type="submit">Appliquer</button>
        <a class="mbi-btn mbi-btn-ghost mbi-btn-block" href="<?= h($baseAction) ?>">Réinitialiser</a>
      </form>
    </aside>

    <div class="mbi-results">
      <div class="mbi-results-head">
        <div class="mbi-results-count"><strong><?= (int)$list['total'] ?></strong> bien<?= $list['total'] > 1 ? 's' : '' ?> trouvé<?= $list['total'] > 1 ? 's' : '' ?></div>
        <form class="mbi-sort" method="get" action="<?= h($baseAction) ?>">
          <?php foreach ($qsBase as $k => $v): if ($k === 'tri') continue; ?>
            <input type="hidden" name="<?= h((string)$k) ?>" value="<?= h((string)$v) ?>">
          <?php endforeach; ?>
          <label for="mbi-sort">Trier :</label>
          <select id="mbi-sort" name="tri" onchange="this.form.submit()">
            <option value="recent"<?= $filters['tri']==='recent' ? ' selected':'' ?>>Plus récentes</option>
            <option value="prix_asc"<?= $filters['tri']==='prix_asc' ? ' selected':'' ?>>Prix croissant</option>
            <option value="prix_desc"<?= $filters['tri']==='prix_desc' ? ' selected':'' ?>>Prix décroissant</option>
            <option value="surface_desc"<?= $filters['tri']==='surface_desc' ? ' selected':'' ?>>Plus grande surface</option>
          </select>
        </form>
      </div>

      <?php if (empty($list['items'])): ?>
        <div class="mbi-empty">
          <p>Aucun bien ne correspond à votre recherche.</p>
          <a class="mbi-btn mbi-btn-ghost" href="<?= h($baseAction) ?>">Réinitialiser les filtres</a>
        </div>
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

        <?php if ((int)$list['total_pages'] > 1): ?>
          <nav class="mbi-pagination" aria-label="Pagination">
            <?php
              $cur = (int)$list['page'];
              $tp = (int)$list['total_pages'];
            ?>
            <a class="mbi-page <?= $cur <= 1 ? 'is-disabled' : '' ?>" href="<?= h($cur <= 1 ? '#' : $linkPage($cur - 1)) ?>" aria-label="Page précédente">‹ Précédent</a>
            <span class="mbi-page-info">Page <?= $cur ?> / <?= $tp ?></span>
            <a class="mbi-page <?= $cur >= $tp ? 'is-disabled' : '' ?>" href="<?= h($cur >= $tp ? '#' : $linkPage($cur + 1)) ?>" aria-label="Page suivante">Suivant ›</a>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</section>

<?php include __DIR__ . '/inc/mbi_annonces_footer.php'; ?>
