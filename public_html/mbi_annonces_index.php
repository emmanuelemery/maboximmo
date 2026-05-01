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

/**
 * Construit une URL avec les filtres courants + overrides.
 * Si la valeur d'override est égale à la valeur courante → toggle off (retire le filtre).
 * Si "_unset" est dans overrides → retire ce filtre quoi qu'il arrive.
 */
function mbi_filter_url(array $filters, array $overrides): string
{
    $keep = ['transaction', 'type_bien', 'prix_min', 'prix_max', 'code_postal', 'ville', 'q', 'tri'];
    $params = [];
    foreach ($keep as $k) {
        $v = $filters[$k] ?? '';
        if ($v !== '' && $v !== 0 && $v !== '0') $params[$k] = $v;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return '?' . http_build_query($params);
}

/** Vérifie qu'une valeur est active dans les filtres courants. */
function mbi_filter_active(array $filters, string $name, $value): bool
{
    return (string)($filters[$name] ?? '') === (string)$value;
}

/** Vérifie qu'une plage prix_min/prix_max correspond aux filtres courants. */
function mbi_filter_range_active(array $filters, int $min, int $max): bool
{
    return ((int)($filters['prix_min'] ?? 0) === $min) && ((int)($filters['prix_max'] ?? 0) === $max);
}

$anyFilterActive = $filters['transaction'] !== ''
    || $filters['type_bien']   !== ''
    || $filters['code_postal'] !== ''
    || $filters['ville']       !== ''
    || (int)$filters['prix_min'] > 0
    || (int)$filters['prix_max'] > 0;

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
    <p class="mbi-hero-subtitle">Cliquez pour filtrer instantanément — pas besoin de "Rechercher".</p>
  </div>
</section>

<section class="mbi-filters" aria-label="Filtres">
  <div class="mbi-container">

    <!-- ── Transaction ── -->
    <div class="mbi-fgrp">
      <h3 class="mbi-flabel">Transaction</h3>
      <div class="mbi-fbtns mbi-cols-2">
        <a class="mbi-fbtn <?= mbi_filter_active($filters, 'transaction', 'vente') ? 'active' : '' ?>"
           href="<?= h(mbi_filter_url($filters, ['transaction' => mbi_filter_active($filters, 'transaction', 'vente') ? null : 'vente'])) ?>">
          <span class="mbi-fbtn-ic">🏷️</span><span>Acheter</span>
        </a>
        <a class="mbi-fbtn <?= mbi_filter_active($filters, 'transaction', 'location') ? 'active' : '' ?>"
           href="<?= h(mbi_filter_url($filters, ['transaction' => mbi_filter_active($filters, 'transaction', 'location') ? null : 'location'])) ?>">
          <span class="mbi-fbtn-ic">🔑</span><span>Louer</span>
        </a>
      </div>
    </div>

    <!-- ── Type de bien ── -->
    <div class="mbi-fgrp">
      <h3 class="mbi-flabel">Type de bien</h3>
      <div class="mbi-fbtns mbi-cols-3">
        <?php
          $typesList = [
            ['code' => 'appartement',     'icon' => '🏢', 'label' => 'Appartement'],
            ['code' => 'maison',          'icon' => '🏠', 'label' => 'Maison'],
            ['code' => 'local_commercial','icon' => '🏪', 'label' => 'Local commercial'],
            ['code' => 'entrepot',        'icon' => '🏭', 'label' => 'Entrepôt'],
            ['code' => 'parking',         'icon' => '🅿️', 'label' => 'Parking'],
            ['code' => 'terrain',         'icon' => '🌳', 'label' => 'Terrain'],
          ];
          foreach ($typesList as $t):
            $isActive = mbi_filter_active($filters, 'type_bien', $t['code']);
        ?>
          <a class="mbi-fbtn <?= $isActive ? 'active' : '' ?>"
             href="<?= h(mbi_filter_url($filters, ['type_bien' => $isActive ? null : $t['code']])) ?>">
            <span class="mbi-fbtn-ic"><?= $t['icon'] ?></span><span><?= h($t['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Prix de vente (visible si transaction != location) ── -->
    <?php if ($filters['transaction'] !== 'location'): ?>
    <div class="mbi-fgrp">
      <h3 class="mbi-flabel">Prix de vente</h3>
      <div class="mbi-fbtns mbi-cols-4">
        <?php
          $venteRanges = [
            [0,      50000,  '< 50 000 €'],
            [50000,  100000, '50 - 100 K€'],
            [100000, 150000, '100 - 150 K€'],
            [150000, 200000, '150 - 200 K€'],
            [200000, 250000, '200 - 250 K€'],
            [250000, 300000, '250 - 300 K€'],
            [300000, 0,      '+ 300 000 €'],
          ];
          foreach ($venteRanges as [$min, $max, $label]):
            $isActive = mbi_filter_range_active($filters, $min, $max);
            $url = mbi_filter_url($filters, $isActive
                ? ['prix_min' => null, 'prix_max' => null]
                : ['prix_min' => $min ?: null, 'prix_max' => $max ?: null]);
        ?>
          <a class="mbi-fbtn <?= $isActive ? 'active' : '' ?>" href="<?= h($url) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Prix de location (visible si transaction != vente) ── -->
    <?php if ($filters['transaction'] !== 'vente'): ?>
    <div class="mbi-fgrp">
      <h3 class="mbi-flabel">Prix de location <small style="color:#6b7280; font-weight:400">(par mois)</small></h3>
      <div class="mbi-fbtns mbi-cols-3">
        <?php
          $locRanges = [
            [50,    250,   '50 - 250 €'],
            [250,   450,   '250 - 450 €'],
            [450,   650,   '450 - 650 €'],
            [650,   800,   '650 - 800 €'],
            [800,   1000,  '800 - 1 000 €'],
            [1000,  0,     '+ 1 000 €'],
          ];
          foreach ($locRanges as [$min, $max, $label]):
            $isActive = mbi_filter_range_active($filters, $min, $max);
            $url = mbi_filter_url($filters, $isActive
                ? ['prix_min' => null, 'prix_max' => null]
                : ['prix_min' => $min ?: null, 'prix_max' => $max ?: null]);
        ?>
          <a class="mbi-fbtn <?= $isActive ? 'active' : '' ?>" href="<?= h($url) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Département ── -->
    <div class="mbi-fgrp">
      <h3 class="mbi-flabel">Département</h3>
      <div class="mbi-fbtns mbi-cols-3">
        <?php
          $depts = [
            ['cp' => '69', 'label' => 'Rhône (69)'],
            ['cp' => '42', 'label' => 'Loire (42)'],
            ['cp' => '38', 'label' => 'Isère (38)'],
            ['cp' => '63', 'label' => 'Puy-de-Dôme (63)'],
            ['cp' => '43', 'label' => 'Haute-Loire (43)'],
            ['cp' => '03', 'label' => 'Allier (03)'],
          ];
          foreach ($depts as $d):
            $isActive = mbi_filter_active($filters, 'code_postal', $d['cp']);
        ?>
          <a class="mbi-fbtn <?= $isActive ? 'active' : '' ?>"
             href="<?= h(mbi_filter_url($filters, ['code_postal' => $isActive ? null : $d['cp']])) ?>">
            <?= h($d['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($anyFilterActive): ?>
    <div class="mbi-fgrp" style="text-align:center; padding-top:8px">
      <a class="mbi-flink-reset" href="<?= h(app_url('/mbi_annonces_index.php')) ?>">↺ Tout effacer les filtres</a>
    </div>
    <?php endif; ?>

  </div>
</section>

<section class="mbi-section">
  <div class="mbi-container">
    <div class="mbi-section-head">
      <h2 class="mbi-section-title">Dernières annonces</h2>
      <a class="mbi-link" href="<?= h(app_url('/mbi_annonces_recherche.php')) ?>">Voir tout →</a>
    </div>

    <?php if (empty($list['items'])): ?>
      <div class="mbi-empty">
        Aucune annonce diffusée pour le moment.
        <?php if (defined('APP_DEBUG') && APP_DEBUG && !empty($list['debug_error'])): ?>
          <pre style="margin-top:20px; padding:16px; background:#fee2e2; color:#991b1b; border-radius:8px; text-align:left; font-size:12px; overflow:auto;">
[DEBUG SQL ERROR]
<?= htmlspecialchars($list['debug_error']) ?>
          </pre>
        <?php endif; ?>
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
