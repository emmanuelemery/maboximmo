<?php
declare(strict_types=1);
/**
 * missions/ma_journee.php — « Ma journée » (N1, poste de pilotage perso).
 * Point d'entrée du paradigme missions : missions en cours, validations, alertes, RDV, docs reçus,
 * bouton « Démarrer une mission ». STUB — à construire.
 */
define('MBI', true);
$pageTitle = 'Ma journée';
require __DIR__ . '/_mission_head.php';
?>
<div class="shell">

  <header class="hero">
    <div class="hero-bg"></div>
    <div class="hero-row">
      <div class="hero-mid">
        <div class="eyebrow"><span class="badge">★ Accueil</span><span>Poste de pilotage · ce que vous avez à faire</span></div>
        <div class="instance">Vos missions en cours, validations, alertes et rendez-vous.</div>
      </div>
      <label class="search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
        <input placeholder="Rechercher une mission, un bien, un tiers…" aria-label="Recherche globale">
        <kbd>/</kbd>
      </label>
    </div>
  </header>

  <div class="grid">
    <section class="card">
      <div class="hd"><div class="t">Démarrer une mission<small>choisissez un parcours</small></div></div>
      <div class="bd"><div class="steps">
        <a class="step cur" href="<?= h($assets('/missions/mettre_bien_location.php')) ?>" style="text-decoration:none"><span class="mk">›</span><span class="txt"><span class="en">Service Location</span><span class="lb">Mettre un bien en location</span></span></a>
      </div></div>
    </section>
    <section class="card">
      <div class="bd">
        <div class="worktop"><span class="eb">Ma journée</span><span class="st">à venir</span><h2>Vos tâches du jour</h2></div>
        <div class="datarow"><span class="v">🏗️ Poste de pilotage en construction — missions en cours, validations, relances, RDV et documents reçus s'afficheront ici.</span></div>
      </div>
    </section>
    <section class="card">
      <div class="tabs"><button class="tab" aria-selected="true">Alertes</button></div>
      <div class="aide"><h4>Bientôt</h4><ul><li>Actions générées automatiquement par vos missions.</li></ul></div>
    </section>
  </div>
</div><!-- /.shell -->

<?php require __DIR__ . '/_mission_foot.php'; ?>
