<?php
declare(strict_types=1);
/**
 * missions/mes_declencheurs.php — « Mes déclencheurs » (automatisations Quand→Alors).
 * Où l'utilisateur voit/paramètre ses règles d'automatisation métier. STUB — à construire.
 */
define('MBI', true);
$pageTitle = 'Mes déclencheurs';
require __DIR__ . '/_mission_head.php';
?>
<div class="shell">
  <header class="hero">
    <div class="hero-bg"></div>
    <div class="hero-row"><div class="hero-mid">
      <div class="eyebrow"><span class="badge">⚡ Automatisations</span><span>Quand → Alors</span></div>
      <div class="instance">Vos déclencheurs métier : quand un événement survient, une action se lance automatiquement.</div>
    </div></div>
  </header>
  <div class="grid">
    <section class="card"><div class="bd">
      <div class="worktop"><span class="eb">Mes déclencheurs</span><span class="st">à venir</span><h2>Règles Quand → Alors</h2></div>
      <div class="datarow"><span class="v">🏗️ En construction — ex. « Quand mandat signé → créer la mission Entrée en gestion (J+1) ».</span></div>
    </div></section>
  </div>
</div><!-- /.shell -->
<?php require __DIR__ . '/_mission_foot.php'; ?>
