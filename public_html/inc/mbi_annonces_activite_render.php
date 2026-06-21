<?php
declare(strict_types=1);

/**
 * Renderer commun des pages « activité ».
 * Attendus dans le scope appelant :
 *   $act    : tableau de contenu issu de mbi_annonces_activite_data()
 *   $agence : ?array  (override agence si ?agence=slug, sinon null)
 *   $slug   : string  (slug de l'activité, pour les URLs canoniques)
 *
 * Le fichier appelant a déjà inclus le header et défini $mbiMeta/$mbiJsonLd.
 * Ici on ne rend QUE le corps (entre <main> ouvert par le header et </main>
 * fermé par le footer).
 */

/** @var array $act */
/** @var array|null $agence */
/** @var string $slug */

$ctaUrl   = app_url($act['cta_url']);
$ctaLabel = (string)$act['cta_label'];

// Personnalisation agence : intro dédiée + libellé contextualisé.
$agName    = $agence ? (string)($agence['nom_commercial'] ?: $agence['nom_agence']) : '';
$agIntro   = $agence ? trim((string)($agence['texte_intro'] ?? '')) : '';
$agQuery   = $agence ? '?agence=' . rawurlencode((string)$agence['slug']) : '';
?>

<section class="mbi-page-hero">
  <div class="mbi-container">
    <span class="mbi-page-eyebrow"><?= h($act['eyebrow']) ?><?= $agName !== '' ? ' · ' . h($agName) : '' ?></span>
    <h1><?= h($act['h1']) ?></h1>
    <p class="mbi-page-lead"><?= h($act['lead']) ?></p>
    <div class="mbi-page-cta">
      <a class="mbi-btn mbi-btn-primary" href="<?= h($ctaUrl) ?>"><?= h($ctaLabel) ?></a>
      <a class="mbi-btn mbi-btn-ghost" style="color:#fff;border-color:rgba(255,255,255,.4)" href="<?= h(app_url('/mbi_annonces_contact_general.php')) ?>">Nous contacter</a>
    </div>
  </div>
</section>

<article class="mbi-prose">

  <?php
    // Ma Box Net : contenu éditorial LOCAL éditable (agence_net_page) — prioritaire
    // sur l'intro générique. HTML admin restreint à des balises de mise en forme.
    $netHtml = isset($netPage) && $netPage ? trim((string)($netPage['contenu_html'] ?? '')) : '';
    if ($netHtml !== ''):
        $netTitre = trim((string)($netPage['titre'] ?? ''));
        $allowed  = '<p><br><strong><b><em><i><ul><ol><li><h2><h3><a>';
  ?>
    <section class="mbi-net-editorial">
      <?php if ($netTitre !== ''): ?><h2><?= h($netTitre) ?></h2><?php endif; ?>
      <?= strip_tags($netHtml, $allowed) ?>
    </section>
  <?php elseif ($agIntro !== ''): ?>
    <section>
      <h2>L'agence <?= h($agName) ?> à votre service</h2>
      <?php foreach (preg_split('/\n\s*\n/', $agIntro) as $para): $para = trim($para); if ($para === '') continue; ?>
        <p><?= h($para) ?></p>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php foreach ($act['sections'] as $sec): ?>
    <section>
      <h2><?= h($sec['h2']) ?></h2>
      <?php foreach ($sec['p'] as $para): ?>
        <p><?= h($para) ?></p>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <?php if (!empty($act['features'])): ?>
    <div class="mbi-feature-grid">
      <?php foreach ($act['features'] as $f): ?>
        <div class="mbi-feature">
          <div class="mbi-feature-ic" aria-hidden="true"><?= $f['ic'] ?></div>
          <h3><?= h($f['h3']) ?></h3>
          <p><?= h($f['p']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($act['faq'])): ?>
    <section class="mbi-faq">
      <h2>Questions fréquentes</h2>
      <?php foreach ($act['faq'] as $qa): ?>
        <details>
          <summary><?= h($qa['q']) ?></summary>
          <p><?= h($qa['a']) ?></p>
        </details>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <div class="mbi-cta-band">
    <h2><?= $agName !== '' ? h($agName) . ', à vos côtés' : 'Un projet ? Parlons-en.' ?></h2>
    <p>Nos conseillers vous répondent rapidement et sans engagement.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a class="mbi-btn mbi-btn-primary" href="<?= h($ctaUrl) ?>"><?= h($ctaLabel) ?></a>
      <a class="mbi-btn mbi-btn-navy" href="<?= h(app_url('/mbi_annonces_contact_general.php' . $agQuery)) ?>">Être recontacté</a>
    </div>
  </div>

</article>
