<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/vitrine_helpers.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$agenceSlug = trim((string)($_GET['agence'] ?? ''));
$route = trim((string)($_GET['route'] ?? ''));

// Normalisation route vide => home
if ($route === '') $route = 'home';

$agence = vitrine_fetch_agence_by_slug($pdo, $agenceSlug);
if (!$agence) {
    http_response_code(404);
    exit('Agence introuvable.');
}

$baseUrl = vitrine_base_url();
$homePath = vitrine_path((string)$agence['slug'], '/');
$homeUrl = vitrine_url($homePath);

// Image OG : logo agence si dispo, sinon image par défaut du projet
$ogImg = '';
if (!empty($agence['logo_url'])) {
    $ogImg = vitrine_url(app_url('/' . ltrim((string)$agence['logo_url'], '/')));
} else {
    $ogImg = vitrine_url(app_url('/images/Home.png'));
}

// ──────────────────────────────────────────────────────────────
// ROUTE: SITEMAP
// ──────────────────────────────────────────────────────────────
if ($route === 'sitemap') {
    header('Content-Type: application/xml; charset=UTF-8');
    $today = date('Y-m-d');

    $url = static fn(string $p): string => vitrine_url($p);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
    echo 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

    $static = [
        ['loc' => vitrine_path($agenceSlug, '/'), 'freq' => 'weekly', 'priority' => '0.9'],
        ['loc' => vitrine_path($agenceSlug, '/annonces'), 'freq' => 'daily', 'priority' => '0.9'],
        ['loc' => vitrine_path($agenceSlug, '/transaction-immobiliere'), 'freq' => 'monthly', 'priority' => '0.7'],
        ['loc' => vitrine_path($agenceSlug, '/gestion-locative'), 'freq' => 'monthly', 'priority' => '0.7'],
        ['loc' => vitrine_path($agenceSlug, '/syndic-copropriete'), 'freq' => 'monthly', 'priority' => '0.6'],
        ['loc' => vitrine_path($agenceSlug, '/estimation-gratuite'), 'freq' => 'monthly', 'priority' => '0.8'],
    ];
    foreach ($static as $p) {
        echo '<url><loc>' . h($url($p['loc'])) . '</loc><lastmod>' . $today . '</lastmod>';
        echo '<changefreq>' . h($p['freq']) . '</changefreq><priority>' . h($p['priority']) . '</priority></url>';
    }

    // Annonces actives de l'agence
    try {
        $statuses = vitrine_active_annonce_statuses();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $params = [$agence['id']];
        array_push($params, ...$statuses);

        $st = $pdo->prepare("
            SELECT a.id, a.slug, a.date_modification
            FROM annonces a
            INNER JOIN biens b ON b.id = a.id_bien
            WHERE a.id_agence = ? AND a.statut IN ($in) AND b.statut_bien IN ('actif','publie')
            ORDER BY a.date_modification DESC, a.id DESC
            LIMIT 5000
        ");
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;
            $slugA = (string)($r['slug'] ?? '');
            $loc = vitrine_annonce_url($agenceSlug, $id, $slugA);
            $date = substr((string)($r['date_modification'] ?? $today), 0, 10);

            echo '<url><loc>' . h($url($loc)) . '</loc><lastmod>' . h($date) . '</lastmod>';
            echo '<changefreq>weekly</changefreq><priority>0.8</priority>';

            // Image principale (SEO images)
            try {
                $sp = $pdo->prepare("
                    SELECT COALESCE(url_webp, url_photo) AS u, caption
                    FROM annonces_photos
                    WHERE id_annonce = ? AND variante IN ('medium','original')
                    ORDER BY (variante='medium') DESC, principale DESC, ordre_affichage ASC, id ASC
                    LIMIT 1
                ");
                $sp->execute([$id]);
                $p = $sp->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($p && !empty($p['u'])) {
                    echo '<image:image><image:loc>' . h($url(app_url('/' . ltrim((string)$p['u'], '/')))) . '</image:loc>';
                    if (!empty($p['caption'])) {
                        echo '<image:caption>' . h((string)$p['caption']) . '</image:caption>';
                    }
                    echo '</image:image>';
                }
            } catch (Throwable) {}

            echo '</url>';
        }
    } catch (Throwable) {}

    echo '</urlset>';
    exit;
}

// ──────────────────────────────────────────────────────────────
// ROUTE: ESTIMATION (form)
// ──────────────────────────────────────────────────────────────
$flashOk = '';
$flashErr = '';
$leadPrefill = [
    'nom' => '',
    'email' => '',
    'telephone' => '',
    'ville' => '',
    'type' => 'estimation',
    'message' => '',
];
if (is_post() && $route === 'estimation-gratuite') {
    RateLimiter::check('vitrine_lead', 20, 600);
    verify_csrf('vitrine_lead');

    $honeypot = trim((string)post('website', ''));
    if ($honeypot !== '') {
        // Bot probable : on répond OK sans enregistrer.
        redirect('/vitrine/' . rawurlencode($agenceSlug) . '/estimation-gratuite?ok=1');
    }

    $nom = trim((string)post('nom', ''));
    $email = trim((string)post('email', ''));
    $telephone = trim((string)post('telephone', ''));
    $ville = trim((string)post('ville', ''));
    $type = trim((string)post('type', 'estimation'));
    $message = trim((string)post('message', ''));

    $leadPrefill = [
        'nom' => $nom,
        'email' => $email,
        'telephone' => $telephone,
        'ville' => $ville,
        'type' => $type,
        'message' => $message,
    ];

    if ($nom === '' || $email === '') {
        $flashErr = 'Merci de renseigner votre nom et votre email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashErr = 'Email invalide.';
    } elseif (!in_array($type, ['estimation', 'vente', 'location', 'gestion'], true)) {
        $flashErr = 'Demande invalide.';
    } else {
        $ok = vitrine_insert_lead($pdo, [
            'id_societe' => $agence['id_societe'] ?? null,
            'id_agence' => $agence['id'] ?? null,
            'type' => $type,
            'nom' => $nom,
            'email' => $email,
            'telephone' => $telephone !== '' ? $telephone : null,
            'ville' => $ville !== '' ? $ville : null,
            'message' => $message !== '' ? $message : null,
            'source_url' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'ip' => client_ip(),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);

        if ($ok) {
            redirect('/vitrine/' . rawurlencode($agenceSlug) . '/estimation-gratuite?ok=1');
        } else {
            $flashErr = "Votre demande n'a pas pu être enregistrée (migration SQL manquante : vitrine_leads).";
        }
    }
}
if ($route === 'estimation-gratuite' && get('ok')) {
    $flashOk = 'Merci ! Votre demande a bien été envoyée. Nous vous recontactons rapidement.';
}

// ──────────────────────────────────────────────────────────────
// RENDER PAGES
// ──────────────────────────────────────────────────────────────
$pageTitle = '';
$pageDescription = '';
$pageCanonical = '';
$navActive = $route === 'home' ? 'home' : $route;

$crumbs = [
    ['name' => (string)($agence['nom_agence'] ?? 'Accueil'), 'item' => vitrine_path($agenceSlug, '/')],
];

if ($navActive === 'annonce') $navActive = 'annonces';
if ($navActive === 'sitemap') $navActive = 'home';

if ($route === 'home') {
    $pageTitle = (string)($agence['nom_agence'] ?? 'Agence') . ' — Immobilier à ' . (string)($agence['ville'] ?? '');
    $pageDescription = 'Découvrez nos annonces actives (vente et location) et confiez-nous votre projet : estimation gratuite, vente, location et gestion locative.';
    $pageCanonical = vitrine_url(vitrine_path($agenceSlug, '/'));

    $list = vitrine_fetch_annonces_actives($pdo, (int)$agence['id'], ['page' => 1, 'per_page' => 9]);
    $annonces = $list['items'];

    $jsonld = vitrine_jsonld_agence($agence, $baseUrl, $pageCanonical)
        . vitrine_breadcrumb_jsonld($crumbs, $baseUrl);

    $vMeta = ['title' => $pageTitle, 'description' => $pageDescription, 'canonical' => $pageCanonical, 'image' => $ogImg];
    $vJsonLd = $jsonld;
    $agenceForLayout = $agence;
    $agence = $agenceForLayout;
    include __DIR__ . '/../inc/vitrine_header.php';
    ?>

    <section class="v-hero">
      <div class="v-hero-grid">
        <div>
          <div class="v-kicker">Votre tiers de confiance</div>
          <h1><?= h((string)($agence['nom_agence'] ?? 'Agence')) ?> — Immobilier à <?= h((string)($agence['ville'] ?? '')) ?></h1>
          <p class="v-lead">Vente, location, gestion locative et accompagnement complet. Transparence, sécurité et suivi pro à chaque étape.</p>
          <div class="v-hero-actions">
            <a class="v-btn v-btn-primary" href="<?= h(vitrine_path($agenceSlug, '/annonces')) ?>">Voir les annonces actives</a>
            <a class="v-btn v-btn-ghost" href="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">Estimation gratuite</a>
          </div>
          <div class="v-trust">
            <div class="v-trust-item"><span class="v-dot"></span> Sélection & qualification des dossiers</div>
            <div class="v-trust-item"><span class="v-dot"></span> Valorisation & diffusion maîtrisée</div>
            <div class="v-trust-item"><span class="v-dot"></span> Reporting clair, décisions tracées</div>
          </div>
        </div>
        <div class="v-hero-card">
          <div class="v-hero-card-title">Parlons de votre bien</div>
          <div class="v-hero-card-text">Estimation gratuite et plan d’action (vente, location ou gestion) sous 24–48h.</div>
          <a class="v-btn v-btn-primary v-btn-block" href="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">Demander une estimation</a>
          <?php if (!empty($agence['telephone'])): ?>
            <a class="v-btn v-btn-ghost v-btn-block" href="tel:<?= h(preg_replace('/\\s+/', '', (string)$agence['telephone']) ?? (string)$agence['telephone']) ?>">Appeler : <?= h((string)$agence['telephone']) ?></a>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="v-section">
      <div class="v-section-head">
        <h2>Annonces actives</h2>
        <a class="v-link" href="<?= h(vitrine_path($agenceSlug, '/annonces')) ?>">Tout voir</a>
      </div>
      <?php if (!$annonces): ?>
        <div class="v-empty">Aucune annonce active pour le moment.</div>
      <?php else: ?>
        <div class="v-grid">
          <?php foreach ($annonces as $a): ?>
            <?php
              $href = vitrine_annonce_url($agenceSlug, (int)$a['annonce_id'], (string)($a['annonce_slug'] ?? ''));
              $img = (string)($a['photo_url'] ?? '');
              $imgSrc = $img !== '' ? app_url('/' . ltrim($img, '/')) : app_url('/images/Home.png');
              $alt = (string)($a['photo_alt'] ?? vitrine_annonce_h1($a));
              $w = (int)($a['photo_w'] ?? 0);
              $h = (int)($a['photo_h'] ?? 0);
              if ($w <= 0) $w = 800;
              if ($h <= 0) $h = 600;
            ?>
            <a class="v-card" href="<?= h($href) ?>">
              <img class="v-card-img" src="<?= h($imgSrc) ?>" alt="<?= h($alt) ?>" width="<?= (int)$w ?>" height="<?= (int)$h ?>" loading="lazy" decoding="async">
              <div class="v-card-body">
                <div class="v-card-top">
                  <div class="v-badge"><?= h(vitrine_tx_label((string)($a['type_transaction'] ?? ''))) ?></div>
                  <div class="v-price"><?= h(vitrine_price_label($a)) ?></div>
                </div>
                <div class="v-card-title"><?= h(vitrine_annonce_h1($a)) ?></div>
                <div class="v-card-meta">
                  <span><?= h((string)($a['ville'] ?? '')) ?></span>
                  <span class="v-sep">•</span>
                  <span><?= h((string)($a['surface_habitable'] ?? '') !== '' ? ((int)$a['surface_habitable'] . ' m²') : '') ?></span>
                  <?php if (!empty($a['nb_pieces'])): ?><span class="v-sep">•</span><span><?= (int)$a['nb_pieces'] ?> p.</span><?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="v-section v-services">
      <h2>Nos activités</h2>
      <div class="v-grid v-grid-3">
        <a class="v-tile" href="<?= h(vitrine_path($agenceSlug, '/transaction-immobiliere')) ?>">
          <div class="v-tile-title">Transaction</div>
          <div class="v-tile-text">Vendre, acheter, louer : stratégie, diffusion et négociation.</div>
        </a>
        <a class="v-tile" href="<?= h(vitrine_path($agenceSlug, '/gestion-locative')) ?>">
          <div class="v-tile-title">Gestion locative</div>
          <div class="v-tile-text">Sécurisez vos loyers et déléguez la gestion au quotidien.</div>
        </a>
        <a class="v-tile" href="<?= h(vitrine_path($agenceSlug, '/syndic-copropriete')) ?>">
          <div class="v-tile-title">Syndic</div>
          <div class="v-tile-text">Suivi de copropriété : transparence, budget, travaux, AG.</div>
        </a>
      </div>
    </section>

    <?php
    include __DIR__ . '/../inc/vitrine_footer.php';
    exit;
}

if ($route === 'annonces') {
    $tx = trim((string)get('tx', ''));
    $q = trim((string)get('q', ''));
    $page = max(1, (int)get('page', 1));

    $list = vitrine_fetch_annonces_actives($pdo, (int)$agence['id'], [
        'page' => $page,
        'per_page' => 12,
        'transaction' => $tx,
        'q' => $q,
    ]);

    $pageTitle = 'Annonces immobilières — ' . (string)($agence['nom_agence'] ?? 'Agence');
    $pageDescription = 'Toutes nos annonces actives (vente et location) : photos, prix, surfaces, localisation. Mise à jour en temps réel.';
    $pageCanonical = vitrine_url(vitrine_path($agenceSlug, '/annonces'));

    $crumbs[] = ['name' => 'Annonces', 'item' => vitrine_path($agenceSlug, '/annonces')];
    $jsonld = vitrine_jsonld_agence($agence, $baseUrl, $homeUrl)
        . vitrine_breadcrumb_jsonld($crumbs, $baseUrl);

    $vMeta = ['title' => $pageTitle, 'description' => $pageDescription, 'canonical' => $pageCanonical, 'image' => $ogImg];
    $vJsonLd = $jsonld;
    include __DIR__ . '/../inc/vitrine_header.php';
    ?>

    <section class="v-section">
      <div class="v-section-head">
        <h1>Annonces actives</h1>
        <div class="v-muted"><?= (int)$list['total'] ?> bien(s)</div>
      </div>

      <div class="v-filters">
        <?php
          $base = vitrine_path($agenceSlug, '/annonces');
          $mk = static function(array $p) use ($base): string {
              $qs = http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
              return $qs ? ($base . '?' . $qs) : $base;
          };
        ?>
        <a class="v-chip <?= $list['transaction'] === '' ? 'is-active' : '' ?>" href="<?= h($mk(['q' => $list['q']])) ?>">Tout</a>
        <a class="v-chip <?= $list['transaction'] === 'vente' ? 'is-active' : '' ?>" href="<?= h($mk(['tx' => 'vente', 'q' => $list['q']])) ?>">Vente</a>
        <a class="v-chip <?= $list['transaction'] === 'location' ? 'is-active' : '' ?>" href="<?= h($mk(['tx' => 'location', 'q' => $list['q']])) ?>">Location</a>

        <form class="v-search" method="get" action="<?= h($base) ?>">
          <?php if ($list['transaction'] !== ''): ?>
            <input type="hidden" name="tx" value="<?= h($list['transaction']) ?>">
          <?php endif; ?>
          <input name="q" value="<?= h($list['q']) ?>" placeholder="Ville, code postal, titre…" aria-label="Rechercher">
          <button class="v-btn v-btn-ghost" type="submit">Rechercher</button>
        </form>
      </div>

      <?php if (!$list['items']): ?>
        <div class="v-empty">Aucune annonce ne correspond à vos critères.</div>
      <?php else: ?>
        <div class="v-grid">
          <?php foreach ($list['items'] as $a): ?>
            <?php
              $href = vitrine_annonce_url($agenceSlug, (int)$a['annonce_id'], (string)($a['annonce_slug'] ?? ''));
              $img = (string)($a['photo_url'] ?? '');
              $imgSrc = $img !== '' ? app_url('/' . ltrim($img, '/')) : app_url('/images/Home.png');
              $alt = (string)($a['photo_alt'] ?? vitrine_annonce_h1($a));
              $w = (int)($a['photo_w'] ?? 0);
              $h = (int)($a['photo_h'] ?? 0);
              if ($w <= 0) $w = 800;
              if ($h <= 0) $h = 600;
            ?>
            <a class="v-card" href="<?= h($href) ?>">
              <img class="v-card-img" src="<?= h($imgSrc) ?>" alt="<?= h($alt) ?>" width="<?= (int)$w ?>" height="<?= (int)$h ?>" loading="lazy" decoding="async">
              <div class="v-card-body">
                <div class="v-card-top">
                  <div class="v-badge"><?= h(vitrine_tx_label((string)($a['type_transaction'] ?? ''))) ?></div>
                  <div class="v-price"><?= h(vitrine_price_label($a)) ?></div>
                </div>
                <div class="v-card-title"><?= h(vitrine_annonce_h1($a)) ?></div>
                <div class="v-card-meta">
                  <span><?= h((string)($a['ville'] ?? '')) ?></span>
                  <span class="v-sep">•</span>
                  <span><?= h((string)($a['surface_habitable'] ?? '') !== '' ? ((int)$a['surface_habitable'] . ' m²') : '') ?></span>
                  <?php if (!empty($a['nb_pieces'])): ?><span class="v-sep">•</span><span><?= (int)$a['nb_pieces'] ?> p.</span><?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ((int)$list['total_pages'] > 1): ?>
          <nav class="v-pagination" aria-label="Pagination">
            <?php
              $cur = (int)$list['page'];
              $tp = (int)$list['total_pages'];
              $qsBase = [];
              if ($list['transaction'] !== '') $qsBase['tx'] = $list['transaction'];
              if ($list['q'] !== '') $qsBase['q'] = $list['q'];
              $linkPage = static function(int $p) use ($base, $qsBase): string {
                  $qs = $qsBase;
                  $qs['page'] = $p;
                  return $base . '?' . http_build_query($qs);
              };
            ?>
            <a class="v-page <?= $cur <= 1 ? 'is-disabled' : '' ?>" href="<?= h($cur <= 1 ? '#' : $linkPage($cur - 1)) ?>">Précédent</a>
            <div class="v-page-info">Page <?= $cur ?> / <?= $tp ?></div>
            <a class="v-page <?= $cur >= $tp ? 'is-disabled' : '' ?>" href="<?= h($cur >= $tp ? '#' : $linkPage($cur + 1)) ?>">Suivant</a>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php
    include __DIR__ . '/../inc/vitrine_footer.php';
    exit;
}

if ($route === 'annonce') {
    $annonceId = (int)($_GET['id'] ?? 0);
    $row = vitrine_fetch_annonce_detail($pdo, (int)$agence['id'], $annonceId);
    if (!$row) {
        http_response_code(404);
        exit('Annonce introuvable.');
    }

    $photos = vitrine_fetch_annonce_photos($pdo, $annonceId);
    $main = vitrine_pick_photo_variant($photos, 'large');
    $mainUrl = vitrine_photo_url($main);
    $mainSrc = $mainUrl !== '' ? app_url('/' . ltrim($mainUrl, '/')) : app_url('/images/Home.png');
    $imgUrlsAbs = [];
    foreach (array_slice($photos, 0, 5) as $p) {
        $u = vitrine_photo_url($p);
        if ($u !== '') $imgUrlsAbs[] = vitrine_url(app_url('/' . ltrim($u, '/')));
    }

    $titleH1 = vitrine_annonce_h1($row);
    $pageTitle = $titleH1 . ' — ' . (string)($agence['nom_agence'] ?? 'Agence');
    $pageDescription = trim((string)($row['meta_description'] ?? $row['annonce_meta_description'] ?? '')) ?: ('Annonce ' . vitrine_tx_label((string)($row['type_transaction'] ?? '')) . ' à ' . (string)($row['ville'] ?? ''));
    $pageCanonical = vitrine_url(vitrine_annonce_url($agenceSlug, $annonceId, (string)($row['slug'] ?? '')));

    $crumbs[] = ['name' => 'Annonces', 'item' => vitrine_path($agenceSlug, '/annonces')];
    $crumbs[] = ['name' => $titleH1, 'item' => vitrine_annonce_url($agenceSlug, $annonceId, (string)($row['slug'] ?? ''))];

    $jsonld = vitrine_jsonld_agence($agence, $baseUrl, $homeUrl)
        . vitrine_jsonld_annonce($row, $pageCanonical, $imgUrlsAbs)
        . vitrine_breadcrumb_jsonld($crumbs, $baseUrl);

    $vMeta = ['title' => $pageTitle, 'description' => $pageDescription, 'canonical' => $pageCanonical, 'image' => $mainSrc !== '' ? vitrine_url($mainSrc) : $ogImg];
    $vJsonLd = $jsonld;
    include __DIR__ . '/../inc/vitrine_header.php';
    ?>

    <article class="v-section">
      <div class="v-article-head">
        <div class="v-badge"><?= h(vitrine_tx_label((string)($row['type_transaction'] ?? ''))) ?></div>
        <h1><?= h($titleH1) ?></h1>
        <div class="v-article-sub">
          <span><?= h((string)($row['ville'] ?? '')) ?></span>
          <?php if (!empty($row['code_postal'])): ?><span class="v-sep">•</span><span><?= h((string)$row['code_postal']) ?></span><?php endif; ?>
          <?php if (!empty($row['surface_habitable'])): ?><span class="v-sep">•</span><span><?= (int)$row['surface_habitable'] ?> m²</span><?php endif; ?>
          <?php if (!empty($row['nb_pieces'])): ?><span class="v-sep">•</span><span><?= (int)$row['nb_pieces'] ?> pièces</span><?php endif; ?>
        </div>
      </div>

      <div class="v-article-grid">
        <div>
          <img class="v-hero-img" src="<?= h($mainSrc) ?>" alt="<?= h((string)($main['caption'] ?? $main['alt_photo'] ?? $titleH1)) ?>" width="<?= (int)($main['largeur'] ?? 1600) ?>" height="<?= (int)($main['hauteur'] ?? 1200) ?>" loading="eager" decoding="async">

          <?php if ($photos): ?>
            <div class="v-gallery">
              <?php foreach (array_slice($photos, 0, 8) as $p): ?>
                <?php
                  $u = vitrine_photo_url($p);
                  if ($u === '') continue;
                  $src = app_url('/' . ltrim($u, '/'));
                ?>
                <img class="v-thumb" src="<?= h($src) ?>" alt="<?= h((string)($p['caption'] ?? $p['alt_photo'] ?? $titleH1)) ?>" width="<?= (int)($p['largeur'] ?? 400) ?>" height="<?= (int)($p['hauteur'] ?? 300) ?>" loading="lazy" decoding="async">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="v-specs">
            <div class="v-spec"><div class="v-spec-k">Prix</div><div class="v-spec-v"><?= h(vitrine_price_label($row)) ?></div></div>
            <div class="v-spec"><div class="v-spec-k">Surface</div><div class="v-spec-v"><?= !empty($row['surface_habitable']) ? ((int)$row['surface_habitable'] . ' m²') : '—' ?></div></div>
            <div class="v-spec"><div class="v-spec-k">Pièces</div><div class="v-spec-v"><?= !empty($row['nb_pieces']) ? ((int)$row['nb_pieces'] . ' p.') : '—' ?></div></div>
            <div class="v-spec"><div class="v-spec-k">Chambres</div><div class="v-spec-v"><?= !empty($row['nb_chambres']) ? ((int)$row['nb_chambres']) : '—' ?></div></div>
            <div class="v-spec"><div class="v-spec-k">DPE</div><div class="v-spec-v"><?= h((string)($row['dpe_classe'] ?? '—')) ?></div></div>
          </div>

          <section class="v-block">
            <h2>Description</h2>
            <?php
              $desc = trim((string)($row['description'] ?? ''));
              if ($desc === '') $desc = trim((string)($row['accroche_commerciale'] ?? ''));
            ?>
            <?php if ($desc === ''): ?>
              <p class="v-muted">Description à venir.</p>
            <?php else: ?>
              <div class="v-prose"><?= nl2br(h($desc)) ?></div>
            <?php endif; ?>
          </section>

          <section class="v-block">
            <h2>Pourquoi nous confier votre projet</h2>
            <ul class="v-list">
              <li>Un interlocuteur unique et un suivi documenté.</li>
              <li>Valorisation, diffusion et sélection rigoureuse.</li>
              <li>Transparence : étapes, délais, reporting et arbitrages.</li>
            </ul>
            <a class="v-btn v-btn-primary" href="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">Estimation gratuite</a>
          </section>
        </div>

        <aside class="v-aside">
          <div class="v-aside-card">
            <div class="v-aside-title">Parlons de ce bien</div>
            <div class="v-aside-text">Contactez-nous ou demandez un rappel : réponse rapide.</div>
            <a class="v-btn v-btn-primary v-btn-block" href="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">Demander un rappel</a>
            <?php if (!empty($agence['telephone'])): ?>
              <a class="v-btn v-btn-ghost v-btn-block" href="tel:<?= h(preg_replace('/\\s+/', '', (string)$agence['telephone']) ?? (string)$agence['telephone']) ?>">Appeler</a>
            <?php endif; ?>
          </div>
          <div class="v-aside-card v-aside-soft">
            <div class="v-aside-title">Estimation gratuite</div>
            <div class="v-aside-text">Vous vendez ou louez ? Obtenez une estimation et une stratégie de mise en marché.</div>
            <a class="v-link" href="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">Accéder au formulaire →</a>
          </div>
        </aside>
      </div>
    </article>

    <?php
    include __DIR__ . '/../inc/vitrine_footer.php';
    exit;
}

// ──────────────────────────────────────────────────────────────
// PAGES ACTIVITÉS (statique + CTA)
// ──────────────────────────────────────────────────────────────
$activityPages = [
    'transaction-immobiliere' => [
        'title' => 'Transaction immobilière — ' . (string)($agence['nom_agence'] ?? 'Agence'),
        'h1' => 'Vente & location : stratégie, diffusion, négociation',
        'desc' => 'Un accompagnement complet pour vendre ou louer au bon prix : valorisation, diffusion, visites, sélection et sécurisation.',
    ],
    'gestion-locative' => [
        'title' => 'Gestion locative — ' . (string)($agence['nom_agence'] ?? 'Agence'),
        'h1' => 'Gestion locative : sécurisez vos loyers, déléguez sereinement',
        'desc' => 'Confiez la gestion de vos biens : sélection des locataires, suivi, quittances, travaux, reporting et transparence.',
    ],
    'syndic-copropriete' => [
        'title' => 'Syndic de copropriété — ' . (string)($agence['nom_agence'] ?? 'Agence'),
        'h1' => 'Syndic : transparence, budget, travaux, assemblées',
        'desc' => 'Un syndic rigoureux : gestion administrative et financière, suivi des prestataires, pilotage des travaux, communication claire.',
    ],
    'estimation-gratuite' => [
        'title' => 'Estimation gratuite — ' . (string)($agence['nom_agence'] ?? 'Agence'),
        'h1' => 'Estimation gratuite de votre bien',
        'desc' => 'Recevez une estimation gratuite et un plan d’action (vente, location, gestion). Réponse rapide.',
    ],
];

if (isset($activityPages[$route])) {
    $cfg = $activityPages[$route];
    $pageTitle = (string)$cfg['title'];
    $pageDescription = (string)$cfg['desc'];
    $pageCanonical = vitrine_url(vitrine_path($agenceSlug, '/' . $route));

    $crumbs[] = ['name' => (string)($cfg['h1'] ?? $route), 'item' => vitrine_path($agenceSlug, '/' . $route)];
    $jsonld = vitrine_jsonld_agence($agence, $baseUrl, $homeUrl)
        . vitrine_breadcrumb_jsonld($crumbs, $baseUrl);

    $vMeta = ['title' => $pageTitle, 'description' => $pageDescription, 'canonical' => $pageCanonical, 'image' => $ogImg];
    $vJsonLd = $jsonld;
    include __DIR__ . '/../inc/vitrine_header.php';
    ?>

    <section class="v-section">
      <div class="v-activity-head">
        <h1><?= h((string)($cfg['h1'] ?? '')) ?></h1>
        <p class="v-lead"><?= h((string)($cfg['desc'] ?? '')) ?></p>
        <div class="v-hero-actions">
          <a class="v-btn v-btn-primary" href="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">Estimation gratuite</a>
          <a class="v-btn v-btn-ghost" href="<?= h(vitrine_path($agenceSlug, '/annonces')) ?>">Voir les annonces</a>
        </div>
      </div>

      <?php if ($route !== 'estimation-gratuite'): ?>
        <div class="v-grid v-grid-3">
          <div class="v-panel">
            <div class="v-panel-title">Clarté</div>
            <div class="v-panel-text">Des étapes simples, un suivi, des décisions tracées.</div>
          </div>
          <div class="v-panel">
            <div class="v-panel-title">Sécurité</div>
            <div class="v-panel-text">Qualification, conformité, rigueur documentaire.</div>
          </div>
          <div class="v-panel">
            <div class="v-panel-title">Performance</div>
            <div class="v-panel-text">Valorisation, diffusion maîtrisée, optimisation du délai.</div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($route === 'estimation-gratuite'): ?>
        <div class="v-form-wrap">
          <?php if ($flashOk !== ''): ?><div class="v-alert v-alert-ok"><?= h($flashOk) ?></div><?php endif; ?>
          <?php if ($flashErr !== ''): ?><div class="v-alert v-alert-err"><?= h($flashErr) ?></div><?php endif; ?>

          <form class="v-form" method="post" action="<?= h(vitrine_path($agenceSlug, '/estimation-gratuite')) ?>">
            <?= csrf_field('vitrine_lead') ?>
            <input type="text" name="website" value="" autocomplete="off" class="v-hp" tabindex="-1" aria-hidden="true">

            <div class="v-form-grid">
              <label class="v-field">
                <span>Nom *</span>
                <input name="nom" required value="<?= h($leadPrefill['nom'] ?? '') ?>">
              </label>
              <label class="v-field">
                <span>Email *</span>
                <input name="email" type="email" required value="<?= h($leadPrefill['email'] ?? '') ?>">
              </label>
              <label class="v-field">
                <span>Téléphone</span>
                <input name="telephone" inputmode="tel" value="<?= h($leadPrefill['telephone'] ?? '') ?>">
              </label>
              <label class="v-field">
                <span>Ville</span>
                <input name="ville" value="<?= h($leadPrefill['ville'] ?? '') ?>">
              </label>
              <label class="v-field v-field-full">
                <span>Type de demande</span>
                <select name="type">
                  <option value="estimation" <?= (($leadPrefill['type'] ?? '') === 'estimation') ? 'selected' : '' ?>>Estimation</option>
                  <option value="vente" <?= (($leadPrefill['type'] ?? '') === 'vente') ? 'selected' : '' ?>>Vente</option>
                  <option value="location" <?= (($leadPrefill['type'] ?? '') === 'location') ? 'selected' : '' ?>>Location</option>
                  <option value="gestion" <?= (($leadPrefill['type'] ?? '') === 'gestion') ? 'selected' : '' ?>>Gestion locative</option>
                </select>
              </label>
              <label class="v-field v-field-full">
                <span>Message</span>
                <textarea name="message" rows="5" placeholder="Décrivez votre bien et votre besoin (vente, location, gestion)…"><?= h($leadPrefill['message'] ?? '') ?></textarea>
              </label>
            </div>

            <button class="v-btn v-btn-primary v-btn-block" type="submit">Envoyer</button>
            <div class="v-muted v-small">En envoyant, vous acceptez d’être recontacté par l’agence. Aucune donnée n’est vendue à des tiers.</div>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <?php
    include __DIR__ . '/../inc/vitrine_footer.php';
    exit;
}

http_response_code(404);
exit('Page introuvable.');
