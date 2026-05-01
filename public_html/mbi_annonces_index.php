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

<?php
  // Détermine les ranges de prix selon transaction (vente par défaut si pas choisi)
  $isLocation = $filters['transaction'] === 'location';
  $venteRanges = [
    ['min' => 0,      'max' => 50000,  'label' => '0 à 50 000 €'],
    ['min' => 50000,  'max' => 100000, 'label' => '50 000 à 100 000 €'],
    ['min' => 100000, 'max' => 150000, 'label' => '100 000 à 150 000 €'],
    ['min' => 150000, 'max' => 200000, 'label' => '150 000 à 200 000 €'],
    ['min' => 200000, 'max' => 250000, 'label' => '200 000 à 250 000 €'],
    ['min' => 250000, 'max' => 300000, 'label' => '250 000 à 300 000 €'],
    ['min' => 300000, 'max' => 0,      'label' => '+ de 300 000 €'],
  ];
  $locationRanges = [
    ['min' => 50,   'max' => 250,  'label' => '50 à 250 €'],
    ['min' => 250,  'max' => 450,  'label' => '250 à 450 €'],
    ['min' => 450,  'max' => 650,  'label' => '450 à 650 €'],
    ['min' => 650,  'max' => 800,  'label' => '650 à 800 €'],
    ['min' => 800,  'max' => 1000, 'label' => '800 à 1000 €'],
    ['min' => 1000, 'max' => 0,    'label' => '+ de 1000 €'],
  ];
  $priceRanges = $isLocation ? $locationRanges : $venteRanges;

  // Index courant (-1 si aucune range sélectionnée)
  $currentPriceIdx = -1;
  foreach ($priceRanges as $i => $r) {
    if ((int)$filters['prix_min'] === $r['min'] && (int)$filters['prix_max'] === $r['max']) {
      $currentPriceIdx = $i;
      break;
    }
  }
  $currentPriceLabel = $currentPriceIdx >= 0 ? $priceRanges[$currentPriceIdx]['label'] : 'Tous les budgets';

  // Index pour les flèches up/down
  $prevIdx = max(0, ($currentPriceIdx <= 0 ? 0 : $currentPriceIdx - 1));
  $nextIdx = min(count($priceRanges) - 1, ($currentPriceIdx < 0 ? 0 : $currentPriceIdx + 1));
  $prevRange = $priceRanges[$prevIdx];
  $nextRange = $priceRanges[$nextIdx];

  $priceUpUrl = mbi_filter_url($filters, [
    'prix_min' => $prevRange['min'] ?: null,
    'prix_max' => $prevRange['max'] ?: null,
  ]);
  $priceDownUrl = mbi_filter_url($filters, [
    'prix_min' => $nextRange['min'] ?: null,
    'prix_max' => $nextRange['max'] ?: null,
  ]);

  // 8 dernières annonces pour la barre miniatures
  $miniList = mbi_annonces_fetch_list($pdo, array_merge($filters, ['per_page' => 8, 'page' => 1]));

  // Modules bar : visible uniquement aux pros loggés
  $isLogged = !empty($_SESSION['user_id'] ?? null);
?>

