<?php
declare(strict_types=1);

/**
 * tarifs.php — Barème d'honoraires (page publique principale).
 * URL liée par défaut dans les annonces/biens (url_tarifs_publics = .../tarifs.php).
 * Page autonome (sans login), charte MaBoxImmo, 2 logos sociétés en en-tête.
 *
 * Barème GÉNÉRAL identique pour toutes les agences (valeurs en dur).
 * ALUR location = plafonds légaux baux ≥ 01/01/2026 (arrêté du 17/07/2025).
 *
 * NB : l'ancienne page barème pilotée par la BDD (table societe_honoraires,
 *      multi-société) est désormais tarifs_societe.php (?societe=ID).
 */

$baremeGestion = [
    ["jusqu'à 5 000 €",       '8 % TTC'],
    ['de 5 001 à 12 500 €',   '7,5 % TTC'],
    ['de 12 501 à 24 000 €',  '7 % TTC'],
    ['au-delà de 24 000 €',   '6 % TTC'],
];
$baremeTransaction = [
    ["jusqu'à 60 000 €",        'Forfait 5 500 € TTC'],
    ['de 60 001 à 100 000 €',   '9 % TTC'],
    ['de 100 001 à 200 000 €',  '7,5 % TTC'],
    ['de 200 001 à 350 000 €',  '6 % TTC'],
    ['au-delà de 350 000 €',    '5,5 % TTC'],
];
// Plafonds ALUR 2026 (€ TTC / m² surface habitable) — visite+dossier+bail / EDL
$baremeLocation = [
    ['Visite + constitution du dossier + rédaction du bail', '12,10 €/m²', '10,09 €/m²', '8,07 €/m²'],
    ["Établissement de l'état des lieux (EDL)",              '3,03 €/m²',  '3,03 €/m²',  '3,03 €/m²'],
];
$majDate = '17/06/2026';

