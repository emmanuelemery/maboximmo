<?php
declare(strict_types=1);

/**
 * mbi_annonces_contact_general.php — Page publique « Contact ».
 * Coordonnées, plan de situation (Google Maps) et formulaire de contact
 * général. Le formulaire poste sur cette même page (handler intégré) et
 * enregistre un lead dans `vitrine_leads` (type contact_general).
 *
 * Personnalisable par agence via ?agence=slug (plan + coordonnées ciblés).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mbi_annonces_helpers.php';
require_once __DIR__ . '/inc/mbi_annonces_pages_helpers.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Agence ciblée (optionnel) ; sinon première agence active pour le plan.
$agences = mbi_pages_fetch_public_agences($pdo);
$agSlug  = trim((string)($_GET['agence'] ?? ''));
$agence  = $agSlug !== '' ? mbi_pages_fetch_agence_by_slug($pdo, $agSlug) : null;
if (!$agence && $agences) { $agence = $agences[0]; }

$sujet = trim((string)($_GET['sujet'] ?? ''));

$flash = ['ok' => false, 'err' => ''];

if (is_post()) {
    RateLimiter::check('mbi_contact_general', 8, 600);
    verify_csrf('mbi_contact_general');

    // Honeypot : champ invisible rempli = bot → on simule un succès.
    if (trim((string)post('website', '')) !== '') {
        redirect('/mbi_annonces_contact_general.php?envoye=1#form');
    }

    $nom     = trim((string)post('nom', ''));
    $email   = trim((string)post('email', ''));
    $tel     = trim((string)post('telephone', ''));
    $message = trim((string)post('message', ''));
    $sujetP  = trim((string)post('sujet', $sujet));

    $errs = [];
    if ($nom === '' || mb_strlen($nom) > 120)                              $errs[] = 'Votre nom est requis.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))       $errs[] = 'Une adresse e-mail valide est requise.';
    if ($tel !== '' && mb_strlen($tel) > 30)                               $errs[] = 'Téléphone invalide.';
    if ($message === '' || mb_strlen($message) > 2000)                     $errs[] = 'Votre message est requis (2000 caractères max).';

    if ($errs) {
        $flash['err'] = implode(' ', $errs);
    } else {
        $body = $message;
        if ($sujetP !== '') $body = "[Sujet : {$sujetP}]\n\n" . $body;
        if ($tel !== '')    $body .= "\n\n— Téléphone : " . $tel;
        try {
            $st = $pdo->prepare("
                INSERT INTO vitrine_leads
                  (id_societe, id_agence, type, nom, email, telephone, ville, message, source_url, ip, user_agent, created_at)
                VALUES
                  (:id_societe, :id_agence, :type, :nom, :email, :telephone, :ville, :message, :source_url, :ip, :user_agent, NOW())
            ");
            $st->execute([
                ':id_societe' => $agence ? ((int)($agence['id_societe'] ?? 0) ?: null) : null,
                ':id_agence'  => $agence ? ((int)($agence['id'] ?? 0) ?: null) : null,
                ':type'       => 'contact_general',
                ':nom'        => $nom,
                ':email'      => $email,
                ':telephone'  => $tel !== '' ? $tel : null,
                ':ville'      => $agence ? ((string)($agence['ville'] ?? '') ?: null) : null,
                ':message'    => $body,
                ':source_url' => mbi_annonces_abs_url(app_url('/mbi_annonces_contact_general.php')),
                ':ip'         => client_ip(),
                ':user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
            redirect('/mbi_annonces_contact_general.php?envoye=1#form');
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) error_log('[mbi_contact_general] insert: ' . $e->getMessage());
            $flash['err'] = "Votre demande n'a pas pu être enregistrée. Réessayez dans quelques minutes.";
        }
    }
}

if (!empty($_GET['envoye'])) $flash['ok'] = true;

$canonical = mbi_annonces_abs_url(app_url('/mbi_annonces_contact_general.php'));
$mbiMeta = [
    'title'       => 'Nos services particuliers & pros — diffuser, gérer, digitaliser · Ma Box Immo',
    'description' => "Particuliers : diffusez ou gérez votre bien (module Bailleur). Professionnels : digitalisez votre agence avec MaBox RH, Agency et Transaction. Demandez une démo ou contactez-nous.",
    'canonical'   => $canonical,
    'image'       => mbi_annonces_abs_url(app_url('/images/Home.png')),
];

$mbiJsonLd = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => 'TRUBOX',
    'url'      => mbi_annonces_abs_url(app_url('/')),
    'email'    => 'contact@trubox.fr',
    'address'  => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => '7ter rue de Verdun',
        'postalCode'      => '42800',
        'addressLocality' => 'Saint-Martin-la-Plaine',
        'addressCountry'  => 'FR',
    ],
    'contactPoint' => [
        '@type'        => 'ContactPoint',
        'contactType'  => 'customer support',
        'email'        => 'contact@trubox.fr',
        'areaServed'   => 'FR',
        'availableLanguage' => 'French',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

$mbiJsonLd .= mbi_annonces_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => app_url('/mbi_annonces_index.php')],
    ['name' => 'Contact', 'url' => app_url('/mbi_annonces_contact_general.php')],
]);

$mbiNavActive = 'contact';
$mbiBodyClass = 'mbi-page-editorial mbi-page-contact';

// Plan de situation : embed Google Maps sans clé API (q + output=embed).
$mapQuery = '';
if ($agence) {
    if (!empty($agence['latitude']) && !empty($agence['longitude'])) {
        $mapQuery = (string)$agence['latitude'] . ',' . (string)$agence['longitude'];
    } else {
        $mapQuery = mbi_pages_agence_address($agence);
    }
}

include __DIR__ . '/inc/mbi_annonces_header.php';
?>

<section class="mbi-svc-hero">
  <div class="mbi-container">
    <span class="mbi-page-eyebrow">Particuliers &amp; professionnels</span>
    <h1>L'immobilier nouvelle génération, propulsé par Ma Box Immo</h1>
    <p class="mbi-page-lead">Diffusez ou confiez votre bien en quelques clics, ou digitalisez toute votre agence avec nos modules métier. Une plateforme pensée pour faire gagner du temps aux propriétaires comme aux professionnels.</p>
    <div class="mbi-page-cta">
      <a class="mbi-btn mbi-btn-primary" href="#particuliers">Je suis particulier</a>
      <a class="mbi-btn mbi-btn-ghost" style="color:#fff;border-color:rgba(255,255,255,.45)" href="#pros">Je suis professionnel</a>
    </div>
  </div>
</section>

<!-- ════════ PARTICULIERS ════════ -->
<section class="mbi-aud" id="particuliers">
  <div class="mbi-container">
    <div class="mbi-aud-head">
      <span class="mbi-aud-tag mbi-aud-tag-part">🏠 Particuliers</span>
      <h2>Votre bien mérite les meilleurs outils</h2>
      <p>Que vous vouliez vendre, louer ou gérer en toute autonomie, Ma Box Immo met la technologie des pros au service des propriétaires.</p>
    </div>
    <div class="mbi-svc-grid">
      <div class="mbi-svc">
        <div class="mbi-svc-ic">📣</div>
        <h3>Diffuser mon bien</h3>
        <p>Publiez votre annonce et profitez d'une diffusion soignée : photos mises en valeur, description optimisée et visibilité sur notre portail.</p>
        <button type="button" class="mbi-btn mbi-btn-primary mbi-btn-block" onclick="mbiPickSujet('diffuser')">Diffuser mon bien →</button>
      </div>
      <div class="mbi-svc">
        <div class="mbi-svc-ic">🗂️</div>
        <h3>Gérer mon bien — module Bailleur</h3>
        <p>Pilotez votre location comme un pro : baux, quittances, documents, suivi des loyers et relances, le tout centralisé dans votre espace Bailleur.</p>
        <button type="button" class="mbi-btn mbi-btn-primary mbi-btn-block" onclick="mbiPickSujet('gerer')">Gérer mon bien →</button>
      </div>
    </div>
  </div>
</section>

<!-- ════════ PROFESSIONNELS ════════ -->
<section class="mbi-aud mbi-aud-dark" id="pros">
  <div class="mbi-container">
    <div class="mbi-aud-head">
      <span class="mbi-aud-tag mbi-aud-tag-pro">💼 Professionnels</span>
      <h2>La suite qui digitalise toute votre agence</h2>
      <p>Trois modules métier connectés, dopés à l'IA et à une GED centralisée, pour faire gagner des heures à vos équipes — du recrutement à la signature.</p>
    </div>
    <div class="mbi-svc-grid mbi-svc-grid-3">
      <div class="mbi-svc mbi-svc-pro">
        <div class="mbi-svc-ic">👥</div>
        <h3>MaBox RH</h3>
        <p>Gestion des salariés, salaires, congés et documents RH dans un cockpit clair et collaboratif.</p>
        <button type="button" class="mbi-btn mbi-btn-block mbi-btn-pro" onclick="mbiPickSujet('rh')">Demander une démo →</button>
      </div>
      <div class="mbi-svc mbi-svc-pro">
        <div class="mbi-svc-ic">🏢</div>
        <h3>MaBox Agency</h3>
        <p>Biens, propriétaires, baux, annonces et diffusion multi-portails (Ubiflow) — toute la gestion locative et la vie de l'agence réunies.</p>
        <button type="button" class="mbi-btn mbi-btn-block mbi-btn-pro" onclick="mbiPickSujet('agency')">Demander une démo →</button>
      </div>
      <div class="mbi-svc mbi-svc-pro">
        <div class="mbi-svc-ic">🤝</div>
        <h3>MaBox Transaction</h3>
        <p>Dossiers de vente, mandats multi-lots, avant-contrat, estimation et honoraires : la transaction de A à Z, sans ressaisie.</p>
        <button type="button" class="mbi-btn mbi-btn-block mbi-btn-pro" onclick="mbiPickSujet('transaction')">Demander une démo →</button>
      </div>
    </div>
  </div>
</section>

<!-- ════════ NOTRE DÉVELOPPEMENT ════════ -->
<section class="mbi-section">
  <div class="mbi-container" style="max-width:980px">
    <div class="mbi-aud-head" style="text-align:center;margin:0 auto 8px">
      <span class="mbi-page-eyebrow" style="color:var(--mbi-gold)">Notre développement</span>
      <h2>Une plateforme qui innove en continu</h2>
    </div>
    <div class="mbi-feature-grid">
      <div class="mbi-feature"><div class="mbi-feature-ic">🤖</div><h3>Intelligence artificielle</h3><p>Extraction automatique des documents, génération d'annonces, analyse de baux et de DPE.</p></div>
      <div class="mbi-feature"><div class="mbi-feature-ic">📁</div><h3>GED centralisée</h3><p>Une seule source documentaire, classement automatique et accès 360° par bien, immeuble ou tiers.</p></div>
      <div class="mbi-feature"><div class="mbi-feature-ic">⚡</div><h3>Pipeline automatisé</h3><p>De l'upload au classement validé : un flux fluide qui supprime la double saisie.</p></div>
      <div class="mbi-feature"><div class="mbi-feature-ic">🔗</div><h3>Tout est connecté</h3><p>RH, Agency, Transaction et GED partagent la même donnée métier, toujours à jour.</p></div>
    </div>
  </div>
</section>

<!-- ════════ FORMULAIRE ════════ -->
<article class="mbi-prose" id="form">

  <?php if ($flash['ok']): ?>
    <div class="mbi-alert mbi-alert-ok">✅ Merci, votre message a bien été envoyé. Nous vous recontactons au plus vite.</div>
  <?php elseif ($flash['err'] !== ''): ?>
    <div class="mbi-alert mbi-alert-err"><?= h($flash['err']) ?></div>
  <?php endif; ?>

  <div class="mbi-contact-cols" style="display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:start">

    <div>
      <h2>Parlons de votre projet</h2>
      <p>Dites-nous ce dont vous avez besoin&nbsp;: un conseiller vous recontacte rapidement, sans engagement.</p>
      <?php
        $sujetOptions = [
          ''            => '— Choisir un sujet —',
          'diffuser'    => 'Particulier — Diffuser mon bien',
          'gerer'       => 'Particulier — Gérer mon bien (Bailleur)',
          'rh'          => 'Pro — MaBox RH',
          'agency'      => 'Pro — MaBox Agency',
          'transaction' => 'Pro — MaBox Transaction',
          'autre'       => 'Autre demande',
        ];
      ?>
      <form class="mbi-form" method="post" action="<?= h(app_url('/mbi_annonces_contact_general.php')) ?>#form">
        <?= csrf_field('mbi_contact_general') ?>
        <input type="text" name="website" value="" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px">

        <div class="mbi-field">
          <label for="c-sujet">Votre besoin</label>
          <select id="c-sujet" name="sujet">
            <?php foreach ($sujetOptions as $val => $lbl): ?>
              <option value="<?= h($val) ?>" <?= $sujet === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mbi-field">
          <label for="c-nom">Nom et prénom *</label>
          <input id="c-nom" type="text" name="nom" required maxlength="120" value="<?= h((string)post('nom', '')) ?>">
        </div>
        <div class="mbi-field">
          <label for="c-email">E-mail *</label>
          <input id="c-email" type="email" name="email" required maxlength="180" value="<?= h((string)post('email', '')) ?>">
        </div>
        <div class="mbi-field">
          <label for="c-tel">Téléphone</label>
          <input id="c-tel" type="tel" name="telephone" maxlength="30" value="<?= h((string)post('telephone', '')) ?>">
        </div>
        <div class="mbi-field">
          <label for="c-msg">Votre message *</label>
          <textarea id="c-msg" name="message" required maxlength="2000" placeholder="Décrivez votre projet ou votre question…"><?= h((string)post('message', '')) ?></textarea>
        </div>
        <button type="submit" class="mbi-btn mbi-btn-primary">Envoyer ma demande</button>
        <p style="font-size:12px;color:var(--mbi-ink-soft);margin:6px 0 0">Vos données servent uniquement à traiter votre demande. Aucune cession à des tiers.</p>
      </form>
    </div>

    <div>
      <h2>Nous trouver</h2>
      <p><strong>TRUBOX</strong></p>
      <div class="mbi-agence-row"><span class="ic" aria-hidden="true">📍</span><span>7ter rue de Verdun, 42800 Saint-Martin-la-Plaine</span></div>
      <div class="mbi-agence-row"><span class="ic" aria-hidden="true">✉️</span>
        <a href="mailto:contact@trubox.fr">contact@trubox.fr</a></div>

      <iframe class="mbi-map-embed" loading="lazy" title="Plan de situation — TRUBOX, Saint-Martin-la-Plaine"
              src="https://maps.google.com/maps?q=<?= h(rawurlencode('7ter rue de Verdun, 42800 Saint-Martin-la-Plaine')) ?>&z=15&output=embed"
              referrerpolicy="no-referrer-when-downgrade"></iframe>

      <p style="margin-top:8px"><a class="mbi-link" href="<?= h(app_url('/mbi_annonces_agences.php')) ?>">Voir aussi nos agences et leurs horaires →</a></p>
    </div>

  </div>
</article>

<script>
function mbiPickSujet(val){
  var sel = document.getElementById('c-sujet');
  if (sel) { sel.value = val; }
  var f = document.getElementById('form');
  if (f) { f.scrollIntoView({behavior:'smooth', block:'start'}); }
  var nom = document.getElementById('c-nom');
  if (nom) { setTimeout(function(){ nom.focus(); }, 400); }
}
</script>

<?php include __DIR__ . '/inc/mbi_annonces_footer.php'; ?>