<section class="mbi-hero2" aria-labelledby="mbi-hero-title">
  <div class="mbi-hero2-inner">

    <h1 id="mbi-hero-title" class="mbi-hero2-title">Trouvez votre bien idéal</h1>
    <p class="mbi-hero2-sub">Cliquez pour filtrer instantanément</p>
    <div class="mbi-hero2-line"></div>

    <!-- ═══ ZONE FILTRES — 4 glass cards alignées ═══ -->
    <div class="mbi-search-area">

      <!-- ── Bloc 1 — Transaction (1 col × 2 rows) ── -->
      <div class="mbi-glass-card">
        <div class="mbi-fcard-title">↔ Transaction</div>
        <div class="mbi-fcard-grid mbi-fcard-grid-1col">
          <a class="mbi-fcardbtn <?= mbi_filter_active($filters, 'transaction', 'location') ? 'active' : '' ?>"
             href="<?= h(mbi_filter_url($filters, ['transaction' => mbi_filter_active($filters, 'transaction', 'location') ? null : 'location'])) ?>">
            <span class="mbi-fcardbtn-ic">🔑</span>LOCATION
          </a>
          <a class="mbi-fcardbtn <?= mbi_filter_active($filters, 'transaction', 'vente') ? 'active' : '' ?>"
             href="<?= h(mbi_filter_url($filters, ['transaction' => mbi_filter_active($filters, 'transaction', 'vente') ? null : 'vente'])) ?>">
            <span class="mbi-fcardbtn-ic">🏠</span>VENTE
          </a>
        </div>
      </div>

      <!-- ── Bloc 2 — Type de bien (3 cols × 2 rows) ── -->
      <div class="mbi-glass-card">
        <div class="mbi-fcard-title">🏢 Type de bien</div>
        <div class="mbi-fcard-grid mbi-fcard-grid-3col">
          <?php
            $typesList = [
              ['code' => 'appartement',     'icon' => '🏢', 'label' => 'Appartement'],
              ['code' => 'maison',          'icon' => '🏡', 'label' => 'Maison'],
              ['code' => 'local_commercial','icon' => '🏪', 'label' => 'Local commercial'],
              ['code' => 'entrepot',        'icon' => '🏭', 'label' => 'Entrepôt'],
              ['code' => 'parking',         'icon' => '🅿️', 'label' => 'Parking'],
              ['code' => 'terrain',         'icon' => '🌳', 'label' => 'Terrain'],
            ];
            foreach ($typesList as $t):
              $isActive = mbi_filter_active($filters, 'type_bien', $t['code']);
          ?>
            <a class="mbi-fcardbtn <?= $isActive ? 'active' : '' ?>"
               href="<?= h(mbi_filter_url($filters, ['type_bien' => $isActive ? null : $t['code']])) ?>">
              <span class="mbi-fcardbtn-ic"><?= $t['icon'] ?></span><?= h($t['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ── Bloc 3 — Département (3 cols × 2 rows) ── -->
      <div class="mbi-glass-card">
        <div class="mbi-fcard-title">📍 Département</div>
        <div class="mbi-fcard-grid mbi-fcard-grid-3col">
          <?php
            $depts = [
              ['cp' => '69', 'label' => 'Rhône'],
              ['cp' => '42', 'label' => 'Loire'],
              ['cp' => '38', 'label' => 'Isère'],
              ['cp' => '63', 'label' => 'Puy-de-Dôme'],
              ['cp' => '43', 'label' => 'Haute-Loire'],
              ['cp' => '03', 'label' => 'Allier'],
            ];
            foreach ($depts as $d):
              $isActive = mbi_filter_active($filters, 'code_postal', $d['cp']);
          ?>
            <a class="mbi-fcardbtn mbi-fcardbtn-dept <?= $isActive ? 'active' : '' ?>"
               href="<?= h(mbi_filter_url($filters, ['code_postal' => $isActive ? null : $d['cp']])) ?>">
              <?= h($d['label']) ?>
              <span class="mbi-fcardbtn-cp"><?= h($d['cp']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ── Bloc 4 — Budget (price card avec flèches) ── -->
      <div class="mbi-glass-card mbi-price-card">
        <div class="mbi-fcard-title">Budget <small>(<?= $isLocation ? '€/mois' : '€' ?>)</small></div>
        <div class="mbi-price-box">
          <a class="mbi-price-arrow" href="<?= h($priceUpUrl) ?>" aria-label="Budget précédent">⌃</a>
          <div class="mbi-price-value"><?= h($currentPriceLabel) ?></div>
          <a class="mbi-price-arrow" href="<?= h($priceDownUrl) ?>" aria-label="Budget suivant">⌄</a>
        </div>
        <div class="mbi-price-chip"><?= h($currentPriceLabel) ?></div>
        <div class="mbi-price-sublabel">Sélection actuelle</div>
      </div>

    </div>

    <?php if ($anyFilterActive): ?>
      <a class="mbi-clear-btn" href="<?= h(app_url('/mbi_annonces_index.php')) ?>">↻ Tout effacer</a>
    <?php endif; ?>

    <!-- ═══ MINI BARRE — 8 annonces avec flèches ═══ -->
    <?php if (!empty($miniList['items'])): ?>
    <div class="mbi-mini-bar">
      <button class="mbi-mini-arrow" type="button" aria-label="Précédent" onclick="document.getElementById('mbi-mini-listings').scrollBy({left:-320,behavior:'smooth'})">‹</button>
      <div class="mbi-mini-listings" id="mbi-mini-listings">
        <?php foreach (array_slice($miniList['items'], 0, 8) as $a):
          $href = mbi_annonces_url_detail((int)$a['annonce_id'], (string)($a['annonce_slug'] ?? ''));
          $imgSrc = !empty($a['photo_url'])
            ? app_url('/' . ltrim((string)$a['photo_url'], '/'))
            : app_url('/images/Home.png');
          $isNew = false;
          $dml = (string)($a['date_mise_en_ligne'] ?? '');
          if ($dml !== '' && strtotime($dml) !== false) {
            $isNew = (time() - strtotime($dml)) < (7 * 86400);
          }
          $typeLabel = (string)($a['type_bien_libelle'] ?? 'Bien');
          $priceLabel = mbi_annonces_price_label($a);
        ?>
          <a class="mbi-mini-prop" href="<?= h($href) ?>">
            <span class="mbi-mini-badge"><?= !empty($a['exclusivite']) ? 'EXCLUSIVITÉ' : ($isNew ? 'NOUVEAU' : '') ?></span>
            <img src="<?= h($imgSrc) ?>" alt="<?= h($typeLabel) ?>" loading="lazy">
            <div class="mbi-mini-info">
              <span class="mbi-mini-type"><?= h(mb_strtoupper(mb_substr($typeLabel, 0, 18))) ?></span>
              <strong class="mbi-mini-price"><?= h($priceLabel) ?></strong>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <button class="mbi-mini-arrow" type="button" aria-label="Suivant" onclick="document.getElementById('mbi-mini-listings').scrollBy({left:320,behavior:'smooth'})">›</button>
    </div>
    <?php endif; ?>

    <!-- ═══ MODULES BAR (logged-in pros only) ═══ -->
    <?php if ($isLogged): ?>
    <div class="mbi-modules-bar">
      <a class="mbi-module-btn" href="<?= h(app_url('/rh_dashboard.php')) ?>">
        <span>👥</span> Ressources humaines
      </a>
      <a class="mbi-module-btn" href="<?= h(app_url('/agency_dashboard.php')) ?>">
        <span>🏢</span> Agency
      </a>
      <a class="mbi-module-btn" href="<?= h(app_url('/agency_dashboard_diffusion.php')) ?>">
        <span>📣</span> Diffuser annonces
      </a>
      <a class="mbi-module-btn" href="<?= h(app_url('/modules/ged/ged_dashboard.php')) ?>">
        <span>📁</span> GED
      </a>
      <a class="mbi-module-btn" href="<?= h(app_url('/mail_dashboard.php')) ?>">
        <span>✉️</span> Mail Box
      </a>
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