$e = static fn(?string $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Barème d'honoraires — Régie Emery · Emery Immo</title>
<meta name="description" content="Barème des honoraires TTC : gestion locative, transaction (vente) et location (loi ALUR).">
<meta name="robots" content="index, follow">
<style>
  :root{ --navy:#243B5C; --or:#D4A047; --petrole:#26606e; --gris:#6b7280; --bg:#f3f1ec; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; color:var(--navy); background:var(--bg);
        -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .wrap{ max-width:900px; margin:0 auto; padding:24px 20px 60px; }
  header.bareme{ background:#fff; border-radius:16px; box-shadow:0 12px 30px -14px rgba(15,23,42,.35); padding:22px 28px; }
  .logos{ display:flex; align-items:center; justify-content:center; gap:34px; flex-wrap:wrap; }
  .logos img{ height:78px; width:auto; object-fit:contain; }
  .logos .sep{ width:1px; align-self:stretch; background:linear-gradient(var(--or),rgba(212,160,71,.15)); }
  .title{ text-align:center; margin-top:16px; }
  .title h1{ margin:0; font-size:26px; letter-spacing:.5px; }
  .title h1 .or{ color:var(--or); }
  .title p{ margin:6px 0 0; color:var(--gris); font-size:13px; }
  .barre-or{ height:3px; background:linear-gradient(90deg,var(--or),rgba(212,160,71,.25)); border-radius:3px; margin:18px 0 0; }
  section.bloc{ background:#fff; border-radius:14px; box-shadow:0 10px 24px -16px rgba(15,23,42,.3); padding:20px 24px; margin-top:22px; }
  section.bloc h2{ display:flex; align-items:center; gap:10px; margin:0 0 14px; font-size:18px;
                   border-left:4px solid var(--or); padding-left:12px; }
  table{ width:100%; border-collapse:collapse; font-size:15px; }
  th,td{ padding:11px 14px; text-align:left; }
  thead th{ background:var(--navy); color:#fff; font-weight:600; font-size:13px; letter-spacing:.4px; }
  thead th:first-child{ border-top-left-radius:8px; }
  thead th:last-child{ border-top-right-radius:8px; }
  tbody tr:nth-child(even){ background:#faf8f3; }
  tbody td:last-child{ text-align:right; font-weight:700; color:var(--petrole); white-space:nowrap; }
  table.alur tbody td{ text-align:center; font-weight:700; color:var(--petrole); }
  table.alur tbody td:first-child{ text-align:left; font-weight:400; color:var(--navy); }
  .note{ margin-top:12px; font-size:12px; color:var(--gris); line-height:1.5; }
  footer.bareme{ text-align:center; color:var(--gris); font-size:12px; margin-top:26px; line-height:1.6; }
  .home-link{ display:inline-flex; align-items:center; gap:7px; margin-bottom:16px; padding:8px 16px;
              background:#fff; border:1px solid #e3ddd0; border-radius:999px; color:var(--navy);
              font-size:14px; font-weight:600; text-decoration:none; box-shadow:0 6px 16px -12px rgba(15,23,42,.35); }
  .home-link:hover{ background:var(--navy); color:#fff; border-color:var(--navy); }
  @media print{ .home-link{ display:none; } }
  @media print{ body{ background:#fff; } .wrap{ padding:0; } section.bloc,header.bareme{ box-shadow:none; } }
</style>
</head>
<body>
<div style="background:#1f6f7a;color:#fff;font-weight:700;text-align:center;padding:10px 16px;font-size:14px;">
  💶 Tous les tarifs affichés sont exprimés en prix TTC.
</div>
<div class="wrap">

  <a class="home-link" href="mbi_annonces_index.php">← Retour à l'accueil</a>

  <header class="bareme">
    <div class="logos">
      <img src="images/logos/regie%20emery.jpg" alt="Régie Emery">
      <div class="sep"></div>
      <img src="images/logos/emery%20immo.jpg" alt="Emery Immo">
    </div>
    <div class="title">
      <h1>Barème d'<span class="or">honoraires</span></h1>
      <p>Honoraires TTC — affichage conforme à la réglementation en vigueur</p>
    </div>
    <div class="barre-or"></div>
  </header>

  <section class="bloc">
    <h2>🔑 Gestion locative</h2>
    <table>
      <thead><tr><th>Loyer annuel</th><th>Honoraires de gestion</th></tr></thead>
      <tbody>
      <?php foreach ($baremeGestion as $r): ?>
        <tr><td><?= $e($r[0]) ?></td><td><?= $e($r[1]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">Pourcentage TTC des loyers encaissés, selon la tranche correspondant au loyer annuel.</p>
  </section>

  <section class="bloc">
    <h2>🏠 Transaction — vente</h2>
    <table>
      <thead><tr><th>Prix de vente</th><th>Honoraires de négociation</th></tr></thead>
      <tbody>
      <?php foreach ($baremeTransaction as $r): ?>
        <tr><td><?= $e($r[0]) ?></td><td><?= $e($r[1]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">Honoraires de négociation TTC, sauf stipulation contraire au mandat. Prix exprimé FAI (honoraires inclus).</p>
  </section>

  <section class="bloc">
    <h2>📋 Location — honoraires à la charge du locataire</h2>
    <table class="alur">
      <thead>
        <tr><th>Prestation</th><th>Zone très tendue</th><th>Zone tendue</th><th>Zone non tendue</th></tr>
      </thead>
      <tbody>
      <?php foreach ($baremeLocation as $r): ?>
        <tr><td><?= $e($r[0]) ?></td><td><?= $e($r[1]) ?></td><td><?= $e($r[2]) ?></td><td><?= $e($r[3]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">
      Plafonds légaux en euros TTC par m² de surface habitable (loi ALUR — arrêté du 17/07/2025,
      baux signés à compter du 01/01/2026). La part à la charge du locataire ne peut excéder celle
      du bailleur. Honoraires d'état des lieux dus en sus, dans la limite indiquée.
    </p>
  </section>

  <footer class="bareme">
    Régie Emery · Emery Immo — Barème en vigueur au <?= $e($majDate) ?>.<br>
    Tarifs TTC. Document d'information non contractuel.
  </footer>

</div>
</body>
</html>
